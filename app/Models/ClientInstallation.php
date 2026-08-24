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
     * Permite asignación masiva de todos los campos.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

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
