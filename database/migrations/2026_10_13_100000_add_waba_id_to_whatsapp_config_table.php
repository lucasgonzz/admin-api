<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WABA ID (business account id de Meta) del número de WhatsApp de la configuración.
 *
 * Hace falta porque el proxy de Meta de Kapso direcciona las PLANTILLAS por WABA y no por número:
 * `POST|GET https://api.kapso.ai/meta/whatsapp/v24.0/{WABA_ID}/message_templates`. El envío de
 * mensajes va por `phone_number_id` (`WhatsappSendService::messages_endpoint()`), que ya estaba;
 * esto es la otra mitad, y sin ella no se puede crear ni consultar una plantilla por API.
 *
 * 🔴 LA GUARDA `hasColumn` NO ES DECORATIVA. La misión M1 del programa «embudo comercial»
 * (`whatsapp-dos-numeros`, slot s11) corre EN PARALELO con ésta sobre la misma tabla y puede
 * agregar la misma columna: el plan maestro dice «si M1 no lo hizo todavía». Medido el 13/10/2026
 * sobre `origin/master` (`b46bef1`): `waba_id` no existe en ninguna parte del repo y M1 todavía no
 * pusheó rama. Con la guarda, la que mergee segunda no hace nada en vez de reventar el
 * `migrate` de producción con «Duplicate column name».
 *
 * Nullable a propósito: una instalación sin WABA cargado tiene que seguir enviando mensajes como
 * siempre. Lo único que no puede hacer es crear o consultar plantillas, y eso lo dice
 * `MetaTemplateService` con un motivo legible (`sin_waba_id`) en vez de salir a la red sin destino.
 */
class AddWabaIdToWhatsappConfigTable extends Migration
{
    /**
     * WABA del portfolio «ComercioCity USD», el del número principal.
     *
     * Está acá y no en un `.env` porque es un identificador público de la cuenta (viaja en la URL
     * de cada llamada al proxy), no una credencial: la credencial es `kapso_api_key`, que sigue
     * viviendo en esta misma tabla y nunca se loguea.
     */
    const WABA_PRINCIPAL = '1290129236552587';

    /**
     * Agrega `waba_id` y lo completa en la fila activa si está vacía.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('whatsapp_config', 'waba_id')) {
            Schema::table('whatsapp_config', function (Blueprint $table) {
                $table->string('waba_id', 40)->nullable()->after('phone_number_id');
            });
        }

        /*
         * Backfill de la fila activa, y SOLO si está vacía: si M1 ya la escribió (o si Lucas la
         * cargó a mano desde el panel) este UPDATE no toca nada. En una instalación nueva la tabla
         * está vacía y tampoco hace nada.
         */
        DB::table('whatsapp_config')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('waba_id')->orWhere('waba_id', '');
            })
            ->update(['waba_id' => self::WABA_PRINCIPAL]);
    }

    /**
     * Saca `waba_id`.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('whatsapp_config', 'waba_id')) {
            Schema::table('whatsapp_config', function (Blueprint $table) {
                $table->dropColumn('waba_id');
            });
        }
    }
}
