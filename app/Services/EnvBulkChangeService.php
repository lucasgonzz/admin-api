<?php

namespace App\Services;

use App\Models\Client;
use App\Models\EnvChangeBatch;
use App\Models\EnvChangeItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Cambio masivo de variables .env sobre los clientes, en dos tiempos: previsualizar y aplicar.
 *
 * 🔴 Por qué dos tiempos y no un endpoint que escriba directo.
 *
 * Esto lo va a manejar Claude por voz ("cambiale la API key de Anthropic a todos"). Entre lo que
 * Lucas dice y lo que llega acá hay una transcripción, y un error en un DB_PASSWORD aplicado "a
 * todos" deja los 40 clientes caídos a la vez. Así que:
 *
 * - previsualizar() lee el .env de cada cliente y no escribe una sola línea. Devuelve el diff y un
 *   token.
 * - aplicar() exige ese token, y antes de escribir vuelve a leer el .env de cada cliente: si
 *   cambió desde la previsualización, ese cliente se saltea en vez de pisar algo que no se miró.
 * - Antes de cada escritura se hace un backup del .env en el propio servidor.
 * - Un cliente que falla no aborta el lote: se reporta y se sigue con los demás.
 */
class EnvBulkChangeService
{
    /**
     * Minutos que vale una previsualización antes de vencerse.
     */
    const PREVIEW_TTL_MINUTES = 30;

    /**
     * Fragmentos que marcan una variable como sensible: su valor nunca se guarda ni se devuelve
     * en claro, sólo enmascarado.
     *
     * @var array<int, string>
     */
    const SENSITIVE_KEY_FRAGMENTS = ['KEY', 'SECRET', 'PASSWORD', 'PASS', 'TOKEN', 'DSN', 'CREDENTIAL'];

    /**
     * Variables sensibles que ningún fragmento de arriba atrapa, y que igual llevan un secreto.
     *
     * Las `*_URL` de conexión traen `usuario:password@host` adentro: Laravel 8 soporta DATABASE_URL,
     * REDIS_URL y MAIL_URL de forma nativa, y ninguna matchea PASSWORD ni KEY. MAIL_USERNAME parece
     * inofensivo hasta que el proveedor es SendGrid, donde el username literal es la API key.
     *
     * Este endpoint acepta CUALQUIER nombre de variable, no sólo las del template, así que la lista
     * es un piso y no una garantía: ante la duda, agregá la variable acá.
     *
     * @var array<int, string>
     */
    const SENSITIVE_KEY_NAMES = ['DATABASE_URL', 'DB_URL', 'REDIS_URL', 'MAIL_URL', 'QUEUE_URL', 'MAIL_USERNAME'];

    /**
     * Servicio que abre el SSH y opera el .env de cada cliente.
     *
     * @var EnvSshService
     */
    private $env_ssh_service;

    /**
     * @param  EnvSshService  $env_ssh_service
     */
    public function __construct(EnvSshService $env_ssh_service)
    {
        $this->env_ssh_service = $env_ssh_service;
    }

    /**
     * Lista los clientes sobre los que se puede operar, con lo necesario para elegirlos por voz.
     *
     * Sólo lectura: no abre ninguna conexión SSH.
     *
     * @param  bool  $incluir_inactivos
     * @return array<int, array<string, mixed>>
     */
    public function listar_clientes(bool $incluir_inactivos = false): array
    {
        $query = Client::with('active_client_api', 'current_version')->orderBy('name');

        if (! $incluir_inactivos) {
            $query->where('is_active', true);
        }

        $listado = [];

        foreach ($query->get() as $client) {
            $api = $client->active_client_api;

            $listado[] = [
                'client_id'    => $client->id,
                'nombre'       => $client->resolve_display_name(),
                'api_url'      => $api ? $api->url : null,
                'spa_url'      => $api ? $api->spa_url : null,
                'hosting_type' => $api ? ($api->hosting_type ?: 'shared_hosting') : null,
                'version'      => $client->current_version ? $client->current_version->name : null,
                'activo'       => (bool) $client->is_active,
                'operable'     => $api !== null,
            ];
        }

        return $listado;
    }

    /**
     * Previsualiza el cambio: lee el .env de cada cliente y arma el diff. NO escribe nada.
     *
     * @param  array<string, string>  $vars        KEY => valor nuevo.
     * @param  array<int, int>|null   $client_ids  Ids de clientes; null = todos los activos.
     * @param  int|null               $admin_id    Admin que originó el lote, si lo hay.
     * @return array<string, mixed>   { token, expira_en, clientes: [...] }
     * @throws \InvalidArgumentException Si se pasa una lista de clientes vacía.
     */
    public function previsualizar(array $vars, $client_ids = null, $admin_id = null): array
    {
        /*
         * 🔴 El invariante vive acá, no sólo en la validación del endpoint. Una lista vacía NO
         * significa "todos": significa que quien llamó creía tener clientes y no los tenía. Si esto
         * sólo estuviera en el controller, un comando de artisan o un endpoint futuro que reusara
         * el servicio le escribiría a los 40 clientes creyendo que no le escribe a ninguno.
         */
        if (is_array($client_ids) && count($client_ids) === 0) {
            throw new \InvalidArgumentException(
                'La lista de clientes está vacía. Para operar sobre todos, pasá null explícitamente.'
            );
        }

        if (count($vars) === 0) {
            throw new \InvalidArgumentException('No se pasó ninguna variable para cambiar.');
        }

        /* Los lotes vencidos guardan valores cifrados que ya no se van a escribir nunca. */
        $this->purgar_lotes_vencidos();

        $clients = $this->resolver_clientes($client_ids);

        $batch = EnvChangeBatch::create([
            'token'      => Str::random(64),
            'status'     => 'previewed',
            'expires_at' => Carbon::now()->addMinutes(self::PREVIEW_TTL_MINUTES),
            'admin_id'   => $admin_id,
        ]);

        $resultados = [];

        try {
            foreach ($clients as $client) {
                $resultados[] = $this->previsualizar_cliente($batch, $client, $vars);
            }
        } finally {
            /* La sesión SSH se cierra pase lo que pase: si algo revienta a mitad, no queda colgada. */
            $this->env_ssh_service->disconnect();
        }

        return [
            'token'     => $batch->token,
            'expira_en' => $batch->expires_at->toIso8601String(),
            'clientes'  => $resultados,
        ];
    }

    /**
     * Aplica un lote previamente previsualizado.
     *
     * @param  string  $token
     * @param  bool    $reanudar  true para retomar un lote que quedó cortado a la mitad.
     * @return array<string, mixed>  { aplicados, omitidos, fallidos, sin_cambios, pendientes, clientes }
     * @throws \RuntimeException Si el token no existe, venció, ya se aplicó o hay una corrida en curso.
     */
    public function aplicar(string $token, bool $reanudar = false): array
    {
        $batch = EnvChangeBatch::where('token', $token)->first();

        if ($batch === null) {
            throw new \RuntimeException('El token de previsualización no existe. Volvé a previsualizar el cambio.');
        }

        if ($batch->status === 'applied') {
            throw new \RuntimeException(
                'Este lote ya se aplicó el ' . $batch->applied_at->format('d/m/Y H:i') . '. '
                . 'Si querés repetir el cambio, previsualizalo de nuevo.'
            );
        }

        if ($batch->expires_at === null || $batch->expires_at->isPast()) {
            throw new \RuntimeException(
                'La previsualización venció (dura ' . self::PREVIEW_TTL_MINUTES . ' minutos). '
                . 'Volvé a previsualizar para ver el estado actual antes de escribir.'
            );
        }

        $this->tomar_el_lote($batch, $reanudar);

        /* Con muchos clientes por SSH esto tarda; sin techo de tiempo el lote se corta a la mitad. */
        $this->levantar_limite_de_tiempo();

        /* Marca de tiempo compartida por todos los backups de este lote, para reconocerlos juntos. */
        $timestamp = Carbon::now()->format('YmdHi');

        $resultados = [];
        $aplicados  = 0;
        $omitidos   = 0;
        $fallidos   = 0;

        try {
            /*
             * Se agrupa por API destino: el hash y el backup se resuelven una vez por cliente.
             *
             * Se toman también los 'failed': un renglón que falló conserva su valor cifrado justo
             * para poder reintentarse. El caso real es arreglar el permiso en el servidor del
             * cliente y volver a mandar el mismo token, sin que Lucas tenga que dictar de nuevo la
             * API key. Los 'stale' NO se reintentan: ahí el .env cambió y hay que volver a mirarlo.
             */
            $grupos = $batch->items()->whereIn('status', ['previewed', 'failed'])->get()->groupBy('client_api_id');

            foreach ($grupos as $items) {
                $resultado = $this->aplicar_grupo($items, $timestamp);

                if ($resultado['status'] === 'aplicado') {
                    $aplicados++;
                } elseif ($resultado['status'] === 'omitido') {
                    $omitidos++;
                } else {
                    $fallidos++;
                }

                $resultados[] = $resultado;
            }
        } finally {
            $this->env_ssh_service->disconnect();
        }

        /*
         * Renglones que todavía esperan algo: los que no se llegaron a procesar y los que fallaron
         * y se pueden reintentar. Si son cero, el lote está cerrado; si no, se retoma con el mismo
         * token y reanudar=true.
         */
        $pendientes = $batch->items()->whereIn('status', ['previewed', 'failed'])->count();

        if ($pendientes === 0) {
            $batch->status     = 'applied';
            $batch->applied_at = Carbon::now();
            $batch->save();
        }

        /*
         * Los clientes cuyo valor ya coincidía nunca se escriben, pero tienen que aparecer en la
         * cuenta: si no, un lote de 40 contesta "aplicados: 12" y los otros 28 desaparecen sin
         * explicación, que por voz es indistinguible de un error.
         */
        $sin_cambios = $batch->items()->where('status', 'unchanged')->distinct()->count('client_api_id');

        return [
            'aplicados'   => $aplicados,
            'omitidos'    => $omitidos,
            'fallidos'    => $fallidos,
            'sin_cambios' => $sin_cambios,
            'pendientes'  => $pendientes,
            'completo'    => $pendientes === 0,
            'clientes'    => $resultados,
        ];
    }

    /**
     * Toma el lote en exclusiva, pasándolo a 'applying' con un UPDATE condicional.
     *
     * El cambio de estado es una sola sentencia con WHERE sobre el estado anterior, no un save():
     * si dos requests llegan con el mismo token a la vez, sólo una ve filas afectadas y la otra se
     * encuentra el lote tomado. Un save() leído-y-escrito deja pasar a las dos.
     *
     * @param  EnvChangeBatch  $batch
     * @param  bool  $reanudar
     * @return void
     * @throws \RuntimeException Si el lote ya está tomado y no se pidió reanudar.
     */
    private function tomar_el_lote(EnvChangeBatch $batch, bool $reanudar): void
    {
        /* Transición atómica previewed → applying: sólo una request puede ganarla. */
        $filas = EnvChangeBatch::where('id', $batch->id)
            ->where('status', 'previewed')
            ->update(['status' => 'applying']);

        if ($filas > 0) {
            $batch->status = 'applying';

            return;
        }

        /*
         * No se ganó la transición. Puede ser porque otra request la ganó, o porque el lote ya
         * estaba en 'applying' de una corrida anterior que se cortó.
         *
         * ⚠️ Acá NO se puede reusar el conteo de filas del UPDATE: MySQL devuelve 0 cuando la
         * sentencia no cambia ningún valor, así que un applying → applying dentro del mismo segundo
         * (updated_at idéntico) informa 0 filas aunque la fila exista y esté en el estado esperado.
         * Se relee el estado en vez de deducirlo del contador.
         */
        $estado_actual = EnvChangeBatch::where('id', $batch->id)->value('status');

        if ($reanudar && $estado_actual === 'applying') {
            $batch->status = 'applying';

            return;
        }

        throw new \RuntimeException(
            'Hay una aplicación de este lote en curso, o quedó cortada a la mitad. '
            . 'Si estás seguro de que no está corriendo, reintentá con reanudar=true para retomar '
            . 'los clientes que quedaron sin escribir.'
        );
    }

    /**
     * Previsualiza el cambio sobre un cliente: lee su .env y crea los renglones del lote.
     *
     * @param  EnvChangeBatch  $batch
     * @param  Client          $client
     * @param  array<string, string>  $vars
     * @return array<string, mixed>
     */
    private function previsualizar_cliente(EnvChangeBatch $batch, Client $client, array $vars): array
    {
        $api = $client->active_client_api;

        /*
         * Se opera sobre la ClientApi ACTIVA, que es la carpeta que está sirviendo hoy. Un cliente
         * puede tener varias (el deploy alterna v1/v2); escribir en todas dejaría el secreto nuevo
         * en carpetas que no están corriendo.
         */
        if ($api === null) {
            return $this->resultado_cliente($client, null, 'error', [], 'El cliente no tiene una API activa configurada.');
        }

        /*
         * 🔴 La creación de los renglones va DENTRO del try, no sólo la lectura por SSH. Un error de
         * base — un valor más largo de lo que entra en la columna, por ejemplo — tiene que hacer
         * fallar a ESE cliente, no tumbar el lote entero. Es la promesa central del diseño y se
         * rompía justo en el camino que nadie mira.
         */
        try {
            $raw        = $this->env_ssh_service->read_env_raw_for($api);
            $env_actual = $this->env_ssh_service->parse_env_content($raw);
            $env_hash   = hash('sha256', $raw);

            $cambios = [];

            foreach ($vars as $key => $nuevo_valor) {
                $cambios[] = $this->crear_item($batch, $client, $api, (string) $key, (string) $nuevo_valor, $env_actual, $env_hash);
            }

            return $this->resultado_cliente($client, $api, 'ok', $cambios, null);
        } catch (\Throwable $e) {
            return $this->resultado_cliente($client, $api, 'error', [], $e->getMessage());
        }
    }

    /**
     * Crea el renglón de una variable y devuelve su representación para la respuesta.
     *
     * @param  EnvChangeBatch  $batch
     * @param  Client          $client
     * @param  \App\Models\ClientApi  $api
     * @param  string          $key
     * @param  string          $nuevo_valor
     * @param  array<string, string>  $env_actual
     * @param  string          $env_hash
     * @return array<string, mixed>
     */
    private function crear_item(EnvChangeBatch $batch, Client $client, $api, string $key, string $nuevo_valor, array $env_actual, string $env_hash): array
    {
        $existe       = array_key_exists($key, $env_actual);
        $valor_actual = $existe ? $env_actual[$key] : null;

        /* Si el valor ya coincide, el renglón queda registrado pero no se escribe al aplicar. */
        $cambia = ! ($existe && $valor_actual === $nuevo_valor);

        EnvChangeItem::create([
            'env_change_batch_id' => $batch->id,
            'client_id'           => $client->id,
            'client_api_id'       => $api->id,
            'env_key'             => $key,
            'old_value_masked'    => $valor_actual === null ? null : $this->mask_value($key, $valor_actual),
            'new_value_masked'    => $this->mask_value($key, $nuevo_valor),
            'new_value_sha256'    => hash('sha256', $nuevo_valor),
            /*
             * 🔴 El valor real sólo se guarda si efectivamente se va a escribir. Un renglón
             * 'unchanged' es, por definición, uno cuyo valor propuesto ES el que el cliente ya
             * tiene en producción: guardarlo dejaría el secreto vigente de cada cliente archivado
             * en la base del admin para siempre, porque aplicar() ni los mira. Y es el caso más
             * común de todos — la segunda corrida de cualquier cambio masivo.
             */
            'new_value_encrypted' => $cambia ? $nuevo_valor : null,
            'env_hash'            => $env_hash,
            'status'              => $cambia ? 'previewed' : 'unchanged',
        ]);

        return [
            'key'          => $key,
            'valor_actual' => $valor_actual === null ? null : $this->mask_value($key, $valor_actual),
            'valor_nuevo'  => $this->mask_value($key, $nuevo_valor),
            'existe'       => $existe,
            'cambia'       => $cambia,
            'sensible'     => $this->is_sensitive_key($key),
        ];
    }

    /**
     * Escribe los renglones de un cliente, si su .env sigue igual que en la previsualización.
     *
     * @param  \Illuminate\Support\Collection  $items  Renglones de una misma ClientApi.
     * @param  string  $timestamp  Marca AAAAMMDDHHMM para nombrar el backup.
     * @return array<string, mixed>
     */
    private function aplicar_grupo($items, string $timestamp): array
    {
        /* Todos los renglones del grupo comparten cliente y API: alcanza con mirar el primero. */
        $primero = $items->first();
        $primero->loadMissing('client', 'client_api');

        $client = $primero->client;
        $api    = $primero->client_api;

        if ($api === null) {
            $motivo = 'La API destino ya no existe en el admin.';
            $this->marcar_items($items, 'failed', $motivo);

            return $this->resultado_aplicacion($client, null, 'error', [], $motivo, null);
        }

        try {
            /*
             * Se relee el .env y se compara contra el hash de la previsualización. Si alguien lo
             * tocó en el medio — otro deploy, una edición a mano —, lo que Lucas confirmó ya no es
             * lo que hay en el servidor, y este cliente se saltea.
             */
            $hash_actual = $this->env_ssh_service->env_hash_for($api);

            if ($hash_actual !== $primero->env_hash) {
                $motivo = 'El .env del cliente cambió después de la previsualización. No se escribió nada: '
                    . 'volvé a previsualizar para ver el estado actual.';

                $this->marcar_items($items, 'stale', $motivo);

                return $this->resultado_aplicacion($client, $api, 'omitido', [], $motivo, null);
            }

            /* Se descifran los valores reales recién acá, para escribirlos. */
            $vars_a_escribir = [];
            foreach ($items as $item) {
                $vars_a_escribir[$item->env_key] = (string) $item->new_value_encrypted;
            }

            /* Backup antes de tocar. Si no queda escrito, backup_env_for() lanza y no se escribe. */
            $backup_path = $this->env_ssh_service->backup_env_for($api, $timestamp);

            /*
             * write_env_vars_for() relee el .env y verifica que cada variable haya quedado con el
             * valor pedido. Si no quedó, lanza — y este grupo cae al catch con su valor intacto,
             * en vez de darse por aplicado y borrar el secreto que nunca llegó a escribirse.
             */
            $this->env_ssh_service->write_env_vars_for($api, $vars_a_escribir);

            /*
             * Aplicado y verificado: recién ahora se borra el valor cifrado. El secreto no tiene
             * por qué seguir guardado en la base del admin una vez que está en el servidor.
             */
            foreach ($items as $item) {
                $item->status              = 'applied';
                $item->backup_path         = $backup_path;
                $item->new_value_encrypted = null;
                $item->save();
            }

            return $this->resultado_aplicacion($client, $api, 'aplicado', array_keys($vars_a_escribir), null, $backup_path);
        } catch (\Throwable $e) {
            /*
             * Falla: se registra el error pero NO se borra el valor cifrado. El lote se puede
             * reanudar, y sin el valor no habría con qué reintentar salvo volviendo a dictarlo.
             */
            $this->marcar_items($items, 'failed', $e->getMessage(), false);

            return $this->resultado_aplicacion($client, $api, 'error', [], $e->getMessage(), null);
        }
    }

    /**
     * Marca un grupo de renglones con un estado y un motivo.
     *
     * @param  \Illuminate\Support\Collection  $items
     * @param  string  $status
     * @param  string  $error
     * @param  bool    $descartar_valor  Si borrar el valor cifrado (no, cuando se va a reintentar).
     * @return void
     */
    private function marcar_items($items, string $status, string $error, bool $descartar_valor = true): void
    {
        foreach ($items as $item) {
            $item->status = $status;
            $item->error  = $error;

            if ($descartar_valor) {
                $item->new_value_encrypted = null;
            }

            $item->save();
        }
    }

    /**
     * Borra los valores cifrados de los lotes vencidos que nunca se aplicaron.
     *
     * Un preview que se abandona, o que se rechaza al confirmar, deja el secreto guardado sin que
     * nada lo vaya a escribir nunca. Se limpia al crear el siguiente lote en vez de con una tarea
     * programada aparte: no hace falta un scheduler para algo que sólo importa cuando el sistema
     * se usa, y así no hay un cron más que se pueda caer sin que nadie mire.
     *
     * @return void
     */
    private function purgar_lotes_vencidos(): void
    {
        $vencidos = EnvChangeBatch::whereIn('status', ['previewed', 'applying'])
            ->where('expires_at', '<', Carbon::now())
            ->pluck('id');

        if ($vencidos->isEmpty()) {
            return;
        }

        EnvChangeItem::whereIn('env_change_batch_id', $vencidos)
            ->whereNotNull('new_value_encrypted')
            ->update(['new_value_encrypted' => null]);

        EnvChangeBatch::whereIn('id', $vencidos)->update(['status' => 'expired']);
    }

    /**
     * Sube el techo de tiempo del proceso mientras dura la aplicación del lote.
     *
     * Cada cliente son varias operaciones SSH (leer, hashear, copiar el backup, escribir, releer) y
     * un cliente inalcanzable se come el timeout de conexión completo. Con el límite del php.ini,
     * un lote grande se corta a la mitad — y aunque ahora eso es reanudable, es mejor no cortarlo.
     *
     * @return void
     */
    private function levantar_limite_de_tiempo(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }

    /**
     * Resuelve los clientes del lote: los ids pedidos, o todos los activos si no se pidió ninguno.
     *
     * @param  array<int, int>|null  $client_ids
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function resolver_clientes($client_ids)
    {
        $query = Client::with('active_client_api')->orderBy('name');

        if (is_array($client_ids)) {
            /* Selección explícita: se respeta lo que se pidió, activo o no. */
            $query->whereIn('id', $client_ids);
        } else {
            /*
             * "Todos" son los clientes ACTIVOS. La tabla tiene is_active con índice propio, o sea
             * que la baja es un estado real del sistema: sin este filtro, "cambiásela a todos"
             * incluiría ex-clientes y demos, y en el peor caso le reescribiría el .env a alguien
             * que se fue pero cuyo hosting sigue arriba.
             */
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Arma el renglón de respuesta de un cliente en la previsualización.
     *
     * @param  Client  $client
     * @param  \App\Models\ClientApi|null  $api
     * @param  string  $status
     * @param  array<int, array<string, mixed>>  $cambios
     * @param  string|null  $error
     * @return array<string, mixed>
     */
    private function resultado_cliente(Client $client, $api, string $status, array $cambios, $error): array
    {
        return [
            'client_id' => $client->id,
            'nombre'    => $client->resolve_display_name(),
            'api_url'   => $api ? $api->url : null,
            'status'    => $status,
            'cambios'   => $cambios,
            'error'     => $error,
        ];
    }

    /**
     * Arma el renglón de respuesta de un cliente en la aplicación.
     *
     * @param  Client|null  $client
     * @param  \App\Models\ClientApi|null  $api
     * @param  string  $status  aplicado | omitido | error
     * @param  array<int, string>  $keys
     * @param  string|null  $error
     * @param  string|null  $backup_path
     * @return array<string, mixed>
     */
    private function resultado_aplicacion($client, $api, string $status, array $keys, $error, $backup_path): array
    {
        return [
            'client_id'   => $client ? $client->id : null,
            'nombre'      => $client ? $client->resolve_display_name() : null,
            'api_url'     => $api ? $api->url : null,
            'status'      => $status,
            'keys'        => $keys,
            'error'       => $error,
            'backup_path' => $backup_path,
        ];
    }

    /**
     * Indica si el valor de esa variable es un secreto que no debe guardarse ni devolverse en claro.
     *
     * @param  string  $key
     * @return bool
     */
    private function is_sensitive_key(string $key): bool
    {
        $upper = strtoupper($key);

        if (in_array($upper, self::SENSITIVE_KEY_NAMES, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (strpos($upper, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enmascara el valor si la variable es sensible; si no, lo devuelve tal cual.
     *
     * Las variables que no son secretas (DB_HOST, APP_URL, MAIL_PORT) se muestran enteras: son las
     * que hace falta leer para confirmar que el cambio es el correcto.
     *
     * @param  string  $key
     * @param  string  $value
     * @return string
     */
    private function mask_value(string $key, string $value): string
    {
        if (! $this->is_sensitive_key($key)) {
            return $value;
        }

        if ($value === '') {
            return '(vacío)';
        }

        /* Valores cortos no muestran ningún prefijo: cuatro caracteres de una clave de ocho es mucho. */
        if (strlen($value) <= 8) {
            return str_repeat('*', 8);
        }

        return substr($value, 0, 4) . str_repeat('*', 8);
    }
}
