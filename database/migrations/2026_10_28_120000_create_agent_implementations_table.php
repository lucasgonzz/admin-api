<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementación de Agentes de IA de un cliente: conectar SU número de WhatsApp (el que ya usa o una
 * línea nueva) a la extensión `agentes_ia` de su sistema, en ocho etapas (misión
 * `implementacion-agentes-ia`, 28/10/2026).
 *
 * Existe para que el proceso que hasta acá vivía en `herramientas/implementar-agentes/README.md` y en
 * la cabeza de quien lo llevaba quede ASENTADO en el admin, etapa por etapa, y lo pueda seguir Martín
 * (el soporte de Codex) desde el ticket del cliente igual que sigue la implementación del sistema y la
 * de la tienda.
 *
 * Reglas del repo aplicadas: sin foreign keys (las relaciones viven en los modelos), índices con
 * nombre corto, strings con largo acotado y todo lo nuevo con default o nullable.
 */
class CreateAgentImplementationsTable extends Migration
{
    /**
     * Crea la tabla agent_implementations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('agent_implementations', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Cliente dueño del número que se conecta (sin FK, a propósito: regla del repo).
            $table->unsignedBigInteger('client_id')->index('agi_client_idx');
            // in_progress | completed | cancelled. String y no enum: sumar un estado no pide migración.
            $table->string('status', 20)->default('in_progress')->index('agi_status_idx');
            // nuevo (línea nueva, sin historial) | propio (el número que el negocio ya usa).
            $table->string('camino', 10)->nullable();
            // La primera etapa que no está completed ni skipped (8 si están todas).
            $table->unsignedTinyInteger('current_stage')->default(1);
            // Ids de Kapso y de Meta que deja la conexión (etapa 3). No viajan al contexto de Martín.
            $table->string('kapso_customer_id', 80)->nullable();
            $table->string('phone_number_id', 60)->nullable();
            $table->string('waba_id', 60)->nullable();
            $table->string('business_portfolio_id', 60)->nullable();
            // Arranque y cierre (completed o cancelled) de la implementación.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla agent_implementations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('agent_implementations');
    }
}
