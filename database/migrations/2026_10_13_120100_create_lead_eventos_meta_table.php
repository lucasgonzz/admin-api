<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las conversiones que se le mandan a Meta por cada lead que vino de un anuncio Click-to-WhatsApp
 * (misión origen-del-lead-y-conversiones-meta, 13/10/2026).
 *
 * Una fila por (lead, evento): `LeadSubmitted`, `QualifiedLead` y `Purchase`, por la Conversions API
 * for Business Messaging. No es una bitácora: es el estado de cada envío, y es lo único que hace que
 * un evento no salga dos veces ni se pierda.
 *
 * 🔴 **El único (`lead_id`, `evento`) es el mecanismo de deduplicación**, no una prolijidad: el
 * observer de `Lead::saved` se dispara en cada guardado que mueve el estado, y el conciliador vuelve
 * a mirar cada diez minutos los mismos leads. Sin esta restricción, cada pasada sería un evento más
 * contado por Meta. `event_id` también es único y viaja a Meta, que deduplica por él del otro lado.
 *
 * El nombre del índice único va explícito por el límite de 64 caracteres de MySQL.
 */
class CreateLeadEventosMetaTable extends Migration
{
    /**
     * Crea la tabla `lead_eventos_meta`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('lead_eventos_meta', function (Blueprint $table) {
            $table->id();

            // De qué lead es el evento. Cascade: si el lead se borra, sus envíos no le sirven a nadie.
            $table->unsignedBigInteger('lead_id')->index();

            // LeadSubmitted | QualifiedLead | Purchase (nombres de evento de Meta, tal cual viajan).
            $table->string('evento', 40);

            /*
             * En qué quedó el envío. Los valores están como constantes en `LeadEventoMeta`:
             *
             *   pendiente      — registrado, el job todavía no lo trabajó.
             *   enviando       — un worker lo RECLAMÓ y está saliendo a Meta. Un `enviando` con el
             *                    `updated_at` de hace más de diez minutos es un proceso que murió:
             *                    el conciliador lo pasa a `error` y lo reintenta.
             *   enviado        — Meta contestó 200.
             *   error          — Meta lo rechazó o no se pudo conectar. El motivo, en `ultimo_error`.
             *   sin_configurar — falta META_CAPI_TOKEN o META_CAPI_DATASET_ID: no salió ninguna
             *                    llamada. Cuando la configuración aparece, el conciliador manda los
             *                    de los últimos siete días.
             *
             * Indexado porque el conciliador filtra por acá.
             */
            $table->string('estado', 20)->default('pendiente')->index();

            // Id del evento que viaja a Meta (`cc-lead-<id>-<evento>`): Meta deduplica por él.
            $table->string('event_id', 80)->unique();

            /*
             * Cuándo el lead alcanzó la etapa: es el `event_time` que viaja a Meta (que no acepta
             * eventos de más de siete días). Nullable a propósito, aunque siempre se escribe: en un
             * MySQL con `explicit_defaults_for_timestamp` apagado, el primer TIMESTAMP NOT NULL de
             * la tabla recibe solo un ON UPDATE CURRENT_TIMESTAMP, y el momento del evento se
             * movería cada vez que cambia el estado del envío.
             */
            $table->timestamp('event_time')->nullable();

            // Cuántas veces se intentó mandar. El conciliador reintenta hasta tres.
            $table->unsignedTinyInteger('intentos')->default(0);

            // Cuándo Meta lo aceptó. Nulo = todavía no.
            $table->timestamp('enviado_at')->nullable();

            // El motivo del último fallo, para leer. Nunca lleva el token.
            $table->text('ultimo_error')->nullable();

            // Lo que contestó Meta, recortado a `events_received`, `messages`, `fbtrace_id` y el
            // código del error si lo hubo. Nada de datos personales: Meta no devuelve ninguno.
            $table->json('respuesta')->nullable();

            $table->timestamps();

            // 🔴 La deduplicación. Nombre explícito por el límite de 64 caracteres de MySQL.
            $table->unique(['lead_id', 'evento'], 'lem_lead_evento_unique');

            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
        });
    }

    /**
     * Borra la tabla entera.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('lead_eventos_meta');
    }
}
