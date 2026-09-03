<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AGENDAR UNA DEMO DESDE AFUERA DEL PANEL, que es lo que `PATCH claude/leads/{id}` prometía y no
 * hacía, más los tres 500 que ese mismo endpoint devolvía donde había prometido un 422 legible.
 *
 * Todo lo de este archivo salió de dos verificaciones independientes del 3/9/2026, y cada test es
 * un caso MEDIDO, no un caso imaginado:
 *
 *  1. 🔴 ESCRIBIR UNA FECHA NO ES AGENDAR. El endpoint escribía `demo_date` sin `demo_id`, y sin
 *     instancia asignada `LeadController::send_demo_mail_json()` rechaza el envío con 422 ("falta
 *     demo asignada"): el lead quedaba con fecha y nunca recibía el mail de la demo.
 *  2. 🔴 Y PODÍA PISARLE EL TURNO A OTRO LEAD. No pasaba por la grilla, así que dos leads podían
 *     terminar en el mismo horario sobre la misma instancia sin que nada lo denunciara. Ahora el
 *     horario se valida contra `LeadAiService::build_availability_json()` —el mismo cálculo que
 *     consume el panel— adentro del lock `demo_slot_hold_{demo_id}`.
 *  3. 🔴 LAS FECHAS RELATIVAS ENTRABAN. `Carbon::parse()` acepta `tomorrow 2026-09-15` (guardaba el
 *     16), `2026-09-15 +3 months` (guardaba diciembre) y `x2026-09-15x` (guardaba el 15), y la
 *     guarda que había sólo pedía que el texto tuviera un `\d{4}-\d{2}-\d{2}` en algún lado.
 *  4. 🔴 TRES CAMINOS DABAN 500 DONDE EL BLOQUE `claude/*` PROMETE 422: `notes` con 20.000 emoji
 *     (la validación contaba caracteres y la columna es TEXT, que son bytes),
 *     `meeting_scheduled_at` fuera del rango de un `timestamp`, y una `demo_date` en el pasado que
 *     ni siquiera fallaba: devolvía 200 y desarmaba la guarda de `LeadFollowupService`.
 *  5. ⚠️ Y el `{id}` de la ruta no estaba restringido a dígitos: `leads/26648-borrame` editaba el
 *     lead 26648.
 *
 * 🔴 CADA CASO NEGATIVO TRAE SU CONTROL POSITIVO. Un 422 solo no distingue "el freno frenó" de "el
 * escenario estaba mal armado y fallaba por cualquier otra cosa": por eso el slot ocupado se prueba
 * junto con el mismo turno forzado con `permitir_horario_fuera_de_grilla`, y la fecha pasada junto
 * con la misma fecha aceptada con `permitir_fecha_pasada`.
 */
class AgendaDeDemoPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del bloque claude/*. */
    const CLAVE = 'clave-de-prueba-agenda-de-demo';

    /** El instante en el que corre todo este archivo: un lunes, para que la ventana sea previsible. */
    const AHORA = '2026-09-07 09:00:00';

    /** Un día laborable adentro de la ventana de disponibilidad. */
    const FECHA = '2026-09-10';

    /**
     * La instancia de demo del pool que usan los leads de este archivo.
     *
     * @var Demo|null
     */
    private $demo = null;

    /**
     * Clave de ingesta, reloj congelado y grilla abierta de punta a punta.
     *
     * Los horarios van de 00:00 a 23:59 a propósito: lo que estos tests miden son los frenos del
     * endpoint, no la configuración de horarios del closer. Con la config real, cambiarle el horario
     * al closer pondría en rojo tests que no tienen nada que ver con eso.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);

        Carbon::setTestNow(self::AHORA);

        AdminSetting::set(LeadDemoSettings::KEY_CLOSER_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_CLOSER_HORARIO_SABADO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_CLOSER_HORARIO_DOMINGO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_SABADO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_DOMINGO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_FRECUENCIA_SLOTS_MINUTOS, '30');
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * La instancia de demo del pool, creada una sola vez por test.
     *
     * @return Demo
     */
    private function demo(): Demo
    {
        if ($this->demo === null) {
            $demo                    = new Demo();
            $demo->uuid              = (string) Str::uuid();
            $demo->erp_spa_url       = 'https://demo-erp.test';
            $demo->erp_api_url       = 'https://demo-erp-api.test';
            $demo->ecommerce_spa_url = 'https://demo-tienda.test';
            $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
            $demo->save();

            $this->demo = $demo;
        }

        return $this->demo;
    }

    /**
     * Lead de prueba.
     *
     * @param bool        $con_instancia Si arranca con demo_id asignado.
     * @param string|null $fecha         Fecha de demo ya agendada, o null para dejarlo sin turno.
     * @param string|null $hora          Hora de inicio de esa demo.
     *
     * @return Lead
     */
    private function crear_lead(bool $con_instancia = true, ?string $fecha = null, ?string $hora = null): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Juana Pérez';
        $lead->phone        = '+549341' . random_int(1000000, 9999999);
        $lead->status       = 'calificado';

        if ($con_instancia) {
            $lead->demo_id = $this->demo()->id;
        }

        if ($fecha !== null) {
            $lead->demo_date       = $fecha;
            $lead->demo_start_time = $hora;
            /* La demo dura una hora (es lo que quedó configurado en setUp()). */
            $lead->demo_end_time   = $hora === null
                ? null
                : sprintf('%02d:%s', ((int) substr($hora, 0, 2)) + 1, substr($hora, 3, 2));
        }

        $lead->save();

        return $lead;
    }

    /**
     * Pega al endpoint de campos.
     *
     * @param Lead                 $lead    Lead objetivo.
     * @param array<string, mixed> $payload Body completo.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pegar(Lead $lead, array $payload)
    {
        return $this->withHeaders($this->headers())->patchJson('/api/claude/leads/' . $lead->id, $payload);
    }

    /**
     * Simula y después aplica con el `confirm_count` que devolvió la simulación, arrastrando los
     * permisos explícitos a las DOS llamadas (los frenos corren igual en la simulación).
     *
     * @param Lead                 $lead    Lead objetivo.
     * @param array<string, mixed> $campos  Campos a escribir.
     * @param array<string, mixed> $permisos Flags explícitos.
     *
     * @return \Illuminate\Testing\TestResponse La respuesta de la escritura real.
     */
    private function simular_y_aplicar(Lead $lead, array $campos, array $permisos = [])
    {
        $simulacion = $this->pegar($lead, array_merge(['campos' => $campos], $permisos));
        $simulacion->assertStatus(200);

        return $this->pegar($lead, array_merge([
            'campos'        => $campos,
            'dry_run'       => false,
            'confirm_count' => $simulacion->json('cambiarian'),
        ], $permisos));
    }

    /* ------------------------------------------------------------------------------------------
     | 1. Sin instancia no hay demo
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 Escribir `demo_date` sobre un lead sin `demo_id` (y sin mandar uno) es 422.
     *
     * Antes devolvía 200 y dejaba al lead con una fecha y ninguna demo: `send_demo_mail_json()`
     * exige `demo_id` y rechaza el envío, así que el mail de la demo nunca salía.
     *
     * @return void
     */
    public function test_escribir_demo_date_sin_instancia_es_422(): void
    {
        $lead = $this->crear_lead(false);

        $respuesta = $this->pegar($lead, ['campos' => [
            'demo_date'       => self::FECHA,
            'demo_start_time' => '15:00',
        ]]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('demo_id', (string) $respuesta->json('error'));
        $this->assertNull($lead->fresh()->getRawOriginal('demo_date'), 'Se escribió la fecha sin instancia.');
    }

    /**
     * Y el control positivo: mandando `demo_id` en el mismo payload, la misma llamada agenda.
     *
     * @return void
     */
    public function test_con_la_instancia_en_el_mismo_payload_la_demo_se_agenda(): void
    {
        $lead = $this->crear_lead(false);

        $respuesta = $this->simular_y_aplicar($lead, [
            'demo_id'         => $this->demo()->id,
            'demo_date'       => self::FECHA,
            'demo_start_time' => '15:00',
            'demo_end_time'   => '16:00',
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('turno.demo_id', (int) $this->demo()->id);
        $respuesta->assertJsonPath('turno.validado_en_grilla', true);

        $fresco = $lead->fresh();
        $this->assertSame((int) $this->demo()->id, (int) $fresco->demo_id);
        $this->assertSame(self::FECHA, $fresco->getRawOriginal('demo_date'));
        $this->assertSame('15:00', $fresco->demo_start_time);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. La grilla y el lock
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 EL FRENO CENTRAL DE ESTA MISIÓN: un horario que otro lead ya tiene tomado sobre la MISMA
     * instancia es 422, y el error trae los horarios que sí están libres para esa fecha.
     *
     * @return void
     */
    public function test_un_horario_tomado_por_otro_lead_es_422_con_los_horarios_libres(): void
    {
        /* El lead que ya tiene el turno. Bloquea la instancia en esa franja. */
        $this->crear_lead(true, self::FECHA, '15:00');

        /* El lead que intenta meterse en el mismo horario, sobre la misma instancia. */
        $lead = $this->crear_lead(true, '2026-09-11', '15:00');

        $respuesta = $this->pegar($lead, ['campos' => [
            'demo_date'       => self::FECHA,
            'demo_start_time' => '15:00',
            'demo_end_time'   => '16:00',
        ]]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('demo_id', (int) $this->demo()->id);
        $respuesta->assertJsonPath('horario_pedido', '15:00');

        $libres = (array) $respuesta->json('horarios_libres');
        $this->assertNotEmpty($libres, 'El 422 no trajo los horarios libres: sin eso, el que llamó no sabe qué pedir.');
        $this->assertNotContains('15:00', $libres, 'El horario tomado figura como libre.');

        /* Y no escribió nada. */
        $this->assertSame('2026-09-11', $lead->fresh()->getRawOriginal('demo_date'));
    }

    /**
     * El control positivo del anterior: el MISMO turno pasa con `permitir_horario_fuera_de_grilla`.
     *
     * Sin este test, el rojo de arriba no distinguiría "la grilla frenó el horario tomado" de "el
     * escenario estaba mal armado y ningún horario pasaba".
     *
     * @return void
     */
    public function test_el_horario_tomado_se_puede_forzar_con_el_permiso_explicito(): void
    {
        $this->crear_lead(true, self::FECHA, '15:00');
        $lead = $this->crear_lead(true, '2026-09-11', '15:00');

        $respuesta = $this->simular_y_aplicar(
            $lead,
            ['demo_date' => self::FECHA, 'demo_start_time' => '15:00', 'demo_end_time' => '16:00'],
            ['permitir_horario_fuera_de_grilla' => true]
        );

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('turno.validado_en_grilla', false);
        $this->assertSame(self::FECHA, $lead->fresh()->getRawOriginal('demo_date'));
    }

    /**
     * Un horario libre sobre la misma instancia y el mismo día sí entra: lo que frena es la
     * colisión, no la fecha.
     *
     * @return void
     */
    public function test_otro_horario_del_mismo_dia_si_entra(): void
    {
        $this->crear_lead(true, self::FECHA, '15:00');
        $lead = $this->crear_lead(true, '2026-09-11', '15:00');

        $this->simular_y_aplicar($lead, [
            'demo_date'       => self::FECHA,
            'demo_start_time' => '19:00',
            'demo_end_time'   => '20:00',
        ])->assertStatus(200);

        $this->assertSame('19:00', $lead->fresh()->demo_start_time);
    }

    /**
     * 🔴 EL LOCK ES EL MISMO QUE TOMA `LeadAiService`. Con `demo_slot_hold_{demo_id}` tomado por
     * otro, el endpoint no escribe: contesta 422 pidiendo reintentar.
     *
     * Este test es lo único que ata el NOMBRE del lock. Dos locks con nombres distintos sobre la
     * misma demo no serializan nada, y eso no se ve en ninguna otra parte: el endpoint seguiría
     * pareciendo correcto mientras el agente le asigna el mismo horario por debajo.
     *
     * 🔴 ESTE TEST CORRE CON EL RELOJ REAL, Y NO ES UNA PREFERENCIA DE ESTILO.
     * `Illuminate\Cache\Lock::block()` mide el tiempo transcurrido con el helper `now()`, que es
     * Carbon: con `Carbon::setTestNow()` puesto, el reloj NUNCA avanza, la espera de 5 segundos no
     * vence jamás y el `while (! $this->acquire())` gira para siempre. Medido acá el 3/9/2026: el
     * archivo entero se colgaba en este test sin llegar a ninguna aserción. Es una propiedad del
     * framework y no del endpoint —en producción el reloj corre—, así que se descongela el reloj
     * para este caso y se usa una fecha calculada desde HOY, que sigue siendo futura corra el día
     * que corra. ⚠️ Los otros tests con el reloj congelado no se cuelgan porque ahí el lock está
     * LIBRE: `block()` lo toma en la primera vuelta y nunca entra al bucle de espera.
     *
     * @return void
     */
    public function test_con_el_lock_de_la_demo_tomado_no_se_escribe_nada(): void
    {
        $lead = $this->crear_lead(true, '2026-09-11', '15:00');

        Carbon::setTestNow();
        $fecha = Carbon::now()->addDays(30)->format('Y-m-d');

        $lock = Cache::lock('demo_slot_hold_' . $this->demo()->id, 30);
        $this->assertTrue($lock->get(), 'No se pudo tomar el lock para armar el escenario.');

        try {
            $respuesta = $this->pegar($lead, ['campos' => [
                'demo_date'       => $fecha,
                'demo_start_time' => '19:00',
            ]]);

            $respuesta->assertStatus(422);
            $respuesta->assertJsonPath('reintentable', true);
            $respuesta->assertJsonPath('demo_id', (int) $this->demo()->id);
        } finally {
            $lock->release();
        }

        $this->assertSame('2026-09-11', $lead->fresh()->getRawOriginal('demo_date'));
    }

    /* ------------------------------------------------------------------------------------------
     | 3. Fechas: lista cerrada de formatos
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 Las tres formas medidas de colar una fecha que nadie pidió son 422 en los DOS campos de
     * fecha.
     *
     * @return void
     */
    public function test_las_fechas_relativas_y_la_basura_pegada_son_422(): void
    {
        $lead = $this->crear_lead(true);

        foreach (['tomorrow 2026-09-15', '2026-09-15 +3 months', 'x2026-09-15x'] as $texto) {
            $this->pegar($lead, ['campos' => ['demo_date' => $texto]])
                ->assertStatus(422);

            $this->pegar($lead, ['campos' => ['meeting_scheduled_at' => $texto]])
                ->assertStatus(422);
        }

        $fresco = $lead->fresh();
        $this->assertNull($fresco->getRawOriginal('demo_date'), 'Se coló una fecha relativa en demo_date.');
        $this->assertNull($fresco->getRawOriginal('meeting_scheduled_at'), 'Se coló una fecha relativa en meeting_scheduled_at.');
    }

    /**
     * El control positivo: las mismas dos columnas aceptan la fecha absoluta bien escrita.
     *
     * @return void
     */
    public function test_las_fechas_absolutas_si_entran(): void
    {
        $lead = $this->crear_lead(true);

        $this->simular_y_aplicar($lead, ['meeting_scheduled_at' => '2026-09-15 16:00'])
            ->assertStatus(200);

        $this->assertSame('2026-09-15 16:00:00', $lead->fresh()->getRawOriginal('meeting_scheduled_at'));
    }

    /**
     * Una fecha con hora en `demo_date` es 422: la columna es DATE y la hora se tiraría en silencio.
     *
     * @return void
     */
    public function test_una_demo_date_con_hora_pegada_es_422(): void
    {
        $lead = $this->crear_lead(true);

        $this->pegar($lead, ['campos' => ['demo_date' => '2026-09-15 16:00']])->assertStatus(422);

        $this->assertNull($lead->fresh()->getRawOriginal('demo_date'));
    }

    /* ------------------------------------------------------------------------------------------
     | 4. Los tres 500 que ahora son 422
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 `notes` que entra por caracteres pero desborda la columna en BYTES es 422, no 500.
     *
     * 20.000 emoji son exactamente 20.000 caracteres (o sea que pasan el máximo declarado) y 80.000
     * bytes contra una columna TEXT que aguanta 65.535.
     *
     * @return void
     */
    public function test_notes_que_desborda_la_columna_en_bytes_es_422(): void
    {
        $lead = $this->crear_lead(true);

        $emoji = str_repeat('🔥', 20000);
        $this->assertSame(20000, mb_strlen($emoji), 'El texto de prueba no tiene el largo esperado en caracteres.');
        $this->assertGreaterThan(65535, strlen($emoji), 'El texto de prueba no desborda la columna en bytes.');

        $respuesta = $this->pegar($lead, ['campos' => ['notes' => $emoji]]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('BYTES', (string) $respuesta->json('error'));
        /* 🔴 Y el error NO devuelve los 80 KB que le mandaron: un 422 ilegible no sirve de nada. */
        $this->assertLessThan(2000, strlen((string) $respuesta->json('valor_recibido')));

        $this->assertNull($lead->fresh()->notes);
    }

    /**
     * 🔴 `meeting_scheduled_at` fuera del rango de un `timestamp` de MySQL es 422, no 500.
     *
     * @return void
     */
    public function test_meeting_scheduled_at_fuera_del_rango_del_timestamp_es_422(): void
    {
        $lead = $this->crear_lead(true);

        foreach (['9999-12-31 23:59', '1000-01-01 00:00'] as $texto) {
            $respuesta = $this->pegar($lead, ['campos' => ['meeting_scheduled_at' => $texto]]);
            $respuesta->assertStatus(422);
            $this->assertStringContainsString('timestamp', (string) $respuesta->json('error'));
        }

        $this->assertNull($lead->fresh()->getRawOriginal('meeting_scheduled_at'));
    }

    /**
     * 🔴 Una `demo_date` en el PASADO es 422.
     *
     * Antes devolvía 200, y sobre un lead en `demo_agendada` eso desarma la guarda
     * `if ($demo_start->isFuture()) return null;` de `LeadFollowupService::process_lead()`: el lead
     * pasa a contar como "no se presentó" y el cron le manda la tanda de seguimiento por una demo
     * que nunca ocurrió.
     *
     * @return void
     */
    public function test_una_demo_date_en_el_pasado_es_422(): void
    {
        $lead = $this->crear_lead(true);

        $respuesta = $this->pegar($lead, ['campos' => [
            'demo_date'       => '2020-01-01',
            'demo_start_time' => '15:00',
        ]]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('PASADO', (string) $respuesta->json('error'));
        $this->assertNull($lead->fresh()->getRawOriginal('demo_date'));
    }

    /**
     * El control positivo: la misma fecha pasada entra con `permitir_fecha_pasada`, que es lo que
     * hace falta para corregir un registro viejo.
     *
     * @return void
     */
    public function test_una_demo_date_en_el_pasado_entra_con_el_permiso_explicito(): void
    {
        $lead = $this->crear_lead(true);

        $this->simular_y_aplicar(
            $lead,
            ['demo_date' => '2020-01-01', 'demo_start_time' => '15:00'],
            ['permitir_fecha_pasada' => true]
        )->assertStatus(200);

        $this->assertSame('2020-01-01', $lead->fresh()->getRawOriginal('demo_date'));
    }

    /* ------------------------------------------------------------------------------------------
     | 5. El {id} de la ruta
     |------------------------------------------------------------------------------------------ */

    /**
     * ⚠️ Un `{id}` que no es un número no edita nada.
     *
     * Antes, `leads/26648-borrame` y `leads/26648.9` editaban el lead 26648: el cast a int se comía
     * la cola y la ruta no restringía el segmento.
     *
     * @return void
     */
    public function test_un_id_no_numerico_no_edita_ningun_lead(): void
    {
        $lead = $this->crear_lead(true);

        foreach (['-borrame', '.9', 'x'] as $cola) {
            $this->withHeaders($this->headers())
                ->patchJson('/api/claude/leads/' . $lead->id . $cola, [
                    'campos'        => ['contact_name' => 'Nombre pisado'],
                    'dry_run'       => false,
                    'confirm_count' => 1,
                ])
                ->assertStatus(404);
        }

        $this->assertSame('Juana Pérez', $lead->fresh()->contact_name, 'Un id no numérico llegó a editar el lead.');
    }

    /* ------------------------------------------------------------------------------------------
     | 6. `notes` REEMPLAZA
     |------------------------------------------------------------------------------------------ */

    /**
     * ⚠️ Cuando el lead YA tenía nota, la respuesta avisa que escribir `notes` la reemplaza.
     *
     * Es lo único que separa "guardé el dolor detectado" de "le borré la nota a Lucas", y hasta el
     * 3/9/2026 no estaba escrito en ninguna parte.
     *
     * @return void
     */
    public function test_la_advertencia_de_notes_viaja_cuando_el_lead_ya_tenia_nota(): void
    {
        $lead        = $this->crear_lead(true);
        $lead->notes = 'Nota que escribió Lucas a mano desde el panel.';
        $lead->save();

        $simulacion = $this->pegar($lead, ['campos' => ['notes' => 'Dolor: pierde ventas por no tener stock al día.']]);

        $simulacion->assertStatus(200);
        $advertencias = (array) $simulacion->json('advertencias');
        $this->assertNotEmpty($advertencias, 'La simulación no avisó que `notes` reemplaza lo que había.');
        $this->assertStringContainsString('REEMPLAZA', implode(' ', $advertencias));

        /* Y el valor de hoy viaja en el diff, que es el camino que la advertencia recomienda. */
        $simulacion->assertJsonPath('diff.notes.actual', 'Nota que escribió Lucas a mano desde el panel.');
    }

    /**
     * Y sobre un lead SIN nota no hay advertencia: no se le está borrando nada a nadie.
     *
     * @return void
     */
    public function test_sin_nota_previa_no_hay_advertencia(): void
    {
        $lead = $this->crear_lead(true);

        $simulacion = $this->pegar($lead, ['campos' => ['notes' => 'Dolor detectado.']]);

        $simulacion->assertStatus(200);
        $this->assertEmpty((array) $simulacion->json('advertencias'));
    }

    /* ------------------------------------------------------------------------------------------
     | 7. Las dos rutas nuevas
     |------------------------------------------------------------------------------------------ */

    /**
     * `GET claude/leads/{id}/availability` devuelve las instancias del pool y los horarios libres,
     * que es lo que permite ELEGIR un horario antes de escribirlo.
     *
     * @return void
     */
    public function test_availability_devuelve_las_instancias_y_los_horarios_libres(): void
    {
        $lead = $this->crear_lead(true, self::FECHA, '15:00');

        $respuesta = $this->withHeaders($this->headers())
            ->getJson('/api/claude/leads/' . $lead->id . '/availability');

        $respuesta->assertStatus(200);

        /* El catálogo trae TODAS las instancias del pool, no sólo la de este test: la base del slot
           ya tiene demos cargadas. Se afirma que la nuestra está, no que sea la primera. */
        $ids = array_map(function ($fila) {
            return (int) $fila['demo_id'];
        }, (array) $respuesta->json('demos'));

        $this->assertNotEmpty($ids);
        $this->assertContains((int) $this->demo()->id, $ids, 'La instancia del test no figura en el catálogo de demos.');

        $slots = (array) $respuesta->json('slots.' . $this->demo()->id . '.' . self::FECHA);
        $this->assertNotEmpty($slots, 'La grilla no devolvió horarios para la fecha pedida.');

        /* 🔴 El propio turno del lead NO aparece bloqueado contra sí mismo: se pasa
           exclude_lead_id, igual que hace el panel. Sin eso, reagendar al mismo horario sería
           imposible. */
        $this->assertContains('15:00', $slots, 'El turno del propio lead figura como ocupado contra sí mismo.');
    }

    /**
     * 🔴 `POST claude/leads/{id}/calendar-event` no recrea el evento del closer sin confirmar,
     * cuando el lead YA tiene uno.
     *
     * Recrearlo borra el anterior y genera un `meet_url` nuevo: el link que el lead ya recibió deja
     * de servir. El freno está en el controlador de Claude y no en `LeadController` porque allá lo
     * aprieta una persona que está mirando el panel, y acá lo aprieta un proceso que puede estar
     * reintentando una llamada cortada.
     *
     * @return void
     */
    public function test_el_evento_del_closer_no_se_recrea_sin_confirmar(): void
    {
        $lead                  = $this->crear_lead(true, self::FECHA, '15:00');
        $lead->google_event_id = 'evento-viejo-de-prueba';
        $lead->meet_url        = 'https://meet.google.com/viejo';
        $lead->save();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/calendar-event', []);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('google_event_id', 'evento-viejo-de-prueba');

        $fresco = $lead->fresh();
        $this->assertSame('evento-viejo-de-prueba', $fresco->google_event_id, 'Se tocó el evento sin confirmar.');
        $this->assertSame('https://meet.google.com/viejo', $fresco->meet_url);
    }

    /**
     * Y sin fecha ni hora de demo cargadas, la misma ruta contesta el 422 del método delegado: no
     * hay con qué calcular el horario del evento del closer.
     *
     * @return void
     */
    public function test_el_evento_del_closer_sin_turno_cargado_es_422(): void
    {
        $lead = $this->crear_lead(true);

        $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/calendar-event', [])
            ->assertStatus(422);
    }

    /**
     * Las dos rutas nuevas están detrás de la clave de ingesta, igual que el resto del bloque.
     *
     * @return void
     */
    public function test_las_rutas_nuevas_exigen_la_clave_de_ingesta(): void
    {
        $lead = $this->crear_lead(true, self::FECHA, '15:00');

        $this->getJson('/api/claude/leads/' . $lead->id . '/availability')->assertStatus(401);
        $this->postJson('/api/claude/leads/' . $lead->id . '/calendar-event', [])->assertStatus(401);
    }
}
