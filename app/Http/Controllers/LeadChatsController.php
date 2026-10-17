<?php

namespace App\Http\Controllers;

use App\Events\LeadConversationUpdated;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use Illuminate\Http\Request;

/**
 * La bandeja de leads estilo WhatsApp (misión flujo-lead-landing-closer, 17/10/2026).
 *
 * Tres endpoints, todos bajo `auth:sanctum` y en el mismo grupo que `lead`:
 *
 *   - `GET admin/lead/chats`         la lista de chats: una fila por lead con su último mensaje,
 *                                    el color de atención y el estado del pipeline, paginada.
 *   - `GET admin/lead/chats/{id}`    la misma fila para UN lead (para refrescar una sola desde Pusher).
 *   - `POST admin/lead/{id}/marcar-respondido`  el botón «no necesita respuesta».
 *
 * 🔴 Es un controlador aparte de `LeadController` a propósito, y no por prolijidad: `GET admin/lead`
 * (`index_json`) sigue existiendo tal cual para la conversación, el closer y todo lo que ya lo
 * consume. Este listado no reemplaza al otro en la API, reemplaza la TABLA del módulo en el SPA. Lo
 * que comparte con el otro lo comparte por los scopes del modelo (`withUnreadLeadMessagesCount`,
 * `apply_condicion_mensaje_sin_responder`, `apply_condicion_error_sin_resolver`) y por el catálogo
 * (`LeadPipelineStatus::options_for_meta()`), nunca copiando SQL: la lista y las tarjetas tienen
 * que decir lo mismo, igual que la tarjeta y la grilla antes (lo que Lucas reportó el 2/9/2026).
 *
 * La forma de la fila es el contrato §4.1 del plan de la misión y la consume `admin-spa`
 * (`components/lead/chats/`): no se le saca ni se le renombra una clave sin pasar por ahí.
 */
class LeadChatsController extends Controller
{
    /** Tamaño de página por defecto y techo, los mismos que `LeadController::index_json()`. */
    const PER_PAGE_DEFAULT = 50;
    const PER_PAGE_MAX = 200;

    /** Largo máximo del `texto` del último mensaje: una línea de la fila, sin saltos. */
    const TEXTO_MAX = 120;

    /**
     * `kind` de `lead_messages` que son un adjunto. Es lo que escribe el webhook
     * (`WhatsappWebhookController::normalize_whatsapp_message_kind()`, que ya convierte `ptt` y
     * `voice` en `audio`) más lo que acepta `WhatsappInboundMediaService`.
     *
     * @var array<int, string>
     */
    const KINDS_ADJUNTO = ['image', 'sticker', 'audio', 'ptt', 'voice', 'video', 'document'];

    /**
     * Lista de chats.
     *
     * Query: `q` (texto; nombre, empresa y teléfono por dígitos), `status` (slug o lista separada
     * por comas; acepta también los valores agrupados de las tarjetas, `demo` y `otros`),
     * `solo_atencion` (bool: solo filas con `atencion` rojo o amarillo), `page` (default 1),
     * `per_page` (default 50, máx 200).
     *
     * Orden fijo (decisión D5 de Lucas): fijados primero (el más reciente arriba), después rojo,
     * después amarillo, después `COALESCE(last_message_at, created_at) DESC`. No hay selector de
     * orden: la tabla con sus botones desapareció.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse `{ data: [fila…], meta: {current_page, last_page, per_page, total} }`
     */
    public function index_json(Request $request)
    {
        $per_page = (int) $request->input('per_page', self::PER_PAGE_DEFAULT);
        if ($per_page < 1) {
            $per_page = self::PER_PAGE_DEFAULT;
        }
        if ($per_page > self::PER_PAGE_MAX) {
            $per_page = self::PER_PAGE_MAX;
        }

        $page = (int) $request->input('page', 1);
        if ($page < 1) {
            $page = 1;
        }

        $query = $this->query_de_la_lista();

        $this->aplicar_busqueda($query, (string) $request->input('q', ''));
        $this->aplicar_filtro_de_estado($query, (string) $request->input('status', ''));

        if ($request->boolean('solo_atencion')) {
            $this->aplicar_solo_atencion($query);
        }

        $this->aplicar_orden($query);

        $paginador = $query->paginate($per_page, ['leads.*'], 'page', $page);

        return response()->json([
            'data' => $this->filas($paginador->getCollection()),
            'meta' => [
                'current_page' => $paginador->currentPage(),
                'last_page'    => $paginador->lastPage(),
                'per_page'     => $paginador->perPage(),
                'total'        => $paginador->total(),
            ],
        ], 200);
    }

    /**
     * Una sola fila, con la misma forma que la lista. Para refrescar un chat puntual cuando llega
     * `LeadConversationUpdated` / `LeadSuggestionCreated` por Pusher, sin volver a pedir la página.
     *
     * @param Request    $request
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse `{ data: fila }`, o 404 si el lead no existe.
     */
    public function show_json(Request $request, $id)
    {
        $lead = $this->query_de_la_lista()->where('leads.id', (int) $id)->first();

        if ($lead === null) {
            return response()->json(['message' => 'El lead no existe.'], 404);
        }

        return response()->json(['data' => $this->filas(collect([$lead]))[0]], 200);
    }

    /**
     * «No necesita respuesta» (decisión D6 de Lucas, 17/10/2026).
     *
     * Body `{ message_id?: int, deshacer?: bool }`:
     *   - sin `message_id`: marca hasta el ÚLTIMO mensaje del lead;
     *   - con `message_id`: hasta ese, que tiene que ser un mensaje del lead (sender `lead`) de ESTE
     *     lead — si no, 422 sin escribir nada;
     *   - `deshacer: true`: limpia la marca.
     *
     * Escribe `leads.atendido_hasta_message_id` y emite `LeadConversationUpdated` con el payload
     * mínimo (solo `lead_id`), para que las pestañas abiertas refresquen la fila. El efecto —la fila
     * deja de contar en el amarillo, en `status-cards`, en `?sin_responder=1` y en el gate de la IA—
     * no se calcula acá: sale solo de que la marca está adentro de la definición única de «sin
     * responder» ({@see Lead::apply_condicion_mensaje_sin_responder()} y su gemelo PHP).
     *
     * Es un id y no un booleano a propósito: el mensaje siguiente del lead tiene un id mayor y vuelve
     * a contar solo, sin que haya que «desmarcar» nada.
     *
     * @param Request    $request
     * @param int|string $id
     *
     * @return \Illuminate\Http\JsonResponse `{ data: fila }`.
     */
    public function marcar_respondido_json(Request $request, $id)
    {
        $lead = Lead::query()->find((int) $id);

        if ($lead === null) {
            return response()->json(['message' => 'El lead no existe.'], 404);
        }

        if ($request->boolean('deshacer')) {
            $hasta = null;
        } else {
            $message_id = $request->input('message_id');

            if ($message_id !== null && $message_id !== '') {
                /* Tiene que ser un mensaje del lead, de este lead. Un id de otro lead o de un saliente
                   nuestro no es algo que se pueda «dar por atendido»: 422 y no se escribe nada. */
                $es_del_lead = ctype_digit((string) $message_id)
                    && LeadMessage::query()
                        ->where('id', (int) $message_id)
                        ->where('lead_id', $lead->id)
                        ->where('sender', 'lead')
                        ->exists();

                if (! $es_del_lead) {
                    return response()->json([
                        'message' => 'message_id tiene que ser un mensaje del lead de este lead.',
                        'errors'  => ['message_id' => ['message_id tiene que ser un mensaje del lead de este lead.']],
                    ], 422);
                }

                $hasta = (int) $message_id;
            } else {
                $hasta = (int) LeadMessage::query()
                    ->where('lead_id', $lead->id)
                    ->where('sender', 'lead')
                    ->max('id');

                if ($hasta === 0) {
                    return response()->json([
                        'message' => 'El lead no tiene mensajes propios para dar por atendidos.',
                        'errors'  => ['message_id' => ['El lead no tiene mensajes propios para dar por atendidos.']],
                    ], 422);
                }
            }
        }

        $lead->atendido_hasta_message_id = $hasta;
        $lead->save();

        /* Payload mínimo (solo ids): el canal `leads.admins` es público. Ver el docblock del evento. */
        LeadConversationUpdated::dispatch((int) $lead->id);

        return $this->show_json($request, $lead->id);
    }

    /**
     * El query base de la lista: `leads.*` más los contadores que ya usa la grilla
     * (`scopeWithUnreadLeadMessagesCount`) más dos alias propios:
     *
     *   - `sin_responder_count`: cuántos mensajes del lead están sin responder según LA definición
     *     ({@see Lead::apply_condicion_mensaje_sin_responder()}, con la marca «no necesita respuesta»
     *     adentro). Es lo que decide el amarillo. `row_warning` no sirve para esto porque mezcla el
     *     «sin responder» con el «por verificar», y la fila tiene que decir CUÁL de los dos es.
     *   - `ultimo_mensaje_id`: el id del último mensaje del hilo que no es evento de estado ni está
     *     `rechazado`. Se trae el id en el SELECT y el mensaje después, en UNA consulta para toda la
     *     página, en vez de cargar la relación `messages` (que arrastra el hilo entero de cada lead).
     *
     * ⚠️ Los alias se referencian en el ORDER BY (MySQL lo permite para el select list) y en ningún
     * WHERE: ahí van los `EXISTS` de {@see self::aplicar_solo_atencion()}, con los mismos predicados.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function query_de_la_lista()
    {
        return Lead::query()
            ->select('leads.*')
            ->withUnreadLeadMessagesCount()
            ->addSelect([
                'sin_responder_count' => LeadMessage::selectRaw('COUNT(*)')
                    ->whereColumn('lead_messages.lead_id', 'leads.id')
                    ->where(function ($sin_responder) {
                        Lead::apply_condicion_mensaje_sin_responder($sin_responder);
                    }),

                'ultimo_mensaje_id' => LeadMessage::select('lead_messages.id')
                    ->whereColumn('lead_messages.lead_id', 'leads.id')
                    ->where(function ($visible) {
                        self::aplicar_condicion_ultimo_mensaje($visible);
                    })
                    ->orderByDesc('lead_messages.id')
                    ->limit(1),
            ]);
    }

    /**
     * Qué mensaje del hilo puede ser «el último» de la fila: ni un evento de estado (no es actividad
     * del chat) ni un `rechazado` (una sugerencia que el operador tiró no se le muestra como si
     * fuera la última palabra). Un `sugerido` esperando aprobación SÍ entra: es justo lo que el admin
     * tiene que ver en la fila.
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    private static function aplicar_condicion_ultimo_mensaje($query): void
    {
        $query->where('lead_messages.is_status_event', false)
            ->where(function ($no_rechazado) {
                $no_rechazado->whereNull('lead_messages.status')
                    ->orWhere('lead_messages.status', '<>', 'rechazado');
            });
    }

    /**
     * Buscador: `LIKE %q%` sobre `contact_name` y `company_name`, y sobre `phone` con los dígitos de
     * `q` (así «341 555» encuentra a `5493415551234`). El teléfono del lead lo guarda el webhook ya
     * normalizado a dígitos, por eso la comparación es directa contra la columna.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $q
     *
     * @return void
     */
    private function aplicar_busqueda($query, string $q): void
    {
        $q = trim($q);
        if ($q === '') {
            return;
        }

        /* `%` y `_` son comodines de LIKE: escapados para que buscar «100%» busque eso. */
        $like    = '%' . addcslashes($q, '\\%_') . '%';
        $digitos = preg_replace('/\D+/', '', $q);

        $query->where(function ($busca) use ($like, $digitos) {
            $busca->where('leads.contact_name', 'like', $like)
                ->orWhere('leads.company_name', 'like', $like);

            if ($digitos !== '') {
                $busca->orWhere('leads.phone', 'like', '%' . $digitos . '%');
            }
        });
    }

    /**
     * Filtro por estado con la misma semántica que `GET admin/lead`: un slug o una lista separada
     * por comas. Y además los dos valores agrupados de las tarjetas, que `GET admin/lead` no conoce
     * porque el SPA viejo ya le mandaba los slugs desplegados: `demo` =
     * {@see LeadPipelineStatus::TARJETA_DEMO_SLUGS} y `otros` = {@see LeadPipelineStatus::slugs_tarjeta_otros()}.
     * Se resuelven acá, contra el catálogo, para que el SPA no tenga que saber qué estados agrupa
     * cada tarjeta (ni quedar viejo cuando el catálogo cambie).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $status
     *
     * @return void
     */
    private function aplicar_filtro_de_estado($query, string $status): void
    {
        $pedidos = array_values(array_filter(array_map('trim', explode(',', $status)), function ($slug) {
            return $slug !== '';
        }));

        if (empty($pedidos)) {
            return;
        }

        $slugs = [];
        foreach ($pedidos as $pedido) {
            if ($pedido === LeadPipelineStatus::TARJETA_DEMO_VALUE) {
                $slugs = array_merge($slugs, LeadPipelineStatus::TARJETA_DEMO_SLUGS);
            } elseif ($pedido === LeadPipelineStatus::TARJETA_OTROS_VALUE) {
                $slugs = array_merge($slugs, LeadPipelineStatus::slugs_tarjeta_otros());
            } else {
                $slugs[] = $pedido;
            }
        }

        $query->whereIn('leads.status', array_values(array_unique($slugs)));
    }

    /**
     * `solo_atencion=1`: solo los leads cuya fila tiene `atencion` rojo o amarillo. Son los MISMOS
     * tres predicados que calculan `failed_send_count`, `pending_verification_count` y
     * `sin_responder_count` en el SELECT, escritos como `EXISTS` porque MySQL no deja usar un alias
     * del select list en el WHERE. Los dos predicados con cuerpo propio viven en el modelo
     * ({@see Lead::apply_condicion_error_sin_resolver()} y
     * {@see Lead::apply_condicion_mensaje_sin_responder()}); el de «por verificar» son dos
     * igualdades y es el mismo de `pending_verification_count`.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    private function aplicar_solo_atencion($query): void
    {
        $query->where(function ($atencion) {
            // Rojo por error de envío: solo si el lead todavía recibe mensajes (mismo descuento que
            // hace el SPA con danger_row_ids y que hace el scope requiereRevision).
            $atencion->where(function ($error_envio) {
                $error_envio->whereNull('leads.no_recibe_mensajes_at')
                    ->whereExists(function ($error) {
                        $error->selectRaw('1')
                            ->from('lead_messages')
                            ->whereColumn('lead_messages.lead_id', 'leads.id');
                        Lead::apply_condicion_error_sin_resolver($error);
                    });
            })
            // Rojo por mensaje esperando confirmación (requiere_verificacion + sugerido).
            ->orWhereExists(function ($pendiente) {
                $pendiente->selectRaw('1')
                    ->from('lead_messages')
                    ->whereColumn('lead_messages.lead_id', 'leads.id')
                    ->where('lead_messages.requiere_verificacion', true)
                    ->where('lead_messages.status', 'sugerido');
            })
            // Amarillo: mensaje del lead sin responder (con la marca «no necesita respuesta» adentro),
            // solo si el lead todavía recibe mensajes (ver el porqué en atencion()).
            ->orWhere(function ($sin_responder_alcanzable) {
                $sin_responder_alcanzable->whereNull('leads.no_recibe_mensajes_at')
                    ->whereExists(function ($sin_responder) {
                        $sin_responder->selectRaw('1')
                            ->from('lead_messages')
                            ->whereColumn('lead_messages.lead_id', 'leads.id');
                        Lead::apply_condicion_mensaje_sin_responder($sin_responder);
                    });
            });
        });
    }

    /**
     * El orden fijo de la bandeja (D5): fijados → rojo → amarillo → último mensaje.
     *
     * No reutiliza `scopeApplyBandejaOrder` a propósito: aquel, en modo `atencion`, pone la atención
     * POR ENCIMA del pin y suma `pendiente_revision_at` al rojo (la única superficie que le queda al
     * Caso B del respaldo automático). Acá el pin gana —es lo que Lucas pidió para la lista estilo
     * WhatsApp, donde fijar es justamente «esto va arriba de todo»— y el rojo es el de la fila
     * (error de envío con el lead alcanzable, o mensaje esperando confirmación), sin más.
     *
     * Referencia los alias del SELECT (`failed_send_count`, `pending_verification_count`,
     * `sin_responder_count`) en un `CASE` del ORDER BY, igual que hace `scopeApplyBandejaOrder`.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    private function aplicar_orden($query): void
    {
        // Fijados primero, el más reciente arriba. CASE WHEN por compatibilidad con MySQL (sin NULLS LAST).
        $query->orderByRaw('CASE WHEN leads.pinned_at IS NOT NULL THEN 0 ELSE 1 END ASC');
        $query->orderByRaw('leads.pinned_at DESC');

        // Después el color de la fila: rojo (0), amarillo (1), nada (2). El amarillo también descuenta a
        // los «ya no recibe mensajes» (ver atencion()), igual que el filtro y el color.
        $query->orderByRaw('
            CASE
                WHEN (failed_send_count > 0 AND leads.no_recibe_mensajes_at IS NULL) OR pending_verification_count > 0 THEN 0
                WHEN sin_responder_count > 0 AND leads.no_recibe_mensajes_at IS NULL THEN 1
                ELSE 2
            END ASC
        ');

        // Desempate: último mensaje, con COALESCE para no dejar al final a los leads sin mensajes.
        $query->orderByRaw('COALESCE(leads.last_message_at, leads.created_at) DESC');
        $query->orderByDesc('leads.id');
    }

    /**
     * Arma las filas de una página: trae los últimos mensajes de TODA la página en una consulta y
     * resuelve el catálogo de estados una sola vez.
     *
     * @param \Illuminate\Support\Collection $leads
     *
     * @return array<int, array<string, mixed>>
     */
    private function filas($leads): array
    {
        $ids_ultimos = $leads->pluck('ultimo_mensaje_id')->filter()->map(function ($id) {
            return (int) $id;
        })->values()->all();

        $ultimos = empty($ids_ultimos)
            ? collect()
            : LeadMessage::query()->with('attachments')->whereIn('id', $ids_ultimos)->get()->keyBy('id');

        $catalogo = $this->catalogo_de_estados();

        $filas = [];
        foreach ($leads as $lead) {
            $ultimo = $lead->ultimo_mensaje_id ? ($ultimos->get((int) $lead->ultimo_mensaje_id) ?: null) : null;
            $filas[] = $this->fila($lead, $ultimo, $catalogo);
        }

        return $filas;
    }

    /**
     * La fila del contrato §4.1, clave por clave.
     *
     * @param Lead                                  $lead     Con los alias del query de la lista cargados.
     * @param LeadMessage|null                      $ultimo   Último mensaje visible del hilo, con adjuntos.
     * @param array<string, array<string, mixed>>   $catalogo Catálogo de estados indexado por slug.
     *
     * @return array<string, mixed>
     */
    private function fila(Lead $lead, ?LeadMessage $ultimo, array $catalogo): array
    {
        $atencion = $this->atencion($lead);
        $estado   = $this->estado_del_catalogo((string) $lead->status, $catalogo);

        return [
            'id'                        => (int) $lead->id,
            'uuid'                      => $lead->uuid,
            'contact_name'              => $lead->contact_name,
            'company_name'              => $lead->company_name,
            'phone'                     => $lead->phone,
            'email'                     => $lead->email,

            'status'                    => $lead->status,
            'status_text'               => $estado['text'],
            'status_color'              => $estado['color'],

            'pinned_at'                 => self::fecha($lead->pinned_at),
            'manually_marked_unread'    => (bool) $lead->manually_marked_unread,
            'no_recibe_mensajes_at'     => self::fecha($lead->no_recibe_mensajes_at),

            'atendido_hasta_message_id' => $lead->atendido_hasta_message_id !== null ? (int) $lead->atendido_hasta_message_id : null,

            'atencion'                  => $atencion['atencion'],
            'atencion_motivo'           => $atencion['motivo'],

            'pending_verification_count' => (int) $lead->pending_verification_count,
            'failed_send_count'          => (int) $lead->failed_send_count,
            'unread_count'               => (int) $lead->unread_count,

            'last_message_at'           => self::fecha($lead->last_message_at),

            'ultimo_mensaje'            => $ultimo ? $this->ultimo_mensaje($ultimo) : null,

            'demo'                      => $lead->demo_id ? [
                'demo_id'           => (int) $lead->demo_id,
                'demo_date'         => $lead->demo_date ? $lead->demo_date->format('Y-m-d') : null,
                'demo_start_time'   => $lead->demo_start_time,
                'demo_end_time'     => $lead->demo_end_time,
                'demo_setup_status' => $lead->demo_setup_status,
            ] : null,
        ];
    }

    /**
     * El color de la fila y su motivo. El rojo le gana al amarillo, y dentro del rojo el error de
     * envío va antes que la confirmación pendiente (si las dos cosas pasan, lo que el operador tiene
     * que ver primero es que al lead no le está llegando nada).
     *
     *   - rojo / error_envio: `failed_send_count > 0` y el lead todavía recibe mensajes
     *     (`no_recibe_mensajes_at` nulo). Un lead marcado como «ya no recibe mensajes» no pide
     *     atención por eso: no hay nada que reintentar.
     *   - rojo / esperando_confirmacion: `pending_verification_count > 0`.
     *   - amarillo / sin_responder: `sin_responder_count > 0`, o sea LA definición de «sin responder»
     *     con la marca «no necesita respuesta» adentro, **y el lead todavía recibe mensajes**.
     *   - null: nada de lo anterior.
     *
     * 🔴 Por qué el amarillo también descuenta `no_recibe_mensajes_at` (chequeo independiente de la
     * misión, 17/10/2026): `Lead::scopeRequiereRevision()` —que alimenta el `sin_responder` de las
     * tarjetas y `?sin_responder=1`— saca a esos leads de TODO el conteo, no solo del error de envío
     * («no hay nada que hacer con un lead al que no le llega nada»). Si acá el amarillo los contara,
     * un lead inalcanzable con un mensaje suyo sin contestar saldría amarillo en la lista y entraría
     * en `solo_atencion=1` mientras la tarjeta dice 0: la lista y las tarjetas diciendo cosas
     * distintas, que es justo el defecto del 2/9/2026 que este controlador promete no repetir. El
     * `row_warning` de la grilla vieja sí lo pintaba; se decide como el scope, no como la grilla,
     * porque la grilla se va y la tarjeta se queda. Esta regla va en los TRES lugares donde vive el
     * predicado (acá, el CASE de {@see self::aplicar_orden()} y {@see self::aplicar_solo_atencion()}).
     *
     * Son los mismos tres predicados del ORDER BY y del filtro, así que el orden, el filtro y el color
     * no pueden contradecirse.
     *
     * @param Lead $lead
     *
     * @return array{atencion: string|null, motivo: string|null}
     */
    private function atencion(Lead $lead): array
    {
        $alcanzable = $lead->no_recibe_mensajes_at === null;

        if ((int) $lead->failed_send_count > 0 && $alcanzable) {
            return ['atencion' => 'rojo', 'motivo' => 'error_envio'];
        }

        if ((int) $lead->pending_verification_count > 0) {
            return ['atencion' => 'rojo', 'motivo' => 'esperando_confirmacion'];
        }

        if ((int) $lead->sin_responder_count > 0 && $alcanzable) {
            return ['atencion' => 'amarillo', 'motivo' => 'sin_responder'];
        }

        return ['atencion' => null, 'motivo' => null];
    }

    /**
     * El resumen del último mensaje para la fila.
     *
     * @param LeadMessage $mensaje Con la relación `attachments` cargada.
     *
     * @return array<string, mixed>
     */
    private function ultimo_mensaje(LeadMessage $mensaje): array
    {
        $delivery = trim((string) ($mensaje->whatsapp_delivery_status ?? ''));
        $kind     = trim((string) ($mensaje->kind ?? ''));

        return [
            'id'                       => (int) $mensaje->id,
            'sender'                   => (string) $mensaje->sender,
            'status'                   => (string) $mensaje->status,
            'whatsapp_delivery_status' => $delivery !== '' ? $delivery : null,
            'is_error'                 => (bool) $mensaje->is_error,
            'kind'                     => $kind !== '' ? $kind : null,
            'created_at'               => self::fecha($mensaje->created_at),
            'texto'                    => self::texto_del_ultimo_mensaje($mensaje),
        ];
    }

    /**
     * El `texto` de la fila: una línea, sin saltos, de hasta {@see self::TEXTO_MAX} caracteres.
     *
     * Con un adjunto y sin texto propio, la etiqueta del tipo («📷 Foto», «🎤 Audio», «📄 Documento»,
     * y «🎥 Video» para el único tipo que el contrato no nombra y que el webhook sí recibe). «Sin
     * texto propio» incluye los marcadores que escribe el webhook cuando el adjunto no trae nada
     * (`[Audio sin transcripción]`, `[IMAGE recibido por WhatsApp]`, …): un corchete cerrado no es
     * algo que el lead haya escrito.
     *
     * Con adjunto Y texto (el epígrafe de una foto, la transcripción de un audio), la etiqueta va
     * adelante del texto: «🎤 hola, quería saber el precio». Decisión de este constructor, no del
     * plan: sin la etiqueta, la transcripción de un audio se leería en la fila como si el lead lo
     * hubiera tipeado, y el operador no sabría que tiene que escucharlo.
     *
     * @param LeadMessage $mensaje Con la relación `attachments` cargada.
     *
     * @return string
     */
    public static function texto_del_ultimo_mensaje(LeadMessage $mensaje): string
    {
        $kind      = strtolower(trim((string) ($mensaje->kind ?? '')));
        $contenido = trim((string) preg_replace('/\s+/u', ' ', (string) ($mensaje->content ?? '')));

        $tiene_adjunto = in_array($kind, self::KINDS_ADJUNTO, true)
            || ($mensaje->relationLoaded('attachments') && $mensaje->attachments->isNotEmpty());

        if ($tiene_adjunto) {
            $etiqueta  = self::etiqueta_del_adjunto($kind, $mensaje);
            $sin_texto = $contenido === '' || (bool) preg_match('/^\[[^\]]*\]$/u', $contenido);

            $contenido = $sin_texto ? $etiqueta : $etiqueta . ' ' . $contenido;
        }

        return mb_strimwidth($contenido, 0, self::TEXTO_MAX, '…', 'UTF-8');
    }

    /**
     * Etiqueta del adjunto según el `kind` del mensaje y, si el `kind` no alcanza, el MIME del
     * primer adjunto.
     *
     * @param string      $kind
     * @param LeadMessage $mensaje
     *
     * @return string
     */
    private static function etiqueta_del_adjunto(string $kind, LeadMessage $mensaje): string
    {
        $por_kind = [
            'image'    => "\u{1F4F7} Foto",
            'sticker'  => "\u{1F4F7} Foto",
            'audio'    => "\u{1F3A4} Audio",
            'ptt'      => "\u{1F3A4} Audio",
            'voice'    => "\u{1F3A4} Audio",
            'video'    => "\u{1F3A5} Video",
            'document' => "\u{1F4C4} Documento",
        ];

        if (isset($por_kind[$kind])) {
            return $por_kind[$kind];
        }

        /* `kind` genérico (`text`, u otro) pero con archivo adjunto: se decide por el MIME. */
        $mime = '';
        if ($mensaje->relationLoaded('attachments') && $mensaje->attachments->isNotEmpty()) {
            $mime = strtolower(trim((string) ($mensaje->attachments->first()->mime ?? '')));
        }

        if (strpos($mime, 'image/') === 0) {
            return $por_kind['image'];
        }
        if (strpos($mime, 'audio/') === 0) {
            return $por_kind['audio'];
        }
        if (strpos($mime, 'video/') === 0) {
            return $por_kind['video'];
        }

        return $por_kind['document'];
    }

    /**
     * Catálogo de estados indexado por slug, del MISMO lugar del que salen las tarjetas y el puntito
     * de la barra de estados (`LeadPipelineStatus::options_for_meta()`), así nunca divergen.
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalogo_de_estados(): array
    {
        $catalogo = [];
        foreach (LeadPipelineStatus::options_for_meta() as $opcion) {
            if (isset($opcion['value'])) {
                $catalogo[(string) $opcion['value']] = $opcion;
            }
        }

        return $catalogo;
    }

    /**
     * Texto y color de un slug de estado. Mismo fallback que `LeadStatusCardsService`: si el slug no
     * está en el catálogo visible (oculto del select, o creado al vuelo por Claude), se humaniza el
     * slug y se usa el color por defecto del catálogo.
     *
     * @param string                               $slug
     * @param array<string, array<string, mixed>>  $catalogo
     *
     * @return array{text: string, color: string}
     */
    private function estado_del_catalogo(string $slug, array $catalogo): array
    {
        if (isset($catalogo[$slug])) {
            $text  = trim((string) $catalogo[$slug]['text']) !== ''
                ? (string) $catalogo[$slug]['text']
                : LeadPipelineStatus::humanize_slug($slug);
            $color = trim((string) $catalogo[$slug]['color']);
        } else {
            $text  = LeadPipelineStatus::humanize_slug($slug);
            $color = LeadPipelineStatus::color_for($slug);
        }

        return [
            'text'  => $text,
            'color' => $color !== '' ? $color : LeadPipelineStatus::color_for(null),
        ];
    }

    /**
     * Una fecha para el JSON, con el mismo formato con el que Eloquent serializa las columnas
     * `datetime` del resto de la API (`Carbon::toJSON()`, ISO 8601): así el SPA parsea estas igual
     * que `last_message_at` de `GET admin/lead`. Un string ya formateado pasa tal cual; null, null.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    private static function fecha($valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return \Carbon\Carbon::instance($valor)->toJSON();
        }

        return (string) $valor;
    }
}
