<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea las cuatro tablas del seguimiento de misiones en el Admin (misión dotin-misiones-admin, 26/11/2026).
 *
 * - `mission_trackings`: el estado de cada misión tal como lo publica la Gamer (una fila por misión, upsert por `seq`).
 * - `mission_tracking_events`: historial corto de hitos (arranque confirmado, cortes, preguntas, respuestas).
 * - `mission_questions`: las preguntas de decisión que la misión le hace a Lucas (con su revisión).
 * - `mission_answers`: las respuestas registradas desde el Admin o por MCP. Es la bandeja durable Admin -> Gamer:
 *   la Gamer está detrás de NAT, así que consulta `pending`, reclama, lee y confirma `applied`.
 *
 * Sin claves foráneas, un solo `unique` de una columna acotada por tabla, índices de nombre corto, strings acotados y
 * defaults en todo lo que no es obligatorio. Idempotente: cada tabla se crea sólo si no existe.
 */
class CreateMissionTrackingTables extends Migration
{
    /**
     * Crea las tablas.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('mission_trackings')) {
            Schema::create('mission_trackings', function (Blueprint $table) {
                $table->id();

                // `cc:<slug>`: la misma clave que usa El local para la misión.
                $table->string('mission_key', 80);
                $table->string('slug', 80);
                $table->string('host', 60)->nullable();
                $table->string('pedido_id', 60)->nullable();
                $table->string('hilo', 40)->nullable();
                $table->string('titulo', 120)->nullable();
                $table->string('origen', 20)->default('desconocido');
                $table->text('repos')->nullable();

                // Estado propio del seguimiento y desde cuándo.
                $table->string('estado', 30)->default('en_cola');
                $table->dateTime('estado_desde')->nullable();

                // Fase fina, etapa derivada y desde cuándo está en la fase.
                $table->string('fase', 40)->nullable();
                $table->string('etapa', 20)->nullable();
                $table->dateTime('fase_desde')->nullable();

                // Texto para Lucas: lo último que avanzó, lo que falta, por qué se cortó o se detuvo y el próximo paso.
                $table->string('ultimo_avance', 500)->nullable();
                $table->string('pendiente', 500)->nullable();
                $table->string('motivo', 300)->nullable();
                $table->string('proximo_paso', 300)->nullable();

                $table->unsignedInteger('ejecucion_n')->default(0);
                $table->unsignedSmallInteger('intentos_tecnicos')->default(0);
                $table->dateTime('arranque_confirmado_at')->nullable();
                $table->dateTime('ultima_actividad_real_at')->nullable();

                // Hora del Admin del último PUT aplicado (la frescura se mide contra ella, no contra el reloj de la PC).
                $table->dateTime('reportado_at')->nullable();

                // Orden de las publicaciones: un PUT con `seq` menor o igual al guardado no se aplica.
                $table->unsignedBigInteger('seq')->default(0);

                $table->string('pregunta_abierta_uid', 36)->nullable();
                $table->text('evidencia')->nullable();
                $table->dateTime('terminada_at')->nullable();

                $table->timestamps();

                $table->unique('mission_key', 'mtr_key_uq');
                $table->index('pedido_id', 'mtr_pedido_idx');
                $table->index('estado', 'mtr_estado_idx');
                $table->index('reportado_at', 'mtr_reportado_idx');
            });
        }

        if (! Schema::hasTable('mission_tracking_events')) {
            Schema::create('mission_tracking_events', function (Blueprint $table) {
                $table->id();
                $table->string('mission_key', 80);
                $table->string('event_uid', 64);
                $table->string('tipo', 40);
                $table->string('estado', 30)->nullable();
                $table->string('fase', 40)->nullable();
                $table->string('texto', 300)->nullable();
                $table->dateTime('ocurrido_at');
                $table->timestamps();

                $table->index('mission_key', 'mte_key_idx');
                $table->unique('event_uid', 'mte_uid_uq');
            });
        }

        if (! Schema::hasTable('mission_questions')) {
            Schema::create('mission_questions', function (Blueprint $table) {
                $table->id();
                $table->string('mission_key', 80);
                $table->string('question_uid', 36);
                $table->unsignedInteger('revision')->default(1);
                $table->string('tipo', 20)->default('ordinaria');
                $table->string('pregunta', 500);
                $table->text('opciones');
                $table->string('recomendada', 20)->nullable();
                $table->string('estado', 20)->default('abierta');
                $table->dateTime('answered_at')->nullable();
                $table->dateTime('applied_at')->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->string('cierre_motivo', 60)->nullable();
                $table->timestamps();

                $table->index('mission_key', 'mq_key_idx');
                $table->unique('question_uid', 'mq_uid_uq');
                $table->index('estado', 'mq_estado_idx');
            });
        }

        if (! Schema::hasTable('mission_answers')) {
            Schema::create('mission_answers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('question_id');
                $table->string('mission_key', 80);
                $table->string('question_uid', 36);
                $table->unsignedInteger('revision');
                $table->string('answer_uid', 36);
                $table->string('opcion', 20)->nullable();
                $table->string('texto', 1000)->nullable();
                $table->unsignedBigInteger('admin_id');
                $table->string('canal', 10);
                $table->string('estado', 20)->default('registrada');
                $table->string('claim_token', 36)->nullable();
                $table->dateTime('claimed_at')->nullable();
                $table->dateTime('applied_at')->nullable();
                $table->unsignedInteger('ejecucion_n')->nullable();
                $table->string('rechazo_motivo', 60)->nullable();
                $table->string('rechazo_detalle', 300)->nullable();
                $table->timestamps();

                $table->index('question_id', 'ma_question_idx');
                $table->index('mission_key', 'ma_key_idx');
                $table->unique('answer_uid', 'ma_uid_uq');
                $table->index('estado', 'ma_estado_idx');
            });
        }
    }

    /**
     * Elimina las tablas.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mission_answers');
        Schema::dropIfExists('mission_questions');
        Schema::dropIfExists('mission_tracking_events');
        Schema::dropIfExists('mission_trackings');
    }
}
