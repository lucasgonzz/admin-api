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
     * @return array<int, array<string, mixed>>
     */
    public function listar_clientes(): array
    {
        $clients = Client::with('active_client_api', 'current_version')->orderBy('name')->get();

        $listado = [];

        foreach ($clients as $client) {
            $api = $client->active_client_api;

            $listado[] = [
                'client_id'    => $client->id,
                'nombre'       => $client->resolve_display_name(),
                'api_url'      => $api ? $api->url : null,
                'spa_url'      => $api ? $api->spa_url : null,
                'hosting_type' => $api ? ($api->hosting_type ?: 'shared_hosting') : null,
                'version'      => $client->current_version ? $client->current_version->name : null,
                'operable'     => $api !== null,
            ];
        }

        return $listado;
    }

    /**
     * Previsualiza el cambio: lee el .env de cada cliente y arma el diff. NO escribe nada.
     *
     * @param  array<string, string>  $vars        KEY => valor nuevo.
     * @param  array<int, int>|null   $client_ids  Ids de clientes, o null para todos.
     * @param  int|null               $admin_id    Admin que originó el lote, si lo hay.
     * @return array<string, mixed>   { token, expira_en, clientes: [...] }
     */
    public function previsualizar(array $vars, $client_ids = null, $admin_id = null): array
    {
        $clients = $this->resolver_clientes($client_ids);

        $batch = EnvChangeBatch::create([
            'token'      => Str::random(64),
            'status'     => 'previewed',
            'expires_at' => Carbon::now()->addMinutes(self::PREVIEW_TTL_MINUTES),
            'admin_id'   => $admin_id,
        ]);

        $resultados = [];

        foreach ($clients as $client) {
            $resultados[] = $this->previsualizar_cliente($batch, $client, $vars);
        }

        $this->env_ssh_service->disconnect();

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
     * @return array<string, mixed>  { aplicados, fallidos, clientes: [...] }
     * @throws \RuntimeException Si el token no existe, venció o el lote ya se aplicó.
     */
    public function aplicar(string $token): array
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

        if (! $batch->is_applicable()) {
            throw new \RuntimeException(
                'La previsualización venció (dura ' . self::PREVIEW_TTL_MINUTES . ' minutos). '
                . 'Volvé a previsualizar para ver el estado actual antes de escribir.'
            );
        }

        /*
         * Se marca aplicado ANTES de escribir. Si dos requests llegan con el mismo token, la
         * segunda encuentra el lote en 'applied' y no reescribe el .env de nadie.
         */
        $batch->status     = 'applied';
        $batch->applied_at = Carbon::now();
        $batch->save();

        /* Marca de tiempo compartida por todos los backups de este lote, para reconocerlos juntos. */
        $timestamp = Carbon::now()->format('YmdHi');

        $resultados = [];
        $aplicados  = 0;
        $fallidos   = 0;

        /* Se agrupa por API destino: el hash y el backup se resuelven una vez por cliente, no por variable. */
        $grupos = $batch->items()->where('status', 'previewed')->get()->groupBy('client_api_id');

        foreach ($grupos as $items) {
            $resultado = $this->aplicar_grupo($items, $timestamp);

            if ($resultado['status'] === 'aplicado') {
                $aplicados++;
            } else {
                $fallidos++;
            }

            $resultados[] = $resultado;
        }

        $this->env_ssh_service->disconnect();

        return [
            'aplicados' => $aplicados,
            'fallidos'  => $fallidos,
            'clientes'  => $resultados,
        ];
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

        try {
            $raw        = $this->env_ssh_service->read_env_raw_for($api);
            $env_actual = $this->parsear_env($raw);
            $env_hash   = hash('sha256', $raw);
        } catch (\Throwable $e) {
            return $this->resultado_cliente($client, $api, 'error', [], $e->getMessage());
        }

        $cambios = [];

        foreach ($vars as $key => $nuevo_valor) {
            $nuevo_valor  = (string) $nuevo_valor;
            $existe       = array_key_exists($key, $env_actual);
            $valor_actual = $existe ? $env_actual[$key] : null;

            /* Si el valor ya coincide, el renglón queda registrado pero no se escribe al aplicar. */
            $status = ($existe && $valor_actual === $nuevo_valor) ? 'unchanged' : 'previewed';

            EnvChangeItem::create([
                'env_change_batch_id' => $batch->id,
                'client_id'           => $client->id,
                'client_api_id'       => $api->id,
                'env_key'             => $key,
                'old_value_masked'    => $valor_actual === null ? null : $this->mask_value($key, $valor_actual),
                'new_value_masked'    => $this->mask_value($key, $nuevo_valor),
                'new_value_sha256'    => hash('sha256', $nuevo_valor),
                'new_value_encrypted' => $nuevo_valor,
                'env_hash'            => $env_hash,
                'status'              => $status,
            ]);

            $cambios[] = [
                'key'          => $key,
                'valor_actual' => $valor_actual === null ? null : $this->mask_value($key, $valor_actual),
                'valor_nuevo'  => $this->mask_value($key, $nuevo_valor),
                'existe'       => $existe,
                'cambia'       => $status === 'previewed',
                'sensible'     => $this->is_sensitive_key($key),
            ];
        }

        return $this->resultado_cliente($client, $api, 'ok', $cambios, null);
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
            $this->marcar_items($items, 'failed', 'La API destino ya no existe en el admin.');

            return $this->resultado_aplicacion($client, null, 'error', [], 'La API destino ya no existe en el admin.', null);
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

            /* Backup antes de tocar. Si el backup no queda escrito, backup_env_for() lanza y no se escribe. */
            $backup_path = $this->env_ssh_service->backup_env_for($api, $timestamp);

            /* Se descifran los valores reales recién acá, para escribirlos. */
            $vars_a_escribir = [];
            foreach ($items as $item) {
                $vars_a_escribir[$item->env_key] = (string) $item->new_value_encrypted;
            }

            $this->env_ssh_service->write_env_vars_for($api, $vars_a_escribir);

            /*
             * Aplicado: se borra el valor cifrado. El secreto no tiene por qué seguir guardado en
             * la base del admin una vez que ya está en el servidor del cliente.
             */
            foreach ($items as $item) {
                $item->status              = 'applied';
                $item->backup_path         = $backup_path;
                $item->new_value_encrypted = null;
                $item->save();
            }

            return $this->resultado_aplicacion($client, $api, 'aplicado', array_keys($vars_a_escribir), null, $backup_path);
        } catch (\Throwable $e) {
            $this->marcar_items($items, 'failed', $e->getMessage());

            return $this->resultado_aplicacion($client, $api, 'error', [], $e->getMessage(), null);
        }
    }

    /**
     * Marca un grupo de renglones con un estado y un motivo, y descarta el valor cifrado.
     *
     * @param  \Illuminate\Support\Collection  $items
     * @param  string  $status
     * @param  string  $error
     * @return void
     */
    private function marcar_items($items, string $status, string $error): void
    {
        foreach ($items as $item) {
            $item->status              = $status;
            $item->error               = $error;
            $item->new_value_encrypted = null;
            $item->save();
        }
    }

    /**
     * Resuelve los clientes del lote: los ids pedidos, o todos si no se pidió ninguno.
     *
     * @param  array<int, int>|null  $client_ids
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function resolver_clientes($client_ids)
    {
        $query = Client::with('active_client_api')->orderBy('name');

        if (is_array($client_ids) && count($client_ids) > 0) {
            $query->whereIn('id', $client_ids);
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
     * Parsea el contenido crudo de un .env en un array asociativo.
     *
     * Espeja el parseo de EnvSshService para comparar contra los mismos valores que ese servicio
     * escribiría: sin comillas envolventes, sin comentarios.
     *
     * @param  string  $raw
     * @return array<string, string>
     */
    private function parsear_env(string $raw): array
    {
        $env = [];

        foreach (explode("\n", $raw) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || strncmp($trimmed, '#', 1) === 0) {
                continue;
            }

            $equals_pos = strpos($trimmed, '=');
            if ($equals_pos === false) {
                continue;
            }

            $key   = trim(substr($trimmed, 0, $equals_pos));
            $value = trim(substr($trimmed, $equals_pos + 1));

            /* Stripea comillas envolventes, igual que EnvSshService. */
            if (strlen($value) >= 2) {
                $primera = substr($value, 0, 1);
                $ultima  = substr($value, -1);

                if (($primera === '"' && $ultima === '"') || ($primera === "'" && $ultima === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $env[$key] = $value;
        }

        return $env;
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
