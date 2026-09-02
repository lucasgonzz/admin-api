<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadPendingReviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Test de paridad entre las DOS implementaciones de "este lead amerita revisión".
 *
 * Hay dos, y tiene que haberlas:
 *
 * - `LeadPendingReviewService::lead_requiere_revision()`, en PHP, que recorre los mensajes del lead
 *   uno por uno. Es la que manda el botón de revisión de la barra de leads.
 * - `Lead::scopeRequiereRevision()`, en SQL, que decide lo mismo con dos `EXISTS`. Es la que cuenta
 *   el "sin responder" de las tarjetas de estado, porque hidratar el hilo entero de todos los leads
 *   en cada carga de la vista no es viable (la relación `messages` arrastra `with([...])`).
 *
 * Mientras el número de la tarjeta diga "lo mismo que el botón", estas dos tienen que dar
 * exactamente el mismo conjunto de leads. Es una equivalencia que no se sostiene sola: cualquiera
 * de los dos lados se puede tocar sin que el otro se entere, y la divergencia no da error — da un
 * número apenas distinto que nadie audita.
 *
 * Por eso `lead_requiere_revision()` es público: acá se lo usa de oráculo, lead por lead.
 *
 * Las trampas conocidas, todas presentes en la matriz de abajo:
 * - el saliente que cuenta es el del setter (`enviado`/`aprobado`) o el del sistema **aprobado**;
 *   una sugerencia en `sugerido` NO contesta nada;
 * - las reacciones del lead (por `kind` y en el formato legado de Kapso, texto plano) no son
 *   mensajes que haya que contestar;
 * - los registros de error son `is_status_event = true`, así que un error no resuelve a otro error;
 * - la entrega fallida de Kapso (`whatsapp_delivery_status`) NO entra: eso es `failed_send_count`,
 *   que es un criterio más ancho y vive en otra parte.
 */
class RevisionDeLeadsEnSqlYEnPhpCoincidenTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Deja la base sin leads: el scope se evalúa sobre la tabla entera y no hay filtros de por medio.
     * Todo adentro de la transacción del test.
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
     * Lead mínimo, en un estado cualquiera del pipeline (el scope no mira el estado).
     *
     * @param string $nombre Nombre de contacto: es lo que se muestra si el test falla.
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
     * Mensaje del hilo. Por defecto: entrante del lead.
     *
     * @param Lead                 $lead   Dueño del hilo.
     * @param array<string, mixed> $campos Campos a pisar.
     *
     * @return LeadMessage
     */
    private function crear_mensaje(Lead $lead, array $campos = []): LeadMessage
    {
        $base = [
            'lead_id'         => $lead->id,
            'sender'          => 'lead',
            'content'         => 'Hola',
            'status'          => 'enviado',
            'kind'            => 'text',
            'is_status_event' => false,
            'is_error'        => false,
            'sent_at'         => now(),
        ];

        return LeadMessage::create(array_merge($base, $campos));
    }

    /**
     * Saliente que efectivamente llego al lead: el `whatsapp_message_id` es lo que Kapso/Meta
     * devuelve al aceptar el envio, y es la parte que convierte a un saliente en "respuesta".
     * Sin el, el mensaje existe en la conversacion pero el lead nunca lo vio.
     *
     * @param Lead                 $lead   Dueno del hilo.
     * @param array<string, mixed> $campos Campos a pisar (sender, status, content...).
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
     * Registro de error de sistema, tal cual lo crea `LeadConversationErrorLogger` (siempre con
     * `is_status_event = true`, que es lo que hace que un error no resuelva a otro error).
     *
     * @param Lead   $lead  Dueño del hilo.
     * @param string $texto Detalle del error.
     *
     * @return LeadMessage
     */
    private function crear_error(Lead $lead, string $texto = 'Error de envío'): LeadMessage
    {
        return $this->crear_mensaje($lead, [
            'sender'          => 'sistema',
            'content'         => $texto,
            'status'          => 'enviado',
            'is_status_event' => true,
            'is_error'        => true,
            'sent_at'         => null,
        ]);
    }

    /**
     * Arma la matriz de escenarios, uno por lead.
     *
     * @return array<string, Lead> Nombre del escenario => lead.
     */
    private function armar_matriz(): array
    {
        $leads = [];

        // 1. Hilo vacío: no hay nada que revisar.
        $leads['sin mensajes'] = $this->crear_lead('Sin mensajes');

        // 2. Solo salientes: nadie escribió del otro lado.
        $lead = $this->crear_lead('Solo salientes');
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Hola, ¿cómo estás?']);
        $leads['solo salientes'] = $lead;

        // 3. Mensaje del lead sin contestar: razón A pura.
        $lead = $this->crear_lead('Sin responder');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $leads['sin responder'] = $lead;

        // 4. Contestado por el setter con un mensaje pegado a mano (status enviado).
        $lead = $this->crear_lead('Contestado por setter enviado');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($lead);
        $leads['contestado por setter enviado'] = $lead;

        // 5. Contestado por el setter con una sugerencia que aprobó (status aprobado).
        $lead = $this->crear_lead('Contestado por setter aprobado');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($lead, ['status' => 'aprobado']);
        $leads['contestado por setter aprobado'] = $lead;

        // 6. Contestado por el agente (sistema aprobado = ya salió al lead).
        $lead = $this->crear_lead('Contestado por sistema aprobado');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($lead, ['sender' => 'sistema', 'status' => 'aprobado']);
        $leads['contestado por sistema aprobado'] = $lead;

        // 7. 🔴 Sugerencia del agente todavía SIN aprobar: no contestó nada, el lead sigue esperando.
        $lead = $this->crear_lead('Sugerencia sin aprobar');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($lead, ['sender' => 'sistema', 'status' => 'sugerido', 'content' => 'Propuesta de respuesta']);
        $leads['sugerencia sin aprobar'] = $lead;

        // 8. Reacción del lead por kind: no es un mensaje a contestar.
        $lead = $this->crear_lead('Reacción por kind');
        $this->crear_saliente_entregado($lead, ['content' => 'Te mando la propuesta']);
        $this->crear_mensaje($lead, ['kind' => 'reaction', 'content' => "\u{1F44D}"]);
        $leads['reacción por kind'] = $lead;

        // 9. Reacción legada de Kapso (texto plano, sin kind).
        $lead = $this->crear_lead('Reacción legada');
        $this->crear_saliente_entregado($lead, ['content' => 'Te mando la propuesta']);
        $this->crear_mensaje($lead, ['content' => 'Reacted with ' . "\u{1F44D}" . ' to message wamid.ABC123']);
        $leads['reacción legada'] = $lead;

        // 10. Reacción legada de Kapso, variante "quitó la reacción".
        $lead = $this->crear_lead('Reacción legada quitada');
        $this->crear_saliente_entregado($lead, ['content' => 'Te mando la propuesta']);
        $this->crear_mensaje($lead, ['content' => 'Removed reaction from message wamid.ABC123']);
        $leads['reacción legada quitada'] = $lead;

        // 11. Error al final del hilo: razón B pura.
        $lead = $this->crear_lead('Error al final');
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Te mando el link']);
        $this->crear_error($lead);
        $leads['error al final'] = $lead;

        // 12. Error con actividad real posterior: resuelto.
        $lead = $this->crear_lead('Error resuelto');
        $this->crear_error($lead);
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Ahí va de nuevo']);
        $leads['error resuelto'] = $lead;

        // 13. 🔴 Error seguido de otro error: el segundo no resuelve al primero (is_status_event).
        $lead = $this->crear_lead('Dos errores seguidos');
        $this->crear_error($lead, 'Primer error');
        $this->crear_error($lead, 'Segundo error');
        $leads['dos errores seguidos'] = $lead;

        // 14. 🔴 Entrega fallida de Kapso sin is_error: es failed_send_count, NO el botón de revisión.
        $lead = $this->crear_lead('Entrega fallida sin is_error');
        $this->crear_mensaje($lead, [
            'sender'                   => 'setter',
            'content'                  => 'Te mando el link',
            'whatsapp_delivery_status' => 'fallido',
        ]);
        $leads['entrega fallida sin is_error'] = $lead;

        // 15. 🔴 Contestado por el AGENTE: sender sistema, status enviado y con id de WhatsApp.
        // Este es el caso que estuvo mal contado hasta el 2/9/2026: como el criterio solo miraba
        // `setter` y `sistema`+`aprobado`, toda conversacion atendida por la IA figuraba como sin
        // responder (497 leads en vez de 43).
        $lead = $this->crear_lead('Contestado por el agente');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($lead, ['sender' => 'sistema', 'content' => 'Te paso los planes']);
        $leads['contestado por el agente'] = $lead;

        // 16. 🔴 Respuesta del agente que NUNCA salio: sin whatsapp_message_id el lead no vio nada.
        // Es el 131008 de julio/agosto de 2026 y cualquier rechazo de Meta en el momento del envio.
        $lead = $this->crear_lead('Respuesta del agente que no salio');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($lead, ['sender' => 'sistema', 'content' => 'Te paso los planes']);
        $leads['respuesta del agente que no salio'] = $lead;

        // 17. 🔴 Mensaje del setter cuyo envio fallo: mismo razonamiento que el 16.
        $lead = $this->crear_lead('Mensaje del setter que no salio');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Te paso los planes']);
        $leads['mensaje del setter que no salio'] = $lead;

        // 18. 🔴 Sugerencia esperando verificacion: la IA genero una respuesta pero nadie la mando,
        // asi que el lead sigue esperando. Pedido explicito de Lucas (2/9/2026).
        $lead = $this->crear_lead('Sugerencia esperando verificacion');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($lead, [
            'sender'                => 'sistema',
            'status'                => 'sugerido',
            'content'               => 'Propuesta que agenda una demo',
            'requiere_verificacion' => true,
        ]);
        $leads['sugerencia esperando verificacion'] = $lead;

        // 19. Esa misma sugerencia, ya aprobada y enviada: deja de estar pendiente.
        $lead = $this->crear_lead('Sugerencia verificada y enviada');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_saliente_entregado($lead, [
            'sender'                => 'sistema',
            'content'               => 'Propuesta que agenda una demo',
            'requiere_verificacion' => true,
        ]);
        $leads['sugerencia verificada y enviada'] = $lead;

        // ⚠️ No hay escenario con `kind` NULL a proposito: la columna es NOT NULL con default
        // 'text' (2026_06_02_190000_add_kind_to_lead_messages_table.php), así que un mensaje con
        // kind nulo NO se puede insertar. La rama `whereNull('kind')` del scope y el `?? ''` de
        // LeadConversationAiState son defensa para el día que la columna se vuelva nullable; hoy
        // ninguna de las dos se puede ejercitar, y las dos se comportan igual si eso cambia.

        return $leads;
    }

    /**
     * El scope SQL y la implementación PHP tienen que devolver el mismo conjunto de leads, escenario
     * por escenario.
     *
     * @return void
     */
    public function test_el_scope_sql_devuelve_exactamente_los_mismos_leads_que_la_version_php(): void
    {
        $matriz  = $this->armar_matriz();
        $service = new LeadPendingReviewService();

        $ids_sql = Lead::query()->requiereRevision()->pluck('id')->all();
        $ids_php = [];

        foreach ($matriz as $escenario => $lead) {
            $en_php = $service->lead_requiere_revision($lead->fresh()->load('messages'));
            $en_sql = in_array($lead->id, $ids_sql, false);

            $this->assertSame(
                $en_php,
                $en_sql,
                'Divergencia en el escenario "' . $escenario . '" (lead #' . $lead->id . ', ' .
                $lead->contact_name . '): PHP dice ' . ($en_php ? 'sí' : 'no') .
                ' y el scope SQL dice ' . ($en_sql ? 'sí' : 'no') . '.'
            );

            if ($en_php) {
                $ids_php[] = $lead->id;
            }
        }

        // Igualdad de conjuntos: además de coincidir escenario por escenario, el scope no puede
        // traer leads de más (ninguno de los de la matriz quedó afuera del bucle).
        sort($ids_php);
        sort($ids_sql);
        $this->assertSame($ids_php, $ids_sql, 'Los dos criterios tienen que devolver el mismo conjunto de ids.');

        // Guarda contra una matriz que se degrade a "todo no": si nada requiere revisión, el test
        // pasaría comparando dos conjuntos vacíos y no probaría nada.
        $this->assertNotEmpty($ids_sql, 'La matriz tiene que incluir escenarios que SÍ requieren revisión.');
        $this->assertLessThan(
            count($matriz),
            count($ids_sql),
            'La matriz tiene que incluir también escenarios que NO requieren revisión.'
        );
    }

    /**
     * 🔴 El parámetro `$incluir_entrega_fallida` ensancha la razón B en UN solo caso.
     *
     * Sin él, el scope tiene que seguir siendo el gemelo exacto del botón de revisión (lo que
     * mide el test de paridad de arriba). Con él, suma los rechazos que Meta avisa por webhook
     * (`whatsapp_delivery_status = 'fallido'`), que nunca dejan un `is_error` y por eso son
     * invisibles para `LeadPendingReviewService`. Es lo que usan las tarjetas de estado, por
     * pedido de Lucas del 1/9/2026.
     *
     * Este test cierra la pinza por los dos lados: que el default no se ensanche, y que el `true`
     * no afloje el criterio más allá de ese caso.
     *
     * @return void
     */
    public function test_el_parametro_de_entrega_fallida_suma_el_rechazo_de_meta_y_solo_eso(): void
    {
        $matriz = $this->armar_matriz();
        $lead_rechazado_por_meta = $matriz['entrega fallida sin is_error'];

        // Camino por defecto: el gemelo del botón de revisión, que no ve el rechazo de Meta.
        $ids_default = Lead::query()->requiereRevision()->pluck('id')->map('intval')->sort()->values()->all();

        // Camino de las tarjetas: el mismo criterio MÁS los rechazos de Meta.
        $ids_con_fallida = Lead::query()->requiereRevision(true)->pluck('id')->map('intval')->sort()->values()->all();

        $this->assertNotContains(
            (int) $lead_rechazado_por_meta->id,
            $ids_default,
            'Sin el parámetro, el scope tiene que seguir siendo el gemelo exacto del botón de revisión.'
        );
        $this->assertContains(
            (int) $lead_rechazado_por_meta->id,
            $ids_con_fallida,
            'Con el parámetro en true, el rechazo de Meta tiene que contar (pedido de Lucas, 1/9/2026).'
        );

        // 🔴 Y no puede sumar NADA más que ese lead: el parámetro ensancha la razón B en un solo
        // caso, no afloja el criterio en general.
        $diferencia = array_values(array_diff($ids_con_fallida, $ids_default));
        $this->assertSame(
            [(int) $lead_rechazado_por_meta->id],
            $diferencia,
            'El parámetro solo puede agregar el lead con entrega rechazada por Meta, ningún otro.'
        );
    }

    /**
     * El scope es global, no per-admin: no puede meterse un `Auth::id()` adentro copiando de
     * `scopeWithUnreadLeadMessagesCount()` (que sí es per-admin, para los no leídos).
     *
     * @return void
     */
    public function test_el_scope_no_depende_del_admin_autenticado(): void
    {
        $this->armar_matriz();

        $sin_sesion = Lead::query()->requiereRevision()->pluck('id')->sort()->values()->all();

        $uno = Admin::create([
            'name'     => 'Admin uno',
            'email'    => 'paridad-uno-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $otro = Admin::create([
            'name'     => 'Admin dos',
            'email'    => 'paridad-dos-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);

        $this->actingAs($uno, 'sanctum');
        $con_uno = Lead::query()->requiereRevision()->pluck('id')->sort()->values()->all();

        $this->actingAs($otro, 'sanctum');
        $con_otro = Lead::query()->requiereRevision()->pluck('id')->sort()->values()->all();

        $this->assertSame($sin_sesion, $con_uno, 'El scope no puede cambiar según quién esté logueado.');
        $this->assertSame($sin_sesion, $con_otro, 'El scope no puede cambiar según quién esté logueado.');
        $this->assertNotEmpty($sin_sesion, 'Sin sesión el scope tiene que seguir devolviendo leads.');
    }

    /**
     * 🔴 Lo que la paridad NO prueba: que el criterio sea el correcto.
     *
     * El test de arriba compara las dos implementaciones entre si, asi que seguiria verde si las
     * dos estuvieran igual de mal — que es exactamente lo que paso hasta el 2/9/2026. Este fija el
     * veredicto esperado de los escenarios que definen la regla, en palabras de Lucas: si la IA
     * genero una respuesta pero esta esperando verificacion, el lead tiene que aparecer como sin
     * responder; una vez que el mensaje salio de verdad por WhatsApp —lo mande un humano o la IA—
     * ya no.
     *
     * @return void
     */
    public function test_solo_un_mensaje_que_salio_de_verdad_cuenta_como_respuesta(): void
    {
        $matriz  = $this->armar_matriz();
        $service = new LeadPendingReviewService();

        /* Escenario => si tiene que figurar como "sin responder". */
        $esperado = [
            'sin responder'                     => true,
            'contestado por setter enviado'     => false,
            'contestado por sistema aprobado'   => false,
            'contestado por el agente'          => false,
            'respuesta del agente que no salio' => true,
            'mensaje del setter que no salio'   => true,
            'sugerencia sin aprobar'            => true,
            'sugerencia esperando verificacion' => true,
            'sugerencia verificada y enviada'   => false,
        ];

        $ids_sql = Lead::query()->requiereRevision()->pluck('id')->all();

        foreach ($esperado as $escenario => $requiere) {
            $lead = $matriz[$escenario];

            $this->assertSame(
                $requiere,
                $service->lead_requiere_revision($lead->fresh()->load('messages')),
                'PHP: el escenario "' . $escenario . '" tendria que ' . ($requiere ? 'SI' : 'NO')
                . ' figurar como sin responder.'
            );

            $this->assertSame(
                $requiere,
                in_array($lead->id, $ids_sql, false),
                'SQL: el escenario "' . $escenario . '" tendria que ' . ($requiere ? 'SI' : 'NO')
                . ' figurar como sin responder.'
            );
        }
    }
}
