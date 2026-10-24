<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReconciliationToSupportCodexOperations extends Migration
{
    public function up()
    {
        Schema::table('support_codex_operations', function (Blueprint $table) {
            $table->dateTime('reconciled_at')->nullable();
            $table->string('reconciled_by', 120)->nullable();
            $table->string('reconciliation_outcome', 30)->nullable();
            $table->text('reconciliation_reason')->nullable();
            $table->text('reconciliation_evidence')->nullable();
            $table->string('reconciliation_reference', 255)->nullable();
        });
    }

    public function down()
    {
        Schema::table('support_codex_operations', function (Blueprint $table) {
            $table->dropColumn(['reconciled_at', 'reconciled_by', 'reconciliation_outcome', 'reconciliation_reason', 'reconciliation_evidence', 'reconciliation_reference']);
        });
    }
}
