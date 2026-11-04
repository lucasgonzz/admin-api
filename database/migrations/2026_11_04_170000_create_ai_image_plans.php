<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateAiImagePlans extends Migration
{
    public function up()
    {
        Schema::create('ai_image_plans', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->decimal('precio_ars', 12, 2);
            $table->decimal('tope_usd_mensual', 10, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('ai_image_plan_id')->nullable()->constrained('ai_image_plans');
            $table->string('ai_image_quality', 6)->default('medium');
        });

        $ahora = now();
        DB::table('ai_image_plans')->insert([
            ['nombre' => 'Imágenes Básico', 'precio_ars' => 10000, 'tope_usd_mensual' => 5, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Imágenes Intermedio', 'precio_ars' => 20000, 'tope_usd_mensual' => 10, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Imágenes Pro', 'precio_ars' => 30000, 'tope_usd_mensual' => 15, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
    }

    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_image_plan_id');
            $table->dropColumn('ai_image_quality');
        });

        Schema::dropIfExists('ai_image_plans');
    }
}
