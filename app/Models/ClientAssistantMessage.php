<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una línea del hilo de WhatsApp entre el dueño de un negocio y el asistente de su sistema.
 *
 * El contenido real de la conversación vive en el `empresa-api` del cliente; acá queda el mapeo
 * de `wamid` ↔ conversación —que es lo único que hace posible la cita— y la traza de en qué
 * tramo se cortó un mensaje que no volvió. Ver la migración `create_client_assistant_messages_table`
 * para el porqué de cada columna.
 *
 * @property int         $client_id                    Cliente dueño del hilo.
 * @property string      $telefono                     E.164 normalizado del dueño.
 * @property string      $direccion                    'in' o 'out'.
 * @property string|null $whatsapp_message_id          wamid de Meta de ESTE mensaje.
 * @property string|null $reply_to_whatsapp_message_id wamid citado (solo entrantes).
 * @property int|null    $ai_conversation_id           Conversación del `empresa-api`.
 * @property int|null    $ai_message_id                Mensaje del asistente que se pollea.
 * @property string      $estado                       recibido | enviado | respondido | degradado | error
 */
class ClientAssistantMessage extends Model
{
    /**
     * Direcciones posibles. El entrante es del dueño hacia el asistente.
     */
    const DIRECCION_ENTRANTE = 'in';
    const DIRECCION_SALIENTE = 'out';

    /**
     * Estados posibles del tramo. `degradado` es el cliente que todavía no tiene el endpoint
     * (404) y está separado de `error` a propósito: es lo esperado, no una falla.
     */
    const ESTADO_RECIBIDO   = 'recibido';
    const ESTADO_ENVIADO    = 'enviado';
    const ESTADO_RESPONDIDO = 'respondido';
    const ESTADO_DEGRADADO  = 'degradado';
    const ESTADO_ERROR      = 'error';

    /**
     * Todas las filas las escribe el propio canal (webhook y job), nunca un request de usuario:
     * no hay entrada de afuera de la que protegerse con una lista blanca.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Los identificadores del `empresa-api` viajan como enteros o como null, nunca como string:
     * el job los compara y los vuelve a mandar en el body del POST.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'          => 'integer',
        'ai_conversation_id' => 'integer',
        'ai_message_id'      => 'integer',
    ];

    /**
     * Cliente dueño del hilo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Scope estándar para contrato homogéneo con el resto de los modelos del repo.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        // Los accesores ecommerce_* del $appends de Client resuelven contra client_ecommerce:
        // sin precargarla salia una consulta por fila serializada.
        $query->with('client', 'client.client_ecommerce');
    }

    /**
     * Resuelve la conversación que el dueño quiere continuar a partir del mensaje que citó.
     *
     * 🔴 Se busca el `wamid` citado entre TODAS las filas del cliente, entrantes y salientes, y
     * no solo entre las salientes del asistente. El caso obvio es citar la respuesta del
     * asistente, pero WhatsApp deja citar cualquier mensaje del hilo —incluido uno propio— y en
     * los dos casos la intención es la misma: seguir esa conversación.
     *
     * Devuelve null cuando el `wamid` citado no es de este canal (por ejemplo, el dueño citó un
     * mensaje viejo de soporte). Eso NO es un error: significa que no hay cita que resolver y la
     * conversación la decide el `empresa-api` con su corte por tiempo, que es el reparto que fija
     * el plan.
     *
     * @param int         $client_id   Cliente dueño del hilo.
     * @param string|null $reply_to_id wamid citado por el dueño, si el payload lo trajo.
     *
     * @return int|null ID de la conversación del `empresa-api`, o null si no se pudo resolver.
     */
    public static function conversacion_por_cita(int $client_id, ?string $reply_to_id): ?int
    {
        $reply_to_id = trim((string) $reply_to_id);
        if ($reply_to_id === '') {
            return null;
        }

        $fila = self::query()
            ->where('client_id', $client_id)
            ->where('whatsapp_message_id', $reply_to_id)
            ->whereNotNull('ai_conversation_id')
            ->orderBy('id', 'desc')
            ->first();

        if ($fila === null) {
            return null;
        }

        return (int) $fila->ai_conversation_id;
    }
}
