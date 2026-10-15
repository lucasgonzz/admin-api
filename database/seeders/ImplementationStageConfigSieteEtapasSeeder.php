<?php

namespace Database\Seeders;

use App\Models\Implementation;
use App\Models\ImplementationStageConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Lleva el catálogo de etapas de implementación de una base EXISTENTE (producción) de OCHO etapas a
 * SIETE (misión implementacion-7-etapas, decisión de Lucas del 15/10/2026):
 *
 *   - La etapa 6 deja de ser «Capacitación» y pasa a ser «Catálogo: categorías, imágenes y
 *     descripciones» (misma posición): se renombra y se redescribe.
 *   - La etapa 8, «Videollamada de capacitación», sale del flujo: su config queda con `active = false`.
 *     NO se borra: las implementaciones anteriores traen una fila 8 y la API le sigue buscando nombre
 *     ahí (`ClaudeImplementationOpsController::nombres_de_etapas()`).
 *
 * 🔴 Por qué hace falta un seeder suelto y no alcanza con tocar `ImplementationStageConfigSeeder`: ese
 * seeder (el que corre `DatabaseSeeder` en una base nueva) ya quedó en siete etapas, pero en producción
 * el catálogo ya existe con las ocho y `updateOrCreate` ni crea ni actualiza lo que ya no figura en su
 * lista: la fila de la 8 seguiría activa, y la 6 se renombraría solo si alguien lo vuelve a correr.
 * Tampoco sirve `UpdateImplementationStageConfigsSeeder`, que TRUNCA la tabla y borraría la config de la
 * 8 que las implementaciones viejas todavía consultan.
 *
 * Qué NO hace: no toca ninguna implementación ni ninguna fila de `implementation_stages`. Los Peña
 * (implementación 8, cliente 73) está en la etapa 6 en curso: con este seeder pasa a ser, sola, la etapa
 * «Catálogo», sin mover un dato de esa implementación.
 *
 * Idempotente: correrlo dos veces deja la misma base y la segunda no escribe nada (se informa por log y
 * por consola qué había). Se corre con:
 *   php artisan db:seed --class=ImplementationStageConfigSieteEtapasSeeder
 */
class ImplementationStageConfigSieteEtapasSeeder extends Seeder
{
    /** Número de la etapa que cambia de nombre: la 6, en la misma posición que «Capacitación». */
    private const ETAPA_DEL_CATALOGO = 6;

    /** Nombre nuevo de la etapa 6 (el mismo de `ImplementationStageConfigSeeder`). */
    private const NOMBRE_DE_LA_ETAPA_6 = 'Catálogo: categorías, imágenes y descripciones';

    /** Descripción nueva de la etapa 6 (la misma de `ImplementationStageConfigSeeder`). */
    private const DESCRIPCION_DE_LA_ETAPA_6 = 'Se arman las categorías y se completan las fotos y descripciones de los artículos que todavía no las tienen.';

    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        $this->renombrar_la_etapa_6();
        $this->desactivar_la_etapa_8();
    }

    /**
     * Deja la config de la etapa 6 con el nombre y la descripción del catálogo. Si la base no tiene esa
     * fila (un catálogo sin sembrar) la crea con los mismos valores que `ImplementationStageConfigSeeder`.
     *
     * @return void
     */
    private function renombrar_la_etapa_6(): void
    {
        $config = ImplementationStageConfig::where('stage_number', self::ETAPA_DEL_CATALOGO)->first();

        if ($config === null) {
            ImplementationStageConfig::create([
                'stage_number'          => self::ETAPA_DEL_CATALOGO,
                'name'                  => self::NOMBRE_DE_LA_ETAPA_6,
                'description'           => self::DESCRIPCION_DE_LA_ETAPA_6,
                'alert_threshold_hours' => 24.00,
                'is_automated'          => false,
                'active'                => true,
            ]);

            $this->informar('la etapa 6 no existía en el catálogo: se creó como «' . self::NOMBRE_DE_LA_ETAPA_6 . '».');

            return;
        }

        if ((string) $config->name === self::NOMBRE_DE_LA_ETAPA_6
            && (string) $config->description === self::DESCRIPCION_DE_LA_ETAPA_6) {
            $this->informar('la etapa 6 ya se llama «' . self::NOMBRE_DE_LA_ETAPA_6 . '». No se toca.');

            return;
        }

        $nombre_anterior = (string) $config->name;

        $config->name        = self::NOMBRE_DE_LA_ETAPA_6;
        $config->description = self::DESCRIPCION_DE_LA_ETAPA_6;
        $config->save();

        $this->informar('la etapa 6 pasó de «' . $nombre_anterior . '» a «' . self::NOMBRE_DE_LA_ETAPA_6 . '».');
    }

    /**
     * Pone `active = false` a la config de la etapa 8 (sin borrarla). Si la base no la tiene, no hay nada
     * que hacer: no se crea una config que ya no existe en el flujo.
     *
     * @return void
     */
    private function desactivar_la_etapa_8(): void
    {
        $config = ImplementationStageConfig::where('stage_number', Implementation::ETAPA_HEREDADA)->first();

        if ($config === null) {
            $this->informar('la etapa 8 no está en el catálogo. Nada que desactivar.');

            return;
        }

        if (! $config->active) {
            $this->informar('la etapa 8 ya estaba inactiva. No se toca.');

            return;
        }

        $config->active = false;
        $config->save();

        $this->informar('la etapa 8 («' . (string) $config->name . '») quedó inactiva; no se borró.');
    }

    /**
     * Deja constancia de lo que se hizo (o de que ya estaba hecho) en el log y, si corre desde artisan,
     * en la consola.
     *
     * @param string $mensaje Qué pasó.
     *
     * @return void
     */
    private function informar(string $mensaje): void
    {
        $texto = 'ImplementationStageConfigSieteEtapasSeeder: ' . $mensaje;

        Log::info($texto);

        if ($this->command !== null) {
            $this->command->info($texto);
        }
    }
}
