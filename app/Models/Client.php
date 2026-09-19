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
        // Interruptor del asistente por WhatsApp (misión asistente-por-whatsapp, 16/9/2026).
        // Casteado a bool y no leído crudo porque el ruteo del webhook decide con un `if` sobre
        // esta columna, y un "0" string es verdadero en PHP.
        'asistente_whatsapp_activo' => 'boolean',
        /* Candado de sesión por pestaña (misión candado-sesion-por-pestana, 19/9/2026): booleana
         * por el mismo motivo que is_active/asistente_whatsapp_activo (un "0" string es verdadero
         * en PHP). `pestanas_synced_at` NO lleva cast a propósito, igual que `schedule_synced_at`:
         * viaja como string crudo de MySQL, no como Carbon. */
        'bloquear_pestanas_duplicadas' => 'boolean',
        /* Última recolección EXITOSA del consumo de tokens de IA (misión tokens-por-cliente,
         * 17/9/2026). Casteada a datetime y no leída cruda porque la pestaña muestra "Traído el …"
         * y el front necesita una fecha con la que pueda hacer cuentas, no el string de MySQL.
         * Las otras dos columnas del trío (`ai_tokens_sync_status`, `ai_tokens_sync_message`) son
         * texto y no necesitan cast, igual que las de `schedule_sync_*`. */
        'ai_tokens_synced_at'       => 'datetime',
        // Paquete de IA asignado y última recolección de su push (misión foto-sucursal-y-asistente-
        // configurable, 17/9/2026). ai_plan_id casteado a int para comparar sin sorpresas; el
        // datetime del push por el mismo motivo que ai_tokens_synced_at (la ficha muestra "Enviado
        // el …"). ai_plan_sync_status/_message son texto y no necesitan cast.
        'ai_plan_id'                => 'integer',
        'ai_plan_synced_at'         => 'datetime',
        /* Contrato del cliente (misión modulo-cobranzas, 18/9/2026): los mismos casts que tiene
         * `Lead` para las mismas columnas, porque `LeadContractPdfService` lee los atributos por
         * nombre en los dos modelos y espera lo mismo de cada uno (arrays ya decodificados, fechas
         * como Carbon). `contract_meses_actualizacion` a int porque se hacen cuentas con él. */
        'contract_financiacion'              => 'array',
        'contract_clausulas_particulares'    => 'array',
        'contract_fecha_emision'             => 'date',
        'contract_fecha_primer_pago_unico'   => 'date',
        'contract_fecha_primer_pago_mensual' => 'date',
        'contract_meses_actualizacion'       => 'integer',
        'contract_copiado_desde_lead_at'     => 'datetime',
        // Primer mes que se cobra la mensualidad (siempre el día 1). Nulo = todavía no arrancó.
        'mensualidad_inicio'                 => 'date',
    ];

    /**
     * Atributos calculados (accessors) que viajan serializados junto a las
     * columnas de la tabla. Resuelven contra la relación `client_ecommerce`
     * ya cargada por scopeWithAll(), sin disparar consultas extra.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'ecommerce_spa_url',
        'ecommerce_api_url',
        'ecommerce_spa_path',
        'ecommerce_api_path',
    ];

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
     * Path de instalación del SPA de la tienda CARGADO A MANO (accessor: ecommerce_spa_path).
     *
     * Devuelve vacío cuando el path guardado es el derivado del dominio, no el que alguien cargó
     * a mano (ver ClientEcommerce::manual_spa_path()). Es lo que hace que el campo del modal
     * signifique "vacío = derivar solo" y que los 40 clientes que ya existen no lo vean
     * pre-cargado con una ruta que en realidad nadie escribió.
     *
     * @return string  Vacío si el cliente todavía no tiene ClientEcommerce.
     */
    public function getEcommerceSpaPathAttribute()
    {
        return $this->client_ecommerce ? $this->client_ecommerce->manual_spa_path() : '';
    }

    /**
     * Path de instalación de la API de la tienda CARGADO A MANO (accessor: ecommerce_api_path).
     *
     * Mismo criterio que getEcommerceSpaPathAttribute().
     *
     * @return string  Vacío si el cliente todavía no tiene ClientEcommerce.
     */
    public function getEcommerceApiPathAttribute()
    {
        return $this->client_ecommerce ? $this->client_ecommerce->manual_api_path() : '';
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
     * Días del horario comercial del cliente (uno por día cargado, incluido 'todos').
     *
     * 🔴 A propósito NO se suma a scopeWithAll(): ese scope lo usa index_json() para listar TODOS
     * los clientes y sumarle una relación más engorda ese payload sin que nadie lo pida. La
     * interfaz de horarios es una pestaña con su propio fetch, y los endpoints que necesitan los
     * horarios cargan la relación explícitamente.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function schedule_days() {
        return $this->hasMany(ClientScheduleDay::class);
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

    /**
     * Paquete de IA asignado a este cliente (misión foto-sucursal-y-asistente-configurable,
     * 17/9/2026). Null cuando todavía no tiene ninguno (que significa "sin tope": no corta).
     *
     * 🔴 A propósito NO se suma a scopeWithAll(): el paquete se lee en la ficha de un cliente y en
     * el push, no en el listado de todos, y sumar una relación más engorda ese payload sin que nadie
     * lo pida. El id (`ai_plan_id`) sí viaja siempre porque es una columna; quien necesite el nombre
     * y los topes cruza contra el catálogo (`ai-plan`) o carga la relación explícitamente.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function ai_plan()
    {
        return $this->belongsTo(AiPlan::class, 'ai_plan_id');
    }

    /*
     * Cobranzas (misión modulo-cobranzas, 18/9/2026). Las cuatro relaciones de abajo
     * 🔴 a propósito NO se suman a scopeWithAll(), con el mismo criterio que `schedule_days` y
     * `ai_plan`: ese scope lo usa index_json() para listar TODOS los clientes, y el historial de
     * pagos y precios de cada uno engordaría ese payload sin que nadie lo pida. El módulo de
     * Cobranzas y las pestañas del cliente tienen sus propios endpoints, que cargan lo que
     * necesitan en consultas acotadas.
     */

    /**
     * Historial de actualizaciones de precio de la mensualidad, la más reciente primero.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function mensualidad_actualizaciones()
    {
        return $this->hasMany(MensualidadActualizacion::class)->orderByDesc('fecha')->orderByDesc('id');
    }

    /**
     * Meses de mensualidad con estado afirmado (los que tienen fila; el resto se deduce).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function mensualidad_periodos()
    {
        return $this->hasMany(MensualidadPeriodo::class);
    }

    /**
     * Pagos recibidos por la mensualidad.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function mensualidad_pagos()
    {
        return $this->hasMany(MensualidadPago::class);
    }

    /**
     * Cuotas de la licencia (el pago único del contrato), por número.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function licencia_cuotas()
    {
        return $this->hasMany(LicenciaCuota::class)->orderBy('numero')->orderBy('id');
    }
}
