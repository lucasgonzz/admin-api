<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Helpers\WhatsappNormalizer;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use Carbon\Carbon;

/**
 * Alta de un lead que llegó por otro medio (referido, WhatsApp personal de Lucas, llamada u otro): criterios CA1,
 * CA2, CA3 y CA6 de la etapa E1 de la misión leads-referidos-compromisos (`POST admin/lead/externo`).
 *
 * Qué se protege:
 *  - CA1: el lead nace con su origen, quién lo refirió y lo que ya se habló, deja un evento en el hilo (así el
 *    onboarding genérico no corre) y NO sale ningún WhatsApp por el solo hecho de darlo de alta.
 *  - CA2: validaciones en 422 con `errors.<campo>`; un teléfono repetido es 409 y no crea otro lead; con
 *    `completar_existente=true` se completa ESE lead sin pisar el origen que ya tenía (caso Ro, lead #697) y el
 *    contexto se AGREGA con un encabezado, nunca se reemplaza.
 *  - CA3: el lead entra al tablero de Juan sólo si nace en `link_enviado` o se pide `entrar_al_tablero`.
 *  - CA6: el primer contacto por plantilla sólo sale si la plantilla está registrada, activa y APROBADA; si no, el
 *    lead se crea igual y la respuesta dice por qué no se mandó.
 *
 * Todo corre contra el endpoint real y la base; el WhatsApp es un espía y el HTTP está cerrado.
 *
 * Hoy están EN ROJO: la ruta `admin/lead/externo`, la migración de referidos y el servicio de alta no existen.
 */
class AltaDeLeadExternoTest extends BaseDeReferidosYCompromisos
{
    /** Endpoint del panel para dar de alta un lead de otro medio. */
    private const ENDPOINT = '/api/admin/lead/externo';

    /**
     * Loguea a un admin común (el que carga el lead desde el panel).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->loguear_a($this->admin);
    }

    // ------------------------------------------------------------------------------------------
    // CA1 — alta completa
    // ------------------------------------------------------------------------------------------

    /**
     * CA1. El caso de Ro: referida por un cliente, con lo que ya se habló. 201; el lead queda con origen
     * `referido`, `origen_calculado_at`, el cliente que lo refirió, el contexto tal cual y el admin que lo cargó;
     * hay UN evento `lead_alta_externa` en el hilo que dice «referido por»; y no se mandó ningún WhatsApp.
     *
     * @return void
     */
    public function test_alta_de_referido_completa(): void
    {
        $this->exigir_columnas_de_referido();
        $cliente = $this->crear_cliente_que_refiere();
        $cuerpo  = $this->cuerpo_de_alta_externa(['referido_por_client_id' => (int) $cliente->id]);

        $respuesta = $this->postJson(self::ENDPOINT, $cuerpo);

        $respuesta->assertStatus(201);
        $respuesta->assertJsonStructure(['model' => ['id'], 'primer_contacto']);

        $lead = Lead::query()->findOrFail((int) $respuesta->json('model.id'));
        $this->assertSame('referido', $lead->origen, 'El origen que se cargó queda en el lead.');
        $this->assertNotNull($lead->origen_calculado_at, 'El origen puesto a mano cuenta como calculado: nadie lo pisa después.');
        $this->assertSame((int) $cliente->id, (int) $lead->referido_por_client_id);
        $this->assertSame($cuerpo['contexto_previo'], $lead->contexto_previo, 'El contexto previo se guarda tal cual vino.');
        $this->assertSame((int) $this->admin->id, (int) $lead->created_by_admin_id, 'Queda registrado quién lo cargó.');
        $this->assertSame('Ro', $lead->contact_name);
        $this->assertSame(WhatsappNormalizer::normalize($cuerpo['phone']), WhatsappNormalizer::normalize((string) $lead->phone), 'El teléfono queda normalizado.');
        $this->assertSame('nuevo', $lead->status, 'El estado inicial por defecto es «nuevo» (decisión P5 de Lucas).');

        $eventos = $this->eventos_del_hilo($lead, 'lead_alta_externa');
        $this->assertCount(1, $eventos, 'Tiene que quedar UN evento «lead_alta_externa» en el hilo (hace que el onboarding genérico no corra).');
        $this->assertStringContainsString('referido por', (string) $eventos[0]->content);
        $this->assertStringContainsString('CF', (string) $eventos[0]->content, 'El evento nombra a quien lo refirió.');

        $this->assertSame([], $this->envios->plantillas, 'Dar de alta no manda ninguna plantilla.');
        $this->assertSame([], $this->envios->textos, 'Dar de alta no manda ningún texto.');
        $this->assertSame(
            0,
            LeadMessage::query()->where('lead_id', (int) $lead->id)->where('sender', 'sistema')->where('is_status_event', false)->count(),
            'Ningún mensaje saliente del sistema: el lead todavía no habló con nosotros por WhatsApp.'
        );
    }

    // ------------------------------------------------------------------------------------------
    // CA2 — validaciones, duplicado y completar
    // ------------------------------------------------------------------------------------------

    /**
     * CA2 (primera parte). Sin teléfono, sin nombre, con un origen que no es externo o con un contexto de 60.001
     * caracteres: 422 con `errors.<campo>` y ningún lead nuevo. Un teléfono que ya tiene lead: 409
     * `{code: lead_existente, lead_id}` y la cantidad de leads no cambia.
     *
     * @return void
     */
    public function test_validaciones_y_duplicado(): void
    {
        $this->exigir_columnas_de_referido();
        $antes = Lead::query()->count();

        $sin_telefono = $this->cuerpo_de_alta_externa();
        unset($sin_telefono['phone']);
        $this->postJson(self::ENDPOINT, $sin_telefono)->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);

        $sin_nombre = $this->cuerpo_de_alta_externa();
        unset($sin_nombre['contact_name']);
        $this->postJson(self::ENDPOINT, $sin_nombre)->assertStatus(422)->assertJsonStructure(['errors' => ['contact_name']]);

        $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(['origen' => 'landing']))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['origen']]);

        $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(['contexto_previo' => str_repeat('a', 60001)]))
            ->assertStatus(422)->assertJsonStructure(['errors' => ['contexto_previo']]);

        $this->assertSame($antes, Lead::query()->count(), 'Un pedido inválido no crea ningún lead.');

        /* Duplicado: el primero entra, el segundo con el mismo teléfono (escrito de otra forma) es 409. */
        $primero = $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(['phone' => '+54 9 3484 52-1016']));
        $primero->assertStatus(201);
        $despues_del_primero = Lead::query()->count();

        $repetido = $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(['phone' => '3484 521016', 'contact_name' => 'Otra Ro']));
        $repetido->assertStatus(409);
        $repetido->assertJsonPath('code', 'lead_existente');
        $repetido->assertJsonPath('lead_id', (int) $primero->json('model.id'));
        $this->assertSame($despues_del_primero, Lead::query()->count(), 'El 409 no crea otro lead.');
    }

    /**
     * CA2 (segunda parte). `completar_existente=true` completa ESE lead: 200 y mismo `lead_id`. El origen pasa de
     * null (o `desconocido`) a `referido`, pero un lead con `origen=anuncio_whatsapp` lo conserva. El contexto es
     * el anterior + el encabezado `— dd/mm/aaaa HH:MM (referido) —` + el nuevo (se agrega, no se reemplaza). El
     * cliente que lo refirió se escribe si estaba vacío.
     *
     * @return void
     */
    public function test_completar_existente_agrega_contexto(): void
    {
        $this->exigir_columnas_de_referido();
        $cliente = $this->crear_cliente_que_refiere();
        $ahora   = Carbon::now(config('app.timezone'))->format('d/m/Y H:i');

        /* Caso 1: el lead de Ro ya existía sin origen y con una nota de contexto vieja. */
        $existente = $this->crear_lead_del_tablero('nuevo', [
            'phone' => '+5493484521016', 'origen' => null, 'contexto_previo' => 'Texto anterior que ya estaba cargado.',
        ]);
        $cuerpo = $this->cuerpo_de_alta_externa([
            'referido_por_client_id' => (int) $cliente->id,
            'contexto_previo'        => 'Texto nuevo que llegó por el WhatsApp de Lucas.',
            'completar_existente'    => true,
        ]);
        $antes = Lead::query()->count();

        $respuesta = $this->postJson(self::ENDPOINT, $cuerpo);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('model.id', (int) $existente->id);
        $this->assertSame($antes, Lead::query()->count(), 'Completar no crea otro lead.');

        $lead = $existente->fresh();
        $this->assertSame('referido', $lead->origen, 'Un lead sin origen toma el que se cargó.');
        $this->assertSame((int) $cliente->id, (int) $lead->referido_por_client_id, 'Se escribe quién lo refirió si estaba vacío.');
        $contexto = (string) $lead->contexto_previo;
        $this->assertSame(0, strpos($contexto, 'Texto anterior que ya estaba cargado.'), 'Lo anterior queda primero y entero.');
        $posicion_del_encabezado = strpos($contexto, '— ' . $ahora . ' (referido) —');
        $this->assertNotFalse($posicion_del_encabezado, 'Lo nuevo va con el encabezado «— dd/mm/aaaa HH:MM (origen) —».');
        $posicion_del_texto_nuevo = strpos($contexto, 'Texto nuevo que llegó por el WhatsApp de Lucas.');
        $this->assertNotFalse($posicion_del_texto_nuevo);
        $this->assertGreaterThan($posicion_del_encabezado, $posicion_del_texto_nuevo, 'El texto nuevo viene después de su encabezado.');

        /* Caso 2: un lead que llegó por un anuncio conserva su origen (el dato más antiguo es el cierto). */
        $del_anuncio = $this->crear_lead_del_tablero('contactado', ['phone' => '+5493511234567', 'origen' => 'anuncio_whatsapp']);
        $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(['phone' => '+5493511234567', 'completar_existente' => true]))->assertStatus(200);
        $this->assertSame('anuncio_whatsapp', $del_anuncio->fresh()->origen, 'Un origen ya calculado no se pisa.');
        $this->assertStringContainsString('(referido)', (string) $del_anuncio->fresh()->contexto_previo, 'Igual se agrega el contexto, con el origen del aviso.');

        /* Caso 3: `desconocido` cuenta como «sin origen» y toma el nuevo. */
        $desconocido = $this->crear_lead_del_tablero('nuevo', ['phone' => '+5493415550000', 'origen' => 'desconocido']);
        $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(['phone' => '+5493415550000', 'completar_existente' => true]))->assertStatus(200);
        $this->assertSame('referido', $desconocido->fresh()->origen);
    }

    // ------------------------------------------------------------------------------------------
    // CA3 — tablero de Juan
    // ------------------------------------------------------------------------------------------

    /**
     * CA3. `estado_inicial=link_enviado` deja una tarjeta abierta en «Interesados» con la nota «Entró solo: llegó
     * por referido…»; `nuevo` + `entrar_al_tablero=true` también; `nuevo` sin la marca no deja ninguna tarjeta.
     *
     * @return void
     */
    public function test_entra_al_tablero_segun_estado(): void
    {
        $this->exigir_columnas_de_referido();

        $casos = [
            'link_enviado'     => [['estado_inicial' => 'link_enviado'], true],
            'nuevo con la marca' => [['estado_inicial' => 'nuevo', 'entrar_al_tablero' => true], true],
            'nuevo sin la marca' => [['estado_inicial' => 'nuevo'], false],
        ];

        foreach ($casos as $nombre => $caso) {
            $telefono  = $this->telefono_tipeado_unico();
            $respuesta = $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(array_merge($caso[0], ['phone' => $telefono])));
            $respuesta->assertStatus(201);

            $lead     = Lead::query()->findOrFail((int) $respuesta->json('model.id'));
            $tarjetas = $this->tarjetas_del_lead($lead);

            if (! $caso[1]) {
                $this->assertCount(0, $tarjetas, $nombre . ': sin la marca no hay tarjeta.');
                continue;
            }

            $this->assertCount(1, $tarjetas, $nombre . ': tiene que haber UNA tarjeta.');
            $this->assertNull($tarjetas[0]->fresh()->closed_at, $nombre . ': la tarjeta está abierta.');
            $this->assertSame('interesados', $this->clave_de_la_columna($tarjetas[0]), $nombre . ': entra en «Interesados».');

            $nota = \App\Models\PipelineActivity::query()
                ->where('opportunity_id', (int) $tarjetas[0]->id)->where('type', 'created')->first();
            $this->assertNotNull($nota, $nombre . ': queda la actividad de alta.');
            $this->assertStringStartsWith('Entró solo: llegó por referido', (string) $nota->body, $nombre . ': la nota dice por qué entró.');
        }
    }

    // ------------------------------------------------------------------------------------------
    // CA6 — primer contacto por plantilla
    // ------------------------------------------------------------------------------------------

    /**
     * CA6, camino feliz. Con `lead_referido_plantilla=cc_referido_v1` registrada, activa y APPROVED, y
     * `primer_contacto=plantilla_referido`: `send_template` se llama UNA vez con el nombre de la plantilla y las
     * variables [primer nombre del lead, quien lo refirió], y queda un `LeadMessage` enviado con
     * `system_message_kind = referido_primer_contacto`.
     *
     * @return void
     */
    public function test_primer_contacto_por_plantilla_aprobada(): void
    {
        $this->exigir_columnas_de_referido();
        $cliente = $this->crear_cliente_que_refiere();
        AdminSetting::set('lead_referido_plantilla', 'cc_referido_v1');
        $this->registrar_plantilla('cc_referido_v1', 'APPROVED', true);

        $respuesta = $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa([
            'referido_por_client_id' => (int) $cliente->id,
            'primer_contacto'        => 'plantilla_referido',
        ]));

        $respuesta->assertStatus(201);
        $respuesta->assertJsonStructure(['primer_contacto']);

        $this->assertCount(1, $this->envios->plantillas, 'Sale UNA plantilla.');
        $envio = $this->envios->plantillas[0];
        $this->assertSame('cc_referido_v1', $envio['template']);
        $this->assertStringContainsString('3484521016', preg_replace('/\D+/', '', $envio['to']), 'Va al teléfono del lead.');
        $this->assertCount(2, $envio['variables'], 'Dos variables: {{1}} el nombre del lead y {{2}} quien lo refirió.');
        $this->assertSame('Ro', $envio['variables'][0]);
        $this->assertStringContainsString('CF', (string) $envio['variables'][1], '{{2}} es el cliente que lo refirió.');
        $this->assertSame([], $this->envios->textos, 'No se manda texto libre: la ventana de 24 h está cerrada.');

        $lead    = Lead::query()->findOrFail((int) $respuesta->json('model.id'));
        $enviado = LeadMessage::query()
            ->where('lead_id', (int) $lead->id)->where('system_message_kind', 'referido_primer_contacto')->get();
        $this->assertCount(1, $enviado, 'Queda UN mensaje en el hilo con el primer contacto.');
        $this->assertSame('enviado', $enviado[0]->status);
        $this->assertSame('sistema', $enviado[0]->sender);
    }

    /**
     * CA6, caminos que NO envían. Sin nombre configurado, con una plantilla que no está registrada o con una que
     * Meta todavía no aprobó: el lead se crea igual (201), `primer_contacto.estado` es `no_enviado` con el motivo
     * (`nombre_vacio`, `plantilla_no_registrada`, `plantilla_no_aprobada`) y no sale nada.
     *
     * @return void
     */
    public function test_primer_contacto_sin_plantilla_no_envia(): void
    {
        $this->exigir_columnas_de_referido();

        $casos = [
            'nombre_vacio'            => '',
            'plantilla_no_registrada' => 'cc_referido_que_no_existe',
            'plantilla_no_aprobada'   => 'cc_referido_pendiente',
        ];
        $this->registrar_plantilla('cc_referido_pendiente', 'PENDING', true);

        foreach ($casos as $motivo => $nombre_configurado) {
            AdminSetting::set('lead_referido_plantilla', $nombre_configurado);
            $antes = Lead::query()->count();

            $respuesta = $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa([
                'phone'           => $this->telefono_tipeado_unico(),
                'primer_contacto' => 'plantilla_referido',
            ]));

            $respuesta->assertStatus(201);
            $respuesta->assertJsonPath('primer_contacto.estado', 'no_enviado');
            $respuesta->assertJsonPath('primer_contacto.motivo', $motivo);
            $this->assertSame($antes + 1, Lead::query()->count(), $motivo . ': el lead se crea igual.');
            $this->assertSame([], $this->envios->plantillas, $motivo . ': no sale ninguna plantilla.');
            $this->assertSame([], $this->envios->textos, $motivo . ': no sale ningún texto.');
            $this->assertCount(
                0,
                LeadMessage::query()->where('system_message_kind', 'referido_primer_contacto')->get(),
                $motivo . ': no queda un primer contacto en el hilo.'
            );
        }
    }
}
