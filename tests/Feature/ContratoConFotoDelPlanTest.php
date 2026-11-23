<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Lead;
use App\Services\ClientContratoService;
use App\Services\ClientMensualidadSyncService;
use App\Services\ContractPreciosService;
use App\Services\LeadContractPdfService;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cobranzas\BaseDeCobranzas;
use Tests\Feature\PreciosVigentes\PreciosVigentesSettingsTest;

/**
 * Etapa E4 de la misión embudo-whatsapp-precios (21/11/2026): el contrato guarda la FOTO del plan de
 * gestión que se eligió, para que el rango y el excedente de un contrato firmado no cambien si después se
 * edita la fuente única de precios (`PreciosVigentesSettings`, pantalla «Precios vigentes»).
 *
 * El problema de hoy: el contrato guarda sólo la CLAVE del plan (`contract_plan_gestion = inicial`). Si el
 * rango de `inicial` pasa de 100 a 200 operaciones, el PDF de un contrato ya firmado cambia y la cobranza
 * le sugiere otra cuota. La solución del PLAN §2.4 es una columna nueva `contract_plan_gestion_foto`
 * (TEXT nullable, cast `array`, en `leads` y en `clients`) con
 * `{clave, nombre, desde, hasta, usuarios, excedente, abono_lista, politica: "2026-11-21"}`:
 *
 *  - CA23: un contrato guardado hoy con `inicial` guarda la foto de la fuente vigente (hasta 200 operaciones;
 *    230 operaciones cuestan $55.900 + 30 × $760 = $78.700).
 *  - CA24: un contrato SIN foto sigue exactamente como hoy (constantes de la política del 3/11/2026 y los
 *    planes históricos por ventas), y guardarlo sin tocarle el plan no da 422 ni le inventa una foto.
 *  - CA25: la foto de `empresa` no tiene tope (`hasta = null`): el PDF dice «operaciones ilimitadas» y la
 *    cuota de 5.000 operaciones es sólo el abono, $263.000.
 *
 * Además fija las reglas de la foto: cambiar de plan la reemplaza, volver a guardar con el mismo plan no la
 * pisa aunque la fuente cambie, se copia al cliente al promoverlo y la cobranza (`cuota_sugerida`) la usa.
 *
 * Contrato de código que fijan estas pruebas (el PLAN §2.4 lo nombra así):
 *  - `ContractPreciosService::plan_gestion($clave, $abono, $foto = null)`.
 *  - `ContractPreciosService::cuota_mensual($plan, $operaciones, $usuarios_extra, $abono_pactado, $foto = null)`.
 *  - `Lead::contract_plan_gestion_foto` y `Client::contract_plan_gestion_foto` (array o null).
 *
 * 🔴 Los contratos «sin foto» se arman sin pasar por el PUT con la clave del plan (se les asigna el plan por
 * el modelo), porque el PUT de un plan vigente nuevo SÍ guarda la foto: así la prueba sigue siendo un
 * contrato viejo de verdad después de implementar.
 *
 * Misma base y mismo estilo que {@see ContratoPlanGestionTest}: reloj clavado al 15/9/2026 y sin red.
 */
class ContratoConFotoDelPlanTest extends BaseDeCobranzas
{
    /** Fecha de la política de precios con la que se toma la foto (PLAN §2.4). */
    private const POLITICA_DE_LA_FOTO = '2026-11-21';

    /**
     * Admin logueado, almacenamiento falso (el PDF se genera en `Storage`) y la fuente de precios sin
     * guardar: la base de testing podría traer un valor, y estas pruebas parten de los defaults de la oferta.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin_logueado();
        AdminSetting::query()->where('key', PreciosVigentesSettingsTest::CLAVE)->delete();
    }

    /**
     * Un lead vacío, listo para cargarle el contrato.
     *
     * @return Lead
     */
    private function lead(): Lead
    {
        $lead = new Lead();
        $lead->company_name = 'Foto del plan '.uniqid();
        $lead->contact_name = 'Prueba';
        $lead->status = 'nuevo';
        $lead->save();

        return $lead;
    }

    /**
     * El cuerpo que manda la solapa Contrato del SPA con un plan de Gestión elegido. Mismo armado que
     * `ContratoPlanGestionTest::condiciones()`; con `$plan = null` no manda la clave del plan.
     *
     * @param string|null $plan   Clave del plan, o null para no mandarla.
     * @param int         $abono  Mensualidad pactada de Gestión.
     *
     * @return array<string, mixed>
     */
    private function condiciones(?string $plan = 'inicial', int $abono = 55900): array
    {
        $cuerpo = [
            'contract_modalidad' => 'licencia',
            'contract_sistemas' => ['gestion'],
            'contract_currency' => 'USD',
            'contract_mensualidad_moneda' => 'ARS',
            'contract_precios_sistemas' => [
                'gestion' => ['licencia' => 0, 'mensualidad' => $abono],
                'ecommerce' => ['licencia' => 800, 'mensualidad' => 20000],
                'agentes' => ['licencia' => 800, 'mensualidad' => 30000],
            ],
            'contract_descuento_conjunto' => 300,
            'contract_mes_gratis' => false,
            'contract_meses_actualizacion' => 6,
        ];
        if ($plan !== null) {
            $cuerpo['contract_plan_gestion'] = $plan;
        }

        return $cuerpo;
    }

    /**
     * Un contrato como los que ya existían antes de E4: con plan pero SIN foto. Se carga el desglose por
     * el PUT (sin la clave del plan, que es la que dispara la foto) y el plan por el modelo.
     *
     * @param string $plan   Clave guardada.
     * @param int    $abono  Mensualidad pactada de Gestión.
     *
     * @return Lead
     */
    private function lead_sin_foto(string $plan, int $abono): Lead
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones(null, $abono))->assertOk();
        $lead->refresh();
        $lead->contract_plan_gestion = $plan;
        $lead->save();

        return $lead->fresh();
    }

    /**
     * El HTML del contrato tal como lo arma el PDF.
     *
     * @param Lead|Client $contrato
     *
     * @return string
     */
    private function html($contrato): string
    {
        return view('emails.lead.contract', LeadContractPdfService::datos_de_vista($contrato))->render();
    }

    /**
     * Cambia la fuente única de precios: Inicial pasa a «hasta 150 operaciones», $60.000 y excedente $900;
     * Intermedio arranca en 151. Es lo que haría Lucas desde Cuenta → Precios vigentes.
     *
     * @return void
     */
    private function cambiar_la_fuente(): void
    {
        $cuerpo = PreciosVigentesSettingsTest::contrato_por_defecto();
        $cuerpo['gestion']['planes'][0]['hasta'] = 150;
        $cuerpo['gestion']['planes'][0]['abono'] = 60000;
        $cuerpo['gestion']['planes'][0]['excedente'] = 900;
        $cuerpo['gestion']['planes'][1]['desde'] = 151;

        $this->putJson(PreciosVigentesSettingsTest::RUTA, $cuerpo)->assertOk();
    }

    /**
     * Compara la foto guardada contra lo esperado, clave por clave (el orden de las claves no importa).
     *
     * @param mixed       $foto         Lo que devuelve `contract_plan_gestion_foto`.
     * @param array       $esperado     Campos esperados.
     * @param string      $mensaje      Contexto para el mensaje de falla.
     *
     * @return void
     */
    private function assert_foto($foto, array $esperado, string $mensaje = ''): void
    {
        $this->assertIsArray($foto, 'El contrato no guardó la foto del plan (contract_plan_gestion_foto). '.$mensaje);
        $this->assertEquals(
            array_merge($esperado, ['politica' => self::POLITICA_DE_LA_FOTO]),
            $foto,
            'La foto guardada no es la esperada. '.$mensaje
        );
    }

    /**
     * La foto del plan Inicial con los defaults de la oferta de 21/11/2026.
     *
     * @return array<string, mixed>
     */
    private function foto_inicial_de_la_oferta(): array
    {
        return ['clave' => 'inicial', 'nombre' => 'Inicial', 'desde' => 0, 'hasta' => 200, 'usuarios' => 1, 'excedente' => 760, 'abono_lista' => 55900];
    }

    // =========================================================================================
    // CA23 — contrato nuevo guarda la foto
    // =========================================================================================

    /**
     * CA23. Guardar un contrato con `inicial` guarda la foto de la fuente y el PDF dice «hasta 200
     * operaciones por mes» (hoy dice 100, de la constante de la política del 3/11).
     *
     * @return void
     */
    public function test_contrato_nuevo_guarda_la_foto_y_el_pdf_dice_200(): void
    {
        $lead = $this->lead();

        /* Control: el PUT con plan vigente anda, guarda la clave y el PDF muestra el plan Inicial. */
        $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones('inicial', 55900))->assertOk();
        $lead->refresh();
        $this->assertSame('inicial', $lead->contract_plan_gestion);
        $this->assertStringContainsString('Plan Inicial (', $this->html($lead));

        /* La foto de la fuente vigente, con la política del 21/11. */
        $this->assert_foto($lead->contract_plan_gestion_foto, $this->foto_inicial_de_la_oferta());
        $this->assertStringContainsString('Plan Inicial (hasta 200 operaciones por mes)', $this->html($lead));
    }

    /**
     * CA23. La cuota con la foto: 230 operaciones en Inicial son $55.900 + 30 × $760 = $78.700 (con las
     * constantes de hoy serían 130 excedentes: $154.700). `cuota_mensual` recibe la foto como último
     * parámetro y, con foto, manda la foto.
     *
     * @return void
     */
    public function test_cuota_con_foto(): void
    {
        /* Control: la cuota sin foto sigue siendo la de la política del 3/11 (100 operaciones de tope). */
        $sin_foto = ContractPreciosService::cuota_mensual('inicial', 230, 0, 55900.0);
        $this->assertSame(130, $sin_foto['excedentes']);
        $this->assertEqualsWithDelta(154700.0, $sin_foto['total'], 0.001);

        /* La misma cuenta con la foto del plan Inicial de la oferta. */
        $con_foto = ContractPreciosService::cuota_mensual('inicial', 230, 0, 55900.0, $this->foto_inicial_de_la_oferta() + ['politica' => self::POLITICA_DE_LA_FOTO]);
        $this->assertSame(200, $con_foto['limite']);
        $this->assertSame(30, $con_foto['excedentes']);
        $this->assertEqualsWithDelta(22800.0, $con_foto['importe_excedentes'], 0.001);
        $this->assertEqualsWithDelta(78700.0, $con_foto['total'], 0.001);
    }

    /**
     * CA23. La cuota que sale de la foto GUARDADA por el PUT real (no de una foto armada a mano): el mismo
     * 230 → $78.700 con la foto que dejó el contrato.
     *
     * @return void
     */
    public function test_la_foto_guardada_por_el_put_alimenta_la_cuota(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones('inicial', 55900))->assertOk();
        $lead->refresh();

        $foto = $lead->contract_plan_gestion_foto;
        $this->assertIsArray($foto, 'El contrato no guardó la foto del plan (contract_plan_gestion_foto).');

        $cuota = ContractPreciosService::cuota_mensual('inicial', 230, 0, 55900.0, $foto);
        $this->assertEqualsWithDelta(78700.0, $cuota['total'], 0.001);
    }

    // =========================================================================================
    // CA24 — contratos sin foto, igual que hoy
    // =========================================================================================

    /**
     * CA24. Un contrato `inicial` con abono 55900 y SIN foto sigue diciendo «hasta 100 operaciones por mes»,
     * y guardarlo de nuevo sin mandar el plan no le inventa una foto ni cambia el PDF.
     *
     * @return void
     */
    public function test_contratos_sin_foto_conservan_su_pdf(): void
    {
        $lead = $this->lead_sin_foto('inicial', 55900);
        $this->assertNull($lead->contract_plan_gestion_foto, 'Un contrato guardado antes de E4 no tiene foto.');
        $this->assertStringContainsString('Plan Inicial (hasta 100 operaciones por mes)', $this->html($lead));

        /* Guardar sin tocar el plan (el cuerpo no trae la clave): sigue sin foto y con el mismo PDF. */
        $this->putJson('/api/admin/lead/'.$lead->id, ['contract_client_name' => 'Otro nombre'])->assertOk();
        $lead->refresh();
        $this->assertSame('inicial', $lead->contract_plan_gestion);
        $this->assertNull($lead->contract_plan_gestion_foto, 'Guardar sin tocar el plan no puede inventar una foto.');
        $this->assertStringContainsString('Plan Inicial (hasta 100 operaciones por mes)', $this->html($lead));

        /* La lectura directa de la clave, sin foto, también es la de hoy. */
        $this->assertSame('hasta 100 operaciones por mes', ContractPreciosService::plan_gestion('inicial', 55900)['limite']);
    }

    /**
     * CA24. Un contrato con `plus` (un plan de la política del 3/11 que la fuente nueva ya no tiene) sin
     * foto dice «de 251 a 500 operaciones por mes»; guardarlo sin tocarle el plan, o mandándolo igual, da 200
     * (no 422) y no le guarda foto.
     *
     * @return void
     */
    public function test_un_contrato_con_plus_se_guarda_sin_422_y_sigue_igual(): void
    {
        $lead = $this->lead_sin_foto('plus', 199400);
        $this->assertStringContainsString('Plan Plus (de 251 a 500 operaciones por mes)', $this->html($lead));

        /* Sin la clave del plan. */
        $this->putJson('/api/admin/lead/'.$lead->id, ['contract_client_name' => 'Otro nombre'])->assertOk();
        /* Con la clave `plus` (lo que manda el SPA al guardar un contrato viejo): tampoco es 422. */
        $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones('plus', 199400))->assertOk();

        $lead->refresh();
        $this->assertSame('plus', $lead->contract_plan_gestion);
        $this->assertNull($lead->contract_plan_gestion_foto, '`plus` no es un plan vigente de la fuente: no se le saca foto.');
        $this->assertStringContainsString('Plan Plus (de 251 a 500 operaciones por mes)', $this->html($lead));
    }

    /**
     * CA24. Un contrato `inicial` con el abono histórico de $34.200 sigue diciendo «hasta 300 ventas por
     * mes», tanto el que ya estaba guardado como el que se vuelve a guardar mandando `inicial` + 34200. Esto
     * último lo exige también `ContratoPlanGestionTest::test_los_planes_historicos_por_ventas_siguen_valiendo...`
     * (PUT de `inicial` + 34200 sobre un lead nuevo → «hasta 300 ventas»): el histórico no toma foto.
     *
     * @return void
     */
    public function test_el_inicial_historico_de_300_ventas_no_toma_foto(): void
    {
        $viejo = $this->lead_sin_foto('inicial', 34200);
        $this->assertStringContainsString('Plan Inicial (hasta 300 ventas por mes)', $this->html($viejo));

        /* Lo vuelve a guardar el SPA con la misma clave y el mismo abono pactado. */
        $this->putJson('/api/admin/lead/'.$viejo->id, $this->condiciones('inicial', 34200))->assertOk();
        $viejo->refresh();
        $this->assertNull($viejo->contract_plan_gestion_foto, 'El `inicial` de $34.200 es el histórico por ventas: no toma foto.');
        $this->assertStringContainsString('Plan Inicial (hasta 300 ventas por mes)', $this->html($viejo));

        /* Y uno nuevo que se carga directo con el histórico tampoco. */
        $nuevo = $this->lead();
        $this->putJson('/api/admin/lead/'.$nuevo->id, $this->condiciones('inicial', 34200))->assertOk();
        $nuevo->refresh();
        $this->assertNull($nuevo->contract_plan_gestion_foto);
        $this->assertStringContainsString('Plan Inicial (hasta 300 ventas por mes)', $this->html($nuevo));
    }

    /**
     * CA24 (decisión a confirmar, ver DECISIONES-E4.md punto 3). Un contrato firmado con `inicial` + $55.900
     * y sin foto, que el SPA vuelve a guardar mandando la MISMA clave `inicial`, no recibe la foto: su PDF
     * sigue en «hasta 100». Sin esto, abrir y guardar un contrato firmado le cambiaría el rango en silencio
     * (de 100 a 200), que es justo lo que E4 viene a evitar.
     *
     * @return void
     */
    public function test_guardar_un_contrato_viejo_con_el_mismo_plan_no_le_pone_foto(): void
    {
        $lead = $this->lead_sin_foto('inicial', 55900);
        $this->assertStringContainsString('hasta 100 operaciones por mes', $this->html($lead));

        $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones('inicial', 55900))->assertOk();
        $lead->refresh();

        $this->assertNull($lead->contract_plan_gestion_foto, 'Mismo plan y sin foto: es un contrato firmado, no se le saca foto.');
        $this->assertStringContainsString('Plan Inicial (hasta 100 operaciones por mes)', $this->html($lead));
    }

    // =========================================================================================
    // CA25 — empresa, ilimitado
    // =========================================================================================

    /**
     * CA25. La foto de `empresa` no tiene tope (`hasta = null`): el PDF dice «operaciones ilimitadas» y la
     * cuota de 5.000 operaciones es sólo el abono lista, $263.000, sin excedente.
     *
     * @return void
     */
    public function test_empresa_ilimitado(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones('empresa', 263000))->assertOk();
        $lead->refresh();
        $this->assertSame('empresa', $lead->contract_plan_gestion);
        $this->assertStringContainsString('Plan Empresa (', $this->html($lead));

        $foto = $lead->contract_plan_gestion_foto;
        $this->assertIsArray($foto, 'El contrato no guardó la foto del plan (contract_plan_gestion_foto).');
        $this->assertSame('empresa', $foto['clave']);
        $this->assertNull($foto['hasta'], 'Empresa no tiene tope de operaciones.');
        $this->assertNull($foto['excedente'], 'Empresa no cobra excedente.');
        $this->assertSame(263000, $foto['abono_lista']);
        $this->assertStringContainsString('operaciones ilimitadas', $this->html($lead));

        $cuota = ContractPreciosService::cuota_mensual('empresa', 5000, 0, 263000.0, $foto);
        $this->assertSame(0, $cuota['excedentes']);
        $this->assertNull($cuota['limite']);
        $this->assertEqualsWithDelta(263000.0, $cuota['total'], 0.001);
    }

    /**
     * CA25. La misma cuenta con la foto armada a mano (sin pasar por el PUT): el parámetro `$foto` de
     * `cuota_mensual` manda sobre la constante de hoy, donde Empresa cobra $76 por cada operación sobre 3.000
     * (5.000 operaciones = $263.000 + 2.000 × $76 = $415.000).
     *
     * @return void
     */
    public function test_cuota_de_empresa_con_foto_sin_excedente(): void
    {
        /* Control: sin foto, la política del 3/11 cobra excedente por encima de 3.000. */
        $this->assertEqualsWithDelta(415000.0, ContractPreciosService::cuota_mensual('empresa', 5000, 0, 263000.0)['total'], 0.001);

        $foto = ['clave' => 'empresa', 'nombre' => 'Empresa', 'desde' => 601, 'hasta' => null, 'usuarios' => 3, 'excedente' => null, 'abono_lista' => 263000, 'politica' => self::POLITICA_DE_LA_FOTO];
        $cuota = ContractPreciosService::cuota_mensual('empresa', 5000, 0, 263000.0, $foto);
        $this->assertSame(0, $cuota['excedentes']);
        $this->assertEqualsWithDelta(263000.0, $cuota['total'], 0.001);

        $plan = ContractPreciosService::plan_gestion('empresa', 263000, $foto);
        $this->assertStringContainsString('operaciones ilimitadas', $plan['limite']);
    }

    // =========================================================================================
    // Reglas de la foto
    // =========================================================================================

    /**
     * Cambiar de plan reemplaza la foto: de Inicial a Intermedio (con la fuente editada en el medio, la foto
     * nueva sale de la fuente de AHORA), a Empresa (sin tope) y a `plus` (que ya no es vigente: la foto se
     * borra y el PDF vuelve a la política del 3/11).
     *
     * @return void
     */
    public function test_cambiar_de_plan_reemplaza_la_foto(): void
    {
        $lead = $this->lead();
        $url = '/api/admin/lead/'.$lead->id;
        $this->putJson($url, $this->condiciones('inicial', 55900))->assertOk();
        $this->assertSame('inicial', $lead->fresh()->contract_plan_gestion);

        $this->assert_foto($lead->fresh()->contract_plan_gestion_foto, $this->foto_inicial_de_la_oferta(), 'Primero Inicial.');

        /* Intermedio con la fuente de hoy. */
        $this->putJson($url, $this->condiciones('intermedio', 85400))->assertOk();
        $this->assert_foto(
            $lead->fresh()->contract_plan_gestion_foto,
            ['clave' => 'intermedio', 'nombre' => 'Intermedio', 'desde' => 201, 'hasta' => 600, 'usuarios' => 2, 'excedente' => 570, 'abono_lista' => 85400],
            'Después Intermedio.'
        );
        $this->assertStringContainsString('Plan Intermedio (de 201 a 600 operaciones por mes)', $this->html($lead->fresh()));

        /* Se edita la fuente y se vuelve a Inicial: la foto nueva sale de la fuente editada. */
        $this->cambiar_la_fuente();
        $this->putJson($url, $this->condiciones('inicial', 55900))->assertOk();
        $this->assert_foto(
            $lead->fresh()->contract_plan_gestion_foto,
            ['clave' => 'inicial', 'nombre' => 'Inicial', 'desde' => 0, 'hasta' => 150, 'usuarios' => 1, 'excedente' => 900, 'abono_lista' => 60000],
            'Volver a Inicial con la fuente editada.'
        );

        /* Empresa: sin tope. */
        $this->putJson($url, $this->condiciones('empresa', 263000))->assertOk();
        $foto = $lead->fresh()->contract_plan_gestion_foto;
        $this->assertIsArray($foto);
        $this->assertSame('empresa', $foto['clave']);
        $this->assertNull($foto['hasta']);

        /* `plus` ya no es vigente: se guarda sin 422, sin foto y con el PDF de la política del 3/11. */
        $this->putJson($url, $this->condiciones('plus', 199400))->assertOk();
        $lead->refresh();
        $this->assertSame('plus', $lead->contract_plan_gestion);
        $this->assertNull($lead->contract_plan_gestion_foto, 'Pasar a un plan que no es vigente borra la foto anterior.');
        $this->assertStringContainsString('Plan Plus (de 251 a 500 operaciones por mes)', $this->html($lead));
    }

    /**
     * Volver a guardar con el mismo plan no pisa la foto, aunque la fuente cambie después: el PDF y la cuota
     * del contrato firmado son los mismos de siempre. Es el motivo de E4.
     *
     * @return void
     */
    public function test_volver_a_guardar_con_el_mismo_plan_no_pisa_la_foto_aunque_la_fuente_cambie(): void
    {
        $lead = $this->lead();
        $url = '/api/admin/lead/'.$lead->id;
        $this->putJson($url, $this->condiciones('inicial', 55900))->assertOk();
        $foto_original = $lead->fresh()->contract_plan_gestion_foto;
        $this->assert_foto($foto_original, $this->foto_inicial_de_la_oferta());

        /* La fuente cambia: Inicial pasa a 150 operaciones, $60.000 y excedente $900. */
        $this->cambiar_la_fuente();
        $this->assertSame(150, \App\Services\PreciosVigentesSettings::plan('inicial')['hasta'], 'Control: la fuente quedó editada.');

        /* El SPA vuelve a guardar el contrato con la misma clave y se cambia otro campo cualquiera. */
        $this->putJson($url, array_merge($this->condiciones('inicial', 55900), ['contract_client_name' => 'Cliente renombrado']))->assertOk();
        $lead->refresh();

        $this->assertEquals($foto_original, $lead->contract_plan_gestion_foto, 'La foto no se pisa con la fuente nueva.');
        $this->assertStringContainsString('Plan Inicial (hasta 200 operaciones por mes)', $this->html($lead));
        $cuota = ContractPreciosService::cuota_mensual('inicial', 230, 0, 55900.0, $lead->contract_plan_gestion_foto);
        $this->assertEqualsWithDelta(78700.0, $cuota['total'], 0.001);

        /* Guardar sin mandar el plan tampoco la toca. */
        $this->putJson($url, ['contract_client_name' => 'Otra vez'])->assertOk();
        $this->assertEquals($foto_original, $lead->fresh()->contract_plan_gestion_foto);
    }

    /**
     * La pestaña Contrato del cliente (`PUT /api/admin/client/{id}/contrato`) guarda la foto igual que la del
     * lead: un cliente sin foto que cambia de plan la recibe, y se la lleva de la fuente de ese momento.
     *
     * @return void
     */
    public function test_la_pestana_del_cliente_guarda_la_foto_al_cambiar_de_plan(): void
    {
        $client = $this->crear_cliente(['contract_plan_gestion' => 'plus']);
        $url = '/api/admin/client/'.$client->id.'/contrato';

        /* Control: el PUT del contrato del cliente con el plan viejo anda. */
        $this->putJson($url, $this->condiciones('plus', 199400))->assertOk();
        $this->assertSame('plus', $client->fresh()->contract_plan_gestion);

        $this->putJson($url, $this->condiciones('intermedio', 85400))->assertOk();
        $client->refresh();
        $this->assertSame('intermedio', $client->contract_plan_gestion);
        $this->assert_foto(
            $client->contract_plan_gestion_foto,
            ['clave' => 'intermedio', 'nombre' => 'Intermedio', 'desde' => 201, 'hasta' => 600, 'usuarios' => 2, 'excedente' => 570, 'abono_lista' => 85400]
        );
    }

    /**
     * La foto se copia al cliente al promoverlo (`ClientContratoService::copiar_desde_lead`, que usa
     * `inicializar_desde_lead` en la promoción): el cliente regenera el mismo PDF que firmó el lead aunque la
     * fuente cambie en el medio.
     *
     * @return void
     */
    public function test_la_foto_se_copia_al_cliente_al_promoverlo(): void
    {
        $lead = $this->lead();
        $this->putJson('/api/admin/lead/'.$lead->id, $this->condiciones('inicial', 55900))->assertOk();
        $lead->refresh();

        /* Control: lo de siempre se copia (la clave del plan). */
        $client = $this->crear_cliente();
        $this->assertTrue(app(ClientContratoService::class)->copiar_desde_lead($lead, $client));
        $this->assertSame('inicial', $client->fresh()->contract_plan_gestion);

        $this->assertIsArray($lead->contract_plan_gestion_foto, 'El lead no guardó la foto del plan.');
        $this->cambiar_la_fuente();
        $this->assertEquals($lead->contract_plan_gestion_foto, $client->fresh()->contract_plan_gestion_foto, 'La foto del lead tiene que viajar al cliente.');
        $this->assertStringContainsString('Plan Inicial (hasta 200 operaciones por mes)', $this->html($client->fresh()));
    }

    /**
     * La cobranza usa la foto: `ClientMensualidadSyncService::cuota_sugerida` con 230 operaciones de un
     * cliente con foto de Inicial da $78.700 (con las constantes de hoy daría $154.700), y el plan del
     * contrato sigue siendo «del contrato». Con la fuente editada después, la cuota no cambia. Un cliente
     * sin foto conserva la cuenta de hoy.
     *
     * @return void
     */
    public function test_cuota_sugerida_usa_la_foto(): void
    {
        $precios = ['gestion' => ['licencia' => 0, 'mensualidad' => 55900], 'ecommerce' => ['licencia' => 800, 'mensualidad' => 20000], 'agentes' => ['licencia' => 800, 'mensualidad' => 30000]];
        $operaciones = ['total' => 230, 'mes' => '2026-09'];
        $servicio = new ClientMensualidadSyncService();

        /* Control: un cliente sin foto sigue con la cuenta de la política del 3/11. */
        $viejo = $this->crear_cliente(['contract_plan_gestion' => 'inicial', 'contract_precios_sistemas' => $precios, 'contract_usuarios_extra' => 0]);
        $cuota_vieja = $servicio->cuota_sugerida($viejo, $operaciones);
        $this->assertTrue($cuota_vieja['plan_del_contrato']);
        $this->assertSame(130, $cuota_vieja['excedentes']);
        $this->assertEqualsWithDelta(154700.0, $cuota_vieja['total'], 0.001);

        /* Un cliente con foto: la cuenta sale de la foto. */
        $foto = $this->foto_inicial_de_la_oferta() + ['politica' => self::POLITICA_DE_LA_FOTO];
        $cliente = $this->crear_cliente(['contract_plan_gestion' => 'inicial', 'contract_plan_gestion_foto' => $foto, 'contract_precios_sistemas' => $precios, 'contract_usuarios_extra' => 0]);
        $this->cambiar_la_fuente();
        $cuota = $servicio->cuota_sugerida($cliente->fresh(), $operaciones);

        $this->assertTrue($cuota['plan_del_contrato'], 'Con foto, el plan es del contrato.');
        $this->assertSame(200, $cuota['limite']);
        $this->assertSame(30, $cuota['excedentes']);
        $this->assertEqualsWithDelta(78700.0, $cuota['total'], 0.001);
    }
}
