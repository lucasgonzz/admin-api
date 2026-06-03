<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de pipeline que Claude sugirió al generar el mensaje (badge en conversación).
 */
class AddSuggestedLeadStatusToLeadMessagesTable extends Migration
{
    /**
     * Agrega `suggested_lead_status` a `lead_messages`.
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->string('suggested_lead_status', 64)->nullable()->after('ai_reasoning')->index();
        });
    }

    /**
     * Quita `suggested_lead_status` de `lead_messages`.
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('suggested_lead_status');
        });
    }
}
