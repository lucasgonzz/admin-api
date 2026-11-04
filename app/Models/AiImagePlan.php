<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiImagePlan extends Model
{
    protected $fillable = ['nombre', 'precio_ars', 'tope_usd_mensual', 'activo'];

    protected $casts = [
        'precio_ars' => 'decimal:2',
        'tope_usd_mensual' => 'decimal:2',
        'activo' => 'boolean',
    ];
}
