<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Buscador de contactos del modal de alta y ventana de 24hs del hilo abierto.
 *
 * Lo que se protege acá no es que el buscador encuentre -eso es un LIKE-, sino las tres cosas
 * que se rompen sin que nadie se entere:
 *
 * 1. Que el DUEÑO salga primero. ClientPhoneDirectory devuelve los empleados primero a propósito,
 *    porque el webhook los prioriza al reconocer un número entrante. Para esta pantalla Lucas
 *    pidió lo contrario, así que el reordenamiento vive en el controller: si alguien "simplifica"
 *    y usa el orden del directorio, la lista se da vuelta y nadie lo nota hasta usarla.
 * 2. Que un cliente DADO DE BAJA no aparezca nunca. Al webhook no lo reconoce en ninguna de sus
 *    tres formas, así que abrirle una conversación garantiza que su respuesta caiga en el
 *    pipeline de leads en vez de en el ticket. Esa es la falla silenciosa que la pantalla evita.
 * 3. Que la ventana viaje con su `expires_at`. El SPA la cierra sola comparando contra su reloj:
 *    sin esa fecha tendría que volver a preguntar cada minuto o mostrar una ventana vencida.
 *
 * Los endpoints se consultan por su ruta real, autenticados con Sanctum, y con la base de por
 * medio: el orden de las rutas también es parte de lo que se verifica.
 */
class BuscadorDeContactosYVentanaDelTicketTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Ningún test de este archivo sale a la red: el sync al empresa-api del cliente no participa.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    /**
     * Admin operador de soporte.
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
     * Cliente con nombre de dueño, razón social y teléfono propios.
     *
     * @param string $name         Nombre del dueño del negocio.
     * @param string $company_name Razón social.
     * @param string $phone        Teléfono de la ficha.
     * @param bool   $is_active    Si el cliente está de alta.
     *
     * @return Client
     */
    private function crear_cliente(string $name, string $company_name, string $phone, bool $is_active = true): Client
    {
        $client               = new Client();
        $client->name         = $name;
        $client->company_name = $company_name;
        $client->phone        = $phone;
        $client->is_active    = $is_active;
        $client->save();

        return $client;
    }

    /**
     * Empleado del cliente con teléfono propio.
     *
     * @param Client $client Cliente dueño.
     * @param string $name   Nombre del empleado.
     * @param string $phone  Teléfono del empleado.
     *
     * @return ClientEmployee
     */
    private function crear_empleado(Client $client, string $name, string $phone): ClientEmployee
    {
        $employee            = new ClientEmployee();
        $employee->client_id = $client->id;
        $employee->name      = $name;
        $employee->phone     = $phone;
        $employee->save();

        return $employee;
    }

    /**
     * Escenario base: el cliente que se busca, uno dado de baja y uno que no tiene que aparecer.
     *
     * El de baja matchea por "distribuidora" igual que el activo: sin el filtro por is_active los
     * dos saldrían en la misma lista y el operador no tendría cómo distinguirlos.
     *
     * @return array<string, mixed> Cliente activo, sus dos empleados y los otros dos clientes.
     */
    private function armar_escenario(): array
    {
        $activo   = $this->crear_cliente('Juan Pérez', 'Distribuidora del Sur', '+5493415551111');
        $brisa    = $this->crear_empleado($activo, 'Brisa', '+5493415552222');
        $marcelo  = $this->crear_empleado($activo, 'Marcelo', '+5493415553333');
        $de_baja  = $this->crear_cliente('Ramón Vieja', 'Distribuidora Vieja', '+5493415554444', false);
        $ajeno    = $this->crear_cliente('Ana Gómez', 'Panadería La Espiga', '+5493415555555');

        return [
            'activo'  => $activo,
            'brisa'   => $brisa,
            'marcelo' => $marcelo,
            'de_baja' => $de_baja,
            'ajeno'   => $ajeno,
        ];
    }

    /**
     * Deja la ventana de 24hs abierta con un entrante de soporte de ese número.
     *
     * @param Client $client       Cliente dueño del ticket.
     * @param string $phone        Teléfono normalizado del contacto.
     * @param int    $hace_minutos Hace cuánto escribió el cliente.
     *
     * @return SupportMessage El entrante que abre la ventana.
     */
    private function abrir_ventana_por_soporte(Client $client, string $phone, int $hace_minutos = 60): SupportMessage
    {
        $ticket = SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'closed',
            'source'         => 'whatsapp',
            'whatsapp_phone' => $phone,
            'opened_at'      => now()->subDays(2),
            'closed_at'      => now()->subHours(2),
        ]);

        return SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => 'Hola, tengo una duda',
            'delivered_at'      => now()->subMinutes($hace_minutos),
        ]);
    }

    /**
     * Ticket abierto del canal indicado, para consultarle la ventana.
     *
     * @param Client $client Cliente dueño.
     * @param string $source erp|whatsapp.
     * @param string $phone  Teléfono del hilo (vacío para el canal ERP).
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client, string $source, string $phone = ''): SupportTicket
    {
        return SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'open',
            'source'         => $source,
            'whatsapp_phone' => $phone !== '' ? $phone : null,
            'name'           => 'Consulta de prueba',
            'opened_at'      => now()->subDays(2),
        ]);
    }

    /**
     * Buscar por la razón social devuelve al dueño arriba de todo y después a los empleados.
     *
     * Es el punto exacto que pidió Lucas: "si escribe el nombre de la empresa, me dé primero en
     * las opciones el dueño de la empresa y después también los empleados". El directorio de
     * teléfonos devuelve justo al revés -empleados primero, porque el webhook los prioriza-, así
     * que este test es lo único que sostiene el orden de la pantalla.
     *
     * @return void
     */
    public function test_buscar_por_el_nombre_de_la_empresa_pone_al_dueno_primero()
    {
        $admin = $this->crear_admin('buscador-empresa@test.local');
        $this->armar_escenario();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/support-ticket/contact-search?q=distribuidora');

        $response->assertStatus(200);
        $results = $response->json('results');

        $this->assertCount(3, $results, 'Tendría que traer al dueño y a los dos empleados del cliente activo.');
        $this->assertSame('client', $results[0]['origin'], 'El primer resultado tiene que ser el dueño, no un empleado.');
        $this->assertTrue($results[0]['is_owner']);
        $this->assertSame('Juan Pérez', $results[0]['label']);
        $this->assertSame('Distribuidora del Sur', $results[0]['company_name']);
        $this->assertSame('empresa', $results[0]['match']);

        $this->assertSame('employee', $results[1]['origin']);
        $this->assertSame('employee', $results[2]['origin']);
        $this->assertFalse($results[1]['is_owner']);
        $this->assertFalse($response->json('truncated'));
    }

    /**
     * Buscar por el nombre del dueño trae todos los contactos de esa empresa.
     *
     * @return void
     */
    public function test_buscar_por_el_nombre_del_dueno_trae_todos_sus_contactos()
    {
        $admin = $this->crear_admin('buscador-dueno@test.local');
        $escenario = $this->armar_escenario();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/support-ticket/contact-search?q=juan');

        $response->assertStatus(200);
        $results = $response->json('results');

        $this->assertCount(3, $results);
        $this->assertSame('cliente', $results[0]['match']);
        foreach ($results as $fila) {
            $this->assertSame((int) $escenario['activo']->id, (int) $fila['client_id']);
        }
    }

    /**
     * Buscar por el nombre de un empleado trae ese contacto y solo ese.
     *
     * Devolver también al dueño sería cómodo pero equivocado: quien escribe el nombre de una
     * persona quiere escribirle a esa persona, y el contacto de más es el que se toca sin querer.
     *
     * @return void
     */
    public function test_buscar_por_el_nombre_de_un_empleado_trae_solo_ese_empleado()
    {
        $admin = $this->crear_admin('buscador-empleado@test.local');
        $escenario = $this->armar_escenario();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/support-ticket/contact-search?q=brisa');

        $response->assertStatus(200);
        $results = $response->json('results');

        $this->assertCount(1, $results, 'Tendría que traer únicamente al empleado que matchea.');
        $this->assertSame('employee', $results[0]['origin']);
        $this->assertFalse($results[0]['is_owner']);
        $this->assertSame('Brisa', $results[0]['label']);
        $this->assertSame((int) $escenario['brisa']->id, (int) $results[0]['client_employee_id']);
        $this->assertSame('empleado', $results[0]['match']);
    }

    /**
     * Buscar por teléfono trae el contacto de ese número y nada más.
     *
     * Se busca por los últimos ocho dígitos, que es como los tiene anotados una persona: el
     * teléfono guardado está en E.164 y sin normalizar la comparación no matchearía nunca.
     *
     * @return void
     */
    public function test_buscar_por_telefono_trae_solo_ese_contacto()
    {
        $admin = $this->crear_admin('buscador-telefono@test.local');
        $escenario = $this->armar_escenario();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/support-ticket/contact-search?q=15552222');

        $response->assertStatus(200);
        $results = $response->json('results');

        $this->assertCount(1, $results, 'Tendría que traer solo el contacto de ese número.');
        $this->assertSame('+5493415552222', $results[0]['phone']);
        $this->assertSame((int) $escenario['brisa']->id, (int) $results[0]['client_employee_id']);
        $this->assertSame('telefono', $results[0]['match']);
    }

    /**
     * Un cliente dado de baja no aparece aunque su nombre matchee.
     *
     * @return void
     */
    public function test_un_cliente_dado_de_baja_no_aparece_en_el_buscador()
    {
        $admin = $this->crear_admin('buscador-baja@test.local');
        $this->armar_escenario();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/support-ticket/contact-search?q=vieja');

        $response->assertStatus(200);
        $this->assertCount(
            0,
            $response->json('results'),
            'Un cliente de baja no lo reconoce el webhook: su respuesta caería en el pipeline de leads.'
        );
    }

    /**
     * Una búsqueda de una sola letra devuelve la lista vacía, no la base entera.
     *
     * Y con 200, no con un 422: es el estado normal de la primera tecla, no un error que la
     * pantalla tenga que mostrarle a nadie.
     *
     * @return void
     */
    public function test_una_busqueda_de_una_letra_no_devuelve_la_base_entera()
    {
        $admin = $this->crear_admin('buscador-una-letra@test.local');
        $this->armar_escenario();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/support-ticket/contact-search?q=a');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('results'));
        $this->assertFalse($response->json('truncated'));
    }

    /**
     * Cada contacto viaja con el estado de su propia ventana de 24hs.
     *
     * La ventana es por número, no por cliente: que el empleado haya escrito recién no habilita
     * texto libre para el dueño, y mostrarlos con el mismo estado haría que el operador mande un
     * texto que Meta rechaza.
     *
     * @return void
     */
    public function test_cada_contacto_trae_el_estado_de_su_ventana()
    {
        $admin = $this->crear_admin('buscador-ventana@test.local');
        $escenario = $this->armar_escenario();
        $this->abrir_ventana_por_soporte($escenario['activo'], '+5493415552222');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/support-ticket/contact-search?q=distribuidora');

        $response->assertStatus(200);

        $por_telefono = [];
        foreach ($response->json('results') as $fila) {
            $por_telefono[$fila['phone']] = $fila;
        }

        $this->assertTrue($por_telefono['+5493415552222']['window']['open'], 'Brisa escribió hace una hora: su ventana está abierta.');
        $this->assertNotNull($por_telefono['+5493415552222']['window']['expires_at']);
        $this->assertFalse($por_telefono['+5493415551111']['window']['open']);
        $this->assertFalse($por_telefono['+5493415553333']['window']['open']);
    }

    /**
     * La ventana del ticket abierto viene abierta y con la fecha en que se vence.
     *
     * El `expires_at` no es decorativo: el SPA cierra la ventana sola comparándolo contra su
     * reloj, sin volver a consultar. Sin esa fecha la pantalla seguiría ofreciendo texto libre
     * después de las 24hs y el mensaje lo rechazaría Meta.
     *
     * @return void
     */
    public function test_la_ventana_del_ticket_viene_abierta_si_el_cliente_escribio_recien()
    {
        $admin = $this->crear_admin('ventana-abierta@test.local');
        $client = $this->crear_cliente('Juan Pérez', 'Distribuidora del Sur', '+5493415551111');
        $ticket = $this->crear_ticket($client, 'whatsapp', '+5493415551111');

        $entrante = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => '¿Me ayudás con el stock?',
            'delivered_at'      => now()->subHour(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/support-ticket/' . $ticket->id . '/whatsapp-window');

        $response->assertStatus(200);
        $response->assertJsonPath('source', 'whatsapp');
        $response->assertJsonPath('phone', '+5493415551111');
        $response->assertJsonPath('window.open', true);

        $vence = Carbon::parse($response->json('window.expires_at'));
        $esperado = Carbon::parse($entrante->delivered_at)->addHours(24);
        $this->assertLessThanOrEqual(
            2,
            abs($vence->diffInSeconds($esperado)),
            'La ventana tiene que vencer 24hs después del último entrante del cliente.'
        );
    }

    /**
     * Sin entrantes en las últimas 24hs la ventana viene cerrada.
     *
     * @return void
     */
    public function test_la_ventana_del_ticket_viene_cerrada_si_hace_mas_de_24hs_que_no_escribe()
    {
        $admin = $this->crear_admin('ventana-cerrada@test.local');
        $client = $this->crear_cliente('Juan Pérez', 'Distribuidora del Sur', '+5493415551111');
        $ticket = $this->crear_ticket($client, 'whatsapp', '+5493415551111');

        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => 'Quedó pendiente esto',
            'delivered_at'      => now()->subHours(30),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/support-ticket/' . $ticket->id . '/whatsapp-window');

        $response->assertStatus(200);
        $response->assertJsonPath('window.open', false);
        $response->assertJsonPath('window.expires_at', null);
    }

    /**
     * Un ticket del ERP no tiene ventana, y eso se contesta con 200 y `window` en null.
     *
     * Con un 422 el SPA tendría que distinguir un ticket de otro canal de una caída de la API, y
     * ante la duda terminaría escondiendo el aviso también cuando la ventana sí importa.
     *
     * @return void
     */
    public function test_un_ticket_del_erp_no_tiene_ventana()
    {
        $admin = $this->crear_admin('ventana-erp@test.local');
        $client = $this->crear_cliente('Juan Pérez', 'Distribuidora del Sur', '+5493415551111');
        $ticket = $this->crear_ticket($client, 'erp');

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/support-ticket/' . $ticket->id . '/whatsapp-window');

        $response->assertStatus(200);
        $response->assertJsonPath('source', 'erp');
        $response->assertJsonPath('phone', null);
        $response->assertJsonPath('window', null);
    }
}
