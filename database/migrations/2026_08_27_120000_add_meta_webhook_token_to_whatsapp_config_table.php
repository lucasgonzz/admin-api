<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token secreto del webhook CRUDO de Meta (`kind: meta` de Kapso).
 *
 * 🔴 NO reutiliza `webhook_secret`, y no es un descuido. `webhook_secret` es el HMAC de los
 * webhooks `kind: kapso`: un webhook `kind: meta` NO manda ninguna cabecera de firma —reenvía
 * «the exact payload received from Meta, without modification» y agrega solo `Content-Type` y
 * `X-Idempotency-Key`—, así que verificar firma ahí rechaza el 100% de las entregas reales.
 * Verificado contra la doc de Kapso el 27/8/2026, después de que la primera versión de este
 * endpoint saliera con `verify_signature()` y hubiera dejado la tabla vacía para siempre sin que
 * nada lo denunciara.
 *
 * El `X-Idempotency-Key` tampoco sirve como credencial: es el SHA256 del propio payload, o sea que
 * lo calcula cualquiera que pueda armar el body. Por eso la autenticación es un token secreto que
 * viaja en el path de la URL que se configura en el panel de Kapso (o en `X-CC-Webhook-Token`).
 *
 * Nullable a propósito: hasta que Lucas corra `php artisan whatsapp:meta-webhook-token` no hay
 * token, y sin token el endpoint contesta 401 a todo. Falla cerrado.
 */
class AddMetaWebhookTokenToWhatsappConfigTable extends Migration
{
    /**
     * Agrega la columna meta_webhook_token.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('whatsapp_config', function (Blueprint $table) {
            $table->string('meta_webhook_token', 191)->nullable()->after('webhook_secret');
        });
    }

    /**
     * Elimina la columna meta_webhook_token.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('whatsapp_config', function (Blueprint $table) {
            $table->dropColumn('meta_webhook_token');
        });
    }
}
