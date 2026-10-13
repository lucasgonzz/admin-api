<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde vino cada lead (misión origen-del-lead-y-conversiones-meta, 13/10/2026).
 *
 * El origen se calcula UNA vez, sobre el PRIMER mensaje entrante del lead, y lo escriben dos
 * observers (`OrigenDesdeElPrimerMensajeObserver` y `OrigenDesdeElReferralObserver`): uno cuando
 * nace el mensaje, otro cuando llega el referral del anuncio, porque los dos webhooks llegan en
 * cualquier orden. La regla vive en `App\Services\LeadOrigenService`.
 *
 * 🔴 Las tres columnas son nullable y sin default, a propósito:
 *   - un lead anterior a este deploy no tiene origen calculado y NULL lo dice tal cual. El comando
 *     `leads:calcular-origen --aplicar` lo completa (va en `pendientes.json` del deploy), y hasta
 *     que corra el filtro `desconocido` de `claude/leads` incluye los NULL;
 *   - un default `desconocido` haría que el observer, que solo escribe si el origen está en NULL,
 *     no pudiera calcular nunca el origen de un lead nuevo.
 */
class AddOrigenToLeadsTable extends Migration
{
    /**
     * Agrega `origen`, `ctwa_clid` y `origen_calculado_at` a `leads`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // anuncio_whatsapp | landing | instagram | desconocido. Indexado porque es un filtro
            // de claude/leads, de claude/query y del buscador del panel.
            $table->string('origen', 20)->nullable()->index()->after('first_message_at');

            // Click id del anuncio Click-to-WhatsApp que trajo al lead, copiado de
            // whatsapp_ad_referrals al calcular el origen. Es lo ÚNICO con lo que Meta atribuye un
            // evento de conversión (CAPI for Business Messaging). 500 por el mismo motivo que la
            // columna de whatsapp_ad_referrals: Meta no documenta un máximo y con `strict` un
            // valor más largo no se trunca, tira.
            $table->string('ctwa_clid', 500)->nullable()->after('origen');

            // Cuándo se calculó el origen. Sirve para distinguir lo que calculó el observer en el
            // momento de lo que completó después el comando de los leads viejos.
            $table->timestamp('origen_calculado_at')->nullable()->after('ctwa_clid');
        });
    }

    /**
     * Elimina las tres columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['origen']);
            $table->dropColumn(['origen', 'ctwa_clid', 'origen_calculado_at']);
        });
    }
}
