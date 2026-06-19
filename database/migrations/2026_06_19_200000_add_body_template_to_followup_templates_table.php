<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el texto literal aprobado en Meta para cada plantilla de seguimiento.
 *
 * Permite mostrar el mensaje real enviado en la conversación del lead
 * en lugar del placeholder "[Seguimiento automático #N - plantilla: xxx]".
 * El texto usa {{1}} como variable de nombre de contacto, igual que en Meta.
 */
class AddBodyTemplateToFollowupTemplatesTable extends Migration
{
    public function up()
    {
        Schema::table('followup_templates', function (Blueprint $table) {
            /* Texto literal de la plantilla en Meta. Usa {{1}} para el nombre del contacto. */
            $table->text('body_template')->nullable()->after('template_name');
        });
    }

    public function down()
    {
        Schema::table('followup_templates', function (Blueprint $table) {
            $table->dropColumn('body_template');
        });
    }
}
