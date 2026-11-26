<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Código de confirmación de un envío de WhatsApp desde Dotin/ChatGPT (misión dotin-whatsapp-admin).
 *
 * Queda atado a admin, cliente OAuth, cuenta, teléfono, contenido y `op`. 🔴 Nunca guarda el texto del mensaje ni
 * el pedido literal de Lucas (sólo sus sha256) ni el código en claro (sólo su sha256).
 *
 * @property int                             $id
 * @property string                          $code_hash    sha256 (hex) del código.
 * @property int                             $admin_id
 * @property string                          $client_id
 * @property string                          $account
 * @property string                          $phone        E.164.
 * @property string                          $content_hash sha256 del contenido a enviar.
 * @property string                          $request_hash sha256 del pedido literal de Lucas.
 * @property string                          $op
 * @property \Illuminate\Support\Carbon      $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property int|null                        $result_status
 * @property string|null                     $result_code
 * @property int|null                        $message_id
 */
class McpWhatsappConfirmation extends Model
{
    /** @var string */
    protected $table = 'mcp_whatsapp_confirmations';

    /** @var array<int, string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'admin_id'      => 'integer',
        'result_status' => 'integer',
        'message_id'    => 'integer',
        'expires_at'    => 'datetime',
        'used_at'       => 'datetime',
    ];

    /**
     * Sin relaciones que cargar: placeholder para mantener consistencia con el resto de los modelos del admin.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}
