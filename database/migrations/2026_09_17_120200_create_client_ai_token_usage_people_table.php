<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién de cada cliente gastó la IA: el mismo consumo de `client_ai_token_usages`, pero abierto por
 * persona en vez de por acción y modelo.
 *
 * Es una tabla aparte y no una columna más de aquélla porque son dos cortes distintos del mismo
 * hecho: el `empresa-api` los agrega por separado (uno por `proceso`/`modelo`, el otro por
 * `auth_user_id`) y meterlos en la misma fila multiplicaría la cardinalidad por la cantidad de
 * personas sin que nadie pida ese cruce.
 *
 * 🔴 **Sin modelo, o sea sin costo.** Acá no viaja con qué modelo se gastó, así que no se puede
 * costear: esta tabla habla de tokens y de llamadas, nunca de plata. La interfaz lo dice con todas
 * las letras para que nadie lea estos números como dólares.
 *
 * 🔴 **`auth_user_id` es NOT NULL con centinela 0, no nullable.** Las filas de procesos automáticos
 * —el scheduler que indexa embeddings, los informes que salen por comando— no tienen persona
 * detrás, y son una fila legítima por cliente y por día. Si esa columna fuera nullable, en MySQL un
 * NULL no colisiona con otro NULL adentro de un índice único: la fila de los procesos automáticos
 * se apilaría en cada corrida y el consumo "de nadie" crecería solo, sin que nada avise. Es
 * exactamente la misma trampa que ya se cerró en `client_ai_token_usages` poniendo `proceso` y
 * `modelo` en NOT NULL con default vacío. El 0 es un valor que sí colisiona consigo mismo, y al
 * leer se muestra como "Procesos automáticos".
 *
 * Sin claves foráneas físicas, igual que el resto: el `auth_user_id` es de la base del CLIENTE, no
 * de ninguna tabla del admin, así que no habría contra qué apuntar aunque se quisiera.
 */
class CreateClientAiTokenUsagePeopleTable extends Migration
{
    /**
     * Crea la tabla client_ai_token_usage_people.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('client_ai_token_usage_people')) {
            return;
        }

        Schema::create('client_ai_token_usage_people', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Cliente dueño del consumo. Sin FK física (ver el docblock de la clase).
            $table->unsignedBigInteger('client_id');

            // Día del consumo, en la zona horaria del comercio (la resuelve el empresa-api).
            $table->date('fecha');

            /*
             * Usuario de la base del CLIENTE que disparó el gasto.
             * 🔴 0 = "procesos automáticos" (ver el docblock de la clase). NOT NULL a propósito:
             * un NULL no colisiona consigo mismo en un índice único y el upsert dejaría de ser
             * idempotente justo para la fila que más se repite.
             */
            $table->unsignedBigInteger('auth_user_id')->default(0);

            /*
             * Nombre de esa persona, tal como lo resolvió el cliente. Se guarda aunque sea derivable
             * del `auth_user_id` porque acá no hay forma de resolverlo: esa tabla de usuarios vive
             * en la base del cliente y el admin no la puede leer. Nullable porque un usuario borrado
             * del lado del cliente llega sin nombre, y esa fila igual tiene que quedar: el gasto
             * ocurrió.
             */
            $table->string('nombre', 120)->nullable();

            // Cuántas llamadas a la API hizo esa persona ese día.
            $table->unsignedInteger('llamadas')->default(0);

            /*
             * Los cuatro contadores, con los mismos nombres del contrato y de la otra tabla. Se
             * copian tal cual: renombrarlos en el camino es justo donde este proyecto ya se quemó.
             */
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_creation_input_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_input_tokens')->default(0);

            $table->timestamps();

            /*
             * 🔴 La clave del upsert idempotente, hermana de `cli_ai_tok_uso_unico`. Volver a pedir
             * un rango reescribe estas filas, no las acumula.
             *
             * No lleva además un índice `(client_id, fecha)`: el prefijo izquierdo de este unique ya
             * sirve exactamente esa búsqueda, y un índice duplicado es peso muerto en cada
             * escritura.
             */
            $table->unique(['client_id', 'fecha', 'auth_user_id'], 'cli_ai_tok_per_unico');

            // Lectura del resumen por fecha sin filtrar cliente (hoy no se usa, pero es la consulta
            // que aparece en cuanto alguien quiera "quién gastó más en toda la plataforma").
            $table->index('fecha', 'cli_ai_tok_per_fecha_idx');
        });
    }

    /**
     * Elimina la tabla client_ai_token_usage_people.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_ai_token_usage_people');
    }
}
