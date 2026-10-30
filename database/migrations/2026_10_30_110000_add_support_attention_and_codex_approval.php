<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSupportAttentionAndCodexApproval extends Migration
{
    public function up()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->boolean('support_attention_enabled')->nullable()->default(null);
        });
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE support_tickets ALTER COLUMN requiere_verificacion_mensajes SET DEFAULT 0');
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->uuid('dispatch_execution_id')->nullable();
        });
        Schema::table('support_codex_operations', function (Blueprint $table) {
            $table->longText('approval_payload')->nullable();
        });
    }

    public function down()
    {
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE support_tickets ALTER COLUMN requiere_verificacion_mensajes SET DEFAULT 1');
        Schema::table('support_codex_cases', function (Blueprint $table) {
            $table->dropColumn('dispatch_execution_id');
        });
        Schema::table('support_codex_operations', function (Blueprint $table) {
            $table->dropColumn('approval_payload');
        });
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('support_attention_enabled');
        });
    }
}
