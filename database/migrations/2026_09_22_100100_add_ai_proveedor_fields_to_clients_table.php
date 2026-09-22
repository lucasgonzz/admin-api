<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué inteligencia eligió el dueño de cada cliente, tal como la informó su `empresa-api` en la
 * última recolección de consumo de IA.
 *
 * Desde la misión proveedores-ia-deepseek (22/9/2026) el dueño elige en su sistema entre Claude y
 * DeepSeek, y con qué nivel de pensamiento; el `empresa-api` lo manda en el bloque `configuracion`
 * del mismo endpoint `admin-sync/consumo-ia` que ya trae los tokens. Acá se guarda la foto de la
 * última recolección para que la solapa lo muestre sin pegarle a la instancia del cliente.
 *
 * - `ai_proveedor`   → anthropic | deepseek (el proveedor que eligió el dueño).
 * - `ai_pensamiento` → agil | equilibrado | profundo.
 * - `ai_modelo`      → el id efectivo del modelo del asistente (`modelo_asistente` del contrato),
 *                      por ejemplo `deepseek-v4-pro` o `claude-opus-5`.
 *
 * 🔴 NULL en las tres = el cliente NUNCA informó (versión anterior del sistema, que manda los
 * tokens pero no la configuración). No es lo mismo que "eligió Anthropic": adivinar el default
 * del sistema del cliente desde acá sería inventar un dato, y la pantalla lo dice con todas las
 * letras. Por eso el service tampoco pisa estas columnas con null cuando el bloque no viene.
 *
 * Van en `clients` y no en una tabla aparte porque es UNA foto por cliente, no un histórico: lo que
 * interesa es qué usa hoy, y el consumo por modelo (que sí es histórico) ya vive abierto por día
 * en `client_ai_token_usage_person_models`.
 *
 * Sin índices a propósito: la única lectura agrupada (cuántos clientes eligieron cada proveedor)
 * barre `clients` entero, que son cuarenta y cinco filas, y un índice que nadie usa es peso muerto
 * en cada escritura.
 *
 * Guard `hasColumn` en cada una: esta migración corre sobre la base de producción del admin y sobre
 * varias bases de testing de slots que pueden estar en estados distintos.
 */
class AddAiProveedorFieldsToClientsTable extends Migration
{
    /**
     * Agrega las tres columnas de la configuración de IA informada por el cliente.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // anthropic | deepseek. Null = nunca informó.
            if (! Schema::hasColumn('clients', 'ai_proveedor')) {
                $table->string('ai_proveedor', 20)->nullable()->after('ai_tokens_sync_message');
            }

            // agil | equilibrado | profundo. Null = nunca informó.
            if (! Schema::hasColumn('clients', 'ai_pensamiento')) {
                $table->string('ai_pensamiento', 20)->nullable()->after('ai_proveedor');
            }

            // Id efectivo del modelo del asistente. Mismo largo que `modelo` en las tablas de
            // consumo (80): es el mismo dato, viajando por otro bloque del mismo contrato.
            if (! Schema::hasColumn('clients', 'ai_modelo')) {
                $table->string('ai_modelo', 80)->nullable()->after('ai_pensamiento');
            }
        });
    }

    /**
     * Revierte quitando las tres columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $columnas = [];

            foreach (['ai_proveedor', 'ai_pensamiento', 'ai_modelo'] as $columna) {
                if (Schema::hasColumn('clients', $columna)) {
                    $columnas[] = $columna;
                }
            }

            if ($columnas !== []) {
                $table->dropColumn($columnas);
            }
        });
    }
}
