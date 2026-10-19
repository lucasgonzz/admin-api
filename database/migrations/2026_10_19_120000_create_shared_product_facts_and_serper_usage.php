<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSharedProductFactsAndSerperUsage extends Migration
{
    public function up()
    {
        Schema::create('shared_product_facts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('gtin', 14)->unique();
            $table->string('status', 20)->default('miss');
            $table->json('product')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('lease_client_id')->nullable();
            $table->string('lease_token_hash', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedBigInteger('auth_user_id')->nullable();
            $table->string('auth_user_name', 190)->nullable();
            $table->timestamps();
        });
        Schema::create('client_serper_usages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('client_id');
            $table->uuid('event_uuid');
            $table->timestamp('occurred_at');
            $table->unsignedBigInteger('auth_user_id')->nullable();
            $table->string('auth_user_name', 190)->nullable();
            $table->string('process', 100);
            $table->string('endpoint', 20);
            $table->string('query', 500)->nullable();
            $table->boolean('ok');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->decimal('credits', 14, 4)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            // El reintento del emisor nunca duplica consumo, aunque cambie el contenido.
            $table->unique(['client_id', 'event_uuid'], 'serper_client_event_unique');
            $table->index(['client_id', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('client_serper_usages');
        Schema::dropIfExists('shared_product_facts');
    }
}
