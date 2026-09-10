<?php

namespace Tests\Unit;

use App\Services\Concerns\ArtefactosDeRelease;
use ReflectionClass;
use Tests\TestCase;
use ZipArchive;

/**
 * El `public/` de una instalación de cero sale del ZIPBALL DEL TAG, no de un checkout en el VPS.
 *
 * Es la pieza que la misión `actualizar-sin-el-vps` (9/9/2026) no necesitaba y ésta sí: el asset
 * `empresa-api-v{v}.zip` del release excluye `public/` a propósito, porque en un UPGRADE esos
 * archivos son del cliente. En una INSTALACIÓN de cero nadie más los pone, y sin
 * `public/index.php` el sistema responde 404 en todo — la lección que costó `elkioscoverde2`.
 *
 * Lo que fijan estos tests es el re-empaquetado, que es donde se puede romper en silencio:
 *
 *  - el zipball de GitHub trae TODO el repo bajo una carpeta raíz con un sha en el nombre
 *    (`lucasgonzz-empresa-api-a1b2c3d/`), y lo que se sube al hosting tiene que tener `public/`
 *    en la RAÍZ del zip: un nivel de más y `unzip` lo deja en un directorio que nadie sirve;
 *  - `public/storage` NO viaja. En el repo es un symlink; empaquetado llega roto y apunta a una
 *    ruta que en el hosting del cliente no existe. El bueno lo crea `artisan storage:link` en
 *    finalize;
 *  - y si el zipball no trajera un solo archivo de `public/`, eso TIENE que cortar la etapa. Un
 *    zip vacío que se sube tranquilo es exactamente el 404 en todo, otra vez.
 */
class PublicDelTagSaleDelZipballTest extends TestCase
{
    /** Carpeta raíz que GitHub le pone al zipball de un tag. */
    const RAIZ = 'lucasgonzz-empresa-api-a1b2c3d/';

    /**
     * Instancia anónima que usa el trait, para poder invocar sus métodos privados.
     *
     * El trait declara `artefacto_log()` abstracto, así que la clase de prueba lo implementa
     * guardando las líneas: además de hacer instanciable la clase, deja verlas en el test.
     *
     * @return object
     */
    private function sujeto()
    {
        return new class {
            use ArtefactosDeRelease;

            /** @var array<int, string> */
            public $lineas = [];

            /**
             * @param string $step
             * @param string $linea
             * @param string $nivel
             *
             * @return void
             */
            protected function artefacto_log(string $step, string $linea, string $nivel = 'info'): void
            {
                $this->lineas[] = '[' . $step . '] ' . $linea;
            }
        };
    }

    /**
     * Invoca un método privado del trait sobre esa instancia.
     *
     * @param object       $sujeto Instancia con el trait.
     * @param string       $metodo Nombre del método.
     * @param array<mixed> $args   Argumentos.
     *
     * @return mixed
     */
    private function invocar($sujeto, string $metodo, array $args = [])
    {
        $method = (new ReflectionClass($sujeto))->getMethod($metodo);
        $method->setAccessible(true);

        return $method->invokeArgs($sujeto, $args);
    }

    /**
     * Arma un zipball de mentira con la misma forma que el de GitHub.
     *
     * @param array<string, string> $entradas Ruta relativa a la raíz => contenido.
     *
     * @return string Ruta del zip creado.
     */
    private function zipball_de_mentira(array $entradas): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'zipball_') . '.zip';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entradas as $relativa => $contenido) {
            $zip->addFromString(self::RAIZ . $relativa, $contenido);
        }
        $zip->close();

        return $ruta;
    }

    /**
     * Lista las entradas de un zip.
     *
     * @param string $ruta Ruta del zip.
     *
     * @return array<int, string>
     */
    private function entradas_de(string $ruta): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($ruta) === true, "No se pudo abrir el zip {$ruta}.");

        $entradas = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entradas[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        return $entradas;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // El filtro de rutas, solo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 La carpeta raíz con el sha se descarta y `public/` queda en la raíz del zip de salida.
     *
     * @return void
     */
    public function test_saca_la_carpeta_raiz_del_zipball()
    {
        $sujeto = $this->sujeto();

        $this->assertSame(
            'public/index.php',
            $this->invocar($sujeto, 'artefacto_ruta_public', [self::RAIZ . 'public/index.php'])
        );
    }

    /**
     * Lo que no es de `public/` no viaja: el código de la API lo trae su propio asset.
     *
     * @return void
     */
    public function test_descarta_todo_lo_que_no_sea_public()
    {
        $sujeto = $this->sujeto();

        $fuera = [
            self::RAIZ . 'app/Models/Article.php',
            self::RAIZ . 'composer.json',
            self::RAIZ . '.env.example',
            self::RAIZ . 'public/',
            self::RAIZ,
            'sin-barra',
        ];

        foreach ($fuera as $nombre) {
            $this->assertNull(
                $this->invocar($sujeto, 'artefacto_ruta_public', [$nombre]),
                'Se coló en el zip de public/ una entrada que no corresponde: ' . $nombre
            );
        }
    }

    /**
     * 🔴 `public/storage` es un symlink en el repo: empaquetado llega roto.
     *
     * @return void
     */
    public function test_descarta_public_storage()
    {
        $sujeto = $this->sujeto();

        $this->assertNull($this->invocar($sujeto, 'artefacto_ruta_public', [self::RAIZ . 'public/storage']));
        $this->assertNull(
            $this->invocar($sujeto, 'artefacto_ruta_public', [self::RAIZ . 'public/storage/fotos/1.jpg'])
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // El re-empaquetado completo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 El test que importa: entra un zipball del repo entero, sale un zip con `public/` en la raíz.
     *
     * @return void
     */
    public function test_el_zip_de_salida_trae_public_en_la_raiz_y_nada_mas()
    {
        $sujeto  = $this->sujeto();
        $zipball = $this->zipball_de_mentira([
            'public/index.php'          => '<?php // front controller',
            'public/.htaccess'          => 'RewriteEngine On',
            'public/afip/logo.png'      => 'PNG',
            'public/storage'            => '../storage/app/public',
            'public/storage/fotos/1.jpg' => 'JPG',
            'app/Models/Article.php'    => '<?php class Article {}',
            'artisan'                   => '<?php // artisan',
            '.env.example'              => 'APP_KEY=',
        ]);
        $destino = tempnam(sys_get_temp_dir(), 'public_') . '.zip';

        $copiadas = $this->invocar($sujeto, 'artefacto_reempaquetar_public', [$zipball, $destino]);

        $entradas = $this->entradas_de($destino);
        sort($entradas);

        $this->assertSame(
            ['public/.htaccess', 'public/afip/logo.png', 'public/index.php'],
            $entradas,
            'El zip de public/ no trae exactamente los archivos de public/ (sin storage) en la raíz.'
        );
        $this->assertSame(3, $copiadas);

        @unlink($zipball);
        @unlink($destino);
    }

    /**
     * 🔴 Un zipball sin `public/` corta la etapa, y no deja un zip vacío listo para subir.
     *
     * Un zip vacío que se sube tranquilo es el 404 en todo, que es exactamente lo que esta pieza
     * vino a evitar.
     *
     * @return void
     */
    public function test_un_zipball_sin_public_corta_y_no_deja_zip()
    {
        $sujeto  = $this->sujeto();
        $zipball = $this->zipball_de_mentira([
            'app/Models/Article.php' => '<?php class Article {}',
            'artisan'                => '<?php // artisan',
        ]);
        $destino = tempnam(sys_get_temp_dir(), 'public_') . '.zip';

        $mensaje = '';
        try {
            $this->invocar($sujeto, 'artefacto_reempaquetar_public', [$zipball, $destino]);
            $this->fail('El re-empaquetado no cortó con un zipball que no trae public/.');
        } catch (\RuntimeException $e) {
            $mensaje = $e->getMessage();
        }

        $this->assertStringContainsString('public/', $mensaje);
        $this->assertStringContainsString('404', $mensaje);
        $this->assertFileDoesNotExist($destino, 'Quedó un zip de public/ vacío listo para subirse.');

        @unlink($zipball);
    }
}
