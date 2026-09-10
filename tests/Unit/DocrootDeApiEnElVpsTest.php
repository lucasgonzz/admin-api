<?php

namespace Tests\Unit;

use App\Services\VpsSiteProvisioner;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El docroot de una API en el VPS tiene que quedar siendo un SYMLINK a `empresa-api/public`.
 *
 * Medido el 10/9/2026 instalando `pescamayorista`: esa etapa **fallaba siempre**, no en un caso de
 * borde, y por dos motivos que se tapaban entre sí.
 *
 *  1. CloudPanel no deja el docroot vacío: le pone un `index.php` que dice "Hello World". El
 *     `rmdir` que tiene que sacar el directorio antes del symlink no podía funcionar nunca.
 *  2. El `ln` iba con `-n` y un comentario que aseguraba que así GNU `ln` se niega a escribir
 *     adentro de un directorio. Es falso: `-n` (`--no-dereference`) sólo cambia el trato de un
 *     SYMLINK a directorio. Contra un directorio real, `ln -sfn destino dir` crea el enlace
 *     ADENTRO —quedó `htdocs/api-pescamayorista.comerciocity.com/public`— y sale con 0.
 *
 * El resultado era un sitio con el docroot sirviendo el "Hello World" de CloudPanel en vez del
 * front controller de Laravel, y la etapa cortando con un mensaje que culpaba al contenido previo.
 *
 * Lo que fijan estos tests es lo que se pierde fácil: la bandera correcta y, sobre todo, que el
 * borrado siga siendo una **lista cerrada**. La tentación al ver el `rmdir` fallando es poner
 * `rm -rf`, y eso, en un reintento sobre un cliente que está sirviendo producción, le borra el
 * docroot.
 */
class DocrootDeApiEnElVpsTest extends TestCase
{
    /**
     * Código fuente de un método de la clase.
     *
     * Se mide sobre la fuente porque lo que hay que fijar son los comandos que se le mandan al VPS,
     * y no hay forma de observarlos sin un servidor del otro lado.
     *
     * @param string $metodo Nombre del método.
     *
     * @return string
     */
    private function fuente_de(string $metodo): string
    {
        $reflection = new ReflectionMethod(VpsSiteProvisioner::class, $metodo);
        $lineas     = file($reflection->getFileName());

        return implode('', array_slice(
            $lineas,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    /**
     * 🔴 `ln -T`: la única bandera que se niega a escribir adentro de un directorio real.
     *
     * @return void
     */
    public function test_el_symlink_del_docroot_va_con_no_target_directory()
    {
        $fuente = $this->fuente_de('enlazar_docroot_de_api');

        $this->assertStringContainsString(
            'ln -sfnT ',
            $fuente,
            'El ln del docroot perdió el -T: sin esa bandera, contra un directorio real el enlace '
            . 'se crea ADENTRO y el sitio queda sirviendo el placeholder de CloudPanel.'
        );
    }

    /**
     * 🔴 El symlink del docroot tiene que quedar A NOMBRE DEL USUARIO DEL SITIO, no de root.
     *
     * nginx corre con `disable_symlinks if_not_owner from=/home/` (línea 51 de su nginx.conf): se
     * niega a seguir un enlace cuyo dueño no coincide con el del directorio que lo contiene. Y el
     * aprovisionamiento entra al VPS por SSH COMO ROOT, así que sin un `chown -h` el enlace nace
     * `root:root` adentro de un `htdocs` del usuario del sitio.
     *
     * El síntoma no se parece a la causa: nginx abre con O_NOFOLLOW, el kernel devuelve ELOOP y el
     * log dice *"Too many levels of symbolic links"*, que suena a un enlace circular. Hacia afuera
     * es un 403 en todo, y lo primero que se cae es el desafío HTTP-01 de Let's Encrypt.
     *
     * Medido el 10/9/2026: los symlinks de golonorte, fenix y ferretotal son del usuario del sitio;
     * los dos únicos `root:root` del VPS eran los que había creado este método.
     *
     * @return void
     */
    public function test_el_symlink_del_docroot_queda_a_nombre_del_usuario_del_sitio()
    {
        $fuente = $this->fuente_de('enlazar_docroot_de_api');

        $this->assertStringContainsString(
            'chown -h ',
            $fuente,
            'Se perdió el chown del symlink del docroot: nginx no lo va a seguir y el sitio '
            . 'responde 403 en todo.'
        );

        /* 🔴 Sin -h se le cambia el dueño al DESTINO (el public/ del cliente) y no al enlace, que
           es lo que nginx mira. El bug quedaría igual y encima tocando otra cosa. */
        $this->assertStringNotContainsString(
            'chown ' . $this->escapado('api-'),
            $fuente,
            'Hay un chown sin -h: eso le cambia el dueño al destino, no al symlink.'
        );

        $chown  = strpos($fuente, 'chown -h ');
        $enlace = strpos($fuente, 'ln -sfnT ');

        $this->assertNotFalse($enlace);
        $this->assertLessThan($chown, $enlace, 'El chown tiene que ir DESPUÉS de crear el enlace.');

        /* Y se verifica de verdad contra el servidor, no se asume. */
        $this->assertStringContainsString('stat -c', $fuente);
        $this->assertStringContainsString('disable_symlinks', $fuente);
    }

    /**
     * Ayuda para el aserto de arriba: no existe un helper de escapado en el test, y lo que importa
     * es que no aparezca un `chown` pelado seguido del usuario.
     *
     * @param string $texto
     *
     * @return string
     */
    private function escapado(string $texto): string
    {
        return "'" . $texto;
    }

    /**
     * 🔴 `rmdir` y nunca `rm -rf`: es lo que protege al docroot de un cliente en producción.
     *
     * @return void
     */
    public function test_el_docroot_nunca_se_borra_recursivamente()
    {
        foreach (['enlazar_docroot_de_api', 'vaciar_docroot_recien_creado'] as $metodo) {
            $fuente = $this->fuente_de($metodo);

            $this->assertStringNotContainsString(
                'rm -rf',
                $fuente,
                $metodo . '() usa rm -rf sobre el docroot: en un reintento sobre un cliente que '
                . 'está sirviendo producción, eso lo deja caído.'
            );
        }

        $this->assertStringContainsString('rmdir ', $this->fuente_de('enlazar_docroot_de_api'));
    }

    /**
     * 🔴 El placeholder se reconoce por su CONTENIDO, no por llamarse `index.php`.
     *
     * Un archivo llamado `index.php` en el docroot de un cliente es su front controller. El único
     * modo seguro de distinguirlo del "Hello World" del panel es mirar qué dice adentro.
     *
     * @return void
     */
    public function test_el_placeholder_se_reconoce_por_md5_y_no_por_nombre()
    {
        $reflection = new ReflectionClass(VpsSiteProvisioner::class);

        $this->assertSame(
            md5("<?php\n\necho 'Hello World :-)';\n"),
            $reflection->getConstant('MD5_PLACEHOLDER_DE_CLOUDPANEL'),
            'La constante no coincide con el index.php que CloudPanel deja de verdad (medido el '
            . '10/9/2026 en los cuatro sitios de pescamayorista).'
        );

        $fuente = $this->fuente_de('vaciar_docroot_recien_creado');

        $this->assertStringContainsString('md5sum ', $fuente);
        $this->assertStringContainsString('MD5_PLACEHOLDER_DE_CLOUDPANEL', $fuente);
    }

    /**
     * 🔴 Lo que no se reconoce NO se borra: el método se va sin tocar nada.
     *
     * @return void
     */
    public function test_lo_desconocido_frena_el_borrado()
    {
        $fuente = $this->fuente_de('vaciar_docroot_recien_creado');

        $desconocido = strpos($fuente, 'no reconozco');
        $borrado     = strpos($fuente, 'rm -f ');

        $this->assertNotFalse(
            $desconocido,
            'Se perdió la rama que denuncia contenido no reconocido en el docroot.'
        );
        $this->assertNotFalse($borrado, 'El método ya no borra el placeholder.');
        $this->assertLessThan(
            $borrado,
            $desconocido,
            'La salida por contenido no reconocido tiene que estar ANTES del borrado: si queda '
            . 'después, se borra primero y se avisa al pedo.'
        );

        $this->assertStringContainsString(
            'return;',
            substr($fuente, $desconocido, 400),
            'Cuando aparece algo que no se reconoce, el método tiene que volverse sin borrar nada.'
        );
    }

    /**
     * Un docroot que YA es el symlink no se toca: es un reintento sobre un sitio listo.
     *
     * @return void
     */
    public function test_un_docroot_ya_enlazado_se_deja_quieto()
    {
        $fuente = $this->fuente_de('vaciar_docroot_recien_creado');

        $readlink = strpos($fuente, 'readlink ');
        $listado  = strpos($fuente, 'ls -A ');

        $this->assertNotFalse($readlink);
        $this->assertNotFalse($listado);
        $this->assertLessThan(
            $listado,
            $readlink,
            'La guarda del symlink existente tiene que correr ANTES de listar el contenido.'
        );
    }
}
