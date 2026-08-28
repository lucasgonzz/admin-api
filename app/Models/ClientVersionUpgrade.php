<?php

namespace App\Models;

use App\ModelProperties\ClientVersionUpgradeProperties;
use App\Models\Concerns\HasUuid;
use App\Services\VersionNumberComparator;
use Illuminate\Database\Eloquent\Model;

class ClientVersionUpgrade extends Model
{
    use HasUuid;

    /**
     * Valor de `created_via` para los upgrades creados por Claude (POST claude/upgrades).
     * La columna es nullable y sin default: NULL = origen no marcado (panel / admin-spa,
     * y todo lo anterior a la migración 2026_08_24_160200).
     */
    const CREATED_VIA_CLAUDE = 'claude';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return ClientVersionUpgradeProperties::all();
    }

    protected $guarded = [];

    /**
     * Un upgrade en `terminada` significa que el cliente YA ESTA en la version de destino, asi que
     * `clients.current_version_id` se alinea solo.
     *
     * Va en un hook del modelo y no en el controller a proposito: hasta ahora la unica que lo
     * escribia era PublishVersionService::syncExisting() -- el boton "sincronizar al cliente" --, y
     * los otros dos caminos que dejan un upgrade en `terminada` no lo tocaban:
     *
     *   1. El pipeline de deployment. step_complete() promovia active_client_api_id del cliente y
     *      dejaba la version sin mover (cliente Servian, upgrade 56 del 1/8/2026: deployment
     *      `completed` con los seis pasos hechos, y el cliente segui figurando en 3.3.1 con la
     *      3.3.3 arriba).
     *   2. La edicion a mano del select "Estado" en la grilla del admin-spa, que pasa por el
     *      update_json generico (cliente ananda, upgrade 72 del 24/8/2026).
     *
     * Cualquier save() que deje el status en `terminada` pasa por aca, asi que ningun camino nuevo
     * puede volver a dejar la version desalineada sin que nada avise.
     */
    protected static function boot()
    {
        parent::boot();

        static::saved(function ($upgrade) {

            if ($upgrade->status !== 'terminada') {
                return;
            }

            // wasChanged() cubre el update; wasRecentlyCreated, el alta directa en `terminada`.
            // Un save() que no movio el status no paga ninguna query.
            if (!$upgrade->wasRecentlyCreated && !$upgrade->wasChanged('status')) {
                return;
            }

            $upgrade->alinear_version_del_cliente();
        });
    }

    /**
     * Deja `clients.current_version_id` en la version de destino de este upgrade.
     *
     * 🔴 Nunca BAJA la version del cliente. Marcar `terminada` un upgrade viejo (a mano, o
     * reabriendo uno de hace meses) no puede retroceder a un cliente que ya subio por otro
     * camino: se compara por valor semantico con VersionNumberComparator, no por `id` de la fila,
     * porque con hotfixes de por medio el `id` no refleja el orden de las versiones.
     *
     * Se lee el cliente y la version de destino con una consulta propia y no por la relacion ya
     * cargada: este hook corre desde el pipeline de deployment, donde la instancia en memoria
     * puede venir de mucho antes.
     *
     * @return bool true si se escribio la version del cliente.
     */
    public function alinear_version_del_cliente()
    {
        /**
         * Hoy el esquema no permite ninguna de las dos (las columnas son NOT NULL y tienen FK a
         * `clients` y `versions`), asi que esto es defensa y no un caso conocido: la alineacion no
         * es motivo para tirar abajo un save() del pipeline de deployment si manana el esquema se
         * afloja o el dato llega por otro lado.
         */
        if (is_null($this->client_id) || is_null($this->to_version_id)) {
            return false;
        }

        $client = Client::find($this->client_id);
        $destino = Version::find($this->to_version_id);

        if (is_null($client) || is_null($destino)) {
            return false;
        }

        if (!is_null($client->current_version_id)) {

            if ((int) $client->current_version_id === (int) $destino->id) {
                return false;
            }

            $actual = Version::find($client->current_version_id);

            if (
                !is_null($actual)
                && VersionNumberComparator::compare($destino->version, $actual->version) <= 0
            ) {
                return false;
            }
        }

        $client->update(['current_version_id' => $destino->id]);

        return true;
    }

    protected $casts = [
        'scheduled_date'         => 'date:Y-m-d',
        'synced_at'              => 'datetime',
        'started_at'             => 'datetime',
        'finished_at'            => 'datetime',
        'sistema_actualizado_at' => 'datetime',
        'migraciones_corridas_at'=> 'datetime',
        'crons_supervisor_at'    => 'datetime',
        'seeders_ejecutados_at'  => 'datetime',
        'comandos_ejecutados_at' => 'datetime',
        'sistema_configurado_at' => 'datetime',
    ];

    function scopeWithAll($query) {
        $query->with([
            'client',
            'target_client_api',
            'from_version',
            'to_version',
            'created_by_admin',
            'deployment_logs' => function ($relation_query) {
                $relation_query->orderBy('created_at');
            },
            'update_seeders.version_seeder.version',
            'update_commands.version_command.version',
        ]);
    }

    public function client() {
        return $this->belongsTo(Client::class);
    }

    public function from_version() {
        return $this->belongsTo(Version::class, 'from_version_id');
    }

    public function to_version() {
        return $this->belongsTo(Version::class, 'to_version_id');
    }

    public function created_by_admin() {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function update_seeders() {
        return $this->hasMany(UpdateSeeder::class)->orderBy('id');
    }

    public function update_commands() {
        return $this->hasMany(UpdateCommand::class)->orderBy('id');
    }

    /**
     * Versiones confirmadas por el admin al crear este upgrade (pivot
     * `client_version_upgrade_versions`). Es la fuente de verdad del rango: reemplaza
     * el cálculo por `id` de `VersionPathService::versionsInRange()` (borrado).
     *
     * 🔴 A propósito NO está en `scopeWithAll()`: `withAll()` la usan 10 call sites
     * (`fullModel('update', $id)`, los *_json de UpdateController, UpdateSeederController,
     * UpdateCommandController y el `show` Blade) y ninguno de ellos la necesita. Cargarla
     * ahí engordaría los 10 payloads sin motivo. Se carga explícitamente con
     * `loadMissing('confirmed_versions')` solo en los 3 lugares que sí la usan
     * (`UpdateController::show`, `extra_data_json`, `PublishVersionService`). No la
     * sumes a `withAll()` sin releer este comentario primero.
     */
    public function confirmed_versions() {
        return $this->belongsToMany(
            Version::class,
            'client_version_upgrade_versions',
            'client_version_upgrade_id',
            'version_id'
        );
    }

    public function target_client_api() {
        return $this->belongsTo(ClientApi::class, 'target_client_api_id');
    }

    public function deployment_logs() {
        return $this->hasMany(DeploymentLog::class)->orderBy('id');
    }

    /**
     * Recalcula y persiste el status del upgrade en función del estado de sus seeders y comandos.
     * Si hay algún ítem fallido → fallida.
     * Si no hay fallidos y el status actual era fallida → actualizandose.
     */
    public function recalculate_status() {
        $this->loadMissing('update_seeders', 'update_commands');

        $has_failed = $this->update_seeders->contains('status', 'fallido')
                   || $this->update_commands->contains('status', 'fallido');

        if ($has_failed) {
            $this->update(['status' => 'fallida']);
        } elseif ($this->status === 'fallida') {
            $this->update(['status' => 'actualizandose']);
        }
    }
}
