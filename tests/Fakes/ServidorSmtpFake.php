<?php

namespace Tests\Fakes;

/**
 * Un servidor SMTP de verdad, en un proceso aparte, para probar lo que SwiftMailer hace con las
 * respuestas reales de un servidor de correo.
 *
 * Existe por un defecto que ningún `Mail::fake()` ni el transporte `array` pueden mostrar: cuando el
 * servidor RECHAZA la casilla (550 en el RCPT TO), SwiftMailer no tira excepción. `send()` vuelve
 * normal y las casillas rechazadas quedan en `Mail::mailer(...)->failures()`. Quien solo mira si
 * `send()` tiró da por enviado un mail que nunca salió. Para ver eso hace falta un servidor que
 * conteste 550 de verdad; el script que lo hace es `servidor_smtp_fake.php`, en esta misma carpeta.
 *
 * Se lanza con el PHP del propio test (`PHP_BINARY`), sin Python ni nada instalado aparte, en un
 * puerto libre que elige el sistema. Si en el entorno no se puede lanzar un proceso, `levantar()`
 * devuelve null y el test que lo pidió se saltea: nunca falla por eso.
 *
 * Uso:
 *
 *   $smtp = ServidorSmtpFake::levantar(ServidorSmtpFake::MODO_RECHAZA);
 *   $smtp->apuntar_el_mailer('admin');
 *   ... el código que manda el mail por el mailer `admin` ...
 *   $smtp->bajar();
 */
class ServidorSmtpFake
{
    /**
     * Contesta 550 a cada RCPT TO: la casilla "no existe".
     */
    const MODO_RECHAZA = 'rechaza';

    /**
     * Acepta todo: el mail "sale" y el servidor lo descarta.
     */
    const MODO_ACEPTA = 'acepta';

    /**
     * Segundos de vida del proceso. Es un techo para que, si el test que lo lanzó muere sin poder
     * bajarlo, no quede un proceso colgado.
     */
    const SEGUNDOS_DE_VIDA = 90;

    /**
     * Puerto en el que escucha.
     *
     * @var int
     */
    public $puerto = 0;

    /**
     * El proceso del servidor.
     *
     * @var resource|null
     */
    private $proceso;

    /**
     * Las puntas de las tuberías del proceso.
     *
     * @var array<int, resource>
     */
    private $tuberias = [];

    /**
     * Lanza el servidor y espera a que diga en qué puerto quedó escuchando.
     *
     * @param string $modo MODO_RECHAZA | MODO_ACEPTA.
     *
     * @return self|null El servidor listo, o null si en este entorno no se puede lanzar un proceso.
     */
    public static function levantar(string $modo): ?self
    {
        if (! function_exists('proc_open') || ! is_string(PHP_BINARY) || PHP_BINARY === '') {
            return null;
        }

        $comando = [
            PHP_BINARY,
            __DIR__ . DIRECTORY_SEPARATOR . 'servidor_smtp_fake.php',
            $modo,
            (string) self::SEGUNDOS_DE_VIDA,
        ];

        $tuberias = [];
        $proceso  = @proc_open($comando, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);

        if (! is_resource($proceso)) {
            return null;
        }

        $servidor           = new self();
        $servidor->proceso  = $proceso;
        $servidor->tuberias = $tuberias;

        // Lo primero que hace el servidor es decir el puerto. Si el proceso murió antes, la tubería
        // se cierra y esto devuelve false.
        $linea = fgets($tuberias[1]);

        if (preg_match('/^PUERTO (\d+)/', (string) $linea, $coincidencia) !== 1) {
            $servidor->bajar();

            return null;
        }

        $servidor->puerto = (int) $coincidencia[1];

        return $servidor;
    }

    /**
     * Apunta un mailer de Laravel a este servidor: SMTP a 127.0.0.1, sin cifrado y con usuario y
     * clave (los que el mailer `admin` exige para intentar un envío).
     *
     * Hay que llamarlo ANTES de que el test use el mailer por primera vez: el administrador de mails
     * guarda cada mailer ya armado.
     *
     * @param string $nombre Nombre del mailer en `config/mail.php`.
     *
     * @return void
     */
    public function apuntar_el_mailer(string $nombre): void
    {
        config(['mail.mailers.' . $nombre => [
            'transport'  => 'smtp',
            'host'       => '127.0.0.1',
            'port'       => $this->puerto,
            'encryption' => null,
            'username'   => 'admin@comerciocity.com',
            'password'   => 'clave-de-prueba',
            'timeout'    => 10,
            'auth_mode'  => null,
            'from'       => ['address' => 'admin@comerciocity.com', 'name' => 'ComercioCity'],
        ]]);
    }

    /**
     * Apaga el servidor. Se puede llamar más de una vez.
     *
     * @return void
     */
    public function bajar(): void
    {
        foreach ($this->tuberias as $tuberia) {
            if (is_resource($tuberia)) {
                fclose($tuberia);
            }
        }

        $this->tuberias = [];

        if (is_resource($this->proceso)) {
            proc_terminate($this->proceso);
            proc_close($this->proceso);
        }

        $this->proceso = null;
    }

    /**
     * Si el test se olvidó de bajarlo, se baja solo al soltarlo.
     */
    public function __destruct()
    {
        $this->bajar();
    }
}
