<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se busca en el buscador del centro de recursos (misión busquedas-centro-recursos, 16/10/2026).
 *
 * Una fila por BÚSQUEDA (no por tecla): la página identifica cada una con un `evento` (id aleatorio) y
 * lo manda cuando el usuario deja de escribir, y otra vez —con el mismo `evento`— si abre un resultado.
 * Por eso `evento` es único y el servidor hace upsert.
 *
 * `consulta` es la consulta ya NORMALIZADA (minúsculas, sin acentos, espacios colapsados, recortada) y
 * sin datos personales: lo que tiene mail, link o números largos nunca llega acá. `recursos_usuario_id`
 * es NULL cuando buscó un invitado; si el usuario se borra, la búsqueda queda (como invitada).
 *
 * `primero_codigo` / `abierto_codigo` son códigos de video (`T1.1`) a propósito sin clave foránea: el
 * catálogo se recarga y un video puede salir; la búsqueda guarda lo que pasó en su momento.
 */
class CreateRecursosBusquedasTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('recursos_busquedas')) {
            return;
        }

        Schema::create('recursos_busquedas', function (Blueprint $table) {
            $table->id();
            $table->string('evento', 40);
            $table->foreignId('recursos_usuario_id')->nullable()->constrained('recursos_usuarios')->nullOnDelete();
            $table->string('consulta', 120);
            $table->unsignedInteger('resultados')->default(0);
            $table->string('primero_codigo', 20)->nullable();
            $table->boolean('completo')->default(false);
            $table->string('abierto_codigo', 20)->nullable();
            $table->unsignedInteger('abierto_posicion')->nullable();
            $table->timestamps();

            $table->unique('evento', 'recursos_busquedas_evento_unique');
            $table->index('created_at', 'recursos_busquedas_created_at_index');
            $table->index(['consulta', 'created_at'], 'recursos_busquedas_consulta_fecha_index');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('recursos_busquedas');
    }
}
