<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `followup_templates.no_marca_error_de_entrega`: plantillas cuyo rechazo de Meta NO pinta rojo.
 *
 * Misión `campana-masiva-sin-rojo` (22/10/2026). Ese día salió `cc_reactivacion_agentes_ia` a 561
 * leads en tres tandas. A los que Meta no les entrega el mensaje, el webhook de Kapso les escribe
 * `whatsapp_delivery_status = 'fallido'` en el saliente, y el panel los pinta de rojo «Error de
 * envío» (`Lead::apply_condicion_error_sin_resolver()`) y los cuenta en la tarjeta
 * (`Lead::scopeRequiereRevision($q, true)`). En una campaña masiva a leads fríos un rechazo de Meta
 * es esperable y no pide que nadie haga nada: siete rojos sobre 187 de la primera tanda le
 * ensuciaban el panel a Lucas sin ninguna acción posible.
 *
 * 🔴 Es una columna de la PLANTILLA y no una regla por nombre en el código porque la próxima
 * campaña masiva va a usar otra plantilla, y una lista de nombres metida en `Lead` es la clase de
 * cosa que nadie se acuerda de actualizar. Se enciende por fila (acá para la campaña del 22/10, y
 * con `POST claude/templates/meta` para las que vengan).
 *
 * ⚠️ El default es `false` a propósito: todas las plantillas que ya existen siguen marcando el
 * rechazo de Meta como error, igual que antes. Y el flag solo apaga la entrega `fallido`: un
 * `is_error` (el sistema no pudo ni mandar) sigue en rojo aunque sea de una plantilla con el flag.
 */
class AddNoMarcaErrorDeEntregaToFollowupTemplates extends Migration
{
    /**
     * Agrega la columna y la enciende para la plantilla de la campaña del 22/10/2026.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('followup_templates', function (Blueprint $table) {
            $table->boolean('no_marca_error_de_entrega')->default(false)->after('activa');
        });

        /* En producción es la fila id 39 (medido por la API sobre 171 mensajes de la campaña). Se
           busca por nombre y no por id para que en una base vacía (testing, un slot recién armado)
           no haga nada en vez de encender una plantilla cualquiera que haya caído en el 39. */
        DB::table('followup_templates')
            ->where('template_name', 'cc_reactivacion_agentes_ia')
            ->update(['no_marca_error_de_entrega' => 1]);
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('followup_templates', function (Blueprint $table) {
            $table->dropColumn('no_marca_error_de_entrega');
        });
    }
}
