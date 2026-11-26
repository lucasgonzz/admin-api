<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los compromisos de contacto de un lead (misión leads-referidos-compromisos, etapa E2, 26/11/2026).
 *
 * Un compromiso es «el próximo contacto que se acordó con este lead»: Juan lo llama el viernes, Martín le escribe el
 * martes, o se espera que el lead avise. Nace de «Registrar llamada», de lo que el lead escribe por WhatsApp o del panel,
 * y mientras esté vigente suspende los seguimientos genéricos del embudo. A su hora, el comando `leads:compromisos`
 * revisa si cambió algo desde que se creó (la «huella») y ejecuta lo acordado o lo re-evalúa.
 *
 * 🔴 Reglas de la tabla (restricciones de migraciones de admin-api):
 *  - Sin claves foráneas: `lead_id`, `reemplazado_por_id`, `admin_task_id`, etc. son enteros sueltos; el borrado en
 *    cascada de los compromisos de un lead lo hace Eloquent (`Lead::deleting`).
 *  - Dos índices con nombre corto: `lcomp_lead_idx` (lead + status: «el vigente de este lead») y `lcomp_vence_idx`
 *    (status + vence_at: «los vigentes que ya vencieron», que es lo que mira el comando cada minuto).
 *  - Todas las columnas nuevas llevan default o son nullable; los strings tienen largo acotado.
 *
 * Se puede volver a correr sobre una base que quedó a medias (`Schema::hasTable`).
 */
class CreateLeadCompromisosTable extends Migration
{
    /**
     * Crea la tabla `lead_compromisos`.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('lead_compromisos')) {
            return;
        }

        Schema::create('lead_compromisos', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Lead al que se le debe el contacto.
            $table->unsignedBigInteger('lead_id');

            // Cuándo toca (hora de Argentina, como todo el admin).
            $table->dateTime('vence_at');

            // Qué se acordó: llamar (Juan), escribir (Martín) o esperar (que avise el lead).
            $table->string('accion', 10)->default('llamar');

            // De dónde salió: llamada | ia_mensaje | transcripcion | manual | agente | reevaluacion.
            $table->string('origen', 20)->default('manual');

            // Id de la llamada, del mensaje o del compromiso anterior que lo originó (según `origen`).
            $table->unsignedBigInteger('origen_id')->nullable();

            // Qué hay que hacer, en una oración, y lo que dijo el lead tal cual (para citarlo).
            $table->text('resumen')->nullable();
            $table->string('cita_del_lead', 500)->nullable();

            // vigente | procesando | ejecutado | reprogramado | reemplazado | cumplido | cancelado | revisar.
            $table->string('status', 15)->default('vigente');

            // La huella: cómo estaba el lead cuando se creó (último mensaje, última llamada y estado).
            $table->unsignedBigInteger('baseline_lead_message_id')->nullable();
            $table->unsignedBigInteger('baseline_llamada_id')->nullable();
            $table->string('estado_lead_al_crear', 40)->nullable();

            // El compromiso que lo reemplazó o lo reprogramó.
            $table->unsignedBigInteger('reemplazado_por_id')->nullable();

            // Cómo terminó (el motivo de una cancelación, lo que decidió la IA, lo que se hizo).
            $table->text('resultado')->nullable();

            // Lo que dejó la ejecución: la tarea de Juan y/o el mensaje (sugerido o enviado) de Martín.
            $table->unsignedBigInteger('admin_task_id')->nullable();
            $table->unsignedBigInteger('lead_message_id')->nullable();

            // Candado contra corridas dobles: cuántas veces se tomó y desde cuándo está «procesando».
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->dateTime('procesando_desde')->nullable();

            // 1 = un admin «Sólo Leads» lo cargó o lo pasó a escribir: el mensaje NUNCA sale solo (siempre sugerencia), aunque el
            // envío automático esté prendido.
            $table->boolean('sin_envio_automatico')->default(false);

            // Quién lo creó y quién lo cerró (null = el sistema o un agente).
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->unsignedBigInteger('cerrado_por_admin_id')->nullable();

            $table->dateTime('ejecutado_at')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'status'], 'lcomp_lead_idx');
            $table->index(['status', 'vence_at'], 'lcomp_vence_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('lead_compromisos');
    }
}
