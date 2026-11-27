<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro idempotente de los avisos push de «mensaje esperando tu aprobación» (misión push-mensajes-por-aprobar).
 *
 * Una fila por mensaje (lead, soporte o chat de la demo): su `source_key` único garantiza que el aviso sale UNA sola vez,
 * aunque lo intenten a la vez el conciliador por minuto y los servicios de verificación que avisan en el momento.
 * Sin foreign keys (regla del proyecto) y con índices de nombre corto.
 */
class CreatePendingApprovalNotificationsTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pending_approval_notifications', function (Blueprint $table) {
            $table->bigIncrements('id');

            /* `lead_message:123`, `support_message:45`, `demo_chat_message:7`. Una sola columna: no es un unique compuesto. */
            $table->string('source_key', 60)->unique('pan_source_key_unique');

            /* Tipo y id del mensaje de origen. */
            $table->string('source_type', 30)->default('');
            $table->unsignedBigInteger('source_id')->default(0);

            /* Conversación a la que pertenece (`lead:{id}`, `ticket:{id}`, `demo_chat:{lead_id}`): sirve para agrupar avisos. */
            $table->string('group_key', 60)->default('');

            /* pending | sent | grouped | resolved | no_recipients | failed */
            $table->string('status', 20)->default('pending');

            /* poller (conciliador por minuto) | direct (servicios de verificación que avisan en el momento) */
            $table->string('channel', 20)->default('poller');

            /* Admins a los que se les mandó el aviso y hashes de endpoints que ya lo aceptaron (para reintentar sin repetir). */
            $table->json('admin_ids')->nullable();
            $table->json('accepted_endpoint_hashes')->nullable();

            /* Fila a la que se agrupó este aviso (sin push propio). */
            $table->unsignedBigInteger('grouped_into_id')->nullable();

            /* Intentos fallidos de envío y reserva temporal para que dos procesos no manden lo mismo. */
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('processing_until')->nullable();
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'pan_source_idx');
            $table->index(['group_key', 'notified_at'], 'pan_group_notified_idx');
            $table->index('status', 'pan_status_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pending_approval_notifications');
    }
}
