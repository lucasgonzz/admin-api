<?php

namespace App\Console\Commands;

use App\Models\WhatsappConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Genera (o muestra) el token secreto del webhook CRUDO de Meta y arma la URL para el panel de Kapso.
 *
 * 🔴 Este token ES la credencial del endpoint de atribución. Un webhook `kind: meta` no manda
 * ninguna cabecera de firma —Kapso reenvía el payload de Meta sin modificar—, así que lo único
 * secreto que puede viajar es la propia URL. Sin token configurado el endpoint contesta 401 a
 * todo: falla cerrado a propósito.
 *
 * No lo pegues en un informe ni en una novedad: quien tenga la URL puede escribir filas de
 * atribución. Si se filtró, se corre este comando de nuevo y se actualiza la URL en Kapso.
 */
class MetaWebhookTokenCommand extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'whatsapp:meta-webhook-token {--show : Muestra el token actual sin regenerarlo}';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Genera o muestra el token del webhook crudo de Meta (atribución Click-to-WhatsApp)';

    /**
     * Largo del token generado. 64 caracteres alfanuméricos es holgado para una credencial que
     * viaja en una URL y no la escribe nadie a mano.
     */
    private const LARGO_TOKEN = 64;

    /**
     * Genera o muestra el token y devuelve la URL completa del webhook.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        $config = WhatsappConfig::getActive();
        if ($config === null) {
            $this->error('No hay configuración de WhatsApp activa. Cargala primero desde el panel.');

            return 1;
        }

        /* Token que ya está guardado, si hay alguno. */
        $token_actual = trim((string) $config->meta_webhook_token);

        if ((bool) $this->option('show')) {
            if ($token_actual === '') {
                $this->warn('Todavía no hay token configurado: el webhook de atribución rechaza todo con 401.');
                $this->line('Corré el comando sin --show para generar uno.');

                return 0;
            }

            $this->info('Token actual del webhook crudo de Meta:');
            $this->line($token_actual);
            $this->mostrar_url($token_actual);

            return 0;
        }

        if ($token_actual !== '') {
            $this->warn('Ya había un token configurado. El anterior deja de servir apenas se guarde el nuevo.');
            $this->warn('Acordate de actualizar la URL en el panel de Kapso, o la atribución deja de entrar.');
        }

        $token = Str::random(self::LARGO_TOKEN);

        $config->meta_webhook_token = $token;
        $config->save();

        $this->info('Token nuevo generado y guardado:');
        $this->line($token);
        $this->mostrar_url($token);

        return 0;
    }

    /**
     * Imprime la URL completa que hay que cargar en el panel de Kapso.
     *
     * @param string $token Token vigente.
     *
     * @return void
     */
    private function mostrar_url(string $token): void
    {
        /* APP_URL es la base pública del admin-api; el prefijo /api lo pone RouteServiceProvider. */
        $base = rtrim((string) config('app.url'), '/');

        $this->newLine();
        $this->info('URL para el webhook `kind: meta` en el panel de Kapso:');
        $this->line($base . '/api/webhook/meta-raw/' . $token);
        $this->newLine();
        $this->comment('El token también se acepta por la cabecera X-CC-Webhook-Token, si preferís no ponerlo en la URL.');
    }
}
