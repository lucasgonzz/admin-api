<?php

namespace Tests\Feature\PerfilSoloLeads;

use App\ModelProperties\AdminProperties;
use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\AdminTaskNotification;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadMessageRead;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Aceptación de E5 «Perfil restringido a Leads» (misión embudo-whatsapp-precios), lado admin-api:
 * CA34 (el flag), CA35 (el backend lo hace cumplir), CA36 (sólo estado y nota), CA38 (panel Blade y
 * broadcasting).
 *
 * Qué se protege acá y no en otro archivo:
 *
 * 1. Un admin con `solo_leads = true` recibe 200 en cada ruta de la lista permitida de Leads y 403 con
 *    `{"code": "solo_leads"}` en el resto, **sin efectos** en la base ni en WhatsApp.
 * 2. Un admin normal (sin el flag, o con el flag en false) no nota ningún cambio.
 * 3. El restringido, al guardar un lead, sólo puede cambiar estado y nota: el servidor descarta el resto.
 *
 * Son tests de Feature contra las rutas reales con base de por medio (patrón `actingAs($admin, 'sanctum')`
 * de StatusCardsAgrupadaOtrosTest). WhatsApp se sustituye a nivel `WhatsappSendService` y la red está
 * cortada con `Http::fake()`: ningún caso toca Meta.
 *
 * Hoy están EN ROJO porque la columna `admins.solo_leads`, el middleware `admin.solo_leads` y el flag en
 * `/me` no existen todavía. Los casos de «admin normal sin flag» son guarda de regresión (verdes hoy y
 * tienen que seguir verdes cuando exista el middleware, que cuelga de todas las rutas autenticadas).
 */
class PerfilSoloLeadsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var object Espía de WhatsappSendService registrado en el contenedor (cuenta los envíos). */
    private $espia_whatsapp;

    /**
     * Corta la red y deja un espía en lugar del servicio de WhatsApp.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->espia_whatsapp = new class extends WhatsappSendService {
            /** @var int Cantidad de mensajes (texto, plantilla o reacción) que se intentaron mandar. */
            public $envios = 0;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->envios++;

                return 'wamid.espia.' . $this->envios;
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->envios++;

                return 'wamid.espia.' . $this->envios;
            }

            public function send_reaction(string $to, string $target_whatsapp_message_id, string $emoji, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->envios++;

                return 'wamid.espia.' . $this->envios;
            }

            public function last_send_was_transient(): bool
            {
                return false;
            }
        };

        $this->app->instance(WhatsappSendService::class, $this->espia_whatsapp);
    }

    // ------------------------------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------------------------------

    /**
     * Crea un admin. Con `$solo_leads` null no se manda la clave (admin «de antes»: sin el flag).
     *
     * @param bool|null $solo_leads Valor del flag, o null para no tocarlo.
     *
     * @return Admin
     */
    private function crear_admin(?bool $solo_leads = null): Admin
    {
        $atributos = [
            'name'     => 'Admin de prueba',
            'email'    => 'solo-leads-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ];

        if ($solo_leads !== null) {
            $atributos['solo_leads'] = $solo_leads;
        }

        return Admin::create($atributos);
    }

    /**
     * Lead con teléfono, nombre, empresa y nota conocidos, para poder comprobar qué cambió.
     *
     * @param string $status Estado inicial.
     *
     * @return Lead
     */
    private function crear_lead(string $status = 'contactado'): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Contacto original';
        $lead->company_name = 'Empresa original';
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->notes        = 'Nota original';
        $lead->status       = $status;
        $lead->save();

        return $lead;
    }

    /**
     * Mensaje sugerido por la IA, pendiente de aprobación.
     *
     * @param Lead $lead Lead dueño del mensaje.
     *
     * @return LeadMessage
     */
    private function crear_mensaje_sugerido(Lead $lead): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'         => $lead->id,
            'sender'          => 'sistema',
            'status'          => 'sugerido',
            'content'         => 'Texto sugerido que todavía no salió',
            'is_status_event' => false,
        ]);
    }

    /**
     * Aviso de tarea pendiente para el admin dado (lo que muestra la pila de avisos).
     *
     * @param Admin $admin Admin que recibe el aviso.
     * @param Lead  $lead  Lead al que apunta la tarea.
     *
     * @return AdminTaskNotification
     */
    private function crear_aviso_de_tarea(Admin $admin, Lead $lead): AdminTaskNotification
    {
        $tarea = AdminTask::create([
            'title'               => 'Llamar al lead',
            'content'             => 'Tarea del embudo',
            'created_by_admin_id' => $admin->id,
            'lead_id'             => $lead->id,
        ]);

        return AdminTaskNotification::create([
            'admin_task_id' => $tarea->id,
            'admin_id'      => $admin->id,
            'seen_at'       => null,
        ]);
    }

    /**
     * Pide una ruta autenticada con Sanctum como el admin dado.
     *
     * @param Admin                $admin   Admin autenticado.
     * @param string               $metodo  Verbo HTTP.
     * @param string               $uri     URL relativa.
     * @param array<string, mixed> $payload Cuerpo de la petición.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedir(Admin $admin, string $metodo, string $uri, array $payload = [])
    {
        return $this->actingAs($admin, 'sanctum')->json($metodo, $uri, $payload);
    }

    /**
     * Exige la respuesta de perfil: 403 con el cuerpo del contrato (`code = solo_leads`).
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a verificar.
     * @param string                           $detalle   Qué se pidió, para el mensaje de fallo.
     *
     * @return void
     */
    private function assertDenegadoPorElPerfil($respuesta, string $detalle): void
    {
        $respuesta->assertStatus(403);
        $this->assertSame(
            'solo_leads',
            $respuesta->json('code'),
            $detalle . ': el 403 del perfil tiene que traer {"code": "solo_leads"} para que el SPA lo distinga.'
        );
    }

    /**
     * Reemplaza los marcadores `{lead}`, `{mensaje}`, `{aviso}` y `{otro_admin}` de una URL de proveedor.
     *
     * @param string               $uri      URL con marcadores.
     * @param array<string, mixed> $valores  Marcador (sin llaves) => valor.
     *
     * @return string
     */
    private function resolver_uri(string $uri, array $valores): string
    {
        $reemplazos = [];
        foreach ($valores as $marcador => $valor) {
            $reemplazos['{' . $marcador . '}'] = (string) $valor;
        }

        return strtr($uri, $reemplazos);
    }

    // ------------------------------------------------------------------------------------------
    // CA34 — el flag
    // ------------------------------------------------------------------------------------------

    /**
     * La columna existe y un admin creado «como antes» (sin mencionar el flag) nace con `solo_leads = false`.
     *
     * @return void
     */
    public function test_los_admins_existentes_quedan_sin_perfil(): void
    {
        $this->assertTrue(
            Schema::hasColumn('admins', 'solo_leads'),
            'Falta la columna admins.solo_leads (migración 2026_11_22_090000_add_solo_leads_to_admins_table).'
        );

        // Insert crudo, sin la clave: es lo que le pasó a cada admin que ya existía antes de migrar.
        $id = DB::table('admins')->insertGetId([
            'name'       => 'Admin de antes',
            'email'      => 'de-antes-' . uniqid() . '@test.local',
            'password'   => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, (int) DB::table('admins')->where('id', $id)->value('solo_leads'));
        $this->assertFalse(Admin::findOrFail($id)->solo_leads, 'El cast de Admin tiene que devolver un booleano false.');
    }

    /**
     * El tilde se guarda desde Usuarios admin (alta y edición) y es opcional: un PUT sin la clave no lo cambia.
     *
     * @return void
     */
    public function test_el_tilde_se_guarda_y_es_opcional(): void
    {
        $quien_edita = $this->crear_admin();
        $objetivo    = $this->crear_admin();

        // Edición: con la clave la guarda.
        $this->pedir($quien_edita, 'PUT', '/api/admin/admin-user/' . $objetivo->id, ['solo_leads' => true])
            ->assertStatus(200);
        $this->assertTrue($objetivo->fresh()->solo_leads, 'PUT admin-user con solo_leads: true tiene que guardarlo.');

        // Edición sin la clave: no la toca.
        $this->pedir($quien_edita, 'PUT', '/api/admin/admin-user/' . $objetivo->id, ['name' => 'Otro nombre'])
            ->assertStatus(200);
        $objetivo->refresh();
        $this->assertSame('Otro nombre', $objetivo->name);
        $this->assertTrue($objetivo->solo_leads, 'Un PUT sin la clave no puede cambiar el flag.');

        // Y se puede apagar.
        $this->pedir($quien_edita, 'PUT', '/api/admin/admin-user/' . $objetivo->id, ['solo_leads' => false])
            ->assertStatus(200);
        $this->assertFalse($objetivo->fresh()->solo_leads);

        // Alta con el tilde.
        $email = 'nuevo-' . uniqid() . '@test.local';
        $this->pedir($quien_edita, 'POST', '/api/admin/admin-user', [
            'name'       => 'Juan',
            'email'      => $email,
            'password'   => 'clave-de-prueba-1',
            'solo_leads' => true,
        ])->assertStatus(201);
        $this->assertTrue(Admin::where('email', $email)->firstOrFail()->solo_leads, 'El alta con el tilde tiene que guardarlo.');

        // Alta sin la clave: nace en false.
        $email_sin_tilde = 'nuevo-sin-' . uniqid() . '@test.local';
        $this->pedir($quien_edita, 'POST', '/api/admin/admin-user', [
            'name'     => 'Pedro',
            'email'    => $email_sin_tilde,
            'password' => 'clave-de-prueba-1',
        ])->assertStatus(201);
        $this->assertFalse(Admin::where('email', $email_sin_tilde)->firstOrFail()->solo_leads);
    }

    /**
     * `/me` expone el flag: true para el restringido, false para uno normal.
     *
     * @return void
     */
    public function test_me_devuelve_solo_leads(): void
    {
        $restringido = $this->crear_admin(true);
        $normal      = $this->crear_admin(false);

        $this->pedir($restringido, 'GET', '/api/admin/me')
            ->assertStatus(200)
            ->assertJsonPath('admin.solo_leads', true);

        $this->pedir($normal, 'GET', '/api/admin/me')
            ->assertStatus(200)
            ->assertJsonPath('admin.solo_leads', false);
    }

    /**
     * La respuesta del login también trae el flag (contrato de §2.6: el SPA decide el menú con eso).
     *
     * @return void
     */
    public function test_el_login_devuelve_solo_leads(): void
    {
        $restringido = $this->crear_admin(true);

        $this->postJson('/api/admin/login', ['email' => $restringido->email, 'password' => 'secret'])
            ->assertStatus(200)
            ->assertJsonPath('admin.solo_leads', true);
    }

    /**
     * El catálogo de propiedades de Admin (lo que dibuja Usuarios admin) incluye «Sólo Leads».
     *
     * @return void
     */
    public function test_el_catalogo_de_admin_incluye_solo_leads(): void
    {
        $encontradas = array_values(array_filter(AdminProperties::all(), function ($propiedad) {
            return ($propiedad['key'] ?? null) === 'solo_leads';
        }));

        $this->assertCount(1, $encontradas, 'AdminProperties tiene que declarar la propiedad solo_leads.');
        $this->assertSame('Sólo Leads', $encontradas[0]['text']);
        $this->assertSame('boolean', $encontradas[0]['type']);
        $this->assertFalse($encontradas[0]['value']);
        $this->assertTrue($encontradas[0]['show']);
        $this->assertNotEmpty($encontradas[0]['description'] ?? '', 'El tilde tiene que explicar qué hace.');
    }

    // ------------------------------------------------------------------------------------------
    // CA35 — el backend lo hace cumplir
    // ------------------------------------------------------------------------------------------

    /**
     * Rutas de la lista permitida: método, URL con marcadores y cuerpo. Las 200 que pide el plan (§2.7).
     *
     * @return array<string, array>
     */
    public function rutas_permitidas_provider(): array
    {
        return [
            'GET me'                           => ['GET', '/api/admin/me', []],
            'GET version'                      => ['GET', '/api/admin/version', []],
            'GET meta/lead'                    => ['GET', '/api/admin/meta/lead', []],
            'GET lead'                         => ['GET', '/api/admin/lead', []],
            'GET lead/status-cards'            => ['GET', '/api/admin/lead/status-cards', []],
            'GET lead/chats'                   => ['GET', '/api/admin/lead/chats', []],
            'GET lead/chats/{id}'              => ['GET', '/api/admin/lead/chats/{lead}', []],
            'GET lead/unread-badges'           => ['GET', '/api/admin/lead/unread-badges', []],
            'GET lead/{id}'                    => ['GET', '/api/admin/lead/{lead}', []],
            'PUT lead/{id} (estado y nota)'    => ['PUT', '/api/admin/lead/{lead}', ['status' => 'en_pausa', 'notes' => 'Nota nueva']],
            'POST lead/{id}/toggle-notify'     => ['POST', '/api/admin/lead/{lead}/toggle-notify-messages', []],
            'GET settings/lead-whatsapp-onb.'  => ['GET', '/api/admin/settings/lead-whatsapp-onboarding', []],
            'GET settings/lead-demo'           => ['GET', '/api/admin/settings/lead-demo', []],
            'GET task-notification/pending'    => ['GET', '/api/admin/task-notification/pending', []],
            'POST task-notification/seen-all'  => ['POST', '/api/admin/task-notification/seen-all', []],
            'POST task-notification/{id}/seen' => ['POST', '/api/admin/task-notification/{aviso}/seen', []],
            'GET push/vapid-public-key'        => ['GET', '/api/admin/push/vapid-public-key', []],
            'POST push/subscribe'              => ['POST', '/api/admin/push/subscribe', ['endpoint' => 'https://push.test/abc', 'keys' => ['p256dh' => 'k1', 'auth' => 'k2']]],
            'POST push/subscription-status'    => ['POST', '/api/admin/push/subscription-status', ['endpoint' => 'https://push.test/abc']],
            'POST push/unsubscribe'            => ['POST', '/api/admin/push/unsubscribe', ['endpoint' => 'https://push.test/abc']],
            'POST logout'                      => ['POST', '/api/admin/logout', []],
        ];
    }

    /**
     * El admin `solo_leads` obtiene 200 en cada ruta de la lista permitida de Leads.
     *
     * @dataProvider rutas_permitidas_provider
     *
     * @param string               $metodo  Verbo HTTP.
     * @param string               $uri     URL con marcadores.
     * @param array<string, mixed> $payload Cuerpo de la petición.
     *
     * @return void
     */
    public function test_el_perfil_recibe_200_en_las_rutas_permitidas(string $metodo, string $uri, array $payload): void
    {
        $restringido = $this->crear_admin(true);
        $lead        = $this->crear_lead();
        $aviso       = $this->crear_aviso_de_tarea($restringido, $lead);

        $url = $this->resolver_uri($uri, ['lead' => $lead->id, 'aviso' => $aviso->id]);

        $this->pedir($restringido, $metodo, $url, $payload)->assertStatus(200);
    }

    /**
     * Rutas que el perfil NO puede usar: una muestra representativa de cada familia (lo que le escribe al
     * lead, lo que cambia estado compartido del chat, las solapas del modal, y todo el resto del admin).
     * Incluye métodos distintos sobre una URL que sí está permitida con otro método (el método cuenta).
     *
     * @return array<string, array>
     */
    public function rutas_prohibidas_provider(): array
    {
        return [
            // Resto del admin (CA35 explícito).
            'GET meta/client'                    => ['GET', '/api/admin/meta/client', []],
            'GET client'                         => ['GET', '/api/admin/client', []],
            'GET task'                           => ['GET', '/api/admin/task', []],
            'PUT me'                             => ['PUT', '/api/admin/me', ['name' => 'Hackeado']],
            'POST admin-user'                    => ['POST', '/api/admin/admin-user', ['name' => 'X', 'email' => 'x-prohibido@test.local', 'password' => 'clave-de-prueba-1']],
            'GET admin-user'                     => ['GET', '/api/admin/admin-user', []],
            'DELETE admin-user/{id}'             => ['DELETE', '/api/admin/admin-user/{otro_admin}', []],
            'GET support-ticket/unread-badges'   => ['GET', '/api/admin/support-ticket/unread-badges', []],
            'PUT settings/lead-demo'             => ['PUT', '/api/admin/settings/lead-demo', []],
            'PUT settings/lead-whatsapp-onb.'    => ['PUT', '/api/admin/settings/lead-whatsapp-onboarding', []],
            // El método cuenta: la misma URL permitida con otro verbo.
            'POST version'                       => ['POST', '/api/admin/version', []],
            'DELETE lead/{id}'                   => ['DELETE', '/api/admin/lead/{lead}', []],
            'POST lead (alta)'                   => ['POST', '/api/admin/lead', ['contact_name' => 'Nuevo']],
            // Escribirle al lead (WA).
            'POST send-direct-message'           => ['POST', '/api/admin/lead/{lead}/send-direct-message', ['content' => 'Hola']],
            'POST send-direct-image'             => ['POST', '/api/admin/lead/{lead}/send-direct-image', []],
            'POST send-direct-document'          => ['POST', '/api/admin/lead/{lead}/send-direct-document', []],
            'POST send-direct-audio'             => ['POST', '/api/admin/lead/{lead}/send-direct-audio', []],
            'POST send-template'                 => ['POST', '/api/admin/lead/{lead}/send-template', []],
            'PUT lead-message/{id}/approve'      => ['PUT', '/api/admin/lead-message/{mensaje}/approve', []],
            'PUT lead-message/approve-with-edit' => ['PUT', '/api/admin/lead-message/{mensaje}/approve-with-edit', ['edited_content' => 'Editado']],
            'PUT lead-message/approve-w-actions' => ['PUT', '/api/admin/lead-message/{mensaje}/approve-with-actions', []],
            'PUT lead-message/{id}/reaction'     => ['PUT', '/api/admin/lead-message/{mensaje}/reaction', ['emoji' => '👍']],
            // IA, programados, demo y promoción.
            'POST scheduled-messages'            => ['POST', '/api/admin/lead/{lead}/scheduled-messages', []],
            'POST request-ai-suggestion'         => ['POST', '/api/admin/lead/{lead}/request-ai-suggestion', []],
            'POST offer-demo'                    => ['POST', '/api/admin/lead/{lead}/offer-demo', []],
            'POST toggle-claude-auto-reply'      => ['POST', '/api/admin/lead/{lead}/toggle-claude-auto-reply', []],
            'POST simulate-inbound'              => ['POST', '/api/admin/lead/{lead}/simulate-inbound', []],
            'POST promote-to-client'             => ['POST', '/api/admin/lead/{lead}/promote-to-client', []],
            // Marcas que cambian el estado compartido del chat de los setters.
            'POST mark-whatsapp-messages-read'   => ['POST', '/api/admin/lead/{lead}/mark-whatsapp-messages-read', []],
            'POST marcar-respondido'             => ['POST', '/api/admin/lead/{lead}/marcar-respondido', []],
            'POST toggle-pinned'                 => ['POST', '/api/admin/lead/{lead}/toggle-pinned', []],
            'POST toggle-manual-unread'          => ['POST', '/api/admin/lead/{lead}/toggle-manual-unread', []],
            'POST mark-followup-suggestion-seen' => ['POST', '/api/admin/lead/{lead}/mark-followup-suggestion-seen', []],
        ];
    }

    /**
     * El perfil recibe 403 con `{"code": "solo_leads"}` en todo lo que no está en la lista permitida.
     *
     * @dataProvider rutas_prohibidas_provider
     *
     * @param string               $metodo  Verbo HTTP.
     * @param string               $uri     URL con marcadores.
     * @param array<string, mixed> $payload Cuerpo de la petición.
     *
     * @return void
     */
    public function test_el_perfil_recibe_403_fuera_de_la_lista_permitida(string $metodo, string $uri, array $payload): void
    {
        $restringido = $this->crear_admin(true);
        $otro_admin  = $this->crear_admin();
        $lead        = $this->crear_lead();
        $mensaje     = $this->crear_mensaje_sugerido($lead);

        $url = $this->resolver_uri($uri, ['lead' => $lead->id, 'mensaje' => $mensaje->id, 'otro_admin' => $otro_admin->id]);

        $this->assertDenegadoPorElPerfil($this->pedir($restringido, $metodo, $url, $payload), $metodo . ' ' . $url);

        // Nada salió por WhatsApp ni se borró lo que había.
        $this->assertSame(0, $this->espia_whatsapp->envios, 'Una ruta prohibida no puede llegar a WhatsApp.');
        Http::assertNothingSent();
        $this->assertNotNull(Lead::find($lead->id), 'Una ruta prohibida no puede borrar el lead.');
        $this->assertNotNull(Admin::find($otro_admin->id), 'Una ruta prohibida no puede borrar admins.');
    }

    /**
     * `meta/{model}` se restringe por parámetro: `lead` sí, cualquier otro modelo no.
     *
     * @return void
     */
    public function test_meta_solo_se_permite_para_lead(): void
    {
        $restringido = $this->crear_admin(true);

        $this->pedir($restringido, 'GET', '/api/admin/meta/lead')->assertStatus(200);

        foreach (['client', 'admin_user', 'task'] as $modelo) {
            $this->assertDenegadoPorElPerfil(
                $this->pedir($restringido, 'GET', '/api/admin/meta/' . $modelo),
                'GET meta/' . $modelo
            );
        }
    }

    /**
     * Escribirle al lead desde el panel queda cerrado y sin efectos: el espía de WhatsApp en cero, ningún
     * mensaje nuevo en el hilo.
     *
     * @return void
     */
    public function test_send_direct_message_del_perfil_no_llama_a_whatsapp(): void
    {
        $restringido = $this->crear_admin(true);
        $lead        = $this->crear_lead();
        $mensajes    = LeadMessage::where('lead_id', $lead->id)->count();

        $this->assertDenegadoPorElPerfil(
            $this->pedir($restringido, 'POST', '/api/admin/lead/' . $lead->id . '/send-direct-message', ['content' => 'Hola, soy Juan']),
            'POST send-direct-message'
        );

        $this->assertSame(0, $this->espia_whatsapp->envios, 'WhatsappSendService no se puede invocar.');
        Http::assertNothingSent();
        $this->assertSame($mensajes, LeadMessage::where('lead_id', $lead->id)->count(), 'No puede quedar ningún mensaje nuevo en el hilo.');
    }

    /**
     * Aprobar una sugerencia queda cerrado: el mensaje sigue sugerido y no sale nada.
     *
     * @return void
     */
    public function test_aprobar_una_sugerencia_del_perfil_no_la_envia(): void
    {
        $restringido = $this->crear_admin(true);
        $lead        = $this->crear_lead();
        $mensaje     = $this->crear_mensaje_sugerido($lead);

        $this->assertDenegadoPorElPerfil(
            $this->pedir($restringido, 'PUT', '/api/admin/lead-message/' . $mensaje->id . '/approve'),
            'PUT lead-message/{id}/approve'
        );

        $this->assertSame('sugerido', $mensaje->fresh()->status, 'La sugerencia tiene que seguir en estado sugerido.');
        $this->assertSame(0, $this->espia_whatsapp->envios);
        Http::assertNothingSent();
    }

    /**
     * Abrir el chat no marca nada como leído para los setters: `mark-whatsapp-messages-read` queda cerrada
     * y no inserta filas en `lead_message_reads`.
     *
     * @return void
     */
    public function test_marcar_leido_del_perfil_no_deja_marcas(): void
    {
        $restringido = $this->crear_admin(true);
        $lead        = $this->crear_lead();
        $mensaje     = $this->crear_mensaje_sugerido($lead);

        $this->assertDenegadoPorElPerfil(
            $this->pedir($restringido, 'POST', '/api/admin/lead/' . $lead->id . '/mark-whatsapp-messages-read'),
            'POST mark-whatsapp-messages-read'
        );

        $this->assertSame(
            0,
            LeadMessageRead::where('lead_message_id', $mensaje->id)->count(),
            'No puede quedar ninguna fila de lectura (read_at tiene que seguir nulo para todos).'
        );
    }

    /**
     * `PUT me` y las altas de admins no producen efectos cuando las intenta el perfil.
     *
     * @return void
     */
    public function test_las_escrituras_de_cuenta_del_perfil_no_tienen_efectos(): void
    {
        $restringido = $this->crear_admin(true);
        $email       = 'x-prohibido-' . uniqid() . '@test.local';

        $this->assertDenegadoPorElPerfil(
            $this->pedir($restringido, 'PUT', '/api/admin/me', ['name' => 'Hackeado']),
            'PUT me'
        );
        $this->assertSame('Admin de prueba', $restringido->fresh()->name);

        $this->assertDenegadoPorElPerfil(
            $this->pedir($restringido, 'POST', '/api/admin/admin-user', ['name' => 'X', 'email' => $email, 'password' => 'clave-de-prueba-1']),
            'POST admin-user'
        );
        $this->assertSame(0, Admin::where('email', $email)->count(), 'No puede crear admins.');

        // Tampoco puede quitarse el perfil a sí mismo por la API de usuarios.
        $this->assertDenegadoPorElPerfil(
            $this->pedir($restringido, 'PUT', '/api/admin/admin-user/' . $restringido->id, ['solo_leads' => false]),
            'PUT admin-user/{id}'
        );
        $this->assertTrue($restringido->fresh()->solo_leads, 'El perfil no se puede apagar solo.');
    }

    /**
     * Admin normal (sin el flag): las mismas rutas responden como hoy. `GET client` da 200 y
     * `send-direct-message` llega al servicio de WhatsApp. GUARDA DE REGRESIÓN: verde hoy, tiene que seguir
     * verde cuando el middleware cuelgue de todas las rutas autenticadas.
     *
     * @return void
     */
    public function test_un_admin_normal_sigue_igual(): void
    {
        $normal   = $this->crear_admin();
        $lead     = $this->crear_lead();
        $mensaje  = $this->crear_mensaje_sugerido($lead);

        $this->pedir($normal, 'GET', '/api/admin/client')->assertStatus(200);
        $this->pedir($normal, 'GET', '/api/admin/meta/client')->assertStatus(200);
        $this->pedir($normal, 'GET', '/api/admin/task')->assertStatus(200);
        $this->pedir($normal, 'GET', '/api/admin/lead/chats')->assertStatus(200);

        $this->pedir($normal, 'POST', '/api/admin/lead/' . $lead->id . '/mark-whatsapp-messages-read')->assertStatus(200);
        $this->assertSame(1, LeadMessageRead::where('lead_message_id', $mensaje->id)->count(), 'El admin normal sí deja la marca de lectura.');

        $this->pedir($normal, 'POST', '/api/admin/lead/' . $lead->id . '/send-direct-message', ['content' => 'Hola desde el panel'])
            ->assertStatus(200);
        $this->assertSame(1, $this->espia_whatsapp->envios, 'El admin normal llega al servicio de WhatsApp.');
    }

    /**
     * Admin con el flag explícitamente en false: igual que el normal (el middleware vuelve en la primera línea).
     *
     * @return void
     */
    public function test_un_admin_con_el_flag_en_false_sigue_igual(): void
    {
        $normal = $this->crear_admin(false);
        $lead   = $this->crear_lead();

        $this->pedir($normal, 'GET', '/api/admin/client')->assertStatus(200);
        $this->pedir($normal, 'GET', '/api/admin/meta/client')->assertStatus(200);
        $this->pedir($normal, 'GET', '/api/admin/task')->assertStatus(200);

        $this->pedir($normal, 'POST', '/api/admin/lead/' . $lead->id . '/send-direct-message', ['content' => 'Hola desde el panel'])
            ->assertStatus(200);
        $this->assertSame(1, $this->espia_whatsapp->envios);

        // Y el PUT de un lead lo guarda entero, sin filtrar nada.
        $this->pedir($normal, 'PUT', '/api/admin/lead/' . $lead->id, ['contact_name' => 'Nombre nuevo', 'notes' => 'Otra nota'])
            ->assertStatus(200);
        $lead->refresh();
        $this->assertSame('Nombre nuevo', $lead->contact_name);
        $this->assertSame('Otra nota', $lead->notes);
    }

    /**
     * Sin sesión sigue siendo 401 (el middleware del perfil va después de autenticar y no lo enmascara).
     * GUARDA DE REGRESIÓN: verde hoy.
     *
     * @return void
     */
    public function test_sin_sesion_sigue_siendo_401(): void
    {
        $this->getJson('/api/admin/lead/chats')->assertStatus(401);
        $this->getJson('/api/admin/client')->assertStatus(401);
    }

    // ------------------------------------------------------------------------------------------
    // CA36 — estado y nota
    // ------------------------------------------------------------------------------------------

    /**
     * El perfil guarda estado y nota y el servidor descarta el resto del cuerpo.
     *
     * @return void
     */
    public function test_put_lead_solo_guarda_estado_y_nota(): void
    {
        $restringido = $this->crear_admin(true);
        $lead        = $this->crear_lead('contactado');
        $telefono    = $lead->phone;

        $this->pedir($restringido, 'PUT', '/api/admin/lead/' . $lead->id, [
            'status'       => 'en_pausa',
            'notes'        => 'Llamé, vuelve el lunes',
            'name'         => 'Otro',
            'contact_name' => 'Otro',
            'company_name' => 'Empresa pisada',
            'phone'        => '000',
        ])->assertStatus(200);

        $lead->refresh();
        $this->assertSame('en_pausa', $lead->status, 'El estado tiene que cambiar.');
        $this->assertSame('Llamé, vuelve el lunes', $lead->notes, 'La nota tiene que cambiar.');
        $this->assertSame('Contacto original', $lead->contact_name, 'contact_name no lo puede cambiar el perfil.');
        $this->assertSame('Empresa original', $lead->company_name, 'company_name no lo puede cambiar el perfil.');
        $this->assertSame($telefono, $lead->phone, 'phone no lo puede cambiar el perfil.');
    }

    /**
     * Un cuerpo con campos que el perfil no puede tocar responde 200 y no cambia nada de eso (el servidor
     * descarta, no rechaza). Además, el mismo cuerpo de un admin normal sí los cambia: el filtro es lo que
     * protege, no que el endpoint ignore esos campos.
     *
     * @return void
     */
    public function test_put_lead_con_otros_campos_descarta_sin_rechazar(): void
    {
        $restringido = $this->crear_admin(true);
        $normal      = $this->crear_admin();
        $lead_a      = $this->crear_lead();
        $lead_b      = $this->crear_lead();
        $cuerpo      = ['contact_name' => 'Pisado', 'company_name' => 'Pisada'];

        $this->pedir($restringido, 'PUT', '/api/admin/lead/' . $lead_a->id, $cuerpo)->assertStatus(200);
        $lead_a->refresh();
        $this->assertSame('Contacto original', $lead_a->contact_name);
        $this->assertSame('Empresa original', $lead_a->company_name);

        $this->pedir($normal, 'PUT', '/api/admin/lead/' . $lead_b->id, $cuerpo)->assertStatus(200);
        $lead_b->refresh();
        $this->assertSame('Pisado', $lead_b->contact_name, 'El admin normal sí guarda todos los campos.');
    }

    // ------------------------------------------------------------------------------------------
    // CA38 — panel Blade y broadcasting
    // ------------------------------------------------------------------------------------------

    /**
     * Rutas del grupo `auth` de `routes/web.php` (panel Blade por sesión).
     *
     * @return array<string, array>
     */
    public function rutas_del_panel_blade_provider(): array
    {
        return [
            'GET /versions'     => ['GET', '/versions'],
            'GET /clients'      => ['GET', '/clients'],
            'GET /leads'        => ['GET', '/leads'],
            'GET /versions/create' => ['GET', '/versions/create'],
        ];
    }

    /**
     * Un admin `solo_leads` con sesión web recibe 403 en el panel Blade; el mismo pedido de un admin normal
     * sí entra (control).
     *
     * @dataProvider rutas_del_panel_blade_provider
     *
     * @param string $metodo Verbo HTTP.
     * @param string $uri    URL del panel.
     *
     * @return void
     */
    public function test_el_panel_blade_da_403(string $metodo, string $uri): void
    {
        $normal      = $this->crear_admin();
        $restringido = $this->crear_admin(true);

        $this->actingAs($normal, 'web')->call($metodo, $uri)->assertStatus(200);

        $this->actingAs($restringido, 'web')->call($metodo, $uri)->assertStatus(403);
    }

    /**
     * En el panel Blade sólo se deja salir: `POST /logout` no da 403 para el perfil.
     *
     * @return void
     */
    public function test_el_panel_blade_deja_cerrar_sesion(): void
    {
        $restringido = $this->crear_admin(true);

        $respuesta = $this->actingAs($restringido, 'web')->post('/logout');

        $this->assertNotSame(403, $respuesta->getStatusCode(), 'POST /logout del panel tiene que seguir permitido.');
    }

    /**
     * `POST /broadcasting/auth` para su canal privado `private-admin.{su id}` da 200 al perfil.
     *
     * @return void
     */
    public function test_broadcasting_auth_de_su_canal(): void
    {
        $restringido = $this->crear_admin(true);

        $this->pedir($restringido, 'POST', '/broadcasting/auth', [
            'socket_id'    => '1234.5678',
            'channel_name' => 'private-admin.' . $restringido->id,
        ])->assertStatus(200);
    }
}
