<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El consumo de IA de cada cliente, traído de su `empresa-api` y agregado por día, acción y modelo.
 *
 * 🔴 Esta tabla es un ESPEJO, no un libro mayor. La fuente de verdad es `ai_token_usages` en el
 * `empresa-api` de cada cliente, que tiene una fila por llamada; acá vive el agregado que el admin
 * necesita para mostrar el gasto sin pegarle a cuarenta y cinco instancias cada vez que Lucas abre
 * una pestaña. Todo lo que hay acá se puede tirar y reconstruir volviendo a pedir el rango.
 *
 * Por eso el unique `(client_id, fecha, proceso, proveedor, modelo)` es la pieza central y no un
 * detalle de performance: es lo que hace que volver a pedir un rango REESCRIBA en vez de acumular.
 * Un acumulador que se desincroniza en silencio es exactamente la clase de error que
 * `APRENDER_NO_PARCHEAR.md` tiene escrita bajo "estado derivado guardado en su propio slot", y acá
 * se cierra por esquema: la base misma no deja que exista una segunda fila para la misma
 * combinación.
 *
 * 🔴 **Las CINCO dimensiones del unique son exactamente las cinco por las que agrupa el origen**
 * (`DATE(created_at), proceso, proveedor, modelo` más el cliente). Indexar cuatro y dejar
 * `proveedor` en los valores parece inofensivo —el modelo casi siempre determina el proveedor— y
 * no lo es: dos filas del mismo payload con el mismo modelo y distinto proveedor colapsan en una,
 * y la que queda tiene los contadores de la última, no la suma. La regla es que la clave del espejo
 * tiene que ser, dimensión por dimensión, la misma que la del `GROUP BY` de la fuente.
 *
 * 🔴 `proceso` y `modelo` son NOT NULL con default `''` a propósito. En MySQL un NULL no colisiona
 * con otro NULL dentro de un índice único, así que un solo `modelo` nulo alcanzaría para que el
 * upsert deje de ser idempotente y empiece a apilar filas sin que nada avise. El vacío es un valor
 * que sí colisiona consigo mismo, y encima se lee como lo que es: "el cliente no lo informó".
 *
 * **El costo NO se guarda.** Se calcula al leer, con `config('ia_precios')`. Guardarlo congelaría
 * el precio del día en que se recolectó y obligaría a un backfill cada vez que un proveedor cambie
 * la lista — y los precios de la IA cambian más seguido que estos datos.
 *
 * Sin claves foráneas físicas, igual que `client_assistant_messages`: el consumo histórico tiene
 * que sobrevivir al borrado de un cliente para poder mirar qué se gastó, y una FK con cascada es
 * exactamente lo contrario de eso.
 */
class CreateClientAiTokenUsagesTable extends Migration
{
    /**
     * Crea la tabla client_ai_token_usages.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('client_ai_token_usages')) {
            return;
        }

        Schema::create('client_ai_token_usages', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Cliente dueño del consumo. Sin FK física (ver el docblock de la clase).
            $table->unsignedBigInteger('client_id');

            // Día del consumo, en la zona horaria del comercio (la resuelve el empresa-api, no acá).
            $table->date('fecha');

            /*
             * La acción que gastó: chat_mensaje, whatsapp_sugerencia, embeddings_articulos,
             * import_excel_articulos… Es la granularidad que pidió Lucas ("el detalle por acción").
             * NOT NULL con default vacío: forma parte del índice único.
             */
            $table->string('proceso', 40)->default('');

            /*
             * anthropic | openai. Costear por el nombre del modelo sería adivinar:
             * `text-embedding-3-small` no dice "openai" en ningún lado.
             * NOT NULL con default: es parte del índice único (ver el docblock de la clase).
             */
            $table->string('proveedor', 20)->default('anthropic');

            // Modelo exacto tal como lo informó el cliente. Es la clave con la que se busca el
            // precio, así que no se normaliza ni se acorta: si no está en la tabla de precios, el
            // costo de esa fila sale null y se muestra como "sin precio cargado".
            $table->string('modelo', 80)->default('');

            // Cuántas llamadas a la API se agregaron en esta fila.
            $table->unsignedInteger('llamadas')->default(0);

            /*
             * Los cuatro contadores, con los mismos nombres que usa la API de Anthropic y que ya
             * usa `ai_token_usages` del lado del cliente. Se copian tal cual: renombrarlos en el
             * camino es justo donde este proyecto ya se quemó (`manual_tasks` vs `tareas`).
             * `unsignedBigInteger` y no `unsignedInteger` porque un cliente que indexa el catálogo
             * entero pasa los 4.294.967.295 tokens en un día sin despeinarse.
             */
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_creation_input_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_input_tokens')->default(0);

            $table->timestamps();

            /*
             * 🔴 La clave del upsert idempotente, con las cinco dimensiones del GROUP BY del
             * origen. Nombre explícito y corto porque el que generaría Laravel con cinco columnas
             * se pasa del techo de 64 caracteres de MySQL.
             */
            $table->unique(['client_id', 'fecha', 'proceso', 'proveedor', 'modelo'], 'cli_ai_tok_uso_unico');

            // Lectura natural de la pestaña de un cliente: su consumo en un rango de fechas.
            $table->index(['client_id', 'fecha'], 'cli_ai_tok_uso_cliente_fecha_idx');

            // Lectura del resumen global: todos los clientes en un rango de fechas.
            $table->index('fecha', 'cli_ai_tok_uso_fecha_idx');
        });
    }

    /**
     * Elimina la tabla client_ai_token_usages.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_ai_token_usages');
    }
}
