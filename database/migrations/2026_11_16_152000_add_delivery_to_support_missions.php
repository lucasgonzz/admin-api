<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDeliveryToSupportMissions extends Migration
{
    public function up()
    {
        Schema::table('support_codex_missions', function (Blueprint $table) {
            $table->unsignedBigInteger('installed_schedule_id')->nullable()->index();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('delivery_verified_at')->nullable();
            $table->text('delivery_verification_evidence')->nullable();
        });
    }

    public function down()
    {
        Schema::table('support_codex_missions', function (Blueprint $table) {
            $table->dropColumn(['installed_schedule_id', 'installed_at', 'delivery_verified_at', 'delivery_verification_evidence']);
        });
    }
}
