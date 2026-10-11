<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientApi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Prende, apaga y mira la EXIGENCIA de la clave de admin-sync en el `.env` de cada frente de un cliente (misión cruzada
 * `cerrar-admin-sync`, 10/10/2026, tarea A4: `POST claude/clients/{id}/admin-sync/exigir-clave`).
 *
 * 🔴 POR QUÉ EXISTE. El `empresa-api` de cada cliente tiene ~43 rutas `api/admin-sync/*` protegidas por el middleware
 * `AdminApiKey`, que NO valida nada mientras `ADMIN_SYNC_REQUIRE_API_KEY` esté apagada (el estado de toda la flota al 10/10).
 * Cerrarlas es escribir `ADMIN_SYNC_REQUIRE_API_KEY=true` (y `ADMIN_SYNC_SETUP_REQUIRE_API_KEY=true`, la del user setup) en el
 * `.env` de cada cliente: UNO A LA VEZ, con vuelta atrás. Editar `.env` en masa sin canario ya tumbó a todos los clientes una
 * vez; por eso el prendido tiene herramienta propia en lugar de escrituras sueltas, y esa herramienta PRUEBA que lo que hizo
 * funciona y se DESHACE sola si no.
 *
 * Extiende `ClientInboundKeySyncService` (el que escribe `ADMIN_API_INBOUND_KEY`) para heredar, sin copiarlas, las reglas que ya
 * están probadas: cuál es el frente activo, qué paths no son confiables, la raíz de la cuenta compartida y la comparación de
 * claves con `hash_equals`. No usa su `sincronizar()`.
 *
 * 🔴 TRES ACCIONES:
 *
 *   - `ver` (default): lee el `.env` de cada frente y dice qué tiene. NO respalda, NO escribe, NO limpia nada, y un frente que
 *     falla no frena la lectura de los demás.
 *   - `prender`: escribe las dos variables en `true` en TODOS los frentes (en shared hay dos carpetas y una rotación puede
 *     activar cualquiera), con respaldo del `.env` antes y borrado del config cacheado después, y PRUEBA contra el frente activo.
 *   - `apagar`: las deja en `false` explícito (no las borra: así queda apagada a propósito aun con la semántica nueva de
 *     `empresa-api`, donde "sin definir + clave cargada" ya exige).
 *
 * 🔴 PRECONDICIONES DE `prender`, todas o no escribe NADA:
 *   1. `clients.api_key` no vacía.
 *   2. Hay un frente activo contra el cual probar (el que resuelve `ClientEmpresaApiUrlResolver`).
 *   3. TODOS los frentes están en `clave: igual` (su `ADMIN_API_INBOUND_KEY` coincide con la del admin): un frente `falta`,
 *      `distinta`, `sin_env` o `error` aborta y se informa el motivo POR FRENTE. Exigir una clave que el frente no tiene deja
 *      al cliente sin canal con el admin.
 *   4. Ningún `.env` tiene un `USER_ID` que no sea el de este cliente (`dueno_distinto`, la misma regla que
 *      `ClientInboundKeySyncService`): es la carpeta de otro dueño.
 *
 * 🔴 LA CLAVE DE SALIDA (`ADMIN_API_OUTBOUND_KEY`, agregada el 11/10/2026). Con la exigencia prendida, `AdminReportHelper` y
 * `SupportSyncHelper` de `empresa-api` (los mensajes de soporte y los acuses de lectura de novedades, cliente → admin) hacen
 * `(bool) config('services.admin_api.require_api_key')` y, si es true y `services.admin_api.inbound_key` (= esa variable) está
 * vacía, devuelven false SIN mandar nada al admin. O sea que prender la exigencia corta esas salidas si el `.env` no la tiene. El
 * admin hoy no valida esa clave de entrada (`ADMIN_INBOUND_REQUIRE_API_KEY` apagado), así que escribirla es inocua, y su valor
 * correcto es `clients.inbound_api_key` (lo que busca el middleware `AdminInboundKey`). Por eso `prender` la asegura en CADA frente,
 * en la misma tanda respaldo → escritura → borrado del cache que las banderas:
 *   - si `clients.inbound_api_key` está vacía, se GENERA (`Str::random(40)`) y se GUARDA con un UPDATE condicional ANTES de escribir
 *     en el primer servidor (y solo después de que pasaron las precondiciones: un `prender` abortado no genera nada);
 *   - si el `.env` del frente ya la tiene con CUALQUIER valor no vacío, NO se toca (puede haber una integración con otra clave): se
 *     informa como `salida: cargada` y no se pisa.
 * 🔴 La vuelta atrás NO la restaura, y `apagar` tampoco la toca: es inocua y dejarla evita volver a cortar la salida. Cuando una
 * reversión se hace después de haberla escrito, el `motivo` lo dice.
 *
 * 🔴 LA PRUEBA (solo contra el frente ACTIVO, el único al que le habla el admin), después de escribir:
 *   (i)  `GET admin-sync/mensualidad-info` CON la clave: un 401 (la clave no entra), un 5xx, un 3xx o una falla de red →
 *        REVIERTE todo lo escrito (restaura los valores previos de las dos variables en cada frente y borra el config
 *        cacheado) y devuelve `estado: revertido`. Pasa con 2xx, o con 404 / 422 (versión que no tiene la ruta o la ruta se
 *        queja de un parámetro: la clave entró). Si da 404 se prueba con `GET admin-sync/employees` antes de darlo por
 *        pasado, y recién con los dos se acepta.
 *   (ii) `GET admin-sync/mensualidad-info` SIN el header: tiene que dar 401. Si da 2xx (o 422: la ruta se ejecutó) → `estado:
 *        sin_efecto` (config cacheado o una versión vieja del cliente): NO revierte, pero lo informa fuerte. Cualquier otra
 *        cosa (404, 5xx, red) → `sin_verificar`: tampoco revierte, y tampoco se puede afirmar que cerró.
 *
 * 🔴 VUELTA ATRÁS. Restaura el valor previo de cada variable en los frentes que ya se escribieron. Una variable que NO estaba
 * en el `.env` vuelve como `false` explícito (apagada a propósito): con el código actual de `empresa-api` es lo mismo que
 * ausente, y con la semántica nueva no deja la puerta cerrada por una clave cargada. Si la restauración de un frente falla,
 * el estado es `revertido_con_fallas` y el frente lo dice: eso hay que mirarlo a mano (el respaldo `.env.bak-<fecha>` existe).
 *
 * 🔴 NUNCA lleva el valor de ninguna clave ni en la respuesta, ni en un mensaje de error, ni en un log. Los textos que salen de
 * excepciones de SSH o de HTTP pasan por `texto_seguro()`; el log lleva ids, estados y acciones. Lo único que sale de un `.env`
 * son los booleanos de las dos exigencias.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en constructor, `readonly`, `enum`,
 * atributos, `mixed` ni `never`.
 */
class ClientAdminSyncClaveService extends ClientInboundKeySyncService
{
    /** La variable que prende la exigencia de la clave en todo el grupo `admin-sync`. */
    const VARIABLE_EXIGENCIA = 'ADMIN_SYNC_REQUIRE_API_KEY';

    /** La variable que prende la exigencia de la clave en el user setup (`admin.setup.key`). */
    const VARIABLE_EXIGENCIA_SETUP = 'ADMIN_SYNC_SETUP_REQUIRE_API_KEY';

    /** Acción: mirar, sin escribir nada. */
    const ACCION_VER = 'ver';

    /** Acción: exigir la clave. */
    const ACCION_PRENDER = 'prender';

    /** Acción: dejar de exigirla. */
    const ACCION_APAGAR = 'apagar';

    /** Las acciones que escriben (piden confirmar el nombre del cliente). */
    const ACCIONES_QUE_ESCRIBEN = [self::ACCION_PRENDER, self::ACCION_APAGAR];

    /** La variable del `.env` de empresa-api con la clave que ESE sistema le manda al admin (cliente → admin). */
    const VARIABLE_SALIDA = 'ADMIN_API_OUTBOUND_KEY';

    /** Salida: el `.env` tiene la variable con algún valor. */
    const SALIDA_CARGADA = 'cargada';

    /** Salida: el `.env` no tiene la variable, o la tiene vacía. */
    const SALIDA_FALTA = 'falta';

    /** Código de la excepción cuando la clave de salida generada no se pudo guardar en el admin. */
    const CODIGO_SALIDA_NO_GUARDADA = 5202;

    /** Exigencia: la variable no está en el `.env` (o está vacía). */
    const EXIGENCIA_SIN_DEFINIR = 'sin_definir';

    /** Exigencia: valor verdadero. */
    const EXIGENCIA_TRUE = 'true';

    /** Exigencia: valor falso. */
    const EXIGENCIA_FALSE = 'false';

    /** Exigencia: el `.env` tiene algo que no es un booleano reconocible. */
    const EXIGENCIA_INVALIDA = 'invalido';

    /** Estado global: `ver` terminó. */
    const ESTADO_VISTO = 'visto';

    /** Estado global: `prender` terminó y la prueba confirmó que cerró. */
    const ESTADO_PRENDIDO = 'prendido';

    /** Estado global: `apagar` terminó. */
    const ESTADO_APAGADO = 'apagado';

    /** Estado global: una precondición falló y no se escribió nada. */
    const ESTADO_ABORTADO = 'abortado';

    /** Estado global: se escribió, algo falló y se restauró todo lo escrito. */
    const ESTADO_REVERTIDO = 'revertido';

    /** Estado global: se escribió, algo falló y la restauración de algún frente TAMBIÉN falló: mirarlo a mano. */
    const ESTADO_REVERTIDO_CON_FALLAS = 'revertido_con_fallas';

    /** Estado global: quedó prendido pero un pedido SIN clave no dio 401 (cache de config o versión vieja). */
    const ESTADO_SIN_EFECTO = 'sin_efecto';

    /** Estado global: quedó prendido pero la prueba sin clave no pudo confirmar el 401 (ruta ausente, 5xx, red). */
    const ESTADO_SIN_VERIFICAR = 'sin_verificar';

    /** Acción sobre un frente: no se tocó. */
    const FRENTE_NINGUNA = 'ninguna';

    /** Acción sobre un frente: ya tenía los valores pedidos, no se escribió. */
    const FRENTE_YA_ESTABA = 'ya_estaba';

    /** Acción sobre un frente: se respaldó el `.env`, se escribieron las dos variables y se borró el config cacheado. */
    const FRENTE_ESCRITO = 'escrito';

    /** Acción sobre un frente: se restauraron los valores previos. */
    const FRENTE_REVERTIDO = 'revertido';

    /** Acción sobre un frente: se intentó escribir y falló. */
    const FRENTE_FALLO = 'fallo';

    /** Acción sobre un frente: se intentó restaurar y falló. */
    const FRENTE_REVERSION_FALLO = 'reversion_fallo';

    /**
     * Segundos de espera de CADA pedido de la prueba. Corto: son lecturas del cliente, no el trabajo pesado.
     *
     * @var int
     */
    const TIMEOUT_DE_LA_PRUEBA = 30;

    /**
     * Segundos que se le permite correr a una llamada: leer, respaldar y escribir tres carpetas por SSH más la prueba.
     *
     * @var int
     */
    const SEGUNDOS_MAXIMOS_DE_EXIGIR = 300;

    /**
     * Ruta (relativa al host de la API) con la que se prueba, y la de respaldo si la primera no existe en esa versión.
     *
     * @var string
     */
    const RUTA_DE_PRUEBA = 'api/admin-sync/mensualidad-info';

    /**
     * @var string
     */
    const RUTA_DE_PRUEBA_DE_RESPALDO = 'api/admin-sync/employees';

    /**
     * Ids de los frentes en los que ESTA llamada escribió `ADMIN_API_OUTBOUND_KEY` (para decir en el `motivo` que una reversión no la saca).
     *
     * @var array<int, int>
     */
    protected $salida_escrita = [];

    /**
     * Lo que leyó de cada frente y no sale en la respuesta: `client_api_id => ['frente' => ClientApi, 'previo' => [...]]`.
     *
     * @var array<int, array<string, mixed>>
     */
    protected $lecturas = [];

    /**
     * Mira, prende o apaga la exigencia de la clave en todos los frentes del cliente.
     *
     * @param Client $client Cliente.
     * @param string $accion `ver` | `prender` | `apagar` (el llamador ya la validó).
     *
     * @return array<string, mixed> `{client_id, accion, estado, listo, motivo, frentes[], prueba}`.
     */
    public function ejecutar(Client $client, $accion = self::ACCION_VER)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::SEGUNDOS_MAXIMOS_DE_EXIGIR);
        }

        $clave         = trim((string) $client->api_key);
        $frentes       = ClientApi::query()->where('client_id', $client->id)->orderBy('id')->get();
        $id_del_activo = $this->id_del_frente_activo($client);
        $timestamp     = Carbon::now()->format('YmdHis');
        $user_id       = trim((string) $client->user_id);
        $filas         = [];

        $this->lecturas       = [];
        $this->salida_escrita = [];

        try {
            /* 1. Leer todos los frentes. Un frente que falla queda como `error` y NO frena la lectura de los demás. */
            foreach ($frentes as $frente) {
                $filas[] = $this->leer_el_frente($frente, $clave, $user_id, $id_del_activo !== null && (int) $frente->id === $id_del_activo);
            }

            if ($accion === self::ACCION_PRENDER) {
                $resultado = $this->prender($client, $clave, $filas, $timestamp, $id_del_activo);
            } elseif ($accion === self::ACCION_APAGAR) {
                $resultado = $this->apagar($clave, $filas, $timestamp);
            } else {
                $resultado = $this->resultado(self::ACCION_VER, self::ESTADO_VISTO, $this->todos_con_la_clave($filas, $clave), null, $filas, null);
            }
        } finally {
            /* La sesión SSH se cierra pase lo que pase. */
            $this->env_ssh_service->disconnect();
        }

        if ($accion !== self::ACCION_VER) {
            $this->registrar_la_operacion($client, $resultado);
        }

        $resultado['client_id'] = (int) $client->id;

        return $resultado;
    }

    /* ==============================================================================================
     | Lectura de un frente
     |============================================================================================= */

    /**
     * Lee el `.env` de un frente y arma su fila de `ver`. No lanza: una falla del servidor es `clave: error`.
     *
     * @param ClientApi $frente       Frente (carpeta) del cliente.
     * @param string    $clave        Clave del admin (`clients.api_key`, puede ser '').
     * @param string    $user_id      `clients.user_id` como texto y sin espacios.
     * @param bool      $es_la_activa true: es el frente al que le habla el admin.
     *
     * @return array<string, mixed>
     */
    protected function leer_el_frente(ClientApi $frente, $clave, $user_id, $es_la_activa)
    {
        $fila = [
            'client_api_id'   => (int) $frente->id,
            'hosting_type'    => (string) ($frente->hosting_type ? $frente->hosting_type : 'shared_hosting'),
            'path'            => null,
            'es_la_activa'    => (bool) $es_la_activa,
            'clave'           => self::ESTADO_ERROR,
            'salida'          => self::ESTADO_ERROR,
            'exigencia'       => null,
            'exigencia_setup' => null,
            'accion'          => self::FRENTE_NINGUNA,
            'error'           => null,
        ];

        try {
            $fila['path'] = $this->env_ssh_service->get_api_path($frente);

            /* Los mismos tres cortes que `ClientInboundKeySyncService::procesar_frente()`: la raíz de la cuenta compartida,
               un path que no se puede identificar con certeza y un `.env` que no existe (no se crea ninguno desde acá). */
            if ($this->es_la_raiz_de_la_cuenta_compartida($fila['path'])) {
                $fila['error'] = 'El path de esta API está vacío y se resuelve a la raíz de la cuenta compartida: '
                    . 'no se opera ahí. Completá el path de la API en el admin.';

                return $fila;
            }

            $motivo_del_path = $this->motivo_de_path_no_confiable($frente, $clave);

            if ($motivo_del_path !== null) {
                $fila['error'] = $motivo_del_path;

                return $fila;
            }

            if (! $this->env_ssh_service->env_exists_for($frente)) {
                $fila['clave']  = self::ESTADO_SIN_ENV;
                $fila['salida'] = self::ESTADO_SIN_ENV;
                $fila['error']  = 'Esta carpeta del cliente no tiene .env en el servidor: no se escribe nada.';

                return $fila;
            }

            $env = $this->env_ssh_service->read_env_for($frente);
        } catch (\Throwable $e) {
            $fila['error'] = $this->texto_seguro($e->getMessage(), $clave);

            return $fila;
        }

        /* 🔴 De quién es este .env: si trae USER_ID y no es el de ESTE cliente, la carpeta apunta al sistema de otro dueño. */
        $user_id_del_env = isset($env[self::VARIABLE_DEL_DUENO]) ? trim((string) $env[self::VARIABLE_DEL_DUENO]) : '';

        if ($user_id_del_env !== '' && $user_id_del_env !== $user_id) {
            $fila['error'] = 'dueno_distinto: el .env de esta carpeta tiene USER_ID ' . $this->texto_seguro($user_id_del_env, $clave, 40)
                . ' y este cliente tiene user_id ' . ($user_id === '' ? '(sin cargar)' : $this->texto_seguro($user_id, $clave, 40))
                . ' en el admin: es el sistema de otro dueño, o la carpeta está mal cargada. No se escribe nada.';

            return $fila;
        }

        $actual = isset($env[self::VARIABLE]) ? (string) $env[self::VARIABLE] : '';

        $fila['clave']           = $this->comparar($actual, $clave);
        $fila['salida']          = isset($env[self::VARIABLE_SALIDA]) && trim((string) $env[self::VARIABLE_SALIDA]) !== ''
            ? self::SALIDA_CARGADA
            : self::SALIDA_FALTA;
        $fila['exigencia']       = $this->leer_la_exigencia($env, self::VARIABLE_EXIGENCIA);
        $fila['exigencia_setup'] = $this->leer_la_exigencia_de_setup($env, self::VARIABLE_EXIGENCIA_SETUP);

        /* Los valores PREVIOS crudos (o null si la variable no estaba), para poder restaurar exactamente lo que había. */
        $this->lecturas[(int) $frente->id] = [
            'frente' => $frente,
            'previo' => [
                self::VARIABLE_EXIGENCIA       => array_key_exists(self::VARIABLE_EXIGENCIA, $env) ? (string) $env[self::VARIABLE_EXIGENCIA] : null,
                self::VARIABLE_EXIGENCIA_SETUP => array_key_exists(self::VARIABLE_EXIGENCIA_SETUP, $env) ? (string) $env[self::VARIABLE_EXIGENCIA_SETUP] : null,
            ],
        ];

        return $fila;
    }

    /**
     * El valor de `ADMIN_SYNC_REQUIRE_API_KEY`: `sin_definir`, `true`, `false` o `invalido`.
     *
     * 🔴 ESTRICTO: solo es `true` o `false` si el texto es exactamente eso (sin importar mayúsculas, con o sin paréntesis: `(true)`,
     * que es lo que `env()` también convierte). Vacío o ausente es `sin_definir`. CUALQUIER otro texto (`off`, `no`, `1`, `0`,
     * `banana`) es `invalido`: en versiones viejas de `empresa-api` `env()` lo deja crudo y `"off"` es un string truthy, así que
     * decir `false` sería mentir sobre lo que la bandera hace en ese cliente.
     *
     * @param array<string, string> $env      El `.env` parseado.
     * @param string                $variable Nombre de la variable.
     *
     * @return string
     */
    protected function leer_la_exigencia(array $env, $variable)
    {
        if (! isset($env[$variable]) || trim((string) $env[$variable]) === '') {
            return self::EXIGENCIA_SIN_DEFINIR;
        }

        $valor = strtolower(trim((string) $env[$variable]));

        if ($valor === 'true' || $valor === '(true)') {
            return self::EXIGENCIA_TRUE;
        }

        if ($valor === 'false' || $valor === '(false)') {
            return self::EXIGENCIA_FALSE;
        }

        return self::EXIGENCIA_INVALIDA;
    }

    /**
     * El valor de `ADMIN_SYNC_SETUP_REQUIRE_API_KEY`: `sin_definir`, `true`, `false` o `invalido`.
     *
     * Esa bandera se lee en `empresa-api` con `filter_var(..., FILTER_VALIDATE_BOOLEAN)`, que acepta `1`, `true`, `on` y `yes` como
     * verdaderos: se informan igual. Lo que `filter_var` leería como falso sin ser un falso reconocible (`banana`) se informa como
     * `invalido` para que se vea, aunque en runtime sea falso.
     *
     * @param array<string, string> $env      El `.env` parseado.
     * @param string                $variable Nombre de la variable.
     *
     * @return string
     */
    protected function leer_la_exigencia_de_setup(array $env, $variable)
    {
        if (! isset($env[$variable]) || trim((string) $env[$variable]) === '') {
            return self::EXIGENCIA_SIN_DEFINIR;
        }

        $valor = strtolower(trim((string) $env[$variable]));

        if (in_array($valor, ['true', '(true)', '1', 'on', 'yes'], true)) {
            return self::EXIGENCIA_TRUE;
        }

        if (in_array($valor, ['false', '(false)', '0', 'off', 'no', 'null', '(null)'], true)) {
            return self::EXIGENCIA_FALSE;
        }

        return self::EXIGENCIA_INVALIDA;
    }

    /**
     * ¿Todos los frentes tienen la clave del admin (`clave: igual`) y el admin tiene una? Es lo que `ver` llama `listo`: el
     * cliente está en condiciones de que se le prenda la exigencia.
     *
     * @param array<int, array<string, mixed>> $filas Filas de los frentes.
     * @param string                           $clave Clave del admin.
     *
     * @return bool
     */
    protected function todos_con_la_clave(array $filas, $clave)
    {
        if ($clave === '' || count($filas) === 0) {
            return false;
        }

        foreach ($filas as $fila) {
            if ($fila['clave'] !== self::ESTADO_IGUAL) {
                return false;
            }
        }

        return true;
    }

    /* ==============================================================================================
     | prender
     |============================================================================================= */

    /**
     * Prende la exigencia en todos los frentes, la prueba y, si no anda, la deshace.
     *
     * @param Client                           $client        Cliente.
     * @param string                           $clave         Clave del admin.
     * @param array<int, array<string, mixed>> $filas         Filas ya leídas.
     * @param string                           $timestamp     Marca del respaldo.
     * @param int|null                         $id_del_activo Id del frente activo.
     *
     * @return array<string, mixed>
     */
    protected function prender(Client $client, $clave, array $filas, $timestamp, $id_del_activo)
    {
        $motivo = $this->motivo_para_no_prender($clave, $filas, $id_del_activo);

        if ($motivo !== null) {
            return $this->resultado(self::ACCION_PRENDER, self::ESTADO_ABORTADO, false, $motivo, $filas, null);
        }

        /* 1-bis. La clave de salida: si algún frente no la tiene y el cliente no tiene `inbound_api_key`, se genera y se GUARDA ahora, ANTES
           de escribir en el primer servidor. Las precondiciones ya pasaron: un `prender` abortado no deja nada generado. */
        $clave_de_salida = '';
        $hay_que_escribir_salida = false;

        foreach ($filas as $fila) {
            if ($fila['salida'] === self::SALIDA_FALTA) {
                $hay_que_escribir_salida = true;
            }
        }

        if ($hay_que_escribir_salida) {
            try {
                $clave_de_salida = trim((string) $client->inbound_api_key);

                if ($clave_de_salida === '') {
                    $clave_de_salida = $this->generar_y_guardar_la_clave_de_salida($client);
                }
            } catch (\Throwable $e) {
                return $this->resultado(
                    self::ACCION_PRENDER,
                    self::ESTADO_ABORTADO,
                    false,
                    'clave_de_salida: ' . $this->texto_seguro($e->getMessage(), $clave) . ' No se escribió nada en ningún servidor.',
                    $filas,
                    null
                );
            }
        }

        /* 2. Escribir, frente por frente. Cualquier falla → se restaura lo ya escrito y se corta. */
        $escritos = [];

        foreach ($filas as $indice => $fila) {
            /* Un frente que ya exige las dos y ya tiene la clave de salida no se vuelve a escribir (ni se respalda de nuevo): no hay
               nada que deshacer ahí. */
            if ($fila['exigencia'] === self::EXIGENCIA_TRUE && $fila['exigencia_setup'] === self::EXIGENCIA_TRUE
                && $fila['salida'] === self::SALIDA_CARGADA) {
                $filas[$indice]['accion'] = self::FRENTE_YA_ESTABA;

                continue;
            }

            $tocado = false;
            $extra  = $fila['salida'] === self::SALIDA_FALTA ? [self::VARIABLE_SALIDA => $clave_de_salida] : [];

            try {
                $this->escribir_las_dos($fila['client_api_id'], 'true', $timestamp, $tocado, $extra);

                $filas[$indice]['accion']          = self::FRENTE_ESCRITO;
                $filas[$indice]['exigencia']       = self::EXIGENCIA_TRUE;
                $filas[$indice]['exigencia_setup'] = self::EXIGENCIA_TRUE;
                $filas[$indice]['salida']          = self::SALIDA_CARGADA;
                $escritos[]                        = $indice;

                if (count($extra) > 0) {
                    $this->salida_escrita[] = (int) $fila['client_api_id'];
                }
            } catch (\Throwable $e) {
                $filas[$indice]['accion'] = self::FRENTE_FALLO;
                $filas[$indice]['error']  = $this->texto_seguro($e->getMessage(), $clave);

                /* Si falló DESPUÉS del respaldo (la escritura o el borrado del cache) el `.env` pudo haber cambiado: este
                   frente también se restaura. Si falló el respaldo, no se tocó nada y no hay nada que restaurar. */
                if ($tocado) {
                    $escritos[] = $indice;
                }

                return $this->deshacer(self::ACCION_PRENDER, $clave, $filas, $escritos, 'No se pudo escribir en el frente ' . $fila['client_api_id'] . '; se restauró lo escrito.', null);
            }
        }

        /* 3. La prueba, contra el frente ACTIVO. */
        $prueba = $this->probar($client, $clave);

        if (! $prueba['con_clave_pasa']) {
            return $this->deshacer(self::ACCION_PRENDER, $clave, $filas, $escritos, $prueba['motivo'], $prueba['detalle']);
        }

        $estado = self::ESTADO_PRENDIDO;
        $motivo = null;

        if ($prueba['sin_clave'] === 'sin_efecto') {
            $estado = self::ESTADO_SIN_EFECTO;
            $motivo = 'Quedó escrito pero NO tiene efecto: un pedido SIN la clave a admin-sync/mensualidad-info dio '
                . $prueba['detalle']['sin_clave']['status'] . ' en vez de 401. O el config del cliente sigue cacheado, o su versión es anterior al middleware que valida.';
        } elseif ($prueba['sin_clave'] !== 'cerrada') {
            $estado = self::ESTADO_SIN_VERIFICAR;
            $motivo = 'Quedó escrito y la clave entra, pero no se pudo confirmar que un pedido SIN la clave dé 401 (' . $prueba['detalle']['sin_clave']['resultado'] . ').';
        }

        return $this->resultado(self::ACCION_PRENDER, $estado, $estado === self::ESTADO_PRENDIDO, $motivo, $filas, $prueba['detalle']);
    }

    /**
     * Por qué NO se puede prender (o null si se puede). Se evalúa ANTES de escribir nada, sobre lo leído de TODOS los frentes.
     *
     * @param string                           $clave         Clave del admin.
     * @param array<int, array<string, mixed>> $filas         Filas leídas.
     * @param int|null                         $id_del_activo Id del frente activo.
     *
     * @return string|null
     */
    protected function motivo_para_no_prender($clave, array $filas, $id_del_activo)
    {
        if ($clave === '') {
            return 'sin_api_key: el cliente no tiene api_key en el admin, así que no hay clave que exigir. '
                . 'Cargala con POST claude/clients/{id}/catalogo/clave. No se escribió nada.';
        }

        if ($id_del_activo === null) {
            return 'sin_frente_activo: ninguna API del cliente tiene una URL válida, así que no hay contra qué probar que la exigencia '
                . 'funcione. No se escribió nada.';
        }

        $motivos = [];

        foreach ($filas as $fila) {
            if ($fila['clave'] !== self::ESTADO_IGUAL) {
                $detalle   = $fila['error'] !== null ? ': ' . $fila['error'] : '';
                $motivos[] = 'frente ' . $fila['client_api_id'] . ' con clave ' . $fila['clave'] . $detalle;
            }
        }

        if (count($motivos) > 0) {
            return 'precondicion: todos los frentes tienen que estar con la clave `igual` antes de exigirla; ' . implode(' | ', $motivos)
                . '. No se escribió nada.';
        }

        return null;
    }

    /**
     * Respalda el `.env` de un frente, escribe las dos variables y borra el config cacheado.
     *
     * @param int    $client_api_id Frente.
     * @param string $valor         `true` | `false`.
     * @param string $timestamp     Marca del respaldo.
     * @param bool   $tocado        Por referencia: pasa a true apenas el respaldo quedó hecho, o sea cuando el `.env` YA puede cambiar.
     *                              Si el respaldo falla queda en false: no se tocó nada.
     * @param array<string, string> $extra Variables de más que van en LA MISMA escritura (la clave de salida, en `prender`).
     *
     * @return void
     *
     * @throws \Throwable Si el servidor falla: el llamador decide si restaurar.
     */
    protected function escribir_las_dos($client_api_id, $valor, $timestamp, &$tocado, array $extra = [])
    {
        $frente = $this->lecturas[$client_api_id]['frente'];

        $this->env_ssh_service->backup_env_for($frente, $timestamp);

        $tocado = true;

        $this->env_ssh_service->write_env_vars_for($frente, array_merge([
            self::VARIABLE_EXIGENCIA       => $valor,
            self::VARIABLE_EXIGENCIA_SETUP => $valor,
        ], $extra));
        $this->env_ssh_service->limpiar_cache_de_config_for($frente);
    }

    /**
     * Genera `clients.inbound_api_key` (40 caracteres, igual que `api_key`) y la guarda SOLO si sigue vacía.
     *
     * Mismo patrón que `ClientInboundKeySyncService::generar_y_guardar()`: el UPDATE condicional evita que dos llamadas simultáneas
     * generen dos claves distintas (la que pierde usa la guardada), y si la base falla NO se deja salir la excepción de Laravel (arma
     * su mensaje con el SQL y los bindings, y el del UPDATE es la clave nueva en claro): se lanza una `RuntimeException` de texto fijo.
     *
     * @param Client $client Cliente sin `inbound_api_key`.
     *
     * @return string La clave que quedó guardada.
     *
     * @throws \RuntimeException Con `CODIGO_SALIDA_NO_GUARDADA` si la base falló.
     */
    protected function generar_y_guardar_la_clave_de_salida(Client $client)
    {
        $nueva = \Illuminate\Support\Str::random(40);

        try {
            $filas = $this->guardar_la_clave_de_salida_si_sigue_vacia($client, $nueva);

            $guardada = $filas > 0
                ? $nueva
                : trim((string) Client::query()->where('id', $client->id)->value('inbound_api_key'));
        } catch (\Throwable $e) {
            Log::error('ClientAdminSyncClaveService: no se pudo guardar la clave de salida generada del cliente.', [
                'client_id' => (int) $client->id,
                'excepcion' => get_class($e),
                'sqlstate'  => (string) $e->getCode(),
            ]);

            throw new \RuntimeException(
                'No se pudo guardar la clave de salida nueva del cliente en el admin (falló la base de datos).',
                self::CODIGO_SALIDA_NO_GUARDADA
            );
        }

        if ($guardada === '') {
            throw new \RuntimeException('La clave de salida nueva del cliente no quedó guardada en el admin.', self::CODIGO_SALIDA_NO_GUARDADA);
        }

        $client->setAttribute('inbound_api_key', $guardada);
        $client->syncOriginalAttribute('inbound_api_key');

        return $guardada;
    }

    /**
     * El UPDATE condicional que guarda la clave de salida nueva solo si el cliente sigue sin ninguna. Aparte para poder hacerlo fallar
     * en un test.
     *
     * @param Client $client Cliente.
     * @param string $nueva  La clave generada.
     *
     * @return int Filas actualizadas.
     */
    protected function guardar_la_clave_de_salida_si_sigue_vacia(Client $client, $nueva)
    {
        return Client::query()
            ->where('id', $client->id)
            ->where(function ($sub) {
                $sub->whereNull('inbound_api_key')->orWhereRaw("TRIM(inbound_api_key) = ''");
            })
            ->update(['inbound_api_key' => $nueva]);
    }

    /* ==============================================================================================
     | apagar
     |============================================================================================= */

    /**
     * Deja la exigencia en `false` explícito en todos los frentes.
     *
     * Precondición: todos los frentes se pudieron leer (un `.env` ilegible o de otro dueño aborta sin escribir). NO exige la
     * clave: apagar tiene que poder hacerse justo cuando la clave está mal.
     *
     * @param string                           $clave     Clave del admin.
     * @param array<int, array<string, mixed>> $filas     Filas leídas.
     * @param string                           $timestamp Marca del respaldo.
     *
     * @return array<string, mixed>
     */
    protected function apagar($clave, array $filas, $timestamp)
    {
        $motivos = [];

        foreach ($filas as $fila) {
            if (! isset($this->lecturas[$fila['client_api_id']])) {
                $motivos[] = 'frente ' . $fila['client_api_id'] . ($fila['error'] !== null ? ': ' . $fila['error'] : ': no se pudo leer su .env');
            }
        }

        if (count($filas) === 0 || count($motivos) > 0) {
            $motivo = count($motivos) > 0
                ? 'precondicion: no se pudo leer el .env de todos los frentes; ' . implode(' | ', $motivos) . '. No se escribió nada.'
                : 'sin_frentes: el cliente no tiene ninguna API cargada. No se escribió nada.';

            return $this->resultado(self::ACCION_APAGAR, self::ESTADO_ABORTADO, false, $motivo, $filas, null);
        }

        $escritos = [];

        foreach ($filas as $indice => $fila) {
            $tocado = false;

            try {
                $this->escribir_las_dos($fila['client_api_id'], 'false', $timestamp, $tocado);

                $filas[$indice]['accion']          = self::FRENTE_ESCRITO;
                $filas[$indice]['exigencia']       = self::EXIGENCIA_FALSE;
                $filas[$indice]['exigencia_setup'] = self::EXIGENCIA_FALSE;
                $escritos[]                        = $indice;
            } catch (\Throwable $e) {
                $filas[$indice]['accion'] = self::FRENTE_FALLO;
                $filas[$indice]['error']  = $this->texto_seguro($e->getMessage(), $clave);

                if ($tocado) {
                    $escritos[] = $indice;
                }

                return $this->deshacer(self::ACCION_APAGAR, $clave, $filas, $escritos, 'No se pudo escribir en el frente ' . $fila['client_api_id'] . '; se restauró lo escrito.', null);
            }
        }

        return $this->resultado(self::ACCION_APAGAR, self::ESTADO_APAGADO, true, null, $filas, null);
    }

    /* ==============================================================================================
     | La prueba
     |============================================================================================= */

    /**
     * Prueba contra el frente ACTIVO que la exigencia funciona: con la clave tiene que entrar, sin ella tiene que dar 401.
     *
     * @param Client $client Cliente.
     * @param string $clave  Clave del admin.
     *
     * @return array{con_clave_pasa: bool, sin_clave: string, motivo: string|null, detalle: array<string, mixed>}
     */
    protected function probar(Client $client, $clave)
    {
        $detalle = ['con_clave' => null, 'sin_clave' => null];

        /* (i) CON la clave. */
        $con = $this->pedir($client, self::RUTA_DE_PRUEBA, $clave);

        if ($con['status'] === 404) {
            /* Una versión sin `mensualidad-info`: se prueba con otra ruta del mismo grupo antes de darla por pasada. */
            $respaldo = $this->pedir($client, self::RUTA_DE_PRUEBA_DE_RESPALDO, $clave);

            if ($respaldo['status'] !== 404) {
                $con = $respaldo;
            }
        }

        $detalle['con_clave'] = ['ruta' => $con['ruta'], 'status' => $con['status'], 'resultado' => $con['resultado']];

        $pasa = $con['status'] !== null
            && (($con['status'] >= 200 && $con['status'] < 300) || $con['status'] === 404 || $con['status'] === 422);

        if (! $pasa) {
            return [
                'con_clave_pasa' => false,
                'sin_clave'      => 'no_corrio',
                'motivo'         => 'La prueba CON la clave falló (' . $con['resultado'] . '): la exigencia deja al cliente sin canal con el admin. Se revirtió todo lo escrito.',
                'detalle'        => $detalle,
            ];
        }

        /* (ii) SIN la clave: tiene que dar 401. */
        $sin = $this->pedir($client, self::RUTA_DE_PRUEBA, null);

        $detalle['sin_clave'] = ['ruta' => $sin['ruta'], 'status' => $sin['status'], 'resultado' => $sin['resultado']];

        if ($sin['status'] === 401) {
            $veredicto = 'cerrada';
        } elseif ($sin['status'] !== null && (($sin['status'] >= 200 && $sin['status'] < 300) || $sin['status'] === 422)) {
            $veredicto = 'sin_efecto';
        } else {
            $veredicto = 'sin_verificar';
        }

        $detalle['sin_clave']['veredicto'] = $veredicto;

        return ['con_clave_pasa' => true, 'sin_clave' => $veredicto, 'motivo' => null, 'detalle' => $detalle];
    }

    /**
     * Un GET de la prueba al empresa-api del cliente, con o sin la clave. Sin seguir redirecciones, sin reintentar.
     *
     * @param Client      $client Cliente.
     * @param string      $ruta   Ruta relativa al host de la API (`api/admin-sync/mensualidad-info`).
     * @param string|null $clave  La clave a mandar en `X-Admin-Api-Key`; null = sin el header.
     *
     * @return array{ruta: string, status: int|null, resultado: string}
     */
    protected function pedir(Client $client, $ruta, $clave)
    {
        $url = $this->api_url_resolver->admin_sync_url($client, $ruta);

        if ($url === '') {
            return ['ruta' => $ruta, 'status' => null, 'resultado' => 'sin URL válida del empresa-api'];
        }

        $headers = ['Accept' => 'application/json'];

        if ($clave !== null) {
            $headers['X-Admin-Api-Key'] = $clave;
        }

        try {
            $response = Http::withHeaders($headers)
                ->withOptions(['allow_redirects' => false])
                ->timeout(self::TIMEOUT_DE_LA_PRUEBA)
                ->get($url);
        } catch (\Throwable $e) {
            $error = ClientCatalogoPuenteService::sin_la_query_de_las_urls($e->getMessage());

            return [
                'ruta'      => $ruta,
                'status'    => null,
                'resultado' => 'sin respuesta: ' . $this->texto_seguro($error, (string) $client->api_key, 200),
            ];
        }

        $status = (int) $response->status();

        return ['ruta' => $ruta, 'status' => $status, 'resultado' => 'HTTP ' . $status];
    }

    /* ==============================================================================================
     | Vuelta atrás
     |============================================================================================= */

    /**
     * Restaura los valores previos de las dos variables en los frentes ya escritos y arma el resultado.
     *
     * Va en orden inverso al de la escritura. Una variable que no estaba en el `.env` vuelve como `false` explícito. Un
     * frente que no se puede restaurar queda `reversion_fallo` y el estado global pasa a `revertido_con_fallas`.
     *
     * @param string                           $accion   `prender` | `apagar`.
     * @param string                           $clave    Clave del admin.
     * @param array<int, array<string, mixed>> $filas    Filas con lo hecho hasta acá.
     * @param array<int, int>                  $escritos Índices (de `$filas`) de los frentes tocados, en orden de escritura.
     * @param string                           $motivo   Por qué se deshace.
     * @param array<string, mixed>|null        $prueba   Detalle de la prueba, si llegó a correr.
     *
     * @return array<string, mixed>
     */
    protected function deshacer($accion, $clave, array $filas, array $escritos, $motivo, $prueba)
    {
        $con_fallas = false;

        foreach (array_reverse($escritos) as $indice) {
            $id     = $filas[$indice]['client_api_id'];
            $frente = $this->lecturas[$id]['frente'];
            $previo = $this->lecturas[$id]['previo'];

            $valores = [];

            foreach ($previo as $variable => $valor_previo) {
                $valores[$variable] = $valor_previo === null ? 'false' : $valor_previo;
            }

            try {
                $this->env_ssh_service->write_env_vars_for($frente, $valores);
                $this->env_ssh_service->limpiar_cache_de_config_for($frente);

                $filas[$indice]['accion']          = self::FRENTE_REVERTIDO;
                $filas[$indice]['exigencia']       = $this->leer_la_exigencia($valores, self::VARIABLE_EXIGENCIA);
                $filas[$indice]['exigencia_setup'] = $this->leer_la_exigencia_de_setup($valores, self::VARIABLE_EXIGENCIA_SETUP);
            } catch (\Throwable $e) {
                $con_fallas                  = true;
                $filas[$indice]['accion']    = self::FRENTE_REVERSION_FALLO;
                $filas[$indice]['error']     = $this->texto_seguro(
                    'No se pudo restaurar el .env (hay un respaldo .env.bak-* en la carpeta): ' . $e->getMessage(),
                    $clave
                );
            }
        }

        $estado = $con_fallas ? self::ESTADO_REVERTIDO_CON_FALLAS : self::ESTADO_REVERTIDO;

        /* 🔴 La reversión restaura SOLO las dos banderas: `ADMIN_API_OUTBOUND_KEY` se deja donde se escribió (es inocua, y sacarla
           volvería a cortar la salida cliente → admin el día que se prenda la exigencia). */
        if (count($this->salida_escrita) > 0) {
            $motivo .= ' La clave de salida (ADMIN_API_OUTBOUND_KEY) escrita en el frente ' . implode(', ', $this->salida_escrita)
                . ' NO se revierte: es inocua y dejarla evita volver a cortar la salida.';
        }

        if ($con_fallas) {
            $motivo .= ' ATENCIÓN: la restauración de al menos un frente falló; revisalo a mano.';
        }

        return $this->resultado($accion, $estado, false, $motivo, $filas, $prueba);
    }

    /* ==============================================================================================
     | Respuesta y log
     |============================================================================================= */

    /**
     * Arma la respuesta común.
     *
     * @param string                           $accion `ver` | `prender` | `apagar`.
     * @param string                           $estado Estado global.
     * @param bool                             $listo  Ver el docblock del controlador.
     * @param string|null                      $motivo Por qué no salió, si no salió.
     * @param array<int, array<string, mixed>> $filas  Frentes.
     * @param array<string, mixed>|null        $prueba Detalle de la prueba.
     *
     * @return array<string, mixed>
     */
    protected function resultado($accion, $estado, $listo, $motivo, array $filas, $prueba)
    {
        return [
            'accion'  => $accion,
            'estado'  => $estado,
            'listo'   => (bool) $listo,
            'motivo'  => $motivo,
            'frentes' => array_values($filas),
            'prueba'  => $prueba,
        ];
    }

    /**
     * Deja constancia de una operación que escribe: ids, estados y acciones. NUNCA valores.
     *
     * @param Client               $client    Cliente.
     * @param array<string, mixed> $resultado Lo que se devuelve.
     *
     * @return void
     */
    protected function registrar_la_operacion(Client $client, array $resultado)
    {
        $resumen = [];

        foreach ($resultado['frentes'] as $fila) {
            $resumen[] = [
                'client_api_id' => $fila['client_api_id'],
                'es_la_activa'  => $fila['es_la_activa'],
                'clave'         => $fila['clave'],
                'salida'        => $fila['salida'],
                'accion'        => $fila['accion'],
            ];
        }

        Log::info('ClientAdminSyncClaveService: ' . $resultado['accion'] . ' la exigencia de la clave de admin-sync.', [
            'client_id' => (int) $client->id,
            'accion'    => $resultado['accion'],
            'estado'    => $resultado['estado'],
            'listo'     => $resultado['listo'],
            'frentes'   => $resumen,
        ]);
    }
}
