<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes de WhatsApp PROGRAMADOS para un lead: los que todavía no salieron.
 *
 * 🔴 Por qué es una tabla propia y no un `status = 'programado'` en `lead_messages`.
 *
 * `LeadAiService::build_user_content()` arma el historial que ve el agente recorriendo **todos**
 * los `$lead->messages` y filtrando por EXCLUSIÓN (`deleted_from_context`, `is_error`, `kind`). Un
 * estado nuevo no aparece en ninguna de esas listas de exclusión, así que un mensaje programado
 * viviendo en `lead_messages` se le colaría al modelo como si YA se lo hubiéramos dicho al lead —
 * y le contestaría en consecuencia a algo que todavía no salió. Es exactamente la clase de error
 * que ese archivo documenta en rojo (la causa inventada del 25/8/2026), y entra por la misma
 * única puerta, porque es el único prompt que lleva historial.
 *
 * Y no es solo el agente: medido el 10/9/2026, **41 archivos de `app/` consultan `lead_messages`**,
 * y varios dan por sentado que lo que no es `sugerido` ya se despachó (`Lead.php`,
 * `LeadMessage.php`, `ClaudeLeadMetricsService.php`). Un estado nuevo obliga a auditar los 41; una
 * tabla aparte no toca ninguno.
 *
 * Cuando el programado efectivamente SALE, se crea un `LeadMessage` normal
 * (`sender='setter'`, `status='enviado'`) y esta fila pasa a `enviado` apuntándolo con
 * `sent_lead_message_id`. O sea: mientras espera vive acá y no lo ve nadie más; una vez enviado se
 * ve como cualquier otro mensaje del hilo, que es lo que pidió Lucas.
 *
 * Sin claves foráneas declarativas, igual que `lead_messages` (convención del repo).
 */
class CreateLeadScheduledMessagesTable extends Migration
{
    /**
     * Crea la tabla `lead_scheduled_messages`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('lead_scheduled_messages', function (Blueprint $table) {
            $table->id();

            // Lead destinatario (referencia a leads.id, sin FK declarativa).
            $table->unsignedBigInteger('lead_id')->index();

            /* Cuándo tiene que salir. Indexada porque el comando que corre cada minuto pregunta
               justo por esto: pendientes con la hora ya cumplida. */
            $table->timestamp('scheduled_send_at')->index();

            /* 'texto_libre' | 'plantilla'. Lo decide la ventana de 24 hs de Meta al momento de
               programar, y se revalida al despachar: ver LeadScheduledMessageService. */
            $table->string('mode', 20);

            /* El texto que se manda si es libre, o el body YA RENDERIZADO de la plantilla. En los
               dos casos es lo que va a quedar escrito en el LeadMessage cuando salga, así que el
               hilo se lee igual sin importar por qué camino salió. */
            $table->text('content');

            // Plantilla Meta aprobada e idioma, solo cuando mode = 'plantilla'.
            $table->string('template_name', 120)->nullable();
            $table->string('template_language', 10)->nullable();
            $table->json('template_variables')->nullable();

            /* El check de Lucas, APAGADO por defecto (decisión del 10/9/2026): apagado, el mensaje
               sale igual a su hora aunque el lead haya escrito antes. */
            $table->boolean('cancel_if_lead_replies')->default(false);

            /* Último mensaje DEL LEAD al momento de programar; null si nunca escribió.
               🔴 Se guarda el id y no una fecha a propósito: al despachar se pregunta si hay algún
               mensaje del lead con id MAYOR que éste. `created_at` es un timestamp sin fracción de
               segundo y un empate en el mismo segundo da el resultado al revés (documentado en el
               informe 20260902-mensaje-libre-a-lead.md). */
            $table->unsignedBigInteger('baseline_lead_message_id')->nullable();

            /* 'pendiente' | 'enviado' | 'cancelado' | 'error'. Indexada por el mismo motivo que
               scheduled_send_at: es la otra mitad de la consulta del comando. */
            $table->string('status', 20)->default('pendiente')->index();

            // El LeadMessage que se creó al salir (referencia a lead_messages.id).
            $table->unsignedBigInteger('sent_lead_message_id')->nullable();

            // Motivo legible cuando quedó en `error` (Meta lo rechazó, la ventana se cerró antes, etc.).
            $table->text('error_text')->nullable();

            /* 'manual' | 'lead_respondio' | 'lead_no_recibe' | 'lead_promovido' | 'sin_telefono'.
               Se guarda el motivo y no solo el estado: "cancelado" a secas no le dice nada al
               operador que vuelve a la conversación tres días después. */
            $table->string('canceled_reason', 40)->nullable();

            // Admin que lo programó (referencia a admins.id). Hereda al LeadMessage como sent_by_admin_id.
            $table->unsignedBigInteger('created_by_admin_id')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla `lead_scheduled_messages`.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('lead_scheduled_messages');
    }
}
