<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Instalación inicial de sistema para un cliente.
 *
 * Registra el pipeline completo de instalación desde cero: compilación de SPA,
 * subida de API, escritura del .env y ejecución del user-setup inicial.
 * Difiere de ClientVersionUpgrade en que no parte de una versión anterior
 * ni ejecuta migraciones/seeders de actualización.
 *
 * @property int         $id
 * @property string      $uuid
 * @property int         $client_id
 * @property int|null    $client_api_id
 * @property int|null    $version_id
 * @property string      $kind              completa | esqueleto
 * @property string|null $group_uuid        UUID del par de filas que se crearon y se inician juntas
 * @property string|null $provision_hosting_type  null = no aprovisionar | shared_hosting | vps
 * @property string      $status            pendiente | instalando | completada | fallida
 * @property array|null  $env_manual_values Valores de variables is_manual_on_create
 * @property string|null $failure_reason
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 */
class ClientInstallation extends Model
{
    use HasUuid;

    /**
     * Instalación real: corre el pipeline completo (compila el SPA, sube la API, escribe el .env
     * y finaliza con artisan).
     *
     * @var string
     */
    const KIND_COMPLETA = 'completa';

    /**
     * Esqueleto: deja el subdominio con lo mínimo que el upgrade NO repone por su cuenta
     * (directorios, public/, el symlink de storage y el .env). No sube el código de la API
     * ni compila el SPA.
     *
     * @var string
     */
    const KIND_ESQUELETO = 'esqueleto';

    /**
     * Constantes de clase y no un enum de PHP: admin-api corre PHP 7.4 en producción.
     *
     * @var array<int, string>
     */
    const KINDS = [self::KIND_COMPLETA, self::KIND_ESQUELETO];

    /**
     * Aprovisionar el hosting compartido de Hostinger antes de instalar: los 4 subdominios, la base
     * de datos y el cron, todo por la API de developers.hostinger.com.
     *
     * @var string
     */
    const PROVISION_SHARED_HOSTING = 'shared_hosting';

    /**
     * Aprovisionar el VPS propio antes de instalar: los 4 sitios de CloudPanel, los A records, la
     * base, el cron y el certificado.
     *
     * @var string
     */
    const PROVISION_VPS = 'vps';

    /**
     * Valores válidos de provision_hosting_type. La AUSENCIA de valor (null) también es válida y es
     * el default: significa "no aprovisiones nada", que es el comportamiento de siempre.
     *
     * @var array<int, string>
     */
    const PROVISION_HOSTING_TYPES = [self::PROVISION_SHARED_HOSTING, self::PROVISION_VPS];

    /**
     * Las tres claves del .env que el aprovisionamiento completa solo.
     *
     * 🔴 Existen como constante porque hay CUATRO lugares que tienen que estar de acuerdo sobre
     * cuáles son, y desalinearlos deja el sistema trabado sin decir por qué:
     *
     * 1. start(), que hoy exige que TODA variable is_manual_on_create tenga valor antes de
     *    despachar. Sin exceptuar estas tres, con el aprovisionamiento tildado el operador nunca
     *    podría apretar "Iniciar": el botón queda gris esperando un valor que va a existir recién
     *    quince minutos después, adentro del pipeline.
     * 2. step_write_env(), que las lee de las credenciales generadas en vez de env_manual_values.
     * 3. El modal de gestión, que las muestra deshabilitadas.
     * 4. all_manual_values_filled() del SPA, espejo exacto de (1).
     *
     * @var array<int, string>
     */
    const CLAVES_ENV_APROVISIONADAS = ['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    /**
     * Permite asignación masiva de todos los campos.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Valor por defecto de kind en toda instancia nueva.
     *
     * 🔴 Repite el default de la migracion a proposito. Sin esto, ClientInstallation::create()
     * devuelve un modelo SIN el atributo kind —Eloquent no relee lo que puso la base—, y la
     * respuesta 201 de store() sale sin la clave: el SPA no tiene con que pintar el badge de la
     * fila que acaba de crear, aunque al recargar el listado aparezca bien.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => self::KIND_COMPLETA,
    ];

    /**
     * Conversiones de tipos para campos de fecha y JSON.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
        'env_manual_values' => 'array',
    ];

    /**
     * Carga todas las relaciones necesarias para mostrar una instalación completa.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with([
            'client',
            'client_api',
            'version',
            'deployment_logs' => function ($relation_query) {
                $relation_query->orderBy('created_at');
            },
        ]);
    }

    /**
     * Filas hermanas de un mismo grupo: las que se crearon juntas y se inician juntas.
     *
     * No ordena: el orden lo pone sort_real_first(), abajo.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $group_uuid
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfGroup($query, $group_uuid)
    {
        return $query->where('group_uuid', $group_uuid);
    }

    /**
     * Ordena las filas de un grupo con la instalación real SIEMPRE primero.
     *
     * El orden importa de verdad: la real es la larga y es la que el operador mira en el log en
     * vivo, y el esqueleto sobre el subdominio hermano se corre después.
     *
     * 🔴 Se ordena en PHP y NO con un orderBy('kind') en SQL. Que 'completa' venga antes que
     * 'esqueleto' alfabéticamente es un accidente del castellano, no un contrato: renombrar un
     * valor de kind (o traducirlo) daría vuelta el pipeline en silencio.
     *
     * @param  Collection  $rows
     * @return Collection
     */
    public static function sort_real_first(Collection $rows): Collection
    {
        $sorted = $rows->values()->all();

        // usort no es estable en PHP 7.4, así que el desempate se hace explícito por id para que
        // dos filas del mismo kind salgan siempre en el mismo orden.
        usort($sorted, function ($a, $b) {
            $a_es_completa = $a->kind === self::KIND_COMPLETA ? 0 : 1;
            $b_es_completa = $b->kind === self::KIND_COMPLETA ? 0 : 1;

            if ($a_es_completa !== $b_es_completa) {
                return $a_es_completa - $b_es_completa;
            }

            return (int) $a->id - (int) $b->id;
        });

        return new Collection($sorted);
    }

    /**
     * Cliente al que pertenece esta instalación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * API del cliente donde se instalará el sistema.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client_api()
    {
        return $this->belongsTo(ClientApi::class);
    }

    /**
     * Versión inicial a instalar.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function version()
    {
        return $this->belongsTo(Version::class);
    }

    /**
     * Líneas de log del proceso de instalación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function deployment_logs()
    {
        return $this->hasMany(DeploymentLog::class)->orderBy('id');
    }
}
