<?php

namespace App\Models\Concerns;

use App\Helpers\AppTime;

/**
 * Trait para modelos Eloquent que respeta el reloj virtual (AppTime) al
 * generar los timestamps `created_at` y `updated_at`.
 *
 * En producción, AppTime::now() es equivalente a Carbon::now(),
 * por lo que este trait no cambia el comportamiento de producción.
 * Solo en entorno local + con virtual_time seteado, los timestamps
 * generados reflejarán la hora virtual configurada.
 *
 * Sobreescribe únicamente freshTimestamp(): freshTimestampString() de Laravel
 * ya delega en freshTimestamp() vía fromDateTime(), por lo que también respetará
 * el reloj virtual sin necesidad de un segundo override.
 */
trait UsesVirtualTime
{
    /**
     * Timestamp usado por Laravel para poblar created_at / updated_at.
     * Delega en AppTime::now(), que en producción devuelve Carbon::now().
     *
     * @return \Illuminate\Support\Carbon
     */
    public function freshTimestamp()
    {
        return AppTime::now();
    }
}
