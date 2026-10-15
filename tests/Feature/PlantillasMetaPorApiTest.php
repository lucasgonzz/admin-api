<?php

namespace Tests\Feature;

use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\WhatsappConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Las plantillas de WhatsApp creadas y consultadas POR API (misión M5 del programa «embudo
 * comercial», 13/10/2026), y el gate que les faltaba a los envíos.
 *
 * 🔴 NINGÚN TEST DE ESTE ARCHIVO CREA UNA PLANTILLA REAL EN META. Todo pasa por `Http::fake()` del
 * proxy de Kapso, y los que validan formato o frenos verifican además con `Http::assertNothingSent()`
 * que no salió ni un pedido. Una plantilla publicada en Meta ocupa un nombre que después no se
 * reusa y consume una de las 100 creaciones por hora de la WABA de producción.
 *
 * Lo que protegen, en orden de importancia:
 *
 *  1. 🔴 QUE EL GATE DE `send-template` NO ROMPA LO QUE YA ANDABA. Es el riesgo real de esta
 *     misión: exigir `meta_status=APPROVED` sobre una tabla donde esa columna acaba de nacer
 *     dejaría a `/leads` sin poder mandar una sola plantilla. Lo cubre el backfill de la migración,
 *     y acá se verifica que una fila `activa` siga pudiendo enviarse.
 *  2. 🔴 QUE UNA CONSULTA VACÍA DE META NO APAGUE UNA PLANTILLA QUE FUNCIONA. Es la dirección
 *     peligrosa del error: apagar de más deja al pipeline muerto en silencio.
 *  3. Que `dry_run` sea el default y que las reglas de formato de Meta se validen antes de la red.
 *  4. Que una `PENDING` que pasa a `APPROVED` se active sola.
 *  5. Que el accessor `variables` deje de rotular mal `{{2}}` y empiece a listar `{{3}}`.
 */
class PlantillasMetaPorApiTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del bloque claude/*. */
    const CLAVE = 'clave-de-prueba-plantillas-meta';

    /** WABA de prueba. No es la de producción: los tests no salen a la red. */
    const WABA = '9999999999999';

    /**
     * Clave de ingesta y una configuración de WhatsApp con WABA cargado.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);

        /* Igual que PlantillaConVariableVaciaNoSaleTest: se apaga lo que haya en la base del slot
           antes de crear la propia, porque `getActive()` devuelve la primera activa y una fila
           sembrada le ganaría a ésta. */
        WhatsappConfig::query()->update(['is_active' => false]);

        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->waba_id         = self::WABA;
        $config->webhook_secret  = 'secreto-de-prueba';
        $config->is_active       = true;
        $config->test_mode       = false;
        $config->save();
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
     * El patrón de URL del proxy de plantillas, para `Http::fake()`.
     *
     * @return string
     */
    private function patron(): string
    {
        return 'api.kapso.ai/meta/whatsapp/*/message_templates*';
    }

    /**
     * Payload válido de alta, con un cuerpo que respeta las reglas de Meta.
     *
     * @param array $cambios Claves a sobreescribir.
     *
     * @return array<string, mixed>
     */
    private function payload(array $cambios = []): array
    {
        $base = [
            'name'     => 'cc_prueba_retomar_acuerdo',
            'category' => 'UTILITY',
            'body'     => 'Hola {{1}}! Te escribo como quedamos sobre {{2}}. ¿Lo vemos hoy?',
            'ejemplos' => ['Gastón', 'la demo del viernes'],
            'uso'      => 'Retomar un acuerdo concreto que el lead propuso.',
            'dry_run'  => false,
        ];

        return array_merge($base, $cambios);
    }

    /**
     * Nombre de plantilla único, para no chocar con lo que haya sembrado en la base del slot.
     *
     * @param string $sufijo
     *
     * @return string
     */
    private function nombre_unico(string $sufijo): string
    {
        return 'cc_test_' . $sufijo . '_' . random_int(100000, 999999);
    }

    /**
     * Una fila de plantilla lista para enviar (activa y aprobada).
     *
     * @param array $cambios
     *
     * @return FollowupTemplate
     */
    private function fila(array $cambios = []): FollowupTemplate
    {
        return FollowupTemplate::create(array_merge([
            'template_name' => $this->nombre_unico('fila'),
            'estado'        => 'manual_prueba',
            'dia_numero'    => 1,
            'body_template' => 'Hola {{1}}! Te escribo sobre {{2}}. Avisame.',
            'language_code' => 'es_AR',
            'activa'        => true,
            'meta_status'   => 'APPROVED',
            'meta_category' => 'UTILITY',
            'uso'           => 'Prueba.',
        ], $cambios));
    }

    /**
     * Un lead con teléfono, para los tests de envío.
     *
     * @return Lead
     */
    private function lead(): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = 'Gastón Prueba';
        $lead->company_name = 'Ferretería de prueba';
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->status       = 'contactado';
        $lead->save();

        return $lead;
    }

    /*
    |--------------------------------------------------------------------------
    | Alta: dry_run y validación de formato
    |--------------------------------------------------------------------------
    */

    /**
     * 🔴 Sin `dry_run=false` explícito no se crea nada en Meta ni se escribe ninguna fila.
     *
     * Es el freno más importante del endpoint: crear una plantilla es una escritura en el sistema
     * de un tercero que ocupa un nombre para siempre.
     *
     * @return void
     */
    public function test_el_alta_simula_por_defecto_y_no_llama_a_meta()
    {
        Http::fake([$this->patron() => Http::response(['id' => '1', 'status' => 'PENDING'], 200)]);

        $nombre  = $this->nombre_unico('dryrun');
        $payload = $this->payload(['name' => $nombre]);
        unset($payload['dry_run']);

        $respuesta = $this->withHeaders($this->headers())->postJson('/api/claude/templates/meta', $payload);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['dry_run' => true, 'creada' => false]);
        /* El payload exacto que se mandaría viaja en la simulación: es lo que se revisa antes de
           confirmar, igual que los `destinatarios` del lote de envíos. */
        $respuesta->assertJsonPath('se_mandaria_a_meta.parameter_format', 'POSITIONAL');
        $respuesta->assertJsonPath('se_registraria_como.activa', false);
        $respuesta->assertJsonPath('se_registraria_como.meta_status', 'PENDING');

        Http::assertNothingSent();
        $this->assertNull(
            FollowupTemplate::where('template_name', $nombre)->first(),
            'Una simulación no puede dejar una fila escrita.'
        );
    }

    /**
     * Con `dry_run=false` crea en Meta y registra la fila apagada y pendiente.
     *
     * @return void
     */
    public function test_crea_en_meta_y_registra_la_fila_apagada_y_pendiente()
    {
        Http::fake([
            $this->patron() => Http::response(['id' => '777000111', 'status' => 'PENDING', 'category' => 'UTILITY'], 200),
        ]);

        $nombre = $this->nombre_unico('alta');

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta', $this->payload(['name' => $nombre]));

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['creada' => true, 'dry_run' => false]);

        $fila = FollowupTemplate::where('template_name', $nombre)->first();
        $this->assertNotNull($fila, 'La plantilla creada en Meta tiene que quedar registrada.');
        $this->assertFalse((bool) $fila->activa, '🔴 Una plantilla recién creada NO puede quedar activa: la enciende el sync o el refresh.');
        $this->assertSame('PENDING', $fila->meta_status);
        $this->assertSame('777000111', $fila->meta_template_id);
        $this->assertSame('UTILITY', $fila->meta_category);
        $this->assertSame('Retomar un acuerdo concreto que el lead propuso.', $fila->uso);
        /* El estado se deriva del nombre con el prefijo manual_, así el cron de seguimientos no la
           puede levantar nunca. */
        $this->assertSame(0, strpos((string) $fila->estado, 'manual_'));

        /* Los ejemplos quedan guardados en `components`: son lo que después rotula cada hueco. */
        $this->assertSame(
            ['Gastón', 'la demo del viernes'],
            $fila->components[0]['example']['body_text'][0]
        );
    }

    /**
     * 🔴 El cron de seguimientos no puede levantar una plantilla manual, así que el `estado` se
     * fuerza a `manual_*` y uno que no lo sea es 422 antes de tocar la red.
     *
     * @return void
     */
    public function test_un_estado_que_no_es_manual_es_rechazado_sin_llamar_a_meta()
    {
        Http::fake([$this->patron() => Http::response(['id' => '1', 'status' => 'PENDING'], 200)]);

        $respuesta = $this->withHeaders($this->headers())->postJson('/api/claude/templates/meta', $this->payload([
            'name'   => $this->nombre_unico('estado'),
            'estado' => 'calificado',
        ]));

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['motivo' => 'estado_invalido']);
        Http::assertNothingSent();
    }

    /**
     * Las reglas de formato de Meta se validan ANTES de salir a la red: cada defecto es un 422 con
     * el motivo escrito y cero pedidos.
     *
     * Son las tres que `comercial/plantillas_chequeo_diario.md` marca como «rechazo automático»,
     * más el nombre y el tope de caracteres. Mandarlas a que las rechace Meta gasta una de las 100
     * creaciones por hora y devuelve un error opaco 24 horas más tarde.
     *
     * @dataProvider cuerpos_que_meta_rechaza
     *
     * @param array  $cambios Lo que se cambia del payload válido.
     * @param string $porque  Qué defecto representa, para el mensaje de la aserción.
     *
     * @return void
     */
    public function test_los_defectos_de_formato_se_frenan_antes_de_la_red(array $cambios, string $porque)
    {
        Http::fake([$this->patron() => Http::response(['id' => '1', 'status' => 'PENDING'], 200)]);

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta', $this->payload($cambios));

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['motivo' => 'formato_invalido']);
        Http::assertNothingSent();
        $this->assertTrue(true, $porque);
    }

    /**
     * Los cuerpos y nombres que Meta rechaza automáticamente.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public function cuerpos_que_meta_rechaza(): array
    {
        return [
            'nombre con mayúsculas y guión' => [
                ['name' => 'CC-Prueba-Mal'],
                'El nombre tiene que cumplir ^[a-z0-9_]+$.',
            ],
            'variable al principio' => [
                ['body' => '{{1}}, te escribo como quedamos sobre {{2}}. ¿Lo vemos?', 'ejemplos' => ['Gastón', 'la demo']],
                'Meta rechaza un cuerpo que empieza con variable.',
            ],
            'variable al final' => [
                ['body' => 'Hola {{1}}! Te escribo como quedamos sobre {{2}}', 'ejemplos' => ['Gastón', 'la demo']],
                'Meta rechaza un cuerpo que termina con variable.',
            ],
            'dos variables seguidas' => [
                ['body' => 'Hola {{1}} {{2}}, te escribo como quedamos. ¿Lo vemos?', 'ejemplos' => ['Gastón', 'Pérez']],
                'Meta rechaza dos variables seguidas.',
            ],
            'numeración con salto' => [
                ['body' => 'Hola {{1}}! Te escribo sobre {{3}} y quedo a la espera.', 'ejemplos' => ['Gastón', 'la demo']],
                'Un {{1}} y un {{3}} sin {{2}} desalinea los parámetros al enviar.',
            ],
            'faltan ejemplos' => [
                ['ejemplos' => ['Gastón']],
                'Meta exige una muestra por hueco.',
            ],
        ];
    }

    /**
     * Un cuerpo de más de 1024 caracteres es 422 y no sale a la red.
     *
     * @return void
     */
    public function test_un_cuerpo_mas_largo_que_el_tope_de_meta_es_rechazado()
    {
        Http::fake([$this->patron() => Http::response(['id' => '1', 'status' => 'PENDING'], 200)]);

        $relleno = str_repeat('á', 1100);

        $respuesta = $this->withHeaders($this->headers())->postJson('/api/claude/templates/meta', $this->payload([
            'body'     => 'Hola {{1}}! ' . $relleno . ' sobre {{2}} quedo a la espera.',
            'ejemplos' => ['Gastón', 'la demo'],
        ]));

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['motivo' => 'formato_invalido']);
        Http::assertNothingSent();
    }

    /**
     * 🔴 Un nombre ya registrado es 422 y NO se reenvía a Meta: no hay upsert.
     *
     * Crear dos veces la misma plantilla consume cupo del tope por hora y deja a Meta decidiendo
     * cuál gana. El 422 nombra el refresh, que es lo que el llamador quería.
     *
     * @return void
     */
    public function test_un_nombre_ya_registrado_no_se_vuelve_a_crear_en_meta()
    {
        Http::fake([$this->patron() => Http::response(['id' => '1', 'status' => 'PENDING'], 200)]);

        $fila = $this->fila();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta', $this->payload(['name' => $fila->template_name]));

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['motivo' => 'nombre_ya_registrado']);
        Http::assertNothingSent();
    }

    /**
     * Una categoría que no es UTILITY ni MARKETING es 422.
     *
     * `AUTHENTICATION` existe en Meta y queda afuera a propósito: no se envía con el componente
     * `body` de texto posicional, que es lo único que sabe armar `WhatsappSendService`.
     *
     * @return void
     */
    public function test_una_categoria_fuera_de_las_dos_es_rechazada()
    {
        Http::fake([$this->patron() => Http::response(['id' => '1', 'status' => 'PENDING'], 200)]);

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta', $this->payload(['category' => 'AUTHENTICATION']));

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['motivo' => 'categoria_invalida']);
        Http::assertNothingSent();
    }

    /**
     * 🔴 Un 401 o 403 de Meta vuelve con `motivo=sin_permiso_en_meta` y el plan B escrito.
     *
     * Es la señal que le dice a la skill `/leads` que deje de insistir por API y mande a cargar la
     * plantilla a mano con el procedimiento de Chrome. Sin un motivo distinguible, reintentaría
     * veinte minutos contra una puerta cerrada.
     *
     * @return void
     */
    public function test_un_403_de_meta_devuelve_el_motivo_del_plan_b()
    {
        Http::fake([
            $this->patron() => Http::response(['error' => ['message' => 'No tenés permiso sobre esta WABA.']], 403),
        ]);

        $nombre = $this->nombre_unico('sinpermiso');

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta', $this->payload(['name' => $nombre]));

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['motivo' => 'sin_permiso_en_meta', 'creada' => false]);
        $this->assertNotNull($respuesta->json('plan_b'), 'El 403 tiene que traer el plan B escrito.');
        $this->assertNull(
            FollowupTemplate::where('template_name', $nombre)->first(),
            'Si Meta no la creó, no puede quedar una fila registrada.'
        );
    }

    /**
     * Sin `waba_id` cargado no se sale a la red: se contesta con el motivo y se dice dónde cargarlo.
     *
     * Las plantillas se piden por WABA y no por `phone_number_id`: sin el WABA no hay URL a la que
     * pegarle, y salir con el número daría un 404 de Meta en vez de un mensaje legible.
     *
     * @return void
     */
    public function test_sin_waba_cargado_no_se_sale_a_la_red()
    {
        Http::fake([$this->patron() => Http::response(['id' => '1', 'status' => 'PENDING'], 200)]);

        $config = WhatsappConfig::getActive();
        $config->waba_id = null;
        $config->save();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta', $this->payload(['name' => $this->nombre_unico('sinwaba')]));

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['motivo' => 'sin_waba_id']);
        Http::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | Listado y refresh
    |--------------------------------------------------------------------------
    */

    /**
     * El listado devuelve el `uso` y el estado de Meta, y filtra por estado sin salir a la red.
     *
     * Lee la tabla local a propósito: una corrida de `/leads` que empieza eligiendo plantilla no
     * paga una llamada a Kapso ni se queda sin biblioteca si el proxy está caído.
     *
     * @return void
     */
    public function test_el_listado_trae_uso_y_estado_y_no_llama_a_meta()
    {
        Http::fake([$this->patron() => Http::response(['data' => []], 200)]);

        $aprobada = $this->fila(['uso' => 'Retomar un día acordado.']);
        $pendiente = $this->fila([
            'activa'      => false,
            'meta_status' => 'PENDING',
            'uso'         => 'Nutrición con un reel.',
        ]);

        $respuesta = $this->withHeaders($this->headers())->getJson('/api/claude/templates/meta?status=PENDING');

        $respuesta->assertStatus(200);
        Http::assertNothingSent();

        $nombres = array_column($respuesta->json('data'), 'template_name');
        $this->assertContains($pendiente->template_name, $nombres, 'El filtro por PENDING tiene que traer la pendiente.');
        $this->assertNotContains($aprobada->template_name, $nombres, 'El filtro por PENDING no puede traer una APPROVED.');

        $fila_devuelta = null;
        foreach ($respuesta->json('data') as $fila) {
            if ($fila['template_name'] === $pendiente->template_name) {
                $fila_devuelta = $fila;
            }
        }
        $this->assertSame('Nutrición con un reel.', $fila_devuelta['uso'], 'El `uso` es lo que le deja elegir plantilla a /leads.');
        $this->assertFalse($fila_devuelta['enviable'], 'Una PENDING no es enviable.');
    }

    /**
     * 🔴 Una `PENDING` que pasa a `APPROVED` se activa sola.
     *
     * Es la automatización que pide el punto 3 de M5: Meta no manda webhooks de estado de
     * plantilla, así que sin esto una plantilla aprobada se quedaría apagada hasta que alguien se
     * acuerde de prenderla.
     *
     * @return void
     */
    public function test_el_refresh_activa_la_plantilla_que_meta_aprobo()
    {
        $fila = $this->fila(['activa' => false, 'meta_status' => 'PENDING']);

        Http::fake([
            $this->patron() => Http::response(['data' => [[
                'name'     => $fila->template_name,
                'status'   => 'APPROVED',
                'category' => 'UTILITY',
                'id'       => '555000',
                'language' => 'es_AR',
            ]]], 200),
        ]);

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta/' . $fila->template_name . '/refresh');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('cambio.activada', true);

        $fila->refresh();
        $this->assertTrue((bool) $fila->activa);
        $this->assertSame('APPROVED', $fila->meta_status);
        $this->assertSame('555000', $fila->meta_template_id);
    }

    /**
     * Una `REJECTED` se desactiva y queda el motivo de Meta escrito.
     *
     * Si quedara activa, el gate de `send-template` la dejaría pasar y el envío fallaría en Kapso
     * dejando un bloque rojo en el hilo del lead y un aviso a los admins.
     *
     * @return void
     */
    public function test_el_refresh_desactiva_la_que_meta_rechazo_y_guarda_el_motivo()
    {
        $fila = $this->fila(['activa' => true, 'meta_status' => 'PENDING']);

        Http::fake([
            $this->patron() => Http::response(['data' => [[
                'name'            => $fila->template_name,
                'status'          => 'REJECTED',
                'rejected_reason' => 'INVALID_FORMAT',
            ]]], 200),
        ]);

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta/' . $fila->template_name . '/refresh');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('cambio.desactivada', true);

        $fila->refresh();
        $this->assertFalse((bool) $fila->activa);
        $this->assertSame('REJECTED', $fila->meta_status);
        $this->assertSame('INVALID_FORMAT', $fila->meta_rechazo);
    }

    /**
     * 🔴 SI META NO DEVUELVE LA PLANTILLA, NO SE TOCA SU ESTADO NI SE DESACTIVA.
     *
     * Es la guarda más importante del refresh y del comando de sync. Que una consulta no la haya
     * traído no prueba que esté rechazada: puede ser el filtro `name` del proxy, una página que se
     * cortó, o un error de Meta que contestó 200. Y la dirección peligrosa del error es clarísima
     * —apagar una plantilla que funciona deja a `/leads` sin poder escribirle a nadie fuera de la
     * ventana de 24 hs, en silencio y hasta que alguien lo note.
     *
     * @return void
     */
    public function test_una_consulta_vacia_de_meta_no_apaga_una_plantilla_que_funciona()
    {
        $fila = $this->fila(['activa' => true, 'meta_status' => 'APPROVED']);

        Http::fake([$this->patron() => Http::response(['data' => []], 200)]);

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta/' . $fila->template_name . '/refresh');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('cambio.existe_en_meta', false);

        $fila->refresh();
        $this->assertTrue((bool) $fila->activa, '🔴 Una consulta vacía no puede apagar una plantilla activa.');
        $this->assertSame('APPROVED', $fila->meta_status, '🔴 Una consulta vacía no puede cambiar el estado.');
        $this->assertNotNull($fila->meta_synced_at, 'Igual tiene que quedar sellado cuándo se consultó.');
    }

    /**
     * El refresh de un nombre que no está registrado es 404 y no crea nada.
     *
     * @return void
     */
    public function test_el_refresh_de_un_nombre_no_registrado_es_404()
    {
        Http::fake([$this->patron() => Http::response(['data' => []], 200)]);

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta/cc_no_existe_en_ningun_lado/refresh');

        $respuesta->assertStatus(404);
        $respuesta->assertJson(['motivo' => 'no_registrada']);
        Http::assertNothingSent();
    }

    /**
     * El filtro `name` de Meta puede devolver vecinos: se elige la coincidencia EXACTA.
     *
     * Sin esto, consultar `cc_informe_diario` podría tomar el estado de `cc_informe_diario_v2` y
     * activar la plantilla equivocada.
     *
     * @return void
     */
    public function test_el_refresh_elige_la_coincidencia_exacta_y_no_un_vecino()
    {
        $fila = $this->fila(['activa' => false, 'meta_status' => 'PENDING']);

        Http::fake([
            $this->patron() => Http::response(['data' => [
                ['name' => $fila->template_name . '_v2', 'status' => 'APPROVED'],
                ['name' => $fila->template_name, 'status' => 'REJECTED', 'rejected_reason' => 'ABUSIVE_CONTENT'],
            ]], 200),
        ]);

        $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta/' . $fila->template_name . '/refresh')
            ->assertStatus(200);

        $fila->refresh();
        $this->assertSame('REJECTED', $fila->meta_status, 'Se tiene que tomar el estado de la plantilla exacta, no del vecino.');
        $this->assertFalse((bool) $fila->activa);
    }

    /*
    |--------------------------------------------------------------------------
    | El comando del scheduler
    |--------------------------------------------------------------------------
    */

    /**
     * `templates:sync-meta` baja el estado de todas, activa la aprobada y no toca la que Meta no
     * devolvió.
     *
     * @return void
     */
    public function test_el_comando_de_sync_activa_la_aprobada_y_respeta_la_que_meta_no_trajo()
    {
        $aprobandose = $this->fila(['activa' => false, 'meta_status' => 'PENDING']);
        $ajena       = $this->fila(['activa' => true, 'meta_status' => 'APPROVED']);

        Http::fake([
            $this->patron() => Http::response(['data' => [[
                'name'     => $aprobandose->template_name,
                'status'   => 'APPROVED',
                'category' => 'UTILITY',
                'id'       => '900900',
            ]]], 200),
        ]);

        $this->artisan('templates:sync-meta')->assertExitCode(0);

        $aprobandose->refresh();
        $this->assertTrue((bool) $aprobandose->activa, 'La que pasó a APPROVED se tiene que activar sola.');
        $this->assertSame('APPROVED', $aprobandose->meta_status);

        $ajena->refresh();
        $this->assertTrue((bool) $ajena->activa, '🔴 La que Meta no devolvió no se puede apagar.');
        $this->assertSame('APPROVED', $ajena->meta_status);
    }

    /**
     * Sin permiso en Meta, el comando corta con exit 1 y no toca ninguna fila.
     *
     * Exit 1 y no 0 porque `/deploy-admin` lo corre después del deploy para validar la integración:
     * un «sin permiso» que devuelve 0 se lee como un éxito con cero plantillas.
     *
     * @return void
     */
    public function test_el_comando_de_sync_falla_fuerte_si_no_tiene_permiso()
    {
        $fila = $this->fila(['activa' => true, 'meta_status' => 'APPROVED']);

        Http::fake([$this->patron() => Http::response(['error' => ['message' => 'sin permiso']], 401)]);

        $this->artisan('templates:sync-meta')->assertExitCode(1);

        $fila->refresh();
        $this->assertTrue((bool) $fila->activa, 'Un fallo de red no puede apagar plantillas.');
        $this->assertNull($fila->meta_synced_at, 'Si no se pudo listar, no se sincronizó nada.');
    }

    /*
    |--------------------------------------------------------------------------
    | El gate de los envíos
    |--------------------------------------------------------------------------
    */

    /**
     * 🔴 Una plantilla que ANDA sigue andando: es el riesgo de regresión de esta misión.
     *
     * El gate exige `meta_status=APPROVED` sobre una columna que acaba de nacer. Lo que evita la
     * caída es el backfill de la migración (toda fila `activa` queda en APPROVED). Este test mide
     * el resultado: una fila activa y aprobada pasa el gate y el envío llega al sender.
     *
     * @return void
     */
    public function test_una_plantilla_activa_y_aprobada_pasa_el_gate()
    {
        Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.PRUEBA']]], 200)]);

        $fila = $this->fila();
        $lead = $this->lead();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
                'template_name' => $fila->template_name,
                'content'       => 'Hola Gastón! Te escribo sobre la demo. Avisame.',
                'variables'     => ['Gastón', 'la demo'],
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['enviado' => true]);
        /* El idioma con el que salió es el GUARDADO, no el del request. */
        $respuesta->assertJsonPath('language_code', 'es_AR');
    }

    /**
     * 🔴 El idioma sale de la FILA aunque el llamador mande otro.
     *
     * Meta aprueba una plantilla para un idioma: mandarla con otro es un envío que descarta por
     * plantilla inexistente, y el admin lo contestaba con 200 y `enviado=false`.
     *
     * @return void
     */
    public function test_el_envio_usa_el_idioma_guardado_y_no_el_del_request()
    {
        Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.PRUEBA']]], 200)]);

        $fila = $this->fila(['language_code' => 'es_MX']);
        $lead = $this->lead();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
                'template_name' => $fila->template_name,
                'content'       => 'Hola Gastón! Te escribo sobre la demo. Avisame.',
                'variables'     => ['Gastón', 'la demo'],
                'language_code' => 'pt_BR',
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('language_code', 'es_MX');

        Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            return isset($cuerpo['template']['language']['code'])
                && $cuerpo['template']['language']['code'] === 'es_MX';
        });
    }

    /**
     * Una plantilla que no está registrada es 422 y no se envía nada.
     *
     * Antes de esta misión pasaba derecho hasta Meta: si no la conocía, el envío fallaba en Kapso y
     * el endpoint contestaba 200 con `enviado=false`, que desde afuera se lee como un éxito parcial.
     *
     * @return void
     */
    public function test_una_plantilla_sin_registrar_no_se_envia()
    {
        Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.PRUEBA']]], 200)]);

        $lead = $this->lead();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
                'template_name' => 'cc_no_existe_en_ningun_lado',
                'content'       => 'Hola Gastón!',
            ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['motivo' => 'plantilla_no_registrada']);
        Http::assertNothingSent();
        $this->assertSame(0, \App\Models\LeadMessage::where('lead_id', $lead->id)->count(), 'No puede quedar ni un mensaje registrado.');
    }

    /**
     * Una plantilla desactivada es 422 y el mensaje nombra el refresh.
     *
     * @return void
     */
    public function test_una_plantilla_desactivada_no_se_envia()
    {
        Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.PRUEBA']]], 200)]);

        $fila = $this->fila(['activa' => false]);
        $lead = $this->lead();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
                'template_name' => $fila->template_name,
                'content'       => 'Hola Gastón!',
            ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['motivo' => 'plantilla_inactiva']);
        Http::assertNothingSent();
    }

    /**
     * Una plantilla activa pero no aprobada es 422, y el 422 trae el motivo de Meta.
     *
     * @return void
     */
    public function test_una_plantilla_no_aprobada_no_se_envia()
    {
        Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.PRUEBA']]], 200)]);

        $fila = $this->fila(['activa' => true, 'meta_status' => 'REJECTED', 'meta_rechazo' => 'INVALID_FORMAT']);
        $lead = $this->lead();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
                'template_name' => $fila->template_name,
                'content'       => 'Hola Gastón!',
            ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['motivo' => 'plantilla_no_aprobada', 'meta_rechazo' => 'INVALID_FORMAT']);
        Http::assertNothingSent();
    }

    /**
     * El LOTE valida la plantilla una vez, antes de resolver destinatarios y antes de cualquier envío.
     *
     * Acá pesa más que en el endpoint individual: sin el gate, una plantilla rechazada producía
     * cincuenta `LeadMessage` rojos en cincuenta conversaciones y cincuenta avisos a los admins.
     *
     * @return void
     */
    public function test_el_lote_frena_antes_de_resolver_destinatarios()
    {
        Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.PRUEBA']]], 200)]);

        $lead = $this->lead();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'         => [$lead->id],
                'template_name'    => 'cc_no_existe_en_ningun_lado',
                'content_template' => 'Hola {{1}}!',
                'dry_run'          => true,
            ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['motivo' => 'plantilla_no_registrada']);
        Http::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | El accessor `variables` (punto 5 de M5)
    |--------------------------------------------------------------------------
    */

    /**
     * 🔴 `{{2}}` deja de llamarse «Hora de la demo» en las plantillas donde no es una hora.
     *
     * La versión anterior del accessor mapeaba por posición contra una lista fija de dos campos: de
     * las diez plantillas del chequeo diario, en NUEVE la etiqueta era falsa. Quien mandaba una
     * desde el selector del panel leía «Hora de la demo» y escribía una hora.
     *
     * @return void
     */
    public function test_el_accessor_rotula_el_segundo_hueco_segun_la_plantilla()
    {
        $fila = $this->fila([
            'template_name' => 'cc_coord_espera_lead',
            'estado'        => 'manual_coordinacion',
            'body_template' => 'Hola {{1}}! ¿Cómo venís con {{2}}? Avisame cuando te acomodes.',
        ]);

        $variables = $fila->variables;

        $this->assertCount(2, $variables);
        $this->assertSame('contact_name', $variables[0]['field']);
        $this->assertStringContainsString('impedimento', $variables[1]['label']);
        $this->assertNotSame('demo_start_time', $variables[1]['field'], '🔴 En esta plantilla {{2}} NO es la hora de la demo.');
    }

    /**
     * 🔴 `{{3}}` se lista. Antes no existía para el accessor, en las tres plantillas que lo usan.
     *
     * Un hueco invisible significa un mensaje que sale con un `{{3}}` sin valor, y Meta lo descarta
     * con `(#131008) Required parameter is missing`.
     *
     * @return void
     */
    public function test_el_accessor_lista_el_tercer_hueco()
    {
        $fila = $this->fila([
            'template_name' => 'cc_respuesta_pendiente',
            'estado'        => 'manual_coordinacion',
            'body_template' => 'Hola {{1}}! Te debía la respuesta sobre {{2}} y no quiero que quede colgada: {{3}} '
                . 'Si querés lo charlamos cuando me digas.',
        ]);

        $variables = $fila->variables;

        $this->assertCount(3, $variables, '🔴 Las tres plantillas con {{3}} tienen que listar tres variables.');
        $this->assertSame('{{3}}', $variables[2]['placeholder']);
        $this->assertStringContainsString('respuesta', $variables[2]['label']);
    }

    /**
     * En los recordatorios de demo `{{2}}` SÍ es la hora, y eso se conserva.
     *
     * Era el único caso donde la etiqueta vieja era correcta: el valor lo completa el sistema desde
     * el turno del lead, no una persona.
     *
     * @return void
     */
    public function test_en_un_recordatorio_el_segundo_hueco_sigue_siendo_la_hora()
    {
        $fila = $this->fila([
            'template_name' => 'cc_recordatorio_dia_demo',
            'estado'        => 'recordatorio',
            'body_template' => 'Hola {{1}}! Te recuerdo que hoy a las {{2}} tenés la demo. Cualquier consulta avisame.',
        ]);

        $variables = $fila->variables;

        $this->assertSame('demo_start_time', $variables[1]['field']);
    }

    /**
     * 🔴 EN UNA PLANTILLA QUE EL CRON PUEDE ALCANZAR, `{{2}}` SIGUE TRAYENDO LA HORA DEL LEAD.
     *
     * Este test protege contra el error que la primera versión de la corrección introdujo y que
     * agarró `SeguimientoConVariableVaciaTest`: `LeadFollowupService::build_template_variable_pairs()`
     * usa el `field` de este accessor para RELLENAR el valor que sale por WhatsApp, no solo para
     * poner una etiqueta en el panel. Dejar `field => null` en una plantilla de un estado real del
     * pipeline hacía que el parámetro viajara vacío, y el guard de `WhatsappSendService` cortaba el
     * envío: un seguimiento automático que hoy sale dejaba de salir, en silencio.
     *
     * El corte entre «rotular» y «rellenar» es `manual_*`, que es el mismo que usa todo el resto
     * del sistema para decidir si el cron puede levantar una plantilla.
     *
     * @return void
     */
    public function test_en_una_plantilla_del_pipeline_el_segundo_hueco_sigue_saliendo_del_lead()
    {
        $fila = $this->fila([
            'template_name' => $this->nombre_unico('pipeline'),
            'estado'        => 'nuevo',
            'body_template' => 'Hola {{1}}, te espero a las {{2}}. Cualquier cosa avisame.',
        ]);

        $this->assertSame(
            'demo_start_time',
            $fila->variables[1]['field'],
            '🔴 Si esto queda en null, el seguimiento automático sale con el parámetro vacío y el guard lo corta.'
        );
    }

    /**
     * Para una plantilla nueva sin mapa, el hueco se rotula con el EJEMPLO que se le mandó a Meta.
     *
     * Y si no hay ejemplo, dice que no sabe en vez de inventar un campo: una etiqueta honesta hace
     * que quien la manda lea el texto; una inventada hace que confíe.
     *
     * @return void
     */
    public function test_el_accessor_usa_el_ejemplo_guardado_y_si_no_lo_tiene_dice_que_no_sabe()
    {
        $con_ejemplo = $this->fila([
            'template_name' => $this->nombre_unico('conejemplo'),
            'body_template' => 'Hola {{1}}! Quedamos en hablar {{2}} para ver la implementación. Decime la hora.',
            'components'    => [[
                'type'    => 'BODY',
                'text'    => 'Hola {{1}}! Quedamos en hablar {{2}} para ver la implementación. Decime la hora.',
                'example' => ['body_text' => [['Gastón', 'el jueves']]],
            ]],
        ]);

        $this->assertStringContainsString('el jueves', $con_ejemplo->variables[1]['label']);

        $sin_nada = $this->fila([
            'template_name' => $this->nombre_unico('sinnada'),
            'body_template' => 'Hola {{1}}! Te escribo por {{2}} y quedo a la espera.',
            'components'    => null,
        ]);

        $this->assertStringContainsString('ver el texto de la plantilla', $sin_nada->variables[1]['label']);
        $this->assertNull($sin_nada->variables[1]['field'], 'Sin saber qué es, no se puede inventar un campo del lead.');
    }

    /**
     * Un `{{2}}` que aparece dos veces en el cuerpo es UNA variable.
     *
     * Meta manda un solo parámetro y lo renderiza en los dos lugares: listarlo dos veces haría
     * contar mal los parámetros al enviar.
     *
     * @return void
     */
    public function test_un_hueco_repetido_cuenta_una_sola_vez()
    {
        $fila = $this->fila([
            'template_name' => $this->nombre_unico('repetido'),
            'body_template' => 'Hola {{1}}! Sobre {{2}}: te decía que {{2}} es justo lo que resolvemos. Avisame.',
        ]);

        $this->assertCount(2, $fila->variables);
    }

    /**
     * 🔴 Un `dry_run` nulo o vacío explícito sigue siendo simulación.
     *
     * `boolean('dry_run', true)` lo leía como false y creaba la plantilla en Meta de verdad.
     *
     * @return void
     */
    public function test_un_dry_run_nulo_o_vacio_no_crea_nada()
    {
        Http::fake([$this->patron() => Http::response(['id' => '1', 'status' => 'PENDING'], 200)]);

        foreach ([null, ''] as $valor) {
            $nombre = $this->nombre_unico('dryrunnulo');

            $respuesta = $this->withHeaders($this->headers())
                ->postJson('/api/claude/templates/meta', $this->payload(['name' => $nombre, 'dry_run' => $valor]));

            $respuesta->assertStatus(200);
            $respuesta->assertJson(['dry_run' => true, 'creada' => false]);
            $this->assertNull(FollowupTemplate::where('template_name', $nombre)->first());
        }

        Http::assertNothingSent();
    }

    /**
     * 🔴 El sync NO enciende una plantilla apagada que no estaba esperando la revisión de Meta.
     *
     * La migración deja `meta_status` en NULL a las filas con `activa = 0`, y entre ellas hay
     * plantillas del pipeline apagadas a propósito. Si el primer sync las encendiera por estar
     * aprobadas en Meta, el cron de seguimientos las empezaría a mandar solas.
     *
     * @return void
     */
    public function test_el_sync_no_enciende_una_apagada_a_proposito()
    {
        $apagada = $this->fila(['activa' => false, 'meta_status' => null, 'estado' => 'demo_realizada']);

        Http::fake([
            $this->patron() => Http::response(['data' => [[
                'name'     => $apagada->template_name,
                'status'   => 'APPROVED',
                'category' => 'UTILITY',
                'id'       => '700700',
            ]]], 200),
        ]);

        $this->artisan('templates:sync-meta')->assertExitCode(0);

        $apagada->refresh();
        $this->assertFalse((bool) $apagada->activa, '🔴 Encenderla es de una persona, no del sync.');
        $this->assertSame('APPROVED', $apagada->meta_status, 'El estado de Meta sí se baja.');
    }

    /**
     * Si Meta deja de devolver una plantilla rechazada, se conserva el motivo real del rechazo.
     *
     * @return void
     */
    public function test_una_consulta_vacia_no_pisa_el_motivo_de_rechazo()
    {
        $rechazada = $this->fila([
            'activa'       => false,
            'meta_status'  => 'REJECTED',
            'meta_rechazo' => 'INVALID_FORMAT',
        ]);

        Http::fake([$this->patron() => Http::response(['data' => []], 200)]);

        $this->withHeaders($this->headers())
            ->postJson('/api/claude/templates/meta/' . $rechazada->template_name . '/refresh');

        $rechazada->refresh();
        $this->assertSame('INVALID_FORMAT', $rechazada->meta_rechazo);
    }
}
