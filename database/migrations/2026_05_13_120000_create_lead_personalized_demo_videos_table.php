<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Videos tutoriales ad-hoc por lead, incluidos en el "Mail 1 - DEMO".
 *
 * Sin claves foráneas declarativas: la relación se resuelve en Eloquent (`lead_id`).
 */
class CreateLeadPersonalizedDemoVideosTable extends Migration
{
    /**
     * Crea la tabla de filas hijas vinculadas a `leads`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('lead_personalized_demo_videos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('lead_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('video_url', 2048);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('lead_id');
        });
    }

    /**
     * Revierte la creación de la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('lead_personalized_demo_videos');
    }
}
