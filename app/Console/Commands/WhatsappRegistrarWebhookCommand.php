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
 * 🔴 **El secreto NO se imprime nunca** (decisión del 15/10/2026): se genera, se manda a Kapso y se
 * guarda en la fila en la misma corrida, y de ahí en adelante vive solo en la base y en Kapso. Antes se
 * mostraba "por si hacía falta en Kapso", pero Kapso ya lo recibió por la API: imprimirlo solo lo deja
 * en el historial de la terminal y en cualquier log que capture la salida.
 *
 * 🔴 **Tampoco se crea un webhook encima de otro.** Si el número ya tiene webhooks, el comando corta y
 * dice cómo seguir: duplicar uno que apunta a este mismo admin haría que cada mensaje llegue dos veces,
 * y dejar vivo uno a otro host (un `ngrok` de desarrollo, por ejemplo) mandaría los mensajes de los
 * dueños también a la máquina de quien lo registró. `--listar` los muestra y `--reemplazar` da de alta
 * el nuestro y DESPUÉS borra los que apuntan a otro host y el viejo de este admin (el que recibe los
 * mensajes). Nunca borra uno de eventos crudos de Meta (`kind: meta`) ni uno de este admin con otra
 * ruta: el de atribución de anuncios vive ahí. Y `--reemplazar` solo existe para el número del
 * asistente: el webhook del número de leads y clientes no se toca desde este comando.
 *
 * Primero se crea y después se borra a propósito: si el alta falla, el número sigue como estaba.
 *
 * Ejemplos:
 *
 *   php artisan whatsapp:registrar-webhook --solo-health
 *   php artisan whatsapp:registrar-webhook --listar
 *   php artisan whatsapp:registrar-webhook --dry-run --reemplazar
 *   php artisan whatsapp:registrar-webhook --proposito=asistente --reemplazar
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
                            {--listar : Solo muestra los webhooks que ya tiene el número en Kapso (host y eventos, sin URLs completas ni secretos)}
                            {--reemplazar : Da de alta el nuestro y después borra los webhooks viejos que apuntan a otro host (solo para el asistente)}
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

        if ((bool) $this->option('reemplazar') && $proposito !== WhatsappConfig::PROPOSITO_ASISTENTE) {
            $this->error('--reemplazar solo se usa con --proposito=asistente: el webhook del número de leads y clientes no se toca desde acá.');

            return 1;
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

        if ((bool) $this->option('listar')) {
            $existentes = $this->webhooks_del_numero($phone_number_id, $api_key);
            if ($existentes === null) {
                return 1;
            }

            $this->mostrar_webhooks($existentes);

            return 0;
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
            $this->line('  GET  ' . $this->endpoint_de_webhooks($phone_number_id) . '   (para ver si ya hay webhooks)');
            $this->line('  POST ' . $this->endpoint_de_webhooks($phone_number_id));
            if ((bool) $this->option('reemplazar')) {
                $this->line('  DELETE ' . $this->endpoint_de_webhooks($phone_number_id) . '/{id}   (después del POST, solo los que apuntan a otro host y el viejo de este admin)');
            }
            if ($generado) {
                $this->line('  El secreto se habría generado en esta corrida (nunca se muestra).');
            }

            return 0;
        }

        /* Antes de crear nada se mira qué hay. Ver la cabecera: ni duplicar ni dejar vivo uno ajeno. */
        $existentes = $this->webhooks_del_numero($phone_number_id, $api_key);
        if ($existentes === null) {
            return 1;
        }

        $a_borrar = [];

        if ($existentes !== []) {
            $this->newLine();
            $this->mostrar_webhooks($existentes);

            if (! (bool) $this->option('reemplazar')) {
                $this->newLine();
                $this->error('Este número ya tiene webhooks en Kapso y no se registró ninguno nuevo.');
                $this->line('Si hay que reemplazarlos, volvé a correr con --reemplazar: da de alta el nuestro y después borra los viejos que apuntan a otro host.');

                return 1;
            }

            $a_borrar = $this->los_que_se_reemplazan($existentes, $url);

            $this->newLine();
            $this->line(count($a_borrar) . ' webhook(s) se borran DESPUÉS de dar de alta el nuestro; '
                . (count($existentes) - count($a_borrar)) . ' se conservan.');
        }

        $respuesta = KapsoHttpClient::make($api_key, self::TIMEOUT)->post(
            $this->endpoint_de_webhooks($phone_number_id),
            [
                'whatsapp_webhook' => [
                    'url'        => $url,
                    'secret_key' => $secreto,
                    'events'     => self::EVENTOS,
                ],
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
            $this->warn('No se guardó ningún secreto y no se borró ningún webhook: todo quedó como estaba.');

            return 1;
        }

        $config->webhook_secret = $secreto;
        $config->save();

        $quedaron_sin_borrar = 0;
        foreach ($a_borrar as $viejo) {
            $borrado = KapsoHttpClient::make($api_key, self::TIMEOUT)->delete(
                $this->endpoint_de_webhooks($phone_number_id) . '/' . rawurlencode((string) $viejo['id'])
            );

            if ($borrado->successful()) {
                $this->info('Borrado el webhook ' . $this->id_corto($viejo) . ' (' . $this->host_de($viejo) . ').');

                continue;
            }

            $quedaron_sin_borrar++;
            $this->warn('Kapso no dejó borrar el webhook ' . $this->id_corto($viejo) . ' (HTTP ' . $borrado->status() . '): '
                . 'queda VIVO. Borralo a mano en app.kapso.ai.');
        }

        $this->newLine();
        $this->info('Webhook registrado en Kapso y secreto guardado en la configuración "' . $proposito . '".');

        if ($generado) {
            $this->newLine();
            $this->comment('El secreto se generó en esta corrida y quedó guardado en la fila y en Kapso. '
                . 'No se muestra a propósito: con él se puede firmar cualquier payload hacia este webhook.');
        }

        if (! $health_ok) {
            $this->newLine();
            $this->warn('Ojo: el health del número no se pudo leer. El webhook quedó registrado, '
                . 'pero conviene mirar el estado del número en app.kapso.ai antes de darlo por listo.');
        }

        return $quedaron_sin_borrar === 0 ? 0 : 1;
    }

    /**
     * Cuáles de los webhooks que ya tiene el número se reemplazan.
     *
     * Se reemplaza un webhook de mensajes (`kind: kapso`) que apunta a OTRO host (una máquina de
     * desarrollo, por ejemplo) y el viejo de ESTE admin que recibe los mensajes (`…/api/webhook/whatsapp`),
     * que queda reemplazado por el que se acaba de crear con secreto nuevo.
     *
     * 🔴 Se conservan los de eventos crudos de Meta (`kind: meta`) —el que lleva la atribución de los
     * anuncios de clic a WhatsApp— y cualquier webhook de este admin con una ruta que no es la de los
     * mensajes: borrar uno de esos se pierde en silencio, sin que ningún mensaje deje de llegar.
     *
     * @param array<int, array<string, mixed>> $existentes Webhooks que Kapso dice que tiene el número.
     * @param string                           $url        URL del webhook que se está dando de alta.
     *
     * @return array<int, array<string, mixed>>
     */
    private function los_que_se_reemplazan(array $existentes, string $url): array
    {
        $nuestro_host = (string) parse_url($url, PHP_URL_HOST);
        $a_borrar     = [];

        foreach ($existentes as $webhook) {
            if ((isset($webhook['kind']) ? (string) $webhook['kind'] : 'kapso') === 'meta') {
                continue;
            }

            $host = $this->host_de($webhook);
            $ruta = (string) parse_url((string) (isset($webhook['url']) ? $webhook['url'] : ''), PHP_URL_PATH);

            $es_ajeno = $host !== $nuestro_host;
            $es_el_viejo_de_mensajes = $host === $nuestro_host
                && preg_match('#^/(?:public/)?api/webhook/whatsapp/?$#', $ruta) === 1;

            if ($es_ajeno || $es_el_viejo_de_mensajes) {
                $a_borrar[] = $webhook;
            }
        }

        return $a_borrar;
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
     * Webhooks que el número ya tiene en Kapso.
     *
     * @param string $phone_number_id Phone Number ID de Meta.
     * @param string $api_key         Clave de Kapso de ese número.
     *
     * @return array<int, array<string, mixed>>|null Null si Kapso no contestó 2xx (ya se avisó).
     */
    private function webhooks_del_numero(string $phone_number_id, string $api_key): ?array
    {
        $respuesta = KapsoHttpClient::make($api_key, self::TIMEOUT)->get(
            $this->endpoint_de_webhooks($phone_number_id),
            ['per_page' => 100]
        );

        if (! $respuesta->successful()) {
            $this->error('Kapso no informó los webhooks del número (HTTP ' . $respuesta->status() . ').');

            return null;
        }

        $data = $respuesta->json('data');

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Imprime los webhooks de un número sin exponer nada que no deba salir en una terminal.
     *
     * 🔴 **Solo el HOST de la URL, nunca la URL entera ni el `secret_key` ni los `headers`.** Hay
     * webhooks cuyo path lleva un token (el de los eventos crudos de Meta), y una lista que lo
     * imprimiera lo dejaría en el historial. Del host alcanza para lo único que hay que decidir:
     * si apunta a este admin o a otro lado (una máquina de desarrollo, por ejemplo).
     *
     * @param array<int, array<string, mixed>> $webhooks
     *
     * @return void
     */
    private function mostrar_webhooks(array $webhooks): void
    {
        if ($webhooks === []) {
            $this->info('El número no tiene ningún webhook en Kapso.');

            return;
        }

        $nuestro_host = (string) parse_url(AdminApiPublicUrl::api('webhook/whatsapp'), PHP_URL_HOST);

        $this->info('Webhooks del número en Kapso (' . count($webhooks) . '):');
        foreach ($webhooks as $webhook) {
            $host    = $this->host_de($webhook);
            $eventos = isset($webhook['events']) && is_array($webhook['events']) ? implode(', ', $webhook['events']) : '(sin eventos)';
            $activo  = ! empty($webhook['active']) ? 'activo' : 'inactivo';
            $es_de_este_admin = $nuestro_host !== '' && $host === $nuestro_host ? 'es de este admin' : 'APUNTA A OTRO HOST';

            $kind = isset($webhook['kind']) ? (string) $webhook['kind'] : 'kapso';

            $this->line('  - ' . $this->id_corto($webhook) . ' · ' . $host . $this->ruta_si_es_la_de_este_admin($webhook) . ' · ' . $kind . ' · ' . $activo . ' · ' . $es_de_este_admin);
            $this->line('      eventos: ' . $eventos);
        }
    }

    /**
     * La ruta del webhook, SOLO si es la ruta conocida de este admin.
     *
     * Sirve para una sola cosa: ver con qué prefijo (`/public/api/…` o `/api/…`) está dado de alta el
     * webhook del número principal, y copiarlo al dar de alta el del asistente con `--url`. Cualquier otra
     * ruta —la de un `ngrok`, la de los eventos crudos de Meta, que lleva un token— no se muestra.
     *
     * @param array<string, mixed> $webhook
     *
     * @return string Vacío si no es la ruta conocida.
     */
    private function ruta_si_es_la_de_este_admin(array $webhook): string
    {
        $ruta = parse_url((string) (isset($webhook['url']) ? $webhook['url'] : ''), PHP_URL_PATH);

        if (is_string($ruta) && preg_match('#^/(?:public/)?api/webhook/whatsapp/?$#', $ruta) === 1) {
            return $ruta;
        }

        return '';
    }

    /**
     * Host de la URL de un webhook (sin path ni query).
     *
     * @param array<string, mixed> $webhook
     *
     * @return string
     */
    private function host_de(array $webhook): string
    {
        $host = parse_url((string) (isset($webhook['url']) ? $webhook['url'] : ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : '(url ilegible)';
    }

    /**
     * Primeros ocho caracteres del id del webhook, que alcanzan para distinguirlos en pantalla.
     *
     * @param array<string, mixed> $webhook
     *
     * @return string
     */
    private function id_corto(array $webhook): string
    {
        return substr((string) (isset($webhook['id']) ? $webhook['id'] : '?'), 0, 8);
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
