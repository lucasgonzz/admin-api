<?php

namespace App\Services;

use App\Models\WhatsappConfig;
use Illuminate\Support\Facades\Log;

/**
 * Crear, listar y consultar PLANTILLAS de WhatsApp en Meta, a través del proxy de Kapso.
 *
 * Es la pieza que faltaba para que el admin pudiera armar una plantilla sin que alguien entre a
 * Meta Business Suite con el navegador. Relevado el 13/10/2026 (ficha
 * `seguimientos-y-plantillas.md` §4): en todo el repo no había NI UNA llamada a plantillas; las
 * únicas URLs de Kapso eran `/{phone_number_id}/messages` y `/media`.
 *
 * 🔴 EL DIRECCIONAMIENTO ES POR WABA, NO POR NÚMERO, y esa es la diferencia con
 * `WhatsappSendService`. Los mensajes se mandan por `phone_number_id`; las plantillas viven en la
 * cuenta de WhatsApp Business (WABA) y se piden por `business_account_id`. Son dos identificadores
 * distintos de la misma configuración: por eso esta misión agregó `whatsapp_config.waba_id`. Pasar
 * el `phone_number_id` acá devuelve 404 de Meta, no un error legible.
 *
 * Reusa `KapsoHttpClient` tal cual, que es el mismo cliente con el mismo header `X-API-Key` y la
 * misma política de TLS que ya usa el envío: no hay una segunda forma de hablarle a Kapso.
 *
 * 🔴 NINGÚN MÉTODO DE ESTA CLASE LANZA EXCEPCIONES. Todos devuelven un array con la misma forma
 * —`ok`, `motivo`, `mensaje`, `http`, `datos`— porque los tres consumidores (el endpoint de Claude,
 * el comando del scheduler y los tests) necesitan DISTINGUIR los fallos, no solo saber que falló:
 *
 *   - `sin_configuracion`   no hay `WhatsappConfig` activa (o no tiene `kapso_api_key`).
 *   - `sin_waba_id`         la configuración activa no tiene WABA cargado.
 *   - `sin_permiso_en_meta` 401 o 403. 🔴 Es la señal del PLAN B: cuando pasa esto, la skill
 *                           `/leads` deja de insistir por API y manda a cargar la plantilla a mano
 *                           con el procedimiento de Chrome de `contexto/cuentas_meta.md`. Si este
 *                           motivo se mezclara con el resto, la skill reintentaría para siempre
 *                           contra una credencial sin permiso.
 *   - `limite_de_meta`      429: 100 creaciones por hora por WABA.
 *   - `error_de_meta`       cualquier otro HTTP no exitoso; `mensaje` trae lo que contestó Meta.
 *   - `sin_red`             excepción del cliente HTTP (timeout, DNS, TLS).
 *
 * 🔴 Y nunca, en ningún camino, se loguea `kapso_api_key`: ni el valor, ni enmascarado, ni dentro
 * del contexto de un error. Lo único que se loguea es qué plantilla y qué contestó Meta.
 */
class MetaTemplateService
{
    /**
     * Versión del Graph API que expone el proxy de Kapso.
     *
     * La misma que ya usa `WhatsappSendService::messages_endpoint()`: si algún día hay que subirla,
     * se suben las dos juntas o el admin queda hablándole a dos versiones de Meta a la vez.
     */
    const VERSION_META = 'v24.0';

    /**
     * Tope de páginas que se siguen al listar.
     *
     * Meta pagina por cursor y con 100 por página 5 vueltas son 500 plantillas: más del doble del
     * tope de 250 por WABA sin portfolio verificado. Existe para que una respuesta con un `next`
     * que se apunta a sí mismo no deje al comando del scheduler girando hasta el timeout del cron.
     */
    const MAX_PAGINAS = 5;

    /**
     * Plantillas por página al listar.
     */
    const POR_PAGINA = 100;

    /**
     * Crea una plantilla en Meta.
     *
     * @param array $plantilla Claves: `name`, `language`, `category`, `components` (array tal cual
     *                         lo espera Meta). `parameter_format` se fuerza a POSITIONAL.
     *
     * @return array{ok: bool, motivo: string|null, mensaje: string|null, http: int|null, datos: array}
     */
    public function crear(array $plantilla): array
    {
        $credenciales = $this->credenciales();
        if ($credenciales['ok'] !== true) {
            return $credenciales;
        }

        /*
         * 🔴 `parameter_format` POSITIONAL y no nombrado, y es una restricción del ENVÍO, no un
         * gusto: `WhatsappSendService::send_template()` arma el componente `body` con parámetros
         * `['type' => 'text', 'text' => …]` en orden, sin nombres. Una plantilla aprobada con
         * parámetros nombrados sería imposible de enviar desde este admin — se aprobaría igual y
         * fallaría recién en el primer envío real, que es el peor momento para enterarse.
         */
        $cuerpo = [
            'name'             => (string) ($plantilla['name'] ?? ''),
            'language'         => (string) ($plantilla['language'] ?? 'es_AR'),
            'category'         => (string) ($plantilla['category'] ?? 'UTILITY'),
            'parameter_format' => 'POSITIONAL',
            'components'       => isset($plantilla['components']) ? $plantilla['components'] : [],
        ];

        $respuesta = $this->pedir('post', $this->endpoint($credenciales['waba_id']), $credenciales['api_key'], $cuerpo);

        if ($respuesta['ok'] === true) {
            Log::channel('daily')->info('MetaTemplateService: plantilla creada en Meta.', [
                'name'   => $cuerpo['name'],
                'status' => isset($respuesta['datos']['status']) ? $respuesta['datos']['status'] : null,
                'id'     => isset($respuesta['datos']['id']) ? $respuesta['datos']['id'] : null,
            ]);
        } else {
            Log::channel('daily')->warning('MetaTemplateService: Meta rechazó la creación de la plantilla.', [
                'name'    => $cuerpo['name'],
                'motivo'  => $respuesta['motivo'],
                'http'    => $respuesta['http'],
                'mensaje' => $respuesta['mensaje'],
            ]);
        }

        return $respuesta;
    }

    /**
     * Lista las plantillas de la WABA, siguiendo la paginación por cursor.
     *
     * @param array $filtros Claves opcionales `status`, `name`, `category`.
     *
     * @return array{ok: bool, motivo: string|null, mensaje: string|null, http: int|null, datos: array}
     *                `datos` es la lista de plantillas (array de arrays), no la respuesta cruda.
     */
    public function listar(array $filtros = []): array
    {
        $credenciales = $this->credenciales();
        if ($credenciales['ok'] !== true) {
            return $credenciales;
        }

        $query = ['limit' => self::POR_PAGINA];
        foreach (['status', 'name', 'category'] as $clave) {
            if (isset($filtros[$clave]) && trim((string) $filtros[$clave]) !== '') {
                $query[$clave] = trim((string) $filtros[$clave]);
            }
        }

        $url         = $this->endpoint($credenciales['waba_id']);
        $plantillas  = [];
        $ultimo_http = null;

        for ($pagina = 0; $pagina < self::MAX_PAGINAS; $pagina++) {
            $respuesta = $this->pedir('get', $url, $credenciales['api_key'], $pagina === 0 ? $query : []);
            if ($respuesta['ok'] !== true) {
                return $respuesta;
            }

            $ultimo_http = $respuesta['http'];
            $pagina_datos = isset($respuesta['datos']['data']) && is_array($respuesta['datos']['data'])
                ? $respuesta['datos']['data']
                : [];
            foreach ($pagina_datos as $fila) {
                if (is_array($fila)) {
                    $plantillas[] = $fila;
                }
            }

            $siguiente = $this->url_de_la_pagina_siguiente($respuesta['datos']);
            if ($siguiente === null) {
                break;
            }
            $url = $siguiente;
        }

        return [
            'ok'      => true,
            'motivo'  => null,
            'mensaje' => null,
            'http'    => $ultimo_http,
            'datos'   => $plantillas,
        ];
    }

    /**
     * Estado de UNA plantilla, por nombre.
     *
     * @param string $name Nombre exacto de la plantilla en Meta.
     *
     * @return array{ok: bool, motivo: string|null, mensaje: string|null, http: int|null, datos: array}
     *                Con `ok` en true y `datos` vacío, la plantilla no existe en Meta.
     */
    public function estado(string $name): array
    {
        $respuesta = $this->listar(['name' => $name]);
        if ($respuesta['ok'] !== true) {
            return $respuesta;
        }

        /*
         * El filtro `name` de Meta no es necesariamente exacto (hay cuentas donde funciona como
         * prefijo), así que se elige la coincidencia exacta de la lista en vez de confiar en que la
         * primera fila sea la buscada. Sin esto, consultar `cc_informe_diario` podría devolver el
         * estado de `cc_informe_diario_v2` y activar la plantilla equivocada.
         */
        $exacta = [];
        foreach ($respuesta['datos'] as $fila) {
            if (isset($fila['name']) && (string) $fila['name'] === $name) {
                $exacta = $fila;
                break;
            }
        }

        return [
            'ok'      => true,
            'motivo'  => null,
            'mensaje' => null,
            'http'    => $respuesta['http'],
            'datos'   => $exacta,
        ];
    }

    /**
     * Baja a la fila local el estado que devolvió Meta, y decide si la plantilla queda activa.
     *
     * Está acá y no duplicado en el endpoint y en el comando porque es LA regla de activación: si
     * viviera en dos lados, `templates:sync-meta` y `POST …/{name}/refresh` podrían terminar
     * decidiendo distinto sobre la misma fila.
     *
     * La regla, tal como la pidió el plan (§M5 punto 3) más lo mínimo para que sea segura:
     *
     *   - `APPROVED` viniendo de cualquier otro estado  → `activa = true`. Es la automatización que
     *     se buscaba: una plantilla que se creó y quedó esperando revisión se enciende sola apenas
     *     Meta la aprueba, sin que nadie tenga que acordarse.
     *   - `REJECTED` o `DISABLED`                       → `activa = false`. No se puede enviar: si
     *     quedara activa, el gate de `send-template` la dejaría pasar y el envío fallaría en Kapso
     *     contestando 200 con `enviado=false`, que es justo el agujero que esta misión cierra.
     *   - `PAUSED`                                      → se guarda el estado y NO se toca `activa`.
     *     Una pausa de Meta por calidad es temporal y se levanta sola; apagar la fila obligaría a
     *     alguien a volver a prenderla a mano.
     *
     * 🔴 Y LO QUE NO HACE, QUE ES LA PARTE QUE IMPORTA: si Meta NO devolvió la plantilla (array
     * vacío), esto no toca `meta_status` ni `activa`. Solo sella `meta_synced_at` y deja el motivo
     * escrito. Que una consulta no la haya traído NO prueba que esté rechazada —puede ser el filtro
     * `name` del proxy, una página que se cortó o un error de Meta que contestó 200— y la dirección
     * peligrosa del error es clarísima: apagar una plantilla que funciona deja a `/leads` sin poder
     * escribirle a nadie fuera de la ventana de 24 hs, en silencio y hasta que alguien lo note.
     *
     * @param \App\Models\FollowupTemplate $fila Plantilla local. Se guarda adentro.
     * @param array                        $meta Fila que devolvió Meta, o array vacío si no vino.
     *
     * @return array{cambio: bool, estado_anterior: string|null, estado_nuevo: string|null, activada: bool, desactivada: bool, existe_en_meta: bool}
     */
    public function aplicar_estado_de_meta($fila, array $meta): array
    {
        $estado_anterior = $fila->meta_status !== null ? (string) $fila->meta_status : null;
        $resultado = [
            'cambio'          => false,
            'estado_anterior' => $estado_anterior,
            'estado_nuevo'    => $estado_anterior,
            'activada'        => false,
            'desactivada'     => false,
            'existe_en_meta'  => false,
        ];

        if (empty($meta)) {
            /* Ver el bloque 🔴 del docblock: no se cambia ni el estado ni `activa`. */
            $fila->meta_rechazo = 'Meta no devolvió esta plantilla al consultarla por nombre. '
                . 'No se cambió su estado: una consulta vacía no es un rechazo.';
            $fila->meta_synced_at = now();
            $fila->save();

            return $resultado;
        }

        $resultado['existe_en_meta'] = true;

        $estado_nuevo = isset($meta['status']) ? strtoupper(trim((string) $meta['status'])) : '';
        if ($estado_nuevo !== '') {
            $fila->meta_status = $estado_nuevo;
            $resultado['estado_nuevo'] = $estado_nuevo;
            $resultado['cambio'] = $estado_nuevo !== $estado_anterior;
        }

        if (isset($meta['category']) && trim((string) $meta['category']) !== '') {
            $fila->meta_category = strtoupper(trim((string) $meta['category']));
        }
        if (isset($meta['id']) && trim((string) $meta['id']) !== '') {
            $fila->meta_template_id = trim((string) $meta['id']);
        }
        /* `language` de Meta es la verdad sobre con qué idioma se aprobó: el envío tiene que usar
           ESE, no el que alguien escribió en la fila. Es el punto 4 de M5 («usan el language_code
           guardado»): lo que se guarda tiene que venir de Meta para que sirva de algo. */
        if (isset($meta['language']) && trim((string) $meta['language']) !== '') {
            $fila->language_code = trim((string) $meta['language']);
        }

        /* El motivo del rechazo tiene dos nombres según de dónde venga la respuesta. */
        $rechazo = '';
        foreach (['rejected_reason', 'rejection_reason'] as $clave) {
            if (isset($meta[$clave]) && trim((string) $meta[$clave]) !== '') {
                $rechazo = trim((string) $meta[$clave]);
                break;
            }
        }
        $fila->meta_rechazo = $estado_nuevo === 'REJECTED'
            ? ($rechazo !== '' ? $rechazo : 'Meta la rechazó sin informar motivo.')
            : ($rechazo !== '' ? $rechazo : null);

        if ($estado_nuevo === 'APPROVED' && $estado_anterior !== 'APPROVED') {
            $fila->activa = true;
            $resultado['activada'] = true;
        }
        if ($estado_nuevo === 'REJECTED' || $estado_nuevo === 'DISABLED') {
            if ((bool) $fila->activa === true) {
                $resultado['desactivada'] = true;
            }
            $fila->activa = false;
        }

        $fila->meta_synced_at = now();
        $fila->save();

        return $resultado;
    }

    /**
     * URL del recurso `message_templates` de una WABA.
     *
     * @param string $waba_id
     *
     * @return string
     */
    public function endpoint(string $waba_id): string
    {
        return 'https://api.kapso.ai/meta/whatsapp/' . self::VERSION_META . '/'
            . rawurlencode($waba_id)
            . '/message_templates';
    }

    /**
     * Credenciales de la configuración activa, o el motivo por el que no se puede llamar a Meta.
     *
     * @return array{ok: bool, motivo: string|null, mensaje: string|null, http: int|null, datos: array, api_key?: string, waba_id?: string}
     */
    public function credenciales(): array
    {
        $config = WhatsappConfig::getActive();

        $api_key = $config !== null ? trim((string) $config->kapso_api_key) : '';
        if ($config === null || $api_key === '') {
            return $this->falla(
                'sin_configuracion',
                'No hay configuración activa de WhatsApp con clave de Kapso (whatsapp_config). '
                    . 'Se carga en el panel, en Cuenta > WhatsApp.',
                null
            );
        }

        $waba_id = trim((string) $config->waba_id);
        if ($waba_id === '') {
            return $this->falla(
                'sin_waba_id',
                'La configuración activa de WhatsApp no tiene waba_id cargado, y las plantillas de '
                    . 'Meta se piden por WABA (no por phone_number_id). Se carga en el panel, en '
                    . 'Cuenta > WhatsApp.',
                null
            );
        }

        return [
            'ok'      => true,
            'motivo'  => null,
            'mensaje' => null,
            'http'    => null,
            'datos'   => [],
            'api_key' => $api_key,
            'waba_id' => $waba_id,
        ];
    }

    /**
     * Hace el pedido al proxy y traduce el resultado a la forma única de esta clase.
     *
     * @param string $metodo  'get' o 'post'.
     * @param string $url     URL completa.
     * @param string $api_key Clave de Kapso. 🔴 No se loguea nunca.
     * @param array  $datos   Query (GET) o body (POST).
     *
     * @return array{ok: bool, motivo: string|null, mensaje: string|null, http: int|null, datos: array}
     */
    protected function pedir(string $metodo, string $url, string $api_key, array $datos): array
    {
        try {
            $http = KapsoHttpClient::make($api_key, (int) config('services.client_api.timeout', 15));

            /*
             * Sin `retry()`, a diferencia del envío de mensajes, y a propósito: un POST de creación
             * reintentado contra un 500 ambiguo puede dejar DOS plantillas con el mismo nombre en
             * Meta (o consumir dos del tope de 100 por hora). El llamador reintenta si quiere; acá
             * no se decide por él. Para el GET da lo mismo, y conviene que las dos ramas se
             * comporten igual.
             */
            $respuesta = $metodo === 'post'
                ? $http->post($url, $datos)
                : $http->get($url, $datos);

            $cuerpo = $respuesta->json();
            if (! is_array($cuerpo)) {
                $cuerpo = [];
            }

            if ($respuesta->successful()) {
                return [
                    'ok'      => true,
                    'motivo'  => null,
                    'mensaje' => null,
                    'http'    => (int) $respuesta->status(),
                    'datos'   => $cuerpo,
                ];
            }

            $status = (int) $respuesta->status();
            $motivo = 'error_de_meta';
            if ($status === 401 || $status === 403) {
                $motivo = 'sin_permiso_en_meta';
            } elseif ($status === 429) {
                $motivo = 'limite_de_meta';
            }

            return $this->falla($motivo, $this->mensaje_de_meta($cuerpo, $respuesta->body()), $status);
        } catch (\Throwable $excepcion) {
            Log::channel('daily')->error('MetaTemplateService: excepción al hablar con el proxy de Kapso.', [
                'metodo' => $metodo,
                'error'  => $excepcion->getMessage(),
            ]);

            return $this->falla('sin_red', $excepcion->getMessage(), null);
        }
    }

    /**
     * Saca el mensaje legible de un error de Meta.
     *
     * @param array  $cuerpo Respuesta ya decodificada.
     * @param string $crudo  Respuesta cruda, por si no vino JSON.
     *
     * @return string
     */
    protected function mensaje_de_meta(array $cuerpo, string $crudo): string
    {
        if (isset($cuerpo['error']['error_user_msg']) && trim((string) $cuerpo['error']['error_user_msg']) !== '') {
            return trim((string) $cuerpo['error']['error_user_msg']);
        }
        if (isset($cuerpo['error']['message']) && trim((string) $cuerpo['error']['message']) !== '') {
            return trim((string) $cuerpo['error']['message']);
        }
        if (isset($cuerpo['message']) && is_string($cuerpo['message']) && trim($cuerpo['message']) !== '') {
            return trim($cuerpo['message']);
        }

        /* 500 caracteres: lo suficiente para diagnosticar y no tanto como para llenar un log con
           una página de HTML de error del proxy. */
        return substr($crudo, 0, 500);
    }

    /**
     * URL de la página siguiente, solo si apunta al proxy de Kapso.
     *
     * 🔴 Se valida el host y no se sigue cualquier `next`. Un `paging.next` es una URL que viene de
     * afuera: seguirla a ciegas significa mandarle el header `X-API-Key` —la credencial de WhatsApp
     * de ComercioCity— a donde diga la respuesta. Si el host no es el del proxy, la paginación se
     * corta y lo que se leyó hasta ahí vale.
     *
     * @param array $cuerpo Respuesta ya decodificada.
     *
     * @return string|null
     */
    protected function url_de_la_pagina_siguiente(array $cuerpo): ?string
    {
        if (! isset($cuerpo['paging']['next'])) {
            return null;
        }

        $url = trim((string) $cuerpo['paging']['next']);
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== 'api.kapso.ai') {
            Log::channel('daily')->warning('MetaTemplateService: se corta la paginación, el next no apunta al proxy.', [
                'host' => $host,
            ]);

            return null;
        }

        return $url;
    }

    /**
     * Arma una respuesta de fallo con la forma única de esta clase.
     *
     * @param string      $motivo
     * @param string|null $mensaje
     * @param int|null    $http
     *
     * @return array{ok: bool, motivo: string, mensaje: string|null, http: int|null, datos: array}
     */
    protected function falla(string $motivo, ?string $mensaje, ?int $http): array
    {
        return [
            'ok'      => false,
            'motivo'  => $motivo,
            'mensaje' => $mensaje,
            'http'    => $http,
            'datos'   => [],
        ];
    }
}
