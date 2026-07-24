<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * Imprime por consola la URL de la landing pública de la demo de un lead (prompt 213/02).
 *
 * Sirve para que el equipo pueda pasarle el link al lead por WhatsApp a mano (sin entrar a
 * la base ni esperar a que el agente de IA lo sugiera dentro de la conversación).
 */
class DemoLandingUrlCommand extends Command
{
    /**
     * Nombre del comando artisan y argumento requerido: id numérico del lead.
     *
     * @var string
     */
    protected $signature = 'demo:landing-url {lead_id : ID del lead}';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Imprime la URL de la landing pública de la demo de un lead';

    /**
     * Busca el lead por id e imprime la URL de su landing pública (ruta `demo.landing`,
     * armada con su `uuid`). Si el lead no existe o no tiene `uuid` cargado (registro viejo),
     * imprime un mensaje claro en vez de romper.
     *
     * @return int Código de salida (0 = éxito, 1 = lead no encontrado o sin uuid).
     */
    public function handle(): int
    {
        // Id recibido por argumento, casteado a entero para la búsqueda.
        $lead_id = (int) $this->argument('lead_id');

        $lead = Lead::query()->find($lead_id);
        if (! $lead) {
            $this->error("No existe un lead con id {$lead_id}.");

            return 1;
        }

        // Sin uuid no hay landing posible (registros viejos, previos a la migración del campo).
        if (empty($lead->uuid)) {
            $this->error("El lead #{$lead_id} no tiene uuid cargado: no se puede armar la landing.");

            return 1;
        }

        // Misma construcción de URL que usa LeadDemoMailHelper y LeadAiService, para que
        // los tres canales (mail, agente, consola) generen siempre el mismo link.
        $url = route('demo.landing', ['uuid' => $lead->uuid]);

        $this->info($url);

        return 0;
    }
}
