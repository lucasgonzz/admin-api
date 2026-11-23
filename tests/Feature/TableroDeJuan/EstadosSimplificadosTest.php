<?php

namespace Tests\Feature\TableroDeJuan;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaChatAcceso;
use App\Services\PruebaDelClienteService;

/**
 * CA9, CA10 y CA11 de la etapa E1 (misión embudo-juan-pipeline): los estados del lead simplificados.
 *
 * Qué se protege, y por qué cada cosa:
 *  1. CA9 — `GET lead/status-cards?version=2` devuelve las 10 tarjetas nuevas, en orden, con las etiquetas de Juan
 *     («Quiere probar», «Cliente», «Perdido»), «Conversando» sumando `contactado` + `calificado` y «Archivados» sólo si hay
 *     un lead en un estado archivado. SIN el parámetro la respuesta es exactamente la de hoy: un SPA viejo no manda
 *     `version` y no puede notar nada (compatible hacia atrás en las dos direcciones). El filtro de chats entiende los
 *     dos valores agrupados nuevos (`conversando`, `archivados`).
 *  2. CA10 — El select del modal del lead no ofrece los estados archivados y sí `en_implementacion`; las etiquetas de las
 *     otras cuatro pantallas (`options_for_meta`) NO se tocan, porque sacar slugs de ahí rompería los nombres de los leads
 *     que ya están en esos estados. El seeder `LeadPipelineStatusJuanSeeder` cambia las tres etiquetas viejas sólo si
 *     siguen en su valor viejo, da de alta `en_implementacion` y es idempotente.
 *  3. CA11 — El chat de Soporte dentro del sistema de la prueba (`PruebaChatAcceso::vigente`) sigue vigente cuando el lead
 *     pasa a `implementacion_pedida` o `en_implementacion` (no sólo en `prueba_activa`), y la búsqueda del lead por teléfono
 *     de `PruebaDelClienteService::lead_del_cliente` también.
 *
 * La tabla `lead_pipeline_statuses` de la base de testing viene sembrada: cada test la vacía (adentro de la transacción)
 * para que rija el catálogo por defecto `DEFAULT_STATUSES`, como en `StatusCardsAgrupadaOtrosTest`. Los conteos de las
 * tarjetas son globales, así que también se vacían los leads.
 *
 * Hoy están EN ROJO: no existe `en_implementacion` ni el parámetro `version`, el select no oculta ningún estado, no existe
 * `LeadPipelineStatusJuanSeeder` y `PruebaChatAcceso::vigente` exige `prueba_activa`.
 */
class EstadosSimplificadosTest extends BaseDelTableroDeJuan
{
    /** Los 8 estados archivados que NO tienen tarjeta propia en la versión 2 (`calificado` va en «Conversando»). */
    private const ARCHIVADOS_SIN_TARJETA = [
        'solicita_disponibilidad',
        'demo_agendada',
        'demo_en_curso',
        'demo_pendiente_de_ingreso',
        'demo_pendiente_de_terminar',
        'demo_realizada',
        'closer_activo',
        'mail2_enviado',
    ];

    /** Los 9 slugs que dejan de ofrecerse en el select del modal del lead (`SLUGS_ARCHIVADOS`). */
    private const SLUGS_ARCHIVADOS = [
        'calificado',
        'solicita_disponibilidad',
        'demo_agendada',
        'demo_en_curso',
        'demo_pendiente_de_ingreso',
        'demo_pendiente_de_terminar',
        'demo_realizada',
        'closer_activo',
        'mail2_enviado',
    ];

    /**
     * Deja la base sin leads ni catálogo de estados (rige el fallback `DEFAULT_STATUSES`) y loguea a un admin común.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        LeadMessage::query()->delete();
        Lead::query()->delete();
        LeadPipelineStatus::query()->delete();

        $this->loguear_a($this->crear_admin_del_tablero('Lucas'));
    }

    // ------------------------------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------------------------------

    /**
     * Crea `n` leads en un estado.
     *
     * @param string $estado
     * @param int    $cantidad
     *
     * @return array<int, Lead>
     */
    private function crear_leads_en(string $estado, int $cantidad = 1): array
    {
        $leads = [];
        for ($numero = 0; $numero < $cantidad; $numero++) {
            $leads[] = $this->crear_lead_del_tablero($estado);
        }

        return $leads;
    }

    /**
     * Las tarjetas de estado que devuelve el endpoint.
     *
     * @param string $query_string Ej. `?version=2` o ''.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pedir_tarjetas(string $query_string = ''): array
    {
        $respuesta = $this->getJson('/api/admin/lead/status-cards' . $query_string);
        $respuesta->assertStatus(200);

        return $respuesta->json('cards');
    }

    /**
     * Los ids de lead que devuelve la lista de chats para un filtro de estado.
     *
     * @param string $status Valor del parámetro `status`.
     *
     * @return array<int, int>
     */
    private function ids_de_chats(string $status): array
    {
        $respuesta = $this->getJson('/api/admin/lead/chats?status=' . $status . '&per_page=200');
        $respuesta->assertStatus(200);

        $ids = array_map('intval', array_column($respuesta->json('data'), 'id'));
        sort($ids);

        return $ids;
    }

    // ------------------------------------------------------------------------------------------
    // CA9 — tarjetas v2
    // ------------------------------------------------------------------------------------------

    /**
     * CA9. Las 10 tarjetas de la versión 2, en el orden del plan, con sus etiquetas y sus totales; «Conversando» cuenta
     * `contactado` + `calificado`; «Archivados» no aparece si no hay ningún lead archivado.
     *
     * @return void
     */
    public function test_tarjetas_v2_las_diez_en_orden(): void
    {
        $this->crear_leads_en('nuevo', 1);
        $this->crear_leads_en('contactado', 2);
        $this->crear_leads_en('calificado', 1);
        foreach (['link_enviado', 'implementacion_pedida', 'prueba_activa', 'en_implementacion', 'prueba_vencida', 'cerrado_ganado', 'cerrado_perdido', 'en_pausa'] as $estado) {
            $this->crear_leads_en($estado, 1);
        }

        $tarjetas = $this->pedir_tarjetas('?version=2');

        $this->assertSame(
            ['nuevo', 'conversando', 'link_enviado', 'implementacion_pedida', 'prueba_activa', 'en_implementacion', 'prueba_vencida', 'cerrado_ganado', 'cerrado_perdido', 'en_pausa'],
            array_column($tarjetas, 'value'),
            'Las 10 tarjetas, en el orden del plan y sin «Archivados» (no hay leads archivados).'
        );
        $this->assertSame(
            ['Nuevo', 'Conversando', 'Quiere probar', 'Implementación pedida', 'En prueba', 'En implementación', 'Prueba vencida', 'Cliente', 'Perdido', 'En pausa'],
            array_column($tarjetas, 'text'),
            'Las etiquetas de Juan.'
        );
        $this->assertSame([1, 3, 1, 1, 1, 1, 1, 1, 1, 1], array_map('intval', array_column($tarjetas, 'total')), '«Conversando» suma contactado (2) + calificado (1).');

        foreach ($tarjetas as $tarjeta) {
            $this->assertArrayHasKey('sin_responder', $tarjeta, 'Cada tarjeta trae su contador de «sin responder».');
            $this->assertArrayHasKey('color', $tarjeta);
        }

        $this->assertSame(['contactado', 'calificado'], $tarjetas[1]['slugs'], '«Conversando» agrupa los dos estados.');
    }

    /**
     * CA9. «Archivados» aparece SOLO si hay un lead en un estado archivado, va al final y cuenta todos los archivados.
     *
     * @return void
     */
    public function test_tarjetas_v2_archivados_solo_si_hay_uno(): void
    {
        $this->crear_leads_en('nuevo', 1);
        $sin = $this->pedir_tarjetas('?version=2');
        $this->assertNotContains('archivados', array_column($sin, 'value'), 'Sin leads archivados no hay tarjeta.');

        foreach (self::ARCHIVADOS_SIN_TARJETA as $estado) {
            $this->crear_leads_en($estado, 1);
        }

        $con      = $this->pedir_tarjetas('?version=2');
        $ultima   = $con[count($con) - 1];

        $this->assertSame('archivados', $ultima['value'], '«Archivados» va última.');
        $this->assertSame('Archivados', $ultima['text']);
        $this->assertSame(count(self::ARCHIVADOS_SIN_TARJETA), (int) $ultima['total'], 'Cuenta los 8 estados archivados que no tienen tarjeta propia.');

        $slugs = $ultima['slugs'];
        sort($slugs);
        $esperados = self::ARCHIVADOS_SIN_TARJETA;
        sort($esperados);
        $this->assertSame($esperados, $slugs);
        $this->assertCount(11, $con, 'Las 10 de siempre más «Archivados».');
    }

    /**
     * CA9. Sin `version` la respuesta es la de hoy (cinco tarjetas: Otros, Calificado, Solicita disponibilidad, Demo y
     * Closer activo), igual con `version=1`, y distinta de la versión 2. `en_implementacion` cae en «Otros» sin tocar código.
     *
     * @return void
     */
    public function test_sin_version_igual_que_hoy(): void
    {
        $this->crear_leads_en('contactado', 1);
        $this->crear_leads_en('calificado', 1);
        $this->crear_leads_en('en_implementacion', 1);
        $this->crear_leads_en('demo_agendada', 1);

        $sin_version = $this->getJson('/api/admin/lead/status-cards');
        $sin_version->assertStatus(200);
        $con_uno = $this->getJson('/api/admin/lead/status-cards?version=1');

        $tarjetas = $sin_version->json('cards');
        $this->assertSame(['otros', 'calificado', 'solicita_disponibilidad', 'demo', 'closer_activo'], array_column($tarjetas, 'value'), 'Las cinco tarjetas de hoy, en su orden.');
        $this->assertSame(['Otros', 'Calificado', 'Solicita disponibilidad', 'Demo', 'Closer activo'], array_column($tarjetas, 'text'));
        $this->assertSame($sin_version->json(), $con_uno->json(), 'Con version=1 es igual que sin parámetro.');

        $otros = $tarjetas[0];
        $this->assertContains('en_implementacion', $otros['slugs'], 'El estado nuevo cae en «Otros» sin tocar código (el cálculo es dinámico).');
        $this->assertSame(2, (int) $otros['total'], '«Otros» cuenta contactado y en_implementacion.');

        $v2 = $this->getJson('/api/admin/lead/status-cards?version=2')->json('cards');
        $this->assertNotSame(array_column($tarjetas, 'value'), array_column($v2, 'value'), 'La versión 2 es otra cosa.');
    }

    /**
     * CA9. El filtro de la lista de chats resuelve `conversando` (contactado + calificado) y `archivados` (los estados que
     * ya no tienen tarjeta), y deja pasar un slug suelto como `en_implementacion`.
     *
     * @return void
     */
    public function test_filtro_conversando_y_archivados(): void
    {
        $contactado = $this->crear_leads_en('contactado', 1)[0];
        $calificado = $this->crear_leads_en('calificado', 1)[0];
        $nuevo      = $this->crear_leads_en('nuevo', 1)[0];
        $agendada   = $this->crear_leads_en('demo_agendada', 1)[0];
        $closer     = $this->crear_leads_en('closer_activo', 1)[0];
        $impl       = $this->crear_leads_en('en_implementacion', 1)[0];

        /* La lista de chats parte de los leads con mensajes. */
        foreach ([$contactado, $calificado, $nuevo, $agendada, $closer, $impl] as $lead) {
            LeadMessage::create([
                'lead_id' => $lead->id, 'sender' => 'lead', 'status' => 'enviado', 'content' => 'Hola', 'kind' => 'text',
                'is_status_event' => false, 'is_error' => false, 'sent_at' => now(),
            ]);
        }

        $esperados_conversando = [(int) $contactado->id, (int) $calificado->id];
        sort($esperados_conversando);
        $this->assertSame($esperados_conversando, $this->ids_de_chats('conversando'), '`conversando` = contactado + calificado.');

        $esperados_archivados = [(int) $agendada->id, (int) $closer->id];
        sort($esperados_archivados);
        $this->assertSame($esperados_archivados, $this->ids_de_chats('archivados'), '`archivados` trae demo_agendada y closer_activo.');

        $this->assertSame([(int) $impl->id], $this->ids_de_chats('en_implementacion'), 'Un slug suelto pasa tal cual.');
    }

    // ------------------------------------------------------------------------------------------
    // CA10 — select y etiquetas
    // ------------------------------------------------------------------------------------------

    /**
     * CA10. El catálogo por defecto trae `en_implementacion` («En implementación», `#6610f2`, grupo «Cierre») y las tres
     * etiquetas nuevas.
     *
     * @return void
     */
    public function test_el_catalogo_por_defecto_trae_en_implementacion_y_las_etiquetas_nuevas(): void
    {
        $this->assertSame('En implementación', LeadPipelineStatus::DEFAULT_STATUSES['en_implementacion'] ?? null);
        $this->assertSame('#6610f2', LeadPipelineStatus::DEFAULT_COLORS['en_implementacion'] ?? null);
        $this->assertSame('Cierre', LeadPipelineStatus::DEFAULT_STATUS_GROUPS['en_implementacion'] ?? null);

        $this->assertSame('Quiere probar', LeadPipelineStatus::DEFAULT_STATUSES['link_enviado']);
        $this->assertSame('Cliente', LeadPipelineStatus::DEFAULT_STATUSES['cerrado_ganado']);
        $this->assertSame('Perdido', LeadPipelineStatus::DEFAULT_STATUSES['cerrado_perdido']);
        $this->assertSame('Conversando', LeadPipelineStatus::DEFAULT_STATUSES['contactado']);
        $this->assertSame('En prueba', LeadPipelineStatus::DEFAULT_STATUSES['prueba_activa']);
    }

    /**
     * CA10. Las opciones de `status` en `GET meta/lead` no incluyen ningún estado archivado e incluyen `en_implementacion`;
     * `options_for_meta()` (las etiquetas de las otras pantallas) sigue incluyendo los archivados.
     *
     * @return void
     */
    public function test_select_sin_archivados(): void
    {
        $respuesta = $this->getJson('/api/admin/meta/lead');
        $respuesta->assertStatus(200);

        $opciones = null;
        foreach ($respuesta->json('properties') as $propiedad) {
            if (isset($propiedad['key']) && $propiedad['key'] === 'status') {
                $opciones = $propiedad['options'];
            }
        }
        $this->assertNotNull($opciones, 'meta/lead trae la propiedad status con sus opciones.');

        $valores = array_column($opciones, 'value');
        foreach (self::SLUGS_ARCHIVADOS as $archivado) {
            $this->assertNotContains($archivado, $valores, 'El select no ofrece «' . $archivado . '».');
        }
        foreach (['nuevo', 'contactado', 'link_enviado', 'implementacion_pedida', 'prueba_activa', 'en_implementacion', 'prueba_vencida', 'cerrado_ganado', 'cerrado_perdido', 'en_pausa'] as $vigente) {
            $this->assertContains($vigente, $valores, 'El select sigue ofreciendo «' . $vigente . '».');
        }

        $etiquetas = array_column($opciones, 'text', 'value');
        $this->assertSame('Quiere probar', $etiquetas['link_enviado']);
        $this->assertSame('Cliente', $etiquetas['cerrado_ganado']);
        $this->assertSame('Perdido', $etiquetas['cerrado_perdido']);
        $this->assertSame('En implementación', $etiquetas['en_implementacion']);

        /* Las etiquetas del resto de las pantallas NO pierden los archivados. */
        $para_etiquetas = array_column(LeadPipelineStatus::options_for_meta(), 'value');
        $this->assertContains('demo_agendada', $para_etiquetas, '`options_for_meta` sigue incluyendo demo_agendada.');
        $this->assertContains('calificado', $para_etiquetas);
        $this->assertContains('en_implementacion', $para_etiquetas);
        $this->assertNotContains('mail2_enviado', $para_etiquetas, 'Lo que ya estaba oculto sigue oculto.');
    }

    /**
     * CA10. `LeadPipelineStatusJuanSeeder` cambia las etiquetas viejas («Cerrado ganado», «Cerrado perdido»), NO pisa una
     * etiqueta editada a mano («Link enviado (web)»), da de alta `en_implementacion` una sola vez y es idempotente.
     *
     * @return void
     */
    public function test_seeder_de_etiquetas(): void
    {
        $clase = 'Database\\Seeders\\LeadPipelineStatusJuanSeeder';
        $this->assertTrue(class_exists($clase), 'Falta el seeder ' . $clase . ' (CA10).');

        LeadPipelineStatus::create(['slug' => 'link_enviado', 'label' => 'Link enviado (web)', 'color' => '#0dcaf0', 'sort_order' => 3]);
        LeadPipelineStatus::create(['slug' => 'cerrado_ganado', 'label' => 'Cerrado ganado', 'color' => '#495057', 'sort_order' => 16]);
        LeadPipelineStatus::create(['slug' => 'cerrado_perdido', 'label' => 'Cerrado perdido', 'color' => '#adb5bd', 'sort_order' => 17]);

        $this->seed($clase);

        $this->assertSame('Link enviado (web)', $this->etiqueta('link_enviado'), 'Una etiqueta editada a mano no se pisa.');
        $this->assertSame('Cliente', $this->etiqueta('cerrado_ganado'));
        $this->assertSame('Perdido', $this->etiqueta('cerrado_perdido'));

        $nuevo = LeadPipelineStatus::query()->where('slug', 'en_implementacion')->get();
        $this->assertCount(1, $nuevo, 'Da de alta `en_implementacion`.');
        $this->assertSame('En implementación', $nuevo[0]->label);
        $this->assertSame('#6610f2', $nuevo[0]->color);
        $this->assertNotNull($nuevo[0]->sort_order);

        /* Segunda corrida: nada cambia ni se duplica. */
        $foto = LeadPipelineStatus::query()->orderBy('id')->get(['slug', 'label', 'color', 'sort_order'])->toArray();
        $this->seed($clase);
        $this->assertSame($foto, LeadPipelineStatus::query()->orderBy('id')->get(['slug', 'label', 'color', 'sort_order'])->toArray(), 'Es idempotente.');
    }

    /**
     * CA10. Con la etiqueta vieja intacta de «Link enviado», el seeder la cambia a «Quiere probar».
     *
     * @return void
     */
    public function test_seeder_cambia_link_enviado_si_sigue_con_su_etiqueta_vieja(): void
    {
        $clase = 'Database\\Seeders\\LeadPipelineStatusJuanSeeder';
        $this->assertTrue(class_exists($clase), 'Falta el seeder ' . $clase . ' (CA10).');

        LeadPipelineStatus::create(['slug' => 'link_enviado', 'label' => 'Link enviado', 'color' => '#0dcaf0', 'sort_order' => 3]);

        $this->seed($clase);

        $this->assertSame('Quiere probar', $this->etiqueta('link_enviado'));
    }

    /**
     * La etiqueta guardada de un estado en el catálogo.
     *
     * @param string $slug
     *
     * @return string|null
     */
    private function etiqueta(string $slug): ?string
    {
        $fila = LeadPipelineStatus::query()->where('slug', $slug)->first();

        return $fila === null ? null : $fila->label;
    }

    /**
     * CA10. `DatabaseSeeder` llama también al seeder de los estados.
     *
     * @return void
     */
    public function test_database_seeder_llama_al_seeder_de_los_estados(): void
    {
        $fuente = (string) file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $this->assertNotFalse(strpos($fuente, 'LeadPipelineStatusJuanSeeder::class'), 'DatabaseSeeder tiene que llamar a LeadPipelineStatusJuanSeeder.');
    }

    // ------------------------------------------------------------------------------------------
    // CA11 — el chat de la prueba
    // ------------------------------------------------------------------------------------------

    /**
     * CA11. `PruebaChatAcceso::vigente` da true con el lead en `prueba_activa`, `implementacion_pedida` o `en_implementacion`
     * (la instancia sigue vigente) y false con el lead en `cerrado_perdido`.
     *
     * @return void
     */
    public function test_chat_de_prueba_vigente_en_implementacion(): void
    {
        $cliente = $this->crear_cliente_del_tablero([
            'estado'        => 'prueba',
            'trial_days'    => 7,
            'trial_ends_at' => now()->addDays(3),
        ]);
        $lead = $this->crear_lead_del_tablero('prueba_activa', ['promoted_client_id' => $cliente->id]);

        TrialInstance::create([
            'nombre'             => 'prueba-chat',
            'estado'             => TrialInstance::ESTADO_ASIGNADA,
            'client_id'          => $cliente->id,
            'lead_id'            => $lead->id,
            'user_id'            => 900300,
            'erp_vps_path'       => 'prueba-chat',
            'ecommerce_vps_path' => 'tienda-prueba-chat',
            'version'            => '4.3.99',
            'ecommerce_version'  => '1.9.99',
            'etapa'              => 'lista',
            'progreso'           => 100,
        ]);

        $casos = [
            'prueba_activa'         => true,
            'implementacion_pedida' => true,
            'en_implementacion'     => true,
            'cerrado_perdido'       => false,
        ];

        foreach ($casos as $estado => $esperado) {
            $lead->status = $estado;
            $lead->save();

            $this->assertSame(
                $esperado,
                PruebaChatAcceso::vigente($lead->fresh(), $cliente->fresh()),
                'Con el lead en «' . $estado . '» el chat de la prueba tendría que ' . ($esperado ? 'seguir vigente' : 'estar cerrado') . '.'
            );
        }
    }

    /**
     * CA11. La búsqueda del lead de un cliente en prueba por teléfono (cuando no está promovido) encuentra al lead aunque
     * esté en `implementacion_pedida` o `en_implementacion`, y no al que está `cerrado_perdido`.
     *
     * @return void
     */
    public function test_el_lead_del_cliente_se_encuentra_por_telefono_en_implementacion(): void
    {
        $servicio = app(PruebaDelClienteService::class);

        foreach (['implementacion_pedida', 'en_implementacion'] as $estado) {
            $telefono = '54934' . random_int(10000000, 99999999);
            $cliente  = $this->crear_cliente_del_tablero(['phone' => '+' . $telefono, 'estado' => 'prueba']);
            $lead     = $this->crear_lead_del_tablero($estado, ['phone' => $telefono]);

            $encontrado = $servicio->lead_del_cliente($cliente);
            $this->assertNotNull($encontrado, 'Con el lead en «' . $estado . '» se encuentra por teléfono.');
            $this->assertSame((int) $lead->id, (int) $encontrado->id);
        }

        $telefono = '54934' . random_int(10000000, 99999999);
        $cliente  = $this->crear_cliente_del_tablero(['phone' => '+' . $telefono, 'estado' => 'prueba']);
        $this->crear_lead_del_tablero('cerrado_perdido', ['phone' => $telefono]);
        $this->assertNull($servicio->lead_del_cliente($cliente), 'Un lead cerrado perdido no es el lead de la prueba.');
    }
}
