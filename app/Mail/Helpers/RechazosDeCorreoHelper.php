<?php

namespace App\Mail\Helpers;

use Illuminate\Support\Facades\Mail;

/**
 * Qué casillas rechazó el servidor de correo en el último envío de un mailer.
 *
 * OJO: que `send()` no haya tirado NO quiere decir que el mail haya salido. Cuando el servidor SMTP
 * rechaza una casilla en el RCPT TO (550 "User unknown", un dominio que no existe, un buzón lleno)
 * SwiftMailer no tira ninguna excepción: `send()` vuelve normal y las casillas rechazadas quedan en
 * `failures()` del mailer. Quien solo atrapa la excepción da por enviado un mail que nunca salió, y
 * encima sigue de largo como si hubiera salido: guarda la casilla rechazada en la ficha del cliente
 * y avisa por otro canal que "ya te mandamos un mail".
 *
 * Es lo mismo en todos los envíos por SMTP del admin, y por eso la lectura vive acá y no copiada en
 * cada servicio: hoy la usan `ImplementacionMailService` (los mails de la implementación) y
 * `AvisoDeActualizacionService` (el aviso de actualización al dueño).
 *
 * Se lee de `Mail::mailer($nombre)->failures()`, que es la MISMA instancia del mailer que mandó el
 * mail (el administrador de mails guarda cada mailer ya armado), y que arranca vacía en cada envío.
 * Bajo `Mail::fake()` el falso también responde `failures()` y devuelve vacío.
 */
class RechazosDeCorreoHelper
{
    /**
     * Las casillas que el servidor rechazó en el ÚLTIMO envío del mailer.
     *
     * Hay que llamarlo inmediatamente después del `send()`: el mailer reinicia la lista en cada envío.
     *
     * Si la lista no se puede leer (un mailer de mentira que no responde `failures()`, por ejemplo),
     * se devuelve vacío: no hay con qué decir que el servidor rechazó nada, y no se inventa un fallo
     * sobre un mail que pudo haber salido bien.
     *
     * @param string $mailer Nombre del mailer en `config/mail.php` (`admin`).
     *
     * @return array<int, string> Las casillas rechazadas, sin repetir. Vacío = el servidor aceptó a todas.
     */
    public static function del_ultimo_envio(string $mailer): array
    {
        try {
            $rechazadas = Mail::mailer($mailer)->failures();
        } catch (\Throwable $excepcion) {
            return [];
        }

        if (! is_array($rechazadas)) {
            return [];
        }

        $salida = [];

        foreach ($rechazadas as $rechazada) {
            if (! is_scalar($rechazada)) {
                continue;
            }

            $casilla = trim((string) $rechazada);

            if ($casilla !== '' && ! in_array($casilla, $salida, true)) {
                $salida[] = $casilla;
            }
        }

        return $salida;
    }
}
