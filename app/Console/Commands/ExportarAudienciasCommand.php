<?php

namespace App\Console\Commands;

use App\Helpers\AppTime;
use App\Services\AudienciasMetaService;
use Illuminate\Console\Command;

/**
 * Exporta a CSV un público personalizado para Meta (misión origen-del-lead-y-conversiones-meta,
 * 13/10/2026): la misma lista que `GET claude/audiencias`, para subirla a mano en el Administrador de
 * anuncios. Encabezado `phone,email`, los dos ya hasheados con SHA-256.
 *
 *     php artisan leads:exportar-audiencias --etapa=conversaron_sin_agendar
 *     php artisan leads:exportar-audiencias --etapa=clientes --salida=C:/tmp/clientes.csv
 */
class ExportarAudienciasCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'leads:exportar-audiencias
        {--etapa= : conversaron_sin_agendar | agendaron_sin_entrar | clientes}
        {--dias=14 : Ventana en días de conversaron_sin_agendar (por el último mensaje del lead)}
        {--salida= : Ruta del CSV. Por defecto, storage/app/audiencias/audiencia-<etapa>-<fecha>.csv}';

    /**
     * @var string
     */
    protected $description = 'Exporta a CSV (teléfono y mail en SHA-256) un público personalizado para Meta armado con el pipeline de leads';

    /**
     * @param AudienciasMetaService $service Inyectado por el contenedor.
     *
     * @return int
     */
    public function handle(AudienciasMetaService $service): int
    {
        $etapa = trim((string) $this->option('etapa'));
        if (! in_array($etapa, AudienciasMetaService::ETAPAS, true)) {
            $this->error('--etapa tiene que ser una de: ' . implode(', ', AudienciasMetaService::ETAPAS) . '.');

            return 1;
        }

        $dias_crudo = trim((string) $this->option('dias'));
        if ($dias_crudo === '') {
            $dias_crudo = (string) AudienciasMetaService::DIAS_POR_DEFECTO;
        }
        if (! ctype_digit($dias_crudo) || (int) $dias_crudo < 1 || (int) $dias_crudo > AudienciasMetaService::DIAS_MAXIMO) {
            $this->error('--dias tiene que ser un entero entre 1 y ' . AudienciasMetaService::DIAS_MAXIMO . '.');

            return 1;
        }

        $salida = trim((string) $this->option('salida'));
        if ($salida === '') {
            $salida = storage_path('app/audiencias/audiencia-' . $etapa . '-' . AppTime::now()->format('Ymd-His') . '.csv');
        }

        $carpeta = dirname($salida);
        if (! is_dir($carpeta) && ! @mkdir($carpeta, 0755, true) && ! is_dir($carpeta)) {
            $this->error('No se pudo crear la carpeta ' . $carpeta . '.');

            return 1;
        }

        $audiencia = $service->audiencia($etapa, (int) $dias_crudo);
        $filas     = $audiencia['filas'];

        if (@file_put_contents($salida, $service->a_csv($filas)) === false) {
            $this->error('No se pudo escribir ' . $salida . '.');

            return 1;
        }

        $this->info(count($filas) . ' persona(s) en ' . $etapa . ' → ' . $salida);

        if ($audiencia['descartados_por_telefono'] > 0) {
            $this->warn($audiencia['descartados_por_telefono'] . ' teléfono(s) que no encajan en ningún formato quedaron afuera '
                . '(no se adivinan; si el contacto tenía mail, entró por el mail).');
        }

        return 0;
    }
}
