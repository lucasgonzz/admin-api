<?php

namespace App\Console\Commands;

use App\Helpers\AdminApiPublicUrl;
use App\Models\WhatsappConfig;
use App\Services\KapsoHttpClient;
use Illuminate\Console\Command;

/**
 * Da de alta en Kapso el webhook de un número de WhatsApp y guarda su secreto en la fila.
 *
 * Existe por el segundo número (misión whatsapp-dos-numeros, 13/10/2026): el institucional ya tiene
 * su webhook registrado desde el panel de Kapso, pero el del asistente hay que darlo de alta, y
 * hacerlo a mano implica pegar el secreto en dos lugares distintos sin equivocarse. Acá el secreto
 * se genera, se manda a Kapso y se guarda en la misma corrida: o quedan los dos iguales, o no queda
 * ninguno.
 *
 * 🔴 **Este comando SALE A LA RED y toca la cuenta de Kapso de producción.** Se construyó en la
 * misión y **no se corrió**: el alta del número es un paso de producción que corre Lucas. Para
 * ensayarlo sin tocar nada está `--dry-run`, que imprime exactamente qué se iba a mandar y a dónde.
 *
 * Qué hace, en orden:
 *
 *   1. Resuelve la config activa de ese propósito. Sin fila, sin `phone_number_id` o sin
 *      `kapso_api_key`, corta con exit 1 y dice cuál de las tres falta: son los tres datos que solo
 *      puede cargar una persona desde el panel.
 *   2. Consulta el **health** del número en Kapso. Es diagnóstico y no una puerta: un número que
 *      Kapso no sabe informar igual puede tener webhook. La excepción es `--solo-health`, donde el
 *      health ES el trabajo y su resultado decide el exit code.
 *   3. Registra el webhook con los cinco eventos y guarda el secreto en la fila.
 *
 * Ejemplos:
 *
 *   php artisan whatsapp:registrar-webhook --solo-health
 *   php artisan whatsapp:registrar-webhook --dry-run
 *   php artisan whatsapp:registrar-webhook --proposito=asistente
 */
class WhatsappRegistrarWebhookCommand extends Command
{
    /**
     * Nombre y opciones del comando artisan.
     *
     * Las dos banderas van sin `=` a propósito: una opción declarada con `=` y pasada pelada llega
     * como null y `$this->option()` no la distingue de "no vino".
     *
     * @var string
     */
    protected $signature = 'whatsapp:registrar-webhook
                            {--proposito=asistente : Propósito del número (principal|asistente)}
                            {--url= : URL del webhook; por defecto la de este admin}
                            {--secreto= : Secreto a usar; por defecto se genera uno nuevo}
                            {--solo-health : Solo consulta el health del número y no registra nada}
                            {--dry-run : Imprime qué se iba a mandar sin salir a la red}';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Registra en Kapso el webhook de un número de WhatsApp y guarda su secreto';

    /**
     * Base de la API de plataforma de Kapso (la que administra números y webhooks).
     *
     * No es la misma base que usan los envíos (`/meta/whatsapp/v24.0/...`, el proxy de la Cloud API
     * de Meta): esta es la API propia de Kapso.
     */
    const BASE_PLATAFORMA = 'https://api.kapso.ai/platform/v1/whatsapp/phone_numbers/';

    /**
     * Los cinco eventos que el webhook tiene que recibir.
     *
     * El primero es el que trae los mensajes del dueño; los otros cuatro son los acuses de estado,
     * y sin ellos no se puede saber si un mensaje llegó: `delivered` y `read` son lo único que
     * distingue "Meta lo aceptó" de "al dueño le llegó".
     */
    const EVENTOS = [
        'whatsapp.message.received',
        'whatsapp.message.sent',
        'whatsapp.message.delivered',
        'whatsapp.message.read',
        'whatsapp.message.failed',
    ];

    /**
     * Bytes de entropía del secreto generado. 32 bytes = 64 caracteres hexadecimales.
     */
    const BYTES_DEL_SECRETO = 32;

    /**
     * Segundos de timeout de las dos llamadas a Kapso.
     */
    const TIMEOUT = 20;

    /**
     * Ejecuta el comando.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        $proposito = trim((string) $this->option('proposito'));
        if ($proposito === '') {
            $proposito = WhatsappConfig::PROPOSITO_ASISTENTE;
        }

        $config = WhatsappConfig::porProposito($proposito);
        if ($config === null) {
            $this->error('No hay configuración activa de WhatsApp con propósito "' . $proposito . '".');
            $this->line('Cargala primero desde el panel (Cuenta → WhatsApp): hacen falta el '
                . 'phone_number_id y la kapso_api_key de ese número, que los da Kapso.');

            return 1;
        }

        $phone_number_id = trim((string) $config->phone_number_id);
        if ($phone_number_id === '') {
            $this->error('La configuración "' . $proposito . '" no tiene phone_number_id cargado.');

            return 1;
        }

        $api_key = trim((string) $config->kapso_api_key);
        if ($api_key === '') {
            $this->error('La configuración "' . $proposito . '" no tiene kapso_api_key cargada: '
                . 'sin ella Kapso rechaza las dos llamadas de este comando.');

            return 1;
        }

        $this->info('Número ' . $proposito . ': phone_number_id ' . $phone_number_id
            . ($config->display_phone !== null ? ' (' . $config->display_phone . ')' : ''));

        $health_ok = $this->consultar_health($phone_number_id, $api_key);

        if ((bool) $this->option('solo-health')) {
            return $health_ok ? 0 : 1;
        }

        $url = trim((string) $this->option('url'));
        if ($url === '') {
            /* La URL del webhook la arma `AdminApiPublicUrl` y no `config('app.url')`: en el shared
             * hosting la API responde bajo `/public`, y una URL sin ese tramo es el 404 silencioso
             * que ya costó 23 eventos varados el 9/9/2026. */
            $url = AdminApiPublicUrl::api('webhook/whatsapp');
        }

        if ($url === '') {
            $this->error('No se pudo armar la URL del webhook: cargá ADMIN_API_PUBLIC_URL o APP_URL, '
                . 'o pasala con --url.');

            return 1;
        }

        $secreto = trim((string) $this->option('secreto'));
        $generado = false;
        if ($secreto === '') {
            $secreto  = bin2hex(random_bytes(self::BYTES_DEL_SECRETO));
            $generado = true;
        }

        $this->newLine();
        $this->info('Webhook a registrar:');
        $this->line('  URL     : ' . $url);
        $this->line('  Eventos : ' . implode(', ', self::EVENTOS));

        if ((bool) $this->option('dry-run')) {
            $this->newLine();
            $this->warn('--dry-run: no se llamó a Kapso y no se guardó nada.');
            $this->line('  POST ' . $this->endpoint_de_webhooks($phone_number_id));
            if ($generado) {
                $this->line('  El secreto se habría generado en esta corrida (no se muestra en un ensayo).');
            }

            return 0;
        }

        $respuesta = KapsoHttpClient::make($api_key, self::TIMEOUT)->post(
            $this->endpoint_de_webhooks($phone_number_id),
            [
                'url'        => $url,
                'secret_key' => $secreto,
                'events'     => self::EVENTOS,
            ]
        );

        if (! $respuesta->successful()) {
            $this->error('Kapso rechazó el alta del webhook (HTTP ' . $respuesta->status() . ').');
            $this->line(mb_strimwidth((string) $respuesta->body(), 0, 1000, '…'));
            $this->newLine();
            /* 🔴 El secreto NO se guarda si el alta falló. Guardarlo dejaría la fila con un secreto
             * que Kapso no conoce, y eso no es "a medio configurar": es el webhook rechazando con
             * 401 todos los mensajes de ese número, que es más difícil de diagnosticar que no tener
             * secreto. */
            $this->warn('No se guardó ningún secreto: la fila quedó como estaba.');

            return 1;
        }

        $config->webhook_secret = $secreto;
        $config->save();

        $this->newLine();
        $this->info('Webhook registrado en Kapso y secreto guardado en la configuración "' . $proposito . '".');

        if ($generado) {
            $this->newLine();
            $this->warn('Secreto generado en esta corrida (anotalo si lo necesitás en Kapso):');
            $this->line($secreto);
            $this->comment('No lo pegues en un informe ni en una novedad: con él se puede firmar '
                . 'cualquier payload hacia este webhook.');
        }

        if (! $health_ok) {
            $this->newLine();
            $this->warn('Ojo: el health del número no se pudo leer. El webhook quedó registrado, '
                . 'pero conviene mirar el estado del número en app.kapso.ai antes de darlo por listo.');
        }

        return 0;
    }

    /**
     * Pregunta a Kapso por el estado del número.
     *
     * @param string $phone_number_id Phone Number ID de Meta.
     * @param string $api_key         Clave de Kapso de ese número.
     *
     * @return bool True si Kapso contestó 2xx.
     */
    private function consultar_health(string $phone_number_id, string $api_key): bool
    {
        if ((bool) $this->option('dry-run')) {
            $this->newLine();
            $this->warn('--dry-run: tampoco se consultó el health.');
            $this->line('  GET ' . self::BASE_PLATAFORMA . rawurlencode($phone_number_id) . '/health');

            return true;
        }

        $respuesta = KapsoHttpClient::make($api_key, self::TIMEOUT)->get(
            self::BASE_PLATAFORMA . rawurlencode($phone_number_id) . '/health'
        );

        if (! $respuesta->successful()) {
            $this->warn('Kapso no informó el health del número (HTTP ' . $respuesta->status() . ').');
            $this->line(mb_strimwidth((string) $respuesta->body(), 0, 500, '…'));

            return false;
        }

        $this->info('Health del número según Kapso:');
        $this->line(mb_strimwidth((string) $respuesta->body(), 0, 1000, '…'));

        return true;
    }

    /**
     * Endpoint de alta de webhooks de un número.
     *
     * @param string $phone_number_id Phone Number ID de Meta.
     *
     * @return string
     */
    private function endpoint_de_webhooks(string $phone_number_id): string
    {
        return self::BASE_PLATAFORMA . rawurlencode($phone_number_id) . '/webhooks';
    }
}
