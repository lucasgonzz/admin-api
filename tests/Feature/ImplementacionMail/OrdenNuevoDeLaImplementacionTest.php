<?php

namespace Tests\Feature\ImplementacionMail;

use App\Mail\ImplementacionMail;
use App\Models\AdminSetting;
use App\Models\Implementation;
use App\Services\ImplementacionMailService;
use Illuminate\Support\Facades\Mail;

/**
 * Los mails de hito en el orden nuevo de `/implementar` (Lucas, 14/10/2026; misión
 * mails-implementacion-orden-nuevo).
 *
 * Las tres reglas que los mails tienen que respetar, y lo que protege cada test:
 *
 *  1. 🔴 Al cliente no se le dice que su sistema quedó instalado hasta la entrega. Al completar el
 *     formulario sale `instalando` («ya estamos instalando»), que pide los archivos y nunca dice
 *     «ya está instalado».
 *  2. 🔴 El centro de recursos sale recién en la entrega: `instalando` no lo lleva.
 *  3. 🔴 La videollamada no se nombra en ningún lado: ni en «lo que sigue» de la entrega (`listo`) ni
 *     en la línea de progreso, que sale en TODOS los mails (su etapa 8 se llamaba "Videollamada de
 *     cierre").
 *
 * Y que la entrega (`listo`) lleve la tarjeta para entrar SIN la contraseña: el mail nunca la lleva,
 * va por WhatsApp. `instalado` y `acceso` siguen aceptados (compatibilidad): lo cubren los tests de
 * render y de envío, que recorren todos los hitos.
 */
class OrdenNuevoDeLaImplementacionTest extends BaseDelMailDeImplementacion
{
    /**
     * El HTML y el texto plano del mail de un hito tal como salen por el mailer.
     *
     * @param Implementation       $impl
     * @param string               $hito
     * @param array<string, mixed> $datos
     *
     * @return array{asunto: string, html: string, texto: string, faltan: array<int, string>}
     */
    private function mail_de(Implementation $impl, string $hito, array $datos): array
    {
        $previa = ImplementacionMailService::previa($impl, $hito, $datos, null);

        Mail::fake();
        $resultado = ImplementacionMailService::enviar($impl, $hito, $datos, null, true);
        $this->assertSame('enviado', $resultado['estado'], $hito . ': el mail no salió');

        $texto = null;
        Mail::assertSent(ImplementacionMail::class, function ($mail) use (&$texto) {
            $mail->build();
            $texto = (string) $mail->textView;

            return true;
        });

        return ['asunto' => $previa['asunto'], 'html' => $previa['html'], 'texto' => (string) $texto, 'faltan' => $previa['faltan']];
    }

    /**
     * 1 y 2. `instalando` dice que estamos instalando, pide los archivos (la lista, el código en común y
     * cómo mandarlos) y no dice que el sistema quedó instalado ni lleva el centro de recursos. Ni en la
     * HTML ni en el texto plano.
     *
     * @return void
     */
    public function test_instalando_pide_los_archivos_sin_decir_que_ya_esta_instalado()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('instalando'));

        $mail = $this->mail_de($impl, 'instalando', []);

        $this->assertSame('Recibimos tu formulario: ya estamos instalando tu sistema', $mail['asunto']);
        $this->assertSame([], $mail['faltan']);

        foreach (['html' => $mail['html'], 'texto' => $mail['texto']] as $version => $contenido) {
            $this->assertStringContainsString('Ya estamos instalando tu sistema.', $contenido, $version);
            $this->assertStringContainsString('Recibimos tu formulario y estamos instalando tu sistema con lo que nos contaste.', $contenido, $version);
            $this->assertStringContainsString('ya podés mandarnos la información que necesitamos para cargarlo', $contenido, $version);

            // El pedido de archivos, completo.
            foreach (['Tus artículos', 'Tus clientes', 'Tus proveedores', 'Las fotos de tus productos', 'el mismo código que figura en tu Excel', 'Mandanos todo por WhatsApp'] as $pedido) {
                $this->assertStringContainsString($pedido, $contenido, $version . ': falta "' . $pedido . '"');
            }

            // 🔴 Lo que NO dice.
            $this->assertStringNotContainsString('ya está instalado', $contenido, $version);
            $this->assertStringNotContainsStringIgnoringCase('tutoriales', $contenido, $version);
            $this->assertStringNotContainsStringIgnoringCase('centro de recursos', $contenido, $version);
            $this->assertStringNotContainsString((string) config('commerciocity.implementacion_mail.recursos_url'), $contenido, $version);
            $this->assertStringNotContainsString('recursos.comerciocity.com', $contenido, $version);
            $this->assertStringNotContainsStringIgnoringCase('videollamada', $contenido, $version);
        }

        // La línea de progreso dice la verdad: la instalación está en curso.
        $this->assertStringContainsString('Etapa 2 de 8', $mail['html']);
    }

    /**
     * `instalando` no pide nada de la implementación: sale aunque el cliente todavía no tenga dirección
     * de sistema (en ese momento recién se está instalando) ni link de formulario.
     *
     * @return void
     */
    public function test_instalando_sale_sin_direccion_de_sistema_ni_link_de_formulario()
    {
        // Sin la URL base del formulario, la implementación no tiene link (lo arma el setUp de la base).
        AdminSetting::where('key', 'implementation_form_url')->delete();

        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test'], false);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('instalando'));

        $this->assertSame('', trim((string) $impl->fresh()->form_link), 'El escenario tendría que quedar sin link de formulario.');

        $previa = ImplementacionMailService::previa($impl, 'instalando', [], null);

        $this->assertSame([], $previa['faltan']);
    }

    /**
     * `instalando` no lleva datos propios: vacío es válido, la nota también, y cualquier otra clave
     * (aunque sea de otro hito) es un error que dice que solo acepta `nota`.
     *
     * @return void
     */
    public function test_instalando_no_acepta_datos_propios()
    {
        $this->assertSame([], ImplementacionMailService::validar_datos('instalando', []));
        $this->assertSame([], ImplementacionMailService::validar_datos('instalando', ['nota' => 'Cualquier cosa, avisanos.']));

        foreach (['articulos' => 10, 'resumen' => ['articulos' => 10], 'recursos_url' => 'https://recursos.comerciocity.com/'] as $clave => $valor) {
            $errores = ImplementacionMailService::validar_datos('instalando', [$clave => $valor]);

            $this->assertSame([$clave], array_keys($errores), $clave);
            $this->assertStringContainsString('Solo acepta `nota`', $errores[$clave], $clave);
        }
    }

    /**
     * 3. La entrega (`listo`) no nombra la videollamada, con ARCA o sin ARCA, en la HTML ni en el texto.
     * «Lo que sigue» es terminar el catálogo, ARCA (si aplica) y el soporte.
     *
     * @return void
     */
    public function test_la_entrega_no_nombra_la_videollamada()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('listo'));

        foreach ([true, false] as $con_arca) {
            $datos = array_merge($this->datos_de_ejemplo('listo'), ['arca' => $con_arca]);
            $mail  = $this->mail_de($impl->fresh(), 'listo', $datos);

            foreach (['html' => $mail['html'], 'texto' => $mail['texto']] as $version => $contenido) {
                $caso = $version . ($con_arca ? ' con ARCA' : ' sin ARCA');

                $this->assertStringNotContainsStringIgnoringCase('videollamada', $contenido, $caso);
                $this->assertStringContainsString('Cuando tengamos todos tus artículos, terminamos tu catálogo: las categorías, las fotos y las descripciones que falten.', $contenido, $caso);
                $this->assertStringContainsString('Soporte por WhatsApp, siempre con una persona del otro lado.', $contenido, $caso);

                if ($con_arca) {
                    $this->assertStringContainsString('Conectamos la facturación electrónica con ARCA.', $contenido, $caso);
                } else {
                    $this->assertStringNotContainsString('ARCA', $contenido, $caso);
                }

                // Sale con la primera tanda: no dice que terminamos de cargar ni que ya factura.
                $this->assertStringNotContainsString('Terminamos de cargar', $contenido, $caso);
                $this->assertStringNotContainsString('facturar con tus datos', $contenido, $caso);
            }
        }
    }

    /**
     * La entrega lleva la tarjeta para entrar —dirección, usuario = documento, contraseña = la del
     * WhatsApp—, el botón para entrar, el aviso de cambiar la contraseña y el botón de los tutoriales.
     * 🔴 Y no hay forma de meterle una contraseña: `listo` rechaza cualquier clave que no sea suya.
     *
     * @return void
     */
    public function test_la_entrega_lleva_la_tarjeta_de_acceso_sin_la_contrasena()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('listo'));

        $mail = $this->mail_de($impl, 'listo', $this->datos_de_ejemplo('listo'));

        $this->assertSame([], $mail['faltan']);

        $html = $mail['html'];
        $this->assertStringContainsString('Cómo entrar', $html);
        $this->assertStringContainsString('>Dirección</td>', $html);
        $this->assertStringContainsString('href="' . self::URL_DEL_SISTEMA . '"', $html);
        $this->assertStringContainsString('>losandes.comerciocity.com</a>', $html);
        $this->assertStringContainsString('>Usuario</td>', $html);
        $this->assertStringContainsString('Tu número de documento', $html);
        $this->assertStringContainsString('>Contraseña</td>', $html);
        $this->assertStringContainsString('La que te mandamos por WhatsApp', $html);
        $this->assertStringContainsString('Entrar a mi sistema', $html);
        $this->assertStringContainsString('Cuando entres, cambiá la contraseña desde la configuración.', $html);
        $this->assertStringContainsString('Ver los tutoriales', $html);

        // La tarjeta va después de lo cargado y antes de "lo que sigue".
        $this->assertTrue(strpos($html, 'Lo que quedó cargado') < strpos($html, 'Cómo entrar'));
        $this->assertTrue(strpos($html, 'Cómo entrar') < strpos($html, 'Lo que sigue'));

        $texto = $mail['texto'];
        $this->assertStringContainsString('Dirección: losandes.comerciocity.com', $texto);
        $this->assertStringContainsString('Usuario: tu número de documento', $texto);
        $this->assertStringContainsString('Contraseña: la que te mandamos por WhatsApp', $texto);
        $this->assertStringContainsString('Entrar a mi sistema: ' . self::URL_DEL_SISTEMA, $texto);
        $this->assertStringContainsString('Cuando entres, cambiá la contraseña desde la configuración.', $texto);

        foreach (['contrasena', 'clave', 'password'] as $clave) {
            $errores = ImplementacionMailService::validar_datos('listo', array_merge($this->datos_de_ejemplo('listo'), [$clave => '1234']));

            $this->assertSame([$clave], array_keys($errores), $clave);
        }
    }

    /**
     * Sin dirección de sistema la entrega no se puede mandar (`faltan: url_sistema`, como antes) y la
     * vista previa no muestra una tarjeta de acceso a ninguna parte.
     *
     * @return void
     */
    public function test_la_entrega_sin_direccion_no_muestra_la_tarjeta()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test'], false);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('listo'));

        $previa = ImplementacionMailService::previa($impl, 'listo', $this->datos_de_ejemplo('listo'), null);

        $this->assertContains('url_sistema', $previa['faltan']);
        $this->assertStringNotContainsString('Cómo entrar', $previa['html']);
        $this->assertStringNotContainsString('Cuando entres, cambiá la contraseña', $previa['html']);
        $this->assertStringContainsString('Ver los tutoriales', $previa['html']);
    }

    /**
     * 3. La línea de progreso sale en TODOS los mails: ninguno nombra la videollamada, y la etapa 8 se
     * llama "Seguimos con soporte". La 6 es "Terminamos tu catálogo" (los tutoriales salen en la
     * entrega). En la HTML y en el texto.
     *
     * @return void
     */
    public function test_ningun_mail_nombra_la_videollamada()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client);

        foreach (ImplementacionMailService::HITOS as $hito) {
            $this->poner_las_etapas($impl, $this->estados_del_hito($hito));

            $mail = $this->mail_de($impl->fresh(), $hito, $this->datos_de_ejemplo($hito));

            foreach (['html' => $mail['html'], 'texto' => $mail['texto']] as $version => $contenido) {
                $this->assertStringNotContainsStringIgnoringCase('videollamada', $contenido, $hito . ' (' . $version . ')');
                $this->assertStringContainsString('Seguimos con soporte', $contenido, $hito . ' (' . $version . ')');
                $this->assertStringContainsString('Terminamos tu catálogo', $contenido, $hito . ' (' . $version . ')');
                $this->assertStringNotContainsString('Tu equipo y los tutoriales', $contenido, $hito . ' (' . $version . ')');
            }
        }
    }
}
