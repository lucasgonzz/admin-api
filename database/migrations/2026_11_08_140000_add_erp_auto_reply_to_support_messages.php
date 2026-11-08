<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddErpAutoReplyToSupportMessages extends Migration
{
    public function up()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->json('erp_auto_reply')->nullable();
        });
    }

    public function down()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('erp_auto_reply');
        });
    }
}
