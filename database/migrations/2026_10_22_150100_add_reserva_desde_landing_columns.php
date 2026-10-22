<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo autogestionada desde la landing (misión demo-autogestionada-landing, 22/10/2026). Dos
 * columnas nuevas, las dos nullable y aditivas: un admin viejo las ignora y una fila vieja queda
 * exactamente como estaba.
 *
 *   - `leads.demo_reservada_desde_landing_at`: cuándo el lead reservó SU demo desde el formulario
 *     de la web (`POST /api/landing/agenda/reservas`). 🔴 POR QUÉ existe: un lead que reservó desde
 *     la web nunca escribió por WhatsApp, y su primer mensaje cae en el gate «la IA espera al segundo
 *     mensaje» (`LeadAiSuggestionScheduler`, `GenerateLeadAiSuggestionJob`), que existe porque al
 *     primer mensaje de un lead nuevo lo contesta el onboarding. Para este lead el onboarding no corre
 *     (ya está en `demo_agendada`), así que sin esta marca su primer mensaje quedaba sin respuesta.
 *     También la lee el bloque DEMO DIRECTA del agente para no ofrecerle la demo ni pedirle el mail.
 *   - `lead_eventos_meta.contexto_web`: los datos del navegador del visitante al reservar (IP, user
 *     agent, cookies `_fbp`/`_fbc`, URL de la página). Con ellos el evento sale a Meta con
 *     `action_source = website` y Meta lo deduplica contra el del píxel (mismo `event_id`). Sin ellos,
 *     todo sale como hasta hoy (`chat`).
 */
class AddReservaDesdeLandingColumns extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('leads') && ! Schema::hasColumn('leads', 'demo_reservada_desde_landing_at')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->timestamp('demo_reservada_desde_landing_at')->nullable();
            });
        }

        if (Schema::hasTable('lead_eventos_meta') && ! Schema::hasColumn('lead_eventos_meta', 'contexto_web')) {
            Schema::table('lead_eventos_meta', function (Blueprint $table) {
                $table->json('contexto_web')->nullable();
            });
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('lead_eventos_meta') && Schema::hasColumn('lead_eventos_meta', 'contexto_web')) {
            Schema::table('lead_eventos_meta', function (Blueprint $table) {
                $table->dropColumn('contexto_web');
            });
        }

        if (Schema::hasTable('leads') && Schema::hasColumn('leads', 'demo_reservada_desde_landing_at')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropColumn('demo_reservada_desde_landing_at');
            });
        }
    }
}
