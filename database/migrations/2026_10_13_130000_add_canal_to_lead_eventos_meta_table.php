<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Por qué canal sale cada conversión a Meta (segunda tanda de M6c, 13/10/2026: «quiero que
 * extiendas la función para que se mande la info de los leads que fueron a la landing y agendaron
 * una demo»).
 *
 * Hasta acá `lead_eventos_meta` tenía un solo canal: los leads de anuncio Click-to-WhatsApp, por la
 * CAPI for Business Messaging (`LeadSubmitted`, `QualifiedLead`, `Purchase`). Ahora suma el de los
 * leads que vinieron de la WEB, al conjunto de datos del píxel (`Lead`, `Schedule`, `Purchase`). Los
 * dos canales comparten tabla, estados, reclamo atómico y conciliador; lo que cambia es el payload,
 * la configuración y el `event_id`. La lógica vive en `MetaConversionesService`.
 *
 *   - `canal`: `whatsapp` | `web`. Default `whatsapp`: todas las filas que ya existen son de ese
 *     canal y quedan bien sin tocarlas.
 *   - 🔴 **El único pasa de (`lead_id`, `evento`) a (`lead_id`, `canal`, `evento`).** `Purchase` existe
 *     en los dos canales con el mismo nombre (es el evento estándar de Meta), y un lead de la web al
 *     que más tarde le llega el referral de un anuncio puede tener filas en los dos. El único sigue
 *     siendo el mecanismo de deduplicación: una fila por lead, canal y evento.
 *
 * El índice nuevo se crea ANTES de borrar el viejo: así `lead_id` nunca se queda sin índice (las
 * consultas por lead y el conciliador lo usan).
 */
class AddCanalToLeadEventosMetaTable extends Migration
{
    /** Índice único de la primera tanda: (lead_id, evento). */
    const INDICE_VIEJO = 'lem_lead_evento_unique';

    /** Índice único nuevo: (lead_id, canal, evento). Nombre explícito por el límite de 64 de MySQL. */
    const INDICE_NUEVO = 'lem_lead_canal_evento_unique';

    /**
     * Agrega `canal` y cambia el único. Cada paso con su chequeo, para poder volver a correrla sobre
     * una base donde quedó a medias.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('lead_eventos_meta')) {
            return;
        }

        if (! Schema::hasColumn('lead_eventos_meta', 'canal')) {
            Schema::table('lead_eventos_meta', function (Blueprint $table) {
                // whatsapp | web. Los valores están como constantes en `LeadEventoMeta`.
                $table->string('canal', 20)->default('whatsapp')->after('lead_id');
            });
        }

        if (! $this->tiene_indice(self::INDICE_NUEVO)) {
            Schema::table('lead_eventos_meta', function (Blueprint $table) {
                // 🔴 La deduplicación, ahora por canal.
                $table->unique(['lead_id', 'canal', 'evento'], self::INDICE_NUEVO);
            });
        }

        if ($this->tiene_indice(self::INDICE_VIEJO)) {
            Schema::table('lead_eventos_meta', function (Blueprint $table) {
                $table->dropUnique(self::INDICE_VIEJO);
            });
        }
    }

    /**
     * Vuelve al esquema de la primera tanda.
     *
     * 🔴 Borra las filas del canal `web` antes de reponer el único viejo: sin la columna `canal` no
     * se distinguen de las de WhatsApp, y un `Purchase` de cada canal para el mismo lead haría
     * fallar el único (lead_id, evento).
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('lead_eventos_meta')) {
            return;
        }

        if (Schema::hasColumn('lead_eventos_meta', 'canal')) {
            DB::table('lead_eventos_meta')->where('canal', 'web')->delete();
        }

        if (! $this->tiene_indice(self::INDICE_VIEJO)) {
            Schema::table('lead_eventos_meta', function (Blueprint $table) {
                $table->unique(['lead_id', 'evento'], self::INDICE_VIEJO);
            });
        }

        if ($this->tiene_indice(self::INDICE_NUEVO)) {
            Schema::table('lead_eventos_meta', function (Blueprint $table) {
                $table->dropUnique(self::INDICE_NUEVO);
            });
        }

        if (Schema::hasColumn('lead_eventos_meta', 'canal')) {
            Schema::table('lead_eventos_meta', function (Blueprint $table) {
                $table->dropColumn('canal');
            });
        }
    }

    /**
     * ¿La tabla tiene un índice con ese nombre? (MySQL: Laravel 8 no trae `Schema::hasIndex`.)
     *
     * @param string $nombre
     *
     * @return bool
     */
    private function tiene_indice(string $nombre): bool
    {
        return ! empty(DB::select('SHOW INDEX FROM `lead_eventos_meta` WHERE Key_name = ?', [$nombre]));
    }
}
