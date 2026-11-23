<?php

namespace Tests\Feature\TableroDeJuan;

use App\Models\AdminTask;
use App\Models\Lead;
use App\Models\PipelineActivity;
use App\Models\PipelineOpportunity;
use Illuminate\Support\Facades\Schema;

/**
 * CA41 de la etapa E1b (misión embudo-juan-pipeline, agregado del 23/11): `GET lead-tablero/candidatos`, la búsqueda de
 * lead que usa el agente cuando Juan le manda un audio y dice «hablé con Damián, el de Gradom».
 *
 * Es sólo lectura y la decisión (`unico` / `ambiguo` / `sin_candidatos`) se calcula en el servidor, de forma
 * determinista: el agente no puede confundir a dos leads parecidos porque «le pareció». Qué se protege:
 *  1. El puntaje de cada regla (teléfono 100, empresa exacta 90, contacto + empresa 80, empresa parcial 60, sólo el
 *     contacto 50, más 5 con tarjeta abierta sin pasar de 100) y la regla de `unico`.
 *  2. Que NUNCA salga el teléfono completo (sólo los últimos 4).
 *  3. Que no escriba nada, que pida al menos `q` o `telefono` y que Juan (perfil Sólo Leads) reciba 403.
 *
 * Hoy están EN ROJO: la ruta no existe (404).
 */
class CandidatosParaLlamadaTest extends BaseDelTableroDeJuan
{
    /**
     * Siembra el tablero y loguea a un admin común (el agente usa la misma ruta con su token).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_el_tablero();
        $this->loguear_a($this->crear_admin_del_tablero('Lucas'));
    }

    /**
     * Pide los candidatos.
     *
     * @param array<string, string> $query
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function buscar(array $query)
    {
        return $this->getJson('/api/admin/lead-tablero/candidatos?' . http_build_query($query));
    }

    /**
     * Los dos leads del ejemplo del pedido de Lucas.
     *
     * @return array{0: Lead, 1: Lead} Gradom y gonpal.
     */
    private function crear_gradom_y_gonpal(): array
    {
        $gradom = $this->crear_lead_del_tablero('contactado', ['contact_name' => 'Damián', 'company_name' => 'Gradom', 'phone' => '5491164648197']);
        $gonpal = $this->crear_lead_del_tablero('contactado', ['contact_name' => 'Diego González', 'company_name' => 'gonpal', 'phone' => '5493415550123']);

        return [$gradom, $gonpal];
    }

    /**
     * CA41. «Damián Gradom» (contacto + empresa) da `unico` con el lead de Gradom primero, puntaje de 80 o más y sólo los
     * últimos 4 dígitos del teléfono: no sale ningún campo `phone` ni el número completo.
     *
     * @return void
     */
    public function test_unico_por_nombre_y_empresa_sin_el_telefono_completo(): void
    {
        list($gradom, $gonpal) = $this->crear_gradom_y_gonpal();

        $respuesta = $this->buscar(['q' => 'Damián Gradom']);
        $respuesta->assertStatus(200);

        $this->assertSame('unico', $respuesta->json('decision'));
        $modelos = $respuesta->json('models');
        $this->assertSame((int) $gradom->id, (int) $modelos[0]['lead_id'], 'El primero es el de Gradom.');
        $this->assertGreaterThanOrEqual(80, (int) $modelos[0]['puntaje']);
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $modelos[0]['telefono_ultimos_4']);
        $this->assertSame('8197', (string) $modelos[0]['telefono_ultimos_4']);

        foreach (['lead_id', 'contact_name', 'company_name', 'telefono_ultimos_4', 'status', 'status_label', 'etapa', 'ultima_actividad_at', 'puntaje', 'motivos'] as $clave) {
            $this->assertArrayHasKey($clave, $modelos[0], 'Cada candidato trae «' . $clave . '».');
        }
        $this->assertSame('Conversando', $modelos[0]['status_label']);
        $this->assertNotEmpty($modelos[0]['motivos']);

        $crudo = (string) $respuesta->getContent();
        $this->assertStringNotContainsString('"phone"', $crudo, 'No viene ningún campo phone.');
        $this->assertStringNotContainsString('5491164648197', $crudo, 'Nunca el teléfono completo.');
        $this->assertStringNotContainsString('64648197', $crudo);
    }

    /**
     * CA41. Buscar por teléfono (los últimos 8 dígitos, venga con el formato que venga) da puntaje 100, y con la tarjeta
     * abierta no pasa de 100.
     *
     * @return void
     */
    public function test_telefono_da_cien_y_el_puntaje_no_pasa_de_cien(): void
    {
        list($gradom) = $this->crear_gradom_y_gonpal();

        foreach (['5491164648197', '64648197', '+54 9 11 6464-8197'] as $telefono) {
            $respuesta = $this->buscar(['telefono' => $telefono]);
            $respuesta->assertStatus(200);
            $this->assertSame((int) $gradom->id, (int) $respuesta->json('models.0.lead_id'), 'Se encuentra por «' . $telefono . '».');
            $this->assertSame(100, (int) $respuesta->json('models.0.puntaje'));
        }

        /* Con tarjeta abierta (+5) sigue en 100. */
        $this->dar_de_alta_en($gradom, 'interesados');
        $this->assertSame(100, (int) $this->buscar(['telefono' => '5491164648197'])->json('models.0.puntaje'));
    }

    /**
     * CA41. La tarjeta abierta en el tablero suma 5 puntos (empresa exacta 90 → 95) y la etapa viaja en el candidato.
     *
     * @return void
     */
    public function test_la_tarjeta_abierta_suma_cinco_y_muestra_la_etapa(): void
    {
        /* Un lead que todavía no tiene tarjeta (nace en `contactado`, que no entra solo al tablero). */
        $lead = $this->crear_lead_del_tablero('contactado', ['company_name' => 'Panificadora Del Sur', 'contact_name' => 'Rosa']);

        $sin_tarjeta   = $this->buscar(['q' => 'Panificadora Del Sur']);
        $puntaje_antes = (int) $sin_tarjeta->json('models.0.puntaje');
        $this->assertSame(90, $puntaje_antes, 'Empresa exacta.');

        $this->dar_de_alta_en($lead, 'interesados');

        $con_tarjeta = $this->buscar(['q' => 'Panificadora Del Sur']);
        $this->assertSame(95, (int) $con_tarjeta->json('models.0.puntaje'), 'Con tarjeta abierta suma 5.');
        $this->assertSame('Interesados', $con_tarjeta->json('models.0.etapa'));
    }

    /**
     * CA41. Dos negocios que se parecen (los dos con la empresa parcial) dan `ambiguo`: el servidor no elige.
     *
     * @return void
     */
    public function test_ambiguo_con_dos_negocios_parecidos(): void
    {
        $this->crear_lead_del_tablero('contactado', ['contact_name' => 'Marta', 'company_name' => 'Ferretería López Norte']);
        $this->crear_lead_del_tablero('contactado', ['contact_name' => 'Pablo', 'company_name' => 'Ferretería López Sur']);

        $respuesta = $this->buscar(['q' => 'Ferretería López']);

        $respuesta->assertStatus(200);
        $this->assertSame('ambiguo', $respuesta->json('decision'));
        $this->assertCount(2, $respuesta->json('models'));
    }

    /**
     * CA41. El caso literal del plan: «Ferretería López» y «Ferretería López Hnos». Por la regla de puntajes, la exacta
     * saca 90 y la parcial 60: el orden y los puntajes tienen que ser esos (la decisión sale de aplicar la regla de `unico`).
     *
     * @return void
     */
    public function test_empresa_exacta_gana_a_la_parcial_en_el_orden(): void
    {
        $exacta  = $this->crear_lead_del_tablero('contactado', ['contact_name' => 'Marta', 'company_name' => 'Ferretería López']);
        $parcial = $this->crear_lead_del_tablero('contactado', ['contact_name' => 'Pablo', 'company_name' => 'Ferretería López Hnos']);

        $respuesta = $this->buscar(['q' => 'Ferretería López']);

        $respuesta->assertStatus(200);
        $this->assertSame([(int) $exacta->id, (int) $parcial->id], array_map('intval', array_column($respuesta->json('models'), 'lead_id')));
        $this->assertSame([90, 60], array_map('intval', array_column($respuesta->json('models'), 'puntaje')));
    }

    /**
     * CA41. Sin coincidencias: `sin_candidatos` y lista vacía. Con más de 5 coincidencias vuelven como mucho 5.
     *
     * @return void
     */
    public function test_sin_candidatos_y_tope_de_cinco(): void
    {
        $this->crear_gradom_y_gonpal();

        $vacio = $this->buscar(['q' => 'zzzz']);
        $vacio->assertStatus(200);
        $this->assertSame('sin_candidatos', $vacio->json('decision'));
        $this->assertSame([], $vacio->json('models'));

        for ($numero = 1; $numero <= 7; $numero++) {
            $this->crear_lead_del_tablero('contactado', ['contact_name' => 'Persona ' . $numero, 'company_name' => 'Panadería Sol ' . $numero]);
        }
        $this->assertCount(5, $this->buscar(['q' => 'Panadería Sol'])->json('models'), 'Hasta 5 candidatos.');
    }

    /**
     * CA41. Sin `q` ni `telefono`: 422.
     *
     * @return void
     */
    public function test_sin_parametros_da_422(): void
    {
        $this->buscar([])->assertStatus(422);
    }

    /**
     * CA41. Es sólo lectura: ninguna tabla cambia.
     *
     * @return void
     */
    public function test_no_escribe_nada(): void
    {
        list($gradom) = $this->crear_gradom_y_gonpal();
        $this->dar_de_alta_en($gradom, 'interesados');

        $contar = function () {
            return [
                'leads'         => Lead::query()->count(),
                'oportunidades' => PipelineOpportunity::query()->count(),
                'actividades'   => PipelineActivity::query()->count(),
                'tareas'        => AdminTask::query()->count(),
                'llamadas'      => Schema::hasTable('lead_llamadas') ? \Illuminate\Support\Facades\DB::table('lead_llamadas')->count() : 0,
            ];
        };
        $antes = $contar();

        $this->buscar(['q' => 'Damián Gradom'])->assertStatus(200);
        $this->buscar(['telefono' => '5491164648197'])->assertStatus(200);

        $this->assertSame($antes, $contar(), 'Buscar no escribe nada.');
    }

    /**
     * CA41. El perfil Sólo Leads (Juan) no la necesita: 403 `solo_leads`.
     *
     * @return void
     */
    public function test_el_perfil_solo_leads_recibe_403(): void
    {
        $this->loguear_a($this->juan);

        $respuesta = $this->buscar(['q' => 'Gradom']);

        $respuesta->assertStatus(403);
        $this->assertSame('solo_leads', $respuesta->json('code'));
    }
}
