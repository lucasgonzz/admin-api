<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La foto que espera su instrucción (misión asistente-espera-foto, 27/9/2026).
 *
 * El pedido de Lucas: el dueño manda la foto de una factura o de un artículo y, enseguida, un audio
 * diciendo qué hacer con ella. Hasta ahora cada mensaje de WhatsApp era un turno del asistente: la
 * foto sola disparaba uno (caro, porque un turno con foto arranca escalado) y el audio disparaba
 * otro donde el modelo ya no veía la foto. Ahora una foto SIN epígrafe espera unos segundos a que
 * llegue la instrucción, y las dos viajan juntas en un solo POST al `empresa-api`.
 *
 * Las dos columnas son lo mínimo para que eso funcione y se pueda diagnosticar:
 *
 *   - `agrupado_en_id`: la fila del mensaje que se llevó esta foto. Sin ella, una foto en estado
 *     `agrupado` no dice con qué instrucción viajó, que es lo primero que se pregunta el día que el
 *     dueño diga "te mandé la factura y cargaste otra cosa".
 *   - `media_en_espera`: la metadata de Kapso de la foto (URL firmada, mime, id de Meta) MIENTRAS
 *     espera, y solo mientras espera. 🔴 Va cifrada por el cast del modelo (`encrypted:array`) y se
 *     pone en null en el mismo momento en que la foto se reclama: una URL firmada de Kapso en claro
 *     en la base es una credencial guardada. Por eso es `text` y no `json`: lo que se guarda es el
 *     texto cifrado, no un JSON que MySQL pueda leer.
 *
 * Aditiva y con guardas, como el resto de las migraciones de este repo que tocan una tabla viva: las
 * filas viejas quedan con las dos columnas en null, que es exactamente "no esperó nunca".
 */
class AddEsperaDeFotoToClientAssistantMessagesTable extends Migration
{
    /**
     * Agrega las dos columnas.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('client_assistant_messages', 'agrupado_en_id')) {
            Schema::table('client_assistant_messages', function (Blueprint $table) {
                // Fila del mensaje con el que viajó esta foto. Indexado porque tras el 202 se
                // buscan las agrupadas de un mensaje para pasarles la conversación.
                $table->unsignedBigInteger('agrupado_en_id')->nullable()->index()->after('ai_message_id');
            });
        }

        if (! Schema::hasColumn('client_assistant_messages', 'media_en_espera')) {
            Schema::table('client_assistant_messages', function (Blueprint $table) {
                // Metadata de la foto mientras espera, cifrada. Null en cuanto se reclama.
                $table->text('media_en_espera')->nullable()->after('agrupado_en_id');
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
        if (Schema::hasColumn('client_assistant_messages', 'media_en_espera')) {
            Schema::table('client_assistant_messages', function (Blueprint $table) {
                $table->dropColumn('media_en_espera');
            });
        }

        if (Schema::hasColumn('client_assistant_messages', 'agrupado_en_id')) {
            Schema::table('client_assistant_messages', function (Blueprint $table) {
                $table->dropIndex(['agrupado_en_id']);
                $table->dropColumn('agrupado_en_id');
            });
        }
    }
}
