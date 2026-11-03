<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Evidencia de una corrección integrada, ligada al diagnóstico y al hilo vigentes. */
class SupportCodexMissionFixEvidence extends Model
{
    protected $table = 'support_codex_mission_fix_evidence';
    protected $guarded = [];
    protected $casts = ['payload' => 'array'];
}
