<?php

namespace Tests\Feature\BandejaDeLeads;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadMessageAttachment;
use App\Models\LeadPipelineStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La lista de chats de la bandeja de leads: `GET admin/lead/chats` y `GET admin/lead/chats/{id}`
 * (misión flujo-lead-landing-closer, 17/10/2026; contrato §4.1 del plan).
 *
 * Lo que se fija acá es el CONTRATO con admin-spa, que otro constructor armó contra el texto del
 * plan y no contra este código: la forma de la fila clave por clave, qué mensaje es «el último»,
 * cómo se resume (texto de una línea, etiquetas de adjunto), los cuatro veredictos de `atencion`
 * (y que el rojo le gana al amarillo), el orden fijo (fijados → último mensaje, como WhatsApp),
 * los cuatro filtros (`q`, `status` simple/lista/grupo, `solo_atencion`, `sin_responder`) y el paginado `{data, meta}`.
 *
 * Los contadores y el color no se recalculan a mano en los tests: se arman hilos reales y se mira
 * qué dice la fila. Si la definición única de «sin responder» cambia, cambia acá también.
 */
class ListaDeChatsDeLeadsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Claves de la fila, en el orden del contrato. El test de forma compara el conjunto exacto.
     *
     * @var array<int, string>
     */
    private const CLAVES_DE_LA_FILA = [
        'id', 'uuid', 'contact_name', 'company_name', 'phone', 'email',
        'status', 'status_text', 'status_color',
        'pinned_at', 'manually_marked_unread', 'no_recibe_mensajes_at',
        'atendido_hasta_message_id',
        'atencion', 'atencion_motivo',
        'sin_responder', 'mensaje_sin_responder',
        'pending_verification_count', 'failed_send_count', 'unread_count',
        'last_message_at',
        'ultimo_mensaje',
        'demo',
    ];

    /** @var array<int, string> Claves de `ultimo_mensaje`, en el orden del contrato. */
    private const CLAVES_DEL_ULTIMO_MENSAJE = [
        'id', 'sender', 'status', 'whatsapp_delivery_status', 'is_error', 'kind', 'created_at', 'texto',
    ];

    /** @var Admin */
    private $admin;

    /**
     * Deja la base sin leads (el orden y el paginado se miden sobre la tabla entera) y arma el admin
     * autenticado. Todo adentro de la transacción del test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        LeadMessage::query()->delete();
        Lead::query()->delete();

        $this->admin = Admin::create([
            'name'     => 'Setter de prueba',
            'email'    => 'chats-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * Lead mínimo.
     *
     * @param string               $nombre
     * @param string               $status
     * @param array<string, mixed> $campos
     *
     * @return Lead
     */
    private function crear_lead(string $nombre, string $status = 'calificado', array $campos = []): Lead
    {
        return Lead::create(array_merge([
            'contact_name' => $nombre,
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => $status,
        ], $campos));
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
     * Saliente que efectivamente llegó al lead (con `whatsapp_message_id`, que es lo que lo vuelve
     * respuesta según LeadMessage::is_reply_to_lead()).
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $campos
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
     * Pega a la lista y devuelve el JSON entero.
     *
     * @param string $query_string
     *
     * @return array<string, mixed>
     */
    private function chats(string $query_string = ''): array
    {
        $url = '/api/admin/lead/chats' . ($query_string !== '' ? '?' . $query_string : '');

        $response = $this->actingAs($this->admin, 'sanctum')->getJson($url);
        $response->assertStatus(200);

        return $response->json();
    }

    /**
     * Las filas de la lista indexadas por id de lead.
     *
     * @param string $query_string
     *
     * @return array<int, array<string, mixed>>
     */
    private function filas(string $query_string = ''): array
    {
        $indexadas = [];
        foreach ($this->chats($query_string)['data'] as $fila) {
            $indexadas[(int) $fila['id']] = $fila;
        }

        return $indexadas;
    }

    /**
     * La fila de un lead puntual, por la lista.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    private function fila_de(Lead $lead): array
    {
        $filas = $this->filas();
        $this->assertArrayHasKey((int) $lead->id, $filas, 'El lead ' . $lead->contact_name . ' tiene que estar en la lista.');

        return $filas[(int) $lead->id];
    }

    /**
     * La fila tiene exactamente las claves del contrato, con los tipos del contrato, y `status_text`
     * / `status_color` salen del catálogo.
     *
     * @return void
     */
    public function test_la_fila_tiene_la_forma_del_contrato(): void
    {
        $lead = $this->crear_lead('Juana Pérez', 'calificado', [
            'company_name' => 'Ferretería El Tornillo',
            'email'        => 'juana@eltornillo.test',
        ]);
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($lead);

        $json = $this->chats();

        $this->assertSame(['data', 'meta'], array_keys($json), 'La respuesta es {data, meta} y nada más.');
        $this->assertSame(['current_page', 'last_page', 'per_page', 'total'], array_keys($json['meta']));
        $this->assertCount(1, $json['data']);

        $fila = $json['data'][0];

        $this->assertSame(self::CLAVES_DE_LA_FILA, array_keys($fila), 'Las claves de la fila son las del contrato, en su orden.');

        $this->assertSame((int) $lead->id, $fila['id']);
        $this->assertSame('Juana Pérez', $fila['contact_name']);
        $this->assertSame('Ferretería El Tornillo', $fila['company_name']);
        $this->assertSame($lead->phone, $fila['phone']);
        $this->assertSame('juana@eltornillo.test', $fila['email']);

        $this->assertSame('calificado', $fila['status']);
        $this->assertSame(LeadPipelineStatus::label_for('calificado'), $fila['status_text']);
        $this->assertSame(LeadPipelineStatus::color_for('calificado'), $fila['status_color']);
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $fila['status_color'], 'El color es un hex del catálogo.');

        $this->assertNull($fila['pinned_at']);
        $this->assertFalse($fila['manually_marked_unread']);
        $this->assertNull($fila['no_recibe_mensajes_at']);
        $this->assertNull($fila['atendido_hasta_message_id']);

        // Contestado: nada que atender.
        $this->assertNull($fila['atencion']);
        $this->assertNull($fila['atencion_motivo']);

        $this->assertSame(0, $fila['pending_verification_count']);
        $this->assertSame(0, $fila['failed_send_count']);
        $this->assertSame(1, $fila['unread_count'], 'El mensaje del lead no fue leído por este admin.');

        $this->assertNotNull($fila['last_message_at']);
        $this->assertNull($fila['demo'], 'Sin demo asignada, `demo` es null.');

        $this->assertIsArray($fila['ultimo_mensaje']);
        $this->assertSame(self::CLAVES_DEL_ULTIMO_MENSAJE, array_keys($fila['ultimo_mensaje']));
        $this->assertSame('setter', $fila['ultimo_mensaje']['sender']);
        $this->assertSame('enviado', $fila['ultimo_mensaje']['status']);
        $this->assertNull($fila['ultimo_mensaje']['whatsapp_delivery_status']);
        $this->assertFalse($fila['ultimo_mensaje']['is_error']);
        $this->assertSame('text', $fila['ultimo_mensaje']['kind']);
        $this->assertSame('Te paso los planes', $fila['ultimo_mensaje']['texto']);
    }

    /**
     * Con demo asignada, `demo` trae las cinco claves del turno.
     *
     * @return void
     */
    public function test_con_demo_asignada_la_fila_trae_el_turno(): void
    {
        $lead = $this->crear_lead('Con demo', 'demo_agendada', [
            'demo_id'           => 7,
            'demo_date'         => '2026-10-20',
            'demo_start_time'   => '10:00',
            'demo_end_time'     => '12:00',
            'demo_setup_status' => 'exitoso',
        ]);

        $fila = $this->fila_de($lead);

        $this->assertSame([
            'demo_id'           => 7,
            'demo_date'         => '2026-10-20',
            'demo_start_time'   => '10:00',
            'demo_end_time'     => '12:00',
            'demo_setup_status' => 'exitoso',
        ], $fila['demo']);
    }

    /**
     * «El último mensaje» es el último que no es evento de estado ni está `rechazado`. Una
     * sugerencia esperando aprobación SÍ es el último: es lo que el admin tiene que ver.
     *
     * @return void
     */
    public function test_el_ultimo_mensaje_saltea_eventos_de_estado_y_rechazados(): void
    {
        $lead = $this->crear_lead('Hilo con ruido');

        $del_lead = $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($lead, ['sender' => 'sistema', 'status' => 'rechazado', 'content' => 'Sugerencia tirada']);
        $this->crear_mensaje($lead, ['sender' => 'sistema', 'status' => 'enviado', 'content' => 'Estado: calificado', 'is_status_event' => true]);

        $fila = $this->fila_de($lead);
        $this->assertSame((int) $del_lead->id, $fila['ultimo_mensaje']['id'], 'Ni el rechazado ni el evento de estado son «el último».');
        $this->assertSame('lead', $fila['ultimo_mensaje']['sender']);

        $sugerida = $this->crear_mensaje($lead, [
            'sender'                => 'sistema',
            'status'                => 'sugerido',
            'content'               => 'Propuesta esperando el ok',
            'requiere_verificacion' => true,
        ]);

        $fila = $this->fila_de($lead);
        $this->assertSame((int) $sugerida->id, $fila['ultimo_mensaje']['id'], 'Un sugerido esperando aprobación sí es el último.');
        $this->assertSame('sugerido', $fila['ultimo_mensaje']['status']);
    }

    /**
     * Un lead sin ningún mensaje tiene `ultimo_mensaje` null y `last_message_at` null.
     *
     * @return void
     */
    public function test_sin_mensajes_el_ultimo_mensaje_es_null(): void
    {
        $lead = $this->crear_lead('Recién creado', 'nuevo');

        $fila = $this->fila_de($lead);

        $this->assertNull($fila['ultimo_mensaje']);
        $this->assertNull($fila['last_message_at']);
        $this->assertNull($fila['atencion']);
    }

    /**
     * `texto` es una sola línea de hasta 120 caracteres.
     *
     * @return void
     */
    public function test_el_texto_del_ultimo_mensaje_es_una_linea_de_hasta_120_caracteres(): void
    {
        $lead  = $this->crear_lead('Texto largo');
        $largo = "Hola,\n\nquería saber   el precio\r\n" . str_repeat('y si tienen stock ', 20);
        $this->crear_mensaje($lead, ['content' => $largo]);

        $texto = $this->fila_de($lead)['ultimo_mensaje']['texto'];

        $this->assertStringNotContainsString("\n", $texto);
        $this->assertStringNotContainsString("\r", $texto);
        $this->assertStringStartsWith('Hola, quería saber el precio', $texto, 'Los saltos y los espacios repetidos se colapsan a uno.');
        $this->assertLessThanOrEqual(120, mb_strlen($texto), 'Nunca más de 120 caracteres.');
        $this->assertStringEndsWith('…', $texto, 'Un texto recortado termina en puntos suspensivos.');
    }

    /**
     * Con adjunto y sin texto propio, la etiqueta del tipo. Los marcadores que escribe el webhook
     * cuando el adjunto no trae nada («[Audio sin transcripción]», «[IMAGE recibido por WhatsApp]»)
     * cuentan como «sin texto».
     *
     * @return void
     */
    public function test_los_adjuntos_sin_texto_muestran_la_etiqueta_del_tipo(): void
    {
        $foto = $this->crear_lead('Mandó una foto');
        $this->crear_mensaje($foto, ['kind' => 'image', 'content' => '[IMAGE recibido por WhatsApp]']);

        $audio = $this->crear_lead('Mandó un audio');
        $this->crear_mensaje($audio, ['kind' => 'audio', 'content' => '[Audio sin transcripción]']);

        $documento = $this->crear_lead('Mandó un documento');
        $this->crear_mensaje($documento, ['kind' => 'document', 'content' => '']);

        // `kind` genérico pero con archivo adjunto: la etiqueta sale del MIME.
        $por_mime = $this->crear_lead('Adjunto con kind text');
        $mensaje  = $this->crear_mensaje($por_mime, ['kind' => 'text', 'content' => '']);
        LeadMessageAttachment::create([
            'lead_message_id' => $mensaje->id,
            'disk'            => 'public',
            'path'            => 'leads/adjuntos/foto.jpg',
            'mime'            => 'image/jpeg',
            'size'            => 1024,
        ]);

        $filas = $this->filas();

        $this->assertSame("\u{1F4F7} Foto", $filas[(int) $foto->id]['ultimo_mensaje']['texto']);
        $this->assertSame("\u{1F3A4} Audio", $filas[(int) $audio->id]['ultimo_mensaje']['texto']);
        $this->assertSame("\u{1F4C4} Documento", $filas[(int) $documento->id]['ultimo_mensaje']['texto']);
        $this->assertSame("\u{1F4F7} Foto", $filas[(int) $por_mime->id]['ultimo_mensaje']['texto']);
    }

    /**
     * Con adjunto Y texto (epígrafe o transcripción), la etiqueta va adelante del texto para que el
     * operador sepa que hay algo que mirar o escuchar.
     *
     * @return void
     */
    public function test_un_adjunto_con_texto_lleva_la_etiqueta_adelante(): void
    {
        $lead = $this->crear_lead('Audio transcripto');
        $this->crear_mensaje($lead, ['kind' => 'audio', 'content' => 'hola quería saber el precio del sistema']);

        $this->assertSame(
            "\u{1F3A4} Audio hola quería saber el precio del sistema",
            $this->fila_de($lead)['ultimo_mensaje']['texto']
        );
    }

    /**
     * Los cuatro veredictos de `atencion`, con su motivo.
     *
     * @return void
     */
    public function test_atencion_en_los_cuatro_casos(): void
    {
        // Rojo por error de envío: un saliente que Meta rechazó y nada después.
        $error = $this->crear_lead('Error de envío');
        $this->crear_mensaje($error, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($error, ['sender' => 'setter', 'content' => 'Te paso los planes', 'whatsapp_delivery_status' => 'fallido']);

        // Rojo por mensaje esperando confirmación.
        $confirmacion = $this->crear_lead('Esperando confirmación');
        $this->crear_mensaje($confirmacion, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($confirmacion, [
            'sender'                => 'sistema',
            'status'                => 'sugerido',
            'content'               => 'Propuesta que agenda una demo',
            'requiere_verificacion' => true,
        ]);

        // Amarillo: el lead habló último y nadie le contestó.
        $sin_responder = $this->crear_lead('Sin responder');
        $this->crear_saliente_entregado($sin_responder);
        $this->crear_mensaje($sin_responder, ['content' => '¿Cuánto sale?']);

        // Nada: contestado por el agente (sistema + enviado + id de WhatsApp).
        $contestado = $this->crear_lead('Contestado');
        $this->crear_mensaje($contestado, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($contestado, ['sender' => 'sistema']);

        $filas = $this->filas();

        $this->assertSame(['rojo', 'error_envio'], [$filas[(int) $error->id]['atencion'], $filas[(int) $error->id]['atencion_motivo']]);
        $this->assertSame(1, $filas[(int) $error->id]['failed_send_count']);

        $this->assertSame(['rojo', 'esperando_confirmacion'], [$filas[(int) $confirmacion->id]['atencion'], $filas[(int) $confirmacion->id]['atencion_motivo']]);
        $this->assertSame(1, $filas[(int) $confirmacion->id]['pending_verification_count']);

        $this->assertSame(['amarillo', 'sin_responder'], [$filas[(int) $sin_responder->id]['atencion'], $filas[(int) $sin_responder->id]['atencion_motivo']]);

        $this->assertSame([null, null], [$filas[(int) $contestado->id]['atencion'], $filas[(int) $contestado->id]['atencion_motivo']]);
    }

    /**
     * El rojo le gana al amarillo: un lead con un mensaje sin responder Y una sugerencia esperando
     * confirmación es rojo.
     *
     * @return void
     */
    public function test_el_rojo_le_gana_al_amarillo(): void
    {
        $lead = $this->crear_lead('Rojo y amarillo');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($lead, [
            'sender'                => 'sistema',
            'status'                => 'sugerido',
            'content'               => 'Propuesta esperando el ok',
            'requiere_verificacion' => true,
        ]);

        $fila = $this->fila_de($lead);

        $this->assertSame('rojo', $fila['atencion']);
        $this->assertSame('esperando_confirmacion', $fila['atencion_motivo']);
    }

    /**
     * Un lead marcado como «ya no recibe mensajes» no es rojo por sus envíos fallidos: no hay nada
     * que reintentar.
     *
     * @return void
     */
    public function test_el_que_ya_no_recibe_mensajes_no_es_rojo_por_error_de_envio(): void
    {
        $lead = $this->crear_lead('Inalcanzable', 'calificado', ['no_recibe_mensajes_at' => now()]);
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Te paso los planes', 'whatsapp_delivery_status' => 'fallido']);

        $fila = $this->fila_de($lead);

        $this->assertSame(1, $fila['failed_send_count'], 'El contador sigue diciendo la verdad.');
        $this->assertNull($fila['atencion'], 'Pero la fila no pide atención.');
        $this->assertNotNull($fila['no_recibe_mensajes_at']);
    }

    /**
     * 🔴 Un lead marcado como «ya no recibe mensajes» con un mensaje suyo sin responder tampoco es
     * amarillo, ni entra en `solo_atencion=1`: `Lead::scopeRequiereRevision()` lo saca de todo el
     * conteo de la tarjeta, y la lista tiene que decir lo mismo que la tarjeta (chequeo independiente
     * de la misión, 17/10/2026). El contador `sin_responder_count` no viaja en la fila; lo que viaja
     * es el veredicto.
     *
     * @return void
     */
    public function test_el_que_ya_no_recibe_mensajes_tampoco_es_amarillo_ni_entra_en_solo_atencion(): void
    {
        // El inalcanzable tiene el mensaje MÁS NUEVO: si el color no mandara, saldría primero.
        $inalcanzable = $this->crear_lead('Inalcanzable con mensaje', 'calificado', ['no_recibe_mensajes_at' => now()]);
        $this->crear_mensaje($inalcanzable, ['content' => '¿Cuánto sale?', 'sent_at' => now()->addMinutes(5)]);

        $alcanzable = $this->crear_lead('Alcanzable con mensaje');
        $this->crear_mensaje($alcanzable, ['content' => '¿Cuánto sale?', 'sent_at' => now()->subMinutes(5)]);

        $filas = $this->filas();
        $this->assertNull($filas[(int) $inalcanzable->id]['atencion'], 'Inalcanzable: la lista no lo pinta, como la tarjeta no lo cuenta.');
        $this->assertNull($filas[(int) $inalcanzable->id]['atencion_motivo']);
        $this->assertSame('amarillo', $filas[(int) $alcanzable->id]['atencion'], 'El mismo hilo, alcanzable, sí es amarillo.');

        $this->assertSame([(int) $alcanzable->id], array_keys($this->filas('solo_atencion=1')), 'solo_atencion deja afuera al inalcanzable.');

        // Y la tarjeta dice lo mismo que la lista: cuenta uno, no dos.
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/lead/status-cards');
        $response->assertStatus(200);
        foreach ($response->json('cards') as $card) {
            if ($card['value'] === 'calificado') {
                $this->assertSame(1, (int) $card['sin_responder'], 'La tarjeta y la lista cuentan al mismo lead.');
            }
        }

        // El orden es solo por último mensaje: el amarillo no sube. El inalcanzable (mensaje más nuevo)
        // va primero aunque no tenga color, y el alcanzable amarillo queda debajo.
        $this->assertSame(
            [(int) $inalcanzable->id, (int) $alcanzable->id],
            array_keys($this->filas()),
            'El más reciente va arriba, tenga color o no.'
        );
    }

    /**
     * Orden como WhatsApp (22/10/2026): fijados (el más reciente arriba) → último mensaje DESC. El
     * rojo y el amarillo se pintan pero no reordenan.
     *
     * Los `sent_at` están elegidos para que el orden por color fuera el CONTRARIO al esperado: si el
     * color mandara, el test fallaría.
     *
     * @return void
     */
    public function test_el_orden_es_fijados_y_ultimo_mensaje_sin_que_el_color_reordene(): void
    {
        $ahora = Carbon::now();

        // Sin color, el más viejo de todos.
        $viejo = $this->crear_lead('Sin color viejo');
        $this->crear_saliente_entregado($viejo, ['sent_at' => $ahora->copy()->subDays(5)]);

        // Amarillo, más viejo que el rojo y que el nuevo sin color.
        $amarillo = $this->crear_lead('Amarillo');
        $this->crear_mensaje($amarillo, ['content' => '¿Cuánto sale?', 'sent_at' => $ahora->copy()->subDays(4)]);

        // Rojo, más nuevo que el amarillo.
        $rojo = $this->crear_lead('Rojo');
        $this->crear_mensaje($rojo, ['sender' => 'setter', 'content' => 'Te paso los planes', 'whatsapp_delivery_status' => 'fallido', 'sent_at' => $ahora->copy()->subDays(3)]);

        // Sin color, el más reciente de los no fijados.
        $nuevo = $this->crear_lead('Sin color nuevo');
        $this->crear_saliente_entregado($nuevo, ['sent_at' => $ahora->copy()->subHours(1)]);

        // Dos fijados sin color y con mensajes viejos: van arriba de todo, el fijado más reciente primero.
        $fijado_viejo = $this->crear_lead('Fijado hace una semana', 'calificado', ['pinned_at' => $ahora->copy()->subDays(7)]);
        $this->crear_saliente_entregado($fijado_viejo, ['sent_at' => $ahora->copy()->subDays(10)]);

        $fijado_reciente = $this->crear_lead('Fijado hoy', 'calificado', ['pinned_at' => $ahora->copy()->subMinutes(10)]);
        $this->crear_saliente_entregado($fijado_reciente, ['sent_at' => $ahora->copy()->subDays(9)]);

        $ids = array_map(function ($fila) {
            return (int) $fila['id'];
        }, $this->chats()['data']);

        $this->assertSame([
            (int) $fijado_reciente->id,
            (int) $fijado_viejo->id,
            (int) $nuevo->id,
            (int) $rojo->id,
            (int) $amarillo->id,
            (int) $viejo->id,
        ], $ids, 'Fijados (más reciente arriba) → último mensaje DESC; el color no reordena.');

        // Y el color se sigue pintando.
        $filas = $this->filas();
        $this->assertSame('rojo', $filas[(int) $rojo->id]['atencion']);
        $this->assertSame('amarillo', $filas[(int) $amarillo->id]['atencion']);
    }

    /**
     * Un mensaje nuevo del lead sube su chat al primer lugar (entre los no fijados).
     *
     * @return void
     */
    public function test_el_ultimo_en_hablar_queda_primero(): void
    {
        $a = $this->crear_lead('A');
        $this->crear_saliente_entregado($a, ['sent_at' => now()->subHours(3)]);
        $b = $this->crear_lead('B');
        $this->crear_saliente_entregado($b, ['sent_at' => now()->subHours(2)]);

        $this->assertSame([(int) $b->id, (int) $a->id], array_keys($this->filas()));

        // A le habla a Lucas: pasa al frente (y queda amarillo, pero no es por eso que sube).
        $this->crear_mensaje($a, ['content' => 'Hola de nuevo', 'sent_at' => now()]);

        $this->assertSame([(int) $a->id, (int) $b->id], array_keys($this->filas()));
        $this->assertSame('amarillo', $this->fila_de($a)['atencion']);
    }

    /**
     * `sin_responder=1` deja exactamente los leads que cuenta el «N sin responder» de la tarjeta
     * (mensaje sin responder o envío fallido, alcanzable), y las claves `sin_responder` y
     * `mensaje_sin_responder` de cada fila dicen lo mismo.
     *
     * @return void
     */
    public function test_sin_responder_deja_exactamente_lo_que_cuenta_la_tarjeta(): void
    {
        $amarillo = $this->crear_lead('Amarillo');
        $this->crear_mensaje($amarillo, ['content' => '¿Cuánto sale?']);

        $rojo_error = $this->crear_lead('Rojo por error');
        $this->crear_mensaje($rojo_error, ['sender' => 'setter', 'content' => 'Te paso los planes', 'whatsapp_delivery_status' => 'fallido']);

        // Rojo por confirmación pendiente, pero con un mensaje del lead sin responder: la tarjeta lo cuenta.
        $rojo_con_mensaje = $this->crear_lead('Rojo con mensaje sin responder');
        $this->crear_mensaje($rojo_con_mensaje, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($rojo_con_mensaje, ['sender' => 'sistema', 'status' => 'sugerido', 'content' => 'Propuesta', 'requiere_verificacion' => true]);

        // Rojo SOLO por confirmación pendiente, sin nada del lead sin responder: la tarjeta no lo cuenta.
        $rojo_confirmacion = $this->crear_lead('Rojo por confirmación');
        $this->crear_saliente_entregado($rojo_confirmacion);
        $this->crear_mensaje($rojo_confirmacion, ['sender' => 'sistema', 'status' => 'sugerido', 'content' => 'Propuesta', 'requiere_verificacion' => true]);

        $contestado = $this->crear_lead('Contestado');
        $this->crear_mensaje($contestado, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($contestado);

        $inalcanzable = $this->crear_lead('Inalcanzable', 'calificado', ['no_recibe_mensajes_at' => now()]);
        $this->crear_mensaje($inalcanzable, ['content' => '¿Cuánto sale?']);

        $esperados = [(int) $amarillo->id, (int) $rojo_error->id, (int) $rojo_con_mensaje->id];
        sort($esperados);

        $filtradas = array_keys($this->filas('sin_responder=1'));
        sort($filtradas);
        $this->assertSame($esperados, $filtradas, 'Mensaje sin responder o envío fallido, alcanzable.');

        // La tarjeta dice lo mismo que el filtro: mismo número, mismas filas.
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/lead/status-cards');
        $response->assertStatus(200);
        $total_tarjetas = 0;
        foreach ($response->json('cards') as $card) {
            if ($card['value'] === 'calificado') {
                $total_tarjetas = (int) $card['sin_responder'];
            }
        }
        $this->assertSame(count($esperados), $total_tarjetas, 'El número del botón es el de las filas.');

        // Las claves de cada fila coinciden con el filtro.
        $todas = $this->filas();
        foreach ($todas as $id => $fila) {
            $this->assertSame(in_array($id, $esperados, true), $fila['sin_responder'], 'sin_responder de ' . $fila['contact_name']);
        }

        // `mensaje_sin_responder`: lo que «Marcar como leído» puede limpiar.
        $this->assertTrue($todas[(int) $amarillo->id]['mensaje_sin_responder']);
        $this->assertTrue($todas[(int) $rojo_con_mensaje->id]['mensaje_sin_responder'], 'Rojo, pero con un mensaje del lead sin responder.');
        $this->assertSame('rojo', $todas[(int) $rojo_con_mensaje->id]['atencion']);
        $this->assertFalse($todas[(int) $rojo_error->id]['mensaje_sin_responder'], 'Rojo por envío fallido, sin nada del lead sin responder.');
        $this->assertFalse($todas[(int) $rojo_confirmacion->id]['mensaje_sin_responder']);
        $this->assertFalse($todas[(int) $contestado->id]['mensaje_sin_responder']);
        $this->assertFalse($todas[(int) $inalcanzable->id]['mensaje_sin_responder'], 'El que no recibe mensajes no pide atención.');
        $this->assertFalse($todas[(int) $inalcanzable->id]['sin_responder']);

        // Se combina con el filtro de estado.
        $this->assertSame([], $this->filas('sin_responder=1&status=nuevo'));
        $otro = $this->crear_lead('Otro estado', 'nuevo');
        $this->crear_mensaje($otro, ['content' => 'Hola']);
        $this->assertSame([(int) $otro->id], array_keys($this->filas('sin_responder=1&status=nuevo')));
    }

    /**
     * El filtro `sin_responder` con los grupos de las tarjetas (`demo`, `otros`), con `q`, con el
     * paginado y con un fijado adentro; y la fila puntual (`show_json`) trae las dos claves nuevas.
     * El número de la tarjeta de grupo tiene que ser el de las filas: es justo lo que se rompió el 2/9.
     *
     * @return void
     */
    public function test_sin_responder_con_grupos_busqueda_paginado_y_fila_puntual(): void
    {
        $demo_a = $this->crear_lead('Demo Ana', 'demo_agendada', ['company_name' => 'Kiosco Ana']);
        $this->crear_mensaje($demo_a, ['content' => 'Hola', 'sent_at' => now()->subMinutes(30)]);
        $demo_b = $this->crear_lead('Demo Beto', 'demo_en_curso', ['pinned_at' => now()]);
        $this->crear_mensaje($demo_b, ['content' => 'Hola', 'sent_at' => now()->subMinutes(20)]);
        $demo_ok = $this->crear_lead('Demo Contestado', 'demo_agendada');
        $this->crear_mensaje($demo_ok, ['content' => 'Hola']);
        $this->crear_saliente_entregado($demo_ok);

        $otro = $this->crear_lead('Otro Carlos', 'nuevo');
        $this->crear_mensaje($otro, ['content' => 'Hola']);

        $calificado = $this->crear_lead('Califi Dora', 'calificado');
        $this->crear_mensaje($calificado, ['content' => 'Hola']);

        // Grupo «demo»: dos sin responder; el fijado va primero.
        $this->assertSame(
            [(int) $demo_b->id, (int) $demo_a->id],
            array_keys($this->filas('sin_responder=1&status=demo')),
            'Grupo demo: los dos sin responder, el fijado primero.'
        );
        // Grupo «otros»: el de estado `nuevo`.
        $this->assertSame([(int) $otro->id], array_keys($this->filas('sin_responder=1&status=otros')));

        // El número de cada tarjeta de grupo es el de las filas.
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/lead/status-cards');
        $response->assertStatus(200);
        $por_tarjeta = [];
        foreach ($response->json('cards') as $card) {
            $por_tarjeta[$card['value']] = (int) $card['sin_responder'];
        }
        $this->assertSame(2, $por_tarjeta['demo']);
        $this->assertSame(1, $por_tarjeta['otros']);
        $this->assertSame(count($this->filas('sin_responder=1&status=demo')), $por_tarjeta['demo']);
        $this->assertSame(count($this->filas('sin_responder=1&status=otros')), $por_tarjeta['otros']);

        // Con búsqueda de texto.
        $this->assertSame([(int) $demo_a->id], array_keys($this->filas('sin_responder=1&status=demo&q=kiosco')));

        // Paginado: el total y las páginas son los del filtro, no los de la lista entera.
        $pagina = $this->chats('sin_responder=1&status=demo&per_page=1');
        $this->assertSame(2, $pagina['meta']['total']);
        $this->assertSame(2, $pagina['meta']['last_page']);
        $this->assertCount(1, $pagina['data']);

        // La fila puntual (la que refresca Pusher) trae las dos claves.
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/lead/chats/' . $demo_a->id);
        $response->assertStatus(200);
        $this->assertTrue($response->json('data.sin_responder'));
        $this->assertTrue($response->json('data.mensaje_sin_responder'));
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/lead/chats/' . $demo_ok->id);
        $this->assertFalse($response->json('data.sin_responder'));
        $this->assertFalse($response->json('data.mensaje_sin_responder'));
    }

    /**
     * «Marcar como leído» (POST marcar-respondido sin message_id) saca al lead del filtro `sin_responder`
     * y le apaga el amarillo; un mensaje nuevo del lead lo vuelve a poner.
     *
     * @return void
     */
    public function test_marcar_como_leido_lo_saca_del_filtro_y_un_mensaje_nuevo_lo_vuelve_a_poner(): void
    {
        $lead = $this->crear_lead('Mensaje de cierre');
        $this->crear_mensaje($lead, ['content' => 'Gracias, chau!']);

        $this->assertSame([(int) $lead->id], array_keys($this->filas('sin_responder=1')));

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/lead/' . $lead->id . '/marcar-respondido');
        $response->assertStatus(200);
        $this->assertFalse($response->json('data.sin_responder'));
        $this->assertFalse($response->json('data.mensaje_sin_responder'));
        $this->assertNull($response->json('data.atencion'));

        $this->assertSame([], $this->filas('sin_responder=1'));

        $this->crear_mensaje($lead, ['content' => 'Ah, una pregunta más']);
        $this->assertSame([(int) $lead->id], array_keys($this->filas('sin_responder=1')));
        $this->assertSame('amarillo', $this->fila_de($lead)['atencion']);
    }

    /**
     * `q` busca por nombre, por empresa y por teléfono (por dígitos: los espacios y guiones no molestan).
     *
     * @return void
     */
    public function test_q_busca_por_nombre_empresa_y_telefono(): void
    {
        $juana  = $this->crear_lead('Juana Pérez', 'calificado', ['company_name' => 'Ferretería El Tornillo', 'phone' => '5493415551234']);
        $pedro  = $this->crear_lead('Pedro Gómez', 'calificado', ['company_name' => 'Kiosco 24', 'phone' => '5491166667777']);
        $otro   = $this->crear_lead('Ana Ruiz', 'calificado', ['company_name' => 'Panadería', 'phone' => '5492211112222']);

        $this->assertSame([(int) $juana->id], array_keys($this->filas('q=juana')), 'Por nombre, sin distinguir mayúsculas.');
        $this->assertSame([(int) $juana->id], array_keys($this->filas('q=tornillo')), 'Por empresa.');
        $this->assertSame([(int) $pedro->id], array_keys($this->filas('q=' . urlencode('11 6666-7777'))), 'Por teléfono, con los dígitos que escribió.');
        $this->assertSame([], array_keys($this->filas('q=inexistente')), 'Sin coincidencias, lista vacía.');

        $todas = $this->filas();
        $this->assertCount(3, $todas, 'Sin q, están los tres.');
        $this->assertArrayHasKey((int) $otro->id, $todas);
    }

    /**
     * `status`: un slug, una lista separada por comas, y los dos valores agrupados de las tarjetas
     * (`demo`, `otros`) resueltos contra el catálogo.
     *
     * @return void
     */
    public function test_status_acepta_un_slug_una_lista_y_los_grupos_de_las_tarjetas(): void
    {
        $nuevo       = $this->crear_lead('Nuevo', 'nuevo');
        $calificado  = $this->crear_lead('Calificado', 'calificado');
        $agendada    = $this->crear_lead('Agendada', 'demo_agendada');
        $en_curso    = $this->crear_lead('En curso', 'demo_en_curso');
        $realizada   = $this->crear_lead('Realizada', 'demo_realizada');
        $closer      = $this->crear_lead('Closer', 'closer_activo');

        $ids = function (string $qs): array {
            $ids = array_keys($this->filas($qs));
            sort($ids);

            return $ids;
        };

        $this->assertSame([(int) $calificado->id], $ids('status=calificado'));

        $esperados = [(int) $calificado->id, (int) $closer->id];
        sort($esperados);
        $this->assertSame($esperados, $ids('status=calificado,closer_activo'));

        // La tarjeta "Demo" agrupa demo_agendada + demo_pendiente_de_ingreso + demo_en_curso (NO demo_realizada).
        $esperados = [(int) $agendada->id, (int) $en_curso->id];
        sort($esperados);
        $this->assertSame($esperados, $ids('status=demo'));

        // La tarjeta "Otros": todo lo que no tiene tarjeta propia (acá: nuevo y demo_realizada).
        $esperados = [(int) $nuevo->id, (int) $realizada->id];
        sort($esperados);
        $this->assertSame($esperados, $ids('status=otros'));

        $this->assertCount(6, $this->filas(), 'Sin status, están todos.');
    }

    /**
     * `solo_atencion=1` deja solo las filas rojas y amarillas.
     *
     * @return void
     */
    public function test_solo_atencion_deja_solo_las_filas_rojas_y_amarillas(): void
    {
        $amarillo = $this->crear_lead('Amarillo');
        $this->crear_mensaje($amarillo, ['content' => '¿Cuánto sale?']);

        $rojo_error = $this->crear_lead('Rojo por error');
        $this->crear_mensaje($rojo_error, ['sender' => 'setter', 'content' => 'Te paso los planes', 'whatsapp_delivery_status' => 'fallido']);

        $rojo_confirmacion = $this->crear_lead('Rojo por confirmación');
        $this->crear_mensaje($rojo_confirmacion, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($rojo_confirmacion, ['sender' => 'sistema', 'status' => 'sugerido', 'content' => 'Propuesta', 'requiere_verificacion' => true]);

        $contestado = $this->crear_lead('Contestado');
        $this->crear_mensaje($contestado, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($contestado);

        $inalcanzable = $this->crear_lead('Inalcanzable', 'calificado', ['no_recibe_mensajes_at' => now()]);
        $this->crear_mensaje($inalcanzable, ['sender' => 'setter', 'content' => 'Te paso los planes', 'whatsapp_delivery_status' => 'fallido']);

        $vacio = $this->crear_lead('Sin mensajes', 'nuevo');

        $con_atencion = array_keys($this->filas('solo_atencion=1'));
        sort($con_atencion);

        $esperados = [(int) $amarillo->id, (int) $rojo_error->id, (int) $rojo_confirmacion->id];
        sort($esperados);

        $this->assertSame($esperados, $con_atencion, 'Ni el contestado, ni el inalcanzable, ni el vacío piden atención.');

        // Y el filtro dice lo mismo que el color de cada fila: ninguna de las filtradas viene con atencion null.
        foreach ($this->filas('solo_atencion=1') as $fila) {
            $this->assertNotNull($fila['atencion']);
        }

        $this->assertCount(6, $this->filas('solo_atencion=0'), 'Con solo_atencion=0 están todos.');
        $this->assertArrayHasKey((int) $vacio->id, $this->filas());
    }

    /**
     * Paginado `{data, meta}`: `per_page` default 50 y techo 200, `page` default 1.
     *
     * @return void
     */
    public function test_el_paginado_respeta_per_page_y_page(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->crear_lead('Lead ' . $i);
        }

        $primera = $this->chats('per_page=2');
        $this->assertCount(2, $primera['data']);
        $this->assertSame(['current_page' => 1, 'last_page' => 3, 'per_page' => 2, 'total' => 5], $primera['meta']);

        $segunda = $this->chats('per_page=2&page=2');
        $this->assertCount(2, $segunda['data']);
        $this->assertSame(2, $segunda['meta']['current_page']);

        $tercera = $this->chats('per_page=2&page=3');
        $this->assertCount(1, $tercera['data']);

        // Sin repetidos entre páginas.
        $ids = array_merge(
            array_column($primera['data'], 'id'),
            array_column($segunda['data'], 'id'),
            array_column($tercera['data'], 'id')
        );
        $this->assertCount(5, array_unique($ids), 'Las tres páginas cubren los cinco leads sin repetir.');

        $defaults = $this->chats();
        $this->assertSame(50, $defaults['meta']['per_page'], 'per_page default 50.');
        $this->assertSame(1, $defaults['meta']['current_page'], 'page default 1.');

        $techo = $this->chats('per_page=999');
        $this->assertSame(200, $techo['meta']['per_page'], 'per_page tiene techo 200.');
    }

    /**
     * `GET lead/chats/{id}` devuelve la misma fila que la lista, y 404 si el lead no existe.
     *
     * @return void
     */
    public function test_una_sola_fila_y_404_si_no_existe(): void
    {
        $lead = $this->crear_lead('Uno solo');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/lead/chats/' . $lead->id);
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertSame(['data'], array_keys($json));
        $this->assertSame($this->fila_de($lead), $json['data'], 'La fila sola es idéntica a la de la lista.');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/lead/chats/999999999')
            ->assertStatus(404);
    }

    /**
     * `lead/chats` no se la come `lead/{id}`: la ruta está declarada antes.
     *
     * @return void
     */
    public function test_la_ruta_de_chats_no_la_captura_lead_id(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/lead/chats');

        $response->assertStatus(200);
        $this->assertArrayHasKey('meta', $response->json(), 'Si lead/{id} hubiera capturado "chats", esto sería un 404 o un modelo.');
    }
}
