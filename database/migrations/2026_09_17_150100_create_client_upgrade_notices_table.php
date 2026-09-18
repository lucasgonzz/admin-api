<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El registro de qué se le avisó al dueño cuando su sistema se actualizó.
 *
 * Una fila por (actualización, cliente). No es una bitácora: es el estado del aviso, y es lo único
 * que hace que esto no duplique ni se pierda.
 *
 * 🔴 **El único par `(client_version_upgrade_id, client_id)` es el mecanismo de deduplicación, y
 * no una prolijidad.** El hook `saved` de `ClientVersionUpgrade` se dispara en cada `save()` que
 * MUEVE el status a `terminada`, y un mismo upgrade puede pasar por ahí más de una vez: basta que
 * alguien lo vuelva a `actualizandose` y lo cierre de nuevo desde la grilla, o que el pipeline lo
 * cierre y después Lucas lo toque a mano. Sin esta restricción, cada una de esas pasadas sería un
 * mail más al dueño contándole la misma actualización.
 *
 * El nombre del índice único va explícito: el que autogenera Laravel concatenando la tabla y las
 * dos columnas da 65 caracteres y MySQL corta en 64 (error 1059).
 *
 * `client_id` es redundante —se puede llegar por el upgrade— y está a propósito: la pregunta que
 * más se hace sobre esta tabla es "¿a este cliente le avisamos?", y responderla no tiene por qué
 * pagar un join.
 */
class CreateClientUpgradeNoticesTable extends Migration
{
    /**
     * Crea la tabla del registro de avisos.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_upgrade_notices', function (Blueprint $table) {
            $table->id();

            // A qué actualización corresponde este aviso.
            $table->unsignedBigInteger('client_version_upgrade_id');

            // A qué cliente. Redundante a propósito (ver el docblock de arriba) e indexado, porque
            // es la columna por la que se consulta.
            $table->unsignedBigInteger('client_id')->index();

            /*
             * A qué dirección salió el mail. Se copia acá y no se lee de `clients.email` en el
             * momento de mirar: si el dueño cambia la casilla el mes que viene, este aviso tiene
             * que seguir diciendo a dónde fue.
             */
            $table->string('email', 150)->nullable();

            /*
             * En qué quedó el aviso. Los valores están declarados como constantes en
             * `ClientUpgradeNotice` — acá van sueltos porque una migración no importa modelos.
             *
             *   pendiente     — la fila la creó el hook, el job todavía no la trabajó.
             *   enviando      — un worker la RECLAMÓ y está trabajándola ahora mismo. Ver
             *                   `dispatch_started_at`, acá abajo.
             *   enviado       — el mail salió. (El WhatsApp puede haber quedado pendiente; eso se
             *                   cuenta en `whatsapp_enviado_at` y en `error`.)
             *   sin_mail      — no hay casilla ni en `clients.email` ni en el cliente. No salió
             *                   nada, y no es un error del sistema: es el dato que falta.
             *   sin_novedades — hay casilla, pero esas versiones no traen ninguna novedad visible
             *                   para este cliente. El mail NO se manda a propósito.
             *   error         — algo falló de verdad. El motivo queda en `error`.
             *
             * Indexado porque el barrido que busca a quién falta avisarle filtra por acá.
             */
            $table->string('estado', 20)->default('pendiente')->index();

            // Cuándo salió cada cosa. Nulos = todavía no salió.
            $table->timestamp('mail_enviado_at')->nullable();
            $table->timestamp('whatsapp_enviado_at')->nullable();

            /*
             * 🔴 Cuándo un worker RECLAMÓ este aviso, y es lo que hace que dos workers no manden
             * dos mails al mismo dueño.
             *
             * El scheduler corre `queue:work database --stop-when-empty` cada minuto y a
             * propósito NO usa `withoutOverlapping()` (está escrito y explicado en
             * `Console/Kernel.php`), así que puede haber dos workers vivos a la vez. Sin esta
             * columna, los dos leen la fila en `pendiente`, los dos pasan la resolución de la
             * casilla (HTTP, hasta 15 s), la consulta de novedades y el SMTP antes de que ninguno
             * escriba `mail_enviado_at`, y salen DOS mails. La ventana no es teórica: es todo ese
             * tramo.
             *
             * El reclamo es un UPDATE condicional sobre `estado` — el que lo gana afecta una fila,
             * el que llega segundo afecta cero — y esta fecha es la que permite destrabar un
             * reclamo que quedó colgado porque el proceso murió sin poder soltarlo. Mismo patrón
             * que `lead_scheduled_messages.dispatch_started_at`.
             */
            $table->timestamp('dispatch_started_at')->nullable();

            // El wamid que devuelve Meta para el WhatsApp del aviso. Nulo si no salió.
            $table->string('whatsapp_message_id', 120)->nullable();

            // El motivo, en castellano y para leer. No es solo para `error`: un aviso `enviado`
            // puede tener acá por qué el WhatsApp no salió.
            $table->text('error')->nullable();

            $table->timestamps();

            // 🔴 La deduplicación. Nombre explícito por el límite de 64 caracteres de MySQL.
            $table->unique(['client_version_upgrade_id', 'client_id'], 'cun_upgrade_client_unique');

            $table->foreign('client_version_upgrade_id')
                ->references('id')->on('client_version_upgrades')->onDelete('cascade');

            $table->foreign('client_id')
                ->references('id')->on('clients')->onDelete('cascade');
        });
    }

    /**
     * Borra la tabla entera.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_upgrade_notices');
    }
}
