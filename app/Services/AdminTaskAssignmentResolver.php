<?php

namespace App\Services;

use App\Models\Admin;

/**
 * Resuelve reglas de asignación de tareas de admin reutilizables entre
 * distintos orígenes de creación de AdminTask (grupo 180, prompt 05).
 *
 * Hoy existen la regla de "tareas de lead" y la de "tareas del embudo", pero se deja como servicio
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

    /**
     * Ids de los admins que reciben las tareas del embudo (la «tarea para Juan»: el lead creó su prueba o pidió que
     * se la carguen). Misión embudo-whatsapp-precios, E2.
     *
     * Regla única: todos los admins con el perfil «Sólo Leads» (`solo_leads = 1`) o vendedores
     * (`es_vendedor = 1`). Si no hay ninguno de los dos, los setters, igual que {@see for_lead_task()}: así una
     * instalación sin ese perfil sigue como estaba. Con los datos de hoy (nadie vendedor) y Juan dado de alta con
     * «Sólo Leads», la tarea le llega sólo a él.
     *
     * @return array<int> Ids de admins; vacío si no hay ninguno de ninguna de las tres clases (la tarea queda sin
     *                    asignar, que significa "la puede tomar cualquiera").
     */
    public static function for_embudo_task(): array
    {
        $ids = Admin::where(function ($query) {
            $query->where('solo_leads', true)->orWhere('es_vendedor', true);
        })
            ->orderBy('id')
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        if (! empty($ids)) {
            return $ids;
        }

        return self::for_lead_task();
    }
}
