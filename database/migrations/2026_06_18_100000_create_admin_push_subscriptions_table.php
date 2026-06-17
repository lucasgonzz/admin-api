<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAdminPushSubscriptionsTable extends Migration
{
    /**
     * Suscripciones Web Push por admin. Un admin puede tener varios devices
     * (ej. teléfono y notebook), cada uno con su propio endpoint y claves.
     */
    public function up()
    {
        Schema::create('admin_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->index();
            // URL única que el navegador asigna a esa suscripción (push service del navegador).
            $table->string('endpoint', 500)->unique();
            // Claves públicas requeridas por el protocolo Web Push (PushSubscription.toJSON().keys).
            $table->string('p256dh');
            $table->string('auth');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('admin_id')->references('id')->on('admins')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('admin_push_subscriptions');
    }
}
