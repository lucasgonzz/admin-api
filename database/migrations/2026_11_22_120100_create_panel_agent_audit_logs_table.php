<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de auditoría de los agentes en el panel (misión admin-control-total, 21/11/2026).
 *
 * Una fila por cada pedido que un admin marcado como agente hace a `api/admin/*`: quién, qué ruta, de qué
 * categoría, si se frenó, si se confirmó, cómo terminó y con qué datos (siempre redactados, sin claves).
 * Sin claves foráneas (regla del proyecto): `admin_id` es sólo una referencia lógica.
 */
class CreatePanelAgentAuditLogsTable extends Migration
{
    /**
     * Crea la tabla con índices cortos para las consultas por agente, categoría y fecha.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('panel_agent_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Admin agente que hizo el pedido (referencia lógica a admins.id, sin FK).
            $table->unsignedBigInteger('admin_id');
            // Nombre del agente al momento del pedido (queda aunque después se borre o renombre).
            $table->string('agent_name', 120);
            // Herramienta que llamó, según el header X-Agente-Cliente (claude-code, openclaw…).
            $table->string('agent_client', 40)->nullable();
            $table->string('method', 10);
            // Patrón de la ruta sin `api/admin/` (ej. client/{id}) y camino real pedido (ej. api/admin/client/83).
            $table->string('route_uri', 255);
            $table->string('path', 500);
            $table->string('route_name', 150)->nullable();
            // Acción del router: Controlador@metodo.
            $table->string('action', 200)->nullable();
            // lectura, escritura, credenciales, borrado, masiva, plata, terceros o excluida.
            $table->string('category', 20);
            $table->boolean('braked')->default(false);
            $table->boolean('confirmed')->default(false);
            $table->boolean('dry_run')->default(false);
            // en_curso, ejecutada, frenada, excluida o dry_run.
            $table->string('result', 20);
            $table->unsignedSmallInteger('status_code')->nullable();
            // Motivo que declaró el agente (header X-Agente-Motivo).
            $table->string('reason', 500)->nullable();
            // Payload y extracto de la respuesta, redactados y recortados.
            $table->longText('request_payload')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'created_at'], 'pal_admin_created_idx');
            $table->index('category', 'pal_category_idx');
            $table->index('created_at', 'pal_created_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('panel_agent_audit_logs');
    }
}
