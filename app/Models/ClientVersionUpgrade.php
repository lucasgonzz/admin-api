<?php

namespace App\Models;

use App\ModelProperties\ClientVersionUpgradeProperties;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ClientVersionUpgrade extends Model
{
    use HasUuid;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return ClientVersionUpgradeProperties::all();
    }

    protected $guarded = [];

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
