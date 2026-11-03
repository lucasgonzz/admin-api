<?php

namespace App\Console\Commands;

use App\Services\MetaTemplateService;
use App\Services\PruebaPlantillasService;
use Illuminate\Console\Command;

/**
 * Crea en Meta (por la API de Kapso) y registra en el admin las plantillas de la prueba gratuita que falten
 * (misión cruzada prueba-martin-importacion, 3/11/2026): `cc_prueba_bienvenida` y las `cc_prueba_paso_*`.
 *
 * Sin `--crear` solo lista qué haría: crear plantillas en Meta es una escritura en un sistema de un tercero
 * con revisión humana de hasta 24 h y un tope de 100 por hora por WABA, así que no se dispara sin querer.
 * Las que ya están registradas no se vuelven a crear. Quedan con `activa = false` y `meta_status = PENDING`;
 * las enciende `templates:sync-meta` (cada hora) cuando Meta las aprueba. La clave de Kapso sale de la
 * configuración y no se imprime.
 *
 * Uso: `php artisan prueba:crear-plantillas` (simula) · `php artisan prueba:crear-plantillas --crear`.
 */
class PruebaCrearPlantillasCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'prueba:crear-plantillas {--crear : Crea de verdad en Meta y registra; sin esto solo lista}';

    /**
     * @var string
     */
    protected $description = 'Crea en Meta y registra las plantillas de WhatsApp de la prueba gratuita que falten';

    /**
     * @param PruebaPlantillasService $plantillas
     * @param MetaTemplateService     $meta
     *
     * @return int
     */
    public function handle(PruebaPlantillasService $plantillas, MetaTemplateService $meta)
    {
        $crear = (bool) $this->option('crear');

        if (! $crear) {
            $this->warn('Simulación: no se crea nada en Meta ni se escribe ninguna fila. Repetí con --crear para crearlas.');
        }

        $resultados = $plantillas->crear_las_que_faltan($meta, $crear);

        $filas = [];
        $con_error = 0;
        foreach ($resultados as $resultado) {
            $filas[] = [
                $resultado['plantilla'],
                $resultado['resultado'],
                $resultado['meta_status'] !== null ? $resultado['meta_status'] : '-',
                $resultado['detalle'] !== null ? $resultado['detalle'] : '',
            ];
            if ($resultado['resultado'] === 'meta_rechazo') {
                $con_error++;
            }
        }

        $this->table(['Plantilla', 'Resultado', 'Estado en Meta', 'Detalle'], $filas);

        if ($con_error > 0) {
            $this->error($con_error . ' plantilla(s) no se pudieron crear. Si el motivo es sin_permiso_en_meta, la clave de Kapso no tiene permiso de plantillas sobre la WABA: se cargan a mano con el procedimiento de contexto/cuentas_meta.md y se registran con POST claude/followup-templates.');

            return 1;
        }

        return 0;
    }
}
