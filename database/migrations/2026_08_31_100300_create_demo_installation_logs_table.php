<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de log, UNA FILA POR LÍNEA, del pipeline de instalación de una demo.
 *
 * 🔴 POR QUÉ FILAS Y NO UNA COLUMNA DE TEXTO EN `demo_installations`
 *
 * `demo_updates` guarda su log en una columna, y eso ya costó un incidente documentado
 * (13/7/2026): el log desbordó la columna TEXT, el `save()` tiró SQLSTATE[22001] adentro del
 * `catch` que intentaba dejar constancia del error, y la corrida quedó en `ejecutandose` PARA
 * SIEMPRE — el panel hace polling contra ese estado y el spinner no paraba nunca. Hubo que migrar
 * la columna a LONGTEXT (`2026_07_13_160000_change_log_to_longtext_in_demo_updates_table`) y
 * además ponerle un tope en PHP (`DemoUpdateService::MAX_LOG_CHARS`), porque
 * `DemoUpdateService::append_log()` reescribe el string ENTERO en cada línea: con salidas de
 * webpack de cientos de miles de caracteres, cada renglón nuevo es un UPDATE cada vez más pesado.
 *
 * Con una fila por línea nada de eso puede pasar: cada `INSERT` es del tamaño de su línea, una
 * línea gigante no puede romper el registro de la corrida, y el estado de `demo_installations`
 * nunca depende de que la escritura del log haya salido bien.
 *
 * 🔴 POR QUÉ NO SE REUSA `deployment_logs`, QUE ES JUSTO ESO
 *
 * `deployment_logs` es la tabla equivalente del camino de CLIENTES, y sabe apuntar a dos cosas:
 * `client_version_upgrade_id` y `client_installation_id`. No tiene columna para una corrida de
 * demo, así que soportarlo pedía tocar esa tabla, `App\Models\DeploymentLog` y el evento
 * `DeploymentLogCreated` — todos archivos del camino de producción de los clientes, fuera del
 * alcance de esta misión. Una tabla propia deja el camino de los clientes intacto byte por byte.
 *
 * Sin FK en la base, por convención del proyecto (igual que `deployment_logs`).
 */
class CreateDemoInstallationLogsTable extends Migration
{
    /**
     * Crea la tabla demo_installation_logs (solo created_at, sin updated_at).
     *
     * @return void
     */
    public function up()
    {
        Schema::create('demo_installation_logs', function (Blueprint $table) {
            // Identificador interno. Es además el criterio de orden del log: dos líneas del mismo
            // segundo tienen que salir en el orden en que se escribieron, y `created_at` no lo
            // garantiza (la precisión de la columna es de un segundo).
            $table->id();

            // Corrida de instalación a la que pertenece esta línea.
            $table->unsignedBigInteger('demo_installation_id');

            // Etapa del pipeline que la escribió (prepare_dirs, upload_api, run_demo_setup, ...).
            $table->string('step');

            // Contenido de la línea.
            //
            // TEXT (64 KB) y no LONGTEXT a propósito: acá una línea es UNA línea, no el log entero.
            // Quien escribe recorta la salida remota en trozos antes de insertar
            // (DemoInstallationService::log_remote_output()), así que el desborde que sí era
            // alcanzable con el log en una sola celda no lo es con este diseño.
            $table->text('line');

            // Nivel: info | success | error | warning.
            $table->string('level');

            // Solo marca de creación: una línea de log no se modifica nunca.
            $table->timestamp('created_at')->nullable();

            // El panel pide el log completo de una corrida ordenado por id.
            $table->index(['demo_installation_id', 'id']);
        });
    }

    /**
     * Elimina la tabla demo_installation_logs.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('demo_installation_logs');
    }
}
