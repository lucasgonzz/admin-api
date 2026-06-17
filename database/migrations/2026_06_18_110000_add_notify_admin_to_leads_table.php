<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNotifyAdminToLeadsTable extends Migration
{
    /**
     * Toggle manual por lead: si está activo, cada mensaje entrante de este lead
     * dispara una notificación push al admin indicado en notify_admin_id.
     * Desactivado por defecto (false / null).
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('notificar_mensajes')->default(false)->after('requiere_seguimiento')->index();
            // Admin que activó el toggle y que recibe el push. Nullable: se limpia si se desactiva.
            $table->unsignedBigInteger('notify_admin_id')->nullable()->after('notificar_mensajes');
        });
    }

    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['notificar_mensajes', 'notify_admin_id']);
        });
    }
}
