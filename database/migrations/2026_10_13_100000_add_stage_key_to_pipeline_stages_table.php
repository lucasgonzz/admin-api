<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clave estable de una etapa del CRM (misión embudo-lead-pipelines, 13/10/2026): un identificador
 * que NO cambia cuando alguien renombra la etapa desde Configuración.
 *
 * 🔴 El defecto que la motiva, antes de que exista. El embudo "Leads" se mueve solo: cuando el lead
 * confirma interés nace la oportunidad en "Interesados", cuando aparece la fecha de la videollamada
 * pasa a "Esperando la llamada" y cuando la llamada termina pasa a "En seguimiento". Pero la
 * decisión de Lucas es que **Tomás edita y renombra las etapas desde Configuración** — y si la
 * automatización buscara la etapa por nombre, el primer renombre la dejaría muda: no tiraría ningún
 * error, simplemente las oportunidades se quedarían quietas en la primera etapa y nadie se
 * enteraría hasta que alguien contara tarjetas a mano. Es la clase "identificar una fila por un
 * texto que el usuario puede editar" (APRENDER_NO_PARCHEAR.md), la misma razón por la que el
 * pipeline se identifica por `slug` y no por `name`.
 *
 * Se llama `stage_key` y no `key` a propósito: `KEY` es palabra reservada de MySQL. Eloquent la
 * entrecomilla sola, pero cualquier consulta cruda escrita después no, y ese error aparece en
 * producción y no acá.
 *
 * Nullable, sin default y sin tocar ninguna fila existente: las etapas que ya están (las ocho del
 * pipeline "Agentes") y las que Tomás cree desde el ABM quedan en NULL, y la automatización —que
 * es la única que la lee— solo mira las del pipeline `lead`.
 */
class AddStageKeyToPipelineStagesTable extends Migration
{
    /**
     * Agrega la columna.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pipeline_stages', function (Blueprint $table) {
            /* Clave estable de la etapa dentro de su pipeline (`interesados`, `esperando_llamada`,
               …). La siembra el seeder del pipeline; el ABM de etapas no la pide ni la escribe. */
            $table->string('stage_key', 40)->nullable()->after('name');

            /* El índice es por `stage_key` solo y no compuesto con `pipeline_id` a propósito: la
               automatización siempre filtra por pipeline Y por clave, y MySQL resuelve bien con el
               índice de `pipeline_id` que ya existe más este. Único NO va: dos pipelines distintos
               pueden tener una etapa con la misma clave, y nada obliga a que exista. */
            $table->index('stage_key');
        });
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->dropIndex(['stage_key']);
            $table->dropColumn('stage_key');
        });
    }
}
