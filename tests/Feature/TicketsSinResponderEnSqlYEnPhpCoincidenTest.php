<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportPendingReplyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Test de paridad entre las DOS implementaciones de "este ticket de soporte está sin responder".
 *
 * Hay dos, y tiene que haberlas:
 *
 * - `SupportPendingReplyService::ticket_sin_responder()`, en PHP, que recorre los mensajes del
 *   ticket uno por uno. Es el lado legible: de un vistazo se ve si dice lo que tiene que decir.
 * - `SupportTicket::scopeSinResponder()`, en SQL, que decide lo mismo con un `EXISTS` que lleva un
 *   `NOT EXISTS` correlacionado adentro. Es la que cuenta la tarjeta del panel de inicio y la que
 *   marca cada fila de la bandeja, porque hidratar el hilo de todos los tickets en cada carga no es
 *   viable (la relación `messages` arrastra adjuntos y admin emisor).
 *
 * Mientras el número de la tarjeta y el filtro de la lista prometan ser lo mismo, estas dos tienen
 * que dar exactamente el mismo conjunto de tickets. Es una equivalencia que no se sostiene sola:
 * cualquiera de los dos lados se puede tocar sin que el otro se entere, y la divergencia no da
 * error — da un número apenas distinto que nadie audita. Es el mismo test, con la misma forma, que
 * `RevisionDeLeadsEnSqlYEnPhpCoincidenTest` sostiene del lado de leads.
 *
 * Por eso `ticket_sin_responder()` es público: acá se lo usa de oráculo, ticket por ticket.
 *
 * Las trampas conocidas, todas presentes en la matriz de abajo:
 * - 🔴 un borrador de la IA sin aprobar (`is_ai_suggestion_draft = true`) NO contesta nada: el
 *   cliente nunca lo recibió, así que no puede tapar su mensaje. Es el escenario que define la
 *   regla entera;
 * - ese mismo borrador, una vez aprobado y mandado (`is_ai_suggestion_draft = false`), sí contesta;
 * - un ticket `closed` nunca está "sin responder", aunque el último mensaje sea del cliente;
 * - se cuentan TICKETS, no mensajes: dos mensajes seguidos del cliente son un solo ticket;
 * - ⚠️ y acá NO se mira la entrega del saliente, a diferencia de leads: un ticket del ERP contestado
 *   por el operador cuenta como respondido aunque no tenga `whatsapp_message_id` (ver el escenario
 *   "erp contestado sin whatsapp_message_id" y el docblock del scope).
 */
class TicketsSinResponderEnSqlYEnPhpCoincidenTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Deja la base sin tickets ni mensajes de soporte: el scope se evalúa sobre la tabla entera y no
     * hay filtros de por medio. Todo adentro de la transacción del test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        SupportMessage::query()->delete();
        SupportTicket::query()->delete();
    }

    /**
     * Cliente dueño de los tickets de la matriz (uno solo alcanza: el criterio no mira el cliente).
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client            = new Client();
        $client->name      = 'Distribuidora de la matriz';
        $client->phone     = '+5493417770009';
        $client->is_active = true;
        $client->save();

        return $client;
    }

    /**
     * Ticket mínimo del canal WhatsApp.
     *
     * @param Client $client Cliente dueño.
     * @param string $nombre Nombre visible: es lo que se muestra si el test falla.
     * @param string $status open|closed.
     * @param string $source erp|whatsapp.
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client, string $nombre, string $status = 'open', string $source = 'whatsapp'): SupportTicket
    {
        return SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'name'           => $nombre,
            'status'         => $status,
            'source'         => $source,
            'whatsapp_phone' => $source === 'whatsapp' ? '+5493417770009' : null,
            'opened_at'      => now()->subDays(2),
            'closed_at'      => $status === 'closed' ? now()->subHours(1) : null,
        ]);
    }

    /**
     * Mensaje del CLIENTE (el que espera respuesta).
     *
     * @param SupportTicket $ticket Ticket dueño.
     * @param string        $texto  Cuerpo.
     *
     * @return SupportMessage
     */
    private function crear_mensaje_del_cliente(SupportTicket $ticket, string $texto = 'Me tira un error'): SupportMessage
    {
        return SupportMessage::create([
            'support_ticket_id'      => $ticket->id,
            'sender_type'            => 'user',
            'kind'                   => 'text',
            'body'                   => $texto,
            'is_ai_suggestion_draft' => false,
            'delivered_at'           => now(),
        ]);
    }

    /**
     * Respuesta REAL del operador (o del agente ya aprobado): `is_ai_suggestion_draft = false`.
     *
     * ⚠️ Sin `whatsapp_message_id` a propósito en el default: acá la entrega NO se mira, y los
     * tickets del ERP nunca lo tienen. Los escenarios que quieren probar el caso con id de WhatsApp
     * lo pasan por $campos.
     *
     * @param SupportTicket        $ticket Ticket dueño.
     * @param array<string, mixed> $campos Campos a pisar.
     *
     * @return SupportMessage
     */
    private function crear_respuesta_del_operador(SupportTicket $ticket, array $campos = []): SupportMessage
    {
        return SupportMessage::create(array_merge([
            'support_ticket_id'      => $ticket->id,
            'sender_type'            => 'admin',
            'kind'                   => 'text',
            'body'                   => 'Ya lo estamos viendo',
            'is_ai_suggestion_draft' => false,
            'delivered_at'           => now(),
        ], $campos));
    }

    /**
     * 🔴 Borrador de la IA SIN aprobar: está en el hilo con `sender_type = 'admin'`, pero el cliente
     * nunca lo recibió. No es una respuesta.
     *
     * @param SupportTicket $ticket Ticket dueño.
     *
     * @return SupportMessage
     */
    private function crear_borrador_de_ia(SupportTicket $ticket): SupportMessage
    {
        return SupportMessage::create([
            'support_ticket_id'      => $ticket->id,
            'sender_type'            => 'admin',
            'kind'                   => 'text',
            'body'                   => 'Propuesta de respuesta del agente',
            'is_ai_suggestion_draft' => true,
            'ai_auto_send_at'        => now()->addMinutes(10),
            'delivered_at'           => null,
        ]);
    }

    /**
     * Arma la matriz de escenarios, uno por ticket.
     *
     * @return array<string, SupportTicket> Nombre del escenario => ticket.
     */
    private function armar_matriz(): array
    {
        $client  = $this->crear_cliente();
        $tickets = [];

        // 1. Abierto y sin mensajes: no hay nadie esperando.
        $tickets['open sin mensajes'] = $this->crear_ticket($client, 'Sin mensajes');

        // 2. Abierto con un solo mensaje del operador: nadie escribió del otro lado.
        $ticket = $this->crear_ticket($client, 'Solo el operador');
        $this->crear_respuesta_del_operador($ticket, ['body' => '¿Todo bien por ahí?']);
        $tickets['open solo el operador'] = $ticket;

        // 3. Abierto y el último mensaje es del cliente: el caso central.
        $ticket = $this->crear_ticket($client, 'Último del cliente');
        $this->crear_mensaje_del_cliente($ticket, 'No me deja facturar');
        $tickets['open ultimo del cliente'] = $ticket;

        // 4. Abierto, el cliente escribió y el operador contestó de verdad.
        $ticket = $this->crear_ticket($client, 'Contestado por el operador');
        $this->crear_mensaje_del_cliente($ticket, 'No me deja facturar');
        $this->crear_respuesta_del_operador($ticket, [
            'whatsapp_message_id' => 'wamid.' . strtoupper(bin2hex(random_bytes(8))),
        ]);
        $tickets['open contestado por el operador'] = $ticket;

        // 5. 🔴 Abierto, el cliente escribió y lo único posterior es un BORRADOR de la IA sin
        // aprobar. El borrador no contestó nada: el cliente sigue esperando. Este escenario es el
        // que define la regla.
        $ticket = $this->crear_ticket($client, 'Solo un borrador de IA');
        $this->crear_mensaje_del_cliente($ticket, 'No me deja facturar');
        $this->crear_borrador_de_ia($ticket);
        $tickets['open solo un borrador de ia'] = $ticket;

        // 6. El mismo caso, pero el borrador ya se aprobó y salió: eso sí contesta.
        $ticket = $this->crear_ticket($client, 'Borrador aprobado y mandado');
        $this->crear_mensaje_del_cliente($ticket, 'No me deja facturar');
        $this->crear_borrador_de_ia($ticket);
        $this->crear_respuesta_del_operador($ticket, ['body' => 'Propuesta de respuesta del agente']);
        $tickets['open borrador aprobado y mandado'] = $ticket;

        // 7. Abierto, el operador contestó y después el cliente volvió a escribir.
        $ticket = $this->crear_ticket($client, 'El cliente volvió a escribir');
        $this->crear_respuesta_del_operador($ticket, ['body' => 'Ya lo vemos']);
        $this->crear_mensaje_del_cliente($ticket, '¿Alguna novedad?');
        $tickets['open el cliente volvio a escribir'] = $ticket;

        // 8. 🔴 CERRADO con el último mensaje del cliente: un ticket cerrado no pide nada.
        $ticket = $this->crear_ticket($client, 'Cerrado con el último del cliente', 'closed');
        $this->crear_mensaje_del_cliente($ticket, 'Gracias, era eso');
        $tickets['closed ultimo del cliente'] = $ticket;

        // 9. Dos mensajes del cliente seguidos: cuenta UNA vez, porque se cuentan tickets y no
        // mensajes. Esto lo verifica la igualdad de conjuntos del test de paridad (el ticket figura
        // una sola vez en los ids del scope).
        $ticket = $this->crear_ticket($client, 'Dos del cliente seguidos');
        $this->crear_mensaje_del_cliente($ticket, 'Hola');
        $this->crear_mensaje_del_cliente($ticket, '¿Me leen?');
        $tickets['open dos del cliente seguidos'] = $ticket;

        // 10. Ida y vuelta completo y el cliente queda hablando último.
        $ticket = $this->crear_ticket($client, 'Ida y vuelta');
        $this->crear_mensaje_del_cliente($ticket, 'No me deja facturar');
        $this->crear_respuesta_del_operador($ticket, ['body' => 'Probá de nuevo']);
        $this->crear_mensaje_del_cliente($ticket, 'Sigue igual');
        $tickets['open ida y vuelta y el cliente ultimo'] = $ticket;

        // 11. ⚠️ Ticket del ERP contestado por el operador SIN `whatsapp_message_id`: cuenta como
        // respondido igual. Es la divergencia deliberada con leads, donde un saliente sin id de
        // WhatsApp no cuenta como respuesta. Los tickets de `source = 'erp'` no lo tienen nunca, así
        // que pedirlo dejaría a todos como "sin responder". El escenario existe para fijarlo por
        // escrito, no porque la implementación lo trate distinto.
        $ticket = $this->crear_ticket($client, 'ERP contestado sin id de WhatsApp', 'open', 'erp');
        $this->crear_mensaje_del_cliente($ticket, 'No me deja facturar');
        $this->crear_respuesta_del_operador($ticket, ['whatsapp_message_id' => null]);
        $tickets['erp contestado sin whatsapp_message_id'] = $ticket;

        // ⚠️ No hay escenario con `is_ai_suggestion_draft` en NULL a propósito: la columna es
        // `boolean NOT NULL default false` (2026_06_03_180000_add_ai_draft_fields_to_support_messages),
        // así que un mensaje con ese campo nulo no se puede insertar. La rama `whereNull(...)` del
        // gemelo SQL y el `! null` del lado PHP son defensa para el día que la columna se vuelva
        // nullable; hoy ninguna de las dos se puede ejercitar, y las dos se comportan igual si eso
        // cambia. Mismo caso que la rama `whereNull('kind')` del scope de leads.

        return $tickets;
    }

    /**
     * El scope SQL y la implementación PHP tienen que devolver el mismo conjunto de tickets,
     * escenario por escenario.
     *
     * @return void
     */
    public function test_el_scope_sql_devuelve_exactamente_los_mismos_tickets_que_la_version_php(): void
    {
        $matriz  = $this->armar_matriz();
        $service = new SupportPendingReplyService();

        $ids_sql = SupportTicket::query()->sinResponder()->pluck('id')->all();
        $ids_php = [];

        foreach ($matriz as $escenario => $ticket) {
            $en_php = $service->ticket_sin_responder($ticket->fresh()->load('messages'));
            $en_sql = in_array($ticket->id, $ids_sql, false);

            $this->assertSame(
                $en_php,
                $en_sql,
                'Divergencia en el escenario "' . $escenario . '" (ticket #' . $ticket->id . ', ' .
                $ticket->name . '): PHP dice ' . ($en_php ? 'sí' : 'no') .
                ' y el scope SQL dice ' . ($en_sql ? 'sí' : 'no') . '.'
            );

            if ($en_php) {
                $ids_php[] = $ticket->id;
            }
        }

        // Igualdad de conjuntos: además de coincidir escenario por escenario, el scope no puede
        // traer tickets de más ni repetir uno (los dos mensajes seguidos del cliente del escenario 9
        // tienen que dar UNA fila, no dos).
        sort($ids_php);
        sort($ids_sql);
        $this->assertSame($ids_php, $ids_sql, 'Los dos criterios tienen que devolver el mismo conjunto de ids.');

        // Guarda contra una matriz que se degrade a "todo no": si ningún ticket estuviera sin
        // responder, el test pasaría comparando dos conjuntos vacíos y no probaría nada.
        $this->assertNotEmpty($ids_sql, 'La matriz tiene que incluir escenarios que SÍ están sin responder.');
        $this->assertLessThan(
            count($matriz),
            count($ids_sql),
            'La matriz tiene que incluir también escenarios que NO están sin responder.'
        );
    }

    /**
     * 🔴 Lo que la paridad NO prueba: que el criterio sea el correcto.
     *
     * El test de arriba compara las dos implementaciones entre sí, así que seguiría verde si las dos
     * estuvieran igual de mal — que es exactamente lo que pasó del lado de leads hasta el 2/9/2026,
     * cuando las dos contaban como respuesta a una sugerencia que nadie había mandado. Este fija el
     * veredicto esperado de cada escenario, verificado por los DOS lados.
     *
     * La regla, en palabras de Lucas: un ticket está sin responder cuando está abierto y su último
     * mensaje real —sin contar los borradores de la IA— es del cliente.
     *
     * @return void
     */
    public function test_un_borrador_de_ia_no_cuenta_como_respuesta_y_un_ticket_cerrado_no_pide_nada(): void
    {
        $matriz  = $this->armar_matriz();
        $service = new SupportPendingReplyService();

        /* Escenario => si tiene que figurar como "sin responder". */
        $esperado = [
            'open sin mensajes'                        => false,
            'open solo el operador'                    => false,
            'open ultimo del cliente'                  => true,
            'open contestado por el operador'          => false,
            'open solo un borrador de ia'              => true,
            'open borrador aprobado y mandado'         => false,
            'open el cliente volvio a escribir'        => true,
            'closed ultimo del cliente'                => false,
            'open dos del cliente seguidos'            => true,
            'open ida y vuelta y el cliente ultimo'    => true,
            'erp contestado sin whatsapp_message_id'   => false,
        ];

        // Cada escenario de la matriz tiene que tener su veredicto declarado: si alguien agrega uno
        // nuevo y se olvida de decir qué espera, el test lo denuncia.
        $this->assertSame(
            array_keys($matriz),
            array_keys($esperado),
            'Todo escenario de la matriz tiene que tener su veredicto esperado declarado, en el mismo orden.'
        );

        $ids_sql = SupportTicket::query()->sinResponder()->pluck('id')->all();

        foreach ($esperado as $escenario => $sin_responder) {
            $ticket = $matriz[$escenario];

            $this->assertSame(
                $sin_responder,
                $service->ticket_sin_responder($ticket->fresh()->load('messages')),
                'PHP: el escenario "' . $escenario . '" tendría que ' . ($sin_responder ? 'SÍ' : 'NO')
                . ' figurar como sin responder.'
            );

            $this->assertSame(
                $sin_responder,
                in_array($ticket->id, $ids_sql, false),
                'SQL: el escenario "' . $escenario . '" tendría que ' . ($sin_responder ? 'SÍ' : 'NO')
                . ' figurar como sin responder.'
            );
        }
    }

    /**
     * Las dos columnas calculadas que suma `withIndicadoresDeInicio()` al listado tienen que decir
     * lo MISMO que el scope que cuenta la tarjeta, fila por fila, y llegar como booleanos.
     *
     * Es la guarda contra el defecto "dice 2 y veo una sola fila": si la columna del listado y el
     * criterio de la tarjeta se escribieran por separado, el número de arriba y las filas de abajo
     * se separarían sin que nada lo avise.
     *
     * @return void
     */
    public function test_la_columna_del_listado_dice_lo_mismo_que_el_scope_de_la_tarjeta(): void
    {
        $matriz  = $this->armar_matriz();
        $ids_sql = SupportTicket::query()->sinResponder()->pluck('id')->all();

        $filas = SupportTicket::query()->withIndicadoresDeInicio()->get();
        $this->assertSame(count($matriz), $filas->count(), 'El scope de indicadores no puede perder filas.');

        foreach ($filas as $fila) {
            // El cast tiene que entregar un booleano de verdad, no el 1/0 de MySQL: el SPA filtra
            // la lista comparando contra true.
            $this->assertIsBool($fila->sin_responder, 'sin_responder tiene que llegar como booleano.');
            $this->assertIsBool($fila->abierto_hoy, 'abierto_hoy tiene que llegar como booleano.');

            $this->assertSame(
                in_array($fila->id, $ids_sql, false),
                $fila->sin_responder,
                'La columna sin_responder del ticket #' . $fila->id . ' no coincide con el scope de la tarjeta.'
            );

            // Y el listado no puede perder las columnas reales por culpa del addSelect.
            $this->assertNotNull($fila->status, 'La fila tiene que seguir trayendo sus columnas reales.');
        }

        // Los tickets de la matriz se abrieron hace dos días: ninguno es de hoy.
        $this->assertSame(
            0,
            $filas->filter(function ($fila) {
                return $fila->abierto_hoy;
            })->count(),
            'Ningún ticket de la matriz se abrió hoy.'
        );
    }

    /**
     * El scope es global, no per-admin: no puede meterse un `Auth::id()` adentro copiando del filtro
     * de asignación de la bandeja. Misma guarda que el test de paridad de leads.
     *
     * @return void
     */
    public function test_el_scope_no_depende_del_admin_autenticado(): void
    {
        $this->armar_matriz();

        $sin_sesion = SupportTicket::query()->sinResponder()->pluck('id')->sort()->values()->all();

        $uno = Admin::create([
            'name'     => 'Admin uno',
            'email'    => 'paridad-tickets-uno-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
        $otro = Admin::create([
            'name'     => 'Admin dos',
            'email'    => 'paridad-tickets-dos-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);

        $this->actingAs($uno, 'sanctum');
        $con_uno = SupportTicket::query()->sinResponder()->pluck('id')->sort()->values()->all();

        $this->actingAs($otro, 'sanctum');
        $con_otro = SupportTicket::query()->sinResponder()->pluck('id')->sort()->values()->all();

        $this->assertSame($sin_sesion, $con_uno, 'El scope no puede cambiar según quién esté logueado.');
        $this->assertSame($sin_sesion, $con_otro, 'El scope no puede cambiar según quién esté logueado.');
        $this->assertNotEmpty($sin_sesion, 'Sin sesión el scope tiene que seguir devolviendo tickets.');
    }
}
