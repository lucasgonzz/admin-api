<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Cierra la CLASE del defecto, no solo sus ejemplos: ningún envío de mail de `app/` puede quedar sin mirar
 * si el servidor de correo rechazó la casilla.
 *
 * El defecto (misión mails-a-leads-rechazados-por-smtp, 6/10/2026): con un 550 en el RCPT TO, SwiftMailer NO
 * tira excepción; `send()` vuelve normal y la casilla queda en `failures()` del mailer. Quien solo atrapa la
 * excepción da por enviado un mail que nunca salió. Se arregló en los nueve puntos que le mandan un mail a
 * un lead y en los dos servicios que ya lo hacían bien (`RechazosDeCorreoHelper`), pero sin esto el décimo
 * punto de envío nace con el mismo hueco, y nadie se entera hasta que un lead recibe un recordatorio que dice
 * "los accesos están en el mail que te mandamos" sobre un mail que nunca llegó.
 *
 * Qué hace: recorre `app/**\/*.php`, saca los comentarios (con `token_get_all`: que un docblock diga
 * `Mail::to()->send()` no cuenta como un envío, ni como un chequeo), y para cada archivo compara
 *   - cuántas SENTENCIAS mandan un mail por la fachada `Mail` (`Mail::...->send(`, `Mail::raw(`...), contra
 *   - cuántas llamadas hay a `RechazosDeCorreoHelper::del_ultimo_envio()` o `::fallar_si_hubo_rechazos()`.
 * Falla, listando el archivo y las dos cuentas, si hay más envíos que chequeos.
 *
 * 🔴 **Límites, dichos a propósito.** Es una heurística: CUENTA, no EMPAREJA cada envío con su chequeo (un
 * archivo con un envío sin chequeo y otro chequeo de más pasaría), y no ve un mail armado por fuera de la
 * fachada `Mail` ni un `->queue()` (un mail encolado se manda en un worker, donde el rechazo no se ve desde
 * acá). Alcanza para el modo de falla real, que es agregar un envío nuevo y olvidarse del chequeo. Y
 * justamente porque una heurística ciega pasa en verde para siempre, hay un test que exige que el detector
 * VEA los envíos que ya existen: ver `test_el_detector_ve_los_envios_que_ya_existen_en_app()`.
 *
 * Puntos ciegos medidos por el chequeo independiente (el detector cuenta CERO envíos en estas formas, y hoy no
 * hay ninguna en `app/`): partir el envío en dos sentencias (`$p = Mail::to($a); $p->send($b);` o
 * `$m = Mail::mailer('admin'); $m->to($a)->send($b);`), un alias de la fachada, `$this->mailer->send(...)` o
 * `app('mailer')->send(...)` (fuera de la fachada) y `Notification::route('mail', ...)`. Quien escriba un envío
 * así tiene que mirar los rechazos igual, aunque este test no se lo exija.
 */
class TodoEnvioDeMailMiraLosRechazosTest extends TestCase
{
    /**
     * Los métodos de la fachada `Mail` (o de lo que devuelve `Mail::to()`) que mandan un mail EN EL ACTO.
     * En minúsculas: PHP no distingue mayúsculas en los nombres de método.
     */
    const METODOS_DE_ENVIO = ['send', 'sendnow', 'raw', 'html', 'plain'];

    /**
     * Las llamadas a `RechazosDeCorreoHelper` que cuentan como "mirar los rechazos".
     */
    const METODOS_DEL_HELPER = ['del_ultimo_envio', 'fallar_si_hubo_rechazos'];

    /**
     * Cuántos envíos y cuántos chequeos hay en un código PHP.
     *
     * Un "envío" es una SENTENCIA que arranca con `Mail::` y, antes de terminar (el `;` a la profundidad
     * original), llama a un método de envío (`->send(`, `::raw(`...). Se cuenta una vez por sentencia aunque
     * encadene varias cosas, y una sentencia con una closure adentro (`Mail::raw('x', function () {...});`)
     * sigue siendo una sola. Un "chequeo" es una llamada `RechazosDeCorreoHelper::del_ultimo_envio(` o
     * `::fallar_si_hubo_rechazos(`, con o sin namespace delante.
     *
     * 🔴 Los comentarios y los espacios se descartan ANTES de contar: un docblock que explica el defecto con
     * un ejemplo `Mail::to($x)->send($y)` no es un envío, y uno que dice "llamá a RechazosDeCorreoHelper::
     * fallar_si_hubo_rechazos()" no es un chequeo.
     *
     * @param string $codigo Contenido de un archivo PHP.
     *
     * @return array{envios: int, chequeos: int}
     */
    private function analizar(string $codigo): array
    {
        // Los tokens "de verdad": sin comentarios, sin docblocks y sin espacios.
        $tokens = [];

        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }

            $tokens[] = $token;
        }

        $envios   = 0;
        $chequeos = 0;
        $total    = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            // `Mail::` (con o sin el namespace de la fachada delante): arranca una sentencia candidata.
            if ($this->es_el_nombre($this->texto($tokens, $i), 'Mail') && $this->texto($tokens, $i + 1) === '::') {
                if ($this->la_sentencia_manda_un_mail($tokens, $i)) {
                    $envios++;
                }

                continue;
            }

            // `RechazosDeCorreoHelper::del_ultimo_envio(` o `::fallar_si_hubo_rechazos(`.
            if ($this->es_el_nombre($this->texto($tokens, $i), 'RechazosDeCorreoHelper')
                && $this->texto($tokens, $i + 1) === '::'
                && in_array($this->texto($tokens, $i + 2), self::METODOS_DEL_HELPER, true)
                && $this->texto($tokens, $i + 3) === '(') {
                $chequeos++;
            }
        }

        return ['envios' => $envios, 'chequeos' => $chequeos];
    }

    /**
     * El texto de un token (los tokens sueltos como `(` o `;` son strings; el resto, arrays).
     *
     * @param array<int, array|string> $tokens
     * @param int                      $posicion
     *
     * @return string Vacío si la posición no existe.
     */
    private function texto(array $tokens, int $posicion): string
    {
        if (! isset($tokens[$posicion])) {
            return '';
        }

        return is_array($tokens[$posicion]) ? (string) $tokens[$posicion][1] : (string) $tokens[$posicion];
    }

    /**
     * Si un nombre de clase es `$nombre`, ya venga solo (`Mail`) o con namespace (`\Illuminate\Support\Facades\Mail`).
     *
     * Se mira el TEXTO y no el tipo de token porque PHP 7.4 parte un nombre con namespace en varios tokens
     * y PHP 8 lo entrega en uno solo; en los dos casos el último tramo es un token con ese texto o uno que
     * termina en `\Mail`. `Mailer` o `MailFake` no coinciden.
     *
     * @param string $texto
     * @param string $nombre
     *
     * @return bool
     */
    private function es_el_nombre(string $texto, string $nombre): bool
    {
        if ($texto === $nombre) {
            return true;
        }

        $sufijo = '\\' . $nombre;

        return strlen($texto) > strlen($sufijo) && substr($texto, -strlen($sufijo)) === $sufijo;
    }

    /**
     * Si la sentencia que arranca en `Mail::` llama a un método de envío antes de terminar.
     *
     * Avanza hasta el `;` que cierra la sentencia (a la misma profundidad de paréntesis, corchetes y llaves
     * con la que arrancó: una closure con `;` adentro no la corta) o hasta salir del bloque que la contiene.
     *
     * @param array<int, array|string> $tokens
     * @param int                      $desde  Posición del token `Mail`.
     *
     * @return bool
     */
    private function la_sentencia_manda_un_mail(array $tokens, int $desde): bool
    {
        $profundidad = 0;
        $total       = count($tokens);

        for ($j = $desde; $j < $total; $j++) {
            $texto = $this->texto($tokens, $j);

            if ($texto === '(' || $texto === '[' || $texto === '{' || $texto === '${') {
                $profundidad++;
            } elseif ($texto === ')' || $texto === ']' || $texto === '}') {
                $profundidad--;

                // Se salió del bloque que contenía la sentencia sin haber visto un envío.
                if ($profundidad < 0) {
                    return false;
                }
            } elseif ($texto === ';' && $profundidad === 0) {
                return false;
            }

            // `->send(`, `::raw(`...: el método de envío.
            if (($texto === '->' || $texto === '::')
                && in_array(strtolower($this->texto($tokens, $j + 1)), self::METODOS_DE_ENVIO, true)
                && $this->texto($tokens, $j + 2) === '(') {
                return true;
            }
        }

        return false;
    }

    /**
     * Todos los `.php` de `app/`, con la ruta absoluta.
     *
     * @return array<int, string>
     */
    private function archivos_de_app(): array
    {
        $raiz     = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app';
        $archivos = [];

        $iterador = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterador as $archivo) {
            if ($archivo->isFile() && substr($archivo->getFilename(), -4) === '.php') {
                $archivos[] = $archivo->getPathname();
            }
        }

        sort($archivos);

        return $archivos;
    }

    /**
     * Cuenta envíos y chequeos de un archivo de `app/` por su ruta relativa (con `/`).
     *
     * @param string $relativa Por ejemplo `Http/Controllers/LeadController.php`.
     *
     * @return array{envios: int, chequeos: int}
     */
    private function analizar_archivo_de_app(string $relativa): array
    {
        $ruta = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa);

        $this->assertFileExists($ruta);

        return $this->analizar((string) file_get_contents($ruta));
    }

    /**
     * 🔴 El test que importa: ningún archivo de `app/` manda más mails de los que mira rechazos.
     *
     * Si falla, un envío nuevo (o uno que se le borró el chequeo) puede estar dando por enviado un mail que el
     * servidor rechazó. La salida lista cada archivo con sus dos cuentas.
     *
     * @return void
     */
    public function test_todo_envio_de_mail_de_app_mira_los_rechazos_del_servidor(): void
    {
        $sin_chequeo = [];
        $raiz        = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR;

        foreach ($this->archivos_de_app() as $ruta) {
            $codigo = (string) file_get_contents($ruta);

            // Un archivo sin ningún `Mail::` no puede mandar nada por la fachada (ni con namespace delante: el `Mail::`
            // sigue estando): se ahorra el `token_get_all()`, que en los archivos grandes es lo que más tarda.
            if (preg_match('/Mail\s*::/', $codigo) !== 1) {
                continue;
            }

            $cuenta = $this->analizar($codigo);

            if ($cuenta['envios'] > $cuenta['chequeos']) {
                $sin_chequeo[] = str_replace('\\', '/', substr($ruta, strlen($raiz)))
                    . ': ' . $cuenta['envios'] . ' envío(s) de mail y ' . $cuenta['chequeos'] . ' chequeo(s) de rechazos';
            }
        }

        $this->assertSame(
            [],
            $sin_chequeo,
            'Hay envíos de mail que no miran si el servidor SMTP rechazó la casilla. Con un 550 en el RCPT TO, SwiftMailer NO tira '
            . 'excepción: send() vuelve normal y el mail no salió. Justo después de cada Mail::...->send() llamá a '
            . 'RechazosDeCorreoHelper::fallar_si_hubo_rechazos() (si el punto ya tiene un catch que anota el fallo), o a '
            . 'RechazosDeCorreoHelper::del_ultimo_envio($mailer) (si arma su propio registro de error), y no anotes el envío como '
            . 'hecho hasta pasarlo. Archivos: ' . implode(' | ', $sin_chequeo)
        );
    }

    /**
     * 🔴 El control del detector: VE los envíos que ya existen. Sin esto, un detector roto (que contara cero
     * envíos en todos lados) haría pasar el test de arriba en verde para siempre, y la clase volvería a
     * abrirse sin que nada lo avise.
     *
     * Son pisos, no cuentas exactas: agregar un envío nuevo CON su chequeo no tiene que romper este test.
     * Hoy: `LeadController` 6, `LeadAiService` 3, `AvisoDeActualizacionService` 1 e `ImplementacionMailService` 1.
     *
     * @dataProvider envios_que_ya_existen
     *
     * @param string $relativa Ruta relativa dentro de `app/`.
     * @param int    $minimo   Cuántos envíos tiene que ver como mínimo.
     *
     * @return void
     */
    public function test_el_detector_ve_los_envios_que_ya_existen_en_app(string $relativa, int $minimo): void
    {
        $cuenta = $this->analizar_archivo_de_app($relativa);

        $this->assertGreaterThanOrEqual($minimo, $cuenta['envios'], 'El detector no ve los envíos de ' . $relativa . ': estaría ciego.');
        $this->assertGreaterThanOrEqual($cuenta['envios'], $cuenta['chequeos'], $relativa . ' tiene envíos sin chequeo de rechazos.');
    }

    /**
     * Los archivos de `app/` que hoy mandan mails, y cuántos como mínimo.
     *
     * @return array<string, array<int, string|int>>
     */
    public static function envios_que_ya_existen(): array
    {
        return [
            'LeadController (6 envíos del panel)'           => ['Http/Controllers/LeadController.php', 6],
            'LeadAiService (3 envíos de la IA)'             => ['Services/LeadAiService.php', 3],
            'AvisoDeActualizacionService (el aviso)'        => ['Services/AvisoDeActualizacionService.php', 1],
            'ImplementacionMailService (los hitos)'         => ['Services/ImplementacionMailService.php', 1],
        ];
    }

    /**
     * El detector sobre código de ejemplo: cuenta lo que tiene que contar y NADA de lo que no.
     *
     * @dataProvider casos_del_detector
     *
     * @param string $codigo            Un trozo de PHP.
     * @param int    $envios_esperados
     * @param int    $chequeos_esperados
     *
     * @return void
     */
    public function test_el_detector_cuenta_bien_sobre_codigo_de_ejemplo(string $codigo, int $envios_esperados, int $chequeos_esperados): void
    {
        $this->assertSame(
            ['envios' => $envios_esperados, 'chequeos' => $chequeos_esperados],
            $this->analizar('<?php ' . $codigo)
        );
    }

    /**
     * Casos del detector: lo que cuenta como envío o chequeo, y lo que se parece y no lo es.
     *
     * @return array<string, array<int, string|int>>
     */
    public static function casos_del_detector(): array
    {
        return [
            'un envío sin chequeo'                                  => ['Mail::to($a)->send($b);', 1, 0],
            'un envío con su chequeo'                               => ['Mail::to($a)->send($b); RechazosDeCorreoHelper::fallar_si_hubo_rechazos();', 1, 1],
            'el chequeo de lectura (del_ultimo_envio)'              => ['Mail::mailer("admin")->to($a)->send($b); $r = RechazosDeCorreoHelper::del_ultimo_envio("admin");', 1, 1],
            'con el namespace completo de la fachada y del helper'  => ['\\Illuminate\\Support\\Facades\\Mail::to($a)->send($b); \\App\\Mail\\Helpers\\RechazosDeCorreoHelper::fallar_si_hubo_rechazos();', 1, 1],
            'una sentencia en varias líneas cuenta una vez'         => ["Mail::mailer('admin')\n    ->to(\$a)\n    ->send(Foo::armar(\n        \$x,\n        \$y\n    ));", 1, 0],
            'Mail::raw con una closure adentro'                     => ['Mail::raw("hola", function ($m) { $m->to($a); $m->subject("x"); });', 1, 0],
            'Mail::send directo'                                    => ['Mail::send("vista", [], function ($m) {});', 1, 0],
            'dos envíos, un chequeo'                                => ['Mail::to($a)->send($b); Mail::to($c)->send($d); RechazosDeCorreoHelper::fallar_si_hubo_rechazos();', 2, 1],
            'un envío en un comentario no cuenta'                   => ['// Mail::to($a)->send($b);', 0, 0],
            'un envío en un docblock no cuenta'                     => ["/**\n * Mail::to(\$a)->send(\$b);\n * RechazosDeCorreoHelper::fallar_si_hubo_rechazos();\n */\nfunction f() {}", 0, 0],
            'un envío en un string no cuenta'                       => ['$t = "Mail::to(\$a)->send(\$b)";', 0, 0],
            'Mail::fake no es un envío'                             => ['Mail::fake(); Mail::assertSent(Foo::class);', 0, 0],
            'Mail::failures no es un envío'                         => ['$f = Mail::failures();', 0, 0],
            'Mail::mailer(...)->failures no es un envío'            => ['$f = Mail::mailer(null)->failures();', 0, 0],
            'otra clase que se llama parecido no cuenta'            => ['Mailer::to($a)->send($b); ElMail::to($a)->send($b);', 0, 0],
            'el use de la fachada no es un envío'                   => ['use Illuminate\\Support\\Facades\\Mail;', 0, 0],
            'el chequeo nombrado en un comentario no cuenta'        => ['Mail::to($a)->send($b); // RechazosDeCorreoHelper::fallar_si_hubo_rechazos();', 1, 0],
            'otro método del helper no cuenta como chequeo'         => ['Mail::to($a)->send($b); RechazosDeCorreoHelper::otra_cosa();', 1, 0],
        ];
    }

    /**
     * Y sobre el `send` de la IA de ejemplo, la lógica de la comparación: si hay más envíos que chequeos, se
     * denuncia el archivo. Es la conducta que `test_todo_envio_de_mail_de_app_mira_los_rechazos_del_servidor()`
     * aplica a todo `app/`, probada acá sobre un trozo que no depende de lo que haya en el repo.
     *
     * @return void
     */
    public function test_un_codigo_con_mas_envios_que_chequeos_se_denuncia(): void
    {
        $sin_chequeo = $this->analizar('<?php Mail::to($a)->send($b);');
        $con_chequeo = $this->analizar('<?php Mail::to($a)->send($b); RechazosDeCorreoHelper::fallar_si_hubo_rechazos();');

        $this->assertGreaterThan($sin_chequeo['chequeos'], $sin_chequeo['envios'], 'Un envío sin chequeo es lo que se denuncia.');
        $this->assertLessThanOrEqual($con_chequeo['chequeos'], $con_chequeo['envios'], 'Un envío con su chequeo no se denuncia.');
    }
}
