<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Almacena la conexión OAuth2 de Google Calendar de un admin closer.
 *
 * Las credenciales se mantienen separadas de la tabla admins porque tienen
 * ciclo de vida propio: se conectan, desconectan y pueden expirar o ser
 * revocadas por el usuario en cualquier momento.
 *
 * @property int         $admin_id
 * @property string      $google_refresh_token_encrypted
 * @property string      $google_calendar_id
 * @property string|null $google_account_email
 * @property bool        $is_active
 */
class AdminCalendarConnection extends Model
{
    protected $table = 'admin_calendar_connections';

    protected $guarded = [];

    /**
     * Campos que nunca se incluyen en la serialización JSON (tokens sensibles).
     */
    protected $hidden = ['google_refresh_token_encrypted'];

    protected $casts = [
        // Fecha de la primera conexión OAuth exitosa.
        'connected_at'   => 'datetime',
        // Última consulta exitosa a Google Calendar.
        'last_synced_at' => 'datetime',
        // Permite desactivar sin borrar el registro histórico.
        'is_active'      => 'boolean',
    ];

    /**
     * Admin propietario de esta conexión.
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Desencripta y devuelve el refresh token para uso interno del servicio.
     * Nunca llamar a este método en contextos donde el resultado pueda exponerse en JSON.
     *
     * @return string Refresh token en texto plano.
     */
    public function get_decrypted_refresh_token(): string
    {
        return Crypt::decryptString($this->google_refresh_token_encrypted);
    }
}
