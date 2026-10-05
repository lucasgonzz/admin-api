<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Http\Controllers\Api\ClaudeImplementationOpsController;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use App\Models\Lead;
use App\Models\TaskTemplate;
use App\Services\ImplementationStartService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * El alta de una implementación por `claude/*`: `POST claude/implementations` (lead → cliente →
 * implementación).
 *
 * Es la puerta que convierte "tal lead compró" en un cliente con sus dos ClientApi, sus tareas y su
 * implementación en marcha. Lo que se protege, en orden de importancia:
 *
 *  1. 🔴 `dry_run` por defecto: la primera llamada NO escribe NADA (ni cliente, ni APIs, ni tareas, ni
 *     implementación, ni toca el lead).
 *  2. 🔴 Que el alta real sea todo o nada: si algo falla a la mitad no queda un cliente sin tareas ni
 *     ClientApis (la promoción del panel no es transaccional).
 *  3. Los frenos de nombre y de subdominio: confirmación por nombre sin revelar el correcto, y un
 *     subdominio válido y libre —incluido el `<sub>2` que ocupa el cliente—.
 *  4. 🔴 La implementación nace en `manual` y sin bienvenida aunque el setting global diga `auto`.
 *  5. Que un cliente con implementación reciba 409 con su id, y que un lead ya promovido no se
 *     promueva dos veces.
 */
class AltaDeImplementacionPorClaudeTest extends BaseDeImplementaciones
{
    /**
     * Sin clave de Anthropic para que la sugerencia de subdominio use el fallback (`Str::slug`) y no
     * salga a la red; y con una plantilla de tarea activa del proceso `lead_a_cliente` para poder
     * contar las tareas que crea la promoción.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => '']);
        config(['services.claude_task_ingest.default_creator_admin_id' => null]);

        TaskTemplate::where('proceso', 'lead_a_cliente')->delete();
        foreach (['Reunión de kickoff', 'Instalar sistemas', 'Enviar pasos de implementación'] as $orden => $titulo) {
            TaskTemplate::create([
                'proceso'     => 'lead_a_cliente',
                'titulo'      => $titulo,
                'descripcion' => 'Tarea de prueba',
                'checklist'   => ['uno', 'dos'],
                'prioridad'   => 1,
                'orden'       => $orden + 1,
                'activa'      => true,
            ]);
        }
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudas
     |----------------------------------------------------------------------------------------- */

    /**
     * El POST del alta con la clave de ingesta.
     *
     * @param array<string, mixed> $cuerpo Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function alta(array $cuerpo)
    {
        return $this->postJson('/api/claude/implementations', $cuerpo, $this->headers());
    }

    /**
     * Lo que existe de lo que el alta escribe, para comparar antes y después.
     *
     * @return array<string, int>
     */
    private function foto(): array
    {
        return [
            'clientes'        => Client::count(),
            'client_apis'     => ClientApi::count(),
            'implementations' => Implementation::count(),
            'etapas'          => ImplementationStage::count(),
            'tareas'          => DB::table('admin_tasks')->count(),
        ];
    }

    /**
     * Un lead listo para promover, con razón social y teléfono.
     *
     * @return Lead
     */
    private function lead_para_promover(): Lead
    {
        return $this->crear_lead(['contact_name' => 'Rosa Fernández', 'company_name' => 'Almacén Rosa']);
    }

    /* ------------------------------------------------------------------------------------------
     | Los frenos del pedido
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin la clave, 401.
     *
     * @return void
     */
    public function test_sin_clave_devuelve_401(): void
    {
        $this->postJson('/api/claude/implementations', ['lead_id' => 1])->assertStatus(401);
    }

    /**
     * Un parámetro desconocido es 422 y no se escribe nada: un `automation_mode` o un `force` suelen ser
     * alguien esperando que haga algo que no hace.
     *
     * @return void
     */
    public function test_un_parametro_desconocido_es_422_y_no_escribe_nada(): void
    {
        $lead  = $this->lead_para_promover();
        $antes = $this->foto();

        $respuesta = $this->alta(['lead_id' => $lead->id, 'automation_mode' => 'auto', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa', 'subdominio' => 'rosa']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('automation_mode', $this->cuerpo($respuesta));
        $respuesta->assertJsonPath('parametros_aceptados', ['lead_id', 'client_id', 'subdominio', 'dry_run', 'confirm_nombre']);
        $this->assertSame($antes, $this->foto());
    }

    /**
     * Ni lead ni cliente: 422 en español (la regla `required_without` tiene su mensaje).
     *
     * @return void
     */
    public function test_sin_lead_ni_cliente_es_422_en_espanol(): void
    {
        $respuesta = $this->alta(['subdominio' => 'rosa']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('es obligatorio si no mandás', $this->cuerpo($respuesta));
        $this->assertStringNotContainsString('field is required', $this->cuerpo($respuesta));
    }

    /**
     * Los dos juntos son ambiguos: 422 y no se escribe nada.
     *
     * @return void
     */
    public function test_lead_y_cliente_juntos_son_422(): void
    {
        $lead    = $this->lead_para_promover();
        $cliente = $this->crear_cliente();

        $this->alta(['lead_id' => $lead->id, 'client_id' => $cliente->id])->assertStatus(422);
    }

    /**
     * Un subdominio con mayúsculas, espacios o más de 20 caracteres no pasa el formato: 422 en español.
     *
     * @return void
     */
    public function test_un_subdominio_con_mal_formato_es_422(): void
    {
        $lead = $this->lead_para_promover();

        foreach (['Rosa', 'la rosa', 'rosa_', str_repeat('a', 21), '-rosa'] as $malo) {
            $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => $malo]);

            $respuesta->assertStatus(422);
            $this->assertStringContainsString('no tiene un formato válido', $this->cuerpo($respuesta), 'Subdominio: ' . $malo);
        }
    }

    /**
     * `dry_run` tiene que ser booleano.
     *
     * @return void
     */
    public function test_dry_run_tiene_que_ser_booleano(): void
    {
        $lead = $this->lead_para_promover();

        $this->alta(['lead_id' => $lead->id, 'dry_run' => 'quizás'])->assertStatus(422);
    }

    /**
     * Un lead o un cliente que no existen son 404.
     *
     * @return void
     */
    public function test_lead_o_cliente_inexistentes_son_404(): void
    {
        $this->alta(['lead_id' => 99999999])->assertStatus(404);
        $this->alta(['client_id' => 99999999])->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | Dry-run: no escribe nada y dice lo que haría
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. 🔴 Sin `dry_run` explícito es una simulación: no se crea NADA y el lead queda como estaba.
     *
     * @return void
     */
    public function test_por_defecto_es_dry_run_y_no_escribe_nada(): void
    {
        $lead  = $this->lead_para_promover();
        $antes = $this->foto();

        $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'rosa']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', true);
        $respuesta->assertJsonPath('accion', 'promover_y_empezar');
        $respuesta->assertJsonPath('listo', true);
        $respuesta->assertJsonPath('bloqueos', []);

        $this->assertSame($antes, $this->foto(), 'El dry-run escribió filas.');

        $lead->refresh();
        $this->assertNull($lead->promoted_client_id);
        $this->assertSame('closer_activo', $lead->status);
    }

    /**
     * El dry-run con subdominio válido: lo dice, devuelve las cuatro URLs y no trae el teléfono ni el
     * mail del lead.
     *
     * @return void
     */
    public function test_el_dry_run_devuelve_las_cuatro_urls_y_no_el_contacto_del_lead(): void
    {
        $lead = $this->lead_para_promover();

        $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'rosa']);

        $respuesta->assertJsonPath('subdominio.pedido', 'rosa');
        $respuesta->assertJsonPath('subdominio.valido', true);
        $respuesta->assertJsonPath('subdominio.motivo', null);
        $respuesta->assertJsonPath('urls', [
            ['rol' => 'spa', 'url' => 'https://rosa.comerciocity.com'],
            ['rol' => 'api', 'url' => 'https://api-rosa.comerciocity.com'],
            ['rol' => 'spa_2', 'url' => 'https://rosa2.comerciocity.com'],
            ['rol' => 'api_2', 'url' => 'https://api-rosa2.comerciocity.com'],
        ]);
        $respuesta->assertJsonPath('lead.id', (int) $lead->id);
        $respuesta->assertJsonPath('lead.company_name', 'Almacén Rosa');
        $respuesta->assertJsonPath('cliente.existente', false);

        $cuerpo = $this->cuerpo($respuesta);
        $this->assertStringNotContainsString($lead->phone, $cuerpo, 'El dry-run mostró el teléfono del lead.');
        $this->assertStringNotContainsString($lead->email, $cuerpo, 'El dry-run mostró el mail del lead.');
    }

    /**
     * Sin subdominio, el dry-run sugiere uno (con el fallback, sin salir a la red) y dice que falta
     * confirmarlo: el alta real no sale sin él.
     *
     * @return void
     */
    public function test_el_dry_run_sugiere_un_subdominio_y_pide_confirmarlo(): void
    {
        $lead = $this->lead_para_promover();

        $respuesta = $this->alta(['lead_id' => $lead->id]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('subdominio.pedido', null);
        $respuesta->assertJsonPath('subdominio.sugerido', 'almacen-rosa');
        $respuesta->assertJsonPath('subdominio.valido', true);
        $respuesta->assertJsonPath('listo', false);
        $this->assertStringContainsString('Falta confirmar el subdominio', $this->cuerpo($respuesta));
        $respuesta->assertJsonPath('urls.1.url', 'https://api-almacen-rosa.comerciocity.com');
    }

    /**
     * 3. Un subdominio ocupado por un cliente existente: lo dice, con el cliente, y el dry-run queda en
     * `listo: false`.
     *
     * @return void
     */
    public function test_un_subdominio_ocupado_se_detecta_en_el_dry_run(): void
    {
        $existente = $this->crear_las_dos_apis($this->crear_cliente('Ferretería Don Juan'), 'ferre');
        $lead      = $this->lead_para_promover();

        $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'ferre']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('subdominio.valido', false);
        $respuesta->assertJsonPath('listo', false);
        $this->assertStringContainsString('ya lo usa el cliente ' . $existente->id, (string) $respuesta->json('subdominio.motivo'));
        $respuesta->assertJsonPath('urls', []);
    }

    /**
     * 3. 🔴 El `<sub>2` de otro cliente también cuenta: un subdominio nuevo `ferre2` chocaría con la
     * segunda API de `ferre` aunque `ferre2` parezca libre.
     *
     * @return void
     */
    public function test_el_segundo_frente_de_otro_cliente_tambien_ocupa_el_subdominio(): void
    {
        $existente = $this->crear_las_dos_apis($this->crear_cliente('Ferretería Don Juan'), 'ferre');
        $lead      = $this->lead_para_promover();

        $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'ferre2']);

        $respuesta->assertJsonPath('subdominio.valido', false);
        $this->assertStringContainsString('ya lo usa el cliente ' . $existente->id, (string) $respuesta->json('subdominio.motivo'));
    }

    /**
     * 3. 🔴 Y al revés: un cliente nuevo ocupa `<sub>` Y `<sub>2`. Si ya hay una ClientApi suelta en
     * `ferre2` (el segundo frente de un negocio cuyo primer frente no está en el admin), un cliente
     * nuevo `ferre` chocaría con ella por su segundo frente aunque `ferre` esté libre.
     *
     * @return void
     */
    public function test_el_segundo_frente_del_cliente_nuevo_tambien_tiene_que_estar_libre(): void
    {
        $existente        = $this->crear_cliente('Solo Segundo Frente');
        $api              = new ClientApi();
        $api->client_id   = $existente->id;
        $api->url         = 'https://api-ferre2.comerciocity.com';
        $api->path        = 'ferre2/api';
        $api->spa_url     = 'https://ferre2.comerciocity.com';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        $respuesta = $this->alta(['lead_id' => $this->lead_para_promover()->id, 'subdominio' => 'ferre']);

        $respuesta->assertJsonPath('subdominio.valido', false);
        $this->assertStringContainsString('ya lo usa el cliente ' . $existente->id, (string) $respuesta->json('subdominio.motivo'));
    }

    /**
     * Que `hb` aparezca adentro de otro host (`api-hbo`) no lo ocupa: se compara el host exacto, no el
     * texto.
     *
     * @return void
     */
    public function test_un_subdominio_que_solo_aparece_adentro_de_otro_no_esta_ocupado(): void
    {
        $this->crear_las_dos_apis($this->crear_cliente('Hbo Distribuciones'), 'hbo');
        $lead = $this->lead_para_promover();

        $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'hb']);

        $respuesta->assertJsonPath('subdominio.valido', true);
        $respuesta->assertJsonPath('listo', true);
    }

    /**
     * Un nombre reservado de la plataforma no sirve: `admin` pisaría el panel de ComercioCity en la zona.
     *
     * @return void
     */
    public function test_un_nombre_reservado_no_sirve(): void
    {
        $lead = $this->lead_para_promover();

        foreach (['admin', 'api', 'www'] as $reservado) {
            $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => $reservado]);

            $respuesta->assertJsonPath('subdominio.valido', false);
            $this->assertStringContainsString('reservado', (string) $respuesta->json('subdominio.motivo'));
        }
    }

    /**
     * Un subdominio que termina en guion no sirve.
     *
     * @return void
     */
    public function test_un_subdominio_que_termina_en_guion_no_sirve(): void
    {
        $respuesta = $this->alta(['lead_id' => $this->lead_para_promover()->id, 'subdominio' => 'rosa-']);

        $respuesta->assertJsonPath('subdominio.valido', false);
        $this->assertStringContainsString('guion', (string) $respuesta->json('subdominio.motivo'));
    }

    /**
     * El nombre de la base (`<prefijo><sub>`) tiene que entrar en los 32 caracteres de MySQL.
     *
     * @return void
     */
    public function test_la_base_tiene_que_entrar_en_32_caracteres(): void
    {
        config(['services.hostinger.database_prefix' => 'u767360347_prefijo_largo_']);

        $respuesta = $this->alta(['lead_id' => $this->lead_para_promover()->id, 'subdominio' => 'abcdefghij']);

        $respuesta->assertJsonPath('subdominio.valido', false);
        $this->assertStringContainsString('32 caracteres', (string) $respuesta->json('subdominio.motivo'));
    }

    /**
     * Sin ningún admin que pueda figurar como creador, el alta real no se puede hacer: queda como
     * bloqueo en el dry-run.
     *
     * @return void
     */
    public function test_sin_creador_resoluble_el_dry_run_lo_bloquea(): void
    {
        $this->app->bind(ClaudeImplementationOpsController::class, function () {
            return new class extends ClaudeImplementationOpsController {
                protected function resolver_creador()
                {
                    return null;
                }
            };
        });

        $respuesta = $this->alta(['lead_id' => $this->lead_para_promover()->id, 'subdominio' => 'rosa']);

        $respuesta->assertJsonPath('listo', false);
        $this->assertStringContainsString('creador de las tareas', $this->cuerpo($respuesta));
    }

    /**
     * Los avisos: URL del formulario sin cargar, lead sin teléfono, y siempre el recordatorio de que el
     * subdominio no se chequeó contra Hostinger.
     *
     * @return void
     */
    public function test_los_avisos_del_dry_run(): void
    {
        $lead = $this->crear_lead(['contact_name' => 'Sin Teléfono', 'company_name' => 'Sin Teléfono SRL', 'phone' => '']);

        $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'sintel']);
        $cuerpo    = $this->cuerpo($respuesta);

        $respuesta->assertJsonPath('form_url_configurada', false);
        $this->assertStringContainsString('implementation_form_url', $cuerpo);
        $this->assertStringContainsString('no tiene teléfono', $cuerpo);
        $this->assertStringContainsString('NO contra Hostinger', $cuerpo);

        AdminSetting::set('implementation_form_url', 'https://admin.ejemplo.test/configuracion');

        $this->alta(['lead_id' => $lead->id, 'subdominio' => 'sintel'])->assertJsonPath('form_url_configurada', true);
    }

    /**
     * Con la versión publicada, el dry-run dice cuál se instalaría.
     *
     * @return void
     */
    public function test_el_dry_run_dice_la_version_que_se_instalaria(): void
    {
        $this->crear_version();
        $ultima = $this->crear_version();

        $this->alta(['lead_id' => $this->lead_para_promover()->id, 'subdominio' => 'rosa'])
            ->assertJsonPath('version_a_instalar.id', (int) $ultima->id)
            ->assertJsonPath('version_a_instalar.version', $ultima->version);
    }

    /* ------------------------------------------------------------------------------------------
     | El alta real: los frenos
     |----------------------------------------------------------------------------------------- */

    /**
     * 3. Un `confirm_nombre` equivocado es 422, no escribe nada y no revela el nombre correcto.
     *
     * @return void
     */
    public function test_el_nombre_equivocado_no_escribe_nada_ni_revela_el_nombre(): void
    {
        $lead  = $this->lead_para_promover();
        $antes = $this->foto();

        $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Otro Negocio']);

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString('Almacén Rosa', $this->cuerpo($respuesta), 'El error reveló el nombre correcto.');
        $this->assertSame($antes, $this->foto());
        $this->assertNull($lead->refresh()->promoted_client_id);
    }

    /**
     * Sin `confirm_nombre`, con dry_run=false: 422 en español.
     *
     * @return void
     */
    public function test_sin_confirm_nombre_el_alta_real_es_422(): void
    {
        $respuesta = $this->alta(['lead_id' => $this->lead_para_promover()->id, 'subdominio' => 'rosa', 'dry_run' => false]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('es obligatorio cuando', $this->cuerpo($respuesta));
    }

    /**
     * La confirmación acepta la razón social aunque venga con otras mayúsculas y espacios.
     *
     * @return void
     */
    public function test_la_confirmacion_ignora_mayusculas_y_espacios(): void
    {
        $respuesta = $this->alta(['lead_id' => $this->lead_para_promover()->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => '  ALMACÉN rosa ']);

        $respuesta->assertStatus(201);
    }

    /**
     * Con razón social vacía, se confirma con el nombre del contacto.
     *
     * @return void
     */
    public function test_sin_razon_social_se_confirma_con_el_nombre_del_contacto(): void
    {
        $lead = $this->crear_lead(['contact_name' => 'Rosa Fernández', 'company_name' => '']);

        $this->alta(['lead_id' => $lead->id, 'subdominio' => 'rosaf', 'dry_run' => false, 'confirm_nombre' => 'Rosa Fernández'])->assertStatus(201);
    }

    /**
     * Un lead sin nombre alguno no se puede confirmar: 422 que dice la causa de verdad.
     *
     * @return void
     */
    public function test_un_lead_sin_nombre_no_se_puede_confirmar(): void
    {
        $lead = $this->crear_lead(['contact_name' => '', 'company_name' => '']);

        $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'lo que sea']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('NO tiene ni razón social ni nombre', $this->cuerpo($respuesta));
    }

    /**
     * El alta real sin subdominio, o con uno que no sirve, es 422 con `bloqueos` y no escribe nada.
     *
     * @return void
     */
    public function test_el_alta_real_sin_subdominio_valido_es_422(): void
    {
        $this->crear_las_dos_apis($this->crear_cliente('Ferretería Don Juan'), 'ferre');
        $lead  = $this->lead_para_promover();
        $antes = $this->foto();

        $sin = $this->alta(['lead_id' => $lead->id, 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa']);
        $sin->assertStatus(422);
        $this->assertStringContainsString('Falta confirmar el subdominio', $this->cuerpo($sin));

        $ocupado = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'ferre', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa']);
        $ocupado->assertStatus(422);
        $this->assertNotEmpty($ocupado->json('bloqueos'));

        $this->assertSame($antes, $this->foto());
    }

    /* ------------------------------------------------------------------------------------------
     | El alta real: el camino feliz
     |----------------------------------------------------------------------------------------- */

    /**
     * 2. El alta de un lead: 201 con el cliente, sus dos ClientApi (con la forma de la promoción del
     * panel), las tareas, el lead promovido y la implementación con sus ocho etapas.
     *
     * @return void
     */
    public function test_el_alta_de_un_lead_deja_todo_el_estado_esperado(): void
    {
        $lead = $this->lead_para_promover();
        AdminSetting::set('implementation_form_url', 'https://admin.ejemplo.test/configuracion/');
        $antes = $this->foto();

        $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa']);

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('dry_run', false);
        $respuesta->assertJsonPath('accion', 'promover_y_empezar');
        $respuesta->assertJsonPath('promovido', true);
        $respuesta->assertJsonPath('implementation.current_stage', 1);
        $respuesta->assertJsonPath('implementation.status', 'in_progress');

        $client = Client::find($respuesta->json('cliente.id'));
        $this->assertNotNull($client);
        $this->assertSame('Rosa Fernández', $client->name);
        $this->assertSame('Almacén Rosa', $client->company_name);
        $this->assertSame((int) $client->user_id, $respuesta->json('cliente.user_id'));

        /* Las dos ClientApi, con la forma de PromoteLeadToClientService. */
        $apis = ClientApi::where('client_id', $client->id)->orderBy('id')->get();
        $this->assertCount(2, $apis);
        $this->assertSame('https://api-rosa.comerciocity.com', $apis[0]->url);
        $this->assertSame('rosa/api', $apis[0]->path);
        $this->assertSame('https://rosa.comerciocity.com', $apis[0]->spa_url);
        $this->assertSame('shared_hosting', $apis[0]->hosting_type);
        $this->assertSame('https://api-rosa2.comerciocity.com', $apis[1]->url);
        $this->assertSame('rosa2/api', $apis[1]->path);
        $this->assertSame((int) $apis[0]->id, (int) $client->active_client_api_id);
        $respuesta->assertJsonPath('cliente.active_client_api_id', (int) $apis[0]->id);
        $this->assertCount(2, $respuesta->json('client_apis'));

        /* El lead quedó promovido. */
        $lead->refresh();
        $this->assertSame((int) $client->id, (int) $lead->promoted_client_id);
        $this->assertSame('cerrado_ganado', $lead->status);

        /* La implementación: manual, etapa 1, ocho etapas, con el link del formulario. */
        $implementation = Implementation::where('client_id', $client->id)->first();
        $this->assertNotNull($implementation);
        $this->assertSame('manual', $implementation->automation_mode);
        $this->assertSame(8, ImplementationStage::where('implementation_id', $implementation->id)->count());
        $respuesta->assertJsonPath('implementation.id', (int) $implementation->id);
        $respuesta->assertJsonPath('implementation.form_link', 'https://admin.ejemplo.test/configuracion/' . $implementation->form_token);

        /* Y exactamente lo que tenía que escribir: un cliente, dos APIs, una implementación, ocho etapas, tres tareas. */
        $despues = $this->foto();
        $this->assertSame($antes['clientes'] + 1, $despues['clientes']);
        $this->assertSame($antes['client_apis'] + 2, $despues['client_apis']);
        $this->assertSame($antes['implementations'] + 1, $despues['implementations']);
        $this->assertSame($antes['etapas'] + 8, $despues['etapas']);
        $this->assertSame($antes['tareas'] + 3, $despues['tareas']);
    }

    /**
     * Las tareas de la promoción llevan como creador al admin de `CLAUDE_TASK_INGEST_CREATOR_ADMIN_ID`.
     *
     * @return void
     */
    public function test_las_tareas_llevan_como_creador_al_admin_configurado(): void
    {
        $admin = $this->crear_admin();
        config(['services.claude_task_ingest.default_creator_admin_id' => $admin->id]);

        $this->alta(['lead_id' => $this->lead_para_promover()->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa'])->assertStatus(201);

        $this->assertSame(3, DB::table('admin_tasks')->where('created_by_admin_id', $admin->id)->count());
    }

    /**
     * Sin `implementation_form_url`, el alta se hace igual: `form_link` es null y va un aviso explícito.
     *
     * @return void
     */
    public function test_sin_la_url_del_formulario_el_alta_avisa_que_no_hay_link(): void
    {
        $respuesta = $this->alta(['lead_id' => $this->lead_para_promover()->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa']);

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('implementation.form_link', null);
        $this->assertStringContainsString('form_link es null', $this->cuerpo($respuesta));
    }

    /**
     * 4. 🔴 Aunque el setting global diga `auto`, la implementación nace en `manual` y no sale ningún
     * mensaje: cada mensaje al cliente lo aprueba Lucas.
     *
     * @return void
     */
    public function test_aunque_el_setting_diga_auto_nace_manual_y_sin_mensajes(): void
    {
        AdminSetting::set('implementation_automation_mode', 'auto');

        $respuesta = $this->alta(['lead_id' => $this->lead_para_promover()->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa']);

        $respuesta->assertStatus(201);
        $implementation = Implementation::find($respuesta->json('implementation.id'));
        $this->assertSame('manual', $implementation->automation_mode);
        $this->assertSame(0, ImplementationMessage::where('implementation_id', $implementation->id)->count());
    }

    /**
     * El alta de un cliente que ya existe y no tiene implementación: `empezar`, sin promover nada.
     *
     * @return void
     */
    public function test_el_alta_de_un_cliente_existente(): void
    {
        $cliente = $this->crear_las_dos_apis($this->crear_cliente('Panchito Gómez'), 'panchito');
        $antes   = $this->foto();

        $respuesta = $this->alta(['client_id' => $cliente->id, 'dry_run' => false, 'confirm_nombre' => 'Negocio de Panchito Gómez']);

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('accion', 'empezar');
        $respuesta->assertJsonPath('promovido', false);
        $respuesta->assertJsonPath('cliente.id', (int) $cliente->id);

        $despues = $this->foto();
        $this->assertSame($antes['clientes'], $despues['clientes'], 'No se promueve: no se crea ningún cliente.');
        $this->assertSame($antes['client_apis'], $despues['client_apis']);
        $this->assertSame($antes['tareas'], $despues['tareas']);
        $this->assertSame($antes['implementations'] + 1, $despues['implementations']);
        $this->assertSame(8, ImplementationStage::where('implementation_id', $respuesta->json('implementation.id'))->count());
    }

    /**
     * El dry-run de un cliente existente muestra su cliente y sus URLs, y avisa si no tiene APIs.
     *
     * @return void
     */
    public function test_el_dry_run_de_un_cliente_existente(): void
    {
        $con_apis = $this->crear_las_dos_apis($this->crear_cliente('Con Apis'), 'conapis');
        $sin_apis = $this->crear_cliente('Sin Apis', ['phone' => '']);

        $uno = $this->alta(['client_id' => $con_apis->id]);
        $uno->assertStatus(200);
        $uno->assertJsonPath('accion', 'empezar');
        $uno->assertJsonPath('cliente.existente', true);
        $uno->assertJsonPath('cliente.id', (int) $con_apis->id);
        $uno->assertJsonPath('subdominio.valido', null);
        $this->assertCount(4, $uno->json('urls'));

        $dos    = $this->alta(['client_id' => $sin_apis->id]);
        $cuerpo = $this->cuerpo($dos);
        $this->assertStringContainsString('ninguna ClientApi', $cuerpo);
        $this->assertStringContainsString('no tiene teléfono', $cuerpo);
        $dos->assertJsonPath('urls', []);
    }

    /* ------------------------------------------------------------------------------------------
     | Lo que ya existe
     |----------------------------------------------------------------------------------------- */

    /**
     * 5. Un cliente con implementación es 409 con el id de la que tiene, tanto en el dry-run como en el
     * alta real, y no se crea otra.
     *
     * @return void
     */
    public function test_un_cliente_con_implementacion_es_409_con_su_id(): void
    {
        $cliente        = $this->crear_cliente('Panchito Gómez');
        $implementation = $this->crear_implementacion($cliente);
        $antes          = $this->foto();

        foreach ([['dry_run' => true], ['dry_run' => false, 'confirm_nombre' => 'Negocio de Panchito Gómez']] as $extra) {
            $respuesta = $this->alta(array_merge(['client_id' => $cliente->id], $extra));

            $respuesta->assertStatus(409);
            $respuesta->assertJsonPath('implementation_id', (int) $implementation->id);
            $respuesta->assertJsonPath('current_stage', 1);
            $respuesta->assertJsonPath('client_id', (int) $cliente->id);
        }

        $this->assertSame($antes, $this->foto());
    }

    /**
     * 5. Un lead ya promovido no se promueve otra vez: se usa su cliente y el subdominio se ignora (con
     * un aviso). Si ese cliente ya tiene implementación, es 409.
     *
     * @return void
     */
    public function test_un_lead_ya_promovido_no_se_promueve_otra_vez(): void
    {
        $cliente = $this->crear_las_dos_apis($this->crear_cliente('Panchito Gómez'), 'panchito');
        $lead    = $this->crear_lead(['promoted_client_id' => $cliente->id, 'status' => 'cerrado_ganado']);
        $antes   = $this->foto();

        $simulacion = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'otro']);
        $simulacion->assertStatus(200);
        $simulacion->assertJsonPath('accion', 'empezar');
        $simulacion->assertJsonPath('cliente.id', (int) $cliente->id);
        $this->assertStringContainsString('no se promueve otra vez', $this->cuerpo($simulacion));

        $real = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'otro', 'dry_run' => false, 'confirm_nombre' => 'Negocio de Panchito Gómez']);
        $real->assertStatus(201);
        $real->assertJsonPath('promovido', false);
        $real->assertJsonPath('cliente.id', (int) $cliente->id);

        $despues = $this->foto();
        $this->assertSame($antes['clientes'], $despues['clientes']);
        $this->assertSame($antes['client_apis'], $despues['client_apis']);

        /* Ahora ese cliente ya tiene implementación: el mismo pedido es 409. */
        $this->alta(['lead_id' => $lead->id, 'dry_run' => false, 'confirm_nombre' => 'Negocio de Panchito Gómez'])->assertStatus(409);
    }

    /* ------------------------------------------------------------------------------------------
     | Todo o nada, y la contención
     |----------------------------------------------------------------------------------------- */

    /**
     * 2. 🔴 Si algo falla a la mitad del alta real, NO queda nada: ni el cliente, ni las APIs, ni las
     * tareas, ni el lead promovido. Se hace fallar al servicio de inicio, que corre DESPUÉS de la
     * promoción.
     *
     * @return void
     */
    public function test_si_el_alta_falla_a_la_mitad_no_queda_nada(): void
    {
        $lead  = $this->lead_para_promover();
        $antes = $this->foto();

        $roto = \Mockery::mock(ImplementationStartService::class);
        $roto->shouldReceive('start')->andThrow(new \RuntimeException('se cayó la base a mitad de camino'));
        $this->app->instance(ImplementationStartService::class, $roto);

        $this->alta(['lead_id' => $lead->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa'])->assertStatus(500);

        $this->assertSame($antes, $this->foto(), 'El alta fallida dejó filas a medias.');

        $lead->refresh();
        $this->assertNull($lead->promoted_client_id, 'El lead quedó promovido a un cliente que no existe.');
        $this->assertSame('closer_activo', $lead->status);
    }

    /**
     * Otra promoción en curso (lock tomado): 422 reintentable, sin escribir nada. El tiempo de espera
     * se pone en cero para que el test no espere los diez segundos.
     *
     * @return void
     */
    public function test_con_otra_promocion_en_curso_el_alta_es_422_reintentable(): void
    {
        $this->app->bind(ClaudeImplementationOpsController::class, function () {
            return new class extends ClaudeImplementationOpsController {
                protected function segundos_de_espera_del_lock_de_altas()
                {
                    return 0;
                }
            };
        });

        $lock = Cache::lock('claude_implementations_alta', 30);
        $this->assertTrue($lock->get(), 'El test no pudo tomar el lock.');

        try {
            $lead  = $this->lead_para_promover();
            $antes = $this->foto();

            $respuesta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa']);

            $respuesta->assertStatus(422);
            $respuesta->assertJsonPath('reintentable', true);
            $this->assertSame($antes, $this->foto());
        } finally {
            $lock->release();
        }
    }

    /**
     * El estado de la implementación recién creada se lee por la ruta de lectura: la skill sigue con
     * lo que devolvió el alta.
     *
     * @return void
     */
    public function test_lo_que_crea_el_alta_se_lee_por_el_estado(): void
    {
        $lead = $this->lead_para_promover();
        $alta = $this->alta(['lead_id' => $lead->id, 'subdominio' => 'rosa', 'dry_run' => false, 'confirm_nombre' => 'Almacén Rosa']);
        $alta->assertStatus(201);

        $estado = $this->getJson('/api/claude/implementations?lead_id=' . $lead->id, $this->headers());

        $estado->assertStatus(200);
        $estado->assertJsonPath('implementation.id', $alta->json('implementation.id'));
        $estado->assertJsonPath('cliente.sistema.spa_url', 'https://rosa.comerciocity.com');
        $estado->assertJsonPath('cliente.sistema.api_url', 'https://api-rosa.comerciocity.com/public');
    }
}
