<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Razón social / nombre de empresa del cliente (el campo name queda para contacto).
 */
class AddCompanyNameToClientsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('company_name', 150)->nullable()->after('name');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('company_name');
        });
    }
}
