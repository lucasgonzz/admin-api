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
 * @property int|null    $agrupado_en_id               Fila del mensaje con el que viajó esta foto.
 * @property array|null  $media_en_espera              Metadata de la foto mientras espera (cifrada).
 * @property string      $estado                       esperando | agrupado | recibido | enviado | respondido | degradado | error
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
     * Los dos estados de la foto que espera su instrucción (misión asistente-espera-foto, 27/9/2026).
     *
     * `esperando` es una foto que llegó SIN epígrafe y todavía no salió hacia el `empresa-api`: se
     * queda unos segundos a ver si el dueño manda la instrucción (el audio de "cargame este
     * artículo"). `agrupado` es una foto que ya viajó, pero pegada al mensaje de OTRA fila
     * (`agrupado_en_id`): es un estado final y el job no la vuelve a tramitar. Ver
     * `AsistenteWhatsappService::recibir()`.
     */
    const ESTADO_ESPERANDO = 'esperando';
    const ESTADO_AGRUPADO  = 'agrupado';

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
     * 🔴 `media_en_espera` va con `encrypted:array` y no con `array`: guarda la URL firmada de Kapso
     * de una foto mientras espera, y una URL firmada en claro en la base es una credencial escrita.
     * Cifrada con la APP_KEY, lo que queda en la columna no sirve para bajar nada.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'          => 'integer',
        'ai_conversation_id' => 'integer',
        'ai_message_id'      => 'integer',
        'agrupado_en_id'     => 'integer',
        'media_en_espera'    => 'encrypted:array',
    ];

    /**
     * Lo que nunca sale al serializar la fila.
     *
     * La metadata de una foto en espera no le sirve a ninguna pantalla ni a ningún log, y
     * serializarla sería mostrar descifrada la URL que la columna guarda cifrada.
     *
     * @var array<int, string>
     */
    protected $hidden = ['media_en_espera'];

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
     * Las fotos de UN dueño que están esperando su instrucción.
     *
     * El dueño es el par `client_id` + `telefono` y no el cliente a secas: es la misma persona
     * mandando la foto y después el audio, y nada de otro teléfono puede llevársela. Sin filtro de
     * tiempo ni de orden a propósito: la vigencia y el "anteriores a" dependen de quién cierra
     * (un mensaje nuevo o el vencimiento de la espera), y los pone cada llamador.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int                                   $client_id Cliente dueño del hilo.
     * @param string                                $telefono  E.164 del dueño.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEnEsperaDelDueno($query, int $client_id, string $telefono)
    {
        return $query->where('client_id', $client_id)
            ->where('telefono', $telefono)
            ->where('direccion', self::DIRECCION_ENTRANTE)
            ->where('estado', self::ESTADO_ESPERANDO);
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
