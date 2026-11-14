<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class AddBlockedMigrationNoticeToSupportCodexOperations extends Migration
{
    public function up(): void
    {
        Schema::table('support_codex_operations', function (Blueprint $table) {
            $table->text('migration_blocked_reason')->nullable();
            $table->timestamp('migration_notice_sent_at')->nullable();
            $table->string('migration_notice_state', 20)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('support_codex_operations', function (Blueprint $table) {
            $table->dropColumn(['migration_blocked_reason', 'migration_notice_sent_at', 'migration_notice_state']);
        });
    }
}
