<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: tabla `demo_media` (grupo 300, prompt 02 — `contexto/demo_experiencia.md` §3.18).
 *
 * Guarda las URLs de multimedia editables desde el admin, keyeadas por el `slot_id` del catálogo
 * (`contexto/demo_catalogo.json`, sincronizado por AgentPromptSyncService). La ESTRUCTURA de los
 * slots (qué clips existen, orden, tipo) vive en el repo y se versiona; acá solo se persiste el
 * link de cada uno. Decisión de arquitectura documentada en esa misma sección: cambiar un video es
 * cambiar un campo, sin deploy.
 *
 * Las filas se crean bajo demanda (cuando se guarda una URL por primera vez para un slot); no hace
 * falta sembrar las 43 filas de antemano, ver `DemoMediaController::update_json()`.
 *
 * Sin foreign keys: regla del workspace, no usar `foreign()`/`constrained()` en migraciones nuevas.
 */
class CreateDemoMediaTable extends Migration
{
    /**
     * Ejecuta la migración (crea la tabla `demo_media`).
     */
    public function up()
    {
        Schema::create('demo_media', function (Blueprint $table) {
            // Clave primaria de la fila.
            $table->id();

            // Id del slot tal cual viene del catálogo (`1.1`, `2.3`, `intro`, `cierre`, `scroll.1`).
            // Es la clave natural del recurso: unique, longitud acotada (regla de índices del
            // workspace, nada de string() sin tamaño en columnas indexadas).
            $table->string('slot_id', 40)->unique();

            // URL del archivo multimedia cargado desde el admin. Nullable: la ausencia de fila (o
            // de valor) significa "sin cargar todavía", se muestra el placeholder en la página.
            $table->string('url', 500)->nullable();

            // Timestamps estándar de creación/actualización.
            $table->timestamps();
        });
    }

    /**
     * Revierte la migración (elimina la tabla `demo_media`).
     */
    public function down()
    {
        Schema::dropIfExists('demo_media');
    }
}
