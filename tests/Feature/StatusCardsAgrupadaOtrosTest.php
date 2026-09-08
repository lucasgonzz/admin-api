<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /lead/status-cards` con la tarjeta "Otros" (misión tarjeta-otros-leads, 8/9/2026): catch-all
 * que va PRIMERA, antes de "Calificado", y cuenta todo lead cuyo estado no tiene tarjeta propia
 * (ni en `LeadPipelineStatus::SLUGS_TARJETAS_ESTADO` ni en `TARJETA_DEMO_SLUGS`).
 *
 * Lo que se protege acá, que no protege ningún otro archivo:
 *
 * 1. El cálculo es **dinámico** contra `LeadPipelineStatus::all_slugs()` (un `array_diff`, no una
 *    lista fija a mano): si aparece un slug nuevo en el catálogo que nadie le dio tarjeta propia
 *    todavía, tiene que caer solo en "Otros", sin que haga falta tocar código. Ese es el caso de
 *    `test_un_slug_de_catalogo_nuevo_sin_tarjeta_propia_cae_en_otros_sin_tocar_codigo()`.
 * 2. El resto de los criterios (`total`, `sin_responder`, clic-para-filtrar) son los mismos que ya
 *    prueban `TarjetasDeEstadoDeLeadsTest.php` y `StatusCardsAgrupadasDemoTest.php` para las otras
 *    tarjetas -- acá se repiten puntualmente sobre "Otros" porque es una tarjeta agrupada nueva,
 *    no porque el criterio en sí sea distinto.
 *
 * 🔴 La tabla `lead_pipeline_statuses` empieza VACÍA en cada test (la deja así `DatabaseTransactions`,
 * que revierte cualquier fila que un test inserte) y ahí `LeadPipelineStatus::all_slugs()` cae al
 * fallback `DEFAULT_STATUSES` -- de eso sale la lista de 8 slugs de SLUGS_OTROS_POR_DEFECTO. El único
 * test que inserta una fila en esa tabla (el del slug dinámico) queda deliberadamente aislado del
 * resto: en cuanto la tabla deja de estar vacía, `all_slugs()` deja de usar el fallback y devuelve
 * SOLO lo que hay en BD, así que ese test no puede mezclar aserciones sobre el resto de los slugs
 * por defecto.
 */
class StatusCardsAgrupadaOtrosTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Los 8 slugs de `DEFAULT_STATUSES` que hoy NO tienen tarjeta propia: el catálogo completo (14)
     * menos los 6 que ya cubren calificado/solicita_disponibilidad/closer_activo/demo. Verificado a
     * mano contra `LeadPipelineStatus::DEFAULT_STATUSES` el 8/9/2026 -- si ese catálogo cambia
     * (se agrega o se saca un estado), esta lista tiene que actualizarse junto con él.
     */
    private const SLUGS_OTROS_POR_DEFECTO = [
        'nuevo',
        'contactado',
        'demo_pendiente_de_terminar',
        'demo_realizada',
        'mail2_enviado',
        'cerrado_ganado',
        'cerrado_perdido',
        'en_pausa',
    ];

    /**
     * Deja la base sin leads para que los conteos (que son globales) sean determinísticos. Mismo
     * criterio que TarjetasDeEstadoDeLeadsTest y PaginadoDelListadoDeLeadsTest.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        LeadMessage::query()->delete();
        Lead::query()->delete();
    }

    /**
     * @return Admin
     */
    private function admin_autenticado(): Admin
    {
        return Admin::create([
            'name'     => 'Admin de prueba',
            'email'    => 'otros-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * Lead mínimo en el estado pedido.
     *
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $status): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->status       = $status;
        $lead->save();

        return $lead;
    }

    /**
     * Pega al endpoint de tarjetas y devuelve el array `cards` tal cual lo manda el backend.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pedir_tarjetas(): array
    {
        $response = $this->actingAs($this->admin_autenticado(), 'sanctum')
            ->getJson('/api/admin/lead/status-cards')
            ->assertStatus(200);

        return $response->json('cards');
    }

    /**
     * "Otros" es la primera tarjeta, con su value/text/color/group fijos y trayendo los 8 slugs
     * que hoy no tienen tarjeta propia (catálogo vacío -> fallback DEFAULT_STATUSES).
     *
     * @return void
     */
    public function test_otros_es_la_primera_tarjeta_con_los_slugs_sin_tarjeta_propia(): void
    {
        $cards = $this->pedir_tarjetas();

        $this->assertSame('otros', $cards[0]['value'], '"Otros" tiene que ser la primera tarjeta, antes de Calificado.');
        $this->assertSame('Otros', $cards[0]['text']);
        $this->assertSame('#6c757d', $cards[0]['color']);
        $this->assertNull($cards[0]['group']);
        $this->assertSame(
            self::SLUGS_OTROS_POR_DEFECTO,
            $cards[0]['slugs'],
            'Los slugs de "otros" son el catálogo completo menos los 6 que ya tienen tarjeta propia.'
        );
    }

    /**
     * El total de "Otros" suma leads en los estados sin tarjeta propia y NO cuenta leads de los 6
     * estados que ya tienen la suya (calificado y demo_agendada, uno de cada grupo cubierto).
     *
     * @return void
     */
    public function test_el_total_suma_los_estados_sin_tarjeta_y_no_los_que_ya_tienen(): void
    {
        $this->crear_lead('nuevo');
        $this->crear_lead('cerrado_perdido');
        // Cubiertos por otras tarjetas: no tienen que sumar a "otros".
        $this->crear_lead('calificado');
        $this->crear_lead('demo_agendada');

        $cards = $this->pedir_tarjetas();
        $otros = collect($cards)->firstWhere('value', 'otros');

        $this->assertSame(2, $otros['total']);
    }

    /**
     * `sin_responder` de "Otros" cuenta leads con mensajes sin contestar en esos estados, mismo
     * criterio que el resto de las tarjetas (`LeadPendingReviewService::lead_requiere_revision()`
     * vía `requiereRevision(true)`).
     *
     * @return void
     */
    public function test_sin_responder_cuenta_los_leads_con_mensajes_sin_contestar(): void
    {
        $con_pendiente = $this->crear_lead('nuevo');
        LeadMessage::create([
            'lead_id'     => $con_pendiente->id,
            'sender'      => 'lead',
            'status'      => 'enviado',
            'content'     => 'Hola, ¿siguen ahí?',
            'is_followup' => false,
        ]);

        // Sin mensajes sin responder: no debe sumar.
        $this->crear_lead('cerrado_perdido');

        $cards = $this->pedir_tarjetas();
        $otros = collect($cards)->firstWhere('value', 'otros');

        $this->assertSame(1, $otros['sin_responder']);
    }

    /**
     * 🔴 El caso que protege el diseño dinámico: un slug de catálogo NUEVO, al que nadie le dio
     * tarjeta propia, tiene que caer en "Otros" sin que haga falta tocar código -- porque el cálculo
     * sale de `array_diff(all_slugs(), $cubiertos)`, no de una lista fija a mano.
     *
     * Insertar el slug en `lead_pipeline_statuses` hace que `all_slugs()` deje de caer al fallback
     * DEFAULT_STATUSES (la tabla ya no está vacía) y devuelva SOLO lo que hay en BD -- por eso este
     * test queda aislado del resto: con un catálogo de un solo slug, "otros" trae únicamente ese
     * slug, no los 8 del fallback.
     *
     * @return void
     */
    public function test_un_slug_de_catalogo_nuevo_sin_tarjeta_propia_cae_en_otros_sin_tocar_codigo(): void
    {
        LeadPipelineStatus::ensure_exists('recontactar_test', 'Recontactar (test)');
        $this->crear_lead('recontactar_test');

        $cards = $this->pedir_tarjetas();
        $otros = collect($cards)->firstWhere('value', 'otros');

        $this->assertSame(['recontactar_test'], $otros['slugs']);
        $this->assertSame(1, $otros['total'], 'El lead del slug nuevo tiene que sumar a "otros" sin ningún cambio de código.');
    }

    /**
     * Clic-para-filtrar: lo que devuelve `POST /admin/search/lead/null/1` al filtrar por los slugs
     * de "Otros" (mismo mecanismo que `on_select_status_card()` en Leads.vue, que hace
     * `commit('lead/add_filter', { type: 'select', key: 'status', igual_que: card.slugs })` y
     * dispara `run_filter` -> ese POST) tiene que traer exactamente la misma cantidad que
     * `otros.total`. Así queda probado que lo que la tarjeta cuenta es lo mismo que se ve al
     * clickearla, sin tener que levantar la SPA.
     *
     * 🔴 NO es `GET /admin/lead`: ese endpoint (`LeadController::index_json()`) no lee `filters[]`,
     * solo un `status` plano. El filtro por grupo de slugs vive en `SearchController::search()` vía
     * `AdminSearchProxyController`, que sí soporta `igual_que` como array (`whereIn`) -- confirmado
     * leyendo el código antes de escribir este test, no asumido del prompt original.
     *
     * @return void
     */
    public function test_filtrar_por_los_slugs_de_otros_devuelve_la_misma_cantidad_que_el_total(): void
    {
        $this->crear_lead('nuevo');
        $this->crear_lead('contactado');
        $this->crear_lead('en_pausa');
        // Cubierto por otra tarjeta: no tiene que aparecer en el filtro de "otros".
        $this->crear_lead('closer_activo');

        $admin = $this->admin_autenticado();

        $cards = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/lead/status-cards')
            ->assertStatus(200)
            ->json('cards');
        $otros = collect($cards)->firstWhere('value', 'otros');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/search/lead/null/1?page=1', [
                'filters' => [
                    ['key' => 'status', 'type' => 'select', 'igual_que' => $otros['slugs']],
                ],
                'per_page' => 200,
            ]);
        $response->assertStatus(200);

        $this->assertSame(
            $otros['total'],
            (int) $response->json('total'),
            'El listado filtrado por los slugs de "otros" tiene que traer la misma cantidad que la tarjeta.'
        );
    }
}
