<?php

namespace App\Exceptions;

/**
 * El servidor de correo RECHAZÓ la casilla del destinatario: el mail no salió.
 *
 * Existe porque SwiftMailer no tira nada cuando eso pasa. Si el servidor SMTP contesta 550 en el
 * RCPT TO ("User unknown", un dominio que no existe, un buzón lleno), `send()` vuelve normal y las
 * casillas rechazadas quedan en `failures()` del mailer. Quien solo atrapa excepciones da por enviado
 * un mail que nunca salió. `RechazosDeCorreoHelper::fallar_si_hubo_rechazos()` convierte ese rechazo
 * silencioso en ESTA excepción, para que lo atrape el mismo `catch` que ya existe en cada punto de
 * envío y haga lo que hace con cualquier otro fallo: no anotar el envío como hecho y dejar el motivo
 * donde quien opera el panel lo ve.
 *
 * 🔴 **El mensaje NO lleva la casilla.** `getMessage()` devuelve siempre `MOTIVO`, igual para una
 * casilla o para diez. La dirección entera ya está en la ficha del lead, al lado del error, y
 * repetirla en cada log, en cada respuesta de la API y en cada pantalla es regar los datos de una
 * persona por todos lados (mismo criterio que el aviso de actualización al cliente). Quien necesite
 * las direcciones —para un log de diagnóstico, por ejemplo— las pide con `rechazadas()`.
 *
 * Extiende `\RuntimeException` y no `\InvalidArgumentException` a propósito: no es un argumento
 * malo, es un fallo de entrega en tiempo de ejecución, y los `catch (\Throwable)` de los puntos de
 * envío la atrapan igual que a cualquier otra.
 */
class MailRechazadoPorElServidorException extends \RuntimeException
{
    /**
     * Lo que se le muestra a quien opera el panel, y lo que queda en la columna `*_mail_last_error`
     * del lead. Dice qué pasó y qué hacer, sin jerga de SMTP.
     */
    const MOTIVO = 'el servidor de correo rechazó la casilla del destinatario (no existe o no acepta mensajes). '
        . 'Corregí el email en la ficha y volvé a enviar.';

    /**
     * Las casillas que el servidor rechazó. Se guardan aparte del mensaje (ver el docblock de la clase).
     *
     * @var array<int, string>
     */
    private $rechazadas = [];

    /**
     * @param array<int, string> $rechazadas Las casillas rechazadas, tal como las devuelve
     *                                       `RechazosDeCorreoHelper::del_ultimo_envio()`.
     */
    public function __construct(array $rechazadas = [])
    {
        parent::__construct(self::MOTIVO);

        $this->rechazadas = array_values($rechazadas);
    }

    /**
     * Las casillas que el servidor rechazó, para quien las quiera loguear.
     *
     * @return array<int, string>
     */
    public function rechazadas(): array
    {
        return $this->rechazadas;
    }
}
