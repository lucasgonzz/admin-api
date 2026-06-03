<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ID ComercioCity (inicio de bloque de 100) asignado al cliente productivo.
 * Se calcula con UserIdBlockAllocatorService al crear el Client vía user setup.
 */
class AddUserIdToClientsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('company_name');
            $table->index('user_id');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
}
