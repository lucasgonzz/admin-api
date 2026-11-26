<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcas «requiere actualización» de los clientes (misión martin-descripcion-y-actualizaciones, 25/11/2026).
 *
 * Cada fila es un motivo para actualizar el sistema (o la tienda) de un cliente: una corrección de soporte ya
 * integrada o una funcionalidad ya mergeada. Queda abierta (`resolved_at` null) hasta que el cliente instala una
 * versión que la contiene o alguien la marca resuelta a mano. `clients.requires_upgrade` refleja si hay alguna
 * abierta y lo mantiene sólo `ClientUpgradeMarkService`.
 *
 * Sin claves foráneas (referencias lógicas a clients, versions, support_tickets y support_codex_missions);
 * strings acotados, defaults en todo lo obligatorio e índices de nombre corto. El único `unique` es de una sola
 * columna (`external_key`, string 120): hace idempotente el alta (`mission:<id>`, `cc:<slug>`).
 */
class CreateClientUpgradeMarksTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_upgrade_marks', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Cliente a actualizar (clients.id, sin FK).
            $table->unsignedBigInteger('client_id');
            // bug | funcionalidad.
            $table->string('kind', 20)->default('funcionalidad');
            // sistema (empresa-api/empresa-spa) | tienda (tienda-api/tienda-spa).
            $table->string('target', 20)->default('sistema');
            // soporte (evidencia de una misión) | claude_code (misiones_cc) | manual.
            $table->string('source', 20)->default('manual');
            $table->string('title', 255)->default('');
            $table->text('detail')->nullable();
            $table->unsignedBigInteger('support_ticket_id')->nullable();
            $table->unsignedBigInteger('support_codex_mission_id')->nullable();
            // Clave idempotente del alta: mission:<id>, cc:<slug>, …
            $table->string('external_key', 120)->nullable();
            // {repo: sha40} de los repos que se instalan en el cliente.
            $table->json('commits')->nullable();
            $table->timestamp('merged_at')->nullable();
            // Primera versión publicada que contiene la corrección y cómo se supo (tag | fecha).
            $table->unsignedBigInteger('available_version_id')->nullable();
            $table->string('available_by', 10)->nullable();
            // Última versión publicada mirada por el refresh (evita volver a consultar GitHub sin novedades).
            $table->unsignedBigInteger('last_checked_version_id')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_version_id')->nullable();
            // instalada | manual | descartada.
            $table->string('resolved_reason', 20)->nullable();
            $table->string('resolution_note', 300)->nullable();
            $table->timestamps();

            $table->unique('external_key', 'cum_external_key_unq');
            $table->index(['client_id', 'resolved_at'], 'cum_client_resolved_idx');
            $table->index('support_codex_mission_id', 'cum_mission_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_upgrade_marks');
    }
}
