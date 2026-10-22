<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Lead;

/**
 * El ÚNICO criterio para decidir a qué lead pertenece un teléfono (misión
 * demo-autogestionada-landing, 22/10/2026, D4 del plan).
 *
 * Hasta esta misión vivía como `WhatsappWebhookController::find_lead_by_phone()` (privado). Ahora lo
 * usan DOS puertas: el webhook, cuando el lead escribe, y la reserva de la landing, cuando el visitante
 * deja su WhatsApp.
 *
 * 🔴 POR QUÉ hay dos búsquedas y no una (segunda vuelta de la misión, revisión independiente):
 *
 *   - {@see buscar_exacto()}: el MISMO número, completo (`WhatsappNormalizer::normalize` de los dos
 *     lados), el más reciente si hay varios. Es el criterio de `Lead::resolver_por_clave_de_experiencia`,
 *     y por el mismo motivo: el número lo tipea cualquiera. La landing usa SOLO esta: con la tolerancia
 *     por sufijo, un visitante de Mendoza que tipea 261 555-1234 caía en la ficha de un lead de Córdoba
 *     con 351 555-1234 (`phones_match` compara los últimos 8 dígitos), se le escribía el turno a la ficha
 *     ajena y se lo redirigía a la página de otra persona. Sin exacto, la landing crea un lead nuevo.
 *   - {@see buscar()}: primero el exacto y, solo si no hay, el de siempre (`phones_match`, que tolera
 *     prefijos y sufijos). Es el del webhook: ahí el número es el del propio remitente, y la tolerancia
 *     existe por las fichas viejas guardadas en formatos raros. Con el exacto primero, si un lead nuevo
 *     de la landing comparte sufijo con uno viejo, el mensaje de cada uno cae en SU ficha (antes, el más
 *     reciente se llevaba los mensajes del otro).
 *
 * Así la landing y el webhook siguen eligiendo el mismo lead cuando el número es el mismo, que es lo
 * que importa: si eligieran leads distintos, el lead reservaría en una ficha y escribiría en otra.
 */
class BuscadorDeLeadPorTelefono
{
    /**
     * Lead con ese teléfono: el exacto (el más reciente si hay varios) o, si no hay ninguno, el más
     * reciente que coincide con la tolerancia de `phones_match`. Null si ninguno.
     *
     * @param string $phone Teléfono en cualquier formato (el webhook lo pasa ya en E.164).
     *
     * @return Lead|null
     */
    public static function buscar(string $phone): ?Lead
    {
        $exacto = self::buscar_exacto($phone);
        if ($exacto !== null) {
            return $exacto;
        }

        $leads = Lead::whereNotNull('phone')->orderBy('id', 'desc')->get();
        foreach ($leads as $lead) {
            if (WhatsappNormalizer::phones_match((string) $lead->phone, $phone)) {
                return $lead;
            }
        }

        return null;
    }

    /**
     * Lead con EXACTAMENTE ese número (normalizado de los dos lados), el más reciente si hay varios, o
     * null. Mismo recorrido que `Lead::resolver_por_clave_de_experiencia`: candidatos por los últimos 8
     * dígitos en SQL y la igualdad completa en PHP.
     *
     * @param string $phone
     *
     * @return Lead|null
     */
    public static function buscar_exacto(string $phone): ?Lead
    {
        $digitos = preg_replace('/\D+/', '', $phone);
        if (! is_string($digitos) || strlen($digitos) < 8) {
            return null;
        }

        $buscado = WhatsappNormalizer::normalize($digitos);
        if ($buscado === '') {
            return null;
        }

        /* Sólo dígitos del lado de la base, para que "+54 9 351 123-4567" también sea candidato. */
        $telefono_sin_formato = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";

        $candidatos = Lead::query()
            ->whereNotNull('phone')
            ->whereRaw($telefono_sin_formato . ' LIKE ?', ['%' . substr($digitos, -8) . '%'])
            ->orderBy('id', 'desc')
            ->get(['id', 'phone']);

        foreach ($candidatos as $candidato) {
            if (WhatsappNormalizer::normalize((string) $candidato->phone) === $buscado) {
                return Lead::query()->find($candidato->id);
            }
        }

        return null;
    }
}
