<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las cuatro tarjetas de estado que van arriba de la grilla de leads (GET /admin/lead/status-cards).
 *
 * Lo que se protege acá son tres definiciones que se rompen solas en el primer refactor:
 *
 * 1. 🔴 **"Sin responder" es el criterio del botón de revisión MÁS los rechazos de Meta**, o sea
 *    `LeadPendingReviewService::lead_requiere_revision()`: mensajes del lead sin contestar, o un
 *    error de sistema (`is_error`) sin actividad real posterior. NO es el criterio del badge
 *    amarillo de la columna "Sin leer" (`failed_send_count`), que además cuenta la entrega fallida
 *    de Kapso (`whatsapp_delivery_status = 'fallido'`). Los dos viven a diez líneas de distancia en
 *    `Lead.php` y el comentario de `failed_send_count` dice "replica tiene_error_sin_resolver" —
 *    se refiere a la parte de "sin resolver", no a las fuentes de error.
 *
 *    🔴 Por eso el servicio llama al scope con `requiereRevision(true)`: el default (`false`) es
 *    el gemelo exacto del botón y lo protege `RevisionDeLeadsEnSqlYEnPhpCoincidenTest`; el `true`
 *    suma los rechazos de Meta, que es lo que Lucas pidió el 1/9/2026 y lo que protege
 *    `test_el_rechazo_de_meta_cuenta_como_sin_responder`. Los dos tests tienen que convivir.
 *
 * 2. El número cuenta **LEADS, no mensajes**. Un lead con tres mensajes sin contestar suma 1.
 *
 * 3. Los conteos son **globales**: no leen ningún filtro del operador (columna, fecha, estado).
 *    Mismo criterio que los badges de no leídos de la barra de estados. Si alguien "aprovecha" el
 *    request para filtrarlos, la tarjeta deja de coincidir con el total del paginador.
 *
 * Y una de forma: siempre las cuatro claves, siempre en el mismo orden, aunque den cero. El SPA no
 * inventa tarjetas ni las ordena.
 */
class TarjetasDeEstadoDeLeadsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Los cuatro slugs con tarjeta, en el orden en que tienen que salir.
     *
     * 🔴 La tercera cambió de `demo_agendada` a `demo` en la misión demo-v2-estados-automaticos
     * (4/9/2026): pasó a ser una tarjeta agrupada (demo_agendada + demo_pendiente_de_ingreso +
     * demo_en_curso bajo un solo total). El detalle de esa agrupación —el `total` es la suma de
     * los tres sub-estados, el `sin_responder` también, y trae `slugs`— se prueba en
     * tests/Feature/StatusCardsAgrupadasDemoTest.php; acá solo se ajustan las claves que este
     * archivo ya usaba para no perder la cobertura de "sin responder"/"total" que sí sigue
     * siendo responsabilidad de este archivo.
     */
    private const SLUGS_ESPERADOS = [
        'calificado',
        'solicita_disponibilidad',
        'demo',
        'closer_activo',
    ];

    /**
     * Deja la base sin leads para que los conteos del endpoint (que son globales) sean
     * determinísticos. Todo esto vive adentro de la transacción del test y se revierte al final.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        LeadMessage::query()->delete();
        Lead::query()->delete();
    }

    /**
     * Admin autenticado para pegarle al endpoint.
     *
     * @return Admin
     */
    private function admin_autenticado(): Admin
    {
        return Admin::create([
            'name'     => 'Setter de prueba',
            'email'    => 'tarjetas-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * Lead mínimo en el estado pedido.
     *
     * @param string $status Slug del pipeline.
     * @param string $nombre Nombre de contacto (para identificarlo en los mensajes de fallo).
     *
     * @return Lead
     */
    private function crear_lead(string $status, string $nombre = 'Lead'): Lead
    {
        return Lead::create([
            'contact_name' => $nombre,
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => $status,
        ]);
    }

    /**
     * Mensaje del hilo del lead. Por defecto: mensaje entrante del lead ya recibido.
     *
     * @param Lead                 $lead   Dueño del hilo.
     * @param array<string, mixed> $campos Campos a pisar sobre los valores por defecto.
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
     * Saliente que efectivamente salio por WhatsApp. El `whatsapp_message_id` es lo que hace que
     * cuente como respuesta: ver LeadMessage::is_reply_to_lead().
     *
     * @param Lead                 $lead   Dueno del hilo.
     * @param array<string, mixed> $campos Campos a pisar.
     *
     * @return LeadMessage
     */
    private function crear_saliente_entregado(Lead $lead, array $campos = []): LeadMessage
    {
        return $this->crear_mensaje($lead, array_merge([
            'sender'              => 'setter',
            'content'             => 'Te paso los planes',
            'whatsapp_message_id' => 'wamid.' . strtoupper(bin2hex(random_bytes(8))),
        ], $campos));
    }

    /**
     * Pega al endpoint y devuelve las tarjetas indexadas por slug.
     *
     * @param string $query_string Query string opcional (para probar que los filtros se ignoran).
     *
     * @return array<string, array<string, mixed>>
     */
    private function tarjetas(string $query_string = ''): array
    {
        $url = '/api/admin/lead/status-cards' . ($query_string !== '' ? '?' . $query_string : '');

        $response = $this->actingAs($this->admin_autenticado(), 'sanctum')->getJson($url);
        $response->assertStatus(200);

        $indexadas = [];
        foreach ($response->json('cards') as $card) {
            $indexadas[$card['value']] = $card;
        }

        return $indexadas;
    }

    /**
     * Las cuatro tarjetas salen siempre, en orden, aunque no haya un solo lead.
     *
     * @return void
     */
    public function test_devuelve_las_cuatro_tarjetas_en_orden_con_ceros(): void
    {
        $response = $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->getJson('/api/admin/lead/status-cards');

        $response->assertStatus(200);

        $cards = $response->json('cards');

        $this->assertCount(4, $cards, 'Tienen que salir las cuatro tarjetas siempre.');

        $slugs = array_map(function ($card) {
            return $card['value'];
        }, $cards);

        $this->assertSame(self::SLUGS_ESPERADOS, $slugs, 'El orden lo fija el backend, no el SPA.');

        foreach ($cards as $card) {
            $this->assertSame(0, $card['total']);
            $this->assertSame(0, $card['sin_responder']);
            $this->assertArrayHasKey('text', $card);
            $this->assertArrayHasKey('color', $card);
            $this->assertArrayHasKey('group', $card);
        }
    }

    /**
     * El total de cada tarjeta cuenta solo los leads de ese estado.
     *
     * @return void
     */
    public function test_el_total_cuenta_leads_de_ese_estado_y_no_leads_de_otros(): void
    {
        $this->crear_lead('calificado', 'Calificado 1');
        $this->crear_lead('calificado', 'Calificado 2');
        $this->crear_lead('demo_agendada', 'Con demo');
        // Estado sin tarjeta: no tiene que aparecer ni sumar en ninguna.
        $this->crear_lead('nuevo', 'Recién entrado');

        $tarjetas = $this->tarjetas();

        $this->assertSame(2, $tarjetas['calificado']['total']);
        $this->assertSame(1, $tarjetas['demo']['total']);
        $this->assertSame(0, $tarjetas['solicita_disponibilidad']['total']);
        $this->assertSame(0, $tarjetas['closer_activo']['total']);
        $this->assertArrayNotHasKey('nuevo', $tarjetas, 'Solo salen los cuatro estados con tarjeta.');
    }

    /**
     * El número de "sin responder" es de LEADS: tres mensajes sin contestar del mismo lead suman 1.
     *
     * @return void
     */
    public function test_sin_responder_cuenta_leads_y_no_mensajes(): void
    {
        $lead = $this->crear_lead('calificado', 'Insistente');
        $this->crear_mensaje($lead, ['content' => 'Hola']);
        $this->crear_mensaje($lead, ['content' => '¿Están?']);
        $this->crear_mensaje($lead, ['content' => 'Sigo esperando']);

        $tarjetas = $this->tarjetas();

        $this->assertSame(1, $tarjetas['calificado']['total']);
        $this->assertSame(1, $tarjetas['calificado']['sin_responder'], 'Cuenta leads, no mensajes.');
    }

    /**
     * Razón A: mensaje del lead sin ningún saliente posterior.
     *
     * @return void
     */
    public function test_sin_responder_incluye_la_razon_a(): void
    {
        $lead = $this->crear_lead('solicita_disponibilidad', 'Sin responder');
        // Hubo respuesta del setter, pero el lead volvió a escribir después.
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Hola, ¿qué necesitás?']);
        $this->crear_mensaje($lead, ['content' => '¿Qué horarios tenés?']);

        $tarjetas = $this->tarjetas();

        $this->assertSame(1, $tarjetas['solicita_disponibilidad']['sin_responder']);
    }

    /**
     * Razón B: el hilo termina en un error de sistema sin actividad real posterior.
     *
     * @return void
     */
    public function test_sin_responder_incluye_la_razon_b(): void
    {
        $lead = $this->crear_lead('demo_agendada', 'Con error');
        // Saliente que sí salió, y después el registro de error (siempre is_status_event = true).
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Te mando el link']);
        $this->crear_mensaje($lead, [
            'sender'          => 'sistema',
            'content'         => 'Error de envío: la ventana de 24hs está cerrada',
            'is_status_event' => true,
            'is_error'        => true,
            'sent_at'         => null,
        ]);

        $tarjetas = $this->tarjetas();

        $this->assertSame(1, $tarjetas['demo']['sin_responder']);
    }

    /**
     * El lead que tiene las dos razones a la vez sigue siendo UN lead. Protege el OR.
     *
     * @return void
     */
    public function test_un_lead_con_las_dos_razones_cuenta_una_sola_vez(): void
    {
        $lead = $this->crear_lead('closer_activo', 'Doble motivo');
        $this->crear_mensaje($lead, ['content' => '¿Me pasás el precio?']);
        $this->crear_mensaje($lead, [
            'sender'          => 'sistema',
            'content'         => 'Error de generación de la respuesta',
            'is_status_event' => true,
            'is_error'        => true,
            'sent_at'         => null,
        ]);

        $tarjetas = $this->tarjetas();

        $this->assertSame(1, $tarjetas['closer_activo']['total']);
        $this->assertSame(1, $tarjetas['closer_activo']['sin_responder']);
    }

    /**
     * Un saliente posterior (setter enviado / setter aprobado / sistema aprobado) apaga la razón A.
     *
     * @return void
     */
    public function test_un_saliente_posterior_apaga_la_razon_a(): void
    {
        $por_setter = $this->crear_lead('calificado', 'Contestado por el setter');
        $this->crear_mensaje($por_setter, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($por_setter);

        $por_sistema = $this->crear_lead('calificado', 'Contestado por el agente aprobado');
        $this->crear_mensaje($por_sistema, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($por_sistema, ['sender' => 'sistema', 'status' => 'aprobado']);

        /* El agente contestando como contesta de verdad: sistema + enviado + id de WhatsApp.
           Hasta el 2/9/2026 este caso NO apagaba la razon A y la tarjeta contaba a casi todos. */
        $por_agente = $this->crear_lead('calificado', 'Contestado por el agente');
        $this->crear_mensaje($por_agente, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($por_agente, ['sender' => 'sistema']);

        $tarjetas = $this->tarjetas();

        $this->assertSame(3, $tarjetas['calificado']['total']);
        $this->assertSame(0, $tarjetas['calificado']['sin_responder']);
    }

    /**
     * 🔴 El caso que pidio Lucas el 2/9/2026, medido sobre la tarjeta que el mira todos los dias.
     *
     * Mientras la respuesta de la IA espera verificacion, ese lead tiene que contar como sin
     * responder: hay alguien esperando y nadie le contesto. Cuando el mensaje sale de verdad por
     * WhatsApp, deja de contar. No importa quien lo mando.
     *
     * @return void
     */
    public function test_una_sugerencia_esperando_verificacion_cuenta_hasta_que_el_mensaje_sale(): void
    {
        $lead = $this->crear_lead('calificado', 'Con sugerencia por verificar');
        $this->crear_mensaje($lead, ['content' => '¿Me pasás un horario para la demo?']);
        $sugerencia = $this->crear_mensaje($lead, [
            'sender'                => 'sistema',
            'status'                => 'sugerido',
            'content'               => 'Te propongo el jueves a las 14',
            'requiere_verificacion' => true,
        ]);

        $tarjetas = $this->tarjetas();
        $this->assertSame(1, $tarjetas['calificado']['sin_responder'], 'Con la sugerencia esperando verificacion tiene que contar.');

        /* El operador la aprueba y sale: el envio real es lo que deja el whatsapp_message_id. */
        $sugerencia->update([
            'status'              => 'enviado',
            'whatsapp_message_id' => 'wamid.' . strtoupper(bin2hex(random_bytes(8))),
        ]);

        $tarjetas = $this->tarjetas();
        $this->assertSame(0, $tarjetas['calificado']['sin_responder'], 'Una vez enviado el mensaje, el lead deja de contar.');
    }

    /**
     * Actividad real posterior al error (cualquier mensaje que no sea evento de estado) lo resuelve.
     *
     * @return void
     */
    public function test_actividad_real_posterior_apaga_la_razon_b(): void
    {
        $lead = $this->crear_lead('demo_agendada', 'Error reintentado');
        $this->crear_mensaje($lead, [
            'sender'          => 'sistema',
            'content'         => 'Error de envío',
            'is_status_event' => true,
            'is_error'        => true,
            'sent_at'         => null,
        ]);
        // Reintento exitoso del setter: no es evento de estado, así que resuelve el error. Y como
        // no es un mensaje del lead, tampoco enciende la razón A.
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Ahí va de nuevo el link']);

        $tarjetas = $this->tarjetas();

        $this->assertSame(1, $tarjetas['demo']['total']);
        $this->assertSame(0, $tarjetas['demo']['sin_responder']);
    }

    /**
     * Una reacción del lead no es un mensaje que haya que contestar. Dos formatos: el nuevo
     * (`kind = 'reaction'`) y el legado de Kapso (texto plano "Reacted … to message wamid.…").
     *
     * @return void
     */
    public function test_una_reaccion_del_lead_no_cuenta_como_mensaje_sin_responder(): void
    {
        $por_kind = $this->crear_lead('calificado', 'Reaccionó (kind)');
        $this->crear_mensaje($por_kind, ['sender' => 'setter', 'content' => 'Te mando la propuesta']);
        $this->crear_mensaje($por_kind, ['kind' => 'reaction', 'content' => "\u{1F44D}"]);

        $por_texto = $this->crear_lead('calificado', 'Reaccionó (texto legado)');
        $this->crear_mensaje($por_texto, ['sender' => 'setter', 'content' => 'Te mando la propuesta']);
        $this->crear_mensaje($por_texto, [
            'content' => 'Reacted with ' . "\u{1F44D}" . ' to message wamid.ABC123',
        ]);

        $quitada = $this->crear_lead('calificado', 'Quitó la reacción');
        $this->crear_mensaje($quitada, ['sender' => 'setter', 'content' => 'Te mando la propuesta']);
        $this->crear_mensaje($quitada, ['content' => 'Removed reaction from message wamid.ABC123']);

        $tarjetas = $this->tarjetas();

        $this->assertSame(3, $tarjetas['calificado']['total']);
        $this->assertSame(0, $tarjetas['calificado']['sin_responder']);
    }

    /**
     * 🔴 El rechazo de Meta cuenta como "sin responder", aunque el botón de revisión no lo vea.
     *
     * Cuando Meta rechaza un envío responde 200 en el momento y avisa el fallo después, por
     * webhook. `WhatsappWebhookController::handle_outbound_status_event()` escribe entonces
     * `whatsapp_delivery_status = 'fallido'` y **nunca** un `is_error`, así que ese caso es
     * invisible para `LeadPendingReviewService`. Encima el saliente queda `status = 'enviado'`, o
     * sea que además apaga la razón A del mensaje del lead que venía antes.
     *
     * Lucas pidió explícitamente (1/9/2026) que la tarjeta lo cuente: sin esto mostraba 0 arriba
     * de una fila que la grilla pinta de rojo por ese mismo envío. Por eso el servicio llama al
     * scope con `requiereRevision(true)`.
     *
     * 🔴 Este test es el par del de paridad: acá se exige que la tarjeta SÍ lo cuente, y en
     * `RevisionDeLeadsEnSqlYEnPhpCoincidenTest` se exige que el camino por defecto (el gemelo del
     * botón) NO lo cuente. Los dos tienen que quedar como están: son criterios distintos a
     * propósito, no una inconsistencia para "unificar".
     *
     * @return void
     */
    public function test_el_rechazo_de_meta_cuenta_como_sin_responder(): void
    {
        $lead = $this->crear_lead('calificado', 'Entrega fallida');
        $this->crear_mensaje($lead, [
            'sender'                   => 'setter',
            'content'                  => 'Te mando el link de la demo',
            'whatsapp_delivery_status' => 'fallido',
            'is_error'                 => false,
        ]);

        $tarjetas = $this->tarjetas();

        $this->assertSame(1, $tarjetas['calificado']['total']);
        $this->assertSame(
            1,
            $tarjetas['calificado']['sin_responder'],
            'Un envío que Meta rechazó tiene que contar en la tarjeta, aunque no haya dejado is_error.'
        );

        // Y tiene que quedar alineado con el badge amarillo del listado, que ya lo contaba.
        $listado = $this->actingAs($this->admin_autenticado(), 'sanctum')->getJson('/api/admin/lead');
        $listado->assertStatus(200);

        $encontrado = null;
        foreach ($listado->json('models') as $fila) {
            if ((int) $fila['id'] === (int) $lead->id) {
                $encontrado = $fila;
                break;
            }
        }

        $this->assertNotNull($encontrado, 'El lead tiene que estar en el listado.');
        $this->assertGreaterThan(
            0,
            (int) $encontrado['failed_send_count'],
            'failed_send_count también lo cuenta: la tarjeta y el rojo de la grilla tienen que coincidir.'
        );

        // Y el botón de revisión NO lo ve: es la divergencia deliberada entre los dos criterios.
        $this->assertFalse(
            app(\App\Services\LeadPendingReviewService::class)
                ->lead_requiere_revision($lead->fresh()->load('messages')),
            'El botón de revisión sigue sin ver el rechazo de Meta: si esto cambia, se movió algo que no correspondía.'
        );
    }

    /**
     * Los conteos son globales: el endpoint no lee filtros del request. Fija la decisión 2 de Lucas.
     *
     * @return void
     */
    public function test_los_conteos_ignoran_los_filtros_del_operador(): void
    {
        $this->crear_lead('calificado', 'Calificado 1');
        $this->crear_lead('calificado', 'Calificado 2');
        $this->crear_lead('demo_agendada', 'Con demo');

        // Se le manda todo lo que el operador podría tener puesto en la grilla: estado, filtros de
        // columna y rango de fechas. Nada de eso tiene que mover un número.
        $query = http_build_query([
            'status'  => 'demo_agendada',
            'from'    => now()->addYear()->toDateString(),
            'to'      => now()->addYears(2)->toDateString(),
            'filters' => [
                ['key' => 'status', 'type' => 'select', 'igual_que' => 'demo_agendada'],
            ],
        ]);

        $tarjetas = $this->tarjetas($query);

        $this->assertSame(2, $tarjetas['calificado']['total'], 'El filtro del operador no toca los conteos.');
        $this->assertSame(1, $tarjetas['demo']['total']);
    }

    /**
     * El color y la etiqueta salen del catálogo (`lead_pipeline_statuses`), no de un hex escrito a
     * mano en el SPA: la tarjeta y el puntito de la barra de estados tienen que salir de la misma
     * fuente o divergen en cuanto alguien cambie un color desde el panel.
     *
     * @return void
     */
    public function test_el_color_y_la_etiqueta_salen_del_catalogo_de_bd(): void
    {
        LeadPipelineStatus::query()->updateOrCreate(
            ['slug' => 'calificado'],
            ['label' => 'Calificado a mano', 'color' => '#123456', 'sort_order' => 0]
        );

        $tarjetas = $this->tarjetas();

        $this->assertSame('Calificado a mano', $tarjetas['calificado']['text']);
        $this->assertSame('#123456', $tarjetas['calificado']['color']);
        $this->assertSame('Calificación', $tarjetas['calificado']['group']);

        // Un slug que no está en la tabla cae al default del modelo, no a un vacío.
        $this->assertSame('Closer activo', $tarjetas['closer_activo']['text']);
        $this->assertSame('#6f42c1', $tarjetas['closer_activo']['color']);
        $this->assertSame('Cierre', $tarjetas['closer_activo']['group']);
    }

    /**
     * Sin sesión no se contestan conteos globales del pipeline.
     *
     * @return void
     */
    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/admin/lead/status-cards')->assertStatus(401);
    }
}
