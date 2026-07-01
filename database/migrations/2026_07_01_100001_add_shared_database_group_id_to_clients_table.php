<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula cada cliente opcionalmente a un grupo de BD compartida.
 */
class AddSharedDatabaseGroupIdToClientsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('shared_database_group_id')->nullable()->after('user_id');
            $table->foreign('shared_database_group_id')
                ->references('id')->on('shared_database_groups')
                ->nullOnDelete();
            $table->index('shared_database_group_id');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['shared_database_group_id']);
            $table->dropIndex(['shared_database_group_id']);
            $table->dropColumn('shared_database_group_id');
        });
    }
}
