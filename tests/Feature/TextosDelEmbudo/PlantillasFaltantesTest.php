<?php

namespace Tests\Feature\TextosDelEmbudo;

use App\Models\AdminSetting;
use App\Models\FollowupTemplate;
use App\Services\MetaTemplateService;
use App\Services\PruebaPlantillasService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * CA33 de la etapa E3 (misión embudo-juan-pipeline): las plantillas que faltaban (embudo día 3, prueba vencida días 8, 14 y 30, recordatorio
 * de archivos) y las nuevas de la prueba quedan escritas y registradas para `prueba:crear-plantillas`, sin crearse solas en Meta.
 *
 *  - `--crear` con Meta simulada crea las 5 de `PLANTILLAS_EMBUDO` (categoría MARKETING, estado `manual_embudo`), el recordatorio de
 *    archivos (UTILITY) y las nuevas de la prueba (v4, v5 e importo v2).
 *  - Correrlo otra vez no crea nada.
 *  - Las claves `lead_embudo_plantilla_*` y `prueba_vencida_plantilla_dia_*` siguen vacías: se cargan a mano cuando Meta aprueba.
 *
 * Hoy están EN ROJO: `PLANTILLAS_EMBUDO` y las nuevas no existen.
 */
class PlantillasFaltantesTest extends TestCase
{
    use DatabaseTransactions;

    /** Las 5 plantillas del embudo. */
    const DEL_EMBUDO = [
        'cc_embudo_conversando_dia_3', 'cc_embudo_link_dia_3', 'cc_prueba_vencida_dia_8', 'cc_prueba_vencida_dia_14', 'cc_prueba_vencida_dia_30',
    ];

    /** Las nuevas de la prueba y el recordatorio. */
    const OTRAS = [
        'cc_impl_archivos_recordatorio', 'cc_prueba_bienvenida_v4', 'cc_prueba_bienvenida_asistente_v4', 'cc_prueba_bienvenida_v5',
        'cc_prueba_bienvenida_asistente_v5', 'cc_prueba_paso_importo_v2',
    ];

    /** @var object Meta simulada: guarda lo que se le pidió crear. */
    private $meta;

    /**
     * Meta simulada, sin ninguna de las plantillas registradas.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        FollowupTemplate::query()->whereIn('template_name', array_merge(self::DEL_EMBUDO, self::OTRAS))->delete();

        $this->meta = new class extends MetaTemplateService {
            /** @var array<int, array<string, mixed>> */
            public $creadas = [];

            public function __construct()
            {
            }

            public function crear(array $plantilla): array
            {
                $this->creadas[] = $plantilla;

                return ['ok' => true, 'motivo' => null, 'mensaje' => null, 'datos' => ['id' => 'meta-' . count($this->creadas), 'status' => 'PENDING']];
            }
        };
        $this->app->instance(MetaTemplateService::class, $this->meta);
    }

    /**
     * @return void
     */
    public function test_crear_registra_las_faltantes_y_las_nuevas_y_no_repite(): void
    {
        $this->assertSame(0, Artisan::call('prueba:crear-plantillas', ['--crear' => true]));

        $creadas = array_column($this->meta->creadas, 'category', 'name');
        foreach (self::DEL_EMBUDO as $nombre) {
            $this->assertArrayHasKey($nombre, $creadas, 'Se crea ' . $nombre . '.');
            $this->assertSame('MARKETING', $creadas[$nombre], $nombre . ' es de marketing.');
            $fila = FollowupTemplate::query()->where('template_name', $nombre)->first();
            $this->assertNotNull($fila, 'Queda registrada ' . $nombre . '.');
            $this->assertSame('manual_embudo', (string) $fila->estado, 'El prefijo manual_ la aleja del cron viejo.');
            $this->assertFalse((bool) $fila->activa, 'Nace inactiva: la enciende el sync cuando Meta aprueba.');
            $this->assertSame(0, preg_match('/sin costo|te cargamos|carguemos/i', (string) $fila->body_template));
        }
        foreach (self::OTRAS as $nombre) {
            $this->assertArrayHasKey($nombre, $creadas, 'Se crea ' . $nombre . '.');
        }
        $this->assertSame('UTILITY', $creadas['cc_impl_archivos_recordatorio']);

        $cantidad = count($this->meta->creadas);
        $this->assertSame(0, Artisan::call('prueba:crear-plantillas', ['--crear' => true]));
        $this->assertCount($cantidad, $this->meta->creadas, 'Correrlo otra vez no crea nada.');
    }

    /**
     * Las claves que dicen QUÉ plantilla usa cada paso no se escriben solas.
     *
     * @return void
     */
    public function test_las_claves_de_las_plantillas_siguen_vacias(): void
    {
        $claves = [
            'lead_embudo_plantilla_conversando_dia_3', 'lead_embudo_plantilla_link_dia_3',
            'prueba_vencida_plantilla_dia_8', 'prueba_vencida_plantilla_dia_14', 'prueba_vencida_plantilla_dia_30',
        ];
        foreach ($claves as $clave) {
            AdminSetting::query()->where('key', $clave)->delete();
        }

        Artisan::call('prueba:crear-plantillas', ['--crear' => true]);

        foreach ($claves as $clave) {
            $this->assertSame('', trim((string) AdminSetting::get($clave, '')), $clave . ' queda vacía.');
        }
    }

    /**
     * Los textos de las faltantes son los del plan, sin carga gratis.
     *
     * @return void
     */
    public function test_los_textos_del_plan(): void
    {
        $this->assertStringContainsString('podés probar el sistema gratis 7 días', PruebaPlantillasService::cuerpo_de('cc_embudo_conversando_dia_3'));
        $this->assertStringContainsString('¿Pudiste crear tu prueba?', PruebaPlantillasService::cuerpo_de('cc_embudo_link_dia_3'));
        $this->assertStringContainsString('Tu prueba terminó', PruebaPlantillasService::cuerpo_de('cc_prueba_vencida_dia_8'));
        $this->assertStringContainsString('se borra la información de tu prueba', PruebaPlantillasService::cuerpo_de('cc_prueba_vencida_dia_30'));
    }
}
