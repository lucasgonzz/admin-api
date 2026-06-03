<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de hosting del endpoint (shared_hosting | vps).
 */
class AddHostingTypeToClientApisTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            $table->string('hosting_type')->default('shared_hosting')->after('spa_url');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            $table->dropColumn('hosting_type');
        });
    }
}
