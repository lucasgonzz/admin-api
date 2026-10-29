<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un pedido de imagen del agente del dueño (app `ia.comerciocity.com`), que genera la PC de Lucas con su
 * cuenta de ChatGPT (misión cruzada `app-ia-y-memoria`, 29/10/2026). Ciclo en `IaImagenesService`.
 *
 * @property int                 $id
 * @property int                 $client_id
 * @property string              $uuid
 * @property int|null            $ai_message_id
 * @property string              $prompt
 * @property string              $tamano          cuadrada | vertical | horizontal.
 * @property string|null         $estilo
 * @property string              $estado          pendiente | reservado | lista | fallida | entregada | entrega_fallida.
 * @property string|null         $resultado       lista | fallida: lo que se le entrega al cliente.
 * @property string|null         $worker_id
 * @property string|null         $lease_hash
 * @property \Carbon\Carbon|null $lease_expires_at
 * @property int                 $intentos
 * @property string|null         $imagen_path
 * @property string|null         $imagen_mime
 * @property string|null         $error
 * @property int                 $entrega_intentos
 * @property string|null         $entrega_error
 * @property \Carbon\Carbon|null $entregado_en
 */
class IaImagenPedido extends Model
{
    const ESTADO_PENDIENTE       = 'pendiente';
    const ESTADO_RESERVADO       = 'reservado';
    const ESTADO_LISTA           = 'lista';
    const ESTADO_FALLIDA         = 'fallida';
    const ESTADO_ENTREGADA       = 'entregada';
    const ESTADO_ENTREGA_FALLIDA = 'entrega_fallida';

    /** Los tres formatos del contrato C2.1. */
    const TAMANOS = ['cuadrada', 'vertical', 'horizontal'];

    protected $table = 'ia_imagen_pedidos';

    protected $guarded = [];

    protected $casts = [
        'client_id'        => 'integer',
        'ai_message_id'    => 'integer',
        'intentos'         => 'integer',
        'entrega_intentos' => 'integer',
        'lease_expires_at' => 'datetime',
        'entregado_en'     => 'datetime',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Si el token de reserva que mandó el worker es el de este pedido (sin mirar si venció).
     *
     * @param string|null $token Token en claro.
     *
     * @return bool
     */
    public function lease_coincide($token)
    {
        if (! is_string($token) || $token === '' || $this->lease_hash === null || $this->lease_hash === '') {
            return false;
        }

        return hash_equals((string) $this->lease_hash, hash('sha256', $token));
    }

    /**
     * Si la reserva sigue vigente.
     *
     * @return bool
     */
    public function lease_vigente()
    {
        return $this->lease_expires_at !== null && $this->lease_expires_at->isFuture();
    }
}
