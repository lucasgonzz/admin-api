<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAgentesErpToSupportTickets extends Migration
{
    public function up()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->timestamp('sistema_exclusivo_at')->nullable();
            $table->boolean('agentes_erp')->default(false);
        });
    }

    public function down()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['sistema_exclusivo_at', 'agentes_erp']);
        });
    }
}
