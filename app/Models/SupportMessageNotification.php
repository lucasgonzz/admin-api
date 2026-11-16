<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SupportMessageNotification extends Model
{
    protected $guarded = [];
    protected $casts = ['accepted_endpoint_hashes' => 'array', 'processing_until' => 'datetime', 'notified_at' => 'datetime'];
}
