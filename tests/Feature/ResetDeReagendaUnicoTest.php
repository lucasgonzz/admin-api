<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Services\LeadDemoSettings;
use App\Services\LeadRescheduleFlagsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🔴 UNA SOLA DEFINICIÓN DEL RESET DE REAGENDA: el camino del PANEL y el camino de CLAUDE dejan
 * exactamente los mismos flags.
 *
 * POR QUÉ EXISTE ESTE ARCHIVO
 * ----------------------------
 * Cuando se reagenda una demo hay que apagar `recordatorio_demo_enviado` y
 * `recordatorio_manana_enviado` y limpiar `demo_fin_check_reprogramado_para`; si no, la demo nueva
 * no recibe su recordatorio (el latch ya está marcado como enviado) y el check de fin queda trabado
 * en un timestamp del horario viejo que nunca más cae en la ventana de ±2 minutos.
 *
 * Ese bloque estaba escrito DOS veces adentro de `LeadController` —en `update()` y en
 * `update_json()`— y `PATCH claude/leads/{id}` iba a ser la TERCERA. Tres copias de la misma regla
 * garantizan que se separen: el día que aparezca un cuarto flag, alguien lo agrega en la copia que
 * estaba mirando y los otros caminos reagendan dejando el flag viejo puesto, sin que nada lo
 * denuncie. Por eso el bloque se extrajo a {@see LeadRescheduleFlagsService} y por eso este test
 * compara los DOS caminos entre sí en vez de verificar cada uno por separado: un test por camino se
 * puede satisfacer con dos implementaciones distintas; éste, no.
 *
 * ⚠️ El test se apoya en `LeadRescheduleFlagsService::flags_reseteados()` como lista de qué
 * comparar, así que agregar un cuarto flag al servicio lo mete solo en la comparación de los dos
 * caminos. Ésa es la propiedad que hace que este test siga sirviendo cuando el reset crezca.
 *
 * 🔴 LO QUE SE AGREGÓ EL 3/9/2026: MOVER SÓLO LA HORA TAMBIÉN ES REAGENDAR.
 * Un verificador adversarial midió que, por los DOS caminos, mover una demo de las 18:00 a las
 * 20:00 del mismo día dejaba `recordatorio_demo_enviado = true` —o sea que el recordatorio del
 * horario nuevo no salía— y `demo_fin_check_reprogramado_para` apuntando a las 19:15 de un horario
 * que ya no existía. El servicio miraba sólo `demo_date`. Ahora mira la agenda entera y
 * `test_mover_solo_la_hora_resetea_por_los_dos_caminos()` lo clava, comparando los dos caminos
 * entre sí igual que el test de arriba: es la MISMA propiedad, aplicada a la mitad de la regla que
 * faltaba.
 */
class ResetDeReagendaUnicoTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del bloque claude/*. */
    const CLAVE = 'clave-de-prueba-reset-de-reagenda';

    /** El instante en el que corre todo este archivo. Ver setUp(). */
    const AHORA = '2026-09-07 09:00:00';

    /**
     * La instancia de demo del pool que usan los leads de este archivo.
     *
     * @var Demo|null
     */
    private $demo = null;

    /**
     * Setea la clave de ingesta —en el `.env.testing` del slot está vacía y el middleware es
     * fail-closed— y fija el reloj y la grilla.
     *
     * 🔴 El reloj y la grilla se congelan porque desde el 3/9/2026 `PATCH claude/leads/{id}` valida
     * el turno contra la disponibilidad REAL: rechaza un turno en el pasado y uno que no esté libre
     * en la grilla de la instancia. Sin congelarlos, este archivo mediría la configuración de
     * horarios en vez de la unicidad del reset. El camino del panel no valida nada de eso, y ésa es
     * justamente una diferencia que este test NO compara: lo único que compara son los flags.
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
     * Lead con la demo agendada y los tres flags puestos, o sea el estado real de un lead al que ya
     * le salieron los recordatorios del horario viejo.
     *
     * 🔴 LA FECHA ES UN PARÁMETRO Y CADA CAMINO USA LA SUYA. Los dos leads de cada test comparten
     * el mismo closer, y desde el 3/9/2026 el camino de Claude valida el horario contra la grilla
     * real: si los dos apuntaran al mismo turno, el segundo lo encontraría OCUPADO POR EL PRIMERO y
     * el test daría rojo por una colisión legítima. Lo que este archivo compara son los FLAGS, que
     * no dependen de a qué día se movió la demo — así que cada camino se mueve al suyo.
     *
     * @param string $fecha Día en el que arranca agendada la demo (Y-m-d).
     *
     * @return Lead
     */
    private function crear_lead_con_los_flags_puestos(string $fecha = '2026-09-10'): Lead
    {
        $lead                                   = new Lead();
        $lead->uuid                             = (string) Str::uuid();
        $lead->contact_name                     = 'Juana Pérez';
        $lead->status                           = 'demo_agendada';
        /* Con instancia asignada: sin demo_id, el camino de Claude rechaza cualquier escritura de
           demo_date con 422 (una demo sin instancia no manda mail ni emite token). */
        $lead->demo_id                          = $this->demo()->id;
        $lead->demo_date                        = $fecha;
        $lead->demo_start_time                  = '18:00';
        $lead->demo_end_time                    = '19:00';
        $lead->recordatorio_demo_enviado        = true;
        $lead->recordatorio_manana_enviado      = true;
        $lead->demo_fin_check_reprogramado_para = $fecha . ' 19:15:00';
        $lead->save();

        return $lead;
    }

    /**
     * Admin autenticado por Sanctum, que es como entra el camino del panel.
     *
     * @return void
     */
    private function autenticar_admin(): void
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);
    }

    /**
     * Escribe campos por el camino de Claude, con las dos llamadas del protocolo.
     *
     * @param Lead                 $lead   Lead objetivo.
     * @param array<string, mixed> $campos Campos a escribir.
     *
     * @return \Illuminate\Testing\TestResponse La respuesta de la escritura real.
     */
    private function escribir_por_claude(Lead $lead, array $campos)
    {
        $headers = [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];

        $simulacion = $this->withHeaders($headers)
            ->patchJson('/api/claude/leads/' . $lead->id, ['campos' => $campos]);
        $simulacion->assertStatus(200);

        return $this->withHeaders($headers)->patchJson('/api/claude/leads/' . $lead->id, [
            'campos'        => $campos,
            'dry_run'       => false,
            'confirm_count' => $simulacion->json('cambiarian'),
        ]);
    }

    /**
     * Los flags del lead, leídos por los nombres que declara el servicio.
     *
     * @param Lead $lead Lead a fotografiar.
     *
     * @return array<string, mixed>
     */
    private function flags_de(Lead $lead): array
    {
        $fresco = $lead->fresh();
        $foto   = [];

        foreach (array_keys(app(LeadRescheduleFlagsService::class)->flags_reseteados()) as $flag) {
            $valor = $fresco->{$flag};
            /* `demo_fin_check_reprogramado_para` está casteada a datetime: se compara el string. */
            $foto[$flag] = is_bool($valor) || $valor === null ? $valor : (string) $valor;
        }

        return $foto;
    }

    /**
     * 🔴 EL TEST DE LA EXTRACCIÓN: reagendar por el panel y reagendar por Claude dejan los MISMOS
     * flags, comparados entre sí y no contra una constante escrita a mano.
     *
     * @return void
     */
    public function test_el_panel_y_claude_dejan_los_mismos_flags_al_reagendar(): void
    {
        /* --- Camino 1: el panel (PUT admin/lead/{id}, autenticado por Sanctum). --- */
        $this->autenticar_admin();

        $lead_panel = $this->crear_lead_con_los_flags_puestos('2026-09-10');

        $this->putJson('/api/admin/lead/' . $lead_panel->id, [
            'demo_date' => '2026-09-17',
        ])->assertStatus(200);

        $flags_panel = $this->flags_de($lead_panel);

        /* --- Camino 2: Claude (PATCH claude/leads/{id}, con la clave del header). Se mueve a OTRO
               día que el del panel: los dos leads comparten closer y el 17 ya se lo llevó el
               anterior. Ver el porqué en crear_lead_con_los_flags_puestos(). --- */
        $lead_claude = $this->crear_lead_con_los_flags_puestos('2026-09-11');

        $this->escribir_por_claude($lead_claude, ['demo_date' => '2026-09-18'])
            ->assertStatus(200)
            ->assertJsonPath('reagendado', true);

        $flags_claude = $this->flags_de($lead_claude);

        /* --- La comparación que fija la extracción. --- */
        $this->assertSame(
            $flags_panel,
            $flags_claude,
            'Reagendar por el panel y por Claude dejó flags distintos: se separaron las dos definiciones del reset.'
        );

        /* Y que el reset efectivamente pasó por los dos lados (si no, comparar dos "no hizo nada"
           también daría igual y el test no probaría nada). */
        $this->assertFalse($flags_panel['recordatorio_demo_enviado']);
        $this->assertFalse($flags_panel['recordatorio_manana_enviado']);
        $this->assertNull($flags_panel['demo_fin_check_reprogramado_para']);

        $this->assertSame('2026-09-17', $lead_panel->fresh()->getRawOriginal('demo_date'));
        $this->assertSame('2026-09-18', $lead_claude->fresh()->getRawOriginal('demo_date'));
    }

    /**
     * Guardar por el panel SIN mover la fecha no toca los flags — el reset es de la reagenda, no
     * del guardado. Es la mitad de la regla que un `always reset` rompería sin que ningún test de
     * "reagendar resetea" lo notara.
     *
     * @return void
     */
    public function test_guardar_sin_mover_la_fecha_no_resetea_nada_por_ninguno_de_los_dos_caminos(): void
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();
        Sanctum::actingAs($admin);

        $lead_panel = $this->crear_lead_con_los_flags_puestos();
        $this->putJson('/api/admin/lead/' . $lead_panel->id, [
            'contact_name' => 'Juana Pérez Gómez',
        ])->assertStatus(200);

        $lead_claude = $this->crear_lead_con_los_flags_puestos();
        $this->withHeaders([
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ])->patchJson('/api/claude/leads/' . $lead_claude->id, [
            'campos'        => ['contact_name' => 'Juana Pérez Gómez'],
            'dry_run'       => false,
            'confirm_count' => 1,
        ])->assertStatus(200)->assertJsonPath('reagendado', false);

        $this->assertSame($this->flags_de($lead_panel), $this->flags_de($lead_claude));
        $this->assertTrue($this->flags_de($lead_claude)['recordatorio_demo_enviado'], 'Se reseteó un flag sin reagendar.');
    }

    /**
     * 🔴 MOVER SÓLO LA HORA, EL MISMO DÍA, TAMBIÉN ES REAGENDAR — y por los DOS caminos.
     *
     * El caso medido el 3/9/2026: la demo pasa de las 18:00 a las 20:00 del mismo día y hasta ese
     * momento no se reseteaba nada, porque el servicio miraba sólo `demo_date`. Consecuencias
     * concretas, las dos silenciosas:
     *   - `recordatorio_demo_enviado` quedaba en true, así que el recordatorio de las 20:00 NUNCA
     *     salía (el latch ya estaba marcado como enviado por el horario viejo), y
     *   - `demo_fin_check_reprogramado_para` seguía apuntando a las 19:15, un instante que después
     *     de mover la demo queda en el pasado y no vuelve a caer nunca en la ventana de ±2 minutos:
     *     el check de fin trabado para siempre, que es exactamente lo que el docblock del servicio
     *     venía advirtiendo.
     *
     * Se compara panel contra Claude y no contra una constante escrita a mano, por lo mismo que el
     * primer test de este archivo: un test por camino se puede satisfacer con dos implementaciones
     * distintas; éste, no.
     *
     * @return void
     */
    public function test_mover_solo_la_hora_resetea_por_los_dos_caminos(): void
    {
        /* --- Camino 1: el panel. --- */
        $this->autenticar_admin();
        $lead_panel = $this->crear_lead_con_los_flags_puestos('2026-09-10');

        $this->putJson('/api/admin/lead/' . $lead_panel->id, [
            'demo_start_time' => '20:00',
            'demo_end_time'   => '21:00',
        ])->assertStatus(200);

        $flags_panel = $this->flags_de($lead_panel);

        /* --- Camino 2: Claude, en OTRO día: los dos leads comparten closer y las 20:00 del 10 ya
               se las llevó el lead del panel. Ver crear_lead_con_los_flags_puestos(). --- */
        $lead_claude = $this->crear_lead_con_los_flags_puestos('2026-09-11');

        $this->escribir_por_claude($lead_claude, [
            'demo_start_time' => '20:00',
            'demo_end_time'   => '21:00',
        ])->assertStatus(200)->assertJsonPath('reagendado', true);

        $flags_claude = $this->flags_de($lead_claude);

        /* La comparación que fija la extracción, ahora sobre la mitad de la regla que faltaba. */
        $this->assertSame(
            $flags_panel,
            $flags_claude,
            'Mover sólo la hora dejó flags distintos por el panel y por Claude: se separaron las dos definiciones.'
        );

        /* Y que el reset efectivamente pasó por los dos lados: sin esto, comparar dos "no hizo
           nada" también daría igual y el test no probaría nada — que es justo el estado en el que
           estaba el código antes del arreglo. */
        $this->assertFalse($flags_panel['recordatorio_demo_enviado'], 'El panel no reseteó al mover sólo la hora.');
        $this->assertFalse($flags_panel['recordatorio_manana_enviado']);
        $this->assertNull($flags_panel['demo_fin_check_reprogramado_para']);
        $this->assertFalse($flags_claude['recordatorio_demo_enviado'], 'Claude no reseteó al mover sólo la hora.');
        $this->assertNull($flags_claude['demo_fin_check_reprogramado_para']);

        /* Y la fecha no se movió: lo que cambió fue SÓLO la hora. */
        $this->assertSame('2026-09-10', $lead_panel->fresh()->getRawOriginal('demo_date'));
        $this->assertSame('2026-09-11', $lead_claude->fresh()->getRawOriginal('demo_date'));
        $this->assertSame('20:00', $lead_panel->fresh()->demo_start_time);
        $this->assertSame('20:00', $lead_claude->fresh()->demo_start_time);
    }
}
