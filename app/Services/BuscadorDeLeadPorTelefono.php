<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Lead;

/**
 * El ÚNICO criterio para decidir a qué lead pertenece un teléfono (misión
 * demo-autogestionada-landing, 22/10/2026, D4 del plan).
 *
 * Hasta esta misión vivía como `WhatsappWebhookController::find_lead_by_phone()` (privado). Se
 * extrajo tal cual —mismo recorrido, misma comparación (`WhatsappNormalizer::phones_match`), mismo
 * desempate (el más reciente por id)— porque ahora lo usan DOS puertas: el webhook, cuando el lead
 * escribe, y la reserva de la landing, cuando el visitante deja su WhatsApp.
 *
 * 🔴 POR QUÉ tiene que ser el mismo y no "uno parecido": si la landing eligiera un lead y el webhook
 * otro para el mismo número, el lead reservaría la demo en una ficha y su primer mensaje caería en
 * otra —sin turno, sin carta, con el agente ofreciéndole una demo que ya tiene—. Cualquier cambio de
 * criterio (por ejemplo, dejar de tolerar sufijos) se hace acá y cambia las dos puertas a la vez.
 */
class BuscadorDeLeadPorTelefono
{
    /**
     * Lead existente con ese teléfono (el más reciente si hay varios), o null.
     *
     * @param string $phone Teléfono en cualquier formato (el webhook lo pasa ya en E.164).
     *
     * @return Lead|null
     */
    public static function buscar(string $phone): ?Lead
    {
        $leads = Lead::whereNotNull('phone')->orderBy('id', 'desc')->get();
        foreach ($leads as $lead) {
            if (WhatsappNormalizer::phones_match((string) $lead->phone, $phone)) {
                return $lead;
            }
        }

        return null;
    }
}
