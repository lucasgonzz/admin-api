<?php

use App\Http\Controllers\Api\PanelAgentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del acceso de agentes al panel (misión admin-control-total)
|--------------------------------------------------------------------------
|
| Se cargan desde `RouteServiceProvider::boot()` con prefijo `api/admin/agente`, el grupo `api` y
| `auth:sanctum` (más `admin.solo_leads` si ese perfil existe). Las dos son lecturas: las puede pedir un
| agente o un humano. Archivo aparte para no tocar `routes/api.php`.
*/

// Catálogo de operaciones del panel, derivado del router: categoría y si cada una está frenada.
Route::get('operaciones', [PanelAgentController::class, 'operations_json']);

// Auditoría de lo que hicieron los agentes (filtros: admin_id, resultado, categoria, desde, limite).
Route::get('auditoria', [PanelAgentController::class, 'audit_json']);
