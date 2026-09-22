<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién de cada cliente gastó la IA Y CON QUÉ MODELO: el mismo hecho que `client_ai_token_usage_people`,
 * pero abierto además por proveedor y modelo.
 *
 * Es la tabla que hace posible ponerle plata a cada persona. `client_ai_token_usage_people` no trae
 * el modelo y por eso, con todas las letras, "habla de tokens y de llamadas, nunca de plata". Desde
 * la misión proveedores-ia-deepseek (22/9/2026) el `empresa-api` manda un tercer bloque,
 * `personas_modelos`, agrupado por `(DATE(created_at), auth_user_id, proveedor, modelo)`, y con el
 * modelo en la fila el costo se calcula al leer con `config('ia_precios')`, igual que en
 * `client_ai_token_usages`.
 *
 * Es una tabla aparte y no una columna más de `..._people` por la misma razón por la que aquélla
 * es aparte de `..._usages`: son cortes distintos del mismo hecho, que el `empresa-api` agrega por
 * separado. Y NO reemplaza a `..._people`: un cliente con una versión intermedia informa
 * `personas` y todavía no `personas_modelos`, y su corte por persona tiene que seguir viéndose.
 *
 * 🔴 **La clave del espejo tiene que ser, dimensión por dimensión, la misma que el `GROUP BY` de
 * la fuente.** Es la regla que dejó escrita el informe de `tokens-por-cliente`, y salió de dos
 * defectos que un chequeo cruzado encontró antes de que costaran: si el espejo agrupa por MENOS
 * dimensiones que la fuente, dos filas del mismo payload colapsan en una y la que queda tiene los
 * contadores de la última en vez de la suma (consumo que desaparece sin que nada lo denuncie); si
 * agrupa por MÁS, inventa filas que ninguna corrida futura vuelve a pisar. Acá la fuente agrupa
 * por cuatro dimensiones más el cliente, así que el unique tiene CINCO columnas:
 * `(client_id, fecha, auth_user_id, proveedor, modelo)`. Ni una menos —dos filas del mismo día y
 * la misma persona con distinto modelo son DOS filas— ni una más.
 *
 * 🔴 **Ninguna columna de la clave es nullable, a propósito.** En MySQL un NULL no colisiona con
 * otro NULL adentro de un índice único: un solo `modelo` o `proveedor` nulo alcanzaría para que el
 * upsert deje de ser idempotente y empiece a apilar filas sin que nada avise. Por eso `proveedor` y
 * `modelo` son NOT NULL con default `''` (el vacío sí colisiona consigo mismo, y se lee como lo
 * que es: "el cliente no lo informó"), y por eso `auth_user_id` es NOT NULL con centinela 0.
 *
 * 🔴 **`auth_user_id` es NOT NULL con centinela 0, no nullable.** Las filas de procesos
 * automáticos —el scheduler que indexa embeddings, los informes que salen por comando— no tienen
 * persona detrás, y son una fila legítima por cliente, día y modelo. Si esa columna fuera nullable,
 * la fila de los procesos automáticos se apilaría en cada corrida y el consumo "de nadie" crecería
 * solo. Es exactamente la misma trampa que ya se cerró en las dos tablas hermanas. El 0 es un valor
 * que sí colisiona consigo mismo, y al leer se muestra como "Procesos automáticos".
 *
 * **El costo NO se guarda.** Se calcula al leer, con `config('ia_precios')`, por los mismos motivos
 * que en `client_ai_token_usages`: guardarlo congelaría el precio del día de la recolección.
 *
 * Sin claves foráneas físicas, igual que el resto: el `auth_user_id` es de la base del CLIENTE, no
 * de ninguna tabla del admin, así que no habría contra qué apuntar aunque se quisiera.
 */
class CreateClientAiTokenUsagePersonModelsTable extends Migration
{
    /**
     * Crea la tabla client_ai_token_usage_person_models.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('client_ai_token_usage_person_models')) {
            return;
        }

        Schema::create('client_ai_token_usage_person_models', function (Blueprint $table) {
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
             * anthropic | deepseek | openai. Parte de la clave única, igual que en
             * `client_ai_token_usages`: dos filas del mismo modelo con distinto proveedor son dos
             * filas. NOT NULL con default vacío (ver el docblock de la clase); el service es el que
             * decide el fallback a `anthropic` cuando el cliente no lo informa, no el esquema.
             */
            $table->string('proveedor', 20)->default('');

            // Modelo exacto tal como lo informó el cliente. Es la clave con la que se busca el
            // precio, así que no se normaliza ni se acorta: si no está en la tabla de precios, el
            // costo de esa fila sale null y se muestra como "sin precio cargado".
            $table->string('modelo', 80)->default('');

            /*
             * Nombre de esa persona, tal como lo resolvió el cliente. Se guarda aunque sea derivable
             * del `auth_user_id` porque acá no hay forma de resolverlo: esa tabla de usuarios vive
             * en la base del cliente y el admin no la puede leer. Nullable porque un usuario borrado
             * del lado del cliente llega sin nombre, y esa fila igual tiene que quedar: el gasto
             * ocurrió. NO es parte de la clave, así que el null acá no rompe nada.
             */
            $table->string('nombre', 120)->nullable();

            // Cuántas llamadas a la API hizo esa persona con ese modelo ese día.
            $table->unsignedInteger('llamadas')->default(0);

            /*
             * Los cuatro contadores, con los mismos nombres del contrato y de las tablas hermanas.
             * Se copian tal cual: renombrarlos en el camino es justo donde este proyecto ya se
             * quemó.
             */
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_creation_input_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_input_tokens')->default(0);

            $table->timestamps();

            /*
             * 🔴 La clave del upsert idempotente, con las cinco dimensiones del GROUP BY del
             * origen (ver el docblock de la clase). Nombre explícito y corto porque el que
             * generaría Laravel con cinco columnas se pasa del techo de 64 caracteres de MySQL.
             */
            $table->unique(
                ['client_id', 'fecha', 'auth_user_id', 'proveedor', 'modelo'],
                'cli_ai_tok_permod_unico'
            );

            /*
             * No lleva además un índice `(client_id, fecha)`, igual que `..._people`: la lectura
             * de la pestaña (`client_id = ? AND fecha BETWEEN ? AND ?`) usa el prefijo izquierdo
             * de este unique, que empieza justo por esas dos columnas, y un índice duplicado es
             * peso muerto en cada escritura del barrido nocturno.
             */

            // Lectura del resumen por fecha sin filtrar cliente (hoy no se usa, pero es la consulta
            // que aparece en cuanto alguien quiera "quién gastó más en toda la plataforma").
            $table->index('fecha', 'cli_ai_tok_permod_fecha_idx');
        });
    }

    /**
     * Elimina la tabla client_ai_token_usage_person_models.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_ai_token_usage_person_models');
    }
}
