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
     * Clave de admin_settings con el mapa de clientes ya alertados.
     */
    const KEY_ALERTED = 'support_missing_phone_alerted';

    /**
     * Clave de admin_settings con los días antes de volver a alertar el mismo cliente.
     */
    const KEY_RECHECK_DAYS = 'support_missing_phone_recheck_days';

    /**
     * Días por defecto antes de repetir la alerta de un cliente que sigue sin teléfono.
     */
    const DEFAULT_RECHECK_DAYS = 30;

    /**
     * Recorre los clientes activos y alerta los que no tienen ningún teléfono.
     *
     * @param ClientPhoneDirectory $phone_directory Criterio único de "teléfono del cliente".
     *
     * @return int
     */
    public function handle(ClientPhoneDirectory $phone_directory): int
    {
        $alerted = $this->read_alerted_map();
        $recheck_days = $this->resolve_recheck_days();
        $created = 0;

        $clients = Client::where('is_active', true)->orderBy('id')->get();

        foreach ($clients as $client) {
            $client_key = (string) $client->id;

            if ($phone_directory->has_any_phone($client)) {
                // Ya tiene teléfono: se olvida la marca para que vuelva a alertar si se lo sacan.
                unset($alerted[$client_key]);
                continue;
            }

            if ($this->was_alerted_recently($alerted, $client_key, $recheck_days)) {
                continue;
            }

            if ($this->create_task_for($client)) {
                $alerted[$client_key] = now()->toIso8601String();
                $created++;
            }
        }

        $this->write_alerted_map($alerted);

        $this->info('Clientes activos revisados: ' . $clients->count() . '. Alertas nuevas: ' . $created . '.');

        return 0;
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
                'created_by_admin_id' => $this->resolve_creator_admin_id($owner_id),
                'assigned_admin_id'   => $owner_id,
                'lead_id'             => null,
                'title'               => 'Cargar el teléfono de ' . $client_name,
                'content'             => 'El cliente ' . $client_name . ' está activo y no tiene ningún teléfono cargado: ni en la ficha, ni como empleado, ni en el lead del que salió. '
                    . 'Mientras siga así, cualquier mensaje de WhatsApp que mande va a caer en el pipeline de leads en vez de abrir un ticket de soporte, y nadie se va a enterar. '
                    . 'Cargalo en la ficha del cliente o como contacto.',
                'todos'               => null,
                'is_done'             => false,
                'sort_order'          => 0,
                'created_via'         => 'sin_telefono',
            ]);

            $task->assigned_admins()->sync([$owner_id]);

            AdminTaskNotificationService::create_for_task($task);

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
     * Admin que figura como creador de la tarea.
     *
     * AdminTaskNotificationService nunca le avisa al creador de una tarea, así que el creador
     * tiene que ser alguien distinto del destinatario o la alerta no le llega a nadie —que es
     * justo la falla silenciosa que este comando viene a resolver. Si el admin es uno solo, la
     * tarea igual queda creada y visible en el panel, pero sin aviso.
     *
     * @param int $owner_id Admin que va a recibir la alerta.
     *
     * @return int
     */
    private function resolve_creator_admin_id(int $owner_id): int
    {
        $other = Admin::where('id', '!=', $owner_id)->orderBy('id')->first(['id']);

        return $other !== null ? (int) $other->id : $owner_id;
    }

    /**
     * Indica si ese cliente ya fue alertado dentro de la ventana de re-chequeo.
     *
     * @param array<string, string> $alerted      Mapa client_id => fecha ISO de la alerta.
     * @param string                $client_key   Id del cliente como string.
     * @param int                   $recheck_days Días antes de repetir.
     *
     * @return bool
     */
    private function was_alerted_recently(array $alerted, string $client_key, int $recheck_days): bool
    {
        if (! isset($alerted[$client_key])) {
            return false;
        }

        try {
            $alerted_at = \Illuminate\Support\Carbon::parse($alerted[$client_key]);
        } catch (\Throwable $exception) {
            return false;
        }

        return $alerted_at->greaterThan(now()->subDays($recheck_days));
    }

    /**
     * Mapa de clientes ya alertados, guardado en admin_settings.
     *
     * No vive en una columna del cliente a propósito: es estado del aviso, no del cliente, y
     * admin_settings.value es TEXT, así que no hace falta migración.
     *
     * @return array<string, string>
     */
    private function read_alerted_map(): array
    {
        $raw = (string) AdminSetting::get(self::KEY_ALERTED, '');
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persiste el mapa de clientes alertados.
     *
     * @param array<string, string> $alerted Mapa client_id => fecha ISO.
     *
     * @return void
     */
    private function write_alerted_map(array $alerted): void
    {
        AdminSetting::set(self::KEY_ALERTED, json_encode($alerted));
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
