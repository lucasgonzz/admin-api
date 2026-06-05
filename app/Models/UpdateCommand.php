<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class UpdateCommand extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'executed_at' => 'datetime',
        // Indica si el operador marcó este comando para ser omitido en el deployment.
        'skipped'     => 'boolean',
    ];

    function scopeWithAll($query) {
        $query->with('version_command');
    }

    public function client_version_upgrade() {
        return $this->belongsTo(ClientVersionUpgrade::class);
    }

    public function version_command() {
        return $this->belongsTo(VersionCommand::class);
    }
}
