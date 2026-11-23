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

    /**
     * Ids de los admins que reciben las tareas de la implementación contratada (misión embudo-juan-pipeline, E2): verificar el
     * cobro de la mitad, importar los artículos y seguir la implementación.
     *
     * Es el admin del setting `implementation_assigned_admin_id` si es mayor que 0 (el mismo que recibe las implementaciones); si
     * no, los setters, igual que {@see for_lead_task()} (Lucas es setter).
     *
     * @return array<int> Ids de admins; vacío si no hay ninguno (la tarea queda sin asignar: la puede tomar cualquiera).
     */
    public static function for_implementacion_task(): array
    {
        $admin_id = (int) \App\Models\AdminSetting::get('implementation_assigned_admin_id', 0);

        if ($admin_id > 0 && Admin::query()->whereKey($admin_id)->exists()) {
            return [$admin_id];
        }

        return self::for_lead_task();
    }

    /**
     * El admin que hace de «Juan» (el vendedor): primero los del perfil «Sólo Leads»; si no hay, un vendedor que NO sea closer; si no hay,
     * el closer. Es el orden que evita que el closer, marcado también como vendedor por `ventas:migrar-licencias` y más viejo que Juan,
     * le gane (misión embudo-juan-pipeline, E3 ronda 2).
     *
     * @param bool $para_el_aviso true = el aviso del día 5: exige teléfono y el último recurso es cualquier `is_closer` con teléfono;
     *                            false = quien hizo una llamada: sin teléfono, y el último recurso es un closer que además sea vendedor.
     *
     * @return Admin|null
     */
    public static function vendedor_de_juan(bool $para_el_aviso): ?Admin
    {
        $con_telefono = function ($consulta) use ($para_el_aviso) {
            if ($para_el_aviso) {
                $consulta->whereNotNull('phone_number')->where('phone_number', '!=', '');
            }
        };

        $juan = Admin::query()->where('solo_leads', true)->where($con_telefono)->orderBy('id')->first();
        if ($juan !== null) {
            return $juan;
        }

        $vendedor = Admin::query()->where('es_vendedor', true)->where('is_closer', false)->where($con_telefono)->orderBy('id')->first();
        if ($vendedor !== null) {
            return $vendedor;
        }

        $closer = Admin::query()->where('is_closer', true)->where($con_telefono);
        if (! $para_el_aviso) {
            $closer->where('es_vendedor', true);
        }

        return $closer->orderBy('id')->first();
    }
}
