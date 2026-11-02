<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\ClientAiPlanSyncService;

/**
 * Reenvía el plan a todos los clientes activos con paquete de IA, para cuando cambia
 * `config/ia_precios.php` (misión plan-ia-por-gasto, 2/11/2026).
 *
 * El plan lleva adentro la tabla de precios (`ClientAiPlanSyncService::cuerpo_del_plan`, clave
 * `precios_ia`), y del lado de empresa esa tabla es la que decide cuánto gastó un negocio contra su
 * tope. Si un proveedor cambia la lista, el barrido de cada noche (`ai-planes:sincronizar`) la lleva
 * solo en menos de un día; este comando es para no esperar a la noche.
 *
 * 🔴 **Hereda de `SincronizarAiPlanesCommand` y no copia nada.** El barrido en serie con un segundo de
 * pausa (el shared de Hostinger bloquea la cuenta entera por ráfaga de conexiones), el `try/catch` por
 * cliente, la tabla y el resumen son los mismos, y tener dos copias de ese bucle es la forma de que la
 * pausa se pierda en una de las dos. Acá cambian solo dos cosas: a quién le toca y el `--dry-run`.
 *
 * Los que NO tienen paquete quedan afuera, y es a propósito: con los precios no tienen nada que ganar
 * (el plan les llega con `precios_ia` en null, porque sin paquete no hay tope contra el que costear).
 * Los inactivos quedan afuera por lo mismo que en el barrido de la noche: su instancia suele estar
 * apagada y lo único que aportan es un timeout. Para uno puntual está
 * `ai-planes:sincronizar --client=<id>`, que lleva la misma tabla.
 *
 * Idempotente, como todo push de plan: correrlo dos veces deja a cada cliente igual.
 */
class SincronizarPreciosIaCommand extends SincronizarAiPlanesCommand
{
    /**
     * Nombre y opciones del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'ia:sincronizar-precios
        {--dry-run : Lista a quién se le reenviaría, sin hablarle a ningún cliente}';

    /**
     * Descripción visible en php artisan list.
     *
     * @var string
     */
    protected $description = 'Reenvía el plan, con la tabla de config/ia_precios.php, a los clientes activos con paquete de IA';

    /**
     * Ejecuta el barrido, o lo simula con `--dry-run`.
     *
     * @param ClientAiPlanSyncService $sync Servicio que hace el trabajo.
     *
     * @return int
     */
    public function handle(ClientAiPlanSyncService $sync): int
    {
        if ($this->option('dry-run')) {
            return $this->simular();
        }

        return parent::handle($sync);
    }

    /**
     * Los clientes activos que tienen un paquete asignado.
     *
     * Se trae el paquete de una: el push lo pide con `loadMissing`, así que no vuelve a consultarlo.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function clientes_a_barrer()
    {
        return Client::with('ai_plan')
            ->where('is_active', true)
            ->whereNotNull('ai_plan_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * El `--dry-run`: muestra a quién se le reenviaría y con qué tope, sin pegarle a nadie y sin tocar
     * `ai_plan_sync_*`.
     *
     * @return int
     */
    private function simular(): int
    {
        $clientes = $this->clientes_a_barrer();

        $filas = [];

        foreach ($clientes as $client) {
            $plan = $client->ai_plan;

            $filas[] = [
                (int) $client->id,
                $client->resolve_display_name(),
                $plan === null ? '(paquete inexistente)' : $plan->nombre,
                $plan === null || $plan->tope_gasto_usd_mensual === null ? 'sin tope' : $plan->tope_gasto_usd_mensual,
            ];
        }

        if ($filas !== []) {
            $this->table(['Cliente', 'Nombre', 'Paquete', 'Tope de gasto (USD)'], $filas);
        }

        $this->info(
            'Dry-run: se le reenviaría a ' . count($filas) . ' cliente(s), con los precios de '
            . count((array) config('ia_precios', [])) . ' modelo(s). No se le mandó nada a nadie.'
        );

        return 0;
    }
}
