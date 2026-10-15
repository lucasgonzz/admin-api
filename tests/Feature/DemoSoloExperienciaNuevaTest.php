<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadDemoSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 🔴 Misión demo-solo-experiencia-nueva (14/10/2026): la dinámica de demo 'actual' está RETIRADA y
 * no hay forma de que un lead, actual o nuevo, deje de usar la página de experiencia con su
 * formulario. Decisión de Lucas, nivel de candado TOTAL.
 *
 * Estos tests existen para que el candado no se pueda sacar sin que alguien lo note. Cada uno
 * cubre una puerta distinta por la que 'actual' podría volver a colarse:
 *
 *  - el modelo al crear (`saving` fuerza 'nueva' incluso si alguien pasa 'actual' o basura);
 *  - el modelo al guardar un lead que ya existe;
 *  - la lectura (`demo_experiencia_efectiva()` no mira la columna: una fila que quedó mal por un
 *    UPDATE directo igual se comporta como 'nueva');
 *  - el endpoint del panel (`POST lead/{id}/demo-experiencia`);
 *  - la setting global (`LeadDemoSettings`), que ya no puede devolver ni guardar 'actual'.
 *
 * Todo contra el modelo y la base reales: un doble del modelo no probaría el hook.
 */
class DemoSoloExperienciaNuevaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Lee la columna `demo_experiencia` directo de la base, sin pasar por Eloquent: es lo que
     * realmente quedó escrito, sin que un accesor o un cast lo "arreglen" al leerlo.
     *
     * @param int $lead_id
     *
     * @return string|null
     */
    private function columna_en_la_base(int $lead_id)
    {
        return DB::table('leads')->where('id', $lead_id)->value('demo_experiencia');
    }

    /**
     * Crea un lead por Eloquent con los atributos pedidos.
     *
     * @param array<string, mixed> $atributos
     *
     * @return Lead
     */
    private function crear_lead(array $atributos = []): Lead
    {
        $lead = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Juana Pérez';

        foreach ($atributos as $clave => $valor) {
            $lead->{$clave} = $valor;
        }

        $lead->save();

        return $lead;
    }

    /**
     * Admin autenticado por Sanctum, como exige el grupo de rutas del panel.
     *
     * @return Admin
     */
    private function autenticar_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /* ------------------------------------------------------------------ */
    /* El modelo al crear                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Un lead creado sin decir nada sobre la dinámica nace 'nueva'.
     *
     * @return void
     */
    public function test_un_lead_creado_sin_valor_nace_con_la_experiencia_nueva(): void
    {
        $lead = $this->crear_lead();

        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $this->columna_en_la_base($lead->id));
    }

    /**
     * Pedir 'actual' al crear no sirve: se estampa 'nueva'. Es el caso de un `Lead::create` de un
     * controller, un seeder o un test que todavía arrastre la dinámica vieja.
     *
     * @return void
     */
    public function test_un_lead_creado_con_actual_nace_con_la_experiencia_nueva(): void
    {
        $lead = Lead::create([
            'uuid'             => (string) Str::uuid(),
            'contact_name'     => 'Juana Pérez',
            'demo_experiencia' => Lead::EXPERIENCIA_ACTUAL,
        ]);

        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $this->columna_en_la_base($lead->id));
        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $lead->demo_experiencia, 'El modelo en memoria quedó con otro valor que la base.');
    }

    /**
     * Un valor inventado o vacío tampoco se guarda: no queda ni 'basura' ni null.
     *
     * @return void
     */
    public function test_un_lead_creado_con_basura_o_vacio_nace_con_la_experiencia_nueva(): void
    {
        foreach (['rara', '', null] as $valor) {
            $lead = $this->crear_lead(['demo_experiencia' => $valor]);

            $this->assertSame(
                Lead::EXPERIENCIA_NUEVA,
                $this->columna_en_la_base($lead->id),
                'Se guardó un valor distinto de "nueva" al crear con ' . var_export($valor, true) . '.'
            );
        }
    }

    /**
     * La setting global ya no decide con qué dinámica nace un lead: aunque en la base de
     * configuración haya quedado guardado 'actual' (el valor histórico), el lead nace 'nueva'.
     *
     * @return void
     */
    public function test_la_setting_global_guardada_en_actual_no_hace_nacer_leads_actuales(): void
    {
        AdminSetting::set(LeadDemoSettings::KEY_EXPERIENCIA_DEFAULT, 'actual');

        $lead = $this->crear_lead();

        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $this->columna_en_la_base($lead->id));
    }

    /* ------------------------------------------------------------------ */
    /* El modelo al guardar un lead que ya existe                          */
    /* ------------------------------------------------------------------ */

    /**
     * Un lead que existe y se guarda con 'actual' por Eloquent vuelve a 'nueva': ningún camino que
     * pase por el modelo puede dejar la dinámica vieja escrita.
     *
     * @return void
     */
    public function test_guardar_un_lead_existente_con_actual_lo_devuelve_a_nueva(): void
    {
        $lead = $this->crear_lead();

        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();

        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $this->columna_en_la_base($lead->id));
    }

    /**
     * Un lead viejo que quedó con la columna en 'actual' (UPDATE directo, o un resto de antes de la
     * misión) se corrige solo la próxima vez que se guarda por cualquier motivo, aunque el motivo
     * no tenga nada que ver con la dinámica.
     *
     * @return void
     */
    public function test_un_lead_viejo_en_actual_se_corrige_al_guardarlo_por_otro_motivo(): void
    {
        $lead = $this->crear_lead();
        DB::table('leads')->where('id', $lead->id)->update(['demo_experiencia' => 'actual']);

        $lead = Lead::findOrFail($lead->id);
        $this->assertSame('actual', $lead->demo_experiencia, 'Montaje inválido: el lead no quedó en actual.');

        $lead->contact_name = 'Otro nombre';
        $lead->save();

        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $this->columna_en_la_base($lead->id));
    }

    /* ------------------------------------------------------------------ */
    /* La lectura                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * 🔴 El candado de lectura: `demo_experiencia_efectiva()` devuelve 'nueva' sin mirar la
     * columna. Se escribe 'nada', 'actual' y basura con un UPDATE directo (que se salta el hook de
     * guardado) y se relee: el lead igual se comporta como 'nueva'. Éste es el test que falla si
     * alguien vuelve a hacer que la columna decida.
     *
     * @return void
     */
    public function test_la_experiencia_efectiva_es_nueva_aunque_la_columna_diga_otra_cosa(): void
    {
        $lead = $this->crear_lead();

        foreach ([null, 'actual', 'rara'] as $valor) {
            DB::table('leads')->where('id', $lead->id)->update(['demo_experiencia' => $valor]);

            $recargado = Lead::findOrFail($lead->id);

            $this->assertSame(
                Lead::EXPERIENCIA_NUEVA,
                $recargado->demo_experiencia_efectiva(),
                'demo_experiencia_efectiva() miró la columna (' . var_export($valor, true) . ') en vez de devolver siempre "nueva".'
            );
            $this->assertTrue(
                $recargado->usa_experiencia_demo_nueva(),
                'usa_experiencia_demo_nueva() dio false con la columna en ' . var_export($valor, true) . '.'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* El endpoint del panel                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Pedirle 'actual' al endpoint del panel es 422 con un mensaje claro, y la columna no se toca
     * ni se escribe ningún evento en el hilo del lead.
     *
     * @return void
     */
    public function test_el_endpoint_rechaza_actual_con_422_y_no_toca_el_lead(): void
    {
        $this->autenticar_admin();
        $lead = $this->crear_lead();

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/demo-experiencia', [
            'demo_experiencia' => 'actual',
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('retirada', (string) $respuesta->json('message'));
        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $this->columna_en_la_base($lead->id));
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->count(), 'Se escribió un evento en el hilo por un cambio rechazado.');
    }

    /**
     * Un valor que ni siquiera es una dinámica conocida sigue siendo 422 de validación.
     *
     * @return void
     */
    public function test_el_endpoint_rechaza_un_valor_desconocido(): void
    {
        $this->autenticar_admin();
        $lead = $this->crear_lead();

        $this->postJson('/api/admin/lead/' . $lead->id . '/demo-experiencia', [
            'demo_experiencia' => 'rara',
        ])->assertStatus(422);

        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $this->columna_en_la_base($lead->id));
    }

    /**
     * Pedir 'nueva' sigue andando: es idempotente (200 con el modelo) y no escribe eventos de más.
     *
     * @return void
     */
    public function test_el_endpoint_acepta_nueva_y_es_idempotente(): void
    {
        $this->autenticar_admin();
        $lead = $this->crear_lead();

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/demo-experiencia', [
            'demo_experiencia' => 'nueva',
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame($lead->id, $respuesta->json('model.id'));
        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $this->columna_en_la_base($lead->id));
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->count(), 'Pedir el valor que ya tiene no debería escribir un evento.');
    }

    /**
     * Pedir 'nueva' sobre un lead que quedó en 'actual' en la base (resto viejo) lo deja en 'nueva'
     * y deja constancia en el hilo, como siempre.
     *
     * @return void
     */
    public function test_el_endpoint_corrige_un_lead_que_quedo_en_actual(): void
    {
        $this->autenticar_admin();
        $lead = $this->crear_lead();
        DB::table('leads')->where('id', $lead->id)->update(['demo_experiencia' => 'actual']);

        $this->postJson('/api/admin/lead/' . $lead->id . '/demo-experiencia', [
            'demo_experiencia' => 'nueva',
        ])->assertStatus(200);

        $this->assertSame(Lead::EXPERIENCIA_NUEVA, $this->columna_en_la_base($lead->id));
        $this->assertSame(1, LeadMessage::where('lead_id', $lead->id)->count());
    }

    /* ------------------------------------------------------------------ */
    /* La setting global                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * `get_experiencia_default()` devuelve 'nueva' aunque lo guardado sea 'actual', basura o
     * nada; y `to_array()` (lo que lee el panel de configuración) dice lo mismo.
     *
     * @return void
     */
    public function test_la_setting_default_siempre_devuelve_nueva(): void
    {
        foreach (['actual', 'rara', ''] as $guardado) {
            AdminSetting::set(LeadDemoSettings::KEY_EXPERIENCIA_DEFAULT, $guardado);

            $this->assertSame('nueva', LeadDemoSettings::get_experiencia_default(), 'Con "' . $guardado . '" guardado devolvió otra cosa.');
            $this->assertSame('nueva', LeadDemoSettings::to_array()['experiencia_default']);
        }

        AdminSetting::where('key', LeadDemoSettings::KEY_EXPERIENCIA_DEFAULT)->delete();
        $this->assertSame('nueva', LeadDemoSettings::get_experiencia_default(), 'Sin nada guardado el default tiene que ser "nueva".');
    }

    /**
     * Guardar la configuración de demos con `experiencia_default = 'actual'` (un admin-spa viejo
     * que todavía ofrece la opción) no rompe nada y NO guarda 'actual': la setting no se pisa.
     *
     * @return void
     */
    public function test_guardar_la_configuracion_con_actual_no_la_persiste(): void
    {
        /* Valor de partida conocido: lo que haya en la base de testing no importa. */
        AdminSetting::set(LeadDemoSettings::KEY_EXPERIENCIA_DEFAULT, 'nueva');

        $datos = LeadDemoSettings::to_array();
        $datos['experiencia_default'] = 'actual';

        LeadDemoSettings::persist_from_request($datos);

        $this->assertSame(
            'nueva',
            AdminSetting::where('key', LeadDemoSettings::KEY_EXPERIENCIA_DEFAULT)->value('value'),
            'persist_from_request() pisó la setting con "actual".'
        );
        $this->assertSame('nueva', LeadDemoSettings::get_experiencia_default());
    }
}
