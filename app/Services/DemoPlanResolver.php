<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve QUÉ secciones y QUÉ clips del catálogo de la demo le tocan a un lead concreto
 * (misión 48, pieza 1 — el "motor de resolución del plan" del bloque C de
 * `contexto/demo_experiencia.md` §9).
 *
 * Hasta esta misión nadie evaluaba las condiciones del catálogo: `DemoCatalogoService` leía
 * `condicion` sólo para copiarla al array de la pantalla de multimedia del admin. Acá es donde
 * esas condiciones se evalúan por primera vez, contra las nueve respuestas del formulario.
 *
 * El plan que devuelve es un array plano y serializable: se congela en `leads.demo_plan`
 * ({@see DemoHitosService}, {@see \App\Http\Controllers\DemoExperienciaController}) y es el
 * contrato que consumen las misiones 49 (panel del roadmap), 50 (emisión desde la instancia) y
 * 51 (panel del lead). No se cambia su forma sin actualizar las tres.
 */
class DemoPlanResolver
{
    /**
     * Versión del catálogo contra la que sabe trabajar este resolver.
     *
     * Se estampa en el plan (`version_catalogo`) para que quien lo lea meses después sepa con qué
     * estructura se resolvió: `condiciones_secciones` y `evento_esperado` existen recién desde la
     * v2 (13/8/2026).
     */
    const VERSION_CATALOGO = 2;

    /**
     * Dominio de los campos NO booleanos que la forma `campo=valor` puede comparar.
     *
     * De las nueve respuestas del formulario, `tipo_precios` es la única que no es un booleano
     * (ver `LeadDemoFormMapper::RESPUESTAS_POR_DEFECTO`), y sus dos valores son los que el mapper
     * escribe y lee. Está acá y no derivado del catálogo a propósito: es lo que permite distinguir
     * "el lead contestó otra cosa" de "el catálogo tiene un typo", que sin esta lista son
     * indistinguibles — las dos darían simplemente `false`.
     */
    const VALORES_VALIDOS = [
        'tipo_precios' => ['unico', 'listas'],
    ];

    /**
     * Resuelve el plan de demo del lead.
     *
     * @param Lead $lead Lead del que se leen las respuestas efectivas del formulario.
     *
     * @return array<string, mixed>|null El plan, o `null` si el catálogo no está sincronizado.
     */
    public static function resolver(Lead $lead)
    {
        $catalogo = DemoCatalogoService::get();

        /* Catálogo sin sincronizar: se devuelve null y NO un plan vacío. Un plan vacío es
         * indistinguible de "este lead no usa ninguna funcionalidad" y generaría un roadmap de un
         * solo hito, que se vería como un dato legítimo. Quien llama decide qué hacer con el null
         * (hoy: no congelar nada y loguear). Misión 48. */
        if (empty($catalogo)) {
            return null;
        }

        // Las respuestas se leen SIEMPRE por acá: respuestas_efectivas() distingue "contestó que
        // no" de "no contestó" (sin formulario devuelve los defaults del catálogo, que en su
        // mayoría están encendidos, y no las columnas apagadas del lead). Esa distinción es justo
        // la que un resolver escrito de cero rompe.
        $respuestas = LeadDemoFormMapper::respuestas_efectivas($lead);

        // Acumulador de condiciones que no se pudieron evaluar. Viaja en el plan Y al log.
        $condiciones_invalidas = [];

        $orden_secciones = isset($catalogo['orden_secciones']) && is_array($catalogo['orden_secciones'])
            ? $catalogo['orden_secciones']
            : [];

        $clips_catalogo = isset($catalogo['clips']) && is_array($catalogo['clips'])
            ? $catalogo['clips']
            : [];

        $condiciones_secciones = isset($catalogo['condiciones_secciones']) && is_array($catalogo['condiciones_secciones'])
            ? $catalogo['condiciones_secciones']
            : [];

        $secciones        = [];
        $orden_seccion    = 0;
        $total_nucleo     = 0;
        $total_biblioteca = 0;

        foreach ($orden_secciones as $nombre_seccion) {
            $condicion_seccion = isset($condiciones_secciones[$nombre_seccion])
                ? $condiciones_secciones[$nombre_seccion]
                : null;

            $evaluacion_seccion = self::evaluar($condicion_seccion, $respuestas);

            if ($evaluacion_seccion === null) {
                // Condición de sección ilegible: la sección entera queda afuera y se declara.
                $condiciones_invalidas[] = [
                    'tipo'      => 'seccion',
                    'id'        => $nombre_seccion,
                    'condicion' => (string) $condicion_seccion,
                ];
                Log::warning('DemoPlanResolver: condición de sección inválida en el catálogo de la demo.', [
                    'seccion'   => $nombre_seccion,
                    'condicion' => $condicion_seccion,
                    'lead_id'   => $lead->id,
                ]);

                continue;
            }

            if ($evaluacion_seccion === false) {
                // La sección no aplica a este lead: no aparece, ni siquiera vacía.
                continue;
            }

            // Clips de esta sección que sobreviven a su propia condición.
            $clips_seccion  = [];
            $orden_clip     = 0;
            $tiene_nucleo   = false;

            foreach ($clips_catalogo as $clip) {
                if (! isset($clip['seccion']) || $clip['seccion'] !== $nombre_seccion) {
                    continue;
                }

                $condicion_clip  = isset($clip['condicion']) ? $clip['condicion'] : null;
                $evaluacion_clip = self::evaluar($condicion_clip, $respuestas);

                if ($evaluacion_clip === null) {
                    // Condición de clip ilegible: el clip queda afuera y se declara.
                    $condiciones_invalidas[] = [
                        'tipo'      => 'clip',
                        'id'        => isset($clip['id']) ? (string) $clip['id'] : '',
                        'condicion' => (string) $condicion_clip,
                    ];
                    Log::warning('DemoPlanResolver: condición de clip inválida en el catálogo de la demo.', [
                        'clip_id'   => isset($clip['id']) ? $clip['id'] : null,
                        'condicion' => $condicion_clip,
                        'lead_id'   => $lead->id,
                    ]);

                    continue;
                }

                if ($evaluacion_clip === false) {
                    continue;
                }

                $tipo_clip = isset($clip['tipo']) ? (string) $clip['tipo'] : 'nucleo';
                if ($tipo_clip === 'nucleo') {
                    $tiene_nucleo = true;
                }

                $orden_clip++;
                $clips_seccion[] = [
                    'id'              => isset($clip['id']) ? (string) $clip['id'] : '',
                    'orden'           => $orden_clip,
                    'titulo'          => isset($clip['titulo']) ? (string) $clip['titulo'] : '',
                    'tipo'            => $tipo_clip,
                    'practica'        => isset($clip['practica']) ? (bool) $clip['practica'] : false,
                    'evento_esperado' => isset($clip['evento_esperado']) && $clip['evento_esperado'] !== ''
                        ? (string) $clip['evento_esperado']
                        : null,
                ];
            }

            /* Una sección que quedó sin ningún clip de núcleo no aparece, aunque le hayan
             * sobrevivido clips de biblioteca. Los de biblioteca son material opcional (§1 de
             * `demo_catalogo.md`): una sección hecha sólo de eso no es una etapa de la demo y
             * generaría una entrada del roadmap sin ningún hito adentro. Misión 48. */
            if (! $tiene_nucleo) {
                continue;
            }

            foreach ($clips_seccion as $clip_incluido) {
                if ($clip_incluido['tipo'] === 'nucleo') {
                    $total_nucleo++;
                } else {
                    $total_biblioteca++;
                }
            }

            $orden_seccion++;
            $secciones[] = [
                'id'    => $nombre_seccion,
                'orden' => $orden_seccion,
                'clips' => $clips_seccion,
            ];
        }

        return [
            'version_catalogo'      => self::VERSION_CATALOGO,
            'resuelto_at'           => now()->format('Y-m-d H:i:s'),
            'respuestas'            => $respuestas,
            'secciones'             => $secciones,
            'condiciones_invalidas' => $condiciones_invalidas,
            'totales'               => [
                'secciones'         => count($secciones),
                'clips_nucleo'      => $total_nucleo,
                'clips_biblioteca'  => $total_biblioteca,
            ],
        ];
    }

    /**
     * Congela el plan sobre el lead, EN MEMORIA (no persiste).
     *
     * Es el único lugar del sistema donde el plan se escribe. Deja los atributos seteados para
     * que quien llama los guarde en el `save()` que ya iba a hacer — en el envío del formulario
     * eso importa: el plan y `demo_form_completado_at` tienen que entrar en la misma escritura,
     * o existe una ventana en la que el formulario figura completado sin plan.
     *
     * Idempotente: si el lead ya tiene el plan congelado no se re-resuelve, y se deja constancia
     * de qué respuestas cambiaron desde entonces.
     *
     * @param Lead $lead Lead con las respuestas ya aplicadas en memoria.
     *
     * @return bool `true` si en esta llamada se congeló un plan nuevo.
     */
    public static function congelar_en_memoria(Lead $lead): bool
    {
        /* Guardia de dinámica, y va ACÁ y no en quien llama: éste es uno de los dos únicos lugares
         * donde el trabajo de la misión 48 escribe algo sobre un lead (el otro es
         * DemoHitosService::generar()). El endpoint del formulario es PÚBLICO y no mira la
         * dinámica —resuelve el lead por uuid y nada más—, así que una guardia que viviera del
         * lado del llamador dejaría entrar a un lead 'actual' por ese camino. Y es alcanzable de
         * verdad: el interruptor por lead (LeadController::set_demo_experiencia_json) existe para
         * pilotear la dinámica nueva con leads elegidos a mano, o sea que volver un lead de
         * 'nueva' a 'actual' es el rollback previsto — y ese lead ya tiene su link de la página
         * circulando. Misión 48. */
        if (! $lead->usa_experiencia_demo_nueva()) {
            return false;
        }

        /* El plan se congela UNA sola vez y no se recalcula al leer. Si se resolviera en cada
         * lectura, editar `demo_catalogo.json` —que se sincroniza a producción sin deploy— le
         * cambiaría el roadmap a un lead que está en el medio de la demo, y los hitos ya marcados
         * quedarían apuntando a clips que dejaron de existir. Decisión del bloque C de
         * `demo_experiencia.md` §9. Misión 48. */
        if ($lead->demo_plan_congelado_at !== null) {
            self::loguear_divergencia($lead);

            return false;
        }

        $plan = self::resolver($lead);

        if ($plan === null) {
            // Catálogo sin sincronizar. No se congela nada y la demo sigue su curso: que no haya
            // roadmap no puede impedir que la demo exista.
            Log::error('DemoPlanResolver: no se pudo congelar el plan de demo, el catálogo no está sincronizado.', [
                'lead_id' => $lead->id,
            ]);

            return false;
        }

        $lead->demo_plan              = $plan;
        $lead->demo_plan_congelado_at = now();

        return true;
    }

    /**
     * Deja constancia de la diferencia entre las respuestas actuales del lead y las que quedaron
     * congeladas dentro del plan.
     *
     * El lead puede reenviar el formulario y el endpoint lo permite (hace merge). El plan no se
     * re-resuelve, así que esta línea es lo único que después permite decidir si hace falta un
     * botón de re-resolver en el panel. No es un caso a arreglar en esta misión.
     *
     * @param Lead $lead
     */
    protected static function loguear_divergencia(Lead $lead)
    {
        $plan = $lead->demo_plan;

        $congeladas = is_array($plan) && isset($plan['respuestas']) && is_array($plan['respuestas'])
            ? $plan['respuestas']
            : [];

        $actuales = LeadDemoFormMapper::respuestas_efectivas($lead);

        $diferencias = [];
        foreach ($actuales as $campo => $valor) {
            $antes = array_key_exists($campo, $congeladas) ? $congeladas[$campo] : null;
            if ($antes !== $valor) {
                $diferencias[$campo] = ['congelado' => $antes, 'ahora' => $valor];
            }
        }

        if (empty($diferencias)) {
            return;
        }

        Log::info('DemoPlanResolver: el lead reenvió el formulario con respuestas distintas de las congeladas en su plan.', [
            'lead_id'     => $lead->id,
            'diferencias' => $diferencias,
        ]);
    }

    /**
     * Evalúa una condición del catálogo contra las respuestas del formulario.
     *
     * La gramática es la que el catálogo usa y ninguna más — tres formas:
     *
     *  - `campo`               → el booleano es true
     *  - `campo=valor`         → igualdad estricta contra el string
     *  - `a && b`              → las dos, partiendo por `&&`
     *
     * No hay `||`, no hay `!`, no hay paréntesis. A propósito no se escribe un evaluador de
     * expresiones genérico: la gramática chica se puede leer entera de un vistazo y no tiene
     * forma de interpretar mal algo que el catálogo no quiso decir.
     *
     * @param string|null          $condicion  Condición cruda del catálogo. Null o vacía = incluir.
     * @param array<string, mixed> $respuestas Las nueve respuestas efectivas del formulario.
     *
     * @return bool|null `true`/`false` según se cumpla, o `null` si la condición NO SE PUEDE
     *                   evaluar (operador desconocido, campo que no existe entre las respuestas).
     */
    protected static function evaluar($condicion, array $respuestas)
    {
        // Sin condición: siempre incluido. Vale para null, cadena vacía y cadena de espacios.
        if ($condicion === null || (is_string($condicion) && trim($condicion) === '')) {
            return true;
        }

        // Una condición que no es un string ni null (un booleano, un número, un array) es un
        // error de tipo del catálogo, no una condición vacía. Sin este chequeo, `false` se
        // castearía a la cadena vacía y se leería como "sin condición".
        if (! is_string($condicion)) {
            return null;
        }

        $condicion = trim((string) $condicion);

        /* Una condición inválida se excluye Y se declara. Excluir en silencio deja al lead sin un
         * módulo que sí le interesaba y nadie se entera; incluir en silencio le muestra un módulo
         * que no usa y tampoco se entera. Lo que no puede pasar es que la falla sea invisible: el
         * catálogo lo edita Claude en el repo y se sincroniza a producción sin deploy, así que un
         * typo llega solo. Misión 48. */

        // Operadores que la gramática NO tiene. Si aparecen es un error del catálogo, no un caso
        // a interpretar: se devuelve null y quien llama excluye y declara.
        if (strpos($condicion, '||') !== false
            || strpos($condicion, '!') !== false
            || strpos($condicion, '(') !== false
            || strpos($condicion, ')') !== false
        ) {
            return null;
        }

        // Único operador binario admitido. Un `&` suelto (typo de `&&`) no parte nada y cae más
        // abajo como nombre de campo inexistente, que también devuelve null.
        $terminos = explode('&&', $condicion);

        foreach ($terminos as $termino) {
            $termino = trim($termino);

            if ($termino === '') {
                return null;
            }

            $posicion_igual = strpos($termino, '=');

            if ($posicion_igual === false) {
                // Forma `campo`: el booleano tiene que ser true.
                if (! array_key_exists($termino, $respuestas)) {
                    return null;
                }

                if ($respuestas[$termino] !== true) {
                    return false;
                }

                continue;
            }

            // Forma `campo=valor`: igualdad estricta contra el string.
            $campo = trim(substr($termino, 0, $posicion_igual));
            $valor = trim(substr($termino, $posicion_igual + 1));

            if ($campo === '' || $valor === '' || ! array_key_exists($campo, $respuestas)) {
                return null;
            }

            /* El lado DERECHO se valida igual que el izquierdo, y esto no es celo: sin esto,
             * cualquier typo del valor (`tipo_precios=lista`, `tipo_precios=Listas`, un `==` de
             * más) se resuelve a `false` en silencio — el clip desaparece del plan sin entrar en
             * `condiciones_invalidas` y sin una línea de log, que es exactamente el modo de falla
             * invisible que esta clase existe para impedir. Y el peor caso es `campo=false`: la
             * gramática no tiene `!`, así que negar un booleano por igualdad es lo primero que va
             * a intentar quien edite el catálogo, y da `false` SIEMPRE, valga lo que valga el
             * campo (`(string) false` es la cadena vacía, `(string) true` es "1"). Misión 48. */
            if (is_bool($respuestas[$campo])) {
                // Un booleano se pregunta con la forma `campo`, nunca con `campo=valor`.
                return null;
            }

            if (isset(self::VALORES_VALIDOS[$campo]) && ! in_array($valor, self::VALORES_VALIDOS[$campo], true)) {
                return null;
            }

            if ((string) $respuestas[$campo] !== $valor) {
                return false;
            }
        }

        return true;
    }
}
