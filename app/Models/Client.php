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
        'is_active'                => 'boolean',
        'user_id'                  => 'integer',
        'shared_database_group_id' => 'integer',
        // Datos de configuración recolectados en la Etapa 1 de implementación (para UserSetup).
        'setup_data'               => 'array',
        // Mensualidad (gestionada en admin, prompt 328/329): inputs manuales + total calculado.
        'cantidad_empleados'       => 'integer',
        'tiene_ecommerce'          => 'boolean',
        'tiene_mercado_libre'      => 'boolean',
        'tiene_tienda_nube'        => 'boolean',
        'precio_plan'              => 'decimal:2',
        'precio_por_cuenta'        => 'decimal:2',
        'precio_ecommerce'         => 'decimal:2',
        'precio_mercado_libre'     => 'decimal:2',
        'precio_tienda_nube'       => 'decimal:2',
        'total_mensualidad'        => 'decimal:2',
        'payment_expired_at'       => 'date',
    ];

    /**
     * Atributos calculados (accessors) que viajan serializados junto a las
     * columnas de la tabla. Resuelven contra la relación `client_ecommerce`
     * ya cargada por scopeWithAll(), sin disparar consultas extra.
     *
     * @var array<int, string>
     */
    protected $appends = ['ecommerce_spa_url', 'ecommerce_api_url'];

    function scopeWithAll($query) {
        // Se agrega 'client_ecommerce' (eager load) para que los accessors
        // ecommerce_spa_url / ecommerce_api_url no disparen una consulta
        // extra por cliente al listar (index_json) o mostrar (show_json).
        $query->with(
            'current_version',
            'active_client_api',
            'client_apis',
            'client_employees',
            'implementation',
            'shared_database_group',
            'client_ecommerce'
        );
    }

    /**
     * URL del SPA de la tienda online del cliente (accessor: ecommerce_spa_url).
     *
     * @return string  Vacío si el cliente todavía no tiene ClientEcommerce.
     */
    public function getEcommerceSpaUrlAttribute()
    {
        return $this->client_ecommerce ? (string) ($this->client_ecommerce->spa_url ?? '') : '';
    }

    /**
     * URL de la API de la tienda online del cliente (accessor: ecommerce_api_url).
     *
     * @return string  Vacío si el cliente todavía no tiene ClientEcommerce.
     */
    public function getEcommerceApiUrlAttribute()
    {
        return $this->client_ecommerce ? (string) ($this->client_ecommerce->api_url ?? '') : '';
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

    /**
     * Tienda online (ecommerce) del cliente (como máximo una).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function client_ecommerce() {
        return $this->hasOne(ClientEcommerce::class);
    }

    /**
     * Proceso de implementación de la tienda online (como máximo uno por cliente).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function ecommerce_implementation() {
        return $this->hasOne(EcommerceImplementation::class);
    }

    /**
     * Grupo de base de datos compartida al que pertenece este cliente (si aplica).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function shared_database_group()
    {
        return $this->belongsTo(SharedDatabaseGroup::class, 'shared_database_group_id');
    }
}
