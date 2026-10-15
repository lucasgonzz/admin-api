<?php

namespace App\Console\Commands;

use App\Models\ClientTemplate;
use App\Models\WhatsappConfig;
use App\Services\AsistenteWhatsappSettings;
use App\Services\KapsoHttpClient;
use Illuminate\Console\Command;

/**
 * Dice en qué estado están los dos números de WhatsApp del admin y por cuál sale cada cosa.
 *
 * Existe por el segundo número (misión asistente-segundo-numero, 15/10/2026): con dos números, la
 * pregunta «¿ya está andando?» deja de ser «¿hay una fila?» y pasa a ser cuatro cosas que se cargan en
 * lugares distintos (panel, comando de webhook, Kapso, Meta). Este comando las junta en una sola
 * pantalla, y es la que se corre antes y después de cada paso del alta.
 *
 * 🔴 **Es SOLO LECTURA, y no imprime nunca un secreto.** De cada clave y de cada secreto dice
 * «cargada» o «falta»; del `phone_number_id` y del número que se lee, el valor (no son secretos: viajan
 * en cada URL de Kapso y en cada chat); de los webhooks, el host y los eventos y nunca la URL entera, que
 * a veces lleva un token en el path. Lo único que sale a la red son GET a Kapso (health, webhooks,
 * plantillas), y se puede evitar con `--sin-red`.
 *
 * Ejemplos:
 *
 *   php artisan whatsapp:estado
 *   php artisan whatsapp:estado --sin-red
 *   php artisan whatsapp:estado --plantilla=cc_asistente_nuevo_numero
 */
class WhatsappEstadoCommand extends Command
{
    /**
     * Nombre y opciones del comando artisan.
     *
     * @var string
     */
    protected $signature = 'whatsapp:estado
                            {--sin-red : No consulta a Kapso: solo lo que hay cargado en la base}
                            {--plantilla=* : Nombre de una plantilla más para mirar su estado en Meta}';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Estado de los dos números de WhatsApp (leads y clientes / asistente): qué falta y por cuál sale cada cosa (solo lectura)';

    /**
     * Base de la API de plataforma de Kapso (números, health y webhooks).
     */
    const BASE_PLATAFORMA = 'https://api.kapso.ai/platform/v1/whatsapp/phone_numbers/';

    /**
     * Base del proxy de Meta que expone Kapso (plantillas).
     */
    const BASE_META = 'https://api.kapso.ai/meta/whatsapp/v24.0/';

    /**
     * Segundos de timeout de cada GET a Kapso.
     */
    const TIMEOUT = 20;

    /**
     * Ejecuta el comando.
     *
     * @return int Código de salida: 0 si el número del asistente está listo, 1 si no.
     */
    public function handle(): int
    {
        $sin_red = (bool) $this->option('sin-red');

        $principal = WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_PRINCIPAL);
        $asistente = WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_ASISTENTE);

        $this->bloque_de_numero('Leads y clientes (principal)', $principal, $sin_red, false);
        $this->bloque_de_numero('Agente personal de los dueños (asistente)', $asistente, $sin_red, true);

        $this->bloque_de_webhooks_de_proyecto($principal !== null ? $principal : $asistente, $sin_red);
        $this->bloque_de_plantillas($principal, $asistente, $sin_red);
        $this->bloque_de_salidas($asistente);

        return ($asistente !== null && $asistente->tieneLoNecesarioParaAtender()) ? 0 : 1;
    }

    /**
     * Imprime lo que hay de un número: lo cargado, lo que falta y, si hay red, lo que Kapso dice.
     *
     * @param string              $titulo
     * @param WhatsappConfig|null $config
     * @param bool                $sin_red
     * @param bool                $exige_todo Si es el asistente: «listo» pide los cuatro datos.
     *
     * @return void
     */
    private function bloque_de_numero(string $titulo, ?WhatsappConfig $config, bool $sin_red, bool $exige_todo): void
    {
        $this->newLine();
        $this->info('== ' . $titulo . ' ==');

        if ($config === null) {
            $this->line('  Sin cargar: no hay una fila activa para este número.');

            return;
        }

        $this->line('  phone_number_id : ' . $this->valor((string) $config->phone_number_id));
        $this->line('  número visible  : ' . $this->valor((string) $config->display_phone));
        $this->line('  nombre          : ' . $this->valor((string) $config->nombre));
        $this->line('  WABA            : ' . $this->valor((string) $config->waba_id));
        $this->line('  clave de Kapso  : ' . (trim((string) $config->kapso_api_key) !== '' ? 'cargada' : 'FALTA'));
        $this->line('  secreto webhook : ' . (trim((string) $config->webhook_secret) !== '' ? 'cargado' : 'FALTA'));
        $this->line('  modo de prueba  : ' . ($config->test_mode ? 'SÍ (no sale nada de verdad)' : 'no'));

        if ($exige_todo) {
            $faltan = $config->loQueLeFaltaParaAtender();
            if ($faltan === []) {
                $this->info('  LISTO: este número ya atiende el canal del asistente.');
            } else {
                $this->warn('  NO está listo. Falta: ' . implode(', ', $faltan) . '.');
                $this->line('  Mientras no esté listo, todo sigue saliendo y entrando por el número de leads y clientes.');
            }
        }

        if ($sin_red) {
            return;
        }

        $phone_number_id = trim((string) $config->phone_number_id);
        $api_key         = trim((string) $config->kapso_api_key);
        if ($phone_number_id === '' || $api_key === '') {
            $this->line('  (sin clave o sin phone_number_id no se le puede preguntar nada a Kapso)');

            return;
        }

        $this->health_del_numero($phone_number_id, $api_key);
        $this->webhooks_del_numero($phone_number_id, $api_key);
    }

    /**
     * Health del número según Kapso.
     *
     * @param string $phone_number_id
     * @param string $api_key
     *
     * @return void
     */
    private function health_del_numero(string $phone_number_id, string $api_key): void
    {
        $respuesta = KapsoHttpClient::make($api_key, self::TIMEOUT)->get(
            self::BASE_PLATAFORMA . rawurlencode($phone_number_id) . '/health'
        );

        if (! $respuesta->successful()) {
            $this->warn('  health en Kapso : no se pudo leer (HTTP ' . $respuesta->status() . ').');

            return;
        }

        $this->line('  health en Kapso : ' . mb_strimwidth(preg_replace('/\s+/', ' ', (string) $respuesta->body()), 0, 600, '…'));
    }

    /**
     * Webhooks del número: host, estado y eventos, nunca la URL ni el secreto.
     *
     * @param string $phone_number_id
     * @param string $api_key
     *
     * @return void
     */
    private function webhooks_del_numero(string $phone_number_id, string $api_key): void
    {
        $respuesta = KapsoHttpClient::make($api_key, self::TIMEOUT)->get(
            self::BASE_PLATAFORMA . rawurlencode($phone_number_id) . '/webhooks',
            ['per_page' => 100]
        );

        if (! $respuesta->successful()) {
            $this->warn('  webhooks        : no se pudieron leer (HTTP ' . $respuesta->status() . ').');

            return;
        }

        $webhooks = $respuesta->json('data');
        if (! is_array($webhooks) || $webhooks === []) {
            $this->line('  webhooks        : ninguno');

            return;
        }

        foreach ($webhooks as $webhook) {
            $host = parse_url((string) (isset($webhook['url']) ? $webhook['url'] : ''), PHP_URL_HOST);
            $this->line('  webhook         : ' . (is_string($host) && $host !== '' ? $host : '(url ilegible)')
                . ' · ' . (! empty($webhook['active']) ? 'activo' : 'inactivo')
                . ' · ' . (isset($webhook['events']) && is_array($webhook['events']) ? count($webhook['events']) . ' eventos' : 'sin eventos'));
        }
    }

    /**
     * Webhooks de PROYECTO de Kapso: los que no cuelgan de un número.
     *
     * 🔴 Los webhooks por número (los que miran los dos bloques de arriba) no son los únicos: si el del
     * ***4199 estuviera dado de alta a nivel proyecto, recibiría también los mensajes del número nuevo,
     * firmados con el secreto del principal, y el admin los trataría como de leads. Es lo único que los
     * listados por número no ven, y por eso se mira aparte. Solo host y eventos, nunca la URL entera.
     *
     * @param WhatsappConfig|null $config Fila con la clave de Kapso del proyecto.
     * @param bool                $sin_red
     *
     * @return void
     */
    private function bloque_de_webhooks_de_proyecto(?WhatsappConfig $config, bool $sin_red): void
    {
        $this->newLine();
        $this->info('== Webhooks de proyecto en Kapso (reciben los eventos de TODOS los números) ==');

        if ($sin_red) {
            $this->line('  (no consultado: --sin-red)');

            return;
        }

        $api_key = $config !== null ? trim((string) $config->kapso_api_key) : '';
        if ($api_key === '') {
            $this->line('  (sin clave de Kapso cargada no se puede consultar)');

            return;
        }

        $respuesta = KapsoHttpClient::make($api_key, self::TIMEOUT)->get(
            'https://api.kapso.ai/platform/v1/whatsapp/webhooks',
            ['per_page' => 100]
        );

        if (! $respuesta->successful()) {
            $this->warn('  no se pudieron leer (HTTP ' . $respuesta->status() . ').');

            return;
        }

        $de_proyecto = [];
        foreach ((array) $respuesta->json('data') as $webhook) {
            if (is_array($webhook) && (! isset($webhook['phone_number_id']) || $webhook['phone_number_id'] === null || $webhook['phone_number_id'] === '')) {
                $de_proyecto[] = $webhook;
            }
        }

        if ($de_proyecto === []) {
            $this->line('  ninguno: cada número recibe solo lo suyo.');

            return;
        }

        foreach ($de_proyecto as $webhook) {
            $host = parse_url((string) (isset($webhook['url']) ? $webhook['url'] : ''), PHP_URL_HOST);
            $this->warn('  webhook de PROYECTO: ' . (is_string($host) && $host !== '' ? $host : '(url ilegible)')
                . ' · ' . (! empty($webhook['active']) ? 'activo' : 'inactivo')
                . ' · ' . (isset($webhook['kind']) ? $webhook['kind'] : 'kapso'));
        }
        $this->line('  Si alguno apunta a este admin, el número nuevo YA está entregando acá antes de darlo de alta.');
    }

    /**
     * Estado en Meta de las plantillas que usa el canal del asistente.
     *
     * @param WhatsappConfig|null $principal
     * @param WhatsappConfig|null $asistente
     * @param bool                $sin_red
     *
     * @return void
     */
    private function bloque_de_plantillas(?WhatsappConfig $principal, ?WhatsappConfig $asistente, bool $sin_red): void
    {
        $this->newLine();
        $this->info('== Plantillas del canal del asistente ==');

        $nombres = [
            'informe de la mañana'   => AsistenteWhatsappSettings::plantilla_de_informes(),
            'aviso de actualización' => AsistenteWhatsappSettings::plantilla_de_actualizaciones(),
        ];

        foreach ($nombres as $para_que => $nombre) {
            $this->line('  ' . $para_que . ' : ' . ($nombre !== '' ? $nombre : 'SIN CARGAR (con la ventana cerrada no sale nada)'));
        }

        /* 🔴 El informe de la mañana NO alcanza con tener el nombre cargado y la plantilla aprobada en
         * Meta: el servicio la busca TAMBIÉN en `client_templates` (activa) para armar el cuerpo, y si no
         * está ahí corta con «sin_plantilla». Se mira acá porque es el eslabón que se olvida. */
        $nombre_del_informe = $nombres['informe de la mañana'];
        if ($nombre_del_informe !== '') {
            $fila = ClientTemplate::query()->where('template_name', $nombre_del_informe)->first();
            $this->line('  informe en client_templates : ' . ($fila === null
                ? 'NO EXISTE (el informe no sale por plantilla)'
                : ($fila->activa ? 'activa' : 'INACTIVA (el informe no sale por plantilla)')));
        }

        $extra = array_filter((array) $this->option('plantilla'));
        $mirar = array_values(array_unique(array_merge(array_filter(array_values($nombres)), $extra)));

        if ($sin_red || $mirar === []) {
            return;
        }

        /* Las plantillas son por WABA y los dos números cuelgan de la misma, así que alcanza con
         * preguntar por la del asistente y, si no la tiene cargada, por la del principal. */
        $base = $asistente !== null && trim((string) $asistente->waba_id) !== '' ? $asistente : $principal;
        if ($base === null || trim((string) $base->waba_id) === '' || trim((string) $base->kapso_api_key) === '') {
            $this->warn('  No se pueden consultar las plantillas: ninguna fila tiene cargado el WABA y la clave.');

            return;
        }

        foreach ($mirar as $nombre) {
            $respuesta = KapsoHttpClient::make(trim((string) $base->kapso_api_key), self::TIMEOUT)->get(
                self::BASE_META . rawurlencode(trim((string) $base->waba_id)) . '/message_templates',
                ['name' => $nombre, 'limit' => 20]
            );

            if (! $respuesta->successful()) {
                $this->warn('  ' . $nombre . ' : no se pudo consultar (HTTP ' . $respuesta->status() . ').');

                continue;
            }

            $filas = $respuesta->json('data');
            if (! is_array($filas) || $filas === []) {
                $this->warn('  ' . $nombre . ' : NO EXISTE en esa WABA.');

                continue;
            }

            foreach ($filas as $fila) {
                $this->line('  ' . $nombre . ' : '
                    . (isset($fila['status']) ? $fila['status'] : '?')
                    . ' · ' . (isset($fila['category']) ? $fila['category'] : '?')
                    . ' · ' . (isset($fila['language']) ? $fila['language'] : '?'));
            }
        }
    }

    /**
     * Por cuál número sale cada cosa HOY, con el estado actual de las filas.
     *
     * Es la misma decisión que toma el código (`WhatsappConfig::canalListo()`), así que lo que se lee
     * acá es lo que va a pasar, no una descripción de lo que debería.
     *
     * @param WhatsappConfig|null $asistente
     *
     * @return void
     */
    private function bloque_de_salidas(?WhatsappConfig $asistente): void
    {
        $listo = $asistente !== null && $asistente->tieneLoNecesarioParaAtender();

        $this->newLine();
        $this->info('== Por cuál número sale cada cosa HOY ==');

        $asistente_dice = $listo ? 'agente personal (asistente)' : 'leads y clientes (principal)';

        $this->line('  respuestas del asistente a los dueños .... ' . $asistente_dice);
        $this->line('  informes de la mañana (08:30) ............ ' . $asistente_dice);
        $this->line('  aviso de actualización a los dueños ...... ' . $asistente_dice);
        $this->line('  leads, soporte, implementaciones ......... leads y clientes (principal)');
        $this->line('  dueño con asistente que escribe al principal: '
            . ($listo ? 'recibe un aviso con el número del asistente' : 'le contesta el asistente, como siempre'));
        $this->line('  persona ajena que escribe al asistente ... '
            . ($listo ? 'recibe un texto corto; no se crea lead ni ticket' : '(el número del asistente todavía no recibe)'));
    }

    /**
     * Un valor para mostrar, o «falta».
     *
     * @param string $valor
     *
     * @return string
     */
    private function valor(string $valor): string
    {
        $valor = trim($valor);

        return $valor !== '' ? $valor : 'falta';
    }
}
