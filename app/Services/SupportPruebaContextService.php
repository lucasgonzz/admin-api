<?php

namespace App\Services;

use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;

/**
 * Arma el bloque `prueba` que recibe Martín (el soporte de Codex) en el contexto de un ticket cuando el
 * cliente es un negocio en PRUEBA GRATUITA (misión cruzada prueba-martin-importacion, 3/11/2026).
 *
 * Con este bloque Martín lee además `prueba.md`: se presenta una sola vez, ofrece la migración gratis,
 * pide el Excel o el PDF, sugiere el próximo hito que falta y sabe cuántos días quedan. Sin el bloque
 * (null), el cliente no está en prueba y Martín atiende como siempre.
 *
 * Reglas:
 *  - **Nunca lanza.** El contexto se arma en cada `claim` y en cada `GET tickets/{id}`; ante un error el
 *    bloque lo DICE (`error`) en vez de volver null, porque null significa «no está en prueba».
 *  - Los hitos salen de la instancia (C3) cuando contesta, y si no de los eventos de C2 (`hitos_fuente`).
 *  - Sin datos personales de más: ni teléfonos ni claves. Los días y los hitos alcanzan.
 */
class SupportPruebaContextService
{
    /** @var PruebaDelClienteService */
    protected $pruebas;

    /**
     * @param PruebaDelClienteService|null $pruebas
     */
    public function __construct(?PruebaDelClienteService $pruebas = null)
    {
        $this->pruebas = $pruebas === null ? app(PruebaDelClienteService::class) : $pruebas;
    }

    /**
     * El bloque `prueba` del contexto, o null si el cliente del ticket no está en prueba.
     *
     * @param SupportTicket $ticket
     *
     * @return array<string, mixed>|null
     */
    public function bloque_para_el_ticket(SupportTicket $ticket): ?array
    {
        try {
            $ticket->loadMissing('client');
            $client = $ticket->client;

            if (! $this->pruebas->es_cliente_en_prueba($client)) {
                return null;
            }

            $lead    = $this->pruebas->lead_del_cliente($client);
            $resumen = $this->pruebas->resumen($client, $lead, true);

            return [
                'estado'             => 'prueba',
                'dias'               => $resumen['dias'],
                'vence_at'           => $resumen['vence_at'],
                'dias_restantes'     => $resumen['dias_restantes'],
                'dia_actual'         => $resumen['dia_actual'],
                'vencida'            => $resumen['vencida'],
                'semilla_presente'   => $resumen['semilla_presente'],
                'hitos'              => $resumen['hitos'],
                'hitos_fuente'       => $resumen['hitos_fuente'],
                'proximo_hito'       => $resumen['proximo_hito'],
                'proximo_hito_label' => $resumen['proximo_hito_label'],
                'bienvenida_enviada' => $resumen['bienvenida_enviada'],
                'ultimo_empujon_at'  => $resumen['ultimo_empujon_at'],
                'lead_id'            => $resumen['lead_id'],
            ];
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('SupportPruebaContextService: no se pudo armar el bloque de la prueba.', [
                'ticket_id' => (int) $ticket->id,
                'error'     => $e->getMessage(),
            ]);

            return ['estado' => 'prueba', 'error' => 'No se pudo leer el estado de la prueba en esta consulta.'];
        }
    }
}
