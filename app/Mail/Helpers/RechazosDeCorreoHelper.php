<?php

namespace App\Mail\Helpers;

use App\Exceptions\MailRechazadoPorElServidorException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Qué casillas rechazó el servidor de correo en el último envío de un mailer.
 *
 * OJO: que `send()` no haya tirado NO quiere decir que el mail haya salido. Cuando el servidor SMTP
 * rechaza una casilla en el RCPT TO (550 "User unknown", un dominio que no existe, un buzón lleno)
 * SwiftMailer no tira ninguna excepción: `send()` vuelve normal y las casillas rechazadas quedan en
 * `failures()` del mailer. Quien solo atrapa la excepción da por enviado un mail que nunca salió, y
 * encima sigue de largo como si hubiera salido: guarda la casilla rechazada en la ficha del cliente,
 * marca el lead como "mail enviado" y avisa por otro canal que "ya te mandamos un mail".
 *
 * Es lo mismo en todos los envíos por SMTP del admin, y por eso la lectura vive acá y no copiada en
 * cada servicio. Hoy la usan:
 *
 *   - `ImplementacionMailService` (los mails de la implementación) y `AvisoDeActualizacionService` (el
 *     aviso de actualización al dueño): por el mailer `admin`, con `del_ultimo_envio('admin')`.
 *   - `LeadController` (los seis envíos del panel a un lead) y `LeadAiService` (los tres envíos que
 *     dispara la IA): por el mailer POR DEFECTO, con `fallar_si_hubo_rechazos()`.
 *
 * 🔴 **Toda llamada nueva a `Mail::...->send()` en `app/` tiene que mirar los rechazos.** No es un
 * pedido de buena voluntad: `TodoEnvioDeMailMiraLosRechazosTest` falla si aparece un envío sin su
 * chequeo, porque el décimo punto de envío nace con el mismo hueco que los otros nueve.
 *
 * Y 🔴 **no se reemplaza por `method_exists(Mail::getFacadeRoot(), 'failures')`.** Es lo que tenía
 * `LeadController::send_followup_mail_json`, y NUNCA corrió: la raíz de la fachada es el
 * `MailManager`, que no tiene `failures()` (le llega por `__call`, que `method_exists` no ve), así que
 * el chequeo daba siempre `false` y el rechazo pasaba como un envío exitoso. Un chequeo que se ve
 * correcto y no se ejecuta nunca es peor que no tenerlo.
 *
 * Se lee de `Mail::mailer($nombre)->failures()`, que es la MISMA instancia del mailer que mandó el
 * mail (el administrador de mails guarda cada mailer ya armado), y que arranca vacía en cada envío.
 * Bajo `Mail::fake()` el falso también responde `failures()` y devuelve vacío.
 *
 * 🔴 **Límites que hay que saber (medidos con el SwiftMailer 6.3 de `vendor/` contra un SMTP de mentira).**
 *
 *   - `failures()` lista CUALQUIER destinatario rechazado, no solo el principal. Hoy ningún mailable de
 *     leads agrega `cc` ni `bcc`, así que "hubo rechazos" significa "el lead no recibió el mail". El día que
 *     uno agregue una copia interna, un rechazo de ESA copia marcaría como fallido un mail que SÍ llegó al
 *     lead (y un reintento se lo mandaría dos veces): ahí hay que mirar si la casilla rechazada es la del lead.
 *   - Entran a `failures()` los rechazos temporales (un 451 de greylisting, un 452 de buzón lleno) igual que
 *     un 550: con 4xx la casilla puede estar bien. Por eso el motivo que ve el operador dice "no existe, está
 *     llena o no acepta mensajes por ahora" y no "la casilla está mal".
 *   - Solo ve el rechazo SÍNCRONO en el RCPT TO. Un servidor que acepta el mail y después lo devuelve (rebote
 *     asincrónico al buzón remitente) no se ve desde acá.
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
     * 🔴 **`null` es el mailer por defecto, y se lee con `Mail::mailer(null)`, no con `Mail::failures()`.**
     * Los envíos a leads usan `Mail::to()`, que va al mailer por defecto; `Mail::mailer(null)` devuelve
     * ESA misma instancia (`MailManager::mailer()` resuelve `null` al driver por defecto). `Mail::failures()`
     * a secas también llegaría al mailer por defecto, pero por `__call` y sin dejar claro de qué mailer
     * se habla: la lectura por nombre es una sola para los dos casos y es la que hay que mockear en un test.
     *
     * @param string|null $mailer Nombre del mailer en `config/mail.php` (`admin`), o null para el mailer por
     *                            defecto (el que usa `Mail::to()` a secas: los mails a leads).
     *
     * @return array<int, string> Las casillas rechazadas, sin repetir. Vacío = el servidor aceptó a todas.
     */
    public static function del_ultimo_envio(?string $mailer = null): array
    {
        try {
            $rechazadas = Mail::mailer($mailer)->failures();
        } catch (\Throwable $excepcion) {
            // 🔴 No se inventa un fallo, pero tampoco se calla: si `failures()` deja de responder (un upgrade de
            // Laravel, un typo), los envíos a leads y a clientes volverían EN SILENCIO a darse por enviados aunque el
            // servidor los rechace, que es justo el defecto que este helper existe para evitar. Un renglón en el log
            // hace que ese día se note. Bajo `Mail::fake()` no se pasa por acá: el falso sí responde `failures()`.
            Log::warning('RechazosDeCorreoHelper: no se pudo leer failures() del mailer; se asume que el servidor no rechazó nada.', [
                'mailer' => $mailer === null ? '(por defecto)' : $mailer,
                'error'  => $excepcion->getMessage(),
            ]);

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

    /**
     * Tira `MailRechazadoPorElServidorException` si el último envío del mailer tuvo casillas rechazadas.
     *
     * Es para los puntos de envío que ya tienen un `try { ... } catch (\Throwable)` que anota el fallo
     * (los del panel de leads y los de la IA): una línea justo después del `send()` y antes de escribir
     * ninguna marca de "enviado" convierte el rechazo silencioso en una excepción más, que el `catch`
     * existente trata como cualquier otro fallo. No hace falta un camino de registro nuevo en cada
     * punto: que `send()` tire o que el servidor rechace termina en el mismo lugar.
     *
     * Los puntos que arman su propio registro de error a partir de la lista (los dos servicios del
     * mailer `admin`) siguen usando `del_ultimo_envio()` directo.
     *
     * Conserva la regla de `del_ultimo_envio()`: si no se puede leer la lista (`Mail::fake()`, un mock sin
     * `failures()`), no tira. Los tests que verifican "qué mail sale y a quién" con `Mail::fake()` no cambian.
     *
     * @param string|null $mailer Nombre del mailer, o null para el mailer por defecto (ver `del_ultimo_envio()`).
     *
     * @return void
     *
     * @throws MailRechazadoPorElServidorException Si el servidor rechazó al menos una casilla.
     */
    public static function fallar_si_hubo_rechazos(?string $mailer = null): void
    {
        $rechazadas = self::del_ultimo_envio($mailer);

        if (! empty($rechazadas)) {
            throw new MailRechazadoPorElServidorException($rechazadas);
        }
    }
}
