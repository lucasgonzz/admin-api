<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Inicia la implementación de un cliente: crea el registro, las ocho etapas y el token del
 * formulario, y deja la etapa 1 en curso.
 *
 * 🔴 POR QUÉ EXISTE ESTE SERVICIO (misión `implementar-cliente`, 5/10/2026). Esta lógica vivía
 * ADENTRO de `ImplementationController::start()`, atada a un `JsonResponse`. Para que
 * `POST claude/implementations` pueda iniciar una implementación hacía falta una de dos: copiarla
 * —y tener dos definiciones de "así nace una implementación" que se desincronizan sin que nada lo
 * denuncie— o sacarla a un lugar que usen los dos. Se sacó. El botón del panel y la ruta de Claude
 * llaman a este mismo método.
 *
 * 🔴 EL PANEL NO CAMBIA DE COMPORTAMIENTO. El cuerpo de `start()` es el que estaba en el
 * controlador, línea por línea, y el controlador conserva lo que es suyo: la guarda de "este cliente
 * ya tiene una implementación" (con su 422 y su mensaje de siempre) y la forma de la respuesta.
 * `InicioDeImplementacionDelPanelTest` fija lo que el botón hace y se escribió ANTES de mover nada.
 *
 * ⚠️ Lo que este servicio NO hace, a propósito: no chequea que el cliente ya tenga implementación.
 * Esa guarda es de cada llamador porque cada uno contesta distinto —el panel con un 422 y su
 * mensaje, Claude con un 409 que lleva el id de la implementación existente— y, sobre todo, porque
 * el que llama tiene que hacerla DENTRO de su propia transacción con la fila del cliente bloqueada:
 * `implementations.client_id` no tiene índice único, así que dos altas simultáneas sobre el mismo
 * cliente crearían dos implementaciones si cada una mirara por su cuenta y sin lock.
 */
class ImplementationStartService
{
    /**
     * Crea la implementación del cliente con sus ocho etapas, la etapa 1 en curso y el token del
     * formulario público.
     *
     * @param Client $client         Cliente destino. El llamador ya verificó que no tiene implementación.
     * @param bool   $forzar_manual  false (default, y es como lo llama el panel): el modo de
     *                               automatización y el envío de la plantilla de bienvenida salen del
     *                               setting global, exactamente como siempre.
     *                               true (lo pasa `POST claude/implementations`): la implementación
     *                               nace SIEMPRE en `manual` y NO se manda ninguna plantilla,
     *                               diga lo que diga el setting. 🔴 No es prolijidad: en modo `auto`
     *                               el webhook de WhatsApp pasa a CONVERSAR con el cliente por su
     *                               cuenta y `start()` manda la plantilla de bienvenida de Meta, y
     *                               todo el sentido de operar desde Claude es que cada mensaje a un
     *                               cliente real lo vea y lo apruebe Lucas antes de salir (por
     *                               WhatsApp Web, desde la skill). Una implementación nacida en `auto`
     *                               por un setting que nadie recordaba tener le escribiría al cliente
     *                               sola, y el mensaje no se deshace.
     *
     * @return Implementation La implementación creada, con el `form_token` ya guardado. Sin
     *                        relaciones cargadas: cada llamador carga las que necesita.
     */
    public function start(Client $client, bool $forzar_manual = false): Implementation
    {
        /**
         * Admin asignado por defecto leído del setting global.
         * Se convierte a entero; si es 0 o no existe se guarda como null.
         */
        $assigned_admin_id = (int) AdminSetting::get('implementation_assigned_admin_id', 0) ?: null;

        // Modo de automatización por defecto para implementaciones nuevas ('manual' | 'auto').
        // Se lee de un setting global para poder reactivar la automatización sin deploy (prompt 342).
        $automation_mode = (string) AdminSetting::get('implementation_automation_mode', 'manual');

        if ($automation_mode !== 'auto' || $forzar_manual) {
            $automation_mode = 'manual';
        }

        /** Implementación creada con etapa 1 en curso. */
        $implementation = DB::transaction(function () use ($client, $assigned_admin_id, $automation_mode) {
            $implementation = Implementation::create([
                'client_id'          => $client->id,
                'status'             => 'in_progress',
                'current_stage'      => 1,
                'started_at'         => now(),
                'assigned_admin_id'  => $assigned_admin_id,
                'automation_mode'    => $automation_mode,
            ]);

            // Crear las ocho etapas en estado pendiente.
            for ($stage_number = 1; $stage_number <= 8; $stage_number++) {
                ImplementationStage::create([
                    'implementation_id' => $implementation->id,
                    'stage_number'        => $stage_number,
                    'status'              => 'pending',
                ]);
            }

            // Activar la etapa 1.
            ImplementationStage::where('implementation_id', $implementation->id)
                ->where('stage_number', 1)
                ->update([
                    'status'     => 'in_progress',
                    'started_at' => now(),
                ]);

            return $implementation;
        });

        // Generar token único (UUID v4) para acceso público al formulario de configuración.
        // Se genera fuera de la transacción para evitar colisiones de unique constraint.
        $implementation->form_token = Str::uuid()->toString();
        $implementation->save();

        // Plantilla de bienvenida por WhatsApp: best-effort, no bloquea la respuesta JSON.
        // En modo manual la presentación la envía Martín desde el panel (prompt 343).
        //
        // app() y no `new`: sin binding registrado el resultado es idéntico (el container instancia la
        // misma clase), pero habilita que un test reemplace el servicio con $this->app->instance() y
        // pruebe que en modo manual NO se llama, sin salir a ninguna red.
        if ($implementation->is_automated()) {
            try {
                app(ImplementationConversationService::class)->send_welcome_template($implementation);
            } catch (\Throwable $exception) {
                Log::error('ImplementationController@start: fallo envío plantilla bienvenida.', [
                    'implementation_id' => $implementation->id,
                    'error'             => $exception->getMessage(),
                ]);
            }
        }

        return $implementation;
    }
}
