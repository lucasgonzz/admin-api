<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de "este lead ya no recibe mensajes" en `leads`.
 *
 * 🔴 Por qué es una marca manual y no algo que el sistema deduzca solo.
 *
 * Cuando una entrega falla, lo único que distingue "el número bloqueó / ya no existe" de un fallo
 * reintentable es el código de error de Meta (131026, 131050…). Ese código **nunca se capturó**:
 * medido el 2/9/2026, de las 84 entregas fallidas de toda la historia, cero tenían
 * `whatsapp_send_error` cargado — `WhatsappWebhookController::extract_delivery_failure_reason()`
 * lo busca en cuatro lugares del payload y Kapso no lo manda en ninguno.
 *
 * Y tampoco se puede inferir del comportamiento: de los 54 leads con alguna entrega fallida, **33
 * después recibieron mensajes normalmente**, incluidos 9 que ya habían fallado dos veces y 6 que
 * habían fallado tres. Una regla del estilo "dos fallos seguidos = número muerto" habría dado por
 * perdidos a esos 9 estando vivos.
 *
 * Así que la marca la pone una persona, con lo que ve en la conversación. Cuando el webhook empiece
 * a guardar el código real (se instrumentó en la misma misión), la clasificación automática se va a
 * poder construir encima — y esta marca va a seguir haciendo falta para los casos que el código no
 * cubra.
 *
 * Aditiva, nullable y sin foreign keys: null = el lead recibe mensajes con normalidad.
 */
class AddNoRecibeMensajesToLeadsTable extends Migration
{
    /**
     * Agrega la marca y el índice que la consulta de la grilla usa para descartar los marcados.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('no_recibe_mensajes_at')->nullable()->after('pendiente_revision_at');
            $table->string('no_recibe_mensajes_motivo', 200)->nullable()->after('no_recibe_mensajes_at');
        });
    }

    /**
     * Quita las dos columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['no_recibe_mensajes_at', 'no_recibe_mensajes_motivo']);
        });
    }
}
