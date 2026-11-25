<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Models\AdminSetting;
use App\Models\FollowupRule;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadFollowupService;

/**
 * Correcciones de la ronda 2 de la etapa E1 (misión leads-referidos-compromisos), pedidas por las tres revisiones
 * independientes de la ronda 1. Cada test fija UNO de los cinco puntos y nace EN ROJO:
 *
 *  1. Juan (perfil «Sólo Leads») puede dar de alta, pero NO disparar el primer contacto por WhatsApp.
 *  2. El primer contacto por plantilla no sale si el lead no recibe mensajes, ya es cliente o ya tuvo uno.
 *  3. Un teléfono argentino tipeado con el 15 se guarda en la forma canónica que manda Meta (o es 422).
 *  4. El cron viejo de seguimientos no le manda las plantillas genéricas a un lead de otro medio.
 *  5. Completar un lead que ya tenía origen real con contexto y referente: Martín y la ficha lo ven.
 */
class CorreccionesDeLaRevisionDeE1Test extends BaseDeReferidosYCompromisos
{
    /** Endpoint del panel para dar de alta un lead de otro medio. */
    private const ENDPOINT = '/api/admin/lead/externo';

    /**
     * Deja la plantilla de referido aprobada y configurada (lo que habilita el primer contacto).
     *
     * @return void
     */
    private function dejar_la_plantilla_de_referido_aprobada(): void
    {
        $this->registrar_plantilla('cc_referido_v1', 'APPROVED', true);
        AdminSetting::set('lead_referido_plantilla', 'cc_referido_v1');
    }

    // ------------------------------------------------------------------------------------------
    // Punto 1 — Juan no dispara el envío
    // ------------------------------------------------------------------------------------------

    /**
     * Juan puede usar `POST admin/lead/externo` (alta común: 201), pero con `primer_contacto=plantilla_referido`
     * recibe 422 `errors.primer_contacto` y no sale nada; tampoco al completar un lead que ya existía.
     *
     * @return void
     */
    public function test_juan_da_de_alta_pero_no_dispara_el_envio(): void
    {
        $this->exigir_columnas_de_referido();
        $this->dejar_la_plantilla_de_referido_aprobada();
        $cliente = $this->crear_cliente_que_refiere();
        $this->loguear_a($this->juan);

        $alta = $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(['referido_por_client_id' => (int) $cliente->id]));
        $alta->assertStatus(201);
        $this->assertSame([], $this->envios->plantillas, 'El alta común de Juan no manda nada.');

        $antes = Lead::query()->count();
        $con_envio = $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa([
            'phone' => $this->telefono_tipeado_unico(), 'referido_por_client_id' => (int) $cliente->id, 'primer_contacto' => 'plantilla_referido',
        ]));
        $con_envio->assertStatus(422)->assertJsonStructure(['errors' => ['primer_contacto']]);
        $this->assertSame($antes, Lead::query()->count(), 'El pedido rechazado no crea el lead.');

        $existente = $this->crear_lead_del_tablero('nuevo', ['phone' => '+5493511234567']);
        $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa([
            'phone' => '+5493511234567', 'referido_por_client_id' => (int) $cliente->id, 'primer_contacto' => 'plantilla_referido', 'completar_existente' => true,
        ]))->assertStatus(422);

        $this->assertSame([], $this->envios->plantillas, 'Juan no le escribe al lead por esta puerta.');
        $this->assertSame(0, LeadMessage::query()->where('lead_id', (int) $existente->id)->where('system_message_kind', 'referido_primer_contacto')->count());
    }

    // ------------------------------------------------------------------------------------------
    // Punto 2 — casos en que el primer contacto no sale
    // ------------------------------------------------------------------------------------------

    /**
     * Completando un lead que no recibe mensajes, que ya es cliente o que ya tuvo su primer contacto: 200 con
     * `primer_contacto = {estado: no_enviado, motivo}` y ninguna plantilla sale.
     *
     * @return void
     */
    public function test_primer_contacto_no_sale_si_no_corresponde(): void
    {
        $this->exigir_columnas_de_referido();
        $this->dejar_la_plantilla_de_referido_aprobada();
        $cliente = $this->crear_cliente_que_refiere();
        $this->loguear_a($this->admin);

        $casos = [
            'no_recibe_mensajes'          => ['+5493511110001', ['no_recibe_mensajes_at' => \Carbon\Carbon::now()]],
            'ya_es_cliente'               => ['+5493511110002', ['status' => 'cerrado_ganado']],
            'ya_es_cliente '              => ['+5493511110003', ['promoted_client_id' => (int) $cliente->id]],
        ];

        foreach ($casos as $motivo => $caso) {
            $this->crear_lead_del_tablero('nuevo', array_merge(['phone' => $caso[0]], $caso[1]));
            $respuesta = $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa([
                'phone' => $caso[0], 'referido_por_client_id' => (int) $cliente->id, 'primer_contacto' => 'plantilla_referido', 'completar_existente' => true,
            ]));
            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('primer_contacto.estado', 'no_enviado');
            $respuesta->assertJsonPath('primer_contacto.motivo', trim($motivo));
        }
        $this->assertSame([], $this->envios->plantillas, 'En ninguno de los tres casos sale una plantilla.');

        /* Segundo intento sobre un lead que ya recibió su primer contacto: no sale dos veces. */
        $cuerpo = $this->cuerpo_de_alta_externa([
            'phone' => '+5493511110004', 'referido_por_client_id' => (int) $cliente->id, 'primer_contacto' => 'plantilla_referido',
        ]);
        $this->postJson(self::ENDPOINT, $cuerpo)->assertStatus(201);
        $this->assertCount(1, $this->envios->plantillas, 'El primero sí sale.');
        $segundo = $this->postJson(self::ENDPOINT, array_merge($cuerpo, ['completar_existente' => true]));
        $segundo->assertStatus(200);
        $segundo->assertJsonPath('primer_contacto.estado', 'no_enviado');
        $segundo->assertJsonPath('primer_contacto.motivo', 'primer_contacto_ya_enviado');
        $this->assertCount(1, $this->envios->plantillas, 'Pedirlo otra vez no manda un segundo mensaje.');
    }

    // ------------------------------------------------------------------------------------------
    // Punto 3 — teléfono con el 15
    // ------------------------------------------------------------------------------------------

    /**
     * Un número argentino tipeado con el 15 se guarda como `+549` + 10 dígitos (lo que manda Meta); uno que no se
     * puede deducir da 422 `errors.phone`. Y el mismo número escrito en forma canónica es un duplicado (409).
     *
     * @return void
     */
    public function test_telefono_con_el_15_queda_en_la_forma_que_manda_meta(): void
    {
        $this->exigir_columnas_de_referido();
        $this->loguear_a($this->admin);

        $casos = [
            '3484 15 521016'    => '+5493484521016',
            '03484-15-521016'   => '+5493484521016',
            '11 15 2345 6789'   => '+5491123456789',
        ];
        foreach ($casos as $tipeado => $canonico) {
            $respuesta = $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(['phone' => $tipeado, 'completar_existente' => false]));
            $respuesta->assertStatus(201);
            $lead = Lead::query()->findOrFail((int) $respuesta->json('model.id'));
            $this->assertSame($canonico, (string) $lead->phone, $tipeado . ' se guarda como lo manda Meta.');
            $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(['phone' => $canonico]))->assertStatus(409);
        }

        $antes = Lead::query()->count();
        $sin_area = $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(['phone' => '15 2345 6789']));
        $sin_area->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);
        $this->assertStringContainsString('15', (string) $sin_area->json('errors.phone.0'), 'El mensaje explica lo del 15.');
        $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa(['phone' => '12345']))->assertStatus(422);
        $this->assertSame($antes, Lead::query()->count());
    }

    // ------------------------------------------------------------------------------------------
    // Punto 4 — el cron viejo no toca a un lead externo
    // ------------------------------------------------------------------------------------------

    /**
     * Un lead de otro medio creado hace más de 48 h, sin respuesta, NO recibe la plantilla genérica del cron viejo
     * ni se pausa; el control (un lead común en la misma situación) sí la recibe, así el test mide lo que dice.
     *
     * @return void
     */
    public function test_el_cron_viejo_no_le_manda_plantillas_genericas_al_lead_externo(): void
    {
        $this->exigir_columnas_de_referido();
        $this->loguear_a($this->admin);
        FollowupRule::query()->updateOrCreate(['estado' => 'nuevo'], ['horas_espera' => 48, 'max_followups' => 1, 'activa' => true]);
        FollowupTemplate::query()->where('estado', 'nuevo')->update(['activa' => false]);
        FollowupTemplate::create([
            'template_name' => 'cc_seg_prueba_nuevo_d1', 'estado' => 'nuevo', 'dia_numero' => 1, 'language_code' => 'es_AR',
            'body_template' => 'Hola {{1}}! Te escribo de ComercioCity.', 'activa' => true, 'meta_status' => 'APPROVED',
        ]);

        $respuesta = $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa());
        $respuesta->assertStatus(201);
        $externo = Lead::query()->findOrFail((int) $respuesta->json('model.id'));
        $comun   = $this->crear_lead_del_tablero('nuevo', ['phone' => '+5493511119999', 'origen' => 'desconocido']);

        $this->avanzar(72 * 3600);

        $servicio = app(LeadFollowupService::class);
        $resultado = $servicio->process_single_lead($externo->fresh());
        $this->assertNull($resultado, 'El lead externo no es del cron viejo.');
        $this->assertSame([], $this->envios->plantillas, 'No sale ninguna plantilla genérica.');
        $this->assertSame(0, LeadMessage::query()->where('lead_id', (int) $externo->id)->where('is_followup', true)->count());
        $this->assertSame('nuevo', $externo->fresh()->status, 'Tampoco se lo pausa.');

        $this->assertSame('suggestion', $servicio->process_single_lead($comun->fresh()), 'Control: el lead común sigue siendo del cron.');
        $this->assertCount(1, $this->envios->plantillas, 'Control: al común le sale su plantilla.');
    }

    // ------------------------------------------------------------------------------------------
    // Punto 5 — completar un lead con origen real
    // ------------------------------------------------------------------------------------------

    /**
     * Completar un lead que llegó por un anuncio con contexto y referente: se guardan, Martín los ve en su contexto
     * (aunque el origen no sea externo) y la ficha (`GET admin/lead/{id}`) trae quién lo refirió.
     *
     * @return void
     */
    public function test_completar_un_lead_con_origen_real_lo_ven_martin_y_la_ficha(): void
    {
        $this->exigir_columnas_de_referido();
        $this->loguear_a($this->admin);
        $cliente = $this->crear_cliente_que_refiere();
        $lead = $this->crear_lead_del_tablero('contactado', ['phone' => '+5493511117777', 'origen' => 'anuncio_whatsapp']);

        $this->postJson(self::ENDPOINT, $this->cuerpo_de_alta_externa([
            'phone' => '+5493511117777', 'completar_existente' => true, 'referido_por_client_id' => (int) $cliente->id,
            'contexto_previo' => 'Me contó que tiene tres sucursales y usa planillas.',
        ]))->assertStatus(200);

        $this->assertSame('anuncio_whatsapp', $lead->fresh()->origen, 'El origen real se conserva.');
        $contexto = $this->contexto_del_turno($lead->fresh());
        $this->assertStringContainsString('tres sucursales', $contexto, 'Martín ve lo que se agregó.');
        $this->assertStringContainsString('CF', $contexto, 'Martín ve quién lo refirió.');

        $ficha = $this->getJson('/api/admin/lead/' . $lead->id);
        $ficha->assertStatus(200);
        $this->assertSame((int) $cliente->id, (int) $ficha->json('model.referido_por_client.id'), 'La ficha trae quién lo refirió.');
        $this->assertStringContainsString('tres sucursales', (string) $ficha->json('model.contexto_previo'));
    }
}
