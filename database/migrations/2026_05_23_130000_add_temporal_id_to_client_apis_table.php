<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite vincular ClientApi creadas antes de persistir el Client padre.
 */
class AddTemporalIdToClientApisTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            $table->string('temporal_id')->nullable()->after('client_id');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            $table->dropColumn('temporal_id');
        });
    }
}
