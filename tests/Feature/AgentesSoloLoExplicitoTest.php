<?php

namespace Tests\Feature;

use App\Jobs\SendSupportAiSuggestion;
use App\Models\Admin;
use App\Models\AdminPushSubscription;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\EscalationPushNotificationService;
use App\Services\KnowledgeGroundingGate;
use App\Services\SupportAiSuggestionScheduler;
use App\Services\SupportAiSuggestionService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Los agentes responden solo lo que está explícito en el repositorio, o escalan.
 *
 * Lo que importa medir acá son las AUSENCIAS, igual que en las misiones anteriores del agente:
 * que una afirmación sin respaldo NO le llegue al cliente, que el texto que redactó el agente NO
 * sobreviva al escalado, y que con el repositorio caído NI SIQUIERA se le pregunte a Claude.
 *
 * El grueso de los tests le pega a KnowledgeGroundingGate directamente y sin base de por medio:
 * el criterio de "cuándo una respuesta está respaldada" es la parte con consecuencias, y poder
 * ejercitarlo entero sin red es la razón por la que esa clase es pura.
 */
class AgentesSoloLoExplicitoTest extends TestCase
{
    use DatabaseTransactions;

    /*
     * 🔴 A propósito NO hay un `Http::fake()` sin argumentos en un setUp().
     *
     * Registra un comodín que le gana a cualquier stub que se agregue después, porque los stubs
     * se resuelven en orden de registro y el primero que matchea contesta. Con él puesto acá, el
     * fake específico de la API que arma cada test de leads no llegaría a usarse nunca y las
     * respuestas del modelo saldrían vacías. Cada helper fakea lo que su camino necesita: los
     * tests del gate no salen a ningún lado, los de soporte sustituyen los servicios, y los de
     * leads fakean la API con el paquete que quieren probar.
     */

    /* ==================================================================================
     * El gate, sin base ni red
     * ================================================================================== */

    /**
     * Con el protocolo viejo todavía vivo, el gate no frena nada.
     *
     * Es la mitad que hace que este cambio se pueda desplegar: los .md sincronizan solos y el
     * código sube cuando Lucas corre el deploy. Entre un momento y el otro el modelo no tiene
     * forma de saber que le pedimos fuentes, y sin esta puerta el gate rechazaría todo.
     *
     * @return void
     */
    public function test_con_el_protocolo_viejo_el_gate_deja_pasar_todo()
    {
        $gate = new KnowledgeGroundingGate();

        $this->assertFalse(
            $gate->esta_activo('Sos un asistente de soporte. Leé el manual antes de responder.'),
            'El gate se activó con un prompt que no menciona el contrato de fuentes.'
        );

        $veredicto = $gate->evaluar(false, null, null, []);

        $this->assertTrue($veredicto['permitido'], 'Con el gate inactivo se frenó una respuesta igual.');
    }

    /**
     * El gate se enciende solo cuando el protocolo que lo explica llegó al prompt.
     *
     * @return void
     */
    public function test_el_gate_se_activa_al_ver_el_contrato_en_el_prompt()
    {
        $gate = new KnowledgeGroundingGate();

        $this->assertTrue(
            $gate->esta_activo('... completá fuentes_kb con las rutas que leíste ...'),
            'El gate no se activó con el protocolo nuevo en el prompt.'
        );
    }

    /**
     * Sin tipo de respuesta declarado, se escala.
     *
     * El default ante lo desconocido tiene que ser el seguro. Un modelo que ignoró el formato,
     * o un prompt a medio sincronizar, dejan al sistema sin saber qué está por mandar — y mandar
     * a ciegas es exactamente lo que este gate viene a impedir.
     *
     * @return void
     */
    public function test_sin_tipo_declarado_se_escala()
    {
        $veredicto = (new KnowledgeGroundingGate())->evaluar(true, null, ['listado/precios.md'], ['listado/precios.md']);

        $this->assertFalse($veredicto['permitido'], 'Una respuesta sin tipo declarado pasó el gate.');
        $this->assertNotSame('', $veredicto['motivo'], 'El escalado no trae motivo para el operador.');
    }

    /**
     * Un tipo que no existe se trata igual que ninguno.
     *
     * @return void
     */
    public function test_un_tipo_inventado_se_escala()
    {
        $veredicto = (new KnowledgeGroundingGate())->evaluar(true, 'respuesta_copada', [], []);

        $this->assertFalse($veredicto['permitido'], 'Un tipo inventado pasó el gate.');
    }

    /**
     * Saludar y preguntar no afirman nada del sistema: no necesitan respaldo.
     *
     * Sin esta puerta el gate escalaría cada "hola" y cada pedido de aclaración, y el escalado
     * se volvería ruido que se ignora — la forma más rápida de perder la señal que este cambio
     * viene a construir.
     *
     * @return void
     */
    public function test_saludar_y_preguntar_no_necesitan_respaldo()
    {
        $gate = new KnowledgeGroundingGate();

        $this->assertTrue(
            $gate->evaluar(true, KnowledgeGroundingGate::TIPO_CONVERSACIONAL, [], [])['permitido'],
            'Un saludo quedó frenado por falta de fuentes.'
        );

        $this->assertTrue(
            $gate->evaluar(true, KnowledgeGroundingGate::TIPO_ACLARACION, [], [])['permitido'],
            'Una pregunta de aclaración quedó frenada por falta de fuentes.'
        );
    }

    /**
     * Cuando el propio agente pide escalar, el gate no tiene nada que verificar.
     *
     * @return void
     */
    public function test_el_escalado_propio_del_agente_pasa()
    {
        $veredicto = (new KnowledgeGroundingGate())->evaluar(true, KnowledgeGroundingGate::TIPO_ESCALADO, [], []);

        $this->assertTrue($veredicto['permitido'], 'El gate frenó un escalado que el agente ya había pedido.');
    }

    /**
     * Afirmar algo del sistema sin citar nada se escala.
     *
     * @return void
     */
    public function test_afirmar_sin_citar_nada_se_escala()
    {
        $veredicto = (new KnowledgeGroundingGate())->evaluar(
            true,
            KnowledgeGroundingGate::TIPO_AFIRMACION,
            [],
            ['listado/precios.md']
        );

        $this->assertFalse($veredicto['permitido'], 'Una afirmación sin fuentes pasó el gate.');
    }

    /**
     * 🔴 El caso central: citar un archivo que no se leyó se escala.
     *
     * Es la diferencia entre una regla escrita en el prompt y una garantía. Un modelo puede
     * declarar cualquier ruta —del índice, de otra conversación, inventada—; lo único que cuenta
     * es lo que el ejecutor de tools pudo servir de verdad en esta consulta.
     *
     * @return void
     */
    public function test_citar_lo_que_no_se_leyo_se_escala()
    {
        $veredicto = (new KnowledgeGroundingGate())->evaluar(
            true,
            KnowledgeGroundingGate::TIPO_AFIRMACION,
            ['abm/precios-cobros.md'],
            ['listado/precios.md']
        );

        $this->assertFalse($veredicto['permitido'], 'El agente citó un archivo que nunca leyó y la respuesta salió igual.');
        $this->assertStringContainsString(
            'abm/precios-cobros.md',
            $veredicto['motivo'],
            'El motivo no dice qué archivo se citó sin haberlo leído.'
        );
    }

    /**
     * Citar una sola de las dos fuentes leídas está bien; citar una que falta, no.
     *
     * @return void
     */
    public function test_alcanza_con_que_todas_las_citadas_esten_leidas()
    {
        $gate = new KnowledgeGroundingGate();

        $this->assertTrue(
            $gate->evaluar(
                true,
                KnowledgeGroundingGate::TIPO_AFIRMACION,
                ['listado/precios.md'],
                ['listado/precios.md', 'listado/stock.md']
            )['permitido'],
            'Citar menos archivos de los leídos se frenó sin motivo.'
        );

        $this->assertFalse(
            $gate->evaluar(
                true,
                KnowledgeGroundingGate::TIPO_AFIRMACION,
                ['listado/precios.md', 'general/interfaz-tablas-y-formularios.md'],
                ['listado/precios.md']
            )['permitido'],
            'Una sola cita sin respaldo entre varias no frenó la respuesta.'
        );
    }

    /**
     * Una afirmación bien respaldada sale.
     *
     * @return void
     */
    public function test_una_afirmacion_respaldada_sale()
    {
        $veredicto = (new KnowledgeGroundingGate())->evaluar(
            true,
            KnowledgeGroundingGate::TIPO_AFIRMACION,
            ['listado/precios.md'],
            ['listado/precios.md']
        );

        $this->assertTrue($veredicto['permitido'], 'Una afirmación con respaldo real quedó frenada.');
        $this->assertSame('', $veredicto['motivo']);
    }

    /**
     * Las diferencias que no cambian a qué archivo se refiere el agente no frenan la respuesta.
     *
     * Mayúsculas, espacios y una barra al principio son ruido de tipeo del modelo. Escalar por
     * eso sería castigar al agente por algo que hizo bien.
     *
     * @return void
     */
    public function test_las_diferencias_de_forma_no_frenan_la_respuesta()
    {
        $veredicto = (new KnowledgeGroundingGate())->evaluar(
            true,
            KnowledgeGroundingGate::TIPO_AFIRMACION,
            ['  /Listado/Precios.MD  '],
            ['listado/precios.md']
        );

        $this->assertTrue($veredicto['permitido'], 'Una cita idéntica salvo mayúsculas y espacios se frenó.');
    }

    /**
     * Dos archivos con el mismo nombre en carpetas distintas NO son el mismo archivo.
     *
     * Es el límite de la normalización de arriba: aflojar hasta acá dejaría pasar una cita a
     * `precios.md` respaldada por haber leído `listado/precios.md`, que es otro documento con
     * otro contenido.
     *
     * @return void
     */
    public function test_el_mismo_nombre_en_otra_carpeta_no_alcanza()
    {
        $veredicto = (new KnowledgeGroundingGate())->evaluar(
            true,
            KnowledgeGroundingGate::TIPO_AFIRMACION,
            ['precios.md'],
            ['listado/precios.md']
        );

        $this->assertFalse($veredicto['permitido'], 'Se dio por respaldada una cita a un archivo de otra carpeta.');
    }

    /**
     * Una fuente sola mandada como string, y no como array, se entiende igual.
     *
     * Es una desviación del formato que no cambia lo que el agente quiso decir, y frenarla
     * sería escalar por una coma.
     *
     * @return void
     */
    public function test_una_fuente_suelta_sin_envolver_se_entiende()
    {
        $veredicto = (new KnowledgeGroundingGate())->evaluar(
            true,
            KnowledgeGroundingGate::TIPO_AFIRMACION,
            'listado/precios.md',
            ['listado/precios.md']
        );

        $this->assertTrue($veredicto['permitido'], 'Una fuente mandada como string suelto se frenó.');
    }

    /**
     * Con el repositorio caído no hay respuesta posible.
     *
     * @return void
     */
    public function test_el_repositorio_caido_escala()
    {
        $veredicto = (new KnowledgeGroundingGate())->escalar_por_repositorio_caido('no se pudo leer el índice');

        $this->assertFalse($veredicto['permitido']);
        $this->assertStringContainsString('repositorio', $veredicto['motivo']);
    }

    /* ==================================================================================
     * El agente de soporte, de punta a punta
     * ================================================================================== */

    /**
     * 🔴 Una afirmación sin respaldo no le llega al cliente, y el texto del agente se descarta.
     *
     * Es el criterio de aceptación de la misión: no alcanza con marcar el ticket, lo que no
     * puede pasar es que el cliente lea la afirmación inventada.
     *
     * @return void
     */
    public function test_una_afirmacion_sin_respaldo_no_le_llega_al_cliente()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();

        $this->espiar_claude([
            'suggested_message' => 'Sí, los presupuestos se pueden hacer en dólares sin problema.',
            'reasoning'         => 'Lo deduje del costo en dólares.',
            'should_close'      => false,
            'should_escalate'   => false,
            'escalation_reason' => null,
            'tipo_respuesta'    => KnowledgeGroundingGate::TIPO_AFIRMACION,
            'fuentes_kb'        => [],
            'gate_permitido'    => false,
            'gate_motivo'       => 'El agente afirmó algo sobre el sistema sin citar ningún documento.',
        ]);

        $this->correr_agente($ticket);

        $escalado = SupportTicket::find($ticket->id);
        $this->assertNotNull($escalado->escalated_at, 'El ticket no quedó escalado.');
        $this->assertStringContainsString('sin citar', (string) $escalado->escalation_reason);

        /* El ticket nace con la verificación prendida, así que nada sale solo: lo que se
         * verifica es que el texto inventado no exista en ningún lado del hilo. */
        $cuerpos = SupportMessage::where('support_ticket_id', $ticket->id)
            ->pluck('body')
            ->all();

        foreach ($cuerpos as $cuerpo) {
            $this->assertStringNotContainsString(
                'en dólares sin problema',
                (string) $cuerpo,
                'La afirmación sin respaldo quedó guardada como mensaje del hilo.'
            );
        }

        foreach ($espia->textos as $texto) {
            $this->assertStringNotContainsString(
                'en dólares sin problema',
                (string) $texto['body'],
                'La afirmación sin respaldo se le mandó al cliente.'
            );
        }
    }

    /**
     * Lo que queda en el hilo es el mensaje de espera, no el del agente.
     *
     * @return void
     */
    public function test_al_frenar_queda_el_mensaje_de_espera()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->espiar_sender();

        $this->espiar_claude([
            'suggested_message' => 'Sí, se integra con WooCommerce.',
            'reasoning'         => '',
            'should_close'      => false,
            'should_escalate'   => false,
            'escalation_reason' => null,
            'tipo_respuesta'    => KnowledgeGroundingGate::TIPO_AFIRMACION,
            'fuentes_kb'        => ['abm/tienda-integraciones.md'],
            'gate_permitido'    => false,
            'gate_motivo'       => 'Citó un documento que no leyó.',
        ]);

        $this->correr_agente($ticket);

        $borrador = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($borrador, 'No quedó ningún borrador para que lo conteste una persona.');
        $this->assertStringContainsString(
            'Dame un momento',
            (string) $borrador->body,
            'El borrador no es el mensaje de espera.'
        );
        $this->assertStringNotContainsString('WooCommerce', (string) $borrador->body);
    }

    /**
     * Un resultado sin veredicto del gate no frena nada.
     *
     * "Nadie evaluó" no es lo mismo que "evaluó y rechazó". Tratar la ausencia como rechazo
     * frenaría todo mensaje generado por un servicio sustituido o por una versión anterior a
     * este cambio, que es justo el estado en el que queda producción entre el push de los .md
     * y el deploy.
     *
     * @return void
     */
    public function test_sin_veredicto_del_gate_el_mensaje_sigue_su_curso()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->espiar_sender();

        $this->espiar_claude([
            'suggested_message' => 'Entrás a Listado y tocás el botón de precios.',
            'reasoning'         => '',
            'should_close'      => false,
            'should_escalate'   => false,
            'escalation_reason' => null,
        ]);

        $this->correr_agente($ticket);

        $escalado = SupportTicket::find($ticket->id);
        $this->assertNull($escalado->escalated_at, 'Se escaló un mensaje que nadie había evaluado.');

        $borrador = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->first();

        $this->assertNotNull($borrador, 'El mensaje se perdió en vez de quedar como borrador.');
        $this->assertStringContainsString('Listado', (string) $borrador->body);
    }

    /**
     * Una respuesta bien respaldada sigue su camino normal.
     *
     * @return void
     */
    public function test_una_respuesta_respaldada_no_se_frena()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->espiar_sender();

        $this->espiar_claude([
            'suggested_message' => 'Sí, podés tener varias listas de precios.',
            'reasoning'         => 'Está en listado/precios.md.',
            'should_close'      => false,
            'should_escalate'   => false,
            'escalation_reason' => null,
            'tipo_respuesta'    => KnowledgeGroundingGate::TIPO_AFIRMACION,
            'fuentes_kb'        => ['listado/precios.md'],
            'gate_permitido'    => true,
            'gate_motivo'       => '',
        ]);

        $this->correr_agente($ticket);

        $escalado = SupportTicket::find($ticket->id);
        $this->assertNull($escalado->escalated_at, 'Se escaló una respuesta que estaba respaldada.');

        $borrador = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->first();

        $this->assertNotNull($borrador);
        $this->assertStringContainsString('listas de precios', (string) $borrador->body);
    }

    /**
     * 🔴 El motivo del escalado dice qué preguntó el cliente.
     *
     * Sin esto el aviso se pierde, y no por un detalle de redacción: `handle_escalation()` no
     * vuelve a avisar cuando el motivo es idéntico al del escalado anterior, y los motivos que
     * redacta el gate son genéricos. Tres preguntas distintas sin respuesta darían el mismo
     * texto y Lucas se enteraría solo de la primera — justo lo contrario del objetivo, que es
     * contestar cada una y completar el repositorio.
     *
     * @return void
     */
    public function test_el_motivo_del_escalado_dice_que_pregunto_el_cliente()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->espiar_sender();

        $this->espiar_claude([
            'suggested_message' => 'Sí, se puede.',
            'reasoning'         => '',
            'should_close'      => false,
            'should_escalate'   => false,
            'escalation_reason' => null,
            'tipo_respuesta'    => KnowledgeGroundingGate::TIPO_AFIRMACION,
            'fuentes_kb'        => [],
            'gate_permitido'    => false,
            'gate_motivo'       => 'El agente afirmó algo sobre el sistema sin citar ningún documento.',
        ]);

        $this->correr_agente($ticket);

        $motivo = (string) SupportTicket::find($ticket->id)->escalation_reason;

        $this->assertStringContainsString(
            'presupuestos se pueden hacer en dólares',
            $motivo,
            'El motivo no dice qué preguntó el cliente, así que dos consultas distintas darían el mismo texto.'
        );
    }

    /**
     * Con el repositorio caído el cliente igual recibe el mensaje de espera.
     *
     * Ese camino escala sin llegar a consultar a Claude, así que no hay ningún texto del agente
     * que respetar. Sin el mensaje del protocolo el cliente se quedaría en silencio justo cuando
     * el sistema no puede contestarle, que es el peor momento para no decir nada.
     *
     * @return void
     */
    public function test_con_el_repositorio_caido_el_cliente_igual_recibe_la_espera()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->espiar_sender();

        /* Lo que devuelve generate() cuando no pudo cargar el manual: escalado, sin texto. */
        $this->espiar_claude([
            'suggested_message' => '',
            'reasoning'         => 'No se pudo consultar el repositorio de conocimiento.',
            'should_close'      => false,
            'should_escalate'   => true,
            'escalation_reason' => 'No se pudo consultar el repositorio de conocimiento.',
            'tipo_respuesta'    => KnowledgeGroundingGate::TIPO_ESCALADO,
            'fuentes_kb'        => [],
            'gate_permitido'    => false,
            'gate_motivo'       => 'No se pudo consultar el repositorio de conocimiento.',
        ]);

        $this->correr_agente($ticket);

        $borrador = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('is_ai_suggestion_draft', true)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($borrador, 'El cliente se quedó sin ningún mensaje de espera.');
        $this->assertStringContainsString('Dame un momento', (string) $borrador->body);

        $this->assertNotNull(
            SupportTicket::find($ticket->id)->escalated_at,
            'El ticket no quedó escalado.'
        );
    }

    /* ==================================================================================
     * El aviso: push primero, WhatsApp como red de seguridad
     * ================================================================================== */

    /**
     * Al operador con device registrado le llega el push, y NO le llega el WhatsApp.
     *
     * Los dos canales en paralelo serían pagar una plantilla por un aviso que ya llegó al
     * teléfono.
     *
     * @return void
     */
    public function test_con_device_registrado_avisa_por_push_y_no_por_whatsapp()
    {
        $suscrito                                     = $this->crear_admin('push-con-device@test.local');
        $suscrito->phone_number                       = '+5493410000011';
        $suscrito->notify_support_escalation_whatsapp = true;
        $suscrito->save();

        $this->registrar_device($suscrito);

        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();
        $push   = $this->espiar_push();

        $this->espiar_claude([
            'suggested_message' => 'Dame un momento, por favor.',
            'reasoning'         => '',
            'should_close'      => false,
            'should_escalate'   => true,
            'escalation_reason' => 'No está cubierto por el manual',
        ]);

        $this->correr_agente($ticket);

        $this->assertCount(1, $push->enviados, 'No salió el push al operador con device registrado.');
        $this->assertSame((int) $suscrito->id, $push->enviados[0]['admin_id']);
        $this->assertStringContainsString(
            '/soporte?ticket_id=' . $ticket->id,
            (string) $push->enviados[0]['data']['url'],
            'El push no abre el ticket.'
        );
        $this->assertStringContainsString('No está cubierto por el manual', (string) $push->enviados[0]['cuerpo']);

        $this->assertCount(0, $espia->plantillas, 'Se pagó una plantilla de WhatsApp por un aviso que ya salió por push.');
    }

    /**
     * Al operador SIN ningún device le llega el WhatsApp de siempre.
     *
     * Es la red de seguridad: un push que falla se ve en el log del push service; un operador
     * sin device no se ve en ningún lado, y se quedaría sin enterarse de nada.
     *
     * @return void
     */
    public function test_sin_device_registrado_sigue_avisando_por_whatsapp()
    {
        $suscrito                                     = $this->crear_admin('push-sin-device@test.local');
        $suscrito->phone_number                       = '+5493410000012';
        $suscrito->notify_support_escalation_whatsapp = true;
        $suscrito->save();

        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();
        $push   = $this->espiar_push();

        $this->espiar_claude([
            'suggested_message' => 'Dame un momento, por favor.',
            'reasoning'         => '',
            'should_close'      => false,
            'should_escalate'   => true,
            'escalation_reason' => 'Reclamo de facturación',
        ]);

        $this->correr_agente($ticket);

        $this->assertCount(0, $push->enviados, 'Se intentó un push a un operador sin ningún device.');
        $this->assertCount(1, $espia->plantillas, 'El operador sin device se quedó sin aviso.');
        $this->assertSame('+5493410000012', $espia->plantillas[0]['to']);
    }

    /* ==================================================================================
     * El agente de leads
     * ================================================================================== */

    /**
     * 🔴 Una afirmación sin respaldo NO le llega al lead: se le pisa el texto y va a una persona.
     *
     * @return void
     */
    public function test_en_leads_la_afirmacion_sin_respaldo_no_sale()
    {
        $this->sembrar_protocolo_de_leads();

        $this->fakear_claude([
            'mensaje_sugerido'  => 'Sí, los presupuestos los podés hacer en dólares.',
            'estado_sugerido'   => 'calificado',
            'razonamiento'      => 'Lo deduje.',
            'tipo_respuesta'    => KnowledgeGroundingGate::TIPO_AFIRMACION,
            'fuentes_kb'        => [],
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(\App\Services\LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringNotContainsString(
            'en dólares',
            (string) $mensaje->content,
            'La afirmación sin respaldo quedó como mensaje sugerido para el lead.'
        );
        $this->assertStringContainsString('Dame un momento', (string) $mensaje->content);
        $this->assertTrue((bool) $mensaje->requiere_verificacion, 'El mensaje no quedó marcado para verificación.');

        $this->assertTrue(
            (bool) $lead->refresh()->requiere_intervencion_humana,
            'El lead no quedó derivado a una persona.'
        );
    }

    /**
     * 🔴 El gate también corta la segunda llamada de disponibilidad.
     *
     * Sin este corte el gate no protegería el camino de agendamiento:
     * `generate_suggestion_with_availability()` arma un paquete NUEVO desde cero, y los flags
     * que puso el gate se perderían enteros. Se mide contando los requests a la API: tienen que
     * ser exactamente uno.
     *
     * @return void
     */
    public function test_en_leads_el_gate_corta_la_segunda_llamada_de_disponibilidad()
    {
        $this->sembrar_protocolo_de_leads();

        $this->fakear_claude([
            'mensaje_sugerido'        => 'Tengo disponibilidad mañana de 18 a 20.',
            'estado_sugerido'         => 'calificado',
            'razonamiento'            => '',
            'solicita_disponibilidad' => true,
            'dia_solicitado'          => 'manana',
            'tipo_respuesta'          => KnowledgeGroundingGate::TIPO_AFIRMACION,
            'fuentes_kb'              => ['demo_agenda'],
        ]);

        $lead = $this->crear_lead();
        app(\App\Services\LeadAiService::class)->generate_suggestion($lead, false);

        $llamadas = 0;
        Http::recorded(function ($request) use (&$llamadas) {
            if (strpos($request->url(), 'api.anthropic.com') !== false) {
                $llamadas++;
            }

            return false;
        });

        $this->assertSame(
            1,
            $llamadas,
            'Salió la segunda llamada de disponibilidad: el gate no protegió el camino de agendamiento.'
        );

        $this->assertTrue(
            (bool) $lead->refresh()->requiere_intervencion_humana,
            'El lead no quedó derivado a una persona.'
        );
    }

    /**
     * Coordinar la agenda es conversacional y no exige respaldo: el flujo normal sigue igual.
     *
     * @return void
     */
    public function test_en_leads_lo_conversacional_no_se_frena()
    {
        $this->sembrar_protocolo_de_leads();

        $this->fakear_claude([
            'mensaje_sugerido' => 'Dale, buenísimo. ¿Cómo es tu nombre?',
            'estado_sugerido'  => 'contactado',
            'razonamiento'     => '',
            'tipo_respuesta'   => KnowledgeGroundingGate::TIPO_CONVERSACIONAL,
            'fuentes_kb'       => [],
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(\App\Services\LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('¿Cómo es tu nombre?', (string) $mensaje->content);
        $this->assertFalse(
            (bool) $lead->refresh()->requiere_intervencion_humana,
            'Se derivó a una persona un saludo que no afirmaba nada del sistema.'
        );
    }

    /**
     * Con el protocolo viejo todavía sincronizado, el agente de leads trabaja como antes.
     *
     * @return void
     */
    public function test_en_leads_con_el_protocolo_viejo_no_cambia_nada()
    {
        /* System base sin el contrato: es el estado de producción entre el deploy del código y
         * la sincronización de los .md. */
        $this->sembrar_protocolo_de_leads(false);

        $this->fakear_claude([
            'mensaje_sugerido' => 'Sí, se puede hacer.',
            'estado_sugerido'  => 'calificado',
            'razonamiento'     => '',
        ]);

        $lead    = $this->crear_lead();
        $mensaje = app(\App\Services\LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('Sí, se puede hacer.', (string) $mensaje->content);
        $this->assertFalse(
            (bool) $lead->refresh()->requiere_intervencion_humana,
            'El gate frenó una respuesta con el protocolo viejo, que no le pide fuentes al modelo.'
        );
    }

    /* ==================================================================================
     * Helpers
     * ================================================================================== */

    /**
     * Deja en base el system prompt y el system base que `build_system_prompt()` exige.
     *
     * Sin estas dos filas el método TIRA, y el archivo entero daría rojo por configuración y no
     * por el código bajo prueba.
     *
     * @param bool $con_contrato Si el system base menciona el contrato de fuentes, que es lo que
     *                           enciende el gate.
     *
     * @return void
     */
    private function sembrar_protocolo_de_leads(bool $con_contrato = true): void
    {
        \App\Models\AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire.',
            'activa'      => true,
        ]);

        \App\Models\SyncedGithubFile::create([
            'key'       => \App\Services\WhatsappProtocolService::SYSTEM_BASE_KEY,
            'repo_path' => 'agentes/lead/recursos/README.md',
            'content'   => $con_contrato
                ? 'System base de prueba. Completá fuentes_kb con los recursos que pediste.'
                : 'System base de prueba, sin el contrato nuevo.',
            'synced_at' => now(),
        ]);
    }

    /**
     * Fakea la API de Anthropic para que devuelva el paquete indicado.
     *
     * @param array<string, mixed> $paquete JSON que devuelve el modelo.
     *
     * @return void
     */
    private function fakear_claude(array $paquete): void
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [[
                    'type' => 'text',
                    'text' => json_encode($paquete, JSON_UNESCAPED_UNICODE),
                ]],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * Lead mínimo.
     *
     * @return \App\Models\Lead
     */
    private function crear_lead(): \App\Models\Lead
    {
        $lead               = new \App\Models\Lead();
        $lead->uuid         = (string) \Illuminate\Support\Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = 'calificado';
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Operador del admin.
     *
     * @param string $email Email único.
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
     * Registra un device de Web Push para el admin.
     *
     * @param Admin $admin Dueño del device.
     *
     * @return AdminPushSubscription
     */
    private function registrar_device(Admin $admin): AdminPushSubscription
    {
        $endpoint = 'https://push.example.test/' . $admin->id;

        return AdminPushSubscription::create([
            'admin_id'      => $admin->id,
            'endpoint'      => $endpoint,
            'endpoint_hash' => AdminPushSubscription::hash_endpoint($endpoint),
            'p256dh'        => 'clave-publica-de-prueba',
            'auth'          => 'clave-auth-de-prueba',
        ]);
    }

    /**
     * Cliente activo con teléfono.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client            = new Client();
        $client->name      = 'Distribuidora de prueba';
        $client->phone     = '+5493416660001';
        $client->is_active = true;
        $client->api_url   = 'https://api-cliente-explicito.test';
        $client->api_key   = 'clave-de-prueba';
        $client->save();

        return $client;
    }

    /**
     * Ticket de WhatsApp abierto con un entrante reciente del cliente.
     *
     * @param Client $client Cliente dueño.
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Contacto',
            'name'             => 'Consulta de prueba',
            'status'           => 'open',
            'source'           => 'whatsapp',
            'whatsapp_phone'   => '+5493416660001',
            'opened_at'        => now()->subHours(2),
            // Explícito y no heredado de la config global: estos tests miden el gate de respaldo
            // documental, no el régimen de nacimiento, y el gate solo se ve si la respuesta queda
            // en borrador. El hook `creating` respeta el valor que se le pase.
            'requiere_verificacion_mensajes' => true,
        ]);

        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => '¿Los presupuestos se pueden hacer en dólares?',
            'delivered_at'      => now()->subMinutes(5),
        ]);

        return $ticket;
    }

    /**
     * Sustituye el servicio que llama a Claude por uno que devuelve lo que se le indique.
     *
     * @param array<string, mixed> $resultado Lo que devuelve generate().
     *
     * @return SupportAiSuggestionService
     */
    private function espiar_claude(array $resultado): SupportAiSuggestionService
    {
        $espia = new class extends SupportAiSuggestionService {
            /** @var array<string, mixed> Respuesta simulada. */
            public $resultado = [];

            public function generate(SupportTicket $ticket): array
            {
                return $this->resultado;
            }
        };

        $espia->resultado = $resultado;
        $this->app->instance(SupportAiSuggestionService::class, $espia);

        /* El camino de soporte tiene los servicios sustituidos, pero un comodín acá cuesta nada
         * y garantiza que ningún request se escape a la red de verdad. */
        Http::fake();

        return $espia;
    }

    /**
     * Sustituye WhatsappSendService por un espía.
     *
     * @return WhatsappSendService
     */
    private function espiar_sender(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Envíos de texto libre. */
            public $textos = [];

            /** @var array<int, array<string, mixed>> Envíos de plantilla. */
            public $plantillas = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.texto.' . count($this->textos);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = [
                    'to'            => $to,
                    'template_name' => $template_name,
                    'variables'     => $variables,
                ];

                return 'wamid.plantilla.' . count($this->plantillas);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Sustituye el envío real de Web Push, que necesitaría claves VAPID y salir a la red.
     *
     * @return EscalationPushNotificationService
     */
    private function espiar_push(): EscalationPushNotificationService
    {
        $espia = new class extends EscalationPushNotificationService {
            /** @var array<int, array<string, mixed>> Push despachados. */
            public $enviados = [];

            protected function send_push(int $admin_id, string $titulo, string $cuerpo, array $data): void
            {
                $this->enviados[] = [
                    'admin_id' => $admin_id,
                    'titulo'   => $titulo,
                    'cuerpo'   => $cuerpo,
                    'data'     => $data,
                ];
            }
        };

        $this->app->instance(EscalationPushNotificationService::class, $espia);

        return $espia;
    }

    /**
     * Corre el job del agente sobre el ticket, con el token de debounce vigente.
     *
     * @param SupportTicket $ticket Ticket a procesar.
     *
     * @return void
     */
    private function correr_agente(SupportTicket $ticket): void
    {
        // En los tests la cola es `sync`, así que el propio scheduler ejecuta el job en el acto:
        // despacharlo otra vez a mano lo corría dos veces y duplicaba cada envío.
        app(SupportAiSuggestionScheduler::class)->schedule_after_client_inbound((int) $ticket->id);
    }
}
