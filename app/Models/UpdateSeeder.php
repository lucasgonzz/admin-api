<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class UpdateSeeder extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'executed_at' => 'datetime',
        // Indica si el operador marcó este seeder para ser omitido en el deployment.
        'skipped'     => 'boolean',
    ];

    function scopeWithAll($query) {
        $query->with('version_seeder');
    }

    public function client_version_upgrade() {
        return $this->belongsTo(ClientVersionUpgrade::class);
    }

    public function version_seeder() {
        return $this->belongsTo(VersionSeeder::class);
    }
}
