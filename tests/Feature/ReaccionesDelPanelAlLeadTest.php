<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La reacción con emoji que un admin aplica desde el panel tiene que llegar al WhatsApp del lead.
 *
 * Es el camino de IDA (admin → lead). La vuelta (lead → admin) ya existe, vive en
 * `LeadWhatsappReactionService` y en las columnas `lead_reaction_*`, y no se toca acá.
 *
 * Lo que estos tests protegen no es el camino feliz —que es un POST— sino las decisiones que se
 * pueden perder en el primer refactor que pase por al lado:
 *
 * 1. Que lo que viaja a Meta sea un `type: reaction` sobre el wamid del mensaje objetivo, y no un
 *    texto con un emoji adentro.
 * 2. Que el emoji vacío sea el "quitar la reacción" y viaje como `''` —no ausente, no null—, que
 *    es literalmente como la Cloud API pide revocarla.
 * 3. Que NADA se persista si el envío no salió. Una reacción pintada en la burbuja dice que el
 *    lead la vio; si Meta la rechazó (ventana de 24hs cerrada, mensaje viejo), el lead no vio nada.
 * 4. Que los mensajes que nunca salieron por WhatsApp (sugerencias, eventos internos del hilo) ni
 *    siquiera lleguen a la red.
 * 5. Que la reacción del panel y la del lead convivan en la misma fila sin pisarse, y que el
 *    estado de entrega de NUESTRA reacción no le toque los tildes al mensaje original.
 *
 * Se le pega al endpoint real, con base y ruteo de verdad; lo único falso es la red.
 */
class ReaccionesDelPanelAlLeadTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Secreto del webhook con el que se firman los payloads de las pruebas.
     */
    private const SECRETO = 'secreto-de-prueba-de-reacciones';

    /**
     * Emoji de pulgar arriba, con escape explícito para que ningún editor se lo coma.
     */
    private const PULGAR = "\u{1F44D}";

    /**
     * Corazón con selector de variación: la forma canónica de la paleta del backend.
     */
    private const CORAZON = "\u{2764}\u{FE0F}";

    /**
     * Deja una configuración de WhatsApp activa y REAL (no test_mode).
     *
     * 🔴 El `Http::fake()` NO va acá. Los stubs se resuelven por el primero que matchea, así que un
     * catch-all registrado en el setUp le gana a cualquier respuesta que el test registre después y
     * todos los envíos vuelven con body vacío. Cada test arma el suyo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        WhatsappConfig::query()->update(['is_active' => false]);

        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = self::SECRETO;
        $config->is_active       = true;
        $config->test_mode       = false;
        $config->save();
    }

    /**
     * Admin que reacciona desde el panel.
     *
     * @param string $email Email único del admin.
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Lead con teléfono cargado: sin teléfono no se intenta ningún envío.
     *
     * @param string $nombre Nombre de contacto.
     *
     * @return Lead
     */
    private function crear_lead(string $nombre): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = $nombre;
        $lead->company_name = 'Empresa de ' . $nombre;
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->status       = 'contactado';
        $lead->save();

        return $lead;
    }

    /**
     * Mensaje del hilo al que se le va a reaccionar.
     *
     * @param Lead                 $lead   Dueño del hilo.
     * @param array<string, mixed> $campos Campos a pisar sobre los valores por defecto.
     *
     * @return LeadMessage
     */
    private function crear_mensaje(Lead $lead, array $campos = []): LeadMessage
    {
        $base = [
            'lead_id'             => $lead->id,
            'sender'              => 'lead',
            'content'             => 'Hola, quiero ver el sistema',
            'status'              => 'enviado',
            'whatsapp_message_id' => 'wamid.OBJETIVO' . random_int(100000, 999999),
            'sent_at'             => now(),
        ];

        return LeadMessage::create(array_merge($base, $campos));
    }

    /**
     * Pega al endpoint real de reacción, autenticado como el admin dado.
     *
     * @param Admin       $admin   Quien reacciona.
     * @param LeadMessage $message Mensaje objetivo.
     * @param string      $emoji   Emoji a aplicar ('' quita la reacción).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function reaccionar(Admin $admin, LeadMessage $message, string $emoji)
    {
        return $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/lead-message/' . $message->id . '/reaction', ['emoji' => $emoji]);
    }

    /**
     * Devuelve los cuerpos de las peticiones de tipo `reaction` que efectivamente viajaron.
     *
     * Se filtra por tipo en vez de contar todas las peticiones porque el camino de fallo puede
     * disparar, además, la notificación a admins (que es otro POST a la misma API).
     *
     * @return array<int, array<string, mixed>>
     */
    private function reacciones_enviadas(): array
    {
        $cuerpos = [];

        foreach (Http::recorded() as $par) {
            $request = $par[0];
            $data = $request->data();
            if (is_array($data) && isset($data['type']) && $data['type'] === 'reaction') {
                $cuerpos[] = $data;
            }
        }

        return $cuerpos;
    }

    /**
     * El camino crítico completo: el emoji sale a Meta como reacción sobre el wamid correcto y
     * queda persistido con el admin que lo apretó.
     *
     * @return void
     */
    public function test_reaccionar_le_manda_a_meta_el_emoji_sobre_el_wamid_del_mensaje()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REACCION1']]], 200)]);

        $admin   = $this->crear_admin('reacciones-feliz@test.com');
        $lead    = $this->crear_lead('Ana');
        $message = $this->crear_mensaje($lead);

        $this->reaccionar($admin, $message, self::PULGAR)->assertStatus(200);

        Http::assertSent(function ($request) use ($message) {
            $body = $request->data();

            return strpos((string) $request->url(), '/1234567890/messages') !== false
                && isset($body['type']) && $body['type'] === 'reaction'
                && isset($body['reaction']['message_id']) && $body['reaction']['message_id'] === $message->whatsapp_message_id
                && isset($body['reaction']['emoji']) && $body['reaction']['emoji'] === self::PULGAR;
        });

        $message->refresh();
        $this->assertSame(self::PULGAR, (string) $message->admin_reaction_emoji);
        $this->assertNotNull($message->admin_reaction_at, 'La reacción tiene que quedar fechada.');
        $this->assertSame('wamid.REACCION1', (string) $message->admin_reaction_whatsapp_message_id);
        $this->assertSame((int) $admin->id, (int) $message->admin_reaction_by_admin_id);
    }

    /**
     * Reemplazar una reacción por otra no necesita ningún camino especial: Meta pisa la anterior
     * sobre el mismo message_id y acá el update pisa las columnas. Queda una sola, la última.
     *
     * @return void
     */
    public function test_reaccionar_de_nuevo_reemplaza_la_reaccion_anterior()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REACCION2']]], 200)]);

        $admin   = $this->crear_admin('reacciones-reemplazo@test.com');
        $lead    = $this->crear_lead('Bruno');
        $message = $this->crear_mensaje($lead);

        $this->reaccionar($admin, $message, self::PULGAR)->assertStatus(200);
        $this->reaccionar($admin, $message, self::CORAZON)->assertStatus(200);

        $enviadas = $this->reacciones_enviadas();
        $this->assertCount(2, $enviadas, 'Cada reacción es su propio POST a Meta.');
        $this->assertSame(self::PULGAR, $enviadas[0]['reaction']['emoji']);
        $this->assertSame(self::CORAZON, $enviadas[1]['reaction']['emoji']);

        $message->refresh();
        $this->assertSame(self::CORAZON, (string) $message->admin_reaction_emoji);
    }

    /**
     * El emoji vacío es el "quitar": viaja como `''` (no ausente, no null) y deja las cuatro
     * columnas en null, wamid incluido.
     *
     * @return void
     */
    public function test_quitar_la_reaccion_manda_el_emoji_vacio_y_deja_las_columnas_en_null()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REACCION3']]], 200)]);

        $admin   = $this->crear_admin('reacciones-quitar@test.com');
        $lead    = $this->crear_lead('Carla');
        $message = $this->crear_mensaje($lead);

        $this->reaccionar($admin, $message, self::PULGAR)->assertStatus(200);
        $this->reaccionar($admin, $message, '')->assertStatus(200);

        $enviadas = $this->reacciones_enviadas();
        $this->assertCount(2, $enviadas);
        $this->assertArrayHasKey('emoji', $enviadas[1]['reaction'], 'El emoji vacío tiene que viajar, no faltar.');
        $this->assertSame('', $enviadas[1]['reaction']['emoji']);

        $message->refresh();
        $this->assertNull($message->admin_reaction_emoji);
        $this->assertNull($message->admin_reaction_at);
        $this->assertNull($message->admin_reaction_whatsapp_message_id);
        $this->assertNull($message->admin_reaction_by_admin_id);
    }

    /**
     * Una sugerencia que nunca se envió no tiene wamid: no hay a qué engancharle la reacción.
     *
     * @return void
     */
    public function test_un_mensaje_que_nunca_salio_por_whatsapp_no_se_puede_reaccionar()
    {
        Http::fake();

        $admin   = $this->crear_admin('reacciones-sin-wamid@test.com');
        $lead    = $this->crear_lead('Dario');
        $message = $this->crear_mensaje($lead, [
            'sender'              => 'sistema',
            'status'              => 'sugerido',
            'whatsapp_message_id' => null,
        ]);

        $this->reaccionar($admin, $message, self::PULGAR)->assertStatus(422);

        Http::assertNothingSent();

        $message->refresh();
        $this->assertNull($message->admin_reaction_emoji);
        $this->assertNull($message->admin_reaction_whatsapp_message_id);
    }

    /**
     * Los eventos internos del hilo (cambios de estado, bloques de error) no salieron por WhatsApp.
     *
     * @return void
     */
    public function test_un_evento_de_sistema_no_se_puede_reaccionar()
    {
        Http::fake();

        $admin   = $this->crear_admin('reacciones-evento@test.com');
        $lead    = $this->crear_lead('Elena');
        $message = $this->crear_mensaje($lead, [
            'sender'          => 'sistema',
            'is_status_event' => true,
        ]);

        $this->reaccionar($admin, $message, self::PULGAR)->assertStatus(422);

        Http::assertNothingSent();

        $message->refresh();
        $this->assertNull($message->admin_reaction_emoji);
    }

    /**
     * La paleta la manda el backend: cualquier emoji que no esté en ella se rechaza antes de la red.
     *
     * @return void
     */
    public function test_un_emoji_fuera_de_la_paleta_se_rechaza_sin_salir_a_la_red()
    {
        Http::fake();

        $admin   = $this->crear_admin('reacciones-payaso@test.com');
        $lead    = $this->crear_lead('Fabian');
        $message = $this->crear_mensaje($lead);

        $this->reaccionar($admin, $message, "\u{1F921}")->assertStatus(422);

        Http::assertNothingSent();

        $message->refresh();
        $this->assertNull($message->admin_reaction_emoji);
    }

    /**
     * El corazón viaja del navegador con o sin U+FE0F según cómo se haya escrito el literal.
     * Se aceptan las dos formas, y lo que sale a Meta es siempre la canónica del backend.
     *
     * @return void
     */
    public function test_el_corazon_sin_selector_de_variacion_tambien_se_acepta()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REACCION4']]], 200)]);

        $admin   = $this->crear_admin('reacciones-corazon@test.com');
        $lead    = $this->crear_lead('Gaston');
        $message = $this->crear_mensaje($lead);

        $this->reaccionar($admin, $message, "\u{2764}")->assertStatus(200);

        $enviadas = $this->reacciones_enviadas();
        $this->assertCount(1, $enviadas);
        $this->assertSame(self::CORAZON, $enviadas[0]['reaction']['emoji'], 'A Meta va la forma canónica, no la que mandó el cliente.');

        $message->refresh();
        $this->assertSame(self::CORAZON, (string) $message->admin_reaction_emoji);
    }

    /**
     * Ventana de 24hs cerrada: Meta rechaza y no queda absolutamente nada guardado. Es la razón
     * principal por la que este endpoint persiste DESPUÉS de enviar y no antes.
     *
     * @return void
     */
    public function test_si_meta_rechaza_la_reaccion_no_queda_nada_guardado()
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'message' => 'Message failed to send because more than 24 hours have passed',
                'code'    => 131047,
            ],
        ], 400)]);

        $admin   = $this->crear_admin('reacciones-ventana@test.com');
        $lead    = $this->crear_lead('Hernan');
        $message = $this->crear_mensaje($lead);

        $this->reaccionar($admin, $message, self::PULGAR)->assertStatus(422);

        /* El 422 tiene que venir del rechazo de Meta, no de una guarda previa: la reacción salió. */
        $this->assertNotEmpty($this->reacciones_enviadas(), 'Este caso mide el rechazo de Meta, así que el POST tuvo que salir.');

        $message->refresh();
        $this->assertNull($message->admin_reaction_emoji);
        $this->assertNull($message->admin_reaction_at);
        $this->assertNull($message->admin_reaction_whatsapp_message_id);
        $this->assertNull($message->admin_reaction_by_admin_id);
    }

    /**
     * Con el modo de prueba activo la reacción se guarda y se pinta, sin tocar la red.
     *
     * @return void
     */
    public function test_en_modo_de_prueba_la_reaccion_se_guarda_sin_llamar_a_la_api()
    {
        Http::fake();

        WhatsappConfig::query()->update(['test_mode' => true]);

        $admin   = $this->crear_admin('reacciones-modo-prueba@test.com');
        $lead    = $this->crear_lead('Ivana');
        $message = $this->crear_mensaje($lead, ['whatsapp_message_id' => 'test-' . uniqid()]);

        $this->reaccionar($admin, $message, self::PULGAR)->assertStatus(200);

        Http::assertNothingSent();

        $message->refresh();
        $this->assertSame(self::PULGAR, (string) $message->admin_reaction_emoji);
        $this->assertSame(0, strncmp((string) $message->admin_reaction_whatsapp_message_id, 'test-', 5));
    }

    /**
     * Son dos ejes distintos sobre la misma fila: reaccionar desde el panel no le toca un pelo a
     * la reacción que el lead ya había dejado.
     *
     * @return void
     */
    public function test_la_reaccion_del_lead_y_la_del_panel_conviven_en_el_mismo_mensaje()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REACCION5']]], 200)]);

        $admin   = $this->crear_admin('reacciones-conviven@test.com');
        $lead    = $this->crear_lead('Julian');
        $message = $this->crear_mensaje($lead, [
            'lead_reaction_emoji'                => self::CORAZON,
            'lead_reaction_at'                   => now()->subMinutes(5),
            'lead_reaction_whatsapp_message_id'  => 'wamid.REACCION.DEL.LEAD',
        ]);

        $this->reaccionar($admin, $message, self::PULGAR)->assertStatus(200);

        $message->refresh();
        $this->assertSame(self::CORAZON, (string) $message->lead_reaction_emoji, 'La reacción del lead no se toca.');
        $this->assertSame('wamid.REACCION.DEL.LEAD', (string) $message->lead_reaction_whatsapp_message_id);
        $this->assertSame(self::PULGAR, (string) $message->admin_reaction_emoji);
    }

    /**
     * El `delivered` de NUESTRA reacción tiene que caer en el vacío.
     *
     * El webhook correlaciona por `whatsapp_message_id`, y el wamid de la reacción vive en otra
     * columna: nunca matchea. 🔴 Que a nadie se le ocurra "arreglarlo" haciendo que el webhook
     * también busque en `admin_reaction_whatsapp_message_id`: eso pisaría el estado de entrega del
     * MENSAJE ORIGINAL con el de la reacción, y los tildes de la burbuja empezarían a mentir.
     *
     * @return void
     */
    public function test_un_estado_de_entrega_con_el_wamid_de_nuestra_reaccion_no_toca_el_mensaje()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REACCION6']]], 200)]);

        $admin   = $this->crear_admin('reacciones-webhook@test.com');
        $lead    = $this->crear_lead('Karina');
        $message = $this->crear_mensaje($lead, [
            'sender'                   => 'setter',
            'whatsapp_delivery_status' => 'enviado',
        ]);

        $this->reaccionar($admin, $message, self::PULGAR)->assertStatus(200);

        $payload = [
            'event'   => 'whatsapp.message.delivered',
            'message' => [
                'id'   => 'wamid.REACCION6',
                'from' => $lead->phone,
            ],
        ];
        $body = json_encode($payload);

        $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_KAPSO_SIGNATURE' => hash_hmac('sha256', $body, self::SECRETO),
        ], $body)->assertStatus(200);

        $message->refresh();
        $this->assertSame('enviado', (string) $message->whatsapp_delivery_status, 'El estado del mensaje original no se toca.');
        $this->assertNull($message->whatsapp_delivered_at);
    }
}
