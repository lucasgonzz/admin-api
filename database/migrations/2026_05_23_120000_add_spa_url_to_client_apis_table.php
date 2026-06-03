<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * URL pública del SPA asociada a un endpoint de API del cliente.
 */
class AddSpaUrlToClientApisTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            $table->string('spa_url')->nullable()->after('path');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            $table->dropColumn('spa_url');
        });
    }
}
