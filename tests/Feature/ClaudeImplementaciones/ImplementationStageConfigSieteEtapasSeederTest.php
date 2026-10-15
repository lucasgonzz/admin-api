<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\ImplementationStageConfig;
use Database\Seeders\ImplementationStageConfigSeeder;
use Database\Seeders\ImplementationStageConfigSieteEtapasSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El catálogo de etapas de implementación en siete (misión `implementacion-7-etapas`, 15/10/2026):
 *
 *  - `ImplementationStageConfigSeeder` (el de `DatabaseSeeder`, para una base nueva) siembra SIETE etapas: la 6 es
 *    «Catálogo: categorías, imágenes y descripciones» y la 8 ya no se siembra.
 *  - `ImplementationStageConfigSieteEtapasSeeder` (el suelto, para producción) lleva una base que ya tiene las OCHO
 *    al esquema nuevo SIN borrar nada: renombra la 6 y deja la 8 inactiva. Es idempotente.
 *
 * `UpdateImplementationStageConfigsSeeder` queda sin probar a propósito: hace `TRUNCATE`, que en MySQL cierra la
 * transacción de `DatabaseTransactions` (implicit commit) y dejaría la tabla tocada para el resto de la suite.
 */
class ImplementationStageConfigSieteEtapasSeederTest extends TestCase
{
    use DatabaseTransactions;

    /** Nombre nuevo de la etapa 6. */
    const NOMBRE_DE_LA_6 = 'Catálogo: categorías, imágenes y descripciones';

    /** Descripción nueva de la etapa 6. */
    const DESCRIPCION_DE_LA_6 = 'Se arman las categorías y se completan las fotos y descripciones de los artículos que todavía no las tienen.';

    /**
     * Deja el catálogo como está HOY en producción: las ocho etapas con sus nombres de siempre (la 6 «Capacitación»
     * y la 8 «Videollamada de capacitación»), todas activas.
     *
     * @return void
     */
    private function sembrar_el_catalogo_de_ocho(): void
    {
        ImplementationStageConfig::query()->delete();

        $nombres = [
            1 => 'Información de la empresa',
            2 => 'Instalación del sistema',
            3 => 'Recolección de archivos',
            4 => 'Migración de datos',
            5 => 'Entrega del sistema',
            6 => 'Capacitación',
            7 => 'Vinculación ARCA/AFIP',
            8 => 'Videollamada de capacitación',
        ];

        foreach ($nombres as $numero => $nombre) {
            ImplementationStageConfig::create([
                'stage_number'          => $numero,
                'name'                  => $nombre,
                'description'           => 'Descripción vieja de la etapa ' . $numero,
                'alert_threshold_hours' => 24.00,
                'is_automated'          => $numero === 2,
                'active'                => true,
            ]);
        }
    }

    /**
     * El catálogo tal cual está en la base, como un arreglo comparable (por número de etapa).
     *
     * @return array<int, array<string, mixed>>
     */
    private function foto_del_catalogo(): array
    {
        $foto = [];

        foreach (ImplementationStageConfig::query()->orderBy('stage_number')->get() as $config) {
            $foto[(int) $config->stage_number] = $config->getAttributes();
        }

        return $foto;
    }

    /**
     * El seeder de una base nueva siembra siete etapas, con la 6 nueva y sin la 8.
     *
     * @return void
     */
    public function test_el_seeder_general_siembra_siete_etapas_sin_la_8(): void
    {
        ImplementationStageConfig::query()->delete();

        $this->seed(ImplementationStageConfigSeeder::class);

        $configs = ImplementationStageConfig::query()->orderBy('stage_number')->get();

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $configs->pluck('stage_number')->map(function ($n) {
            return (int) $n;
        })->all());
        $this->assertSame(self::NOMBRE_DE_LA_6, $configs[5]->name);
        $this->assertSame(self::DESCRIPCION_DE_LA_6, $configs[5]->description);
        $this->assertSame('Vinculación ARCA/AFIP', $configs[6]->name);
        $this->assertSame(0, ImplementationStageConfig::where('stage_number', 8)->count(), 'La 8 dejó de sembrarse.');
        $this->assertSame(0, ImplementationStageConfig::where('name', 'Capacitación')->count());
    }

    /**
     * El seeder suelto, sobre la base de producción de ocho etapas: la 6 se renombra y se redescribe, la 8 queda inactiva y NO
     * se borra, y el resto del catálogo no se toca.
     *
     * @return void
     */
    public function test_el_seeder_suelto_renombra_la_6_y_desactiva_la_8_sin_borrarla(): void
    {
        $this->sembrar_el_catalogo_de_ocho();
        $antes = $this->foto_del_catalogo();

        $this->seed(ImplementationStageConfigSieteEtapasSeeder::class);

        $despues = $this->foto_del_catalogo();

        $this->assertCount(8, $despues, 'No se borra ninguna config.');

        $this->assertSame(self::NOMBRE_DE_LA_6, $despues[6]['name']);
        $this->assertSame(self::DESCRIPCION_DE_LA_6, $despues[6]['description']);
        $this->assertSame(1, (int) $despues[6]['active'], 'La 6 sigue activa.');

        $this->assertSame(0, (int) $despues[8]['active'], 'La 8 queda inactiva.');
        $this->assertSame('Videollamada de capacitación', $despues[8]['name'], 'La 8 conserva su nombre: la API se lo sigue pidiendo para las implementaciones viejas.');

        foreach ([1, 2, 3, 4, 5, 7] as $numero) {
            $this->assertSame($antes[$numero], $despues[$numero], 'La etapa ' . $numero . ' no se tocó.');
        }
    }

    /**
     * Idempotente: la segunda corrida no escribe nada (ni siquiera `updated_at`).
     *
     * @return void
     */
    public function test_el_seeder_suelto_es_idempotente(): void
    {
        $this->sembrar_el_catalogo_de_ocho();

        $this->seed(ImplementationStageConfigSieteEtapasSeeder::class);
        $despues_de_la_primera = $this->foto_del_catalogo();

        $this->seed(ImplementationStageConfigSieteEtapasSeeder::class);

        $this->assertSame($despues_de_la_primera, $this->foto_del_catalogo());
    }

    /**
     * Sobre una base sin la etapa 6 la crea con el nombre nuevo; sobre una sin la 8 no inventa una config que ya no existe en el
     * flujo.
     *
     * @return void
     */
    public function test_sin_la_6_la_crea_y_sin_la_8_no_la_crea(): void
    {
        $this->sembrar_el_catalogo_de_ocho();
        ImplementationStageConfig::whereIn('stage_number', [6, 8])->delete();

        $this->seed(ImplementationStageConfigSieteEtapasSeeder::class);

        $seis = ImplementationStageConfig::where('stage_number', 6)->first();
        $this->assertNotNull($seis);
        $this->assertSame(self::NOMBRE_DE_LA_6, $seis->name);
        $this->assertTrue((bool) $seis->active);

        $this->assertSame(0, ImplementationStageConfig::where('stage_number', 8)->count(), 'No se crea una config de la etapa 8.');
        $this->assertSame(7, ImplementationStageConfig::count());
    }

    /**
     * Sobre una base ya corregida por el seeder general (siete etapas, sin la 8) no hace nada.
     *
     * @return void
     */
    public function test_sobre_una_base_ya_de_siete_etapas_no_cambia_nada(): void
    {
        ImplementationStageConfig::query()->delete();
        $this->seed(ImplementationStageConfigSeeder::class);
        $antes = $this->foto_del_catalogo();

        $this->seed(ImplementationStageConfigSieteEtapasSeeder::class);

        $this->assertSame($antes, $this->foto_del_catalogo());
    }
}
