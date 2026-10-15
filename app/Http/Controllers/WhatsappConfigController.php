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
        /* `delProposito()` y no `porProposito()`: el panel tiene que ver también la fila APAGADA, que es la
         * que se vuelve a prender desde acá. */
        $principal = WhatsappConfig::delProposito(WhatsappConfig::PROPOSITO_PRINCIPAL);
        $asistente = WhatsappConfig::delProposito(WhatsappConfig::PROPOSITO_ASISTENTE);

        return response()->json([
            'principal' => $principal,
            'asistente' => $asistente,
            /* Clave APARTE y aditiva: dice si cada número está listo y qué le falta, con la misma
             * regla que usa el resto del admin para decidir por qué número sale cada cosa
             * (`WhatsappConfig::canalListo()`). Así el panel no repite esa regla y no se desincroniza
             * de ella. Solo trae NOMBRES de campos: nunca un valor guardado. `activo` en `faltan`
             * significa que la fila existe pero está apagada. */
            'estado' => [
                'principal' => $this->estado_de($principal),
                'asistente' => $this->estado_de($asistente),
            ],
        ]);
    }

    /**
     * `{listo, faltan}` de una fila, o «falta la fila».
     *
     * @param WhatsappConfig|null $config
     *
     * @return array{listo: bool, faltan: array<int, string>}
     */
    private function estado_de(?WhatsappConfig $config): array
    {
        if ($config === null) {
            return ['listo' => false, 'faltan' => ['fila']];
        }

        $faltan = $config->loQueLeFaltaParaAtender();
        if (! $config->is_active) {
            array_unshift($faltan, 'activo');
        }

        return ['listo' => $faltan === [], 'faltan' => $faltan];
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

        /* 🔴 `usar_clave_del_principal` (solo para el asistente): los dos números cuelgan del MISMO
         * proyecto de Kapso y se envían con la misma API key, así que el panel no tiene por qué pedirle a
         * una persona que pegue una clave que ya está guardada en la fila del principal. La copia se
         * hace ACÁ, del lado del servidor: la clave no viaja al navegador para volver a subir, y un PUT
         * con la casilla tildada no puede terminar con la clave de otro proyecto. Con una
         * `kapso_api_key` explícita en el body gana esa, porque es el caso del número que de verdad
         * vive en otro proyecto. */
        $copiar_clave_del_principal = $proposito === WhatsappConfig::PROPOSITO_ASISTENTE
            && $request->boolean('usar_clave_del_principal')
            && trim((string) $request->input('kapso_api_key', '')) === '';

        $reglas = [
            'kapso_api_key'   => $copiar_clave_del_principal ? 'nullable|string|max:255' : 'required|string|max:255',
            'phone_number_id' => 'required|string|max:255',
            'test_mode'       => 'sometimes|boolean',
            /* Los tres datos que identifican al número para una persona. `nullable` y no `required`
             * porque el número anda igual sin ellos —son para que el panel y los logs puedan decir
             * cuál es cuál—, y porque el panel manda los siete campos siempre: cuando el operador
             * no los completa llegan como cadena vacía, que tiene que pasar sin 422. */
            /* 🔴 El número tal como se lee es OBLIGATORIO para el asistente (y opcional para el
             * principal): es lo que se le dice a un dueño que le escribe al número equivocado, y sin él
             * el número no se considera listo (`WhatsappConfig::canalListo()`). Con ocho dígitos como
             * mínimo, la misma vara que usa esa regla, para que el panel no deje guardar algo que
             * después el modelo va a rechazar sin decir por qué. */
            'display_phone'   => $proposito === WhatsappConfig::PROPOSITO_ASISTENTE
                ? ['required', 'string', 'max:255', 'regex:/(?:\D*\d){8,}/']
                : 'nullable|string|max:255',
            'nombre'          => 'nullable|string|max:255',
            'waba_id'         => 'nullable|string|max:255',
            'usar_clave_del_principal' => 'sometimes|boolean',
            /* El interruptor de «número activo». Apagar el del asistente es la forma de volver atrás el
             * segundo número desde el panel (todo vuelve a salir y entrar por el principal). */
            'is_active'       => 'sometimes|boolean',
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

        /* 🔴 El número de leads y clientes no se apaga desde acá: sin él no entra ni sale ningún mensaje
         * del admin, y un tilde mal puesto dejaría mudo todo el pipeline comercial. */
        if ($proposito === WhatsappConfig::PROPOSITO_PRINCIPAL
            && $request->has('is_active')
            && ! $request->boolean('is_active')) {
            return response()->json([
                'message' => 'El número de leads y clientes no se puede apagar desde el panel.',
                'errors'  => ['is_active' => ['El número de leads y clientes no se puede apagar desde el panel.']],
            ], 422);
        }

        /* 🔴 El `phone_number_id` identifica al número: es lo que decide, en cada mensaje entrante, por cuál
         * de las dos filas se valida la firma y a qué árbol de enrutado va. Si las dos filas lo tuvieran
         * igual (un pegado del número equivocado), el webhook resolvería siempre la primera, y los
         * comandos de alta de Kapso actuarían sobre el número de leads y clientes. */
        $repetido = WhatsappConfig::where('phone_number_id', trim((string) $validated['phone_number_id']))
            ->where('proposito', '!=', $proposito)
            ->exists();

        if ($repetido) {
            return response()->json([
                'message' => 'Ese phone number ID ya lo usa el otro número.',
                'errors'  => ['phone_number_id' => ['Ese phone number ID ya está cargado en el otro número: cada tarjeta tiene el suyo.']],
            ], 422);
        }

        /* 🔴 La fila se resuelve POR PROPÓSITO y nunca por `getActive()`. Es el bug que cerró esta
         * misión: `getActive()` devuelve siempre la principal, así que guardar el asistente
         * sobrescribía el número de leads. Y cuando la fila no existe se crea una NUEVA con su
         * propósito puesto: en ningún camino de este método se toca la fila del otro número. */
        $config = WhatsappConfig::delProposito($proposito);

        if (! $config) {
            $config = new WhatsappConfig();
            $config->proposito = $proposito;
            $config->is_active = true;
        }

        if ($request->has('is_active')) {
            $config->is_active = $request->boolean('is_active');
        }

        if ($copiar_clave_del_principal) {
            $principal = WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_PRINCIPAL);
            $clave     = $principal !== null ? trim((string) $principal->kapso_api_key) : '';

            if ($clave === '') {
                /* No se inventa una clave ni se deja la fila sin ella: sin clave el número no envía. */
                return response()->json([
                    'message' => 'El número principal no tiene clave de Kapso cargada, así que no hay una para compartir.',
                    'errors'  => ['kapso_api_key' => ['Cargá la clave del número principal o pegá una para este número.']],
                ], 422);
            }

            $config->kapso_api_key = $clave;
        } else {
            $config->kapso_api_key = $validated['kapso_api_key'];
        }

        $config->phone_number_id = $validated['phone_number_id'];

        /* 🔴 El secreto se escribe solo si vino Y no está vacío. El panel manda los siete campos
         * siempre, así que un `webhook_secret: ""` significa "no lo toqué" y jamás "borralo": un PUT
         * que venía a cambiar el nombre del número no puede dejar sin secreto al webhook que el
         * comando acababa de registrar en Kapso. Vaciarlo a propósito es una intervención a mano en
         * la base, y tiene que seguir siéndolo. */
        /* 🔴 El secreto del ASISTENTE no se escribe desde acá, venga lo que venga en el body. Lo escribe
         * `whatsapp:registrar-webhook` en la misma corrida en que lo da de alta en Kapso, y es lo que
         * hace que el número pase a «listo»: si una persona lo pudiera pegar a mano (el del principal, por
         * ejemplo, que tiene a la vista en la tarjeta de arriba), el número se daría por listo sin
         * webhook registrado y toda la flota empezaría a recibir los avisos por un número que no recibe.
         * Además, un panel abierto antes de que corra el comando volvería a subir el secreto viejo y
         * pisaría el nuevo. Para el principal sigue como siempre. */
        if ($proposito === WhatsappConfig::PROPOSITO_PRINCIPAL && $request->has('webhook_secret')) {
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
