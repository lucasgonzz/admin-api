<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los dos números de WhatsApp del admin (misión whatsapp-dos-numeros, 13/10/2026).
 *
 * Hasta hoy `whatsapp_config` era "la" fila: un único número por el que entraban y salían leads,
 * soporte, implementación y —desde el 16/9/2026— el asistente personal de cada dueño. El pedido de
 * Lucas separa esos dos mundos en dos números distintos: el +54 9 3444 54-4199 sigue siendo el
 * institucional (leads y soporte) y el asistente de los dueños se muda a un número propio. El admin
 * escucha los dos y rutea por el `phone_number_id` que viene en el payload de Kapso.
 *
 * Por eso la tabla pasa de "una fila" a "una fila por propósito", y las cuatro columnas son lo
 * mínimo para que eso funcione y se pueda operar:
 *
 *   - `proposito`: `principal` o `asistente`. Con default e índice, porque `getActive()` —que lo
 *     llaman ~20 lugares— ahora filtra por esta columna y lo hace en cada envío.
 *   - `display_phone`: el número tal como se le muestra a una persona (+54 9 3444 54-4199). Meta
 *     no lo devuelve en ningún payload y el `phone_number_id` es un número opaco de 15 dígitos:
 *     sin esto, el panel no puede decir cuál de las dos tarjetas es cuál.
 *   - `nombre`: etiqueta corta para el panel y los logs ("Institucional", "Asistente").
 *   - `waba_id`: la cuenta de WhatsApp Business a la que pertenece el número. Es el dato que hace
 *     falta para ir a buscar algo a Kapso o a Meta sobre ESE número y no sobre el otro.
 *
 * 🔴 **Esta migración NO crea la fila del asistente.** No existe todavía el `phone_number_id` del
 * número nuevo (lo tiene que leer Lucas en `app.kapso.ai`), y sin fila de asistente **todo se
 * comporta exactamente como hoy**: `getActive()` devuelve la misma fila de siempre, ahora marcada
 * `principal`. La fila del asistente se crea desde el panel cuando estén los datos.
 *
 * Aditiva y con guardas, como el resto de las migraciones de este repo que tocan una tabla viva.
 */
class AddPropositoToWhatsappConfigTable extends Migration
{
    /**
     * Agrega las cuatro columnas y marca como principal lo que ya estaba.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('whatsapp_config')) {
            return;
        }

        $recien_creada = ! Schema::hasColumn('whatsapp_config', 'proposito');

        if ($recien_creada) {
            Schema::table('whatsapp_config', function (Blueprint $table) {
                // Indexada porque se filtra por ella en cada resolución de credenciales de envío.
                $table->string('proposito', 20)->default('principal')->index()->after('id');
            });
        }

        if (! Schema::hasColumn('whatsapp_config', 'display_phone')) {
            Schema::table('whatsapp_config', function (Blueprint $table) {
                // El número legible, solo para que una persona sepa qué fila está mirando.
                $table->string('display_phone')->nullable()->after('proposito');
            });
        }

        if (! Schema::hasColumn('whatsapp_config', 'nombre')) {
            Schema::table('whatsapp_config', function (Blueprint $table) {
                // Etiqueta corta del número, para el panel y los logs.
                $table->string('nombre')->nullable()->after('display_phone');
            });
        }

        if (! Schema::hasColumn('whatsapp_config', 'waba_id')) {
            Schema::table('whatsapp_config', function (Blueprint $table) {
                // Cuenta de WhatsApp Business dueña del número.
                $table->string('waba_id')->nullable()->after('nombre');
            });
        }

        /* 🔴 La fila que ya existe en producción queda marcada EXPLÍCITAMENTE como principal, y no
         * solo por el default de la columna. El default cubre el `ALTER TABLE` de MySQL, pero una
         * base que hubiera llegado acá con la columna puesta de otra forma (un dump viejo, una
         * remediación a mano) podría tener la fila en null — y una fila sin propósito es una fila
         * que `getActive()` no encuentra, o sea el número de leads mudo.
         *
         * Va sin `where` a propósito —todas las filas existentes son del número institucional,
         * porque el del asistente todavía no existe— pero SOLO cuando la columna la acaba de crear
         * esta corrida. Si la columna ya estaba, acá puede haber una fila de asistente ya cargada y
         * un UPDATE pelado se la daría vuelta. */
        if ($recien_creada) {
            DB::table('whatsapp_config')->update(['proposito' => 'principal']);
        }
    }

    /**
     * Saca las cuatro columnas.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('whatsapp_config')) {
            return;
        }

        foreach (['waba_id', 'nombre', 'display_phone'] as $columna) {
            if (Schema::hasColumn('whatsapp_config', $columna)) {
                Schema::table('whatsapp_config', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }

        if (Schema::hasColumn('whatsapp_config', 'proposito')) {
            /* 🔴 Sin la columna, `getActive()` del código anterior es «la primera fila activa», y con una
             * fila del asistente cargada podría devolver ESA y mandar los leads por el número equivocado.
             * Se la apaga antes de perder el dato que la distingue. */
            DB::table('whatsapp_config')->where('proposito', '!=', 'principal')->update(['is_active' => false]);

            Schema::table('whatsapp_config', function (Blueprint $table) {
                $table->dropIndex(['proposito']);
                $table->dropColumn('proposito');
            });
        }
    }
}
