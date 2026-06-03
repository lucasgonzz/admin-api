<?php

namespace App\Models;

use App\ModelProperties\VersionProperties;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Version extends Model
{
    use HasUuid;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return VersionProperties::all();
    }

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    function scopeWithAll($query) {
        $query->with(
            'notifications.restrictedClients',
            'seeders.restrictedClients',
            'commands.restrictedClients',
            'manual_tasks.restrictedClients'
        );
    }

    public function notifications() {
        return $this->hasMany(VersionNotification::class)->orderBy('sort_order');
    }

    public function seeders() {
        return $this->hasMany(VersionSeeder::class)->orderBy('execution_order');
    }

    public function commands() {
        return $this->hasMany(VersionCommand::class)->orderBy('execution_order');
    }

    public function manual_tasks() {
        return $this->hasMany(VersionManualTask::class)->orderBy('execution_order');
    }

    public function upgrades() {
        return $this->hasMany(ClientVersionUpgrade::class, 'to_version_id');
    }
}
