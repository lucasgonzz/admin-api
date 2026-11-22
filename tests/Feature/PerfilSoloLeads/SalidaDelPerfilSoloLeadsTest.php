<?php

namespace Tests\Feature\PerfilSoloLeads;

use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\AdminTaskNotification;
use App\Models\Lead;
use App\Models\LeadPipelineStatus;
use App\Services\AdminSoloLeadsPolicy;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Aceptación del hallazgo BLOQUEANTE de la revisión de E5 (misión embudo-whatsapp-precios): el perfil
 * «Sólo Leads» (un vendedor EXTERNO) recibía, dentro de `target_client`, `promoted_client` y `demo` del lead,
 * TODAS las columnas de Client y Demo: `api_key` (la clave de administración del sistema productivo de cada
 * cliente), `inbound_api_key`, cobranzas, contrato, precios y AFIP. Eso viola «sólo acceso al módulo de leads».
 *
 * Qué fija este archivo (cierre por defecto, lista BLANCA de salida, no lista negra):
 *
 * 1. El cuerpo CRUDO de `GET lead` y `GET lead/{id}` para el perfil no contiene ningún centinela sembrado en
 *    Client/Demo ni las claves JSON sensibles (api_key, inbound_api_key, cobranzas_*, contract_*, afip_*,
 *    precio_*), y las relaciones anidadas se reducen a un mínimo explícito.
 * 2. Un test recorre las 23 rutas permitidas con datos sembrados y exige que ningún cuerpo traiga
 *    centinelas ni claves que matcheen `api_key|token|secret|password|clave`.
 * 3. Un admin normal NO cambia: conserva esos campos (guarda de regresión, también prueba que el sembrado
 *    sí llega a la respuesta cuando no hay perfil).
 * 4. Los campos mínimos que la pantalla de Leads del SPA necesita siguen en la respuesta del perfil.
 * 5. `PUT lead/{id}` del perfil: `status` sólo acepta un estado del catálogo (fijos de
 *    `Lead::PIPELINE_STATUSES` + `LeadPipelineStatus`); otro valor da 422 y no guarda nada. Un admin normal
 *    sigue pudiendo.
 *
 * Los tests de aceptación previos (`PerfilSoloLeadsTest`, etc.) no se tocan.
 */
class SalidaDelPerfilSoloLeadsTest extends TestCase
{
    use DatabaseTransactions, SembradoDeLeadConCentinelas;

    /**
     * Patrón de nombre de clave que NUNCA puede aparecer en una respuesta del perfil (a ninguna profundidad).
     */
    const PATRON_DE_CLAVE_SECRETA = '/api_key|token|secret|password|clave/i';

    /**
     * Excepciones explícitas al patrón anterior: claves que matchean pero NO son un secreto, con su motivo.
     * Hoy vacía: ninguna de las 23 rutas devuelve legítimamente una clave con esos nombres. Si algún día una
     * respuesta lo necesita, se agrega acá la ruta y la clave con la justificación (no se afloja el patrón).
     *
     * @var array<string, array<int, string>> "<MÉTODO> <uri>" => [claves permitidas]
     */
    const EXCEPCIONES_AL_PATRON = [];

    /**
     * Claves anidadas de los Client que el perfil puede ver: sólo para mostrar el nombre en la lista.
     *
     * @var array<int, string>
     */
    const CAMPOS_MINIMOS_DE_CLIENT = ['company_name', 'id', 'name'];

    /**
     * Claves de la demo que el perfil puede ver (la lista usa `erp_spa_url`; el selector usa `nombre`).
     *
     * @var array<int, string>
     */
    const CAMPOS_MINIMOS_DE_DEMO = ['erp_spa_url', 'id', 'nombre'];

    /**
     * Espía de WhatsApp (ningún caso toca Meta) y red cortada.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->app->instance(WhatsappSendService::class, new class extends WhatsappSendService {
            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                return 'wamid.espia';
            }
        });
    }

    // ------------------------------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------------------------------

    /**
     * Crea un admin con o sin el perfil.
     *
     * @param bool $solo_leads Si tiene el perfil «Sólo Leads».
     *
     * @return Admin
     */
    private function crear_admin(bool $solo_leads): Admin
    {
        return Admin::create([
            'name'       => 'Admin ' . ($solo_leads ? 'externo' : 'normal'),
            'email'      => 'salida-' . uniqid() . '@test.local',
            'password'   => bcrypt('secret'),
            'solo_leads' => $solo_leads,
        ]);
    }

    /**
     * Pide una ruta autenticada con Sanctum y devuelve la respuesta.
     *
     * @param Admin                $admin   Admin autenticado.
     * @param string               $metodo  Verbo HTTP.
     * @param string               $uri     URL.
     * @param array<string, mixed> $payload Cuerpo.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedir(Admin $admin, string $metodo, string $uri, array $payload = [])
    {
        return $this->actingAs($admin, 'sanctum')->json($metodo, $uri, $payload);
    }

    /**
     * Todos los valores centinela (texto y números) como cadenas, para buscarlos en el cuerpo crudo.
     *
     * @return array<string, string> Etiqueta => valor.
     */
    private function todos_los_centinelas(): array
    {
        $todos = $this->centinelas_de_texto();
        foreach ($this->centinelas_numericos() as $etiqueta => $numero) {
            $todos[$etiqueta] = (string) $numero;
        }

        return $todos;
    }

    /**
     * Falla si el cuerpo crudo contiene algún centinela.
     *
     * @param string $cuerpo  Cuerpo crudo de la respuesta.
     * @param string $detalle Qué se pidió, para el mensaje de fallo.
     *
     * @return void
     */
    private function assertSinCentinelas(string $cuerpo, string $detalle): void
    {
        // El JSON escapa las barras (`https:\/\/`): se busca también con las barras sin escapar, para que los
        // centinelas con forma de URL no se escapen del chequeo.
        $cuerpo_sin_escapar = str_replace('\\/', '/', $cuerpo);

        $filtrados = [];
        foreach ($this->todos_los_centinelas() as $etiqueta => $valor) {
            if (strpos($cuerpo, $valor) !== false || strpos($cuerpo_sin_escapar, $valor) !== false) {
                $filtrados[] = $etiqueta;
            }
        }

        $this->assertSame([], $filtrados, $detalle . ': el cuerpo crudo del perfil filtra datos de otros módulos: ' . implode(', ', $filtrados));
    }

    /**
     * Recorre un valor decodificado de JSON y devuelve las rutas de las claves que matchean el patrón secreto.
     *
     * @param mixed  $valor Valor decodificado (objetos como stdClass).
     * @param string $ruta  Ruta acumulada.
     * @param array  $salida Acumulador de rutas encontradas.
     *
     * @return array<int, string>
     */
    private function claves_secretas($valor, string $ruta = '', array &$salida = []): array
    {
        if (is_object($valor)) {
            foreach (get_object_vars($valor) as $clave => $hijo) {
                if (preg_match(self::PATRON_DE_CLAVE_SECRETA, (string) $clave)) {
                    $salida[] = $ruta . '.' . $clave;
                }
                $this->claves_secretas($hijo, $ruta . '.' . $clave, $salida);
            }
        } elseif (is_array($valor)) {
            foreach ($valor as $hijo) {
                $this->claves_secretas($hijo, $ruta . '[]', $salida);
            }
        }

        return $salida;
    }

    /**
     * Arma el escenario: perfil, admin normal, lead completo con centinelas y un aviso de tarea.
     *
     * @return array{0: Admin, 1: Admin, 2: Lead, 3: AdminTaskNotification}
     */
    private function escenario(): array
    {
        $restringido = $this->crear_admin(true);
        $normal      = $this->crear_admin(false);
        $lead        = $this->sembrar_lead_completo();
        $tarea       = AdminTask::create([
            'title'               => 'Llamar',
            'content'             => 'Tarea sembrada',
            'created_by_admin_id' => $restringido->id,
            'lead_id'             => $lead->id,
        ]);
        $aviso = AdminTaskNotification::create([
            'admin_task_id' => $tarea->id,
            'admin_id'      => $restringido->id,
            'seen_at'       => null,
        ]);

        return [$restringido, $normal, $lead, $aviso];
    }

    // ------------------------------------------------------------------------------------------
    // 1. GET lead (lista) y GET lead/{id}: el cuerpo crudo no filtra nada
    // ------------------------------------------------------------------------------------------

    /**
     * La LISTA de leads del perfil no trae centinelas ni las claves JSON sensibles, y sus relaciones
     * anidadas quedan en el mínimo explícito.
     *
     * @return void
     */
    public function test_la_lista_de_leads_del_perfil_no_filtra_datos_de_otros_modulos(): void
    {
        [$restringido, , $lead] = $this->escenario();

        $respuesta = $this->pedir($restringido, 'GET', '/api/admin/lead?page=1&per_page=200')->assertStatus(200);
        $cuerpo    = $respuesta->getContent();

        $this->assertSinCentinelas($cuerpo, 'GET lead');
        foreach (['"api_key"', '"inbound_api_key"', '"cobranzas_observaciones"', '"afip_cuit"', '"precio_plan"', '"total_mensualidad"'] as $clave) {
            // (`contract_clausulas_particulares` NO va acá: el lead tiene su propia columna con ese nombre, que es
            // del módulo de leads; la del Client la cubre el centinela `target.contract_clausulas`.)
            $this->assertStringNotContainsString($clave, $cuerpo, 'GET lead: la clave ' . $clave . ' no puede viajar al perfil.');
        }

        // El lead sembrado sigue en la lista y sus relaciones quedaron en el mínimo.
        $fila = null;
        foreach ($respuesta->json('models.data') as $candidata) {
            if ((int) $candidata['id'] === (int) $lead->id) {
                $fila = $candidata;
            }
        }
        $this->assertNotNull($fila, 'El lead sembrado tiene que seguir en la lista.');
        $this->assertSame(self::CAMPOS_MINIMOS_DE_CLIENT, $this->claves_ordenadas($fila['target_client']));
        $this->assertSame(self::CAMPOS_MINIMOS_DE_CLIENT, $this->claves_ordenadas($fila['promoted_client']));
        $this->assertSame(self::CAMPOS_MINIMOS_DE_DEMO, $this->claves_ordenadas($fila['demo']));
        $this->assertSame(['id', 'name'], $this->claves_ordenadas($fila['created_by_admin']));
    }

    /**
     * La FICHA del lead del perfil no trae centinelas ni las claves JSON sensibles, y sus relaciones
     * anidadas quedan en el mínimo explícito (incluido el emisor de cada mensaje).
     *
     * @return void
     */
    public function test_la_ficha_del_lead_del_perfil_no_filtra_datos_de_otros_modulos(): void
    {
        [$restringido, , $lead] = $this->escenario();

        $respuesta = $this->pedir($restringido, 'GET', '/api/admin/lead/' . $lead->id)->assertStatus(200);
        $cuerpo    = $respuesta->getContent();

        $this->assertSinCentinelas($cuerpo, 'GET lead/{id}');
        foreach (['"api_key"', '"inbound_api_key"', '"cobranzas_observaciones"', '"afip_cuit"', '"precio_plan"', '"total_mensualidad"', '"demo_ingreso_token"', '"demo_ingreso_url":"http'] as $clave) {
            $this->assertStringNotContainsString($clave, $cuerpo, 'GET lead/{id}: ' . $clave . ' no puede viajar al perfil.');
        }

        $modelo = $respuesta->json('model');
        $this->assertSame(self::CAMPOS_MINIMOS_DE_CLIENT, $this->claves_ordenadas($modelo['target_client']));
        $this->assertSame(self::CAMPOS_MINIMOS_DE_CLIENT, $this->claves_ordenadas($modelo['promoted_client']));
        $this->assertSame(self::CAMPOS_MINIMOS_DE_DEMO, $this->claves_ordenadas($modelo['demo']));
        $this->assertSame(['id', 'name'], $this->claves_ordenadas($modelo['created_by_admin']));
        $this->assertSame(['id', 'name'], $this->claves_ordenadas($modelo['messages'][0]['sent_by_admin']));
    }

    /**
     * Ordena las claves de un objeto decodificado (para comparar el conjunto exacto).
     *
     * @param array<string, mixed> $objeto Objeto decodificado como arreglo asociativo.
     *
     * @return array<int, string>
     */
    private function claves_ordenadas(array $objeto): array
    {
        $claves = array_keys($objeto);
        sort($claves);

        return $claves;
    }

    // ------------------------------------------------------------------------------------------
    // 2. Las 23 rutas permitidas, una por una
    // ------------------------------------------------------------------------------------------

    /**
     * URL concreta y cuerpo de cada ruta permitida, con los datos sembrados. La clave es exactamente la de
     * `AdminSoloLeadsPolicy::RUTAS_PERMITIDAS`; un test verifica que el mapa las cubra todas (una ruta nueva en
     * la política obliga a agregarla acá).
     *
     * @param Lead                 $lead  Lead sembrado.
     * @param Admin                $admin Admin con el perfil.
     * @param AdminTaskNotification $aviso Aviso de tarea sembrado.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> clave => [método, URL, cuerpo]
     */
    private function pedidos_concretos(Lead $lead, Admin $admin, AdminTaskNotification $aviso): array
    {
        $push = ['endpoint' => 'https://push.test/abc', 'keys' => ['p256dh' => 'k1', 'auth' => 'k2']];

        return [
            'GET api/admin/me'                                       => ['GET', '/api/admin/me', []],
            'POST api/admin/logout'                                  => ['POST', '/api/admin/logout', []],
            'GET api/admin/version'                                  => ['GET', '/api/admin/version', []],
            'GET api/admin/meta/{model}'                             => ['GET', '/api/admin/meta/lead', []],
            'GET api/admin/lead'                                     => ['GET', '/api/admin/lead?page=1&per_page=200', []],
            'GET api/admin/lead/status-cards'                        => ['GET', '/api/admin/lead/status-cards', []],
            'GET api/admin/lead/chats'                               => ['GET', '/api/admin/lead/chats', []],
            'GET api/admin/lead/chats/{id}'                          => ['GET', '/api/admin/lead/chats/' . $lead->id, []],
            'GET api/admin/lead/unread-badges'                       => ['GET', '/api/admin/lead/unread-badges', []],
            'GET api/admin/lead/{id}'                                => ['GET', '/api/admin/lead/' . $lead->id, []],
            'PUT api/admin/lead/{id}'                                => ['PUT', '/api/admin/lead/' . $lead->id, ['status' => 'en_pausa', 'notes' => 'Nota nueva']],
            'POST api/admin/lead/{id}/toggle-notify-messages'        => ['POST', '/api/admin/lead/' . $lead->id . '/toggle-notify-messages', []],
            'GET api/admin/settings/lead-whatsapp-onboarding'        => ['GET', '/api/admin/settings/lead-whatsapp-onboarding', []],
            'GET api/admin/settings/lead-demo'                       => ['GET', '/api/admin/settings/lead-demo', []],
            'GET api/admin/task-notification/pending'                => ['GET', '/api/admin/task-notification/pending', []],
            'POST api/admin/task-notification/{id}/seen'             => ['POST', '/api/admin/task-notification/' . $aviso->id . '/seen', []],
            'POST api/admin/task-notification/seen-all'              => ['POST', '/api/admin/task-notification/seen-all', []],
            'GET api/admin/push/vapid-public-key'                    => ['GET', '/api/admin/push/vapid-public-key', []],
            'POST api/admin/push/subscribe'                          => ['POST', '/api/admin/push/subscribe', $push],
            'POST api/admin/push/unsubscribe'                        => ['POST', '/api/admin/push/unsubscribe', ['endpoint' => 'https://push.test/abc']],
            'POST api/admin/push/subscription-status'                => ['POST', '/api/admin/push/subscription-status', ['endpoint' => 'https://push.test/abc']],
            'POST broadcasting/auth'                                 => ['POST', '/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-admin.' . $admin->id]],
            'POST logout'                                            => ['POST', '/logout', []],
        ];
    }

    /**
     * El mapa de pedidos concretos cubre EXACTAMENTE la lista permitida de la política (ni una de más ni una
     * de menos): una ruta que se agregue al perfil queda cubierta por el recorrido de abajo, o este test rompe.
     *
     * @return void
     */
    public function test_el_recorrido_cubre_exactamente_las_rutas_permitidas(): void
    {
        [$restringido, , $lead, $aviso] = $this->escenario();

        $del_mapa = array_keys($this->pedidos_concretos($lead, $restringido, $aviso));
        $oficial  = AdminSoloLeadsPolicy::RUTAS_PERMITIDAS;
        sort($del_mapa);
        sort($oficial);

        $this->assertCount(23, $oficial, 'La lista permitida del plan tiene 23 rutas.');
        $this->assertSame($oficial, $del_mapa);
    }

    /**
     * Recorre las 23 rutas permitidas con datos sembrados como el perfil: ningún cuerpo trae centinelas ni
     * claves que matcheen el patrón secreto (salvo las excepciones explícitas, hoy ninguna).
     *
     * @return void
     */
    public function test_ninguna_de_las_rutas_permitidas_filtra_secretos_al_perfil(): void
    {
        [$restringido, , $lead, $aviso] = $this->escenario();

        foreach ($this->pedidos_concretos($lead, $restringido, $aviso) as $clave => $pedido) {
            [$metodo, $url, $cuerpo_pedido] = $pedido;

            // El panel Blade usa sesión web; el resto va por Sanctum.
            if ($clave === 'POST logout') {
                $respuesta = $this->actingAs($restringido, 'web')->post($url, $cuerpo_pedido);
            } else {
                $respuesta = $this->pedir($restringido, $metodo, $url, $cuerpo_pedido);
            }

            $this->assertNotSame(403, $respuesta->getStatusCode(), $clave . ': la ruta permitida no puede dar 403.');

            $cuerpo = $respuesta->getContent();
            $this->assertSinCentinelas($cuerpo, $clave);

            $decodificado = json_decode($cuerpo);
            $hallazgos    = array_values(array_diff(
                $this->claves_secretas($decodificado),
                array_map(function ($clave_permitida) {
                    return '.' . $clave_permitida;
                }, self::EXCEPCIONES_AL_PATRON[$clave] ?? [])
            ));
            $this->assertSame([], $hallazgos, $clave . ': claves sensibles en la respuesta del perfil: ' . implode(', ', $hallazgos));
        }
    }

    // ------------------------------------------------------------------------------------------
    // 3. El admin normal NO cambia (guarda de regresión)
    // ------------------------------------------------------------------------------------------

    /**
     * Un admin normal conserva los campos completos de Client y Demo en la lista y en la ficha. De paso
     * prueba que el sembrado SÍ llega a la respuesta cuando no hay perfil (el test del perfil no es vacío).
     *
     * @return void
     */
    public function test_el_admin_normal_sigue_recibiendo_los_campos_completos(): void
    {
        [, $normal, $lead] = $this->escenario();

        $lista = $this->pedir($normal, 'GET', '/api/admin/lead?page=1&per_page=200')->assertStatus(200)->getContent();
        $ficha = $this->pedir($normal, 'GET', '/api/admin/lead/' . $lead->id)->assertStatus(200)->getContent();

        foreach (['lista' => $lista, 'ficha' => $ficha] as $nombre => $cuerpo) {
            foreach (['target.api_key', 'target.inbound_api_key', 'target.cobranzas_observaciones', 'promoted.api_key', 'demo.api_key', 'target.afip_cuit'] as $etiqueta) {
                $this->assertStringContainsString(
                    $this->centinelas_de_texto()[$etiqueta],
                    $cuerpo,
                    'El admin normal tiene que seguir viendo ' . $etiqueta . ' en la ' . $nombre . ' (no cambia).'
                );
            }
            $this->assertStringContainsString('"precio_plan"', $cuerpo);
        }
    }

    // ------------------------------------------------------------------------------------------
    // 4. Lo que la pantalla de Leads necesita sigue ahí
    // ------------------------------------------------------------------------------------------

    /**
     * Campos mínimos que el SPA lee en la lista (`Leads.vue`: nombre del cliente/demo de la fila, estado,
     * contacto, no leídos) y en la conversación/ficha (`LeadConversationView.vue`, `lead/conversation/*`,
     * `lead/demo/*`, `lead/resumen/*`): el filtro de salida no puede quitarlos.
     *
     * @return void
     */
    public function test_los_campos_minimos_de_la_pantalla_de_leads_siguen_en_la_respuesta(): void
    {
        [$restringido, , $lead] = $this->escenario();

        $lista = $this->pedir($restringido, 'GET', '/api/admin/lead?page=1&per_page=200')->assertStatus(200);
        $fila  = null;
        foreach ($lista->json('models.data') as $candidata) {
            if ((int) $candidata['id'] === (int) $lead->id) {
                $fila = $candidata;
            }
        }
        $this->assertNotNull($fila);
        foreach (['id', 'uuid', 'contact_name', 'company_name', 'phone', 'status', 'notes', 'last_message_at', 'unread_messages_count', 'target_client_id', 'promoted_client_id', 'demo_id'] as $campo) {
            $this->assertArrayHasKey($campo, $fila, 'La lista tiene que seguir trayendo ' . $campo . '.');
        }
        $this->assertSame('Objetivo Visible SA', $fila['target_client']['name']);
        $this->assertSame('Empresa Objetivo Visible', $fila['target_client']['company_name']);
        $this->assertSame('https://demo-visible.test', $fila['demo']['erp_spa_url']);
        $this->assertArrayHasKey('links', $lista->json('models'), 'La paginación sigue en la lista.');

        $ficha  = $this->pedir($restringido, 'GET', '/api/admin/lead/' . $lead->id)->assertStatus(200);
        $modelo = $ficha->json('model');
        foreach (['messages', 'scheduled_messages', 'partners', 'personalized_demo_videos', 'last_summarized_call', 'demo_form_panel'] as $relacion) {
            $this->assertNotEmpty($modelo[$relacion], 'La ficha tiene que seguir trayendo ' . $relacion . '.');
        }
        $mensaje = $modelo['messages'][0];
        foreach (['id', 'sender', 'status', 'content', 'created_at', 'attachments', 'sent_by_admin_name'] as $campo) {
            $this->assertArrayHasKey($campo, $mensaje, 'El mensaje tiene que seguir trayendo ' . $campo . '.');
        }
        foreach (['id', 'mime', 'original_filename', 'download_url'] as $campo) {
            $this->assertArrayHasKey($campo, $mensaje['attachments'][0], 'El adjunto tiene que seguir trayendo ' . $campo . '.');
        }
        $this->assertSame('Programado sembrado', $modelo['scheduled_messages'][0]['content']);
        $this->assertSame('Socio sembrado', $modelo['partners'][0]['name']);
        $this->assertSame('https://video.test/sembrado', $modelo['personalized_demo_videos'][0]['video_url']);
        $this->assertArrayHasKey('call_summary', $modelo['last_summarized_call']);
        $this->assertArrayHasKey('respuestas', $modelo['demo_form_panel']);
    }

    /**
     * La lista de chats y el aviso de tareas (que anidan demo, lead y admins) siguen con lo que pintan.
     *
     * @return void
     */
    public function test_chats_y_avisos_conservan_lo_que_pintan(): void
    {
        [$restringido, , $lead] = $this->escenario();

        $chat = $this->pedir($restringido, 'GET', '/api/admin/lead/chats/' . $lead->id)->assertStatus(200)->json('data');
        $this->assertSame('Respuesta del lead sembrada', $chat['ultimo_mensaje']['texto']);
        foreach (['demo_id', 'demo_date', 'demo_start_time', 'demo_end_time', 'demo_setup_status'] as $campo) {
            $this->assertArrayHasKey($campo, $chat['demo']);
        }

        $aviso = $this->pedir($restringido, 'GET', '/api/admin/task-notification/pending')->assertStatus(200)->json('models.0');
        $this->assertSame('Llamar', $aviso['task']['title']);
        $this->assertSame(['id', 'name'], $this->claves_ordenadas($aviso['task']['created_by_admin']));
        $this->assertSame('Empresa Sembrada', $aviso['task']['lead']['company_name']);
    }

    // ------------------------------------------------------------------------------------------
    // 5. La política: cierre por defecto
    // ------------------------------------------------------------------------------------------

    /**
     * Una relación u objeto anidado que NO está listado se elimina, a cualquier profundidad (cierre por
     * defecto: una relación nueva que alguien cargue mañana no se filtra sola), y una clave que matchee el
     * patrón secreto se elimina aunque esté en una columna propia.
     *
     * @return void
     */
    public function test_la_politica_elimina_lo_que_no_esta_listado(): void
    {
        $politica = new AdminSoloLeadsPolicy();

        $entrada = json_decode(json_encode([
            'model' => [
                'id'                   => 1,
                'contact_name'         => 'Juan',
                'relacion_nueva'       => ['id' => 9, 'secreto_de_otro_modulo' => 'x'],
                'lista_nueva'          => [['id' => 1, 'dato' => 'y']],
                'demo_ingreso_token'   => 'tok',
                'target_client'        => ['id' => 2, 'name' => 'N', 'company_name' => 'C', 'api_key' => 'k', 'precio_plan' => 5, 'client_ecommerce' => ['id' => 1]],
                'messages'             => [['id' => 3, 'content' => 'hola', 'api_key_cruzada' => 'k', 'relacion_nueva_de_mensaje' => ['id' => 1], 'sent_by_admin' => ['id' => 4, 'name' => 'A', 'email' => 'a@a']]],
            ],
        ]));

        $salida = json_decode(json_encode($politica->filtrar_salida($entrada)), true);

        $this->assertSame(1, $salida['model']['id']);
        $this->assertSame('Juan', $salida['model']['contact_name']);
        $this->assertArrayNotHasKey('relacion_nueva', $salida['model']);
        $this->assertArrayNotHasKey('lista_nueva', $salida['model']);
        $this->assertArrayNotHasKey('demo_ingreso_token', $salida['model']);
        $this->assertSame(['company_name' => 'C', 'id' => 2, 'name' => 'N'], $this->ordenar_por_clave($salida['model']['target_client']));
        $this->assertSame(['content' => 'hola', 'id' => 3, 'sent_by_admin' => ['id' => 4, 'name' => 'A']], $this->ordenar_por_clave($salida['model']['messages'][0]));
    }

    /**
     * Ordena un arreglo asociativo por clave (para comparar sin depender del orden de inserción).
     *
     * @param array<string, mixed> $arreglo Arreglo asociativo.
     *
     * @return array<string, mixed>
     */
    private function ordenar_por_clave(array $arreglo): array
    {
        ksort($arreglo);

        return $arreglo;
    }

    // ------------------------------------------------------------------------------------------
    // 6. PUT lead/{id}: el estado tiene que existir en el catálogo
    // ------------------------------------------------------------------------------------------

    /**
     * Un estado inexistente del perfil da 422 y no guarda NADA (ni el estado ni la nota que venía con él).
     *
     * @return void
     */
    public function test_el_perfil_no_puede_guardar_un_estado_inexistente(): void
    {
        [$restringido, , $lead] = $this->escenario();

        foreach (['estado_que_no_existe', '', 'NUEVO ', ['nuevo']] as $invalido) {
            $respuesta = $this->pedir($restringido, 'PUT', '/api/admin/lead/' . $lead->id, [
                'status' => $invalido,
                'notes'  => 'Nota que no debe guardarse',
            ]);

            $this->assertSame(422, $respuesta->getStatusCode(), 'status ' . json_encode($invalido) . ' tiene que dar 422.');
            $lead->refresh();
            $this->assertSame('contactado', $lead->status, 'Un estado inválido no puede guardarse.');
            $this->assertSame('Nota sembrada', $lead->notes, 'El 422 no guarda nada, tampoco la nota.');
        }
    }

    /**
     * El perfil sí puede elegir un estado fijo del pipeline, uno del catálogo dinámico, o guardar sólo la nota.
     *
     * @return void
     */
    public function test_el_perfil_puede_elegir_estados_del_catalogo_y_guardar_solo_la_nota(): void
    {
        [$restringido, , $lead] = $this->escenario();

        // Un estado fijo (Lead::PIPELINE_STATUSES) y uno que sólo existe en el catálogo dinámico.
        LeadPipelineStatus::ensure_exists('estado_dinamico_e5', 'Estado dinámico E5');

        $this->pedir($restringido, 'PUT', '/api/admin/lead/' . $lead->id, ['status' => 'en_pausa'])->assertStatus(200);
        $this->assertSame('en_pausa', $lead->fresh()->status);

        $this->pedir($restringido, 'PUT', '/api/admin/lead/' . $lead->id, ['status' => 'estado_dinamico_e5'])->assertStatus(200);
        $this->assertSame('estado_dinamico_e5', $lead->fresh()->status);

        $this->pedir($restringido, 'PUT', '/api/admin/lead/' . $lead->id, ['notes' => 'Sólo la nota'])->assertStatus(200);
        $lead->refresh();
        $this->assertSame('estado_dinamico_e5', $lead->status, 'Sin `status` en el cuerpo el estado no cambia.');
        $this->assertSame('Sólo la nota', $lead->notes);
    }

    /**
     * Un admin normal no cambia: el PUT con un estado fuera del catálogo se comporta como hoy (200 y lo
     * guarda). La validación del catálogo es sólo del perfil.
     *
     * @return void
     */
    public function test_el_admin_normal_sigue_pudiendo_guardar_cualquier_estado(): void
    {
        [, $normal, $lead] = $this->escenario();

        $this->pedir($normal, 'PUT', '/api/admin/lead/' . $lead->id, ['status' => 'estado_que_no_existe'])->assertStatus(200);

        $this->assertSame('estado_que_no_existe', $lead->fresh()->status, 'Para el admin normal no cambia nada.');
    }
}
