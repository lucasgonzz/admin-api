<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportCodexScheduledUpgrade extends Model
{
    public const SCHEDULED = 'scheduled';
    public const STARTING = 'starting';
    public const POST_CLOSURE = 'post_closure';
    public const CONFIGURING = 'configuring';
    public const VERIFICATION_PENDING = 'verification_pending';
    public const VERIFIED = 'verified';
    public const BLOCKED = 'blocked';
    public const FAILED = 'failed';

    public const EXECUTING = [self::STARTING, self::POST_CLOSURE, self::CONFIGURING];

    protected $guarded = [];
    protected $casts = ['artifacts' => 'array'];

    public function agreement()
    {
        return $this->belongsTo(SupportCodexUpgradeAgreement::class, 'agreement_id');
    }

    public function upgrade()
    {
        return $this->belongsTo(ClientVersionUpgrade::class, 'upgrade_id');
    }
}
