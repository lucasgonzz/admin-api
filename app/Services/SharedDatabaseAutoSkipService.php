<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientVersionUpgrade;
use App\Models\UpdateCommand;
use App\Models\UpdateSeeder;

/**
 * Marca como skipped los seeders/comandos per_database de un upgrade nuevo
 * cuando ya fueron ejecutados con éxito en otro cliente del mismo grupo de BD.
 */
class SharedDatabaseAutoSkipService
{
    /**
     * Aplica auto-skip a ítems per_database del upgrade
     * que ya fueron ejecutados en clientes hermanos del mismo grupo de BD.
     *
     * @param ClientVersionUpgrade $upgrade
     * @return void
     */
    public function apply(ClientVersionUpgrade $upgrade): void
    {
        $upgrade->loadMissing([
            'client.shared_database_group',
            'update_seeders.version_seeder',
            'update_commands.version_command',
        ]);

        $client = $upgrade->client;
        if (! $client) {
            return;
        }

        $shared_database_group_id = $client->shared_database_group_id;
        if ($shared_database_group_id === null) {
            return;
        }

        $sibling_client_ids = Client::query()
            ->where('shared_database_group_id', $shared_database_group_id)
            ->where('id', '!=', $client->id)
            ->pluck('id')
            ->all();

        if (empty($sibling_client_ids)) {
            return;
        }

        $executed_version_seeder_ids = $this->executed_version_seeder_ids_for_clients($sibling_client_ids);
        $executed_version_command_ids = $this->executed_version_command_ids_for_clients($sibling_client_ids);

        foreach ($upgrade->update_seeders as $update_seeder) {
            $version_seeder = $update_seeder->version_seeder;
            if (! $version_seeder) {
                continue;
            }

            if (($version_seeder->run_scope ?? null) !== 'per_database') {
                continue;
            }

            $version_seeder_id = (int) $update_seeder->version_seeder_id;
            if (! isset($executed_version_seeder_ids[$version_seeder_id])) {
                continue;
            }

            $update_seeder->update(['skipped' => true]);
        }

        foreach ($upgrade->update_commands as $update_command) {
            $version_command = $update_command->version_command;
            if (! $version_command) {
                continue;
            }

            if (($version_command->run_scope ?? null) !== 'per_database') {
                continue;
            }

            $version_command_id = (int) $update_command->version_command_id;
            if (! isset($executed_version_command_ids[$version_command_id])) {
                continue;
            }

            $update_command->update(['skipped' => true]);
        }
    }

    /**
     * Índice de version_seeder_id ya ejecutados con éxito en upgrades de los clientes indicados.
     *
     * @param array<int, int|string> $client_ids
     * @return array<int, bool>
     */
    protected function executed_version_seeder_ids_for_clients(array $client_ids)
    {
        $ids = UpdateSeeder::query()
            ->where('status', 'exitoso')
            ->whereHas('client_version_upgrade', function ($query) use ($client_ids) {
                $query->whereIn('client_id', $client_ids);
            })
            ->pluck('version_seeder_id')
            ->unique();

        $indexed = [];
        foreach ($ids as $version_seeder_id) {
            $indexed[(int) $version_seeder_id] = true;
        }

        return $indexed;
    }

    /**
     * Índice de version_command_id ya ejecutados con éxito en upgrades de los clientes indicados.
     *
     * @param array<int, int|string> $client_ids
     * @return array<int, bool>
     */
    protected function executed_version_command_ids_for_clients(array $client_ids)
    {
        $ids = UpdateCommand::query()
            ->where('status', 'exitoso')
            ->whereHas('client_version_upgrade', function ($query) use ($client_ids) {
                $query->whereIn('client_id', $client_ids);
            })
            ->pluck('version_command_id')
            ->unique();

        $indexed = [];
        foreach ($ids as $version_command_id) {
            $indexed[(int) $version_command_id] = true;
        }

        return $indexed;
    }
}
