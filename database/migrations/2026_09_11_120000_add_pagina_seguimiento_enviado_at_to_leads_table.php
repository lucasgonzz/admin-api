<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de un solo disparo del seguimiento de página en `leads` (misión experiencia-landing,
 * 11/9/2026).
 *
 * La página de experiencia pasó a ser la landing que Martín le pasa al lead ANTES de ofrecerle la
 * demo. Si el lead la abre y a las dos horas no pidió la demo ni volvió a escribir,
 * `leads:check-pagina-sin-demo` le manda un único "vi que le pegaste una mirada a tu página...".
 * Esta columna es lo que garantiza el "único": se estampa al mandar —y también cuando el envío
 * falla, para no reintentar en loop— y el comando descarta a todo lead que ya la tenga.
 *
 * Es fecha y no booleano a propósito: cruzada con `lead_messages` permite distinguir "salió" de
 * "se intentó y falló", que un flag no cuenta.
 *
 * Aditiva, nullable, sin default requerido y sin foreign keys: compatible hacia atrás con el
 * código que hoy corre en producción (que simplemente no la lee).
 */
class AddPaginaSeguimientoEnviadoAtToLeadsTable extends Migration
{
    /**
     * Agrega la marca.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('pagina_seguimiento_enviado_at')->nullable();
        });
    }

    /**
     * Quita la marca.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('pagina_seguimiento_enviado_at');
        });
    }
}
