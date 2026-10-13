<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por qué número entró (o salió) cada línea del hilo del asistente (misión whatsapp-dos-numeros,
 * 13/10/2026).
 *
 * Con un solo número de WhatsApp esta columna no hacía falta: era siempre el mismo. Con dos
 * —el institucional y el del asistente— pasa a ser el dato que contesta las dos preguntas que
 * aparecen el día que algo no funciona: *"¿este mensaje entró por el número nuevo o por el viejo?"*
 * y *"¿la respuesta salió por el mismo número por el que escribió el dueño?"*. Sin ella, las dos se
 * contestan cruzando el log del webhook con la hora, que es exactamente lo que esta tabla existe
 * para no tener que hacer.
 *
 * 🔴 **Nullable a propósito, y no es pereza.** Las filas históricas —todo el hilo desde el
 * 16/9/2026— no lo tienen y no hay de dónde deducirlo. Más importante: el payload de Kapso puede no
 * traer el campo, y en ese caso el webhook cae al número principal en vez de rechazar el mensaje.
 * La ventana de 24 hs del asistente se calcula sobre esta misma tabla y tiene que seguir
 * funcionando con filas sin este dato: un `NOT NULL` acá convertiría un dato que falta en un
 * mensaje perdido.
 *
 * Indexada porque la pregunta natural es "todo lo que entró por tal número", y porque el día que
 * haya que separar lo que cayó por el número equivocado se hace con esta columna.
 */
class AddPhoneNumberIdToClientAssistantMessagesTable extends Migration
{
    /**
     * Agrega la columna.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('client_assistant_messages')) {
            return;
        }

        if (Schema::hasColumn('client_assistant_messages', 'phone_number_id')) {
            return;
        }

        Schema::table('client_assistant_messages', function (Blueprint $table) {
            // Phone Number ID de Meta del número por el que entró o salió este mensaje.
            // 64 es holgado: los de Meta son numéricos de 15 dígitos.
            $table->string('phone_number_id', 64)->nullable()->index()->after('telefono');
        });
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('client_assistant_messages')) {
            return;
        }

        if (! Schema::hasColumn('client_assistant_messages', 'phone_number_id')) {
            return;
        }

        Schema::table('client_assistant_messages', function (Blueprint $table) {
            $table->dropIndex(['phone_number_id']);
            $table->dropColumn('phone_number_id');
        });
    }
}
