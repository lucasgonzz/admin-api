<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `local_actividad_clientes`: lo que el sistema de cada cliente le avisa al admin que pasó adentro
 * (inicio de sesión, uso, escaneo de una factura, respuesta del agente del negocio…), para que «El local»
 * sepa quién está adentro (misión el-local, 3/11/2026, contrato C del plan).
 *
 * La llena `POST api/inbound/local/actividad`. En la Fase 1 nadie la manda todavía: el emisor es el
 * `empresa-api` en la Fase 2, que es otra misión con su release. La tabla y el endpoint quedan
 * construidos y probados ahora para que esa misión toque solo empresa.
 *
 * 🔴 `huella` es lo que impide duplicar: sha1 de tipo + ocurrido_at + usuario, única por cliente. No se usa
 * un índice único sobre (client_id, tipo, ocurrido_at, usuario) porque `usuario` es nullable y en MySQL dos
 * NULL no chocan en un índice único: el mismo «login» sin usuario entraría dos veces.
 *
 * Retención corta: `local:podar` borra lo de más de 7 días todos los días a las 04:20.
 *
 * Sin claves foráneas, como el resto del repo, e índices de nombre corto.
 */
class CreateLocalActividadClientesTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('local_actividad_clientes')) {
            return;
        }

        Schema::create('local_actividad_clientes', function (Blueprint $table) {
            $table->id();

            // Cliente que mandó el evento: sale SIEMPRE de la clave X-Admin-Api-Key, nunca del body.
            $table->unsignedBigInteger('client_id');

            // login | actividad | logout | escaneo | agente_negocio | asistente | otro.
            $table->string('tipo', 30);

            // Nombre del usuario del sistema del cliente que lo hizo, si vino.
            $table->string('usuario', 120)->nullable();

            // Detalle chico (≤ 2 KB) del evento, tal como lo mandó el cliente.
            $table->json('detalle')->nullable();

            // Cuándo pasó, en la hora del admin (America/Argentina/Buenos_Aires).
            $table->dateTime('ocurrido_at');

            // sha1(tipo|ocurrido_at|usuario): la clave del «no se duplica».
            $table->char('huella', 40);

            $table->timestamp('created_at')->nullable();

            $table->index(['client_id', 'ocurrido_at'], 'lac_client_ocurrido_idx');
            $table->index('ocurrido_at', 'lac_ocurrido_idx');
            $table->unique(['client_id', 'huella'], 'lac_client_huella_uq');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('local_actividad_clientes');
    }
}
