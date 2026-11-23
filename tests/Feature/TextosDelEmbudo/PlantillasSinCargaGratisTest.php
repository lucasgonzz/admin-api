<?php

namespace Tests\Feature\TextosDelEmbudo;

use App\Models\AdminSetting;
use App\Models\FollowupTemplate;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaCorreoService;
use App\Services\Prueba\PruebaSettings;
use App\Services\PruebaPlantillasService;
use Illuminate\Support\Str;
use Tests\Feature\PruebaGratuita\BaseDeLaPrueba;

/**
 * CA29 de la etapa E3 (misión embudo-juan-pipeline): las plantillas y el correo de la prueba ya no prometen cargar «sin costo».
 *
 * Meta no deja editar una plantilla aprobada, así que lo corregido va con NOMBRE NUEVO y reemplaza a la vieja recién cuando Meta
 * lo aprueba (el mismo patrón de las v2):
 *  - `cc_prueba_bienvenida_v4` y `cc_prueba_bienvenida_asistente_v4` (sin la dirección del sistema).
 *  - `cc_prueba_bienvenida_v5` y `cc_prueba_bienvenida_asistente_v5` (las v2 sin la oferta de cargar gratis).
 *  - `cc_prueba_paso_importo_v2`, que con Meta aprobada se usa PARA TODOS y nunca vuelve a usarse `cc_prueba_paso_importo`.
 * Y el correo del paso `importo` cambia su asunto a «Subí tus artículos a tu prueba».
 *
 * Hoy están EN ROJO: ninguna de esas plantillas existe.
 */
class PlantillasSinCargaGratisTest extends BaseDeLaPrueba
{
    /** Las plantillas nuevas de la prueba (con su nombre literal, así el test no muere por una constante inexistente). */
    const NUEVAS = [
        'cc_prueba_bienvenida_v4', 'cc_prueba_bienvenida_asistente_v4', 'cc_prueba_bienvenida_v5',
        'cc_prueba_bienvenida_asistente_v5', 'cc_prueba_paso_importo_v2',
    ];

    /**
     * Registra una plantilla como aprobada o pendiente.
     *
     * @param string $nombre
     * @param bool   $aprobada
     *
     * @return void
     */
    private function registrar(string $nombre, bool $aprobada): void
    {
        FollowupTemplate::query()->where('template_name', $nombre)->delete();
        FollowupTemplate::create([
            'template_name' => $nombre, 'estado' => PruebaPlantillasService::ESTADO, 'dia_numero' => 1,
            'body_template' => PruebaPlantillasService::cuerpo_de($nombre) !== '' ? PruebaPlantillasService::cuerpo_de($nombre) : 'Hola {{1}}', 'language_code' => 'es_AR',
            'activa' => $aprobada, 'meta_status' => $aprobada ? 'APPROVED' : 'PENDING', 'meta_category' => 'UTILITY', 'uso' => 'test',
            'solo_si_ingreso_confirmado' => false, 'no_marca_error_de_entrega' => false,
        ]);
    }

    /**
     * Un cliente en prueba dentro del alcance de las novedades y con dirección del sistema (así las v2 y v5 se pueden armar).
     *
     * @return \App\Models\Client
     */
    private function cliente_con_url()
    {
        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
        $client = $this->cliente_en_prueba();
        $client->email = 'alta@test.local';
        $client->save();
        $lead = $this->lead_en_prueba($client);
        $lead->demo_setup_status = 'exitoso';
        $lead->save();
        TrialInstance::create([
            'nombre' => 'prueba-' . Str::random(8), 'estado' => TrialInstance::ESTADO_ASIGNADA,
            'erp_vps_path' => '/home/prueba/erp', 'ecommerce_vps_path' => '/home/prueba/tienda',
            'client_id' => (int) $client->id, 'lead_id' => (int) $lead->id, 'user_id' => (int) $client->user_id,
            'alias_slug' => 'ferreteria-textos',
        ]);

        return $client->fresh();
    }

    /**
     * CA29. Con las v4 y v5 aprobadas, `bienvenida_vigente` devuelve la v5 (si corresponde la v2) y, si no, la v4; sin ninguna, el orden
     * de hoy.
     *
     * @return void
     */
    public function test_la_bienvenida_vigente_usa_v5_y_despues_v4(): void
    {
        $client = $this->cliente_con_url();
        $plantillas = app(PruebaPlantillasService::class);
        foreach (PruebaPlantillasService::todas() as $nombre => $definicion) {
            $this->registrar($nombre, true);
        }
        foreach (self::NUEVAS as $nombre) {
            $this->registrar($nombre, true);
        }

        $this->assertSame('cc_prueba_bienvenida_v5', $plantillas->bienvenida_vigente(false, $client));
        $this->assertSame('cc_prueba_bienvenida_asistente_v5', $plantillas->bienvenida_vigente(true, $client));

        foreach (['cc_prueba_bienvenida_v5', 'cc_prueba_bienvenida_asistente_v5'] as $nombre) {
            $this->registrar($nombre, false);
        }
        $this->assertSame('cc_prueba_bienvenida_v4', $plantillas->bienvenida_vigente(false, $client));
        $this->assertSame('cc_prueba_bienvenida_asistente_v4', $plantillas->bienvenida_vigente(true, $client));

        foreach (['cc_prueba_bienvenida_v4', 'cc_prueba_bienvenida_asistente_v4'] as $nombre) {
            $this->registrar($nombre, false);
        }
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_V2, $plantillas->bienvenida_vigente(false, $client), 'Sin v4 ni v5: el orden de hoy.');
    }

    /**
     * CA29. El paso `importo`: la plantilla es `cc_prueba_paso_importo_v2` (para todos, aun fuera del alcance de las novedades) y NUNCA
     * `cc_prueba_paso_importo`, que queda retirada.
     *
     * @return void
     */
    public function test_el_paso_importo_usa_la_v2_y_nunca_la_vieja(): void
    {
        $client = $this->cliente_con_url();
        $plantillas = app(PruebaPlantillasService::class);

        $this->assertSame('cc_prueba_paso_importo_v2', $plantillas->plantilla_del_paso_vigente('importo', $client));

        AdminSetting::set(PruebaSettings::KEY_NOVEDADES_ALCANCE, 'ensayo');
        $this->assertSame('cc_prueba_paso_importo_v2', $plantillas->plantilla_del_paso_vigente('importo', $client), 'Para todos, no sólo para las novedades.');
        $this->assertSame('cc_prueba_paso_importo_v2', $plantillas->plantilla_del_paso_vigente('importo'));

        $this->assertTrue(defined(PruebaPlantillasService::class . '::PLANTILLAS_RETIRADAS'), 'Falta PLANTILLAS_RETIRADAS.');
        $this->assertContains('cc_prueba_paso_importo', constant(PruebaPlantillasService::class . '::PLANTILLAS_RETIRADAS'));
    }

    /**
     * CA29. Los textos nuevos no prometen cargar gratis y tienen sus variables.
     *
     * @return void
     */
    public function test_los_textos_nuevos_no_prometen_carga_gratis(): void
    {
        foreach (self::NUEVAS as $nombre) {
            $cuerpo = PruebaPlantillasService::cuerpo_de($nombre);
            $this->assertNotSame('', $cuerpo, 'Falta el texto de ' . $nombre . '.');
            $this->assertSame(0, preg_match('/sin costo|te cargamos|carguemos/i', $cuerpo), $nombre . ' no puede prometer cargar sin costo.');
            $this->assertStringContainsString('{{1}}', $cuerpo);
            $this->assertStringContainsString('video', $cuerpo, $nombre . ' tiene que decir que la carga la hace el negocio con un video.');
        }

        $this->assertStringContainsString('{{2}}', PruebaPlantillasService::cuerpo_de('cc_prueba_bienvenida_asistente_v4'), 'El número del asistente.');
        $this->assertStringContainsString('{{2}}', PruebaPlantillasService::cuerpo_de('cc_prueba_bienvenida_v5'), 'La dirección del sistema.');
        $this->assertStringContainsString('{{3}}', PruebaPlantillasService::cuerpo_de('cc_prueba_bienvenida_asistente_v5'));
        $this->assertStringNotContainsString('{{2}}', PruebaPlantillasService::cuerpo_de('cc_prueba_bienvenida_v4'));
    }

    /**
     * CA29. El correo del paso `importo`.
     *
     * @return void
     */
    public function test_el_correo_del_paso_importo(): void
    {
        $paso = PruebaCorreoService::PASOS['importo'];

        $this->assertSame('Subí tus artículos a tu prueba', $paso['asunto']);
        $this->assertSame(0, preg_match('/sin costo|te cargamos|carguemos/i', $paso['asunto'] . ' ' . $paso['texto']));
        $this->assertStringContainsString('video', $paso['texto']);
    }
}
