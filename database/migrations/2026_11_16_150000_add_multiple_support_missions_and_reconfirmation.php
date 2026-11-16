<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddMultipleSupportMissionsAndReconfirmation extends Migration
{
    public function up()
    {
        Schema::create('support_codex_missions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('support_ticket_id')->index();
            $table->string('mission_type', 20)->default('bug');
            $table->text('mission');
            $table->string('mission_hash', 64);
            $table->string('mission_thread_id', 150)->nullable()->index();
            $table->timestamp('mission_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('scope_confirmed_message_id')->nullable();
            $table->string('local_mission_request_id', 36)->nullable();
            $table->string('local_mission_claim_token', 36)->nullable();
            $table->timestamps();
            $table->unique(['support_ticket_id', 'mission_hash'], 'support_mission_ticket_hash_unique');
        });
        DB::table('support_codex_cases')->whereNotNull('mission')->whereRaw("TRIM(mission) <> ''")
            ->orderBy('id')->chunkById(100, function ($cases) {
                foreach ($cases as $case) {
                    $hash = hash('sha256', $case->mission);
                    $evidence = DB::table('support_codex_mission_fix_evidence')->where('support_ticket_id', $case->support_ticket_id)
                        ->where('mission_hash', $hash)->where('mission_thread_id', $case->mission_thread_id)->first();
                    DB::table('support_codex_missions')->insert([
                        'support_ticket_id' => $case->support_ticket_id, 'mission' => $case->mission, 'mission_hash' => $hash,
                        'mission_type' => $case->close_on_mission_start ? 'funcionalidad' : 'bug',
                        'mission_thread_id' => $case->mission_thread_id, 'mission_started_at' => $case->mission_started_at,
                        'completed_at' => $evidence === null ? null : $evidence->created_at,
                        'local_mission_request_id' => $case->local_mission_request_id,
                        'local_mission_claim_token' => $case->local_mission_claim_token,
                        'created_at' => $case->created_at, 'updated_at' => $case->updated_at,
                    ]);
                }
            });
        Schema::table('support_codex_scheduled_upgrades', function (Blueprint $table) {
            $table->unsignedBigInteger('reconfirmation_operation_id')->nullable();
            $table->timestamp('reconfirmation_requested_at')->nullable();
            $table->timestamp('reconfirmation_expires_at')->nullable();
            $table->unsignedBigInteger('reconfirmation_message_id')->nullable();
            $table->timestamp('reconfirmed_at')->nullable();
            $table->boolean('overnight_fallback')->default(false);
        });
    }

    public function down()
    {
        Schema::table('support_codex_scheduled_upgrades', function (Blueprint $table) {
            $table->dropColumn(['reconfirmation_operation_id', 'reconfirmation_requested_at', 'reconfirmation_expires_at',
                'reconfirmation_message_id', 'reconfirmed_at', 'overnight_fallback']);
        });
        Schema::dropIfExists('support_codex_missions');
    }
}
