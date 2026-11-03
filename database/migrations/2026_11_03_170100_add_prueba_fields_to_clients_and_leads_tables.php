<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El cliente en prueba (programa «Prueba gratuita de 7 días», misión `prueba-alta-instancia`,
 * 3/11/2026, decisión D5 del plan).
 *
 * - `clients.estado`: `activo` (default: todos los que ya existen siguen igual) | `prueba` |
 *   `prueba_vencida` | `prueba_borrada`.
 * - `clients.trial_days` / `clients.trial_ends_at`: los días de la prueba y cuándo vence. Editables
 *   desde el admin; al cambiarlos se le avisa al ERP (`admin-sync/prueba/vencimiento`, contrato C3).
 * - `leads.trial_days`: los días con los que arrancó la prueba de ese lead.
 *
 * Compatibles hacia atrás: columnas con default o nullable, sin FK.
 */
class AddPruebaFieldsToClientsAndLeadsTables extends Migration
{
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('estado', 20)->default('activo')->index()->after('is_active');
            $table->unsignedSmallInteger('trial_days')->nullable()->after('estado');
            $table->timestamp('trial_ends_at')->nullable()->after('trial_days');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->nullable()->after('status');
        });
    }

    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['estado', 'trial_days', 'trial_ends_at']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('trial_days');
        });
    }
}
