<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El seguimiento proactivo de las implementaciones de sistema (misión cruzada `seguimiento-de-implementaciones`,
 * 11/11/2026): Martín le escribe al cliente cada 2 días hábiles para avanzar al próximo paso.
 *
 * Solo AGREGA dos tablas: no toca ninguna existente.
 *  - `implementation_followups`: uno por cliente (unique), con el reloj (`next_due_at`), los contadores, la pausa,
 *    la última medición de hitos de uso y la reserva del monitor.
 *  - `implementation_followup_events`: la bitácora (creado, enviado, pausado, frenado, terminado...).
 *
 * `lease_token` guarda el sha256 del token de la reserva (como `support_codex_cases.lease_hash`): el token en claro
 * solo lo tiene el monitor. `lease_claimed_at` es el instante del claim: con él el envío sabe si el cliente escribió
 * DESPUÉS de que Martín leyó la conversación (409 `cliente_escribio`).
 */
class CreateImplementationFollowupsTables extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('implementation_followups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->unique();
            $table->unsignedBigInteger('implementation_id')->nullable()->index();
            $table->string('status', 20)->default('activo')->index();
            $table->unsignedTinyInteger('interval_business_days')->default(2);
            $table->unsignedTinyInteger('max_unanswered')->default(2);
            $table->unsignedTinyInteger('unanswered_count')->default(0);
            $table->dateTime('next_due_at')->nullable()->index();
            $table->date('paused_until')->nullable();
            $table->string('pause_reason', 500)->nullable();
            $table->dateTime('last_sent_at')->nullable();
            $table->dateTime('last_client_contact_at')->nullable();
            $table->json('hitos')->nullable();
            $table->dateTime('hitos_at')->nullable();
            $table->json('hitos_no_aplican')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->dateTime('lease_claimed_at')->nullable();
            $table->string('run_id', 36)->nullable();
            $table->timestamps();
        });

        Schema::create('implementation_followup_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('implementation_followup_id')->index();
            $table->string('kind', 20);
            $table->string('source', 10);
            $table->text('detail')->nullable();
            $table->json('data')->nullable();
            $table->unsignedBigInteger('support_ticket_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('implementation_followup_events');
        Schema::dropIfExists('implementation_followups');
    }
}
