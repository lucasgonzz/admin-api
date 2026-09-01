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
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Te paso los planes']);
        $leads['contestado por setter enviado'] = $lead;

        // 5. Contestado por el setter con una sugerencia que aprobó (status aprobado).
        $lead = $this->crear_lead('Contestado por setter aprobado');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($lead, ['sender' => 'setter', 'status' => 'aprobado', 'content' => 'Te paso los planes']);
        $leads['contestado por setter aprobado'] = $lead;

        // 6. Contestado por el agente (sistema aprobado = ya salió al lead).
        $lead = $this->crear_lead('Contestado por sistema aprobado');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($lead, ['sender' => 'sistema', 'status' => 'aprobado', 'content' => 'Te paso los planes']);
        $leads['contestado por sistema aprobado'] = $lead;

        // 7. 🔴 Sugerencia del agente todavía SIN aprobar: no contestó nada, el lead sigue esperando.
        $lead = $this->crear_lead('Sugerencia sin aprobar');
        $this->crear_mensaje($lead, ['content' => '¿Cuánto sale?']);
        $this->crear_mensaje($lead, ['sender' => 'sistema', 'status' => 'sugerido', 'content' => 'Propuesta de respuesta']);
        $leads['sugerencia sin aprobar'] = $lead;

        // 8. Reacción del lead por kind: no es un mensaje a contestar.
        $lead = $this->crear_lead('Reacción por kind');
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Te mando la propuesta']);
        $this->crear_mensaje($lead, ['kind' => 'reaction', 'content' => "\u{1F44D}"]);
        $leads['reacción por kind'] = $lead;

        // 9. Reacción legada de Kapso (texto plano, sin kind).
        $lead = $this->crear_lead('Reacción legada');
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Te mando la propuesta']);
        $this->crear_mensaje($lead, ['content' => 'Reacted with ' . "\u{1F44D}" . ' to message wamid.ABC123']);
        $leads['reacción legada'] = $lead;

        // 10. Reacción legada de Kapso, variante "quitó la reacción".
        $lead = $this->crear_lead('Reacción legada quitada');
        $this->crear_mensaje($lead, ['sender' => 'setter', 'content' => 'Te mando la propuesta']);
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
}
