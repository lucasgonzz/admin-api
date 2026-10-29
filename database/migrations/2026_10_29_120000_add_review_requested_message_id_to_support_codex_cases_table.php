<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReviewRequestedMessageIdToSupportCodexCasesTable extends Migration
{
    public function up()
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            // Solo pedir_revision enviada crea esta procedencia. Las revisiones anteriores y
            // las pausas técnicas siguen bloqueadas; nunca se deduce el origen por su texto.
            $table->unsignedBigInteger('review_requested_message_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->dropColumn('review_requested_message_id');
        });
    }
}
