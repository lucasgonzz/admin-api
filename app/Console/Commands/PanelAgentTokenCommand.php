<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Alta, rotación, revocación y listado de los tokens de los agentes del panel (misión admin-control-total).
 *
 * Un agente es un `Admin` con `es_agente = true` y un único token de Sanctum vivo. El token se imprime UNA
 * vez (o solo, con `--solo-token`, para canalizarlo a un almacén de secretos) y no se escribe en logs ni archivos.
 *
 * Ejemplos:
 *   php artisan agente:token "Claude Code"                 alta o rotación; imprime el token una vez
 *   php artisan agente:token OpenClaw --solo-token         imprime sólo el token (una línea)
 *   php artisan agente:token "Claude Code" --revocar       borra sus tokens; no emite ninguno
 *   php artisan agente:token x --listar                    agentes y tokens vivos, sin valores
 */
class PanelAgentTokenCommand extends Command
{
    /**
     * Firma del comando.
     *
     * @var string
     */
    protected $signature = 'agente:token {nombre : Nombre del agente (ej. "Claude Code", OpenClaw)} {--revocar : Borra los tokens del agente sin emitir uno nuevo} {--listar : Lista los agentes y sus tokens vivos (sin valores)} {--solo-token : Imprime únicamente el token, en una línea}';

    /**
     * Descripción del comando.
     *
     * @var string
     */
    protected $description = 'Da de alta, rota o revoca el token de un agente (Claude Code, OpenClaw) para operar el panel del admin';

    /**
     * Ejecuta el comando según la opción pedida.
     *
     * @return int 0 si salió bien, 1 si no.
     */
    public function handle()
    {
        if ($this->option('listar')) {
            return $this->list_agents();
        }

        // Slug del nombre (`Claude Code` => `claude-code`): define el email y el nombre del token.
        $slug = Str::slug((string) $this->argument('nombre'));
        if ($slug === '') {
            $this->error('El nombre del agente no es válido.');

            return 1;
        }

        $email = 'agente.' . $slug . '@agentes.comerciocity.invalid';
        $agent = Admin::where('email', $email)->first();

        // Un admin humano con ese email no se toca ni se convierte en agente.
        if ($agent !== null && ! (bool) $agent->es_agente) {
            $this->error('El email ' . $email . ' ya pertenece a un admin que no es agente: no se hizo nada.');

            return 1;
        }

        if ($this->option('revocar')) {
            return $this->revoke($agent, $email);
        }

        if ($agent === null) {
            $agent = Admin::create([
                'name'      => 'Agente ' . trim((string) $this->argument('nombre')),
                'email'     => $email,
                // Contraseña aleatoria que nadie ve: el agente no entra por el login, sólo con su token.
                'password'  => Hash::make(Str::random(64)),
                'es_agente' => true,
            ]);
        }

        // Rotación: un solo token vivo por agente.
        $agent->tokens()->delete();
        $token = $agent->createToken('agente:' . $slug, ['agente'])->plainTextToken;

        if ($this->option('solo-token')) {
            $this->line($token);

            return 0;
        }

        $this->info('Agente: ' . $agent->name . ' <' . $agent->email . '>');
        $this->warn('Token (se muestra UNA sola vez; guardalo ahora en su almacén de secretos):');
        $this->line($token);

        return 0;
    }

    /**
     * Borra todos los tokens del agente.
     *
     * @param  Admin|null $agent Agente (null si todavía no existe).
     * @param  string     $email Email esperado, para el mensaje.
     * @return int
     */
    private function revoke($agent, string $email): int
    {
        if ($agent === null) {
            $this->error('No existe un agente con el email ' . $email . '.');

            return 1;
        }

        $revoked = $agent->tokens()->delete();
        $this->info('Tokens revocados de ' . $agent->email . ': ' . $revoked . '.');

        return 0;
    }

    /**
     * Lista los agentes con la cantidad de tokens vivos y el último uso; nunca imprime un valor de token.
     *
     * @return int
     */
    private function list_agents(): int
    {
        $rows = [];
        foreach (Admin::where('es_agente', true)->orderBy('id')->get() as $agent) {
            $rows[] = [
                $agent->id,
                $agent->email,
                $agent->tokens()->count(),
                (string) $agent->tokens()->max('last_used_at'),
            ];
        }

        $this->table(['id', 'email', 'tokens vivos', 'último uso'], $rows);

        return 0;
    }
}
