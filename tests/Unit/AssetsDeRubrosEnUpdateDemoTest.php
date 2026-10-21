<?php

namespace Tests\Unit;

use App\Services\DemoUpdateService;
use Tests\TestCase;

class AssetsDeRubrosEnUpdateDemoTest extends TestCase
{
    public function test_distribuye_el_rubro_y_preserva_archivos_publicos_y_privados(): void
    {
        $archivo = tempnam(sys_get_temp_dir(), 'demo-rubros-');
        try {
            $zip = new \ZipArchive();
            $zip->open($archivo, \ZipArchive::OVERWRITE);
            foreach (['artisan', 'vendor/autoload.php', 'database/seeders/data/demo_industrias_assets.json', 'public/demo-industrias/ferreteria/logo.webp', 'public/demo-industrias/ferreteria/martillo.webp', 'public/index.php', 'public/logo.png', 'public/storage/foto.webp', 'storage/app/afip/certificado'] as $ruta) {
                $zip->addFromString($ruta, $ruta);
            }
            $zip->close();
            $this->preparar($archivo);
            $zip->open($archivo);

            foreach (['artisan', 'vendor/autoload.php', 'database/seeders/data/demo_industrias_assets.json', 'public/demo-industrias/ferreteria/logo.webp', 'public/demo-industrias/ferreteria/martillo.webp'] as $ruta) {
                $this->assertSame($ruta, $zip->getFromName($ruta));
            }
            foreach (['public/index.php', 'public/logo.png', 'public/storage/foto.webp', 'storage/app/afip/certificado'] as $ruta) {
                $this->assertFalse($zip->getFromName($ruta), 'El update no puede pisar ' . $ruta);
            }
            $zip->close();
        } finally {
            unlink($archivo);
        }
    }

    public function test_un_release_anterior_sin_assets_no_borra_los_existentes(): void
    {
        $archivo = tempnam(sys_get_temp_dir(), 'demo-anterior-');
        try {
            $zip = new \ZipArchive();
            $zip->open($archivo, \ZipArchive::OVERWRITE);
            $zip->addFromString('artisan', 'código anterior');
            $zip->close();
            $this->preparar($archivo);
            $zip->open($archivo);
            $this->assertSame('código anterior', $zip->getFromName('artisan'));
            $this->assertSame(1, $zip->numFiles);
            $zip->close();
        } finally {
            unlink($archivo);
        }
    }

    private function preparar(string $archivo): void
    {
        $clase = new \ReflectionClass(DemoUpdateService::class);
        $servicio = $clase->newInstanceWithoutConstructor();
        $metodo = $clase->getMethod('preparar_zip_de_actualizacion_demo');
        $metodo->setAccessible(true);
        $metodo->invoke($servicio, $archivo);
    }
}
