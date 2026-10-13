<?php

namespace App\Services;

use App\Models\RecursosProgreso;
use App\Models\RecursosUsuario;
use App\Models\RecursosVideo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * El avance de un usuario en un video (misión centro-de-recursos-clientes, 13/10/2026).
 *
 * La página junta los tramos que se REPRODUJERON de verdad (`[[desde, hasta], …]`, en segundos) y los
 * manda cada ~10 s, al pausar y al cerrar. Acá se marcan esos segundos en `vistos` (un carácter por
 * segundo del video) y el porcentaje es la cuenta de unos sobre `ceil(duracion)`.
 *
 * 🔴 Saltar con la barra NO suma, y no hay que "arreglarlo" infiriendo lo que hay entre dos
 * posiciones: un salto no viaja como tramo, así que esos segundos quedan en cero. Por eso tampoco se
 * marca nada a partir de `posicion` (que solo sirve para retomar). Es la misma regla del prototipo
 * aprobado.
 *
 * `terminado_at` se fija la primera vez que el porcentaje llega al umbral (`recursos.umbral_terminado`,
 * 90 %) y no se borra nunca después.
 */
class RecursosProgresoService
{
    /**
     * Valida los tramos de un pedido contra la duración del video. Devuelve el motivo del primer error
     * o null si sirven.
     *
     * @param RecursosVideo $video
     * @param mixed         $tramos
     *
     * @return string|null
     */
    public function error_de_los_tramos(RecursosVideo $video, $tramos): ?string
    {
        if (! is_array($tramos)) {
            return '`tramos` tiene que ser una lista de [desde, hasta].';
        }

        $max_tramos = (int) config('recursos.progreso.max_tramos', 50);
        $max_largo  = (float) config('recursos.progreso.max_segundos_tramo', 30);
        $holgura    = (float) config('recursos.progreso.holgura_final', 1);
        $duracion   = (float) $video->duracion;

        if (count($tramos) > $max_tramos) {
            return 'Son más de ' . $max_tramos . ' tramos en un solo pedido.';
        }

        foreach (array_values($tramos) as $i => $tramo) {
            if (! is_array($tramo) || count($tramo) !== 2) {
                return 'El tramo #' . $i . ' no es un par [desde, hasta].';
            }

            $tramo = array_values($tramo);

            if (! is_numeric($tramo[0]) || ! is_numeric($tramo[1])) {
                return 'El tramo #' . $i . ' no es numérico.';
            }

            $desde = (float) $tramo[0];
            $hasta = (float) $tramo[1];

            if ($desde < 0 || $hasta < 0) {
                return 'El tramo #' . $i . ' es negativo.';
            }

            if ($desde > $hasta) {
                return 'El tramo #' . $i . ' termina antes de empezar.';
            }

            if ($hasta > $duracion + $holgura) {
                return 'El tramo #' . $i . ' pasa del final del video (' . $duracion . ' s).';
            }

            if ($hasta - $desde > $max_largo) {
                return 'El tramo #' . $i . ' es de más de ' . $max_largo . ' s.';
            }
        }

        return null;
    }

    /**
     * Registra el avance. Los tramos ya tienen que haber pasado `error_de_los_tramos()`.
     *
     * Bajo `lockForUpdate` sobre la fila del progreso: la página puede mandar dos avances casi juntos
     * (el periódico y el de pausar) y sin el lock el segundo pisaría los segundos que marcó el primero.
     *
     * @param RecursosUsuario                 $usuario
     * @param RecursosVideo                   $video
     * @param float                           $posicion
     * @param array<int, array<int, float>>   $tramos
     *
     * @return array{porcentaje: float, terminado: bool}
     */
    public function registrar(RecursosUsuario $usuario, RecursosVideo $video, float $posicion, array $tramos): array
    {
        $this->asegurar_la_fila($usuario, $video);

        return DB::transaction(function () use ($usuario, $video, $posicion, $tramos) {
            /** @var RecursosProgreso $progreso */
            $progreso = RecursosProgreso::where('recursos_usuario_id', $usuario->id)
                ->where('recursos_video_id', $video->id)
                ->lockForUpdate()
                ->first();

            $segundos = $video->segundos();
            $vistos   = self::ajustar_largo((string) $progreso->vistos, $segundos);

            foreach ($tramos as $tramo) {
                $tramo = array_values($tramo);
                $vistos = self::marcar($vistos, (float) $tramo[0], (float) $tramo[1], $segundos);
            }

            $unos       = substr_count($vistos, '1');
            $porcentaje = $segundos > 0 ? round(min(100, $unos * 100 / $segundos), 2) : 0.0;

            $progreso->vistos          = $vistos;
            $progreso->porcentaje      = $porcentaje;
            $progreso->ultima_posicion = round(max(0, min($posicion, (float) $video->duracion)), 1);

            if ($progreso->terminado_at === null && $porcentaje >= ((float) config('recursos.umbral_terminado', 0.9)) * 100) {
                $progreso->terminado_at = now();
            }

            $progreso->save();

            return [
                'porcentaje' => (float) $porcentaje,
                'terminado'  => $progreso->terminado_at !== null,
            ];
        });
    }

    /**
     * Crea la fila del progreso si no existe. Aparte de la transacción del avance: dos pedidos
     * simultáneos del primer avance chocan contra el único (usuario, video) y el segundo sigue con la
     * fila que creó el primero.
     *
     * @param RecursosUsuario $usuario
     * @param RecursosVideo   $video
     *
     * @return void
     */
    protected function asegurar_la_fila(RecursosUsuario $usuario, RecursosVideo $video): void
    {
        $existe = RecursosProgreso::where('recursos_usuario_id', $usuario->id)
            ->where('recursos_video_id', $video->id)
            ->exists();

        if ($existe) {
            return;
        }

        try {
            RecursosProgreso::create([
                'recursos_usuario_id' => $usuario->id,
                'recursos_video_id'   => $video->id,
                'vistos'              => str_repeat('0', $video->segundos()),
                'porcentaje'          => 0,
                'ultima_posicion'     => 0,
            ]);
        } catch (QueryException $e) {
            // La creó otro pedido en el medio: sirve igual.
            if (! RecursosProgreso::where('recursos_usuario_id', $usuario->id)->where('recursos_video_id', $video->id)->exists()) {
                throw $e;
            }
        }
    }

    /**
     * Deja `vistos` con el largo del video: si el video se recargó con otra duración, se corta o se
     * completa con ceros (lo visto hasta ahí se conserva).
     *
     * @param string $vistos
     * @param int    $segundos
     *
     * @return string
     */
    public static function ajustar_largo(string $vistos, int $segundos): string
    {
        $vistos = preg_replace('/[^01]/', '0', $vistos);

        if (strlen($vistos) > $segundos) {
            return substr($vistos, 0, $segundos);
        }

        return str_pad($vistos, $segundos, '0');
    }

    /**
     * Marca como vistos los segundos que toca un tramo. El segundo `i` es el intervalo `[i, i+1)`: un
     * tramo `[3.2, 3.8]` marca el 3; `[0, 10]` marca del 0 al 9. Un tramo de largo cero no marca nada.
     *
     * @param string $vistos
     * @param float  $desde
     * @param float  $hasta
     * @param int    $segundos
     *
     * @return string
     */
    public static function marcar(string $vistos, float $desde, float $hasta, int $segundos): string
    {
        if ($segundos <= 0 || $hasta <= $desde) {
            return $vistos;
        }

        $primero = (int) floor($desde);
        $ultimo  = (int) ceil($hasta) - 1;

        $primero = max(0, $primero);
        $ultimo  = min($segundos - 1, max($primero, $ultimo));

        for ($i = $primero; $i <= $ultimo; $i++) {
            $vistos[$i] = '1';
        }

        return $vistos;
    }
}
