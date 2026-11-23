<?php

namespace Tests\Feature\TableroDeJuan;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use Carbon\Carbon;
use Tests\Feature\PruebaGratuita\BaseDeLaPrueba;

/**
 * CA7 y CA8 de la etapa E1 (misión embudo-juan-pipeline): lo que Juan habló por teléfono le llega a los agentes, para
 * que no le vuelvan a preguntar al lead lo que ya contestó.
 *
 *  - CA7, Martín (agente de leads): `build_user_content` suma el bloque `LLAMADAS DEL EQUIPO (Juan)` con las últimas 5
 *    llamadas (fecha, si atendió, resultado, cotizado y notas recortadas a 300 caracteres), cierra con la instrucción de
 *    no repetir preguntas y no aparece si el lead no tiene llamadas.
 *  - CA8, Soporte: el contexto del ticket (`GET claude/support-codex/tickets/{id}`) trae `lead_del_cliente` con el lead
 *    del que salió el cliente (el promovido más nuevo): sus datos, las últimas 5 llamadas y los últimos 20 mensajes de la
 *    charla con Martín (sin eventos de estado, cada texto recortado a 500), más la capacidad `lead_context = true`. Un
 *    cliente que no salió de un lead trae `lead_del_cliente: null`.
 *
 * Las llamadas se siembran directo en `lead_llamadas` (por query builder): este test no depende de cómo se registran.
 *
 * Hoy están EN ROJO: la tabla `lead_llamadas` no existe, el contexto de Martín no tiene el bloque y el de Soporte no trae
 * `lead_del_cliente` ni la capacidad.
 */
class ContextoDeLlamadasParaAgentesTest extends BaseDeLaPrueba
{
    use ArmadoDelTableroDeJuan;

    /**
     * Fija el reloj en el instante de los tests de E1.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clavar_el_reloj(self::$AHORA_DEL_TABLERO);
    }

    /**
     * El contexto completo del turno de Martín (`build_user_content` es protegido: se llama por una subclase, igual que
     * en los tests de E2 de la misión anterior).
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function contexto_del_turno_de_martin(Lead $lead): string
    {
        $servicio = new class extends LeadAiService {
            public function contexto(Lead $lead): string
            {
                return $this->build_user_content($lead, false, '', null, false);
            }
        };

        return $servicio->contexto($lead);
    }

    /**
     * Inserta un mensaje del hilo del lead con su fecha explícita (así el orden no depende del reloj ni del azar).
     *
     * @param Lead   $lead
     * @param string $sender       `lead` o `sistema`.
     * @param string $contenido
     * @param Carbon $momento
     * @param bool   $es_de_estado true = evento de cambio de estado (no es charla).
     *
     * @return void
     */
    private function agregar_mensaje(Lead $lead, string $sender, string $contenido, Carbon $momento, bool $es_de_estado = false): void
    {
        $mensaje = new LeadMessage();
        $mensaje->forceFill([
            'lead_id'         => $lead->id,
            'sender'          => $sender,
            'kind'            => 'text',
            'content'         => $contenido,
            'status'          => 'enviado',
            'is_status_event' => $es_de_estado,
            'is_error'        => false,
            'sent_at'         => $momento,
            'created_at'      => $momento,
            'updated_at'      => $momento,
        ]);
        $mensaje->save();
    }

    // ------------------------------------------------------------------------------------------
    // CA7 — Martín
    // ------------------------------------------------------------------------------------------

    /**
     * CA7. El contexto de Martín trae el bloque de llamadas con las notas, lo cotizado y la instrucción de no repetir
     * preguntas, DESPUÉS de las notas internas; un lead sin llamadas no tiene el bloque.
     *
     * @return void
     */
    public function test_bloque_de_martin(): void
    {
        $juan = $this->crear_admin_del_tablero('Juan', true);
        $lead = $this->crear_lead_del_tablero('contactado', ['notes' => 'Nota interna del setter']);
        $this->insertar_llamada($lead, [
            'admin_id'  => $juan->id,
            'atendio'   => 1,
            'resultado' => 'implementacion_acordada',
            'cotizado'  => 'Gestión $628.400, mitad $314.200',
            'notas'     => 'Quiere sumar una sucursal en marzo y factura con ARCA.',
        ]);

        $contexto = $this->contexto_del_turno_de_martin($lead->fresh());

        $this->assertStringContainsString('LLAMADAS DEL EQUIPO', $contexto, 'Tiene que estar el bloque de llamadas.');
        $this->assertStringContainsString('Quiere sumar una sucursal en marzo y factura con ARCA.', $contexto, 'Las notas de la llamada.');
        $this->assertStringContainsString('Gestión $628.400, mitad $314.200', $contexto, 'Lo cotizado.');
        $this->assertStringContainsString('No le vuelvas a preguntar lo que ya está acá', $contexto, 'La instrucción de no repetir preguntas.');
        $this->assertGreaterThan(
            (int) strpos($contexto, 'Notas internas'),
            (int) strpos($contexto, 'LLAMADAS DEL EQUIPO'),
            'El bloque va después de las notas internas.'
        );

        /* Un lead sin llamadas no tiene el bloque. */
        $sin_llamadas = $this->crear_lead_del_tablero('contactado');
        $this->assertStringNotContainsString('LLAMADAS DEL EQUIPO', $this->contexto_del_turno_de_martin($sin_llamadas));
    }

    /**
     * CA7. Sólo las últimas 5 llamadas, con las notas recortadas a 300 caracteres.
     *
     * @return void
     */
    public function test_bloque_de_martin_trae_las_ultimas_cinco_y_recorta_las_notas(): void
    {
        $lead = $this->crear_lead_del_tablero('contactado');

        for ($numero = 1; $numero <= 7; $numero++) {
            $etiqueta = 'LLAMADA-' . str_pad((string) $numero, 2, '0', STR_PAD_LEFT);
            $notas    = $numero === 7 ? $etiqueta . ' ' . str_repeat('Z', 400) : $etiqueta;
            $this->insertar_llamada($lead, [
                'notas'      => $notas,
                'created_at' => Carbon::now()->subDays(10 - $numero)->format('Y-m-d H:i:s'),
            ]);
        }

        $contexto = $this->contexto_del_turno_de_martin($lead->fresh());

        foreach (['03', '04', '05', '06', '07'] as $numero) {
            $this->assertStringContainsString('LLAMADA-' . $numero, $contexto, 'Están las últimas cinco.');
        }
        foreach (['01', '02'] as $numero) {
            $this->assertStringNotContainsString('LLAMADA-' . $numero, $contexto, 'Las dos más viejas quedan afuera.');
        }
        $this->assertStringContainsString(str_repeat('Z', 100), $contexto, 'La nota larga entra…');
        $this->assertStringNotContainsString(str_repeat('Z', 301), $contexto, '…recortada: nunca más de 300 caracteres de nota.');
    }

    // ------------------------------------------------------------------------------------------
    // CA8 — Soporte
    // ------------------------------------------------------------------------------------------

    /**
     * CA8. El contexto del ticket de un cliente que salió de un lead con 1 llamada y 25 mensajes de charla trae
     * `lead_del_cliente` con los datos del lead, la llamada, los últimos 20 mensajes (recortados a 500, sin eventos de
     * estado) y la capacidad `lead_context`.
     *
     * @return void
     */
    public function test_contexto_de_soporte(): void
    {
        $juan    = $this->crear_admin_del_tablero('Juan', true);
        $cliente = $this->crear_cliente('+5493411234567', false, 'clave-del-cliente');
        $lead    = $this->lead_en_prueba($cliente);
        $lead->notes              = 'Dueño muy atento, prefiere que le hablen de vos.';
        $lead->cantidad_empleados = 4;
        $lead->business_type      = 'Ferretería';
        $lead->save();

        $this->insertar_llamada($lead, [
            'admin_id'  => $juan->id,
            'atendio'   => 1,
            'resultado' => 'implementacion_acordada',
            'cotizado'  => 'Gestión $628.400',
            'notas'     => 'Tiene 3 sucursales y hoy lleva todo en Excel.',
        ]);

        /* 25 mensajes de charla (alternados lead / Martín), cada uno de 700 caracteres, y 3 eventos de estado al final. */
        $inicio = Carbon::now()->subHours(2);
        for ($numero = 1; $numero <= 25; $numero++) {
            $this->agregar_mensaje(
                $lead,
                $numero % 2 === 1 ? 'lead' : 'sistema',
                'MSG-' . str_pad((string) $numero, 2, '0', STR_PAD_LEFT) . ' ' . str_repeat('y', 700),
                $inicio->copy()->addMinutes($numero)
            );
        }
        for ($numero = 1; $numero <= 3; $numero++) {
            $this->agregar_mensaje($lead, 'sistema', 'Estado cambiado de "Nuevo" a "Conversando" #' . $numero, $inicio->copy()->addMinutes(30 + $numero), true);
        }

        $ticket = $this->ticket_del_cliente($cliente);

        $respuesta = $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers_de_claude());
        $respuesta->assertStatus(200);

        $bloque = $respuesta->json('ticket.lead_del_cliente');
        $this->assertIsArray($bloque, 'El contexto tiene que traer `lead_del_cliente`.');

        /* Los datos del lead. */
        $this->assertSame((int) $lead->id, (int) $bloque['lead_id']);
        $this->assertSame('Marcela Gómez', $bloque['nombre']);
        $this->assertSame('Ferretería de prueba', $bloque['empresa']);
        $this->assertSame('Ferretería', $bloque['rubro']);
        $this->assertSame(4, (int) $bloque['cantidad_empleados']);
        $this->assertSame('Dueño muy atento, prefiere que le hablen de vos.', $bloque['notas']);
        $this->assertArrayHasKey('resumen_demo', $bloque, 'La clave del resumen de la demo viaja (null si no hay).');

        /* La llamada. */
        $this->assertCount(1, $bloque['llamadas']);
        $llamada = $bloque['llamadas'][0];
        $this->assertSame('Tiene 3 sucursales y hoy lleva todo en Excel.', $llamada['notas']);
        $this->assertSame('Gestión $628.400', $llamada['cotizado']);
        $this->assertSame('implementacion_acordada', $llamada['resultado']);
        $this->assertTrue((bool) $llamada['atendio']);
        $this->assertSame('Juan', $llamada['admin']);
        $this->assertNotEmpty($llamada['fecha']);

        /* La charla con Martín: los últimos 20 mensajes, recortados, sin eventos de estado. */
        $charla = $bloque['charla_con_martin'];
        $this->assertCount(20, $charla, 'Los últimos 20 mensajes de la charla.');
        $textos = array_column($charla, 'texto');
        foreach ($charla as $mensaje) {
            $this->assertLessThanOrEqual(500, mb_strlen($mensaje['texto']), 'Cada texto se recorta a 500 caracteres.');
            $this->assertContains($mensaje['de'], ['lead', 'martin'], '`de` es lead o martin.');
            $this->assertArrayHasKey('fecha', $mensaje);
        }
        $unidos = implode("\n", $textos);
        $this->assertStringContainsString('MSG-25', $unidos, 'Está el más nuevo.');
        $this->assertStringContainsString('MSG-06', $unidos, 'Está el vigésimo desde el final.');
        $this->assertStringNotContainsString('MSG-05', $unidos, 'Los 5 más viejos quedan afuera.');
        $this->assertStringNotContainsString('Estado cambiado', $unidos, 'Los eventos de estado no son charla.');

        /* La capacidad, y que no se filtra la clave del cliente. */
        $this->assertTrue((bool) $respuesta->json('ticket.capabilities.lead_context'), 'Capacidad `lead_context`.');
        $this->assertStringNotContainsString('clave-del-cliente', (string) $respuesta->getContent());
    }

    /**
     * CA8. El contexto de Soporte trae como mucho las últimas 5 llamadas.
     *
     * @return void
     */
    public function test_contexto_de_soporte_trae_las_ultimas_cinco_llamadas(): void
    {
        $cliente = $this->crear_cliente('+5493411230000', false, 'clave-del-cliente');
        $lead    = $this->lead_en_prueba($cliente);

        for ($numero = 1; $numero <= 7; $numero++) {
            $this->insertar_llamada($lead, [
                'notas'      => 'LLAMADA-' . str_pad((string) $numero, 2, '0', STR_PAD_LEFT),
                'created_at' => Carbon::now()->subDays(10 - $numero)->format('Y-m-d H:i:s'),
            ]);
        }

        $ticket    = $this->ticket_del_cliente($cliente);
        $respuesta = $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers_de_claude());
        $respuesta->assertStatus(200);

        $notas = array_column($respuesta->json('ticket.lead_del_cliente.llamadas'), 'notas');
        $this->assertCount(5, $notas, 'Como mucho 5 llamadas.');
        $this->assertContains('LLAMADA-07', $notas, 'La más nueva está.');
        $this->assertNotContains('LLAMADA-01', $notas, 'La más vieja queda afuera.');
        $this->assertNotContains('LLAMADA-02', $notas);
    }

    /**
     * CA8. Un cliente que no salió de un lead trae `lead_del_cliente: null` (la clave viaja igual).
     *
     * @return void
     */
    public function test_cliente_sin_lead(): void
    {
        $cliente = $this->crear_cliente('+5493417770000', false, 'otra-clave');
        $ticket  = $this->ticket_del_cliente($cliente);

        $respuesta = $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers_de_claude());
        $respuesta->assertStatus(200);

        $this->assertArrayHasKey('lead_del_cliente', $respuesta->json('ticket'), 'La clave viaja siempre.');
        $this->assertNull($respuesta->json('ticket.lead_del_cliente'));
    }
}
