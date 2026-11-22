<?php

namespace Tests\Feature\PreciosVigentes;

use App\Models\AdminSetting;
use App\Models\AiSystemPrompt;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\KnowledgeGroundingGate;
use App\Services\LeadAiService;
use App\Services\LeadOfertaDePruebaService;
use App\Services\LeadWhatsappOnboardingService;
use App\Services\WhatsappProtocolService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Martín toma los precios SIEMPRE de la fuente del admin (misión embudo-whatsapp-precios, etapa E1,
 * criterios CA4, CA5 y CA6 del PLAN).
 *
 * Lo que se custodia:
 *  - CA4: el contexto del turno (`build_user_content`) de cualquier lead trae el bloque «PRECIOS
 *    VIGENTES» generado desde la fuente, antes del bloque de la oferta y con el texto terminando en la
 *    pregunta de siempre; un cambio en la fuente cambia el bloque.
 *  - CA5: ese bloque cuenta como respaldo para el gate de `fuentes_kb`: citar `precios_vigentes` sin
 *    llamar ninguna tool deja salir la afirmación; citar `precios` sin haberlo leído se sigue frenando;
 *    y el segundo intento sirve el bloque cuando el agente lo cita.
 *  - CA6: el «desde» de la oferta de la prueba sale de la fuente y no de `ContractPreciosService`.
 *
 * La IA va siempre simulada con `Http::fake` sobre api.anthropic.com, igual que
 * `SegundoIntentoConRespaldoServidoTest`: no se llama al modelo real.
 */
class BloqueDePreciosParaMartinTest extends TestCase
{
    use DatabaseTransactions;

    /** Lo último que dice el contexto del turno (no tiene que moverse: lo fija un test existente). */
    private const PREGUNTA_FINAL = '¿Qué respuesta sugerís y en qué estado debería quedar el lead?';

    /**
     * Deja la base sin la fuente guardada: la de testing puede traer un valor.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::query()->where('key', PreciosVigentesSettingsTest::CLAVE)->delete();
    }

    /* ==================================================================================
     * CA4 — el contexto del turno
     * ================================================================================== */

    /**
     * CA4. El contexto de CUALQUIER lead trae el bloque con los precios vigentes (sin oferta, con
     * oferta, en distintos estados); con la oferta, el bloque va ANTES de ella y el texto sigue
     * terminando en la pregunta de siempre.
     *
     * @return void
     */
    public function test_el_contexto_trae_los_precios_vigentes_antes_de_la_oferta(): void
    {
        /* Un lead cualquiera, sin oferta ni prueba. */
        $sin_oferta = $this->crear_lead('nuevo');
        $contexto   = $this->contexto_del_turno($sin_oferta);

        $this->assertStringContainsString('PRECIOS VIGENTES', $contexto);
        $this->assertStringContainsString('Inicial: hasta 200 operaciones por mes, 1 usuario: $55.900 ($27.950 los primeros 3 meses)', $contexto);
        $this->assertStringContainsString('Los tres juntos: 30 % de descuento, $1.319.640 en lugar de $1.885.200 ($659.820)', $contexto);
        $this->assertStringContainsString('Plantilla de marketing: USD 0,0618 (unos $89,56)', $contexto);
        $this->assertStringContainsString('Plus y A medida ya no se ofrecen', $contexto);
        $this->assertStringEndsWith(self::PREGUNTA_FINAL, $contexto);

        /* En cualquier estado: con prueba, sin prueba, avanzado o cerrado. */
        foreach (['contactado', 'calificado', 'prueba_activa', 'cerrado_perdido'] as $estado) {
            Lead::query()->whereKey($sin_oferta->id)->update(['status' => $estado]);

            $this->assertStringContainsString(
                'PRECIOS VIGENTES',
                $this->contexto_del_turno($sin_oferta->fresh()),
                "El lead en `{$estado}` no recibió el bloque de precios."
            );
        }

        /* Un lead al que ya se le ofreció la prueba: el bloque de la oferta existe y va DESPUÉS. */
        $con_oferta = $this->crear_lead('nuevo');
        LeadMessage::create([
            'lead_id' => $con_oferta->id, 'sender' => 'sistema', 'kind' => 'text',
            'content' => 'Tu prueba la creás desde acá', 'is_status_event' => false, 'status' => 'enviado',
            'sent_at' => now(), 'system_message_kind' => LeadWhatsappOnboardingService::KIND_PRUEBA_LINK,
            'whatsapp_message_id' => 'wamid.precios.' . Str::random(12),
        ]);

        $this->assertNotSame(
            '',
            LeadOfertaDePruebaService::bloque_para_el_agente($con_oferta->fresh()),
            'La precondición falló: el lead no quedó con la oferta hecha.'
        );

        $contexto = $this->contexto_del_turno($con_oferta->fresh());
        $precios  = strpos($contexto, 'PRECIOS VIGENTES');
        $oferta   = strpos($contexto, 'PRUEBA GRATIS (lead de la web');

        $this->assertNotFalse($precios, 'El lead con oferta no recibió el bloque de precios.');
        $this->assertNotFalse($oferta, 'El bloque de la oferta desapareció del contexto.');
        $this->assertLessThan($oferta, $precios, 'El bloque de precios tiene que ir ANTES del de la oferta.');
        $this->assertStringEndsWith(self::PREGUNTA_FINAL, $contexto);
    }

    /**
     * CA4. Si se cambia el abono de Intermedio en la fuente, el bloque dice el valor nuevo y su
     * descuento de bienvenida, y deja de decir el viejo.
     *
     * @return void
     */
    public function test_un_cambio_en_la_fuente_cambia_el_bloque(): void
    {
        $this->guardar_fuente(function (array $contrato) {
            $contrato['gestion']['planes'][1]['abono'] = 86000;

            return $contrato;
        });

        $contexto = $this->contexto_del_turno($this->crear_lead('nuevo'));

        $this->assertStringContainsString('$86.000 ($43.000 los primeros 3 meses)', $contexto);
        $this->assertStringNotContainsString('$85.400', $contexto, 'El bloque sigue diciendo el precio viejo.');
    }

    /* ==================================================================================
     * CA5 — el gate de respaldo
     * ================================================================================== */

    /**
     * CA5. Un paquete `afirmacion_del_sistema` que cita `precios_vigentes` y NO llamó ninguna tool sale:
     * no queda el mensaje de espera ni la intervención humana, y alcanza con una sola llamada.
     *
     * @return void
     */
    public function test_citar_precios_vigentes_respalda_una_afirmacion_sin_tools(): void
    {
        $this->sembrar_protocolo_de_leads();

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('El plan Inicial sale $55.900 por mes.', ['precios_vigentes'])),
        ]);

        $lead    = $this->crear_lead('calificado');
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertStringContainsString('$55.900', (string) $mensaje->content, 'El precio no llegó al mensaje.');
        $this->assertStringNotContainsString(
            LeadAiService::MENSAJE_DE_ESPERA_SIN_RESPALDO,
            (string) $mensaje->content,
            'El gate frenó una afirmación respaldada por el bloque de precios vigentes.'
        );
        $this->assertFalse((bool) $mensaje->requiere_verificacion, 'Quedó marcada para verificar.');
        $this->assertFalse((bool) $lead->refresh()->requiere_intervencion_humana, 'El lead quedó derivado a una persona.');
        $this->assertSame(1, $this->llamadas_a_claude(), 'Hubo un reintento innecesario.');
    }

    /**
     * CA5. Citar `precios` (el recurso) sin haberlo leído se sigue frenando, como hoy: el bloque de
     * precios vigentes no destraba cualquier cita, sólo la suya.
     *
     * @return void
     */
    public function test_citar_precios_sin_leerlo_se_sigue_frenando(): void
    {
        $this->sembrar_protocolo_de_leads();

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('El plan Inicial sale $55.900 por mes.', ['precios'])),
        ]);

        $mensaje = app(LeadAiService::class)->generate_suggestion($this->crear_lead('calificado'), false);

        $this->assertSame(
            LeadAiService::MENSAJE_DE_ESPERA_SIN_RESPALDO,
            (string) $mensaje->content,
            'La cita de `precios` sin leerlo tiene que frenar y dejar el mensaje de espera.'
        );
        $this->assertTrue((bool) $mensaje->requiere_verificacion);
        $this->assertSame(1, $this->llamadas_a_claude(), 'Un recurso que no existe no da derecho a reintento.');
    }

    /**
     * CA5. El segundo intento también sirve `precios_vigentes`: si el gate frena porque citó, además,
     * un recurso que no leyó, el reintento le entrega el bloque de precios junto con el recurso y el
     * mensaje sale. Y `servir_fuente_citada('precios_vigentes')` devuelve el bloque.
     *
     * @return void
     */
    public function test_el_segundo_intento_sirve_precios_vigentes(): void
    {
        $this->sembrar_protocolo_de_leads();
        $this->sembrar_recursos(['posicionamiento']);

        $this->fakear_secuencia([
            $this->respuesta_final($this->paquete('El plan Inicial sale $55.900 por mes.', ['posicionamiento', 'precios_vigentes'])),
            $this->respuesta_final($this->paquete('El plan Inicial sale $55.900 por mes.', ['posicionamiento', 'precios_vigentes'])),
        ]);

        $lead    = $this->crear_lead('calificado');
        $mensaje = app(LeadAiService::class)->generate_suggestion($lead, false);

        $this->assertSame(2, $this->llamadas_a_claude(), 'No hubo exactamente un reintento.');

        /* El segundo pedido lleva, en la parte servida por el sistema, el bloque de precios. */
        $cuerpos = $this->cuerpos_enviados_a_claude();
        $this->assertCount(2, $cuerpos);

        $servido = strstr($cuerpos[1], 'CONTENIDO COMPLETO DE LO QUE CITASTE');
        $this->assertNotFalse($servido, 'El segundo pedido no trae la parte servida por el sistema.');
        $this->assertStringContainsString('===== precios_vigentes =====', $servido);
        $this->assertStringContainsString('Inicial: hasta 200 operaciones por mes, 1 usuario: $55.900', $servido);

        /* Y el mensaje sale: el reintento quedó respaldado. */
        $this->assertStringContainsString('$55.900', (string) $mensaje->content);
        $this->assertStringNotContainsString(LeadAiService::MENSAJE_DE_ESPERA_SIN_RESPALDO, (string) $mensaje->content);
        $this->assertFalse((bool) $lead->refresh()->requiere_intervencion_humana);

        /* La fuente resuelve por su nombre (método privado: se llama por reflexión). */
        $metodo = new ReflectionMethod(LeadAiService::class, 'servir_fuente_citada');
        $metodo->setAccessible(true);
        $bloque = (string) $metodo->invoke(app(LeadAiService::class), 'precios_vigentes', $lead);

        $this->assertStringContainsString('PRECIOS VIGENTES', $bloque);
        $this->assertStringContainsString('Los tres juntos: 30 % de descuento, $1.319.640 en lugar de $1.885.200 ($659.820)', $bloque);
    }

    /* ==================================================================================
     * CA6 — el «desde» de la oferta
     * ================================================================================== */

    /**
     * CA6. `LeadOfertaDePruebaService::precio_desde()` sale de la fuente: «$27.950» con los valores del
     * pedido; con el abono de Inicial en 60000, «$30.000»; con el descuento en 0 %, «$60.000» y
     * `promo()` vacío.
     *
     * @return void
     */
    public function test_el_desde_de_la_oferta_sale_de_la_fuente(): void
    {
        $this->assertSame('$27.950', LeadOfertaDePruebaService::precio_desde());

        $this->guardar_fuente(function (array $contrato) {
            $contrato['gestion']['planes'][0]['abono'] = 60000;

            return $contrato;
        });

        $this->assertSame(
            '$30.000',
            LeadOfertaDePruebaService::precio_desde(),
            'El «desde» no leyó el abono de Inicial de la fuente.'
        );
        $this->assertStringContainsString('50 % off', LeadOfertaDePruebaService::promo());

        $this->guardar_fuente(function (array $contrato) {
            $contrato['gestion']['planes'][0]['abono']                  = 60000;
            $contrato['gestion']['descuento_bienvenida']['porcentaje'] = 0;

            return $contrato;
        });

        $this->assertSame('$60.000', LeadOfertaDePruebaService::precio_desde());
        $this->assertSame('', LeadOfertaDePruebaService::promo(), 'Sin descuento no tiene que haber paréntesis de promo.');
    }

    /* ==================================================================================
     * Helpers
     * ================================================================================== */

    /**
     * Guarda en `admin_settings` el contrato de §2.6 con la modificación pedida, que es como queda la
     * fuente después de que alguien la edita.
     *
     * @param callable $modificar Recibe el contrato por defecto y devuelve el que se guarda.
     *
     * @return void
     */
    private function guardar_fuente(callable $modificar): void
    {
        $contrato = $modificar(PreciosVigentesSettingsTest::contrato_por_defecto());

        AdminSetting::set(PreciosVigentesSettingsTest::CLAVE, json_encode($contrato, JSON_UNESCAPED_UNICODE));
    }

    /**
     * El contexto que recibe Martín en un turno (`build_user_content` es protegido: se lo llama por una
     * subclase anónima, como hace OfertaDePruebaAlLeadDeLaLandingTest).
     *
     * @param Lead $lead Lead en curso.
     *
     * @return string
     */
    private function contexto_del_turno(Lead $lead): string
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
     * Paquete mínimo que devuelve el agente, declarado como afirmación del sistema.
     *
     * @param string             $mensaje Texto sugerido para el lead.
     * @param array<int, string> $fuentes Lo que el agente dice haber usado como respaldo.
     *
     * @return array<string, mixed>
     */
    private function paquete(string $mensaje, array $fuentes): array
    {
        return [
            'mensaje_sugerido' => $mensaje,
            'estado_sugerido'  => 'calificado',
            'razonamiento'     => '',
            'tipo_respuesta'   => KnowledgeGroundingGate::TIPO_AFIRMACION,
            'fuentes_kb'       => $fuentes,
        ];
    }

    /**
     * Respuesta final de Claude, sin más tool use.
     *
     * @param array<string, mixed> $paquete JSON que devuelve el modelo.
     *
     * @return array<string, mixed>
     */
    private function respuesta_final(array $paquete): array
    {
        return [
            'stop_reason' => 'end_turn',
            'content'     => [[
                'type' => 'text',
                'text' => json_encode($paquete, JSON_UNESCAPED_UNICODE),
            ]],
        ];
    }

    /**
     * Fakea la API de Anthropic con una secuencia de respuestas.
     *
     * @param array<int, array<string, mixed>> $respuestas Respuestas en orden.
     *
     * @return void
     */
    private function fakear_secuencia(array $respuestas): void
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $secuencia = Http::sequence();

        foreach ($respuestas as $respuesta) {
            $secuencia->push($respuesta);
        }

        Http::fake([
            'api.anthropic.com/*' => $secuencia,
            '*'                   => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * Cuántos pedidos se le hicieron a la API de Anthropic.
     *
     * @return int
     */
    private function llamadas_a_claude(): int
    {
        return count($this->cuerpos_enviados_a_claude());
    }

    /**
     * Cuerpos de los pedidos a la API de Anthropic, en orden, con los no-ASCII legibles (el body viaja
     * como JSON y `json_encode` escapa cada acento: buscar «SALIÓ» en el crudo no puede dar nunca).
     *
     * @return array<int, string>
     */
    private function cuerpos_enviados_a_claude(): array
    {
        $cuerpos = [];

        Http::recorded(function ($request) use (&$cuerpos) {
            if (strpos($request->url(), 'api.anthropic.com') !== false) {
                $decodificado = json_decode((string) $request->body(), true);

                $cuerpos[] = json_last_error() === JSON_ERROR_NONE
                    ? json_encode($decodificado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (string) $request->body();
            }

            return false;
        });

        return $cuerpos;
    }

    /**
     * Deja en base el system prompt y el system base que `build_system_prompt()` exige, con el contrato
     * de fuentes que enciende el gate.
     *
     * @return void
     */
    private function sembrar_protocolo_de_leads(): void
    {
        AiSystemPrompt::create([
            'contenido'   => 'System prompt de prueba.',
            'descripcion' => 'Fila mínima para que build_system_prompt() no tire.',
            'activa'      => true,
        ]);

        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::SYSTEM_BASE_KEY,
            'repo_path' => 'agentes/lead/recursos/README.md',
            'content'   => 'System base de prueba. Completá fuentes_kb con los recursos que pediste.',
            'synced_at' => now(),
        ]);
    }

    /**
     * Deja en base el contenido de los recursos, para que se puedan servir de verdad.
     *
     * @param array<int, string> $recursos Nombres de los recursos a sembrar.
     *
     * @return void
     */
    private function sembrar_recursos(array $recursos): void
    {
        foreach ($recursos as $nombre) {
            SyncedGithubFile::create([
                'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX . $nombre,
                'repo_path' => "agentes/lead/recursos/{$nombre}.md",
                'content'   => "Contenido de prueba del recurso {$nombre}.",
                'synced_at' => now(),
            ]);
        }
    }

    /**
     * Lead mínimo en el estado pedido.
     *
     * @param string $estado Estado del lead.
     *
     * @return Lead
     */
    private function crear_lead(string $estado): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->phone        = '+54911' . random_int(10000000, 99999999);
        $lead->status       = $estado;
        $lead->save();

        return $lead->refresh();
    }
}
