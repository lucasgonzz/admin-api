<?php

namespace Tests\Feature\Pipelines;

use App\Models\Admin;
use App\Models\AdminCalendarConnection;
use App\Models\Client;
use App\Models\PipelineOpportunity;
use App\Services\Pipelines\PipelineOpportunityService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * La próxima acción de una oportunidad se sincroniza con el Google Calendar del admin que la fija
 * (misión pipelines-calendario-proxima-accion, 5/10/2026).
 *
 * Lo que se protege, pegándole a los endpoints REALES con los payloads que manda la SPA y mirando
 * (a) el estado que queda en la base y (b) las llamadas que salieron hacia Google (fakeadas, nada
 * sale a la red):
 *  1. Con hora → evento `opaque` de 30 minutos, con la hora LOCAL tal cual está guardada (la
 *     trampa de las tres horas), sin invitados ni Meet.
 *  2. Sin hora → evento de día completo y `transparent`: uno opaco bloquearía todos los slots de
 *     demo del día (los lee `CloserGoogleCalendarBusyService` sobre este mismo calendario).
 *  3. El evento SIGUE a la próxima acción: PATCH al cambiarla, DELETE al borrarla, al cerrar la
 *     oportunidad o al borrarla; si cambia de manos, se borra del calendario del primero.
 *  4. Best-effort: sin calendario vinculado no hay ni una llamada, y cualquier falla de Google deja
 *     el guardado del admin exactamente como siempre.
 *  5. Un 422 no llega a Google, y mover conservando la acción no hace llamadas por nada.
 */
class CalendarioDeProximaAccionTest extends BaseDePipelines
{
    /** Id de calendario del admin "principal" de las pruebas. Con `@` y puntos, como los reales. */
    const CALENDARIO = 'agenda-lucas@group.calendar.google.com';

    /** Id de calendario de un segundo admin. */
    const CALENDARIO_OTRO = 'agenda-otro@group.calendar.google.com';

    /** Base de la API de eventos. */
    const URL_CALENDARIOS = 'https://www.googleapis.com/calendar/v3/calendars/';

    /**
     * Estado HTTP con el que contesta el Google fakeado a cada método (se pisa por prueba).
     *
     * @var array<string, int>
     */
    private $estado_de_google = [];

    /**
     * Si el refresco del token tiene que dar `invalid_grant` (token revocado).
     *
     * @var bool
     */
    private $token_revocado = false;

    /**
     * Contador de ids de evento que inventa el Google fakeado (`evt-1`, `evt-2`, ...).
     *
     * @var int
     */
    private $contador_de_eventos = 0;

    /**
     * Cambia el `Http::fake` del base (que contesta todo vacío) por uno que se comporta como la API
     * de calendarios de Google: token, POST (crea y devuelve un id), PATCH y DELETE.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->estado_de_google    = ['POST' => 200, 'PATCH' => 200, 'DELETE' => 204];
        $this->token_revocado      = false;
        $this->contador_de_eventos = 0;

        Http::swap(new Factory());
        Http::fake(function ($request) {
            return $this->responder_como_google($request);
        });
    }

    /* ------------------------------------------------------------------------------------------
     | 1. Con hora: evento opaco, hora local sin convertir
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. Fijar una próxima acción con hora, con el calendario vinculado: un POST al calendario
     *    correcto con la hora local tal cual, 30 minutos, opaque y sin invitados; el id y el admin
     *    quedan guardados y la respuesta al admin es la de siempre.
     *
     * @return void
     */
    public function test_proxima_accion_con_hora_crea_evento_opaco_con_la_hora_local(): void
    {
        $admin = $this->admin_logueado('Lucas');
        $this->conectar_calendario($admin);

        $pipeline = $this->crear_pipeline();
        $cliente  = $this->crear_cliente();
        $op       = $this->alta_de_uno($pipeline, $cliente);

        $this->assertSame([], $this->llamadas_a_google(), 'Un alta sin próxima acción no toca Google.');

        $respuesta = $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00', 'next_action_note' => 'Llamar']);

        /* La respuesta al admin es la de siempre. */
        $respuesta->assertStatus(200);
        $this->assertSame('2026-09-30 15:00:00', $respuesta->json('opportunity.next_action_at'));
        $this->assertSame('Llamar', $respuesta->json('opportunity.next_action_note'));
        $this->assertCount(1, $respuesta->json('activities'));

        /* Una sola llamada: el POST, al calendario del admin. */
        $llamadas = $this->llamadas_a_google();
        $this->assertCount(1, $llamadas);
        $this->assertSame('POST', $llamadas[0]->method());
        $this->assertSame($this->url_eventos(self::CALENDARIO), $llamadas[0]->url(), 'Sin query string: nada de sendUpdates ni conferenceDataVersion.');
        $this->assertSame(['Bearer tok-de-prueba'], $llamadas[0]->header('Authorization'));

        $cuerpo = $llamadas[0]->data();
        $this->assertSame('Llamar — ' . $cliente->name, $cuerpo['summary']);
        $this->assertSame(
            ['dateTime' => '2026-09-30T15:00:00', 'timeZone' => 'America/Argentina/Buenos_Aires'],
            $cuerpo['start'],
            'La hora local tal cual está guardada, sin convertir a UTC.'
        );
        $this->assertSame(
            ['dateTime' => '2026-09-30T15:30:00', 'timeZone' => 'America/Argentina/Buenos_Aires'],
            $cuerpo['end'],
            'Dura 30 minutos.'
        );
        $this->assertSame('opaque', $cuerpo['transparency']);
        $this->assertArrayNotHasKey('attendees', $cuerpo);
        $this->assertArrayNotHasKey('conferenceData', $cuerpo);
        $this->assertStringContainsString('Etapa: Por contactar', $cuerpo['description']);
        $this->assertStringContainsString('Cliente: ' . $cliente->name, $cuerpo['description']);
        $this->assertStringContainsString('Pipeline: ' . $pipeline->name, $cuerpo['description']);
        $this->assertStringNotContainsString($cliente->phone, $cuerpo['description'], 'Sin teléfono en el evento.');
        $this->assertStringNotContainsString($cliente->email, $cuerpo['description'], 'Sin mail en el evento.');

        $guardada = $this->oportunidad($op['id']);
        $this->assertSame('evt-1', $guardada->next_action_calendar_event_id);
        $this->assertSame($admin->id, (int) $guardada->next_action_calendar_admin_id);
    }

    /**
     * 1b. Sin nota, el título es el nombre de la etapa; con un lead de sujeto, el nombre sale del
     *     mismo criterio que el tablero (la empresa).
     *
     * @return void
     */
    public function test_el_titulo_cae_al_nombre_de_la_etapa_y_el_lead_se_nombra_por_su_empresa(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $pipeline = $this->crear_pipeline();
        $lead     = $this->crear_lead();
        $op       = $this->alta_de_uno($pipeline, $lead);

        $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00'])->assertStatus(200);

        $cuerpo = $this->llamadas_a_google('POST')[0]->data();
        $this->assertSame('Por contactar — ' . $lead->company_name, $cuerpo['summary']);
        $this->assertStringContainsString('Lead: ' . $lead->company_name, $cuerpo['description']);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. Día completo: transparent
     |----------------------------------------------------------------------------------------- */

    /**
     * 2. Una fecha sin hora es un evento de día completo (`date`, y el `end` es el día siguiente)
     *    y TRANSPARENT: no saca los slots de demo de ese día.
     *
     * @return void
     */
    public function test_fecha_sin_hora_es_un_evento_de_dia_completo_transparente(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $op = $this->alta_de_uno($this->crear_pipeline(), $this->crear_cliente());

        $this->actualizar($op['id'], ['next_action_at' => '2026-10-02', 'next_action_note' => 'Retomar'])->assertStatus(200);

        $llamadas = $this->llamadas_a_google();
        $this->assertCount(1, $llamadas);
        $cuerpo = $llamadas[0]->data();

        $this->assertSame(['date' => '2026-10-02'], $cuerpo['start']);
        $this->assertSame(['date' => '2026-10-03'], $cuerpo['end'], 'El fin de un evento de día completo es exclusivo: el día siguiente.');
        $this->assertSame('transparent', $cuerpo['transparency']);
        $this->assertSame('evt-1', $this->oportunidad($op['id'])->next_action_calendar_event_id);
    }

    /* ------------------------------------------------------------------------------------------
     | 3. El evento sigue a la próxima acción
     |----------------------------------------------------------------------------------------- */

    /**
     * 3. Cambiar la fecha es un PATCH al MISMO evento (no un segundo POST) y el id no cambia. Pasar de
     *    "con hora" a "día completo" anula `dateTime` en el PATCH (si no, el evento quedaría con
     *    los dos formatos) y lo vuelve transparente.
     *
     * @return void
     */
    public function test_cambiar_la_fecha_actualiza_el_mismo_evento(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $op  = $this->alta_de_uno($this->crear_pipeline(), $this->crear_cliente());
        $url = $this->url_eventos(self::CALENDARIO);

        $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00', 'next_action_note' => 'Llamar'])->assertStatus(200);
        $this->actualizar($op['id'], ['next_action_at' => '2026-10-01 09:30'])->assertStatus(200);

        $this->assertCount(1, $this->llamadas_a_google('POST'), 'No se crea un segundo evento.');
        $parches = $this->llamadas_a_google('PATCH');
        $this->assertCount(1, $parches);
        $this->assertSame($url . '/evt-1', $parches[0]->url());
        $this->assertSame('2026-10-01T09:30:00', $parches[0]->data()['start']['dateTime']);
        $this->assertSame('2026-10-01T10:00:00', $parches[0]->data()['end']['dateTime']);
        $this->assertSame('opaque', $parches[0]->data()['transparency']);
        $this->assertSame('evt-1', $this->oportunidad($op['id'])->next_action_calendar_event_id);

        /* De "con hora" a "día completo". */
        $this->actualizar($op['id'], ['next_action_at' => '2026-10-05'])->assertStatus(200);

        $parches = $this->llamadas_a_google('PATCH');
        $this->assertCount(2, $parches);
        $cuerpo = $parches[1]->data();
        $this->assertSame('2026-10-05', $cuerpo['start']['date']);
        $this->assertNull($cuerpo['start']['dateTime'], 'El PATCH anula el formato anterior.');
        $this->assertNull($cuerpo['start']['timeZone']);
        $this->assertSame('2026-10-06', $cuerpo['end']['date']);
        $this->assertNull($cuerpo['end']['dateTime']);
        $this->assertSame('transparent', $cuerpo['transparency']);

        /* Cambiar solo la nota también actualiza (el título del evento es la nota). */
        $this->actualizar($op['id'], ['next_action_note' => 'Mandar presupuesto'])->assertStatus(200);
        $parches = $this->llamadas_a_google('PATCH');
        $this->assertCount(3, $parches);
        $this->assertSame('Mandar presupuesto — ' . $this->nombre_del_cliente($op['id']), $parches[2]->data()['summary']);
    }

    /**
     * 4. Borrar la próxima acción (`next_action_at: null`): DELETE del evento y las dos columnas en
     *    null.
     *
     * @return void
     */
    public function test_borrar_la_proxima_accion_borra_el_evento(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $op = $this->alta_de_uno($this->crear_pipeline(), $this->crear_cliente());

        $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00', 'next_action_note' => 'Llamar'])->assertStatus(200);
        $this->assertSame('evt-1', $this->oportunidad($op['id'])->next_action_calendar_event_id);

        $this->actualizar($op['id'], ['next_action_at' => null])->assertStatus(200);

        $borrados = $this->llamadas_a_google('DELETE');
        $this->assertCount(1, $borrados);
        $this->assertSame($this->url_eventos(self::CALENDARIO) . '/evt-1', $borrados[0]->url());

        $guardada = $this->oportunidad($op['id']);
        $this->assertNull($guardada->next_action_at);
        $this->assertNull($guardada->next_action_calendar_event_id);
        $this->assertNull($guardada->next_action_calendar_admin_id);
    }

    /**
     * 5. Mover a ganada o a perdida limpia la próxima acción (el servicio ya lo hace) y por lo tanto
     *    borra el evento.
     *
     * @return void
     */
    public function test_mover_a_ganada_o_perdida_borra_el_evento(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $pipeline = $this->crear_pipeline();
        $ganada   = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $perdida  = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->actualizar($ganada['id'], ['next_action_at' => '2026-09-30 15:00'])->assertStatus(200);
        $this->actualizar($perdida['id'], ['next_action_at' => '2026-10-01 11:00'])->assertStatus(200);
        $this->assertSame('evt-1', $this->oportunidad($ganada['id'])->next_action_calendar_event_id);
        $this->assertSame('evt-2', $this->oportunidad($perdida['id'])->next_action_calendar_event_id);

        $this->mover($ganada['id'], ['stage_id' => $this->etapa($pipeline, 'Ganado')->id])->assertStatus(200);
        $this->mover($perdida['id'], ['stage_id' => $this->etapa($pipeline, 'Perdido')->id, 'lost_reason' => 'Precio'])->assertStatus(200);

        $borrados = $this->llamadas_a_google('DELETE');
        $this->assertCount(2, $borrados);
        $this->assertSame($this->url_eventos(self::CALENDARIO) . '/evt-1', $borrados[0]->url());
        $this->assertSame($this->url_eventos(self::CALENDARIO) . '/evt-2', $borrados[1]->url());

        foreach ([$ganada, $perdida] as $cerrada) {
            $guardada = $this->oportunidad($cerrada['id']);
            $this->assertNull($guardada->next_action_at);
            $this->assertNull($guardada->next_action_calendar_event_id);
            $this->assertNull($guardada->next_action_calendar_admin_id);
        }
    }

    /**
     * 6. Mover a una etapa con campo agenda (fecha y hora) fija la próxima acción y crea el evento
     *    (POST); mover CONSERVANDO una acción manual futura no hace ninguna llamada nueva.
     *
     * @return void
     */
    public function test_mover_a_una_etapa_con_agenda_crea_y_conservar_no_llama(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $pipeline = $this->crear_pipeline();
        $cliente  = $this->crear_cliente();
        $op       = $this->alta_de_uno($pipeline, $cliente);

        /* Agenda: la fecha de la reunión es la próxima acción. */
        $this->mover($op['id'], [
            'stage_id' => $this->etapa($pipeline, 'Reunión agendada')->id,
            'fields'   => ['fecha_reunion' => '2026-10-01 11:00'],
        ])->assertStatus(200);

        $posts = $this->llamadas_a_google('POST');
        $this->assertCount(1, $posts);
        $this->assertSame('2026-10-01T11:00:00', $posts[0]->data()['start']['dateTime']);
        $this->assertSame('Reunión agendada — ' . $cliente->name, $posts[0]->data()['summary'], 'Sin nota: el título es el nombre de la etapa destino.');
        $this->assertSame('evt-1', $this->oportunidad($op['id'])->next_action_calendar_event_id);

        /* Una manual y futura sobrevive a un movimiento que no la toca. */
        $otro = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $this->actualizar($otro['id'], ['next_action_at' => '2026-10-10 16:00', 'next_action_note' => 'Seguir'])->assertStatus(200);
        $antes = count($this->llamadas_a_google());

        $respuesta = $this->mover($otro['id'], [
            'stage_id' => $this->etapa($pipeline, 'Contactado')->id,
            'fields'   => ['canal' => 'WhatsApp'],
        ]);
        $respuesta->assertStatus(200);
        $this->assertSame('2026-10-10 16:00:00', $respuesta->json('opportunity.next_action_at'), 'La manual futura se conservó.');
        $this->assertCount($antes, $this->llamadas_a_google(), 'Conservar la próxima acción no llama a Google.');
        $this->assertSame('evt-2', $this->oportunidad($otro['id'])->next_action_calendar_event_id);
    }

    /**
     * 6b. Cambiar solo el responsable no toca el calendario.
     *
     * @return void
     */
    public function test_cambiar_solo_el_responsable_no_llama_a_google(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);
        $otro = $this->crear_admin('Otro');

        $op = $this->alta_de_uno($this->crear_pipeline(), $this->crear_cliente());
        $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00'])->assertStatus(200);
        $antes = count($this->llamadas_a_google());

        $this->actualizar($op['id'], ['owner_admin_id' => $otro->id])->assertStatus(200);
        $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00'])->assertStatus(200);

        $this->assertCount($antes, $this->llamadas_a_google());
    }

    /* ------------------------------------------------------------------------------------------
     | 7. Sin calendario: cero llamadas
     |----------------------------------------------------------------------------------------- */

    /**
     * 7. Sin conexión, con la conexión inactiva o con `google_calendar_id` vacío: NI UNA llamada a
     *    Google (ni siquiera el token) y la respuesta de siempre.
     *
     * @return void
     */
    public function test_sin_calendario_utilizable_no_hay_ninguna_llamada_a_google(): void
    {
        $pipeline = $this->crear_pipeline();

        $casos = [
            'sin conexión'            => null,
            'conexión inactiva'       => ['is_active' => false],
            'sin calendario elegido'  => ['google_calendar_id' => ''],
        ];

        foreach ($casos as $nombre => $conexion) {
            $admin = $this->admin_logueado('Admin ' . $nombre);
            if ($conexion !== null) {
                $this->conectar_calendario($admin, self::CALENDARIO, $conexion);
            }

            $op = $this->alta_de_uno($pipeline, $this->crear_cliente());

            $respuesta = $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00', 'next_action_note' => 'Llamar']);

            $respuesta->assertStatus(200);
            $this->assertSame('2026-09-30 15:00:00', $respuesta->json('opportunity.next_action_at'), $nombre);
            $guardada = $this->oportunidad($op['id']);
            $this->assertNull($guardada->next_action_calendar_event_id, $nombre);
            $this->assertNull($guardada->next_action_calendar_admin_id, $nombre);
        }

        $this->assertSame([], $this->todas_las_llamadas_a_google(), 'Ni calendario ni token.');
    }

    /* ------------------------------------------------------------------------------------------
     | 8. Google falla: el guardado no se entera
     |----------------------------------------------------------------------------------------- */

    /**
     * 8. Google contesta 500, 403 (token sin el scope de escritura) o el token está revocado
     *    (`invalid_grant`): el admin recibe 200 igual, la próxima acción queda guardada y la
     *    oportunidad queda sin evento.
     *
     * @return void
     */
    public function test_si_google_falla_el_guardado_queda_igual(): void
    {
        $pipeline = $this->crear_pipeline();

        $casos = ['500' => 500, '403' => 403, 'invalid_grant' => 'token'];

        foreach ($casos as $nombre => $falla) {
            $this->estado_de_google = ['POST' => 200, 'PATCH' => 200, 'DELETE' => 204];
            $this->token_revocado   = false;
            if ($falla === 'token') {
                $this->token_revocado = true;
            } else {
                $this->estado_de_google['POST'] = $falla;
            }

            $admin = $this->admin_logueado('Admin ' . $nombre);
            $this->conectar_calendario($admin);
            $op = $this->alta_de_uno($pipeline, $this->crear_cliente());

            $respuesta = $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00', 'next_action_note' => 'Llamar']);

            $respuesta->assertStatus(200);
            $this->assertSame('2026-09-30 15:00:00', $respuesta->json('opportunity.next_action_at'), $nombre);
            $this->assertCount(1, $respuesta->json('activities'), $nombre);

            $guardada = $this->oportunidad($op['id']);
            $this->assertSame('2026-09-30 15:00:00', $guardada->next_action_at->format('Y-m-d H:i:s'), $nombre);
            $this->assertSame('Llamar', $guardada->next_action_note, $nombre);
            $this->assertNull($guardada->next_action_calendar_event_id, $nombre);
            $this->assertNull($guardada->next_action_calendar_admin_id, $nombre);
        }
    }

    /**
     * 8b. Si Google falla al borrar, la oportunidad queda con su evento anotado (para reintentar en
     *     el próximo cambio) y el admin igual recibe 200.
     *
     * @return void
     */
    public function test_si_google_falla_al_borrar_el_evento_queda_anotado_para_reintentar(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $op = $this->alta_de_uno($this->crear_pipeline(), $this->crear_cliente());
        $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00'])->assertStatus(200);

        $this->estado_de_google['DELETE'] = 500;
        $this->actualizar($op['id'], ['next_action_at' => null])->assertStatus(200);

        $guardada = $this->oportunidad($op['id']);
        $this->assertNull($guardada->next_action_at);
        $this->assertSame('evt-1', $guardada->next_action_calendar_event_id, 'Sigue anotado: no se limpia lo que no se pudo borrar.');

        /* Cualquier cambio posterior que toque la oportunidad lo reintenta, aunque la próxima acción siga vacía. */
        $this->estado_de_google['DELETE'] = 204;
        $this->actualizar($op['id'], ['next_action_at' => null, 'owner_admin_id' => $admin->id])->assertStatus(200);

        $this->assertCount(2, $this->llamadas_a_google('DELETE'));
        $this->assertNull($this->oportunidad($op['id'])->next_action_calendar_event_id);
    }

    /* ------------------------------------------------------------------------------------------
     | 9. El evento ya no existe en Google
     |----------------------------------------------------------------------------------------- */

    /**
     * 9. Si el PATCH da 404 o 410 (el admin borró el evento a mano), se recrea con un POST y se
     *    guarda el id nuevo.
     *
     * @return void
     */
    public function test_si_el_evento_ya_no_existe_se_recrea(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $op = $this->alta_de_uno($this->crear_pipeline(), $this->crear_cliente());
        $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00'])->assertStatus(200);
        $this->assertSame('evt-1', $this->oportunidad($op['id'])->next_action_calendar_event_id);

        foreach ([404 => '2026-10-01 10:00', 410 => '2026-10-02 10:00'] as $estado => $fecha) {
            $this->estado_de_google['PATCH'] = $estado;
            $antes = $this->contador_de_eventos;

            $this->actualizar($op['id'], ['next_action_at' => $fecha])->assertStatus(200);

            $this->assertSame('evt-' . ($antes + 1), $this->oportunidad($op['id'])->next_action_calendar_event_id, 'HTTP ' . $estado);
        }

        $this->assertCount(3, $this->llamadas_a_google('POST'));
        $this->assertCount(2, $this->llamadas_a_google('PATCH'));
        $this->assertSame('2026-10-02T10:00:00', $this->llamadas_a_google('POST')[2]->data()['start']['dateTime']);
    }

    /* ------------------------------------------------------------------------------------------
     | 10. Cambia de manos
     |----------------------------------------------------------------------------------------- */

    /**
     * 10. Otro admin reprograma. Con calendario vinculado: se borra el evento del calendario del
     *     primero (con SU calendar_id) y se crea en el del segundo. Sin calendario: se borra y la
     *     oportunidad queda sin evento (uno con la fecha vieja sería mentira).
     *
     * @return void
     */
    public function test_otro_admin_reprograma_y_el_evento_cambia_de_calendario(): void
    {
        $primero = $this->admin_logueado('Primero');
        $this->conectar_calendario($primero, self::CALENDARIO);
        $segundo = $this->crear_admin('Segundo');
        $this->conectar_calendario($segundo, self::CALENDARIO_OTRO);
        $tercero = $this->crear_admin('Tercero');

        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00'])->assertStatus(200);
        $guardada = $this->oportunidad($op['id']);
        $this->assertSame($primero->id, (int) $guardada->next_action_calendar_admin_id);

        /* El segundo, con calendario, reprograma. */
        $this->loguear_como($segundo);
        $this->actualizar($op['id'], ['next_action_at' => '2026-10-01 18:00'])->assertStatus(200);

        $llamadas = $this->llamadas_a_google();
        $this->assertSame(['POST', 'DELETE', 'POST'], array_map(function ($llamada) {
            return $llamada->method();
        }, $llamadas));
        $this->assertSame($this->url_eventos(self::CALENDARIO) . '/evt-1', $llamadas[1]->url(), 'Se borra del calendario del primero.');
        $this->assertSame($this->url_eventos(self::CALENDARIO_OTRO), $llamadas[2]->url(), 'Y se crea en el del segundo.');
        $this->assertSame('2026-10-01T18:00:00', $llamadas[2]->data()['start']['dateTime']);

        $guardada = $this->oportunidad($op['id']);
        $this->assertSame('evt-2', $guardada->next_action_calendar_event_id);
        $this->assertSame($segundo->id, (int) $guardada->next_action_calendar_admin_id);

        /* El tercero, SIN calendario, reprograma: se borra el del segundo y no queda ninguno. */
        $this->loguear_como($tercero);
        $this->actualizar($op['id'], ['next_action_at' => '2026-10-03 12:00'])->assertStatus(200);

        $llamadas = $this->llamadas_a_google();
        $this->assertCount(4, $llamadas);
        $this->assertSame('DELETE', $llamadas[3]->method());
        $this->assertSame($this->url_eventos(self::CALENDARIO_OTRO) . '/evt-2', $llamadas[3]->url());

        $guardada = $this->oportunidad($op['id']);
        $this->assertSame('2026-10-03 12:00:00', $guardada->next_action_at->format('Y-m-d H:i:s'));
        $this->assertNull($guardada->next_action_calendar_event_id);
        $this->assertNull($guardada->next_action_calendar_admin_id);
    }

    /* ------------------------------------------------------------------------------------------
     | 11. Borrar la oportunidad
     |----------------------------------------------------------------------------------------- */

    /**
     * 11. Borrar la oportunidad borra su evento (del calendario donde está). Una sin evento no llama
     *     a Google.
     *
     * @return void
     */
    public function test_borrar_la_oportunidad_borra_su_evento(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $pipeline = $this->crear_pipeline();
        $con      = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $sin      = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->actualizar($con['id'], ['next_action_at' => '2026-09-30 15:00'])->assertStatus(200);

        $this->deleteJson('/api/admin/pipeline-opportunities/' . $sin['id'])->assertStatus(200);
        $this->assertSame([], $this->llamadas_a_google('DELETE'), 'Sin evento no hay nada que borrar.');

        $this->deleteJson('/api/admin/pipeline-opportunities/' . $con['id'])->assertStatus(200);

        $this->assertNull($this->oportunidad($con['id']));
        $borrados = $this->llamadas_a_google('DELETE');
        $this->assertCount(1, $borrados);
        $this->assertSame($this->url_eventos(self::CALENDARIO) . '/evt-1', $borrados[0]->url());
    }

    /* ------------------------------------------------------------------------------------------
     | 12. Alta masiva
     |----------------------------------------------------------------------------------------- */

    /**
     * 12. Un alta masiva en una etapa inicial con agenda crea un evento por oportunidad creada (y no
     *     por las salteadas). Un alta sin agenda no llama a Google.
     *
     * @return void
     */
    public function test_alta_masiva_con_agenda_crea_un_evento_por_oportunidad(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $pipeline = $this->crear_pipeline();
        $uno      = $this->crear_cliente();
        $dos      = $this->crear_lead();

        /* Sin agenda (etapa inicial por defecto): nada. */
        $this->alta($pipeline, [['type' => 'client', 'id' => $this->crear_cliente()->id]])->assertStatus(201);
        $this->assertSame([], $this->llamadas_a_google());

        $respuesta = $this->alta($pipeline, [
            ['type' => 'client', 'id' => $uno->id],
            ['type' => 'lead', 'id' => $dos->id],
            ['type' => 'client', 'id' => 99999999],
        ], [
            'stage_id' => $this->etapa($pipeline, 'Reunión agendada')->id,
            'fields'   => ['fecha_reunion' => '2026-10-01 11:00'],
        ]);

        $respuesta->assertStatus(201);
        $this->assertCount(2, $respuesta->json('created'));
        $this->assertCount(1, $respuesta->json('skipped'));

        $posts = $this->llamadas_a_google('POST');
        $this->assertCount(2, $posts, 'Un POST por oportunidad creada, ninguno por la salteada.');
        foreach ($posts as $post) {
            $this->assertSame('2026-10-01T11:00:00', $post->data()['start']['dateTime']);
            $this->assertSame('opaque', $post->data()['transparency']);
        }

        foreach ($respuesta->json('created') as $creada) {
            $guardada = $this->oportunidad($creada['id']);
            $this->assertNotNull($guardada->next_action_calendar_event_id);
            $this->assertSame($admin->id, (int) $guardada->next_action_calendar_admin_id);
        }
    }

    /**
     * 12b. El tope: con 27 sujetos y una etapa con agenda se crean las 27 oportunidades pero solo 25
     *      eventos (la cola es `sync` en producción: más llamadas dejarían el request colgado).
     *
     * @return void
     */
    public function test_alta_masiva_respeta_el_tope_de_eventos(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $pipeline = $this->crear_pipeline();
        $sujetos  = [];
        for ($i = 0; $i < 27; $i++) {
            $sujetos[] = ['type' => 'client', 'id' => $this->crear_cliente()->id];
        }

        $this->assertSame(25, PipelineOpportunityService::MAX_EVENTOS_EN_ALTA_MASIVA);

        $respuesta = $this->alta($pipeline, $sujetos, [
            'stage_id' => $this->etapa($pipeline, 'Reunión agendada')->id,
            'fields'   => ['fecha_reunion' => '2026-10-01 11:00'],
        ]);

        $respuesta->assertStatus(201);
        $this->assertCount(27, $respuesta->json('created'), 'Las 27 oportunidades se crean igual.');
        $this->assertCount(25, $this->llamadas_a_google('POST'), 'Pero solo 25 eventos.');

        $ids = array_column($respuesta->json('created'), 'id');
        $con_evento = PipelineOpportunity::query()->whereIn('id', $ids)->whereNotNull('next_action_calendar_event_id')->count();
        $this->assertSame(25, $con_evento);

        /* Los 25 comparten UN solo refresco de token. */
        $tokens = array_filter($this->todas_las_llamadas_a_google(), function ($llamada) {
            return strpos($llamada->url(), 'oauth2.googleapis.com/token') !== false;
        });
        $this->assertCount(1, $tokens, 'El token se pide una vez por request, no una por evento.');
    }

    /* ------------------------------------------------------------------------------------------
     | 13. Los 422 no llegan a Google
     |----------------------------------------------------------------------------------------- */

    /**
     * 13. Una validación que da 422 (fecha mal formada, nota sin fecha) no hace ninguna llamada a
     *     Google y no guarda nada, ni en el PUT ni al mover.
     *
     * @return void
     */
    public function test_un_422_no_llega_a_google_ni_guarda_nada(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $pipeline = $this->crear_pipeline();
        $op       = $this->alta_de_uno($pipeline, $this->crear_cliente());

        $this->actualizar($op['id'], ['next_action_at' => '30/09/2026 15:00'])->assertStatus(422);
        $this->actualizar($op['id'], ['next_action_at' => '2026-09-30T15:00:00.000Z'])->assertStatus(422);
        $this->actualizar($op['id'], ['next_action_note' => 'Llamar'])->assertStatus(422);
        $this->mover($op['id'], [
            'stage_id'         => $this->etapa($pipeline, 'Contactado')->id,
            'fields'           => ['canal' => 'WhatsApp'],
            'next_action_note' => 'Llamar',
        ])->assertStatus(422);
        $this->mover($op['id'], [
            'stage_id'       => $this->etapa($pipeline, 'Contactado')->id,
            'fields'         => ['canal' => 'WhatsApp'],
            'next_action_at' => 'mañana',
        ])->assertStatus(422);

        $this->assertSame([], $this->todas_las_llamadas_a_google());

        $guardada = $this->oportunidad($op['id']);
        $this->assertNull($guardada->next_action_at);
        $this->assertNull($guardada->next_action_note);
        $this->assertNull($guardada->next_action_calendar_event_id);
        $this->assertSame($this->etapa($pipeline, 'Por contactar')->id, (int) $guardada->stage_id, 'El mover fallido tampoco movió.');
    }

    /* ------------------------------------------------------------------------------------------
     | 14. Caché de disponibilidad
     |----------------------------------------------------------------------------------------- */

    /**
     * 14. La caché de disponibilidad de la fecha (`closer_gcal_busy_date_<fecha>`) se invalida cuando
     *     el evento es opaque, y NO cuando es de día completo (transparente: no cambia la
     *     disponibilidad). Al mover un evento con hora se invalida también la fecha vieja.
     *
     * @return void
     */
    public function test_la_cache_de_disponibilidad_se_invalida_solo_si_el_evento_bloquea(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        $pipeline = $this->crear_pipeline();
        $con_hora = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $sin_hora = $this->alta_de_uno($pipeline, $this->crear_cliente());

        /* Día completo: la caché del día NO se toca. */
        $this->sembrar_cache('2026-10-02');
        $this->actualizar($sin_hora['id'], ['next_action_at' => '2026-10-02'])->assertStatus(200);
        $this->assertTrue(Cache::has('closer_gcal_busy_date_2026-10-02'), 'Un evento transparente no invalida la caché.');

        /* Con hora: la caché del día SÍ se invalida. */
        $this->sembrar_cache('2026-09-30');
        $this->actualizar($con_hora['id'], ['next_action_at' => '2026-09-30 15:00'])->assertStatus(200);
        $this->assertFalse(Cache::has('closer_gcal_busy_date_2026-09-30'), 'Un evento opaque invalida la caché de su fecha.');

        /* Moverlo a otro día invalida la fecha vieja Y la nueva. */
        $this->sembrar_cache('2026-09-30');
        $this->sembrar_cache('2026-10-07');
        $this->actualizar($con_hora['id'], ['next_action_at' => '2026-10-07 09:00'])->assertStatus(200);
        $this->assertFalse(Cache::has('closer_gcal_busy_date_2026-09-30'));
        $this->assertFalse(Cache::has('closer_gcal_busy_date_2026-10-07'));

        /* Pasar de "con hora" a "día completo" libera la fecha que bloqueaba. */
        $this->sembrar_cache('2026-10-07');
        $this->sembrar_cache('2026-10-08');
        $this->actualizar($con_hora['id'], ['next_action_at' => '2026-10-08'])->assertStatus(200);
        $this->assertFalse(Cache::has('closer_gcal_busy_date_2026-10-07'), 'Ya no bloquea ese día.');
        $this->assertTrue(Cache::has('closer_gcal_busy_date_2026-10-08'), 'El nuevo evento es transparente.');

        /* Borrar la próxima acción de un evento con hora también la invalida. */
        $otra = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $this->actualizar($otra['id'], ['next_action_at' => '2026-10-09 10:00'])->assertStatus(200);
        $this->sembrar_cache('2026-10-09');
        $this->actualizar($otra['id'], ['next_action_at' => null])->assertStatus(200);
        $this->assertFalse(Cache::has('closer_gcal_busy_date_2026-10-09'));
    }

    /* ------------------------------------------------------------------------------------------
     | Compuerta del servicio
     |----------------------------------------------------------------------------------------- */

    /**
     * El servicio de sincronización nunca propaga: si Google revienta con una excepción (red caída),
     * el guardado igual da 200. (Es el único caso que un HTTP fakeado con códigos no representa:
     * una excepción de transporte, no una respuesta.)
     *
     * @return void
     */
    public function test_una_excepcion_de_red_no_rompe_el_guardado(): void
    {
        $admin = $this->admin_logueado();
        $this->conectar_calendario($admin);

        Http::swap(new Factory());
        Http::fake(function ($request) {
            throw new \RuntimeException('cURL error 28: Operation timed out');
        });

        $op = $this->alta_de_uno($this->crear_pipeline(), $this->crear_cliente());

        $respuesta = $this->actualizar($op['id'], ['next_action_at' => '2026-09-30 15:00', 'next_action_note' => 'Llamar']);

        $respuesta->assertStatus(200);
        $guardada = $this->oportunidad($op['id']);
        $this->assertSame('2026-09-30 15:00:00', $guardada->next_action_at->format('Y-m-d H:i:s'));
        $this->assertNull($guardada->next_action_calendar_event_id);
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudantes
     |----------------------------------------------------------------------------------------- */

    /**
     * El Google fakeado: token, y los tres métodos de la API de eventos.
     *
     * @param \Illuminate\Http\Client\Request $request
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    private function responder_como_google($request)
    {
        $url    = $request->url();
        $metodo = $request->method();

        if (strpos($url, 'oauth2.googleapis.com/token') !== false) {
            if ($this->token_revocado) {
                return Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400);
            }

            return Http::response(['access_token' => 'tok-de-prueba', 'expires_in' => 3600, 'token_type' => 'Bearer'], 200);
        }

        if (strpos($url, 'www.googleapis.com/calendar/v3/calendars/') !== false) {
            $estado = isset($this->estado_de_google[$metodo]) ? $this->estado_de_google[$metodo] : 200;

            if ($estado >= 400) {
                return Http::response(['error' => ['code' => $estado, 'message' => 'Falla simulada de Google']], $estado);
            }

            if ($metodo === 'POST') {
                $this->contador_de_eventos++;

                return Http::response(['id' => 'evt-' . $this->contador_de_eventos], $estado);
            }

            if ($metodo === 'PATCH') {
                return Http::response(['id' => basename(parse_url($url, PHP_URL_PATH))], $estado);
            }

            return Http::response('', $estado);
        }

        return Http::response([], 200);
    }

    /**
     * Conecta el calendario de un admin (la fila de `admin_calendar_connections`).
     *
     * @param Admin                $admin
     * @param string               $calendario
     * @param array<string, mixed> $atributos  Pisan los defaults (is_active, google_calendar_id).
     *
     * @return AdminCalendarConnection
     */
    private function conectar_calendario(Admin $admin, $calendario = self::CALENDARIO, array $atributos = [])
    {
        return AdminCalendarConnection::create(array_merge([
            'admin_id'                       => $admin->id,
            'google_refresh_token_encrypted' => Crypt::encryptString('refresh-token-de-prueba'),
            'google_calendar_id'             => $calendario,
            'google_account_email'           => 'admin' . $admin->id . '@test.local',
            'connected_at'                   => now(),
            'is_active'                      => true,
        ], $atributos));
    }

    /**
     * PUT de la próxima acción / responsable, tal cual lo manda la SPA.
     *
     * @param int                  $id
     * @param array<string, mixed> $payload
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function actualizar($id, array $payload)
    {
        return $this->putJson('/api/admin/pipeline-opportunities/' . $id, $payload);
    }

    /**
     * URL de la colección de eventos de un calendario.
     *
     * @param string $calendario
     *
     * @return string
     */
    private function url_eventos($calendario)
    {
        return self::URL_CALENDARIOS . rawurlencode($calendario) . '/events';
    }

    /**
     * Las llamadas que salieron hacia la API de EVENTOS de Google Calendar, en orden. No incluye el
     * refresco del token (otro dominio).
     *
     * @param string|null $metodo POST | PATCH | DELETE; null = todos.
     *
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    private function llamadas_a_google($metodo = null)
    {
        $llamadas = [];

        foreach ($this->todas_las_llamadas_a_google() as $llamada) {
            if (strpos($llamada->url(), 'www.googleapis.com/calendar/v3/calendars/') === false) {
                continue;
            }
            if ($metodo !== null && $llamada->method() !== $metodo) {
                continue;
            }
            $llamadas[] = $llamada;
        }

        return $llamadas;
    }

    /**
     * Todo lo que salió hacia Google (token incluido).
     *
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    private function todas_las_llamadas_a_google()
    {
        $llamadas = [];

        foreach (Http::recorded() as $par) {
            $request = $par[0];
            if (strpos($request->url(), 'googleapis.com') !== false) {
                $llamadas[] = $request;
            }
        }

        return $llamadas;
    }

    /**
     * Pone en la caché de disponibilidad una fecha, como la deja `CloserGoogleCalendarBusyService`.
     *
     * @param string $fecha `Y-m-d`
     *
     * @return void
     */
    private function sembrar_cache($fecha)
    {
        Cache::put('closer_gcal_busy_date_' . $fecha, ['ranges' => [], 'snapshot_closers' => []], 300);
    }

    /**
     * Nombre del cliente sujeto de una oportunidad (el que usa el título del evento).
     *
     * @param int $oportunidad_id
     *
     * @return string
     */
    private function nombre_del_cliente($oportunidad_id)
    {
        return (string) Client::query()->whereKey($this->oportunidad($oportunidad_id)->client_id)->value('name');
    }
}
