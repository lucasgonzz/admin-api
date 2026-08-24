<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega lead_messages.sent_via, que registra el origen de un mensaje saliente.
 *
 * Valores previstos:
 * - "claude": enviado por Claude vía los endpoints `claude/*` (analítica y recuperación de leads).
 * - null:     origen no marcado. Es el estado de TODO lo anterior a esta migración.
 *
 * Mismo patrón que `admin_tasks.created_via` (migración 2026_07_22_100002), que ya distingue
 * el mismo actor: ahí `ClaudeTaskIngestController` escribe 'claude'. Se usa string y no un
 * booleano `sent_by_claude` para que un tercer origen mañana (n8n, otra integración) no
 * necesite otra migración.
 *
 * 🔴 A diferencia de `created_via`, esta columna es NULLABLE y sin default, a propósito.
 * `admin_tasks` podía asumir que todo lo viejo era "admin" porque solo había un origen previo.
 * `lead_messages` no: sus filas históricas vienen de cinco caminos distintos (mensaje del lead,
 * envío manual del setter, sugerencia de IA auto-enviada, sugerencia de IA aprobada por un admin,
 * y seguimiento automático por plantilla), y ninguno de esos valores sería honesto como default.
 * NULL significa "origen no marcado: mirá `sender` + `sent_by_admin_id` como se hacía siempre",
 * así la semántica de todo lo existente queda intacta y no hace falta ningún backfill.
 *
 * Sin índice: no se filtra por esta columna en ningún camino caliente y su cardinalidad es baja.
 * Solo se lee al serializar el hilo de una conversación.
 */
class AddSentViaToLeadMessagesTable extends Migration
{
    /**
     * Agrega la columna sent_via a lead_messages.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->string('sent_via', 20)->nullable()->after('sent_by_admin_id');
        });
    }

    /**
     * Quita la columna sent_via de lead_messages.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('sent_via');
        });
    }
}
