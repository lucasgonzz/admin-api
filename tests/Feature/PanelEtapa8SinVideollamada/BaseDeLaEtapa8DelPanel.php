<?php

namespace Tests\Feature\PanelEtapa8SinVideollamada;

use App\Models\Admin;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Services\ImplementationAiInterpreter;
use App\Services\ImplementationConversationService;
use App\Services\WhatsappSendService;
use Tests\Feature\ClaudeImplementaciones\BaseDeImplementaciones;

/**
 * Andamiaje común de los tests de la etapa 8 del PANEL (misión `panel-etapa-8-sin-videollamada`, 14/10/2026).
 *
 * 🔴 Regla de Lucas (14/10/2026): la videollamada no se nombra salvo que el cliente la pida. El panel todavía se la ofrecía al cliente al
 * entrar a la etapa 8 («nos gustaría hacer una videollamada corta…»), le repreguntaba la disponibilidad y la mostraba como etapa 8 en la
 * lista de progreso de WhatsApp. Estos tests fijan lo que pasa ahora en cada camino del panel, y que las implementaciones que ya tenían
 * hecha la pregunta vieja siguen andando.
 *
 * Los envíos de WhatsApp se anotan en un doble (nunca salen); lo que le llega al CLIENTE se mira además en `implementation_messages`, donde
 * `send_outbound()` persiste todo lo que le manda.
 */
abstract class BaseDeLaEtapa8DelPanel extends BaseDeImplementaciones
{
    /** Teléfono del admin asignado de las pruebas (a donde llegan los avisos). */
    const TELEFONO_DEL_ADMIN = '+5493415550001';

    /** El nombre del admin asignado: es el que el cliente lee en «{admin} te va a responder en breve». */
    const NOMBRE_DEL_ADMIN = 'Martín Prueba';

    /**
     * Se quita el listener que le pone `phone` al admin (ver `con_admin_asignado()`).
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Admin::getEventDispatcher()->forget('eloquent.retrieved: ' . Admin::class);

        parent::tearDown();
    }

    /**
     * Un WhatsappSendService que anota lo que se le manda y no sale a ningún lado.
     *
     * @return WhatsappSendService Con `enviados`: lista de `['to' => ..., 'body' => ...]`.
     */
    protected function whatsapp_que_anota()
    {
        return new class extends WhatsappSendService {
            /** @var array<int, array<string, string>> */
            public $enviados = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->enviados[] = ['to' => $to, 'body' => $body];

                return 'wamid-de-prueba-' . count($this->enviados);
            }
        };
    }

    /**
     * Un intérprete de IA que anota cada llamada y contesta lo que se le diga. Los tests no pueden llamar a Anthropic.
     *
     * @param mixed $valor Lo que contesta en `value` (true = «no quiere la videollamada», false = «sí», null = ambiguo).
     *
     * @return ImplementationAiInterpreter Con `llamadas`: lista de `['clave' => ..., 'respuesta' => ...]`.
     */
    protected function interprete_que_contesta($valor)
    {
        return new class($valor) extends ImplementationAiInterpreter {
            /** @var array<int, array<string, string>> */
            public $llamadas = [];

            /** @var mixed */
            private $valor;

            public function __construct($valor)
            {
                $this->valor = $valor;
            }

            public function interpret(string $question_key, string $question_text, string $client_response): array
            {
                $this->llamadas[] = ['clave' => $question_key, 'respuesta' => $client_response];

                return ['value' => $this->valor];
            }
        };
    }

    /**
     * Le asigna a la implementación un admin con teléfono.
     *
     * 🔴 `notify_assigned_admin()` lee `$admin->phone`, pero la tabla `admins` tiene `phone_number` y no `phone`: en producción ESE AVISO NO
     * SALE NUNCA (hallazgo ya anotado por `ModoAutomaticoDelUserSetupTest`, que nadie arregló). Para poder mirar QUÉ se le avisaría, el test le
     * pone `phone` al admin en memoria cuando se lo lee; no toca la base ni el código del servicio.
     *
     * @param Implementation $implementacion La implementación.
     *
     * @return Admin El admin asignado.
     */
    protected function con_admin_asignado(Implementation $implementacion): Admin
    {
        $admin       = $this->crear_admin();
        $admin->name = self::NOMBRE_DEL_ADMIN;
        $admin->save();

        $implementacion->assigned_admin_id = $admin->id;
        $implementacion->save();

        Admin::retrieved(function ($leido) {
            $leido->setAttribute('phone', self::TELEFONO_DEL_ADMIN);
        });

        return $admin;
    }

    /**
     * Una implementación en la etapa pedida, con su cliente (con teléfono y nombre de negocio) y sus ocho etapas.
     *
     * @param int    $etapa Etapa en la que queda (default 8).
     * @param string $modo  `manual` o `auto` (default `auto`: es el único que manda cosas solo).
     *
     * @return Implementation
     */
    protected function implementacion_en(int $etapa = 8, string $modo = 'auto'): Implementation
    {
        $cliente        = $this->crear_cliente('Panchito Gómez', ['company_name' => 'Panchito S.A.']);
        $implementacion = $this->crear_implementacion($cliente, ['automation_mode' => $modo]);

        return $this->llevar_a_la_etapa($implementacion, $etapa);
    }

    /**
     * El servicio de conversación con el WhatsApp y el intérprete de prueba.
     *
     * @param WhatsappSendService|null          $whatsapp  Por defecto, uno que anota.
     * @param ImplementationAiInterpreter|null $interprete Por defecto, uno que contesta `false`.
     *
     * @return ImplementationConversationService
     */
    protected function servicio($whatsapp = null, $interprete = null): ImplementationConversationService
    {
        return new ImplementationConversationService(
            $whatsapp !== null ? $whatsapp : $this->whatsapp_que_anota(),
            $interprete !== null ? $interprete : $this->interprete_que_contesta(false)
        );
    }

    /**
     * Lo que el cliente de la implementación recibió por WhatsApp, leído de `implementation_messages` (donde `send_outbound()` persiste
     * TODO lo que le manda, salga o no).
     *
     * @param Implementation $implementacion La implementación.
     *
     * @return array<int, string> Los cuerpos, del más viejo al más nuevo.
     */
    protected function lo_que_recibio_el_cliente(Implementation $implementacion): array
    {
        return ImplementationMessage::where('implementation_id', $implementacion->id)
            ->where('direction', 'outbound')
            ->orderBy('id')
            ->pluck('body')
            ->all();
    }

    /**
     * Un mensaje entrante del cliente, con la forma que le pasa el webhook a `handle()`.
     *
     * @param Implementation $implementacion La implementación.
     * @param string         $texto          Lo que escribió.
     *
     * @return array<string, string>
     */
    protected function mensaje_del_cliente(Implementation $implementacion, string $texto): array
    {
        return [
            'from' => (string) $implementacion->client->phone,
            'type' => 'text',
            'body' => $texto,
        ];
    }
}
