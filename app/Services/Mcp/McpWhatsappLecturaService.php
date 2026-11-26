<?php

namespace App\Services\Mcp;

use App\Models\Admin;
use App\Models\WhatsappConfig;
use App\Services\WhatsappPersonal\WhatsappPersonalEnvioService;
use App\Services\WhatsappPersonal\WhatsappPersonalLecturaService;
use Carbon\Carbon;

/**
 * Las tres herramientas de LECTURA de WhatsApp del conector (misión dotin-whatsapp-admin, 26/11/2026):
 * `whatsapp_list_accounts`, `whatsapp_list_chats` y `whatsapp_get_messages`.
 *
 * 🔴 Sólo lee. No marca nada como leído, no manda recibos a Meta ni sale a la red: el número personal se lee con
 * `WhatsappPersonalLecturaService::chats()` y `mensajes()` (sólo SELECT; nunca `leer()`, `media()` ni `plantillas()`)
 * y el principal con {@see McpWhatsappPrincipalLectura}. La cuenta del asistente (dueños con su IA) no se expone.
 *
 * Cuenta y conversación son SIEMPRE explícitas en cada pedido: una cuenta fuera de `mcp.whatsapp_cuentas_lectura` es
 * `cuenta_no_disponible` y un teléfono mal formado es `telefono_invalido`. Un error de argumentos no es una
 * excepción JSON-RPC sino un resultado con `isError = true`.
 */
class McpWhatsappLecturaService
{
    /** Tope de chats por pedido. */
    const MAX_CHATS = 50;

    /** Tope de mensajes por pedido. */
    const MAX_MENSAJES = 100;

    /** @var WhatsappPersonalLecturaService Lectura del número personal. */
    private $personal;

    /** @var McpWhatsappPrincipalLectura Lectura del número principal. */
    private $principal;

    /**
     * @param WhatsappPersonalLecturaService $personal  Lectura (sólo SELECT) del número personal.
     * @param McpWhatsappPrincipalLectura    $principal Lectura (sólo SELECT) del número principal.
     */
    public function __construct(WhatsappPersonalLecturaService $personal, McpWhatsappPrincipalLectura $principal)
    {
        $this->personal  = $personal;
        $this->principal = $principal;
    }

    /**
     * Los nombres de las herramientas que este servicio sabe ejecutar.
     *
     * @return array<int, string>
     */
    public function nombres()
    {
        return ['whatsapp_list_accounts', 'whatsapp_list_chats', 'whatsapp_get_messages'];
    }

    /**
     * El catálogo de las tres herramientas. Todas son de sólo lectura, no destructivas e idempotentes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogo()
    {
        $anotaciones = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $cuenta      = ['type' => 'string', 'enum' => $this->cuentas_de_lectura(), 'description' => 'Cuenta de WhatsApp: personal (el WhatsApp personal de Lucas) o principal (el número de ComercioCity: leads y soporte).'];

        return [
            $this->herramienta(
                'whatsapp_list_accounts',
                'Cuentas de WhatsApp',
                'Lista las cuentas de WhatsApp que se pueden leer (personal y principal), si están listas y si tienen envío habilitado. No muestra claves. Sólo lectura: no marca nada como leído.',
                [],
                $anotaciones
            ),
            $this->herramienta(
                'whatsapp_list_chats',
                'Chats de una cuenta',
                'Lista los chats de UNA cuenta de WhatsApp, del más reciente al más viejo, con sus mensajes sin leer. Hay que indicar la cuenta. Sólo lectura: no marca nada como leído ni manda recibos de lectura.',
                [
                    'account'     => $cuenta,
                    'since'       => ['type' => 'string', 'description' => 'Sólo chats con actividad desde esta fecha (ISO 8601, ej. 2026-11-26T09:00:00-03:00).'],
                    'unread_only' => ['type' => 'boolean', 'description' => 'Sólo los chats con mensajes sin leer (por defecto no).'],
                    'limit'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_CHATS, 'description' => 'Cantidad máxima de chats (1 a 50, por defecto 20).'],
                ],
                $anotaciones,
                ['account']
            ),
            $this->herramienta(
                'whatsapp_get_messages',
                'Mensajes de una conversación',
                'Trae los mensajes de UNA conversación (cuenta + teléfono) en orden cronológico. Hay que indicar la cuenta y el teléfono. Sólo lectura: no marca nada como leído ni manda recibos de lectura.',
                [
                    'account' => $cuenta,
                    'phone'   => ['type' => 'string', 'description' => 'Teléfono del contacto en cualquier formato (ej. 3412345678, +54 9 341 234-5678).'],
                    'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_MENSAJES, 'description' => 'Cantidad máxima de mensajes (1 a 100, por defecto 30).'],
                    'since'   => ['type' => 'string', 'description' => 'Sólo mensajes desde esta fecha (ISO 8601). Sin ella, los más nuevos.'],
                ],
                $anotaciones,
                ['account', 'phone']
            ),
        ];
    }

    /**
     * Ejecuta una herramienta y devuelve el resultado MCP listo para el JSON-RPC.
     *
     * @param string               $nombre     Nombre de la herramienta (ya verificado con {@see nombres()}).
     * @param array<string, mixed> $argumentos Argumentos que mandó el cliente.
     * @param Admin                $admin      Admin dueño del token (los "leídos" de los leads son por admin).
     *
     * @return array<string, mixed> `{content: [...], structuredContent?: {...}, isError: bool}`.
     */
    public function ejecutar($nombre, array $argumentos, Admin $admin)
    {
        try {
            switch ($nombre) {
                case 'whatsapp_list_accounts':
                    $datos = $this->list_accounts();
                    break;
                case 'whatsapp_list_chats':
                    $datos = $this->list_chats($argumentos, $admin);
                    break;
                case 'whatsapp_get_messages':
                    $datos = $this->get_messages($argumentos, $admin);
                    break;
                default:
                    return $this->resultado_de_error('herramienta_desconocida', 'Herramienta desconocida: ' . $nombre);
            }
        } catch (\InvalidArgumentException $e) {
            // El código viaja antes de los dos puntos: `<codigo>: <mensaje>`.
            $partes = explode(': ', $e->getMessage(), 2);

            return $this->resultado_de_error($partes[0], isset($partes[1]) ? $partes[1] : $partes[0]);
        }

        return [
            'content'           => [['type' => 'text', 'text' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'structuredContent' => $datos,
            'isError'           => false,
        ];
    }

    /**
     * Normaliza un teléfono pedido a E.164, o null si no es un teléfono.
     *
     * Suma al normalizador del número personal el formato argentino de marcado con el "15" del celular
     * (`0341 15-234-5678` = `+5493412345678`), que el normalizador común no entiende.
     *
     * @param string $crudo Teléfono tal como lo escribió quien llama.
     *
     * @return string|null
     */
    public static function normalizar_telefono($crudo)
    {
        $crudo   = trim((string) $crudo);
        $digitos = (string) preg_replace('/\D+/', '', $crudo);

        // Marcado local con "15": [0]área(2 a 4) + 15 + número = 10 dígitos sin el 15 (ej. 0341 15 2345678).
        if ($crudo !== '' && preg_match('/^[0-9+\-\s().]+$/', $crudo) === 1) {
            $sin_ceros = ltrim($digitos, '0');
            if (strlen($sin_ceros) === 12 && substr($sin_ceros, 0, 2) !== '54') {
                for ($area = 2; $area <= 4; $area++) {
                    if (substr($sin_ceros, $area, 2) === '15') {
                        return WhatsappPersonalEnvioService::normalizar_telefono(substr($sin_ceros, 0, $area) . substr($sin_ceros, $area + 2));
                    }
                }
            }
        }

        return WhatsappPersonalEnvioService::normalizar_telefono($crudo);
    }

    /* ------------------------------------------------------------------------------------------
     | Herramientas
     |----------------------------------------------------------------------------------------- */

    /**
     * `whatsapp_list_accounts`: las cuentas que se pueden leer, sin ninguna clave ni id de Kapso.
     *
     * @return array<string, mixed>
     */
    private function list_accounts()
    {
        $nombres = [
            WhatsappConfig::PROPOSITO_PERSONAL  => 'WhatsApp personal de Lucas',
            WhatsappConfig::PROPOSITO_PRINCIPAL => 'Número de ComercioCity (leads y soporte)',
        ];
        $con_envio = $this->cuentas_de_envio();

        $cuentas = [];
        foreach ($this->cuentas_de_lectura() as $cuenta) {
            $fila = WhatsappConfig::delProposito($cuenta);
            $cuentas[] = [
                'cuenta'  => $cuenta,
                'nombre'  => isset($nombres[$cuenta]) ? $nombres[$cuenta] : $cuenta,
                'numero'  => $fila !== null && $fila->display_phone !== null ? (string) $fila->display_phone : null,
                'lista'   => $fila !== null && WhatsappConfig::canalListo($cuenta) !== null,
                'lectura' => true,
                'envio'   => in_array($cuenta, $con_envio, true),
            ];
        }

        return ['cuentas' => $cuentas];
    }

    /**
     * `whatsapp_list_chats`: los chats de una cuenta.
     *
     * @param array<string, mixed> $argumentos Argumentos del pedido.
     * @param Admin                $admin      Admin del token.
     *
     * @return array<string, mixed>
     */
    private function list_chats(array $argumentos, Admin $admin)
    {
        $cuenta      = $this->cuenta_pedida($argumentos);
        $desde       = $this->fecha_pedida($argumentos, 'since');
        $solo_nuevos = $this->booleano_pedido($argumentos, 'unread_only');
        $limite      = $this->entero_pedido($argumentos, 'limit', 20, self::MAX_CHATS);

        if ($cuenta === WhatsappConfig::PROPOSITO_PERSONAL) {
            $chats = [];
            foreach ($this->personal->chats($solo_nuevos, $desde, $limite) as $chat) {
                $chats[] = [
                    'telefono'                 => $chat['telefono'],
                    'nombre'                   => $chat['contact_name'],
                    'no_leidos'                => $chat['no_leidos'],
                    'ultimo_mensaje'           => $chat['ultimo_mensaje'],
                    'ultimo_mensaje_direccion' => $chat['ultimo_mensaje_direccion'],
                    'last_message_at'          => $chat['last_message_at'],
                    'canal'                    => 'personal',
                ];
            }
        } else {
            $chats = $this->principal->chats($solo_nuevos, $desde, $limite, (int) $admin->id);
        }

        return ['cuenta' => $cuenta, 'chats' => $chats];
    }

    /**
     * `whatsapp_get_messages`: los mensajes de UNA conversación de una cuenta.
     *
     * @param array<string, mixed> $argumentos Argumentos del pedido.
     * @param Admin                $admin      Admin del token.
     *
     * @return array<string, mixed>
     */
    private function get_messages(array $argumentos, Admin $admin)
    {
        $cuenta = $this->cuenta_pedida($argumentos);
        $crudo  = isset($argumentos['phone']) && (is_string($argumentos['phone']) || is_int($argumentos['phone'])) ? (string) $argumentos['phone'] : '';
        if ($crudo === '') {
            throw new \InvalidArgumentException('telefono_invalido: phone es obligatorio (el teléfono del contacto).');
        }
        $telefono = self::normalizar_telefono($crudo);
        if ($telefono === null) {
            throw new \InvalidArgumentException('telefono_invalido: el teléfono no es válido.');
        }

        $desde  = $this->fecha_pedida($argumentos, 'since');
        $limite = $this->entero_pedido($argumentos, 'limit', 30, self::MAX_MENSAJES);

        if ($cuenta === WhatsappConfig::PROPOSITO_PERSONAL) {
            $leidos   = $this->personal->mensajes($telefono, $desde, null, $limite);
            $mensajes = [];
            foreach ($leidos['mensajes'] as $mensaje) {
                $mensajes[] = [
                    'id'         => $mensaje['id'],
                    'canal'      => 'personal',
                    'direccion'  => $mensaje['direccion'],
                    'tipo'       => $mensaje['tipo'],
                    'texto'      => $mensaje['cuerpo'],
                    'estado'     => $mensaje['estado'],
                    'enviado_at' => $mensaje['sent_at'],
                ];
            }
            $hay_mas = $leidos['hay_mas'];
        } else {
            $leidos   = $this->principal->mensajes($telefono, $desde, $limite, (int) $admin->id);
            $mensajes = $leidos['mensajes'];
            $hay_mas  = $leidos['hay_mas'];
        }

        return ['cuenta' => $cuenta, 'telefono' => $telefono, 'mensajes' => $mensajes, 'hay_mas' => $hay_mas];
    }

    /* ------------------------------------------------------------------------------------------
     | Auxiliares
     |----------------------------------------------------------------------------------------- */

    /**
     * Las cuentas que se pueden leer (config), sin repetidos.
     *
     * @return array<int, string>
     */
    private function cuentas_de_lectura()
    {
        return array_values(array_unique(array_map('strval', (array) config('mcp.whatsapp_cuentas_lectura', []))));
    }

    /**
     * Las cuentas desde las que se puede enviar (config).
     *
     * @return array<int, string>
     */
    private function cuentas_de_envio()
    {
        return array_values(array_unique(array_map('strval', (array) config('mcp.whatsapp_cuentas_envio', []))));
    }

    /**
     * La cuenta pedida, verificada contra la lista de lectura.
     *
     * @param array<string, mixed> $argumentos Argumentos del pedido.
     *
     * @return string
     */
    private function cuenta_pedida(array $argumentos)
    {
        $cuenta = isset($argumentos['account']) && is_string($argumentos['account']) ? trim($argumentos['account']) : '';
        if ($cuenta === '') {
            throw new \InvalidArgumentException('cuenta_no_disponible: account es obligatorio (' . implode(' o ', $this->cuentas_de_lectura()) . ').');
        }
        if (! in_array($cuenta, $this->cuentas_de_lectura(), true)) {
            throw new \InvalidArgumentException('cuenta_no_disponible: esa cuenta no se puede leer. Cuentas: ' . implode(', ', $this->cuentas_de_lectura()) . '.');
        }

        return $cuenta;
    }

    /**
     * Una fecha ISO 8601 opcional del pedido (null si no vino).
     *
     * @param array<string, mixed> $argumentos Argumentos del pedido.
     * @param string               $clave      Nombre del argumento.
     *
     * @return string|null
     */
    private function fecha_pedida(array $argumentos, $clave)
    {
        if (! array_key_exists($clave, $argumentos) || $argumentos[$clave] === null || $argumentos[$clave] === '') {
            return null;
        }
        if (! is_string($argumentos[$clave])) {
            throw new \InvalidArgumentException('argumento_invalido: ' . $clave . ' tiene que ser una fecha ISO 8601.');
        }
        try {
            Carbon::parse($argumentos[$clave]);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('argumento_invalido: ' . $clave . ' no es una fecha válida (ISO 8601).');
        }

        return $argumentos[$clave];
    }

    /**
     * Un booleano estricto opcional del pedido (falso si no vino).
     *
     * @param array<string, mixed> $argumentos Argumentos del pedido.
     * @param string               $clave      Nombre del argumento.
     *
     * @return bool
     */
    private function booleano_pedido(array $argumentos, $clave)
    {
        if (! array_key_exists($clave, $argumentos) || $argumentos[$clave] === null) {
            return false;
        }
        if (! is_bool($argumentos[$clave])) {
            throw new \InvalidArgumentException('argumento_invalido: ' . $clave . ' tiene que ser true o false.');
        }

        return $argumentos[$clave];
    }

    /**
     * Un entero opcional del pedido, dentro de 1 y un tope.
     *
     * @param array<string, mixed> $argumentos Argumentos del pedido.
     * @param string               $clave      Nombre del argumento.
     * @param int                  $defecto    Valor si no vino.
     * @param int                  $maximo     Tope.
     *
     * @return int
     */
    private function entero_pedido(array $argumentos, $clave, $defecto, $maximo)
    {
        if (! array_key_exists($clave, $argumentos) || $argumentos[$clave] === null) {
            return $defecto;
        }
        if (! is_int($argumentos[$clave]) || $argumentos[$clave] < 1 || $argumentos[$clave] > $maximo) {
            throw new \InvalidArgumentException('argumento_invalido: ' . $clave . ' tiene que ser un entero de 1 a ' . $maximo . '.');
        }

        return $argumentos[$clave];
    }

    /**
     * Arma la definición de una herramienta.
     *
     * @param string               $nombre      Nombre.
     * @param string               $titulo      Título legible.
     * @param string               $descripcion Descripción (castellano).
     * @param array<string, mixed> $propiedades Propiedades del esquema de entrada.
     * @param array<string, bool>  $anotaciones Anotaciones MCP.
     * @param array<int, string>   $requeridas  Propiedades obligatorias.
     *
     * @return array<string, mixed>
     */
    private function herramienta($nombre, $titulo, $descripcion, array $propiedades, array $anotaciones, array $requeridas = [])
    {
        $esquema = ['type' => 'object', 'properties' => count($propiedades) > 0 ? $propiedades : new \stdClass()];
        if (count($requeridas) > 0) {
            $esquema['required'] = $requeridas;
        }

        return ['name' => $nombre, 'title' => $titulo, 'description' => $descripcion, 'inputSchema' => $esquema, 'annotations' => $anotaciones];
    }

    /**
     * Un resultado de herramienta con error (no una excepción JSON-RPC).
     *
     * @param string $codigo  Código estable del error (p. ej. `cuenta_no_disponible`).
     * @param string $mensaje Texto para quien llama.
     *
     * @return array<string, mixed>
     */
    private function resultado_de_error($codigo, $mensaje)
    {
        return [
            'content'           => [['type' => 'text', 'text' => $codigo . ': ' . $mensaje]],
            'structuredContent' => ['error' => $codigo, 'mensaje' => $mensaje],
            'isError'           => true,
        ];
    }
}
