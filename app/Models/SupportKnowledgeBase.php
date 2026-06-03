<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Entrada de la base de conocimiento de soporte para sugerencias de Claude.
 */
class SupportKnowledgeBase extends Model
{
    /**
     * Nombre de tabla en singular compuesto.
     *
     * @var string
     */
    protected $table = 'support_knowledge_base';

    /**
     * Campos asignables desde el ABM del admin.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'content',
        'is_active',
    ];

    /**
     * Casteos de tipos para persistencia y JSON.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];
}
