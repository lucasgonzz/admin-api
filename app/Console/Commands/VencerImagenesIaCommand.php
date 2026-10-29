<?php

namespace App\Console\Commands;

use App\Services\IaApp\IaImagenesService;
use Illuminate\Console\Command;

/**
 * Da por fallidos los pedidos de imagen de la app del agente que no van a salir solos y se lo avisa al
 * sistema del cliente (misión cruzada `app-ia-y-memoria`, 29/10/2026): los que la PC de Lucas reservó y
 * se le cayeron dos veces, y los que nadie generó en `ia_app.imagenes.vence_horas` horas (la PC apagada).
 *
 * Sin esto, con la PC apagada, el dueño vería su imagen «generándose» para siempre. `pendientes()` hace lo
 * mismo cada vez que la PC pregunta; este comando cubre justamente el caso en que la PC no pregunta.
 *
 * Además: reencola las entregas que se perdieron (con resultado y sin entregar, con el próximo intento
 * vencido hace más de 15 minutos) y borra del disco las imágenes entregadas hace más de 7 días (la fila
 * queda, con `imagen_path` en null).
 */
class VencerImagenesIaCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ia:imagenes-vencer';

    /**
     * @var string
     */
    protected $description = 'Vence los pedidos de imagen colgados, reencola entregas perdidas y borra las imágenes entregadas hace más de 7 días';

    /**
     * @param IaImagenesService $imagenes
     *
     * @return int
     */
    public function handle(IaImagenesService $imagenes): int
    {
        $vencidos    = $imagenes->vencer_colgados();
        $reencolados = $imagenes->reencolar_entregas_perdidas(15);
        $borradas    = $imagenes->borrar_imagenes_entregadas(7);

        $this->info('Pedidos de imagen vencidos: ' . $vencidos . '. Entregas perdidas reencoladas: ' . $reencolados
            . '. Imágenes entregadas borradas del disco: ' . $borradas . '.');

        return 0;
    }
}
