<?php

namespace App\Console\Commands;

use App\Models\WhatsappConfig;
use App\Services\WhatsappSendService;
use Illuminate\Console\Command;

/**
 * Manda UN mensaje de prueba por uno de los dos números, a un teléfono que elige quien corre el comando.
 *
 * Existe porque el pedido de Lucas del 15/10/2026 incluye «verificar que el segundo número envía de
 * verdad y que Meta lo deja escribirle a números argentinos»: con el health de Kapso no alcanza, porque
 * un número puede figurar sano y no poder iniciar conversaciones (nombre en revisión, calidad, tier).
 * La única prueba que cuenta es que le llegue un mensaje a un celular de verdad.
 *
 * 🔴 **Por defecto NO manda nada**: imprime qué iba a mandar y por qué número. Hace falta `--enviar`
 * para que salga, y sale por el número que se pide con `--proposito` aunque todavía no esté «listo»
 * (sin webhook registrado el número ya puede ENVIAR; lo que no puede es recibir). El mensaje va al
 * teléfono que se pasa a mano: este comando no toca a ningún cliente ni a ningún lead.
 *
 * Fuera de la ventana de 24 hs Meta solo acepta una plantilla aprobada, así que para un celular que
 * nunca le escribió a ese número hay que usar `--plantilla` (p. ej. `cc_sistema_actualizado`, que lleva
 * una variable). Con `--texto` solo llega si esa persona le escribió al número en las últimas 24 hs.
 *
 * Ejemplos:
 *
 *   php artisan whatsapp:probar-envio +5493444123456 --plantilla=cc_sistema_actualizado --variable="Lucas"
 *   php artisan whatsapp:probar-envio +5493444123456 --plantilla=cc_sistema_actualizado --variable="Lucas" --enviar
 *   php artisan whatsapp:probar-envio +5493444123456 --texto="Prueba" --enviar
 */
class WhatsappProbarEnvioCommand extends Command
{
    /**
     * Nombre y opciones del comando artisan.
     *
     * @var string
     */
    protected $signature = 'whatsapp:probar-envio
                            {telefono : Teléfono que recibe la prueba, con código de país (p. ej. +5493444123456)}
                            {--proposito=asistente : Número por el que sale (principal|asistente)}
                            {--plantilla= : Nombre de una plantilla aprobada (la única forma de escribirle a alguien fuera de la ventana)}
                            {--variable=* : Valor de una variable de la plantilla, en orden (primera, segunda…)}
                            {--idioma=es_AR : Idioma de la plantilla}
                            {--texto= : Texto libre (solo llega con la ventana de 24 hs abierta)}
                            {--enviar : Manda de verdad; sin esto solo muestra qué se mandaría}';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Manda un mensaje de prueba por uno de los dos números a un teléfono que elegís (sin --enviar, solo muestra qué mandaría)';

    /**
     * Ejecuta el comando.
     *
     * @return int Código de salida (0 = Meta aceptó el mensaje, o fue un ensayo).
     */
    public function handle(): int
    {
        $proposito = trim((string) $this->option('proposito'));
        $proposito = $proposito !== '' ? $proposito : WhatsappConfig::PROPOSITO_ASISTENTE;

        $config = WhatsappConfig::porProposito($proposito);
        if ($config === null) {
            $this->error('No hay configuración activa de WhatsApp con propósito "' . $proposito . '".');

            return 1;
        }

        if (trim((string) $config->phone_number_id) === '' || trim((string) $config->kapso_api_key) === '') {
            $this->error('Al número "' . $proposito . '" le falta el phone_number_id o la clave de Kapso: sin eso no puede enviar nada.');

            return 1;
        }

        $plantilla = trim((string) $this->option('plantilla'));
        $texto     = trim((string) $this->option('texto'));

        if (($plantilla === '') === ($texto === '')) {
            $this->error('Elegí una sola cosa para mandar: --plantilla=<nombre> o --texto="<texto>".');

            return 1;
        }

        $telefono = trim((string) $this->argument('telefono'));
        if (preg_replace('/\D+/', '', $telefono) === '') {
            $this->error('El teléfono no tiene dígitos.');

            return 1;
        }

        $variables = array_values(array_map('strval', (array) $this->option('variable')));

        $this->info('Número "' . $proposito . '": phone_number_id ' . $config->phone_number_id
            . ($config->display_phone !== null ? ' (' . $config->display_phone . ')' : ''));
        $this->line('Destino: ' . $telefono);
        $this->line($plantilla !== ''
            ? 'Plantilla: ' . $plantilla . ' (' . $this->option('idioma') . '), ' . count($variables) . ' variable(s)'
            : 'Texto libre de ' . mb_strlen($texto) . ' caracteres');

        if ((bool) $config->test_mode) {
            $this->warn('Ojo: la fila de este número está en MODO DE PRUEBA: el envío se simula y no sale de verdad.');
        }

        if (! (bool) $this->option('enviar')) {
            $this->newLine();
            $this->warn('Ensayo: no se mandó nada. Agregá --enviar para que salga.');

            return 0;
        }

        $sender = (new WhatsappSendService())->para($config);

        $message_id = $plantilla !== ''
            ? $sender->send_template($telefono, $plantilla, $variables, (string) $this->option('idioma'), 'Prueba de envio por whatsapp:probar-envio')
            : $sender->send_text($telefono, $texto, 'Prueba de envio por whatsapp:probar-envio', true);

        $this->newLine();

        if ($message_id === null) {
            $this->error('Meta/Kapso NO aceptó el mensaje.');
            if ($sender->last_send_error !== null) {
                $this->line('Motivo: ' . mb_strimwidth((string) $sender->last_send_error, 0, 500, '…'));
            }
            if ($sender->last_send_status_code !== null) {
                $this->line('HTTP ' . $sender->last_send_status_code);
            }

            return 1;
        }

        $this->info('Meta aceptó el mensaje (' . $message_id . ').');
        $this->line('Aceptado no es entregado: mirá el celular. Si no llega, el motivo suele estar en la calidad o el nombre del número en Meta.');

        return 0;
    }
}
