<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Models\PruebaWhatsappEnvio;
use App\Services\MetaConversionesService;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaVpsService;
use App\Services\PromoteLeadToClientService;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Carbon\Carbon;
use Database\Seeders\LeadPipelineStatusEmbudoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\PruebaVpsServiceFake;

/**
 * Después de vencer la prueba (misión embudo-whatsapp-precios, etapa E3, criterios CA21 y CA22 del PLAN; §2.3).
 *
 * Dos cosas, las dos sobre el ciclo de la prueba gratuita:
 *
 *  - CA21. El LEAD también vence: `PruebaCicloService::vencer($client)` pasa al lead promovido de `prueba_activa` a
 *    `prueba_vencida` (estado nuevo del catálogo, grupo «Prueba»), por query builder y con un evento de estado en el hilo.
 *    A un lead en cualquier otro estado no lo toca. Contratar desde `prueba_vencida` lo deja en `cerrado_ganado`.
 *  - CA22. Los empujones post-vencimiento: `prueba:empujar` manda por el ticket del dueño la plantilla configurada para el
 *    día 8, 14 y 30 desde el alta (`admin_settings` `prueba_vencida_plantilla_dia_8/14/30`), una sola vez cada una
 *    (deduplicada por `prueba_whatsapp_envios.plantilla`). Sin plantilla configurada no hay paso y devuelve `vencida` como hoy.
 *
 * El reloj es fijo y en hora de Argentina. Alta de la prueba: lunes 02/11/2026 a las 11:00 (siete días: vence el 09/11 a
 * las 11:00). El «día N» de la prueba empieza a las 11:00 de `02/11 + (N - 1)` días: el día 8 es el 09/11 desde las 11:00.
 *
 * Hoy están EN ROJO: el lead no cambia de estado al vencer, `prueba_vencida` no existe en el catálogo de estados y
 * `PruebaWhatsappService::empujar()` corta apenas ve que la prueba venció.
 */
class EmpujonesDespuesDeVencerTest extends BaseDeLaPrueba
{
    /** Alta de la prueba, hora de Argentina. */
    private const ALTA = '2026-11-02 11:00:00';

    /** Zona horaria del negocio. */
    private const AR = 'America/Argentina/Buenos_Aires';

    /**
     * Colas, mail y VPS falsos (la instancia no existe en estos tests, pero el servicio del ciclo lo pide), y las claves de
     * plantillas post-vencimiento limpias.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        $this->app->instance(PruebaVpsService::class, new PruebaVpsServiceFake());

        foreach ([8, 14, 30] as $dia) {
            AdminSetting::set('prueba_vencida_plantilla_dia_' . $dia, '');
        }
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // CA21 — el lead vence con la prueba
    // ------------------------------------------------------------------------------------------

    /**
     * CA21. `vencer($client)` pasa al lead promovido de `prueba_activa` a `prueba_vencida` y deja un evento de estado en
     * el hilo (`is_status_event`: no cuenta como mensaje ni como sin leer). Una segunda corrida (el cron `pruebas:vencer`
     * y el evento `prueba.vencida` de la instancia se pueden pisar) no repite el evento.
     *
     * @return void
     */
    public function test_vencer_pasa_el_lead_a_prueba_vencida(): void
    {
        [$client, $lead] = $this->prueba_en_curso();
        $this->ir_a('2026-11-09 11:10:00');

        app(PruebaCicloService::class)->vencer($client->fresh());

        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, (string) $client->fresh()->estado, 'Precondición: el cliente vence como siempre.');
        $this->assertSame('prueba_vencida', (string) $lead->fresh()->status, 'El lead promovido pasa de prueba_activa a prueba_vencida.');

        $eventos = LeadMessage::query()->where('lead_id', $lead->id)->where('is_status_event', true)->get();
        $this->assertCount(1, $eventos, 'Queda un evento de estado en el hilo.');
        $this->assertStringContainsString((string) LeadPipelineStatus::label_for('prueba_vencida'), (string) $eventos->first()->content, 'El evento dice a qué estado pasó.');

        app(PruebaCicloService::class)->vencer($client->fresh());
        $this->assertSame(1, LeadMessage::query()->where('lead_id', $lead->id)->where('is_status_event', true)->count(), 'Vencer dos veces no repite el evento.');
    }

    /**
     * CA21. El evento `prueba.vencida` de la instancia (C2) vence al cliente por `vencer_por_lead`: el lead también pasa a
     * `prueba_vencida` por ese camino.
     *
     * @return void
     */
    public function test_el_evento_prueba_vencida_de_la_instancia_tambien_pasa_al_lead(): void
    {
        [$client, $lead] = $this->prueba_en_curso();
        $this->ir_a('2026-11-09 11:10:00');

        app(PruebaCicloService::class)->vencer_por_lead($lead->fresh());

        $this->assertSame('prueba_vencida', (string) $lead->fresh()->status);
    }

    /**
     * CA21. A un lead que NO está en `prueba_activa` no se lo toca al vencer: un lead en `cerrado_ganado` (contrató por
     * otro camino), `cerrado_perdido` o en cualquier otro estado queda como está, y sin evento. Control en el mismo test:
     * el lead en `prueba_activa` del mismo estilo SÍ pasa, así el test no pasa por vacío.
     *
     * @return void
     */
    public function test_vencer_no_toca_a_un_lead_en_otro_estado(): void
    {
        [$control_client, $control_lead] = $this->prueba_en_curso('+5493411110001');
        $this->ir_a('2026-11-09 11:10:00');
        app(PruebaCicloService::class)->vencer($control_client->fresh());
        $this->assertSame('prueba_vencida', (string) $control_lead->fresh()->status, 'Control: el lead en prueba_activa sí pasa.');

        foreach (['cerrado_ganado', 'cerrado_perdido', 'implementacion_pedida'] as $indice => $estado) {
            $this->ir_a('2026-11-02 12:00:00');
            [$client, $lead] = $this->prueba_en_curso('+549341111001' . $indice);
            Lead::query()->whereKey($lead->id)->update(['status' => $estado]);

            $this->ir_a('2026-11-09 11:10:00');
            app(PruebaCicloService::class)->vencer($client->fresh());

            $this->assertSame($estado, (string) $lead->fresh()->status, "Un lead en {$estado} no se toca al vencer la prueba.");
            $this->assertSame(0, LeadMessage::query()->where('lead_id', $lead->id)->where('is_status_event', true)->count(), "Y no deja evento de estado ({$estado}).");
        }
    }

    /**
     * CA21. Contratar desde `prueba_vencida` anda: `PromoteLeadToClientService` (el camino de «promover a cliente» del panel)
     * contrata la prueba vencida y deja al lead en `cerrado_ganado` y al cliente en `activo`.
     *
     * @return void
     */
    public function test_contratar_desde_vencida(): void
    {
        [$client, $lead] = $this->prueba_en_curso();
        $this->ir_a('2026-11-09 11:10:00');
        app(PruebaCicloService::class)->vencer($client->fresh());
        $this->assertSame('prueba_vencida', (string) $lead->fresh()->status, 'Precondición: el lead venció con la prueba.');

        app(PromoteLeadToClientService::class)->run($lead->fresh(), $this->admin());

        $this->assertSame('cerrado_ganado', (string) $lead->fresh()->status, 'Contratar desde prueba_vencida lo deja en cerrado_ganado.');
        $this->assertSame(PruebaCicloService::CLIENTE_ACTIVO, (string) $client->fresh()->estado);
        $this->assertSame((int) $client->id, (int) $lead->fresh()->promoted_client_id, 'Es el mismo cliente: no se crea otro.');
    }

    /**
     * CA21 + §10 (riesgo 6). Un lead en `prueba_vencida` no emite `Purchase` hacia Meta: su cliente sigue sin contratar.
     * (La guarda vive en `MetaConversionesService::cliente_promovido_en_prueba`, que mira `clients.estado`.)
     *
     * @return void
     */
    public function test_un_lead_en_prueba_vencida_no_emite_purchase(): void
    {
        [$client, $lead] = $this->prueba_en_curso();
        $this->ir_a('2026-11-09 11:10:00');
        app(PruebaCicloService::class)->vencer($client->fresh());
        $this->assertSame('prueba_vencida', (string) $lead->fresh()->status, 'Precondición: el lead venció con la prueba.');

        $eventos = (new MetaConversionesService())->eventos_alcanzados($lead->fresh());

        $this->assertNotContains(LeadEventoMeta::EVENTO_PURCHASE, $eventos, 'Una prueba vencida sin contratar no es una compra.');
    }

    /**
     * CA21 (estado nuevo del catálogo, PLAN §2.2.1). `prueba_vencida` es un estado del catálogo de leads: grupo «Prueba»,
     * color `#adb5bd`, con etiqueta. Está en los valores por defecto (bases nuevas) y lo da de alta el seeder standalone
     * `LeadPipelineStatusEmbudoSeeder` en las bases existentes, una sola vez aunque se corra dos veces.
     *
     * @return void
     */
    public function test_prueba_vencida_esta_en_el_catalogo_y_en_el_seeder(): void
    {
        $this->assertArrayHasKey('prueba_vencida', LeadPipelineStatus::DEFAULT_STATUSES, 'Falta en DEFAULT_STATUSES.');
        $this->assertNotSame('', trim((string) LeadPipelineStatus::DEFAULT_STATUSES['prueba_vencida']));
        $this->assertSame('#adb5bd', LeadPipelineStatus::DEFAULT_COLORS['prueba_vencida']);
        $this->assertSame('Prueba', LeadPipelineStatus::DEFAULT_STATUS_GROUPS['prueba_vencida']);

        LeadPipelineStatus::query()->where('slug', 'prueba_vencida')->delete();

        (new LeadPipelineStatusEmbudoSeeder())->run();
        (new LeadPipelineStatusEmbudoSeeder())->run();

        $this->assertSame(1, LeadPipelineStatus::query()->where('slug', 'prueba_vencida')->count(), 'El seeder lo da de alta una sola vez.');
        $this->assertSame('#adb5bd', LeadPipelineStatus::query()->where('slug', 'prueba_vencida')->value('color'));
        $this->assertSame(
            (string) LeadPipelineStatus::DEFAULT_STATUSES['prueba_vencida'],
            (string) LeadPipelineStatus::query()->where('slug', 'prueba_vencida')->value('label')
        );
    }

    /**
     * DECISIONES-E3 (menores). `prueba_vencida` va en el catálogo inmediatamente después de `prueba_activa` (grupo «Prueba»),
     * también cuando lo da de alta el seeder standalone en una base que ya tiene el catálogo.
     *
     * @return void
     */
    public function test_prueba_vencida_va_justo_despues_de_prueba_activa(): void
    {
        LeadPipelineStatus::seed_defaults_if_empty();
        LeadPipelineStatus::query()->where('slug', 'prueba_vencida')->delete();

        (new LeadPipelineStatusEmbudoSeeder())->run();

        $activa  = LeadPipelineStatus::query()->where('slug', 'prueba_activa')->value('sort_order');
        $vencida = LeadPipelineStatus::query()->where('slug', 'prueba_vencida')->value('sort_order');
        $this->assertNotNull($vencida, 'El seeder da de alta prueba_vencida.');
        $this->assertSame((int) $activa + 1, (int) $vencida, 'Va inmediatamente después de prueba_activa.');
    }

    /**
     * DECISIONES-E3 (menores). Extender una prueba vencida que vuelve a quedar activa devuelve al lead de `prueba_vencida` a
     * `prueba_activa`, con evento de estado. Si extender no la reactiva (la fecha nueva sigue en el pasado) el lead no se
     * toca; y un lead que no estaba en `prueba_vencida` tampoco.
     *
     * @return void
     */
    public function test_extender_una_vencida_que_se_reactiva_vuelve_el_lead_a_prueba_activa(): void
    {
        [$client, $lead] = $this->negocio_vencido(8);
        $ciclo = app(PruebaCicloService::class);

        /* Siete días desde el alta siguen en el pasado: no se reactiva. */
        $ciclo->cambiar_dias($client->fresh(), 7);
        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, (string) $client->fresh()->estado, 'Precondición: sigue vencida.');
        $this->assertSame('prueba_vencida', (string) $lead->fresh()->status, 'Si no se reactiva, el lead no se toca.');
        $this->assertSame(0, LeadMessage::query()->where('lead_id', $lead->id)->where('is_status_event', true)->count());

        /* Treinta días desde el alta: vuelve a estar en curso. */
        $ciclo->cambiar_dias($client->fresh(), 30);
        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA, (string) $client->fresh()->estado, 'Precondición: la prueba se reactivó.');
        $this->assertSame('prueba_activa', (string) $lead->fresh()->status, 'El lead vuelve a prueba_activa.');
        $this->assertSame(1, LeadMessage::query()->where('lead_id', $lead->id)->where('is_status_event', true)->count(), 'Con su evento de estado.');
    }

    /**
     * DECISIONES-E3 (menores). Extender no mueve a un lead que no está en `prueba_vencida` (por ejemplo, uno que ya pidió la
     * implementación), aunque la prueba se reactive.
     *
     * @return void
     */
    public function test_extender_no_mueve_a_un_lead_que_no_estaba_en_prueba_vencida(): void
    {
        [$client, $lead] = $this->negocio_vencido(8);
        Lead::query()->whereKey($lead->id)->update(['status' => 'implementacion_pedida']);

        app(PruebaCicloService::class)->cambiar_dias($client->fresh(), 30);

        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA, (string) $client->fresh()->estado, 'Precondición: se reactivó.');
        $this->assertSame('implementacion_pedida', (string) $lead->fresh()->status);
    }

    // ------------------------------------------------------------------------------------------
    // CA22 — empujones después de vencer
    // ------------------------------------------------------------------------------------------

    /**
     * CA22. Cliente vencido, `prueba_vencida_plantilla_dia_8 = cc_prueba_vencida_dia_8` (aprobada) y día 8 desde el alta:
     * `prueba:empujar` manda esa plantilla UNA vez, por el ticket del dueño (variable = su primer nombre) y deja una fila en
     * `prueba_whatsapp_envios`. Corrido otra vez el mismo día, y al día siguiente (día 9), no se manda de nuevo.
     *
     * @return void
     */
    public function test_dia_8_manda_la_plantilla_una_vez(): void
    {
        $espia = $this->espiar_sender();
        AdminSetting::set('prueba_vencida_plantilla_dia_8', 'cc_prueba_vencida_dia_8');
        $this->plantilla_aprobada('cc_prueba_vencida_dia_8');
        [$client] = $this->negocio_vencido(8);

        $this->assertSame(0, Artisan::call('prueba:empujar', ['--cliente' => $client->id]));

        $this->assertCount(1, $espia->plantillas, 'El día 8 sale la plantilla configurada.');
        $this->assertSame('cc_prueba_vencida_dia_8', $espia->plantillas[0]['template_name']);
        $this->assertSame(['Marcela'], $espia->plantillas[0]['variables'], 'Con el primer nombre del dueño.');
        $filas = PruebaWhatsappEnvio::query()->where('client_id', $client->id)->where('plantilla', 'cc_prueba_vencida_dia_8')->get();
        $this->assertCount(1, $filas, 'Una fila en prueba_whatsapp_envios.');
        $this->assertSame(PruebaWhatsappEnvio::ESTADO_ENVIADO, (string) $filas->first()->estado);

        /* Corrida otra vez el mismo día. */
        $this->ir_a('2026-11-09 16:00:00');
        $this->assertSame(0, Artisan::call('prueba:empujar', ['--cliente' => $client->id]));
        $this->assertCount(1, $espia->plantillas, 'No se manda dos veces el mismo día.');
        $this->assertSame(1, PruebaWhatsappEnvio::query()->where('client_id', $client->id)->where('plantilla', 'cc_prueba_vencida_dia_8')->count());

        /* Al día siguiente (día 9) no toca ninguna plantilla. */
        $this->ir_a('2026-11-10 13:00:00');
        $this->assertSame(0, Artisan::call('prueba:empujar', ['--cliente' => $client->id]));
        $this->assertCount(1, $espia->plantillas, 'El día 9 no hay empujón.');
        $this->assertSame(1, PruebaWhatsappEnvio::query()->where('client_id', $client->id)->where('plantilla', 'cc_prueba_vencida_dia_8')->count());
    }

    /**
     * CA22 / PLAN §2.3. Los días 14 y 30 desde el alta mandan sus plantillas, cada una una vez (cada día es independiente:
     * el tope de «un mensaje por día» no los pisa porque caen en días distintos). El día 30 cae antes del borrado (gracia
     * de 30 días después de vencer).
     *
     * @return void
     */
    public function test_dias_14_y_30_mandan_sus_plantillas(): void
    {
        $espia = $this->espiar_sender();
        foreach ([8, 14, 30] as $dia) {
            AdminSetting::set('prueba_vencida_plantilla_dia_' . $dia, 'cc_prueba_vencida_dia_' . $dia);
            $this->plantilla_aprobada('cc_prueba_vencida_dia_' . $dia);
        }
        [$client] = $this->negocio_vencido(8);

        foreach ([8, 14, 30] as $dia) {
            $this->ir_al_dia($dia, 13);
            $this->assertSame(0, Artisan::call('prueba:empujar', ['--cliente' => $client->id]));
            $this->assertSame(0, Artisan::call('prueba:empujar', ['--cliente' => $client->id]), 'La segunda corrida del día no repite.');
        }

        $nombres = array_column($espia->plantillas, 'template_name');
        $this->assertSame(
            ['cc_prueba_vencida_dia_8', 'cc_prueba_vencida_dia_14', 'cc_prueba_vencida_dia_30'],
            $nombres,
            'Cada plantilla sale una vez, en su día, y nada más.'
        );
        $this->assertSame(3, PruebaWhatsappEnvio::query()->where('client_id', $client->id)->where('plantilla', 'like', 'cc_prueba_vencida_dia_%')->count());
    }

    /**
     * DECISIONES-E3 (menores). Los hitos son por UMBRAL, no por igualdad: se manda el hito más alto alcanzado (8, 14 o 30) que
     * todavía no se mandó. Si la corrida del día 8 se perdió, la del día 10 manda la del 8; el día 11 no repite; el 20 manda la
     * del 14 y el 33 la del 30. Cada una una sola vez (deduplicadas por `prueba_whatsapp_envios.plantilla`).
     *
     * @return void
     */
    public function test_los_hitos_son_por_umbral(): void
    {
        $espia = $this->espiar_sender();
        foreach ([8, 14, 30] as $dia) {
            AdminSetting::set('prueba_vencida_plantilla_dia_' . $dia, 'cc_prueba_vencida_dia_' . $dia);
            $this->plantilla_aprobada('cc_prueba_vencida_dia_' . $dia);
        }
        [$client] = $this->negocio_vencido(10);

        $corrida = function (int $dia) use ($client) {
            $this->ir_al_dia($dia, 13);
            $this->assertSame(0, Artisan::call('prueba:empujar', ['--cliente' => $client->id]));
        };

        $corrida(10);
        $this->assertSame(['cc_prueba_vencida_dia_8'], array_column($espia->plantillas, 'template_name'), 'La corrida perdida del día 8 se recupera el día 10.');
        $corrida(11);
        $this->assertCount(1, $espia->plantillas, 'El día 11 no repite.');
        $corrida(20);
        $this->assertSame(['cc_prueba_vencida_dia_8', 'cc_prueba_vencida_dia_14'], array_column($espia->plantillas, 'template_name'));
        $corrida(33);
        $this->assertSame(['cc_prueba_vencida_dia_8', 'cc_prueba_vencida_dia_14', 'cc_prueba_vencida_dia_30'], array_column($espia->plantillas, 'template_name'));
        $corrida(40);
        $this->assertCount(3, $espia->plantillas, 'Pasados los tres hitos no se manda más.');
        $this->assertSame(3, PruebaWhatsappEnvio::query()->where('client_id', $client->id)->where('plantilla', 'like', 'cc_prueba_vencida_dia_%')->count());
    }

    /**
     * DECISIONES-E3 (menores). Los hitos anteriores salteados NO se mandan: un negocio que se mira por primera vez el día 20
     * recibe la del 14 (el más alto alcanzado) y nunca la del 8; el día 31 recibe la del 30 y tampoco la del 8.
     *
     * @return void
     */
    public function test_los_hitos_anteriores_salteados_no_se_mandan(): void
    {
        $espia = $this->espiar_sender();
        foreach ([8, 14, 30] as $dia) {
            AdminSetting::set('prueba_vencida_plantilla_dia_' . $dia, 'cc_prueba_vencida_dia_' . $dia);
            $this->plantilla_aprobada('cc_prueba_vencida_dia_' . $dia);
        }
        [$client] = $this->negocio_vencido(20);

        $this->assertSame(0, Artisan::call('prueba:empujar', ['--cliente' => $client->id]));
        $this->assertSame(['cc_prueba_vencida_dia_14'], array_column($espia->plantillas, 'template_name'), 'El día 20 sale la del 14, no la del 8.');

        $this->ir_al_dia(31, 13);
        $this->assertSame(0, Artisan::call('prueba:empujar', ['--cliente' => $client->id]));
        $this->assertSame(['cc_prueba_vencida_dia_14', 'cc_prueba_vencida_dia_30'], array_column($espia->plantillas, 'template_name'), 'Y nunca la del 8.');
    }

    /**
     * CA22. Sin plantilla configurada, el día 8 no manda nada y `empujar()` devuelve `vencida` como hoy. Y con el nombre
     * configurado pero la plantilla no aprobada todavía por Meta, tampoco sale nada por WhatsApp.
     *
     * @return void
     */
    public function test_sin_plantilla_no_manda(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio_vencido(8);
        $servicio = app(PruebaWhatsappService::class);

        $resultado = $servicio->empujar($client, $lead);

        $this->assertSame('vencida', $resultado['estado'], 'Sin plantilla configurada, el resultado es el de hoy.');
        $this->assertCount(0, $espia->plantillas);
        $this->assertSame(0, PruebaWhatsappEnvio::query()->where('client_id', $client->id)->where('plantilla', 'like', 'cc_prueba_vencida_dia_%')->count());

        /* Nombre configurado, plantilla sin aprobar: no sale nada por WhatsApp. */
        AdminSetting::set('prueba_vencida_plantilla_dia_8', 'cc_prueba_vencida_dia_8');
        FollowupTemplate::create([
            'template_name' => 'cc_prueba_vencida_dia_8', 'estado' => PruebaPlantillasService::ESTADO, 'dia_numero' => 1,
            'body_template' => 'Hola {{1}}, tu prueba terminó.', 'language_code' => 'es_AR', 'activa' => false,
            'meta_status' => 'PENDING', 'meta_category' => 'UTILITY', 'uso' => 'Post-vencimiento (test).',
            'solo_si_ingreso_confirmado' => false, 'no_marca_error_de_entrega' => false,
        ]);

        $servicio->empujar($client, $lead);
        $this->assertCount(0, $espia->plantillas, 'La plantilla sin aprobar no sale.');
        $this->assertSame(
            0,
            PruebaWhatsappEnvio::query()->where('client_id', $client->id)->where('plantilla', 'cc_prueba_vencida_dia_8')->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)->count()
        );

        /* Control: cuando Meta la aprueba, el mismo cliente el mismo día sí la recibe (así lo de arriba no pasa por vacío). */
        FollowupTemplate::query()->where('template_name', 'cc_prueba_vencida_dia_8')->update(['activa' => true, 'meta_status' => 'APPROVED']);
        $servicio->empujar($client, $lead);
        $this->assertCount(1, $espia->plantillas, 'Control: con la plantilla aprobada sí sale.');
        $this->assertSame('cc_prueba_vencida_dia_8', $espia->plantillas[0]['template_name']);
    }

    // ------------------------------------------------------------------------------------------
    // Armado
    // ------------------------------------------------------------------------------------------

    /**
     * Un cliente en prueba EN CURSO con su lead en `prueba_activa` (como lo deja el alta: por query builder), que vence el
     * 09/11 a las 11:00 (siete días desde el alta del 02/11 a las 11:00).
     *
     * @param string $telefono Teléfono del dueño (distinto en cada negocio del test).
     *
     * @return array{0: Client, 1: Lead}
     */
    private function prueba_en_curso(string $telefono = '+5493411234567'): array
    {
        $this->ir_a(self::ALTA);

        $client = $this->cliente_en_prueba(7, 7, $telefono);
        $client->trial_days    = 7;
        $client->trial_ends_at = Carbon::parse(self::ALTA, self::AR)->addDays(7);
        $client->save();

        $lead = $this->lead_en_prueba($client);
        Lead::query()->whereKey($lead->id)->update(['status' => 'prueba_activa']);

        return [$client->fresh(), $lead->fresh()];
    }

    /**
     * Un negocio con la prueba VENCIDA (cliente y lead en su estado vencido), con la bienvenida ya enviada el día del alta,
     * parado en el día N desde el alta (a las 13:00, dos horas después de la hora del alta).
     *
     * @param int $dia Día de la prueba (8 = el primer día después de vencer).
     *
     * @return array{0: Client, 1: Lead}
     */
    private function negocio_vencido(int $dia): array
    {
        $this->crear_config_whatsapp();
        $this->admin();

        [$client, $lead] = $this->prueba_en_curso();

        PruebaWhatsappEnvio::create([
            'client_id'  => $client->id,
            'lead_id'    => $lead->id,
            'plantilla'  => PruebaPlantillasService::BIENVENIDA,
            'hito'       => null,
            'estado'     => PruebaWhatsappEnvio::ESTADO_ENVIADO,
            'dia'        => '2026-11-02',
            'enviado_at' => Carbon::parse(self::ALTA, self::AR),
        ]);

        /* Vencida como la deja `pruebas:vencer`: el cliente por su estado; el lead, sin depender de CA21, por la base. */
        Client::query()->whereKey($client->id)->update(['estado' => PruebaCicloService::CLIENTE_PRUEBA_VENCIDA]);
        Lead::query()->whereKey($lead->id)->update(['status' => 'prueba_vencida']);

        $this->ir_al_dia($dia, 13);

        return [$client->fresh(), $lead->fresh()];
    }

    /**
     * Plantilla de Meta registrada y aprobada.
     *
     * @param string $nombre
     *
     * @return void
     */
    private function plantilla_aprobada(string $nombre): void
    {
        FollowupTemplate::create([
            'template_name' => $nombre, 'estado' => PruebaPlantillasService::ESTADO, 'dia_numero' => 1,
            'body_template' => 'Hola {{1}}, tu prueba de ComercioCity terminó. Si querés seguir, escribinos.', 'language_code' => 'es_AR',
            'activa' => true, 'meta_status' => 'APPROVED', 'meta_category' => 'MARKETING', 'uso' => 'Post-vencimiento (test).',
            'solo_si_ingreso_confirmado' => false, 'no_marca_error_de_entrega' => false,
        ]);
    }

    /**
     * Mueve el reloj fijo al día N de la prueba, a la hora dada.
     *
     * @param int $dia  Día de la prueba (1 = el día del alta).
     * @param int $hora Hora (de Argentina) de ese día calendario.
     *
     * @return void
     */
    private function ir_al_dia(int $dia, int $hora): void
    {
        Carbon::setTestNow(Carbon::parse(self::ALTA, self::AR)->startOfDay()->addDays($dia - 1)->setTime($hora, 0, 0));
    }

    /**
     * Mueve el reloj fijo a una hora de Argentina.
     *
     * @param string $fecha `Y-m-d H:i:s`.
     *
     * @return void
     */
    private function ir_a(string $fecha): void
    {
        Carbon::setTestNow(Carbon::parse($fecha, self::AR));
    }
}
