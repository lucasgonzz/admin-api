<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los frenos del envío de plantillas por Claude (endpoints `claude/leads/{id}/send-template` y
 * `claude/send-template-batch`).
 *
 * Este test existe por un motivo puntual y no por completitud: estos dos endpoints son los únicos
 * de todo `claude/*` que le mandan un WhatsApp a un lead REAL de producción, y un mensaje enviado
 * no se deshace. Los frenos —simulación por defecto, confirmación del conteo exacto, tope de lote
 * y cooldown— son la única cosa entre un filtro mal escrito y 50 personas recibiendo un mensaje
 * que no correspondía. Todo lo que se verifica acá es "NO se envió nada", que es exactamente lo
 * que un test de camino feliz nunca mira.
 *
 * El envío se sustituye a nivel WhatsappSendService, así que no se toca la red ni Meta, pero el
 * resto del camino (validación, resolución de leads, persistencia del LeadMessage) es el real.
 */
class EnvioDePlantillaPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude';

    /**
     * Setea la clave de ingesta en config para que el middleware fail-closed deje pasar.
     *
     * En el .env del slot CLAUDE_TASK_INGEST_KEY está vacía, así que sin esto TODO `claude/*`
     * devuelve 401 y los tests medirían el middleware en vez del controlador.
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
     * Crea un lead con teléfono, listo para recibir un envío.
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
        $lead->status       = $status;
        $lead->save();

        return $lead;
    }

    /**
     * Sustituye WhatsappSendService por un espía que cuenta los envíos en vez de llamar a Meta.
     *
     * @param bool $confirma True: devuelve un whatsapp_message_id. False: simula envío no confirmado.
     *
     * @return WhatsappSendService El espía, ya registrado en el contenedor.
     */
    private function espiar_sender(bool $confirma = true): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Envíos que se intentaron. */
            public $envios = [];

            /** @var bool Si el envío se confirma o no. */
            public $confirma = true;

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->envios[] = [
                    'to'            => $to,
                    'template_name' => $template_name,
                    'variables'     => $variables,
                    'language_code' => $language_code,
                ];

                if (! $this->confirma) {
                    $this->last_send_error = 'Meta rechazó el envío (simulado en el test).';

                    return null;
                }

                return 'wamid.test.' . count($this->envios);
            }
        };

        $espia->confirma = $confirma;
        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Sin el header de la clave, el endpoint de envío no atiende.
     *
     * @return void
     */
    public function test_sin_clave_el_envio_devuelve_401()
    {
        $lead  = $this->crear_lead('Sin clave');
        $espia = $this->espiar_sender();

        $respuesta = $this->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
            'template_name' => 'cc_recuperacion_demora_propia',
            'content'       => 'Hola!',
        ]);

        $respuesta->assertStatus(401);
        $this->assertCount(0, $espia->envios, 'No se pudo haber intentado ningún envío sin clave.');
    }

    /**
     * El envío individual deja el mensaje marcado como enviado por Claude, sin admin y sin
     * consumir el cupo de seguimientos del lead.
     *
     * @return void
     */
    public function test_el_envio_individual_marca_el_mensaje_como_enviado_por_claude()
    {
        $lead  = $this->crear_lead('Marcado');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
                'template_name' => 'cc_recuperacion_demora_propia',
                'variables'     => ['Marcado'],
                'content'       => 'Hola Marcado! Perdoná la demora.',
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['enviado' => true]);

        $this->assertCount(1, $espia->envios);
        $this->assertSame('cc_recuperacion_demora_propia', $espia->envios[0]['template_name']);
        $this->assertSame(['Marcado'], $espia->envios[0]['variables']);

        $mensaje = LeadMessage::query()->where('lead_id', $lead->id)->first();
        $this->assertNotNull($mensaje, 'Tiene que quedar registrado el mensaje en la conversación.');
        $this->assertSame('claude', $mensaje->sent_via, 'El origen tiene que quedar marcado como claude.');
        $this->assertNull($mensaje->sent_by_admin_id, 'No hay admin detrás de un envío de Claude.');
        $this->assertSame('setter', $mensaje->sender, 'Con sender=sistema se pintaría como sugerencia de IA.');
        $this->assertFalse((bool) $mensaje->is_followup, 'is_followup=false para no consumir el cupo del lead.');
        $this->assertNotNull($mensaje->whatsapp_message_id);
    }

    /**
     * Un envío que no se confirma igual queda registrado en el hilo, con el motivo, para que se
     * pueda encontrar después con `claude/messages?has_send_error=1`.
     *
     * @return void
     */
    public function test_un_envio_que_falla_igual_queda_registrado_con_el_motivo()
    {
        $lead  = $this->crear_lead('Fallado');
        $espia = $this->espiar_sender(false);

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
                'template_name' => 'cc_recuperacion_demora_propia',
                'content'       => 'Hola Fallado!',
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['enviado' => false]);

        $mensaje = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sent_via', 'claude')
            ->first();

        $this->assertNotNull($mensaje, 'Un envío fallido igual tiene que dejar rastro en el hilo.');
        $this->assertNull($mensaje->whatsapp_message_id);
        $this->assertNotEmpty($mensaje->whatsapp_send_error, 'Sin motivo, el mensaje sería invisible para la consulta de fallidos.');
    }

    /**
     * 🔴 El freno principal: por defecto el lote SIMULA. Sin dry_run explícito no sale ni un mensaje.
     *
     * @return void
     */
    public function test_el_lote_simula_por_defecto_y_no_envia_nada()
    {
        $uno  = $this->crear_lead('Uno');
        $dos  = $this->crear_lead('Dos');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'         => [$uno->id, $dos->id],
                'template_name'    => 'cc_recuperacion_demora_propia',
                'content_template' => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['dry_run' => true, 'enviarian' => 2]);

        $this->assertCount(0, $espia->envios, 'La simulación no puede llamar al sender ni una vez.');
        $this->assertSame(
            0,
            LeadMessage::query()->whereIn('lead_id', [$uno->id, $dos->id])->count(),
            'La simulación no puede crear ningún LeadMessage.'
        );
    }

    /**
     * 🔴 Un confirm_count que no coincide corta el lote entero, sin enviar nada.
     *
     * Es el caso que protege del error más probable: yo pido la simulación, alguien agrega o saca
     * un lead de la lista, y el envío real sale a un conjunto distinto del que se revisó.
     *
     * @return void
     */
    public function test_un_confirm_count_equivocado_no_envia_nada()
    {
        $uno   = $this->crear_lead('Uno');
        $dos   = $this->crear_lead('Dos');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$uno->id, $dos->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
                'dry_run'              => false,
                'confirm_count'        => 3,
            ]);

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios, 'Con el conteo desfasado no puede salir ni un mensaje.');
        $this->assertSame(0, LeadMessage::query()->whereIn('lead_id', [$uno->id, $dos->id])->count());
    }

    /**
     * 🔴 Sin confirm_count, dry_run=false tampoco alcanza para enviar.
     *
     * @return void
     */
    public function test_sin_confirm_count_no_se_envia_aunque_dry_run_sea_false()
    {
        $lead  = $this->crear_lead('Solo');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$lead->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
                'dry_run'              => false,
            ]);

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios);
    }

    /**
     * 🔴 Un lote por encima del tope se rechaza entero, sin enviar nada.
     *
     * El tope existe por el timeout: 15 s de timeout por envío con dos reintentos son ~45 s por
     * fallo, y con Meta caído —el escenario mismo para el que existe este endpoint— un lote grande
     * se comería el request entero sin devolver respuesta.
     *
     * @return void
     */
    public function test_un_lote_por_encima_del_tope_se_rechaza_entero()
    {
        $espia = $this->espiar_sender();

        /* No hace falta que los leads existan: el tope se chequea antes de resolverlos. */
        $ids = range(1, 51);

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'         => $ids,
                'template_name'    => 'cc_recuperacion_demora_propia',
                'content_template' => 'Hola!',
                'dry_run'          => false,
                'confirm_count'    => 51,
            ]);

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios, 'Por encima del tope no puede salir ni un mensaje.');
    }

    /**
     * Con el conteo correcto, el lote sí envía, y marca cada mensaje como enviado por Claude.
     *
     * @return void
     */
    public function test_con_el_conteo_correcto_el_lote_envia()
    {
        $uno   = $this->crear_lead('Uno');
        $dos   = $this->crear_lead('Dos');
        $espia = $this->espiar_sender();

        /* Paso 1: la simulación, que devuelve el conteo y el token del conjunto. */
        $cuerpo = [
            'lead_ids'             => [$uno->id, $dos->id],
            'template_name'        => 'cc_recuperacion_demora_propia',
            'content_template'     => 'Hola {{1}}!',
            'variables_desde_lead' => ['contact_name'],
        ];

        $simulacion = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', $cuerpo);
        $simulacion->assertStatus(200);
        $simulacion->assertJson(['dry_run' => true, 'enviarian' => 2]);

        /* El teléfono va enmascarado en la simulación: revisar a quién le pega no puede ser
           una forma de exportar la agenda. */
        foreach ($simulacion->json('destinatarios') as $destinatario) {
            $this->assertStringStartsWith('*', $destinatario['telefono_enmascarado']);
            $this->assertStringNotContainsString('549341', $destinatario['telefono_enmascarado']);
        }

        /* Paso 2: el envío real, con el conteo y el token que devolvió la simulación. */
        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', array_merge($cuerpo, [
                'dry_run'       => false,
                'confirm_count' => 2,
                'confirm_token' => $simulacion->json('confirm_token'),
            ]));

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['dry_run' => false, 'enviados' => 2]);

        $this->assertCount(2, $espia->envios);

        $mensajes = LeadMessage::query()
            ->whereIn('lead_id', [$uno->id, $dos->id])
            ->where('sent_via', 'claude')
            ->get();

        $this->assertCount(2, $mensajes);
        foreach ($mensajes as $mensaje) {
            $this->assertFalse((bool) $mensaje->is_followup);
            $this->assertNull($mensaje->sent_by_admin_id);
        }

        /* El contenido se renderiza por lead, no se manda el template crudo. */
        $contenidos = $mensajes->pluck('content')->all();
        sort($contenidos);
        $this->assertSame(['Hola Dos!', 'Hola Uno!'], $contenidos);
    }

    /**
     * 🔴 El cooldown: un lead que ya recibió un mensaje de Claude en las últimas 24 hs se omite.
     *
     * Es lo que hace seguro reintentar un lote que se cortó por red a mitad de camino: sin esto,
     * los leads que ya habían recibido el mensaje lo reciben dos veces.
     *
     * @return void
     */
    public function test_un_lead_con_envio_reciente_de_claude_queda_omitido()
    {
        $reciente = $this->crear_lead('Reciente');
        $limpio   = $this->crear_lead('Limpio');

        /* Envío previo de Claude que SÍ salió, dentro de la ventana de cooldown.
           El whatsapp_message_id no es decorativo: el cooldown cuenta mensajes que efectivamente
           llegaron, no intentos. Ver test_un_envio_fallido_no_deja_al_lead_en_cooldown. */
        LeadMessage::create([
            'lead_id'             => $reciente->id,
            'sender'              => 'setter',
            'content'             => 'Mensaje anterior de Claude',
            'status'              => 'enviado',
            'sent_via'            => 'claude',
            'whatsapp_message_id' => 'wamid.anterior',
        ]);

        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$reciente->id, $limpio->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['dry_run' => true, 'enviarian' => 1]);

        $cuerpo = $respuesta->json();
        $this->assertCount(1, $cuerpo['omitidos'], 'El lead con envío reciente tiene que salir omitido.');
        $this->assertSame($reciente->id, $cuerpo['omitidos'][0]['lead_id']);
    }

    /**
     * Un lead sin teléfono se omite del lote en vez de romperlo.
     *
     * @return void
     */
    public function test_un_lead_sin_telefono_se_omite_sin_romper_el_lote()
    {
        $con_telefono = $this->crear_lead('Con telefono');

        $sin_telefono               = new Lead();
        $sin_telefono->contact_name = 'Sin telefono';
        $sin_telefono->status       = 'contactado';
        $sin_telefono->save();

        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$con_telefono->id, $sin_telefono->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['dry_run' => true, 'enviarian' => 1]);
        $this->assertCount(1, $respuesta->json()['omitidos']);
    }

    /**
     * 🔴 Un envío que FALLÓ no deja al lead en cooldown.
     *
     * Sin esto el endpoint se saboteaba justo en el caso para el que existe: con Meta caído el
     * envío falla, igual se graba la fila para la trazabilidad, y el lead quedaba 24 hs sin poder
     * recibir nada habiendo recibido nada. El cooldown cuenta mensajes que llegaron, no intentos.
     *
     * @return void
     */
    public function test_un_envio_fallido_no_deja_al_lead_en_cooldown()
    {
        $lead = $this->crear_lead('Reintentable');

        /* Intento anterior de Claude que no salió: sin whatsapp_message_id, con motivo. */
        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'setter',
            'content'             => 'Intento que no salió',
            'status'              => 'enviado',
            'sent_via'            => 'claude',
            'whatsapp_message_id' => null,
            'whatsapp_send_error' => 'Meta rechazó: cuenta con pago pendiente',
        ]);

        $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$lead->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['enviarian' => 1]);
        $this->assertCount(
            0,
            $respuesta->json('omitidos'),
            'Un intento fallido no puede bloquear al lead: nunca recibió nada.'
        );
    }

    /**
     * 🔴 Un confirm_token de otro conjunto no sirve, aunque el conteo coincida.
     *
     * Es el hueco que el conteo solo no tapa: simular con dos leads y enviar a otros dos distintos
     * pasaba el chequeo, porque `confirm_count` mira la cantidad y no quiénes son.
     *
     * @return void
     */
    public function test_un_token_de_otro_conjunto_no_habilita_el_envio()
    {
        $uno    = $this->crear_lead('Uno');
        $dos    = $this->crear_lead('Dos');
        $tres   = $this->crear_lead('Tres');
        $cuatro = $this->crear_lead('Cuatro');
        $espia  = $this->espiar_sender();

        $base = [
            'template_name'        => 'cc_recuperacion_demora_propia',
            'content_template'     => 'Hola {{1}}!',
            'variables_desde_lead' => ['contact_name'],
        ];

        /* Simulo con Uno y Dos. */
        $simulacion = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', array_merge($base, [
                'lead_ids' => [$uno->id, $dos->id],
            ]));
        $simulacion->assertStatus(200);

        /* Intento enviar a Tres y Cuatro con el token de Uno y Dos. Son dos también, así que
           el confirm_count coincide: lo único que puede frenarlo es el token. */
        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', array_merge($base, [
                'lead_ids'      => [$tres->id, $cuatro->id],
                'dry_run'       => false,
                'confirm_count' => 2,
                'confirm_token' => $simulacion->json('confirm_token'),
            ]));

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios, 'Con el token de otro conjunto no puede salir nada.');
    }

    /**
     * 🔴 La matriz de `dry_run`: solo un "false" inequívoco habilita el envío real.
     *
     * Es el freno más importante de todos y el más fácil de romper sin darse cuenta con un cambio
     * de cómo se lee el parámetro, así que se prueba valor por valor y no de a uno.
     *
     * @return void
     */
    public function test_solo_un_false_inequivoco_desactiva_la_simulacion()
    {
        $lead = $this->crear_lead('Matriz');

        /* Valores que TIENEN que seguir simulando (o rechazar), nunca enviar. */
        $no_envian = [null, '', ' ', 1, true, 'false', 'true', 'yes', 'no', 'off'];

        foreach ($no_envian as $valor) {
            $espia = $this->espiar_sender();

            $respuesta = $this->withHeaders($this->headers())
                ->postJson('/api/claude/send-template-batch', [
                    'lead_ids'             => [$lead->id],
                    'template_name'        => 'cc_recuperacion_demora_propia',
                    'content_template'     => 'Hola {{1}}!',
                    'variables_desde_lead' => ['contact_name'],
                    'dry_run'              => $valor,
                    'confirm_count'        => 1,
                ]);

            $legible = var_export($valor, true);
            $this->assertContains(
                $respuesta->status(),
                [200, 422],
                'dry_run=' . $legible . ' tendría que simular o rechazar.'
            );
            $this->assertCount(
                0,
                $espia->envios,
                'dry_run=' . $legible . ' NO puede terminar en un envío real.'
            );
        }

        $this->assertSame(
            0,
            LeadMessage::query()->where('lead_id', $lead->id)->count(),
            'Ninguno de los valores ambiguos puede haber creado un mensaje.'
        );
    }

    /**
     * 🔴 El endpoint individual también respeta el cooldown.
     *
     * Sin esto, iterarlo sobre una lista de leads saltea los cuatro frenos del lote: el tope, la
     * simulación, la confirmación del conteo y el propio cooldown.
     *
     * @return void
     */
    public function test_el_endpoint_individual_tambien_respeta_el_cooldown()
    {
        $lead = $this->crear_lead('Insistido');

        LeadMessage::create([
            'lead_id'             => $lead->id,
            'sender'              => 'setter',
            'content'             => 'Mensaje anterior de Claude',
            'status'              => 'enviado',
            'sent_via'            => 'claude',
            'whatsapp_message_id' => 'wamid.anterior',
        ]);

        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
                'template_name' => 'cc_recuperacion_demora_propia',
                'content'       => 'Hola de nuevo!',
            ]);

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios, 'El cooldown tiene que frenar también al endpoint individual.');

        /* Con la decisión explícita sí se puede: es el caso legítimo de "mandale otro a este". */
        $forzado = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
                'template_name'    => 'cc_recuperacion_demora_propia',
                'content'          => 'Hola de nuevo!',
                'ignorar_cooldown' => true,
            ]);

        $forzado->assertStatus(200);
        $this->assertCount(1, $espia->envios);
    }

    /**
     * 🔴 `variables_por_lead` como lista posicional se rechaza.
     *
     * Con una lista, las claves son 0,1,2... y un lead cuyo id coincida con un índice se llevaría
     * las variables de OTRO destinatario: el mensaje de otra persona, con el nombre de otra
     * persona, a un teléfono real. Y sale en silencio.
     *
     * @return void
     */
    public function test_una_lista_posicional_de_variables_se_rechaza()
    {
        $uno   = $this->crear_lead('Uno');
        $dos   = $this->crear_lead('Dos');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'           => [$uno->id, $dos->id],
                'template_name'      => 'cc_recuperacion_demora_propia',
                'content_template'   => 'Hola {{1}}!',
                'variables_por_lead' => [['Uno'], ['Dos']],
            ]);

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios);
    }

    /**
     * Un lead en estado cerrado se omite salvo decisión explícita.
     *
     * @return void
     */
    public function test_un_lead_cerrado_se_omite_salvo_que_se_pida_incluirlo()
    {
        $cerrado = $this->crear_lead('Cerrado', 'cerrado_perdido');
        $this->espiar_sender();

        $base = [
            'lead_ids'             => [$cerrado->id],
            'template_name'        => 'cc_recuperacion_demora_propia',
            'content_template'     => 'Hola {{1}}!',
            'variables_desde_lead' => ['contact_name'],
        ];

        $sin_incluir = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', $base);
        $sin_incluir->assertStatus(200);
        $sin_incluir->assertJson(['enviarian' => 0]);

        $incluyendo = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', array_merge($base, ['include_closed' => true]));
        $incluyendo->assertStatus(200);
        $incluyendo->assertJson(['enviarian' => 1]);
    }

    /**
     * El teléfono no puede usarse como variable de plantilla: sería la puerta de atrás del
     * enmascarado que hace la simulación.
     *
     * @return void
     */
    public function test_el_telefono_no_se_puede_usar_como_variable()
    {
        $lead  = $this->crear_lead('Curioso');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$lead->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['phone'],
            ]);

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios);
    }
}
