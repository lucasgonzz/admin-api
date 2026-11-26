<?php

namespace App\Console\Commands;

use App\Services\Mcp\McpOauthService;
use Illuminate\Console\Command;

/**
 * `php artisan mcp:revocar [--admin_id=N]`: revoca los tokens vigentes del conector MCP de sólo lectura (misión
 * dots-openclaw, 25/11/2026) y dice cuántos eran. Es el botón de emergencia: después de correrlo, ChatGPT tiene
 * que volver a pedir autorización (login en la pantalla de consentimiento).
 *
 * Sin opciones revoca los de todos los admins; con `--admin_id` sólo los de ese admin.
 */
class McpRevocarCommand extends Command
{
    /** @var string */
    protected $signature = 'mcp:revocar {--admin_id= : Revoca sólo los tokens de este admin}';

    /** @var string */
    protected $description = 'Revoca los tokens vigentes del conector MCP de sólo lectura (todos o los de un admin)';

    /**
     * Revoca e imprime la cantidad.
     *
     * @param McpOauthService $oauth Servicio que revoca.
     *
     * @return int 0 si salió bien; 1 si `--admin_id` no es un número.
     */
    public function handle(McpOauthService $oauth)
    {
        $opcion   = $this->option('admin_id');
        $admin_id = null;

        if ($opcion !== null && $opcion !== '') {
            if (! ctype_digit((string) $opcion)) {
                $this->error('--admin_id tiene que ser un número.');

                return 1;
            }
            $admin_id = (int) $opcion;
        }

        $cantidad = $oauth->revocar_todos($admin_id);
        $this->info('Tokens revocados: ' . $cantidad);

        return 0;
    }
}
