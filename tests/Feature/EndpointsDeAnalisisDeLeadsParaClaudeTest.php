<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los endpoints de lectura de `claude/*`: leads, mensajes y métricas.
 *
 * El caso que originó todo esto y que este test protege puntualmente: encontrar los seguimientos
 * automáticos que NO se pudieron entregar durante el problema de pago de Meta. Esos mensajes
 * quedan en la base con una firma precisa —`is_followup=1`, `followup_template_id` cargado,
 * `whatsapp_message_id` en null y el motivo en `whatsapp_send_error`— y el endpoint tiene que
 * devolver exactamente esos y ninguno más. Un filtro que se lleve puesto un seguimiento que SÍ
 * salió termina en alguien recibiendo dos veces el mismo mensaje.
 *
 * También se verifica lo que no se ve: que la proyección por defecto no filtre teléfono ni email,
 * que el cursor no repita ni saltee filas, y que `count_only` no devuelva ni una fila.
 */
class EndpointsDeAnalisisDeLeadsParaClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude';

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers con la clave de ingesta.
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
     * Crea un lead con teléfono y email cargados.
     *
     * @param string $nombre
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $nombre, string $status = 'contactado'): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = $nombre;
        $lead->company_name = 'Empresa de ' . $nombre;
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->email        = strtolower($nombre) . '@ejemplo.com';
        $lead->status       = $status;
        $lead->save();

        return $lead;
    }

    /**
     * Crea un seguimiento automático por plantilla que NO se pudo entregar.
     *
     * Reproduce exactamente lo que deja LeadFollowupService::send_followup_via_template() cuando
     * send_template() devuelve null: el mensaje se graba igual, sin whatsapp_message_id y con el
     * motivo del fallo.
     *
     * @param Lead   $lead
     * @param string $motivo
     *
     * @return LeadMessage
     */
    private function seguimiento_caido(Lead $lead, string $motivo): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'              => $lead->id,
            'sender'               => 'sistema',
            'content'              => 'Hola ' . $lead->contact_name . '! Seguimiento que no salió.',
            'status'               => 'enviado',
            'is_followup'          => true,
            'followup_template_id' => 7,
            'whatsapp_message_id'  => null,
            'whatsapp_send_error'  => $motivo,
        ]);
    }

    /**
     * Crea un seguimiento automático que SÍ se entregó.
     *
     * @param Lead $lead
     *
     * @return LeadMessage
     */
    private function seguimiento_entregado(Lead $lead): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'                  => $lead->id,
            'sender'                   => 'sistema',
            'content'                  => 'Hola ' . $lead->contact_name . '! Seguimiento que sí salió.',
            'status'                   => 'enviado',
            'is_followup'              => true,
            'followup_template_id'     => 7,
            'whatsapp_message_id'      => 'wamid.ok.' . $lead->id,
            'whatsapp_delivery_status' => 'entregado',
        ]);
    }

    /**
     * Sin el header de la clave, la lectura tampoco atiende.
     *
     * @return void
     */
    public function test_sin_clave_la_lectura_devuelve_401()
    {
        $this->getJson('/api/claude/leads')->assertStatus(401);
        $this->getJson('/api/claude/messages')->assertStatus(401);
        $this->getJson('/api/claude/schema')->assertStatus(401);
    }

    /**
     * 🔴 EL CASO DE META: el filtro devuelve los seguimientos caídos y NO los que sí salieron.
     *
     * @return void
     */
    public function test_el_filtro_de_seguimientos_caidos_no_se_lleva_puestos_los_que_si_salieron()
    {
        $caido_uno = $this->crear_lead('CaidoUno');
        $caido_dos = $this->crear_lead('CaidoDos');
        $entregado = $this->crear_lead('Entregado');

        $this->seguimiento_caido($caido_uno, 'Meta rechazó: cuenta con pago pendiente');
        $this->seguimiento_caido($caido_dos, 'Meta rechazó: cuenta con pago pendiente');
        $this->seguimiento_entregado($entregado);

        /* Un mensaje normal del lead: no es seguimiento y no tiene que aparecer. */
        LeadMessage::create([
            'lead_id' => $entregado->id,
            'sender'  => 'lead',
            'content' => 'Hola, me interesa',
            'status'  => 'enviado',
        ]);

        $respuesta = $this->withHeaders($this->headers())
            ->getJson('/api/claude/messages?is_followup=1&delivery=no_confirmado&has_send_error=1');

        $respuesta->assertStatus(200);

        $data = $respuesta->json('data');
        $this->assertCount(2, $data, 'Tienen que salir los dos caídos y nada más.');

        $lead_ids = array_column($data, 'lead_id');
        sort($lead_ids);
        $esperados = [$caido_uno->id, $caido_dos->id];
        sort($esperados);
        $this->assertSame($esperados, $lead_ids);

        foreach ($data as $mensaje) {
            $this->assertNull($mensaje['whatsapp_message_id'], 'Un caído no puede tener message_id.');
            $this->assertNotEmpty($mensaje['whatsapp_send_error']);
        }
    }

    /**
     * 🔴 El agrupado por error separa el problema de Meta de las otras causas de fallo.
     *
     * Sin esto, el filtro de caídos también se lleva números mal cargados y plantillas
     * despausadas, y una recuperación masiva le mandaría un mensaje a gente cuyo teléfono
     * simplemente está mal.
     *
     * @return void
     */
    public function test_el_agrupado_por_error_separa_las_causas_del_fallo()
    {
        $meta_uno = $this->crear_lead('MetaUno');
        $meta_dos = $this->crear_lead('MetaDos');
        $invalido = $this->crear_lead('Invalido');

        $this->seguimiento_caido($meta_uno, 'Meta rechazó: cuenta con pago pendiente');
        $this->seguimiento_caido($meta_dos, 'Meta rechazó: cuenta con pago pendiente');
        $this->seguimiento_caido($invalido, 'Número destino inválido');

        $respuesta = $this->withHeaders($this->headers())
            ->getJson('/api/claude/messages?is_followup=1&delivery=no_confirmado&has_send_error=1&group_by=error');

        $respuesta->assertStatus(200);

        $cuerpo = $respuesta->json();
        $this->assertSame('error', $cuerpo['group_by']);

        /* Los tres caídos tienen que quedar repartidos en dos grupos, no en uno solo. */
        $grupos = $cuerpo['data'];
        $this->assertCount(2, $grupos, 'Dos causas distintas tienen que dar dos grupos.');

        $total = 0;
        foreach ($grupos as $grupo) {
            /* Un agrupado devuelve conteos, no el cuerpo de los mensajes. */
            $this->assertArrayNotHasKey('content', $grupo, 'Un agrupado no puede traer el texto de los mensajes.');
            $this->assertArrayHasKey('error', $grupo, 'El texto del error ES la clave del grupo.');
            $total += (int) $grupo['cantidad'];
        }
        $this->assertSame(3, $total, 'Los tres caídos tienen que estar contados.');

        /* El grupo más grande tiene que ser el del impago, con dos. */
        $this->assertSame(2, (int) $grupos[0]['cantidad']);
        $this->assertStringContainsString('pago pendiente', (string) $grupos[0]['error']);
    }

    /**
     * `count_only` mide sin traer ni una fila.
     *
     * @return void
     */
    public function test_count_only_no_devuelve_filas()
    {
        $lead = $this->crear_lead('Contado');
        $this->seguimiento_caido($lead, 'Meta rechazó: cuenta con pago pendiente');
        $this->seguimiento_caido($lead, 'Meta rechazó: cuenta con pago pendiente');

        $respuesta = $this->withHeaders($this->headers())
            ->getJson('/api/claude/messages?is_followup=1&delivery=no_confirmado&count_only=1');

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['count' => 2, 'leads_distintos' => 1]);

        $cuerpo = $respuesta->json();
        $this->assertArrayNotHasKey('data', $cuerpo, 'count_only no puede traer filas.');
    }

    /**
     * 🔴 La proyección por defecto no filtra teléfono ni email.
     *
     * Estos datos viajan a la ventana de contexto de un modelo: tienen que ser opt-in explícito.
     *
     * @return void
     */
    public function test_sin_include_contacto_no_viajan_telefono_ni_email()
    {
        $lead = $this->crear_lead('Reservado');

        $respuesta = $this->withHeaders($this->headers())
            ->getJson('/api/claude/leads?lead_ids[]=' . $lead->id);

        $respuesta->assertStatus(200);
        $fila = $respuesta->json('data.0');

        $this->assertArrayNotHasKey('phone', $fila, 'El teléfono no puede viajar sin pedirlo.');
        $this->assertArrayNotHasKey('email', $fila, 'El email no puede viajar sin pedirlo.');
        $this->assertSame('Reservado', $fila['contact_name']);

        /* Con el include explícito sí. */
        $con_contacto = $this->withHeaders($this->headers())
            ->getJson('/api/claude/leads?lead_ids[]=' . $lead->id . '&include=contacto');

        $con_contacto->assertStatus(200);
        $this->assertArrayHasKey('phone', $con_contacto->json('data.0'));
    }

    /**
     * El cursor barre todas las filas sin repetir ni saltear ninguna.
     *
     * @return void
     */
    public function test_el_cursor_no_repite_ni_saltea_filas()
    {
        $creados = [];
        for ($i = 1; $i <= 5; $i++) {
            $creados[] = $this->crear_lead('Cursor' . $i)->id;
        }

        $vistos   = [];
        $after_id = null;
        $vueltas  = 0;

        do {
            $url = '/api/claude/leads?limit=2&lead_ids[]=' . implode('&lead_ids[]=', $creados);
            if ($after_id !== null) {
                $url .= '&after_id=' . $after_id;
            }

            $respuesta = $this->withHeaders($this->headers())->getJson($url);
            $respuesta->assertStatus(200);

            foreach ($respuesta->json('data') as $fila) {
                $vistos[] = (int) $fila['id'];
            }

            $after_id = $respuesta->json('next_after_id');
            $vueltas++;
        } while ($after_id !== null && $vueltas < 10);

        sort($vistos);
        sort($creados);

        $this->assertSame($creados, $vistos, 'El barrido tiene que devolver cada lead una sola vez.');
        $this->assertSame(count($vistos), count(array_unique($vistos)), 'No puede repetir ninguna fila.');
    }

    /**
     * 🔴 La métrica que aísla el daño de Meta: la tasa medida sobre "se despachó" contra la
     * medida sobre "Meta confirmó la entrega".
     *
     * Un lead al que no le llegó nada no puede contar como "no respondió".
     *
     * @return void
     */
    public function test_la_tasa_sobre_entregados_no_castiga_a_quien_no_recibio_nada()
    {
        $respondio = $this->crear_lead('Respondio');
        $no_llego  = $this->crear_lead('NoLlego');

        /* A este le llegó y contestó. */
        LeadMessage::create([
            'lead_id'                  => $respondio->id,
            'sender'                   => 'setter',
            'content'                  => 'Hola!',
            'status'                   => 'enviado',
            'whatsapp_message_id'      => 'wamid.a',
            'whatsapp_delivery_status' => 'entregado',
        ]);
        LeadMessage::create([
            'lead_id' => $respondio->id,
            'sender'  => 'lead',
            'content' => 'Hola, contame',
            'status'  => 'enviado',
        ]);

        /* A este se le despachó pero Meta nunca confirmó la entrega: no pudo responder. */
        LeadMessage::create([
            'lead_id'                  => $no_llego->id,
            'sender'                   => 'setter',
            'content'                  => 'Hola!',
            'status'                   => 'enviado',
            'whatsapp_message_id'      => 'wamid.b',
            'whatsapp_delivery_status' => null,
        ]);

        $desde = now()->subDays(3)->toDateString();
        $hasta = now()->addDay()->toDateString();

        $respuesta = $this->withHeaders($this->headers())
            ->getJson('/api/claude/metrics?from=' . $desde . '&to=' . $hasta);

        $respuesta->assertStatus(200);

        $respuestas = $respuesta->json('respuesta');
        $this->assertNotNull($respuestas, 'El bloque de tasas de respuesta tiene que estar.');

        /* La tasa sobre despachados incluye al que nunca recibió nada; la de entregados no. */
        $this->assertArrayHasKey('respondio_alguna_vez', $respuestas);
        $this->assertArrayHasKey('respondio_alguna_vez_entregado', $respuestas);

        $sobre_despachados = $respuestas['respondio_alguna_vez'];
        $sobre_entregados  = $respuestas['respondio_alguna_vez_entregado'];

        $this->assertSame(2, (int) $sobre_despachados['denominador'], 'Despachados: los dos leads.');
        $this->assertSame(1, (int) $sobre_entregados['denominador'], 'Entregados: solo al que le llegó.');
        $this->assertSame(1, (int) $sobre_despachados['numerador']);
        $this->assertSame(1, (int) $sobre_entregados['numerador']);
    }

    /**
     * Las métricas exigen rango de fechas: sin índice en created_at, una consulta abierta
     * escanearía las tablas enteras.
     *
     * @return void
     */
    public function test_las_metricas_exigen_rango_de_fechas()
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/claude/metrics')
            ->assertStatus(422);
    }

    /**
     * El schema se describe solo, para no tener que adivinar filtros del otro lado.
     *
     * @return void
     */
    public function test_el_schema_describe_los_filtros_disponibles()
    {
        $respuesta = $this->withHeaders($this->headers())->getJson('/api/claude/schema');

        $respuesta->assertStatus(200);
        $cuerpo = $respuesta->json();

        $this->assertArrayHasKey('pipeline_statuses', $cuerpo);
        $this->assertArrayHasKey('delivery', $cuerpo);

        /* `delivery` es un mapa valor => explicación, no una lista pelada: el schema se explica
           solo, así del otro lado no hay que adivinar qué significa cada filtro. */
        $this->assertArrayHasKey('no_confirmado', $cuerpo['delivery']);
        $this->assertStringContainsString(
            'whatsapp_message_id IS NULL',
            (string) $cuerpo['delivery']['no_confirmado'],
            'El schema tiene que decir qué condición SQL aplica cada valor.'
        );
    }
}
