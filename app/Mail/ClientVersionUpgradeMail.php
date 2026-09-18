<?php

namespace App\Mail;

/**
 * El mail que le avisa al dueño que le actualizamos el sistema, con las novedades adentro.
 *
 * 🔴 **No trae layout ni blade propio.** Extiende `ComercioCityMail` y lo único que agrega es la
 * fábrica que arma el `ComercioCityMailPayload`: el look-and-feel de los mails del sistema vive en
 * `emails/commerciocity/layout.blade.php` y sus partials, y ese es el que se usa. Un mail nuevo
 * con estética propia es un segundo lugar donde vive la marca, y los dos se desincronizan.
 *
 * Las novedades salen como `detail_lines` —una fila "Título: cuerpo" por novedad— y no como
 * párrafos sueltos: el `body.blade.php` las renderiza con viñeta y el título en negrita, que es
 * exactamente lo que hace legible una lista de seis cosas nuevas. Con `paragraphs` se perdería
 * dónde termina una novedad y empieza la otra.
 *
 * 🔴 **Lo lee un comerciante.** Nada de rutas de código, nombres de clase, números de commit ni
 * jerga. El número de versión aparece una sola vez, en el asunto, porque es lo que le permite
 * distinguir un aviso de otro en la bandeja; el titular habla de lo que gana, no de la versión.
 */
class ClientVersionUpgradeMail extends ComercioCityMail
{
    /**
     * Arma el mail del aviso.
     *
     * @param string $nombre_del_negocio Nombre del comercio, para el saludo. Vacío = se saluda sin nombre.
     * @param string $version            Número de la versión a la que quedó el cliente (p. ej. "4.0.25").
     * @param array<int, array<string, string>> $novedades Cada ítem: title (el titular de la novedad)
     *                                                     y body (el texto). Nunca vacío: si no hay
     *                                                     novedades, este mail no se arma.
     *
     * @return self
     */
    public static function armar(string $nombre_del_negocio, string $version, array $novedades): self
    {
        $saludo = trim($nombre_del_negocio) !== ''
            ? 'Hola, ' . trim($nombre_del_negocio) . '.'
            : 'Hola.';

        $asunto = trim($version) !== ''
            ? 'Actualizamos tu sistema — novedades de la versión ' . trim($version)
            : 'Actualizamos tu sistema — esto es lo nuevo';

        return new self(new ComercioCityMailPayload([
            'subject' => $asunto,

            // El titular habla del beneficio. El número de versión ya está en el asunto.
            'title' => 'Ya tenés las mejoras nuevas en tu sistema',

            'preheader' => 'Actualizamos tu sistema. Esto es lo que trae de nuevo.',

            'paragraphs' => [
                $saludo . ' Actualizamos tu sistema y ya está andando con lo último. No tenés que '
                    . 'hacer nada: la próxima vez que entres, lo vas a tener ahí.',
                'Esto es lo que trae de nuevo:',
            ],

            'detail_lines' => self::lineas_de_novedades($novedades),

            // 🔴 Las dudas van al WhatsApp de SOPORTE, no al número del asistente que manda este
            // aviso: ese número solo contesta si el dueño tiene `asistente_whatsapp_activo`, y
            // para la mayoría del parque no lo tiene — la pregunta quedaría sin respuesta.
            'closing' => 'Si tenés alguna duda sobre estas novedades, escribinos por el WhatsApp '
                . 'de soporte, el de siempre.',
        ]));
    }

    /**
     * Pasa las novedades al formato de `detail_lines` del payload, descartando las que quedarían
     * como una viñeta vacía.
     *
     * @param array<int, array<string, string>> $novedades Cada ítem: title, body.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lineas_de_novedades(array $novedades): array
    {
        $lineas = [];

        foreach ($novedades as $novedad) {
            if (! is_array($novedad)) {
                continue;
            }

            $titulo = isset($novedad['title']) ? trim((string) $novedad['title']) : '';
            $texto  = isset($novedad['body']) ? trim((string) $novedad['body']) : '';

            // Sin titular no hay viñeta posible: el blade renderiza "label: value" y quedaría
            // empezando con dos puntos.
            if ($titulo === '') {
                continue;
            }

            $lineas[] = [
                'label'      => $titulo,
                'value'      => $texto,
                'bold_label' => true,
            ];
        }

        return $lineas;
    }
}
