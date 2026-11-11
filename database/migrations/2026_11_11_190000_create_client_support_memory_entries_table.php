<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ficha del cliente para Martín (misión cruzada `ficha-del-cliente`, 11/11/2026): la memoria persistente por
 * cliente que el agente de soporte va escribiendo en cada ticket (decisiones, preferencias, problemas, compromisos
 * con fecha, cómo trabaja el negocio) y que viaja SIEMPRE en el contexto de sus tickets (`ficha_cliente`).
 *
 * Una fila = una idea. Nunca se edita en el lugar: reemplazar crea una fila nueva con `replaces_id` y pasa la vieja a
 * `reemplazada`; archivar la pasa a `archivada` con motivo. Así queda el rastro de qué sabía el equipo y cuándo.
 * Las reglas viven en `App\Services\ClientMemory\ClientSupportMemoryService`.
 */
class CreateClientSupportMemoryEntriesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('client_support_memory_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('client_id');
            /* decision | preferencia | problema | compromiso | negocio | implementacion | otro */
            $table->string('categoria', 20);
            $table->string('texto', 600);
            /* Para compromisos y pausas: hasta cuándo vale. Pasada la fecha la entrada viaja con `vencida: true`. */
            $table->date('vigente_hasta')->nullable();
            /* vigente | reemplazada | archivada */
            $table->string('status', 12)->default('vigente');
            $table->unsignedBigInteger('replaces_id')->nullable();
            /* martin | lucas */
            $table->string('source', 10);
            $table->unsignedBigInteger('support_ticket_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('archived_reason', 300)->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status'], 'csme_client_status_idx');
            $table->index('replaces_id', 'csme_replaces_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_support_memory_entries');
    }
}
