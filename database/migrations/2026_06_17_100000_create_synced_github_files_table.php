<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Almacena el contenido de archivos markdown sincronizados desde el repositorio
 * lucasgonzz/claude-comerciocity que NO tienen un modelo de dominio propio
 * (a diferencia de AgentIdentity o AiSystemPrompt).
 *
 * Ej: comercial/leads_protocolo_whatsapp.md. Los servicios de runtime leen de
 * esta tabla en lugar de pegarle a la GitHub API en el camino crítico.
 */
class CreateSyncedGithubFilesTable extends Migration
{
    /**
     * Crea la tabla synced_github_files.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('synced_github_files', function (Blueprint $table) {
            $table->id();
            /* Clave interna estable para leer el archivo desde el código (ej: 'leads_protocolo_whatsapp'). */
            $table->string('key', 120)->unique();
            /* Ruta del archivo dentro del repositorio (ej: 'comercial/leads_protocolo_whatsapp.md'). */
            $table->string('repo_path', 255);
            /* Contenido completo del markdown descargado. */
            $table->longText('content');
            /* Momento de la última sincronización exitosa (manual o automática). */
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla synced_github_files.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('synced_github_files');
    }
}
