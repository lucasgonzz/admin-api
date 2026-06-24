<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Variante de mensaje de onboarding para A/B testing (welcome, auto, etc.).
 *
 * Los contadores sent/responded/scheduled/attended se incrementan en tiempo real;
 * el agente analizador recalcula tasas a partir de ellos.
 */
class MessageVariant extends Model
{
    /**
     * Campos asignables desde el CRUD del módulo Agente.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casts de tipos para el modelo.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Elige aleatoriamente una variante activa del tipo indicado.
     *
     * @param string $message_type Tipo de mensaje (p. ej. welcome_with_name).
     *
     * @return self|null null si no hay variantes activas del tipo.
     */
    public static function pick_active_variant(string $message_type): ?self
    {
        /* Pool de variantes activas del tipo solicitado. */
        $variants = self::query()
            ->where('message_type', $message_type)
            ->where('active', true)
            ->get();

        if ($variants->isEmpty()) {
            return null;
        }

        return $variants->random();
    }

    /**
     * Incrementa el contador de envíos de esta variante.
     *
     * @return void
     */
    public function increment_sent(): void
    {
        $this->increment('sent_count');
    }

    /**
     * Incrementa el contador de leads que respondieron después del welcome.
     *
     * @return void
     */
    public function increment_responded(): void
    {
        $this->increment('responded_count');
    }

    /**
     * Incrementa el contador de leads que agendaron demo tras el welcome.
     *
     * @return void
     */
    public function increment_scheduled(): void
    {
        $this->increment('scheduled_count');
    }

    /**
     * Incrementa el contador de leads que confirmaron ingreso a demo.
     *
     * @return void
     */
    public function increment_attended(): void
    {
        $this->increment('attended_count');
    }
}
