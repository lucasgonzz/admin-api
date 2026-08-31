<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de instalaciones desde cero del SISTEMA (ERP) de una demo.
 *
 * Es el espejo de `client_installations` para el catálogo de demos: registra el pipeline completo
 * de instalación (directorios, public/, SPA compilado, API, .env, artisan, demo-setup y
 * verificación), la versión que se instaló, los valores de .env que cargó el operador a mano y el
 * desenlace de la corrida.
 *
 * 🔴 Se separa de `client_installations` en vez de agregarle un `demo_id` nullable. El motivo es el
 * mismo por el que existen DemoPathResolver y ClientApiPathResolver por separado: una instalación
 * de cliente cuelga de una `ClientApi` (con su `path`, su `hosting_type` y su blue/green de dos
 * subdominios) y una demo no tiene nada de eso — sus rutas se derivan del slug de `erp_spa_url`.
 * Compartir la tabla obligaría a que cada lector de `client_installations` se pregunte si la fila
 * que está mirando tiene cliente o demo, y ese es el chequeo que en algún momento alguien no hace.
 *
 * Sin FK en la base, por convención del proyecto: la integridad se sostiene en Eloquent, igual que
 * en `client_installations` y en `demo_updates`.
 */
class CreateDemoInstallationsTable extends Migration
{
    /**
     * Crea la tabla demo_installations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('demo_installations', function (Blueprint $table) {
            // Identificador interno autoincremental.
            $table->id();

            // UUID único para referencias externas (job, URLs, nombres de los ZIP temporales).
            $table->uuid('uuid')->unique();

            // Demo a la que se le instala el sistema.
            $table->unsignedBigInteger('demo_id');

            // Versión a instalar. Nullable por simetría con client_installations, pero en la
            // práctica el pipeline la exige: sin tag no hay qué compilar ni qué empaquetar.
            $table->unsignedBigInteger('version_id')->nullable();

            // Admin que disparó la instalación (nullable: puede haberse creado sin sesión).
            $table->unsignedBigInteger('created_by_admin_id')->nullable();

            // Estado del proceso: pendiente | instalando | completada | fallida.
            //
            // Mismos cuatro valores que client_installations, y NO los de demo_updates
            // (pendiente | ejecutandose | completado | fallido). Se eligió el juego de las
            // instalaciones porque este recurso es una instalación: el panel de operaciones y los
            // tests leen estos strings, y mezclar los dos vocabularios en el mismo módulo es lo
            // que después hace que un `=== 'completado'` no matchee nunca sin dar error.
            $table->string('status')->default('pendiente');

            // Valores de las variables is_manual_on_create que carga el operador antes de iniciar:
            // son las credenciales de la base que Lucas creó a mano en hPanel.
            $table->json('env_manual_values')->nullable();

            // Razón del fallo (solo cuando status = fallida).
            $table->text('failure_reason')->nullable();

            // Marca de tiempo de inicio del pipeline.
            $table->timestamp('started_at')->nullable();

            // Marca de tiempo de finalización (haya salido bien o mal).
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // El listado y el polling del panel filtran por demo y ordenan por id descendente.
            $table->index('demo_id');
        });
    }

    /**
     * Elimina la tabla demo_installations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('demo_installations');
    }
}
