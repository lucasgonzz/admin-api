<?php

/*
 * El servidor SMTP de mentira de los tests: un proceso de PHP que escucha en 127.0.0.1 y habla el
 * mínimo de ESMTP que necesita SwiftMailer (EHLO, AUTH LOGIN, MAIL FROM, RCPT TO, DATA, RSET y QUIT).
 *
 * No es una clase ni se carga con el autoload: lo lanza `Tests\Fakes\ServidorSmtpFake` como un proceso
 * aparte, con el PHP del propio test, y lee de su salida el puerto en el que quedó escuchando.
 *
 * Uso:   php servidor_smtp_fake.php <rechaza|acepta> [segundos de vida]
 *
 * Modos:
 *   rechaza — contesta 550 a cada RCPT TO ("User unknown"), como un servidor real ante una casilla
 *             que no existe. Es el caso que SwiftMailer NO convierte en excepción.
 *   acepta  — contesta 250 a todo: el mail "sale" y el servidor lo descarta.
 *
 * Escucha en un puerto libre que elige el sistema (el 0) y avisa con la línea `PUERTO <n>` en la
 * salida estándar. Se apaga solo a los `segundos de vida` (60 por defecto): si el test que lo
 * lanzó se muere sin poder bajarlo, no queda un proceso colgado.
 *
 * Es el mismo servidor que armó el revisor en Python para demostrar el defecto, pasado a PHP para
 * que la suite no dependa de que haya Python instalado.
 */

$modo     = isset($argv[1]) ? $argv[1] : 'rechaza';
$segundos = isset($argv[2]) ? max(1, (int) $argv[2]) : 60;

$servidor = @stream_socket_server('tcp://127.0.0.1:0', $codigo_de_error, $texto_de_error);

if ($servidor === false) {
    fwrite(STDERR, 'No pude escuchar: ' . $texto_de_error . "\n");
    exit(1);
}

$nombre = (string) stream_socket_get_name($servidor, false);
$puerto = (int) substr($nombre, (int) strrpos($nombre, ':') + 1);

fwrite(STDOUT, 'PUERTO ' . $puerto . "\n");
fflush(STDOUT);

/**
 * Una línea de respuesta del servidor.
 *
 * @param resource $conexion
 * @param string   $texto
 *
 * @return void
 */
function decir($conexion, $texto)
{
    fwrite($conexion, $texto . "\r\n");
}

/**
 * Atiende una conexión hasta que el cliente se despide.
 *
 * @param resource $conexion
 * @param string   $modo     rechaza | acepta
 *
 * @return void
 */
function atender($conexion, $modo)
{
    // Un cliente que se cuelga no tiene que colgar al servidor.
    stream_set_timeout($conexion, 15);

    decir($conexion, '220 localhost ESMTP de prueba');

    $en_datos = false;

    while (($linea = fgets($conexion)) !== false) {
        $texto = rtrim($linea, "\r\n");

        // El cuerpo del mail: se lee hasta la línea con un solo punto y se descarta.
        if ($en_datos) {
            if ($texto === '.') {
                $en_datos = false;
                decir($conexion, '250 2.0.0 OK mail aceptado');
            }

            continue;
        }

        $mayusculas = strtoupper($texto);

        if (strpos($mayusculas, 'EHLO') === 0 || strpos($mayusculas, 'HELO') === 0) {
            // Anuncia AUTH porque el mailer `admin` de los tests lleva usuario y clave, y SwiftMailer
            // exige que el servidor ofrezca un mecanismo si va a autenticarse.
            decir($conexion, '250-localhost');
            decir($conexion, '250 AUTH LOGIN PLAIN');
        } elseif (strpos($mayusculas, 'AUTH LOGIN') === 0) {
            decir($conexion, '334 VXNlcm5hbWU6');
            fgets($conexion);
            decir($conexion, '334 UGFzc3dvcmQ6');
            fgets($conexion);
            decir($conexion, '235 2.7.0 Authentication successful');
        } elseif (strpos($mayusculas, 'AUTH PLAIN') === 0) {
            decir($conexion, '235 2.7.0 Authentication successful');
        } elseif (strpos($mayusculas, 'MAIL FROM') === 0) {
            decir($conexion, '250 2.1.0 OK');
        } elseif (strpos($mayusculas, 'RCPT TO') === 0) {
            if ($modo === 'rechaza') {
                $destino = trim((string) substr($texto, (int) strpos($texto, ':') + 1));
                decir($conexion, '550 5.1.1 ' . $destino . ': Recipient address rejected: User unknown in virtual mailbox table');
            } else {
                decir($conexion, '250 2.1.5 OK');
            }
        } elseif (strpos($mayusculas, 'DATA') === 0) {
            decir($conexion, '354 End data with <CR><LF>.<CR><LF>');
            $en_datos = true;
        } elseif (strpos($mayusculas, 'QUIT') === 0) {
            decir($conexion, '221 2.0.0 Bye');

            return;
        } else {
            // RSET, NOOP y cualquier otra cosa.
            decir($conexion, '250 2.0.0 OK');
        }
    }
}

$limite = time() + $segundos;

while (time() < $limite) {
    $conexion = @stream_socket_accept($servidor, 1);

    if ($conexion === false) {
        continue;
    }

    atender($conexion, $modo);
    fclose($conexion);
}
