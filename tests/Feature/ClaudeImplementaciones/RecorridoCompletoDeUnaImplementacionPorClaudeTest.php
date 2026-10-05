<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Jobs\EjecutarInstalacionDeImplementacionJob;
use App\Jobs\EjecutarUserSetupDeImplementacionJob;
use App\Mail\ImplementacionMail;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Models\EnvTemplate;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * El recorrido ENTERO de una implementación como lo hace la skill `/implementar`, de punta a punta por
 * HTTP: "tal lead compró" → el alta → el mail y los WhatsApp de la bienvenida → el cliente completa el
 * formulario (por su ruta pública real) → avanzar → instalar → configurar → pedir los archivos →
 * recorrer las etapas → cerrar.
 *
 * Cada endpoint tiene sus propios tests; éste verifica que las PIEZAS ENCAJAN: lo que escribe uno es lo
 * que lee el siguiente (el link del formulario, la casilla del lead, el teléfono del responsable de
 * migración que sale del formulario, el payload del user setup que sale de lo que el cliente cargó, el
 * candado que llena el job, el cierre de la etapa 8) y que lo que Claude deja escrito sigue siendo
 * legible por el panel.
 *
 * Lo que se simula, y por qué: el pipeline de instalación se da por terminado marcando las filas
 * `completada` (no hay SSH ni Hostinger en un test) y el POST a empresa-api se falsea con `Http::fake()`;
 * la cola es `Queue::fake()` (se afirma que cada job salió en `database`) y los mails `Mail::fake()`.
 *
 * El reloj va congelado en instantes explícitos: "el cliente escribió DESPUÉS de lo último que se le
 * mandó" es una afirmación sobre el orden de los `sent_at`, y con el reloj real dos envíos del mismo
 * segundo se pisarían entre sí.
 */
class RecorridoCompletoDeUnaImplementacionPorClaudeTest extends BaseDeImplementaciones
{
    /**
     * Las llamadas HTTP salientes que hizo el recorrido (`urls` y el `cuerpo` de la última de cada
     * destino). Las llena el único `Http::fake()` del test, armado en `setUp()`.
     *
     * @var \stdClass
     */
    private $llamadas;

    /**
     * El entorno que tiene el admin cuando todo está bien configurado: token de Hostinger, credencial SSH
     * del compartido, la plantilla de variables del .env, una versión publicada, la URL del formulario y
     * sin clave de Anthropic (el subdominio lo manda el pedido, así que la sugerencia no se usa).
     *
     * 🔴 Y con el modo de automatización GLOBAL en `auto`, que es el caso difícil: es el valor con el que
     * un panel real puede estar, y justamente lo que el alta por `claude/*` tiene que ignorar (la
     * implementación nace `manual` y sin bienvenida automática, porque cada mensaje al cliente lo manda la
     * skill por WhatsApp Web con el ok de Lucas). Con el global en `manual` ese freno no se vería.
     *
     * Un solo `Http::fake()`, acá: con dos, el primero que matchea gana y el segundo no se enteraría de
     * nada. Además corta la red de TODO el recorrido (un `auto` mal ignorado intentaría hablar con
     * WhatsApp).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();

        $this->llamadas         = new \stdClass();
        $this->llamadas->urls   = [];
        $this->llamadas->cuerpo = [];

        Http::fake(function ($request) {
            $this->llamadas->urls[]                           = $request->url();
            $this->llamadas->cuerpo[(string) $request->url()] = $request->data();

            return Http::response(['ok' => true], 200);
        });

        config([
            'services.anthropic.api_key'                           => '',
            'services.hostinger.api_token'                         => 'token-de-prueba',
            'services.claude_task_ingest.default_creator_admin_id' => null,
        ]);

        /* El alta necesita un admin que figure como creador de las tareas de la promoción. */
        $this->crear_admin();

        AdminSetting::set('implementation_form_url', 'https://admin.ejemplo.test/configuracion');
        AdminSetting::set('implementation_automation_mode', 'auto');
        DB::table('implementation_mails')->delete();

        EnvTemplate::query()->delete();
        $manuales = [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST'       => '127.0.0.1',
            'DB_PORT'       => '3306',
            'DB_DATABASE'   => null,
            'DB_USERNAME'   => null,
            'DB_PASSWORD'   => null,
        ];
        foreach ($manuales as $clave => $valor) {
            EnvTemplate::create(['key' => $clave, 'value' => $valor, 'group' => 'db', 'scope' => 'empresa', 'is_manual_on_create' => true]);
        }

        ClientSshCredential::query()->delete();
        $credencial           = new ClientSshCredential();
        $credencial->type     = 'shared_hosting';
        $credencial->host     = 'compartido.ejemplo.test';
        $credencial->port     = 65002;
        $credencial->username = 'deploy';
        $credencial->password = 'secreta';
        $credencial->save();

        $this->crear_version();
    }

    /**
     * Suelta el reloj congelado: el resto de la suite vive con el real.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudas
     |----------------------------------------------------------------------------------------- */

    /**
     * Congela el reloj en un instante de ese día.
     *
     * @param string $hora Hora (`10:05:00`).
     *
     * @return void
     */
    private function a_las(string $hora): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 ' . $hora));
    }

    /**
     * El POST a una ruta de la implementación (o al alta, con `$id` vacío).
     *
     * @param int|string           $id     Id de la implementación (o '' para el alta).
     * @param string               $ruta   Lo que va después de /implementations/{id}.
     * @param array<string, mixed> $cuerpo Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function post_de($id, string $ruta, array $cuerpo)
    {
        $url = '/api/claude/implementations' . ($id === '' ? '' : '/' . $id) . $ruta;

        return $this->postJson($url, $cuerpo, $this->headers());
    }

    /**
     * El estado de la implementación por su id.
     *
     * @param int    $id      Id de la implementación.
     * @param string $include Includes (csv).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function estado(int $id, string $include = '')
    {
        return $this->getJson('/api/claude/implementations/' . $id . ($include !== '' ? '?include=' . $include : ''), $this->headers());
    }

    /**
     * Avanza una etapa (real) con la etapa actual correcta, como lo hace la skill.
     *
     * @param int                  $id    Id de la implementación.
     * @param int                  $etapa La etapa que se cierra.
     * @param array<string, mixed> $extra Lo que se suma al body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function avanzar(int $id, int $etapa, array $extra = [])
    {
        return $this->post_de($id, '/advance', array_merge(['etapa_actual' => $etapa, 'dry_run' => false], $extra))->assertStatus(200);
    }

    /**
     * Registra por WhatsApp Web lo que la skill acaba de mandar.
     *
     * @param int    $id     Id de la implementación.
     * @param string $accion La acción.
     * @param string $texto  Lo que se mandó.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function registrar(int $id, string $accion, string $texto)
    {
        return $this->post_de($id, '/actions', ['accion' => $accion, 'canal' => 'whatsapp_web', 'texto' => $texto])->assertStatus(201);
    }

    /* ------------------------------------------------------------------------------------------
     | El recorrido
     |----------------------------------------------------------------------------------------- */

    /**
     * Del "tal lead compró" al cierre de la etapa 8, con todo lo que la skill hace en el medio.
     *
     * @return void
     */
    public function test_el_recorrido_completo_de_la_skill(): void
    {
        $this->a_las('10:00:00');

        /* ============================================================ 1. "Tal lead compró": el alta */
        $lead = $this->crear_lead([
            'contact_name' => 'Rosa Fernández',
            'company_name' => 'Almacén Rosa',
            'phone'        => '3415551234',
            'email'        => 'rosa@ejemplo.test',
        ]);

        $this->post_de('', '', ['lead_id' => $lead->id, 'subdominio' => 'rosa'])
            ->assertStatus(200)
            ->assertJsonPath('listo', true)
            ->assertJsonPath('accion', 'promover_y_empezar');

        $alta = $this->post_de('', '', ['lead_id' => $lead->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa']);
        $alta->assertStatus(201)->assertJsonPath('promovido', true)->assertJsonPath('implementation.current_stage', 1);

        $id      = (int) $alta->json('implementation.id');
        $cliente = Client::find($alta->json('cliente.id'));
        $link    = (string) $alta->json('implementation.form_link');
        $this->assertNotSame('', $link, 'La skill necesita el link del formulario para mandárselo al cliente.');

        /* 🔴 Con el modo global en `auto`, la implementación nace igual `manual` y sin ningún mensaje al
           cliente: ni la plantilla de bienvenida ni una llamada a WhatsApp. */
        $this->assertSame('manual', Implementation::find($id)->automation_mode);
        $this->assertSame(0, ImplementationMessage::where('implementation_id', $id)->count());
        $this->assertSame([], $this->llamadas->urls, 'El alta no sale a la red.');

        /* Se encuentra por el lead, que es por donde arranca la skill. */
        $this->getJson('/api/claude/implementations?lead_id=' . $lead->id, $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('implementation.id', $id);

        /* ================================ 2. La bienvenida: el mail y los dos WhatsApp, y se registran */
        $this->post_de($id, '/mail', ['hito' => 'bienvenida', 'dry_run' => false, 'confirm_client_name' => 'Rosa Fernández'])
            ->assertStatus(200)
            ->assertJsonPath('enviado', true);

        Mail::assertSent(ImplementacionMail::class, function ($mailable) {
            return $mailable->hasTo('rosa@ejemplo.test');
        });
        $this->assertSame('rosa@ejemplo.test', $cliente->refresh()->email, 'La casilla del lead (la que dejó en la demo) se guarda en la ficha cuando el mail sale.');

        $this->registrar($id, 'presentacion', 'Hola Rosa, soy Lucas de ComercioCity.')->assertJsonPath('mensaje.telefono', '+5493415551234');
        $this->registrar($id, 'form_link', 'Este es el formulario: ' . $link);

        /* El cliente contesta a las 10:05 y la skill lo ve en `entrantes`. */
        $this->crear_mensaje(Implementation::find($id), 'inbound', Carbon::parse('2026-10-05 10:05:00'), 'wamid.rosa.1', 'Hola, ya lo veo');
        $this->estado($id)->assertJsonPath('entrantes.sin_responder', 1);

        /* La skill le contesta a las 10:10: el saliente que registró lleva id (`waweb-...`), así que cuenta
           como respuesta. Con id nulo se leería como un envío fallido y seguiría "sin responder". */
        $this->a_las('10:10:00');
        $this->registrar($id, 'progreso', 'Perfecto, cualquier duda me escribís.');
        $this->estado($id)->assertJsonPath('entrantes.sin_responder', 0);

        /* ================================ 3. El cliente completa el formulario por su ruta PÚBLICA real */
        $token = Implementation::find($id)->form_token;

        $this->postJson('/api/form/implementation/' . $token . '/submit', ['fields' => [
            'price_mode'               => 'single',
            'stock_mode'               => 'single',
            'apply_iva'                => 'yes',
            'ask_quantity'             => 'ask',
            'default_cuenta_corriente' => 'default_off',
            'company_name'             => 'Almacén Rosa S.A.',
            'doc_number'               => '20304050607',
            'email'                    => 'rosa@ejemplo.test',
            'employees'                => [['name' => 'Ana Pérez', 'dni' => '30111222', 'phone' => '3415554321']],
            'migration_responsible'    => 'Ana Pérez',
        ]])->assertStatus(200);

        $formulario = $this->estado($id, 'formulario');
        $this->assertNotNull($formulario->json('implementation.form_submitted_at'));
        $this->assertStringContainsString('Almacén Rosa S.A.', $this->cuerpo($formulario));
        $this->assertStringNotContainsString('30111222', $this->cuerpo($formulario), 'El resumen no trae el DNI de los empleados si no se pide el contacto.');
        $this->assertSame('Almacén Rosa S.A.', $cliente->refresh()->setup_data['company_name'], 'El formulario se mapea a clients.setup_data.');
        $this->assertSame('+5493415554321', Implementation::find($id)->migration_contact_phone, 'El responsable de migración sale de la tabla de empleados del formulario.');

        /* ============================================ 4. Avanzar a la etapa 2 (sin crear la instalación) */
        $this->post_de($id, '/advance', ['etapa_actual' => 1])->assertStatus(200)->assertJsonPath('dry_run', true);
        $this->avanzar($id, 1, ['nota' => 'Formulario completo'])->assertJsonPath('implementation.current_stage', 2);
        $this->assertSame(0, ClientInstallation::where('client_id', $cliente->id)->count(), 'Avanzar no crea la instalación: la crea install.');

        /* ============================================================================= 5. Instalar */
        $this->post_de($id, '/install', [])->assertStatus(200)->assertJsonPath('listo', true);

        $this->post_de($id, '/install', ['dry_run' => false, 'confirm_client_name' => 'Rosa Fernández'])
            ->assertStatus(202)
            ->assertJsonPath('conexion_de_cola', 'database');

        Queue::assertPushed(EjecutarInstalacionDeImplementacionJob::class, 1);
        Queue::assertPushed(EjecutarInstalacionDeImplementacionJob::class, function ($job) {
            return $job->connection === 'database';
        });

        $instalaciones = ClientInstallation::where('client_id', $cliente->id)->orderBy('id')->get();
        $this->assertSame(['completa', 'esqueleto'], $instalaciones->pluck('kind')->all());
        $this->assertSame(['instalando', 'instalando'], array_column($this->estado($id)->json('instalaciones'), 'status'));

        /* Mientras instala, configurar es 422: la instalación real todavía no terminó. */
        $this->post_de($id, '/user-setup', ['dry_run' => false, 'confirm_client_name' => 'Rosa Fernández'])->assertStatus(422);
        Queue::assertNotPushed(EjecutarUserSetupDeImplementacionJob::class);

        /* El pipeline termina (acá, a mano: no hay SSH ni Hostinger en un test). */
        ClientInstallation::where('client_id', $cliente->id)->update(['status' => 'completada', 'finished_at' => now()]);
        $this->assertSame(['completada', 'completada'], array_column($this->estado($id)->json('instalaciones'), 'status'));

        /* ========================================================================= 6. Configurar el sistema */
        $setup = $this->post_de($id, '/user-setup', []);
        $setup->assertStatus(200)->assertJsonPath('listo', true);
        $this->assertSame('Almacén Rosa S.A.', $setup->json('payload.company_name'), 'El payload sale de lo que el cliente cargó en el formulario.');
        $this->assertSame('***0607', $setup->json('payload.doc_number'), 'El documento del dueño sale enmascarado salvo con include=contacto.');
        $this->assertSame('20304050607', $this->post_de($id, '/user-setup', ['include' => 'contacto'])->json('payload.doc_number'));

        $this->post_de($id, '/user-setup', ['dry_run' => false, 'confirm_client_name' => 'Rosa Fernández'])
            ->assertStatus(202)
            ->assertJsonPath('user_setup.estado', 'en_curso');

        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, 1);
        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, function ($job) {
            return $job->connection === 'database';
        });
        $this->estado($id)->assertJsonPath('user_setup.estado', 'en_curso');

        /* Hasta acá, ni una sola llamada a la red: encolar no habla con el sistema del cliente. */
        $this->assertSame([], $this->llamadas->urls);

        /* Y el worker lo corre: el POST a empresa-api sale con lo que cargó el cliente. */
        $token = (string) $this->estado($id)->json('user_setup.iniciado_at');
        $this->assertNotSame('', $token, 'El registro en_curso tiene que traer el token con el que se despachó el job.');
        (new EjecutarUserSetupDeImplementacionJob($id, $token))->handle();

        $destino = 'https://api-rosa.comerciocity.com/api/admin-sync/user-setup';
        $this->assertSame([$destino], $this->llamadas->urls);
        $this->assertSame('Almacén Rosa S.A.', $this->llamadas->cuerpo[$destino]['company_name']);
        $this->assertSame((int) $cliente->user_id, (int) $this->llamadas->cuerpo[$destino]['user_id']);

        $this->estado($id)->assertJsonPath('user_setup.estado', 'ok');
        $this->assertNotNull(Implementation::find($id)->user_setup_executed_at);

        /* El candado ya está puesto: por acá no se re-aplica NUNCA (le haría migrate:fresh al cliente). Y el
           rechazo es el del candado, con su motivo, no uno cualquiera. */
        $reaplicar = $this->post_de($id, '/user-setup', ['dry_run' => false, 'confirm_client_name' => 'Rosa Fernández']);
        $reaplicar->assertStatus(422);
        $this->assertStringStartsWith('El user setup ya se aplicó', (string) $reaplicar->json('error'));
        $this->assertStringContainsString('VACÍA la base', (string) $reaplicar->json('error'));
        $reaplicar->assertJsonStructure(['user_setup_executed_at']);
        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, 1);
        $this->assertSame([$destino], $this->llamadas->urls, 'El intento de re-aplicar no volvió a llamar al cliente.');

        $this->post_de($id, '/mail', ['hito' => 'instalado', 'dry_run' => false, 'confirm_client_name' => 'Rosa Fernández'])
            ->assertStatus(200)
            ->assertJsonPath('enviado', true);

        /* =============================================================== 7. Los archivos y las etapas */
        $this->avanzar($id, 2)->assertJsonPath('implementation.current_stage', 3)->assertJsonPath('avisos', []);

        /* El pedido de archivos va al responsable de migración que cargó el cliente, no al dueño. */
        $this->registrar($id, 'pedir_archivos', 'Mandame los Excel de artículos, clientes y proveedores.')
            ->assertJsonPath('mensaje.telefono', '+5493415554321');

        $this->avanzar($id, 3);
        $this->avanzar($id, 4);
        $this->registrar($id, 'entrega', 'Listo, ya podés entrar a tu sistema.')->assertJsonPath('etapa', 5);
        $this->avanzar($id, 5);
        $this->avanzar($id, 6);

        $this->avanzar($id, 7, ['saltar' => true, 'nota' => 'El cliente factura con otro sistema: ARCA no aplica']);

        /* ============================================================================ 8. Cerrar */
        $cierre = $this->avanzar($id, 8);
        $cierre->assertJsonPath('cierra_la_implementacion', true)
            ->assertJsonPath('implementation.status', 'completed')
            ->assertJsonPath('implementation.current_stage', 8);

        /* ================================================== 9. Todo lo que quedó, leído de una sola vez */
        $final = $this->estado($id);
        $final->assertJsonPath('implementation.status', 'completed');
        $final->assertJsonPath('user_setup.estado', 'ok');
        $final->assertJsonPath('entrantes.sin_responder', 0);
        $final->assertJsonPath('mails.0.hito', 'bienvenida');
        $final->assertJsonPath('mails.1.hito', 'instalado');
        $this->assertCount(2, $final->json('instalaciones'));

        $estados = array_column($final->json('etapas'), 'estado');
        $this->assertSame(['completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'skipped', 'completed'], $estados);

        $this->assertSame(['presentacion', 'form_link', 'progreso'], array_column($final->json('etapas.0.acciones'), 'accion'));
        $this->assertSame(['claude', 'claude', 'claude'], array_column($final->json('etapas.0.acciones'), 'origen'));
        $this->assertSame(['user_setup'], array_column($final->json('etapas.1.acciones'), 'accion'), 'El job deja la acción user_setup en la etapa 2.');
        $this->assertSame(['pedir_archivos'], array_column($final->json('etapas.2.acciones'), 'accion'));
        $this->assertSame(['entrega'], array_column($final->json('etapas.4.acciones'), 'accion'));

        Mail::assertSent(ImplementacionMail::class, 2);

        /* Y el panel lee y muestra lo que Claude escribió, sin enterarse de que no lo escribió el panel. */
        $panel = $this->actingAs($this->crear_admin(), 'sanctum');

        $detalle = $panel->getJson('/api/admin/implementation/' . $id);
        $detalle->assertStatus(200)->assertJsonPath('model.status', 'completed');

        $salientes = [];
        foreach ($detalle->json('model.messages') as $mensaje) {
            if ($mensaje['direction'] === 'outbound') {
                $salientes[] = $mensaje['body'];
            }
        }
        $this->assertSame(
            [
                'Hola Rosa, soy Lucas de ComercioCity.',
                'Este es el formulario: ' . $link,
                'Perfecto, cualquier duda me escribís.',
                'Mandame los Excel de artículos, clientes y proveedores.',
                'Listo, ya podés entrar a tu sistema.',
            ],
            $salientes,
            'El hilo del panel muestra lo que se le mandó al cliente.'
        );

        $acciones = $panel->getJson('/api/admin/implementation/' . $id . '/actions');
        $acciones->assertStatus(200);

        $ejecutadas = [];
        foreach ($acciones->json('actions') as $accion) {
            if ($accion['last_executed_at'] !== null) {
                $ejecutadas[] = $accion['key'];
            }
        }
        sort($ejecutadas);
        $this->assertSame(['entrega', 'form_link', 'pedir_archivos', 'presentacion', 'progreso', 'user_setup'], $ejecutadas, 'El checklist del panel tiene que ver lo que Claude registró.');

        /* La implementación cerrada no avanza más. */
        $this->post_de($id, '/advance', ['etapa_actual' => 8, 'dry_run' => false])->assertStatus(409);

        /* Y los WhatsApp que se le mandaron quedan todos con id: ninguno se lee como envío fallido. */
        $this->assertSame(0, ImplementationMessage::where('implementation_id', $id)->where('direction', 'outbound')->whereNull('whatsapp_message_id')->count());
    }
}
