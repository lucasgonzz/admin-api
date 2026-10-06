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

        $listo = count($resultado_de_frentes) > 0;

        foreach ($resultado_de_frentes as $fila) {
            /* Listo = quedó (o ya estaba) con la clave igual. Un frente sin .env o con error no cuenta. */
            if ($fila['estado'] !== self::ESTADO_IGUAL && $fila['accion'] !== self::ACCION_ESCRITA) {
                $listo = false;
            }
        }

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
     * @param Client $client Cliente sin clave.
     *
     * @return string La clave que quedó guardada.
     */
    protected function generar_y_guardar(Client $client)
    {
        $nueva = Str::random(40);

        $filas = Client::query()
            ->where('id', $client->id)
            ->where(function ($sub) {
                $sub->whereNull('api_key')->orWhereRaw("TRIM(api_key) = ''");
            })
            ->update(['api_key' => $nueva]);

        $guardada = $filas > 0
            ? $nueva
            : trim((string) Client::query()->where('id', $client->id)->value('api_key'));

        /* El modelo en memoria refleja lo guardado, sin quedar marcado como modificado. */
        $client->setAttribute('api_key', $guardada);
        $client->syncOriginalAttribute('api_key');

        return $guardada;
    }

    /**
     * Deja constancia de una aplicación en el log: ids, estados y acciones. NUNCA valores.
     *
     * @param Client                           $client               Cliente.
     * @param string                           $api_key_en_el_admin  presente | falta | generada.
     * @param array<int, array<string, mixed>> $frentes              Resultado por frente.
     * @param bool                             $listo                Si todos quedaron con la clave igual.
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
