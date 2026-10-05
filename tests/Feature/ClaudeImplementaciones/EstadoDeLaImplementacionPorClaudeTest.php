<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Http\Controllers\Api\ClaudeImplementationOpsController;
use App\Models\AdminSetting;
use App\Models\DeploymentLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La lectura del estado de una implementación por `claude/*`:
 * `GET claude/implementations/{id}` y `GET claude/implementations?client_id=|lead_id=`.
 *
 * Lo que se protege, en orden de importancia:
 *
 *  1. 🔴 Que no se filtre ninguna credencial: ni `api_key` / `inbound_api_key` del cliente ni el
 *     `form_token` pelado. La respuesta se arma campo por campo y no serializando modelos.
 *  2. 🔴 Que los datos personales no viajen salvo que se pidan: el contacto con `include=contacto` y,
 *     del formulario, el DNI y el teléfono de los empleados solo con `include=contacto` también. El
 *     mail y el CUIT del dueño no salen nunca.
 *  3. Que `entrantes` cuente bien lo que el cliente escribió y nadie contestó —sin tomar por respuesta
 *     un saliente fallido—, porque es lo único que avisa que el hilo de la implementación se está
 *     comiendo mensajes.
 *  4. Que el estado del user setup sea el correcto en cada caso, incluido el que quedó colgado.
 *  5. Que sin la tabla de mails (migración de otra parte de la misión) el estado no se rompa.
 *  6. Los frenos de siempre: sin clave 401, lista blanca de parámetros, includes inválidos 422.
 */
class EstadoDeLaImplementacionPorClaudeTest extends BaseDeImplementaciones
{
    /* ------------------------------------------------------------------------------------------
     | Escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Un cliente con sus dos APIs, su implementación y un teléfono conocido.
     *
     * @return array{cliente: \App\Models\Client, implementacion: \App\Models\Implementation}
     */
    private function escenario(): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez', ['phone' => '+5493415559999', 'email' => 'panchito@ejemplo.test']);
        $cliente = $this->crear_las_dos_apis($cliente, 'panchito');

        return ['cliente' => $cliente, 'implementacion' => $this->crear_implementacion($cliente)];
    }

    /**
     * Respuestas del formulario como las deja el formulario público en la etapa 1.
     *
     * @return array<string, mixed>
     */
    private function respuestas_del_formulario(): array
    {
        return [
            'price_mode'        => 'lists',
            'price_lists'       => [['name' => 'Minorista', 'margin' => 30], ['name' => 'Mayorista', 'margin' => 15]],
            'stock_mode'        => 'deposits',
            'deposit_names'     => [['name' => 'Local centro'], ['name' => 'Depósito']],
            'apply_iva'         => 'yes',
            'company_name'      => 'Panchito S.A.',
            'address_company'   => 'San Martín 123',
            'facebook'          => 'panchito.sa.oficial',
            'instagram'         => '@panchito.sa',
            'social_networks'   => 'Instagram @panchito.sa',
            'doc_number'        => '20304050607',
            'email'             => 'dueno-secreto@ejemplo.test',
            'employees'         => [
                ['name' => 'Ana Pérez', 'dni' => '30111222', 'phone' => '3415551234'],
                ['name' => 'Beto Ríos', 'dni' => '28999888', 'phone' => '3415554321'],
            ],
            'migration_responsible' => 'Ana Pérez',
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Los frenos
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin la clave, 401 en las dos rutas: el middleware es fail-closed.
     *
     * @return void
     */
    public function test_sin_clave_las_dos_rutas_devuelven_401(): void
    {
        $this->getJson('/api/claude/implementations/1')->assertStatus(401);
        $this->getJson('/api/claude/implementations?client_id=1')->assertStatus(401);
    }

    /**
     * Un parámetro que el endpoint no conoce es 422 y nombra cuál.
     *
     * @return void
     */
    public function test_un_parametro_desconocido_es_422(): void
    {
        $e = $this->escenario();

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id . '?force=1', $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('force', $this->cuerpo($respuesta));
        $respuesta->assertJsonPath('parametros_aceptados.0', 'include');

        $this->getJson('/api/claude/implementations?client_id=1&mode=auto', $this->headers())->assertStatus(422);
    }

    /**
     * Un include que no existe es 422 y dice cuáles hay.
     *
     * @return void
     */
    public function test_un_include_invalido_es_422(): void
    {
        $e = $this->escenario();

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id . '?include=todo', $this->headers());

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('includes_validos', ['contacto', 'formulario', 'logs']);
    }

    /**
     * Una implementación que no existe es 404 con la forma del bloque.
     *
     * @return void
     */
    public function test_una_implementacion_inexistente_es_404(): void
    {
        $this->getJson('/api/claude/implementations/99999999', $this->headers())
            ->assertStatus(404)
            ->assertJsonStructure(['error']);
    }

    /**
     * Un id que no es un número no llega al controlador: la ruta lo descarta (404), y no opera la
     * implementación 12 por comerse la cola del `12-borrame`.
     *
     * @return void
     */
    public function test_un_id_con_cola_no_opera_otra_implementacion(): void
    {
        $e = $this->escenario();

        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id . '-borrame', $this->headers())
            ->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | La forma de la respuesta
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. La forma completa por defecto: implementación, cliente, ocho etapas, user setup, instalaciones,
     * mails y entrantes. Sin `contacto` ni `formulario`.
     *
     * @return void
     */
    public function test_la_forma_por_defecto(): void
    {
        AdminSetting::set('implementation_form_url', 'https://admin.ejemplo.test/configuracion/');
        $e = $this->escenario();

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonStructure([
            'implementation' => ['id', 'client_id', 'current_stage', 'status', 'automation_mode', 'started_at', 'completed_at', 'form_submitted_at', 'user_setup_executed_at', 'form_link'],
            'cliente'        => ['id', 'name', 'company_name', 'slug', 'user_id', 'is_active', 'sistema' => ['spa_url', 'api_url', 'path', 'hosting_type', 'active_client_api_id']],
            'etapas',
            'user_setup'     => ['estado', 'ejecutado_at', 'iniciado_at', 'terminado_at', 'error'],
            'instalaciones',
            'mails',
            'entrantes'      => ['sin_responder', 'ultimo_at'],
        ]);

        $respuesta->assertJsonPath('implementation.id', (int) $e['implementacion']->id);
        $respuesta->assertJsonPath('implementation.current_stage', 1);
        $respuesta->assertJsonPath('implementation.status', 'in_progress');
        $respuesta->assertJsonPath('implementation.automation_mode', 'manual');
        $respuesta->assertJsonPath('implementation.form_link', 'https://admin.ejemplo.test/configuracion/' . $e['implementacion']->form_token);

        $respuesta->assertJsonPath('cliente.id', (int) $e['cliente']->id);
        $respuesta->assertJsonPath('cliente.name', 'Panchito Gómez');
        $respuesta->assertJsonPath('cliente.sistema.spa_url', 'https://panchito.ejemplo.test');
        $respuesta->assertJsonPath('cliente.sistema.path', 'panchito/api');
        $respuesta->assertJsonPath('cliente.sistema.hosting_type', 'shared_hosting');
        $respuesta->assertJsonPath('cliente.sistema.active_client_api_id', (int) $e['cliente']->active_client_api_id);

        /* La URL de la API sale normalizada como el resto del admin: en el hosting compartido lleva /public. */
        $respuesta->assertJsonPath('cliente.sistema.api_url', 'https://api-panchito.ejemplo.test/public');

        $this->assertArrayNotHasKey('contacto', $respuesta->json('cliente'));
        $this->assertArrayNotHasKey('formulario', $respuesta->json());
    }

    /**
     * Sin la URL base del formulario cargada, `form_link` es null (y no un link roto con "/token").
     *
     * @return void
     */
    public function test_sin_la_url_del_formulario_el_link_es_null(): void
    {
        $e = $this->escenario();

        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('implementation.form_link', null);
    }

    /**
     * Las ocho etapas, con el nombre del catálogo y el estado real, y las acciones registradas con su
     * origen (las del panel no traen canal).
     *
     * @return void
     */
    public function test_las_ocho_etapas_con_sus_acciones(): void
    {
        $e = $this->escenario();
        $this->llevar_a_la_etapa($e['implementacion'], 3);
        $this->escribir_data_de_la_etapa($e['implementacion'], 1, ['actions' => [
            ['action' => 'presentacion', 'stage' => 1, 'at' => '2026-10-05T14:00:00.000000Z'],
            ['action' => 'form_link', 'stage' => 1, 'at' => '2026-10-05T14:05:00.000000Z', 'canal' => 'whatsapp_web', 'origen' => 'claude'],
        ]]);

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $etapas = $respuesta->json('etapas');
        $this->assertCount(8, $etapas);

        foreach ($etapas as $indice => $etapa) {
            $this->assertSame($indice + 1, $etapa['numero']);
            $this->assertNotSame('', trim((string) $etapa['nombre']));
        }

        $this->assertSame('completed', $etapas[0]['estado']);
        $this->assertSame('completed', $etapas[1]['estado']);
        $this->assertSame('in_progress', $etapas[2]['estado']);
        $this->assertSame('pending', $etapas[3]['estado']);
        $this->assertNotNull($etapas[0]['completed_at']);

        $this->assertSame([
            ['accion' => 'presentacion', 'at' => '2026-10-05T14:00:00.000000Z', 'canal' => null, 'origen' => 'panel'],
            ['accion' => 'form_link', 'at' => '2026-10-05T14:05:00.000000Z', 'canal' => 'whatsapp_web', 'origen' => 'claude'],
        ], $etapas[0]['acciones']);
        $this->assertSame([], $etapas[1]['acciones']);
    }

    /* ------------------------------------------------------------------------------------------
     | Credenciales y datos personales
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. 🔴 Ninguna credencial viaja: ni las claves del cliente ni una clave `form_token` suelta. El
     * token solo aparece adentro del `form_link`, que es lo que la skill le manda al cliente.
     *
     * @return void
     */
    public function test_no_se_filtra_ninguna_credencial(): void
    {
        AdminSetting::set('implementation_form_url', 'https://admin.ejemplo.test/configuracion');
        $e = $this->escenario();

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id . '?include=contacto,formulario,logs', $this->headers());
        $cuerpo    = $this->cuerpo($respuesta);

        $this->assertStringNotContainsString($e['cliente']->api_key, $cuerpo, 'Salió la api_key del cliente.');
        $this->assertStringNotContainsString($e['cliente']->inbound_api_key, $cuerpo, 'Salió la inbound_api_key del cliente.');
        $this->assertStringNotContainsString('"api_key"', $cuerpo);
        $this->assertStringNotContainsString('"inbound_api_key"', $cuerpo);
        $this->assertStringNotContainsString('"form_token"', $cuerpo, 'El form_token solo puede salir adentro del form_link.');
        $this->assertStringNotContainsString('provisioning_secrets', $cuerpo);
    }

    /**
     * 2. 🔴 Sin `include=contacto` no viaja ni el teléfono ni el mail del cliente, en ninguna parte.
     *
     * @return void
     */
    public function test_sin_contacto_no_viajan_el_telefono_ni_el_mail(): void
    {
        $e = $this->escenario();

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());
        $cuerpo    = $this->cuerpo($respuesta);

        $this->assertStringNotContainsString('5559999', $cuerpo, 'Salió el teléfono del cliente sin pedir el contacto.');
        $this->assertStringNotContainsString('panchito@ejemplo.test', $cuerpo, 'Salió el mail del cliente sin pedir el contacto.');
    }

    /**
     * Con `include=contacto`: teléfono, mail, mail del formulario y teléfono del responsable.
     *
     * @return void
     */
    public function test_con_contacto_viajan_los_cuatro_datos(): void
    {
        $e = $this->escenario();
        $e['cliente']->setup_data = ['email' => 'dueno-secreto@ejemplo.test'];
        $e['cliente']->save();
        $e['implementacion']->migration_contact_phone = '+5493415550101';
        $e['implementacion']->save();

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id . '?include=contacto', $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('cliente.contacto.phone', '+5493415559999');
        $respuesta->assertJsonPath('cliente.contacto.email', 'panchito@ejemplo.test');
        $respuesta->assertJsonPath('cliente.contacto.email_formulario', 'dueno-secreto@ejemplo.test');
        $respuesta->assertJsonPath('cliente.contacto.migration_contact_phone', '+5493415550101');
    }

    /**
     * 2. 🔴 Con `include=formulario` solo, el resumen sale SIN el DNI ni el teléfono de los empleados
     * (queda el nombre), y el mail y el documento del dueño no salen en ningún caso.
     *
     * @return void
     */
    public function test_el_formulario_sin_contacto_no_trae_dni_ni_telefonos(): void
    {
        $e = $this->escenario();
        $this->escribir_data_de_la_etapa($e['implementacion'], 1, ['form_responses' => $this->respuestas_del_formulario()]);
        $e['implementacion']->form_submitted_at = now();
        $e['implementacion']->save();

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id . '?include=formulario', $this->headers());

        $respuesta->assertStatus(200);
        $this->assertNotNull($respuesta->json('formulario.enviado_at'));

        $cuerpo = $this->cuerpo($respuesta);
        $this->assertStringContainsString('Minorista', $cuerpo, 'El resumen no trae las listas de precios.');
        $this->assertStringContainsString('Local centro', $cuerpo);
        $this->assertStringContainsString('Ana Pérez', $cuerpo, 'Tiene que quedar el nombre de los empleados.');
        $this->assertStringContainsString('Panchito S.A.', $cuerpo, 'Tiene que quedar el nombre del negocio.');

        foreach (['30111222', '28999888', '3415551234', '3415554321', '20304050607', 'dueno-secreto@ejemplo.test'] as $dato_personal) {
            $this->assertStringNotContainsString($dato_personal, $cuerpo, 'Salió un dato personal sin pedir el contacto: ' . $dato_personal);
        }
    }

    /**
     * 2. 🔴 Con `include=formulario` solo, el resumen tampoco trae la DIRECCIÓN ni las REDES del negocio: son datos
     * de contacto del negocio y esta lectura la hace una sesión que los pega en una conversación. Quedan el
     * nombre del negocio y lo que sirve para decidir el paso (precios, stock, equipo por nombre).
     *
     * @return void
     */
    public function test_el_formulario_sin_contacto_no_trae_la_direccion_ni_las_redes_del_negocio(): void
    {
        $e = $this->escenario();
        $this->escribir_data_de_la_etapa($e['implementacion'], 1, ['form_responses' => $this->respuestas_del_formulario()]);

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id . '?include=formulario', $this->headers());

        $respuesta->assertStatus(200);
        $cuerpo = $this->cuerpo($respuesta);

        foreach (['San Martín 123', 'panchito.sa.oficial', '@panchito.sa'] as $dato_del_negocio) {
            $this->assertStringNotContainsString($dato_del_negocio, $cuerpo, 'Salió un dato de contacto del negocio sin pedir el contacto: ' . $dato_del_negocio);
        }

        $etiquetas = array_column($respuesta->json('formulario.resumen'), 'label');
        $this->assertNotContains('Dirección', $etiquetas);
        $this->assertNotContains('Redes sociales', $etiquetas);
        $this->assertContains('Nombre de la empresa', $etiquetas);
        $this->assertContains('Manejo de precios', $etiquetas);
    }

    /**
     * Con `include=formulario,contacto` el resumen es el completo del panel: con DNI y teléfonos, y con la
     * dirección y las redes del negocio. El mail y el documento del dueño siguen sin salir: `build_summary()`
     * no los incluye.
     *
     * @return void
     */
    public function test_el_formulario_con_contacto_trae_el_resumen_completo(): void
    {
        $e = $this->escenario();
        $this->escribir_data_de_la_etapa($e['implementacion'], 1, ['form_responses' => $this->respuestas_del_formulario()]);

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id . '?include=formulario,contacto', $this->headers());

        $cuerpo = $this->cuerpo($respuesta);
        $this->assertStringContainsString('DNI 30111222', $cuerpo);
        $this->assertStringContainsString('3415554321', $cuerpo);
        $this->assertStringContainsString('San Martín 123', $cuerpo, 'Con el contacto, la dirección del negocio sí sale.');
        $this->assertStringContainsString('Instagram @panchito.sa', $cuerpo, 'Con el contacto, las redes del negocio sí salen.');
        $this->assertStringNotContainsString('20304050607', $cuerpo);
        $this->assertStringNotContainsString('dueno-secreto@ejemplo.test', $cuerpo);
    }

    /**
     * Si el cliente todavía no completó el formulario, el bloque existe pero vacío.
     *
     * @return void
     */
    public function test_el_formulario_sin_enviar_viene_vacio(): void
    {
        $e = $this->escenario();

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id . '?include=formulario', $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('formulario.enviado_at', null);
        $respuesta->assertJsonPath('formulario.resumen', []);
    }

    /* ------------------------------------------------------------------------------------------
     | El user setup
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin registro y sin lock: `sin_correr`.
     *
     * @return void
     */
    public function test_el_user_setup_sin_correr(): void
    {
        $e = $this->escenario();

        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers())
            ->assertJsonPath('user_setup.estado', 'sin_correr')
            ->assertJsonPath('user_setup.ejecutado_at', null);
    }

    /**
     * El lock `user_setup_executed_at` manda: si está, es `ok` aunque el registro de la etapa 2 diga
     * `error` (un reintento posterior salió bien) o no exista (lo aplicó el panel).
     *
     * @return void
     */
    public function test_el_lock_manda_y_dice_ok(): void
    {
        $e = $this->escenario();
        $e['implementacion']->user_setup_executed_at = now();
        $e['implementacion']->save();
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'error', 'error' => 'viejo']]);

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $respuesta->assertJsonPath('user_setup.estado', 'ok');
        $this->assertNotNull($respuesta->json('user_setup.ejecutado_at'));
    }

    /**
     * Un registro `en_curso` reciente es `en_curso`, sin marca de colgado.
     *
     * @return void
     */
    public function test_el_user_setup_en_curso(): void
    {
        $e = $this->escenario();
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->subMinutes(3)->toISOString()]]);

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $respuesta->assertJsonPath('user_setup.estado', 'en_curso');
        $this->assertArrayNotHasKey('colgado', $respuesta->json('user_setup'));
    }

    /**
     * 4. Un `en_curso` de hace más de 45 minutos se reporta con `colgado: true` y la nota que explica
     * qué hacer.
     *
     * @return void
     */
    public function test_un_user_setup_en_curso_viejo_se_reporta_colgado(): void
    {
        $e = $this->escenario();
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->subMinutes(46)->toISOString()]]);

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $respuesta->assertJsonPath('user_setup.estado', 'en_curso');
        $respuesta->assertJsonPath('user_setup.colgado', true);
        $this->assertStringContainsString('409', (string) $respuesta->json('user_setup.nota'));
    }

    /**
     * Un `error` registrado (y sin lock) sale como `error`, con el motivo y las fechas.
     *
     * @return void
     */
    public function test_el_user_setup_con_error(): void
    {
        $e = $this->escenario();
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => [
            'estado'       => 'error',
            'iniciado_at'  => '2026-10-05T14:00:00.000000Z',
            'terminado_at' => '2026-10-05T14:09:00.000000Z',
            'error'        => 'La client_api respondió con error (status 500)',
        ]]);

        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers())
            ->assertJsonPath('user_setup.estado', 'error')
            ->assertJsonPath('user_setup.iniciado_at', '2026-10-05T14:00:00.000000Z')
            ->assertJsonPath('user_setup.terminado_at', '2026-10-05T14:09:00.000000Z')
            ->assertJsonPath('user_setup.error', 'La client_api respondió con error (status 500)');
    }

    /* ------------------------------------------------------------------------------------------
     | Instalaciones y logs
     |----------------------------------------------------------------------------------------- */

    /**
     * Las instalaciones del cliente, la última primero, con su versión y sin ningún secreto.
     *
     * @return void
     */
    public function test_las_instalaciones_con_su_version(): void
    {
        $e       = $this->escenario();
        $version = $this->crear_version();
        $vieja   = $this->crear_instalacion($e['cliente'], ['status' => 'fallida', 'failure_reason' => 'se cortó el SSH', 'version_id' => $version->id]);
        $nueva   = $this->crear_instalacion($e['cliente'], ['status' => 'completada', 'version_id' => $version->id, 'provision_hosting_type' => 'shared_hosting', 'group_uuid' => '11111111-1111-1111-1111-111111111111', 'env_manual_values' => ['DB_PASSWORD' => 'secreta-de-prueba']]);

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $instalaciones = $respuesta->json('instalaciones');
        $this->assertCount(2, $instalaciones);
        $this->assertSame((int) $nueva->id, $instalaciones[0]['id']);
        $this->assertSame((int) $vieja->id, $instalaciones[1]['id']);
        $this->assertSame('completada', $instalaciones[0]['status']);
        $this->assertSame('completa', $instalaciones[0]['kind']);
        $this->assertSame('shared_hosting', $instalaciones[0]['provision_hosting_type']);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $instalaciones[0]['group_uuid']);
        $this->assertSame(['id' => (int) $version->id, 'version' => $version->version], $instalaciones[0]['version']);
        $this->assertSame('se cortó el SSH', $instalaciones[1]['failure_reason']);
        $this->assertArrayNotHasKey('logs', $instalaciones[0]);

        $this->assertStringNotContainsString('secreta-de-prueba', $this->cuerpo($respuesta), 'Salió un valor de env_manual_values.');
    }

    /**
     * Con `include=logs`, las últimas 40 líneas de cada instalación, en orden cronológico.
     *
     * @return void
     */
    public function test_los_logs_son_las_ultimas_cuarenta_en_orden(): void
    {
        $e           = $this->escenario();
        $instalacion = $this->crear_instalacion($e['cliente'], ['status' => 'instalando']);

        for ($i = 1; $i <= 45; $i++) {
            DeploymentLog::create([
                'client_installation_id' => $instalacion->id,
                'step'                   => 'upload_api',
                'line'                   => 'línea ' . $i,
                'level'                  => 'info',
                'created_at'             => now(),
            ]);
        }

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id . '?include=logs', $this->headers());

        $logs = $respuesta->json('instalaciones.0.logs');
        $this->assertCount(40, $logs);
        $this->assertSame('línea 6', $logs[0]['line']);
        $this->assertSame('línea 45', $logs[39]['line']);
        $this->assertSame('upload_api', $logs[0]['step']);
    }

    /**
     * Una línea de log enorme se recorta (la salida cruda de un comando no entra en ningún contexto).
     *
     * @return void
     */
    public function test_una_linea_de_log_enorme_se_recorta(): void
    {
        $e           = $this->escenario();
        $instalacion = $this->crear_instalacion($e['cliente'], ['status' => 'instalando']);
        DeploymentLog::create([
            'client_installation_id' => $instalacion->id,
            'step'                   => 'finalize_api',
            'line'                   => str_repeat('x', 3000),
            'level'                  => 'info',
            'created_at'             => now(),
        ]);

        $linea = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id . '?include=logs', $this->headers())->json('instalaciones.0.logs.0.line');

        $this->assertLessThan(600, mb_strlen($linea));
        $this->assertStringEndsWith('…', $linea);
    }

    /* ------------------------------------------------------------------------------------------
     | Los mails
     |----------------------------------------------------------------------------------------- */

    /**
     * 5. Sin la tabla de mails el estado no se rompe: `mails` vacío y el resto contesta igual.
     *
     * La tabla la crea la migración de otra parte de la misión. Para probar la guarda aunque la base de
     * testing ya la tenga, el controlador contesta que la tabla no existe (y, si existe, se deja una
     * fila adentro: lo que se prueba es que ni la mira).
     *
     * @return void
     */
    public function test_sin_la_tabla_de_mails_el_estado_contesta_igual(): void
    {
        $e = $this->escenario();

        if (Schema::hasTable('implementation_mails')) {
            DB::table('implementation_mails')->insert([
                'implementation_id' => $e['implementacion']->id,
                'hito'              => 'bienvenida',
                'email'             => 'lucas@gmail.com',
                'asunto'            => 'x',
                'estado'            => 'enviado',
                'reenvios'          => 0,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        /* La facade Schema no se puede espiar en esta versión de Laravel: se atan al contenedor una
           subclase del controlador a la que la tabla "no le existe". */
        $this->app->bind(ClaudeImplementationOpsController::class, function () {
            return new class extends ClaudeImplementationOpsController {
                protected function existe_la_tabla_de_mails()
                {
                    return false;
                }
            };
        });

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('mails', []);
        $respuesta->assertJsonPath('implementation.id', (int) $e['implementacion']->id);
    }

    /**
     * Con la tabla, los mails de la implementación salen con la casilla enmascarada y sin la entera.
     *
     * Se saltea si la base de testing todavía no tiene la migración de `implementation_mails` (la crea
     * otra parte de la misión): el caso sin tabla lo cubre el test anterior.
     *
     * @return void
     */
    public function test_los_mails_salen_con_la_casilla_enmascarada(): void
    {
        if (! Schema::hasTable('implementation_mails')) {
            $this->markTestSkipped('La base de testing no tiene la tabla implementation_mails.');
        }

        $e = $this->escenario();

        DB::table('implementation_mails')->insert([
            'implementation_id' => $e['implementacion']->id,
            'hito'              => 'bienvenida',
            'email'             => 'lucas@gmail.com',
            'asunto'            => 'Arrancamos con la implementación de tu sistema',
            'estado'            => 'enviado',
            'error'             => null,
            'enviado_at'        => now(),
            'reenvios'          => 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $respuesta->assertJsonPath('mails.0.hito', 'bienvenida');
        $respuesta->assertJsonPath('mails.0.estado', 'enviado');
        $respuesta->assertJsonPath('mails.0.reenvios', 1);
        $respuesta->assertJsonPath('mails.0.para_enmascarado', 'l***@gmail.com');
        $this->assertStringNotContainsString('lucas@gmail.com', $this->cuerpo($respuesta));
    }

    /* ------------------------------------------------------------------------------------------
     | Entrantes sin responder
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin mensajes: cero y sin fecha.
     *
     * @return void
     */
    public function test_entrantes_sin_mensajes(): void
    {
        $e = $this->escenario();

        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers())
            ->assertJsonPath('entrantes.sin_responder', 0)
            ->assertJsonPath('entrantes.ultimo_at', null);
    }

    /**
     * Sin ningún saliente, todos los entrantes están sin responder.
     *
     * @return void
     */
    public function test_entrantes_sin_ningun_saliente(): void
    {
        $e = $this->escenario();
        $this->crear_mensaje($e['implementacion'], 'inbound', now()->subHours(3), 'wamid.1');
        $this->crear_mensaje($e['implementacion'], 'inbound', now()->subHours(2), 'wamid.2');

        $respuesta = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $respuesta->assertJsonPath('entrantes.sin_responder', 2);
        $this->assertNotNull($respuesta->json('entrantes.ultimo_at'));
    }

    /**
     * Solo cuentan los entrantes POSTERIORES al último saliente.
     *
     * @return void
     */
    public function test_entrantes_posteriores_al_ultimo_saliente(): void
    {
        $e = $this->escenario();
        $this->crear_mensaje($e['implementacion'], 'inbound', now()->subHours(5), 'wamid.1');
        $this->crear_mensaje($e['implementacion'], 'outbound', now()->subHours(4), 'waweb-uno');
        $this->crear_mensaje($e['implementacion'], 'inbound', now()->subHours(3), 'wamid.2');
        $this->crear_mensaje($e['implementacion'], 'inbound', now()->subHours(2), 'wamid.3');

        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers())
            ->assertJsonPath('entrantes.sin_responder', 2);
    }

    /**
     * 3. 🔴 Un saliente SIN `whatsapp_message_id` es un envío fallido: no cuenta como respuesta. Si
     * contara, un mensaje que nunca salió taparía lo que el cliente escribió.
     *
     * @return void
     */
    public function test_un_saliente_fallido_no_cuenta_como_respuesta(): void
    {
        $e = $this->escenario();
        $this->crear_mensaje($e['implementacion'], 'inbound', now()->subHours(5), 'wamid.1');
        $this->crear_mensaje($e['implementacion'], 'outbound', now()->subHours(4), null);

        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers())
            ->assertJsonPath('entrantes.sin_responder', 1);
    }

    /* ------------------------------------------------------------------------------------------
     | Por cliente y por lead
     |----------------------------------------------------------------------------------------- */

    /**
     * `?client_id=` devuelve lo mismo que por id.
     *
     * @return void
     */
    public function test_por_client_id(): void
    {
        $e = $this->escenario();

        $por_cliente = $this->getJson('/api/claude/implementations?client_id=' . $e['cliente']->id, $this->headers());
        $por_id      = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $por_cliente->assertStatus(200);
        $this->assertSame($por_id->json(), $por_cliente->json());
    }

    /**
     * `?lead_id=` resuelve por el cliente promovido.
     *
     * @return void
     */
    public function test_por_lead_id(): void
    {
        $e    = $this->escenario();
        $lead = $this->crear_lead(['promoted_client_id' => $e['cliente']->id]);

        $this->getJson('/api/claude/implementations?lead_id=' . $lead->id, $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('implementation.id', (int) $e['implementacion']->id);
    }

    /**
     * Un lead sin promover no tiene implementación: 404 que dice qué hacer.
     *
     * @return void
     */
    public function test_un_lead_sin_promover_es_404(): void
    {
        $lead = $this->crear_lead();

        $respuesta = $this->getJson('/api/claude/implementations?lead_id=' . $lead->id, $this->headers());

        $respuesta->assertStatus(404);
        $this->assertStringContainsString('POST claude/implementations', $this->cuerpo($respuesta));
    }

    /**
     * Un cliente sin implementación: 404.
     *
     * @return void
     */
    public function test_un_cliente_sin_implementacion_es_404(): void
    {
        $cliente = $this->crear_cliente('Sin Implementación');

        $this->getJson('/api/claude/implementations?client_id=' . $cliente->id, $this->headers())->assertStatus(404);
    }

    /**
     * Ni cliente ni lead que existan: 404.
     *
     * @return void
     */
    public function test_cliente_o_lead_inexistentes_son_404(): void
    {
        $this->getJson('/api/claude/implementations?client_id=99999999', $this->headers())->assertStatus(404);
        $this->getJson('/api/claude/implementations?lead_id=99999999', $this->headers())->assertStatus(404);
    }

    /**
     * Exactamente uno de los dos: ninguno o los dos es 422.
     *
     * @return void
     */
    public function test_exactamente_uno_de_client_id_o_lead_id(): void
    {
        $this->getJson('/api/claude/implementations', $this->headers())->assertStatus(422);
        $this->getJson('/api/claude/implementations?client_id=1&lead_id=2', $this->headers())->assertStatus(422);
        $this->getJson('/api/claude/implementations?client_id=abc', $this->headers())->assertStatus(422);
    }
}
