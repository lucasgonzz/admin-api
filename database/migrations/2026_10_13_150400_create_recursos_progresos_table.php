<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto vio cada usuario de cada video (misión centro-de-recursos-clientes, 13/10/2026).
 *
 * `vistos` es un carácter por segundo del video (`0` sin ver, `1` visto), con largo
 * `ceil(duracion)`. Se marcan solo los segundos que la página dice que se REPRODUJERON (los tramos):
 * saltar con la barra no suma, porque el salto no viaja como tramo. `porcentaje` es la cuenta de unos
 * sobre el largo, guardada para no recorrer el texto en cada listado del panel.
 *
 * `terminado_at` se fija la primera vez que el porcentaje llega al umbral (90 %) y NO se borra
 * después: si el video se recarga con otra duración y el porcentaje baja, el usuario ya lo había
 * terminado y lo sigue viendo terminado.
 */
class CreateRecursosProgresosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('recursos_progresos')) {
            return;
        }

        Schema::create('recursos_progresos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recursos_usuario_id')->constrained('recursos_usuarios')->cascadeOnDelete();
            $table->foreignId('recursos_video_id')->constrained('recursos_videos')->cascadeOnDelete();
            $table->text('vistos')->nullable();
            $table->decimal('porcentaje', 5, 2)->default(0);
            $table->decimal('ultima_posicion', 7, 1)->default(0);
            $table->timestamp('terminado_at')->nullable();
            $table->timestamps();

            $table->unique(['recursos_usuario_id', 'recursos_video_id'], 'recursos_progresos_usuario_video_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('recursos_progresos');
    }
}
