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
 * 🔴 `listo` (ver `calcular_listo()`) es true cuando hay AL MENOS UN frente con la clave igual o recién
 * escrita y NINGUNO en `falta`, `distinta` o `error`; un frente `sin_env` no cuenta ni a favor ni en
 * contra. Así una carpeta que nunca se instaló (la segunda de un cliente de shared) no deja al cliente
 * sin `listo` para siempre cuando el frente que sirve tráfico ya tiene la clave.
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
     * @param EnvSshService $env_ssh_service Inyectable: en los tests es un fake en memoria.
     */
    public function __construct(EnvSshService $env_ssh_service)
    {
        $this->env_ssh_service = $env_ssh_service;
    }

    /**
     * Mira (o escribe) la clave en el `.env` de todos los frentes del cliente.
     *
     * @param Client $client  Cliente dueño de la clave.
     * @param bool   $dry_run true: solo mira y dice qué haría. false: escribe de verdad.
     *
     * @return array{client_id: int, dry_run: bool, api_key_en_el_admin: string, frentes: array<int, array<string, mixed>>, listo: bool}
     */
    public function sincronizar(Client $client, bool $dry_run): array
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

        try {
            foreach ($frentes as $frente) {
                $resultado_de_frentes[] = $this->procesar_frente($frente, $clave, $dry_run, $timestamp);
            }
        } finally {
            /* La sesión SSH se cierra pase lo que pase: si algo revienta a mitad, no queda colgada. */
            $this->env_ssh_service->disconnect();
        }

        $listo = $this->calcular_listo($resultado_de_frentes);

        if (! $dry_run) {
            $this->registrar_en_el_log($client, $api_key_en_el_admin, $resultado_de_frentes, $listo);
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
     *
     * @return array<string, mixed> `{client_api_id, hosting_type, path, estado, accion, error}`.
     */
    protected function procesar_frente(ClientApi $frente, $clave, $dry_run, $timestamp)
    {
        $fila = [
            'client_api_id' => (int) $frente->id,
            'hosting_type'  => (string) ($frente->hosting_type ? $frente->hosting_type : 'shared_hosting'),
            'path'          => null,
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

        /* 2. Qué tan igual es. */
        $actual         = isset($env[self::VARIABLE]) ? (string) $env[self::VARIABLE] : '';
        $fila['estado'] = $this->comparar($actual, $clave);

        if ($fila['estado'] === self::ESTADO_IGUAL) {
            return $fila;
        }

        /* 3. Falta o es distinta: en dry_run se dice lo que se haría y se corta ahí. */
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
     * 🔴 La regla es la del contrato con el motor (6/10/2026):
     *
     *   - `listo` es true cuando hay AL MENOS UN frente con la clave igual (`estado: igual`) o recién
     *     escrita (`accion: escrita`), y NINGÚN frente en `falta`, `distinta` o `error`.
     *   - Un frente `sin_env` no cuenta ni a favor ni en contra: es una carpeta que nunca se instaló
     *     (la segunda de un cliente de shared), no un fallo. Si contara en contra, el cliente
     *     quedaría sin `listo` para siempre aunque el frente que sirve tráfico tenga la clave, y el
     *     motor se quedaría en el ciclo "corré clave --aplicar".
     *   - Con todos los frentes `sin_env` (o ninguno) no hay nada que esté listo: false.
     *   - En `dry_run` un frente en `falta` o `distinta` deja `listo` en false: no se escribió nada.
     *
     * Ojo con la lectura de `estado`: es lo que se ENCONTRÓ antes de actuar. Un frente que estaba en
     * `falta` o `distinta` y se escribió bien trae `accion: escrita` y cuenta a favor; uno que quedó
     * en `error` (no se pudo leer o escribir) cuenta en contra, escriba lo que escriba el resto.
     *
     * @param array<int, array<string, mixed>> $frentes Resultado por frente (`estado` y `accion`).
     *
     * @return bool
     */
    protected function calcular_listo(array $frentes)
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
     *
     * @return void
     */
    protected function registrar_en_el_log(Client $client, $api_key_en_el_admin, array $frentes, $listo)
    {
        $resumen = [];

        foreach ($frentes as $fila) {
            $resumen[] = [
                'client_api_id' => $fila['client_api_id'],
                'estado'        => $fila['estado'],
                'accion'        => $fila['accion'],
            ];
        }

        Log::info('ClientInboundKeySyncService: se aplicó la clave del admin en los frentes del cliente.', [
            'client_id'           => (int) $client->id,
            'api_key_en_el_admin' => $api_key_en_el_admin,
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
