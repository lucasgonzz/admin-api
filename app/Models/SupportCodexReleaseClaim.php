<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportCodexReleaseClaim extends Model
{
    protected $guarded = [];
    protected $casts = ['develop' => 'array', 'proof' => 'array', 'published_at' => 'datetime', 'blocked_at' => 'datetime'];
}
