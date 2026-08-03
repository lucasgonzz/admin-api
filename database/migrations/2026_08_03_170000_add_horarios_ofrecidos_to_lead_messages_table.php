<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `lead_messages` la declaración de qué horarios ofreció el texto del mensaje (grupo 306,
 * prompt 04 — `contexto/estado_y_decisiones.md` §3.23).
 *
 * Nullable y SIN default `[]` a propósito: `null` significa "el modelo no declaró nada" (mensaje
 * de la dinámica actual, o de la dinámica nueva que no ofrece ningún horario); `[]` significa "el
 * modelo declaró explícitamente que no ofrece horarios en este mensaje". Esa distinción es la que
 * después permite saber si el modelo está cumpliendo el contrato o ignorándolo.
 *
 * Sin foreign keys (regla del repo). Sin condicionar nombres/columnas por env(). Sin índice: esta
 * columna se lee solo por id de mensaje (LeadSuggestionSendService::send_suggestion()), nunca se
 * busca por su contenido.
 */
return new class extends Migration
{
    /**
     * Agrega la columna `horarios_ofrecidos` a `lead_messages`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->json('horarios_ofrecidos')->nullable()->after('pending_actions');
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
