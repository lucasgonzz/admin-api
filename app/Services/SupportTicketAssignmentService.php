<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Client;

class SupportTicketAssignmentService
{
    /**
     * Resuelve admin inicial para un ticket entrante.
     *
     * @param Client $client
     * @return int|null
     */
    public function resolve_assigned_admin_id(Client $client): ?int
    {
        // Prioriza administradores marcados como dueños por defecto de soporte.
        $default_admin = Admin::where('is_default_support_owner', true)->orderBy('id')->first();
        if (!is_null($default_admin)) {
            return (int) $default_admin->id;
        }

        // Fallback: primer admin activo por orden de alta.
        $first_admin = Admin::orderBy('id')->first();
        if (!is_null($first_admin)) {
            return (int) $first_admin->id;
        }

        // Si no hay admins cargados, retorna null para no romper creación.
        return null;
    }
}

