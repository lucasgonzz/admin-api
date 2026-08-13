<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: el plan de demo congelado del lead y el secreto del canal de eventos (misión 48 —
 * `contexto/demo_experiencia.md` §9, bloque C).
 *
 * Tres columnas sobre `leads`:
 *
 *  - `demo_plan`: el plan resuelto por {@see \App\Services\DemoPlanResolver}, serializado. Es una
 *    FOTO: se escribe una sola vez y no se recalcula al leer. El catálogo (`demo_catalogo.json`)
 *    se sincroniza a producción sin deploy, así que resolverlo en cada lectura le cambiaría el
 *    roadmap a un lead que está en el medio de la demo.
 *  - `demo_plan_congelado_at`: cuándo se congeló. Null = todavía no tiene plan; es la condición
 *    que hace idempotente al congelamiento.
 *  - `demo_eventos_token`: secreto con el que la instancia de demo se identifica al reportar
 *    eventos (`POST /api/demo-eventos`). El token ES el identificador del lead, por eso lleva
 *    índice: es el campo por el que el middleware busca.
 *
 * Sin foreign keys: regla del workspace.
 */
class AddDemoPlanToLeadsTable extends Migration
{
    /**
     * Ejecuta la migración (agrega las tres columnas a `leads`).
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // El plan congelado completo (secciones, clips, condiciones inválidas, totales).
            $table->json('demo_plan')->nullable()->after('demo_form_completado_at');

            // Marca de congelamiento. Null = sin plan todavía.
            $table->timestamp('demo_plan_congelado_at')->nullable()->after('demo_plan');

            // Secreto del canal de eventos. Longitud acotada a 64 (Str::random(64)), con índice
            // simple porque es la clave de búsqueda del middleware DemoEventosKey.
            $table->string('demo_eventos_token', 64)->nullable()->after('demo_plan_congelado_at');
            $table->index('demo_eventos_token');
        });
    }

    /**
     * Revierte la migración (elimina las tres columnas).
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['demo_eventos_token']);
            $table->dropColumn(['demo_plan', 'demo_plan_congelado_at', 'demo_eventos_token']);
        });
    }
}
