<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atribución de anuncios Click-to-WhatsApp: qué aviso de Meta trajo a cada teléfono.
 *
 * 🔴 LA TABLA CUELGA DEL TELÉFONO, NO DEL LEAD, y ese es el punto entero de la migración. El
 * bloque `referral` viaja en el PRIMER mensaje que manda la persona después de tocar el anuncio;
 * en ese instante el lead puede no existir todavía (lo crea el webhook de Kapso más tarde, por
 * otro camino y con su propia transacción). Una FK a `leads` obligaría a este endpoint a crear o
 * esperar el lead, que es exactamente lo que no tiene que hacer: solo persiste atribución. El lead
 * se ata después por teléfono, con la relación `whatsapp_ad_referrals` del modelo Lead.
 *
 * El teléfono se guarda YA normalizado a E.164 con WhatsappNormalizer, igual que `leads.phone`:
 * si cada punta lo normalizara distinto, la relación por teléfono no engancharía nunca.
 */
class CreateWhatsappAdReferralsTable extends Migration
{
    /**
     * Crea la tabla whatsapp_ad_referrals.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('whatsapp_ad_referrals', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Teléfono E.164 normalizado de quien tocó el anuncio. Indexado porque es la única
            // clave por la que se cruza con `leads.phone`.
            $table->string('phone', 32)->index();

            // Click ID de Click-to-WhatsApp. Es el dato que después permite devolverle a Meta el
            // evento de conversión por CAPI. Nullable porque Meta no lo manda en todos los
            // formatos de referral, y una fila sin clid igual sirve para saber qué aviso trajo
            // a la persona. Indexado para poder buscar por clid al armar el evento.
            $table->string('ctwa_clid', 191)->nullable()->index();

            // Datos del anuncio, tal como los manda Meta. Todos nullable: el bloque `referral`
            // cambia de forma según el tipo de creatividad y no hay ninguno garantizado.
            $table->string('source_id', 191)->nullable();
            $table->string('source_type', 40)->nullable();
            $table->text('source_url')->nullable();
            $table->string('headline', 500)->nullable();
            $table->text('body')->nullable();
            $table->string('media_type', 40)->nullable();
            $table->text('thumbnail_url')->nullable();

            // ID del mensaje de Meta que trajo el referral. ÚNICO: es la clave de idempotencia
            // del endpoint. Kapso reintenta un webhook que no contestó 200 a tiempo, y sin esta
            // restricción el mismo clic quedaría contado dos veces en la atribución.
            $table->string('wamid', 191)->unique();

            // Momento del mensaje según Meta (timestamp del propio mensaje, no de la recepción).
            // Nullable porque un payload puede no traerlo y no es motivo para descartar la fila.
            $table->timestamp('received_at')->nullable();

            // Payload crudo del referral. Meta agrega campos nuevos sin avisar y este endpoint no
            // procesa mensajes: guardar el original es lo único que permite recuperar un dato que
            // hoy no tiene columna sin haber perdido los clics de mientras.
            $table->json('raw')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla whatsapp_ad_referrals.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('whatsapp_ad_referrals');
    }
}
