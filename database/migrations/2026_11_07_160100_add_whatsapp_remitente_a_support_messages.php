<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWhatsappRemitenteASupportMessages extends Migration
{
    public function up(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->string('whatsapp_remitente_crudo', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('whatsapp_remitente_crudo');
        });
    }
}
