<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Models\UserIdBlock;
use Illuminate\Support\Facades\DB;

/**
 * Servicio para calcular y reservar bloques de user_id de 100 en 100.
 *
 * Política:
 * - user_id base por sistema debe ser múltiplo de 100.
 * - no puede repetirse entre sistemas.
 * - se sugiere el siguiente bloque libre en base al mayor user_id ya asignado a un cliente.
 */
class UserIdBlockAllocatorService
{
    /**
     * Calcula el siguiente block_start sugerido.
     * Ancla en el máximo user_id ya asignado a un cliente real (fuente de verdad de
     * los bloques en uso) y avanza al siguiente múltiplo de 100 libre.
     *
     * @return int
     */
    public function suggest_next_block_start()
    {
        // Ancla = el user_id más alto YA asignado a un cliente real. El próximo bloque es ese + 100.
        // (Antes se tomaba el máximo entre leads, clients y bloques reservados; un lead con un
        // user_id numérico basura — ej. un teléfono guardado por error — disparaba el valor a
        // decenas de miles y todos los clientes nuevos heredaban ese arrastre.)
        $max_client_user_id = $this->get_max_client_user_id();
        $candidate = $this->next_multiple_of_100($max_client_user_id + 1);

        // Salvaguarda de colisión: si ese bloque ya está reservado (ventana de creación en
        // paralelo de otro lead), avanzar de 100 en 100 hasta uno libre.
        while ($this->is_block_reserved($candidate)) {
            $candidate += 100;
        }

        return $candidate;
    }

    /**
     * Valida que el user_id sea múltiplo de 100 y que el bloque esté libre
     * (o reservado por el mismo lead en edición).
     *
     * @param int      $user_id
     * @param int|null $current_lead_id
     *
     * @return string|null Mensaje de error si falla, null si está ok
     */
    public function validate_user_id_block($user_id, $current_lead_id = null)
    {
        if (!is_numeric($user_id)) {
            return 'El user_id debe ser numérico.';
        }

        $normalized_user_id = (int) $user_id;
        if ($normalized_user_id <= 0) {
            return 'El user_id debe ser mayor a 0.';
        }

        if ($normalized_user_id % 100 !== 0) {
            return 'El user_id debe ser múltiplo de 100.';
        }

        $block_start = $this->block_start_from_user_id($normalized_user_id);

        $query = UserIdBlock::where('block_start', $block_start);
        if (!is_null($current_lead_id)) {
            $query->where(function ($q) use ($current_lead_id) {
                $q->whereNull('lead_id')
                  ->orWhere('lead_id', '!=', $current_lead_id);
            });
        }

        if ($query->exists()) {
            return 'El bloque de user_id ' . $block_start . '-' . ($block_start + 99) . ' ya está reservado.';
        }

        return null;
    }

    /**
     * Reserva o actualiza la reserva del bloque para un lead.
     * Si el lead ya tenía otro bloque reservado, lo libera.
     *
     * @param Lead   $lead
     * @param int    $user_id
     * @param string $source
     * @param string|null $notes
     */
    public function reserve_block_for_lead(Lead $lead, $user_id, $source = 'lead_create', $notes = null)
    {
        $block_start = $this->block_start_from_user_id((int) $user_id);

        DB::transaction(function () use ($lead, $block_start, $source, $notes) {
            // Liberar reservas previas del lead en otros bloques
            UserIdBlock::where('lead_id', $lead->id)
                ->where('block_start', '!=', $block_start)
                ->delete();

            // Reservar o actualizar el bloque actual
            UserIdBlock::updateOrCreate(
                ['block_start' => $block_start],
                [
                    'source'   => $source,
                    'lead_id'  => $lead->id,
                    'notes'    => $notes,
                ]
            );
        });
    }

    /**
     * Asocia el bloque reservado de un lead al client promovido.
     *
     * @param int $lead_id
     * @param int $client_id
     */
    public function attach_client_to_lead_block($lead_id, $client_id)
    {
        UserIdBlock::where('lead_id', $lead_id)->update(['client_id' => $client_id]);
    }

    /**
     * Mayor user_id (bloque ComercioCity) ya asignado a un cliente.
     *
     * @return int
     */
    private function get_max_client_user_id()
    {
        return (int) (Client::query()
            ->whereNotNull('user_id')
            ->max('user_id') ?? 0);
    }

    /**
     * Redondea hacia arriba al siguiente múltiplo de 100.
     *
     * @param int $value
     *
     * @return int
     */
    private function next_multiple_of_100($value)
    {
        return (int) (ceil($value / 100) * 100);
    }

    /**
     * @param int $user_id
     *
     * @return int
     */
    private function block_start_from_user_id($user_id)
    {
        return (int) (floor($user_id / 100) * 100);
    }

    /**
     * @param int $block_start
     *
     * @return bool
     */
    private function is_block_reserved($block_start)
    {
        return UserIdBlock::where('block_start', $block_start)->exists();
    }
}
