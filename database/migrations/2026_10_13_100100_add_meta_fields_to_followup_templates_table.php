<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El estado de Meta de cada plantilla de lead, más para qué sirve.
 *
 * Hasta hoy `followup_templates` no guardaba NADA de Meta: ni si la plantilla está aprobada, ni su
 * categoría (UTILITY/MARKETING, que define el costo), ni su id, ni el motivo de un rechazo. El
 * único interruptor era `activa`, puesto a mano. Consecuencia medida (ficha
 * `seguimientos-y-plantillas.md` §2 y §5): `send-template` manda cualquier nombre, y si Meta no lo
 * conoce el envío falla en Kapso y el endpoint contesta 200 con `enviado=false`.
 *
 * Las columnas:
 *   - `meta_status`      PENDING | APPROVED | REJECTED | PAUSED | DISABLED, tal cual lo devuelve Meta.
 *   - `meta_category`    UTILITY | MARKETING | AUTHENTICATION. Define el costo: marketing se cobra
 *                        siempre, utilidad es gratis dentro de la ventana de 24 hs.
 *   - `meta_template_id` id de la plantilla en Meta, para poder mirarla en el panel.
 *   - `meta_rechazo`     motivo del rechazo (`rejected_reason`), para no volver a mandar lo mismo.
 *   - `meta_synced_at`   cuándo se consultó el estado por última vez. Null = nunca.
 *   - `components`       el JSON de componentes tal como se mandó a Meta, con los ejemplos.
 *   - `uso`              texto corto de CUÁNDO usarla. Es lo que le deja elegir a la skill `/leads`
 *                        sin tener que leer el cuerpo de las ~38 plantillas y adivinar.
 *
 * 🔴 EL BACKFILL DE `meta_status` ES LO QUE EVITA UNA VENTANA ROTA EN PRODUCCIÓN, y por eso no se
 * puede sacar «porque el comando de sync lo va a llenar igual». Esta misma misión pone un gate en
 * `POST claude/leads/{id}/send-template` que exige `meta_status = APPROVED`. Sin backfill, entre el
 * deploy del admin y la primera corrida de `templates:sync-meta` (hasta una hora después) NINGUNA
 * plantilla tendría estado y el chequeo diario de leads no podría mandar una sola: el gate nuevo
 * dejaría de ser una validación y sería una caída.
 *
 * Marcar `APPROVED` lo que hoy está `activa` es correcto por la convención que ya existe: en este
 * sistema `activa=true` significa «aprobada en Meta y lista para mandar». Está documentado y
 * medido — el 3/9/2026 se cargaron las diez plantillas del chequeo diario y las tres que Meta
 * todavía tenía en revisión quedaron con `activa=false` a propósito
 * (`comercial/plantillas_chequeo_diario.md` del repo de conocimiento). La primera corrida de
 * `templates:sync-meta` corrige cualquier fila donde esa suposición no valga: trae el estado real
 * de Meta y, si está rechazada, la desactiva.
 */
class AddMetaFieldsToFollowupTemplatesTable extends Migration
{
    /**
     * Agrega las columnas de Meta y el uso, y completa el estado de las plantillas activas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('followup_templates', function (Blueprint $table) {
            /* 20 alcanza para el más largo de Meta (AUTHENTICATION, 14) con margen. Indexado porque
               el comando de sync y `GET claude/templates/meta?status=` filtran justo por acá. */
            $table->string('meta_status', 20)->nullable()->index()->after('language_code');
            $table->string('meta_category', 20)->nullable()->after('meta_status');
            $table->string('meta_template_id', 40)->nullable()->after('meta_category');
            $table->text('meta_rechazo')->nullable()->after('meta_template_id');
            $table->timestamp('meta_synced_at')->nullable()->after('meta_rechazo');
            $table->text('components')->nullable()->after('meta_synced_at');
            $table->string('uso', 255)->nullable()->after('components');
        });

        /* Ver el bloque 🔴 del docblock: esto es lo que hace que el gate nuevo de send-template sea
           un no-op para todo lo que ya andaba. */
        DB::table('followup_templates')
            ->where('activa', true)
            ->whereNull('meta_status')
            ->update(['meta_status' => 'APPROVED']);
    }

    /**
     * Saca las columnas de Meta y el uso.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('followup_templates', function (Blueprint $table) {
            $table->dropColumn([
                'meta_status',
                'meta_category',
                'meta_template_id',
                'meta_rechazo',
                'meta_synced_at',
                'components',
                'uso',
            ]);
        });
    }
}
