<?php

namespace Tests\Feature\ImplementacionMail;

use App\Exceptions\ImplementacionMailException;
use App\Mail\ImplementacionMail;
use App\Services\ImplementacionMailService;
use Illuminate\Support\Facades\Mail;

/**
 * El mail de hito `categorias` con UNA sola opción (misión `categorias-del-dueno`, 7/10/2026).
 *
 * Cuando el dueño trae su propia lista de categorías, `/categorizar` le arma UN solo sistema (el
 * suyo) y el mail ya no le pide que elija entre formas distintas: le pide que revise y confirme.
 * Por eso con una opción el mail cambia de texto, no numera la tarjeta ("Opción 1" de una sola
 * opción confunde) y cierra en singular.
 *
 * Lo que este archivo fija, por orden:
 *   1. una opción se acepta; cero y cuatro se rechazan con el mensaje nuevo ("una, dos o tres");
 *   2. el HTML y el texto plano con una opción: asunto, preheader, titular, introducción, sin
 *      índice y con el cierre en singular (o el `como_elegir` que mande quien llama);
 *   3. COMPATIBLE HACIA ATRÁS: con dos o tres opciones el mail sale exactamente como salía.
 *
 * Los tests de dos y de tres que ya existían (`ValidacionDeDatosTest`) siguen en su lugar.
 */
class UnaSolaOpcionDeCategoriasTest extends BaseDelMailDeImplementacion
{
    /**
     * La opción única de ejemplo: lo que armaría `/implementar` desde la lista del dueño.
     *
     * @param array<string, mixed> $cambios Lo que se quiere pisar.
     *
     * @return array<string, mixed>
     */
    private function opcion_unica(array $cambios = []): array
    {
        return array_merge([
            'nombre'     => 'Tus categorías',
            'categorias' => 12,
            'base'       => 'Son las categorías que nos pasaste vos, con las subcategorías que armamos nosotros.',
            'ejemplos'   => ['Herramientas', 'Pinturas', 'Electricidad'],
        ], $cambios);
    }

    /**
     * Una implementación nueva, en la etapa 4 (la de las categorías), con su cliente y su casilla.
     *
     * @return \App\Models\Implementation
     */
    private function nueva_implementacion()
    {
        $client = $this->crear_cliente(['name' => 'Martina', 'company_name' => 'Los Andes', 'email' => 'dueno@ejemplo.test']);

        return $this->crear_implementacion($client, $this->estados(3, 4));
    }

    /**
     * El HTML del mail de categorías, como lo ve el cliente.
     *
     * @param array<string, mixed> $datos
     *
     * @return string
     */
    private function html_de(array $datos): string
    {
        return ImplementacionMailService::previa($this->nueva_implementacion(), 'categorias', $datos, null)['html'];
    }

    /**
     * La versión de texto plano del mismo mail, con los saltos de línea unificados (la vista se
     * edita con CRLF en Windows y con LF en el servidor: el texto del mail no tiene que depender de
     * eso para compararse).
     *
     * @param array<string, mixed> $datos
     *
     * @return string
     */
    private function texto_de(array $datos): string
    {
        Mail::fake();

        ImplementacionMailService::enviar($this->nueva_implementacion(), 'categorias', $datos, null, false);

        $mailable = Mail::sent(ImplementacionMail::class)->first();
        $this->assertNotNull($mailable, 'El mail no salió.');

        // Con Mail::fake() el contenedor devuelve el falso: se arma el mailable a mano y se lee la vista de texto.
        app()->call([$mailable, 'build']);

        return str_replace("\r\n", "\n", (string) $mailable->textView);
    }

    /**
     * Del texto plano, solo el cuerpo propio del hito: desde el titular hasta justo antes de la
     * línea de progreso. Es lo que cambia (o no) según cuántas opciones lleguen.
     *
     * @param string $texto   El texto plano completo.
     * @param string $titular La primera línea del cuerpo.
     *
     * @return string
     */
    private function cuerpo_de(string $texto, string $titular): string
    {
        $desde = strpos($texto, $titular);
        $hasta = strpos($texto, 'TU IMPLEMENTACIÓN');

        $this->assertNotFalse($desde, 'No está el titular en el texto plano.');
        $this->assertNotFalse($hasta, 'No está la línea de progreso en el texto plano.');

        return rtrim(substr($texto, $desde, $hasta - $desde));
    }

    /* ------------------------------------------------------------------------------------------
     | 1. Qué cantidad de opciones se acepta
     |----------------------------------------------------------------------------------------- */

    /**
     * Una sola opción es válida (antes era un error), con y sin `como_elegir`, y sin ejemplos.
     *
     * @return void
     */
    public function test_una_sola_opcion_es_valida()
    {
        $this->assertSame([], ImplementacionMailService::validar_datos('categorias', ['opciones' => [$this->opcion_unica()]]));

        $this->assertSame([], ImplementacionMailService::validar_datos('categorias', [
            'opciones'    => [$this->opcion_unica(['ejemplos' => []])],
            'como_elegir' => 'Confirmala cuando puedas.',
        ]));
    }

    /**
     * Cada opción sigue pidiendo lo suyo aunque sea una sola: nombre, base y categorias. El error
     * dice en qué campo, con la ruta de siempre.
     *
     * @return void
     */
    public function test_la_opcion_unica_sigue_validando_sus_campos()
    {
        $errores = ImplementacionMailService::validar_datos('categorias', [
            'opciones' => [$this->opcion_unica(['nombre' => '', 'categorias' => 0])],
        ]);

        $this->assertArrayHasKey('opciones.0.nombre', $errores);
        $this->assertArrayHasKey('opciones.0.categorias', $errores);
    }

    /**
     * Cero y cuatro opciones se rechazan, con el mensaje nuevo: ya no dice "dos o tres" a secas, dice
     * "una, dos o tres", y cuenta cuántas llegaron.
     *
     * @return void
     */
    public function test_cero_y_cuatro_opciones_se_rechazan_con_el_mensaje_nuevo()
    {
        $unica = $this->opcion_unica();

        $cero = ImplementacionMailService::validar_datos('categorias', ['opciones' => []]);
        $this->assertSame(['opciones' => 'Tienen que ser una, dos o tres opciones (llegaron 0).'], $cero);

        $cuatro = ImplementacionMailService::validar_datos('categorias', [
            'opciones' => [
                $unica,
                $this->opcion_unica(['nombre' => 'Otra']),
                $this->opcion_unica(['nombre' => 'Una más']),
                $this->opcion_unica(['nombre' => 'Y otra']),
            ],
        ]);
        $this->assertSame(['opciones' => 'Tienen que ser una, dos o tres opciones (llegaron 4).'], $cuatro);

        $this->assertSame(
            ['opciones' => 'Es obligatorio: las opciones de categorías (una, dos o tres).'],
            ImplementacionMailService::validar_datos('categorias', [])
        );
        $this->assertSame(
            ['opciones' => 'Tiene que ser una lista con una, dos o tres opciones.'],
            ImplementacionMailService::validar_datos('categorias', ['opciones' => 'una'])
        );
    }

    /**
     * La previa y el envío frenan con cero opciones igual que con cualquier dato que no sirve:
     * `faltan_datos`, 422, y no se manda nada.
     *
     * @return void
     */
    public function test_cero_opciones_frenan_la_previa_y_el_envio()
    {
        Mail::fake();

        $impl = $this->nueva_implementacion();

        foreach (['previa', 'enviar'] as $metodo) {
            try {
                if ($metodo === 'previa') {
                    ImplementacionMailService::previa($impl, 'categorias', ['opciones' => []], null);
                } else {
                    ImplementacionMailService::enviar($impl, 'categorias', ['opciones' => []], null, false);
                }

                $this->fail($metodo . ' tendría que haber tirado la excepción.');
            } catch (ImplementacionMailException $excepcion) {
                $this->assertSame(ImplementacionMailException::MOTIVO_FALTAN_DATOS, $excepcion->motivo, $metodo);
                $this->assertSame(422, $excepcion->getCode(), $metodo);
                $this->assertSame(['opciones'], array_keys($excepcion->errores), $metodo);
                $this->assertStringContainsString('una, dos o tres', $excepcion->getMessage(), $metodo);
            }
        }

        Mail::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 2. El mail con una sola opción
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El HTML con una opción: asunto, preheader, titular e introducción propios, la tarjeta SIN
     * "Opción 1" y el cierre en singular. Nada de "formas" ni "propuestas" ni "elegí": no hay entre
     * qué elegir.
     *
     * @return void
     */
    public function test_con_una_opcion_el_html_lleva_los_textos_de_la_lista_unica()
    {
        $previa = ImplementacionMailService::previa(
            $this->nueva_implementacion(),
            'categorias',
            ['opciones' => [$this->opcion_unica()]],
            null
        );

        $this->assertSame('Tu catálogo, ordenado en categorías', $previa['asunto']);
        $this->assertSame([], $previa['faltan']);

        $html = $previa['html'];

        $this->assertStringContainsString('<title>Tu catálogo, ordenado en categorías</title>', $html);
        $this->assertStringContainsString('Ubicamos tus productos en sus categorías. Revisalo y confirmalo desde tu sistema.', $html);
        $this->assertStringContainsString('Tu catálogo ya tiene sus categorías.', $html);
        $this->assertStringContainsString(
            'Ubicamos tus productos en sus categorías y subcategorías. Revisá cómo quedó y confirmalo desde tu sistema: '
            . 'hasta que lo confirmes, no cambia nada.',
            $html
        );

        // La tarjeta: sin índice, con la cantidad, el nombre, la base y los ejemplos.
        $this->assertStringContainsString('>12 categorías</p>', $html);
        $this->assertStringContainsString('Tus categorías', $html);
        $this->assertStringContainsString('Son las categorías que nos pasaste vos, con las subcategorías que armamos nosotros.', $html);
        $this->assertStringContainsString('Herramientas', $html);
        $this->assertStringContainsString('Electricidad', $html);

        $this->assertStringNotContainsString('Opción', $html, 'Con una sola opción no se numera.');
        $this->assertStringNotContainsString('&middot;', $html);

        // El cierre en singular. El HTML escapa las comillas.
        $this->assertStringContainsString(
            'La ves completa en tu sistema, en Alertas → Catálogo → Categorías: tocá &quot;Elegir este&quot; para confirmarla.',
            $html
        );

        // Nada del mail de "elegí entre varias".
        $this->assertStringNotContainsString('Elegí cómo ordenar tu catálogo', $html);
        $this->assertStringNotContainsString('formas', $html);
        $this->assertStringNotContainsString('propuestas', $html);
        $this->assertStringNotContainsString('elegís desde tu sistema', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('@if', $html);
    }

    /**
     * 🔴 El texto plano con una opción: la misma historia sin diseño. Se compara el cuerpo entero,
     * desde el titular hasta la línea de progreso.
     *
     * @return void
     */
    public function test_con_una_opcion_el_texto_plano_sale_sin_numerar_y_con_el_cierre_en_singular()
    {
        $texto = $this->texto_de(['opciones' => [$this->opcion_unica()]]);

        $esperado = implode("\n", [
            'Tu catálogo ya tiene sus categorías.',
            '',
            'Ubicamos tus productos en sus categorías y subcategorías. Revisá cómo quedó y confirmalo desde tu sistema: hasta que lo confirmes, no cambia nada.',
            '',
            '12 categorías: Tus categorías',
            'Son las categorías que nos pasaste vos, con las subcategorías que armamos nosotros.',
            'Por ejemplo: Herramientas, Pinturas, Electricidad',
            '',
            'La ves completa en tu sistema, en Alertas → Catálogo → Categorías: tocá "Elegir este" para confirmarla.',
        ]);

        $this->assertSame($esperado, $this->cuerpo_de($texto, 'Tu catálogo ya tiene sus categorías.'));

        $this->assertStringNotContainsString('Opción', $texto);
        $this->assertStringNotContainsString('&quot;', $texto, 'El texto plano no sale escapado.');
        $this->assertStringNotContainsString('{{', $texto);
    }

    /**
     * Si quien llama manda su propio cierre (`como_elegir`), manda ese: también con una sola opción,
     * en el HTML y en el texto. La tarjeta sigue sin índice.
     *
     * @return void
     */
    public function test_con_una_opcion_el_como_elegir_reemplaza_el_cierre()
    {
        $datos = [
            'opciones'    => [$this->opcion_unica()],
            'como_elegir' => 'Mirala con calma y avisanos si cambiarías algo.',
        ];

        $html = $this->html_de($datos);

        $this->assertStringContainsString('Mirala con calma y avisanos si cambiarías algo.', $html);
        $this->assertStringNotContainsString('La ves completa en tu sistema', $html);
        $this->assertStringNotContainsString('Opción', $html);

        $texto = $this->texto_de($datos);

        $this->assertStringContainsString('Mirala con calma y avisanos si cambiarías algo.', $texto);
        $this->assertStringNotContainsString('La ves completa en tu sistema', $texto);
        $this->assertStringContainsString('12 categorías: Tus categorías', $texto);
    }

    /**
     * Con una sola categoría el singular se respeta, y sin ejemplos no se dibujan fichas.
     *
     * @return void
     */
    public function test_la_opcion_unica_de_una_categoria_va_en_singular_y_sin_fichas()
    {
        $html = $this->html_de(['opciones' => [$this->opcion_unica(['categorias' => 1, 'ejemplos' => []])]]);

        $this->assertStringContainsString('>1 categoría</p>', $html);
        $this->assertStringNotContainsString('border:1px solid #e1e5ef', $html, 'Sin ejemplos no hay fichas.');
        $this->assertStringNotContainsString('Opción', $html);
    }

    /**
     * Lo que viene de los datos sale escapado también en la tarjeta única.
     *
     * @return void
     */
    public function test_la_opcion_unica_escapa_lo_que_viene_de_los_datos()
    {
        $html = $this->html_de(['opciones' => [$this->opcion_unica([
            'nombre' => 'Tus <b>categorías</b>',
            'base'   => 'Con "comillas" & <i>etiquetas</i>',
        ])]]);

        $this->assertStringNotContainsString('<b>categorías</b>', $html);
        $this->assertStringNotContainsString('<i>etiquetas</i>', $html);
        $this->assertStringContainsString('Tus &lt;b&gt;categorías&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;i&gt;etiquetas&lt;/i&gt;', $html);
    }

    /**
     * El envío REAL con una opción sale con el asunto de la lista única, con la vista marcada como
     * "una sola opción" y con la única tarjeta.
     *
     * @return void
     */
    public function test_el_envio_real_con_una_opcion_sale_con_el_asunto_de_la_lista_unica()
    {
        Mail::fake();

        $resultado = ImplementacionMailService::enviar(
            $this->nueva_implementacion(),
            'categorias',
            ['opciones' => [$this->opcion_unica()]],
            null,
            false
        );

        $this->assertSame('enviado', $resultado['estado']);

        Mail::assertSent(ImplementacionMail::class, 1);
        Mail::assertSent(ImplementacionMail::class, function ($mailable) {
            return $mailable->hito === 'categorias'
                && $mailable->asunto === 'Tu catálogo, ordenado en categorías'
                && $mailable->vista['una_sola_opcion'] === true
                && count($mailable->vista['opciones']) === 1;
        });
    }

    /* ------------------------------------------------------------------------------------------
     | 3. Compatible hacia atrás: dos y tres, exactamente como antes
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Con DOS opciones el mail sale exactamente como salía antes de esta misión: tarjetas
     * numeradas, "dos formas", cierre de varias. Se compara el texto plano ENTERO del cuerpo, que es
     * lo que no puede cambiar. (El HTML se midió byte por byte contra el de antes del cambio.)
     *
     * @return void
     */
    public function test_con_dos_opciones_el_mail_sale_exactamente_como_antes()
    {
        $datos = ['opciones' => array_slice($this->opciones_de_ejemplo(), 0, 2)];

        $esperado = implode("\n", [
            'Elegí cómo ordenar tu catálogo.',
            '',
            'Analizamos todos tus productos y armamos dos formas distintas de organizarlos. Cada una parte de un criterio diferente: quedate con la que se parezca más a cómo te buscan tus clientes y cómo trabaja tu equipo.',
            '',
            'Opción 1 - 14 categorías: Por tipo de producto',
            'Agrupa por lo que el producto es, como en el mostrador.',
            'Por ejemplo: Fijaciones, Herramientas manuales, Pinturas, Electricidad',
            '',
            'Opción 2 - 9 categorías: Por uso',
            'Agrupa por lo que el cliente quiere hacer.',
            'Por ejemplo: Baño, Cocina, Jardín, Obra',
            '',
            'Las ves completas y elegís desde tu sistema, en Alertas → Catálogo → Categorías: ahí ves los productos de cada una y cómo quedaría el menú de tu tienda. La elección la hace el dueño de la cuenta.',
        ]);

        $this->assertSame($esperado, $this->cuerpo_de($this->texto_de($datos), 'Elegí cómo ordenar tu catálogo.'));

        $html = $this->html_de($datos);

        $this->assertStringContainsString('Opción 1 &middot; 14 categorías</p>', $html);
        $this->assertStringContainsString('Opción 2 &middot; 9 categorías</p>', $html);
        $this->assertStringContainsString('Las ves completas y elegís desde tu sistema, en Alertas → Catálogo → Categorías', $html);
        $this->assertStringContainsString('<title>Dos formas de ordenar tu catálogo</title>', $html);
        $this->assertStringNotContainsString('Elegir este', $html, 'El cierre en singular es solo de la opción única.');
        $this->assertStringNotContainsString('Tu catálogo ya tiene sus categorías', $html);
    }

    /**
     * 🔴 Idem con TRES opciones.
     *
     * @return void
     */
    public function test_con_tres_opciones_el_mail_sale_exactamente_como_antes()
    {
        $datos = ['opciones' => $this->opciones_de_ejemplo()];

        $esperado = implode("\n", [
            'Elegí cómo ordenar tu catálogo.',
            '',
            'Analizamos todos tus productos y armamos tres formas distintas de organizarlos. Cada una parte de un criterio diferente: quedate con la que se parezca más a cómo te buscan tus clientes y cómo trabaja tu equipo.',
            '',
            'Opción 1 - 14 categorías: Por tipo de producto',
            'Agrupa por lo que el producto es, como en el mostrador.',
            'Por ejemplo: Fijaciones, Herramientas manuales, Pinturas, Electricidad',
            '',
            'Opción 2 - 9 categorías: Por uso',
            'Agrupa por lo que el cliente quiere hacer.',
            'Por ejemplo: Baño, Cocina, Jardín, Obra',
            '',
            'Opción 3 - 22 categorías: Por rubro y detalle',
            'Más fino: cada rubro se abre en subcategorías.',
            'Por ejemplo: Tornillería > Autoperforantes, Pintura > Látex',
            '',
            'Las ves completas y elegís desde tu sistema, en Alertas → Catálogo → Categorías: ahí ves los productos de cada una y cómo quedaría el menú de tu tienda. La elección la hace el dueño de la cuenta.',
        ]);

        $this->assertSame($esperado, $this->cuerpo_de($this->texto_de($datos), 'Elegí cómo ordenar tu catálogo.'));

        $html = $this->html_de($datos);

        $this->assertStringContainsString('Opción 1 &middot; 14 categorías</p>', $html);
        $this->assertStringContainsString('Opción 3 &middot; 22 categorías</p>', $html);
        $this->assertStringContainsString('<title>Tres formas de ordenar tu catálogo</title>', $html);
        $this->assertStringNotContainsString('Elegir este', $html);
        $this->assertStringNotContainsString('Tu catálogo ya tiene sus categorías', $html);
    }

    /**
     * Con dos o tres opciones la vista NO queda marcada como "una sola opción", y con dos o tres un
     * `como_elegir` sigue reemplazando el cierre como siempre.
     *
     * @return void
     */
    public function test_con_dos_o_tres_opciones_la_vista_no_es_de_una_sola_y_el_como_elegir_manda()
    {
        Mail::fake();

        foreach ([2, 3] as $cantidad) {
            ImplementacionMailService::enviar(
                $this->nueva_implementacion(),
                'categorias',
                [
                    'opciones'    => array_slice($this->opciones_de_ejemplo(), 0, $cantidad),
                    'como_elegir' => 'Elegí con el número.',
                ],
                null,
                false
            );
        }

        $enviados = Mail::sent(ImplementacionMail::class);
        $this->assertCount(2, $enviados);

        foreach ($enviados as $mailable) {
            $this->assertFalse($mailable->vista['una_sola_opcion'], 'Con varias opciones no es una sola.');
            $this->assertSame('Elegí con el número.', $mailable->vista['como_elegir']);
        }
    }
}
