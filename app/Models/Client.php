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
        /* Factura de mensualidad a Consumidor Final, sin CUIT (misión mensualidad-consumidor-final,
         * 13/10/2026). Booleana y no leída cruda porque `AfipFacturacionService::resolver_receptor()`
         * decide con un `if` sobre esta columna, y un "0" string es verdadero en PHP. */
        'facturar_consumidor_final' => 'boolean',
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
        /* Modalidad del contrato (misión precios-dos-modalidades, 13/10/2026): los mismos casts
         * que en `Lead`, por el mismo motivo que el resto del bloque. `contract_modalidad` es texto
         * y no lleva cast. */
        'contract_sistemas'                  => 'array',
        'contract_permanencia_meses'         => 'integer',
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
        'frente_principal_client_api_id',
    ];

    /**
     * Modo de un despliegue cuya API destino ES la activa: se reemplaza el código que está
     * atendiendo (misión `un-solo-frente`, 14/10/2026). Ver modo_de_despliegue_hacia().
     *
     * @var string
     */
    const MODO_EN_EL_LUGAR = 'en_el_lugar';

    /**
     * Modo de un despliegue cuya API destino NO es la activa: se sube a la otra carpeta y al final
     * se rota. Incluye la última rotación de un cliente que hoy atiende desde su frente 2 hacia el
     * principal.
     *
     * @var string
     */
    const MODO_ROTACION = 'rotacion';

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
     * Id de la ClientApi que es el FRENTE PRINCIPAL del cliente (accessor:
     * frente_principal_client_api_id), o null.
     *
     * 🔴 Devuelve null si `client_apis` NO está cargada, y es a propósito: este accessor viaja en
     * `$appends`, o sea en TODA serialización de un cliente —el listado completo del panel, los
     * clientes anidados en upgrades, instalaciones y tiendas—. Si cargara la relación por su cuenta
     * sería una consulta por cliente serializado. Los que necesitan el dato (la ficha del cliente
     * que lee el formulario de actualizaciones, vía scopeWithAll()) ya traen la relación cargada, y
     * ahí sale gratis. El que necesite el frente principal SIEMPRE usa
     * resolver_frente_principal_client_api_id(), que sí carga lo que falta.
     *
     * Campo nuevo y opcional del contrato con admin-spa (misión `un-solo-frente`, 14/10/2026): un SPA
     * viejo lo ignora, y uno nuevo sin el campo cae al comportamiento de siempre.
     *
     * @return int|null
     */
    public function getFrentePrincipalClientApiIdAttribute()
    {
        if (! $this->relationLoaded('client_apis')) {
            return null;
        }

        return self::frente_principal_entre($this->client_apis);
    }

    /**
     * Id del frente principal del cliente, cargando `client_apis` si hace falta. Null si el cliente
     * no tiene ninguna ClientApi.
     *
     * Es la puerta de entrada a la regla para todo el que no esté serializando: el destino por
     * defecto de una actualización (ClientVersionUpgradeCreationService), el freno del
     * `deploy/start` y las respuestas de `claude/upgrades*`.
     *
     * @return int|null
     */
    public function resolver_frente_principal_client_api_id()
    {
        $this->loadMissing('client_apis');

        return self::frente_principal_entre($this->client_apis);
    }

    /**
     * 🔴 LA REGLA DEL FRENTE PRINCIPAL, Y ES LA ÚNICA COPIA QUE HAY EN EL ADMIN (misión
     * `un-solo-frente`, 14/10/2026).
     *
     * Decisión de Lucas del 14/10/2026: se deja de rotar entre las dos carpetas de cada cliente. Un
     * cliente que atiende desde su frente 1 se actualiza ahí mismo, en el lugar; uno que atiende
     * desde el 2 se lleva al 1 una última vez. Para eso hace falta saber cuál de las dos ClientApi es
     * "el 1", y la base no lo dice en ningún lado: hay que deducirlo.
     *
     * La regla, medida contra el admin de producción el 14/10/2026 (53 clientes, 115 ClientApi):
     *
     *  1. Si hay una sola ClientApi, esa.
     *  2. La que tiene el nombre de carpeta que es PREFIJO ESTRICTO del de la otra: `fenix` /
     *     `fenix-prueba`, `golonorte` / `golonorte2`, `innovate` / `innovate-2`, `demo2` / `demo22`.
     *     El nombre de carpeta es `vps_path` en el VPS y `path` sin el `/api` final en el shared
     *     (nombre_de_carpeta_del_frente()).
     *  3. Si el prefijo no decide (`colman-beta` / `lucasgonzalez2`), la de MENOR id.
     *
     * 🔴 "La de menor id" sola NO sirve, y por eso es el último recurso y no la regla: en golonorte
     * (cliente 15, VPS) la 73 es `golonorte2` y la 74 es `golonorte`. Con menor id a secas, el
     * "frente 1" de ese cliente sería su carpeta 2.
     *
     * Con más de dos ClientApi (quedan altas duplicadas viejas) la regla 2 se generaliza así: el
     * principal es la ÚNICA cuyo nombre es prefijo estricto de algún otro y que no tiene, a su vez,
     * otro nombre como prefijo estricto. Si hay cero o más de una así, decide la regla 3. Con dos
     * ClientApi es exactamente la regla del plan.
     *
     * Es estática y pura (entra una colección, sale un id) para poder probarla sin base y para que
     * el accessor la use sobre la relación ya cargada sin disparar ninguna consulta.
     *
     * @param  iterable  $client_apis  ClientApi del cliente (colección o array).
     * @return int|null  Null si no hay ninguna.
     */
    public static function frente_principal_entre($client_apis)
    {
        $nombres = [];
        foreach ($client_apis as $client_api) {
            if ($client_api === null || $client_api->id === null) {
                continue;
            }
            $nombres[(int) $client_api->id] = self::nombre_de_carpeta_del_frente($client_api);
        }

        if (count($nombres) === 0) {
            return null;
        }

        if (count($nombres) === 1) {
            $ids = array_keys($nombres);

            return (int) $ids[0];
        }

        $candidatos = [];
        foreach ($nombres as $id => $nombre) {
            if ($nombre === '') {
                continue;
            }

            $es_prefijo_de_otro = false;
            $tiene_un_prefijo   = false;
            foreach ($nombres as $otro_id => $otro) {
                if ($otro_id === $id || $otro === '') {
                    continue;
                }
                if (self::es_prefijo_estricto($nombre, $otro)) {
                    $es_prefijo_de_otro = true;
                }
                if (self::es_prefijo_estricto($otro, $nombre)) {
                    $tiene_un_prefijo = true;
                }
            }

            if ($es_prefijo_de_otro && ! $tiene_un_prefijo) {
                $candidatos[] = (int) $id;
            }
        }

        if (count($candidatos) === 1) {
            return $candidatos[0];
        }

        return (int) min(array_keys($nombres));
    }

    /**
     * Nombre de la carpeta de un frente, tal como lo compara frente_principal_entre().
     *
     * - VPS: `vps_path` (es el nombre del sitio de CloudPanel: `golonorte`, `golonorte2`). El `path`
     *   de una ClientApi de VPS puede venir vacío o con un resto del shared, y no se mira.
     * - shared_hosting: `path` sin barras de los bordes y sin el `/api` final (`colman/api` →
     *   `colman`).
     *
     * En minúsculas, para que una diferencia de mayúsculas cargada a mano no rompa el prefijo.
     *
     * @param  ClientApi  $client_api
     * @return string  '' si no hay nada cargado.
     */
    public static function nombre_de_carpeta_del_frente($client_api)
    {
        $hosting_type = $client_api->hosting_type ? (string) $client_api->hosting_type : 'shared_hosting';

        if ($hosting_type === 'vps') {
            return mb_strtolower(trim((string) $client_api->vps_path, "/ \t"));
        }

        $path = trim((string) $client_api->path, "/ \t");
        $path = (string) preg_replace('#/api$#i', '', $path);

        return mb_strtolower(trim($path, "/ \t"));
    }

    /**
     * ¿$corto es prefijo ESTRICTO de $largo? (`golonorte` de `golonorte2` sí; de sí mismo, no).
     *
     * @param  string  $corto
     * @param  string  $largo
     * @return bool
     */
    private static function es_prefijo_estricto($corto, $largo)
    {
        return strlen($corto) < strlen($largo) && strpos($largo, $corto) === 0;
    }

    /**
     * Advertencia cuando la API destino no es ni el frente principal ni la activa: una rotación
     * hacia el frente secundario (misión `un-solo-frente`, 14/10/2026). Null en cualquier otro caso.
     *
     * No frena nada: es un campo aditivo de las respuestas de `claude/upgrades*` (preview, creación
     * en seco y real, start). Desde la decisión del 14/10 lo normal es el principal (en el lugar, o
     * la última rotación hacia él); mandar al 2 a un cliente que atiende desde el 1 es justo lo que
     * la decisión vino a dejar de hacer, y tiene que verse aunque se permita.
     *
     * @param  int|string|null  $target_client_api_id
     * @return string|null
     */
    public function advertencia_de_frente_secundario($target_client_api_id)
    {
        if ($target_client_api_id === null || $target_client_api_id === '') {
            return null;
        }

        $principal_id = $this->resolver_frente_principal_client_api_id();
        $target_id    = (int) $target_client_api_id;

        if ($principal_id === null || $target_id === $principal_id) {
            return null;
        }

        if ($this->active_client_api_id !== null && $target_id === (int) $this->active_client_api_id) {
            return null;
        }

        return 'La API destino (' . $target_id . ') no es el frente principal del cliente (' . $principal_id . ') ni la '
            . 'que está atendiendo: es una rotación hacia el frente secundario, contra la decisión del 14/10/2026 de '
            . 'actualizar siempre sobre el frente principal. Lo que corresponde es target_client_api_id=' . $principal_id
            . '. No frena nada.';
    }

    /**
     * Modo de un despliegue de este cliente hacia una ClientApi: `en_el_lugar` si es la ACTIVA,
     * `rotacion` si no (misión `un-solo-frente`, 14/10/2026).
     *
     * Un cliente sin `active_client_api_id` cae en `rotacion`: no hay ninguna carpeta que se sepa
     * que está atendiendo, así que no hay código vivo que pisar.
     *
     * @param  int|string|null  $target_client_api_id
     * @return string|null  Null si no hay API destino.
     */
    public function modo_de_despliegue_hacia($target_client_api_id)
    {
        if ($target_client_api_id === null || $target_client_api_id === '') {
            return null;
        }

        if ($this->active_client_api_id !== null
            && (int) $target_client_api_id === (int) $this->active_client_api_id) {
            return self::MODO_EN_EL_LUGAR;
        }

        return self::MODO_ROTACION;
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

    /**
     * Oportunidades del CRM de pipelines donde este cliente es el sujeto (misión pipelines-crm,
     * 27/9/2026), abiertas y cerradas, de todos los pipelines.
     *
     * 🔴 A propósito NO se suma a scopeWithAll(), con el mismo criterio que `schedule_days` y las
     * relaciones de Cobranzas: la pestaña "Pipelines" de la ficha tiene su propio endpoint
     * (`GET pipeline-opportunities?client_id=`), y el listado de todos los clientes no tiene por
     * qué cargarlas.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function pipeline_opportunities()
    {
        return $this->hasMany(PipelineOpportunity::class, 'client_id');
    }
}
