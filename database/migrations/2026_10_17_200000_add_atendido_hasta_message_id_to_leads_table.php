<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La marca «no necesita respuesta» de la bandeja de leads (misión flujo-lead-landing-closer, 17/10/2026).
 *
 * `atendido_hasta_message_id` guarda el id del ÚLTIMO mensaje del lead que el operador dio por
 * atendido sin contestarle nada. Todo mensaje del lead con `id <= atendido_hasta_message_id` deja
 * de contar como «sin responder»: en el amarillo de la fila, en el `sin_responder` de las tarjetas,
 * en `?sin_responder=1` y en el gate del scheduler de la IA (que no genera sugerencia para un
 * mensaje que nadie tiene que contestar). Un mensaje nuevo del lead tiene un id mayor y vuelve a
 * contar solo, sin que haya que limpiar nada: por eso es un id y no un booleano.
 *
 * Hasta hoy lo único que sacaba a un mensaje del «sin responder» sin mandarle nada al lead era
 * reaccionarle desde el panel. Un «gracias», un sticker o un «dale» no merecen ni un texto ni
 * una reacción, y quedaban pintando la fila para siempre.
 *
 * Nullable y sin default: toda fila existente queda en null, que significa «nada marcado», y se
 * comporta exactamente como antes. Sin FK (como el resto de las migraciones del repo: el id apunta
 * a `lead_messages`, pero una fila borrada del hilo no tiene por qué romper el lead) y con índice
 * corto, porque el predicado se evalúa en subconsultas correlacionadas sobre cada lead del listado.
 *
 * Aditiva y con guardas, como las demás migraciones de tablas vivas.
 */
class AddAtendidoHastaMessageIdToLeadsTable extends Migration
{
    /**
     * Agrega la columna con su índice.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('leads')) {
            return;
        }

        if (! Schema::hasColumn('leads', 'atendido_hasta_message_id')) {
            Schema::table('leads', function (Blueprint $table) {
                // Id del último mensaje del lead dado por atendido sin respuesta; null = nada marcado.
                $table->unsignedBigInteger('atendido_hasta_message_id')
                    ->nullable()
                    ->index('leads_atendido_hasta_idx')
                    ->after('pinned_at');
            });
        }
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('leads') || ! Schema::hasColumn('leads', 'atendido_hasta_message_id')) {
            return;
        }

        /* El índice se saca primero y con su propio try: si la columna existía pero el índice no (una
         * corrida a medias), el `down()` igual tiene que poder sacar la columna. */
        try {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropIndex('leads_atendido_hasta_idx');
            });
        } catch (\Throwable $e) {
            // Sin índice que sacar: sigue con la columna.
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('atendido_hasta_message_id');
        });
    }
}
