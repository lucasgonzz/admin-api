<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientVersionUpgrade;
use App\Models\UpdateCommand;
use App\Models\UpdateSeeder;
use App\Models\Version;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Cuerpo de la creación de un ClientVersionUpgrade, extraído de UpdateController
 * para que lo usen los dos caminos que crean actualizaciones:
 *
 * - `UpdateController::store()` / `store_json()` (panel + admin-spa, con sesión Sanctum).
 * - Los endpoints `claude/*` (sin sesión: `Auth::id()` devuelve null, que es un valor
 *   válido y persistible en `created_by_admin_id` — la columna es nullable con
 *   `onDelete('set null')`).
 *
 * 🔴 Acá adentro NO viven los frenos de Claude (`dry_run`, `confirm_client_name`,
 * `confirm_version_count`). Son del controlador de Claude solamente: meterlos acá los
 * metería en el camino de la SPA, que no los quiere.
 *
 * 🔴 Este servicio NO abre transacción a propósito: el camino de la SPA nunca la tuvo y
 * agregarla sería un cambio de comportamiento. El que la quiera (el endpoint 10 de
 * `claude/*`) envuelve la llamada a `create()` con `DB::transaction()`.
 */
class ClientVersionUpgradeCreationService
{
    /**
     * Calcula el conjunto final de `version_id`s a confirmar para el upgrade.
     *
     * Valida pertenencia contra `candidatesBetween()` (el rango real entre la versión
     * actual del cliente y el destino): filtra qué del conjunto pedido pertenece, nunca
     * agrega versiones fuera de ese rango. Si algo pedido no pertenece, aborta con 422 —
     * regla dura: acá NO se recalcula el rango para decidir qué guardar, solo para
     * validar pertenencia. La versión destino siempre queda incluida, pertenezca o no
     * al resultado de `candidatesBetween` (puede no pertenecer si, por ejemplo, no está
     * publicada): define el upgrade y no puede quedar afuera.
     *
     * @param  Client  $client
     * @param  Version  $to
     * @param  array<int, int>  $requested_ids
     * @return array<int, int>
     */
    public function resolve_confirmed_version_ids(Client $client, Version $to, array $requested_ids): array
    {
        $candidates    = VersionPathService::candidatesBetween($client->current_version, $to);
        $candidate_ids = $candidates->pluck('id')->all();

        // Se trabaja con conjuntos ya deduplicados de las dos puntas: un mismo id repetido
        // en el request es un pedido válido (el conjunto pedido es el mismo), no un error.
        // Comparar contra `array_intersect` sin deduplicar antes rechazaba esos casos con
        // 422 y, mezclado con ids inválidos, podía compensar los conteos y dejar pasar algo
        // que el propio mensaje de error dice que rechaza.
        $requested_ids = array_values(array_unique(array_map('intval', $requested_ids)));
        $candidate_ids = array_values(array_unique(array_map('intval', $candidate_ids)));

        $confirmed_ids = array_values(array_intersect($requested_ids, $candidate_ids));

        if (count($confirmed_ids) !== count($requested_ids)) {
            abort(422, 'Se enviaron versiones que no pertenecen al rango calculado.');
        }

        if (! in_array((int) $to->id, $confirmed_ids, true)) {
            $confirmed_ids[] = (int) $to->id;
        }

        return $confirmed_ids;
    }

    /**
     * ¿Esta candidata viene TILDADA por defecto en la sugerencia del panel?
     *
     * La regla es una sola y siempre fue la misma: troncal sí, hotfix no, y la versión destino
     * siempre, sea o no hotfix (define el upgrade y no puede quedar afuera).
     *
     * 🔴 Vive acá y es estática porque tiene DOS consumidores y no puede tener dos definiciones:
     * `ClaudeUpgradeOpsController::preview_json()` la publica como `default_checked` —o sea, es lo
     * que un humano ve tildado antes de confirmar— y `ClaudeUpgradeBatchController::store_batch_json()`
     * la usa para armar el conjunto de cada cliente cuando la política es `sugeridas_del_panel`. Si
     * el lote tuviera su propia copia, el día que cambie la regla el lote crearía upgrades con un
     * conjunto distinto del que el preview muestra, y nadie lo notaría hasta que un cliente quede con
     * un hotfix de más o de menos.
     *
     * ⚠️ Es una SUGERENCIA, no una decisión: el alta de a uno (`store_json`) sigue exigiendo
     * `confirmed_version_ids` nombrados uno por uno y no llama a esto. En el lote, lo que hace las
     * veces de esa confirmación es el `confirm_token`, que incorpora el conjunto resultante de cada
     * cliente.
     *
     * @param  Version  $candidata  Versión del rango que se está evaluando.
     * @param  Version  $destino    Versión destino del upgrade.
     * @return bool
     */
    public static function es_sugerida_por_defecto(Version $candidata, Version $destino): bool
    {
        $es_destino = ((int) $candidata->id === (int) $destino->id);
        $es_hotfix  = (bool) $candidata->is_hotfix;

        return (! $es_hotfix) || $es_destino;
    }

    /**
     * Crea el upgrade, sincroniza las versiones confirmadas, genera los UpdateSeeder /
     * UpdateCommand del camino y aplica el auto-skip de base compartida.
     *
     * @param  Client  $client
     * @param  Version  $to
     * @param  array<int, int>  $confirmed_ids  Ya resueltos con resolve_confirmed_version_ids().
     * @param  array<string, mixed>  $options  Ver build_create_attributes().
     * @return ClientVersionUpgrade
     */
    public function create(Client $client, Version $to, array $confirmed_ids, array $options = [])
    {
        $upgrade = ClientVersionUpgrade::create(
            $this->build_create_attributes($client, $client->current_version_id, $to->id, $options)
        );

        $upgrade->confirmed_versions()->sync($confirmed_ids);

        $this->create_seeders_and_commands($upgrade, $client, $confirmed_ids);

        $this->apply_shared_database_auto_skip($upgrade);

        return $upgrade;
    }

    /**
     * Genera los UpdateSeeder y UpdateCommand del camino de versiones confirmado.
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @param  Client  $client
     * @param  array<int, int>  $confirmed_ids
     * @return void
     */
    protected function create_seeders_and_commands(ClientVersionUpgrade $upgrade, Client $client, array $confirmed_ids)
    {
        $path = VersionPathService::withSeedersAndCommands(
            Version::whereIn('id', $confirmed_ids)->get(),
            (int) $client->id
        );

        foreach ($path as $path_version) {
            foreach ($path_version->seeders as $seeder) {
                UpdateSeeder::create([
                    'client_version_upgrade_id' => $upgrade->id,
                    'version_seeder_id'         => $seeder->id,
                    'status'                    => 'pendiente',
                ]);
            }
        }

        foreach ($path as $path_version) {
            foreach ($path_version->commands as $command) {
                UpdateCommand::create([
                    'client_version_upgrade_id' => $upgrade->id,
                    'version_command_id'        => $command->id,
                    'status'                    => 'pendiente',
                ]);
            }
        }
    }

    /**
     * Atributos con los que se crea el ClientVersionUpgrade.
     *
     * Claves reconocidas de `$options` (todas opcionales):
     * - `notes`                 string|null
     * - `scheduled_date`        string|null  — si viene vacío, hoy.
     * - `target_client_api_id`  int|string|null — si viene, se valida que sea del cliente;
     *                           si no, la primera ClientApi que no sea la activa.
     * - `created_by_admin_id`   int|null — si la clave no está, se usa `Auth::id()`
     *                           (null cuando no hay sesión, que es lo que pasa en `claude/*`).
     * - `created_via`           string|null — solo lo escribe el endpoint de creación de
     *                           Claude. Si no viene, la columna queda NULL, que significa
     *                           "origen no marcado, mirá created_by_admin_id como siempre".
     *
     * @param  Client  $client
     * @param  int|null  $from_version_id
     * @param  int  $to_version_id
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build_create_attributes(Client $client, $from_version_id, $to_version_id, array $options = [])
    {
        $notes          = array_key_exists('notes', $options) ? $options['notes'] : null;
        $scheduled_date = array_key_exists('scheduled_date', $options) ? $options['scheduled_date'] : null;

        $attributes = [
            'client_id'           => $client->id,
            'from_version_id'     => $from_version_id,
            'to_version_id'       => $to_version_id,
            'status'              => 'pendiente',
            'notes'               => $notes,
            'scheduled_date'      => $scheduled_date ? $scheduled_date : now()->toDateString(),
            'created_by_admin_id' => array_key_exists('created_by_admin_id', $options)
                ? $options['created_by_admin_id']
                : Auth::id(),
        ];

        $created_via = array_key_exists('created_via', $options) ? $options['created_via'] : null;
        if ($created_via !== null && $created_via !== '') {
            $attributes['created_via'] = $created_via;
        }

        $target_id = array_key_exists('target_client_api_id', $options) ? $options['target_client_api_id'] : null;
        if ($target_id !== null && $target_id !== '') {
            $this->assert_target_client_api_belongs_to_client($client, (int) $target_id);
            $attributes['target_client_api_id'] = (int) $target_id;
        } else {
            $default_target_id = $this->resolve_default_target_client_api_id($client);
            if ($default_target_id !== null) {
                $attributes['target_client_api_id'] = $default_target_id;
            }
        }

        return $attributes;
    }

    /**
     * Arma las opciones de creación a partir de un Request, para que los dos caminos
     * lean los mismos campos con el mismo criterio.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function options_from_request(Request $request)
    {
        return [
            'notes'                => $request->input('notes'),
            'scheduled_date'       => $request->input('scheduled_date'),
            'target_client_api_id' => $request->input('target_client_api_id'),
        ];
    }

    /**
     * API destino por defecto: la primera ClientApi del cliente que no sea la activa en producción.
     *
     * @param  Client  $client
     * @return int|null
     */
    public function resolve_default_target_client_api_id(Client $client)
    {
        $client->loadMissing('client_apis');

        $active_id   = $client->active_client_api_id ? (int) $client->active_client_api_id : null;
        $fallback_id = null;

        foreach ($client->client_apis as $client_api) {
            $api_id = (int) $client_api->id;
            if ($fallback_id === null) {
                $fallback_id = $api_id;
            }
            if ($active_id !== null && $api_id !== $active_id) {
                return $api_id;
            }
        }

        return $fallback_id;
    }

    /**
     * Verifica que la ClientApi pertenezca al cliente del upgrade.
     *
     * @param  Client  $client
     * @param  int  $target_client_api_id
     * @return void
     */
    public function assert_target_client_api_belongs_to_client(Client $client, $target_client_api_id)
    {
        $belongs = $client->client_apis()
            ->where('id', $target_client_api_id)
            ->exists();

        if (! $belongs) {
            abort(422, 'La API destino no pertenece al cliente seleccionado.');
        }
    }

    /**
     * Marca como skipped los seeders/comandos per_database ya ejecutados
     * en clientes hermanos del mismo grupo de BD compartida.
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @return void
     */
    protected function apply_shared_database_auto_skip(ClientVersionUpgrade $upgrade)
    {
        $service = new SharedDatabaseAutoSkipService();
        $service->apply($upgrade->load([
            'client.shared_database_group',
            'update_seeders.version_seeder',
            'update_commands.version_command',
        ]));
    }
}
