<?php

namespace App\Exceptions;

use App\Services\ImplementacionMailService;
use Illuminate\Http\JsonResponse;

/**
 * Lo que `ImplementacionMailService` tira cuando un mail de hito NO se puede armar o mandar por una
 * razón de negocio (y no por un fallo del sistema).
 *
 * Todas se contestan con 422 —salvo `envio_en_curso`, que es 409— y todas llevan un `motivo` corto y
 * estable para que quien llama (el endpoint de claude/*, la skill) decida sin parsear el mensaje:
 *
 *   sin_mail       — no hay casilla a la que escribirle (ni la que se pasó, ni la de la ficha, ni la
 *                    del formulario, ni la del lead promovido).
 *   ya_enviado     — ese hito ya salió y no se pidió reenviarlo. El mensaje dice cuándo y a quién.
 *   faltan_datos   — falta algo para armar el mail: un dato del hito que no sirve (`errores`, por
 *                    campo) o algo que sale de la implementación y todavía no está (`faltan`, p. ej.
 *                    `form_link` o `url_sistema`).
 *   hito_invalido  — el hito no es ninguno de `ImplementacionMailService::HITOS`.
 *   envio_en_curso — hay otro envío del MISMO hito en vuelo para esta implementación. Se contesta
 *                    409 y no se manda nada: es justo el caso para el que existe el lock.
 *
 * El mensaje (`getMessage()`) está en castellano y se puede mostrar tal cual. `getCode()` es el
 * status HTTP que corresponde.
 *
 * Si nadie la atrapa, `render()` la convierte igual en la respuesta JSON con su status: así un
 * endpoint que se olvide del try/catch contesta 422 con el motivo y no un 500 sin explicación.
 */
class ImplementacionMailException extends \RuntimeException
{
    const MOTIVO_SIN_MAIL        = 'sin_mail';
    const MOTIVO_YA_ENVIADO      = 'ya_enviado';
    const MOTIVO_FALTAN_DATOS    = 'faltan_datos';
    const MOTIVO_HITO_INVALIDO   = 'hito_invalido';
    const MOTIVO_ENVIO_EN_CURSO  = 'envio_en_curso';

    /**
     * Motivo corto y estable (uno de las constantes MOTIVO_*).
     *
     * @var string
     */
    public $motivo;

    /**
     * Errores por campo cuando el motivo es `faltan_datos` por datos que no sirven.
     * Clave: el campo (`articulos`, `opciones.1.nombre`); valor: qué está mal.
     *
     * @var array<string, string>
     */
    public $errores = [];

    /**
     * Lo que falta cuando el motivo es `faltan_datos` y no depende de lo que se mandó
     * (`form_link`, `url_sistema`, `email`, `cliente`).
     *
     * @var array<int, string>
     */
    public $faltan = [];

    /**
     * @param string $motivo  Una de las constantes MOTIVO_*.
     * @param string $mensaje Texto legible, en castellano.
     * @param int    $codigo  Status HTTP que corresponde (422 salvo `envio_en_curso`).
     */
    public function __construct(string $motivo, string $mensaje, int $codigo = 422)
    {
        parent::__construct($mensaje, $codigo);

        $this->motivo = $motivo;
    }

    /**
     * No hay casilla a la que mandar el mail.
     *
     * @param string $detalle Aclaración opcional que se suma al mensaje.
     *
     * @return self
     */
    public static function sin_mail(string $detalle = ''): self
    {
        $mensaje = 'No hay una casilla de correo a la que mandarle el mail: ni la que se pasó, ni la de la '
            . 'ficha del cliente, ni la del formulario, ni la del lead. Pasá `email` con la dirección del dueño.';

        if ($detalle !== '') {
            $mensaje .= ' ' . $detalle;
        }

        $excepcion         = new self(self::MOTIVO_SIN_MAIL, $mensaje);
        $excepcion->faltan = ['email'];

        return $excepcion;
    }

    /**
     * El hito ya salió y no se pidió reenviarlo.
     *
     * @param string $hito             Hito que se quiso mandar.
     * @param string $cuando           Fecha y hora del envío ya hecho, legible.
     * @param string $para_enmascarado Dirección a la que salió, enmascarada.
     *
     * @return self
     */
    public static function ya_enviado(string $hito, string $cuando, string $para_enmascarado): self
    {
        return new self(
            self::MOTIVO_YA_ENVIADO,
            'El mail del hito "' . $hito . '" ya salió el ' . $cuando . ' a ' . $para_enmascarado
            . '. Si de verdad hace falta mandarlo de nuevo, repetí la llamada con reenviar=true.'
        );
    }

    /**
     * Falta algo para armar el mail.
     *
     * @param array<int, string>    $faltan  Lo que falta (nombres de campos o de datos).
     * @param array<string, string> $errores Errores por campo, si el problema son los datos que se mandaron.
     * @param string                $detalle Aclaración opcional que reemplaza al mensaje automático.
     *
     * @return self
     */
    public static function faltan_datos(array $faltan, array $errores = [], string $detalle = ''): self
    {
        if ($detalle === '') {
            $partes = [];

            foreach ($errores as $campo => $problema) {
                $partes[] = $campo . ': ' . $problema;
            }

            $detalle = ! empty($partes)
                ? 'Los datos del mail no sirven. ' . implode(' ', $partes)
                : 'Falta para armar el mail: ' . implode(', ', $faltan) . '.';
        }

        $excepcion          = new self(self::MOTIVO_FALTAN_DATOS, $detalle);
        $excepcion->faltan  = array_values($faltan);
        $excepcion->errores = $errores;

        return $excepcion;
    }

    /**
     * El hito no existe.
     *
     * @param string $hito Lo que llegó.
     *
     * @return self
     */
    public static function hito_invalido(string $hito): self
    {
        return new self(
            self::MOTIVO_HITO_INVALIDO,
            'El hito "' . $hito . '" no existe. Los hitos son: ' . implode(', ', ImplementacionMailService::HITOS) . '.'
        );
    }

    /**
     * Hay otro envío del mismo hito en vuelo.
     *
     * @param string $hito Hito que se quiso mandar.
     *
     * @return self
     */
    public static function envio_en_curso(string $hito): self
    {
        return new self(
            self::MOTIVO_ENVIO_EN_CURSO,
            'Ya hay un envío del hito "' . $hito . '" en curso para esta implementación: no se mandó nada. '
            . 'Esperá a que termine y mirá cómo quedó antes de reintentar.',
            409
        );
    }

    /**
     * El motivo corto y estable, para quien prefiera un método a la propiedad.
     *
     * @return string
     */
    public function getMotivo(): string
    {
        return $this->motivo;
    }

    /**
     * La respuesta JSON de esta excepción cuando nadie la atrapó.
     *
     * Laravel llama a este método solo si la excepción llega hasta el handler: si el endpoint la
     * atrapa (lo normal), esto no corre.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return JsonResponse
     */
    public function render($request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'motivo'  => $this->motivo,
            'errores' => $this->errores,
            'faltan'  => $this->faltan,
        ], $this->getCode());
    }
}
