<?php

namespace Tests\Feature;

use App\Helpers\AppTime;
use App\Models\FollowupRule;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\BatchLeadAiRecoveryService;
use App\Services\LeadFollowupService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El cron de seguimientos se ocupa SOLO de los leads que nunca contestaron.
 *
 * Decisión de Lucas del 14/9/2026. Entre el 10/9 y el 14/9 el motor pausó "por inactividad" a 27
 * leads, 8 de ellos en plena conversación (el #637 contestó el 12/9 y fue pausado 25 hs después),
 * y mandó `contactado_d4` ("contame a qué se dedica tu empresa") a leads que ya lo habían contado.
 * Todo lead que respondió al menos una vez después del primer saliente sale del alcance del cron:
 * ni plantilla automática, ni pausa automática, ni reintento de un seguimiento fallido. Lo lleva
 * `/leads` a mano.
 *
 * Lo que estos tests fijan, en orden de importancia:
 *
 *  1. 🔴 QUE EL QUE CONTESTÓ NO RECIBA PLANTILLA NI PAUSA, aunque tenga horas de sobra y el cupo
 *     agotado. Es exactamente lo que pasaba antes.
 *  2. 🔴 QUE EL QUE NUNCA CONTESTÓ SIGA RECIBIENDO LO SUYO: plantilla mientras haya cupo, pausa
 *     cuando se agota. La guarda no puede apagar el motor para todos.
 *  3. 🔴 QUE EL ENTRANTE AUTOMÁTICO DEL ANUNCIO NO CUENTE COMO RESPUESTA. En clic-a-WhatsApp el
 *     primer mensaje del hilo es del lead ("¡Hola! Quiero más información") y recién después sale
 *     la bienvenida. Si contara, todo lead de anuncio quedaría afuera antes del primer seguimiento.
 *  4. Que el botón de forzar seguimiento del panel respete la misma regla y lo diga (`result`
 *     nuevo `lead_respondio`, sin tocar nada).
 *  5. Que el reintento de seguimientos fallidos (botón de recovery) tampoco le mande nada.
 *
 * El envío se sustituye a nivel WhatsappSendService, igual que en SeguimientoConVariableVaciaTest:
 * no se toca la red, y lo que se cuenta es qué se le pidió mandar al espía.
 */
class ElCronNoTocaAlLeadQueContestoTest extends TestCase
{
    use DatabaseTransactions;

    /** Horas hacia atrás con las que se fechan el lead y sus mensajes: bien pasadas las 48 de la regla. */
    const HORAS_ATRAS = 72;

    /**
     * Evita que cualquier salida a la red se concrete.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    /**
     * Lead de prueba, creado hace HORAS_ATRAS para que un lead sin mensajes también cumpla la regla.
     *
     * @param string $status Estado del pipeline.
     *
     * @return Lead
     */
    private function crear_lead(string $status = 'nuevo'): Lead
    {
        $lead               = new Lead();
        $lead->phone        = '+5493417778899';
        $lead->contact_name = 'Marina';
        $lead->status       = $status;
        $lead->created_at   = AppTime::now()->subHours(self::HORAS_ATRAS);
        $lead->save();

        return $lead;
    }

    /**
     * Mensaje del hilo, fechado hace HORAS_ATRAS (menos un desplazamiento en minutos para que el
     * orden de creación coincida con el orden cronológico y no haya dos con el mismo instante).
     *
     * @param Lead                 $lead
     * @param string               $sender  'lead' | 'sistema' | 'setter'.
     * @param string               $content
     * @param array<string, mixed> $extra   Columnas adicionales (is_followup, is_status_event, etc.).
     *
     * @return LeadMessage
     */
    private function mensaje(Lead $lead, string $sender, string $content, array $extra = []): LeadMessage
    {
        static $offset = 0;
        $offset++;

        $message = new LeadMessage();
        $message->lead_id    = $lead->id;
        $message->sender     = $sender;
        $message->content    = $content;
        $message->status     = 'enviado';
        $message->created_at = AppTime::now()->subHours(self::HORAS_ATRAS)->addMinutes($offset);
        foreach ($extra as $campo => $valor) {
            $message->{$campo} = $valor;
        }
        $message->save();

        return $message;
    }

    /**
     * Seguimiento por plantilla que SÍ salió (whatsapp_message_id cargado): consume cupo.
     *
     * @param Lead $lead
     * @param int  $numero
     *
     * @return LeadMessage
     */
    private function seguimiento_enviado(Lead $lead, int $numero): LeadMessage
    {
        return $this->mensaje($lead, 'sistema', "Seguimiento {$numero}", [
            'is_followup'         => true,
            'whatsapp_message_id' => 'wamid.TEST.' . uniqid('', true),
        ]);
    }

    /**
     * Regla de seguimiento para un estado. `updateOrCreate` porque `estado` es único en la tabla;
     * lo que se escriba acá se deshace con la transacción.
     *
     * @param string $estado
     * @param int    $horas_espera
     * @param int    $max_followups
     *
     * @return FollowupRule
     */
    private function crear_regla(string $estado, int $horas_espera, int $max_followups): FollowupRule
    {
        return FollowupRule::query()->updateOrCreate(
            ['estado' => $estado],
            ['horas_espera' => $horas_espera, 'max_followups' => $max_followups, 'activa' => true]
        );
    }

    /**
     * Plantilla de seguimiento para un estado. Apaga las que ya hubiera en la base para ese
     * estado, así `find_template_for()` resuelve la de este test y no una sembrada.
     *
     * @param string $estado
     *
     * @return FollowupTemplate
     */
    private function crear_plantilla(string $estado): FollowupTemplate
    {
        FollowupTemplate::query()->where('estado', $estado)->update(['activa' => false]);

        $template                = new FollowupTemplate();
        $template->estado        = $estado;
        $template->dia_numero    = 1;
        $template->template_name = 'cc_seg_prueba_' . $estado . '_d1';
        $template->language_code = 'es_AR';
        $template->body_template = 'Hola {{1}}! Te escribo de ComercioCity.';
        $template->activa        = true;
        $template->save();

        return $template;
    }

    /**
     * Sustituye WhatsappSendService por un espía que registra qué se le pidió mandar.
     *
     * @return WhatsappSendService El espía, ya registrado en el contenedor.
     */
    private function espiar_sender(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Plantillas que se pidió enviar. */
            public $envios = [];

            /**
             * @param string      $to
             * @param string      $template_name
             * @param array       $variables
             * @param string      $language_code
             * @param string|null $context
             *
             * @return string|null
             */
            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->envios[] = [
                    'to'            => $to,
                    'template_name' => $template_name,
                    'variables'     => $variables,
                ];

                return 'wamid.ESPIA.' . uniqid('', true);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Cantidad de seguimientos registrados en el hilo del lead.
     *
     * @param Lead $lead
     *
     * @return int
     */
    private function seguimientos_en_el_hilo(Lead $lead): int
    {
        return LeadMessage::query()->where('lead_id', $lead->id)->where('is_followup', true)->count();
    }

    /**
     * Cantidad de eventos de pausa automática en el hilo del lead.
     *
     * @param Lead $lead
     *
     * @return int
     */
    private function eventos_de_pausa(Lead $lead): int
    {
        return LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('is_status_event', true)
            ->where('content', 'like', '%En Pausa%')
            ->count();
    }

    /**
     * Lead de clic-a-WhatsApp: entrante automático del anuncio + bienvenida nuestra + silencio.
     *
     * @return Lead
     */
    private function lead_de_anuncio_que_nunca_contesto(): Lead
    {
        $lead = $this->crear_lead('nuevo');
        $this->mensaje($lead, 'lead', '¡Hola! Quiero más información');
        $this->mensaje($lead, 'sistema', 'Hola Marina! Bienvenida a ComercioCity.');

        return $lead;
    }

    /**
     * Caso 1. El entrante automático del anuncio NO es una respuesta: el cron sigue llevando al
     * lead como siempre, y a las 72 hs le manda el primer seguimiento.
     *
     * @return void
     */
    public function test_el_cron_sigue_llevando_al_que_nunca_contesto()
    {
        $espia = $this->espiar_sender();
        $this->crear_regla('nuevo', 48, 1);
        $this->crear_plantilla('nuevo');
        $lead = $this->lead_de_anuncio_que_nunca_contesto();

        $resultado = app(LeadFollowupService::class)->process_single_lead($lead);

        $this->assertSame('suggestion', $resultado);
        $this->assertCount(1, $espia->envios);
        $this->assertSame(1, $this->seguimientos_en_el_hilo($lead));
        $this->assertSame('nuevo', $lead->fresh()->status);
    }

    /**
     * Caso 2. 🔴 El mismo lead, pero contestó después de la bienvenida: el cron no lo toca.
     * Ni seguimiento, ni cambio de estado.
     *
     * @return void
     */
    public function test_el_cron_no_manda_plantilla_al_que_contesto()
    {
        $espia = $this->espiar_sender();
        $this->crear_regla('nuevo', 48, 1);
        $this->crear_plantilla('nuevo');
        $lead = $this->lead_de_anuncio_que_nunca_contesto();
        $this->mensaje($lead, 'lead', 'Tengo una ferretería en Rosario');

        $resultado = app(LeadFollowupService::class)->process_single_lead($lead);

        $this->assertNull($resultado);
        $this->assertCount(0, $espia->envios);
        $this->assertSame(0, $this->seguimientos_en_el_hilo($lead));
        $this->assertSame('nuevo', $lead->fresh()->status);
    }

    /**
     * Caso 3. 🔴 Cupo agotado (2 de 2) y contestó: NO se pausa. Es el caso del #637.
     *
     * @return void
     */
    public function test_el_cron_no_pausa_al_que_contesto_aunque_se_le_haya_agotado_el_cupo()
    {
        $espia = $this->espiar_sender();
        $this->crear_regla('contactado', 48, 2);
        $this->crear_plantilla('contactado');
        $lead = $this->crear_lead('contactado');
        $this->mensaje($lead, 'sistema', 'Hola Marina! Bienvenida a ComercioCity.');
        $this->mensaje($lead, 'lead', 'Tengo una ferretería en Rosario');
        $this->seguimiento_enviado($lead, 1);
        $this->seguimiento_enviado($lead, 2);

        $resultado = app(LeadFollowupService::class)->process_single_lead($lead);

        $this->assertNull($resultado);
        $this->assertCount(0, $espia->envios);
        $this->assertSame('contactado', $lead->fresh()->status);
        $this->assertSame(0, $this->eventos_de_pausa($lead));
    }

    /**
     * Caso 4. Mismo cupo agotado, pero nunca habló: la pausa sigue existiendo para él.
     *
     * @return void
     */
    public function test_el_cron_sigue_pausando_al_que_nunca_contesto_cuando_se_agota_el_cupo()
    {
        $this->espiar_sender();
        $this->crear_regla('contactado', 48, 2);
        $this->crear_plantilla('contactado');
        $lead = $this->crear_lead('contactado');
        $this->mensaje($lead, 'sistema', 'Hola Marina! Bienvenida a ComercioCity.');
        $this->seguimiento_enviado($lead, 1);
        $this->seguimiento_enviado($lead, 2);

        $resultado = app(LeadFollowupService::class)->process_single_lead($lead);

        $this->assertSame('paused', $resultado);
        $this->assertSame('en_pausa', $lead->fresh()->status);
        $this->assertSame(1, $this->eventos_de_pausa($lead));
    }

    /**
     * Caso 5. El botón "forzar seguimiento" del panel respeta la misma regla y lo dice.
     *
     * @return void
     */
    public function test_forzar_seguimiento_sobre_el_que_contesto_devuelve_lead_respondio_y_no_toca_nada()
    {
        $espia = $this->espiar_sender();
        $this->crear_regla('nuevo', 48, 1);
        $this->crear_plantilla('nuevo');
        $lead = $this->lead_de_anuncio_que_nunca_contesto();
        $this->mensaje($lead, 'lead', 'Tengo una ferretería en Rosario');
        $mensajes_antes = LeadMessage::query()->where('lead_id', $lead->id)->count();

        $outcome = app(LeadFollowupService::class)->force_followup_now($lead);

        $this->assertSame(['result' => 'lead_respondio', 'followup_number' => null, 'via' => null], $outcome);
        $this->assertCount(0, $espia->envios);
        $this->assertSame($mensajes_antes, LeadMessage::query()->where('lead_id', $lead->id)->count());
        $this->assertSame('nuevo', $lead->fresh()->status);
    }

    /**
     * Y sobre el que nunca contestó, forzar sigue mandando la plantilla (la guarda no rompe el botón).
     *
     * @return void
     */
    public function test_forzar_seguimiento_sobre_el_que_nunca_contesto_sigue_mandando()
    {
        $espia = $this->espiar_sender();
        $this->crear_regla('nuevo', 48, 1);
        $this->crear_plantilla('nuevo');
        $lead = $this->lead_de_anuncio_que_nunca_contesto();

        $outcome = app(LeadFollowupService::class)->force_followup_now($lead);

        $this->assertSame('suggestion', $outcome['result']);
        $this->assertSame('template', $outcome['via']);
        $this->assertCount(1, $espia->envios);
    }

    /**
     * Caso 6. La definición de "contestó", caso por caso.
     *
     * @return void
     */
    public function test_la_definicion_de_contesto()
    {
        $service = app(LeadFollowupService::class);

        /* Sin mensajes: no contestó. */
        $sin_mensajes = $this->crear_lead();
        $this->assertFalse($service->lead_respondio($sin_mensajes));

        /* Lead creado por nosotros (primer mensaje saliente) y un entrante después: contestó. */
        $creado_por_nosotros = $this->crear_lead();
        $this->mensaje($creado_por_nosotros, 'setter', 'Hola Marina, te escribo de ComercioCity.');
        $this->mensaje($creado_por_nosotros, 'lead', 'Hola, sí, contame');
        $this->assertTrue($service->lead_respondio($creado_por_nosotros));

        /* Cero salientes y un entrante: todavía no le dijimos nada, no contestó. */
        $solo_entrante = $this->crear_lead();
        $this->mensaje($solo_entrante, 'lead', '¡Hola! Quiero más información');
        $this->assertFalse($service->lead_respondio($solo_entrante));

        /* El entrante automático del anuncio, la bienvenida y silencio: no contestó. */
        $this->assertFalse($service->lead_respondio($this->lead_de_anuncio_que_nunca_contesto()));

        /* Contestó una vez y después vinieron dos seguimientos sin respuesta: sigue contando como
           que contestó (la guarda de "tres sin respuesta" la aplica /leads, no el cron). */
        $contesto_y_se_callo = $this->crear_lead('contactado');
        $this->mensaje($contesto_y_se_callo, 'sistema', 'Hola Marina! Bienvenida.');
        $this->mensaje($contesto_y_se_callo, 'lead', 'Tengo una ferretería');
        $this->seguimiento_enviado($contesto_y_se_callo, 1);
        $this->seguimiento_enviado($contesto_y_se_callo, 2);
        $this->assertTrue($service->lead_respondio($contesto_y_se_callo));

        /* Un evento de estado con sender 'lead' no es un mensaje (hoy no existen, pero la query
           los excluye igual): no contestó. */
        $solo_evento = $this->crear_lead();
        $this->mensaje($solo_evento, 'sistema', 'Hola Marina! Bienvenida.');
        $this->mensaje($solo_evento, 'lead', '[evento]', ['is_status_event' => true]);
        $this->assertFalse($service->lead_respondio($solo_evento));

        /* Y un evento de estado saliente ANTES del entrante automático tampoco convierte a ese
           entrante en respuesta: el primer saliente que cuenta es la bienvenida, que vino después. */
        $evento_primero = $this->crear_lead();
        $this->mensaje($evento_primero, 'sistema', '[Lead creado]', ['is_status_event' => true]);
        $this->mensaje($evento_primero, 'lead', '¡Hola! Quiero más información');
        $this->mensaje($evento_primero, 'sistema', 'Hola Marina! Bienvenida.');
        $this->assertFalse($service->lead_respondio($evento_primero));
    }

    /**
     * Caso 7. 🔴 El reintento de seguimientos fallidos no le manda nada al que contestó.
     *
     * La respuesta del lead va ANTES del intento fallido a propósito: si fuera después, ya lo
     * omitía el chequeo de "hay un mensaje más nuevo que el fallido" y este test no probaría la
     * guarda nueva. Este es el lead al que el cron, antes del 14/9, le mandó un seguimiento que no
     * correspondía y encima falló.
     *
     * @return void
     */
    public function test_el_reintento_de_seguimientos_fallidos_no_reintenta_al_que_contesto()
    {
        $espia    = $this->espiar_sender();
        $template = $this->crear_plantilla('nuevo');
        $lead     = $this->lead_de_anuncio_que_nunca_contesto();
        $this->mensaje($lead, 'lead', 'Tengo una ferretería en Rosario');
        $this->mensaje($lead, 'sistema', 'Hola Marina! Te escribo de ComercioCity.', [
            'is_followup'          => true,
            'followup_template_id' => $template->id,
            'whatsapp_message_id'  => null,
        ]);

        $stats = app(BatchLeadAiRecoveryService::class)->retry_failed_followups();

        $this->assertSame(0, $stats['retried']);
        $this->assertSame(1, $stats['skipped_followups']);
        $this->assertCount(0, $espia->envios);
    }

    /**
     * Control del caso 7: el mismo intento fallido, en un lead que nunca contestó, SÍ se reintenta.
     * Sin este caso el anterior podría estar verde por un armado que no dispara el reintento nunca.
     *
     * @return void
     */
    public function test_el_reintento_de_seguimientos_fallidos_sigue_reintentando_al_que_nunca_contesto()
    {
        $espia    = $this->espiar_sender();
        $template = $this->crear_plantilla('nuevo');
        $lead     = $this->lead_de_anuncio_que_nunca_contesto();
        $this->mensaje($lead, 'sistema', 'Hola Marina! Te escribo de ComercioCity.', [
            'is_followup'          => true,
            'followup_template_id' => $template->id,
            'whatsapp_message_id'  => null,
        ]);

        $stats = app(BatchLeadAiRecoveryService::class)->retry_failed_followups();

        $this->assertSame(1, $stats['retried']);
        $this->assertSame(0, $stats['skipped_followups']);
        $this->assertCount(1, $espia->envios);
    }
}
