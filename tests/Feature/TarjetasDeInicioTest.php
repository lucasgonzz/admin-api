<?php

namespace Tests\Feature;

use App\Helpers\AppTime;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las tres tarjetas del panel de inicio del admin (`GET admin/inicio/tarjetas`).
 *
 * Es el contrato que consume `Inicio.vue`: tres enteros y el momento del cálculo, con los nombres
 * exactos de las claves. Lo que se verifica acá es lo que ningún camino feliz mira: que el endpoint
 * pida sesión, que los tres números cuenten lo que se sembró, y —🔴 sobre todo— que
 * `leads_sin_responder` sea EL MISMO número que el scope que ya cuenta la tarjeta "sin responder"
 * del listado de leads. Esa última aserción es la guarda contra que alguien reescriba esa
 * definición más adelante: el día que el endpoint se ponga a calcularla por su cuenta, este test se
 * pone rojo con razón.
 */
class TarjetasDeInicioTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 🔴 Este test NO vacía las tablas: mide el INCREMENTO que produce su propia siembra.
     *
     * Los tres contadores son globales, así que la tentación es borrar `support_tickets` y `leads`
     * en el `setUp` (es lo que hace el test de paridad del scope, que necesita igualdad de
     * conjuntos). Acá no hace falta y además no conviene por dos motivos:
     *
     * - la base de testing del slot viene con el fixture cargado, así que un valor absoluto sería
     *   una aserción sobre el fixture y no sobre el endpoint;
     * - un `delete from lead_messages` en el MySQL local de esta máquina dispara
     *   `1615 Prepared statement needs to be re-prepared` de forma determinística cuando el
     *   `table_definition_cache` (600) está saturado — un rojo que no es del código y que ya hace
     *   fallar al `RevisionDeLeadsEnSqlYEnPhpCoincidenTest`, que nadie tocó.
     *
     * Medir el delta prueba exactamente lo mismo: que el endpoint cuente lo que se sembró, ni más
     * ni menos. Todo corre adentro de la transacción del test, así que nada queda escrito.
     *
     * @param Admin $admin Operador con el que se pide el endpoint.
     *
     * @return array<string, int> Los tres contadores en este momento.
     */
    private function leer_contadores(Admin $admin): array
    {
        $payload = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/inicio/tarjetas')
            ->assertStatus(200)
            ->json();

        return [
            'tickets_sin_responder' => (int) $payload['tickets_sin_responder'],
            'tickets_abiertos_hoy'  => (int) $payload['tickets_abiertos_hoy'],
            'leads_sin_responder'   => (int) $payload['leads_sin_responder'],
        ];
    }

    /**
     * Operador del panel.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        return Admin::create([
            'name'     => 'Lucas',
            'email'    => 'tarjetas-inicio-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * Cliente dueño de los tickets.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client            = new Client();
        $client->name      = 'Distribuidora del panel';
        $client->phone     = '+5493417770010';
        $client->is_active = true;
        $client->save();

        return $client;
    }

    /**
     * Ticket con `opened_at` explícito, para poder decidir si es "de hoy" o no.
     *
     * @param Client                   $client    Cliente dueño.
     * @param string                   $nombre    Nombre visible.
     * @param \Carbon\Carbon           $opened_at Momento de apertura.
     * @param string                   $status    open|closed.
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client, string $nombre, $opened_at, string $status = 'open'): SupportTicket
    {
        return SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'name'           => $nombre,
            'status'         => $status,
            'source'         => 'whatsapp',
            'whatsapp_phone' => '+5493417770010',
            'opened_at'      => $opened_at,
            'closed_at'      => $status === 'closed' ? $opened_at->copy()->addHour() : null,
        ]);
    }

    /**
     * Mensaje del cliente en un ticket (deja el ticket "sin responder" si queda último).
     *
     * @param SupportTicket $ticket Ticket dueño.
     *
     * @return SupportMessage
     */
    private function crear_mensaje_del_cliente(SupportTicket $ticket): SupportMessage
    {
        return SupportMessage::create([
            'support_ticket_id'      => $ticket->id,
            'sender_type'            => 'user',
            'kind'                   => 'text',
            'body'                   => 'Tengo una duda',
            'is_ai_suggestion_draft' => false,
            'delivered_at'           => now(),
        ]);
    }

    /**
     * Respuesta real del operador (tapa al mensaje del cliente).
     *
     * @param SupportTicket $ticket Ticket dueño.
     *
     * @return SupportMessage
     */
    private function crear_respuesta_del_operador(SupportTicket $ticket): SupportMessage
    {
        return SupportMessage::create([
            'support_ticket_id'      => $ticket->id,
            'sender_type'            => 'admin',
            'kind'                   => 'text',
            'body'                   => 'Ya lo vemos',
            'is_ai_suggestion_draft' => false,
            'delivered_at'           => now(),
        ]);
    }

    /**
     * Lead con un mensaje suyo sin contestar: es el caso que cuenta `requiereRevision(true)`.
     *
     * @param string $nombre Nombre de contacto.
     *
     * @return Lead
     */
    private function crear_lead_sin_responder(string $nombre): Lead
    {
        $lead = Lead::create([
            'contact_name' => $nombre,
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => 'calificado',
        ]);

        LeadMessage::create([
            'lead_id'         => $lead->id,
            'sender'          => 'lead',
            'content'         => '¿Cuánto sale?',
            'status'          => 'enviado',
            'kind'            => 'text',
            'is_status_event' => false,
            'is_error'        => false,
            'sent_at'         => now(),
        ]);

        return $lead;
    }

    /**
     * Lead sin ningún mensaje: no cuenta en la tarjeta.
     *
     * @param string $nombre Nombre de contacto.
     *
     * @return Lead
     */
    private function crear_lead_tranquilo(string $nombre): Lead
    {
        return Lead::create([
            'contact_name' => $nombre,
            'phone'        => '54911' . random_int(1000000, 9999999),
            'status'       => 'calificado',
        ]);
    }

    /**
     * Sin sesión el endpoint no contesta nada: va adentro del grupo `auth:sanctum`.
     *
     * @return void
     */
    public function test_sin_sesion_devuelve_401(): void
    {
        $this->getJson('/api/admin/inicio/tarjetas')->assertStatus(401);
    }

    /**
     * Con sesión devuelve 200 y exactamente las cuatro claves del contrato, con los tres contadores
     * como enteros.
     *
     * @return void
     */
    public function test_con_sesion_devuelve_las_cuatro_claves_del_contrato(): void
    {
        $admin = $this->crear_admin();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/inicio/tarjetas');

        $response->assertStatus(200);
        $payload = $response->json();

        $this->assertSame(
            ['tickets_sin_responder', 'tickets_abiertos_hoy', 'leads_sin_responder', 'calculado_en'],
            array_keys($payload),
            'El contrato son esas cuatro claves, con esos nombres exactos: el SPA las lee por nombre.'
        );

        $this->assertIsInt($payload['tickets_sin_responder']);
        $this->assertIsInt($payload['tickets_abiertos_hoy']);
        $this->assertIsInt($payload['leads_sin_responder']);
        $this->assertIsString($payload['calculado_en']);
        $this->assertNotSame('', trim($payload['calculado_en']), 'calculado_en no puede venir vacío.');
    }

    /**
     * Los tres números cuentan lo que se sembró, ni más ni menos.
     *
     * Se compara contra la lectura previa del endpoint (ver {@see self::leer_contadores()}): el
     * delta de cada contador tiene que ser exactamente el de la siembra.
     *
     * @return void
     */
    public function test_los_tres_numeros_cuentan_lo_que_se_sembro(): void
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente();

        $antes = $this->leer_contadores($admin);

        $hoy_temprano = AppTime::now()->startOfDay()->addMinutes(5);
        $anteayer     = AppTime::now()->startOfDay()->subDays(2);

        // Dos tickets sin responder: uno abierto hoy y uno viejo.
        $this->crear_mensaje_del_cliente($this->crear_ticket($client, 'Sin responder de hoy', $hoy_temprano));
        $this->crear_mensaje_del_cliente($this->crear_ticket($client, 'Sin responder viejo', $anteayer));

        // Uno abierto hoy pero ya contestado: suma a "abiertos hoy" y no a "sin responder".
        $contestado = $this->crear_ticket($client, 'Contestado de hoy', $hoy_temprano);
        $this->crear_mensaje_del_cliente($contestado);
        $this->crear_respuesta_del_operador($contestado);

        // Uno cerrado con el último mensaje del cliente: no suma a ninguna de las dos de soporte
        // (está cerrado, y se abrió anteayer).
        $this->crear_mensaje_del_cliente($this->crear_ticket($client, 'Cerrado viejo', $anteayer, 'closed'));

        // Dos leads sin responder y uno tranquilo.
        $this->crear_lead_sin_responder('Lead que pregunta');
        $this->crear_lead_sin_responder('Lead que insiste');
        $this->crear_lead_tranquilo('Lead tranquilo');

        $despues = $this->leer_contadores($admin);

        $this->assertSame(
            2,
            $despues['tickets_sin_responder'] - $antes['tickets_sin_responder'],
            'Son dos los tickets abiertos esperando respuesta: el cerrado y el contestado no cuentan.'
        );
        $this->assertSame(
            2,
            $despues['tickets_abiertos_hoy'] - $antes['tickets_abiertos_hoy'],
            'Son dos los tickets que se abrieron hoy: los dos de anteayer no cuentan.'
        );
        $this->assertSame(
            2,
            $despues['leads_sin_responder'] - $antes['leads_sin_responder'],
            'Son dos los leads esperando respuesta: el que no escribió nunca no cuenta.'
        );
    }

    /**
     * 🔴 `leads_sin_responder` tiene que ser EXACTAMENTE `Lead::requiereRevision(true)->count()`.
     *
     * Es la misma definición que cuenta la tarjeta "sin responder" del listado de leads
     * (`LeadStatusCardsService`). Esta aserción es la guarda de que nadie la reescriba: si el
     * endpoint se pone a calcularla por su cuenta, los dos números se separan y este test lo
     * denuncia. Acá sí se compara el valor ABSOLUTO contra el scope —no un delta—, que es
     * justamente de lo que se trata.
     *
     * @return void
     */
    public function test_leads_sin_responder_es_el_mismo_numero_que_el_scope_de_la_tarjeta_de_leads(): void
    {
        $admin = $this->crear_admin();

        $antes = $this->leer_contadores($admin);

        $this->crear_lead_sin_responder('Lead uno');
        $this->crear_lead_sin_responder('Lead dos');
        $this->crear_lead_sin_responder('Lead tres');
        $this->crear_lead_tranquilo('Lead cuatro');

        $del_scope = (int) Lead::query()->requiereRevision(true)->count();
        $despues   = $this->leer_contadores($admin);

        $this->assertSame(
            $del_scope,
            $despues['leads_sin_responder'],
            'La tarjeta de inicio y el scope de la tarjeta de leads tienen que dar el mismo número.'
        );

        // Y la guarda contra un test que no prueba nada: la siembra tiene que mover el número.
        $this->assertSame(
            3,
            $despues['leads_sin_responder'] - $antes['leads_sin_responder'],
            'Son tres los leads sembrados sin responder.'
        );
    }

    /**
     * "Abiertos hoy" es desde el comienzo del día en curso: un ticket de ayer a las 23:55 no cuenta,
     * uno de hoy a las 00:05 sí.
     *
     * El corte se mide contra `AppTime::now()->startOfDay()`, que devuelve el mismo timezone
     * (`America/Argentina/Buenos_Aires`) con el que la base guarda `opened_at`: la comparación es
     * directa, sin conversión.
     *
     * @return void
     */
    public function test_abiertos_hoy_corta_en_el_comienzo_del_dia(): void
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente();

        $antes = $this->leer_contadores($admin);

        $comienzo_de_hoy = AppTime::now()->startOfDay();

        $this->crear_ticket($client, 'Hoy a las 00:05', $comienzo_de_hoy->copy()->addMinutes(5));
        $this->crear_ticket($client, 'Ayer a las 23:55', $comienzo_de_hoy->copy()->subMinutes(5));
        $this->crear_ticket($client, 'Ayer a esta misma hora', AppTime::now()->subDay());

        $despues = $this->leer_contadores($admin);

        $this->assertSame(
            1,
            $despues['tickets_abiertos_hoy'] - $antes['tickets_abiertos_hoy'],
            'Solo el de hoy a las 00:05 cuenta: los dos de ayer quedan afuera.'
        );
    }
}
