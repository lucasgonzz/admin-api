<?php

namespace Tests\Feature\ImplementacionMail;

use App\Exceptions\ImplementacionMailException;
use App\Mail\ImplementacionMail;
use App\Services\ImplementacionMailService;
use Illuminate\Support\Facades\Mail;

/**
 * La validación de los datos de cada hito: tipos, máximos, obligatorios y la lista cerrada de claves.
 *
 * `validar_datos()` es lo que llama el endpoint antes de armar nada; `previa()` y `enviar()` repiten
 * la validación por su cuenta y tiran `faltan_datos` si algo no sirve.
 */
class ValidacionDeDatosTest extends BaseDelMailDeImplementacion
{
    /**
     * Texto de `$largo` caracteres.
     *
     * @param int $largo
     *
     * @return string
     */
    private function texto(int $largo): string
    {
        return str_repeat('a', $largo);
    }

    /**
     * Errores de un hito.
     *
     * @param string               $hito
     * @param array<string, mixed> $datos
     *
     * @return array<string, string>
     */
    private function errores(string $hito, array $datos): array
    {
        return ImplementacionMailService::validar_datos($hito, $datos);
    }

    /**
     * Los datos de ejemplo de cada hito pasan.
     *
     * @return void
     */
    public function test_los_datos_de_ejemplo_de_cada_hito_pasan()
    {
        foreach (ImplementacionMailService::HITOS as $hito) {
            $this->assertSame([], $this->errores($hito, $this->datos_de_ejemplo($hito)), $hito);
        }
    }

    /**
     * Un hito que no existe es un error con la lista de los que sí.
     *
     * @return void
     */
    public function test_un_hito_inexistente_es_un_error()
    {
        $errores = $this->errores('despedida', []);

        $this->assertSame(['hito'], array_keys($errores));
        $this->assertStringContainsString('bienvenida, instalado, acceso, imagenes, categorias, listo', $errores['hito']);
    }

    /**
     * Bienvenida e instalado no llevan datos propios: cualquier clave que no sea `nota` es un error,
     * y se dice que solo acepta `nota`.
     *
     * @return void
     */
    public function test_bienvenida_e_instalado_no_aceptan_otros_datos()
    {
        foreach (['bienvenida', 'instalado'] as $hito) {
            $this->assertSame([], $this->errores($hito, []), $hito);
            $this->assertSame([], $this->errores($hito, ['nota' => 'Hola']), $hito);

            $errores = $this->errores($hito, ['articulos' => 5]);

            $this->assertSame(['articulos'], array_keys($errores), $hito);
            $this->assertStringContainsString('Solo acepta `nota`', $errores['articulos'], $hito);
        }
    }

    /**
     * Una clave que el hito no conoce es un error en todos los hitos, aunque sea de otro hito.
     * Una lista cerrada: un nombre mal escrito no se ignora en silencio.
     *
     * @return void
     */
    public function test_una_clave_desconocida_es_un_error()
    {
        $errores = $this->errores('acceso', ['articulos' => 10, 'total' => 20, 'articulo' => 3]);

        $this->assertSame(['total', 'articulo'], array_keys($errores));
        $this->assertStringContainsString('articulos', $errores['total'], 'Dice cuáles sí acepta.');

        $errores = $this->errores('imagenes', ['con_foto' => 1, 'total' => 2, 'a_revisa' => 1]);
        $this->assertSame(['a_revisa'], array_keys($errores), 'Un opcional mal escrito se avisa, no se pierde.');
    }

    /**
     * La nota acepta hasta 600 caracteres en todos los hitos; con 601 es un error. No texto, error.
     * Vacía o nula, como si no estuviera.
     *
     * @return void
     */
    public function test_la_nota_admite_hasta_600_caracteres()
    {
        foreach (ImplementacionMailService::HITOS as $hito) {
            $base = $this->datos_de_ejemplo($hito);

            $this->assertSame([], $this->errores($hito, $base + ['nota' => $this->texto(600)]), $hito . ' con 600');

            $errores = $this->errores($hito, $base + ['nota' => $this->texto(601)]);
            $this->assertSame(['nota'], array_keys($errores), $hito . ' con 601');
            $this->assertStringContainsString('600', $errores['nota']);
            $this->assertStringContainsString('601', $errores['nota']);
        }

        $this->assertSame([], $this->errores('instalado', ['nota' => null]));
        $this->assertSame([], $this->errores('instalado', ['nota' => '   ']));
        $this->assertSame(['nota'], array_keys($this->errores('instalado', ['nota' => 123])));
        $this->assertSame(['nota'], array_keys($this->errores('instalado', ['nota' => ['una', 'lista']])));
    }

    /**
     * Acceso: `articulos` es obligatorio y es un entero de cero en adelante. Acepta el string de
     * dígitos que llega de un formulario y nada más.
     *
     * @return void
     */
    public function test_acceso_pide_articulos_como_entero()
    {
        $this->assertSame(['articulos' => 'Es obligatorio.'], $this->errores('acceso', []));
        $this->assertSame(['articulos' => 'Es obligatorio.'], $this->errores('acceso', ['articulos' => null]));

        $this->assertSame([], $this->errores('acceso', ['articulos' => 0]));
        $this->assertSame([], $this->errores('acceso', ['articulos' => 2418]));
        $this->assertSame([], $this->errores('acceso', ['articulos' => '2418']));

        foreach ([-1, '-1', 12.5, '12.5', 'doce', true, [3], '1e3', ' 5'] as $malo) {
            $errores = $this->errores('acceso', ['articulos' => $malo]);
            $this->assertSame(['articulos'], array_keys($errores), 'Valor: ' . json_encode($malo));
        }

        $this->assertSame(['articulos'], array_keys($this->errores('acceso', ['articulos' => 10000000])), 'Pasa el tope de siete dígitos.');
        $this->assertSame([], $this->errores('acceso', ['articulos' => 9999999]));
    }

    /**
     * Imágenes: `con_foto` y `total` son obligatorios y enteros; `a_revisar` es opcional; `fuentes` es
     * un texto de hasta 160 caracteres.
     *
     * @return void
     */
    public function test_imagenes_pide_con_foto_y_total_y_acepta_a_revisar_y_fuentes()
    {
        $errores = $this->errores('imagenes', []);
        $this->assertSame(['con_foto', 'total'], array_keys($errores));

        $this->assertSame([], $this->errores('imagenes', ['con_foto' => 10, 'total' => 20]));
        $this->assertSame([], $this->errores('imagenes', ['con_foto' => '10', 'total' => '20', 'a_revisar' => '3']));

        $this->assertSame(['a_revisar'], array_keys($this->errores('imagenes', ['con_foto' => 1, 'total' => 2, 'a_revisar' => -1])));
        $this->assertSame(['con_foto'], array_keys($this->errores('imagenes', ['con_foto' => 'x', 'total' => 2])));

        $this->assertSame([], $this->errores('imagenes', ['con_foto' => 1, 'total' => 2, 'fuentes' => $this->texto(160)]));
        $errores = $this->errores('imagenes', ['con_foto' => 1, 'total' => 2, 'fuentes' => $this->texto(161)]);
        $this->assertSame(['fuentes'], array_keys($errores));
        $this->assertStringContainsString('160', $errores['fuentes']);
    }

    /**
     * Imágenes: un catálogo vacío no tiene porcentaje, y no puede haber más artículos con foto que
     * artículos.
     *
     * @return void
     */
    public function test_imagenes_no_acepta_un_total_vacio_ni_mas_fotos_que_articulos()
    {
        $errores = $this->errores('imagenes', ['con_foto' => 0, 'total' => 0]);
        $this->assertSame(['total'], array_keys($errores));
        $this->assertStringContainsString('mayor que cero', $errores['total']);

        $errores = $this->errores('imagenes', ['con_foto' => 3000, 'total' => 2418]);
        $this->assertSame(['con_foto'], array_keys($errores));
        $this->assertStringContainsString('2418', $errores['con_foto']);

        $this->assertSame([], $this->errores('imagenes', ['con_foto' => 2418, 'total' => 2418]), 'Todos con foto es válido.');
        $this->assertSame([], $this->errores('imagenes', ['con_foto' => 0, 'total' => 5]), 'Ninguno con foto es válido.');
    }

    /**
     * Categorías: `opciones` es obligatorio y son UNA, DOS o TRES. La historia: eran EXACTAMENTE tres;
     * el 6/10/2026 (misión `implementacion-dos-sistemas`) pasaron a dos o tres, porque `/categorizar`
     * arma DOS sistemas por defecto; y el 7/10/2026 (misión `categorias-del-dueno`) se aceptó también
     * UNA, la lista de categorías que trae el dueño. Con cuatro, vacío o sin ser una lista, es un
     * error. Dos y tres siguen entrando igual que antes (compatible hacia atrás).
     *
     * @return void
     */
    public function test_categorias_pide_una_dos_o_tres_opciones()
    {
        $opciones = $this->opciones_de_ejemplo();

        $this->assertSame(['opciones' => 'Es obligatorio: las opciones de categorías (una, dos o tres).'], $this->errores('categorias', []));

        /* Antes de la misión `categorias-del-dueno` una sola opción era un error ("dos o tres"); ahora es válida. */
        $this->assertSame([], $this->errores('categorias', ['opciones' => array_slice($opciones, 0, 1)]), 'Una opción es válido.');

        $cuatro = $this->errores('categorias', ['opciones' => array_merge($opciones, [$opciones[0]])]);
        $this->assertSame(['opciones'], array_keys($cuatro));
        $this->assertStringContainsString('dos o tres', $cuatro['opciones']);
        $this->assertStringContainsString('llegaron 4', $cuatro['opciones']);

        $this->assertSame(['opciones'], array_keys($this->errores('categorias', ['opciones' => []])));
        $this->assertSame(['opciones'], array_keys($this->errores('categorias', ['opciones' => 'tres'])));

        $this->assertSame([], $this->errores('categorias', ['opciones' => array_slice($opciones, 0, 2)]), 'Dos opciones es válido.');
        $this->assertSame([], $this->errores('categorias', ['opciones' => $opciones]), 'Tres sigue siendo válido.');
    }

    /**
     * Categorías: cada opción pide nombre (hasta 60), base (hasta 280) y categorias (entero de 1 en
     * adelante). Los errores dicen en qué opción están, con la ruta `opciones.<posición>.<campo>`.
     *
     * @return void
     */
    public function test_cada_opcion_de_categorias_valida_sus_campos()
    {
        $opciones = $this->opciones_de_ejemplo();

        // Los topes justos pasan.
        $opciones[0]['nombre'] = $this->texto(60);
        $opciones[1]['base']   = $this->texto(280);
        $this->assertSame([], $this->errores('categorias', ['opciones' => $opciones]));

        // Pasarse en uno cae en esa opción.
        $opciones[0]['nombre'] = $this->texto(61);
        $opciones[1]['base']   = $this->texto(281);
        $errores               = $this->errores('categorias', ['opciones' => $opciones]);

        $this->assertSame(['opciones.0.nombre', 'opciones.1.base'], array_keys($errores));
        $this->assertStringContainsString('60', $errores['opciones.0.nombre']);
        $this->assertStringContainsString('280', $errores['opciones.1.base']);
    }

    /**
     * Categorías: obligatorios de cada opción y tipo de `categorias`.
     *
     * @return void
     */
    public function test_cada_opcion_exige_nombre_base_y_cantidad_de_categorias()
    {
        $opciones = $this->opciones_de_ejemplo();

        $opciones[0] = ['ejemplos' => ['Uno']];
        $errores     = $this->errores('categorias', ['opciones' => $opciones]);
        $this->assertSame(['opciones.0.nombre', 'opciones.0.base', 'opciones.0.categorias'], array_keys($errores));

        $opciones    = $this->opciones_de_ejemplo();
        $opciones[1] = ['nombre' => '   ', 'base' => 'Algo', 'categorias' => 5];
        $errores     = $this->errores('categorias', ['opciones' => $opciones]);
        $this->assertSame(['opciones.1.nombre'], array_keys($errores), 'Un nombre en blanco es como no ponerlo.');

        foreach ([0, -3, 'muchas', 2.5, null] as $malo) {
            $opciones             = $this->opciones_de_ejemplo();
            $opciones[2]['categorias'] = $malo;
            $errores              = $this->errores('categorias', ['opciones' => $opciones]);
            $this->assertSame(['opciones.2.categorias'], array_keys($errores), 'Valor: ' . json_encode($malo));
        }

        $opciones    = $this->opciones_de_ejemplo();
        $opciones[0] = 'no es un objeto';
        $this->assertSame(['opciones.0'], array_keys($this->errores('categorias', ['opciones' => $opciones])));

        $opciones                 = $this->opciones_de_ejemplo();
        $opciones[0]['categoria'] = 5;
        $this->assertSame(['opciones.0.categoria'], array_keys($this->errores('categorias', ['opciones' => $opciones])), 'Clave mal escrita dentro de la opción.');
    }

    /**
     * Categorías: los ejemplos son opcionales, hasta cuatro, de hasta 40 caracteres cada uno.
     *
     * @return void
     */
    public function test_los_ejemplos_de_una_opcion_son_hasta_cuatro_textos_cortos()
    {
        $opciones = $this->opciones_de_ejemplo();

        $opciones[0]['ejemplos'] = ['a', 'b', 'c', 'd'];
        $this->assertSame([], $this->errores('categorias', ['opciones' => $opciones]));

        $opciones[0]['ejemplos'] = ['a', 'b', 'c', 'd', 'e'];
        $errores                 = $this->errores('categorias', ['opciones' => $opciones]);
        $this->assertSame(['opciones.0.ejemplos'], array_keys($errores));
        $this->assertStringContainsString('cuatro', $errores['opciones.0.ejemplos']);

        $opciones[0]['ejemplos'] = ['ok', $this->texto(41), 7, ''];
        $errores                 = $this->errores('categorias', ['opciones' => $opciones]);
        $this->assertSame(['opciones.0.ejemplos.1', 'opciones.0.ejemplos.2', 'opciones.0.ejemplos.3'], array_keys($errores));

        $opciones[0]['ejemplos'] = $this->texto(10);
        $this->assertSame(['opciones.0.ejemplos'], array_keys($this->errores('categorias', ['opciones' => $opciones])));

        unset($opciones[0]['ejemplos']);
        $this->assertSame([], $this->errores('categorias', ['opciones' => $opciones]), 'Sin ejemplos es válido.');
    }

    /**
     * Categorías: `como_elegir` es opcional y llega hasta 220 caracteres.
     *
     * @return void
     */
    public function test_como_elegir_admite_hasta_220_caracteres()
    {
        $base = ['opciones' => $this->opciones_de_ejemplo()];

        $this->assertSame([], $this->errores('categorias', $base + ['como_elegir' => $this->texto(220)]));

        $errores = $this->errores('categorias', $base + ['como_elegir' => $this->texto(221)]);
        $this->assertSame(['como_elegir'], array_keys($errores));
        $this->assertStringContainsString('220', $errores['como_elegir']);
    }

    /**
     * Listo: `resumen` es opcional y cada rubro un entero de cero en adelante; los rubros que no son
     * de la lista y los valores que no sirven son errores. `recursos_url` tiene que ser http o https.
     *
     * @return void
     */
    public function test_listo_valida_el_resumen_y_la_url_de_recursos()
    {
        $this->assertSame([], $this->errores('listo', []));
        $this->assertSame([], $this->errores('listo', ['resumen' => []]));
        $this->assertSame([], $this->errores('listo', ['resumen' => ['articulos' => 10, 'proveedores' => 0]]));

        $errores = $this->errores('listo', ['resumen' => ['articulos' => -1, 'paises' => 3, 'clientes' => 'muchos']]);
        $this->assertEqualsCanonicalizing(['resumen.articulos', 'resumen.paises', 'resumen.clientes'], array_keys($errores));

        $this->assertSame(['resumen'], array_keys($this->errores('listo', ['resumen' => 'todo'])));

        $this->assertSame([], $this->errores('listo', ['recursos_url' => 'https://ejemplo.test/tutoriales']));
        $this->assertSame([], $this->errores('listo', ['recursos_url' => 'http://ejemplo.test']));

        foreach (['javascript:alert(1)', 'ftp://ejemplo.test/x', 'ejemplo.test/tutoriales', 'https://', 'data:text/html,hola', 'no es una url'] as $mala) {
            $errores = $this->errores('listo', ['recursos_url' => $mala]);
            $this->assertSame(['recursos_url'], array_keys($errores), 'URL: ' . $mala);
            $this->assertStringContainsString('http', $errores['recursos_url']);
        }

        $errores = $this->errores('listo', ['recursos_url' => 'https://ejemplo.test/' . $this->texto(500)]);
        $this->assertSame(['recursos_url'], array_keys($errores), 'Hasta 500 caracteres.');
    }

    /**
     * Un texto que no es UTF-8 válido sería un texto vacío en el mail (el escape lo borra sin
     * avisar): se frena en la validación.
     *
     * @return void
     */
    public function test_un_texto_que_no_es_utf8_es_un_error()
    {
        $errores = $this->errores('instalado', ['nota' => "Caf\xE9 sin codificar"]);

        $this->assertSame(['nota'], array_keys($errores));
        $this->assertStringContainsString('UTF-8', $errores['nota']);
    }

    /**
     * `previa()` y `enviar()` repiten la validación: con datos que no sirven tiran `faltan_datos`
     * con los errores por campo, antes de armar o mandar nada.
     *
     * @return void
     */
    public function test_previa_y_enviar_frenan_con_faltan_datos_si_los_datos_no_sirven()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados(3, 4));

        /*
         * Datos que no sirven: CUATRO opciones. Antes acá se usaba UNA sola, pero desde el 7/10/2026
         * (misión `categorias-del-dueno`) una opción es válida; lo que sigue siendo un error es pasarse
         * de tres.
         */
        $opciones_de_ejemplo = $this->opciones_de_ejemplo();
        $datos_que_no_sirven = ['opciones' => array_merge($opciones_de_ejemplo, [$opciones_de_ejemplo[0]])];

        foreach (['previa', 'enviar'] as $metodo) {
            try {
                if ($metodo === 'previa') {
                    ImplementacionMailService::previa($impl, 'categorias', $datos_que_no_sirven, null);
                } else {
                    ImplementacionMailService::enviar($impl, 'categorias', $datos_que_no_sirven, null, false);
                }

                $this->fail($metodo . ' tendría que haber tirado la excepción.');
            } catch (ImplementacionMailException $excepcion) {
                $this->assertSame(ImplementacionMailException::MOTIVO_FALTAN_DATOS, $excepcion->motivo, $metodo);
                $this->assertSame(422, $excepcion->getCode(), $metodo);
                $this->assertSame(['opciones'], array_keys($excepcion->errores), $metodo);
                $this->assertSame(['opciones'], $excepcion->faltan, $metodo);
                $this->assertStringContainsString('dos o tres', $excepcion->getMessage(), $metodo);
            }
        }
    }

    /* ------------------------------------------------------------------------------------------
     | Dos o tres opciones de categorías (misión `implementacion-dos-sistemas`, 6/10/2026, D4)
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Con DOS opciones la previa entra y el mail dice "dos" en el asunto, el preheader y la
     * introducción: un mail con dos tarjetas que dice "tres formas" es un mail que miente. Lleva las
     * dos tarjetas y ninguna tercera.
     *
     * @return void
     */
    public function test_con_dos_opciones_la_previa_entra_y_el_mail_dice_dos()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados(3, 4));

        $previa = ImplementacionMailService::previa($impl, 'categorias', ['opciones' => array_slice($this->opciones_de_ejemplo(), 0, 2)], null);

        $this->assertSame('Dos formas de ordenar tu catálogo', $previa['asunto']);
        $this->assertSame([], $previa['faltan']);

        $html = $previa['html'];

        $this->assertStringContainsString('<title>Dos formas de ordenar tu catálogo</title>', $html);
        $this->assertStringContainsString('Armamos dos propuestas de categorías para tus productos. Elegí la que más te sirva.', $html);
        $this->assertStringContainsString('armamos dos formas distintas de organizarlos', $html);

        $this->assertStringContainsString('Opción 1 &middot; 14 categorías', $html);
        $this->assertStringContainsString('Opción 2 &middot; 9 categorías', $html);
        $this->assertStringNotContainsString('Opción 3', $html);

        $this->assertStringNotContainsString('Tres formas', $html, 'Con dos opciones el mail no puede decir "tres".');
        $this->assertStringNotContainsString('tres propuestas', $html);
        $this->assertStringNotContainsString('tres formas', $html);

        /* El cierre de siempre vale para dos: "cada una" no cuenta cuántas son. */
        $this->assertStringContainsString('elegís desde tu sistema, en Alertas → Catálogo → Categorías', $html);
    }

    /**
     * 🔴 Compatible hacia atrás: con TRES opciones el mail dice exactamente lo de siempre.
     *
     * @return void
     */
    public function test_con_tres_opciones_la_previa_sigue_diciendo_lo_de_siempre()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados(3, 4));

        $previa = ImplementacionMailService::previa($impl, 'categorias', ['opciones' => $this->opciones_de_ejemplo()], null);

        $this->assertSame('Tres formas de ordenar tu catálogo', $previa['asunto']);

        $html = $previa['html'];

        $this->assertStringContainsString('Armamos tres propuestas de categorías para tus productos. Elegí la que más te sirva.', $html);
        $this->assertStringContainsString(
            'Analizamos todos tus productos y armamos tres formas distintas de organizarlos. Cada una parte de un criterio diferente: '
            . 'quedate con la que se parezca más a cómo te buscan tus clientes y cómo trabaja tu equipo.',
            $html
        );
        $this->assertStringContainsString('Opción 3 &middot; 22 categorías', $html);
        $this->assertStringNotContainsString('Dos formas', $html);
        $this->assertStringNotContainsString('dos propuestas', $html);
    }

    /**
     * El envío REAL con dos opciones sale con el asunto de dos, con las dos tarjetas en la vista y
     * queda registrado así.
     *
     * @return void
     */
    public function test_el_envio_real_con_dos_opciones_sale_con_el_asunto_de_dos()
    {
        Mail::fake();

        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados(3, 4));

        $resultado = ImplementacionMailService::enviar(
            $impl,
            'categorias',
            ['opciones' => array_slice($this->opciones_de_ejemplo(), 0, 2)],
            null,
            false
        );

        $this->assertSame('enviado', $resultado['estado']);

        Mail::assertSent(ImplementacionMail::class, 1);
        Mail::assertSent(ImplementacionMail::class, function ($mailable) {
            return $mailable->hito === 'categorias'
                && $mailable->asunto === 'Dos formas de ordenar tu catálogo'
                && count($mailable->vista['opciones']) === 2;
        });
    }

    /**
     * Dos opciones con el mismo nombre son una opción repetida, no dos formas de ordenar el catálogo.
     *
     * @return void
     */
    public function test_dos_opciones_con_el_mismo_nombre_son_un_error()
    {
        $opciones              = array_slice($this->opciones_de_ejemplo(), 0, 2);
        $opciones[1]['nombre'] = '  POR TIPO de producto ';

        $errores = $this->errores('categorias', ['opciones' => $opciones]);

        $this->assertSame(['opciones'], array_keys($errores));
        $this->assertStringContainsString('nombres distintos', $errores['opciones']);
    }

    /**
     * El catálogo de `claude/*` ya no promete "EXACTAMENTE 3": describe dos o tres, en el freno de los
     * datos y en la descripción del hito y de los datos del mail.
     *
     * @return void
     */
    public function test_el_catalogo_describe_dos_o_tres_opciones_y_no_exactamente_tres()
    {
        $endpoint = config('claude_catalog.endpoints.POST api/claude/implementations/{id}/mail');

        $this->assertIsArray($endpoint);

        $texto = json_encode($endpoint, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('EXACTAMENTE 3', $texto);
        $this->assertStringNotContainsString('las tres opciones', $texto);
        $this->assertStringContainsString('2 o 3', $texto);
        $this->assertStringContainsString('dos o tres', $texto);
        // Desde categorias-del-dueno (7/10/2026) una sola opción es válida: el catálogo tiene que
        // documentarla y no puede seguir diciendo que una opción es un 422.
        $this->assertStringContainsString('una opción', $texto);
        $this->assertStringNotContainsString('una o cuatro', $texto);
    }
}
