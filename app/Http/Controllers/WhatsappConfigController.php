<?php

namespace App\Http\Controllers;

use App\Models\WhatsappConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API JSON para leer y editar las configuraciones de WhatsApp (Kapso).
 * Solo accesible para admins autenticados vía Sanctum.
 *
 * 🔴 **Desde el 13/10/2026 son DOS números, no uno** (misión whatsapp-dos-numeros): el `principal`
 * —institucional: leads, soporte, implementación— y el `asistente`, por el que cada dueño habla con
 * el asistente de IA de su propio sistema. Este controlador es el único lugar desde el que se
 * cargan: el `phone_number_id` de un número lo da Kapso y no se puede deducir de nada, así que sin
 * esta pantalla el número nuevo solo se podría crear con `tinker`.
 */
class WhatsappConfigController extends Controller
{
    /**
     * Devuelve las configuraciones activas, una por propósito.
     *
     * 🔴 **Cambia la forma de la respuesta**: antes era la fila plana de la única config, ahora es
     * `{principal, asistente}` y cualquiera de las dos puede ser null. Es compatible porque este
     * endpoint lo consume **un solo** cliente —el panel del admin-spa, que se mergea después— y
     * ese panel tolera las dos formas: con la vieja muestra solo la tarjeta principal.
     *
     * `asistente` en null es el estado normal hasta que se cargue el número nuevo, y el panel lo
     * pinta como la tarjeta vacía con el botón de crearla.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'principal' => WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_PRINCIPAL),
            'asistente' => WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_ASISTENTE),
        ]);
    }

    /**
     * Actualiza la configuración de un propósito; crea el registro si no existe.
     *
     * 🔴 **Sin `proposito` en el body escribe la PRINCIPAL**, que es exactamente lo que hacía antes
     * de la misión. Eso es lo que hace que un build viejo del SPA —o un `curl` de un runbook
     * anterior— siga funcionando igual en vez de crear una fila huérfana.
     *
     * 🔴 **Y con un `proposito` que no sea ninguno de los dos, corta con 422 y no escribe nada.**
     * Hasta esta misión el `update()` ignoraba el campo y escribía siempre `getActive()`: un PUT con
     * `proposito: 'asistente'` contra esa versión **pisaba en silencio las credenciales del número
     * de leads**. Un typo en ese campo no puede volver a terminar escribiendo el número
     * institucional, así que el propósito se valida SOLO y PRIMERO, antes de armar el resto de las
     * reglas y antes de resolver ninguna fila.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        /* Primera validación, con una sola regla: de acá no se sale con un propósito inventado.
         * Está separada de la de abajo porque el propósito DECIDE las otras reglas (ver el
         * `webhook_secret`), y una regla que se elige a partir de un valor sin validar es la forma
         * exacta en que un typo acaba escribiendo la fila equivocada. */
        $request->validate([
            'proposito' => 'sometimes|string|in:' . WhatsappConfig::PROPOSITO_PRINCIPAL
                . ',' . WhatsappConfig::PROPOSITO_ASISTENTE,
        ]);

        $proposito = $this->proposito_pedido($request);

        $reglas = [
            'kapso_api_key'   => 'required|string|max:255',
            'phone_number_id' => 'required|string|max:255',
            'test_mode'       => 'sometimes|boolean',
            /* Los tres datos que identifican al número para una persona. `nullable` y no `required`
             * porque el número anda igual sin ellos —son para que el panel y los logs puedan decir
             * cuál es cuál—, y porque el panel manda los siete campos siempre: cuando el operador
             * no los completa llegan como cadena vacía, que tiene que pasar sin 422. */
            'display_phone'   => 'nullable|string|max:255',
            'nombre'          => 'nullable|string|max:255',
            'waba_id'         => 'nullable|string|max:255',
        ];

        /*
         * 🔴 El `webhook_secret` es `required` para la PRINCIPAL y `nullable` para el asistente, y la
         * asimetría es deliberada.
         *
         * Para la principal se deja como estaba: es la fila que existe desde el principio, ya tiene
         * su secreto, y permitir vaciarlo sería permitir romper la entrada de leads y soporte desde
         * el panel. Desde esta misión el webhook además **rechaza** una config con el secreto vacío
         * (antes `hash_hmac(..., null)` firmaba con clave `""` y validaba cualquier firma), así que
         * borrarlo dejaría el número mudo.
         *
         * Para el asistente tiene que poder quedar vacío en el ALTA, porque el secreto no lo elige
         * una persona: lo genera `whatsapp:registrar-webhook`, que es el que lo da de alta en Kapso
         * y después lo guarda en la fila. Exigirlo acá obligaría a inventar un valor de descarte que
         * el comando pisa dos minutos más tarde — y un secreto inventado que alguien deja puesto es
         * peor que ninguno, porque parece configurado. Mientras no esté, el webhook de ese número
         * rechaza todo: falla cerrado, que es el lado correcto.
         */
        $reglas['webhook_secret'] = $proposito === WhatsappConfig::PROPOSITO_PRINCIPAL
            ? 'required|string|max:255'
            : 'nullable|string|max:255';

        $validated = $request->validate($reglas);

        /* 🔴 La fila se resuelve POR PROPÓSITO y nunca por `getActive()`. Es el bug que cerró esta
         * misión: `getActive()` devuelve siempre la principal, así que guardar el asistente
         * sobrescribía el número de leads. Y cuando la fila no existe se crea una NUEVA con su
         * propósito puesto: en ningún camino de este método se toca la fila del otro número. */
        $config = WhatsappConfig::porProposito($proposito);

        if (! $config) {
            $config = new WhatsappConfig();
            $config->proposito = $proposito;
            $config->is_active = true;
        }

        $config->kapso_api_key   = $validated['kapso_api_key'];
        $config->phone_number_id = $validated['phone_number_id'];

        /* 🔴 El secreto se escribe solo si vino Y no está vacío. El panel manda los siete campos
         * siempre, así que un `webhook_secret: ""` significa "no lo toqué" y jamás "borralo": un PUT
         * que venía a cambiar el nombre del número no puede dejar sin secreto al webhook que el
         * comando acababa de registrar en Kapso. Vaciarlo a propósito es una intervención a mano en
         * la base, y tiene que seguir siéndolo. */
        if ($request->has('webhook_secret')) {
            $secreto = trim((string) $request->input('webhook_secret'));
            if ($secreto !== '') {
                $config->webhook_secret = $secreto;
            }
        }

        foreach (['display_phone', 'nombre', 'waba_id'] as $campo) {
            if (! $request->has($campo)) {
                continue;
            }

            // Cadena vacía se guarda como null: "sin dato" es null y no '', así el panel no tiene
            // que distinguir dos formas de lo mismo. Acá sí se acepta vaciar, porque estos tres son
            // etiquetas y borrar una no rompe nada.
            $valor = trim((string) $request->input($campo));
            $config->{$campo} = $valor !== '' ? $valor : null;
        }

        // Solo actualizamos test_mode si el panel lo envió, para no resetearlo en updates parciales.
        if ($request->has('test_mode')) {
            $config->test_mode = $request->boolean('test_mode');
        }

        $config->save();

        return response()->json($config);
    }

    /**
     * Propósito que el body pide escribir.
     *
     * Solo se llama DESPUÉS de la validación del propósito, así que acá el valor es uno de los dos
     * válidos o no vino. Ausente = principal, que es lo que hacía este endpoint antes de la misión.
     *
     * @param Request $request
     *
     * @return string
     */
    private function proposito_pedido(Request $request): string
    {
        $proposito = trim((string) $request->input('proposito', ''));

        return $proposito === WhatsappConfig::PROPOSITO_ASISTENTE
            ? WhatsappConfig::PROPOSITO_ASISTENTE
            : WhatsappConfig::PROPOSITO_PRINCIPAL;
    }
}
