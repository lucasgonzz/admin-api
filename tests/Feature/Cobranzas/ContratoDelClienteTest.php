<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LicenciaCuota;
use App\Services\LeadContractPdfService;
use App\Services\ClientContratoService;
use App\Services\RunUserSetupService;
use Illuminate\Support\Facades\Storage;

/**
 * El contrato del cliente: cómo llega del lead, cómo se edita y cómo sale en PDF (misión
 * modulo-cobranzas, 18/9/2026).
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **Que promover un lead copie el contrato y genere las cuotas UNA vez.** Es el único
 *     momento en que el contrato cruza del lead al cliente; si falla, el módulo de Licencias
 *     arranca vacío para cada cliente nuevo. Y la rama de update de `ensure_production_client`
 *     NO puede pisar lo que el cliente editó después.
 *  2. **Que el backfill sea idempotente**: copia a los que no tienen, no duplica cuotas, y la
 *     segunda corrida no cambia nada.
 *  3. **Que el PDF diga los meses del contrato** ("doce (12) meses") y siga diciendo "seis (6)
 *     meses" para un lead que no tiene el dato. Es compatibilidad hacia atrás con cada contrato
 *     ya firmado.
 *
 * 🔴 `Storage::fake('local')` en el `setUp()`: el PDF lee la firma del PRESTADOR del disco
 * `local`, y sin el fake la suite dependería de lo que haya en el entorno de Lucas.
 */
class ContratoDelClienteTest extends BaseDeCobranzas
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Un lead ganado con el contrato cargado: licencia financiada en dos cuotas y mensualidad
     * desde octubre.
     *
     * @param array<string, mixed> $atributos
     *
     * @return Lead
     */
    private function crear_lead_con_contrato(array $atributos = []): Lead
    {
        $lead = new Lead();
        $lead->contact_name                       = 'Prospecto Cobranzas';
        $lead->company_name                       = 'Comercio promovido ' . uniqid();
        $lead->email                              = 'promovido@test.local';
        $lead->status                             = 'cerrado_ganado';
        $lead->contract_client_name               = 'Juan Pérez';
        $lead->contract_client_razon_social       = 'Juan Pérez S.A.';
        $lead->contract_client_cuit               = '20-11111111-1';
        $lead->contract_currency                  = 'USD';
        $lead->contract_precio_licencia           = '1500';
        $lead->contract_fecha_emision             = '2026-09-10';
        $lead->contract_fecha_primer_pago_unico   = '2026-10-05';
        $lead->contract_financiacion              = [
            ['monto' => '1000', 'fecha' => '2026-10-05'],
            ['monto' => '500', 'fecha' => '2026-11-05'],
        ];
        $lead->contract_mensualidad_moneda        = 'ARS';
        $lead->contract_mensualidad_base          = '36000';
        $lead->contract_usuarios_incluidos        = 3;
        $lead->contract_fecha_primer_pago_mensual = '2026-10-10';
        $lead->contract_clausulas_particulares    = [['titulo' => 'Datos', 'texto' => 'Se migran los datos del sistema anterior.']];
        $lead->contract_meses_actualizacion       = 12;

        foreach ($atributos as $campo => $valor) {
            $lead->{$campo} = $valor;
        }

        $lead->save();

        return $lead->refresh();
    }

    /**
     * 1a. Al crear el cliente, el contrato se copia, las cuotas nacen de la financiación y el
     * inicio de la mensualidad sale del primer pago mensual.
     *
     * @return void
     */
    public function test_promover_el_lead_copia_el_contrato_y_genera_las_cuotas(): void
    {
        $lead = $this->crear_lead_con_contrato();

        $client = app(RunUserSetupService::class)->ensure_production_client($lead, '');

        $this->assertInstanceOf(Client::class, $client);
        $client->refresh();

        $this->assertSame('Juan Pérez', $client->contract_client_name);
        $this->assertSame('20-11111111-1', $client->contract_client_cuit);
        $this->assertSame('1500', $client->contract_precio_licencia);
        $this->assertSame('2026-09-10', $client->contract_fecha_emision->toDateString());
        $this->assertCount(2, $client->contract_financiacion);
        $this->assertSame('Datos', $client->contract_clausulas_particulares[0]['titulo']);
        $this->assertSame(12, $client->contract_meses_actualizacion);
        $this->assertNotNull($client->contract_copiado_desde_lead_at);

        $this->assertSame('2026-10-01', $client->mensualidad_inicio->toDateString(), 'El primer mes que se cobra es el del primer pago mensual.');

        $cuotas = LicenciaCuota::where('client_id', $client->id)->orderBy('numero')->get();
        $this->assertCount(2, $cuotas);
        $this->assertSame(1, $cuotas[0]->numero);
        $this->assertEqualsWithDelta(1000.0, (float) $cuotas[0]->monto, 0.001, 'El monto de la cuota es el del contrato, leído como se escribe en Argentina.');
        $this->assertSame('USD', $cuotas[0]->moneda);
        $this->assertSame('2026-10', $cuotas[0]->periodo);
        $this->assertSame('2026-10-05', $cuotas[0]->vencimiento->toDateString());
        $this->assertSame('pendiente', $cuotas[0]->estado);
        $this->assertFalse($cuotas[0]->importado);
        $this->assertEqualsWithDelta(500.0, (float) $cuotas[1]->monto, 0.001);
        $this->assertSame('2026-11', $cuotas[1]->periodo);

        $lead->refresh();
        $this->assertSame($client->id, $lead->promoted_client_id);
    }

    /**
     * 1c. Los montos del contrato se tipean a mano y en Argentina el punto es de miles: `1.500`
     * tiene que dar mil quinientos, no uno y medio. Cubre las formas que aparecen de verdad en los
     * contratos cargados y las dos ambiguas que decide la regla de los tres dígitos.
     *
     * @return void
     */
    public function test_los_montos_del_contrato_se_leen_con_formato_argentino(): void
    {
        $casos = [
            '1.500'        => 1500.0,
            '1.500.000'    => 1500000.0,
            '1,500'        => 1500.0,
            '1.500,50'     => 1500.5,
            '1,500.50'     => 1500.5,
            '1500.50'      => 1500.5,
            '1,5'          => 1.5,
            '0.75'         => 0.75,
            'USD 1.200'    => 1200.0,
            '$ 12.000'     => 12000.0,
            '1500'         => 1500.0,
            ''             => 0.0,
            'sin numero'   => 0.0,
        ];

        foreach ($casos as $texto => $esperado) {
            $this->assertEqualsWithDelta($esperado, ClientContratoService::monto_desde_texto($texto), 0.0001, 'Texto: "' . $texto . '"');
        }

        $this->assertEqualsWithDelta(1500.0, ClientContratoService::monto_desde_texto(1500), 0.0001);
        $this->assertEqualsWithDelta(0.0, ClientContratoService::monto_desde_texto(null), 0.0001);

        // Y la cuota que nace del contrato usa esa regla: `1.500` es una cuota de mil quinientos.
        $lead = $this->crear_lead_con_contrato([
            'contract_financiacion' => [['monto' => '1.500', 'fecha' => '2026-10-05']],
        ]);
        $client = app(RunUserSetupService::class)->ensure_production_client($lead, '');
        $cuota = LicenciaCuota::where('client_id', $client->id)->first();
        $this->assertEqualsWithDelta(1500.0, (float) $cuota->monto, 0.001);
    }

    /**
     * 1b. Sin financiación, una sola cuota por el precio de la licencia. Y un lead sin contrato
     * no marca nada en el cliente.
     *
     * @return void
     */
    public function test_sin_financiacion_nace_una_sola_cuota_y_sin_contrato_no_nace_nada(): void
    {
        $lead = $this->crear_lead_con_contrato(['contract_financiacion' => null]);

        $client = app(RunUserSetupService::class)->ensure_production_client($lead, '');

        $cuotas = LicenciaCuota::where('client_id', $client->id)->get();
        $this->assertCount(1, $cuotas);
        $this->assertEqualsWithDelta(1500.0, (float) $cuotas[0]->monto, 0.001);
        $this->assertSame('2026-10-05', $cuotas[0]->vencimiento->toDateString(), 'Vence en la fecha del primer pago único.');

        // Un lead sin contrato: el cliente nace sin marca de copia, sin cuotas y cobrando desde el mes corriente.
        $pelado = new Lead();
        $pelado->contact_name = 'Sin contrato';
        $pelado->company_name = 'Comercio sin contrato ' . uniqid();
        $pelado->status       = 'cerrado_ganado';
        $pelado->save();

        $client_pelado = app(RunUserSetupService::class)->ensure_production_client($pelado->refresh(), '');
        $client_pelado->refresh();

        $this->assertNull($client_pelado->contract_copiado_desde_lead_at, 'Sin contrato en el lead no hay nada copiado que declarar.');
        $this->assertSame(0, LicenciaCuota::where('client_id', $client_pelado->id)->count());
        $this->assertSame('2026-09-01', $client_pelado->mensualidad_inicio->toDateString());
    }

    /**
     * 1c. La rama de update no pisa el contrato que el cliente editó ni duplica cuotas.
     *
     * @return void
     */
    public function test_la_rama_de_update_no_pisa_el_contrato_del_cliente(): void
    {
        $lead = $this->crear_lead_con_contrato();
        $service = app(RunUserSetupService::class);

        $client = $service->ensure_production_client($lead, '');

        // Después de la promoción, el cliente edita su contrato y el lead cambia el suyo.
        $client->contract_client_name = 'Editado en el cliente';
        $client->save();
        $lead->refresh();
        $lead->contract_client_name = 'Cambiado en el lead';
        $lead->save();

        $de_nuevo = $service->ensure_production_client($lead->refresh(), '');

        $this->assertSame($client->id, $de_nuevo->id);
        $this->assertSame('Editado en el cliente', $de_nuevo->contract_client_name);
        $this->assertSame(2, LicenciaCuota::where('client_id', $client->id)->count(), 'Las cuotas no se duplican.');
    }

    /**
     * 2. El backfill copia a los promovidos sin contrato copiado, NO genera cuotas (deuda fantasma
     * para clientes que ya vienen operando), fija el inicio solo si el contrato trae fecha de primer
     * pago mensual, y no hace nada la segunda vez.
     *
     * @return void
     */
    public function test_el_backfill_copia_una_vez_y_no_duplica_cuotas(): void
    {
        // Promovido "viejo": tiene cliente, el cliente no tiene el contrato copiado ni inicio.
        $lead_viejo = $this->crear_lead_con_contrato();
        $client_viejo = $this->crear_cliente(['mensualidad_inicio' => null]);
        $lead_viejo->promoted_client_id = $client_viejo->id;
        $lead_viejo->save();

        // Otro promovido cuyo cliente YA tiene una cuota importada de la planilla.
        $lead_con_cuotas = $this->crear_lead_con_contrato();
        $client_con_cuotas = $this->crear_cliente(['mensualidad_inicio' => '2026-02-01']);
        $lead_con_cuotas->promoted_client_id = $client_con_cuotas->id;
        $lead_con_cuotas->save();
        LicenciaCuota::create([
            'client_id' => $client_con_cuotas->id, 'numero' => 1, 'periodo' => '2026-02',
            'monto' => 1200, 'moneda' => 'USD', 'estado' => 'pagada', 'importado' => true,
        ]);

        // Un promovido sin contrato en el lead: no hay nada que copiar.
        $lead_pelado = new Lead();
        $lead_pelado->contact_name = 'Pelado';
        $lead_pelado->company_name = 'Pelado ' . uniqid();
        $lead_pelado->status       = 'cerrado_ganado';
        $lead_pelado->save();
        $client_pelado = $this->crear_cliente();
        $lead_pelado->promoted_client_id = $client_pelado->id;
        $lead_pelado->save();

        $this->artisan('cobranzas:copiar-contratos-de-leads')->assertExitCode(0);

        $client_viejo->refresh();
        $this->assertSame('Juan Pérez', $client_viejo->contract_client_name);
        $this->assertNotNull($client_viejo->contract_copiado_desde_lead_at);
        $this->assertSame(12, $client_viejo->contract_meses_actualizacion);
        $this->assertSame('2026-10-01', $client_viejo->mensualidad_inicio->toDateString(), 'Sin inicio, se fija desde la fecha de primer pago mensual del contrato.');
        $this->assertSame(0, LicenciaCuota::where('client_id', $client_viejo->id)->count(), 'El backfill no genera cuotas: la licencia de un cliente viejo puede estar cobrada sin figurar en la planilla.');

        $client_con_cuotas->refresh();
        $this->assertNotNull($client_con_cuotas->contract_copiado_desde_lead_at, 'El contrato se copia igual.');
        $this->assertSame(1, LicenciaCuota::where('client_id', $client_con_cuotas->id)->count(), 'Las cuotas de la planilla quedan como están.');
        $this->assertSame('2026-02-01', $client_con_cuotas->mensualidad_inicio->toDateString(), 'El inicio que ya tenía no se toca.');

        $client_pelado->refresh();
        $this->assertNull($client_pelado->contract_copiado_desde_lead_at);

        // Segunda corrida: nada cambia.
        $marca = $client_viejo->contract_copiado_desde_lead_at;
        $client_viejo->contract_client_name = 'Editado después del backfill';
        $client_viejo->save();

        $this->artisan('cobranzas:copiar-contratos-de-leads')->assertExitCode(0);

        $client_viejo->refresh();
        $this->assertSame('Editado después del backfill', $client_viejo->contract_client_name);
        $this->assertEquals($marca, $client_viejo->contract_copiado_desde_lead_at);
        $this->assertSame(0, LicenciaCuota::where('client_id', $client_viejo->id)->count());

        // Un contrato SIN fecha de primer pago mensual no inventa el inicio (quedaría en rojo este mes).
        $lead_sin_fecha = $this->crear_lead_con_contrato(['contract_fecha_primer_pago_mensual' => null]);
        $client_sin_fecha = $this->crear_cliente(['mensualidad_inicio' => null]);
        $lead_sin_fecha->promoted_client_id = $client_sin_fecha->id;
        $lead_sin_fecha->save();

        $this->artisan('cobranzas:copiar-contratos-de-leads')->assertExitCode(0);

        $client_sin_fecha->refresh();
        $this->assertNotNull($client_sin_fecha->contract_copiado_desde_lead_at);
        $this->assertNull($client_sin_fecha->mensualidad_inicio, 'Sin fecha en el contrato, el inicio lo carga Lucas a mano.');

        // Y con --simular no escribe.
        $otro_lead = $this->crear_lead_con_contrato();
        $otro_client = $this->crear_cliente();
        $otro_lead->promoted_client_id = $otro_client->id;
        $otro_lead->save();

        $this->artisan('cobranzas:copiar-contratos-de-leads', ['--simular' => true])->assertExitCode(0);

        $otro_client->refresh();
        $this->assertNull($otro_client->contract_copiado_desde_lead_at);
        $this->assertSame(0, LicenciaCuota::where('client_id', $otro_client->id)->count());
    }

    /**
     * El GET y el PUT del contrato del cliente, con las mismas claves que la pestaña del lead.
     *
     * @return void
     */
    public function test_ver_y_editar_el_contrato_del_cliente(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $vacio = $this->getJson('/api/admin/client/' . $client->id . '/contrato');
        $vacio->assertStatus(200);
        $this->assertNull($vacio->json('contrato.contract_client_name'));
        $this->assertSame(6, $vacio->json('contrato.contract_meses_actualizacion'), 'Default del contrato.');
        $this->assertNull($vacio->json('contrato.contract_copiado_desde_lead_at'));
        $this->assertNull($vacio->json('lead_id'));
        $this->assertTrue($vacio->json('resumen_actualizacion.sin_oficial'));

        $response = $this->putJson('/api/admin/client/' . $client->id . '/contrato', [
            'contract_client_name'               => 'María López',
            'contract_client_razon_social'       => 'López Hnos. S.R.L.',
            'contract_client_cuit'               => '30-22222222-2',
            'contract_currency'                  => 'USD',
            'contract_precio_licencia'           => '2000',
            'contract_fecha_emision'             => '2026-09-15',
            'contract_fecha_primer_pago_unico'   => '2026-10-01',
            'contract_financiacion'              => [['monto' => '2000', 'fecha' => '2026-10-01']],
            'contract_clausulas_particulares'    => [],
            'contract_mensualidad_moneda'        => 'ARS',
            'contract_mensualidad_base'          => '40000',
            'contract_usuarios_incluidos'        => 2,
            'contract_usuarios_extra'            => 1,
            'contract_precio_usuario_extra'      => '5000',
            'contract_perfiles_ecommerce'        => 0,
            'contract_precio_perfil_ecommerce'   => null,
            'contract_fecha_primer_pago_mensual' => '2026-11-01',
            'contract_meses_actualizacion'       => 9,
        ]);

        $response->assertStatus(200);
        $this->assertSame('María López', $response->json('contrato.contract_client_name'));
        $this->assertSame('2026-09-15', $response->json('contrato.contract_fecha_emision'));
        $this->assertSame(9, $response->json('contrato.contract_meses_actualizacion'));
        $this->assertSame(9, $response->json('resumen_actualizacion.meses'));
        $this->assertCount(1, $response->json('contrato.contract_financiacion'));

        $client->refresh();
        $this->assertSame('López Hnos. S.R.L.', $client->contract_client_razon_social);
        $this->assertSame(9, $client->contract_meses_actualizacion);

        // Un guardado parcial no vacía el resto.
        $parcial = $this->putJson('/api/admin/client/' . $client->id . '/contrato', ['contract_client_cuit' => '30-33333333-3']);
        $parcial->assertStatus(200);
        $this->assertSame('María López', $parcial->json('contrato.contract_client_name'));
        $this->assertSame('30-33333333-3', $parcial->json('contrato.contract_client_cuit'));

        // Validación: los meses van de 1 a 60.
        $this->putJson('/api/admin/client/' . $client->id . '/contrato', ['contract_meses_actualizacion' => 0])->assertStatus(422);
        $this->putJson('/api/admin/client/' . $client->id . '/contrato', ['contract_financiacion' => 'no es un array'])->assertStatus(422);

        // El lead del que vino, cuando lo hay.
        $lead = $this->crear_lead_con_contrato();
        $lead->promoted_client_id = $client->id;
        $lead->save();
        $this->assertSame($lead->id, $this->getJson('/api/admin/client/' . $client->id . '/contrato')->json('lead_id'));
    }

    /**
     * 3a. El PDF del contrato del cliente sale como PDF.
     *
     * @return void
     */
    public function test_el_pdf_del_contrato_del_cliente_es_un_pdf(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente([
            'contract_client_name'       => 'Juan Pérez',
            'contract_currency'          => 'USD',
            'contract_precio_licencia'   => '1500',
            'contract_mensualidad_moneda' => 'ARS',
            'contract_mensualidad_base'  => '36000',
            'contract_usuarios_incluidos' => 3,
        ]);

        $response = $this->postJson('/api/admin/client/' . $client->id . '/contrato/pdf', ['incluir_firma' => false]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent(), 'La respuesta no es un PDF.');

        // Un body vacío (una SPA que no manda incluir_firma) también sirve.
        $this->postJson('/api/admin/client/' . $client->id . '/contrato/pdf', [])->assertStatus(200);
    }

    /**
     * 3b. Los meses de actualización salen en letras y cifra; sin el dato, el texto histórico.
     *
     * @return void
     */
    public function test_el_contrato_dice_los_meses_de_actualizacion_en_letras(): void
    {
        $lead = $this->crear_lead_con_contrato(['contract_meses_actualizacion' => 12]);

        $datos = LeadContractPdfService::datos_de_vista($lead);
        $this->assertSame(12, $datos['meses_actualizacion']);
        $this->assertSame('doce (12) meses', $datos['meses_actualizacion_texto']);

        $html = view('emails.lead.contract', $datos)->render();
        $this->assertStringContainsString('cada <strong>doce (12) meses</strong>', $html);
        $this->assertStringNotContainsString('seis (6) meses', $html);

        // Un lead sin el dato (o con 0) sigue diciendo lo de siempre: compatibilidad con lo firmado.
        $sin_dato = $this->crear_lead_con_contrato(['contract_meses_actualizacion' => null]);
        $html_default = view('emails.lead.contract', LeadContractPdfService::datos_de_vista($sin_dato))->render();
        $this->assertStringContainsString('cada <strong>seis (6) meses</strong>', $html_default);

        // Un cliente también genera con sus propios meses.
        $client = $this->crear_cliente(['contract_meses_actualizacion' => 1, 'contract_mensualidad_base' => '1000']);
        $this->assertSame('un (1) mes', LeadContractPdfService::datos_de_vista($client)['meses_actualizacion_texto']);

        // El helper cubre de 1 a 24 y cae a la cifra afuera.
        $this->assertSame('veinticuatro (24) meses', LeadContractPdfService::meses_en_letras(24));
        $this->assertSame('30 meses', LeadContractPdfService::meses_en_letras(30));

        // Y el PDF real del lead con 12 meses se genera (dompdf incluido).
        $pdf = LeadContractPdfService::generate($lead, false);
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
