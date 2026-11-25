<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\ClientTemplate;
use App\Services\AsistenteRecordatoriosService;
use App\Services\AsistenteWhatsappService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * El aviso inmediato de pedido nuevo de la tienda por el asistente de WhatsApp (misión cruzada
 * whatsapp-aviso-pedido, 25/11/2026, contrato C3 del plan).
 *
 * Hoy el aviso de un pedido de la tienda sale recién cuando el admin consulta, cada 5 minutos, los
 * pendientes del `empresa-api` del cliente. La misión suma un empuje: cuando llega el pedido, el
 * `empresa-api` le pega al admin en `POST api/inbound/asistente/pedido-nuevo` (clave
 * `X-Admin-Api-Key`, body `{"order_id": int}`) y el admin encola, en la conexión `database` y la cola
 * `asistente`, un job que corre el envío de SIEMPRE (`AsistenteRecordatoriosService::enviar_a`) para
 * ese cliente. La consulta de 5 minutos queda sólo como respaldo.
 *
 * Lo que estas pruebas cuidan, en orden de cuánto duele si se rompe:
 *
 *   1. **Un solo mensaje por pedido**: dos empujes con el mismo `order_id` encolan UN job, y el envío
 *      del job más el de la consulta de respaldo mandan UN WhatsApp.
 *   2. **La clave es la identidad**: sin clave, con clave inválida o de un cliente inactivo, nada se
 *      encola. El cliente sale de la clave, nunca del body.
 *   3. **Sin canal no se encola ni falla**: 202 con `canal_no_conectado`.
 *   4. **Se encola en `database`/`asistente`**: la conexión por defecto del admin es `sync`, y con
 *      `sync` el envío correría dentro del pedido del `empresa-api` (que espera la respuesta).
 *   5. **El envío es el existente**: ventana de 24 hs, plantilla de tareas fuera de ella y sello
 *      `recordatorios/avisado`, sin tocar una línea del servicio.
 *
 * Todo es hermético: `Queue::fake()` en los tests del endpoint, y el `Http::fake()` del `BaseDelCanal`
 * más el espía de envío en los del job (sin Kapso ni empresa-api reales).
 */
class InboundAsistentePedidoNuevoTest extends BaseDelCanal
{
    /** Ruta del empuje. */
    const RUTA = 'api/inbound/asistente/pedido-nuevo';

    /** Clave inbound del cliente de prueba. */
    const CLAVE = 'inbound-clave-del-cliente-de-prueba';

    /** Clase del job que corre el envío del cliente. */
    const JOB = 'App\\Jobs\\EnviarPedidoNuevoDelAsistenteJob';

    /** Id del ítem del pedido N° 78 tal como lo arma el `empresa-api`. */
    const ID_PEDIDO = 'recordatorio:pedido_tienda:78-1792150000';

    /** Detalle del ítem (no cambia respecto de la 4.3.59). */
    const DETALLE = 'Pedido N° 78 de la tienda: Gabriel Domínguez, $188.000';

    /** Texto completo del aviso (ventana abierta). */
    const TEXTO = "🛒 Te llegó un pedido nuevo en la tienda: *N° 78* de Gabriel Domínguez, por \$188.000.\nEntrega: Retiro por local · Pago: Mercado Pago\n1 artículo: Malbec Reserva x2";

    /** Resumen para la plantilla de tareas (ventana cerrada). */
    const RESUMEN = 'el aviso del pedido N° 78 de la tienda: Gabriel Domínguez, $188.000, retiro por local, pago con Mercado Pago, 1 artículo';

    /** Nombre de la plantilla de tareas del asistente. */
    const PLANTILLA_TAREA = 'cc_tarea_del_asistente';

    /**
     * Cliente activo con el canal prendido y su clave inbound cargada.
     *
     * @param bool   $canal_activo Estado del interruptor `asistente_whatsapp_activo`.
     * @param string $clave        Clave inbound.
     *
     * @return Client
     */
    private function cliente_con_clave(bool $canal_activo = true, string $clave = self::CLAVE): Client
    {
        $client = $this->crear_cliente('+5493411234567', $canal_activo);
        $client->forceFill(['inbound_api_key' => $clave])->save();

        return $client;
    }

    /**
     * Empuja el aviso de un pedido, con la clave dada (o sin header si es null).
     *
     * @param array<string, mixed> $cuerpo Body JSON.
     * @param string|null          $clave  Valor de `X-Admin-Api-Key`, o null para no mandarlo.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function empujar(array $cuerpo, ?string $clave = self::CLAVE)
    {
        $headers = $clave === null ? [] : ['X-Admin-Api-Key' => $clave];

        return $this->postJson(self::RUTA, $cuerpo, $headers);
    }

    /**
     * Cuántos jobs del aviso quedaron encolados.
     *
     * @return int
     */
    private function jobs_encolados(): int
    {
        return Queue::pushed(self::JOB)->count();
    }

    /**
     * Si el job lleva el id de un cliente en alguna de sus propiedades (no se ata al nombre de la
     * propiedad: lo que importa es a qué cliente va dirigido).
     *
     * @param object $job       Job encolado.
     * @param int    $client_id Id del cliente.
     *
     * @return bool
     */
    private function job_lleva_el_id($job, int $client_id): bool
    {
        $reflejo = new \ReflectionObject($job);
        foreach ($reflejo->getProperties() as $propiedad) {
            $propiedad->setAccessible(true);
            $valor = $propiedad->getValue($job);
            if (is_int($valor) && $valor === $client_id) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------------------------------
    // Autenticación
    // ---------------------------------------------------------------------------------------------

    /**
     * Sin `X-Admin-Api-Key` es 401 y no se encola nada.
     *
     * @return void
     */
    public function test_sin_clave_es_401_y_no_encola(): void
    {
        Queue::fake();
        $this->cliente_con_clave();

        $this->empujar(['order_id' => 78], null)->assertStatus(401);

        Queue::assertNothingPushed();
    }

    /**
     * Una clave que no es de ningún cliente es 401 y no se encola nada.
     *
     * @return void
     */
    public function test_clave_invalida_es_401_y_no_encola(): void
    {
        Queue::fake();
        $this->cliente_con_clave();

        $this->empujar(['order_id' => 78], 'clave-que-no-existe')->assertStatus(401);

        Queue::assertNothingPushed();
    }

    /**
     * 🔴 Un cliente dado de baja no empuja aunque conserve la clave.
     *
     * @return void
     */
    public function test_cliente_inactivo_es_401_y_no_encola(): void
    {
        Queue::fake();
        $client = $this->cliente_con_clave();
        $client->forceFill(['is_active' => false])->save();

        $this->empujar(['order_id' => 78])->assertStatus(401);

        Queue::assertNothingPushed();
    }

    /**
     * 🔴 La identidad es la clave: un `client_uuid` o `client_id` ajeno en el body no cambia a qué
     * cliente se le avisa. El job lleva el id del cliente de la CLAVE.
     *
     * @return void
     */
    public function test_el_cliente_sale_de_la_clave_y_no_del_body(): void
    {
        Queue::fake();
        $propio = $this->cliente_con_clave();
        $ajeno  = $this->crear_cliente('+5493417654321');

        $this->empujar([
            'order_id'    => 78,
            'client_id'   => $ajeno->id,
            'client_uuid' => (string) $ajeno->uuid,
        ])->assertStatus(202);

        $encolados = Queue::pushed(self::JOB);
        $this->assertCount(1, $encolados);
        $this->assertTrue($this->job_lleva_el_id($encolados->first(), $propio->id), 'El job tiene que llevar el id del cliente de la clave.');
        $this->assertFalse($this->job_lleva_el_id($encolados->first(), $ajeno->id), 'El cliente del body no cuenta.');
    }

    // ---------------------------------------------------------------------------------------------
    // Validación del body
    // ---------------------------------------------------------------------------------------------

    /**
     * Sin `order_id`, o con uno que no es un entero positivo, es 422 y no se encola nada.
     *
     * @return void
     */
    public function test_order_id_ausente_o_invalido_es_422(): void
    {
        Queue::fake();
        $this->cliente_con_clave();

        $this->empujar([])->assertStatus(422);
        $this->empujar(['order_id' => 0])->assertStatus(422);
        $this->empujar(['order_id' => -5])->assertStatus(422);
        $this->empujar(['order_id' => 'abc'])->assertStatus(422);
        $this->empujar(['order_id' => null])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------------------------------------
    // Encolado
    // ---------------------------------------------------------------------------------------------

    /**
     * Cliente con canal: 202 `{encolado: true}` y el job queda en la conexión `database` y la cola
     * `asistente` (la que atiende el worker permanente; la conexión por defecto del admin es `sync`).
     * El pedido HTTP no manda nada a Meta ni al `empresa-api`: eso lo hace el job.
     *
     * @return void
     */
    public function test_con_canal_encola_el_job_en_database_y_cola_asistente(): void
    {
        Queue::fake();
        $espia  = $this->espiar_sender();
        $client = $this->cliente_con_clave();

        $this->empujar(['order_id' => 78])
            ->assertStatus(202)
            ->assertJson(['encolado' => true]);

        $encolados = Queue::pushed(self::JOB);
        $this->assertCount(1, $encolados);

        $job = $encolados->first();
        $this->assertSame('database', $job->connection, 'Con la conexión por defecto (sync) el envío correría dentro del pedido del empresa-api.');
        $this->assertSame(AsistenteWhatsappService::COLA_DEL_ASISTENTE, $job->queue);
        $this->assertSame('asistente', $job->queue);
        $this->assertTrue($this->job_lleva_el_id($job, $client->id));

        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
        Http::assertNothingSent();
    }

    /**
     * Cliente con el interruptor del canal apagado: 202 `canal_no_conectado`, nada encolado.
     *
     * @return void
     */
    public function test_sin_canal_responde_202_canal_no_conectado_y_no_encola(): void
    {
        Queue::fake();
        $this->cliente_con_clave(false);

        $this->empujar(['order_id' => 78])
            ->assertStatus(202)
            ->assertJson(['encolado' => false, 'motivo' => 'canal_no_conectado']);

        Queue::assertNothingPushed();
    }

    /**
     * Cliente con el canal prendido pero sin teléfono al que escribirle: tampoco hay canal.
     *
     * @return void
     */
    public function test_sin_telefono_del_dueno_tampoco_encola(): void
    {
        Queue::fake();
        $client = $this->cliente_con_clave();
        $client->forceFill(['phone' => '', 'asistente_phone' => ''])->save();

        $this->empujar(['order_id' => 78])
            ->assertStatus(202)
            ->assertJson(['encolado' => false, 'motivo' => 'canal_no_conectado']);

        Queue::assertNothingPushed();
    }

    /**
     * 🔴 Dos empujes del mismo pedido encolan UN solo job; el segundo responde `ya_encolado`.
     *
     * @return void
     */
    public function test_el_mismo_pedido_dos_veces_encola_un_solo_job(): void
    {
        Queue::fake();
        $this->cliente_con_clave();

        $this->empujar(['order_id' => 78])->assertStatus(202)->assertJson(['encolado' => true]);
        $this->empujar(['order_id' => 78])
            ->assertStatus(202)
            ->assertJson(['encolado' => false, 'motivo' => 'ya_encolado']);

        $this->assertSame(1, $this->jobs_encolados());
    }

    /**
     * Otro pedido del mismo cliente SÍ encola (la marca de «ya encolado» es por pedido), y el mismo
     * número de pedido de otro cliente también (es por cliente y pedido).
     *
     * @return void
     */
    public function test_otro_pedido_u_otro_cliente_encolan_su_propio_job(): void
    {
        Queue::fake();
        $this->cliente_con_clave();
        $otro = $this->crear_cliente('+5493417654321');
        $otro->forceFill(['inbound_api_key' => 'inbound-clave-de-otro-cliente'])->save();

        $this->empujar(['order_id' => 78])->assertJson(['encolado' => true]);
        $this->empujar(['order_id' => 79])->assertJson(['encolado' => true]);
        $this->empujar(['order_id' => 78], 'inbound-clave-de-otro-cliente')->assertJson(['encolado' => true]);

        $this->assertSame(3, $this->jobs_encolados());
    }

    /**
     * Un pedido que no encoló por falta de canal no «gasta» la marca: cuando el canal se conecta,
     * el mismo pedido sí encola.
     *
     * @return void
     */
    public function test_sin_canal_no_deja_marcado_el_pedido(): void
    {
        Queue::fake();
        $client = $this->cliente_con_clave(false);

        $this->empujar(['order_id' => 78])->assertJson(['encolado' => false, 'motivo' => 'canal_no_conectado']);

        $client->forceFill(['asistente_whatsapp_activo' => true])->save();

        $this->empujar(['order_id' => 78])->assertJson(['encolado' => true]);
        $this->assertSame(1, $this->jobs_encolados());
    }

    // ---------------------------------------------------------------------------------------------
    // El job: el envío es el de siempre
    // ---------------------------------------------------------------------------------------------

    /**
     * Ítem `pedido_tienda` tal como lo ofrece `recordatorios-pendientes` del `empresa-api` ampliado.
     *
     * @return array<string, mixed>
     */
    private function item_de_pedido(): array
    {
        return [
            'id'                 => self::ID_PEDIDO,
            'tarea_id'           => null,
            'fecha'              => now()->toDateString(),
            'hora'               => now()->format('H:i'),
            'detalle'            => self::DETALLE,
            'texto'              => self::TEXTO,
            'ai_conversation_id' => 77,
            'ruta'               => '/tienda',
            'plantilla'          => 'tarea_del_asistente',
            'resumen'            => self::RESUMEN,
        ];
    }

    /**
     * Fakea la consulta de pendientes (con el pedido) y el sello del `empresa-api`.
     *
     * @return void
     */
    private function fakear_empresa_con_el_pedido(): void
    {
        $this->fakear_http([
            '*/asistente/recordatorios-pendientes*' => Http::response(['recordatorios' => [$this->item_de_pedido()]], 200),
            '*/asistente/recordatorios/avisado'     => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * Pedidos que salieron a una ruta del `empresa-api`.
     *
     * @param string $fragmento Pedazo de la URL.
     * @param string $metodo    GET | POST.
     *
     * @return array<int, \Illuminate\Http\Client\Request>
     */
    private function pedidos_a(string $fragmento, string $metodo): array
    {
        $pedidos = [];
        foreach (Http::recorded() as $par) {
            $request = $par[0];
            if ($request->method() === $metodo && strpos($request->url(), $fragmento) !== false) {
                $pedidos[] = $request;
            }
        }

        return $pedidos;
    }

    /**
     * Fila entrante del dueño: abre la ventana de 24 hs del número principal.
     *
     * @param Client $client Cliente.
     *
     * @return void
     */
    private function abrir_ventana(Client $client): void
    {
        $fila                      = new ClientAssistantMessage();
        $fila->client_id           = $client->id;
        $fila->telefono            = (string) $client->phone;
        $fila->phone_number_id     = self::PHONE_NUMBER_ID_PRINCIPAL;
        $fila->direccion           = ClientAssistantMessage::DIRECCION_ENTRANTE;
        $fila->whatsapp_message_id = 'wamid.ENTRANTE-PEDIDO-1';
        $fila->tipo                = 'text';
        $fila->texto               = 'Buen día';
        $fila->estado              = ClientAssistantMessage::ESTADO_RESPONDIDO;
        $fila->ai_conversation_id  = 77;
        $fila->save();
    }

    /**
     * Carga la plantilla de tareas del asistente (nombre en `AdminSetting` y fila activa).
     *
     * @return void
     */
    private function cargar_plantilla_de_tareas(): void
    {
        AdminSetting::set('asistente_tarea_template_name', self::PLANTILLA_TAREA);

        $plantilla                = new ClientTemplate();
        $plantilla->template_name = self::PLANTILLA_TAREA;
        $plantilla->language_code = 'es_AR';
        $plantilla->categoria     = 'recordatorios';
        $plantilla->titulo        = 'Tarea del asistente';
        $plantilla->body_template = 'Ya tengo listo lo que me encargaste: {{1}}.';
        $plantilla->activa        = true;
        $plantilla->save();
    }

    /**
     * Corre el job del aviso como lo haría el worker, resolviendo sus dependencias del contenedor.
     *
     * @param int $client_id Cliente del aviso.
     *
     * @return void
     */
    private function correr_el_job(int $client_id): void
    {
        $clase = self::JOB;
        $job   = new $clase($client_id);

        app()->call([$job, 'handle']);
    }

    /**
     * El job sólo se encola con los reintentos acotados: un solo intento (los reintentos y el sello
     * son del servicio de recordatorios, no de la cola) y un tope de 60 segundos.
     *
     * @return void
     */
    public function test_el_job_tiene_un_solo_intento_y_timeout_corto(): void
    {
        $clase = self::JOB;
        $job   = new $clase(1);

        $this->assertSame(1, $job->tries);
        $this->assertSame(60, $job->timeout);
    }

    /**
     * Ventana abierta: el job manda el texto del pedido por el número del asistente, UNA vez, y lo
     * sella en el `empresa-api` con el id del ítem y el wamid.
     *
     * @return void
     */
    public function test_el_job_con_la_ventana_abierta_manda_el_texto_y_sella(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->cliente_con_clave();
        $this->abrir_ventana($client);
        $this->fakear_empresa_con_el_pedido();

        $this->correr_el_job($client->id);

        $consultas = $this->pedidos_a('/api/admin-sync/asistente/recordatorios-pendientes', 'GET');
        $this->assertCount(1, $consultas);
        $this->assertSame('clave-del-cliente', $consultas[0]->header('X-Admin-Api-Key')[0], 'Se le pregunta al empresa-api DE ESE cliente con la clave de ese cliente.');

        $this->assertCount(1, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
        $this->assertSame((string) $client->phone, $espia->textos[0]['to']);
        $this->assertSame(self::TEXTO, $espia->textos[0]['body']);

        $sellos = $this->pedidos_a('/api/admin-sync/asistente/recordatorios/avisado', 'POST');
        $this->assertCount(1, $sellos);
        $this->assertSame(self::ID_PEDIDO, $sellos[0]['id']);
        $this->assertSame('wamid.saliente.1', $sellos[0]['whatsapp_message_id']);
    }

    /**
     * Ventana cerrada con la plantilla de tareas: sale por plantilla con el `resumen` del ítem, nunca
     * como texto libre (Meta lo rechaza fuera de las 24 hs), y se sella con `via = plantilla`.
     *
     * @return void
     */
    public function test_el_job_con_la_ventana_cerrada_manda_la_plantilla_de_tareas(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->cliente_con_clave();
        $this->cargar_plantilla_de_tareas();
        $this->fakear_empresa_con_el_pedido();

        $this->correr_el_job($client->id);

        $this->assertCount(0, $espia->textos, 'Fuera de la ventana no se manda texto libre.');
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame(self::PLANTILLA_TAREA, $espia->plantillas[0]['template_name']);
        $this->assertSame([self::RESUMEN], $espia->plantillas[0]['variables']);

        $sellos = $this->pedidos_a('/api/admin-sync/asistente/recordatorios/avisado', 'POST');
        $this->assertCount(1, $sellos);
        $this->assertSame(self::ID_PEDIDO, $sellos[0]['id']);
        $this->assertSame('plantilla', $sellos[0]['via']);
    }

    /**
     * Ventana cerrada y sin plantilla que sirva: el job no manda nada ni sella (queda para que salga
     * como texto cuando el dueño escriba), y no tira excepción.
     *
     * @return void
     */
    public function test_el_job_sin_ventana_ni_plantilla_no_manda_ni_sella(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->cliente_con_clave();
        $this->fakear_empresa_con_el_pedido();

        $this->correr_el_job($client->id);

        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
        $this->assertCount(0, $this->pedidos_a('/api/admin-sync/asistente/recordatorios/avisado', 'POST'));
    }

    /**
     * Si cuando el job corre el cliente ya no tiene canal (se apagó entre el encolado y el worker),
     * no le pregunta nada a su `empresa-api` ni manda nada.
     *
     * @return void
     */
    public function test_el_job_vuelve_a_chequear_el_canal_antes_de_enviar(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->cliente_con_clave();
        $this->abrir_ventana($client);
        $this->fakear_empresa_con_el_pedido();

        $client->forceFill(['asistente_whatsapp_activo' => false])->save();

        $this->correr_el_job($client->id);

        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
        $this->assertCount(0, $this->pedidos_a('/api/admin-sync/asistente/recordatorios-pendientes', 'GET'));
    }

    /**
     * Un cliente que ya no existe (job viejo en la cola) no rompe el worker ni sale a la red.
     *
     * @return void
     */
    public function test_el_job_de_un_cliente_inexistente_no_hace_nada(): void
    {
        $espia = $this->espiar_sender();

        $this->correr_el_job(999999999);

        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
        Http::assertNothingSent();
    }

    /**
     * 🔴 Un solo mensaje por pedido: el job del empuje y la consulta de respaldo de cada 5 minutos
     * ven el MISMO ítem, y entre las dos sale un solo WhatsApp (el reclamo del servicio es atómico y
     * el job no se salta ese reclamo).
     *
     * @return void
     */
    public function test_el_job_y_la_consulta_de_respaldo_mandan_un_solo_mensaje(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->cliente_con_clave();
        $this->abrir_ventana($client);
        $this->fakear_empresa_con_el_pedido();

        $this->correr_el_job($client->id);
        app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $this->assertCount(1, $espia->textos, 'El empuje y el respaldo no pueden mandarle dos veces el mismo pedido al dueño.');
        $this->assertCount(1, $this->pedidos_a('/api/admin-sync/asistente/recordatorios/avisado', 'POST'));
    }

    /**
     * Dos corridas del job para el mismo cliente (dos pedidos pegados que llegan juntos) tampoco
     * repiten el mensaje: el segundo job ve el ítem ya reclamado.
     *
     * @return void
     */
    public function test_dos_corridas_del_job_mandan_un_solo_mensaje(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->cliente_con_clave();
        $this->abrir_ventana($client);
        $this->fakear_empresa_con_el_pedido();

        $this->correr_el_job($client->id);
        $this->correr_el_job($client->id);

        $this->assertCount(1, $espia->textos);
    }
}
