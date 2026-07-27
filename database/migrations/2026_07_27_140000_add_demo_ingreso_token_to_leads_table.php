<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega las columnas necesarias para el token de ingreso a la demo (grupo 233, prompt 01).
 *
 * `admin-api` emite y persiste (en claro) el token que el lead usa para entrar a la demo con
 * la sesión ya iniciada. El token no es de un solo uso: vale durante toda la ventana del turno
 * más la gracia posterior configurada en LeadDemoSettings::get_gracia_minutos_post().
 *
 * - demo_ingreso_token: el token en sí (64 caracteres, Str::random(64)).
 * - demo_ingreso_token_expira_at: vencimiento calculado a partir de demo_date + demo_end_time + gracia.
 * - demo_ingreso_token_revocado_at: reservado para el prompt 05 (revocación/reemisión); por ahora
 *   siempre queda en null al emitir un token nuevo.
 *
 * Sin foreign keys (regla del repo). Sin condicionar nombres/columnas por env().
 */
return new class extends Migration
{
    /**
     * Agrega las 3 columnas nuevas a la tabla `leads` y un índice simple para el token.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Token en claro (admin-api necesita poder reconstruir el link para reenviarlo por WhatsApp).
            $table->string('demo_ingreso_token', 64)->nullable()->after('demo_setup_last_run_at');

            // Vencimiento del token: fin de la demo + gracia, o fallback de 4hs si no se pudo calcular.
            $table->dateTime('demo_ingreso_token_expira_at')->nullable()->after('demo_ingreso_token');

            // Timestamp de revocación manual (prompt 05); null mientras el token esté vigente.
            $table->dateTime('demo_ingreso_token_revocado_at')->nullable()->after('demo_ingreso_token_expira_at');

            // Índice simple (no unique) para la búsqueda por token desde el endpoint de ingreso.
            $table->index('demo_ingreso_token');
        });
    }

    /**
     * Revierte las columnas agregadas (vacío, como el resto de migraciones del repo).
     *
     * @return void
     */
    public function down()
    {
    }
};
