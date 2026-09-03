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
 *
 * 🔴 LOS DOS HUECOS QUE SE CERRARON DESPUÉS, EL MISMO 3/9/2026.
 * Un revisor de merge midió que el archivo, tal como estaba, dejaba dos agujeros:
 *   1. **El ABM Blade no se ejercitaba nunca.** El servicio tiene TRES consumidores y acá había
 *      dos: `LeadController::update()` era el único sin red, o sea el único al que se le podía
 *      sacar la llamada al servicio sin que la suite dijera nada.
 *      → `test_el_abm_blade_deja_los_mismos_flags_que_claude_al_reagendar()`.
 *   2. **El no-op se probaba con `contact_name`**, que ni siquiera es un campo de agenda: se
 *      denunciaba un `always reset` y nada más. Faltaba el caso de todos los días —reenviar el
 *      MISMO turno— que si resetea de más le manda al lead el recordatorio dos veces.
 *      → `test_reenviar_el_mismo_turno_no_resetea_nada_por_ninguno_de_los_tres_caminos()`.
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
     * Un admin recién creado, que es el que después se autentica por el guard que corresponda.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Admin autenticado por Sanctum, que es como entra el camino de la SPA.
     *
     * @return void
     */
    private function autenticar_admin(): void
    {
        Sanctum::actingAs($this->crear_admin());
    }

    /**
     * Admin autenticado por el guard `web`, que es como entra el ABM Blade.
     *
     * 🔴 EL GUARD VA EXPLÍCITO Y NO POR DEFAULT. Las rutas de `routes/web.php` están detrás del
     * middleware `auth` a secas (guard default = `web`, sesión) y las de la SPA detrás de
     * `auth:sanctum`. Como `Sanctum::actingAs()` hace `shouldUse('sanctum')`, un `actingAs()` sin
     * guard después de él NO vuelve al guard `web`: le pondría el usuario al de Sanctum. Nombrarlo
     * hace que el orden de los caminos adentro de un test no importe.
     *
     * @return void
     */
    private function autenticar_admin_blade(): void
    {
        $this->actingAs($this->crear_admin(), 'web');
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
     * Escribe campos por el camino de la SPA (`PUT admin/lead/{id}`, guard de Sanctum).
     *
     * @param Lead                 $lead   Lead objetivo.
     * @param array<string, mixed> $campos Campos a escribir.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function escribir_por_el_panel_spa(Lead $lead, array $campos)
    {
        $this->autenticar_admin();

        return $this->putJson('/api/admin/lead/' . $lead->id, $campos);
    }

    /**
     * El formulario del ABM Blade tal cual lo manda el panel: TODOS los campos, con los valores que
     * el lead ya tiene, y encima lo que este test quiere cambiar.
     *
     * 🔴 POR QUÉ VA COMPLETO Y NO PARCIAL. `LeadController::extract_data()` arma su array con un
     * `$request->input(...)` por campo, así que lo que no se manda se escribe como NULL — no se
     * conserva. El `<form>` de `leads.edit` manda el formulario entero, y mandar acá sólo
     * `demo_date` estaría ejercitando un camino que en el panel no existe: cualquier conclusión
     * sobre "guardar sin mover la fecha" sacada de un payload parcial sería sobre otra cosa.
     *
     * ⚠️ Se leen con `getRawOriginal()` por lo mismo que {@see LeadRescheduleFlagsService}: los
     * accessors devolverían un Carbon para `demo_date` y el formulario manda texto.
     *
     * @param Lead                 $lead    Lead a fotografiar.
     * @param array<string, mixed> $cambios Lo que este test cambia sobre el formulario actual.
     *
     * @return array<string, mixed>
     */
    private function formulario_blade(Lead $lead, array $cambios = []): array
    {
        $fresco = $lead->fresh();

        $formulario = [
            'contact_name'    => $fresco->contact_name,
            'status'          => $fresco->status,
            'demo_id'         => (string) $fresco->demo_id,
            'demo_date'       => $fresco->getRawOriginal('demo_date'),
            'demo_start_time' => $fresco->getRawOriginal('demo_start_time'),
            'demo_end_time'   => $fresco->getRawOriginal('demo_end_time'),
        ];

        return array_merge($formulario, $cambios);
    }

    /**
     * Escribe por el ABM Blade (`PUT leads/{id}` de `routes/web.php`, guard `web`).
     *
     * Va como form-data y no como JSON a propósito: así entra el `<form>` del panel.
     *
     * @param Lead                 $lead    Lead objetivo.
     * @param array<string, mixed> $cambios Lo que cambia sobre el formulario actual del lead.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function escribir_por_el_panel_blade(Lead $lead, array $cambios = [])
    {
        $this->autenticar_admin_blade();

        return $this->put(route('leads.update', $lead->id), $this->formulario_blade($lead, $cambios));
    }

    /**
     * El turno que un lead YA tiene, con las horas escritas como las quiera este test.
     *
     * La fecha sale del lead y no se escribe a mano porque cada lead de un test vive en su propio
     * día: ver {@see self::crear_lead_con_los_flags_puestos()}.
     *
     * @param Lead   $lead   Lead del que sale la fecha.
     * @param string $inicio Hora de inicio, tal cual se quiere mandar.
     * @param string $fin    Hora de fin, tal cual se quiere mandar.
     *
     * @return array<string, string>
     */
    private function turno_de(Lead $lead, string $inicio, string $fin): array
    {
        return [
            'demo_date'       => (string) $lead->fresh()->getRawOriginal('demo_date'),
            'demo_start_time' => $inicio,
            'demo_end_time'   => $fin,
        ];
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

    /**
     * 🔴 EL TERCER CONSUMIDOR: `LeadController::update()`, el ABM Blade del panel.
     *
     * POR QUÉ SE AGREGÓ (3/9/2026)
     * -----------------------------
     * El docblock de {@see LeadRescheduleFlagsService} enumera TRES caminos que comparten el reset
     * —`update()` (Blade), `update_json()` (la SPA) y `PATCH claude/leads/{id}`— y este archivo
     * ejercitaba sólo los dos últimos. O sea que el día que alguien le sacara la llamada a
     * `resetear_si_cambio_la_agenda()` a `update()`, la suite entera seguía en verde y el panel
     * Blade reagendaba dejando los recordatorios del horario VIEJO marcados como enviados: la demo
     * nueva sin recordatorio y el check de fin trabado, exactamente el bug que este archivo existe
     * para que no vuelva. Un consumidor sin test no queda cubierto por los otros dos; es el que se
     * rompe solo.
     *
     * ✅ SE EJERCITA DE PUNTA A PUNTA POR HTTP, no llamando al método a mano. La ruta es el
     * `Route::resource('leads', 'LeadController')` de `routes/web.php`, adentro de
     * `Route::middleware('auth')`: alcanza con un admin en el guard `web` (ver
     * {@see self::autenticar_admin_blade()}, y ojo que NO es el de Sanctum, que es el de la SPA) y
     * con mandar el form entero como form-data (ver {@see self::formulario_blade()}). Contesta 302
     * al `leads.show`, que es lo que contesta el panel de verdad.
     *
     * Se compara contra el camino de Claude y no contra constantes escritas a mano, por lo mismo
     * que el primer test del archivo: un test por camino se puede satisfacer con dos
     * implementaciones distintas; éste, no.
     *
     * @return void
     */
    public function test_el_abm_blade_deja_los_mismos_flags_que_claude_al_reagendar(): void
    {
        /* --- Camino 1: el ABM Blade (PUT leads/{id}, guard web). --- */
        $lead_blade = $this->crear_lead_con_los_flags_puestos('2026-09-08');

        $this->escribir_por_el_panel_blade($lead_blade, ['demo_date' => '2026-09-15'])
            ->assertStatus(302)
            ->assertRedirect(route('leads.show', $lead_blade->id));

        $flags_blade = $this->flags_de($lead_blade);

        /* --- Camino 2: Claude, en OTRO día: los dos leads comparten closer y el 15 ya se lo llevó
               el del Blade. Ver crear_lead_con_los_flags_puestos(). --- */
        $lead_claude = $this->crear_lead_con_los_flags_puestos('2026-09-09');

        $this->escribir_por_claude($lead_claude, ['demo_date' => '2026-09-16'])
            ->assertStatus(200)
            ->assertJsonPath('reagendado', true);

        $flags_claude = $this->flags_de($lead_claude);

        /* --- La comparación que fija la extracción sobre el camino que no tenía red. --- */
        $this->assertSame(
            $flags_blade,
            $flags_claude,
            'Reagendar por el ABM Blade y por Claude dejó flags distintos: el camino Blade tiene su propia definición del reset.'
        );

        /* Y que el reset efectivamente pasó por el Blade: sin esto, comparar dos "no hizo nada"
           también daría igual y el test no probaría nada. */
        $this->assertFalse($flags_blade['recordatorio_demo_enviado'], 'El ABM Blade no reseteó al reagendar.');
        $this->assertFalse($flags_blade['recordatorio_manana_enviado'], 'El ABM Blade no reseteó al reagendar.');
        $this->assertNull($flags_blade['demo_fin_check_reprogramado_para'], 'El ABM Blade no limpió el check de fin.');

        $this->assertSame('2026-09-15', $lead_blade->fresh()->getRawOriginal('demo_date'));
        $this->assertSame('2026-09-16', $lead_claude->fresh()->getRawOriginal('demo_date'));
    }

    /**
     * 🔴 REENVIAR EL MISMO TURNO NO ES REAGENDAR — por los TRES caminos.
     *
     * POR QUÉ IMPORTA, Y POR QUÉ NO ALCANZABA EL NO-OP QUE YA HABÍA
     * -------------------------------------------------------------
     * `test_guardar_sin_mover_la_fecha_no_resetea_nada_...()` prueba el no-op mandando
     * `contact_name`, que es un campo que ni siquiera está en {@see
     * LeadRescheduleFlagsService::CAMPOS_DE_AGENDA}: un `always reset` lo denunciaría, pero
     * cualquier cosa que pase por la comparación de la agenda, no. El caso que falta es el que
     * pasa TODOS los días en el panel: abrir el modal del lead y darle Guardar sin tocar el turno,
     * o sea reenviar el MISMO `demo_date`, el MISMO `demo_start_time` y el MISMO `demo_end_time`.
     *
     * Lo que se rompe si eso resetea de más no es cosmético: los dos latches vuelven a `false` y el
     * cron le manda al lead el recordatorio que YA le mandó. Un recordatorio repetido por cada
     * guardado del modal.
     *
     * Y es un riesgo real, no teórico, porque la comparación es de STRINGS CRUDOS sobre un
     * `varchar(32)` sin normalizar (está escrito en el docblock del servicio). Por eso el test no
     * se queda en el caso feliz y prueba también las variantes que un "normalicemos la hora" del
     * futuro movería de lugar:
     *
     *   - **El mismo horario con espacios de más** (`' 18:00 '`): sigue siendo no-op hoy, y por
     *     tres capas distintas — el middleware global `TrimStrings`, el mutator
     *     `Lead::setDemoStartTimeAttribute()` y el `trim()` de `ClaudeLeadsFieldsController::
     *     normalizar()`. Se prueba de punta a punta y no contra una de las tres.
     *   - **`'18:00:00'` contra `'18:00'`**: NO es no-op, y es deliberado. El docblock del servicio
     *     lo dice con todas las letras ("reescribir 18:00 como 18:00:00 cuenta como reagenda aunque
     *     sea el mismo instante... va para el lado seguro"). Se fija acá porque es la ÚNICA
     *     aserción del archivo que se pondría en rojo si alguien normalizara la hora: el caso de
     *     los espacios seguiría verde igual. Un borde declarado y sin test es un borde que se
     *     cambia sin querer.
     *
     * ❌ LO QUE NO SE PUDO PROBAR COMPARANDO CAMINOS: `'9:00'` contra `'09:00'`. El camino de
     * Claude lo rechaza con 422 antes de escribir nada (`normalizar()` exige
     * `^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$`, o sea dos dígitos en la hora) y los dos caminos del
     * panel no validan el formato: no hay dos caminos que acepten el mismo payload, así que no hay
     * nada que comparar. Se fija lo que sí es verificable —el 422— y se deja anotado que del otro
     * lado ese texto entra crudo.
     *
     * @return void
     */
    public function test_reenviar_el_mismo_turno_no_resetea_nada_por_ninguno_de_los_tres_caminos(): void
    {
        /* Un lead por camino, cada uno en su día: comparten closer y el camino de Claude valida
           contra la grilla real. Ver crear_lead_con_los_flags_puestos(). */
        $lead_blade  = $this->crear_lead_con_los_flags_puestos('2026-09-10');
        $lead_spa    = $this->crear_lead_con_los_flags_puestos('2026-09-11');
        $lead_claude = $this->crear_lead_con_los_flags_puestos('2026-09-14');

        /* Foto de los tres flags ANTES de tocar nada. Se compara contra esto y no entre leads: ver
           el porqué en flags_que_cambiaron(). */
        $antes_blade  = $this->flags_de($lead_blade);
        $antes_spa    = $this->flags_de($lead_spa);
        $antes_claude = $this->flags_de($lead_claude);

        /* --- Variante 1: el MISMO turno, carácter por carácter, por los tres caminos. --- */
        $this->escribir_por_el_panel_blade($lead_blade, $this->turno_de($lead_blade, '18:00', '19:00'))
            ->assertStatus(302);

        $this->escribir_por_el_panel_spa($lead_spa, $this->turno_de($lead_spa, '18:00', '19:00'))
            ->assertStatus(200);

        $this->escribir_por_claude($lead_claude, $this->turno_de($lead_claude, '18:00', '19:00'))
            ->assertStatus(200)
            ->assertJsonPath('reagendado', false)
            ->assertJsonPath('escritos', 0);

        $this->assert_los_tres_caminos_no_tocaron_ningun_flag(
            $this->flags_que_cambiaron($lead_blade, $antes_blade),
            $this->flags_que_cambiaron($lead_spa, $antes_spa),
            $this->flags_que_cambiaron($lead_claude, $antes_claude),
            'Reenviar el turno idéntico reseteó los latches de recordatorio: el lead recibe el recordatorio dos veces.'
        );

        /* --- Variante 2: el mismo horario con un espacio de más. Sigue sin ser reagenda. --- */
        $this->escribir_por_el_panel_blade($lead_blade, $this->turno_de($lead_blade, ' 18:00 ', ' 19:00 '))
            ->assertStatus(302);

        $this->escribir_por_el_panel_spa($lead_spa, $this->turno_de($lead_spa, ' 18:00 ', ' 19:00 '))
            ->assertStatus(200);

        $this->escribir_por_claude($lead_claude, $this->turno_de($lead_claude, ' 18:00 ', ' 19:00 '))
            ->assertStatus(200)
            ->assertJsonPath('reagendado', false)
            ->assertJsonPath('escritos', 0);

        $this->assert_los_tres_caminos_no_tocaron_ningun_flag(
            $this->flags_que_cambiaron($lead_blade, $antes_blade),
            $this->flags_que_cambiaron($lead_spa, $antes_spa),
            $this->flags_que_cambiaron($lead_claude, $antes_claude),
            'El mismo horario con un espacio de más contó como reagenda: el lead recibe el recordatorio dos veces.'
        );

        /* Y la columna quedó sin el espacio, o sea que el turno no se ensució en el camino. */
        $this->assertSame('18:00', $lead_blade->fresh()->getRawOriginal('demo_start_time'));
        $this->assertSame('18:00', $lead_spa->fresh()->getRawOriginal('demo_start_time'));
        $this->assertSame('18:00', $lead_claude->fresh()->getRawOriginal('demo_start_time'));

        /* --- Variante 3, el borde declarado: '18:00:00' NO es el mismo string, así que SÍ resetea,
               y tiene que resetear igual por los tres caminos. Es la aserción que se pondría en
               rojo el día que alguien normalice la hora; ver el docblock. --- */
        $this->escribir_por_el_panel_blade($lead_blade, $this->turno_de($lead_blade, '18:00:00', '19:00:00'))
            ->assertStatus(302);

        $this->escribir_por_el_panel_spa($lead_spa, $this->turno_de($lead_spa, '18:00:00', '19:00:00'))
            ->assertStatus(200);

        $this->escribir_por_claude($lead_claude, $this->turno_de($lead_claude, '18:00:00', '19:00:00'))
            ->assertStatus(200)
            ->assertJsonPath('reagendado', true);

        $flags_blade  = $this->flags_de($lead_blade);
        $flags_spa    = $this->flags_de($lead_spa);
        $flags_claude = $this->flags_de($lead_claude);

        $this->assertSame($flags_blade, $flags_claude, 'El ABM Blade y Claude no coinciden en si 18:00:00 es reagenda.');
        $this->assertSame($flags_spa, $flags_claude, 'La SPA y Claude no coinciden en si 18:00:00 es reagenda.');
        $this->assertFalse(
            $flags_claude['recordatorio_demo_enviado'],
            'Reescribir 18:00 como 18:00:00 dejó de contar como reagenda: alguien normalizó la hora y el servicio dice, explícitamente, que la comparación es de strings crudos.'
        );

        /* --- Lo que NO se puede comparar entre caminos: '9:00'. Claude lo frena con 422 antes de
               escribir; los dos caminos del panel no validan formato y lo guardarían crudo. Se fija
               el 422, que es lo verificable, y no se fuerza el otro lado. --- */
        $lead_nueve = $this->crear_lead_con_los_flags_puestos('2026-09-15');
        $lead_nueve->demo_start_time = '09:00';
        $lead_nueve->demo_end_time   = '10:00';
        $lead_nueve->save();

        $this->withHeaders([
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ])->patchJson('/api/claude/leads/' . $lead_nueve->id, [
            'campos' => ['demo_start_time' => '9:00'],
        ])->assertStatus(422);

        /* No escribió nada: ni la hora ni los flags. */
        $this->assertSame('09:00', $lead_nueve->fresh()->getRawOriginal('demo_start_time'));
        $this->assertTrue($this->flags_de($lead_nueve)['recordatorio_demo_enviado']);
    }

    /**
     * QUÉ flags le cambiaron a este lead respecto de una foto anterior, por nombre.
     *
     * 🔴 POR QUÉ EL NO-OP SE COMPARA POR NOMBRES Y NO POR VALORES, como sí hacen los tests de
     * reagenda de este archivo. `demo_fin_check_reprogramado_para` arranca con el DÍA DEL PROPIO
     * LEAD adentro (`$fecha . ' 19:15:00'`, ver `crear_lead_con_los_flags_puestos()`) y cada camino
     * tiene su lead en su propio día, porque comparten closer. Cuando el reset SÍ pasa los tres
     * terminan en `null` y comparar los valores crudos alcanza; cuando el reset NO tiene que pasar,
     * cada lead conserva su timestamp y los valores son legítimamente distintos. Comparar la lista
     * de flags que se movieron mide exactamente la propiedad que interesa —los tres caminos
     * tocaron lo mismo— sin arrastrar el día de cada fixture.
     *
     * @param Lead                 $lead  Lead a mirar, ya después de la escritura.
     * @param array<string, mixed> $antes Foto de {@see self::flags_de()} tomada antes.
     *
     * @return array<int, string> Nombres de los flags que cambiaron.
     */
    private function flags_que_cambiaron(Lead $lead, array $antes): array
    {
        $cambiaron = [];

        foreach ($this->flags_de($lead) as $flag => $valor) {
            if ($valor !== $antes[$flag]) {
                $cambiaron[] = $flag;
            }
        }

        return $cambiaron;
    }

    /**
     * Los tres caminos movieron los MISMOS flags, y no movieron ninguno.
     *
     * Se comparan los caminos entre sí antes de mirar el contenido, por el criterio de todo el
     * archivo: que los tres coincidan es la propiedad; que coincidan en "no se tocó nada" es lo que
     * hace que la coincidencia signifique algo (tres caminos que resetean de más también
     * coincidirían).
     *
     * @param array<int, string> $cambios_blade  Flags que movió el ABM Blade.
     * @param array<int, string> $cambios_spa    Flags que movió la SPA.
     * @param array<int, string> $cambios_claude Flags que movió el PATCH de Claude.
     * @param string             $mensaje        Qué se rompió, si se rompió.
     *
     * @return void
     */
    private function assert_los_tres_caminos_no_tocaron_ningun_flag(
        array $cambios_blade,
        array $cambios_spa,
        array $cambios_claude,
        string $mensaje
    ): void {
        $this->assertSame($cambios_blade, $cambios_claude, 'El ABM Blade y Claude movieron flags distintos. ' . $mensaje);
        $this->assertSame($cambios_spa, $cambios_claude, 'La SPA y Claude movieron flags distintos. ' . $mensaje);

        $this->assertSame([], $cambios_claude, $mensaje);
    }
}
