<?php

namespace App\Models;

use App\ModelProperties\ClientProperties;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Cliente (instancia empresa-api) registrado en admin-api.
 *
 * @property string|null $slug         Identificador legible opcional (único cuando está definido).
 * @property string|null $company_name Razón social; name suele reflejar el contacto cuando el lead crea el registro.
 * @property string|null $phone        Teléfono de contacto (formato libre; se normaliza al comparar en WhatsApp).
 * @property int|null     $user_id      Inicio de bloque ComercioCity (múltiplo de 100), alineado con el User en empresa-api.
 */
class Client extends Model
{
    use HasUuid;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return ClientProperties::all();
    }

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'user_id'   => 'integer',
    ];

    function scopeWithAll($query) {
        $query->with('current_version', 'active_client_api', 'client_apis', 'client_employees', 'implementation');
    }

    /**
     * Nombre legible del cliente para soporte (empresa o contacto principal).
     *
     * @return string
     */
    public function resolve_display_name()
    {
        $company_name = trim((string) ($this->company_name ?? ''));
        if ($company_name !== '') {
            return $company_name;
        }

        return trim((string) ($this->name ?? ''));
    }

    public function current_version() {
        return $this->belongsTo(Version::class, 'current_version_id');
    }

    public function upgrades() {
        return $this->hasMany(ClientVersionUpgrade::class)->orderBy('id', 'desc');
    }

    public function notification_reads() {
        return $this->hasMany(ClientNotificationRead::class);
    }

    public function client_apis() {
        return $this->hasMany(ClientApi::class);
    }

    public function active_client_api() {
        return $this->belongsTo(ClientApi::class, 'active_client_api_id');
    }

    /**
     * Empleados o contactos operativos del cliente (WhatsApp / soporte).
     */
    public function client_employees() {
        return $this->hasMany(ClientEmployee::class);
    }

    /**
     * Proceso de implementación guiada (como máximo uno por cliente).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function implementation() {
        return $this->hasOne(Implementation::class);
    }
}
