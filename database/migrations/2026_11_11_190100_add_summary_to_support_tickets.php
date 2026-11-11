<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Título y resumen del ticket (misión cruzada `ficha-del-cliente`, 11/11/2026). El título es la columna de siempre,
 * `support_tickets.name`; se suman:
 *  - `summary` (≤ 600): qué pasó, qué se hizo y cómo quedó, lo escribe Martín con `POST .../tickets/{id}/title`.
 *  - `summary_updated_at`: cuándo lo escribió.
 *  - `name_source` (`martin` | `lucas` | null): quién puso el título. El guardado del panel marca `lucas`, y un
 *    título de Lucas no lo pisa Martín. null = un nombre viejo o automático (lo puede reemplazar Martín).
 * Solo agrega columnas nullable: un admin viejo las ignora.
 */
class AddSummaryToSupportTickets extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('summary', 600)->nullable();
            $table->timestamp('summary_updated_at')->nullable();
            $table->string('name_source', 10)->nullable();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['summary', 'summary_updated_at', 'name_source']);
        });
    }
}
