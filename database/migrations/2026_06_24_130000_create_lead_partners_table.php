<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Socios adicionales detectados en llamadas o WhatsApp y confirmados por el closer.
 */
class CreateLeadPartnersTable extends Migration
{
    /**
     * Crea la tabla de socios vinculados a un lead.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('lead_partners', function (Blueprint $table) {
            /* Identificador interno del socio sugerido o confirmado. */
            $table->id();
            /* Lead al que pertenece el socio. */
            $table->unsignedBigInteger('lead_id')->index();
            /* Nombre visible del socio en el panel del closer. */
            $table->string('name', 150)->nullable();
            /* Teléfono de contacto opcional del socio. */
            $table->string('phone', 50)->nullable();
            /* Notas internas del closer sobre el socio. */
            $table->text('notes')->nullable();
            /* Origen: call_transcript | whatsapp_suggestion | manual. */
            $table->string('source', 30)->default('manual');
            /* true = sugerido por IA pero aún no confirmado por el closer. */
            $table->boolean('pending_confirmation')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla de socios del lead.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('lead_partners');
    }
}
