<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportCodexUpgradeAgreement extends Model
{
    protected $guarded = [];
    protected $casts = ['accepted_at' => 'datetime'];

    public function operation()
    {
        return $this->belongsTo(SupportCodexOperation::class, 'support_codex_operation_id');
    }
}
