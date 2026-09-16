<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El hilo de WhatsApp entre el dueño de un negocio y el asistente de IA de su propio sistema.
 *
 * 🔴 Esta tabla NO es una copia de la conversación. La conversación de verdad —los `AiMessage`,
 * el contexto, las tarjetas de carga— vive en el `empresa-api` de ese cliente, que es el único
 * que la puede leer y el único que la tiene que tener. Acá queda solo lo que el admin necesita
 * para hacer su trabajo de intermediario, que son dos cosas y ninguna se puede resolver del otro
 * lado:
 *
 *   1. **El mapeo de las citas.** WhatsApp identifica cada mensaje con un `wamid` de Meta, y
 *      cuando el dueño responde CITANDO uno viejo, el payload trae el `wamid` citado y nada más.
 *      El `empresa-api` no conoce ningún `wamid` —nunca habló con Meta—, así que traducir
 *      "citó este mensaje" a "quiere seguir esta conversación" solo se puede hacer acá. Ese es el
 *      mecanismo que Lucas eligió para separar conversaciones en WhatsApp, donde no hay ningún
 *      botón de "nueva conversación": citar un mensaje del asistente reabre esa conversación
 *      aunque sea vieja, y el corte por tiempo (6 hs sin hablar) lo resuelve el cliente.
 *   2. **La traza para diagnosticar.** Un mensaje que el dueño mandó y nunca le volvió es, sin
 *      esta tabla, invisible: no dejó ticket, no dejó `SupportMessage` y el `empresa-api` puede
 *      ni haberse enterado. Con la fila y su `estado` se ve en qué tramo se cortó.
 *
 * Sin claves foráneas, igual que el resto de las tablas de mensajería de este repo: el hilo tiene
 * que sobrevivir al borrado de un cliente para poder investigar qué pasó, y una FK con cascada
 * es exactamente lo contrario de eso.
 */
class CreateClientAssistantMessagesTable extends Migration
{
    /**
     * Crea la tabla client_assistant_messages.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('client_assistant_messages')) {
            return;
        }

        Schema::create('client_assistant_messages', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Cliente dueño del hilo. Indexado porque toda consulta de diagnóstico arranca por
            // acá ("qué le pasó al asistente de tal cliente").
            $table->unsignedBigInteger('client_id')->index();

            // Teléfono E.164 normalizado del dueño, tal como lo dejó WhatsappNormalizer. Se
            // guarda aunque se pueda derivar de `clients.phone`: si mañana el dueño cambia el
            // teléfono de la ficha, el hilo tiene que seguir diciendo por dónde entró.
            $table->string('telefono', 32);

            // 'in' (del dueño hacia el asistente) u 'out' (la respuesta del asistente).
            $table->string('direccion', 3);

            // wamid de Meta. En un entrante es el del mensaje del dueño; en un saliente, el que
            // devuelve el envío. 🔴 Es la clave de la cita y por eso va indexado y nullable: un
            // saliente que Meta rechaza no tiene wamid, y esa fila igual tiene que quedar (es
            // justamente el caso que hay que poder diagnosticar).
            $table->string('whatsapp_message_id', 128)->nullable()->index();

            // Solo en entrantes: el wamid que el dueño citó al responder. Indexado porque es la
            // columna por la que se busca la conversación que quiere continuar.
            $table->string('reply_to_whatsapp_message_id', 128)->nullable()->index();

            // Identificadores que devuelve el `empresa-api`. El de la conversación es lo que se
            // vuelve a mandar cuando una cita la resuelve; el del mensaje es lo que se pollea
            // hasta que el asistente termina de pensar.
            $table->unsignedBigInteger('ai_conversation_id')->nullable()->index();
            $table->unsignedBigInteger('ai_message_id')->nullable();

            // Tipo normalizado del mensaje entrante (text, audio, image…), tal como lo dejó el
            // webhook. Sirve para saber, leyendo la tabla, si el dueño mandó texto o un audio que
            // llegó transcripto.
            $table->string('tipo', 20)->default('text');

            // El texto: lo que escribió el dueño (o la transcripción de su audio) en un entrante,
            // y lo que le contestó el asistente en un saliente.
            $table->text('texto')->nullable();

            /*
             * Estado del tramo, y cada valor nombra un lugar distinto donde se puede cortar:
             *
             *   recibido   — la fila entrante quedó, el job todavía no la despachó al cliente.
             *   enviado    — el `empresa-api` la aceptó (202) y estamos esperando la respuesta.
             *   respondido — le llegó la respuesta al dueño por WhatsApp. Es el final feliz.
             *   degradado  — el cliente todavía no tiene el endpoint (404). NO es un error del
             *                sistema: es la versión vieja, que es lo esperado durante semanas, y
             *                por eso tiene estado propio y no se reintenta.
             *   error      — cualquier otra cosa: sin api_key, 401/403, 5xx, tope de polling.
             */
            $table->string('estado', 20)->default('recibido')->index();

            // Detalle legible del fallo, para no tener que cruzar con el log al diagnosticar.
            $table->text('error')->nullable();

            $table->timestamps();

            // Búsqueda natural del hilo de un cliente: lo último primero.
            $table->index(['client_id', 'created_at'], 'cli_asist_msg_cliente_fecha_idx');
        });
    }

    /**
     * Elimina la tabla client_assistant_messages.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_assistant_messages');
    }
}
