<?php

namespace App\Console\Commands;

use App\Helpers\AppTime;
use App\Models\Lead;
use App\Services\LeadOrigenService;
use App\Services\MetaConversionesService;
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
 * builder pelado, sin pasar por Eloquent: no mueve `updated_at` ni dispara observers.
 *
 * 🔴 **Las etapas alcanzadas antes de la medición no se le mandan a Meta.** Cuando `--aplicar` le
 * carga el `ctwa_clid` a un lead viejo que resulta ser de anuncio, en la misma pasada registra cada
 * evento que ese lead YA alcanzó (`LeadSubmitted`, `QualifiedLead`, `Purchase`) como una fila de
 * `lead_eventos_meta` en estado `omitido`, con el motivo escrito («alcanzado antes de que existiera
 * la medición; no se manda»), y no encola nada. Ni el job ni el conciliador mandan un `omitido`
 * jamás: el reclamo del envío no acepta ese estado, y como la fila ya existe, nadie la vuelve a
 * registrar como `pendiente`. Sin eso, el conciliador tomaría a cualquier lead de anuncio tocado en
 * la última semana y reportaría clics de hace semanas con fecha de ayer. Lo que el lead alcance
 * DESPUÉS sí sale, como para cualquier lead.
 *
 * Lo mismo con los leads viejos que quedan como `landing` (segunda tanda, 13/10/2026): ese origen
 * también tiene canal de conversiones (el dataset del píxel de la web), así que sus etapas ya
 * alcanzadas (`Lead`, `Schedule`, `Purchase`) quedan `omitido` en el canal `web`. Un lead al que el
 * backfill no le carga `ctwa_clid` ni lo deja `landing` no registra nada.
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
    public function handle(LeadOrigenService $service, MetaConversionesService $conversiones): int
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

        $aplicar   = (bool) $this->option('aplicar');
        $conteo    = array_fill_keys(LeadOrigenService::ORIGENES, 0);
        $sin_dato  = 0;
        $escritos  = 0;
        $omitidos  = 0;
        $ahora     = AppTime::now()->format('Y-m-d H:i:s');

        $query->chunkById(200, function ($leads) use ($service, $conversiones, $aplicar, $ahora, &$conteo, &$sin_dato, &$escritos, &$omitidos) {
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

                $escrito = DB::table('leads')
                    ->where('id', (int) $lead->id)
                    ->whereNull('origen')
                    ->update($cambios);
                $escritos += $escrito;

                /* Lead viejo de anuncio o de la web: lo que ya alcanzó queda `omitido` y no sale a Meta
                   (ver el docblock de la clase). El modelo en memoria no tiene lo que se acaba de
                   escribir por el query builder: se le carga para que el servicio elija el canal. */
                if ($escrito > 0 && ($resultado['ctwa_clid'] !== null || $resultado['origen'] === LeadOrigenService::ORIGEN_LANDING)) {
                    $lead->origen = $resultado['origen'];
                    if ($resultado['ctwa_clid'] !== null) {
                        $lead->ctwa_clid = $resultado['ctwa_clid'];
                    }
                    try {
                        $omitidos += count($conversiones->registrar_omitidos($lead));
                    } catch (\Throwable $exception) {
                        $this->error('Lead ' . $lead->id . ': no se pudieron registrar los eventos omitidos (' . $conversiones->sin_token($exception->getMessage()) . ').');
                    }
                }
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
            $this->info('Conversiones registradas como omitidas (alcanzadas antes de la medición, no se mandan): ' . $omitidos . '.');
        } else {
            $this->warn('Simulación: no se escribió nada. Correlo con --aplicar para guardar el origen.');
        }

        return 0;
    }
}
