<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los paquetes de suscripción de IA que ofrece ComercioCity (misión foto-sucursal-y-asistente-
 * configurable, 17/9/2026). Cada uno define un precio en dólares y los dos topes que el dueño de
 * un comercio no puede pasar en un mes: tokens y cantidad de interacciones diarias con el asistente.
 *
 * 🔴 Los topes son el corazón de esto y por eso son NULLABLE: null (o 0) significa "sin tope", y ese
 * es el estado por defecto de todo el parque hasta que Lucas asigne un paquete. El corte del lado de
 * empresa NUNCA se activa sin un tope > 0 recibido, así que un cliente sin plan asignado funciona
 * como siempre. Ver el contrato de la misión: la compatibilidad hacia atrás vive en que estos dos
 * campos puedan viajar en null.
 *
 * `activo` es baja LÓGICA, no física: un paquete que se desactiva deja de ofrecerse en el ABM pero
 * sigue existiendo, porque puede estar asignado a clientes (clients.ai_plan_id) y no hay FK física
 * que impida borrarlo. Ver AiPlanController::destroy_json() para el porqué.
 *
 * Guard `hasTable` porque esta migración corre sobre la base de producción del admin y sobre las
 * bases de testing de los slots, que pueden estar en estados distintos.
 */
class CreateAiPlansTable extends Migration
{
    /**
     * Crea la tabla ai_plans.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ai_plans')) {
            return;
        }

        Schema::create('ai_plans', function (Blueprint $table) {
            $table->id();

            // Nombre del paquete (Básico, Intermedio, Pro). Único para que el seeder pueda ser
            // idempotente por nombre y para que el ABM no cree dos paquetes con el mismo rótulo.
            $table->string('nombre')->unique();

            // Precio mensual en dólares. decimal(10,2) alcanza de sobra para un precio de suscripción.
            $table->decimal('precio_usd', 10, 2)->default(0);

            // Tope de tokens del mes calendario. NULL o 0 = sin tope (no corta). unsignedBigInteger
            // porque un mes de un comercio que indexa el catálogo pasa los 4.294.967.295 sin esfuerzo.
            $table->unsignedBigInteger('tope_tokens_mensual')->nullable();

            // Tope de interacciones por día con el asistente. NULL o 0 = sin tope.
            $table->unsignedInteger('tope_interacciones_diarias')->nullable();

            // Baja lógica: un paquete inactivo no se ofrece pero sigue existiendo (ver el docblock).
            $table->boolean('activo')->default(true);

            // Orden de presentación en el ABM y en el selector de la ficha del cliente.
            $table->integer('orden')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla ai_plans.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_plans');
    }
}
