<?php

namespace Tests\Feature;

use App\Jobs\GenerateLeadAiSuggestionJob;
use App\Models\Admin;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadSuggestionEnvioEnCurso;
use App\Services\LeadSuggestionSendService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La sugerencia que se está enviando por WhatsApp no se puede borrar por abajo.
 *
 * EL INCIDENTE, en una línea: el setter aprueba una sugerencia, sale la primera parte por Kapso, el
 * contestador automático del lead contesta al toque, el webhook de ese inbound entra a
 * LeadAiSuggestionScheduler::clear_stale_pending_suggestions() y BORRA —DELETE real, LeadMessage no
 * usa SoftDeletes— la fila del mensaje que en ese mismo instante está saliendo. Cuando el envío
 * vuelve, su UPDATE toca 0 filas (y Eloquent devuelve `true` igual), el `fresh()` devuelve null
 * contra una firma que promete LeadMessage, el TypeError sube hasta el catch(\Throwable) del
 * controller y el hilo termina mostrando un bloque rojo "No se pudo enviar la sugerencia por
 * WhatsApp" arriba de un mensaje que el lead YA TIENE en el teléfono. El setter lo manda de nuevo.
 *
 * Lo que estos tests protegen no es una función: son cuatro decisiones que se pierden solas en el
 * primer refactor que pase por al lado.
 *
 *  1. Que exista un marcador de "esto se está enviando", que el barrido lo respete y que se suelte
 *     en TODAS las salidas (incluidas las que no envían nada y las que salen por excepción). Un
 *     marcador que no se suelta es una sugerencia que el barrido no limpia nunca más.
 *  2. Que el UPDATE de cierre mire cuántas filas tocó y tenga una respuesta para el 0 que no sea
 *     explotar: si la fila igual desapareció, el mensaje que salió se repone en el hilo.
 *  3. Que el sistema no afirme "no se envió" cuando lo único que sabe es "saltó una excepción". Son
 *     dos hechos distintos y entre ellos hay un POST a Kapso que ya devolvió 200.
 *  4. Que proteger la sugerencia no rompa el debounce ni deje al lead sin respuesta: la sugerencia
 *     vieja se sigue borrando cuando NO está en vuelo, y el inbound que llegó durante el envío se
 *     vuelve a atender al terminar.
 *
 * Se le pega al endpoint real, con base, ruteo y servicios de verdad; lo único falso es la red.
 */
class SugerenciaBorradaEnPlenoEnvioTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Texto del bloque rojo que registra un fallo de envío REAL. Es el que tiene que seguir
     * apareciendo cuando de verdad no salió nada, y el que no puede aparecer nunca cuando sí salió.
     */
    private const BLOQUE_FALLO_DE_ENVIO = 'No se pudo enviar la sugerencia por WhatsApp';

    /**
     * Aviso del hilo cuando la sugerencia se borró en pleno envío y el mensaje se repuso.
     */
    private const BLOQUE_SUGERENCIA_REPUESTA = 'El mensaje salió, pero la sugerencia se había borrado';

    /**
     * Aviso del hilo cuando la aprobación falló por algo que NO es el envío.
     */
    private const BLOQUE_APROBACION_FALLIDA = 'Hubo un problema al aprobar la sugerencia';

    /**
     * Corta cualquier salida HTTP real y deja el despacho de sugerencias observable.
     *
     * 🔴 Acá el `Http::fake()` catch-all SÍ va en el setUp, al revés de lo que avisa
     * ReaccionesDelPanelAlLeadTest. Esa advertencia es por la precedencia de los stubs: un catch-all
     * del setUp le gana a la respuesta puntual que un test registre después. En este archivo ningún
     * test registra respuestas propias —el envío se sustituye entero a nivel WhatsappSendService, así
     * que nada de lo que se mide pasa por HTTP—, y el fake queda solo como red para que ninguna
     * notificación lateral (push a admins, aviso de agendamiento) salga a internet de verdad.
     *
     * El Bus se falsea SOLO para GenerateLeadAiSuggestionJob: es el único que no queremos que corra
     * (llamaría a Claude), y necesitamos contar cuántas veces se programó la generación. Todo lo
     * demás sigue despachándose como siempre.
     *
     * 🔴 Acá NO se toca la demora global de la sugerencia (`admin_settings`). Escribirla desde el
     * setUp parece lo prolijo —deja el despacho en un solo cajón del BusFake— y cuesta caro: la
     * escritura de una fila de configuración global choca con cualquier otra corrida de la suite
     * sobre la misma base, y sale como un deadlock de MySQL en el setUp, que no se parece en nada a
     * lo que en realidad pasó. Medido el 2/9/2026 en este slot. Lo que se hace en su lugar es contar
     * los despachos sin importar por qué cajón entraron (ver despachos_de_generacion()).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * 🔴 LA CACHE SE LIMPIA ACÁ, Y NO ES PROLIJIDAD: ES LA CONDICIÓN PARA QUE ESTE ARCHIVO SIRVA.
         *
         * `DatabaseTransactions` revierte la base al terminar cada test, pero NO la cache — y todo
         * lo que este archivo prueba está apoyado en cache: el lease de "envío en curso", la marca
         * de inbound diferido y el token de debounce del scheduler. Con un driver que persiste en
         * disco (`file`, que es lo que dice el `.env.testing` de los slots y lo que corre en
         * producción), `storage/framework/cache/data` SOBREVIVE a la corrida: un lease que quedó de
         * la corrida anterior sobre un id que MySQL vuelve a asignar hace que el barrido saltee
         * cuando el test asume que borra, o que `esta_en_curso()` devuelva true donde el test asume
         * false. Medido: 1 fallo de 8 corridas del mismo commit, siempre en el escenario central, y
         * la corrida con cache sucia tardaba 3:28 contra 19s con la cache limpia.
         *
         * Un test de condición de carrera que él mismo es inestable no sirve como red: cuando falle
         * de verdad nadie le va a creer. Por eso se limpia entero y en el setUp, sin depender de qué
         * driver tenga configurado la máquina donde corra.
         */
        Cache::flush();

        Http::fake();
        Bus::fake([GenerateLeadAiSuggestionJob::class]);
    }

    /**
     * Deja el reloj y la cache como estaban, para el archivo de tests que corra después.
     *
     * El reloj se toca en el test del lease (Carbon::setTestNow para saltar el TTL sin dormir dos
     * minutos) y la cache queda con leases y marcas de este archivo: los dos son estado global que
     * no revierte `DatabaseTransactions`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    /**
     * Cuántas veces se programó la generación de la sugerencia siguiente.
     *
     * Suma los tres cajones del BusFake a propósito. El scheduler elige cómo despachar según la
     * demora configurada: con demora > 0 va a la cola (cajón normal) y con demora 0 sale por
     * `onConnection('sync')->afterResponse()`, que el fake anota en `dispatchedAfterResponse` y que
     * `assertDispatched` NO ve. Lo que estos tests afirman es que la generación se programó, y
     * cuántas veces; por qué canal viajó es una decisión del scheduler que no tienen por qué clavar
     * —y atarse a un canal haría que el archivo pase o falle según la configuración que tenga la base.
     *
     * @return int
     */
    private function despachos_de_generacion(): int
    {
        return Bus::dispatched(GenerateLeadAiSuggestionJob::class)->count()
            + Bus::dispatchedSync(GenerateLeadAiSuggestionJob::class)->count()
            + Bus::dispatchedAfterResponse(GenerateLeadAiSuggestionJob::class)->count();
    }

    /**
     * Admin que aprueba desde el panel.
     *
     * @param string $email Email único del admin.
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Lead con teléfono cargado: sin teléfono no se intenta ningún envío.
     *
     * Queda en `contactado` a propósito, y no en `nuevo`: con `nuevo` el inbound simulado dispararía
     * el onboarding, que manda su propio WhatsApp por el mismo espía y ensucia todo lo que se mide.
     *
     * @param string $nombre Nombre de contacto.
     *
     * @return Lead
     */
    private function crear_lead(string $nombre): Lead
    {
        $lead                     = new Lead();
        $lead->contact_name       = $nombre;
        $lead->company_name       = 'Empresa de ' . $nombre;
        $lead->phone              = '549341' . random_int(1000000, 9999999);
        $lead->status             = 'contactado';
        $lead->claude_auto_reply  = true;
        $lead->save();

        return $lead;
    }

    /**
     * Mensaje entrante del lead, ya en el hilo.
     *
     * Sirve para dos cosas a la vez: abre la ventana de 24hs de WhatsApp (sin un inbound reciente la
     * sugerencia ni se intenta enviar) y hace que el inbound simulado de cada test sea el SEGUNDO,
     * que es desde donde el scheduler programa sugerencia.
     *
     * @param Lead   $lead  Dueño del hilo.
     * @param string $texto Contenido del mensaje.
     *
     * @return LeadMessage
     */
    private function crear_inbound(Lead $lead, string $texto): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'     => $lead->id,
            'sender'      => 'lead',
            'kind'        => 'text',
            'content'     => $texto,
            'status'      => 'enviado',
            'is_followup' => false,
            'sent_at'     => now(),
        ]);
    }

    /**
     * Sugerencia de Claude pendiente de aprobación, tal como la deja el job de generación.
     *
     * @param Lead                 $lead   Dueño del hilo.
     * @param array<string, mixed> $campos Campos a pisar sobre los valores por defecto.
     *
     * @return LeadMessage
     */
    private function crear_sugerencia(Lead $lead, array $campos = []): LeadMessage
    {
        $base = [
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'kind'                  => 'text',
            'content'               => 'Te cuento cómo funciona el sistema.',
            'status'                => 'sugerido',
            'is_followup'           => false,
            'requiere_verificacion' => false,
            /* Estado que Claude propone: se aplica recién al enviar, y sirve para verificar que el
               pipeline avanza igual cuando el mensaje se repone. No se usa `closer_activo`, que
               dispararía la notificación al closer. */
            'suggested_lead_status' => 'interesado',
        ];

        return LeadMessage::create(array_merge($base, $campos));
    }

    /**
     * Sustituye WhatsappSendService por un espía que registra los envíos y, si se le pide, dispara
     * algo ADENTRO del envío.
     *
     * 🔴 El gancho `al_enviar` es el corazón de este archivo y no es una comodidad de escritura.
     * "El borrado ocurre mientras el envío está en curso" es una carrera entre dos requests, y en un
     * test hay un solo proceso: la única forma determinista de reproducirla es meter el otro request
     * ADENTRO del `send_text()`, en el punto exacto en el que en producción el lead recibe el mensaje
     * y su contestador automático contesta. Sin esto no se prueba nada: el barrido correría antes o
     * después del envío, que es el caso que ya funcionaba.
     *
     * Si alguien lo quiere "simplificar" a un barrido antes o después de aprobar, el test sigue
     * pasando con el bug puesto.
     *
     * @return WhatsappSendService El espía, ya registrado en el contenedor.
     */
    private function espiar_sender(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, string> Cada llamada a send_text, reintentos incluidos. */
            public $textos = [];

            /** @var array<int, string> Partes distintas que se intentaron, en orden. */
            public $partes = [];

            /** @var array<int, string> Plantillas Meta que se intentaron enviar, en orden. */
            public $plantillas = [];

            /** @var int Número de parte (contando desde 1) que WhatsApp rechaza. 0 = ninguna. */
            public $falla_en_la_parte = 0;

            /** @var bool True si el fallo simulado es transitorio (el 409 de Kapso) y dispara los reintentos. */
            public $falla_es_transitoria = false;

            /** @var callable|null Se ejecuta adentro del primer envío, antes de contestar. */
            public $al_enviar = null;

            /**
             * Prefijo del id de Meta que devuelve este espía.
             *
             * 🔴 Único por instancia, y no un literal fijo: `lead_messages.whatsapp_message_id` tiene
             * índice UNIQUE. Un test que arma dos escenarios seguidos —o que repone un mensaje
             * borrado— insertaría dos filas con el mismo id y el fallo aparecería como un 422
             * misterioso del endpoint en vez de como lo que es, un fixture que se repite.
             *
             * @var string
             */
            public $prefijo = 'wamid.';

            /**
             * Id de Meta que le tocó a esa parte.
             *
             * @param int $parte Número de parte, contando desde 1.
             *
             * @return string
             */
            public function wamid(int $parte): string
            {
                return $this->prefijo . $parte;
            }

            /**
             * Id de Meta que le tocó a esa plantilla.
             *
             * Va con un espacio de ids propio ("tpl"), separado del de las partes de texto:
             * `lead_messages.whatsapp_message_id` tiene índice UNIQUE, y un test que mande texto y
             * plantilla chocaría contra sí mismo.
             *
             * @param int $envio Número de envío de plantilla, contando desde 1.
             *
             * @return string
             */
            public function wamid_plantilla(int $envio): string
            {
                return $this->prefijo . 'tpl' . $envio;
            }

            /**
             * @param string      $to
             * @param string      $body
             * @param string|null $context
             * @param bool        $skip_failure_notification
             *
             * @return string|null
             */
            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = $body;

                /* Las partes se cuentan por texto distinto y no por llamada: un reintento vuelve a
                   pedir la MISMA parte, y contando llamadas la parte que falla se "arreglaría" sola
                   en el segundo intento. */
                if (empty($this->partes) || end($this->partes) !== $body) {
                    $this->partes[] = $body;
                }

                $parte = count($this->partes);

                if ($this->al_enviar !== null) {
                    /* Una sola vez: la carrera se dispara cuando el lead recibe el primer mensaje.
                       Dejarla armada para cada parte anidaría barridos dentro de barridos y el test
                       mediría el enredo en vez del arreglo. */
                    $callback        = $this->al_enviar;
                    $this->al_enviar = null;
                    $callback();
                }

                if ($this->falla_en_la_parte > 0 && $parte === $this->falla_en_la_parte) {
                    $this->last_send_error = 'Kapso rechazó el envío: otro mensaje en vuelo para esta conversación (simulado en el test).';

                    return null;
                }

                return $this->wamid($parte);
            }

            /**
             * Envío por plantilla Meta (el camino de los seguimientos).
             *
             * Respeta el mismo gancho `al_enviar` que send_text(): la carrera se dispara adentro del
             * envío, tenga la forma que tenga.
             *
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
                $this->plantillas[] = $template_name;

                if ($this->al_enviar !== null) {
                    $callback        = $this->al_enviar;
                    $this->al_enviar = null;
                    $callback();
                }

                return $this->wamid_plantilla(count($this->plantillas));
            }

            /**
             * @return bool
             */
            public function last_send_was_transient(): bool
            {
                return $this->falla_es_transitoria;
            }
        };

        $espia->prefijo = 'wamid.' . uniqid('sug', false) . random_int(1000, 9999) . '.';

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Aprueba la sugerencia por el endpoint real del panel.
     *
     * 🔴 Acepta el texto editado en vez de tener un helper aparte, y no es una comodidad: el
     * escenario que originó esta misión, textual de Lucas, es *"aprueba una sugerencia de la IA, LA
     * EDITA y le da enviar"* — o sea `approve-with-edit`, no `approve` a secas. El panel tiene TRES
     * endpoints de aprobación (`approve`, `approve-with-edit`, `approve-with-actions`), los tres
     * llaman al mismo send_suggestion() y los tres pueden perder la fila en pleno envío. Que el
     * helper cubra el texto editado hace que cualquier test futuro pueda entrar por el endpoint que
     * corresponda en lugar de escribir el `putJson` a mano y quedarse con el más fácil.
     *
     * @param Admin       $admin         Quien aprueba.
     * @param LeadMessage $message       Sugerencia pendiente.
     * @param string|null $texto_editado Texto final que escribió el setter. Null (default) aprueba
     *                                   tal cual lo escribió Claude.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function aprobar(Admin $admin, LeadMessage $message, ?string $texto_editado = null)
    {
        $sesion = $this->actingAs($admin, 'sanctum');

        if ($texto_editado === null) {
            return $sesion->putJson('/api/admin/lead-message/' . $message->id . '/approve');
        }

        return $sesion->putJson(
            '/api/admin/lead-message/' . $message->id . '/approve-with-edit',
            ['edited_content' => $texto_editado]
        );
    }

    /**
     * Dispara un mensaje entrante del lead por el endpoint real.
     *
     * Es el mismo camino que el webhook de Kapso: crea el LeadMessage del lead y llama a
     * `LeadAiSuggestionScheduler::schedule_after_lead_inbound()`, que es el que barre las
     * sugerencias pendientes. No se replican esas dos llamadas a mano justamente para que el día que
     * el barrido se mueva de lugar, estos tests lo sigan.
     *
     * @param Lead   $lead  Lead que escribe.
     * @param string $texto Contenido del inbound.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function simular_inbound(Lead $lead, string $texto)
    {
        return $this->postJson('/api/admin/lead/' . $lead->id . '/simulate-inbound', ['content' => $texto]);
    }

    /**
     * Bloques de error del hilo (los que MessageBubble pinta en rojo), en orden.
     *
     * @param Lead $lead Dueño del hilo.
     *
     * @return \Illuminate\Support\Collection
     */
    private function bloques_de_error(Lead $lead)
    {
        return LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('is_error', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * Indica si el hilo tiene un bloque de error que empieza con ese texto.
     *
     * Se compara por prefijo porque así es como quedan guardados: LeadConversationErrorLogger pega
     * "contexto: detalle" en un solo `content`.
     *
     * @param Lead   $lead    Dueño del hilo.
     * @param string $prefijo Contexto del aviso.
     *
     * @return bool
     */
    private function hay_bloque(Lead $lead, string $prefijo): bool
    {
        foreach ($this->bloques_de_error($lead) as $bloque) {
            if (strpos((string) $bloque->content, $prefijo) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mensajes reales del sistema en el hilo (sugerencias y envíos), sin los bloques de error.
     *
     * @param Lead $lead Dueño del hilo.
     *
     * @return \Illuminate\Support\Collection
     */
    private function mensajes_del_sistema(Lead $lead)
    {
        return LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sender', 'sistema')
            ->where('is_error', false)
            ->where('is_status_event', false)
            ->orderBy('id')
            ->get();
    }

    /**
     * 🔴 EL CASO. El lead contesta mientras la sugerencia sale, y la sugerencia sobrevive.
     *
     * El inbound entra por el endpoint real ADENTRO del `send_text()`, así que el barrido corre con
     * el mensaje todavía en `sugerido` y con el envío a mitad de camino: exactamente la ventana que
     * producía el incidente. Con el marcador puesto, el barrido lo saltea y la fila llega entera al
     * final del envío.
     *
     * @return void
     */
    public function test_la_sugerencia_en_pleno_envio_sobrevive_al_inbound_del_lead()
    {
        $admin = $this->crear_admin('sugerencia-en-vuelo@test.local');
        $lead  = $this->crear_lead('Brisa');
        $this->crear_inbound($lead, 'Hola, quiero ver el sistema');

        $sugerencia = $this->crear_sugerencia($lead);

        $espia = $this->espiar_sender();

        /* El estado del inbound se guarda y se afirma AFUERA: una aserción que falle acá adentro
           sube como excepción por el finally de send_suggestion(), la agarra el catch(\Throwable) del
           controller y el test reporta un 422 en vez de decir qué se rompió. */
        $inbound = new \stdClass();
        $inbound->status = null;

        $espia->al_enviar = function () use ($lead, $inbound) {
            $inbound->status = $this->simular_inbound($lead, 'Ah, y otra cosa: ¿tienen app?')->status();
        };

        $this->aprobar($admin, $sugerencia)->assertStatus(200);

        $this->assertSame(200, $inbound->status, 'El inbound del lead no llegó a correr durante el envío.');
        $this->assertCount(1, $espia->partes, 'El mensaje no salió por WhatsApp.');

        $fresco = LeadMessage::query()->find($sugerencia->id);
        $this->assertNotNull($fresco, 'El barrido borró la sugerencia mientras se estaba enviando.');
        $this->assertSame('enviado', (string) $fresco->status);
        $this->assertSame($espia->wamid(1), (string) $fresco->whatsapp_message_id);
        $this->assertNotNull($fresco->sent_at, 'El mensaje quedó enviado sin fecha de envío.');
        $this->assertSame((int) $admin->id, (int) $fresco->sent_by_admin_id);

        /* El inbound del lead quedó en el hilo igual: protegerse de él no es ignorarlo. */
        $inbounds = LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'lead')->count();
        $this->assertSame(2, $inbounds, 'El mensaje que el lead escribió durante el envío se perdió.');
    }

    /**
     * La red de seguridad: si la fila igual desaparece, el mensaje que salió se repone en el hilo.
     *
     * Acá el borrado saltea el guard a propósito (`LeadMessage::whereKey(...)->delete()` derecho, sin
     * pasar por el barrido). No es un caso imposible: cubre cualquier otro camino que borre esa fila
     * —un `discard_obsolete_suggestion()`, un borrado a mano, el que se escriba mañana— y es la única
     * forma de probar que el UPDATE de cierre mira cuántas filas tocó.
     *
     * @return void
     */
    public function test_si_la_fila_igual_desaparece_el_mensaje_se_recrea_en_el_hilo_como_enviado()
    {
        $admin = $this->crear_admin('sugerencia-repuesta@test.local');
        $lead  = $this->crear_lead('Marcelo');
        $this->crear_inbound($lead, 'Hola, quiero ver el sistema');

        $sugerencia = $this->crear_sugerencia($lead, ['content' => 'Te dejo el link de la demo.']);

        $espia = $this->espiar_sender();
        $espia->al_enviar = function () use ($sugerencia) {
            LeadMessage::query()->whereKey($sugerencia->id)->delete();
        };

        $this->aprobar($admin, $sugerencia)->assertStatus(200);

        $this->assertNull(LeadMessage::query()->find($sugerencia->id), 'La fila vieja tenía que estar borrada en este escenario.');

        $mensajes = $this->mensajes_del_sistema($lead);
        $this->assertCount(1, $mensajes, 'El mensaje que ya había salido por WhatsApp no quedó en el hilo (o quedó duplicado).');

        $repuesto = $mensajes->first();
        $this->assertSame('Te dejo el link de la demo.', (string) $repuesto->content, 'El texto repuesto no es el que recibió el lead.');
        $this->assertSame('enviado', (string) $repuesto->status);
        $this->assertSame($espia->wamid(1), (string) $repuesto->whatsapp_message_id, 'El mensaje repuesto perdió el id real de WhatsApp.');
        $this->assertSame((int) $admin->id, (int) $repuesto->sent_by_admin_id);
        $this->assertNotNull($repuesto->sent_at);

        /* El pipeline avanza igual que en el camino normal: el lead recibió contenido real. */
        $this->assertSame('interesado', (string) $lead->fresh()->status, 'El estado sugerido no se aplicó cuando el mensaje se repuso.');

        /* Y queda constancia entendible en el hilo, más el lead marcado para que lo mire un humano. */
        $this->assertTrue($this->hay_bloque($lead, self::BLOQUE_SUGERENCIA_REPUESTA), 'No quedó constancia en el hilo de que la sugerencia se había borrado.');
        $this->assertNotNull($lead->fresh()->pendiente_revision_at, 'Pasó algo anómalo y el lead no quedó marcado para revisión.');
    }

    /**
     * 🔴 El hilo no puede decir que no se envió cuando sí se envió.
     *
     * Es el daño concreto del incidente: el bloque rojo es lo único que el setter mira, y en los dos
     * escenarios de arriba el lead YA TIENE el mensaje. Un aviso de fallo ahí no es un detalle de
     * copy: es lo que hace que el mismo mensaje salga dos veces.
     *
     * @return void
     */
    public function test_el_bloque_del_hilo_no_dice_que_no_se_envio_cuando_si_se_envio()
    {
        /* Escenario 1: el lead contesta durante el envío y el barrido corre. */
        $admin_uno = $this->crear_admin('sin-bloque-rojo-uno@test.local');
        $lead_uno  = $this->crear_lead('Camila');
        $this->crear_inbound($lead_uno, 'Hola');
        $sugerencia_uno = $this->crear_sugerencia($lead_uno);

        $espia_uno = $this->espiar_sender();
        $espia_uno->al_enviar = function () use ($lead_uno) {
            $this->simular_inbound($lead_uno, 'Perdón, una duda más');
        };

        $this->aprobar($admin_uno, $sugerencia_uno)->assertStatus(200);

        $this->assertFalse(
            $this->hay_bloque($lead_uno, self::BLOQUE_FALLO_DE_ENVIO),
            'El hilo avisa que no se envió un mensaje que el lead recibió (inbound durante el envío).'
        );

        /* Escenario 2: la fila desaparece igual y el mensaje se repone. */
        $admin_dos = $this->crear_admin('sin-bloque-rojo-dos@test.local');
        $lead_dos  = $this->crear_lead('Nahuel');
        $this->crear_inbound($lead_dos, 'Hola');
        $sugerencia_dos = $this->crear_sugerencia($lead_dos);

        $espia_dos = $this->espiar_sender();
        $espia_dos->al_enviar = function () use ($sugerencia_dos) {
            LeadMessage::query()->whereKey($sugerencia_dos->id)->delete();
        };

        $this->aprobar($admin_dos, $sugerencia_dos)->assertStatus(200);

        $this->assertFalse(
            $this->hay_bloque($lead_dos, self::BLOQUE_FALLO_DE_ENVIO),
            'El hilo avisa que no se envió un mensaje que el lead recibió (fila borrada en pleno envío).'
        );
        $this->assertTrue($this->hay_bloque($lead_dos, self::BLOQUE_SUGERENCIA_REPUESTA), 'Se perdió el aviso correcto del mensaje repuesto.');
    }

    /**
     * Una excepción DESPUÉS del envío no se puede reportar como un fallo de WhatsApp.
     *
     * El servicio manda de verdad y recién ahí tira, que es la forma exacta que tenía el incidente
     * (el TypeError del `fresh()` nulo saltaba con el mensaje ya entregado). El controller no sabe si
     * el mensaje salió: lo único que sabe es que subió una excepción, y eso es lo que tiene que decir.
     *
     * @return void
     */
    public function test_una_excepcion_despues_del_envio_no_se_reporta_como_fallo_de_whatsapp()
    {
        $admin = $this->crear_admin('excepcion-tardia@test.local');
        $lead  = $this->crear_lead('Sofía');
        $this->crear_inbound($lead, 'Hola, me interesa');

        $sugerencia = $this->crear_sugerencia($lead);

        $espia = $this->espiar_sender();

        /* Mensaje técnico calcado del incidente real: es exactamente lo que terminaba pegado en el
           hilo, para que un setter lo leyera. */
        $detalle_tecnico = 'Return value of App\Services\LeadSuggestionSendService::send_suggestion() must be an instance of App\Models\LeadMessage, null returned';

        $servicio = new class($espia, $detalle_tecnico) extends LeadSuggestionSendService {
            /** @var string Mensaje de la excepción que se tira una vez enviado el mensaje. */
            private $detalle_tecnico;

            /**
             * @param WhatsappSendService $whatsapp_send_service
             * @param string              $detalle_tecnico
             */
            public function __construct(WhatsappSendService $whatsapp_send_service, string $detalle_tecnico)
            {
                parent::__construct($whatsapp_send_service);

                $this->detalle_tecnico = $detalle_tecnico;
            }

            /**
             * @param LeadMessage $message
             * @param string|null $edited_content
             * @param array|null  $final_actions
             * @param bool        $is_auto_send
             * @param int|null    $sent_by_admin_id
             *
             * @return LeadMessage
             */
            public function send_suggestion(LeadMessage $message, ?string $edited_content = null, ?array $final_actions = null, bool $is_auto_send = false, ?int $sent_by_admin_id = null): LeadMessage
            {
                parent::send_suggestion($message, $edited_content, $final_actions, $is_auto_send, $sent_by_admin_id);

                throw new \TypeError($this->detalle_tecnico);
            }
        };

        $this->app->instance(LeadSuggestionSendService::class, $servicio);

        $respuesta = $this->aprobar($admin, $sugerencia);

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['message' => 'No se pudo completar la aprobación. Revisá la conversación antes de reintentar.']);

        /* El mensaje salió de verdad antes de la excepción. */
        $this->assertCount(1, $espia->partes, 'El escenario no llegó a enviar nada: no prueba lo que dice probar.');

        $this->assertTrue($this->hay_bloque($lead, self::BLOQUE_APROBACION_FALLIDA), 'El hilo no dejó constancia del problema al aprobar.');
        $this->assertFalse($this->hay_bloque($lead, self::BLOQUE_FALLO_DE_ENVIO), 'El hilo afirma que no se envió un mensaje que sí salió.');

        foreach ($this->bloques_de_error($lead) as $bloque) {
            $this->assertFalse(
                strpos((string) $bloque->content, $detalle_tecnico) !== false,
                'El detalle técnico de la excepción quedó pegado en el hilo, que lo lee un setter.'
            );
        }
    }

    /**
     * El camino feliz sigue andando igual: nada de todo esto se paga en el caso normal.
     *
     * @return void
     */
    public function test_el_envio_completo_sigue_andando()
    {
        $admin = $this->crear_admin('envio-completo@test.local');
        $lead  = $this->crear_lead('Iván');
        $this->crear_inbound($lead, 'Hola, contame');

        $sugerencia = $this->crear_sugerencia($lead, ['content' => 'Hola Iván, te cuento.']);

        $espia = $this->espiar_sender();

        $this->aprobar($admin, $sugerencia)->assertStatus(200);

        $this->assertCount(1, $espia->partes);
        $this->assertSame('Hola Iván, te cuento.', $espia->partes[0]);

        $fresco = LeadMessage::query()->find($sugerencia->id);
        $this->assertNotNull($fresco, 'El camino feliz perdió la fila del mensaje.');
        $this->assertSame('enviado', (string) $fresco->status);
        $this->assertNotNull($fresco->sent_at);
        $this->assertSame($espia->wamid(1), (string) $fresco->whatsapp_message_id);
        $this->assertSame((int) $admin->id, (int) $fresco->sent_by_admin_id);
        $this->assertNull($fresco->partial_send_pending, 'Salió todo y quedó texto pendiente igual.');
        $this->assertNull($fresco->whatsapp_send_error, 'Un envío completo quedó con motivo de fallo.');

        $this->assertSame('interesado', (string) $lead->fresh()->status, 'El estado sugerido no se aplicó.');
        $this->assertCount(0, $this->bloques_de_error($lead), 'Un envío completo dejó un bloque de error en el hilo.');
    }

    /**
     * El envío parcial sigue quedando registrado como lo que es: algo llegó y algo no.
     *
     * Es el arreglo del lead #440 y no se puede perder por el camino. La tercera parte falla con el
     * 409 de Kapso, que es transitorio: el envío la reintenta tres veces (pagando el backoff real de
     * 1500ms + 3500ms) antes de cortar. Esas pausas no se tocan para acelerar el test: son la
     * solución al incidente.
     *
     * @return void
     */
    public function test_el_envio_parcial_sigue_andando()
    {
        $admin = $this->crear_admin('envio-parcial@test.local');
        $lead  = $this->crear_lead('Rocío');
        $this->crear_inbound($lead, 'Hola');

        $contenido  = "Primera parte del mensaje.\n---\nSegunda parte del mensaje.\n---\nTercera parte del mensaje.";
        $sugerencia = $this->crear_sugerencia($lead, ['content' => $contenido]);

        $espia                       = $this->espiar_sender();
        $espia->falla_en_la_parte    = 3;
        $espia->falla_es_transitoria = true;

        $this->aprobar($admin, $sugerencia)->assertStatus(200);

        $this->assertCount(3, $espia->partes, 'No se intentaron las tres partes.');
        $this->assertCount(5, $espia->textos, 'La parte que falló no se reintentó las tres veces con backoff.');

        $fresco = LeadMessage::query()->find($sugerencia->id);
        $this->assertNotNull($fresco);
        $this->assertSame('enviado', (string) $fresco->status, 'Un envío parcial se registró como fallo total.');
        $this->assertSame(2, (int) $fresco->sent_parts_count);
        $this->assertSame(3, (int) $fresco->total_parts_count);
        $this->assertSame('Tercera parte del mensaje.', (string) $fresco->partial_send_pending, 'Lo que quedó sin enviar no se puede copiar y remandar.');
        $this->assertTrue(strpos((string) $fresco->whatsapp_send_error, 'Envío parcial') === 0, 'El motivo guardado no dice que el envío fue parcial.');

        $this->assertTrue($this->hay_bloque($lead, 'Envío parcial de la sugerencia'), 'Se perdió el aviso de envío parcial en el hilo.');
        $this->assertFalse($this->hay_bloque($lead, self::BLOQUE_FALLO_DE_ENVIO), 'Un envío parcial se avisó como si no hubiera salido nada.');
        $this->assertNotNull($lead->fresh()->pendiente_revision_at, 'Un envío parcial no marcó el lead para revisión.');
    }

    /**
     * Con la ventana de 24hs cerrada el hilo SÍ tiene que seguir diciendo que no se envió.
     *
     * Es el test que impide corregir de más. El copy de "no se pudo enviar" no está mal en sí mismo:
     * está mal cuando el mensaje salió. Acá no salió nada de verdad —ni se intentó—, así que el aviso
     * es cierto y tiene que quedar.
     *
     * @return void
     */
    public function test_la_ventana_de_24hs_cerrada_sigue_registrando_el_motivo_real()
    {
        $admin = $this->crear_admin('ventana-cerrada@test.local');
        /* Sin ningún inbound del lead: la ventana de 24hs de WhatsApp está cerrada. */
        $lead = $this->crear_lead('Tomás');

        $sugerencia = $this->crear_sugerencia($lead);

        $espia = $this->espiar_sender();

        $this->aprobar($admin, $sugerencia)->assertStatus(200);

        $this->assertCount(0, $espia->textos, 'Se intentó enviar con la ventana de 24hs cerrada.');

        $fresco = LeadMessage::query()->find($sugerencia->id);
        $this->assertNotNull($fresco);
        $this->assertSame('rechazado', (string) $fresco->status);
        $this->assertNull($fresco->sent_at);
        $this->assertSame(
            'Ventana de 24hs de WhatsApp cerrada (el lead no escribió en las últimas 24hs).',
            (string) $fresco->whatsapp_send_error
        );

        $this->assertTrue($this->hay_bloque($lead, self::BLOQUE_FALLO_DE_ENVIO), 'Se perdió el aviso de un fallo de envío REAL.');
        $this->assertSame('contactado', (string) $lead->fresh()->status, 'Se aplicó el estado sugerido sin haber enviado nada.');
    }

    /**
     * Si WhatsApp rechaza todas las partes, el mensaje sigue quedando en `rechazado` con su motivo.
     *
     * @return void
     */
    public function test_un_fallo_real_de_whatsapp_sigue_marcando_rechazado()
    {
        $admin = $this->crear_admin('fallo-real@test.local');
        $lead  = $this->crear_lead('Valentina');
        $this->crear_inbound($lead, 'Hola');

        $sugerencia = $this->crear_sugerencia($lead);

        $espia                       = $this->espiar_sender();
        $espia->falla_en_la_parte    = 1;
        $espia->falla_es_transitoria = true;

        $this->aprobar($admin, $sugerencia)->assertStatus(200);

        $this->assertCount(3, $espia->textos, 'La única parte no se reintentó las tres veces antes de darla por perdida.');

        $fresco = LeadMessage::query()->find($sugerencia->id);
        $this->assertNotNull($fresco);
        $this->assertSame('rechazado', (string) $fresco->status);
        $this->assertNull($fresco->sent_at);
        $this->assertSame($espia->last_send_error, (string) $fresco->whatsapp_send_error, 'No se guardó el motivo real del rechazo.');

        $this->assertTrue($this->hay_bloque($lead, self::BLOQUE_FALLO_DE_ENVIO), 'Un fallo real de envío dejó de avisarse en el hilo.');
        $this->assertSame('contactado', (string) $lead->fresh()->status, 'Se aplicó el estado sugerido sin que el lead recibiera nada.');
    }

    /**
     * 🔴 Sin envío en curso, el barrido sigue borrando la sugerencia vieja.
     *
     * El debounce es la razón de ser de ese barrido: si el lead siguió escribiendo, la sugerencia que
     * Claude armó para el mensaje anterior ya no sirve. Proteger las que están en vuelo no puede
     * volverse "no borrar nunca más", o el panel se llena de sugerencias viejas para siempre.
     *
     * @return void
     */
    public function test_sin_envio_en_curso_el_barrido_sigue_borrando_la_sugerencia_vieja()
    {
        $admin = $this->crear_admin('barrido-normal@test.local');
        $lead  = $this->crear_lead('Julián');
        $this->crear_inbound($lead, 'Hola, quiero info');

        $sugerencia = $this->crear_sugerencia($lead);

        /* Nadie está enviando nada: no hay marcador puesto para este mensaje. */
        $this->assertFalse((new LeadSuggestionEnvioEnCurso())->esta_en_curso((int) $sugerencia->id));

        $this->actingAs($admin, 'sanctum');
        $this->simular_inbound($lead, 'Y otra cosa más')->assertStatus(200);

        $this->assertNull(
            LeadMessage::query()->find($sugerencia->id),
            'El barrido dejó de descartar una sugerencia obsoleta que NO se estaba enviando.'
        );

        $this->assertSame(1, $this->despachos_de_generacion(), 'El inbound dejó de reprogramar la sugerencia siguiente.');
    }

    /**
     * El marcador se suelta en todas las salidas, incluidas las que no envían nada.
     *
     * Es el test que impide el "mensaje inmortal": un marcador que queda puesto es una sugerencia que
     * el barrido no limpia nunca más (hasta que venza el TTL de 5 minutos). Se recorren las tres
     * salidas que devuelven sin haber mandado nada por WhatsApp.
     *
     * Cada tramo mira las dos mitades: que el marcador estuviera puesto MIENTRAS el envío corría (si
     * no, un `false` al final no prueba nada, lo daría también un marcador que nunca se puso) y que
     * esté suelto al terminar. Para las salidas que no llegan a `send_text()` se observa desde el
     * `created` de LeadMessage: las dos escriben su aviso en el hilo antes de volver.
     *
     * @return void
     */
    public function test_el_marcador_se_libera_en_todas_las_salidas()
    {
        $en_curso = new LeadSuggestionEnvioEnCurso();

        /* Salida 1: WhatsApp rechaza todo. Se observa desde adentro del propio envío. */
        $admin_uno = $this->crear_admin('marcador-fallo@test.local');
        $lead_uno  = $this->crear_lead('Franco');
        $this->crear_inbound($lead_uno, 'Hola');
        $sugerencia_uno = $this->crear_sugerencia($lead_uno);

        $espia                    = $this->espiar_sender();
        $espia->falla_en_la_parte = 1;

        $visto_uno = new \stdClass();
        $visto_uno->en_curso = null;
        $espia->al_enviar = function () use ($visto_uno, $en_curso, $sugerencia_uno) {
            $visto_uno->en_curso = $en_curso->esta_en_curso((int) $sugerencia_uno->id);
        };

        $this->aprobar($admin_uno, $sugerencia_uno)->assertStatus(200);

        $this->assertTrue($visto_uno->en_curso, 'El marcador no estaba puesto mientras el mensaje salía por WhatsApp.');
        $this->assertFalse($en_curso->esta_en_curso((int) $sugerencia_uno->id), 'El marcador quedó puesto tras un fallo de envío.');

        /* Salida 2: ventana de 24hs cerrada. No llega a send_text(), pero deja su aviso en el hilo. */
        $admin_dos = $this->crear_admin('marcador-ventana@test.local');
        $lead_dos  = $this->crear_lead('Pilar');
        $sugerencia_dos = $this->crear_sugerencia($lead_dos);

        $visto_dos = new \stdClass();
        $visto_dos->en_curso = null;
        LeadMessage::created(function () use ($visto_dos, $en_curso, $sugerencia_dos) {
            if ($visto_dos->en_curso === null) {
                $visto_dos->en_curso = $en_curso->esta_en_curso((int) $sugerencia_dos->id);
            }
        });

        $this->espiar_sender();
        $this->aprobar($admin_dos, $sugerencia_dos)->assertStatus(200);

        $this->assertTrue($visto_dos->en_curso, 'El marcador no estaba puesto en la salida por ventana cerrada.');
        $this->assertFalse($en_curso->esta_en_curso((int) $sugerencia_dos->id), 'El marcador quedó puesto tras salir por ventana de 24hs cerrada.');

        /* Salida 3: el gate de agendamiento del auto-envío. Entra por el servicio, no por el panel:
           el respaldo automático no tiene endpoint. */
        $lead_tres = $this->crear_lead('Gonzalo');
        $this->crear_inbound($lead_tres, 'Hola');
        $sugerencia_tres = $this->crear_sugerencia($lead_tres, [
            'pending_actions' => ['agendar_demo' => ['fecha' => '2026-09-10', 'hora' => '15:00']],
        ]);

        $visto_tres = new \stdClass();
        $visto_tres->en_curso = null;
        LeadMessage::created(function () use ($visto_tres, $en_curso, $sugerencia_tres) {
            if ($visto_tres->en_curso === null) {
                $visto_tres->en_curso = $en_curso->esta_en_curso((int) $sugerencia_tres->id);
            }
        });

        $espia_tres = $this->espiar_sender();
        app(LeadSuggestionSendService::class)->send_suggestion($sugerencia_tres, null, null, true, null);

        $this->assertCount(0, $espia_tres->textos, 'El gate de agendamiento envió algo por WhatsApp.');
        $this->assertTrue($visto_tres->en_curso, 'El marcador no estaba puesto en la salida por el gate de agendamiento.');
        $this->assertFalse($en_curso->esta_en_curso((int) $sugerencia_tres->id), 'El marcador quedó puesto tras el gate de agendamiento.');
        $this->assertSame('sugerido', (string) LeadMessage::query()->find($sugerencia_tres->id)->status, 'El gate cambió el estado del mensaje.');
    }

    /**
     * El inbound que llegó durante el envío no deja al lead sin respuesta.
     *
     * Es la consecuencia directa de proteger la sugerencia, y no cubrirla sería cambiar un bug por
     * otro: como el barrido ya no la borra, el GenerateLeadAiSuggestionJob que se despacha en ese
     * momento la ve todavía en `sugerido` y se omite. El mensaje que el lead acaba de escribir
     * quedaría sin contestar hasta que volviera a escribir.
     *
     * Por eso se cuentan DOS despachos y no uno: el primero es el del barrido —el que en producción
     * se omite—, y el segundo es la reprogramación que dispara el servicio de envío al terminar,
     * consumiendo la marca de inbound diferido. Sin la marca, este test ve uno solo.
     *
     * @return void
     */
    public function test_el_inbound_durante_el_envio_no_deja_al_lead_sin_sugerencia_nueva()
    {
        $admin = $this->crear_admin('inbound-diferido@test.local');
        $lead  = $this->crear_lead('Malena');
        $this->crear_inbound($lead, 'Hola, quiero ver el sistema');

        $sugerencia = $this->crear_sugerencia($lead);

        $espia = $this->espiar_sender();
        $espia->al_enviar = function () use ($lead) {
            $this->simular_inbound($lead, '¿Y cuánto sale?');
        };

        $this->aprobar($admin, $sugerencia)->assertStatus(200);

        $this->assertSame('enviado', (string) LeadMessage::query()->find($sugerencia->id)->status);

        $this->assertSame(
            2,
            $this->despachos_de_generacion(),
            'El inbound que llegó durante el envío no volvió a programar la generación al terminar: el lead se queda sin respuesta.'
        );

        /* La marca se atiende de a una: si quedara puesta, cada envío siguiente reprogramaría de más.
           Se borra DESPUÉS de reprogramar, no antes: ver hay_inbound_durante_el_envio(). */
        $this->assertFalse(
            (new LeadSuggestionEnvioEnCurso())->hay_inbound_durante_el_envio((int) $sugerencia->id),
            'La marca de inbound diferido quedó sin atender después del envío.'
        );
    }

    /**
     * 🔴 El lease se renueva mientras el envío avanza: un envío más largo que el TTL sigue protegido.
     *
     * Es la diferencia entre un lease y un TTL fijo, y por qué el número dejó de ser 300. Un TTL fijo
     * está mal en las dos direcciones: corto para un envío de varias partes con reintentos (una parte
     * que sale recién en el 3er intento cuesta ~50s, con 6 partes se pasan los 300s), y largo para un
     * request HTTP que muere a los 120s por `max_execution_time` sin que ningún `catch` corra,
     * dejando el marcador vivo y al lead sin respuesta. Renovando con cada parte, el lease dura lo
     * que dura el envío y ni un segundo más.
     *
     * Cómo se modela sin dormir tres minutos: cada parte "tarda" 90 segundos de reloj (menos que el
     * TTL de 120s, así que la renovación de la parte anterior siempre llega a tiempo — una renovación
     * NO resucita un lease ya vencido, y en producción tampoco haría falta que lo hiciera). Para
     * cuando arranca la tercera parte ya pasaron 180 segundos desde `marcar()`: más que el TTL. Con
     * renovación el lease sigue vivo ahí; sin renovación está muerto hace un minuto.
     *
     * @return void
     */
    public function test_el_lease_se_renueva_mientras_el_envio_avanza()
    {
        $admin = $this->crear_admin('lease-renovado@test.local');
        $lead  = $this->crear_lead('Delfina');
        $this->crear_inbound($lead, 'Hola');

        $sugerencia = $this->crear_sugerencia($lead, [
            'content' => "Primera parte del mensaje.\n---\nSegunda parte del mensaje.\n---\nTercera parte del mensaje.",
        ]);

        $en_curso = new LeadSuggestionEnvioEnCurso();

        $paso = new \stdClass();
        $paso->numero                   = 0;
        $paso->lease_en_la_tercera_parte = null;

        $espia = $this->espiar_sender();

        $gancho = null;
        $gancho = function () use ($espia, $paso, $en_curso, $sugerencia, &$gancho) {
            $paso->numero++;

            /* Tercera parte: ya pasaron 180s desde que se tomó el lease, más que su TTL de 120s. */
            if ($paso->numero >= 3) {
                $paso->lease_en_la_tercera_parte = $en_curso->esta_en_curso((int) $sugerencia->id);

                return;
            }

            /* Esta parte "tardó" 90 segundos. Menos que el TTL: la renovación que corre en cuanto la
               parte sale con éxito todavía encuentra el lease vivo y lo estira otros 120. */
            Carbon::setTestNow(Carbon::now()->addSeconds(90));

            $espia->al_enviar = $gancho;
        };
        $espia->al_enviar = $gancho;

        $this->aprobar($admin, $sugerencia)->assertStatus(200);

        Carbon::setTestNow();

        $this->assertCount(3, $espia->partes, 'El envío no llegó a la tercera parte: no prueba lo que dice probar.');
        $this->assertTrue(
            $paso->lease_en_la_tercera_parte,
            'El lease venció en pleno envío: con un TTL fijo el barrido puede borrar la sugerencia mientras todavía salen partes.'
        );

        $this->assertFalse($en_curso->esta_en_curso((int) $sugerencia->id), 'El lease quedó puesto después de terminar el envío.');
        $this->assertSame('enviado', (string) LeadMessage::query()->find($sugerencia->id)->status);
    }

    /**
     * 🔴 El lease tiene dueño: un envío que terminó no puede desproteger a otro que sigue mandando.
     *
     * No es un caso de laboratorio. Dos envíos del MISMO mensaje son alcanzables de verdad:
     * AutoSendLeadAiSuggestionJob y una aprobación humana desde el panel entran los dos con
     * `status = 'sugerido'`, porque el status recién cambia DESPUÉS del POST a Kapso. Sin token, el
     * primero que llega a su `finally` suelta el marcador del segundo, el barrido lo ve libre y borra
     * la fila del que todavía está mandando: el incidente completo, otra vez.
     *
     * @return void
     */
    public function test_un_liberar_con_token_ajeno_no_desprotege_el_envio_en_curso()
    {
        $en_curso   = new LeadSuggestionEnvioEnCurso();
        $message_id = 987654321;

        $token_del_primero = $en_curso->marcar($message_id);
        /* El segundo envío del mismo mensaje arranca y se lleva el lease. */
        $token_del_segundo = $en_curso->marcar($message_id);

        $this->assertNotSame($token_del_primero, $token_del_segundo, 'Dos leases del mismo mensaje salieron con el mismo token.');

        $this->assertFalse(
            $en_curso->liberar($message_id, $token_del_primero),
            'Un envío que ya terminó soltó el lease de otro que sigue mandando.'
        );
        $this->assertTrue(
            $en_curso->esta_en_curso($message_id),
            'El mensaje quedó desprotegido con un envío todavía en curso: el barrido puede borrarle la fila.'
        );

        $this->assertFalse(
            $en_curso->renovar($message_id, $token_del_primero),
            'Un token ajeno pudo estirar un lease que no es suyo.'
        );

        /* El dueño sí puede, y ahí se suelta de verdad. */
        $this->assertTrue($en_curso->liberar($message_id, $token_del_segundo));
        $this->assertFalse($en_curso->esta_en_curso($message_id));
    }

    /**
     * La misma detección de 0 filas en el camino de seguimiento por plantilla.
     *
     * Es la gemela de la salida de éxito principal y tenía el `update()` mudo: ahí arriba la
     * PLANTILLA YA SALIÓ por WhatsApp, así que el efecto externo es idéntico e igual de irreversible.
     * Que hoy el barrido filtre `is_followup = false` no alcanza como defensa —es un detalle de otro
     * archivo, y hay otros caminos que borran esa fila—, y dejar la familia a medio arreglar es la
     * clase de error que este proyecto ya tiene escrita.
     *
     * @return void
     */
    public function test_la_deteccion_de_cero_filas_repone_el_seguimiento_por_plantilla()
    {
        $admin = $this->crear_admin('seguimiento-repuesto@test.local');
        $lead  = $this->crear_lead('Federico');

        $plantilla = FollowupTemplate::create([
            'estado'        => 'contactado',
            'dia_numero'    => 1,
            'template_name' => 'seguimiento_test_' . uniqid('', false),
            'body_template' => 'Hola {{1}}, ¿pudiste ver lo que te mandé?',
            'language_code' => 'es_AR',
            'activa'        => true,
        ]);

        $sugerencia = $this->crear_sugerencia($lead, [
            'content'              => 'Hola Federico, ¿pudiste ver lo que te mandé?',
            'is_followup'          => true,
            'followup_template_id' => $plantilla->id,
        ]);

        $espia = $this->espiar_sender();
        $espia->al_enviar = function () use ($sugerencia) {
            LeadMessage::query()->whereKey($sugerencia->id)->delete();
        };

        $this->aprobar($admin, $sugerencia)->assertStatus(200);

        $this->assertCount(1, $espia->plantillas, 'La plantilla no salió por WhatsApp: el escenario no prueba nada.');
        $this->assertNull(LeadMessage::query()->find($sugerencia->id), 'La fila vieja tenía que estar borrada en este escenario.');

        $mensajes = $this->mensajes_del_sistema($lead);
        $this->assertCount(1, $mensajes, 'El seguimiento que ya había salido por WhatsApp no quedó en el hilo (o quedó duplicado).');

        $repuesto = $mensajes->first();
        $this->assertSame('enviado', (string) $repuesto->status);
        $this->assertSame($espia->wamid_plantilla(1), (string) $repuesto->whatsapp_message_id, 'El seguimiento repuesto perdió el id real de WhatsApp.');
        $this->assertTrue((bool) $repuesto->is_followup, 'El seguimiento se repuso como si fuera un mensaje común.');
        $this->assertSame((int) $plantilla->id, (int) $repuesto->followup_template_id, 'Se perdió con qué plantilla se mandó.');
        $this->assertSame((int) $admin->id, (int) $repuesto->sent_by_admin_id);
        $this->assertNotNull($repuesto->sent_at);

        $this->assertTrue($this->hay_bloque($lead, self::BLOQUE_SUGERENCIA_REPUESTA), 'No quedó constancia en el hilo de que el seguimiento se había borrado.');
        $this->assertFalse($this->hay_bloque($lead, self::BLOQUE_FALLO_DE_ENVIO), 'El hilo avisa que no salió un seguimiento que el lead recibió.');
        $this->assertNotNull($lead->fresh()->pendiente_revision_at, 'Pasó algo anómalo y el lead no quedó marcado para revisión.');
    }

    /**
     * 🔴 El gate de agendamiento no se queda sin su mensaje.
     *
     * El respaldo automático se corta cuando el paquete de acciones agenda o cancela una demo:
     * notifica a los admins por push y por WhatsApp que hay un mensaje esperando aprobación y lo deja
     * en `sugerido`. Si el re-disparo del inbound diferido corriera igual, el barrido que dispara se
     * llevaría puesta esa sugerencia —el lease ya está suelto— y el admin abriría el panel avisado y
     * no encontraría nada.
     *
     * El inbound entra ADENTRO del gate, cuando el mensaje del aviso se escribe en el hilo: ahí el
     * lease todavía está puesto, así que el barrido lo saltea y deja la marca de inbound diferido.
     * Sin la condición, el `finally` termina borrando lo que este mismo request acabó de dejar.
     *
     * @return void
     */
    public function test_el_gate_de_agendamiento_no_se_queda_sin_su_mensaje()
    {
        $admin = $this->crear_admin('gate-inbound@test.local');
        $lead  = $this->crear_lead('Ariel');
        $this->crear_inbound($lead, 'Hola, quiero la demo');

        $sugerencia = $this->crear_sugerencia($lead, [
            'pending_actions' => ['agendar_demo' => ['fecha' => '2026-09-10', 'hora' => '15:00']],
        ]);

        $espia = $this->espiar_sender();

        /* El endpoint de inbound pide sesión, y acá no se entra por el panel: el respaldo automático
           no tiene endpoint, se llama al servicio derecho. */
        $this->actingAs($admin, 'sanctum');

        $disparo = new \stdClass();
        $disparo->hecho = false;
        LeadMessage::created(function () use ($disparo, $lead) {
            /* Una sola vez: el propio inbound crea un LeadMessage y volvería a entrar acá. */
            if ($disparo->hecho) {
                return;
            }
            $disparo->hecho = true;

            $this->simular_inbound($lead, '¿Y si lo hacemos el jueves?');
        });

        app(LeadSuggestionSendService::class)->send_suggestion($sugerencia, null, null, true, null);

        $this->assertTrue($disparo->hecho, 'El inbound no llegó a correr durante el gate: el escenario no prueba nada.');
        $this->assertCount(0, $espia->textos, 'El gate de agendamiento envió algo por WhatsApp.');

        $vivo = LeadMessage::query()->find($sugerencia->id);
        $this->assertNotNull($vivo, 'El re-disparo del inbound diferido borró la sugerencia que el gate dejó esperando aprobación humana.');
        $this->assertSame('sugerido', (string) $vivo->status, 'El mensaje que espera aprobación cambió de estado.');

        $this->assertTrue($this->hay_bloque($lead, 'Mensaje de agendamiento sin aprobar'), 'Se perdió el aviso del gate en el hilo.');

        /* Y no se gastó una generación de más: la sugerencia siguiente ya existe, es la que quedó
           esperando revisión. El único despacho es el del barrido del inbound. */
        $this->assertSame(
            1,
            $this->despachos_de_generacion(),
            'El inbound diferido reprogramó una generación que ya no hacía falta: es una llamada a Claude tirada.'
        );
    }

    /**
     * La fila recreada conserva TODO lo que el hilo renderiza, no sólo el texto.
     *
     * `recrear_mensaje_enviado()` enumera los atributos a mano y su docblock promete que el hilo
     * queda igual que en el camino normal. Un campo que se olvide no rompe nada visible: simplemente
     * desaparece de la conversación, en silencio. Estos cuatro los pinta MessageBubble.vue de
     * admin-spa (los dos badges de demo confirmada, los admins notificados y el desplegable de
     * horarios enviados) y no estaban.
     *
     * @return void
     */
    public function test_la_fila_recreada_conserva_los_campos_que_el_hilo_renderiza()
    {
        $admin = $this->crear_admin('campos-repuestos@test.local');
        $lead  = $this->crear_lead('Bruno');
        $this->crear_inbound($lead, 'Hola');

        $notificaciones = [['evento' => 'demo_agendada', 'admins' => ['Lucas', 'Martín']]];
        /* `calendar_snapshot` es una columna `text` SIN cast: viaja como el string JSON que escribió
           LeadAiService. El test lo guarda igual que producción para que la copia se pruebe tal cual
           es, y no contra una versión conveniente. */
        $snapshot = json_encode(
            ['closers' => [['admin_id' => 7, 'nombre' => 'Ana', 'estado' => 'ok', 'eventos' => []]]],
            JSON_UNESCAPED_UNICODE
        );

        $sugerencia = $this->crear_sugerencia($lead, [
            'content'                         => 'Te dejo los horarios.',
            'ai_reasoning'                    => 'El lead pidió horarios concretos.',
            'marca_demo_ingreso_confirmado'   => true,
            'marca_demo_terminada_confirmada' => true,
            'admin_notifications'             => $notificaciones,
            'calendar_snapshot'               => $snapshot,
        ]);

        $espia = $this->espiar_sender();
        $espia->al_enviar = function () use ($sugerencia) {
            LeadMessage::query()->whereKey($sugerencia->id)->delete();
        };

        $this->aprobar($admin, $sugerencia)->assertStatus(200);

        $repuesto = $this->mensajes_del_sistema($lead)->first();
        $this->assertNotNull($repuesto, 'El mensaje no se repuso en el hilo.');
        $this->assertNotSame((int) $sugerencia->id, (int) $repuesto->id, 'La fila vieja seguía viva: el escenario no probó la recreación.');

        $this->assertTrue((bool) $repuesto->marca_demo_ingreso_confirmado, 'Se perdió el badge "Ingreso a demo confirmado".');
        $this->assertTrue((bool) $repuesto->marca_demo_terminada_confirmada, 'Se perdió el badge "Demo terminada confirmada".');
        $this->assertSame($notificaciones, $repuesto->admin_notifications, 'Se perdieron los avisos de admins notificados.');
        $this->assertSame($snapshot, (string) $repuesto->calendar_snapshot, 'Se perdió el desplegable de horarios enviados.');

        /* Y el snapshot sigue siendo JSON legible: si se hubiera codificado dos veces, el hilo
           mostraría el desplegable roto en vez de vacío, que es peor. */
        $this->assertIsArray(json_decode((string) $repuesto->calendar_snapshot, true), 'El calendar_snapshot repuesto dejó de ser JSON parseable.');

        /* Y lo que ya funcionaba sigue estando. */
        $this->assertSame('El lead pidió horarios concretos.', (string) $repuesto->ai_reasoning);
        $this->assertSame('Te dejo los horarios.', (string) $repuesto->content);
    }

    /**
     * 🔴 EL ESCENARIO DE LUCAS, textual: aprueba la sugerencia, LA EDITA, le da enviar.
     *
     * Los tests de arriba entran todos por `approve` a secas, que es el endpoint sin edición. El
     * incidente que originó la misión pasó por `approve-with-edit`, que es otro controller y otro
     * argumento a send_suggestion(). Acá se cubre el camino feliz de ese endpoint bajo la carrera: el
     * lead contesta durante el envío, la fila sobrevive, y lo que salió por WhatsApp es el texto del
     * setter y no el de Claude.
     *
     * @return void
     */
    public function test_la_sugerencia_editada_sobrevive_al_inbound_y_manda_el_texto_del_setter()
    {
        $admin = $this->crear_admin('editada-en-vuelo@test.local');
        $lead  = $this->crear_lead('Lucía');
        $this->crear_inbound($lead, 'Hola, quiero ver el sistema');

        $sugerencia    = $this->crear_sugerencia($lead, ['content' => 'Te cuento cómo funciona el sistema.']);
        $texto_editado = 'Te cuento cómo funciona, Lucía: arrancamos con una demo de 30 minutos.';

        $espia = $this->espiar_sender();

        $inbound = new \stdClass();
        $inbound->status = null;
        $espia->al_enviar = function () use ($lead, $inbound) {
            $inbound->status = $this->simular_inbound($lead, '¿Y cuánto sale?')->status();
        };

        $this->aprobar($admin, $sugerencia, $texto_editado)->assertStatus(200);

        $this->assertSame(200, $inbound->status, 'El inbound del lead no llegó a correr durante el envío.');
        $this->assertSame([$texto_editado], $espia->partes, 'Al lead le llegó el texto de Claude y no el que escribió el setter.');

        $fresco = LeadMessage::query()->find($sugerencia->id);
        $this->assertNotNull($fresco, 'El barrido borró la sugerencia editada mientras se estaba enviando.');
        $this->assertSame('enviado', (string) $fresco->status);
        $this->assertSame($texto_editado, (string) $fresco->edited_content, 'No quedó guardado el texto que el setter escribió.');
        $this->assertSame('Te cuento cómo funciona el sistema.', (string) $fresco->content, 'Se perdió el texto original de Claude, que es contra lo que se compara la corrección.');
    }

    /**
     * 🔴 El mensaje repuesto lleva el texto EDITADO, que es el que el lead tiene en el teléfono.
     *
     * Es la línea de `recrear_mensaje_enviado()` que más importa de todas y la que Lucas pidió ver
     * clavada: cuando la fila desaparece, lo que se repone en el hilo tiene que ser fiel a lo que
     * SALIÓ, no a lo que Claude había propuesto. Si el hilo mostrara el original, el setter leería un
     * mensaje que nunca se envió, y su corrección se perdería sin dejar rastro.
     *
     * @return void
     */
    public function test_el_mensaje_repuesto_lleva_el_texto_editado_por_el_setter()
    {
        $admin = $this->crear_admin('editada-repuesta@test.local');
        $lead  = $this->crear_lead('Ramiro');
        $this->crear_inbound($lead, 'Hola');

        $sugerencia    = $this->crear_sugerencia($lead, ['content' => 'Te cuento cómo funciona el sistema.']);
        $texto_editado = 'Mirá Ramiro, te lo resumo: una demo de 30 minutos y lo ves andando.';

        $espia = $this->espiar_sender();
        $espia->al_enviar = function () use ($sugerencia) {
            LeadMessage::query()->whereKey($sugerencia->id)->delete();
        };

        $this->aprobar($admin, $sugerencia, $texto_editado)->assertStatus(200);

        $this->assertSame([$texto_editado], $espia->partes, 'Al lead le llegó el texto de Claude y no el que escribió el setter.');
        $this->assertNull(LeadMessage::query()->find($sugerencia->id), 'La fila vieja tenía que estar borrada en este escenario.');

        $mensajes = $this->mensajes_del_sistema($lead);
        $this->assertCount(1, $mensajes, 'El mensaje editado que ya había salido no quedó en el hilo (o quedó duplicado).');

        $repuesto = $mensajes->first();
        $this->assertSame(
            $texto_editado,
            (string) $repuesto->edited_content,
            'El hilo repuesto no muestra el texto editado: el setter ve un mensaje distinto al que recibió el lead.'
        );
        $this->assertSame('Te cuento cómo funciona el sistema.', (string) $repuesto->content, 'Se perdió el original de Claude en la fila repuesta.');
        $this->assertSame('enviado', (string) $repuesto->status);
        $this->assertSame($espia->wamid(1), (string) $repuesto->whatsapp_message_id, 'El mensaje repuesto perdió el id real de WhatsApp.');
        $this->assertSame((int) $admin->id, (int) $repuesto->sent_by_admin_id);

        $this->assertTrue($this->hay_bloque($lead, self::BLOQUE_SUGERENCIA_REPUESTA), 'No quedó constancia en el hilo de que la sugerencia se había borrado.');
        $this->assertFalse($this->hay_bloque($lead, self::BLOQUE_FALLO_DE_ENVIO), 'El hilo avisa que no se envió un mensaje que el lead recibió.');
    }
}
