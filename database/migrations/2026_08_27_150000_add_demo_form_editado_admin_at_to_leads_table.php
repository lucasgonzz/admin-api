<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de que un admin editó a mano, desde el modal del lead, las respuestas del formulario de
 * configuración de la demo (misión del 27/8/2026).
 *
 * 🔴 Es una columna NUEVA y no un reuso de `demo_form_completado_at`, y ese es el punto entero de
 * la migración. Sin una marca aparte, `LeadDemoFormMapper::respuestas_efectivas()` —que decide con
 * qué respuestas se arma la instancia— seguiría devolviendo los defaults del catálogo para
 * cualquier lead que no completó el formulario, ignorando lo que el admin acaba de guardar: o sea,
 * exactamente lo contrario de lo que se pidió.
 *
 * Y marcar `demo_form_completado_at` en vez de agregar esta columna no alcanza, por dos motivos
 * que se suman:
 *
 *  1. MIENTE. Esa fecha significa "el lead contestó", y la tarjeta del panel tiene que poder
 *     avisar si el lead contestó o si las respuestas las puso el admin. Con una sola fecha las dos
 *     cosas son indistinguibles.
 *  2. Tiene un efecto colateral REAL sobre el disparo automático:
 *     `RunDemoSetupService::evaluar_disparo()` cambia de rama según `demo_form_completado_at`, así
 *     que una edición manual pasaría a armar la demo a T-15 en vez de esperar el fallback de T+5.
 *     Una edición desde el panel no puede mover cuándo se arma la instancia.
 *
 * Compatible hacia atrás por construcción: columna `nullable`, sin default obligatorio y sin tocar
 * ninguna existente. Un `admin-api` viejo contra esta base la ignora y sigue andando; el nuevo
 * contra un lead sin la marca cae al camino de siempre.
 *
 * Sin foreign keys ni índices (regla del repo): no se busca por esta columna, se lee del lead que
 * ya se trajo.
 */
return new class extends Migration
{
    /**
     * Agrega la columna, pegada a `demo_form_completado_at` porque son las dos caras de la misma
     * pregunta —quién dejó estas respuestas— y leer el `describe` de `leads` con las dos juntas es
     * la mitad de la explicación.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('demo_form_editado_admin_at')->nullable()->after('demo_form_completado_at');
        });
    }

    /**
     * Revierte la columna. Acá SÍ se dropea, a diferencia de otras migraciones del repo que dejan
     * el `down()` vacío: es una columna sola, sin índices ni datos que dependan de ella, y volver
     * atrás tiene que dejar la tabla como estaba o el próximo `up()` choca con que ya existe.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('demo_form_editado_admin_at');
        });
    }
};
