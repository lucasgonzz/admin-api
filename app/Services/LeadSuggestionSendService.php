<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Models\ProtocolEntry;
use Illuminate\Support\Facades\Log;

/**
 * Envía por WhatsApp un mensaje sugerido por Claude tras aprobación del setter en admin-spa.
 *
 * FIX (prompt 366, lead #440, 22/7/2026): una sugerencia partida en varios mensajes tiene tres
 * desenlaces posibles, no dos. Antes de este cambio solo existían "nada enviado" (rechazado) y
 * "todo enviado" (enviado); una sugerencia de 4 partes que llegó hasta la 3ra y falló la 4ta con
 * un 409 de Kapso ("otro mensaje en vuelo para esta conversación") se registraba igual como
 * "rechazado" — el hilo mostraba el bloque rojo "No se pudo enviar" pese a que el lead sí había
 * recibido y respondido al contenido. Ahora existe el caso intermedio de **envío parcial**
 * (0 < partes enviadas < partes totales): se registra `status = 'enviado'` con
 * `sent_parts_count`/`total_parts_count`/`partial_send_pending`, se aplica igual el pipeline
 * sugerido y se deja constancia clara en el hilo de cuántos mensajes llegaron. Además,
 * `enviar_partes()` espacia el envío de cada parte y reintenta con backoff ante fallos
 * transitorios (409/429/5xx), para que el 409 de Kapso deje de ser la causa habitual del caso C.
 *
 * `enviar_partes()` es público y con firma genérica porque el mensaje directo que el operador
 * escribe en el panel del lead (LeadController@send_direct_message_json) manda por ahí también:
 * duplicar las pausas y los reintentos en otro archivo garantizaba que los dos caminos se
 * separaran con el tiempo y que el arreglo del #440 valiera solo para uno de los dos.
 */
class LeadSuggestionSendService
{
    /**
     * @var WhatsappSendService
     */
    private $whatsapp_send_service;

    /**
     * @param WhatsappSendService|null $whatsapp_send_service
     */
    public function __construct(?WhatsappSendService $whatsapp_send_service = null)
    {
        $this->whatsapp_send_service = $whatsapp_send_service ?? new WhatsappSendService();
    }

    /**
     * Envía el texto al lead por WhatsApp y marca el mensaje como enviado.
     *
     * Si el cuerpo contiene el separador "\n---\n", se parte en múltiples mensajes
     * y se envían secuencialmente. El whatsapp_message_id que se persiste corresponde
     * al último envío.
     *
     * @param LeadMessage $message       Mensaje en estado `sugerido`.
     * @param string|null $edited_content Texto final; si es null se usa content del mensaje.
     * @param array|null  $final_actions  Paquete de acciones editado por el admin (prompt 320, ver
     *                                    contrato `final_actions` en LeadAiService::apply_pending_actions()).
     *                                    Si es null se aplican las acciones originales de Claude
     *                                    (comportamiento sin cambios).
     * @param bool        $is_auto_send   FIX (prompt 337): true cuando llama AutoSendLeadAiSuggestionJob
     *                                    (respaldo automático, sin revisión humana), false (default) cuando
     *                                    llama un endpoint de aprobación humana. Con pending_actions y
     *                                    true, nunca se ejecutan acciones con efecto externo
     *                                    (agendar_demo/cancelar_demo/Mail 1) a ciegas — ver Caso A/B en
     *                                    el cuerpo del método.
     * @param int|null    $sent_by_admin_id (prompt 403) Admin que aprobó la sugerencia desde el panel
     *                                    (Auth::id() del endpoint approve_*). Null cuando el auto-envío
     *                                    de respaldo la manda sin revisión humana.
     *
     * @return LeadMessage
     */
    public function send_suggestion(LeadMessage $message, ?string $edited_content = null, ?array $final_actions = null, bool $is_auto_send = false, ?int $sent_by_admin_id = null): LeadMessage
    {
        if ((string) $message->sender !== 'sistema') {
            throw new \InvalidArgumentException('Solo se pueden enviar sugerencias del sistema.');
        }

        if ((string) $message->status !== 'sugerido') {
            throw new \InvalidArgumentException('Solo se pueden enviar mensajes en estado sugerido.');
        }

        $lead = $message->lead;
        if ($lead === null) {
            $lead = Lead::query()->find($message->lead_id);
        }

        if ($lead === null) {
            throw new \RuntimeException('Lead no encontrado para el mensaje.');
        }

        $message_id = (int) $message->id;
        $en_curso   = new LeadSuggestionEnvioEnCurso();

        /*
         * 🔴 El marcador se toma ACÁ y no más abajo, pegado al send_text(). Todo lo que viene
         * después —revalidar los horarios ofrecidos contra Google, apply_pending_actions() agendando
         * una demo, y recién al final enviar_partes() con sus pausas de 1200ms y su backoff de hasta
         * 3500ms— corre con la fila de este mensaje viva y visible para cualquier otro request. El
         * webhook de un inbound del lead (WhatsappWebhookController) entra a
         * LeadAiSuggestionScheduler::clear_stale_pending_suggestions(), que hace un DELETE real
         * (LeadMessage NO usa SoftDeletes) de todo lo que esté en 'sugerido'. La ventana peligrosa
         * son SEGUNDOS, no milisegundos: el contestador automático de un lead contesta al toque.
         *
         * Las dos validaciones de entrada quedan ARRIBA del marcador a propósito: si el mensaje no
         * es del sistema o no está sugerido no hay ningún envío que proteger, y tomar el marcador
         * ahí sería marcar algo que este camino nunca va a liberar.
         */
        $token = $en_curso->marcar($message_id);

        /*
         * 🔴 El lease se RENUEVA con cada parte que sale, y la renovación llega hasta el bucle de
         * partes como callback en vez de estar cableada adentro de enviar_partes(). Los dos motivos:
         *
         *   1. enviar_partes() es pública y compartida con el mensaje directo que un operador
         *      escribe en el panel (LeadController@send_direct_message_json), que no tiene ningún
         *      lease que sostener. Atarla al marcador la obligaría a conocer algo que a ese otro
         *      llamador no le importa, que es justamente lo que se evitó cuando se sacó afuera.
         *   2. Un TTL fijo está mal en las dos direcciones (ver la cuenta en la constante de
         *      LeadSuggestionEnvioEnCurso): corto para un envío de 6 partes con reintentos, y largo
         *      para un proceso HTTP que muere a los 120s por max_execution_time sin que ningún
         *      `catch` corra. Renovando, el lease dura lo que dura el envío y ni un segundo más.
         */
        $renovar_lease = function () use ($en_curso, $message_id, $token) {
            try {
                $en_curso->renovar($message_id, $token);
            } catch (\Throwable $e) {
                /* Una renovación que falla no puede cortar un envío que YA está entregando partes al
                 * lead: lo peor que pasa si el lease se cae es volver al riesgo de la carrera para lo
                 * que quede del mensaje, y eso es infinitamente menos malo que abortar a mitad. */
                Log::channel('daily')->warning('LeadSuggestionSendService: no se pudo renovar el lease del envío en curso.', [
                    'message_id' => $message_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        };

        try {
            return $this->enviar_sugerencia_aprobada($message, $lead, $edited_content, $final_actions, $is_auto_send, $sent_by_admin_id, $renovar_lease);
        } finally {
            /*
             * 🔴 finally, y no una liberación al final del cuerpo: TODAS las salidas tienen que
             * soltar el lease, incluidas las que no envían nada (ventana de 24hs cerrada,
             * handle_auto_send_agendamiento_gate(), la regeneración por horario caducado) y las que
             * salen por excepción (HorarioYaNoDisponibleException de apply_pending_actions()). Un
             * lease que no se suelta es una sugerencia que el barrido no limpia hasta que venza.
             */
            try {
                $en_curso->liberar($message_id, $token);
            } catch (\Throwable $e) {
                /*
                 * 🔴 Mismo criterio que atender_inbound_diferido() acá abajo, y por la misma razón:
                 * esto corre adentro de un `finally`, y una excepción acá REEMPLAZA a la que venía
                 * subiendo —la HorarioYaNoDisponibleException de apply_pending_actions(), por
                 * ejemplo—, con lo cual el llamador termina diagnosticando el síntoma equivocado.
                 * Con el driver `file` es improbable; con uno de red (redis, memcached) es un timeout
                 * cualquiera. Y si la liberación falla no queda nada colgado para siempre: el lease
                 * vence solo, que es exactamente la red que tiene.
                 */
                Log::channel('daily')->error('LeadSuggestionSendService: no se pudo liberar el lease del envío en curso.', [
                    'message_id' => $message_id,
                    'error'      => $e->getMessage(),
                ]);
            }

            $this->atender_inbound_diferido($en_curso, $lead, $message_id);
        }
    }

    /**
     * Cuerpo real del envío de una sugerencia aprobada, ya con el marcador de "envío en curso"
     * tomado por send_suggestion().
     *
     * Está separado sólo para que el marcador se libere en un `finally` que envuelva todas las
     * salidas de este método —incluidas las cuatro que devuelven sin enviar nada y las que salen por
     * excepción—, sin tener que sembrar la liberación en cada `return`. La firma pública del
     * servicio no cambió: quien llama sigue viendo send_suggestion().
     *
     * @param LeadMessage   $message          Mensaje en estado `sugerido`, ya validado.
     * @param Lead          $lead             Lead dueño del mensaje, ya resuelto.
     * @param string|null   $edited_content   Ver send_suggestion().
     * @param array|null    $final_actions    Ver send_suggestion().
     * @param bool          $is_auto_send     Ver send_suggestion().
     * @param int|null      $sent_by_admin_id Ver send_suggestion().
     * @param callable|null $al_enviar_parte  Se invoca después de cada parte que sale con éxito.
     *                                        Acá lo único que hace es renovar el lease del marcador
     *                                        de envío en curso; viaja como parámetro para no atar
     *                                        enviar_partes() al marcador (ver send_suggestion()).
     *
     * @return LeadMessage
     */
    private function enviar_sugerencia_aprobada(LeadMessage $message, Lead $lead, ?string $edited_content, ?array $final_actions, bool $is_auto_send, ?int $sent_by_admin_id, ?callable $al_enviar_parte = null): LeadMessage
    {
        /*
         * Revalidación de horarios ofrecidos (grupo 306, prompt 04). La disponibilidad se calculó
         * al GENERAR la sugerencia, pero el mensaje se envía recién después de la aprobación humana
         * — minutos u horas más tarde. Antes de este control, solo se revalidaba el horario que el
         * lead ELIGIÓ (agendar_demo, más abajo en este mismo método); los horarios que el MENSAJE
         * ofrece nunca se revalidaban, así que una sugerencia aprobada tarde podía ofrecer horarios
         * ya tomados o ya pasados — exactamente lo que un humano revisando cada mensaje corrige a
         * mano hoy. Va ANTES de aplicar pending_actions y de mandar por WhatsApp. Solo aplica a la
         * dinámica nueva y solo si el mensaje declaró horarios (array no vacío).
         *
         * No reemplaza la revalidación de agendar_demo (apply_pending_actions(), más abajo): una
         * protege el horario que el lead eligió contra colisión con otro lead; esta protege los
         * horarios que el mensaje ofrece contra el paso del tiempo. Son controles de cosas distintas.
         */
        if ($lead->usa_experiencia_demo_nueva() && ! empty($message->horarios_ofrecidos)) {
            $minutos_transcurridos = $message->created_at !== null
                ? $message->created_at->diffInMinutes(AppTime::now())
                : null;

            try {
                $caducados = app(\App\Services\LeadAiService::class)->revalidar_horarios_ofrecidos($lead, $message->horarios_ofrecidos);
            } catch (\Throwable $e) {
                /* Fail-safe OBLIGATORIO: este control existe para atrapar horarios vencidos, no para
                 * dejar a un lead sin respuesta por un error de infraestructura ajeno (Google caído,
                 * base lenta). Misma degradación segura que ya usa el resto del flujo de
                 * disponibilidad: se envía igual y se deja constancia en el log. */
                Log::channel('disponibilidad')->warning('[DISPONIBILIDAD] Fallo al revalidar horarios_ofrecidos antes de enviar; se envía igual.', [
                    'lead_id'    => $lead->id,
                    'message_id' => $message->id,
                    'error'      => $e->getMessage(),
                ]);
                $caducados = [];
            }

            if (! empty($caducados)) {
                Log::channel('disponibilidad')->warning('[DISPONIBILIDAD] Horario ofrecido caducó antes del envío.', [
                    'lead_id'               => $lead->id,
                    'message_id'            => $message->id,
                    'horarios_declarados'   => $message->horarios_ofrecidos,
                    'horarios_caducados'    => $caducados,
                    'minutos_transcurridos' => $minutos_transcurridos,
                ]);

                /* No se inventa un estado nuevo: se reutiliza 'rechazado' (ya significa "esto no
                 * salió") + requiere_verificacion, igual que el resto del flujo. El registro queda
                 * en el hilo (no se borra) para que se pueda auditar qué se ofreció y por qué caducó. */
                $message->status                = 'rechazado';
                $message->requiere_verificacion = true;
                $message->save();

                $lead->requiere_intervencion_humana = true;
                $lead->save();

                (new LeadConversationErrorLogger())->log(
                    (int) $lead->id,
                    'Horario ofrecido dejó de estar disponible',
                    /* 🔴 Descriptivo, sin causa inventada: acá arriba sólo se sabe que la revalidación
                     * lo marcó caducado, no SI se ocupó o SI pasó — decir "se ocupó" es afirmar un
                     * hecho que el sistema no verificó, la misma clase de error que arregla la misión
                     * del reagendado (25/8/2026, lead Brisa). Y este texto no se queda en el panel:
                     * queda en el hilo, y hasta hoy entraba al historial que lee el agente. */
                    'El horario que este mensaje ofrecía dejó de estar disponible mientras esperaba aprobación. Se regeneró la sugerencia con disponibilidad fresca.'
                );

                $lead->sync_suggestion_flags();
                LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

                Log::channel('daily')->info('LeadSuggestionSendService: sugerencia regenerada por horario ofrecido caducado (grupo 306, prompt 04).', [
                    'lead_id'    => $lead->id,
                    'message_id' => $message->id,
                ]);

                /* El mensaje que reemplaza a una oferta caducada es otro mensaje de oferta: si se
                 * regenera por la primera llamada, el modelo no tiene ni JSON ni oferta primaria y
                 * contesta un ack sin horario ni link (lead 30, 4/8/2026: "Dale, Brisa... ahora
                 * mismo te lo preparo", sin agendar nada). La regeneración entra por el mismo
                 * camino que produjo el mensaje original (grupo 330, prompt 02) -- ver
                 * LeadAiService::regenerar_sugerencia_por_horario_caducado(), que además trae su
                 * propio fail-safe si la disponibilidad falla. */
                return app(\App\Services\LeadAiService::class)->regenerar_sugerencia_por_horario_caducado($lead->fresh(), (bool) $message->is_followup);
            }
        }

        /*
         * Seguimiento por plantilla pendiente de aprobación (tramo de agenda, prompt 283). Se reenvía
         * SIEMPRE por su plantilla Meta guardada (send_template), no por send_text: los seguimientos
         * disparan justamente cuando el lead quedó en silencio, así que la ventana de 24hs suele estar
         * cerrada y send_text daría 422. La plantilla no admite texto editado, así que $edited_content
         * se ignora en este camino (el setter aprueba o rechaza; para escribir algo propio, rechaza y
         * responde manualmente).
         */
        /*
         * Del arreglo de "la sugerencia borrada en pleno envío", este camino hereda UNA mitad y
         * necesita la otra por su cuenta:
         *
         *   - El LEASE sí lo hereda: se toma en send_suggestion() ANTES de bifurcar acá, así que el
         *     barrido ve el mensaje como "en curso" tanto si sale por texto como si sale por
         *     plantilla. Eso no se duplica abajo.
         *   - La REPOSICIÓN no se hereda: la escribe cada salida que dejó un efecto externo, y la
         *     salida de éxito de send_followup_suggestion_via_template() dejó uno igual de
         *     irreversible (la plantilla ya salió por WhatsApp). Ahí abajo tiene su propia detección
         *     de 0 filas, reusando recrear_mensaje_enviado().
         *
         * Hoy, además, clear_stale_pending_suggestions() filtra `is_followup = false`, así que un
         * seguimiento ni siquiera es borrable por ese barrido — pero eso es un detalle del OTRO
         * archivo (y hay otros caminos que borran, como discard_obsolete_suggestion()), y el día que
         * ese filtro cambie este camino no se entera.
         */
        if ($message->is_followup && ! empty($message->followup_template_id)) {
            return $this->send_followup_suggestion_via_template($message, $lead, $sent_by_admin_id);
        }

        /*
         * FIX (prompt 337): resguardo del respaldo automático. Antes de aplicar cualquier acción
         * pendiente, si esto es un auto-envío (job, sin revisión humana) y el paquete trae
         * agendar_demo o cancelar_demo, se corta acá (Caso A): el texto de Claude confirma un
         * horario al lead y no hay forma segura de mandarlo sin persistir la reserva. El mensaje
         * queda `sugerido` sin tocar, a la espera de que un humano lo apruebe desde el panel.
         */
        if ($is_auto_send && ! empty($message->pending_actions)) {
            $pending_actions = $message->pending_actions;
            if (! empty($pending_actions['agendar_demo']) || ! empty($pending_actions['cancelar_demo'])) {
                return $this->handle_auto_send_agendamiento_gate($message, $lead);
            }
        }

        /*
         * Mensajes que quedaron pendientes por el motivo "agendamiento" (ver
         * LeadAiService::requires_agendamiento_verification_gate) no aplicaron todavía ninguna
         * acción (agendar_demo, guardar_nombre, mail, etc.) — se aplican recién acá, al aprobar,
         * revalidando disponibilidad en este momento y no la de cuando Claude respondió. Si la
         * validación falla (ej. el horario ya se ocupó mientras esperaba aprobación), no se envía
         * nada al lead: el error se propaga para que LeadController devuelva 422 y el admin pida
         * una sugerencia nueva.
         *
         * ⚠️ Este párrafo describía el contrato, pero hasta el 25/8/2026 el código NO lo cumplía:
         * cuando el horario no validaba, apply_parsed_response() reescribía el mensaje in-place con
         * un correctivo ("ese horario se ocupó") y lo enviaba igual — con el sent_by_admin_id de
         * esta aprobación, o sea firmado por un admin que nunca leyó ese texto. Desde ese día el
         * camino de aprobación tira HorarioYaNoDisponibleException y no envía nada
         * (LeadAiService::frenar_por_horario_no_disponible), que es lo que este comentario ya decía.
         */
        if (! empty($message->pending_actions)) {
            if ($is_auto_send) {
                /*
                 * FIX (prompt 337): Caso B del respaldo automático. Llegar hasta acá ya significa
                 * que el paquete NO trae agendar_demo ni cancelar_demo (se filtró arriba), así que
                 * es seguro auto-enviar el texto — pero solo aplicando acciones sin efecto externo.
                 * Se arma un `final_actions` mínimo que desactiva explícitamente agendar_demo y
                 * cancelar_demo (por si vinieran igual en el paquete crudo) y fuerza
                 * enviar_mail_demo=false (el Mail 1 de acceso a la demo nunca sale sin aprobación
                 * humana, aunque guardar_email haya guardado un email nuevo). El resto de las
                 * acciones (guardar_nombre, guardar_email, estado_sugerido,
                 * requiere_intervencion_humana/motivo_intervencion) no se tocan acá: al no venir en
                 * este array, apply_pending_actions() conserva el valor original de Claude.
                 */
                $final_actions = [
                    'agendar_demo'     => null,
                    'cancelar_demo'    => false,
                    'enviar_mail_demo' => false,
                ];
            }

            $message = app(\App\Services\LeadAiService::class)->apply_pending_actions($message, $final_actions);

            if ($is_auto_send) {
                // El mensaje salió por WhatsApp sin que nadie lo revisara: Lucas quiere verlo en
                // la fila roja de la grilla (mismo mecanismo que el Caso A, columna del prompt 301).
                $this->mark_lead_pending_review($lead);
            }
        }

        $body = $edited_content !== null ? trim($edited_content) : trim((string) $message->content);
        if ($body === '') {
            throw new \InvalidArgumentException('El mensaje a enviar no puede estar vacío.');
        }

        $phone = trim((string) $lead->phone);

        // Si la ventana de conversación de WhatsApp está cerrada (sin mensaje entrante en 24hs),
        // no intentar send_text (Meta devuelve 422). Marcar como rechazado y salir.
        if ($phone !== '' && ! $this->is_within_whatsapp_window($lead)) {
            Log::channel('daily')->warning('LeadSuggestionSendService: ventana de 24hs cerrada, sugerencia no enviada.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
            ]);

            (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

            $message->update([
                'status'              => 'rechazado',
                'sent_at'             => null,
                // Motivo conocido en este call site, no viene de WhatsappSendService (prompt 336).
                'whatsapp_send_error' => 'Ventana de 24hs de WhatsApp cerrada (el lead no escribió en las últimas 24hs).',
            ]);

            $lead->sync_suggestion_flags();

            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

            // Deja asentado en el hilo que la sugerencia no se pudo enviar (prompt 299), para
            // que quede visible tanto en aprobación manual como en auto-envío de respaldo.
            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'No se pudo enviar la sugerencia por WhatsApp',
                'La ventana de 24hs de WhatsApp está cerrada (el lead no escribió en las últimas 24hs).'
            );

            return $this->fresh_o_en_memoria($message);
        }

        // Resultado real del envío por partes (prompt 366): a diferencia del string|null anterior,
        // ahora send_body() informa cuántas partes salieron y cuántas había en total, lo que
        // habilita el caso C (envío parcial) más abajo.
        $send_result = null;
        $send_failed = false;
        // Motivo real del fallo (prompt 336): se completa recién si send_failed queda en true.
        $error_detail = null;

        if ($phone !== '') {
            $send_result = $this->send_body($phone, $body, $lead, $message, $al_enviar_parte);

            if ($send_result['sent_parts'] === 0) {
                $send_failed = true;
                // El motivo real quedó capturado en la instancia de WhatsappSendService al fallar send_text().
                $error_detail = $send_result['error'];
                Log::channel('daily')->warning('LeadSuggestionSendService: send_body() no envió ninguna parte.', [
                    'lead_id'    => $lead->id,
                    'message_id' => $message->id,
                ]);
            }
        } else {
            $send_failed = true;
            $error_detail = 'El lead no tiene teléfono cargado.';
            Log::channel('daily')->warning('LeadSuggestionSendService: lead sin teléfono.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
            ]);
        }

        /*
         * Caso A (prompt 366): no salió ninguna parte (o el lead no tiene teléfono). Comportamiento
         * idéntico al de antes de este fix: status='rechazado', sin tocar el pipeline del lead. La
         * notificación a admins ante el fallo de envío en sí ya la maneja WhatsappSendService de
         * forma centralizada (no se duplica acá).
         */
        if ($send_failed) {
            (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

            $message->update([
                'status'              => 'rechazado',
                'sent_at'             => null,
                'whatsapp_send_error' => $error_detail,
            ]);

            $lead->sync_suggestion_flags();

            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

            // Deja asentado en el hilo que el envío no se confirmó (prompt 299), con el motivo real
            // capturado (prompt 336) o el texto genérico como fallback.
            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'No se pudo enviar la sugerencia por WhatsApp',
                $error_detail ?: 'El envío no se confirmó (lead sin teléfono o error de conexión con WhatsApp/Kapso).'
            );

            return $this->fresh_o_en_memoria($message);
        }

        // Caso C (prompt 366, lead #440): salieron algunas partes pero no todas. Ni "rechazado"
        // (el lead sí recibió contenido real) ni "enviado" a secas (faltan partes por mandar).
        $is_partial_send = $send_result['sent_parts'] < $send_result['total_parts'];

        $original_content = (string) $message->content;
        $update_payload = [
            'status'              => 'enviado',
            'sent_at'             => now(),
            'whatsapp_message_id' => $send_result['last_message_id'],
            // Admin que aprobó esta sugerencia desde el panel (null si fue auto-envío de la IA, prompt 403).
            'sent_by_admin_id'    => $sent_by_admin_id,
            // Contabilidad del envío por partes (prompt 366): null/null/null en el caso completo (B).
            'sent_parts_count'    => $send_result['sent_parts'],
            'total_parts_count'   => $send_result['total_parts'],
            'partial_send_pending' => $is_partial_send ? $send_result['pending_text'] : null,
        ];

        if ($is_partial_send) {
            // Motivo legible del corte, mismo formato que usa LeadConversationErrorLogger más abajo.
            $update_payload['whatsapp_send_error'] = sprintf(
                'Envío parcial: salieron los primeros %d de %d mensajes. El resto no se envió: %s.',
                $send_result['sent_parts'],
                $send_result['total_parts'],
                $send_result['error'] ?: 'motivo no determinado'
            );
        }

        if ($edited_content !== null && trim($edited_content) !== '' && trim($edited_content) !== $original_content) {
            $update_payload['edited_content'] = trim($edited_content);
            $this->record_setter_correction($lead, $original_content, trim($edited_content));
        }

        (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

        /*
         * 🔴 NO volver a `$message->update($update_payload)`, por más que sea lo que hace el resto
         * del archivo. Model::performUpdate() (vendor/laravel/framework/src/Illuminate/Database/
         * Eloquent/Model.php) tira a la basura el número de filas que devuelve el query builder y
         * termina con un `return true` fijo: si otro request borró esta fila mientras el mensaje
         * salía por WhatsApp, el UPDATE toca 0 filas, nadie se entera, y el `fresh()` del final
         * devuelve null contra una firma que promete LeadMessage.
         *
         * Y a esta altura el efecto externo YA OCURRIÓ —el lead tiene el mensaje en el teléfono— y
         * no se puede deshacer: el sistema tiene que enterarse sí o sí.
         */
        $filas_afectadas = LeadMessage::query()->whereKey($message->getKey())->update($update_payload);

        /*
         * 🔴 El exists() no sobra aunque $filas_afectadas ya sea 0. La conexión mysql de este
         * proyecto NO define PDO::MYSQL_ATTR_FOUND_ROWS (config/database.php: 'options' sólo trae
         * MYSQL_ATTR_SSL_CA), y sin esa opción rowCount() cuenta filas CAMBIADAS, no encontradas: un
         * UPDATE que escribe los mismos valores devuelve 0 con la fila perfectamente presente.
         * Recrear mirando sólo el contador duplicaría el mensaje en el hilo. La pregunta que decide
         * la recreación es "¿la fila está?", y se contesta preguntándola.
         */
        $fila_desaparecida = $filas_afectadas === 0
            && ! LeadMessage::query()->whereKey($message->getKey())->exists();

        if ($fila_desaparecida) {
            $message = $this->recrear_mensaje_enviado($lead, $message, $update_payload, $body, $send_result);
        } else {
            /* El UPDATE salió por query builder, así que el objeto en memoria quedó con los valores
             * viejos. Se sincroniza a mano porque apply_suggested_pipeline_status() y el broadcast de
             * acá abajo leen de ESTE objeto, no de la base. */
            $message->forceFill($update_payload);
            $message->syncOriginal();
        }

        // El estado sugerido se aplica tanto en el envío completo (B) como en el parcial (C): en
        // ambos casos el lead recibió contenido real y avanzó la conversación. Solo el caso A (nada
        // enviado) deja el pipeline intacto.
        $this->apply_suggested_pipeline_status($lead, $message);

        if ($is_partial_send) {
            // Un envío parcial siempre necesita ojo humano: marca el lead en la fila destacada de
            // la grilla (mismo mecanismo que ya usa el respaldo automático sin revisión, prompt 337).
            $this->mark_lead_pending_review($lead);

            // Deja constancia en el hilo de qué llegó y qué no, en tono claro y no alarmista (no es
            // un bloque rojo de "falló todo": el lead sí recibió contenido).
            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'Envío parcial de la sugerencia',
                sprintf(
                    'El lead recibió los primeros %d de %d mensajes. Los %d en adelante no se enviaron y quedaron pendientes para mandar a mano. Motivo: %s.',
                    $send_result['sent_parts'],
                    $send_result['total_parts'],
                    $send_result['sent_parts'] + 1,
                    $send_result['error'] ?: 'motivo no determinado'
                )
            );
        }

        $lead->sync_suggestion_flags();

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return $this->fresh_o_en_memoria($message);
    }

    /**
     * Reprograma la sugerencia siguiente si el lead escribió mientras este mensaje se enviaba.
     *
     * Cierra la consecuencia directa de proteger la sugerencia en vuelo: como el barrido ya no la
     * borra, GenerateLeadAiSuggestionJob la ve todavía en 'sugerido'
     * (LeadConversationAiState::has_pending_non_followup_suggestion) y se omite — el mensaje que el
     * lead acaba de escribir se quedaría sin respuesta hasta que volviera a escribir. La marca la
     * dejó clear_stale_pending_suggestions() al saltear este id; acá se consume y se vuelve a
     * programar, ahora que el mensaje ya pasó a 'enviado' y el guard del job no se dispara.
     *
     * Se llama DESPUÉS de liberar el marcador, no antes: si corriera con el marcador todavía puesto,
     * el barrido que dispara la reprogramación volvería a saltear este mismo id y a anotar otra
     * marca, y ahí sí habría un ciclo.
     *
     * @param LeadSuggestionEnvioEnCurso $en_curso
     * @param Lead                       $lead
     * @param int                        $message_id
     *
     * @return void
     */
    private function atender_inbound_diferido(LeadSuggestionEnvioEnCurso $en_curso, Lead $lead, int $message_id): void
    {
        try {
            if (! $en_curso->consumir_inbound_durante_el_envio($message_id)) {
                return;
            }

            (new LeadAiSuggestionScheduler())->schedule_after_lead_inbound((int) $lead->id);
        } catch (\Throwable $e) {
            /*
             * 🔴 Este try/catch NO es decorativo y no se puede sacar "porque schedule_after_lead_inbound()
             * no tira": esto corre adentro de un `finally`, y una excepción acá REEMPLAZA a la que
             * venía subiendo —la HorarioYaNoDisponibleException de apply_pending_actions(), por
             * ejemplo—, con lo cual el llamador termina diagnosticando el síntoma equivocado y el
             * setter ve un error que no tiene nada que ver con lo que pasó. Nunca tapar: loguear y
             * seguir, que la excepción original siga su camino.
             */
            Log::channel('daily')->error('LeadSuggestionSendService: no se pudo reprogramar la sugerencia tras el envío.', [
                'lead_id'    => $lead->id,
                'message_id' => $message_id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Vuelve a crear en el hilo el mensaje que ya salió por WhatsApp, cuando otro request borró su
     * fila mientras el envío estaba en curso.
     *
     * Es la red de seguridad del caso que da nombre a esta misión: el efecto externo ya ocurrió (el
     * lead tiene el mensaje en el teléfono) y no se puede deshacer, así que la única salida honesta
     * es reponer en la conversación el estado final que la fila habría tenido si nadie la hubiera
     * borrado. El hilo queda igual que en el camino normal —mismo texto, mismo badge de "editado",
     * mismo badge de envío parcial— y el setter no manda el mensaje dos veces.
     *
     * @param Lead        $lead
     * @param LeadMessage $message        Modelo en memoria de la fila borrada (fuente de los campos
     *                                    que no dependen del envío).
     * @param array       $update_payload Payload que el UPDATE no pudo aplicar (estado final).
     * @param string      $body           Texto (o plantilla) que efectivamente salió por WhatsApp,
     *                                    para el log técnico.
     * @param array|null  $send_result    Resultado de enviar_partes(), para el log técnico. Null en
     *                                    el camino de seguimiento por plantilla, que sale de una y
     *                                    no tiene reparto en partes que informar.
     *
     * @return LeadMessage Mensaje nuevo, ya en 'enviado'.
     */
    private function recrear_mensaje_enviado(Lead $lead, LeadMessage $message, array $update_payload, string $body, ?array $send_result = null): LeadMessage
    {
        /*
         * 🔴 Campos enumerados a mano, y NO $message->getAttributes(). getAttributes() devuelve los
         * valores CRUDOS de la base: pending_actions, horarios_ofrecidos, applied_actions_summary,
         * actions_override_log y admin_notifications volverían como el JSON en string, y al pasarlos
         * de nuevo por los casts `array` de LeadMessage se codificarían dos veces ("[{\"fecha\"...")
         * — el hilo mostraría un mensaje que se ve bien y un panel de acciones roto, que es peor que
         * un error visible. Leyendo por propiedad ($message->pending_actions) los casts ya
         * devolvieron el array y create() lo vuelve a codificar una sola vez.
         *
         * ⚠️ `calendar_snapshot` es la excepción y por eso va igual pero por otro motivo: es una
         * columna `text` SIN cast (lo escribe json_encode() a mano en LeadAiService), así que el
         * valor de la propiedad ya es el string JSON final y se copia tal cual. Si algún día se le
         * pusiera cast `array`, esta línea sigue siendo correcta; lo que nunca hay que hacer es
         * mezclarla con un json_encode() propio acá.
         *
         * 🔴 La lista tiene que cubrir TODO lo que el hilo renderiza, no sólo el texto: el docblock
         * promete que la fila repuesta se ve igual que la original, y MessageBubble.vue de admin-spa
         * pinta también los badges de demo confirmada (marca_demo_ingreso_confirmado,
         * marca_demo_terminada_confirmada), los avisos de admins notificados (admin_notifications) y
         * el desplegable de horarios enviados (calendar_snapshot). Un campo que falte acá no rompe
         * nada visible: simplemente desaparece del hilo, en silencio.
         */
        $atributos = [
            'lead_id'                         => $message->lead_id,
            'sender'                          => 'sistema',
            'content'                         => $message->content,
            /* Sin ternario contra $update_payload: el array_merge() de abajo lo pisa igual cuando el
               setter editó el texto, así que resolverlo acá arriba era una decisión escrita dos veces. */
            'edited_content'                  => $message->edited_content,
            'ai_reasoning'                    => $message->ai_reasoning,
            'suggested_lead_status'           => $message->suggested_lead_status,
            'pending_actions'                 => $message->pending_actions,
            'horarios_ofrecidos'              => $message->horarios_ofrecidos,
            'applied_actions_summary'         => $message->applied_actions_summary,
            'actions_override_log'            => $message->actions_override_log,
            'admin_notifications'             => $message->admin_notifications,
            'calendar_snapshot'               => $message->calendar_snapshot,
            'followup_template_id'            => $message->followup_template_id,
            'is_followup'                     => (bool) $message->is_followup,
            'requiere_verificacion'           => (bool) $message->requiere_verificacion,
            'marca_demo_ingreso_confirmado'   => (bool) $message->marca_demo_ingreso_confirmado,
            'marca_demo_terminada_confirmada' => (bool) $message->marca_demo_terminada_confirmada,
            'sent_via'                        => $message->sent_via,
            /* La fila nueva NO es un evento de estado ni un error: es el mensaje real que el lead
               recibió, y tiene que renderizarse como cualquier otro mensaje del hilo. */
            'is_status_event'                 => false,
            'is_error'                        => false,
        ];

        /* El estado final del envío (status, sent_at, whatsapp_message_id, sent_by_admin_id, los
           contadores de partes y partial_send_pending) sale del mismo payload que el UPDATE no pudo
           aplicar: así la fila recreada y la que habría quedado son la misma cosa, y no hay dos
           lugares que definan "cómo queda un mensaje enviado". */
        $nuevo = LeadMessage::create(array_merge($atributos, $update_payload));

        Log::channel('daily')->error('LeadSuggestionSendService: la sugerencia se borró mientras se enviaba; el mensaje se recreó en el hilo.', [
            'lead_id'             => $lead->id,
            'message_id_borrado'  => $message->getKey(),
            'message_id_nuevo'    => $nuevo->id,
            /* Sale del payload y no de $send_result: así el dato es el mismo que quedó escrito en la
               fila, y sirve igual para el camino de plantilla, que no tiene $send_result. */
            'whatsapp_message_id' => isset($update_payload['whatsapp_message_id']) ? $update_payload['whatsapp_message_id'] : null,
            'partes_enviadas'     => $send_result !== null ? $send_result['sent_parts'] : null,
            'partes_totales'      => $send_result !== null ? $send_result['total_parts'] : null,
            'texto_enviado'       => $body,
        ]);

        /*
         * Constancia en el hilo por el mismo mecanismo que ya usa "Envío parcial de la sugerencia":
         * pasó algo anómalo pero no catastrófico, y el setter tiene que entender qué ve sin leer un
         * log. El detalle técnico va al Log, no acá.
         *
         * ⚠️ Este texto NO puede empezar con la frase "Horario ofrecido dejó de estar disponible":
         * admin-spa (MessageBubble.vue) reconoce ese aviso matcheando el PREFIJO del content con
         * indexOf(...) === 0, y cualquier otro bloque que arranque igual se renderizaría como si
         * fuera aquel.
         */
        (new LeadConversationErrorLogger())->log(
            (int) $lead->id,
            'El mensaje salió, pero la sugerencia se había borrado',
            'El lead escribió justo mientras se enviaba y el sistema descartó la sugerencia antes de que terminara. El texto ya había salido por WhatsApp, así que se volvió a dejar en la conversación tal como lo recibió el lead. No hay que mandarlo de nuevo.'
        );

        // Mismo criterio que el envío parcial: pasó algo anómalo, lo mira un humano.
        $this->mark_lead_pending_review($lead);

        return $nuevo;
    }

    /**
     * Devuelve el mensaje recargado de la base, o el que tenemos en memoria si la fila ya no está.
     *
     * 🔴 fresh() devuelve null cuando la fila desapareció, y los tres métodos de este archivo que lo
     * usaban prometen `: LeadMessage`: ese null se convierte en TypeError, el controller lo agarra
     * con catch(\Throwable) y termina escribiendo "No se pudo enviar la sugerencia por WhatsApp"
     * arriba de un envío que salió perfecto.
     *
     * Cuando la fila ya no está, lo más verdadero que se puede devolver es el objeto en memoria:
     * refleja lo que ESTE request hizo, que es exactamente lo que el llamador necesita saber.
     *
     * Devolver el modelo en memoria NO es tapar el problema, y la razón es que este método NUNCA es
     * la respuesta a un efecto externo perdido. Las DOS salidas de este archivo que dejan algo
     * irreversible del otro lado —el éxito del envío por texto y el éxito del seguimiento por
     * plantilla— reponen la fila antes de llegar acá (ver recrear_mensaje_enviado()), con constancia
     * en el hilo y en el log. Las otras cinco (ventana de 24hs cerrada, fallo real de envío,
     * seguimiento sin plantilla o sin teléfono, seguimiento que Kapso rechazó, y el gate de
     * agendamiento del auto-envío) no enviaron nada: ahí no hay nada que reponer, y el objeto en
     * memoria es la descripción fiel de lo que pasó.
     *
     * @param LeadMessage $message
     *
     * @return LeadMessage
     */
    private function fresh_o_en_memoria(LeadMessage $message): LeadMessage
    {
        $fresco = $message->fresh();

        return $fresco !== null ? $fresco : $message;
    }

    /**
     * Manda un texto YA partido, parte por parte, con las pausas y los reintentos de siempre.
     *
     * Es literalmente el cuerpo que hasta ahora vivía adentro de `send_body()`. Se sacó afuera,
     * sin cambiarle una coma al comportamiento, para que el mensaje DIRECTO que un operador
     * escribe en el panel del lead use exactamente este camino en vez de una copia. Lo que se
     * copia se desincroniza, y acá lo que está en juego es la solución a un incidente real
     * (lead #440, 22/7/2026): sin las pausas y los reintentos, un 409 de Kapso corta el envío a
     * mitad de camino y el hilo miente sobre lo que le llegó a la persona.
     *
     * Entre los dos llamadores lo único que cambia son dos cosas, y las dos entran por parámetro:
     * cómo se decidió partir el texto (el separador laxo de las sugerencias de Claude contra el
     * estricto de lo que escribe una persona, ver SeparadorDeMensajesManuales) y con qué separador
     * se vuelven a unir las partes que no salieron, que es lo que después se copia y se remanda.
     *
     * FIX (prompt 366, lead #440, 22/7/2026): antes las partes salían en un foreach sin ninguna
     * pausa entre una y otra, y solo se devolvía el id de la última — si la última fallaba
     * (típicamente 409 de Kapso por "otro mensaje en vuelo"), el llamador interpretaba el null
     * como "no se envió nada" aunque las anteriores hubieran salido bien. Ahora:
     *   - se espera 1200ms entre parte y parte exitosa (le da tiempo a Kapso a liberar el
     *     bloqueo de "in-flight" de la conversación, que es la causa de raíz del 409);
     *   - cada parte se reintenta hasta 3 veces con backoff (1500ms / 3500ms) cuando el fallo es
     *     transitorio (409/429/5xx, ver WhatsappSendService::last_send_was_transient());
     *   - si una parte no sale tras los 3 intentos, se corta el envío ahí (no tiene sentido mandar
     *     la parte 5 si la 4 nunca llegó) y se devuelve el detalle exacto de qué salió y qué no,
     *     para que el llamador pueda registrar un envío parcial en vez de mentir con "rechazado"
     *     o "enviado" a secas.
     *
     * @param string             $phone                       Destino en el formato que ya usa el envío.
     * @param array<int, string> $partes                      Partes ya limpias y en orden. Tiene que
     *                                                        ser una lista indexada desde 0: el texto
     *                                                        pendiente se arma con array_slice() sobre
     *                                                        la posición de la parte que falló.
     * @param string             $context                     Contexto legible para la notificación de
     *                                                        fallo a admins.
     * @param string             $partes_pendientes_separador Con qué se vuelven a unir las partes que
     *                                                        no salieron.
     * @param callable|null      $al_enviar_parte             Se invoca, sin argumentos, después de
     *                                                        CADA parte que sale con éxito.
     *
     *                                                        🔴 Existe para que el envío de una
     *                                                        sugerencia pueda renovar el lease del
     *                                                        marcador de "envío en curso" mientras
     *                                                        avanza, sin que este método —que es
     *                                                        compartido con el mensaje directo del
     *                                                        panel— tenga que saber que ese marcador
     *                                                        existe. El mensaje directo no pasa nada
     *                                                        y no cambia en nada. Es opcional y va
     *                                                        al final justamente para eso.
     *
     * @return array{sent_parts:int, total_parts:int, last_message_id:string|null, pending_text:string|null, error:string|null}
     */
    public function enviar_partes(string $phone, array $partes, string $context, string $partes_pendientes_separador = "\n---\n", ?callable $al_enviar_parte = null): array
    {
        $total_parts = count($partes);

        // Id de la última parte enviada con éxito hasta el momento.
        $last_message_id = null;
        // Cantidad de partes que efectivamente salieron.
        $sent_parts = 0;
        // Motivo del corte, solo se completa si alguna parte falla tras agotar los reintentos.
        $error = null;

        foreach ($partes as $index => $part) {
            $part_sent = false;

            // Hasta 3 intentos por parte. Los intermedios pasan skip_failure_notification=true
            // para que un reintento que después sale bien no dispare la notificación de fallo a
            // los admins (solo el último intento, si también falla, notifica de verdad).
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $is_last_attempt = $attempt === 3;

                $message_id = $this->whatsapp_send_service->send_text($phone, $part, $context, ! $is_last_attempt);

                if ($message_id !== null) {
                    $last_message_id = $message_id;
                    $sent_parts++;
                    $part_sent = true;
                    break;
                }

                // Fallo transitorio (409 "in-flight" de Kapso es el caso central) y todavía quedan
                // intentos: esperar con backoff creciente antes de reintentar la misma parte.
                if (! $is_last_attempt && $this->whatsapp_send_service->last_send_was_transient()) {
                    usleep($attempt === 1 ? 1500000 : 3500000);
                    continue;
                }

                // Fallo no transitorio, o ya era el último intento: no tiene sentido seguir
                // reintentando esta parte.
                break;
            }

            if (! $part_sent) {
                // Se agotaron los intentos (o el fallo no era transitorio): cortar acá. El texto
                // pendiente incluye esta parte (la que falló) más todas las que quedaron sin
                // intentar, para que el setter las pueda mandar a mano.
                $error = $this->whatsapp_send_service->last_send_error;
                $pending_text = implode($partes_pendientes_separador, array_slice($partes, $index));

                return [
                    'sent_parts'       => $sent_parts,
                    'total_parts'      => $total_parts,
                    'last_message_id'  => $last_message_id,
                    'pending_text'     => $pending_text,
                    'error'            => $error,
                ];
            }

            /*
             * La parte salió: se le avisa al llamador, si pidió que se le avisara. Va ACÁ y no
             * después de la pausa a propósito — el que renueva un lease necesita hacerlo apenas
             * confirma que sigue vivo, no después de dormir 1200ms más.
             */
            if ($al_enviar_parte !== null) {
                $al_enviar_parte();
            }

            // Pausa entre partes exitosas (NO después de la última): es la prevención de raíz del
            // 409 de Kapso ("Another message is already in-flight for this conversation"), le da
            // tiempo a liberar el bloqueo de la conversación antes de mandar la siguiente parte.
            // Sin este comentario un futuro lector podría borrar el usleep por "innecesario".
            if ($index < $total_parts - 1) {
                usleep(1200000);
            }
        }

        return [
            'sent_parts'      => $sent_parts,
            'total_parts'     => $total_parts,
            'last_message_id' => $last_message_id,
            'pending_text'    => null,
            'error'           => null,
        ];
    }

    /**
     * Envía la sugerencia de Claude al número dado, partiéndola en mensajes separados si trae "---".
     *
     * Lo único propio que le queda a este método es lo que distingue a una sugerencia de cualquier
     * otro texto: el separador laxo del agente ("\n---\n", una línea con tres guiones a secas, que
     * es lo único que le pide el prompt) y el contexto con el que se identifica el fallo. El envío
     * en sí vive en `enviar_partes()`, compartido con el mensaje directo del panel.
     *
     * @param string        $phone
     * @param string        $body
     * @param Lead          $lead            Para armar el contexto de la notificación de fallo a admins.
     * @param LeadMessage   $message         Para armar el contexto de la notificación de fallo a admins.
     * @param callable|null $al_enviar_parte Callback que se ejecuta después de cada parte enviada
     *                                       con éxito. Lo arma send_suggestion() para renovar el
     *                                       lease del marcador de envío en curso; acá sólo se pasa
     *                                       de largo hasta enviar_partes().
     *
     * @return array{sent_parts:int, total_parts:int, last_message_id:string|null, pending_text:string|null, error:string|null}
     */
    private function send_body(string $phone, string $body, Lead $lead, LeadMessage $message, ?callable $al_enviar_parte = null): array
    {
        // Split idéntico al comportamiento anterior: separador "\n---\n", trim y descarte de partes vacías.
        $parts = array_values(array_filter(
            array_map('trim', preg_split('/\n---\n/', $body)),
            fn($p) => $p !== ''
        ));

        $context = 'Sugerencia de Claude - Lead #' . $lead->id
            . (! empty($lead->contact_name) ? " ({$lead->contact_name})" : '')
            . " (mensaje #{$message->id})";

        return $this->enviar_partes($phone, $parts, $context, "\n---\n", $al_enviar_parte);
    }

    /**
     * Aplica el cambio de estado sugerido por Claude al enviar el mensaje.
     *
     * @param Lead        $lead
     * @param LeadMessage $message
     *
     * @return void
     */
    private function apply_suggested_pipeline_status(Lead $lead, LeadMessage $message): void
    {
        $slug = trim((string) ($message->suggested_lead_status ?? ''));
        if ($slug === '') {
            return;
        }

        /*
         * FIX (prompt 118): si create_message_and_update_lead() ya aplicó el status,
         * no repetir el save() redundante al enviar el mensaje sugerido.
         */
        if ((string) $lead->status === $slug) {
            return;
        }

        LeadPipelineStatus::ensure_exists($slug);
        $lead->status = $slug;
        $lead->save();

        // Si el lead pasa a closer_activo (demo confirmada), avisar al closer por WhatsApp.
        // CloserNotificationService vive en el mismo namespace App\Services, no requiere `use`.
        if ($slug === 'closer_activo') {
            (new CloserNotificationService())->notify_for_lead($lead);
        }
    }

    /**
     * Registra corrección del setter como protocol_entry pendiente de revisión.
     *
     * @param Lead   $lead
     * @param string $original_content
     * @param string $edited_content
     *
     * @return void
     */
    private function record_setter_correction(Lead $lead, string $original_content, string $edited_content): void
    {
        ProtocolEntry::create([
            'titulo'           => 'Corrección del setter — '.now()->format('d/m/Y H:i'),
            'descripcion'      => 'Corrección automática detectada. El setter modificó '.
                'el mensaje sugerido por Claude. Revisar si aplica '.
                'como entrada general del protocolo.',
            'mensaje_template' => $edited_content,
            'categoria'        => 'situacion_frecuente',
            'estado_aplicable' => $lead->status,
            'notas_setter'     => 'Mensaje original de Claude: '.$original_content,
            'activa'           => false,
        ]);
    }

    /**
     * Indica si el lead escribió en las últimas 24 horas (ventana activa de WhatsApp).
     *
     * Fuera de este período Meta rechaza los mensajes de texto libre con 422.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    private function is_within_whatsapp_window(Lead $lead): bool
    {
        return LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sender', 'lead')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
    }

    /**
     * Aprueba y envía un seguimiento pendiente por su plantilla Meta guardada.
     *
     * Camino separado de send_text porque los seguimientos se disparan con el lead en silencio (ventana
     * de 24hs cerrada). Al aprobar (o al vencer el timer de respaldo), se envía la plantilla con el
     * nombre del contacto como {{1}}. Marca 'enviado' con whatsapp_message_id si Kapso confirma; si
     * falla, 'rechazado' (WhatsappSendService ya notificó a los admins de forma centralizada).
     *
     * @param LeadMessage $message
     * @param Lead        $lead
     * @param int|null    $sent_by_admin_id (prompt 403) Admin que aprobó el seguimiento desde el panel;
     *                                       null cuando lo aprobó el respaldo automático.
     *
     * @return LeadMessage
     */
    private function send_followup_suggestion_via_template(LeadMessage $message, Lead $lead, ?int $sent_by_admin_id = null): LeadMessage
    {
        $template = FollowupTemplate::query()->find($message->followup_template_id);
        $phone    = trim((string) $lead->phone);

        if ($template === null || $phone === '') {
            Log::channel('daily')->warning('LeadSuggestionSendService: seguimiento sin plantilla o sin teléfono, no enviado.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
            ]);

            (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);
            $message->update([
                'status'              => 'rechazado',
                'sent_at'             => null,
                // Motivo conocido en este call site (prompt 336): no hubo intento de envío real.
                'whatsapp_send_error' => 'Seguimiento sin plantilla o lead sin teléfono.',
            ]);
            $lead->sync_suggestion_flags();
            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

            return $this->fresh_o_en_memoria($message);
        }

        /*
         * 🔴 Las variables las arma LeadFollowupService, NO este archivo. Hasta el 27/8/2026 acá
         * se hacía `[trim((string) ($lead->contact_name ?? ''))]`, o sea que un lead sin nombre
         * mandaba `{{1}}` vacío y Meta contestaba `(#131008) Required parameter is missing`.
         *
         * Y este camino no es un caso de borde: para los seis estados de
         * LeadAiService::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO —entre ellos `demo_agendada`,
         * que tiene 6 plantillas activas— LeadFollowupService::process_lead() NO llama a
         * send_followup_via_template(): deja el seguimiento pendiente de verificación y el envío
         * real cae acá, al aprobarlo el setter o al vencer el timer de respaldo. Con el armado a
         * mano, el arreglo del nombre genérico valía para un camino y no para el otro.
         *
         * Que la definición de qué va en cada placeholder viva en UN solo lugar es lo que evita que
         * esto se vuelva a partir en dos.
         */
        $variables = app(LeadFollowupService::class)->build_template_variables($template, $lead);

        $context = 'Seguimiento aprobado - Lead #' . $lead->id
            . (! empty($lead->contact_name) ? " ({$lead->contact_name})" : '');

        $whatsapp_message_id = $this->whatsapp_send_service->send_template(
            $phone,
            $template->template_name,
            $variables,
            $template->language_code,
            $context
        );

        (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

        if ($whatsapp_message_id === null) {
            Log::channel('daily')->warning('LeadSuggestionSendService: seguimiento aprobado falló al enviarse por plantilla.', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
                'template'   => $template->template_name,
            ]);

            $message->update([
                'status'              => 'rechazado',
                'sent_at'             => null,
                // Motivo real capturado por WhatsappSendService (prompt 336).
                'whatsapp_send_error' => $this->whatsapp_send_service->last_send_error,
            ]);
            $lead->sync_suggestion_flags();
            LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

            // Deja asentado en el hilo que el seguimiento (aprobado por el setter o auto-enviado)
            // falló al enviarse por su plantilla (prompt 299), con el motivo real o el texto
            // genérico como fallback.
            (new LeadConversationErrorLogger())->log(
                (int) $lead->id,
                'No se pudo enviar el seguimiento por WhatsApp',
                $this->whatsapp_send_service->last_send_error ?: 'El envío por plantilla no se confirmó (revisar conexión con WhatsApp/Kapso).'
            );

            return $this->fresh_o_en_memoria($message);
        }

        $update_payload = [
            'status'              => 'enviado',
            'sent_at'             => now(),
            'whatsapp_message_id' => $whatsapp_message_id,
            // Admin que aprobó este seguimiento desde el panel (null si fue el respaldo automático, prompt 403).
            'sent_by_admin_id'    => $sent_by_admin_id,
        ];

        /*
         * 🔴 Misma detección de 0 filas que el camino principal, y por el mismo motivo: acá arriba la
         * PLANTILLA YA SALIÓ por WhatsApp. El efecto externo es idéntico al del otro camino —el lead
         * tiene el mensaje en el teléfono— y es igual de irreversible, así que un `$message->update()`
         * mudo (Model::performUpdate() tira el rowCount y devuelve `true` fijo) deja al hilo sin el
         * mensaje que la persona recibió, y al setter mandándolo de nuevo.
         *
         * Arreglar sólo la salida que el incidente nombró y dejar a esta —que es su gemela— con el
         * update mudo es exactamente "arreglar las instancias que el stack trace nombró, y no la
         * familia", que este proyecto ya tiene escrito como clase de error.
         */
        $filas_afectadas = LeadMessage::query()->whereKey($message->getKey())->update($update_payload);

        /* El exists() no sobra aunque $filas_afectadas ya sea 0: sin PDO::MYSQL_ATTR_FOUND_ROWS
           —que esta conexión no define— rowCount() cuenta filas CAMBIADAS, no encontradas. El
           razonamiento completo está en el camino principal, arriba. */
        $fila_desaparecida = $filas_afectadas === 0
            && ! LeadMessage::query()->whereKey($message->getKey())->exists();

        if ($fila_desaparecida) {
            /* La reposición vive en UN solo lugar: es el mismo recrear_mensaje_enviado() del camino
               principal, con el mismo aviso en el hilo y la misma marca de revisión. Lo único propio
               de acá es qué salió —una plantilla de Meta con sus variables, no el content del
               mensaje— y que no hay reparto en partes que informar. */
            $message = $this->recrear_mensaje_enviado(
                $lead,
                $message,
                $update_payload,
                'Plantilla "'.$template->template_name.'" con variables: '.implode(' | ', $variables)
            );
        } else {
            /* El UPDATE salió por query builder, así que el objeto en memoria quedó con los valores
               viejos, y apply_suggested_pipeline_status() y el broadcast de abajo leen de ESTE objeto. */
            $message->forceFill($update_payload);
            $message->syncOriginal();
        }

        /* Un seguimiento normalmente no cambia el pipeline, pero respetamos suggested_lead_status si existe. */
        $this->apply_suggested_pipeline_status($lead, $message);
        $lead->sync_suggestion_flags();
        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        return $this->fresh_o_en_memoria($message);
    }

    /**
     * Caso A del respaldo automático (prompt 337): el paquete de acciones pendientes trae
     * agendar_demo o cancelar_demo. El texto de Claude confirma (o cancela) un horario al lead, así
     * que no hay forma segura de enviarlo sin persistir la reserva — se corta el auto-envío entero
     * y queda en manos de un humano.
     *
     * No se envía nada por WhatsApp: el mensaje queda `sugerido` con pending_actions intacto (sigue
     * aprobable desde el panel). Se limpia el countdown (ai_auto_send_at) y se cancela el token de
     * respaldo, se marca el lead para revisión, se deja constancia en el hilo, y se notifica a los
     * admins reutilizando el mismo canal que ya avisa la verificación pendiente de agendamiento.
     *
     * @param LeadMessage $message Mensaje `sugerido` con pending_actions de agendamiento.
     * @param Lead        $lead    Lead dueño del mensaje.
     *
     * @return LeadMessage Mismo mensaje, sin cambios de estado (sigue `sugerido`).
     */
    private function handle_auto_send_agendamiento_gate(LeadMessage $message, Lead $lead): LeadMessage
    {
        // Cancela el token del job (invalida cualquier reintento ya encolado) y limpia
        // ai_auto_send_at: la burbuja no debe seguir mostrando un countdown que nunca va a disparar.
        (new LeadAiSuggestionAutoSendScheduler())->cancel_for_message((int) $message->id);

        $this->mark_lead_pending_review($lead);

        // Deja constancia en el hilo del motivo por el que el respaldo no auto-envió este mensaje.
        (new LeadConversationErrorLogger())->log(
            (int) $lead->id,
            'Mensaje de agendamiento sin aprobar',
            'El respaldo automático no envía este mensaje porque agenda o cancela una demo: requiere aprobación humana desde el panel.'
        );

        // Mismo canal que ya usa la verificación pendiente de agendamiento (push + WhatsApp a admins
        // suscritos); no se inventa un canal nuevo para este aviso.
        try {
            (new LeadVerificacionAgendamientoNotificationService())->notify($lead, $message);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('LeadSuggestionSendService: error al notificar respaldo retenido (Caso A, prompt 337).', [
                'lead_id'    => $lead->id,
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);
        }

        LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $message->id);

        Log::channel('daily')->info('LeadSuggestionSendService: auto-envío de respaldo retenido (Caso A, agendamiento sin aprobar).', [
            'lead_id'    => $lead->id,
            'message_id' => $message->id,
        ]);

        return $this->fresh_o_en_memoria($message);
    }

    /**
     * Marca el lead como pendiente de revisión (columna del prompt 301), sin pisar una marca
     * previa. Se usa cuando el respaldo automático actuó sin que nadie lo mirara: Caso A (no se
     * envió nada, quedó esperando aprobación) y Caso B (se envió, pero sin revisión humana).
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function mark_lead_pending_review(Lead $lead): void
    {
        if ($lead->pendiente_revision_at !== null) {
            return;
        }

        $lead->pendiente_revision_at = now();
        $lead->save();
    }
}
