<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientAiTokenUsage;
use App\Models\ClientAiTokenUsagePerson;
use App\Models\ClientAiTokenUsagePersonModel;
use App\Services\ClientAiTokensSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * Lo que sumó la misión proveedores-ia-deepseek (22/9/2026) al espejo de tokens del admin: el
 * corte por persona Y modelo (que es lo que le pone plata a cada persona), la configuración de IA
 * que informa cada cliente, los cortes por modelo y por proveedor del resumen global, y los tres
 * precios nuevos.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 **Que la clave del espejo nuevo tenga las cinco dimensiones del origen.** Dos filas del
 *     mismo día y la misma persona con distinto modelo son DOS filas. Si colapsaran, la que queda
 *     tendría los contadores de la última y se costearía Opus con la tarifa de Haiku o al revés,
 *     con el estado en `success` y sin que nada avise.
 *  2. 🔴 **Que un modelo sin precio deje a la persona sin costo total (null), no en un número
 *     corto.** Es la regla de `config/ia_precios.php` aplicada al corte nuevo: cero es "no costó
 *     nada", null es "no sé cuánto costó".
 *  3. **Que un cliente con una versión anterior siga dando `success` y no pierda lo que informó
 *     antes.** Los bloques nuevos son opcionales; que no vengan no puede pisar con null la
 *     configuración que ese cliente informó la semana pasada.
 *
 * Hereda de `BaseDelCanal` por su `fakear_http()`: nada de esto sale a la red.
 */
class TokensPorModeloYProveedorTest extends BaseDelCanal
{
    /**
     * Admin logueado por Sanctum: las rutas de lectura viven bajo auth:sanctum.
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de tokens';
        $admin->email    = 'tokens-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Cliente listo para que le pidan el consumo: con ClientApi activa y api_key.
     *
     * @param string $phone Teléfono, distinto por cliente cuando hay varios en el mismo test.
     *
     * @return Client
     */
    private function cliente_consultable(string $phone = '+5493411234567'): Client
    {
        $client = $this->crear_cliente($phone);
        $this->crear_client_api($client, 'https://api-ferreteria.test', 'shared_hosting');

        return $client->fresh();
    }

    /**
     * Payload del `empresa-api`, con las claves EXACTAS del contrato de proveedores-ia-deepseek.
     *
     * 🔴 Los nombres de las claves se escriben acá a mano, tal cual los declara el plan, y no se
     * derivan de ninguna constante del admin: si alguien renombra algo de un lado, este test se
     * pone en rojo. `personas_modelos` y `configuracion` van en null para simular al cliente de
     * versión anterior, que directamente no manda esas claves.
     *
     * @param array<int, array<string, mixed>>      $dias             Bloque `dias`.
     * @param array<int, array<string, mixed>>      $personas         Bloque `personas`.
     * @param array<int, array<string, mixed>>|null $personas_modelos Bloque `personas_modelos`, o null para omitirlo.
     * @param array<string, mixed>|null             $configuracion    Bloque `configuracion`, o null para omitirlo.
     *
     * @return array<string, mixed>
     */
    private function payload_de_consumo(
        array $dias,
        array $personas = [],
        ?array $personas_modelos = null,
        ?array $configuracion = null
    ): array {
        $payload = [
            'user_id'  => 500,
            'desde'    => '2026-09-15',
            'hasta'    => '2026-09-17',
            'dias'     => $dias,
            'personas' => $personas,
        ];

        if ($personas_modelos !== null) {
            $payload['personas_modelos'] = $personas_modelos;
        }

        if ($configuracion !== null) {
            $payload['configuracion'] = $configuracion;
        }

        return $payload;
    }

    /**
     * Una fila del bloque `dias`.
     *
     * @param string $fecha    Día.
     * @param string $proceso  Acción.
     * @param string $modelo   Modelo.
     * @param int    $llamadas Llamadas.
     * @param int    $input    Tokens de entrada.
     * @param int    $output   Tokens de salida.
     *
     * @return array<string, mixed>
     */
    private function fila_dia(
        string $fecha,
        string $proceso,
        string $modelo,
        int $llamadas = 1,
        int $input = 1000,
        int $output = 200
    ): array {
        return [
            'fecha'                       => $fecha,
            'proceso'                     => $proceso,
            'proveedor'                   => 'anthropic',
            'modelo'                      => $modelo,
            'llamadas'                    => $llamadas,
            'input_tokens'                => $input,
            'output_tokens'               => $output,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens'     => 0,
        ];
    }

    /**
     * Una fila del bloque `personas`.
     *
     * @param string      $fecha        Día.
     * @param int|null    $auth_user_id Usuario de la base del cliente; null = procesos automáticos.
     * @param string|null $nombre       Nombre resuelto por el cliente.
     * @param int         $llamadas     Llamadas.
     * @param int         $input        Tokens de entrada.
     * @param int         $output       Tokens de salida.
     *
     * @return array<string, mixed>
     */
    private function fila_persona(
        string $fecha,
        $auth_user_id,
        $nombre,
        int $llamadas = 1,
        int $input = 1000,
        int $output = 200
    ): array {
        return [
            'fecha'                       => $fecha,
            'auth_user_id'                => $auth_user_id,
            'nombre'                      => $nombre,
            'llamadas'                    => $llamadas,
            'input_tokens'                => $input,
            'output_tokens'               => $output,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens'     => 0,
        ];
    }

    /**
     * Una fila del bloque `personas_modelos`, con las diez claves del contrato.
     *
     * @param string      $fecha        Día.
     * @param int|null    $auth_user_id Usuario de la base del cliente; null = procesos automáticos.
     * @param string|null $nombre       Nombre resuelto por el cliente.
     * @param string      $proveedor    anthropic | deepseek | openai.
     * @param string      $modelo       Modelo.
     * @param int         $llamadas     Llamadas.
     * @param int         $input        Tokens de entrada.
     * @param int         $output       Tokens de salida.
     *
     * @return array<string, mixed>
     */
    private function fila_persona_modelo(
        string $fecha,
        $auth_user_id,
        $nombre,
        string $proveedor,
        string $modelo,
        int $llamadas = 1,
        int $input = 1000,
        int $output = 200
    ): array {
        return [
            'fecha'                       => $fecha,
            'auth_user_id'                => $auth_user_id,
            'nombre'                      => $nombre,
            'proveedor'                   => $proveedor,
            'modelo'                      => $modelo,
            'llamadas'                    => $llamadas,
            'input_tokens'                => $input,
            'output_tokens'               => $output,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens'     => 0,
        ];
    }

    /**
     * El bloque `configuracion` del contrato, con sus cuatro claves.
     *
     * @return array<string, string>
     */
    private function configuracion_deepseek_profundo(): array
    {
        return [
            'proveedor'        => 'deepseek',
            'pensamiento'      => 'profundo',
            'modelo_asistente' => 'deepseek-v4-pro',
            'modelo_general'   => 'deepseek-flash',
        ];
    }

    /**
     * Deja una fila del corte por persona ya espejada.
     *
     * @param Client      $client       Cliente dueño.
     * @param string      $fecha        Día.
     * @param int         $auth_user_id Usuario (0 = automático).
     * @param string|null $nombre       Nombre.
     * @param int         $llamadas     Llamadas.
     * @param int         $input        Tokens de entrada.
     *
     * @return void
     */
    private function sembrar_persona(
        Client $client,
        string $fecha,
        int $auth_user_id,
        $nombre,
        int $llamadas,
        int $input
    ): void {
        $fila               = new ClientAiTokenUsagePerson();
        $fila->client_id    = $client->id;
        $fila->fecha        = $fecha;
        $fila->auth_user_id = $auth_user_id;
        $fila->nombre       = $nombre;
        $fila->llamadas     = $llamadas;
        $fila->input_tokens = $input;
        $fila->save();
    }

    /**
     * Deja una fila del corte por persona y modelo ya espejada.
     *
     * @param Client      $client       Cliente dueño.
     * @param string      $fecha        Día.
     * @param int         $auth_user_id Usuario (0 = automático).
     * @param string|null $nombre       Nombre.
     * @param string      $proveedor    Proveedor.
     * @param string      $modelo       Modelo.
     * @param int         $llamadas     Llamadas.
     * @param int         $input        Tokens de entrada.
     *
     * @return void
     */
    private function sembrar_persona_modelo(
        Client $client,
        string $fecha,
        int $auth_user_id,
        $nombre,
        string $proveedor,
        string $modelo,
        int $llamadas,
        int $input
    ): void {
        $fila               = new ClientAiTokenUsagePersonModel();
        $fila->client_id    = $client->id;
        $fila->fecha        = $fecha;
        $fila->auth_user_id = $auth_user_id;
        $fila->nombre       = $nombre;
        $fila->proveedor    = $proveedor;
        $fila->modelo       = $modelo;
        $fila->llamadas     = $llamadas;
        $fila->input_tokens = $input;
        $fila->save();
    }

    /**
     * Deja una fila del corte por acción ya espejada.
     *
     * @param Client $client    Cliente dueño.
     * @param string $fecha     Día.
     * @param string $proveedor Proveedor.
     * @param string $modelo    Modelo.
     * @param int    $input     Tokens de entrada.
     *
     * @return void
     */
    private function sembrar_consumo(Client $client, string $fecha, string $proveedor, string $modelo, int $input): void
    {
        $fila               = new ClientAiTokenUsage();
        $fila->client_id    = $client->id;
        $fila->fecha        = $fecha;
        $fila->proceso      = 'chat_mensaje';
        $fila->proveedor    = $proveedor;
        $fila->modelo       = $modelo;
        $fila->llamadas     = 1;
        $fila->input_tokens = $input;
        $fila->save();
    }

    /**
     * Indexa una lista de filas por una clave, para afirmar sin depender del orden.
     *
     * @param array<int, array<string, mixed>> $filas Filas.
     * @param string                           $clave Clave a usar como índice.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexar(array $filas, string $clave): array
    {
        $resultado = [];

        foreach ($filas as $fila) {
            $resultado[(string) $fila[$clave]] = $fila;
        }

        return $resultado;
    }

    // ------------------------------------------------------------------
    // El sync
    // ------------------------------------------------------------------

    /**
     * 🔴 El sync guarda `personas_modelos` con la clave de CINCO dimensiones y escribe la
     * configuración del cliente. Dos filas del mismo día y la misma persona con distinto modelo
     * son dos filas; el `auth_user_id` nulo se guarda como 0; la fecha fuera del rango se descarta;
     * y correrlo dos veces reescribe en vez de acumular.
     *
     * Todo en un solo test a propósito: es UN payload real, y lo que importa es que las cuatro
     * propiedades convivan en la misma corrida.
     *
     * @return void
     */
    public function test_el_sync_guarda_personas_modelos_con_cinco_dimensiones_y_la_configuracion(): void
    {
        $client = $this->cliente_consultable();

        $personas_modelos = [
            // Juan, el mismo día, con DOS modelos: tienen que quedar como dos filas.
            $this->fila_persona_modelo('2026-09-16', 3, 'Juan', 'anthropic', 'claude-sonnet-5', 10, 100000, 5000),
            $this->fila_persona_modelo('2026-09-16', 3, 'Juan', 'deepseek', 'deepseek-v4-pro', 4, 50000, 2000),
            // Procesos automáticos: `auth_user_id` viaja en null, tal cual el contrato.
            $this->fila_persona_modelo('2026-09-16', null, null, 'openai', 'text-embedding-3-small', 400, 80000, 0),
            // El cliente contesta de más: un día de 2024 que nadie pidió.
            $this->fila_persona_modelo('2024-01-05', 3, 'Juan', 'anthropic', 'claude-sonnet-5', 99, 999999, 99999),
        ];

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response($this->payload_de_consumo(
                [$this->fila_dia('2026-09-16', 'chat_mensaje', 'claude-sonnet-5', 14, 150000, 7000)],
                [$this->fila_persona('2026-09-16', 3, 'Juan', 14, 150000, 7000)],
                $personas_modelos,
                $this->configuracion_deepseek_profundo()
            ), 200),
        ]);

        $servicio = app(ClientAiTokensSyncService::class);

        $servicio->traer_del_cliente($client, '2026-09-15', '2026-09-17');
        $segundo = $servicio->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        /* Sin esta aserción el test pasa por el motivo equivocado: con un `create()` pelado la
         * segunda corrida explotaría contra el unique y devolvería `failed`, y el conteo daría lo
         * mismo porque las filas de la primera siguen ahí. */
        $this->assertSame(ClientAiTokensSyncService::ESTADO_SUCCESS, $segundo['estado']);

        // Un día + una persona + tres del corte nuevo (la de 2024 se descartó).
        $this->assertSame(5, $segundo['filas'], 'El contador no suma las filas del corte por modelo.');

        $filas = ClientAiTokenUsagePersonModel::where('client_id', $client->id)->get();

        $this->assertCount(
            3,
            $filas,
            'Tenían que quedar tres filas: las dos de Juan (un modelo cada una) y la de los automáticos. '
            . 'Más de tres es que la segunda corrida acumuló; menos es que dos modelos colapsaron o '
            . 'que entró la fecha de 2024.'
        );

        $juan_sonnet = ClientAiTokenUsagePersonModel::where('client_id', $client->id)
            ->where('auth_user_id', 3)
            ->where('modelo', 'claude-sonnet-5')
            ->first();

        $juan_deepseek = ClientAiTokenUsagePersonModel::where('client_id', $client->id)
            ->where('auth_user_id', 3)
            ->where('modelo', 'deepseek-v4-pro')
            ->first();

        $this->assertNotNull($juan_sonnet, 'La fila de Juan con Sonnet no está: colapsó con la de DeepSeek.');
        $this->assertNotNull($juan_deepseek, 'La fila de Juan con DeepSeek no está: colapsó con la de Sonnet.');

        // Cada una con SUS contadores, no con la suma ni con los de la última.
        $this->assertSame(10, $juan_sonnet->llamadas);
        $this->assertSame(100000, $juan_sonnet->input_tokens);
        $this->assertSame('anthropic', $juan_sonnet->proveedor);
        $this->assertSame('2026-09-16', substr((string) $juan_sonnet->fecha, 0, 10));

        $this->assertSame(4, $juan_deepseek->llamadas);
        $this->assertSame(50000, $juan_deepseek->input_tokens);
        $this->assertSame('deepseek', $juan_deepseek->proveedor);

        $automatico = ClientAiTokenUsagePersonModel::where('client_id', $client->id)
            ->where('modelo', 'text-embedding-3-small')
            ->first();

        $this->assertNotNull($automatico);
        $this->assertSame(
            ClientAiTokenUsagePersonModel::AUTOMATICO,
            $automatico->auth_user_id,
            'El auth_user_id nulo del contrato tiene que guardarse como el centinela 0.'
        );
        $this->assertNull($automatico->nombre);
        $this->assertSame(400, $automatico->llamadas);

        // Y la configuración quedó en el cliente.
        $client->refresh();

        $this->assertSame('deepseek', $client->ai_proveedor);
        $this->assertSame('profundo', $client->ai_pensamiento);
        $this->assertSame('deepseek-v4-pro', $client->ai_modelo, 'ai_modelo tiene que ser modelo_asistente, no modelo_general.');
        $this->assertSame(ClientAiTokensSyncService::ESTADO_SUCCESS, $client->ai_tokens_sync_status);
    }

    /**
     * 🔴 Un cliente de versión anterior no manda `personas_modelos` ni `configuracion`: sigue siendo
     * `success`, no escribe nada en la tabla nueva, y NO pisa con null la configuración que informó
     * antes.
     *
     * El caso real es un cliente que se actualizó, informó DeepSeek, y después una recolección le
     * llega con un payload sin el bloque (un downgrade, o un payload recortado). Que la solapa
     * pase de "DeepSeek · Profundo" a "todavía no informó" por eso sería mentir.
     *
     * @return void
     */
    public function test_un_payload_sin_los_bloques_nuevos_es_success_y_no_pisa_la_configuracion(): void
    {
        $client = $this->cliente_consultable();

        // Lo que informó en una recolección anterior.
        $client->update([
            'ai_proveedor'   => 'deepseek',
            'ai_pensamiento' => 'profundo',
            'ai_modelo'      => 'deepseek-v4-pro',
        ]);

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response($this->payload_de_consumo(
                [$this->fila_dia('2026-09-16', 'chat_mensaje', 'claude-sonnet-5', 5, 10000, 500)],
                [$this->fila_persona('2026-09-16', 3, 'Juan', 5, 10000, 500)]
            ), 200),
        ]);

        $resultado = app(ClientAiTokensSyncService::class)->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        $this->assertSame(ClientAiTokensSyncService::ESTADO_SUCCESS, $resultado['estado']);
        $this->assertSame(2, $resultado['filas']);

        $this->assertSame(
            0,
            ClientAiTokenUsagePersonModel::where('client_id', $client->id)->count(),
            'Sin bloque `personas_modelos` no puede haber filas en la tabla nueva.'
        );

        $client->refresh();

        $this->assertSame('deepseek', $client->ai_proveedor, 'Un payload sin `configuracion` pisó el proveedor con null.');
        $this->assertSame('profundo', $client->ai_pensamiento);
        $this->assertSame('deepseek-v4-pro', $client->ai_modelo);
    }

    // ------------------------------------------------------------------
    // La pestaña del cliente
    // ------------------------------------------------------------------

    /**
     * 🔴 `por_persona` costea a cada persona sumando sus modelos con precio, y la deja en null si
     * alguno no tiene precio. `modelos[]` viaja con las claves del contrato, `configuracion` viaja,
     * e `informa_modelo_por_persona` es true.
     *
     * Las cuentas, con los precios del 22/9/2026 (dólares por millón de tokens):
     *
     *   Juan  → 1.000.000 input de claude-sonnet-5 (2,00) + 1.000.000 input de deepseek-flash (0,30)
     *           = **2,30 USD**, con precio completo.
     *   Brisa → 1.000.000 input de claude-haiku-4-5 (1,00) + 200.000 de modelo-inventado-9 (sin precio)
     *           = **null**: no "1,00", porque 1,00 sería un número corto que parece completo.
     *   Carla → solo en el corte por modelo (1.000.000 input de deepseek-v4-pro = 1,32): se agrega
     *           igual, con sus números.
     *   Procesos automáticos → solo en el corte por persona: costo null y `modelos` vacío.
     *
     * @return void
     */
    public function test_la_pestania_del_cliente_costea_por_persona_y_deja_en_null_a_quien_uso_un_modelo_sin_precio(): void
    {
        $this->admin_logueado();

        $client = $this->crear_cliente();
        $client->update([
            'ai_proveedor'   => 'anthropic',
            'ai_pensamiento' => 'profundo',
            'ai_modelo'      => 'claude-opus-5',
        ]);

        // El corte por persona: la fuente de llamadas y tokens.
        $this->sembrar_persona($client, '2026-09-16', 3, 'Juan', 14, 2000000);
        $this->sembrar_persona($client, '2026-09-16', 7, 'Brisa', 6, 1200000);
        $this->sembrar_persona($client, '2026-09-16', ClientAiTokenUsagePerson::AUTOMATICO, null, 400, 80000);

        // El corte por persona y modelo: la fuente de la plata.
        $this->sembrar_persona_modelo($client, '2026-09-16', 3, 'Juan', 'anthropic', 'claude-sonnet-5', 10, 1000000);
        $this->sembrar_persona_modelo($client, '2026-09-16', 3, 'Juan', 'deepseek', 'deepseek-flash', 4, 1000000);
        $this->sembrar_persona_modelo($client, '2026-09-16', 7, 'Brisa', 'anthropic', 'claude-haiku-4-5', 4, 1000000);
        $this->sembrar_persona_modelo($client, '2026-09-16', 7, 'Brisa', 'anthropic', 'modelo-inventado-9', 2, 200000);
        $this->sembrar_persona_modelo($client, '2026-09-17', 9, 'Carla', 'deepseek', 'deepseek-v4-pro', 3, 1000000);

        $response = $this->getJson(
            '/api/admin/client/' . $client->id . '/tokens?desde=2026-09-15&hasta=2026-09-17'
        );

        $response->assertStatus(200);

        $payload = $response->json();

        $this->assertTrue($payload['informa_modelo_por_persona']);

        $this->assertSame(
            ['proveedor' => 'anthropic', 'pensamiento' => 'profundo', 'modelo' => 'claude-opus-5'],
            $payload['configuracion']
        );

        $this->assertCount(4, $payload['por_persona'], 'Carla, que solo está en el corte por modelo, tiene que aparecer igual.');

        $personas = $this->indexar($payload['por_persona'], 'nombre');

        // Juan: suma de sus dos modelos, ambos con precio.
        $this->assertEqualsWithDelta(2.30, $personas['Juan']['costo_usd'], 0.0001);
        $this->assertTrue($personas['Juan']['tiene_precio_completo']);
        $this->assertSame(14, $personas['Juan']['llamadas'], 'Las llamadas salen del corte por persona, no del de modelos.');
        $this->assertSame(2000000, $personas['Juan']['tokens']);
        $this->assertCount(2, $personas['Juan']['modelos']);

        // El modelo más caro primero, y con las diez claves del contrato de la respuesta.
        $sonnet = $personas['Juan']['modelos'][0];

        $this->assertSame('claude-sonnet-5', $sonnet['modelo']);
        $this->assertSame('anthropic', $sonnet['proveedor']);
        $this->assertEqualsWithDelta(2.00, $sonnet['costo_usd'], 0.0001);
        $this->assertTrue($sonnet['tiene_precio']);
        $this->assertSame(10, $sonnet['llamadas']);
        $this->assertSame(1000000, $sonnet['tokens']);

        foreach (['proveedor', 'modelo', 'llamadas', 'tokens', 'input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'costo_usd', 'tiene_precio'] as $clave) {
            $this->assertArrayHasKey($clave, $sonnet, 'Falta la clave `' . $clave . '` en el desglose por modelo.');
        }

        $this->assertSame('deepseek-flash', $personas['Juan']['modelos'][1]['modelo']);
        $this->assertEqualsWithDelta(0.30, $personas['Juan']['modelos'][1]['costo_usd'], 0.0001);

        // Brisa: un modelo sin precio deja el total en null, aunque el otro sí se pudo costear.
        $this->assertNull(
            $personas['Brisa']['costo_usd'],
            'Un modelo sin precio tiene que dejar a la persona en null: 1,00 sería un total que miente.'
        );
        $this->assertFalse($personas['Brisa']['tiene_precio_completo']);
        $this->assertCount(2, $personas['Brisa']['modelos']);

        $modelos_de_brisa = $this->indexar($personas['Brisa']['modelos'], 'modelo');

        $this->assertEqualsWithDelta(1.00, $modelos_de_brisa['claude-haiku-4-5']['costo_usd'], 0.0001);
        $this->assertTrue($modelos_de_brisa['claude-haiku-4-5']['tiene_precio']);
        $this->assertNull($modelos_de_brisa['modelo-inventado-9']['costo_usd']);
        $this->assertFalse($modelos_de_brisa['modelo-inventado-9']['tiene_precio']);

        // El sin precio va al final de la lista de modelos.
        $this->assertSame('modelo-inventado-9', $personas['Brisa']['modelos'][1]['modelo']);

        // Carla: solo en el corte por modelo, pero se agrega con sus números.
        $this->assertEqualsWithDelta(1.32, $personas['Carla']['costo_usd'], 0.0001);
        $this->assertTrue($personas['Carla']['tiene_precio_completo']);
        $this->assertSame(3, $personas['Carla']['llamadas']);
        $this->assertSame(9, $personas['Carla']['auth_user_id']);

        // Procesos automáticos: solo en el corte por persona, sin corte por modelo.
        $auto = $personas[ClientAiTokenUsagePerson::ETIQUETA_AUTOMATICO];

        $this->assertTrue($auto['es_automatico']);
        $this->assertNull($auto['costo_usd']);
        $this->assertFalse($auto['tiene_precio_completo']);
        $this->assertSame([], $auto['modelos']);
        $this->assertSame(400, $auto['llamadas']);

        // Orden: por costo descendente, los sin costo al final por tokens.
        $nombres = [];
        foreach ($payload['por_persona'] as $fila) {
            $nombres[] = $fila['nombre'];
        }

        $this->assertSame(
            ['Juan', 'Carla', 'Brisa', ClientAiTokenUsagePerson::ETIQUETA_AUTOMATICO],
            $nombres,
            'El orden tiene que ser por plata: 2,30, 1,32, y después los dos sin costo por tokens.'
        );
    }

    /**
     * Un cliente de versión anterior: `por_persona` sale como hasta ahora —tokens y llamadas—, con
     * `costo_usd` null y `modelos` vacío, `informa_modelo_por_persona` en false y la configuración
     * en null. Nada de esto puede romper ni inventar un costo.
     *
     * @return void
     */
    public function test_la_pestania_de_un_cliente_de_version_anterior_no_inventa_costo_por_persona(): void
    {
        $this->admin_logueado();

        $client = $this->crear_cliente();

        $this->sembrar_persona($client, '2026-09-16', 3, 'Juan', 14, 2000000);

        $response = $this->getJson(
            '/api/admin/client/' . $client->id . '/tokens?desde=2026-09-15&hasta=2026-09-17'
        );

        $response->assertStatus(200);

        $payload = $response->json();

        $this->assertFalse($payload['informa_modelo_por_persona']);
        $this->assertSame(['proveedor' => null, 'pensamiento' => null, 'modelo' => null], $payload['configuracion']);

        $this->assertCount(1, $payload['por_persona']);
        $this->assertSame('Juan', $payload['por_persona'][0]['nombre']);
        $this->assertSame(2000000, $payload['por_persona'][0]['tokens']);
        $this->assertNull($payload['por_persona'][0]['costo_usd'], 'Sin corte por modelo no hay costo: null, no cero.');
        $this->assertFalse($payload['por_persona'][0]['tiene_precio_completo']);
        $this->assertSame([], $payload['por_persona'][0]['modelos']);
    }

    // ------------------------------------------------------------------
    // El resumen global
    // ------------------------------------------------------------------

    /**
     * El resumen global suma `por_modelo` (con `tiene_precio`), `por_proveedor` y
     * `clientes_por_proveedor`, que cuenta solo clientes ACTIVOS agrupados por lo que informaron.
     *
     * @return void
     */
    public function test_el_resumen_global_trae_por_modelo_por_proveedor_y_clientes_por_proveedor(): void
    {
        $this->admin_logueado();

        /* Las dos tablas arrancan en un estado conocido para que las cifras sean comprobables. Los
         * cambios corren adentro de la transacción de la prueba, así que no tocan la base del slot:
         * el consumo se borra y los clientes que ya hubiera quedan inactivos, que es lo que los deja
         * fuera del conteo. */
        ClientAiTokenUsage::query()->delete();
        Client::query()->update(['is_active' => false]);

        $uno    = $this->crear_cliente('+5493411111111');
        $dos    = $this->crear_cliente('+5493412222222');
        $tres   = $this->crear_cliente('+5493413333333');
        $cuatro = $this->crear_cliente('+5493414444444');
        $cinco  = $this->crear_cliente('+5493415555555');

        $uno->update(['ai_proveedor' => 'deepseek']);
        $dos->update(['ai_proveedor' => 'deepseek']);
        $tres->update(['ai_proveedor' => 'anthropic']);
        // $cuatro queda en null: nunca informó.
        // $cinco informó DeepSeek pero está inactivo: no cuenta.
        $cinco->update(['ai_proveedor' => 'deepseek', 'is_active' => false]);

        $this->sembrar_consumo($uno, '2026-09-16', 'anthropic', 'claude-sonnet-5', 1000000);   // 2,00
        $this->sembrar_consumo($dos, '2026-09-16', 'deepseek', 'deepseek-flash', 1000000);     // 0,30
        $this->sembrar_consumo($tres, '2026-09-17', 'anthropic', 'modelo-inventado-9', 100000); // sin precio

        $response = $this->getJson('/api/admin/tokens/resumen?desde=2026-09-15&hasta=2026-09-17');

        $response->assertStatus(200);

        $payload = $response->json();

        // Por modelo: tres filas, la más cara primero, cada una con si tiene precio.
        $this->assertCount(3, $payload['por_modelo']);
        $this->assertSame('claude-sonnet-5', $payload['por_modelo'][0]['modelo']);

        $por_modelo = $this->indexar($payload['por_modelo'], 'modelo');

        $this->assertEqualsWithDelta(2.00, $por_modelo['claude-sonnet-5']['costo_usd'], 0.0001);
        $this->assertTrue($por_modelo['claude-sonnet-5']['tiene_precio']);
        $this->assertSame('anthropic', $por_modelo['claude-sonnet-5']['proveedor']);

        $this->assertEqualsWithDelta(0.30, $por_modelo['deepseek-flash']['costo_usd'], 0.0001);
        $this->assertTrue($por_modelo['deepseek-flash']['tiene_precio']);
        $this->assertSame('deepseek', $por_modelo['deepseek-flash']['proveedor']);

        $this->assertNull($por_modelo['modelo-inventado-9']['costo_usd']);
        $this->assertFalse($por_modelo['modelo-inventado-9']['tiene_precio']);

        // Por proveedor: anthropic mezcla un modelo con precio y uno sin, y lo dice.
        $por_proveedor = $this->indexar($payload['por_proveedor'], 'proveedor');

        $this->assertCount(2, $por_proveedor);
        $this->assertEqualsWithDelta(2.00, $por_proveedor['anthropic']['costo_usd'], 0.0001);
        $this->assertSame(1100000, $por_proveedor['anthropic']['tokens']);
        $this->assertSame(
            ['modelo-inventado-9'],
            $por_proveedor['anthropic']['modelos_sin_precio'],
            'El costo de anthropic es un piso y la fila tiene que nombrar el modelo que quedó afuera.'
        );

        $this->assertEqualsWithDelta(0.30, $por_proveedor['deepseek']['costo_usd'], 0.0001);
        $this->assertSame([], $por_proveedor['deepseek']['modelos_sin_precio']);

        // Clientes por proveedor: dos DeepSeek, uno Claude, uno sin informar. El inactivo no cuenta.
        $clientes = [];
        foreach ($payload['clientes_por_proveedor'] as $fila) {
            $clientes[$fila['proveedor'] === null ? 'null' : $fila['proveedor']] = $fila['clientes'];
        }

        $this->assertSame(2, $clientes['deepseek'], 'Tenían que contarse los dos activos con DeepSeek, sin el inactivo.');
        $this->assertSame(1, $clientes['anthropic']);
        $this->assertSame(1, $clientes['null'], 'El cliente que nunca informó tiene que contarse como "sin informar".');
        $this->assertSame(4, array_sum($clientes), 'Se coló un cliente inactivo en el conteo.');
    }

    // ------------------------------------------------------------------
    // Los precios
    // ------------------------------------------------------------------

    /**
     * Los tres modelos nuevos tienen precio, y la aritmética a mano de un caso de cada uno.
     *
     *   deepseek-flash  → 1.000.000 input × 0,30 + 100.000 output × 1,20 + 200.000 cache_read × 0,006
     *                     = 0,30 + 0,12 + 0,0012 = **0,4212 USD**
     *   deepseek-v4-pro → 1.000.000 input × 1,32 + 100.000 output × 3,96 + 500.000 cache_read × 0,044
     *                     = 1,32 + 0,396 + 0,022 = **1,738 USD**
     *   claude-opus-5   → 1.000.000 input × 5 + 100.000 output × 25 + 100.000 cache_write × 6,25
     *                     + 500.000 cache_read × 0,50 = 5 + 2,5 + 0,625 + 0,25 = **8,375 USD**
     *
     * Y la escritura en caché de DeepSeek cuesta CERO —no null—: es su precio real, y cero
     * significa "no costó nada".
     *
     * @return void
     */
    public function test_los_tres_modelos_nuevos_tienen_precio_y_la_cuenta_da(): void
    {
        foreach (['claude-opus-5', 'deepseek-flash', 'deepseek-v4-pro'] as $modelo) {
            $this->assertTrue(ClientAiTokenUsage::tiene_precio($modelo), 'Falta el precio de ' . $modelo . '.');
        }

        $this->assertEqualsWithDelta(0.4212, ClientAiTokenUsage::costo_usd('deepseek-flash', [
            'input_tokens'            => 1000000,
            'output_tokens'           => 100000,
            'cache_read_input_tokens' => 200000,
        ]), 0.000001);

        $this->assertEqualsWithDelta(1.738, ClientAiTokenUsage::costo_usd('deepseek-v4-pro', [
            'input_tokens'            => 1000000,
            'output_tokens'           => 100000,
            'cache_read_input_tokens' => 500000,
        ]), 0.000001);

        $this->assertEqualsWithDelta(8.375, ClientAiTokenUsage::costo_usd('claude-opus-5', [
            'input_tokens'                => 1000000,
            'output_tokens'               => 100000,
            'cache_creation_input_tokens' => 100000,
            'cache_read_input_tokens'     => 500000,
        ]), 0.000001);

        $solo_cache_write = ClientAiTokenUsage::costo_usd('deepseek-flash', [
            'cache_creation_input_tokens' => 1000000,
        ]);

        $this->assertNotNull($solo_cache_write, 'DeepSeek tiene precio: escribir la caché es gratis, no desconocido.');
        $this->assertEqualsWithDelta(0.0, $solo_cache_write, 0.000001);
    }
}
