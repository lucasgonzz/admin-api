<?php

namespace App\Console\Commands;

use App\Helpers\AppTime;
use App\Models\Lead;
use App\Services\LeadOrigenService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Completa el origen de los leads anteriores al deploy (misión origen-del-lead-y-conversiones-meta,
 * 13/10/2026), con la MISMA regla que aplican los observers: el primer mensaje entrante de cada lead.
 *
 * Simula por defecto: sin `--aplicar` no escribe nada, solo cuenta cuántos leads quedarían en cada
 * origen. Va en `pendientes.json` para que `/deploy-admin` lo corra una vez con `--aplicar`.
 *
 *     php artisan leads:calcular-origen                 # simulación sobre todos los leads sin origen
 *     php artisan leads:calcular-origen --aplicar       # escribe
 *     php artisan leads:calcular-origen --desde=2026-09-01 --aplicar
 *
 * 🔴 Solo toca leads con `origen` en NULL, así que es seguro de correr dos veces. Escribe con el query
 * builder pelado, sin pasar por Eloquent: no mueve `updated_at` ni dispara observers. Un lead viejo
 * que resulta ser de anuncio queda con su `ctwa_clid`, pero no le manda a Meta conversiones de
 * etapas que alcanzó hace semanas: esas salen recién si el lead vuelve a moverse.
 *
 * Un lead sin ningún mensaje entrante queda en NULL a propósito: todavía no hay de dónde leer su
 * origen, y si mañana escribe, el observer lo calcula con su primer mensaje.
 */
class CalcularOrigenDeLeadsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'leads:calcular-origen
        {--desde= : Solo leads creados desde esta fecha (AAAA-MM-DD)}
        {--aplicar : Escribe el origen; sin esta opción solo simula}';

    /**
     * @var string
     */
    protected $description = 'Calcula el origen (anuncio de WhatsApp, web, Instagram o sin dato) de los leads que todavía no lo tienen, sobre su primer mensaje (simula por defecto, --aplicar para escribir)';

    /**
     * @param LeadOrigenService $service Inyectado por el contenedor.
     *
     * @return int
     */
    public function handle(LeadOrigenService $service): int
    {
        $query = Lead::query()->whereNull('origen');

        $desde = trim((string) $this->option('desde'));
        if ($desde !== '') {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
                $this->error('--desde tiene que ser una fecha AAAA-MM-DD (llegó "' . $desde . '").');

                return 1;
            }
            $query->where('created_at', '>=', Carbon::parse($desde . ' 00:00:00'));
        }

        $aplicar  = (bool) $this->option('aplicar');
        $conteo   = array_fill_keys(LeadOrigenService::ORIGENES, 0);
        $sin_dato = 0;
        $escritos = 0;
        $ahora    = AppTime::now()->format('Y-m-d H:i:s');

        $query->chunkById(200, function ($leads) use ($service, $aplicar, $ahora, &$conteo, &$sin_dato, &$escritos) {
            foreach ($leads as $lead) {
                $resultado = $service->calcular_para_lead($lead);
                if ($resultado === null) {
                    $sin_dato++;
                    continue;
                }

                $conteo[$resultado['origen']]++;

                if (! $aplicar) {
                    continue;
                }

                $cambios = [
                    'origen'              => $resultado['origen'],
                    'origen_calculado_at' => $ahora,
                ];
                if ($resultado['ctwa_clid'] !== null) {
                    $cambios['ctwa_clid'] = $resultado['ctwa_clid'];
                }

                $escritos += DB::table('leads')
                    ->where('id', (int) $lead->id)
                    ->whereNull('origen')
                    ->update($cambios);
            }
        });

        $filas = [];
        foreach ($conteo as $origen => $cantidad) {
            $filas[] = [$origen, $cantidad];
        }
        $filas[] = ['(sin mensajes entrantes: queda en NULL)', $sin_dato];

        $this->table(['Origen', 'Leads'], $filas);

        if ($aplicar) {
            $this->info('Escritos: ' . $escritos . ' lead(s).');
        } else {
            $this->warn('Simulación: no se escribió nada. Correlo con --aplicar para guardar el origen.');
        }

        return 0;
    }
}
