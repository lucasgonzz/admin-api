<?php

namespace Tests\Feature;

use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadDemoHito;
use App\Services\DemoHitosService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El canal por el que la instancia de demo reporta lo que el lead hace adentro (misión 48,
 * pieza 4): autenticación por token, idempotencia por uuid, y la regla de que un estado de hito
 * NUNCA retrocede.
 *
 * Los hitos se crean a mano acá (no vía el formulario) para que estas pruebas midan el canal y no
 * el resolver, que ya tiene las suyas en DemoPlanYHitosTest.
 */
class DemoEventosIngestaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Lead con la dinámica nueva, su token de eventos emitido y dos hitos: el de ingreso y el del
     * clip 1.1, que espera el evento `articulo.creado`.
     *
     * @return Lead
     */
    private function crear_lead_con_hitos(): Lead
    {
        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Juana Pérez';
        $lead->demo_experiencia   = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_eventos_token = Str::random(64);
        $lead->save();

        LeadDemoHito::create([
            'lead_id' => $lead->id, 'orden' => 1, 'tipo' => LeadDemoHito::TIPO_INGRESO,
            'seccion' => null, 'clip_id' => null, 'titulo' => 'Entrar a la demo',
            'evento_esperado' => DemoHitosService::EVENTO_INGRESO,
            'estado' => LeadDemoHito::ESTADO_PENDIENTE,
        ]);

        LeadDemoHito::create([
            'lead_id' => $lead->id, 'orden' => 2, 'tipo' => LeadDemoHito::TIPO_TUTORIAL,
            'seccion' => 'S1 - Listado', 'clip_id' => '1.1', 'titulo' => 'Crear un articulo',
            'evento_esperado' => 'articulo.creado',
            'estado' => LeadDemoHito::ESTADO_PENDIENTE,
        ]);

        return $lead;
    }

    /**
     * Postea un lote de eventos con el token del lead.
     *
     * @param Lead|null                        $lead    Null = sin header de autenticación.
     * @param array<int, array<string, mixed>> $eventos
     * @param string|null                      $token_a_mano Token explícito (para el caso del token inválido).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function postear($lead, array $eventos, ?string $token_a_mano = null)
    {
        $headers = [];

        if ($token_a_mano !== null) {
            $headers['X-Demo-Eventos-Key'] = $token_a_mano;
        } elseif ($lead !== null) {
            $headers['X-Demo-Eventos-Key'] = $lead->demo_eventos_token;
        }

        return $this->postJson('/api/demo-eventos', ['eventos' => $eventos], $headers);
    }

    /**
     * Un evento suelto, listo para postear.
     *
     * @param string      $nombre
     * @param string|null $clip_id
     * @param string|null $uuid
     *
     * @return array<string, mixed>
     */
    private function evento(string $nombre, ?string $clip_id = null, ?string $uuid = null): array
    {
        return [
            'uuid'        => $uuid !== null ? $uuid : (string) Str::uuid(),
            'nombre'      => $nombre,
            'clip_id'     => $clip_id,
            'ocurrido_at' => '2026-08-13T14:31:02-03:00',
            'datos'       => [],
        ];
    }

    /**
     * El hito de un clip del lead.
     *
     * @param Lead   $lead
     * @param string $clip_id
     *
     * @return LeadDemoHito
     */
    private function hito_de_clip(Lead $lead, string $clip_id): LeadDemoHito
    {
        return LeadDemoHito::where('lead_id', $lead->id)->where('clip_id', $clip_id)->first();
    }

    /**
     * Caso 8: sin header y con un token inexistente, 401 — y sin decir cuál de las dos cosas pasó.
     */
    public function test_sin_token_o_con_token_invalido_devuelve_401(): void
    {
        $lead = $this->crear_lead_con_hitos();

        $this->postear(null, [$this->evento('demo.ingreso')])->assertStatus(401);
        $this->postear($lead, [$this->evento('demo.ingreso')], 'un-token-que-no-existe')->assertStatus(401);

        // Y no se guardó nada por ninguno de los dos caminos.
        $this->assertSame(0, DemoEventoRecibido::where('lead_id', $lead->id)->count());
    }

    /**
     * Caso 9: ver el tutorial deja el hito en `parcial`; el evento de negocio lo cierra en
     * `completo`.
     */
    public function test_tutorial_y_despues_accion_dejan_el_hito_completo(): void
    {
        $lead = $this->crear_lead_con_hitos();

        $this->postear($lead, [$this->evento('clip.terminado', '1.1')])->assertStatus(200);
        $this->assertSame(LeadDemoHito::ESTADO_PARCIAL, $this->hito_de_clip($lead, '1.1')->estado);

        $this->postear($lead, [$this->evento('articulo.creado')])->assertStatus(200);

        $hito = $this->hito_de_clip($lead, '1.1');
        $this->assertSame(LeadDemoHito::ESTADO_COMPLETO, $hito->estado);
        $this->assertNotNull($hito->tutorial_visto_at);
        $this->assertNotNull($hito->accion_hecha_at);
    }

    /**
     * Caso 10: y en el orden inverso, también. El estado se recalcula desde las dos marcas del
     * hito, no desde el evento que llegó, así que el orden de llegada no cambia el resultado.
     */
    public function test_la_accion_antes_que_el_tutorial_tambien_deja_el_hito_completo(): void
    {
        $lead = $this->crear_lead_con_hitos();

        $this->postear($lead, [$this->evento('articulo.creado')])->assertStatus(200);
        $this->assertSame(LeadDemoHito::ESTADO_PARCIAL, $this->hito_de_clip($lead, '1.1')->estado);

        $this->postear($lead, [$this->evento('clip.terminado', '1.1')])->assertStatus(200);
        $this->assertSame(LeadDemoHito::ESTADO_COMPLETO, $this->hito_de_clip($lead, '1.1')->estado);
    }

    /**
     * Caso 11: el mismo uuid dos veces deja una sola fila y no vuelve a mover nada. Es la
     * idempotencia de la que depende el reintento del emisor.
     */
    public function test_el_mismo_uuid_dos_veces_no_duplica_ni_vuelve_a_mover(): void
    {
        $lead  = $this->crear_lead_con_hitos();
        $uuid  = (string) Str::uuid();
        $mismo = $this->evento('clip.terminado', '1.1', $uuid);

        $primera = $this->postear($lead, [$mismo])->assertStatus(200);
        $this->assertSame(1, $primera->json('guardados'));
        $this->assertSame(1, $primera->json('hitos'));

        $segunda = $this->postear($lead, [$mismo])->assertStatus(200);
        $this->assertSame(0, $segunda->json('guardados'));
        $this->assertSame(1, $segunda->json('duplicados'));
        $this->assertSame(0, $segunda->json('hitos'));

        $this->assertSame(1, DemoEventoRecibido::where('uuid', $uuid)->count());
        $this->assertSame(LeadDemoHito::ESTADO_PARCIAL, $this->hito_de_clip($lead, '1.1')->estado);
    }

    /**
     * Caso 12: un evento que no le corresponde a ningún hito se guarda igual y no rompe nada. El
     * crudo alimenta el brief del closer aunque hoy no sepamos qué hacer con él.
     */
    public function test_un_evento_sin_hito_correspondiente_se_guarda_y_no_rompe(): void
    {
        $lead = $this->crear_lead_con_hitos();

        $respuesta = $this->postear($lead, [$this->evento('algo.que.nadie.espera')])->assertStatus(200);

        $this->assertSame(1, $respuesta->json('guardados'));
        $this->assertSame(0, $respuesta->json('hitos'));
        $this->assertSame(1, DemoEventoRecibido::where('nombre', 'algo.que.nadie.espera')->where('lead_id', $lead->id)->count());
    }

    /**
     * El hito de ingreso tiene dos señales distintas: pulsar el botón lo deja en `parcial`, y el
     * evento `demo.ingreso` que manda la instancia lo cierra en `completo`.
     */
    public function test_el_ingreso_pasa_por_parcial_antes_de_completo(): void
    {
        $lead = $this->crear_lead_con_hitos();

        DemoHitosService::marcar_ingreso_pulsado($lead);

        $hito = LeadDemoHito::where('lead_id', $lead->id)->where('tipo', LeadDemoHito::TIPO_INGRESO)->first();
        $this->assertSame(LeadDemoHito::ESTADO_PARCIAL, $hito->estado);

        $this->postear($lead, [$this->evento(DemoHitosService::EVENTO_INGRESO)])->assertStatus(200);

        $hito = LeadDemoHito::where('lead_id', $lead->id)->where('tipo', LeadDemoHito::TIPO_INGRESO)->first();
        $this->assertSame(LeadDemoHito::ESTADO_COMPLETO, $hito->estado);
    }

    /**
     * Un estado NUNCA retrocede: un `clip.terminado` que llega tarde (otro uuid, después de que el
     * hito ya estaba completo) no lo devuelve a `parcial`.
     */
    public function test_un_estado_completo_no_retrocede(): void
    {
        $lead = $this->crear_lead_con_hitos();

        $this->postear($lead, [$this->evento('clip.terminado', '1.1')])->assertStatus(200);
        $this->postear($lead, [$this->evento('articulo.creado')])->assertStatus(200);
        $this->assertSame(LeadDemoHito::ESTADO_COMPLETO, $this->hito_de_clip($lead, '1.1')->estado);

        // Mismo evento de tutorial, uuid distinto: llegó desordenado o el emisor lo reenvió.
        $this->postear($lead, [$this->evento('clip.terminado', '1.1')])->assertStatus(200);
        $this->assertSame(LeadDemoHito::ESTADO_COMPLETO, $this->hito_de_clip($lead, '1.1')->estado);

        // Y el de ingreso, ya completo, tampoco vuelve atrás si se pulsa el botón después.
        $this->postear($lead, [$this->evento(DemoHitosService::EVENTO_INGRESO)])->assertStatus(200);
        DemoHitosService::marcar_ingreso_pulsado($lead);

        $ingreso = LeadDemoHito::where('lead_id', $lead->id)->where('tipo', LeadDemoHito::TIPO_INGRESO)->first();
        $this->assertSame(LeadDemoHito::ESTADO_COMPLETO, $ingreso->estado);
    }

    /**
     * El lote tiene techo. Sin él, un solo POST podía disparar cientos de miles de queries
     * contra la base que corre el panel comercial entero.
     */
    public function test_un_lote_demasiado_grande_se_rechaza(): void
    {
        $lead = $this->crear_lead_con_hitos();

        $lote = [];
        for ($i = 0; $i < 201; $i++) {
            $lote[] = $this->evento('clip.terminado', '1.1');
        }

        $this->postear($lead, $lote)->assertStatus(422);
        $this->assertSame(0, DemoEventoRecibido::where('lead_id', $lead->id)->count());
    }

    /**
     * 🔴 Un `ocurrido_at` que no es una fecha se rechaza con 422 y no revienta el canal.
     *
     * Antes se validaba como string y se persistía en una columna `timestamp`: el `create()`
     * tiraba un 500 no manejado. Como el emisor reintenta ante cualquier respuesta no exitosa,
     * UN evento mal formado dejaba el canal en loop infinito, fallando siempre en el mismo lugar
     * y sin nadie mirando. El 422 es definitivo y dice qué arreglar.
     */
    public function test_una_fecha_ilegible_o_fuera_de_rango_se_rechaza_con_422(): void
    {
        $lead = $this->crear_lead_con_hitos();

        foreach (['asdf', '2999-12-31', '0000-00-00'] as $fecha) {
            $evento                = $this->evento('clip.terminado', '1.1');
            $evento['ocurrido_at'] = $fecha;

            $this->postear($lead, [$evento])->assertStatus(422);
        }

        $this->assertSame(0, DemoEventoRecibido::where('lead_id', $lead->id)->count());
        $this->assertSame(LeadDemoHito::ESTADO_PENDIENTE, $this->hito_de_clip($lead, '1.1')->estado);
    }

    /**
     * La idempotencia es POR LEAD: el uuid de un lead no puede bloquear el evento de otro.
     */
    public function test_el_mismo_uuid_en_dos_leads_distintos_no_se_pisa(): void
    {
        $lead_a = $this->crear_lead_con_hitos();
        $lead_b = $this->crear_lead_con_hitos();

        $uuid   = (string) Str::uuid();
        $evento = $this->evento('clip.terminado', '1.1', $uuid);

        $this->postear($lead_a, [$evento])->assertStatus(200);

        // El mismo uuid, otro lead: se guarda igual y le mueve SU hito.
        $respuesta = $this->postear($lead_b, [$evento])->assertStatus(200);
        $this->assertSame(1, $respuesta->json('guardados'));
        $this->assertSame(0, $respuesta->json('duplicados'));

        $this->assertSame(LeadDemoHito::ESTADO_PARCIAL, $this->hito_de_clip($lead_b, '1.1')->estado);
        $this->assertSame(2, DemoEventoRecibido::where('uuid', $uuid)->count());
    }

    /**
     * El techo de `datos` es de bytes y no de claves. La regla `max:50` de Laravel cuenta
     * ELEMENTOS del array: sin este chequeo, un `datos` de una sola clave con megabytes adentro
     * pasaba y se guardaba entero.
     */
    public function test_un_datos_gigante_se_rechaza_aunque_tenga_una_sola_clave(): void
    {
        $lead = $this->crear_lead_con_hitos();

        $evento          = $this->evento('clip.terminado', '1.1');
        $evento['datos'] = ['payload' => str_repeat('A', 20000)];

        $this->postear($lead, [$evento])->assertStatus(422);
        $this->assertSame(0, DemoEventoRecibido::where('lead_id', $lead->id)->count());

        // Y un `datos` de tamaño razonable sigue entrando.
        $chico          = $this->evento('clip.terminado', '1.1');
        $chico['datos'] = ['articulo_id' => 7, 'nombre' => 'Tornillo'];
        $this->postear($lead, [$chico])->assertStatus(200);
    }

    /**
     * Un lote con varios eventos se procesa entero en una sola llamada.
     */
    public function test_un_lote_con_varios_eventos_se_procesa_entero(): void
    {
        $lead = $this->crear_lead_con_hitos();

        $respuesta = $this->postear($lead, [
            $this->evento(DemoHitosService::EVENTO_INGRESO),
            $this->evento('clip.terminado', '1.1'),
            $this->evento('articulo.creado'),
        ])->assertStatus(200);

        $this->assertSame(3, $respuesta->json('recibidos'));
        $this->assertSame(3, $respuesta->json('guardados'));
        $this->assertSame(LeadDemoHito::ESTADO_COMPLETO, $this->hito_de_clip($lead, '1.1')->estado);
    }
}
