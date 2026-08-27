<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de Meta aprobadas para hablarle a un CLIENTE desde la bandeja de soporte.
 *
 * 🔴 Es una tabla NUEVA y no filas nuevas en `followup_templates`, y ese es el punto entero de la
 * migración. En `followup_templates` busca el motor de seguimiento automático de leads
 * (LeadFollowupService) filtrando por estado del pipeline + día: una plantilla pensada para un
 * cliente cargada ahí terminaría saliéndole sola a un lead, sin que nadie la haya mandado y sin
 * que nadie se entere hasta que el lead conteste. Son dos juegos separados porque los dispara
 * gente distinta por caminos distintos.
 *
 * La otra diferencia con las de lead: acá la categoría es una COLUMNA y no un derivado. En las de
 * lead se deduce del estado del pipeline, que existe; para un cliente no hay ningún estado del
 * que deducirla, así que la define quien crea la plantilla, con su etiqueta y su orden.
 */
class CreateClientTemplatesTable extends Migration
{
    /**
     * Crea la tabla client_templates.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_templates', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Nombre exacto con el que la plantilla está aprobada en Meta.
            // 🔴 ÚNICO porque es la clave de idempotencia del endpoint del bloque claude/*: Claude
            // reenvía el lote entero cada vez que corrige una descripción o una categoría, y sin
            // esta restricción la segunda corrida dejaría la tabla con dos filas del mismo nombre
            // y el selector mostrando la plantilla repetida.
            $table->string('template_name', 120)->unique();

            // Idioma con el que la plantilla quedó aprobada en Meta; viaja tal cual en el envío.
            $table->string('language_code', 10)->default('es_AR');

            // Slug del grupo con el que el selector las junta. Va indexado porque el listado del
            // SPA siempre sale ordenado y agrupado por acá.
            $table->string('categoria', 60)->index();

            // Etiqueta legible del grupo. Nullable a propósito: si nadie la cargó, el modelo arma
            // una a partir del slug en vez de mostrar un encabezado vacío.
            $table->string('categoria_label', 120)->nullable();

            // Posición del grupo en el selector. 99 manda al final, que es adonde tiene que ir un
            // grupo que nadie ordenó todavía.
            $table->unsignedSmallInteger('categoria_orden')->default(99);

            // Nombre corto y humano para la lista. Sin esto el operador tendría que elegir por el
            // nombre técnico de Meta, que no dice nada.
            $table->string('titulo', 200)->nullable();

            // Cuerpo tal como quedó aprobado en Meta, con sus {{n}}. Es para la vista previa y para
            // saber cuántas variables pedir: el texto que le llega al cliente lo arma Meta, no acá.
            $table->text('body_template')->nullable();

            // Cuándo conviene usarla, en criollo, para el operador que la elige.
            $table->text('descripcion')->nullable();

            // Descripción de cada {{n}}: [{placeholder, label, field, ai_suggestable}].
            $table->json('variables')->nullable();

            // Permite sacar una plantilla del selector sin borrar la fila: si Meta la desaprueba,
            // el historial de lo que ya se mandó tiene que seguir existiendo.
            $table->boolean('activa')->default(true)->index();

            $table->timestamps();

            // Orden natural del selector: primero el grupo, después el slug dentro del grupo.
            $table->index(['categoria_orden', 'categoria'], 'cli_tpl_orden_cat_idx');
        });
    }

    /**
     * Elimina la tabla client_templates.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_templates');
    }
}
