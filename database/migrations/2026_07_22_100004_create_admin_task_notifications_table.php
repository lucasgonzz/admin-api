<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla admin_task_notifications: registra, por (tarea, admin),
 * si ese admin ya vio el aviso de que se le asignó una tarea.
 *
 * Como una tarea ahora puede asignarse a varios admins a la vez (ver
 * admin_task_assignees), cada admin necesita su propio estado de
 * "visto / no visto" para poder cerrar su aviso individualmente sin
 * afectar el de los demás asignados.
 */
class CreateAdminTaskNotificationsTable extends Migration
{
    /**
     * Crea la tabla con sus índices. Sin foreign keys (regla del workspace).
     */
    public function up()
    {
        Schema::create('admin_task_notifications', function (Blueprint $table) {
            $table->id();
            // Tarea sobre la que se generó el aviso.
            $table->unsignedBigInteger('admin_task_id')->index();
            // Admin destinatario del aviso.
            $table->unsignedBigInteger('admin_id')->index();
            // Momento en que el admin cerró/vio el aviso. Null = todavía pendiente.
            $table->timestamp('seen_at')->nullable()->index();
            $table->timestamps();

            // Único por (tarea, admin): un admin solo tiene un aviso por tarea.
            // Nombre corto explícito para no exceder el límite de 64 caracteres de MySQL.
            $table->unique(['admin_task_id', 'admin_id'], 'admin_task_notif_task_admin_unique');

            // Índice compuesto para la consulta caliente: "avisos pendientes de este admin".
            $table->index(['admin_id', 'seen_at'], 'admin_task_notif_admin_seen_idx');
        });
    }

    /**
     * Revierte la creación de la tabla.
     */
    public function down()
    {
        Schema::dropIfExists('admin_task_notifications');
    }
}
