<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddClientMessageIdToSupportMessages extends Migration
{
    public function up()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->string('client_message_id', 100)->nullable();
            $table->unique(['support_ticket_id', 'sender_admin_id', 'client_message_id'], 'support_message_client_retry_unique');
        });
    }

    public function down()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropUnique('support_message_client_retry_unique');
            $table->dropColumn('client_message_id');
        });
    }
}
