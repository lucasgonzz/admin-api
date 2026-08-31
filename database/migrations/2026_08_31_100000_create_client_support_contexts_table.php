<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha de contexto por cliente para el agente de soporte.
 *
 * 🔴 POR QUÉ SON DOS COLUMNAS DE TEXTO Y NO UNA. `ficha_operativa` se inyecta en el prompt de
 * CADA consulta del agente; `notas_internas` no se inyecta nunca y es para el operador humano.
 * La separación tiene que estar en el esquema y no en la disciplina de quien escribe: con un solo
 * bloque, tarde o temprano alguien anota "este cliente es de trato difícil" y ese juicio termina
 * condicionando el tono de la respuesta que se le manda a ese mismo cliente. Con dos columnas es
 * imposible por construcción, porque el camino del prompt no nombra la segunda
 * (ver ClientSupportContext::ficha_operativa_de_cliente()).
 *
 * 🔴 POR QUÉ NO HAY NINGUNA COLUMNA CON DATOS DEL CLIENTE. Tickets abiertos, antigüedad, versión
 * que corre, mensajes intercambiados y veces que se escaló son todos calculables leyendo la base,
 * y SupportClientContextService los calcula al armar el prompt. Guardarlos acá sería garantizar
 * que queden desactualizados sin que nada lo denuncie: el agente leería con confianza un dato
 * viejo, que es peor que no tener el dato.
 */
class CreateClientSupportContextsTable extends Migration
{
    /**
     * Crea la tabla de fichas de contexto de soporte.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_support_contexts', function (Blueprint $table) {
            $table->id();

            /* 🔴 UNIQUE, no un index a secas: la idempotencia por client_id del endpoint de carga
               la garantiza este índice y no el código. Sin él, dos corridas encimadas del mismo
               lote dejan dos fichas del mismo cliente y el prompt levanta una de las dos al azar. */
            $table->unsignedBigInteger('client_id')->unique();

            /* Lo ÚNICO que se inyecta en el prompt del agente. Markdown libre: lo que hay para
               decir de cada cliente es distinto (de uno importa que habla sólo por audio, de otro
               que viene de tres regresiones seguidas) y las casillas fijas obligan a llenar con
               relleno, que es peor que no decir nada cuando se inyecta en cada llamada. */
            $table->text('ficha_operativa')->nullable();

            /* 🔴 NUNCA se inyecta en el prompt. Juicios sobre la persona, temas comerciales, todo
               lo que es para el operador humano y no para el agente. */
            $table->text('notas_internas')->nullable();

            /* Origen de la fila, mismo criterio que admin_tasks.created_via y
               client_version_upgrades.created_via: 'claude' cuando la cargó la sesión de Claude
               por POST claude/client-context. Se estampa SÓLO en el alta. */
            $table->string('created_via', 30)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla de fichas de contexto de soporte.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_support_contexts');
    }
}
