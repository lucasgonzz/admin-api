<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Services\AsistenteWhatsappSettings;
use App\Services\SupportAiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración global de sugerencias IA automáticas en soporte WhatsApp.
 */
class SupportAiSettingsController extends Controller
{
    /**
     * Devuelve activación, las dos demoras, el régimen con el que nacen los tickets nuevos, y los
     * dos interruptores del canal de soporte por WhatsApp.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return response()->json($this->payload(), 200);
    }

    /**
     * Todo lo que el panel de Cuenta -> Soporte necesita para pintarse.
     *
     * 🔴 `tickets_habilitados` y `texto_desconectado` viven en `AsistenteWhatsappSettings` y
     * hasta el 13/10/2026 **no tenían endpoint**: el interruptor que decide si un mensaje de un
     * cliente abre ticket de soporte solo se podía mover con `tinker`. Eso es exactamente lo que
     * hizo que el canal quedara apagado desde el 16/9 sin que nadie lo notara: sin pantalla, nadie
     * mira el estado de un interruptor.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return array_merge(SupportAiSettings::to_array(), [
            'tickets_habilitados' => AsistenteWhatsappSettings::tickets_habilitados(),
            'texto_desconectado'  => AsistenteWhatsappSettings::texto_de_soporte_desconectado(),
        ]);
    }

    /**
     * Persiste activación, debounce previo a Claude, demora de envío automático, régimen inicial y
     * los dos interruptores del canal de soporte por WhatsApp.
     *
     * 🔴 **La respuesta devuelve SIEMPRE las dos claves nuevas**, hayan venido en el body o no.
     * El panel detecta "la API ignoró lo que mandé" por la AUSENCIA de la clave en la respuesta, y
     * ante eso revierte lo que el operador tipeó y le avisa. Una respuesta que se arma a mano con
     * solo los campos escritos haría que la pantalla denuncie un fallo que no existe; por eso sale
     * de `payload()`, que lee el estado real recién guardado.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'suggestions_enabled' => 'required|boolean',
            'suggestion_delay'    => 'nullable|integer|min:'.SupportAiSettings::SUGGESTION_DELAY_MIN_SECONDS.'|max:'.SupportAiSettings::SUGGESTION_DELAY_MAX_SECONDS,
            'auto_send_delay'     => 'nullable|integer|min:'.SupportAiSettings::AUTO_SEND_DELAY_MIN_SECONDS.'|max:'.SupportAiSettings::AUTO_SEND_DELAY_MAX_SECONDS,
            // `nullable` y no `required` a propósito, igual que las dos demoras: un build viejo del
            // SPA, cacheado en el navegador de un operador, todavía no manda este campo. Con
            // `required` no podría guardar ni las demoras; con `nullable` guarda lo suyo y el
            // régimen queda como estaba, en vez de darse vuelta sin que nadie lo haya pedido.
            'require_verification' => 'nullable|boolean',
            // Los dos del canal de soporte por WhatsApp. `nullable` por el mismo motivo que los de
            // arriba: un build viejo del SPA no los manda y no tiene por qué dejar de poder guardar
            // lo suyo. Lo que no viene, no se toca.
            'tickets_habilitados'  => 'nullable|boolean',
            'texto_desconectado'   => 'nullable|string|max:1000',
        ]);

        AdminSetting::set(
            SupportAiSettings::KEY_SUGGESTIONS_ENABLED,
            $validated['suggestions_enabled'] ? '1' : '0'
        );

        $suggestion_delay = SupportAiSettings::clamp_suggestion_delay(
            (int) ($validated['suggestion_delay'] ?? SupportAiSettings::get_suggestion_delay_seconds())
        );
        AdminSetting::set(
            SupportAiSettings::KEY_SUGGESTION_DELAY_SECONDS,
            (string) $suggestion_delay
        );

        $auto_send_delay = SupportAiSettings::clamp_auto_send_delay(
            (int) ($validated['auto_send_delay'] ?? SupportAiSettings::get_auto_send_delay_seconds())
        );
        AdminSetting::set(
            SupportAiSettings::KEY_AUTO_SEND_DELAY_SECONDS,
            (string) $auto_send_delay
        );

        $require_verification = array_key_exists('require_verification', $validated)
            && $validated['require_verification'] !== null
            ? (bool) $validated['require_verification']
            : SupportAiSettings::new_ticket_requires_verification();

        AdminSetting::set(
            SupportAiSettings::KEY_REQUIRE_VERIFICATION,
            $require_verification ? '1' : '0'
        );

        /* 🔴 Solo se escribe lo que el panel MANDÓ. Un `tickets_habilitados` ausente no es
         * "apagalo": es un build del SPA que no conoce el campo, y darlo vuelta por omisión
         * desconectaría el canal de soporte de toda la flota en un PUT que venía a cambiar una
         * demora. Mismo criterio que `require_verification`, y por la misma razón. */
        if (array_key_exists('tickets_habilitados', $validated) && $validated['tickets_habilitados'] !== null) {
            AdminSetting::set(
                AsistenteWhatsappSettings::KEY_TICKETS_ENABLED,
                $validated['tickets_habilitados'] ? '1' : '0'
            );
        }

        /* El texto sí acepta CADENA VACÍA, y vacía significa NO CONTESTAR NADA (ver
         * `AsistenteWhatsappSettings::texto_de_soporte_desconectado()`): borrar el texto es una
         * decisión válida que hay que poder tomar desde el panel.
         *
         * 🔴 Lo que distingue «borralo» de «no lo toques» es que la CLAVE VIAJE, no su valor: el
         * middleware global `ConvertEmptyStringsToNull` convierte el `""` que manda el panel en `null`
         * antes de llegar acá, así que mirar `!== null` hacía imposible borrar el texto (volvía a
         * aparecer y el panel decía «guardado»). Un build del SPA que no conoce el campo no manda la
         * clave, y ese sí no toca la fila. */
        if ($request->has('texto_desconectado')) {
            AdminSetting::set(
                AsistenteWhatsappSettings::KEY_DESCONECTADO_TEXTO,
                trim((string) $request->input('texto_desconectado'))
            );
        }

        return response()->json(array_merge($this->payload(), [
            'suggestions_enabled'  => (bool) $validated['suggestions_enabled'],
            'suggestion_delay'     => $suggestion_delay,
            'auto_send_delay'      => $auto_send_delay,
            'require_verification' => $require_verification,
        ]), 200);
    }
}
