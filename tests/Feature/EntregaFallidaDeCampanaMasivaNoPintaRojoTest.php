<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión campana-masiva-sin-rojo (22/10/2026): el rechazo de Meta de una plantilla de campaña
 * masiva no pinta rojo en el módulo de leads.
 *
 * Ese día salió `cc_reactivacion_agentes_ia` a 561 leads fríos. A los que Meta no les entregó el
 * mensaje, el webhook de Kapso les escribe `whatsapp_delivery_status = 'fallido'` (nunca un
 * `is_error`), y hasta este cambio eso era «Error de envío» en rojo: siete sobre 187 en la primera
 * tanda, sin ninguna acción posible para Lucas. Ahora la plantilla lleva
 * `followup_templates.no_marca_error_de_entrega = 1` y
 * {@see Lead::apply_condicion_entrega_fallida_que_cuenta()} deja afuera esos rechazos.
 *
 * Lo que se fija acá:
 *   1. Con el flag en 1 el fallido no cuenta en ningún lado: badge `failed_send_count`, `atencion`
 *      de la lista de chats, `solo_atencion=1` y la tarjeta (`requiereRevision(true)`).
 *   2. Con el flag en 0 sigue en rojo, igual que antes.
 *   3. Un saliente fallido sin plantilla (texto libre) sigue en rojo.
 *   4. Un `is_error` posterior sigue en rojo aunque haya un fallido de campaña antes: el flag solo
 *      apaga la entrega fallida, no el error del sistema.
 *   5. `POST claude/templates/meta` acepta el campo y lo muestra en el dry_run; sin el campo, false.
 *   6. `GET claude/templates/meta` devuelve el campo en cada plantilla.
 *
 * Los contadores no se recalculan a mano: se arman hilos reales y se mira qué dice la API.
 */
class EntregaFallidaDeCampanaMasivaNoPintaRojoTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del bloque claude/*. */
    const CLAVE = 'clave-de-prueba-campana-masiva';

    /** @var Admin */
    private $admin;

    /**
     * Deja la base sin leads (la lista y la tarjeta se miden sobre la tabla entera) y arma el admin
     * autenticado y la clave del bloque claude/*. Todo adentro de la transacción del test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);

        LeadMessage::query()->delete();
        Lead::query()->delete();

        $this->admin = Admin::create([
            'name'     => 'Setter de prueba',
            'email'    => 'campana-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * Plantilla registrada, con o sin el flag de campaña masiva.
     *
     * @param bool $no_marca_error_de_entrega
     *
     * @return FollowupTemplate
     */
    private function crear_plantilla(bool $no_marca_error_de_entrega): FollowupTemplate
    {
        return FollowupTemplate::create([
            'template_name'             => 'cc_test_campana_' . random_int(100000, 999999),
            'estado'                    => 'manual_prueba',
            'dia_numero'                => 1,
            'body_template'             => 'Hola {{1}}! Te escribo por los agentes de IA. ¿Lo vemos?',
            'language_code'             => 'es_AR',
            'activa'                    => true,
            'meta_status'               => 'APPROVED',
            'no_marca_error_de_entrega' => $no_marca_error_de_entrega,
        ]);
    }

    /**
     * Lead mínimo.
     *
     * @param string $nombre
     *
     * @return Lead
     */
    private function crear_lead(string $nombre): Lead
    {
        return Lead::create([
            'contact_name' => $nombre,
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => 'calificado',
        ]);
    }

    /**
     * Mensaje del hilo. Por defecto: entrante del lead, recibido ahora.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $campos
     *
     * @return LeadMessage
     */
    private function crear_mensaje(Lead $lead, array $campos = []): LeadMessage
    {
        $base = [
            'lead_id'         => $lead->id,
            'sender'          => 'lead',
            'content'         => 'Hola, quiero ver el sistema',
            'status'          => 'enviado',
            'kind'            => 'text',
            'is_status_event' => false,
            'is_error'        => false,
            'sent_at'         => now(),
        ];

        return LeadMessage::create(array_merge($base, $campos));
    }

    /**
     * Historia previa de un lead frío: escribió hace semanas y se le contestó (le llegó). Así el
     * hilo no queda amarillo por un mensaje suyo sin responder y lo único que puede pintar es el
     * saliente fallido.
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function crear_historia_contestada(Lead $lead): void
    {
        $this->crear_mensaje($lead, ['sent_at' => now()->subWeeks(3)]);
        $this->crear_mensaje($lead, [
            'sender'              => 'setter',
            'content'             => 'Te paso los planes',
            'whatsapp_message_id' => 'wamid.' . strtoupper(bin2hex(random_bytes(8))),
            'sent_at'             => now()->subWeeks(3),
        ]);
    }

    /**
     * Saliente de plantilla que Meta rechazó (así lo escribe el webhook de Kapso).
     *
     * @param Lead                  $lead
     * @param FollowupTemplate|null $plantilla Null para un saliente sin plantilla (texto libre).
     *
     * @return LeadMessage
     */
    private function crear_saliente_fallido(Lead $lead, ?FollowupTemplate $plantilla): LeadMessage
    {
        return $this->crear_mensaje($lead, [
            'sender'                   => 'setter',
            'content'                  => 'Hola! Te escribo por los agentes de IA. ¿Lo vemos?',
            'whatsapp_message_id'      => 'wamid.' . strtoupper(bin2hex(random_bytes(8))),
            'whatsapp_delivery_status' => 'fallido',
            'followup_template_id'     => $plantilla !== null ? $plantilla->id : null,
        ]);
    }

    /**
     * Las filas de `GET admin/lead/chats` indexadas por id de lead.
     *
     * @param string $query_string
     *
     * @return array<int, array<string, mixed>>
     */
    private function filas(string $query_string = ''): array
    {
        $url = '/api/admin/lead/chats' . ($query_string !== '' ? '?' . $query_string : '');

        $response = $this->actingAs($this->admin, 'sanctum')->getJson($url);
        $response->assertStatus(200);

        $indexadas = [];
        foreach ($response->json('data') as $fila) {
            $indexadas[(int) $fila['id']] = $fila;
        }

        return $indexadas;
    }

    /**
     * Ids de los leads que cuenta la tarjeta (el mismo scope que usa LeadStatusCardsService).
     *
     * @return array<int, int>
     */
    private function ids_de_la_tarjeta(): array
    {
        return Lead::query()->requiereRevision(true)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->sort()->values()->all();
    }

    /**
     * Headers del bloque claude/*.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Payload mínimo y válido de `POST claude/templates/meta`, en simulación.
     *
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    private function payload_de_alta(array $cambios = []): array
    {
        return array_merge([
            'name'     => 'cc_test_campana_' . random_int(100000, 999999),
            'category' => 'MARKETING',
            'body'     => 'Hola {{1}}! Te escribo por {{2}}. ¿Lo vemos esta semana?',
            'ejemplos' => ['Gastón', 'los agentes de IA'],
            'uso'      => 'Campaña masiva de reactivación a leads fríos.',
            'dry_run'  => true,
        ], $cambios);
    }

    /**
     * 🔴 El caso de la campaña del 22/10: fallido de una plantilla con el flag → no pinta en ningún lado.
     *
     * @return void
     */
    public function test_el_fallido_de_una_plantilla_de_campana_masiva_no_pinta_rojo(): void
    {
        $campana = $this->crear_plantilla(true);
        $lead    = $this->crear_lead('Frío de la campaña');
        $this->crear_historia_contestada($lead);
        $this->crear_saliente_fallido($lead, $campana);

        $fila = $this->filas()[(int) $lead->id];
        $this->assertSame(0, $fila['failed_send_count'], 'El rechazo de Meta de la campaña no suma al badge.');
        $this->assertNull($fila['atencion'], 'La fila no se pinta: ni rojo ni amarillo.');
        $this->assertNull($fila['atencion_motivo']);

        $this->assertArrayNotHasKey((int) $lead->id, $this->filas('solo_atencion=1'), 'No entra en solo_atencion=1.');
        $this->assertSame([], $this->ids_de_la_tarjeta(), 'No cuenta en la tarjeta (requiereRevision(true)).');
    }

    /**
     * Con el flag en 0 (todas las plantillas que ya existían) el fallido sigue en rojo, como antes.
     *
     * @return void
     */
    public function test_el_fallido_de_una_plantilla_sin_el_flag_sigue_en_rojo(): void
    {
        $comun = $this->crear_plantilla(false);
        $lead  = $this->crear_lead('Seguimiento común');
        $this->crear_historia_contestada($lead);
        $this->crear_saliente_fallido($lead, $comun);

        $fila = $this->filas()[(int) $lead->id];
        $this->assertSame(1, $fila['failed_send_count']);
        $this->assertSame(['rojo', 'error_envio'], [$fila['atencion'], $fila['atencion_motivo']]);

        $this->assertArrayHasKey((int) $lead->id, $this->filas('solo_atencion=1'));
        $this->assertSame([(int) $lead->id], $this->ids_de_la_tarjeta());
    }

    /**
     * Un saliente fallido sin `followup_template_id` (texto libre, respuesta de la IA) no tiene
     * plantilla que lo exima: sigue en rojo.
     *
     * @return void
     */
    public function test_el_fallido_sin_plantilla_sigue_en_rojo(): void
    {
        /* Una plantilla con el flag existe en la base: el null no puede «engancharse» a ella. */
        $this->crear_plantilla(true);

        $lead = $this->crear_lead('Texto libre');
        $this->crear_historia_contestada($lead);
        $this->crear_saliente_fallido($lead, null);

        $fila = $this->filas()[(int) $lead->id];
        $this->assertSame(1, $fila['failed_send_count']);
        $this->assertSame(['rojo', 'error_envio'], [$fila['atencion'], $fila['atencion_motivo']]);

        $this->assertArrayHasKey((int) $lead->id, $this->filas('solo_atencion=1'));
        $this->assertSame([(int) $lead->id], $this->ids_de_la_tarjeta());
    }

    /**
     * 🔴 El flag apaga la entrega fallida y nada más: un `is_error` posterior (el sistema no pudo
     * ni mandar) sigue en rojo aunque antes haya un fallido de campaña.
     *
     * @return void
     */
    public function test_un_is_error_posterior_sigue_en_rojo_aunque_haya_un_fallido_de_campana(): void
    {
        $campana = $this->crear_plantilla(true);
        $lead    = $this->crear_lead('Campaña y después error');
        $this->crear_historia_contestada($lead);
        $this->crear_saliente_fallido($lead, $campana);
        $this->crear_mensaje($lead, [
            'sender'          => 'sistema',
            'content'         => 'Error de envío: la ventana de 24hs está cerrada',
            'is_status_event' => true,
            'is_error'        => true,
            'sent_at'         => null,
        ]);

        $fila = $this->filas()[(int) $lead->id];
        $this->assertSame(1, $fila['failed_send_count'], 'Cuenta el is_error; el fallido de campaña no.');
        $this->assertSame(['rojo', 'error_envio'], [$fila['atencion'], $fila['atencion_motivo']]);

        $this->assertArrayHasKey((int) $lead->id, $this->filas('solo_atencion=1'));
        $this->assertSame([(int) $lead->id], $this->ids_de_la_tarjeta());
    }

    /**
     * El ícono de la fila (`ChatRow.vue`, `tick()`) lee `ultimo_mensaje`: el campo aditivo
     * `entrega_fallida_cuenta` le dice si ese `fallido` es un error de verdad. False para el
     * rechazo de una plantilla de campaña masiva, true para un fallido común y false para un
     * mensaje entregado (ampliación tras el chequeo independiente, 22/10/2026).
     *
     * @return void
     */
    public function test_el_ultimo_mensaje_de_la_fila_dice_si_la_entrega_fallida_cuenta(): void
    {
        $campana = $this->crear_plantilla(true);
        $comun   = $this->crear_plantilla(false);

        $de_campana = $this->crear_lead('Fallido de campaña');
        $this->crear_historia_contestada($de_campana);
        $this->crear_saliente_fallido($de_campana, $campana);

        $fallido_comun = $this->crear_lead('Fallido común');
        $this->crear_historia_contestada($fallido_comun);
        $this->crear_saliente_fallido($fallido_comun, $comun);

        $entregado = $this->crear_lead('Entregado');
        $this->crear_historia_contestada($entregado);

        $filas = $this->filas();

        $this->assertSame('fallido', $filas[(int) $de_campana->id]['ultimo_mensaje']['whatsapp_delivery_status']);
        $this->assertFalse($filas[(int) $de_campana->id]['ultimo_mensaje']['entrega_fallida_cuenta'], 'El rechazo de la campaña no cuenta.');

        $this->assertSame('fallido', $filas[(int) $fallido_comun->id]['ultimo_mensaje']['whatsapp_delivery_status']);
        $this->assertTrue($filas[(int) $fallido_comun->id]['ultimo_mensaje']['entrega_fallida_cuenta'], 'El fallido común sigue contando.');

        $this->assertNotSame('fallido', $filas[(int) $entregado->id]['ultimo_mensaje']['whatsapp_delivery_status']);
        $this->assertFalse($filas[(int) $entregado->id]['ultimo_mensaje']['entrega_fallida_cuenta'], 'Un mensaje entregado no es entrega fallida.');
    }

    /**
     * `POST claude/templates/meta` acepta el campo y lo muestra en la simulación. Sin el campo (una
     * llamada vieja) la plantilla se registraría con false. No sale nada a Meta.
     *
     * @return void
     */
    public function test_el_alta_de_plantilla_acepta_el_flag_y_lo_muestra_en_el_dry_run(): void
    {
        Http::fake();

        $con_flag = $this->withHeaders($this->headers())->postJson(
            '/api/claude/templates/meta',
            $this->payload_de_alta(['no_marca_error_de_entrega' => 1])
        );
        $con_flag->assertStatus(200);
        $con_flag->assertJson(['dry_run' => true, 'creada' => false]);
        $con_flag->assertJsonPath('se_registraria_como.no_marca_error_de_entrega', true);

        $sin_flag = $this->withHeaders($this->headers())->postJson(
            '/api/claude/templates/meta',
            $this->payload_de_alta()
        );
        $sin_flag->assertStatus(200);
        $sin_flag->assertJsonPath('se_registraria_como.no_marca_error_de_entrega', false);

        Http::assertNothingSent();
    }

    /**
     * `GET claude/templates/meta` devuelve el campo en cada plantilla, para que quien elige
     * plantilla sepa cuál es de campaña masiva.
     *
     * @return void
     */
    public function test_el_listado_de_plantillas_devuelve_el_flag(): void
    {
        $campana = $this->crear_plantilla(true);
        $comun   = $this->crear_plantilla(false);

        $respuesta = $this->withHeaders($this->headers())->getJson('/api/claude/templates/meta?estado=manual_prueba');
        $respuesta->assertStatus(200);

        $por_id = [];
        foreach ($respuesta->json('data') as $plantilla) {
            $por_id[(int) $plantilla['id']] = $plantilla;
        }

        $this->assertTrue($por_id[(int) $campana->id]['no_marca_error_de_entrega']);
        $this->assertFalse($por_id[(int) $comun->id]['no_marca_error_de_entrega']);
    }
}
