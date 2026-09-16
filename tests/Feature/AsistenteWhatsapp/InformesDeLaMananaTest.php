<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\ClientTemplate;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\AsistenteInformesService;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Los informes del mostrador que salen a las 8:30 por WhatsApp.
 *
 * Lo que estas pruebas cuidan es la decisión que puede hacer que esta parte **no funcione en
 * producción**: a las 8:30 de la mañana la ventana de 24 hs de Meta está cerrada para casi todos
 * los dueños —nadie le escribió al número desde ayer—, así que el camino real no es el texto libre
 * sino la plantilla aprobada. Sin esa plantilla no sale nada, y eso tiene que quedar dicho y
 * medido, no descubierto en producción una mañana cualquiera.
 *
 * La otra mitad es el orden de los dos pasos: primero sale el WhatsApp y recién después se marca el
 * informe como avisado. Al revés, un fallo de Meta deja el informe marcado para siempre y al dueño
 * sin enterarse nunca.
 */
class InformesDeLaMananaTest extends BaseDelCanal
{
    /**
     * Payload que devuelve el `empresa-api` del cliente con los informes del día.
     *
     * @return array<string, mixed>
     */
    private function informes_del_dia(): array
    {
        return [
            'informes' => [
                [
                    'id'      => 11,
                    'tipo'    => 'rendimiento',
                    'titulo'  => 'Rendimiento de ayer',
                    'resumen' => 'Vendiste $184.500 en 23 ventas, 12% arriba del lunes pasado.',
                    'url'     => 'https://ferreteria.comerciocity.com/informe/abc123',
                ],
                [
                    'id'      => 12,
                    'tipo'    => 'caja',
                    'titulo'  => 'Caja y vencimientos',
                    'resumen' => 'Tenés dos cheques que vencen esta semana.',
                    'url'     => 'https://ferreteria.comerciocity.com/informe/def456',
                ],
            ],
        ];
    }

    /**
     * Abre la ventana de 24 hs metiendo un entrante reciente del mismo número.
     *
     * Se hace por el pipeline de leads porque `WhatsappSessionWindowService` mira las tres tablas y
     * ésta es la que menos andamiaje pide. La ventana es POR NÚMERO, no por canal, así que un
     * entrante de cualquiera de los tres la abre para los tres — que es exactamente lo que este
     * servicio consulta, y el caso real del dueño que además fue lead.
     *
     * @param string $telefono Teléfono del dueño.
     *
     * @return void
     */
    private function abrir_la_ventana(string $telefono): void
    {
        $lead               = new Lead();
        $lead->contact_name = 'Dueño de prueba';
        $lead->phone        = $telefono;
        $lead->save();

        $mensaje                      = new LeadMessage();
        $mensaje->lead_id             = $lead->id;
        $mensaje->sender              = 'lead';
        $mensaje->content             = 'Buen día';
        $mensaje->whatsapp_message_id = 'wamid.ABRE-LA-VENTANA';
        $mensaje->save();
    }

    /**
     * Ventana abierta: sale como texto libre, con el título, el resumen y el link de cada informe.
     *
     * @return void
     */
    public function test_con_la_ventana_abierta_sale_como_texto(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('enviado_texto', $resultado['estado']);
        $this->assertSame(2, $resultado['informes']);

        $this->assertCount(1, $espia->textos, 'Los informes del día van en UN solo mensaje.');
        $this->assertCount(0, $espia->plantillas);

        $cuerpo = $espia->textos[0]['body'];
        $this->assertStringContainsString('Rendimiento de ayer', $cuerpo);
        $this->assertStringContainsString('Caja y vencimientos', $cuerpo);
        $this->assertStringContainsString('https://ferreteria.comerciocity.com/informe/abc123', $cuerpo);
        $this->assertStringContainsString('Vendiste $184.500', $cuerpo);
    }

    /**
     * Ventana cerrada y con plantilla cargada: sale por plantilla, con el link adentro.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_sale_por_plantilla(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();

        AdminSetting::set(AsistenteWhatsappSettings::KEY_INFORME_TEMPLATE_NAME, 'informes_del_dia');
        $this->crear_plantilla('informes_del_dia');

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('enviado_plantilla', $resultado['estado']);

        $this->assertCount(0, $espia->textos, 'Fuera de la ventana no se manda texto libre: Meta lo rechaza.');
        $this->assertCount(1, $espia->plantillas);

        $this->assertSame('informes_del_dia', $espia->plantillas[0]['template_name']);
        $this->assertCount(1, $espia->plantillas[0]['variables']);

        $variable = $espia->plantillas[0]['variables'][0];
        $this->assertStringContainsString('Rendimiento de ayer', $variable);
        $this->assertStringContainsString('https://ferreteria.comerciocity.com/informe/abc123', $variable);
        $this->assertStringNotContainsString("\n", $variable, 'Meta rechaza el envío entero si un parámetro trae saltos de línea.');
    }

    /**
     * 🔴 Ventana cerrada y SIN plantilla: no se manda nada y no se marca nada.
     *
     * Es el riesgo número uno de esta misión y por eso tiene prueba propia. No hay repliegue a texto
     * libre a propósito: fuera de ventana Meta lo rechaza, consume ventana de conversación y deja un
     * fallo que hay que ir a interpretar.
     *
     * @return void
     */
    public function test_sin_plantilla_no_se_manda_nada_y_no_se_marca_nada(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();

        AdminSetting::where('key', AsistenteWhatsappSettings::KEY_INFORME_TEMPLATE_NAME)->delete();

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('sin_plantilla', $resultado['estado']);
        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas);

        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/avisado') !== false;
        });
    }

    /**
     * Una plantilla configurada pero inactiva es lo mismo que no tener ninguna.
     *
     * Es el caso real de una plantilla que Meta desaprueba: la fila se marca inactiva y se deja,
     * porque el historial de lo que ya se mandó tiene que seguir existiendo.
     *
     * @return void
     */
    public function test_una_plantilla_inactiva_no_sirve(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();

        AdminSetting::set(AsistenteWhatsappSettings::KEY_INFORME_TEMPLATE_NAME, 'informes_del_dia');
        $this->crear_plantilla('informes_del_dia', false);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('sin_plantilla', $resultado['estado']);
        $this->assertCount(0, $espia->plantillas);
    }

    /**
     * El informe se marca como avisado DESPUÉS de que el WhatsApp salió, nunca antes.
     *
     * @return void
     */
    public function test_el_informe_se_marca_despues_de_mandarlo(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        app(AsistenteInformesService::class)->enviar_a($client);

        foreach ([11, 12] as $informe_id) {
            Http::assertSent(function ($request) use ($informe_id) {
                return strpos($request->url(), '/asistente/informes/' . $informe_id . '/avisado') !== false;
            });
        }
    }

    /**
     * Si Meta rechaza el envío, ningún informe queda marcado como avisado.
     *
     * 🔴 Es el orden de los dos pasos, medido. Al revés, el dueño perdería el informe de ese día sin
     * que nada lo denuncie y sin forma de recuperarlo: mañana el `empresa-api` ya lo cuenta como
     * avisado.
     *
     * @return void
     */
    public function test_si_el_envio_falla_el_informe_no_queda_marcado(): void
    {
        $this->espiar_sender(false);
        $client = $this->crear_cliente();
        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('error', $resultado['estado']);

        Http::assertNotSent(function ($request) {
            return strpos($request->url(), '/avisado') !== false;
        });
    }

    /**
     * Un cliente cuyo sistema no tiene la ruta degrada en silencio, sin molestar al dueño.
     *
     * A diferencia del canal de mensajes, acá el dueño no pidió nada: mandarle un texto explicando
     * que le falta una actualización sería ruido no solicitado a las 8:30 de la mañana.
     *
     * @return void
     */
    public function test_un_cliente_sin_la_ruta_degrada_en_silencio(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();

        $this->fakear_http(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('degradado', $resultado['estado']);
        $this->assertCount(0, $espia->textos);
        $this->assertCount(0, $espia->plantillas);
    }

    /**
     * Sin informes del día no se manda nada.
     *
     * @return void
     */
    public function test_sin_informes_no_se_manda_nada(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response(['informes' => []], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('sin_informes', $resultado['estado']);
        $this->assertCount(0, $espia->textos);
    }

    /**
     * Un cliente sin teléfono o sin clave no llega ni a salir a la red.
     *
     * @return void
     */
    public function test_sin_telefono_o_sin_clave_no_se_sale_a_la_red(): void
    {
        $this->espiar_sender();

        $sin_telefono = $this->crear_cliente('');
        $this->assertSame('sin_telefono', app(AsistenteInformesService::class)->enviar_a($sin_telefono)['estado']);

        $sin_clave = $this->crear_cliente('+5493411234567', true, '');
        $this->assertSame('sin_api_key', app(AsistenteInformesService::class)->enviar_a($sin_clave)['estado']);

        Http::assertNothingSent();
    }

    /**
     * El barrido toma solo a los clientes activos con el canal prendido.
     *
     * @return void
     */
    public function test_el_barrido_toma_solo_a_los_del_canal_prendido(): void
    {
        $this->espiar_sender();

        $prendido = $this->crear_cliente('+5493411111111', true);
        $apagado  = $this->crear_cliente('+5493412222222', false);

        $inactivo            = $this->crear_cliente('+5493413333333', true);
        $inactivo->is_active = false;
        $inactivo->save();

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response(['informes' => []], 200),
        ]);

        $resultados = app(AsistenteInformesService::class)->enviar_a_todos();

        $ids = array_map(function (array $fila) {
            return $fila['client_id'];
        }, $resultados);

        $this->assertContains((int) $prendido->id, $ids);
        $this->assertNotContains((int) $apagado->id, $ids);
        $this->assertNotContains((int) $inactivo->id, $ids);
    }

    /**
     * El texto de la variable de plantilla recorta informes antes que cortar un link por la mitad.
     *
     * Un link cortado no abre nada; un informe que quedó afuera se lee igual desde el sistema, y el
     * mensaje lo dice.
     *
     * @return void
     */
    public function test_la_variable_de_plantilla_recorta_informes_antes_que_links(): void
    {
        $servicio = app(AsistenteInformesService::class);

        $muchos = [];
        for ($i = 1; $i <= 12; $i++) {
            $muchos[] = [
                'id'      => $i,
                'tipo'    => 'informe',
                'titulo'  => 'Informe bastante largo número ' . $i,
                'resumen' => 'Un resumen que no entra en ninguna plantilla de Meta.',
                'url'     => 'https://ferreteria.comerciocity.com/informe/token-largo-' . $i,
            ];
        }

        $variable = $servicio->armar_variable_de_plantilla($muchos);

        $this->assertLessThan(AsistenteInformesService::LARGO_MAXIMO_DE_VARIABLE, mb_strlen($variable));
        $this->assertStringContainsString('Informe bastante largo número 1', $variable);
        $this->assertStringContainsString('token-largo-1', $variable);
        $this->assertStringContainsString('más en el sistema', $variable);
    }

    /**
     * Un informe sin link se manda igual, con su resumen.
     *
     * El `empresa-api` devuelve `url: null` a propósito cuando no puede armar la URL del SPA, en vez
     * de mandarle un link roto a cuarenta dueños. Acá eso no puede frenar el aviso.
     *
     * @return void
     */
    public function test_un_informe_sin_link_se_manda_igual(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response([
                'informes' => [
                    [
                        'id'      => 30,
                        'tipo'    => 'rendimiento',
                        'titulo'  => 'Rendimiento de ayer',
                        'resumen' => 'Vendiste $10.000.',
                        'url'     => null,
                    ],
                ],
            ], 200),
            '*/avisado' => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('enviado_texto', $resultado['estado']);
        $this->assertStringContainsString('Vendiste $10.000.', $espia->textos[0]['body']);
    }

    /**
     * 🔴 Cuando el cliente no manda link, se arma con su `spa_url` y el token.
     *
     * Y ese es el camino REAL, no el de borde: el `empresa-api` arma su URL leyendo una config del
     * `.env` del cliente que **no tiene ningún cliente cargada** —no está en el seeder de plantillas
     * de `.env` ni la escribe la generación del admin—, así que devuelve null para los cuarenta. Sin
     * este repliegue, la decisión de Lucas de mandar "resumen + link" quedaría en "resumen" para
     * todos, con un warning en el log de cada cliente como única señal.
     *
     * @return void
     */
    public function test_sin_link_del_cliente_se_arma_con_la_spa_url(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->crear_client_api($client);
        $client->active_client_api->spa_url = 'https://ferreteria.comerciocity.com/';
        $client->active_client_api->save();

        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response([
                'informes' => [
                    [
                        'id'      => 41,
                        'tipo'    => 'rendimiento',
                        'titulo'  => 'Rendimiento de ayer',
                        'resumen' => 'Vendiste $10.000.',
                        'url'     => null,
                        'token'   => 'token-largo-del-informe',
                    ],
                ],
            ], 200),
            '*/avisado' => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('enviado_texto', $resultado['estado']);
        $this->assertStringContainsString(
            'https://ferreteria.comerciocity.com/informe/token-largo-del-informe',
            $espia->textos[0]['body']
        );
    }

    /**
     * La `url` que manda el cliente gana: la arma el sistema que sabe cómo se llega a sí mismo.
     *
     * @return void
     */
    public function test_si_el_cliente_manda_link_se_respeta(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->crear_client_api($client);
        $client->active_client_api->spa_url = 'https://otra-cosa.test';
        $client->active_client_api->save();

        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertStringContainsString(
            'https://ferreteria.comerciocity.com/informe/abc123',
            $espia->textos[0]['body']
        );
        $this->assertStringNotContainsString('otra-cosa.test', $espia->textos[0]['body']);
    }

    /**
     * Sin `spa_url` y sin `url`, el informe sale con el resumen y sin link — no se cae.
     *
     * @return void
     */
    public function test_sin_spa_url_el_informe_sale_sin_link(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response([
                'informes' => [
                    [
                        'id'      => 42,
                        'tipo'    => 'caja',
                        'titulo'  => 'Caja y vencimientos',
                        'resumen' => 'Dos cheques esta semana.',
                        'url'     => null,
                        'token'   => 'un-token',
                    ],
                ],
            ], 200),
            '*/avisado' => Http::response(['ok' => true], 200),
        ]);

        $resultado = app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame('enviado_texto', $resultado['estado']);
        $this->assertStringContainsString('Dos cheques esta semana.', $espia->textos[0]['body']);
        $this->assertStringNotContainsString('/informe/', $espia->textos[0]['body']);
    }

    /**
     * 🔴 El informe deja su fila saliente, con la conversación del informe.
     *
     * Es lo que hace que al informe se le pueda PREGUNTAR algo, que es la mitad de lo que pidió
     * Lucas. Sin esta fila, el dueño responde citando el informe y la cita no resuelve nada: la
     * pregunta cae en el hilo genérico y el asistente contesta sin el informe delante.
     *
     * @return void
     */
    public function test_el_informe_deja_fila_saliente_con_su_conversacion(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response([
                'informes' => [
                    [
                        'id'                 => 51,
                        'tipo'               => 'rendimiento',
                        'titulo'             => 'Rendimiento de ayer',
                        'resumen'            => 'Vendiste $10.000.',
                        'url'                => 'https://ferreteria.comerciocity.com/informe/uno',
                        'ai_conversation_id' => 808,
                    ],
                ],
            ], 200),
            '*/avisado' => Http::response(['ok' => true], 200),
        ]);

        app(AsistenteInformesService::class)->enviar_a($client);

        $saliente = ClientAssistantMessage::where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();

        $this->assertNotNull($saliente, 'El informe tenía que dejar su fila saliente.');
        $this->assertSame('wamid.saliente.1', $saliente->whatsapp_message_id);
        $this->assertSame(808, (int) $saliente->ai_conversation_id);
        $this->assertStringContainsString('Rendimiento de ayer', (string) $saliente->texto);

        /* Y con eso, responder citando el informe lleva a la conversación del informe. */
        $this->assertSame(
            808,
            ClientAssistantMessage::conversacion_por_cita((int) $client->id, 'wamid.saliente.1')
        );
    }

    /**
     * Un `empresa-api` viejo no manda la conversación: la fila queda igual, sin ella.
     *
     * Es la degradación correcta — la pregunta entra como conversación común de WhatsApp — y no un
     * error: el informe igual le llegó al dueño.
     *
     * @return void
     */
    public function test_sin_conversacion_del_cliente_la_fila_queda_igual(): void
    {
        $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        app(AsistenteInformesService::class)->enviar_a($client);

        $saliente = ClientAssistantMessage::where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();

        $this->assertNotNull($saliente);
        $this->assertNull($saliente->ai_conversation_id);
    }

    /**
     * Si el envío falla, no queda ninguna fila saliente inventada.
     *
     * @return void
     */
    public function test_un_envio_rechazado_no_deja_fila_saliente(): void
    {
        $this->espiar_sender(false);
        $client = $this->crear_cliente();
        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
        ]);

        app(AsistenteInformesService::class)->enviar_a($client);

        $this->assertSame(
            0,
            ClientAssistantMessage::where('client_id', $client->id)->count()
        );
    }

    /**
     * Por plantilla también queda la fila, con el texto ya renderizado.
     *
     * @return void
     */
    public function test_por_plantilla_tambien_queda_la_fila_saliente(): void
    {
        $this->espiar_sender();
        $client = $this->crear_cliente();

        AdminSetting::set(AsistenteWhatsappSettings::KEY_INFORME_TEMPLATE_NAME, 'informes_del_dia');
        $this->crear_plantilla('informes_del_dia');

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        app(AsistenteInformesService::class)->enviar_a($client);

        $saliente = ClientAssistantMessage::where('client_id', $client->id)
            ->where('direccion', ClientAssistantMessage::DIRECCION_SALIENTE)
            ->first();

        $this->assertNotNull($saliente);
        $this->assertSame('wamid.plantilla.1', $saliente->whatsapp_message_id);
        $this->assertStringContainsString('Rendimiento de ayer', (string) $saliente->texto);
        $this->assertStringNotContainsString('{{1}}', (string) $saliente->texto);
    }

    /**
     * El comando corre el barrido y termina bien aunque no haya nada que mandar.
     *
     * @return void
     */
    public function test_el_comando_corre_el_barrido(): void
    {
        $espia  = $this->espiar_sender();
        $client = $this->crear_cliente();
        $this->abrir_la_ventana((string) $client->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('asistente:enviar-informes')->assertExitCode(0);

        $this->assertCount(1, $espia->textos);
    }

    /**
     * El comando grita cuando hubo informes y no se mandó nada por falta de plantilla.
     *
     * 🔴 Es lo que separa este estado de "no había informes": por fuera se ven iguales —el comando
     * corre, no rompe nada y nadie recibe nada—, y a las 8:30 la ventana está cerrada para casi
     * todos, así que sin la plantilla este es SIEMPRE el camino.
     *
     * @return void
     */
    public function test_el_comando_grita_cuando_falta_la_plantilla(): void
    {
        $this->espiar_sender();
        $this->crear_cliente();

        AdminSetting::where('key', AsistenteWhatsappSettings::KEY_INFORME_TEMPLATE_NAME)->delete();

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
        ]);

        /* Se mira la salida entera y no una línea exacta: lo que importa es que el motivo aparezca,
         * no cómo quede formateada la tabla. */
        $this->assertSame(0, Artisan::call('asistente:enviar-informes'));
        $this->assertStringContainsString('falta de plantilla de Meta aprobada', Artisan::output());
    }

    /**
     * Con `--client` se manda uno solo, sin barrer a los demás.
     *
     * @return void
     */
    public function test_el_comando_puede_reintentar_un_solo_cliente(): void
    {
        $espia = $this->espiar_sender();

        $uno  = $this->crear_cliente('+5493411111111');
        $otro = $this->crear_cliente('+5493412222222');
        $this->abrir_la_ventana((string) $uno->phone);

        $this->fakear_http([
            '*/asistente/informes-pendientes' => Http::response($this->informes_del_dia(), 200),
            '*/avisado'                       => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('asistente:enviar-informes', ['--client' => $uno->id])->assertExitCode(0);

        $this->assertCount(1, $espia->textos);
        $this->assertSame('+5493411111111', $espia->textos[0]['to']);
    }

    /**
     * Plantilla de cliente aprobada en Meta, con un solo `{{1}}`.
     *
     * @param string $nombre Nombre con el que quedó aprobada.
     * @param bool   $activa Si está disponible para usar.
     *
     * @return ClientTemplate
     */
    private function crear_plantilla(string $nombre, bool $activa = true): ClientTemplate
    {
        $plantilla                  = new ClientTemplate();
        $plantilla->template_name   = $nombre;
        $plantilla->language_code   = 'es_AR';
        $plantilla->categoria       = 'informes';
        $plantilla->titulo          = 'Informes del día';
        $plantilla->body_template   = 'Buen día. Te dejo tus informes de hoy: {{1}}';
        $plantilla->activa          = $activa;
        $plantilla->save();

        return $plantilla;
    }
}
