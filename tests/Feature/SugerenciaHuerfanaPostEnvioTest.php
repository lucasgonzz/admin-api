<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadSuggestionEnvioEnCurso;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La sugerencia que ya salió por WhatsApp no puede quedar colgada en 'sugerido' para siempre.
 *
 * EL INCIDENTE, en una línea (lead 618, Hugo Aguero, 9/9/2026): `enviar_partes()` confirmó que al
 * menos una parte salió por Kapso —el lead la tiene en el teléfono, contestó—, pero el proceso PHP
 * murió (fatal por `max_execution_time`, que ningún `catch`/`finally` agarra) ANTES de llegar al
 * UPDATE final que arma `enviar_sugerencia_aprobada()` recién al terminar. La fila quedó en
 * `status='sugerido'`, con `sent_at` y `whatsapp_message_id` en NULL, para siempre: el panel la
 * seguía mostrando "para aprobar" aunque el lead ya la había contestado. Y hay un segundo daño,
 * peor: una vez que el lease de `LeadSuggestionEnvioEnCurso` vencía sin haber sido liberado (nadie
 * queda vivo para renovarlo ni para soltarlo), un inbound posterior del lead disparaba
 * `LeadAiSuggestionScheduler::clear_stale_pending_suggestions()`, que SÍ borra —DELETE real, sin
 * SoftDeletes— todo lo que siga en `'sugerido'`. Sin la red de `recrear_mensaje_enviado()` (que
 * sólo se dispara desde el propio proceso que hizo el envío, y ese proceso está muerto), el mensaje
 * desaparecía del hilo sin dejar rastro.
 *
 * La corrección (`LeadSuggestionSendService::persistir_progreso_envio()`, invocada desde el
 * callback que `enviar_partes()` dispara tras CADA parte exitosa, y desde
 * `send_followup_suggestion_via_template()` apenas Kapso confirma la plantilla): la fila deja de
 * depender del UPDATE de cierre. Apenas hay evidencia real de que algo salió, `status` deja de ser
 * `'sugerido'` y `whatsapp_message_id`/`sent_at` quedan grabados — con eso alcanza para que
 * `clear_stale_pending_suggestions()` (que sólo borra `status='sugerido'`) no pueda tocar la fila
 * nunca más, sin que haga falta que el lease siga vivo.
 *
 * Lo que este archivo prueba: (1) que la fila nunca queda en el estado exacto del bug —`sugerido`
 * con `sent_at`/`whatsapp_message_id` en NULL— cuando algo revienta después de que salió una parte,
 * y (2) que el barrido ya no puede borrar esa fila aunque el lease ya no la proteja.
 */
class SugerenciaHuerfanaPostEnvioTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Corta cualquier salida HTTP real. Mismo criterio que SugerenciaBorradaEnPlenoEnvioTest: acá
     * ningún test registra respuestas propias, el envío se sustituye entero a nivel
     * WhatsappSendService.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * 🔴 Igual que en SugerenciaBorradaEnPlenoEnvioTest, y por el mismo motivo:
         * DatabaseTransactions revierte la base entre tests, pero NO la cache, y el lease de
         * "envío en curso" vive ahí. Un lease que sobrevive de la corrida anterior sobre un id que
         * MySQL vuelve a asignar rompe la determinación de este archivo.
         */
        Cache::flush();

        Http::fake();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        /*
         * 🔴 El test de la plantilla registra un DB::connection()->beforeExecuting(), y esa lista
         * vive en la conexión compartida del proceso de phpunit —no la resetea DatabaseTransactions,
         * que sólo revierte filas—. Sin esto, el callback (con su \RuntimeException) queda pegado
         * para SIEMPRE y corre de más en cada query de cualquier test que se ejecute después en el
         * mismo proceso, siempre que la única condición que ya tiene (el id de ESE mensaje puntual)
         * nunca vuelva a matchear. Frágil igual: se limpia acá para no depender de que nunca matchee.
         */
        $conexion = \Illuminate\Support\Facades\DB::connection();
        $callbacks = new \ReflectionProperty($conexion, 'beforeExecutingCallbacks');
        $callbacks->setAccessible(true);
        $callbacks->setValue($conexion, []);

        Cache::flush();

        parent::tearDown();
    }

    /**
     * @param string $email
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
     * @param string $nombre
     *
     * @return Lead
     */
    private function crear_lead(string $nombre): Lead
    {
        $lead                    = new Lead();
        $lead->contact_name      = $nombre;
        $lead->company_name      = 'Empresa de ' . $nombre;
        $lead->phone             = '549341' . random_int(1000000, 9999999);
        $lead->status            = 'contactado';
        $lead->claude_auto_reply = true;
        $lead->save();

        return $lead;
    }

    /**
     * Abre la ventana de 24hs de WhatsApp: sin un inbound reciente la sugerencia ni se intenta
     * enviar (ver `is_within_whatsapp_window()`).
     *
     * @param Lead   $lead
     * @param string $texto
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
     * @param Lead                 $lead
     * @param array<string, mixed> $campos
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
            'suggested_lead_status' => 'interesado',
        ];

        return LeadMessage::create(array_merge($base, $campos));
    }

    /**
     * Espía de WhatsappSendService que puede "morir" de verdad —tirar una excepción real, no
     * devolver null— al intentar una parte puntual.
     *
     * 🔴 Es la única forma determinista de reproducir en un test "el proceso murió DESPUÉS de que
     * Kapso confirmó una parte y ANTES del UPDATE de cierre": las partes anteriores a
     * `explota_en_la_parte` salen de verdad (`send_text()` devuelve su wamid, `enviar_partes()` las
     * cuenta como enviadas e invoca el callback de progreso), y recién al intentar la parte que
     * explota, el envío entero se corta con una excepción que sube sin que nada la capture adentro
     * de `enviar_partes()`/`send_body()`/`enviar_sugerencia_aprobada()`.
     *
     * ⚠️ No es un `\Throwable` no-capturable de verdad (un `max_execution_time` real no dispara
     * ningún `catch`, ni siquiera el `try/finally` de `send_suggestion()`) — eso no se puede
     * simular en PHPUnit sin matar el proceso de test. Una `\RuntimeException` corriente SÍ dispara
     * ese `finally` (libera el lease con normalidad), lo cual es una condición MÁS floja que la
     * real, no más estricta: si la fila sobrevive al barrido incluso con el lease ya liberado
     * prolijamente, sobrevive con más razón todavía cuando el lease queda zombie hasta que vence su
     * TTL. Lo único que este archivo no puede probar es el mecanismo exacto por el que el lease
     * queda ausente (liberación prolija vs. TTL vencido); lo que sí prueba, y es lo que importa acá,
     * es que la fila no depende de que el lease siga vivo para sobrevivir al barrido.
     *
     * @return object Instancia del espía, ya registrada en el contenedor.
     */
    private function espia_que_revienta(): object
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, string> Partes distintas que se intentaron, en orden. */
            public $partes = [];

            /** @var int Número de parte (contando desde 1) en la que el envío "muere". 0 = nunca. */
            public $explota_en_la_parte = 0;

            /** @var callable|null Se ejecuta justo antes de explotar, con la parte anterior ya confirmada. */
            public $al_explotar = null;

            /**
             * @var callable|null Se ejecuta con CADA parte que sale con éxito (no con las que
             *                    explotan), justo antes de devolver su wamid — recibe el número de
             *                    parte (contando desde 1). Ajuste 2 del chequeo independiente
             *                    (9/9/2026): sirve para simular "la fila desaparece por otra vía
             *                    mientras el envío por partes sigue en curso", sin necesidad de tirar
             *                    ninguna excepción — al revés de $al_explotar, que sí la tira.
             */
            public $al_confirmar_parte = null;

            /** @var string Prefijo del id de Meta que devuelve este espía (único por instancia, ver el otro archivo de tests). */
            public $prefijo = 'wamid.';

            /**
             * @param int $parte
             *
             * @return string
             */
            public function wamid(int $parte): string
            {
                return $this->prefijo . $parte;
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
                if (empty($this->partes) || end($this->partes) !== $body) {
                    $this->partes[] = $body;
                }

                $parte = count($this->partes);

                if ($this->explota_en_la_parte > 0 && $parte === $this->explota_en_la_parte) {
                    if ($this->al_explotar !== null) {
                        ($this->al_explotar)();
                    }

                    throw new \RuntimeException('Simulado: el proceso murió a mitad del envío (test de la sugerencia huérfana post-envío).');
                }

                if ($this->al_confirmar_parte !== null) {
                    ($this->al_confirmar_parte)($parte);
                }

                return $this->wamid($parte);
            }

            /**
             * @return bool
             */
            public function last_send_was_transient(): bool
            {
                return false;
            }
        };

        $espia->prefijo = 'wamid.' . uniqid('huerfana', false) . random_int(1000, 9999) . '.';

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Aprueba la sugerencia por el endpoint real del panel.
     *
     * @param Admin       $admin
     * @param LeadMessage $message
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function aprobar(Admin $admin, LeadMessage $message)
    {
        return $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/lead-message/' . $message->id . '/approve');
    }

    /**
     * Dispara un mensaje entrante del lead por el endpoint real (mismo camino que el webhook de
     * Kapso: crea el inbound y llama a `LeadAiSuggestionScheduler::schedule_after_lead_inbound()`,
     * que es el que barre las sugerencias pendientes).
     *
     * @param Lead   $lead
     * @param string $texto
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function simular_inbound(Lead $lead, string $texto)
    {
        return $this->postJson('/api/admin/lead/' . $lead->id . '/simulate-inbound', ['content' => $texto]);
    }

    /**
     * 🔴 EL BUG, tal cual pasó con el lead 618. Una parte sale de verdad y recién después el envío
     * revienta: la fila nunca puede quedar como si nada hubiera salido.
     *
     * @return void
     */
    public function test_el_mensaje_no_queda_huerfano_en_sugerido_si_algo_revienta_tras_la_primera_parte()
    {
        $admin = $this->crear_admin('huerfana-uno@test.local');
        $lead  = $this->crear_lead('Hugo');
        $this->crear_inbound($lead, 'Hola, quiero ver el sistema');

        $contenido  = "Primera parte del mensaje.\n---\nSegunda parte del mensaje.";
        $sugerencia = $this->crear_sugerencia($lead, ['content' => $contenido]);

        $espia                       = $this->espia_que_revienta();
        $espia->explota_en_la_parte  = 2;

        $respuesta = $this->aprobar($admin, $sugerencia);

        // El controller atrapa el Throwable y devuelve 422: el punto de este test es la fila, no
        // la respuesta HTTP (que de hecho SÍ tiene que reportar el problema, no un 200 falso).
        $respuesta->assertStatus(422);

        $this->assertCount(2, $espia->partes, 'No se llegó a intentar la segunda parte: este escenario no prueba lo que dice probar.');

        $fresco = LeadMessage::query()->find($sugerencia->id);
        $this->assertNotNull($fresco, 'La fila desapareció; ese es otro escenario (ver SugerenciaBorradaEnPlenoEnvioTest).');

        /*
         * 🔴 LA ASERCIÓN CENTRAL: el bug exacto es status='sugerido' + sent_at=NULL +
         * whatsapp_message_id=NULL pese a que una parte salió de verdad. Nunca puede volver a pasar.
         */
        $es_el_bug_real = (string) $fresco->status === 'sugerido'
            && $fresco->sent_at === null
            && $fresco->whatsapp_message_id === null;
        $this->assertFalse($es_el_bug_real, 'BUG REPRODUCIDO: la fila quedó huérfana en "sugerido", sin sent_at ni whatsapp_message_id, pese a que la primera parte ya había salido por WhatsApp.');

        $this->assertNotSame('sugerido', (string) $fresco->status, 'El status siguió en "sugerido" después de que una parte ya se había confirmado.');
        $this->assertNotNull($fresco->whatsapp_message_id, 'Se perdió el id de WhatsApp de la parte que sí salió.');
        $this->assertSame($espia->wamid(1), (string) $fresco->whatsapp_message_id, 'El id guardado no es el de la parte que realmente salió.');
        $this->assertNotNull($fresco->sent_at, 'Quedó sin fecha de envío pese a que una parte ya había salido.');

        // El progreso quedó grabado con la forma del envío parcial (1 de 2), reusando esa semántica
        // a propósito (ver el comentario grande en enviar_sugerencia_aprobada()).
        $this->assertSame(1, (int) $fresco->sent_parts_count);
        $this->assertSame(2, (int) $fresco->total_parts_count);
        $this->assertSame('Segunda parte del mensaje.', (string) $fresco->partial_send_pending, 'Lo que quedó sin intentar no se puede reconstruir para mandarlo a mano.');
    }

    /**
     * El mismo escenario, pero para el seguimiento por plantilla: un solo `send_template()` que
     * confirma un id y recién después revienta.
     *
     * @return void
     */
    public function test_el_seguimiento_por_plantilla_tampoco_queda_huerfano_si_algo_revienta_tras_confirmarse()
    {
        $admin = $this->crear_admin('huerfana-plantilla@test.local');
        $lead  = $this->crear_lead('Hugo Seguimiento');
        $this->crear_inbound($lead, 'Hola');

        $template = \App\Models\FollowupTemplate::create([
            'estado'         => 'contactado',
            'dia_numero'     => 1,
            'template_name'  => 'seguimiento_test_huerfana',
            'language_code'  => 'es_AR',
            'body_template'  => 'Hola {{1}}, ¿seguís interesado?',
            'activa'         => true,
        ]);

        $sugerencia = $this->crear_sugerencia($lead, [
            'is_followup'          => true,
            'followup_template_id' => $template->id,
        ]);

        $espia = new class extends WhatsappSendService {
            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                return 'wamid.tpl-huerfana.1';
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        /*
         * 🔴 A diferencia del camino de texto (que tiene varias partes y varios intentos de
         * send_text() para "atrapar" un momento intermedio), el seguimiento por plantilla es UN
         * solo send_template(): no hay una segunda llamada de la que colgarse. La forma de
         * reproducir "algo revienta DESPUÉS de que Kapso confirmó, ANTES del UPDATE de cierre" acá
         * es interceptar la query SQL misma: DB::beforeExecuting() corre ANTES de que la consulta
         * llegue a MySQL, así que tirar ahí corta el UPDATE de cierre entero —nunca sale— sin tocar
         * el UPDATE progresivo que ya corrió antes (persistir_progreso_envio(), disparado apenas se
         * confirma el id, ver el comentario grande en send_followup_suggestion_via_template()).
         *
         * Se cuentan los UPDATE contra lead_messages para ESTE mensaje puntual y se revienta en el
         * SEGUNDO: el primero es el progresivo (el que este test tiene que probar que alcanza), el
         * segundo es el de cierre (el que en el incidente real nunca llegaba a correr).
         */
        $updates_a_este_mensaje = 0;
        \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$updates_a_este_mensaje, $sugerencia) {
            if (stripos($sql, 'update `lead_messages`') !== 0) {
                return;
            }

            /*
             * 🔴 Nada de in_array($id, $bindings) a secas: $bindings trae los valores CRUDOS, antes
             * de que la grammar los prepare para PDO —incluye el objeto Carbon de `now()` tal cual—,
             * y una comparación laxa (`==`) de un int contra ese objeto revienta con "Object of
             * class Carbon could not be converted to int" en PHP 7.4. Se filtra a valores escalares
             * antes de comparar.
             */
            $es_este_mensaje = false;
            foreach ($bindings as $valor) {
                if (is_scalar($valor) && (int) $valor === (int) $sugerencia->id) {
                    $es_este_mensaje = true;
                    break;
                }
            }

            if (! $es_este_mensaje) {
                return;
            }

            $updates_a_este_mensaje++;

            if ($updates_a_este_mensaje === 2) {
                throw new \RuntimeException('Simulado: el proceso murió justo antes del UPDATE de cierre (test).');
            }
        });

        $respuesta = $this->aprobar($admin, $sugerencia);
        $respuesta->assertStatus(422);

        $this->assertSame(2, $updates_a_este_mensaje, 'No se llegó a intentar un segundo UPDATE: este escenario no prueba lo que dice probar.');

        $fresco = LeadMessage::query()->find($sugerencia->id);
        $this->assertNotNull($fresco);

        $es_el_bug_real = (string) $fresco->status === 'sugerido'
            && $fresco->sent_at === null
            && $fresco->whatsapp_message_id === null;
        $this->assertFalse($es_el_bug_real, 'BUG REPRODUCIDO en el camino de seguimiento por plantilla: la fila quedó huérfana en "sugerido" pese a que la plantilla ya había salido por WhatsApp.');

        $this->assertNotSame('sugerido', (string) $fresco->status);
        $this->assertSame('wamid.tpl-huerfana.1', (string) $fresco->whatsapp_message_id);
        $this->assertNotNull($fresco->sent_at);
        $this->assertSame((int) $admin->id, (int) $fresco->sent_by_admin_id);
    }

    /**
     * 🔴 EL SEGUNDO ESCENARIO. Con la fila ya fuera de 'sugerido', el barrido de
     * `clear_stale_pending_suggestions()` no la puede borrar nunca más, aunque el lease de
     * "envío en curso" ya no la proteja.
     *
     * @return void
     */
    public function test_el_barrido_ya_no_puede_borrar_un_mensaje_que_alcanzo_a_mandar_una_parte()
    {
        $admin = $this->crear_admin('huerfana-barrido@test.local');
        $lead  = $this->crear_lead('Marcelo');
        $this->crear_inbound($lead, 'Hola, quiero ver el sistema');

        $contenido  = "Primera parte del mensaje.\n---\nSegunda parte del mensaje.";
        $sugerencia = $this->crear_sugerencia($lead, ['content' => $contenido]);

        $en_curso = new LeadSuggestionEnvioEnCurso();

        $espia                      = $this->espia_que_revienta();
        $espia->explota_en_la_parte = 2;

        /*
         * Justo antes de "morir": confirma que el progreso de la primera parte YA se grabó (si no
         * fuera así, este test no probaría nada) y fuerza al lease a quedar ausente —belt-and-
         * suspenders sobre lo que el `finally` de send_suggestion() ya hace solo con una excepción
         * capturable (ver el docblock de espia_que_revienta()): lo que importa es que, sin lease,
         * la fila sobreviva igual, porque ya dejó de estar en 'sugerido'.
         */
        $espia->al_explotar = function () use ($sugerencia) {
            $this->assertNotSame(
                'sugerido',
                (string) LeadMessage::query()->find($sugerencia->id)->status,
                'El progreso de la primera parte no se había grabado todavía: este test no prueba lo que dice probar.'
            );

            Cache::flush();
        };

        $this->aprobar($admin, $sugerencia)->assertStatus(422);

        $this->assertFalse(
            $en_curso->esta_en_curso((int) $sugerencia->id),
            'El lease seguía protegiendo la fila: este test necesita que NO la proteja para probar lo que dice probar.'
        );

        // Llega un inbound del lead: en el incidente real esto disparaba el barrido, que borraba
        // la fila (DELETE real, sin SoftDeletes) porque el lease ya no la protegía.
        $this->actingAs($admin, 'sanctum');
        $this->simular_inbound($lead, 'Y otra cosa más')->assertStatus(200);

        $sobrevivio = LeadMessage::query()->find($sugerencia->id);
        $this->assertNotNull($sobrevivio, 'BUG REPRODUCIDO: el barrido borró un mensaje que ya había mandado una parte por WhatsApp, sin lease que lo protegiera.');
        $this->assertNotSame('sugerido', (string) $sobrevivio->status);
        $this->assertSame($espia->wamid(1), (string) $sobrevivio->whatsapp_message_id);
        $this->assertNotNull($sobrevivio->sent_at);
    }

    /**
     * 🔴 EL TERCER ESCENARIO (ajuste del chequeo independiente de esta misma misión, 9/9/2026): la
     * fila puede desaparecer por OTRA vía —no por el barrido, no en el UPDATE de cierre— justo
     * DURANTE `persistir_progreso_envio()` de una parte INTERMEDIA (acá, la 1ra de 3), sin que nada
     * explote. Antes de este ajuste esa escritura era fire-and-forget: si el UPDATE tocaba 0 filas,
     * nadie se enteraba. Con el ajuste, la detecta y la deja asentada en el log, pero A PROPÓSITO no
     * recrea ahí mismo (ver el comentario grande de persistir_progreso_envio() sobre por qué
     * recrear a mitad del bucle de partes sería más riesgoso que el problema que resuelve) — el
     * envío de las partes 2 y 3 tiene que seguir su curso normal, y recién el UPDATE de cierre
     * (que ya sabía recrear desde antes de esta misión) repone la fila una sola vez, con el estado
     * final completo.
     *
     * Lo que este test prueba: que la desaparición a mitad de camino NO corta el envío de las partes
     * que faltan, y que al final queda UNA sola fila (no cero, no duplicada) con las tres partes
     * contabilizadas.
     *
     * @return void
     */
    public function test_la_fila_desaparece_en_pleno_envio_por_partes_y_no_se_pierde_el_mensaje()
    {
        $admin = $this->crear_admin('huerfana-parte-intermedia@test.local');
        $lead  = $this->crear_lead('Ariel');
        $this->crear_inbound($lead, 'Hola, quiero ver el sistema');

        $contenido  = "Primera parte del mensaje.\n---\nSegunda parte del mensaje.\n---\nTercera parte del mensaje.";
        $sugerencia = $this->crear_sugerencia($lead, ['content' => $contenido]);

        $espia = $this->espia_que_revienta();

        // explota_en_la_parte queda en 0 (nunca explota): acá no hace falta ningún fatal, alcanza con
        // que la fila desaparezca por otra vía justo cuando la parte 1 (intermedia, no la última) ya
        // se confirmó y persistir_progreso_envio() va a intentar su UPDATE.
        $espia->al_confirmar_parte = function (int $parte) use ($sugerencia) {
            if ($parte === 1) {
                LeadMessage::query()->whereKey($sugerencia->id)->delete();
            }
        };

        $respuesta = $this->aprobar($admin, $sugerencia);

        // Nada explota en este escenario: el envío entero se completa y el controller responde 200,
        // al revés de los dos tests de arriba (que sí simulan un fatal real).
        $respuesta->assertStatus(200);

        $this->assertCount(3, $espia->partes, 'No se intentaron las tres partes: la desaparición de la fila cortó el envío antes de tiempo.');

        $this->assertNull(LeadMessage::query()->find($sugerencia->id), 'La fila vieja tenía que estar borrada en este escenario.');

        $mensajes = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sender', 'sistema')
            ->where('is_error', false)
            ->where('is_status_event', false)
            ->orderBy('id')
            ->get();

        $this->assertCount(1, $mensajes, 'El mensaje que salió por WhatsApp no quedó en el hilo, o quedó duplicado por más de una recreación.');

        $repuesto = $mensajes->first();
        $this->assertSame($contenido, (string) $repuesto->content, 'El texto repuesto no es el que recibió el lead.');
        $this->assertSame('enviado', (string) $repuesto->status);
        $this->assertSame($espia->wamid(3), (string) $repuesto->whatsapp_message_id, 'El id guardado no es el de la última parte enviada.');
        $this->assertNotNull($repuesto->sent_at);
        $this->assertSame((int) $admin->id, (int) $repuesto->sent_by_admin_id);

        // Las tres partes realmente salieron (espia->partes lo confirmó arriba): el estado final
        // repuesto tiene que contabilizarlas todas, no sólo la que sobrevivió hasta el cierre.
        $this->assertSame(3, (int) $repuesto->sent_parts_count, 'No quedaron registradas las tres partes que realmente salieron.');
        $this->assertSame(3, (int) $repuesto->total_parts_count);
        $this->assertNull($repuesto->partial_send_pending, 'Salieron las tres partes y quedó marcado como pendiente de todos modos.');

        $this->assertSame('interesado', (string) $lead->fresh()->status, 'El estado sugerido no se aplicó cuando el mensaje se repuso en pleno envío por partes.');
    }
}
