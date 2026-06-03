<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notas internas del setter (`comments`) y columnas opcionales para filas en borrador.
 *
 * Sin FK; ajustes de nullability vía SQL nativo para evitar dependencia de `doctrine/dbal`.
 */
class AddCommentsAndNullableFieldsToLeadPersonalizedDemoVideosTable extends Migration
{
    /**
     * Agrega `comments` y permite `title` / `video_url` nulos (todos los campos pueden ir vacíos).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_personalized_demo_videos', function (Blueprint $table) {
            $table->text('comments')->nullable()->after('description');
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE lead_personalized_demo_videos MODIFY title VARCHAR(255) NULL');
            DB::statement('ALTER TABLE lead_personalized_demo_videos MODIFY video_url VARCHAR(2048) NULL');
        }
    }

    /**
     * Revierte columnas opcionales y elimina `comments`.
     *
     * @return void
     */
    public function down()
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("UPDATE lead_personalized_demo_videos SET title = '' WHERE title IS NULL");
            DB::statement('ALTER TABLE lead_personalized_demo_videos MODIFY title VARCHAR(255) NOT NULL');
            DB::statement("UPDATE lead_personalized_demo_videos SET video_url = '' WHERE video_url IS NULL");
            DB::statement('ALTER TABLE lead_personalized_demo_videos MODIFY video_url VARCHAR(2048) NOT NULL');
        }

        Schema::table('lead_personalized_demo_videos', function (Blueprint $table) {
            $table->dropColumn('comments');
        });
    }
}
