<?php

namespace App\Services;

use App\Models\Client;

/**
 * Resuelve placeholders de seeders/comandos de deployment con datos del cliente.
 * Sustituye {user_id?} / {user_id} por clients.user_id (ComercioCity) y elimina
 * placeholders opcionales no provistos ({sale_id?}, etc.).
 */
class DeploymentRunCommandResolver
{
    /**
     * Resuelve un comando o seeder para ejecutarlo en el hosting del cliente.
     *
     * @param  string  $command  Comando plantilla (p. ej. php artisan foo {user_id?}).
     * @param  string|null  $run_scope  per_user | per_database | null.
     * @param  Client|null  $client  Cliente del upgrade (fuente de user_id).
     * @return string
     */
    public function resolve(string $command, ?string $run_scope, ?Client $client): string
    {
        // Texto base del comando sin espacios sobrantes.
        $command = trim($command);
        if ($command === '') {
            return $command;
        }

        // user_id de ComercioCity del cliente admin (bloque múltiplo de 100).
        $client_user_id = $client && $client->user_id !== null
            ? (int) $client->user_id
            : null;

        // id interno del cliente en admin-api (por si el comando usa {client_id?}).
        $client_id = $client ? (int) $client->id : null;

        $requires_user_id = $this->command_requires_user_id($command, $run_scope);

        if ($requires_user_id && $client_user_id === null) {
            throw new \RuntimeException(
                'El cliente no tiene user_id de ComercioCity configurado. '
                . 'Completá el campo user_id del cliente antes de ejecutar seeders/comandos por tenant.'
            );
        }

        $resolved = $command;

        if ($client_user_id !== null) {
            $resolved = str_replace(
                ['{user_id?}', '{user_id}'],
                (string) $client_user_id,
                $resolved
            );
        }

        if ($client_id !== null) {
            $resolved = str_replace(
                ['{client_id?}', '{client_id}'],
                (string) $client_id,
                $resolved
            );
        }

        // Quita placeholders opcionales restantes ({sale_id?}, {article_id?}, etc.).
        $resolved = preg_replace('/\s*\{[a-z0-9_]+\?\}/i', '', $resolved) ?? $resolved;
        $resolved = preg_replace('/\s+/', ' ', trim($resolved)) ?? trim($resolved);

        // Seeders/comandos por tenant sin argumento user_id explícito: USER_ID en el entorno.
        if ($run_scope === 'per_user' && $client_user_id !== null) {
            $resolved = $this->maybe_prefix_user_id_env($resolved, $client_user_id);
        }

        return $resolved;
    }

    /**
     * Indica si el comando necesita user_id del cliente.
     *
     * @param  string  $command
     * @param  string|null  $run_scope
     * @return bool
     */
    private function command_requires_user_id(string $command, ?string $run_scope): bool
    {
        if ($run_scope === 'per_user') {
            return true;
        }

        return (bool) preg_match('/\{user_id(\?)?\}/i', $command);
    }

    /**
     * Prefija USER_ID= cuando el comando per_user no incluye ya el id como argumento.
     *
     * @param  string  $command
     * @param  int  $client_user_id
     * @return string
     */
    private function maybe_prefix_user_id_env(string $command, int $client_user_id): string
    {
        if (stripos($command, 'USER_ID=') === 0) {
            return $command;
        }

        // Ya quedó el user_id como argumento tras reemplazar placeholders.
        if (preg_match('/\s' . preg_quote((string) $client_user_id, '/') . '(\s|$)/', $command)) {
            return $command;
        }

        return 'USER_ID=' . $client_user_id . ' ' . $command;
    }
}
