<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `scope` a env_templates para distinguir entre la plantilla
 * .env de empresa-api ('empresa', valor por defecto de las filas existentes) y la
 * nueva plantilla de tienda-api ('tienda'). Sin esta columna, ambas plantillas
 * compartirían la misma tabla sin forma de separarlas al armar cada .env.
 *
 * Importante: la tabla tenía `key` como UNIQUE global (una sola variable por nombre
 * en todo el sistema). Con dos scopes, `tienda` reutiliza keys que ya existen en
 * `empresa` (APP_NAME, DB_HOST, MAIL_HOST, etc.), así que ese unique rompería el
 * seeder de tienda. Se reemplaza por un index normal sobre `key` (se preserva la
 * búsqueda rápida por key) y la unicidad lógica pasa a resolverse en capa de
 * aplicación (seeders con updateOrCreate por key+scope; ver regla del workspace de
 * no crear unique compuestos con columnas string).
 */
return new class extends Migration
{
    /**
     * Agrega `scope` con default 'empresa' (preserva las filas existentes), reemplaza
     * el unique global de `key` por un index normal, y agrega index a `scope` para las
     * consultas que filtran por scope al generar cada .env.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('env_templates', function (Blueprint $table) {
            /* Ámbito de la variable: 'empresa' (default, filas existentes) o 'tienda'. */
            $table->string('scope', 20)->default('empresa')->after('group')->index('env_templates_scope_idx');

            /* El unique global de key ya no aplica: tienda reutiliza keys de empresa. */
            $table->dropUnique('env_templates_key_unique');
            /* Index normal para preservar la performance de búsqueda por key. */
            $table->index('key', 'env_templates_key_idx');
        });
    }

    /**
     * Revierte: quita los índices agregados, restaura el unique de `key` y elimina `scope`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('env_templates', function (Blueprint $table) {
            $table->dropIndex('env_templates_key_idx');
            $table->unique('key', 'env_templates_key_unique');
            $table->dropIndex('env_templates_scope_idx');
            $table->dropColumn('scope');
        });
    }
};
