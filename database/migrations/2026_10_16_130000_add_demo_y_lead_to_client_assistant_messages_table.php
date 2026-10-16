<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El asistente personal para el lead durante su demo (misión mail-demo-turno-y-asistente, 16/10/2026).
 *
 * `client_assistant_messages` es el hilo de WhatsApp entre el DUEÑO de un cliente y el asistente de
 * su sistema, y cada fila cuelga de un `client_id`. Un lead que está haciendo su demo no es un
 * cliente: no tiene fila en `clients`, y crearle una falsa metería a la demo en las tandas de
 * actualización, en los informes de las 08:30 y en los conteos de la flota. En cambio, el hilo del
 * lead cuelga de la INSTANCIA de demo que se le asignó y de él mismo:
 *
 *   - `demo_id`: la instancia (`demos.id`) a la que se le habla. Es lo que le dice al job que, en
 *     vez de buscar un `Client`, arme el destino desde la demo (`erp_api_url`, `api_key`).
 *   - `lead_id`: quién escribió, para leer el hilo de un vistazo.
 *
 * Las dos son NULLABLE y sin default: toda fila anterior (la de un dueño) queda con las dos en null
 * y recorre exactamente el camino de siempre. Sin FK, como el resto de las migraciones del repo, y
 * con índice corto sobre `demo_id` porque es por donde se consulta.
 *
 * Aditiva y con guardas, como las demás migraciones de tablas vivas.
 */
class AddDemoYLeadToClientAssistantMessagesTable extends Migration
{
    /**
     * Agrega las dos columnas.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('client_assistant_messages')) {
            return;
        }

        if (! Schema::hasColumn('client_assistant_messages', 'demo_id')) {
            Schema::table('client_assistant_messages', function (Blueprint $table) {
                // Instancia de demo a la que se le habla; null = hilo de un dueño de cliente.
                $table->unsignedBigInteger('demo_id')->nullable()->index('cli_asist_msg_demo_idx')->after('client_id');
            });
        }

        if (! Schema::hasColumn('client_assistant_messages', 'lead_id')) {
            Schema::table('client_assistant_messages', function (Blueprint $table) {
                // Lead que escribió durante su turno; null = hilo de un dueño de cliente.
                $table->unsignedBigInteger('lead_id')->nullable()->after('demo_id');
            });
        }
    }

    /**
     * Saca las dos columnas.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('client_assistant_messages')) {
            return;
        }

        if (Schema::hasColumn('client_assistant_messages', 'demo_id')) {
            /* El índice se saca primero y con su propio try: si la columna existía pero el índice no (una
             * corrida a medias), el `down()` igual tiene que poder sacar la columna. */
            try {
                Schema::table('client_assistant_messages', function (Blueprint $table) {
                    $table->dropIndex('cli_asist_msg_demo_idx');
                });
            } catch (\Throwable $e) {
                // Sin índice que sacar: sigue con la columna.
            }

            Schema::table('client_assistant_messages', function (Blueprint $table) {
                $table->dropColumn('demo_id');
            });
        }

        if (Schema::hasColumn('client_assistant_messages', 'lead_id')) {
            Schema::table('client_assistant_messages', function (Blueprint $table) {
                $table->dropColumn('lead_id');
            });
        }
    }
}
