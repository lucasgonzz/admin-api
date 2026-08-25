<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\AdminTask;
use App\Models\Client;
use App\Services\AdminTaskNotificationService;
use App\Services\ClientPhoneDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Avisa cuando un cliente activo no tiene ningún teléfono por el que reconocerlo.
 *
 * El webhook de WhatsApp decide si un mensaje entrante es de un cliente o de un lead
 * mirando tres lugares: el teléfono de la ficha, los empleados del cliente, y el lead que
 * fue promovido a ese cliente. Si no está en ninguno, el mensaje del cliente **cae en el
 * pipeline de leads** y nadie se entera de que era un pedido de soporte: no hay error, no
 * hay log, no hay ticket. Este comando existe para que esa falla silenciosa deje de serlo.
 *
 * Un cliente con `clients.phone` vacío pero con un empleado o un lead promovido con teléfono
 * NO se alerta: el webhook lo resuelve igual.
 */
class CheckClientsWithoutPhone extends Command
{
    /**
     * Nombre del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'support:check-clients-without-phone';

    /**
     * Descripción visible en php artisan list.
     *
     * @var string
     */
    protected $description = 'Crea una tarea cuando un cliente activo queda sin ningún teléfono cargado';

    /**
     * Clave de admin_settings con los días antes de volver a alertar el mismo cliente.
     */
    const KEY_RECHECK_DAYS = 'support_missing_phone_recheck_days';

    /**
     * Días por defecto antes de repetir la alerta de un cliente que sigue sin teléfono.
     */
    const DEFAULT_RECHECK_DAYS = 30;

    /**
     * Origen que queda estampado en admin_tasks.created_via.
     */
    const CREATED_VIA = 'sin_telefono';

    /**
     * Recorre los clientes activos y alerta los que no tienen ningún teléfono.
     *
     * @param ClientPhoneDirectory $phone_directory Criterio único de "teléfono del cliente".
     *
     * @return int
     */
    public function handle(ClientPhoneDirectory $phone_directory): int
    {
        $recheck_days = $this->resolve_recheck_days();
        $created = 0;

        $clients = Client::where('is_active', true)->orderBy('id')->get();

        foreach ($clients as $client) {
            if ($phone_directory->has_any_phone($client)) {
                continue;
            }

            if ($this->already_alerted($client, $recheck_days)) {
                continue;
            }

            if ($this->create_task_for($client)) {
                $created++;
            }
        }

        $this->info('Clientes activos revisados: ' . $clients->count() . '. Alertas nuevas: ' . $created . '.');

        return 0;
    }

    /**
     * Indica si no corresponde volver a alertar a este cliente.
     *
     * El estado de la alerta se deduce de las tareas mismas y no de una marca aparte: una
     * marca separada se desincroniza del panel, y ya pasó —una marca puesta sin haber creado
     * tarea dejaba al cliente en silencio treinta días.
     *
     * @param Client $client       Cliente sin teléfono.
     * @param int    $recheck_days Días antes de volver a avisar por el mismo cliente.
     *
     * @return bool
     */
    private function already_alerted(Client $client, int $recheck_days): bool
    {
        $title = self::task_title_for($client->resolve_display_name());

        // Con la tarea todavía sin hacer no hay nada que agregar: dice justo lo mismo.
        $abierta = AdminTask::where('created_via', self::CREATED_VIA)
            ->where('is_done', false)
            ->where('title', $title)
            ->exists();

        if ($abierta) {
            return true;
        }

        // Cerrada, pero el cliente sigue sin teléfono: se vuelve a avisar, aunque no todos los
        // días. Alguien la dio por hecha sin cargar nada y hay que insistir, no acosar.
        return AdminTask::where('created_via', self::CREATED_VIA)
            ->where('title', $title)
            ->where('created_at', '>=', now()->subDays($recheck_days))
            ->exists();
    }

    /**
     * Crea la tarea del panel para un cliente sin teléfono.
     *
     * @param Client $client Cliente sin ningún teléfono cargado.
     *
     * @return bool True si la tarea quedó creada.
     */
    private function create_task_for(Client $client): bool
    {
        $client_name = $client->resolve_display_name();

        $owner_id = $this->resolve_owner_admin_id();
        if ($owner_id === null) {
            Log::channel('daily')->warning('support:check-clients-without-phone: no hay admins, no se puede alertar.', [
                'client_id' => $client->id,
            ]);

            return false;
        }

        try {
            $task = AdminTask::create([
                // Creador cero: la tarea la crea el cron, no una persona. La columna no tiene
                // foreign key y TaskCard ya oculta el "Creado por" si la relación viene vacía.
                // Además AdminTaskNotificationService nunca le avisa al creador de una tarea:
                // poner acá a un admin real dejaría sin aviso justo a quien tiene que enterarse.
                'created_by_admin_id' => 0,
                'assigned_admin_id'   => $owner_id,
                'lead_id'             => null,
                'title'               => self::task_title_for($client_name),
                'content'             => 'El cliente ' . $client_name . ' está activo y no tiene ningún teléfono cargado: ni en la ficha, ni como empleado, ni en el lead del que salió. '
                    . 'Mientras siga así, cualquier mensaje de WhatsApp que mande va a caer en el pipeline de leads en vez de abrir un ticket de soporte, y nadie se va a enterar. '
                    . 'Cargalo en la ficha del cliente o como contacto.',
                'todos'               => null,
                'is_done'             => false,
                'sort_order'          => 0,
                'created_via'         => self::CREATED_VIA,
            ]);

            // La asignación y el aviso van en su propio try: si fallan, la tarea YA está
            // creada y commiteada, así que reportar "no se pudo crear" sería mentira y dejaría
            // el aviso sin reintentar nunca (la corrida siguiente ve la tarea abierta y pasa
            // de largo). Mismo criterio que ClaudeTaskIngestController y LeadAiService.
            try {
                $task->assigned_admins()->sync([$owner_id]);
                AdminTaskNotificationService::create_for_task($task);
            } catch (\Throwable $notification_exception) {
                Log::channel('daily')->error('support:check-clients-without-phone: la tarea quedó creada pero el aviso falló.', [
                    'task_id' => $task->id,
                    'error'   => $notification_exception->getMessage(),
                ]);
            }

            Log::channel('daily')->info('support:check-clients-without-phone alertó un cliente.', [
                'client_id' => $client->id,
                'task_id'   => $task->id,
                'admin_id'  => $owner_id,
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('support:check-clients-without-phone no pudo crear la tarea.', [
                'client_id' => $client->id,
                'error'     => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Admin que tiene que recibir la alerta: el dueño por defecto de soporte.
     *
     * @return int|null Null solo si no hay ningún admin cargado.
     */
    private function resolve_owner_admin_id()
    {
        $owner = Admin::where('is_default_support_owner', true)->orderBy('id')->first(['id']);
        if ($owner !== null) {
            return (int) $owner->id;
        }

        $first = Admin::orderBy('id')->first(['id']);

        return $first !== null ? (int) $first->id : null;
    }


    /**
     * Título de la tarea de alerta de un cliente.
     *
     * Es también la llave con la que se detecta si ya hay una tarea abierta para ese cliente,
     * porque admin_tasks no tiene columna client_id.
     *
     * @param string $client_name Razón social del cliente.
     *
     * @return string
     */
    private static function task_title_for(string $client_name): string
    {
        return 'Cargar el teléfono de ' . $client_name;
    }




    /**
     * Días antes de volver a alertar el mismo cliente.
     *
     * @return int
     */
    private function resolve_recheck_days(): int
    {
        $configured = (int) AdminSetting::get(self::KEY_RECHECK_DAYS, self::DEFAULT_RECHECK_DAYS);

        return $configured > 0 ? $configured : self::DEFAULT_RECHECK_DAYS;
    }
}
