<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAiTokenUsage;
use App\Models\ClientAiTokenUsagePerson;
use App\Services\ClientAiTokensSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * La recolección del consumo de tokens de IA desde el `empresa-api` de cada cliente.
 *
 * 🔴 **El test que más importa de este archivo es el del upsert** (`..._dos_veces_...`). Toda la
 * arquitectura de esta parte descansa en que volver a pedir un rango REESCRIBA en vez de acumular:
 * es lo que permite que la recolección nocturna use una ventana de tres días para que un cliente
 * caído se recupere solo, y es lo que permite apretar "Traer ahora" sin miedo. Si esa propiedad se
 * rompe, no se rompe con un error: se rompe con números que crecen solos y que nadie puede
 * distinguir de un cliente que gastó mucho.
 *
 * Hereda de `BaseDelCanal` por su `fakear_http()`, que hace `Http::swap()` ANTES del `fake` porque
 * `Http::fake()` acumula y gana el primero que matchea. Duplicar esa sutileza en otro archivo es
 * pedir que se olvide la mitad.
 */
class RecoleccionDeTokensTest extends BaseDelCanal
{
    /**
     * Payload de ejemplo del `empresa-api`, con las claves EXACTAS del contrato.
     *
     * 🔴 Los nombres de las claves se escriben acá a mano, tal cual los declara el plan, y no se
     * derivan de ninguna constante del admin: si mañana alguien renombra una columna del lado del
     * admin, este test tiene que ponerse en rojo. Es exactamente el lugar donde este proyecto ya se
     * quemó (`manual_tasks` vs `tareas`).
     *
     * @param array<int, array<string, mixed>> $dias     Bloque `dias`.
     * @param array<int, array<string, mixed>> $personas Bloque `personas`.
     *
     * @return array<string, mixed>
     */
    private function payload_de_consumo(array $dias, array $personas = []): array
    {
        return [
            'user_id'  => 500,
            'desde'    => '2026-09-15',
            'hasta'    => '2026-09-17',
            'dias'     => $dias,
            'personas' => $personas,
        ];
    }

    /**
     * Una fila del bloque `personas`.
     *
     * 🔴 **Lleva `fecha`, y tiene que llevarla.** El espejo del admin guarda una fila por cliente,
     * día y persona, con unique `(client_id, fecha, auth_user_id)`: sin día no hay dónde ubicar la
     * fila, y meterla con una fecha inventada haría que la corrida siguiente —con otro rango— la
     * pise con un agregado distinto. O sea, el acumulador desincronizado en silencio que toda esta
     * parte existe para no tener.
     *
     * @param string      $fecha        Día.
     * @param int|null    $auth_user_id Usuario de la base del cliente; null = procesos automáticos.
     * @param string|null $nombre       Nombre resuelto por el cliente.
     * @param int         $llamadas     Llamadas agregadas.
     * @param int         $input        Tokens de entrada.
     * @param int         $output       Tokens de salida.
     *
     * @return array<string, mixed>
     */
    private function persona(
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
     * Una fila del bloque `dias`, con los cuatro contadores.
     *
     * @param string $fecha    Día.
     * @param string $proceso  Acción que gastó.
     * @param string $modelo   Modelo.
     * @param int    $llamadas Llamadas agregadas.
     * @param int    $input    Tokens de entrada.
     * @param int    $output   Tokens de salida.
     *
     * @return array<string, mixed>
     */
    private function fila(
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
     * Cliente listo para que le pidan el consumo: con ClientApi activa y api_key.
     *
     * @param string $url URL de su `empresa-api`.
     *
     * @return Client
     */
    private function cliente_consultable(string $url = 'https://api-ferreteria.test'): Client
    {
        $client = $this->crear_cliente();
        $this->crear_client_api($client, $url, 'shared_hosting');

        return $client->fresh();
    }

    /**
     * El camino feliz: el cliente contesta, las filas quedan guardadas y el estado es success.
     *
     * @return void
     */
    public function test_el_sync_guarda_las_filas_y_marca_el_cliente_como_sincronizado(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response($this->payload_de_consumo([
                $this->fila('2026-09-16', 'chat_mensaje', 'claude-sonnet-5', 12, 41230, 3100),
                $this->fila('2026-09-17', 'whatsapp_sugerencia', 'claude-haiku-4-5', 3, 5000, 400),
            ]), 200),
        ]);

        $resultado = app(ClientAiTokensSyncService::class)->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        $this->assertSame(ClientAiTokensSyncService::ESTADO_SUCCESS, $resultado['estado']);
        $this->assertSame(2, $resultado['filas']);

        $this->assertSame(2, ClientAiTokenUsage::where('client_id', $client->id)->count());

        $fila = ClientAiTokenUsage::where('client_id', $client->id)
            ->where('proceso', 'chat_mensaje')
            ->first();

        $this->assertNotNull($fila);
        $this->assertSame('2026-09-16', substr((string) $fila->fecha, 0, 10));
        $this->assertSame('claude-sonnet-5', $fila->modelo);
        $this->assertSame('anthropic', $fila->proveedor);
        $this->assertSame(12, $fila->llamadas);
        $this->assertSame(41230, $fila->input_tokens);
        $this->assertSame(3100, $fila->output_tokens);

        $client->refresh();
        $this->assertSame(ClientAiTokensSyncService::ESTADO_SUCCESS, $client->ai_tokens_sync_status);
        $this->assertNull($client->ai_tokens_sync_message);
        $this->assertNotNull($client->ai_tokens_synced_at);
    }

    /**
     * 🔴 **El test que sostiene toda la mecánica.** Correr el sync dos veces con los MISMOS datos
     * tiene que dejar exactamente el mismo resultado: ni una fila de más, ni un token de más.
     *
     * Sin esta propiedad, la ventana de tres días de la recolección nocturna triplicaría el consumo
     * de cada cliente todas las noches, y el número resultante sería indistinguible de un cliente
     * que gastó tres veces más.
     *
     * @return void
     */
    public function test_correr_el_sync_dos_veces_con_los_mismos_datos_no_duplica_ni_acumula(): void
    {
        $client = $this->cliente_consultable();

        $dias = [
            $this->fila('2026-09-16', 'chat_mensaje', 'claude-sonnet-5', 12, 41230, 3100),
            $this->fila('2026-09-17', 'embeddings_articulos', 'text-embedding-3-small', 400, 80000, 0),
        ];

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response($this->payload_de_consumo($dias), 200),
        ]);

        $servicio = app(ClientAiTokensSyncService::class);

        $servicio->traer_del_cliente($client, '2026-09-15', '2026-09-17');
        $segundo = $servicio->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        /* 🔴 Sin esta aserción el test pasa por el motivo equivocado: si el upsert fuera un
         * `create()` pelado, la segunda corrida explotaría contra el unique, la transacción
         * revertiría, el service lo atraparía y devolvería `failed` — y el conteo daría 2 igual,
         * porque las filas de la PRIMERA corrida siguen ahí. La idempotencia es que la segunda
         * corrida termine BIEN, no solo que no deje filas de más. */
        $this->assertSame(ClientAiTokensSyncService::ESTADO_SUCCESS, $segundo['estado']);

        $this->assertSame(
            2,
            ClientAiTokenUsage::where('client_id', $client->id)->count(),
            'La segunda corrida creó filas nuevas: el upsert no está pegando contra el unique.'
        );

        $chat = ClientAiTokenUsage::where('client_id', $client->id)
            ->where('proceso', 'chat_mensaje')
            ->first();

        $this->assertSame(12, $chat->llamadas, 'Las llamadas se acumularon en vez de reescribirse.');
        $this->assertSame(41230, $chat->input_tokens, 'Los tokens se acumularon en vez de reescribirse.');
    }

    /**
     * Correrlo con datos distintos para el mismo día reescribe la fila: el cliente es la fuente de
     * verdad y el admin es un espejo, no un libro mayor.
     *
     * Es el caso REAL de la ventana de tres días: el día de ayer se vuelve a pedir cuando ya tiene
     * más consumo acumulado que cuando se pidió por primera vez.
     *
     * @return void
     */
    public function test_pedir_de_nuevo_un_dia_que_creyo_reescribe_la_fila_con_el_valor_nuevo(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response($this->payload_de_consumo([
                $this->fila('2026-09-16', 'chat_mensaje', 'claude-sonnet-5', 5, 10000, 500),
            ]), 200),
        ]);

        $servicio = app(ClientAiTokensSyncService::class);
        $servicio->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        // Más tarde el mismo día siguió gastando: el cliente ahora informa más.
        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response($this->payload_de_consumo([
                $this->fila('2026-09-16', 'chat_mensaje', 'claude-sonnet-5', 9, 22000, 1300),
            ]), 200),
        ]);

        $segundo = $servicio->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        // Ver el comentario del test del upsert: sin esto, un `create()` pelado pasaría igual.
        $this->assertSame(ClientAiTokensSyncService::ESTADO_SUCCESS, $segundo['estado']);

        $this->assertSame(1, ClientAiTokenUsage::where('client_id', $client->id)->count());

        $fila = ClientAiTokenUsage::where('client_id', $client->id)->first();

        $this->assertSame(9, $fila->llamadas);
        $this->assertSame(22000, $fila->input_tokens);
        $this->assertSame(1300, $fila->output_tokens);
    }

    /**
     * El mismo upsert, del lado de las personas: correrlo dos veces no acumula ni duplica.
     *
     * Vale igual que el de los días y por el mismo motivo: la recolección nocturna vuelve a pedir
     * los últimos tres días todas las noches. Si esto se acumulara, el empleado que más usa el
     * sistema aparecería gastando el triple, que es un número que nadie tiene con qué desmentir.
     *
     * @return void
     */
    public function test_el_upsert_por_persona_tampoco_acumula_al_correr_dos_veces(): void
    {
        $client = $this->cliente_consultable();

        $personas = [
            $this->persona('2026-09-16', 3, 'Juan', 40, 90000, 5000),
            $this->persona('2026-09-16', 7, 'Brisa', 12, 20000, 1500),
            $this->persona('2026-09-17', 3, 'Juan', 5, 8000, 400),
        ];

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response($this->payload_de_consumo([
                $this->fila('2026-09-16', 'chat_mensaje', 'claude-sonnet-5', 52, 110000, 6500),
            ], $personas), 200),
        ]);

        $servicio = app(ClientAiTokensSyncService::class);

        $servicio->traer_del_cliente($client, '2026-09-15', '2026-09-17');
        $segundo = $servicio->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        // Ver el comentario del test del upsert: sin esto, un `create()` pelado pasaría igual.
        $this->assertSame(ClientAiTokensSyncService::ESTADO_SUCCESS, $segundo['estado']);

        $this->assertSame(
            3,
            ClientAiTokenUsagePerson::where('client_id', $client->id)->count(),
            'La segunda corrida creó filas de persona nuevas: el upsert no pega contra el unique.'
        );

        $juan_del_16 = ClientAiTokenUsagePerson::where('client_id', $client->id)
            ->where('auth_user_id', 3)
            ->where('fecha', '2026-09-16')
            ->first();

        $this->assertSame(40, $juan_del_16->llamadas, 'Las llamadas de la persona se acumularon.');
        $this->assertSame(90000, $juan_del_16->input_tokens, 'Los tokens de la persona se acumularon.');

        // Y el corte de lectura pliega los dos días de Juan en una sola línea del rango.
        $resumen = ClientAiTokenUsagePerson::resumir((int) $client->id, '2026-09-15', '2026-09-17');

        $this->assertCount(2, $resumen, 'El resumen tiene que tener una línea por persona, no por día.');
        $this->assertSame('Juan', $resumen[0]['nombre']);
        $this->assertSame(45, $resumen[0]['llamadas']);
        $this->assertSame(98000 + 5400, $resumen[0]['tokens']);
    }

    /**
     * 🔴 La fila de los procesos automáticos: llega con `auth_user_id` NULO del otro lado y tiene
     * que guardarse como UNA sola fila por día, no una por corrida.
     *
     * Es el caso que obliga al centinela 0 en vez de una columna nullable: en MySQL un NULL no
     * colisiona con otro NULL adentro de un índice único, así que con `auth_user_id` nullable esta
     * fila —la que más se repite, porque el scheduler de embeddings corre todos los días— se
     * apilaría en cada corrida y el consumo "de nadie" crecería solo, sin que nada avise.
     *
     * @return void
     */
    public function test_el_consumo_sin_persona_se_guarda_y_se_lee_como_una_sola_fila(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response($this->payload_de_consumo([
                $this->fila('2026-09-16', 'embeddings_articulos', 'text-embedding-3-small', 400, 80000, 0),
            ], [
                // Tal cual lo manda el contrato: sin persona detrás, `auth_user_id` viaja en null.
                $this->persona('2026-09-16', null, null, 400, 80000, 0),
            ]), 200),
        ]);

        $servicio = app(ClientAiTokensSyncService::class);

        $servicio->traer_del_cliente($client, '2026-09-15', '2026-09-17');
        $servicio->traer_del_cliente($client, '2026-09-15', '2026-09-17');
        $tercero = $servicio->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        // Ver el comentario del test del upsert: sin esto, un `create()` pelado pasaría igual.
        $this->assertSame(ClientAiTokensSyncService::ESTADO_SUCCESS, $tercero['estado']);

        $filas = ClientAiTokenUsagePerson::where('client_id', $client->id)->get();

        $this->assertCount(
            1,
            $filas,
            'Tres corridas dejaron más de una fila de procesos automáticos: el NULL se está '
            . 'colando en la clave única.'
        );

        $this->assertSame(
            ClientAiTokenUsagePerson::AUTOMATICO,
            $filas[0]->auth_user_id,
            'El auth_user_id nulo del contrato tiene que guardarse como el centinela 0.'
        );
        $this->assertNull($filas[0]->nombre);
        $this->assertSame(400, $filas[0]->llamadas);

        // Y al leer se muestra con nombre propio, no como un renglón en blanco ni como "Usuario #0".
        $resumen = ClientAiTokenUsagePerson::resumir((int) $client->id, '2026-09-15', '2026-09-17');

        $this->assertCount(1, $resumen);
        $this->assertTrue($resumen[0]['es_automatico']);
        $this->assertNull($resumen[0]['auth_user_id'], 'Hacia afuera el centinela vuelve a ser null.');
        $this->assertSame(ClientAiTokenUsagePerson::ETIQUETA_AUTOMATICO, $resumen[0]['nombre']);
        $this->assertSame(80000, $resumen[0]['tokens']);
    }

    /**
     * 🔴 Un HTTP 200 cuyo cuerpo NO es el payload de consumo tiene que quedar `failed`, no
     * `success` con cero filas.
     *
     * No es un caso de laboratorio: el shared hosting de Hostinger sirve su página genérica **con
     * HTTP 200** cuando la cuenta está saturada. `Response::json()` es un `json_decode` pelado, así
     * que ese HTML devuelve null y un guard flojo lo convierte en "no gastó nada". El resultado
     * sería una solapa que dice "Traído el 18/09 03:15" mostrando cero tokens — exactamente
     * indistinguible de un cliente que de verdad no gastó nada, que es la confusión que la columna
     * de estado existe para evitar.
     *
     * @return void
     */
    public function test_un_200_que_no_es_el_payload_de_consumo_queda_failed(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response(
                '<html><head><title>Service Unavailable</title></head><body>Este sitio no está disponible</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $resultado = app(ClientAiTokensSyncService::class)->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        $this->assertSame(
            ClientAiTokensSyncService::ESTADO_FAILED,
            $resultado['estado'],
            'Un 200 con la página del hosting quedó en verde: el guard no está mirando la forma del payload.'
        );
        $this->assertStringContainsString('dias', (string) $resultado['mensaje']);

        $client->refresh();
        $this->assertSame(ClientAiTokensSyncService::ESTADO_FAILED, $client->ai_tokens_sync_status);
        $this->assertNull(
            $client->ai_tokens_synced_at,
            'Un cuerpo que no es el payload no puede estampar la fecha de última sincronización.'
        );
    }

    /**
     * 🔴 `proveedor` es parte de la clave: dos filas del mismo payload con el mismo modelo y
     * distinto proveedor son DOS filas, no una.
     *
     * El origen agrupa por `(fecha, proceso, proveedor, modelo)` —cuatro dimensiones— y el espejo
     * tiene que indexar las mismas cuatro más el cliente. Con `proveedor` en los valores, las dos
     * filas colapsan en una y la que queda tiene los contadores de la última en vez de la suma:
     * consumo que desaparece sin que nada lo denuncie.
     *
     * @return void
     */
    public function test_dos_filas_con_el_mismo_modelo_y_distinto_proveedor_no_colapsan(): void
    {
        $client = $this->cliente_consultable();

        $una = $this->fila('2026-09-16', 'chat_mensaje', 'modelo-compartido', 3, 10000, 500);
        $una['proveedor'] = 'anthropic';

        $otra = $this->fila('2026-09-16', 'chat_mensaje', 'modelo-compartido', 7, 44000, 900);
        $otra['proveedor'] = 'openai';

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response(
                $this->payload_de_consumo([$una, $otra]),
                200
            ),
        ]);

        $resultado = app(ClientAiTokensSyncService::class)->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        $this->assertSame(
            2,
            ClientAiTokenUsage::where('client_id', $client->id)->count(),
            'Las dos filas colapsaron en una: `proveedor` no está en la clave única.'
        );

        $this->assertSame(2, $resultado['filas'], 'El contador informó algo distinto de lo que entró.');

        $anthropic = ClientAiTokenUsage::where('client_id', $client->id)->where('proveedor', 'anthropic')->first();
        $openai    = ClientAiTokenUsage::where('client_id', $client->id)->where('proveedor', 'openai')->first();

        $this->assertSame(10000, $anthropic->input_tokens);
        $this->assertSame(44000, $openai->input_tokens);
    }

    /**
     * 🔴 Una fila con una fecha fuera del rango pedido se descarta.
     *
     * Si se guardara, quedaría **para siempre**: el upsert solo pisa lo que la fuente vuelve a
     * informar, y el admin nunca vuelve a pedir ese día. Ninguna corrida futura la tocaría ni la
     * borraría, y el espejo dejaría de ser reconstruible desde la fuente — que es lo único que
     * justifica que esta tabla exista.
     *
     * @return void
     */
    public function test_una_fila_con_fecha_fuera_del_rango_pedido_se_descarta(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response($this->payload_de_consumo([
                $this->fila('2026-09-16', 'chat_mensaje', 'claude-sonnet-5', 2, 3000, 100),
                // El cliente contesta de más: un día de 2024 que nadie pidió.
                $this->fila('2024-01-05', 'chat_mensaje', 'claude-sonnet-5', 99, 999999, 99999),
                // Y uno del día siguiente al rango.
                $this->fila('2026-09-18', 'chat_mensaje', 'claude-sonnet-5', 50, 50000, 5000),
            ], [
                $this->persona('2024-01-05', 3, 'Juan', 99, 999999, 99999),
            ]), 200),
        ]);

        $resultado = app(ClientAiTokensSyncService::class)->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        $this->assertSame(ClientAiTokensSyncService::ESTADO_SUCCESS, $resultado['estado']);

        $filas = ClientAiTokenUsage::where('client_id', $client->id)->get();

        $this->assertCount(1, $filas, 'Entraron filas de días que nadie pidió.');
        $this->assertSame('2026-09-16', substr((string) $filas[0]->fecha, 0, 10));

        $this->assertSame(
            0,
            ClientAiTokenUsagePerson::where('client_id', $client->id)->count(),
            'La fila de persona fuera del rango también tiene que descartarse.'
        );

        // Y el contador informa solo lo que efectivamente entró.
        $this->assertSame(1, $resultado['filas']);
    }

    /**
     * Un corte de conexión o un timeout deja el cliente en `failed`, sin excepción y sin fecha.
     *
     * 🔴 Es la rama que MÁS se va a ejecutar en producción —cuarenta y cinco instancias en shared
     * hosting, todas las noches— y es la única que no tenía prueba. Acá no hay respuesta HTTP
     * asociada, así que es el único camino donde `$response` queda en null.
     *
     * @return void
     */
    public function test_un_corte_de_conexion_deja_el_cliente_en_failed_sin_excepcion(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException(
                    'cURL error 28: Operation timed out after 15000 milliseconds'
                );
            },
        ]);

        $resultado = app(ClientAiTokensSyncService::class)->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        $this->assertSame(ClientAiTokensSyncService::ESTADO_FAILED, $resultado['estado']);
        $this->assertStringContainsString('timed out', (string) $resultado['mensaje']);
        $this->assertSame(0, ClientAiTokenUsage::where('client_id', $client->id)->count());

        $client->refresh();
        $this->assertSame(ClientAiTokensSyncService::ESTADO_FAILED, $client->ai_tokens_sync_status);
        $this->assertNull($client->ai_tokens_synced_at);
    }

    /**
     * 404: la versión instalada del cliente todavía no tiene el endpoint.
     *
     * 🔴 Es el caso ESPERADO durante semanas, no un error. Tiene estado propio (`no_soportado`), no
     * escribe ninguna fila y no toca `ai_tokens_synced_at`. Y sobre todo: **no lanza**, porque esto
     * corre adentro de un barrido de cuarenta y cinco clientes.
     *
     * @return void
     */
    public function test_un_cliente_sin_el_endpoint_queda_no_soportado_sin_filas_y_sin_excepcion(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $resultado = app(ClientAiTokensSyncService::class)->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        $this->assertSame(ClientAiTokensSyncService::ESTADO_NO_SOPORTADO, $resultado['estado']);
        $this->assertSame(0, ClientAiTokenUsage::where('client_id', $client->id)->count());

        $client->refresh();
        $this->assertSame(ClientAiTokensSyncService::ESTADO_NO_SOPORTADO, $client->ai_tokens_sync_status);
        $this->assertNotNull($client->ai_tokens_sync_message);
        $this->assertNull(
            $client->ai_tokens_synced_at,
            'Un 404 no puede estampar la fecha de última sincronización exitosa.'
        );
    }

    /**
     * 401: la api_key del admin no coincide con la del cliente. Eso SÍ es un fallo, y el motivo
     * tiene que quedar escrito para que alguien pueda arreglarlo sin leer el log.
     *
     * @return void
     */
    public function test_un_401_deja_el_cliente_en_failed_con_el_motivo_escrito(): void
    {
        $client = $this->cliente_consultable();

        $this->fakear_http([
            '*/api/admin-sync/consumo-ia*' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $resultado = app(ClientAiTokensSyncService::class)->traer_del_cliente($client, '2026-09-15', '2026-09-17');

        $this->assertSame(ClientAiTokensSyncService::ESTADO_FAILED, $resultado['estado']);
        $this->assertStringContainsString('401', (string) $resultado['mensaje']);
        $this->assertStringContainsString('api_key', (string) $resultado['mensaje']);

        $client->refresh();
        $this->assertSame(ClientAiTokensSyncService::ESTADO_FAILED, $client->ai_tokens_sync_status);
        $this->assertStringContainsString('401', (string) $client->ai_tokens_sync_message);
    }

    /**
     * Un cliente que explota no puede llevarse puesto el barrido de los demás.
     *
     * El servicio ya se compromete a no lanzar; esto prueba el cinturón sobre los tirantes, que es
     * el `try/catch` por cliente del comando. Se reemplaza el servicio en el contenedor por uno que
     * revienta a propósito para el primer cliente: es la única forma de ejercitar ese `catch` sin
     * romper el contrato del servicio real.
     *
     * @return void
     */
    public function test_el_barrido_sigue_con_el_resto_cuando_un_cliente_explota(): void
    {
        $primero = $this->crear_cliente('+5493411111111');
        $segundo = $this->crear_cliente('+5493412222222');

        $explosivo = new class extends ClientAiTokensSyncService {
            /** @var int Id del cliente al que se le hace reventar la consulta. */
            public $revienta_a = 0;

            /** @var array<int, int> Ids visitados, en orden. */
            public $visitados = [];

            public function traer_del_cliente(Client $client, $desde, $hasta)
            {
                $this->visitados[] = (int) $client->id;

                if ((int) $client->id === $this->revienta_a) {
                    throw new \RuntimeException('El empresa-api de este cliente se cayó a pedazos.');
                }

                return [
                    'estado'          => ClientAiTokensSyncService::ESTADO_SUCCESS,
                    'mensaje'         => null,
                    'filas'           => 0,
                    'sincronizado_at' => null,
                ];
            }
        };

        $explosivo->revienta_a = (int) $primero->id;

        $this->app->instance(ClientAiTokensSyncService::class, $explosivo);

        $salida = Artisan::call('tokens:recolectar', ['--dias' => 3]);

        $this->assertSame(0, $salida, 'El comando tiene que terminar en 0 aunque un cliente explote.');
        $this->assertContains((int) $primero->id, $explosivo->visitados);
        $this->assertContains(
            (int) $segundo->id,
            $explosivo->visitados,
            'El barrido se cortó en el cliente que explotó y no llegó al siguiente.'
        );
    }
}
