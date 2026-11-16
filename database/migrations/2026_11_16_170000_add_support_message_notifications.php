<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class AddSupportMessageNotifications extends Migration
{
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) { $table->string('support_notification_email', 255)->nullable(); });
        Schema::table('support_attention_notifications', function (Blueprint $table) {
            $table->text('emailed_admin_ids')->nullable();
            $table->timestamp('email_notified_at')->nullable();
        });
        Schema::create('support_ticket_admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('support_ticket_id');
            $table->unsignedBigInteger('admin_id');
            $table->timestamps();
            $table->unsignedBigInteger('after_message_id')->default(0);
            $table->unique(['support_ticket_id', 'admin_id'], 'support_ticket_notify_admin_unique');
        });
        Schema::create('support_message_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('support_message_id');
            $table->unsignedBigInteger('admin_id');
            $table->text('accepted_endpoint_hashes')->nullable();
            $table->timestamp('processing_until')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['support_message_id', 'admin_id'], 'support_message_notify_admin_unique');
        });
    }
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) { $table->dropColumn('support_notification_email'); });
        Schema::dropIfExists('support_message_notifications');
        Schema::dropIfExists('support_ticket_admin_notifications');
        Schema::table('support_attention_notifications', function (Blueprint $table) {
            $table->dropColumn(['emailed_admin_ids', 'email_notified_at']);
        });
    }
}
