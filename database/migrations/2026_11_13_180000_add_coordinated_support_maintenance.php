<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddCoordinatedSupportMaintenance extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('support_codex_upgrade_agreements', 'maintenance_mode')) { Schema::table('support_codex_upgrade_agreements', function (Blueprint $table) {
            $table->string('maintenance_mode', 20)->default('closed');
            $table->string('target', 16)->default('sistema');
            $table->unsignedBigInteger('client_ecommerce_id')->nullable();
        }); }
        if (! Schema::hasColumn('support_codex_upgrade_agreements', 'store_identity_hash')) { Schema::table('support_codex_upgrade_agreements', function (Blueprint $table) {
            $table->string('store_identity_hash', 64)->nullable();
        }); }
        if (! Schema::hasColumn('support_codex_release_claims', 'target')) { Schema::table('support_codex_release_claims', function (Blueprint $table) {
            $table->string('target', 16)->default('sistema');
        }); }
        if (! Schema::hasColumn('support_codex_scheduled_upgrades', 'target')) { Schema::table('support_codex_scheduled_upgrades', function (Blueprint $table) {
            $table->string('target', 16)->default('sistema');
            $table->unsignedBigInteger('client_ecommerce_id')->nullable();
            $table->unsignedBigInteger('ecommerce_installation_id')->nullable();
            $table->string('backup_path', 500)->nullable();
            $table->string('backup_sha256', 64)->nullable();
            $table->unsignedBigInteger('backup_size')->nullable();
        }); }
        if (DB::select("SHOW INDEX FROM support_codex_scheduled_upgrades WHERE Key_name = 'support_store_installation_unique'") === []) {
            Schema::table('support_codex_scheduled_upgrades', function (Blueprint $table) {
                $table->unique('ecommerce_installation_id', 'support_store_installation_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('support_codex_scheduled_upgrades', function (Blueprint $table) {
            $table->dropUnique('support_store_installation_unique');
            $table->dropColumn(['target', 'client_ecommerce_id', 'ecommerce_installation_id', 'backup_path', 'backup_sha256', 'backup_size']);
        });
        Schema::table('support_codex_release_claims', function (Blueprint $table) { $table->dropColumn('target'); });
        Schema::table('support_codex_upgrade_agreements', function (Blueprint $table) {
            $table->dropColumn(['maintenance_mode', 'target', 'client_ecommerce_id', 'store_identity_hash']);
        });
    }
}
