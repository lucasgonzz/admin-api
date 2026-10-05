<?php

namespace Tests\Feature\ImplementacionMail;

use App\Mail\ImplementacionMail;
use App\Models\AdminSetting;
use App\Services\ImplementacionMailService;
use Illuminate\Support\Facades\Mail;

/**
 * Lo que dice cada mail de hito: asunto, texto, cifras, botones y links, ya renderizado.
 *
 * Cada test mira el HTML que devuelve `previa()` —que es el mismo que sale por el mailer— y no el
 * archivo de la vista: lo que importa es lo que ve el cliente.
 */
class RenderDeLosHitosTest extends BaseDelMailDeImplementacion
{
    /**
     * Las seis previas se arman con su asunto, sin ninguna llave de Blade sin procesar, con el nombre
     * del negocio, el saludo y la etapa que corresponde a la implementación.
     *
     * @return void
     */
    public function test_las_seis_previas_se_renderizan_completas()
    {
        $asuntos = [
            'bienvenida' => 'Arrancamos con la implementación de tu sistema',
            'instalado'  => 'Tu sistema ya está instalado',
            'acceso'     => 'Ya podés entrar a tu sistema',
            'imagenes'   => 'Las fotos de tu catálogo',
            'categorias' => 'Tres formas de ordenar tu catálogo',
            'listo'      => 'Tu sistema está listo',
        ];

        $etapa_en_curso = [
            'bienvenida' => 1, 'instalado' => 3, 'acceso' => 4, 'imagenes' => 4, 'categorias' => 4, 'listo' => 6,
        ];

        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client);

        $this->assertSame(array_keys($asuntos), ImplementacionMailService::HITOS, 'Los seis hitos, en orden.');

        foreach (ImplementacionMailService::HITOS as $hito) {
            $this->poner_las_etapas($impl, $this->estados_del_hito($hito));

            $previa = ImplementacionMailService::previa($impl->fresh(), $hito, $this->datos_de_ejemplo($hito), null);
            $html   = $previa['html'];

            $this->assertSame($asuntos[$hito], $previa['asunto'], $hito);
            $this->assertSame([], $previa['faltan'], $hito . ': no tendría que faltar nada');
            $this->assertStringContainsString('<title>' . $asuntos[$hito] . '</title>', $html, $hito);

            // Nada sin procesar: ni llaves de Blade ni directivas.
            $this->assertStringNotContainsString('{{', $html, $hito);
            $this->assertStringNotContainsString('{!!', $html, $hito);
            $this->assertStringNotContainsString('@include', $html, $hito);
            $this->assertStringNotContainsString('@foreach', $html, $hito);
            $this->assertStringNotContainsString('@endif', $html, $hito);

            $this->assertStringContainsString('Ferretería Los Andes', $html, $hito);
            $this->assertStringContainsString('Hola, Martina.', $html, $hito);
            $this->assertStringContainsString('Etapa ' . $etapa_en_curso[$hito] . ' de 8', $html, $hito);
        }
    }

    /**
     * La previa dice a quién iría el mail: la casilla entera y enmascarada.
     *
     * @return void
     */
    public function test_la_previa_dice_a_quien_le_llegaria_el_mail()
    {
        $client = $this->crear_cliente(['email' => 'lucas@gmail.com']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('instalado'));

        $previa = ImplementacionMailService::previa($impl, 'instalado', [], null);

        $this->assertSame('lucas@gmail.com', $previa['para']);
        $this->assertSame('l***@gmail.com', $previa['para_enmascarado']);
        $this->assertSame(['asunto', 'para', 'para_enmascarado', 'html', 'faltan'], array_keys($previa));
    }

    /**
     * Bienvenida: el botón es el link del formulario de ESTA implementación (URL base + token).
     *
     * @return void
     */
    public function test_la_bienvenida_lleva_el_link_del_formulario_de_la_implementacion()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('bienvenida'), ['form_token' => 'abc123token']);

        $previa = ImplementacionMailService::previa($impl, 'bienvenida', [], null);

        $this->assertStringContainsString('href="https://admin.comerciocity.com/configuracion/abc123token"', $previa['html']);
        $this->assertStringContainsString('Completar el formulario', $previa['html']);
        $this->assertStringContainsString('class="cc-boton"', $previa['html']);
        $this->assertSame([], $previa['faltan']);
    }

    /**
     * Sin link de formulario (la setting vacía o la implementación sin token) la previa lo avisa en
     * `faltan` y el mail sale sin botón: no se arma un botón que no lleva a ningún lado.
     *
     * @return void
     */
    public function test_sin_link_de_formulario_la_previa_lo_avisa_y_no_arma_el_boton()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('bienvenida'));

        AdminSetting::updateOrCreate(['key' => 'implementation_form_url'], ['value' => '']);

        $previa = ImplementacionMailService::previa($impl->fresh(), 'bienvenida', [], null);

        $this->assertSame(['form_link'], $previa['faltan']);
        $this->assertStringNotContainsString('Completar el formulario', $previa['html']);
        $this->assertStringNotContainsString('class="cc-boton"', $previa['html']);

        // Y sin token, aunque la setting esté.
        AdminSetting::updateOrCreate(['key' => 'implementation_form_url'], ['value' => self::URL_DEL_FORMULARIO]);
        $sin_token = $this->crear_implementacion($this->crear_cliente(['email' => 'otro@ejemplo.test']), [], ['form_token' => null]);

        $previa = ImplementacionMailService::previa($sin_token, 'bienvenida', [], null);

        $this->assertSame(['form_link'], $previa['faltan']);
    }

    /**
     * Instalado: pide los archivos y explica por qué el código tiene que ser el mismo que el del Excel.
     *
     * @return void
     */
    public function test_el_instalado_pide_los_archivos_y_el_codigo_en_comun()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('instalado'));

        $html = ImplementacionMailService::previa($impl, 'instalado', [], null)['html'];

        foreach (['Tus artículos', 'Tus clientes', 'Tus proveedores', 'Las fotos de tus productos'] as $pedido) {
            $this->assertStringContainsString($pedido, $html);
        }

        $this->assertStringContainsString('el mismo código que figura en tu Excel', $html);
        $this->assertStringNotContainsString('class="cc-boton"', $html, 'El mail de instalado no lleva botón.');
    }

    /**
     * Acceso: la cifra con el separador de miles argentino, la dirección del sistema del cliente y el
     * botón que lo lleva. Y NUNCA una contraseña.
     *
     * @return void
     */
    public function test_el_acceso_lleva_la_cifra_el_sistema_del_cliente_y_el_boton()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('acceso'));

        $previa = ImplementacionMailService::previa($impl, 'acceso', ['articulos' => 2418], null);
        $html   = $previa['html'];

        $this->assertStringContainsString('>2.418</p>', $html);
        $this->assertStringContainsString('artículos cargados en tu sistema', $html);
        $this->assertStringContainsString('Cargamos 2.418 artículos.', $html, 'El preheader también lleva la cifra.');
        $this->assertStringContainsString('href="' . self::URL_DEL_SISTEMA . '"', $html);
        $this->assertStringContainsString('>losandes.comerciocity.com</a>', $html, 'La dirección se muestra sin https://.');
        $this->assertStringContainsString('Entrar a mi sistema', $html);
        $this->assertStringContainsString('Tu número de documento', $html);
        $this->assertStringContainsString('La que te mandamos por WhatsApp', $html);
        $this->assertStringNotContainsString('1234', $html, 'El mail nunca lleva la contraseña.');
    }

    /**
     * Acceso con números que cambian la forma: uno (singular), cero y un millón (miles dos veces).
     *
     * @return void
     */
    public function test_el_acceso_cuida_el_singular_y_el_formato_de_los_numeros()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('acceso'));

        $uno = ImplementacionMailService::previa($impl, 'acceso', ['articulos' => 1], null)['html'];
        $this->assertStringContainsString('>1</p>', $uno);
        $this->assertStringContainsString('artículo cargado en tu sistema', $uno);
        $this->assertStringNotContainsString('artículos cargados', $uno);
        $this->assertStringContainsString('Cargamos 1 artículo.', $uno);

        $cero = ImplementacionMailService::previa($impl, 'acceso', ['articulos' => 0], null)['html'];
        $this->assertStringContainsString('>0</p>', $cero);
        $this->assertStringContainsString('artículos cargados en tu sistema', $cero);

        $muchos = ImplementacionMailService::previa($impl, 'acceso', ['articulos' => 1234567], null)['html'];
        $this->assertStringContainsString('>1.234.567</p>', $muchos);
    }

    /**
     * Los mails que mandan al cliente a su sistema (acceso, imagenes y listo) piden la dirección del
     * sistema. Si el cliente no tiene ninguna, la previa lo avisa y el mail sale sin botón.
     *
     * @return void
     */
    public function test_sin_direccion_de_sistema_la_previa_lo_avisa_y_no_arma_los_botones()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test'], false);
        $impl   = $this->crear_implementacion($client);

        foreach (['acceso', 'imagenes', 'listo'] as $hito) {
            $previa = ImplementacionMailService::previa($impl, $hito, $this->datos_de_ejemplo($hito), null);

            $this->assertSame(['url_sistema'], $previa['faltan'], $hito);
            $this->assertStringNotContainsString('Entrar a mi sistema', $previa['html'], $hito);
            $this->assertStringNotContainsString('Revisar las fotos', $previa['html'], $hito);
        }

        // Los hitos que no necesitan la dirección no la piden.
        $this->assertSame([], ImplementacionMailService::previa($impl, 'instalado', [], null)['faltan']);
        $this->assertSame([], ImplementacionMailService::previa($impl, 'categorias', $this->datos_de_ejemplo('categorias'), null)['faltan']);
    }

    /**
     * Imágenes: "X de Y", el porcentaje, el aviso de lo que espera el visto bueno y el botón a la
     * pantalla de alertas de imágenes del sistema del cliente.
     *
     * @return void
     */
    public function test_las_imagenes_llevan_el_avance_el_aviso_y_el_boton_a_las_alertas()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('imagenes'));

        $html = ImplementacionMailService::previa($impl, 'imagenes', [
            'con_foto' => 1904, 'total' => 2418, 'a_revisar' => 132, 'fuentes' => 'Catálogo de Blum y web de Bronzen',
        ], null)['html'];

        $this->assertStringContainsString('>1.904 de 2.418</p>', $html);
        $this->assertStringContainsString('artículos con foto', $html);
        $this->assertStringContainsString('El 79&nbsp;% de tu catálogo ya tiene foto.', $html);
        $this->assertStringContainsString('1.904 de tus 2.418 artículos ya tienen foto.', $html, 'El preheader.');
        $this->assertStringContainsString('132 fotos esperan tu visto bueno.', $html);
        $this->assertStringContainsString('href="' . self::URL_DEL_SISTEMA . '/alertas/imagenes"', $html);
        $this->assertStringContainsString('Revisar las fotos', $html);
        $this->assertStringContainsString('Fuentes: Catálogo de Blum y web de Bronzen', $html);
    }

    /**
     * Si no hay fotos por revisar no sale el aviso ni el botón; si el dato no vino, el botón queda
     * pero tampoco hay aviso. Y con una sola foto por revisar el aviso cambia de forma.
     *
     * @return void
     */
    public function test_las_fotos_por_revisar_mandan_el_aviso_y_el_boton_solo_cuando_corresponde()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('imagenes'));
        $base   = ['con_foto' => 100, 'total' => 200];

        // Ninguna por revisar: ni aviso ni botón.
        $cero = ImplementacionMailService::previa($impl, 'imagenes', $base + ['a_revisar' => 0], null)['html'];
        $this->assertStringNotContainsString('esperan tu visto bueno', $cero);
        $this->assertStringNotContainsString('Revisar las fotos', $cero);

        // El dato no vino: no se sabe, el botón queda y no hay aviso.
        $sin_dato = ImplementacionMailService::previa($impl, 'imagenes', $base, null)['html'];
        $this->assertStringNotContainsString('esperan tu visto bueno', $sin_dato);
        $this->assertStringContainsString('Revisar las fotos', $sin_dato);

        // Una sola: singular en todo el aviso.
        $una = ImplementacionMailService::previa($impl, 'imagenes', $base + ['a_revisar' => 1], null)['html'];
        $this->assertStringContainsString('1 foto espera tu visto bueno.', $una);
        $this->assertStringContainsString('Es la que la IA no pudo confirmar', $una);
        $this->assertStringContainsString('la aprobás', $una);
    }

    /**
     * El porcentaje no miente en los bordes: 2.417 de 2.418 no es "100 %" y 1 de 5.000 no es "0 %".
     * Con todo hecho la barra se llena entera.
     *
     * @return void
     */
    public function test_el_porcentaje_de_fotos_no_miente_en_los_bordes()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('imagenes'));

        $casi_todo = ImplementacionMailService::previa($impl, 'imagenes', ['con_foto' => 2417, 'total' => 2418], null)['html'];
        $this->assertStringContainsString('El 99&nbsp;% de tu catálogo', $casi_todo);

        $casi_nada = ImplementacionMailService::previa($impl, 'imagenes', ['con_foto' => 1, 'total' => 5000], null)['html'];
        $this->assertStringContainsString('El 1&nbsp;% de tu catálogo', $casi_nada);

        $nada = ImplementacionMailService::previa($impl, 'imagenes', ['con_foto' => 0, 'total' => 5000], null)['html'];
        $this->assertStringContainsString('El 0&nbsp;% de tu catálogo', $nada);

        $todo = ImplementacionMailService::previa($impl, 'imagenes', ['con_foto' => 10, 'total' => 10], null)['html'];
        $this->assertStringContainsString('El 100&nbsp;% de tu catálogo', $todo);
        $this->assertStringNotContainsString('border-radius:0 8px 8px 0', $todo, 'Con el 100 % la parte gris de la barra no existe.');
    }

    /**
     * Categorías: las tres opciones, con su cantidad, su nombre, en qué se basan y sus ejemplos. Sin
     * botón: se elige contestando.
     *
     * @return void
     */
    public function test_las_categorias_llevan_las_tres_opciones_y_no_llevan_boton()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('categorias'));

        $html = ImplementacionMailService::previa($impl, 'categorias', $this->datos_de_ejemplo('categorias'), null)['html'];

        $this->assertStringContainsString('Opción 1 &middot; 14 categorías', $html);
        $this->assertStringContainsString('Opción 2 &middot; 9 categorías', $html);
        $this->assertStringContainsString('Opción 3 &middot; 22 categorías', $html);
        $this->assertStringContainsString('Por tipo de producto', $html);
        $this->assertStringContainsString('Por uso', $html);
        $this->assertStringContainsString('Por rubro y detalle', $html);
        $this->assertStringContainsString('Agrupa por lo que el cliente quiere hacer.', $html);
        $this->assertStringContainsString('Fijaciones', $html);
        $this->assertStringContainsString('Tornillería &gt; Autoperforantes', $html, 'El texto con ">" sale escapado.');
        $this->assertStringContainsString('elegís desde tu sistema, en Alertas → Catálogo → Categorías', $html);
        $this->assertStringNotContainsString('class="cc-boton"', $html);
    }

    /**
     * Categorías: el cierre se reemplaza con `como_elegir`, una opción de una sola categoría va en
     * singular y una opción sin ejemplos no dibuja fichas.
     *
     * @return void
     */
    public function test_las_categorias_aceptan_su_propio_cierre_el_singular_y_ningun_ejemplo()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('categorias'));

        $opciones = $this->opciones_de_ejemplo();

        $opciones[0]['categorias'] = 1;
        $opciones[1]['ejemplos']   = [];
        $opciones[2]['ejemplos']   = [];
        unset($opciones[0]['ejemplos']);

        $html = ImplementacionMailService::previa($impl, 'categorias', [
            'opciones'    => $opciones,
            'como_elegir' => 'Elegí con el número y lo dejamos armado esta semana.',
        ], null)['html'];

        $this->assertStringContainsString('Opción 1 &middot; 1 categoría<', $html);
        $this->assertStringContainsString('Elegí con el número y lo dejamos armado esta semana.', $html);
        $this->assertStringNotContainsString('elegís desde tu sistema, en Alertas → Catálogo → Categorías', $html);
        $this->assertStringNotContainsString('border:1px solid #e1e5ef', $html, 'Sin ejemplos no hay fichas.');
    }

    /**
     * Listo: el resumen con lo que quedó cargado, el botón a su sistema y el botón de los tutoriales
     * que apunta a la carpeta de recursos de la configuración.
     *
     * @return void
     */
    public function test_el_listo_lleva_el_resumen_el_boton_y_los_tutoriales_de_la_config()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('listo'));

        $html = ImplementacionMailService::previa($impl, 'listo', $this->datos_de_ejemplo('listo'), null)['html'];

        foreach ([['2.418', 'artículos'], ['2.286', 'con foto'], ['14', 'categorías'], ['63', 'marcas'], ['412', 'clientes'], ['38', 'proveedores']] as $rubro) {
            $this->assertStringContainsString('>' . $rubro[0] . '</p>', $html);
            $this->assertStringContainsString('>' . $rubro[1] . '</p>', $html);
        }

        $this->assertStringContainsString('Lo que quedó cargado', $html);
        $this->assertStringContainsString('href="' . self::URL_DEL_SISTEMA . '"', $html);
        $this->assertStringContainsString('Entrar a mi sistema', $html);
        $this->assertStringContainsString('Ver los tutoriales', $html);
        $this->assertStringContainsString(
            'href="' . config('commerciocity.implementacion_mail.recursos_url') . '"',
            $html,
            'Sin el dato `recursos_url`, el botón apunta a la carpeta del centro de recursos de la config.'
        );
        $this->assertStringContainsString('https://drive.google.com/drive/folders/1pGWyUekgBok-ShmRWbXNrDhuxXMQvElp', $html);
        $this->assertStringContainsString('Lo que sigue', $html);
        $this->assertStringContainsString('Gracias por elegirnos para acompañar a tu negocio.', $html);
    }

    /**
     * Listo: los rubros en cero o ausentes no salen, el singular se respeta y `recursos_url` pisa
     * el de la config (escapado).
     *
     * @return void
     */
    public function test_el_listo_omite_lo_que_no_hay_y_acepta_otra_carpeta_de_recursos()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('listo'));

        $html = ImplementacionMailService::previa($impl, 'listo', [
            'resumen'      => ['articulos' => 1, 'clientes' => 1, 'proveedores' => 0],
            'recursos_url' => 'https://ejemplo.test/tutoriales?a=1&b=2',
        ], null)['html'];

        $this->assertStringContainsString('>artículo</p>', $html);
        $this->assertStringContainsString('>cliente</p>', $html);
        $this->assertStringNotContainsString('>proveedores</p>', $html, 'Un rubro en cero no es información.');
        $this->assertStringNotContainsString('>marcas</p>', $html);
        $this->assertStringContainsString('href="https://ejemplo.test/tutoriales?a=1&amp;b=2"', $html);
        $this->assertStringNotContainsString('drive.google.com', $html);

        // Sin resumen no hay bloque de cifras.
        $sin_resumen = ImplementacionMailService::previa($impl, 'listo', [], null)['html'];
        $this->assertStringNotContainsString('Lo que quedó cargado', $sin_resumen);
    }

    /**
     * Todo lo que viene de datos sale escapado: el negocio, el nombre, la nota y los textos de las
     * opciones. Un cliente con `<script>` en el nombre no puede meter HTML en su propio mail.
     *
     * @return void
     */
    public function test_los_datos_se_imprimen_escapados()
    {
        $client = $this->crear_cliente([
            'name'         => '<b>Martina</b> Gómez',
            'company_name' => '<script>alert(1)</script> & Hijos',
            'email'        => 'dueno@ejemplo.test',
        ]);
        $impl = $this->crear_implementacion($client, $this->estados_del_hito('categorias'));

        $opciones            = $this->opciones_de_ejemplo();
        $opciones[0]['base'] = 'Con "comillas" y <i>etiquetas</i> & más';

        $html = ImplementacionMailService::previa($impl, 'categorias', [
            'opciones' => $opciones,
            'nota'     => 'Una nota con <img src=x onerror=alert(1)> adentro',
        ], null)['html'];

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>Martina</b>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<i>etiquetas</i>', $html);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Hijos', $html);
        $this->assertStringContainsString('Hola, &lt;b&gt;Martina&lt;/b&gt;.', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;i&gt;etiquetas&lt;/i&gt; &amp; más', $html);
    }

    /**
     * La nota personal va debajo de la introducción, un párrafo por cada línea.
     *
     * @return void
     */
    public function test_la_nota_va_debajo_de_la_introduccion_un_parrafo_por_linea()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('instalado'));

        $html = ImplementacionMailService::previa($impl, 'instalado', [
            'nota' => "Martina, cualquier cosa me escribís.\n\n  Hablamos mañana.  ",
        ], null)['html'];

        $intro = strpos($html, 'Lo dejamos configurado con lo que nos contaste');
        $nota1 = strpos($html, 'Martina, cualquier cosa me escribís.</p>');
        $nota2 = strpos($html, 'Hablamos mañana.</p>');
        $pedido = strpos($html, 'Lo que necesitamos');

        $this->assertNotFalse($intro);
        $this->assertNotFalse($nota1, 'La primera línea es un párrafo.');
        $this->assertNotFalse($nota2, 'La segunda línea es otro párrafo, sin los espacios de los costados.');
        $this->assertTrue($intro < $nota1 && $nota1 < $nota2 && $nota2 < $pedido, 'La nota va después de la intro y antes del bloque del hito.');

        // Sin nota, no hay párrafos de más.
        $sin_nota = ImplementacionMailService::previa($impl, 'instalado', [], null)['html'];
        $this->assertStringNotContainsString('margin:16px 0 0 0;font-family', $sin_nota);
    }

    /**
     * Sin nombre usable el saludo es "Hola." a secas, igual que la carta de acceso a la demo. "Cliente"
     * es el relleno con el que el sistema crea un cliente sin nombre: tampoco se usa.
     *
     * @return void
     */
    public function test_sin_nombre_el_saludo_es_hola_a_secas()
    {
        foreach ([' ', 'Cliente', 'cliente'] as $nombre) {
            $client = $this->crear_cliente(['name' => $nombre, 'email' => 'dueno@ejemplo.test']);
            $impl   = $this->crear_implementacion($client, $this->estados_del_hito('instalado'));

            $html = ImplementacionMailService::previa($impl, 'instalado', [], null)['html'];

            $this->assertStringContainsString('>Hola.</p>', $html, 'Nombre: "' . $nombre . '"');
        }

        // Y con apellido, solo el primer nombre.
        $client = $this->crear_cliente(['name' => 'María Laura Pérez', 'email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('instalado'));
        $html   = ImplementacionMailService::previa($impl, 'instalado', [], null)['html'];

        $this->assertStringContainsString('>Hola, María.</p>', $html);
        $this->assertStringNotContainsString('Pérez', $html);
    }

    /**
     * El negocio sale del formulario (`setup_data.company_name`) y, si no está, de la ficha. Sin
     * ninguno, el mail habla de "tu negocio".
     *
     * @return void
     */
    public function test_el_negocio_sale_del_formulario_y_si_no_de_la_ficha()
    {
        $client = $this->crear_cliente([
            'company_name' => 'Nombre de la ficha',
            'setup_data'   => ['company_name' => 'Nombre del formulario'],
            'email'        => 'dueno@ejemplo.test',
        ]);
        $impl = $this->crear_implementacion($client, $this->estados_del_hito('instalado'));

        $html = ImplementacionMailService::previa($impl, 'instalado', [], null)['html'];
        $this->assertStringContainsString('Implementación · Nombre del formulario', $html);
        $this->assertStringContainsString('implementando ComercioCity en Nombre del formulario.', $html);
        $this->assertStringNotContainsString('Nombre de la ficha', $html);

        $client2 = $this->crear_cliente(['company_name' => 'Nombre de la ficha', 'email' => 'otro@ejemplo.test']);
        $impl2   = $this->crear_implementacion($client2, $this->estados_del_hito('instalado'));
        $html2   = ImplementacionMailService::previa($impl2, 'instalado', [], null)['html'];
        $this->assertStringContainsString('Implementación · Nombre de la ficha', $html2);

        $client3 = $this->crear_cliente(['company_name' => null, 'email' => 'tercero@ejemplo.test']);
        $impl3   = $this->crear_implementacion($client3, $this->estados_del_hito('instalado'));
        $html3   = ImplementacionMailService::previa($impl3, 'instalado', [], null)['html'];
        $this->assertStringContainsString('>Implementación</p>', $html3, 'Sin negocio, el rótulo es solo "Implementación".');
        $this->assertStringContainsString('implementando ComercioCity en tu negocio.', $html3);
    }

    /**
     * La firma y el logo salen de la configuración; sin logo cargado se muestra el nombre de la marca.
     *
     * @return void
     */
    public function test_la_firma_y_el_logo_salen_de_la_configuracion()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('instalado'));

        $html = ImplementacionMailService::previa($impl, 'instalado', [], null)['html'];

        $this->assertStringContainsString('>Lucas González</p>', $html);
        $this->assertStringContainsString('>Fundador de ComercioCity</p>', $html);
        $this->assertStringContainsString('<img src="' . config('commerciocity.logo_url') . '" alt="ComercioCity" width="56" height="56"', $html);

        config([
            'commerciocity.implementacion_mail.firma_nombre' => 'Otra Persona',
            'commerciocity.implementacion_mail.firma_rol'    => 'Soporte',
            'commerciocity.logo_url'                         => '',
        ]);

        $otro = ImplementacionMailService::previa($impl, 'instalado', [], null)['html'];

        $this->assertStringContainsString('>Otra Persona</p>', $otro);
        $this->assertStringContainsString('>Soporte</p>', $otro);
        $this->assertStringNotContainsString('<img', $otro);
        $this->assertStringContainsString('>ComercioCity</span>', $otro);
    }

    /**
     * La versión de texto plano dice lo mismo que la tarjeta y NO escapa: "Pinturas & Más" sale tal
     * cual, mientras que en el HTML sale como "&amp;".
     *
     * @return void
     */
    public function test_la_version_de_texto_plano_sale_sin_escapar()
    {
        Mail::fake();

        $client = $this->crear_cliente(['company_name' => 'Pinturas & Más "El Sol"', 'email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('acceso'));

        ImplementacionMailService::enviar($impl, 'acceso', ['articulos' => 2418, 'nota' => 'Cualquier cosa: l\'avisás'], null, false);

        $enviados = Mail::sent(ImplementacionMail::class);
        $this->assertCount(1, $enviados);

        // Con Mail::fake() el contenedor devuelve el falso y `render()` no existe: se arma el
        // mailable a mano (`build()` fija las dos vistas) y se renderiza cada una.
        $mailable = $enviados->first();
        app()->call([$mailable, 'build']);

        $html  = view($mailable->view, $mailable->buildViewData())->render();
        $texto = (string) $mailable->textView;

        $this->assertStringContainsString('Pinturas &amp; Más &quot;El Sol&quot;', $html);

        $this->assertStringContainsString('Pinturas & Más "El Sol"', $texto);
        $this->assertStringNotContainsString('&amp;', $texto);
        $this->assertStringNotContainsString('&quot;', $texto);
        $this->assertStringContainsString("l'avisás", $texto);
        $this->assertStringContainsString('Tu catálogo ya está adentro.', $texto);
        $this->assertStringContainsString('2.418 artículos cargados en tu sistema', $texto);
        $this->assertStringContainsString(self::URL_DEL_SISTEMA, $texto);
        $this->assertStringContainsString('[>] Cargamos tu catálogo (en curso)', $texto);
        $this->assertStringNotContainsString('<table', $texto);
        $this->assertStringNotContainsString('{{', $texto);
    }

    /**
     * Por el mailer real el mail sale multipart: la tarjeta HTML y la versión de texto plano.
     *
     * @return void
     */
    public function test_por_el_mailer_real_el_mail_sale_con_las_dos_versiones()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('instalado'));

        // El transporte `array` guarda lo que se manda en memoria: es el mailer real, sin red.
        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('enviado', $resultado['estado']);

        $mensajes = app('mail.manager')->mailer(ImplementacionMailService::MAILER)
            ->getSwiftMailer()->getTransport()->messages();

        $this->assertCount(1, $mensajes);

        $mensaje = $mensajes->first();
        $this->assertSame('Tu sistema ya está instalado', $mensaje->getSubject());
        $this->assertSame(['dueno@ejemplo.test'], array_keys($mensaje->getTo()));
        $this->assertStringContainsString('<table', $mensaje->getBody());

        $tipos = array_map(function ($parte) {
            return $parte->getContentType();
        }, $mensaje->getChildren());

        $this->assertContains('text/plain', $tipos, 'Además de la tarjeta, viaja la versión de texto plano.');
    }
}
