<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRequiereIntervencionHumanaToLeadsTable extends Migration
{
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Flag persistido: Claude (o admin) marcó que este lead requiere intervención humana.
            // true  = el admin debe revisar y actuar manualmente.
            // false = sin intervención pendiente.
            $table->boolean('requiere_intervencion_humana')->default(false)->after('claude_auto_reply');
        });
    }

    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('requiere_intervencion_humana');
        });
    }
}
