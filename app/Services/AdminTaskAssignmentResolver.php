<?php

namespace App\Services;

use App\Models\Admin;

/**
 * Resuelve reglas de asignación de tareas de admin reutilizables entre
 * distintos orígenes de creación de AdminTask (grupo 180, prompt 05).
 *
 * Hoy solo existe la regla de "tareas de lead", pero se deja como servicio
 * propio (y no inline en LeadAiService) para que cualquier origen futuro de
 * tareas relacionadas con leads use la misma consulta sin duplicarla.
 */
class AdminTaskAssignmentResolver
{
    /**
     * Ids de los admins que deben recibir las tareas originadas en
     * conversaciones de leads (alertas de intervención humana, etc.).
     *
     * Devuelve array vacío si no hay ningún setter configurado; en ese caso
     * la tarea queda sin asignar, que significa "la puede hacer cualquiera"
     * (no "no la ve nadie").
     *
     * @return array<int> Ids de admins con es_setter = true.
     */
    public static function for_lead_task(): array
    {
        return Admin::where('es_setter', true)
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }
}
