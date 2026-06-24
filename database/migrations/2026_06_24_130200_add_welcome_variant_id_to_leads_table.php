<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot de la variante de welcome asignada a cada lead (A/B testing).
 */
class AddWelcomeVariantIdToLeadsTable extends Migration
{
    /**
     * Agrega la referencia a message_variants en leads.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            /* Variante de welcome recibida por el lead; no cambia si se archiva la variante. */
            $table->unsignedBigInteger('welcome_variant_id')->nullable()->after('status');
        });
    }

    /**
     * Quita la columna de variante asignada.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('welcome_variant_id');
        });
    }
}
