<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a client_installations el tipo de instalación y el grupo al que pertenece.
 *
 * Nace del esqueleto del subdominio secundario: un mismo pedido del operador puede crear DOS
 * filas —la instalación real sobre una ClientApi y el esqueleto sobre la otra— que se inician
 * juntas pero fallan o se completan por separado.
 */
class AddKindAndGroupUuidToClientInstallationsTable extends Migration
{
    /**
     * Agrega las columnas kind y group_uuid.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_installations', function (Blueprint $table) {
            // 'completa' | 'esqueleto'.
            //
            // 🔴 El default es 'completa' y NO nullable a propósito: toda fila que ya existe en la
            // tabla es una instalación completa, y con este default MySQL las rellena en el mismo
            // ALTER TABLE sin una sola sentencia de backfill. Un default 'esqueleto' o un nullable
            // obligarían a que cada lector se pregunte qué significa la ausencia de valor, y
            // InstallationService elige el pipeline comparando este campo: una fila vieja sin valor
            // dejaría de correr el pipeline real.
            //
            // Sin enum de base (ni de PHP: el proyecto corre 7.4). Los valores válidos viven en
            // ClientInstallation::KIND_* y en la validación del controlador.
            $table->string('kind', 20)->default('completa')->after('version_id');

            // UUID compartido por las filas que se crearon juntas y se inician juntas.
            //
            // NULL significa "esta fila no forma parte de un par", y es lo que hace que todo lo
            // viejo conserve su semántica de-a-una en start(), show() y update_env_values(): sin
            // grupo, esos endpoints se comportan exactamente como antes de esta migración.
            $table->uuid('group_uuid')->nullable()->after('kind');

            // Se busca por grupo en cada start() y en cada poll del SPA.
            $table->index('group_uuid');

            // Sin FK, por convención del proyecto (igual que el resto de esta tabla): la
            // integridad se sostiene en Eloquent.
        });
    }

    /**
     * Saca las columnas kind y group_uuid.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_installations', function (Blueprint $table) {
            // El índice se dropea antes que la columna: MySQL no deja borrar una columna indexada
            // sin sacar el índice primero.
            $table->dropIndex(['group_uuid']);
            $table->dropColumn(['kind', 'group_uuid']);
        });
    }
}
