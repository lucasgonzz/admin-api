<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads que llegaron por otro medio (misión leads-referidos-compromisos, etapa E1, 25/11/2026).
 *
 * Un referido, un contacto del WhatsApp personal de Lucas o alguien que ya habló por teléfono no pasó por el
 * circuito pensado para los leads. Para poder darlos de alta y que Martín sepa cómo llegaron, el lead guarda:
 *  - `referido_por_client_id`: el cliente de ComercioCity que lo recomendó (sin clave foránea: el admin no las usa).
 *  - `referido_por_texto`: quien lo recomendó cuando NO es un cliente cargado (un amigo, un contador).
 *  - `contexto_previo`: lo que ya se habló antes del alta (texto libre, hasta 60.000 caracteres: mediumText).
 *
 * 🔴 Las tres columnas son nullable: un lead anterior no tiene nada de esto y NULL lo dice tal cual. Cada columna
 * lleva su `Schema::hasColumn()` para poder volver a correr la migración sobre una base que quedó a medias.
 */
class AddReferidoToLeadsTable extends Migration
{
    /**
     * Agrega las tres columnas y el índice corto del cliente que refiere.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Cliente que lo refirió. Índice propio (nombre corto) para listar «los que trajo este cliente».
            if (! Schema::hasColumn('leads', 'referido_por_client_id')) {
                $table->unsignedBigInteger('referido_por_client_id')->nullable()->after('origen_calculado_at');
                $table->index('referido_por_client_id', 'leads_ref_client_idx');
            }

            // Quien lo refirió cuando no es un cliente cargado. Acotado a 120 caracteres.
            if (! Schema::hasColumn('leads', 'referido_por_texto')) {
                $table->string('referido_por_texto', 120)->nullable()->after('referido_por_client_id');
            }

            // Lo que ya se habló con el lead antes de darlo de alta (el tope de 60.000 lo pone el servicio).
            if (! Schema::hasColumn('leads', 'contexto_previo')) {
                $table->mediumText('contexto_previo')->nullable()->after('referido_por_texto');
            }
        });
    }

    /**
     * Quita el índice y las tres columnas (las que estén).
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('leads', 'referido_por_client_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropIndex('leads_ref_client_idx');
            });
        }

        $columnas = array_values(array_filter(['referido_por_client_id', 'referido_por_texto', 'contexto_previo'], function ($columna) {
            return Schema::hasColumn('leads', $columna);
        }));

        if (! empty($columnas)) {
            Schema::table('leads', function (Blueprint $table) use ($columnas) {
                $table->dropColumn($columnas);
            });
        }
    }
}
