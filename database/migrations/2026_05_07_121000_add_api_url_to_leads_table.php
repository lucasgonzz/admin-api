<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * URL base del empresa-api de producción del cliente, usada para disparar
 * admin-sync/user-setup desde el lead (la api_key sigue viniendo del Client promovido).
 */
class AddApiUrlToLeadsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('api_url', 255)->nullable()->after('promoted_client_id');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('api_url');
        });
    }
}
