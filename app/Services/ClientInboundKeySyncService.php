<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientApi;
use App\Services\Concerns\TapaLaClaveDelCliente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deja la clave de la API de un cliente (`clients.api_key`) escrita como `ADMIN_API_INBOUND_KEY` en
 * el `.env` de CADA frente (`ClientApi`) de ese cliente (misión cruzada `implementacion-dos-sistemas`,
 * 6/10/2026, ruta C1: `POST claude/clients/{id}/catalogo/clave`).
 *
 * 🔴 POR QUÉ EXISTE. El motor de `/categorizar` le habla a las rutas `admin-sync/catalogo/*` del
 * `empresa-api` del cliente, que se autentican con `X-Admin-Api-Key` = `ADMIN_API_INBOUND_KEY` de
 * ese `.env`. Esa clave ya la tiene el admin (`clients.api_key`, la genera al crear el cliente y la
 * manda en cada sincronización), así que no hace falta que viaje a la máquina de Lucas ni que él la
 * maneje: el admin la ESCRIBE en el servidor del cliente. Traer un secreto de un servidor a la PC
 * es justo lo que frena el clasificador, y con razón.
 *
 * 🔴 QUÉ HACE, POR CADA FRENTE (en shared son dos carpetas y una rotación puede activar cualquiera):
 *
 *   1. Lee el `.env` (por SSH, con `EnvSshService`, que ya resuelve shared_hosting y vps).
 *   2. Compara `ADMIN_API_INBOUND_KEY` con `clients.api_key` con `hash_equals`.
 *   3. Si no coincide y NO es `dry_run`: respalda el `.env` (`backup_env_for`) y escribe la variable
 *      (`write_env_vars_for`, que RELEE el archivo y lanza si no quedó escrita).
 *
 * Un frente que falla (SSH caído, sin permisos) queda como `estado: error` y NO frena a los demás.
 *
 * 🔴 Un frente cuyo `.env` ya tiene OTRA clave (`estado: distinta`) NO se pisa si no se pidió: aplicando sin
 * `pisar_distintas: true` queda `accion: ninguna` con el motivo en `error`, y `listo` en false. Esa otra
 * clave la puede estar usando alguien (una integración que el admin no conoce): reemplazarla es una decisión
 * que se toma a propósito. Un frente `falta` (sin la variable, o vacía) se escribe siempre. El `dry_run`
 * PREDICE lo mismo que haría aplicar con los mismos parámetros: sin el campo (o en false) un frente
 * `distinta` sale con `accion: ninguna` y ese mismo motivo; con `pisar_distintas: true`, con
 * `accion: escribir`.
 *
 * 🔴 Antes de escribir se verifica que el `.env` sea de ESTE cliente: si trae `USER_ID` y no coincide con
 * `clients.user_id` (comparado como texto y sin espacios), el frente queda `estado: error`
 * (`dueno_distinto`) y no se toca. Es la clase de error de una carpeta mal cargada en el admin, que apunta
 * al sistema de OTRO dueño: escribirle ahí la clave de este cliente sería pisar la de aquél. Un `.env` sin
 * `USER_ID` (los de base propia no lo necesitan) o con la variable vacía se sigue como siempre.
 *
 * 🔴 `es_la_activa` y `listo` (ver `calcular_listo()`). Cada frente de la respuesta dice si es EL frente al
 * que le habla el admin: el que resuelve `ClientEmpresaApiUrlResolver::resolve_client_api()`, o sea el MISMO
 * que usa el puente de catálogo y todas las sincronizaciones salientes (no una copia del criterio). Con el
 * frente activo determinado, `listo` es true cuando ESE frente tiene la clave (`igual`, o `escrita` en esta
 * llamada) y NINGÚN frente quedó en `falta` ni en `distinta` sin escribir; un frente INACTIVO en `error` o
 * `sin_env` se informa con su `error` pero NO traba: el puente no le habla, y si trabara dejaría al cliente
 * sin `listo` para siempre (`--aplicar` no arregla un `dueno_distinto` ni un path roto de una carpeta
 * vieja). El activo en `error` o `sin_env` deja `listo` en false. Si no se puede determinar cuál es el
 * activo (ningún frente tiene una URL válida), vale la regla de siempre: al menos un frente `igual` o
 * `escrita` y ninguno en `falta`, `distinta` o `error`, con `sin_env` neutral.
 *
 * 🔴 NUNCA lleva el valor de ninguna clave ni en la respuesta, ni en un mensaje de error, ni en un
 * log. Los textos que salen de excepciones de SSH pasan por `texto_seguro()`; el log lleva ids,
 * estados y acciones, nunca valores.
 *
 * Si `clients.api_key` está vacía: en `dry_run` lo dice (`falta`) y no guarda nada; aplicando, la
 * genera con `Str::random(40)` (igual que `ClientController`) y la GUARDA ANTES de escribir en el
 * primer servidor, para que un corte a mitad de camino no deje un `.env` con una clave que el admin
 * no conoce.
 *
 * El método público es UNO (`sincronizar`) y devuelve exactamente la forma del contrato con el
 * motor: `{client_id, dry_run, api_key_en_el_admin, frentes, listo}`.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
class ClientInboundKeySyncService
{
    use TapaLaClaveDelCliente;

    /**
     * La variable del `.env` del `empresa-api` del cliente que guarda la clave que exige a su admin.
     *
     * @var string
     */
    const VARIABLE = 'ADMIN_API_INBOUND_KEY';

    /**
     * La variable del `.env` que dice de qué dueño es el sistema (el `User` de `empresa-api`): tiene que
     * coincidir con `clients.user_id`. La usan las bases compartidas por varios comercios para saber a
     * qué dueño leerle los datos.
     *
     * @var string
     */
    const VARIABLE_DEL_DUENO = 'USER_ID';

    /**
     * Lo que dice `error` de un frente `distinta` que NO se escribe (aplicando) ni se escribiría (en dry_run)
     * porque no vino `pisar_distintas: true`. El texto es parte del contrato con el motor: se lo muestra a quien
     * opera, tal cual.
     *
     * @var string
     */
    const MENSAJE_DISTINTA_SIN_PISAR = 'tiene otra clave; para reemplazarla, pisar_distintas: true';

    /** Estado de la clave en el admin: ya estaba cargada. */
    const CLAVE_PRESENTE = 'presente';

    /** Estado de la clave en el admin: no hay ninguna (solo se dice, `dry_run`). */
    const CLAVE_FALTA = 'falta';

    /** Estado de la clave en el admin: no había y esta llamada la generó y la guardó. */
    const CLAVE_GENERADA = 'generada';

    /** Estado de un frente: su `.env` ya tiene la clave igual a la del admin. */
    const ESTADO_IGUAL = 'igual';

    /** Estado de un frente: su `.env` no tiene la variable (o la tiene vacía). */
    const ESTADO_FALTA = 'falta';

    /** Estado de un frente: su `.env` tiene la variable con OTRO valor. */
    const ESTADO_DISTINTA = 'distinta';

    /** Estado de un frente: el servidor no tiene `.env` en esa carpeta (no se crea ninguno desde acá). */
    const ESTADO_SIN_ENV = 'sin_env';

    /** Estado de un frente: no se pudo leer o escribir (SSH caído, permisos, ruta imposible de resolver). */
    const ESTADO_ERROR = 'error';

    /** Acción sobre un frente: no hace falta tocar nada, o no se puede. */
    const ACCION_NINGUNA = 'ninguna';

    /** Acción sobre un frente: es lo que se haría aplicando (solo en `dry_run`). */
    const ACCION_ESCRIBIR = 'escribir';

    /** Acción sobre un frente: se respaldó el `.env` y se escribió la variable (verificada releyendo). */
    const ACCION_ESCRITA = 'escrita';

    /** Acción sobre un frente: se intentó escribir y falló. */
    const ACCION_FALLO = 'fallo';

    /**
     * Segundos que se le permite correr a una llamada: leer, respaldar y escribir un `.env` por SSH
     * en dos o tres carpetas tarda segundos, pero un servidor lento no tiene que cortar a la mitad.
     *
     * @var int
     */
    const SEGUNDOS_MAXIMOS = 180;

    /**
     * Código de la excepción que lanza `sincronizar()` cuando la clave GENERADA no se pudo guardar en el
     * admin (falló la base). El controlador la reconoce por este código y contesta un 500 con código
     * estable (`clave_no_guardada`), sin el texto de la base.
     *
     * @var int
     */
    const CODIGO_CLAVE_NO_GUARDADA = 5201;

    /**
     * Texto FIJO de esa excepción: no lleva nada de lo que dijo la base, que puede traer la clave nueva.
     *
     * @var string
     */
    const MENSAJE_CLAVE_NO_GUARDADA = 'No se pudo guardar la clave nueva del cliente en el admin (falló la base de datos). '
        . 'No se escribió nada en ningún servidor: reintentá, y si se repite mirá el log del admin.';

    /**
     * Servicio que abre el SSH y opera el `.env` de cada frente.
     *
     * @var EnvSshService
     */
    protected $env_ssh_service;

    /**
     * Resuelve a qué frente (`ClientApi`) le habla el admin: el mismo criterio que usa el puente.
     *
     * @var ClientEmpresaApiUrlResolver
     */
    protected $api_url_resolver;

    /**
     * @param EnvSshService                    $env_ssh_service  Inyectable: en los tests es un fake en memoria.
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver Inyectable para las pruebas.
     */
    public function __construct(EnvSshService $env_ssh_service, ?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->env_ssh_service  = $env_ssh_service;
        $this->api_url_resolver = $api_url_resolver === null
            ? new ClientEmpresaApiUrlResolver()
            : $api_url_resolver;
    }

    /**
     * Mira (o escribe) la clave en el `.env` de todos los frentes del cliente.
     *
     * @param Client $client          Cliente dueño de la clave.
     * @param bool   $dry_run         true: solo mira y dice qué haría. false: escribe de verdad.
     * @param bool   $pisar_distintas true: también reemplaza la clave de un frente que ya tiene OTRA (en
     *                                dry_run, dice que la escribiría). false (default): ese frente no se
     *                                escribe, y el dry_run lo dice igual que aplicar.
     *
     * @return array{client_id: int, dry_run: bool, api_key_en_el_admin: string, frentes: array<int, array<string, mixed>>, listo: bool}
     */
    public function sincronizar(Client $client, bool $dry_run, bool $pisar_distintas = false): array
    {
        $this->levantar_limite_de_tiempo();

        $clave               = trim((string) $client->api_key);
        $api_key_en_el_admin = $clave === '' ? self::CLAVE_FALTA : self::CLAVE_PRESENTE;

        $frentes = ClientApi::query()->where('client_id', $client->id)->orderBy('id')->get();

        /* Aplicando y sin clave: se genera y se guarda ANTES de tocar ningún servidor. Y solo si hay
           algún frente donde escribirla: generar una clave que no va a ir a ningún lado es ruido. */
        if ($clave === '' && ! $dry_run && $frentes->count() > 0) {
            $clave               = $this->generar_y_guardar($client);
            $api_key_en_el_admin = self::CLAVE_GENERADA;
        }

        $resultado_de_frentes = [];
        $timestamp            = Carbon::now()->format('YmdHis');
        $user_id_del_cliente  = trim((string) $client->user_id);
        $id_del_frente_activo = $this->id_del_frente_activo($client);

        try {
            foreach ($frentes as $frente) {
                $es_la_activa = $id_del_frente_activo !== null && (int) $frente->id === $id_del_frente_activo;

                $resultado_de_frentes[] = $this->procesar_frente($frente, $clave, $dry_run, $timestamp, $user_id_del_cliente, $pisar_distintas, $es_la_activa);
            }
        } finally {
            /* La sesión SSH se cierra pase lo que pase: si algo revienta a mitad, no queda colgada. */
            $this->env_ssh_service->disconnect();
        }

        $listo = $this->calcular_listo($resultado_de_frentes);

        if (! $dry_run) {
            $this->registrar_en_el_log($client, $api_key_en_el_admin, $resultado_de_frentes, $listo, $pisar_distintas);
        }

        return [
            'client_id'           => (int) $client->id,
            'dry_run'             => $dry_run,
            'api_key_en_el_admin' => $api_key_en_el_admin,
            'frentes'             => $resultado_de_frentes,
            'listo'               => $listo,
        ];
    }

    /**
     * Resuelve un frente: lo lee, lo compara y, si corresponde y no es `dry_run`, lo escribe.
     *
     * No lanza: cualquier falla del servidor termina en `estado: error` de ESE frente.
     *
     * @param ClientApi $frente    Frente (carpeta) del cliente.
     * @param string    $clave     Clave vigente del cliente (puede ser '' en `dry_run` sin clave).
     * @param bool      $dry_run   true: no respalda ni escribe.
     * @param string    $timestamp Marca que nombra el respaldo del `.env`.
     * @param string    $user_id_del_cliente `clients.user_id` como texto y sin espacios ('' si no tiene).
     * @param bool      $pisar_distintas     true: un frente `distinta` también se escribe (o se escribiría, en dry_run).
     * @param bool      $es_la_activa        true: es el frente al que le habla el admin (ver `id_del_frente_activo()`).
     *
     * @return array<string, mixed> `{client_api_id, hosting_type, path, es_la_activa, estado, accion, error}`.
     */
    protected function procesar_frente(ClientApi $frente, $clave, $dry_run, $timestamp, $user_id_del_cliente = '', $pisar_distintas = false, $es_la_activa = false)
    {
        $fila = [
            'client_api_id' => (int) $frente->id,
            'hosting_type'  => (string) ($frente->hosting_type ? $frente->hosting_type : 'shared_hosting'),
            'path'          => null,
            'es_la_activa'  => (bool) $es_la_activa,
            'estado'        => self::ESTADO_ERROR,
            'accion'        => self::ACCION_NINGUNA,
            'error'         => null,
        ];

        /* 1. Dónde está el .env y qué tiene. Un fallo acá es del servidor, no un caso de negocio. */
        try {
            $fila['path'] = $this->env_ssh_service->get_api_path($frente);

            /* 🔴 Un frente de shared con el path vacío se resuelve a la RAÍZ de la cuenta compartida
               (`domains/comerciocity.com/public_html/`), donde viven las carpetas de TODOS los clientes
               —es cómo quedaron dos clientes en la migración al VPS, ver `ClientApiPathResolver`—. Ahí
               no se lee ni se escribe un .env, aunque exista uno. */
            if ($this->es_la_raiz_de_la_cuenta_compartida($fila['path'])) {
                $fila['error'] = 'El path de esta API está vacío y se resuelve a la raíz de la cuenta compartida: '
                    . 'no se opera ahí. Completá el path de la API en el admin.';

                return $fila;
            }

            /* 🔴 Y tampoco en un path que no se pueda identificar con certeza: segmentos vacíos, `.` o `..`
               (`x/..` se resuelve a la raíz de la cuenta, `../x` sale de la carpeta de los clientes). */
            $motivo_del_path = $this->motivo_de_path_no_confiable($frente, $clave);

            if ($motivo_del_path !== null) {
                $fila['error'] = $motivo_del_path;

                return $fila;
            }

            /* Existir se pregunta aparte de leer: un .env que no está NO es un error de lectura, y
               crearlo desde acá dejaría un archivo en el servidor equivocado (bug del 22/8/2026). */
            if (! $this->env_ssh_service->env_exists_for($frente)) {
                $fila['estado'] = self::ESTADO_SIN_ENV;
                $fila['error']  = 'Esta carpeta del cliente no tiene .env en el servidor: no se escribe nada '
                    . '(crearlo desde acá lo dejaría en un lugar que nadie revisó).';

                return $fila;
            }

            $env = $this->env_ssh_service->read_env_for($frente);
        } catch (\Throwable $e) {
            $fila['error'] = $this->texto_seguro($e->getMessage(), $clave);

            return $fila;
        }

        /* 🔴 De quién es este .env. Si trae USER_ID y no es el de ESTE cliente, la carpeta apunta al sistema de
           otro dueño (mal cargada en el admin): no se toca. Sin USER_ID, o vacío, se sigue como siempre. */
        $user_id_del_env = isset($env[self::VARIABLE_DEL_DUENO]) ? trim((string) $env[self::VARIABLE_DEL_DUENO]) : '';

        if ($user_id_del_env !== '' && $user_id_del_env !== $user_id_del_cliente) {
            $fila['error'] = 'dueno_distinto: el .env de esta carpeta tiene USER_ID ' . $this->texto_seguro($user_id_del_env, $clave, 40)
                . ' y este cliente tiene user_id ' . ($user_id_del_cliente === '' ? '(sin cargar)' : $this->texto_seguro($user_id_del_cliente, $clave, 40))
                . ' en el admin: es el sistema de otro dueño, o la carpeta está mal cargada. No se escribe nada.';

            return $fila;
        }

        /* 2. Qué tan igual es. */
        $actual         = isset($env[self::VARIABLE]) ? (string) $env[self::VARIABLE] : '';
        $fila['estado'] = $this->comparar($actual, $clave);

        if ($fila['estado'] === self::ESTADO_IGUAL) {
            return $fila;
        }

        /* 🔴 Un frente que ya tiene OTRA clave no se pisa si no se pidió: esa clave la puede estar usando alguien
           que el admin no conoce. Queda `distinta` (lo que se encontró), `accion: ninguna` y el motivo en
           `error`; `listo` queda en false porque ese frente no tiene la clave de este cliente. Va ANTES del corte
           del dry_run a propósito: el dry_run tiene que predecir lo mismo que haría aplicar con los mismos
           parámetros, y si dijera `escribir` para algo que aplicar no escribe, el que mira antes de aplicar se
           enteraría recién después. */
        if ($fila['estado'] === self::ESTADO_DISTINTA && ! $pisar_distintas) {
            $fila['error'] = self::MENSAJE_DISTINTA_SIN_PISAR;

            return $fila;
        }

        /* 3. Falta, o es distinta y se pidió pisarla: en dry_run se dice lo que se haría y se corta ahí. */
        if ($dry_run) {
            $fila['accion'] = self::ACCION_ESCRIBIR;

            return $fila;
        }

        /* 4. Aplicar: respaldo primero (si no queda escrito, backup_env_for lanza y no se escribe),
              después la variable. write_env_vars_for relee el archivo y lanza si no quedó. */
        try {
            $this->env_ssh_service->backup_env_for($frente, $timestamp);
            $this->env_ssh_service->write_env_vars_for($frente, [self::VARIABLE => $clave]);

            $fila['accion'] = self::ACCION_ESCRITA;
        } catch (\Throwable $e) {
            $fila['estado'] = self::ESTADO_ERROR;
            $fila['accion'] = self::ACCION_FALLO;
            $fila['error']  = $this->texto_seguro($e->getMessage(), $clave);
        }

        return $fila;
    }

    /**
     * ¿Quedó lista la clave para que el motor use el catálogo del cliente?
     *
     * 🔴 La regla es la del contrato con el motor, y depende de si se pudo determinar el frente ACTIVO (el
     * que marca `es_la_activa`: aquél al que le habla el puente):
     *
     *   Con frente activo determinado (`calcular_listo_con_activa()`):
     *   - `listo` es true cuando el frente ACTIVO tiene la clave (`estado: igual`, o `accion: escrita` en
     *     esta llamada) y NINGÚN frente está en `falta` ni en `distinta` sin escribir (un frente a medias
     *     es una rotación que se rompe: el día que la activen, no tiene la clave).
     *   - Un frente INACTIVO en `error` o `sin_env` no traba: se informa como siempre, con su `error`,
     *     pero el puente no le habla. Si trabara, un `dueno_distinto`, un path roto o un SSH caído en una
     *     carpeta vieja dejarían al cliente sin `listo` para siempre (`--aplicar` no lo arregla) y
     *     `/categorizar` quedaría trabado.
     *   - El ACTIVO en `error` o `sin_env` deja `listo` en false: el puente le habla a un frente sin clave.
     *
     *   Sin frente activo determinado (`calcular_listo_sin_activa()`): la regla de siempre, porque no hay
     *   a quién mirar: al menos un frente `igual` o `escrita` y ninguno en `falta`, `distinta` o `error`,
     *   con `sin_env` neutral y todos `sin_env` (o ninguno) en false.
     *
     *   En `dry_run` el cálculo es el mismo, sobre lo que se ENCONTRÓ: un frente en `falta` o `distinta`
     *   (activo o no) deja `listo` en false porque no se escribió nada; el `dry_run` dice lo que haría
     *   aplicar en `accion`, y aplicando esos mismos quedan `escrita`.
     *
     * Ojo con la lectura de `estado`: es lo que se ENCONTRÓ antes de actuar. Un frente que estaba en
     * `falta` o `distinta` y se escribió bien trae `accion: escrita` y cuenta como con la clave.
     *
     * @param array<int, array<string, mixed>> $frentes Resultado por frente (`es_la_activa`, `estado` y `accion`).
     *
     * @return bool
     */
    protected function calcular_listo(array $frentes)
    {
        foreach ($frentes as $fila) {
            if (! empty($fila['es_la_activa'])) {
                return $this->calcular_listo_con_activa($frentes);
            }
        }

        return $this->calcular_listo_sin_activa($frentes);
    }

    /**
     * La regla de `listo` cuando se sabe cuál es el frente activo (ver `calcular_listo()`).
     *
     * @param array<int, array<string, mixed>> $frentes Resultado por frente.
     *
     * @return bool
     */
    protected function calcular_listo_con_activa(array $frentes)
    {
        foreach ($frentes as $fila) {
            $con_la_clave = $fila['estado'] === self::ESTADO_IGUAL || $fila['accion'] === self::ACCION_ESCRITA;

            if (! empty($fila['es_la_activa'])) {
                /* El que atiende tiene que tener la clave: en error, sin .env, falta o distinta sin escribir, no hay listo. */
                if (! $con_la_clave) {
                    return false;
                }

                continue;
            }

            /* Un frente inactivo en error o sin .env se informa pero no traba: el puente no le habla. */
            if ($fila['estado'] === self::ESTADO_ERROR || $fila['estado'] === self::ESTADO_SIN_ENV) {
                continue;
            }

            /* Pero uno inactivo en falta o distinta sin escribir sí: quedaría a medias, sin la clave. */
            if (! $con_la_clave) {
                return false;
            }
        }

        return true;
    }

    /**
     * La regla de `listo` de siempre, para cuando NO se pudo determinar el frente activo: al menos un
     * frente con la clave y ninguno en `falta`, `distinta` o `error`; `sin_env` no cuenta ni a favor ni en
     * contra.
     *
     * @param array<int, array<string, mixed>> $frentes Resultado por frente.
     *
     * @return bool
     */
    protected function calcular_listo_sin_activa(array $frentes)
    {
        $con_la_clave = 0;

        foreach ($frentes as $fila) {
            if ($fila['estado'] === self::ESTADO_SIN_ENV) {
                continue;
            }

            if ($fila['estado'] === self::ESTADO_IGUAL || $fila['accion'] === self::ACCION_ESCRITA) {
                $con_la_clave++;

                continue;
            }

            /* falta o distinta sin escribir (dry_run), o error: en contra. */
            return false;
        }

        return $con_la_clave > 0;
    }

    /**
     * El id del frente al que le habla el admin, o null si no se puede determinar.
     *
     * 🔴 Sale de `ClientEmpresaApiUrlResolver::resolve_client_api()`, que recorre los MISMOS candidatos, en el
     * mismo orden y con el mismo filtro de "URL válida" que `resolve_base_url()`: la API activa del cliente si
     * tiene una URL válida; si no, la primera ClientApi con una URL válida. Es exactamente a la que le pega
     * el puente de catálogo (`ClientCatalogoPuenteService` usa `admin_sync_url()`, que usa `resolve_base_url()`).
     * No se mira `clients.active_client_api_id` a mano, que es lo que muestra la ficha de `claude/clients/{id}`
     * y no coincide cuando el cliente no tiene API activa cargada o la que tiene no trae una URL válida.
     *
     * Devuelve null si el resolver no elige ninguna ClientApi (ninguna tiene URL válida: gana el valor legacy
     * `clients.api_url`, o no hay ninguno), o si elige una que no es de este cliente (un dato mal cargado).
     *
     * @param Client $client Cliente.
     *
     * @return int|null
     */
    protected function id_del_frente_activo(Client $client)
    {
        $activa = $this->api_url_resolver->resolve_client_api($client);

        if ($activa === null || (int) $activa->client_id !== (int) $client->id) {
            return null;
        }

        return (int) $activa->id;
    }

    /**
     * Por qué el path de este frente no es confiable para operar sobre su `.env`, o null si lo es.
     *
     * 🔴 `ClientApiPathResolver::resolve()` arma la carpeta CONCATENANDO el path que cargó una persona en
     * el admin, sin normalizarlo. Un `path` con segmentos `.`, `..` o vacíos se resuelve a otro lado del
     * que parece: `x/..` es la raíz de la cuenta compartida (donde viven las carpetas de TODOS los
     * clientes), `../x` sale de ella, `./` es la raíz y `a//b` no es lo que alguien quiso escribir. En
     * VPS el `vps_path` es texto libre del CRUD y se pega entre `/home/api-` y `/empresa-api`: con una
     * barra o un `..` adentro la carpeta resuelta cae fuera de `/home/api-<nombre>`.
     *
     * Reglas:
     *  - shared: el path, sin las barras de los extremos (que no cambian dónde cae), no puede tener
     *    ningún segmento vacío, solo espacios, `.` ni `..`. Un path vacío o `/` ya lo frena antes la guarda
     *    de la raíz de la cuenta, con su propio mensaje.
     *  - vps: el `vps_path` tiene que ser un nombre simple: sin `/` ni `\`, y distinto de `.` y `..`.
     *
     * @param ClientApi $frente Frente (carpeta) del cliente.
     * @param string    $clave  Clave del cliente (el path sale en el mensaje, por las dudas, tapado).
     *
     * @return string|null El motivo, o null si el path sirve.
     */
    protected function motivo_de_path_no_confiable(ClientApi $frente, $clave)
    {
        if (($frente->hosting_type ? $frente->hosting_type : 'shared_hosting') === 'vps') {
            $vps_path = (string) $frente->vps_path;

            if (strpos($vps_path, '/') !== false || strpos($vps_path, '\\') !== false || in_array(trim($vps_path), ['.', '..'], true)) {
                return 'El vps_path de esta API ("' . $this->texto_seguro($vps_path, $clave, 60) . '") no es un nombre de carpeta simple '
                    . '(lleva "/" o "\\", o es "." o ".."): no se opera ahí. Corregí el vps_path de la API en el admin.';
            }

            return null;
        }

        foreach (explode('/', trim((string) $frente->path, '/')) as $segmento) {
            $segmento = trim($segmento);

            if ($segmento === '' || $segmento === '.' || $segmento === '..') {
                return 'El path de esta API ("' . $this->texto_seguro((string) $frente->path, $clave, 80) . '") tiene segmentos vacíos, "." o "..": '
                    . 'no se opera sobre una carpeta que no se puede identificar con certeza. Corregí el path de la API en el admin.';
            }
        }

        return null;
    }

    /**
     * ¿Es este directorio la raíz de la cuenta de hosting compartido, y no la carpeta de un cliente?
     *
     * @param string $path Directorio que resolvió `ClientApiPathResolver`.
     *
     * @return bool
     */
    protected function es_la_raiz_de_la_cuenta_compartida($path)
    {
        return rtrim((string) $path, '/') === rtrim(ClientApiPathResolver::PREFIJO_SHARED, '/');
    }

    /**
     * Compara el valor que tiene el `.env` con la clave del admin.
     *
     * 🔴 Una clave vacía del admin NUNCA es igual a nada: `hash_equals('', '')` da true, y darlo por
     * bueno diría "listo" para un frente que no tiene ninguna clave que valga.
     *
     * @param string $actual Valor de `ADMIN_API_INBOUND_KEY` en el `.env` ('' si no está).
     * @param string $clave  Clave del admin.
     *
     * @return string `igual` | `falta` | `distinta`.
     */
    protected function comparar($actual, $clave)
    {
        if ($actual === '') {
            return self::ESTADO_FALTA;
        }

        if ($clave === '') {
            return self::ESTADO_DISTINTA;
        }

        return hash_equals((string) $clave, (string) $actual) ? self::ESTADO_IGUAL : self::ESTADO_DISTINTA;
    }

    /**
     * Genera una clave de 40 caracteres y la guarda en el cliente, solo si sigue vacía.
     *
     * El UPDATE condicional (`WHERE api_key IS NULL OR TRIM(api_key) = ''`) es lo que evita que dos
     * llamadas simultáneas generen dos claves distintas y la segunda pise a la primera después de
     * que la primera ya la escribió en un servidor: la que pierde la carrera usa la clave que
     * quedó guardada.
     *
     * 🔴 Si la base falla, NO se deja salir la excepción de Laravel: una `QueryException` arma su
     * mensaje con el SQL y los BINDINGS, y los del UPDATE son la clave nueva en claro. Ese texto saldría
     * en la respuesta de error y en el log del handler. Se atrapa todo, se deja constancia de la clase
     * del error y de su SQLSTATE (que no trae valores) y se lanza una `RuntimeException` propia, con
     * texto fijo, un código que el controlador reconoce y SIN encadenar la original como `previous`
     * (el handler imprime también esa cadena).
     *
     * @param Client $client Cliente sin clave.
     *
     * @return string La clave que quedó guardada.
     *
     * @throws \RuntimeException Con `CODIGO_CLAVE_NO_GUARDADA` si la base falló.
     */
    protected function generar_y_guardar(Client $client)
    {
        $nueva = Str::random(40);

        try {
            $filas = $this->guardar_si_sigue_vacia($client, $nueva);

            $guardada = $filas > 0
                ? $nueva
                : trim((string) Client::query()->where('id', $client->id)->value('api_key'));
        } catch (\Throwable $e) {
            Log::error('ClientInboundKeySyncService: no se pudo guardar la clave generada del cliente.', [
                'client_id' => (int) $client->id,
                'excepcion' => get_class($e),
                'sqlstate'  => (string) $e->getCode(),
            ]);

            throw new \RuntimeException(self::MENSAJE_CLAVE_NO_GUARDADA, self::CODIGO_CLAVE_NO_GUARDADA);
        }

        /* El modelo en memoria refleja lo guardado, sin quedar marcado como modificado. */
        $client->setAttribute('api_key', $guardada);
        $client->syncOriginalAttribute('api_key');

        return $guardada;
    }

    /**
     * El UPDATE condicional que guarda la clave nueva solo si el cliente sigue sin ninguna.
     *
     * Es un método aparte para poder hacer fallar el guardado en un test sin tocar la base, y para que
     * `generar_y_guardar()` tenga UN solo lugar donde atrapar lo que diga la base.
     *
     * @param Client $client Cliente sin clave.
     * @param string $nueva  La clave generada.
     *
     * @return int Filas actualizadas: 1 si la guardó, 0 si otro pedido ya había guardado una.
     */
    protected function guardar_si_sigue_vacia(Client $client, $nueva)
    {
        return Client::query()
            ->where('id', $client->id)
            ->where(function ($sub) {
                $sub->whereNull('api_key')->orWhereRaw("TRIM(api_key) = ''");
            })
            ->update(['api_key' => $nueva]);
    }

    /**
     * Deja constancia de una aplicación en el log: ids, estados y acciones. NUNCA valores.
     *
     * @param Client                           $client               Cliente.
     * @param string                           $api_key_en_el_admin  presente | falta | generada.
     * @param array<int, array<string, mixed>> $frentes              Resultado por frente.
     * @param bool                             $listo                Si la clave quedó lista (ver `calcular_listo()`).
     * @param bool                             $pisar_distintas      Si el pedido autorizó reemplazar una clave distinta.
     *
     * @return void
     */
    protected function registrar_en_el_log(Client $client, $api_key_en_el_admin, array $frentes, $listo, $pisar_distintas = false)
    {
        $resumen = [];

        foreach ($frentes as $fila) {
            $resumen[] = [
                'client_api_id' => $fila['client_api_id'],
                'es_la_activa'  => $fila['es_la_activa'],
                'estado'        => $fila['estado'],
                'accion'        => $fila['accion'],
            ];
        }

        Log::info('ClientInboundKeySyncService: se aplicó la clave del admin en los frentes del cliente.', [
            'client_id'           => (int) $client->id,
            'api_key_en_el_admin' => $api_key_en_el_admin,
            'pisar_distintas'     => (bool) $pisar_distintas,
            'frentes'             => $resumen,
            'listo'               => $listo,
        ]);
    }

    /**
     * Sube el techo de tiempo de la llamada: varios frentes por SSH no se cortan a la mitad.
     *
     * @return void
     */
    protected function levantar_limite_de_tiempo()
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::SEGUNDOS_MAXIMOS);
        }
    }
}
