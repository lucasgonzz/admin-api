<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\SupportMessage;
use App\Services\AsistenteAvisosService;
use Illuminate\Support\Facades\Http;

/**
 * El aviso «ya leí tu Excel» (`importacion_lista`) de un negocio en prueba, mandado por
 * `asistente:enviar-avisos` POR EL TICKET DE SOPORTE (misión cruzada prueba-martin-importacion, 3/11/2026).
 *
 * Lo que protege:
 *  1. Un cliente en prueba entra al barrido aunque nadie le haya escrito al asistente, y el aviso sale por el
 *     ticket (texto + link) con la ventana del principal abierta, queda en el hilo como del equipo y se sella
 *     del lado del cliente.
 *  2. Con la ventana cerrada no sale nada ni se sella: queda pendiente para la vuelta siguiente.
 *  3. Los otros tipos de aviso de un cliente en prueba sin asistente quedan `sin_canal` (no se inventa un
 *     destino).
 */
class AvisoDeImportacionPorSoporteTest extends BaseDeLaPrueba
{
    const UUID = '9b2c4f1e-7a3d-4c55-9e0b-2f6d8a1c3e70';
    const TEXTO = 'Ya leí "Lista_Proveedor.xlsx". Encontré 412 filas. Entrá al sistema y te aparece la importación lista para que confirmes las columnas y la termines.';
    const URL = 'https://ferreteria.comerciocity.com/listado-de-articulos?importacion=9b2c4f1e-7a3d-4c55-9e0b-2f6d8a1c3e70';

    /**
     * @param string $tipo
     *
     * @return array<string, mixed>
     */
    private function pendientes(string $tipo = 'importacion_lista'): array
    {
        return ['avisos' => [[
            'id'                 => $tipo . ':' . self::UUID,
            'tipo'               => $tipo,
            'uuid'               => self::UUID,
            'ai_conversation_id' => null,
            'texto'              => self::TEXTO,
            'resumen'            => self::TEXTO,
            'ruta'               => '/listado-de-articulos?importacion=' . self::UUID,
            'url'                => self::URL,
        ]]];
    }

    /**
     * @param string $fragmento
     * @param string $metodo
     *
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    private function pedidos_a(string $fragmento, string $metodo): array
    {
        $pedidos = [];
        foreach (Http::recorded() as $par) {
            if ($par[0]->method() === $metodo && strpos($par[0]->url(), $fragmento) !== false) {
                $pedidos[] = $par[0];
            }
        }

        return $pedidos;
    }

    /** @test */
    public function el_aviso_sale_por_el_ticket_con_la_ventana_abierta_y_se_sella(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();

        $client = $this->cliente_en_prueba();
        $ticket = $this->ticket_del_cliente($client, true);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        $resultados = app(AsistenteAvisosService::class)->enviar_a_todos();

        $del_cliente = null;
        foreach ($resultados as $resultado) {
            if ((int) $resultado['client_id'] === (int) $client->id) {
                $del_cliente = $resultado;
            }
        }

        $this->assertNotNull($del_cliente, 'El cliente en prueba entra al barrido sin mensajes al asistente.');
        $this->assertSame('enviado', $del_cliente['estado'], $del_cliente['detalle']);
        $this->assertSame(1, $del_cliente['enviados']);

        $this->assertCount(1, $espia->textos);
        $this->assertSame((string) $client->phone, $espia->textos[0]['to']);
        $this->assertSame(self::TEXTO . "\n\n" . self::URL, $espia->textos[0]['body']);

        $consultas = $this->pedidos_a('/asistente/avisos-pendientes', 'GET');
        $this->assertCount(1, $consultas);
        $this->assertSame('clave-del-cliente', $consultas[0]->header('X-Admin-Api-Key')[0]);

        $marcas = $this->pedidos_a('/asistente/avisos/avisado', 'POST');
        $this->assertCount(1, $marcas);
        $this->assertSame('importacion_lista:' . self::UUID, $marcas[0]['id']);
        $this->assertSame(self::URL, $marcas[0]['url']);
        $this->assertSame('wamid.saliente.1', $marcas[0]['whatsapp_message_id']);

        $saliente = SupportMessage::where('support_ticket_id', $ticket->id)->where('sender_type', 'admin')->first();
        $this->assertNotNull($saliente, 'El aviso queda en el hilo del ticket, donde lo ve Martín.');
        $this->assertSame(self::TEXTO . "\n\n" . self::URL, $saliente->body);
        $this->assertSame('wamid.saliente.1', $saliente->whatsapp_message_id);

        /* La vuelta siguiente no lo manda dos veces aunque el cliente lo siga ofreciendo. */
        $otra = app(AsistenteAvisosService::class)->enviar_a_cliente_en_prueba($client);
        $this->assertCount(1, $espia->textos);
        $this->assertStringContainsString('ya_avisado', $otra['detalle']);
    }

    /** @test */
    public function con_la_ventana_cerrada_queda_pendiente_sin_mandar_ni_sellar(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();

        $client = $this->cliente_en_prueba();
        $this->ticket_del_cliente($client, false);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes(), 200),
            '*/asistente/avisos/avisado'     => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteAvisosService::class)->enviar_a_cliente_en_prueba($client);

        $this->assertSame('pendiente', $resultado['estado']);
        $this->assertStringContainsString('ventana_cerrada', $resultado['detalle']);
        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $this->pedidos_a('/asistente/avisos/avisado', 'POST'));

        /* Y no quedó un reclamo colgado: cuando el negocio escriba, sale. */
        $this->ticket_del_cliente($client, true);
        $resultado = app(AsistenteAvisosService::class)->enviar_a_cliente_en_prueba($client);
        $this->assertSame('enviado', $resultado['estado'], $resultado['detalle']);
        $this->assertCount(1, $espia->textos);
    }

    /** @test */
    public function la_consulta_automatica_sale_solo_en_el_ticket_del_archivo_y_sin_link_al_asistente(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();
        $client = $this->cliente_en_prueba();
        $ticket = $this->ticket_del_cliente($client);
        $otro_ticket = $this->ticket_del_cliente($client);
        $otro_cliente = $this->cliente_en_prueba(5, 7, '+5493418887777');
        $ajeno = $this->ticket_del_cliente($otro_cliente);

        $pregunta = '¿La columna Importe es costo o precio?';
        $aviso = array_replace($this->pendientes()['avisos'][0], ['texto' => $pregunta]);
        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response(['avisos' => [
                ['support_ticket_id' => $ticket->id] + $aviso,
                ['support_ticket_id' => $ajeno->id, 'id' => 'importacion_lista:00000000-0000-4000-8000-000000000001'] + $aviso,
            ]], 200),
            '*/asistente/avisos/avisado' => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteAvisosService::class)->enviar_a_cliente_en_prueba($client);

        $this->assertSame(1, $resultado['enviados']);
        $this->assertStringContainsString('ticket_no_disponible', $resultado['detalle']);
        $this->assertCount(1, $espia->textos);
        $this->assertSame($pregunta, $espia->textos[0]['body']);
        $this->assertDatabaseHas('support_messages', ['support_ticket_id' => $ticket->id, 'body' => $pregunta]);
        $this->assertDatabaseMissing('support_messages', ['support_ticket_id' => $otro_ticket->id, 'body' => $pregunta]);
        $this->assertDatabaseMissing('support_messages', ['support_ticket_id' => $ajeno->id, 'body' => $pregunta]);
    }

    /** @test */
    public function los_otros_tipos_de_aviso_quedan_sin_canal_para_un_negocio_sin_asistente(): void
    {
        $espia = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $this->admin();

        $client = $this->cliente_en_prueba();
        $this->ticket_del_cliente($client, true);

        $this->fakear_http([
            '*/asistente/avisos-pendientes*' => Http::response($this->pendientes('escaneo_factura'), 200),
        ]);

        $resultado = app(AsistenteAvisosService::class)->enviar_a_cliente_en_prueba($client);

        $this->assertSame('pendiente', $resultado['estado']);
        $this->assertStringContainsString('sin_canal', $resultado['detalle']);
        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $this->pedidos_a('/asistente/avisos/avisado', 'POST'));
    }
}
