<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de interpretación semántica de respuestas del cliente.
 *
 * Reemplaza la lógica de palabras clave hardcodeadas del flujo de implementación
 * por una interpretación en lenguaje natural usando Claude (Anthropic).
 *
 * Expone un único método público `interpret()` que recibe el identificador de
 * la pregunta, el texto enviado al cliente y su respuesta, y devuelve un array
 * con la clave `value` conteniendo la interpretación normalizada.
 *
 * En caso de error de red o respuesta inválida, devuelve siempre `['value' => null]`
 * sin lanzar excepciones, registrando el problema en el log diario.
 */
class ImplementationAiInterpreter
{
    /**
     * Prompt del sistema base: contexto del dominio para todas las interpretaciones.
     *
     * @var string
     */
    private const SYSTEM_PROMPT_BASE =
        'Sos un intérprete de respuestas de clientes para un sistema de implementación de software empresarial argentino. '
        . 'El cliente se comunica por WhatsApp en español rioplatense informal. '
        . 'Respondé ÚNICAMENTE con un JSON válido de una sola línea, sin texto adicional, sin comillas extras, sin markdown. '
        . 'Interpretá la intención real del mensaje, no solo las palabras exactas.';

    /**
     * Contexto específico por clave de pregunta.
     *
     * Describe al modelo qué significa cada posible valor de retorno para la pregunta dada.
     *
     * @var array<string, string>
     */
    private const QUESTION_CONTEXTS = [
        'use_price_lists' =>
            'Se le preguntó al cliente si va a manejar un único precio de venta o varias listas de precios con distintos márgenes. '
            . 'true = quiere listas de precios / múltiples precios. false = precio único. null = no responde a esa pregunta.',

        'use_deposits' =>
            'Se le preguntó si va a dividir el stock en depósitos o sucursales. '
            . 'true = sí usa múltiples depósitos/sucursales. false = un único lugar. null = no responde.',

        'ask_amount_in_vender' =>
            'Se le preguntó si prefiere que el sistema le pregunte la cantidad al cargar una venta, o que agregue 1 unidad automáticamente. '
            . 'true = quiere que le pregunte. false = quiere que agregue 1 automáticamente. null = no responde.',

        'default_cuenta_corriente' =>
            'Se le preguntó si quiere que las ventas queden en cuenta corriente automáticamente. '
            . 'true = sí, automático. false = no, manual. null = no responde.',

        'dollar_prices' =>
            'Se le preguntó si maneja precios en dólares además de pesos. '
            . 'true = sí, maneja dólares. false = solo pesos. null = no responde.',

        'employees_confirm' =>
            'Se le preguntó si terminó de pasar la lista de empleados. '
            . 'true = terminó. false = no terminó. null = no responde (probablemente mandó otro empleado).',

        'skip_videocall' =>
            'Se le preguntó disponibilidad para una videollamada de cierre. '
            . 'true = no quiere hacer la videollamada / no la necesita. false = sí tiene disponibilidad o quiere hacerla. null = respuesta ambigua.',

        'yes_no' =>
            'El cliente respondió a una pregunta genérica de sí o no. '
            . 'true = sí. false = no. null = respuesta ambigua o no relacionada.',

        'is_self' =>
            'Se le preguntó quién se va a encargar de una tarea. '
            . 'true = el cliente dice que él mismo se va a encargar (usa "yo", "yo mismo", "me encargo", etc. o su propio nombre). '
            . 'false = lo va a hacer otra persona.',

        'file_category' =>
            'El cliente está enviando archivos Excel. Inferir de qué categoría es el archivo basándose en el contexto del mensaje y el nombre del archivo. '
            . '"articles" = artículos o productos. "clients" = clientes. "suppliers" = proveedores. null = no se puede inferir.',

        'no_tengo_category' =>
            'El cliente indica que no tiene cierto archivo. Inferir a qué categoría se refiere. '
            . '"articles" = artículos o productos. "clients" = clientes. "suppliers" = proveedores. null = no se puede inferir.',

        'employee_choice' =>
            'Se le presentó al cliente una lista numerada de empleados y se le preguntó quién se encarga de enviar los archivos. '
            . 'Devolvé el índice base-1 del empleado elegido (int), 0 si eligió "yo mismo" o equivalente, null si la respuesta es ambigua o no identificable.',

        'social_networks' =>
            'Se le preguntó al cliente si tiene Instagram o Facebook del negocio para mostrarlos en su tienda online. '
            . 'Si el cliente manda uno o más links o usuarios, extraé instagram y facebook por separado (la URL completa o el usuario tal como lo escribió; null si no mencionó esa red). '
            . 'Si el cliente dice que no tiene redes o que prefiere no ponerlas, devolvé none = true. '
            . 'El valor debe ser un objeto: {"instagram": string|null, "facebook": string|null, "none": true|false}. '
            . 'Devolvé null (en lugar del objeto) solo si la respuesta es totalmente ambigua o no relacionada.',

        'domain_confirmation' =>
            'Se le sugirió al cliente un dominio para su tienda online y se le preguntó si le parece bien o prefiere otro. '
            . 'Si confirma la sugerencia, devolvé el dominio sugerido. Si propone otro nombre, devolvé el dominio elegido (agregale .com.ar si no incluyó extensión). '
            . 'Devolvé el dominio como string en minúsculas, sin espacios ni "http". null si la respuesta es ambigua.',

        'online_price_type' =>
            'Se le preguntó al cliente quién puede ver los precios de su tienda online. '
            . '1 = cualquier persona que entre. 2 = solo usuarios registrados. 3 = solo clientes ya cargados en el sistema. '
            . 'Devolvé 1, 2 o 3 según la opción elegida, o null si es ambiguo.',

        'colors_change' =>
            'Se le mostró al cliente una paleta de colores sugerida para su tienda y se le preguntó si le gusta o quiere cambiar algo. '
            . 'true = el cliente acepta los colores tal cual. false = el cliente pide cambios. null = ambiguo.',
    ];

    /**
     * Schema de respuesta JSON esperado por clave de pregunta.
     *
     * Define el contrato de salida que Claude debe respetar.
     *
     * @var array<string, string>
     */
    private const RESPONSE_SCHEMAS = [
        // Preguntas booleanas con posible null.
        'use_price_lists'         => '{"value": true|false|null}',
        'use_deposits'            => '{"value": true|false|null}',
        'ask_amount_in_vender'    => '{"value": true|false|null}',
        'default_cuenta_corriente'=> '{"value": true|false|null}',
        'dollar_prices'           => '{"value": true|false|null}',
        'employees_confirm'       => '{"value": true|false|null}',
        'skip_videocall'          => '{"value": true|false|null}',
        'yes_no'                  => '{"value": true|false|null}',
        // Booleana sin null.
        'is_self'                 => '{"value": true|false}',
        // Categorías de archivos.
        'file_category'           => '{"value": "articles"|"clients"|"suppliers"|null}',
        'no_tengo_category'       => '{"value": "articles"|"clients"|"suppliers"|null}',
        // Elección de empleado de un listado numerado.
        'employee_choice'         => '{"value": 0|1|2|3|4|5|null}',
        // Redes sociales: objeto con instagram/facebook/none, o null si ambiguo.
        'social_networks'         => '{"value": {"instagram": string|null, "facebook": string|null, "none": true|false}|null}',
        // Confirmación o elección de dominio para la tienda online.
        'domain_confirmation'     => '{"value": "dominio.com.ar"|null}',
        // Tipo de visibilidad de precios de la tienda online.
        'online_price_type'       => '{"value": 1|2|3|null}',
        // Aceptación o pedido de cambio de la paleta de colores.
        'colors_change'           => '{"value": true|false|null}',
    ];

    /**
     * Interpreta la respuesta del cliente para una pregunta dada usando Claude.
     *
     * @param string $question_key    Identificador de la pregunta (ver QUESTION_CONTEXTS).
     * @param string $question_text   Texto exacto que se le envió al cliente.
     * @param string $client_response Texto de la respuesta del cliente.
     *
     * @return array{'value': mixed} Array con la interpretación normalizada.
     *                               Devuelve ['value' => null] ante cualquier error.
     */
    public function interpret(string $question_key, string $question_text, string $client_response): array
    {
        // Respuesta por defecto ante cualquier error o caso no interpretable.
        $fallback = ['value' => null];

        // Validar que el body no esté vacío.
        $client_response = trim($client_response);
        if ($client_response === '') {
            return $fallback;
        }

        // Leer configuración de Anthropic desde config/services.php.
        $api_key = (string) config('services.anthropic.api_key');
        if ($api_key === '') {
            Log::channel('daily')->warning('ImplementationAiInterpreter: api_key de Anthropic no configurada.', [
                'question_key' => $question_key,
            ]);
            return $fallback;
        }

        // Modelo a usar: configurable, con fallback al modelo por defecto.
        $model = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');

        // Contexto específico para esta pregunta.
        $question_context = self::QUESTION_CONTEXTS[$question_key] ?? 'Interpretá la respuesta del cliente.';

        // Schema JSON esperado en la respuesta.
        $response_schema = self::RESPONSE_SCHEMAS[$question_key] ?? '{"value": true|false|null}';

        // Construir el prompt del usuario combinando contexto, pregunta enviada y respuesta recibida.
        $user_prompt = $question_context . "\n"
            . "Schema de respuesta esperado: {$response_schema}\n"
            . "Pregunta que se le hizo al cliente: {$question_text}\n"
            . "Respuesta del cliente: {$client_response}";

        try {
            // Configurar cliente HTTP con las cabeceras requeridas por la API de Anthropic.
            $http = Http::withHeaders([
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(10);

            // Manejo de SSL: configurable para entornos sin CA bundle válido.
            $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
            $ca_bundle  = config('services.anthropic.ca_bundle');

            if (! $verify_ssl) {
                $http = $http->withoutVerifying();
            } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
                $http = $http->withOptions(['verify' => $ca_bundle]);
            }

            // Realizar la llamada a la API de Claude.
            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 256,
                'system'     => self::SYSTEM_PROMPT_BASE,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => $user_prompt,
                    ],
                ],
            ]);

            // Verificar que la respuesta HTTP sea exitosa.
            if (! $response->successful()) {
                Log::channel('daily')->warning('ImplementationAiInterpreter: error HTTP de Anthropic.', [
                    'question_key' => $question_key,
                    'status'       => $response->status(),
                ]);
                return $fallback;
            }

            // Extraer el texto generado por Claude de los bloques de contenido.
            $raw_text       = '';
            $content_blocks = $response->json('content', []);

            if (is_array($content_blocks)) {
                foreach ($content_blocks as $block) {
                    if (is_array($block) && isset($block['text'])) {
                        $raw_text .= (string) $block['text'];
                    }
                }
            }

            $raw_text = trim($raw_text);

            if ($raw_text === '') {
                Log::channel('daily')->warning('ImplementationAiInterpreter: respuesta vacía de Anthropic.', [
                    'question_key' => $question_key,
                ]);
                return $fallback;
            }

            // Extraer el bloque JSON aunque Claude agregue texto alrededor.
            // Se usa una coincidencia greedy (primer "{" hasta el último "}") para
            // soportar tanto objetos planos como objetos anidados (ej: social_networks).
            if (preg_match('/\{.*\}/s', $raw_text, $matches)) {
                $raw_text = $matches[0];
            }

            // Decodificar el JSON devuelto por Claude.
            $decoded = json_decode($raw_text, true);

            if (! is_array($decoded) || ! array_key_exists('value', $decoded)) {
                Log::channel('daily')->warning('ImplementationAiInterpreter: JSON inválido o sin clave "value".', [
                    'question_key' => $question_key,
                    'raw_text'     => $raw_text,
                ]);
                return $fallback;
            }

            // Devolver el array con el valor interpretado.
            return ['value' => $decoded['value']];

        } catch (\Throwable $exception) {
            Log::channel('daily')->warning('ImplementationAiInterpreter: excepción al llamar a Anthropic.', [
                'question_key' => $question_key,
                'message'      => $exception->getMessage(),
            ]);
            return $fallback;
        }
    }
}
