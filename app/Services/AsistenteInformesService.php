<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientTemplate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Los informes de la mañana del mostrador, por WhatsApp.
 *
 * Todas las mañanas el `empresa-api` de cada cliente deja listos los informes del mostrador —el
 * rendimiento de ayer, la caja, los vencimientos—. Hasta ahora el dueño los veía solo entrando al
 * sistema. Lucas pidió que le lleguen al WhatsApp, "para abrirlos desde el celular y poder
 * preguntarles cosas": de ahí que el mensaje sea **resumen + link**, y que el link abra el informe
 * sin pedir usuario y contraseña.
 *
 * 🔴 **La ventana de 24 hs de Meta decide la forma, y esto es lo que puede hacer que esta parte no
 * funcione en producción.** A las 8:30 de la mañana la ventana está cerrada para casi todos los
 * dueños —nadie le escribió al número en las últimas 24 hs—, así que el camino real no es el texto
 * libre sino la plantilla aprobada. **Sin una plantilla de Meta creada y aprobada, acá no sale
 * nada**, y es correcto que no salga: un texto libre fuera de ventana lo rechaza Meta y consume
 * ventana de conversación sin dejar rastro legible. Se registra y se sigue.
 *
 * 🔴 **Y el aviso se marca en DOS pasos, a propósito.** Primero sale el WhatsApp y recién después
 * se le dice al `empresa-api` que ese informe ya se avisó. Al revés —marcar y después mandar— un
 * fallo de Meta dejaría el informe marcado como avisado para siempre y el dueño sin enterarse
 * nunca. La idempotencia la da `mostrador_reportes.avisado_at` del lado del cliente: el comando
 * puede correr dos veces sin mandar nada dos veces.
 */
class AsistenteInformesService
{
    /**
     * Ruta de los informes del día que todavía no se avisaron.
     */
    const RUTA_PENDIENTES = 'api/admin-sync/asistente/informes-pendientes';

    /**
     * Prefijo de la ruta que marca un informe como avisado. Se completa con `/{id}/avisado`.
     */
    const RUTA_INFORMES = 'api/admin-sync/asistente/informes';

    /**
     * Largo máximo del texto que viaja como variable de plantilla.
     *
     * Es el mismo tope que `SupportWhatsappOpenerService::TEMPLATE_VARIABLE_MAX_LENGTH`, y está
     * repetido acá y no importado porque son dos decisiones distintas sobre la misma restricción
     * de Meta: allá acota el texto de un operador, acá arma una lista de informes. Si Meta cambia
     * el límite hay que tocar los dos, y eso es más sano que atar el mostrador a la bandeja de
     * soporte.
     */
    const LARGO_MAXIMO_DE_VARIABLE = 600;

    /**
     * Resolución de la URL del `empresa-api` de cada cliente.
     *
     * @var ClientEmpresaApiUrlResolver
     */
    private $urls;

    /**
     * Envío a Kapso/Meta.
     *
     * @var WhatsappSendService
     */
    private $sender;

    /**
     * Estado de la ventana de 24 hs de Meta por número.
     *
     * @var WhatsappSessionWindowService
     */
    private $ventana;

    /**
     * Saneo de variables de plantilla. Se usa el del opener, que ya está probado: Meta rechaza el
     * envío entero si un parámetro trae saltos de línea, tabs o espacios de más, y ese saneo tiene
     * que vivir en un solo lugar.
     *
     * @var SupportWhatsappOpenerService
     */
    private $opener;

    /**
     * @param ClientEmpresaApiUrlResolver  $urls    Resolución de la URL del `empresa-api`.
     * @param WhatsappSendService          $sender  Envío a Kapso/Meta.
     * @param WhatsappSessionWindowService $ventana Ventana de 24 hs de Meta.
     * @param SupportWhatsappOpenerService $opener  Dueño del saneo de variables de plantilla.
     */
    public function __construct(
        ClientEmpresaApiUrlResolver $urls,
        WhatsappSendService $sender,
        WhatsappSessionWindowService $ventana,
        SupportWhatsappOpenerService $opener
    ) {
        $this->urls    = $urls;
        $this->sender  = $sender;
        $this->ventana = $ventana;
        $this->opener  = $opener;
    }

    /**
     * Manda los informes del día a todos los clientes con el canal prendido.
     *
     * Cada cliente va en su propio try: el que falla no puede dejar sin informe a los que vienen
     * después. Con 40+ clientes y un solo comando, eso es la diferencia entre perder un aviso y
     * perderlos todos.
     *
     * @return array<int, array<string, mixed>> Una fila por cliente, para que el comando la imprima.
     */
    public function enviar_a_todos(): array
    {
        $clientes = Client::query()
            ->where('is_active', true)
            ->where('asistente_whatsapp_activo', true)
            ->orderBy('id')
            ->get();

        $resultados = [];

        foreach ($clientes as $client) {
            try {
                $resultados[] = $this->enviar_a($client);
            } catch (\Throwable $exception) {
                Log::channel('daily')->error('AsistenteInformes: excepción mandando los informes de un cliente.', [
                    'client_id' => $client->id,
                    'error'     => $exception->getMessage(),
                ]);

                $resultados[] = [
                    'client_id' => (int) $client->id,
                    'nombre'    => (string) $client->name,
                    'estado'    => 'error',
                    'informes'  => 0,
                    'detalle'   => $exception->getMessage(),
                ];
            }
        }

        return $resultados;
    }

    /**
     * Manda los informes del día a un cliente.
     *
     * @param Client $client Cliente con el canal prendido.
     *
     * @return array{client_id: int, nombre: string, estado: string, informes: int, detalle: string}
     */
    public function enviar_a(Client $client): array
    {
        $telefono = trim((string) $client->phone);
        if ($telefono === '') {
            return $this->resultado($client, 'sin_telefono', 0, 'El cliente no tiene teléfono cargado en su ficha.');
        }

        if (trim((string) $client->api_key) === '') {
            return $this->resultado($client, 'sin_api_key', 0, 'El cliente no tiene cargada la api_key.');
        }

        $pendientes = $this->traer_informes($client);

        if ($pendientes['estado'] !== 'ok') {
            return $this->resultado($client, $pendientes['estado'], 0, $pendientes['detalle']);
        }

        $informes = $pendientes['informes'];
        if (empty($informes)) {
            return $this->resultado($client, 'sin_informes', 0, 'No hay informes del día sin avisar.');
        }

        $abierta = $this->ventana->is_open($telefono);

        if ($abierta) {
            $enviado = $this->enviar_como_texto($client, $telefono, $informes);
        } else {
            $enviado = $this->enviar_como_plantilla($client, $telefono, $informes);
        }

        if ($enviado['estado'] !== 'enviado_texto' && $enviado['estado'] !== 'enviado_plantilla') {
            return $this->resultado($client, $enviado['estado'], count($informes), $enviado['detalle']);
        }

        /* Paso dos: recién ahora, con el WhatsApp ya entregado, se marcan los informes. */
        $marcados = 0;
        foreach ($informes as $informe) {
            if ($this->marcar_avisado($client, (int) $informe['id'])) {
                $marcados++;
            }
        }

        $detalle = 'Se avisaron ' . count($informes) . ' informes; quedaron marcados ' . $marcados . '.';

        Log::channel('daily')->info('AsistenteInformes: informes del día avisados.', [
            'client_id' => $client->id,
            'informes'  => count($informes),
            'marcados'  => $marcados,
            'forma'     => $enviado['estado'],
        ]);

        return $this->resultado($client, $enviado['estado'], count($informes), $detalle);
    }

    /**
     * Le pide al `empresa-api` del cliente los informes del día que todavía no se avisaron.
     *
     * @param Client $client Cliente.
     *
     * @return array{estado: string, informes: array<int, array<string, mixed>>, detalle: string}
     */
    private function traer_informes(Client $client): array
    {
        $vacio = [];

        $url = $this->urls->admin_sync_url($client, self::RUTA_PENDIENTES);
        if ($url === '') {
            return ['estado' => 'sin_url', 'informes' => $vacio, 'detalle' => 'No se pudo resolver la URL del empresa-api.'];
        }

        try {
            $respuesta = Http::withHeaders([
                    'X-Admin-Api-Key' => trim((string) $client->api_key),
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->get($url);
        } catch (ConnectionException $exception) {
            return ['estado' => 'error', 'informes' => $vacio, 'detalle' => 'No respondió el sistema del cliente: ' . $exception->getMessage()];
        }

        $status = (int) $respuesta->status();

        /* 404 es el cliente que todavía no tiene la ruta. Igual que en el canal de mensajes: no es
         * un error del sistema, es la versión vieja, y no hay nada que reintentar. Acá ni siquiera
         * se le avisa a nadie por WhatsApp — al dueño no le falta nada que él haya pedido. */
        if ($status === 404) {
            return ['estado' => 'degradado', 'informes' => $vacio, 'detalle' => 'El sistema del cliente todavía no tiene la ruta de informes.'];
        }

        if ($status !== 200) {
            return ['estado' => 'error', 'informes' => $vacio, 'detalle' => 'El sistema del cliente respondió ' . $status . '.'];
        }

        $datos = (array) $respuesta->json();
        $lista = isset($datos['informes']) && is_array($datos['informes']) ? $datos['informes'] : [];

        $informes = [];
        foreach ($lista as $fila) {
            if (! is_array($fila) || empty($fila['id'])) {
                continue;
            }

            $informes[] = [
                'id'      => (int) $fila['id'],
                'tipo'    => trim((string) ($fila['tipo'] ?? '')),
                'titulo'  => trim((string) ($fila['titulo'] ?? '')),
                'resumen' => trim((string) ($fila['resumen'] ?? '')),
                /* El link puede venir null a propósito: el `empresa-api` elige devolver null antes
                 * que armar una URL con la del API y mandarle un link roto al dueño. En ese caso el
                 * mensaje sale igual, con el resumen y sin link. */
                'url'     => trim((string) ($fila['url'] ?? '')),
            ];
        }

        return ['estado' => 'ok', 'informes' => $informes, 'detalle' => ''];
    }

    /**
     * Ventana abierta: el mensaje sale como texto libre, con su forma completa.
     *
     * @param Client                            $client
     * @param string                            $telefono
     * @param array<int, array<string, mixed>>  $informes
     *
     * @return array{estado: string, detalle: string}
     */
    private function enviar_como_texto(Client $client, string $telefono, array $informes): array
    {
        $texto = $this->armar_texto($informes);

        $whatsapp_message_id = $this->sender->send_text(
            $telefono,
            $texto,
            'Informes de la mañana - cliente #' . $client->id
        );

        if ($whatsapp_message_id === null) {
            return ['estado' => 'error', 'detalle' => 'Meta rechazó el texto: ' . (string) $this->sender->last_send_error];
        }

        return ['estado' => 'enviado_texto', 'detalle' => ''];
    }

    /**
     * Ventana cerrada: el mensaje sale por plantilla aprobada, o no sale.
     *
     * 🔴 **Sin plantilla configurada o sin la fila activa en `client_templates`, NO se manda nada.**
     * No hay ningún repliegue a texto libre, y no es una omisión: fuera de la ventana de 24 hs,
     * Meta rechaza el texto libre. "Intentarlo igual" no manda el mensaje, consume ventana de
     * conversación y deja un fallo que hay que ir a interpretar.
     *
     * @param Client                           $client
     * @param string                           $telefono
     * @param array<int, array<string, mixed>> $informes
     *
     * @return array{estado: string, detalle: string}
     */
    private function enviar_como_plantilla(Client $client, string $telefono, array $informes): array
    {
        $nombre_plantilla = AsistenteWhatsappSettings::plantilla_de_informes();
        if ($nombre_plantilla === '') {
            $detalle = 'La ventana de 24 hs está cerrada y no hay plantilla configurada en '
                . AsistenteWhatsappSettings::KEY_INFORME_TEMPLATE_NAME . '.';

            Log::channel('daily')->warning('AsistenteInformes: sin plantilla, no se manda nada.', [
                'client_id' => $client->id,
                'informes'  => count($informes),
            ]);

            return ['estado' => 'sin_plantilla', 'detalle' => $detalle];
        }

        $plantilla = ClientTemplate::query()
            ->where('template_name', $nombre_plantilla)
            ->where('activa', true)
            ->first();

        if ($plantilla === null) {
            $detalle = 'La plantilla "' . $nombre_plantilla . '" no existe en client_templates o está inactiva.';

            Log::channel('daily')->warning('AsistenteInformes: la plantilla configurada no sirve.', [
                'client_id'     => $client->id,
                'template_name' => $nombre_plantilla,
            ]);

            return ['estado' => 'sin_plantilla', 'detalle' => $detalle];
        }

        $variable = $this->armar_variable_de_plantilla($informes);

        /* 🔴 Variable vacía = mensaje descartado por Meta con `(#131008) Required parameter is
         * missing`. Es la falla que costó 2.933 seguimientos de leads entre julio y agosto de 2026
         * (ver el comentario grande de `WhatsappSendService::send_template()`), y no se repite: si
         * no hay nada que poner adentro, no se manda. */
        if ($variable === '') {
            return ['estado' => 'sin_plantilla', 'detalle' => 'Los informes no dejaron ningún texto para la variable de la plantilla.'];
        }

        $whatsapp_message_id = $this->sender->send_template(
            $telefono,
            (string) $plantilla->template_name,
            [$variable],
            (string) $plantilla->language_code,
            'Informes de la mañana - cliente #' . $client->id
        );

        if ($whatsapp_message_id === null) {
            return ['estado' => 'error', 'detalle' => 'Meta rechazó la plantilla: ' . (string) $this->sender->last_send_error];
        }

        return ['estado' => 'enviado_plantilla', 'detalle' => ''];
    }

    /**
     * El mensaje completo de la mañana, para cuando se puede mandar texto libre.
     *
     * Un bloque por informe: el título, el resumen y el link. Texto plano y sin markdown —WhatsApp
     * no lo renderiza y los asteriscos quedan a la vista—, que es el mismo criterio que sigue el
     * asistente adentro de la conversación.
     *
     * @param array<int, array<string, mixed>> $informes
     *
     * @return string
     */
    public function armar_texto(array $informes): string
    {
        $lineas = [];
        $lineas[] = count($informes) === 1
            ? 'Buen día. Te dejo el informe de hoy:'
            : 'Buen día. Te dejo los informes de hoy:';

        foreach ($informes as $informe) {
            $lineas[] = '';

            $titulo = (string) $informe['titulo'];
            $lineas[] = $titulo !== '' ? $titulo : 'Informe';

            $resumen = (string) $informe['resumen'];
            if ($resumen !== '') {
                $lineas[] = $resumen;
            }

            $url = (string) $informe['url'];
            if ($url !== '') {
                $lineas[] = $url;
            }
        }

        $lineas[] = '';
        $lineas[] = 'Si querés preguntarme algo sobre esto, respondeme por acá.';

        return implode("\n", $lineas);
    }

    /**
     * La versión comprimida que entra en una variable de plantilla.
     *
     * Meta no acepta saltos de línea en un parámetro y corta los largos, así que acá el mensaje se
     * aplana a una sola línea y se queda con lo que sirve desde el celular: **el título y el
     * link**. El resumen se sacrifica primero porque el link es lo que abre el informe completo, y
     * un resumen cortado a la mitad no sirve para nada.
     *
     * Si ni así entra, se recortan informes del final y se dice cuántos quedaron afuera: es
     * preferible avisar de tres informes y decir que hay dos más que mandar los cinco truncados y
     * que el último link quede partido.
     *
     * @param array<int, array<string, mixed>> $informes
     *
     * @return string Cadena vacía si no quedó nada que mandar.
     */
    public function armar_variable_de_plantilla(array $informes): string
    {
        $partes = [];
        foreach ($informes as $informe) {
            $titulo = (string) $informe['titulo'];
            $titulo = $titulo !== '' ? $titulo : 'Informe';

            $url = (string) $informe['url'];

            $partes[] = $url !== '' ? ($titulo . ': ' . $url) : $titulo;
        }

        $total = count($partes);

        /* Se sacan del final hasta que entre. El primer informe del día es el más importante (el
         * rendimiento de ayer), así que el que sobra es siempre el último. */
        while ($partes !== []) {
            $quedaron_afuera = $total - count($partes);

            $texto = implode(' · ', $partes);
            if ($quedaron_afuera > 0) {
                $texto .= ' · y ' . $quedaron_afuera . ' informe' . ($quedaron_afuera > 1 ? 's' : '') . ' más en el sistema';
            }

            $saneado = $this->opener->sanitize_template_variable($texto);

            if (mb_strlen($saneado) < self::LARGO_MAXIMO_DE_VARIABLE) {
                return $saneado;
            }

            array_pop($partes);
        }

        return '';
    }

    /**
     * Le dice al `empresa-api` que ese informe ya se avisó.
     *
     * Un fallo acá no voltea nada: el WhatsApp ya salió y el dueño ya tiene su informe. Lo único
     * que pasa es que mañana se le puede volver a avisar el mismo, y eso es preferible a la
     * alternativa (marcar antes de mandar y perder el aviso del todo).
     *
     * @param Client $client     Cliente.
     * @param int    $informe_id Informe del mostrador.
     *
     * @return bool True si quedó marcado.
     */
    private function marcar_avisado(Client $client, int $informe_id): bool
    {
        $url = $this->urls->admin_sync_url(
            $client,
            self::RUTA_INFORMES . '/' . $informe_id . '/avisado'
        );

        if ($url === '') {
            return false;
        }

        try {
            $respuesta = Http::withHeaders([
                    'X-Admin-Api-Key' => trim((string) $client->api_key),
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->post($url);
        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('AsistenteInformes: no se pudo marcar el informe como avisado.', [
                'client_id'  => $client->id,
                'informe_id' => $informe_id,
                'error'      => $exception->getMessage(),
            ]);

            return false;
        }

        if (! $respuesta->successful()) {
            Log::channel('daily')->warning('AsistenteInformes: el cliente rechazó la marca de avisado.', [
                'client_id'  => $client->id,
                'informe_id' => $informe_id,
                'status'     => $respuesta->status(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Arma la fila del resultado que imprime el comando.
     *
     * @param Client $client
     * @param string $estado
     * @param int    $informes
     * @param string $detalle
     *
     * @return array{client_id: int, nombre: string, estado: string, informes: int, detalle: string}
     */
    private function resultado(Client $client, string $estado, int $informes, string $detalle): array
    {
        return [
            'client_id' => (int) $client->id,
            'nombre'    => (string) $client->name,
            'estado'    => $estado,
            'informes'  => $informes,
            'detalle'   => $detalle,
        ];
    }
}
