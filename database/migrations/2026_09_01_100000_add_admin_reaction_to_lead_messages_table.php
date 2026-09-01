<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reacción con emoji que un admin aplica DESDE EL PANEL sobre un mensaje del hilo del lead.
 *
 * Es el espejo deliberado de `lead_reaction_*` (migración 2026_06_17_170000), no un reemplazo:
 * son dos ejes distintos —el lead reaccionó a un mensaje nuestro / nosotros reaccionamos a un
 * mensaje del hilo— y conviven en la misma fila sin pisarse. Por eso columnas nuevas y no reusar
 * las existentes.
 *
 * `admin_reaction_whatsapp_message_id` NO lleva `unique()`, a diferencia de su par entrante. Ese
 * `unique` existe para la idempotencia del webhook (`LeadWhatsappReactionService::handle_lead_inbound_reaction()`):
 * un mismo evento de Kapso puede reentrar y hay que desduplicarlo. Acá el wamid lo devuelve Meta a
 * nuestro propio POST, no hay reentrada posible, y un `unique` solo agregaría un modo de fallo.
 *
 * Sin FK declarativa hacia `admins`, igual que `sent_by_admin_id`: se indexa la columna y listo.
 *
 * La tabla ya guarda emojis en `lead_reaction_emoji`, o sea que el charset ya es `utf8mb4`: no hace
 * falta tocarlo acá.
 */
class AddAdminReactionToLeadMessagesTable extends Migration
{
    /**
     * Agrega las columnas de la reacción del panel sobre un mensaje existente.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            // Emoji Unicode que el admin aplicó al mensaje (null si no hay reacción del panel).
            $table->string('admin_reaction_emoji', 32)->nullable()->after('lead_reaction_whatsapp_message_id');
            // Momento en que el admin reaccionó desde el panel.
            $table->timestamp('admin_reaction_at')->nullable()->after('admin_reaction_emoji');
            // wamid que devolvió Meta por NUESTRO POST de reacción (sin unique: no hay reentrada que desduplicar).
            $table->string('admin_reaction_whatsapp_message_id', 191)->nullable()->after('admin_reaction_at');
            // Admin que reaccionó, para mostrar el nombre en la burbuja. Sin FK, igual que sent_by_admin_id.
            $table->unsignedBigInteger('admin_reaction_by_admin_id')->nullable()->index()->after('admin_reaction_whatsapp_message_id');
        });
    }

    /**
     * Revierte las columnas de la reacción del panel.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn([
                'admin_reaction_emoji',
                'admin_reaction_at',
                'admin_reaction_whatsapp_message_id',
                'admin_reaction_by_admin_id',
            ]);
        });
    }
}
