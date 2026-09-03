<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\LeadPipelineStatus;
use App\Models\ProtocolEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alta y edición en lote de las entradas del PROTOCOLO DE VENTAS (`protocol_entries`), desde
 * afuera, por Claude.
 *
 * Molde: {@see ClaudeFollowupTemplatesController} para el alta idempotente en lote y
 * {@see ClaudeLeadsPipelineController} para los frenos.
 *
 * 🔴 LA CLAVE DE IDEMPOTENCIA ES `titulo`, Y ESTÁ MEDIDA, NO ELEGIDA
 * -------------------------------------------------------------------
 * El plan de esta misión dejaba abiertas dos opciones: la terna
 * `(categoria, estado_aplicable, followup_numero)` si esa terna identificaba una entrada, y
 * `titulo` si no. Medido el 3/9/2026 sobre `admin_testing_s4` con `ProtocolEntriesSeeder`
 * sembrado, que es el protocolo real:
 *
 *     filas = 46 · titulos_distintos = 46 · ternas_distintas = 23
 *
 * O sea que la terna NO identifica una entrada ni de cerca: hay once entradas con
 * `(situacion_frecuente, null, null)`, cinco con `(etapa_principal, demo_realizada, null)`, cuatro
 * con `(etapa_principal, contactado, null)` — las cuatro "Etapa 2A/2B/2C/2D", que son cuatro
 * situaciones distintas del mismo estado y por diseño comparten los tres campos. Usar la terna
 * como clave haría que cargar "Etapa 2B" PISARA "Etapa 2A" en silencio, que es exactamente el modo
 * de fallo que una clave natural tiene que impedir.
 *
 * `titulo` sí es único hoy (46/46) y es lo que el ABM del panel muestra como identidad de la
 * entrada (`ProtocolEntryProperties`: es uno de los campos con `show => true`). ⚠️ NO hay índice
 * único en la base que lo garantice: si algún día aparecen dos entradas con el mismo título, este
 * endpoint actualiza la primera y deja la segunda intacta. Se decidió no agregar el índice porque
 * esta misión no lleva migraciones; si el protocolo crece hasta necesitarlo, ése es el arreglo.
 *
 * 🔴 ES ESTRICTAMENTE ADITIVO: nunca borra una entrada que no vino en el payload. Para sacar una de
 * circulación se manda con `activa => false`, que es reversible y queda escrito.
 *
 * 🔴 `activa` ES OBLIGATORIO Y EXPLÍCITO: una entrada nueva NO se activa sola. Medido el 3/9/2026,
 * los únicos que tocan esta tabla son el ABM del panel (`ProtocolEntryController`) y
 * `LeadSuggestionSendService::record_setter_correction()`, que crea entradas con `activa => false`
 * justamente porque son correcciones pendientes de revisión. Un default `true` acá le pisaría el
 * criterio a ese camino y metería texto sin revisar en el protocolo que se le muestra al setter.
 *
 * 🔴 EL HALLAZGO DE ESTA MISIÓN, Y ES LO PRIMERO QUE TIENE QUE LEER EL QUE VENGA A "COMPLETAR LA
 * SIMETRÍA": `agent_identities` Y `ai_system_prompts` NO TIENEN ENDPOINT DE ESCRITURA A PROPÓSITO
 * ------------------------------------------------------------------------------------------------
 * Las tres tablas parecen la misma familia —"lo que el agente sabe"— y por eso da la impresión de
 * que falta abrirle un POST a las otras dos. No falta: sería un daño. `AgentPromptSyncService` las
 * PISA CADA 10 MINUTOS desde el repo de conocimiento `lucasgonzz/claude-comerciocity`
 * (`agentes/lead/identidad.md` → `AgentIdentity`; `agentes/lead/instrucciones_operativas.md` →
 * `AiSystemPrompt`). Un POST sobre ellas sería una escritura que desaparece sola sin que nada la
 * denuncie, que es la peor clase de endpoint que se puede construir: el que la usa cree que
 * cambió el comportamiento del agente y diez minutos después vuelve todo atrás. El camino de
 * escritura de esas dos es commitear al repo de conocimiento, que es lo que ya hacemos; las dos se
 * agregan a la LECTURA (`GET claude/query`) para poder verificar qué quedó sincronizado.
 *
 * `protocol_entries` es distinta y por eso ES la que se abre: no la sincroniza nadie desde GitHub,
 * tiene ABM propio en el panel y `LeadSuggestionSendService` le escribe filas.
 *
 * 🔴 LOS FRENOS, en el orden en que corren: tope de lote, `categoria` de la enumeración real,
 * `estado_aplicable` que sea un slug del pipeline (o vacío = "todos"), `titulo` no repetido dentro
 * del lote, `dry_run` por defecto con el diff entrada por entrada, y `confirm_count` +
 * `confirm_token` del conjunto simulado cuando `dry_run` es false.
 */
class ClaudeProtocolEntriesController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Tope duro de entradas por llamada. El protocolo entero son 46 filas: un lote más grande que
     * esto es un error de armado.
     */
    const MAX_BATCH = 100;

    /**
     * Las categorías reales. Fuente: `ProtocolEntryProperties::all()` (el select del ABM del panel)
     * y un `SELECT DISTINCT categoria` sobre `admin_testing_s4` con el protocolo sembrado, que
     * devuelve exactamente estas tres (etapa_principal 19, seguimiento 16, situacion_frecuente 11).
     * Una categoría inventada no la muestra ningún filtro del panel: la entrada queda invisible.
     */
    const CATEGORIAS = ['etapa_principal', 'seguimiento', 'situacion_frecuente'];

    /**
     * Alta/edición idempotente de un lote de entradas del protocolo.
     *
     * @param Request $request Body: entradas[] (req), dry_run, confirm_count, confirm_token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'entradas'                    => 'required|array|min:1',
            'entradas.*.titulo'           => 'required|string|max:255',
            'entradas.*.categoria'        => 'required|string|max:40',
            'entradas.*.estado_aplicable' => 'nullable|string|max:40',
            'entradas.*.followup_numero'  => 'nullable|integer|min:1|max:255',
            /* `descripcion` y `mensaje_template` son NOT NULL en la tabla y son el contenido de la
               entrada: sin ellos no hay entrada. Al EDITAR se pueden omitir para no reescribirlos,
               y el chequeo de obligatorios al crear está más abajo, contra la fila existente. */
            'entradas.*.descripcion'      => 'nullable|string|max:20000',
            'entradas.*.mensaje_template' => 'nullable|string|max:20000',
            'entradas.*.notas_setter'     => 'nullable|string|max:20000',
            /* 🔴 `required` y no `nullable`: una entrada no se activa sola. Ver el docblock. */
            'entradas.*.activa'           => 'required|boolean',
            'dry_run'                     => 'nullable|boolean',
            'confirm_count'               => 'nullable|integer|min:0',
            'confirm_token'               => 'nullable|string|max:64',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $filas = array_values(array_filter((array) $request->input('entradas', []), 'is_array'));

        /* --- Freno 1: tope duro por llamada, antes de tocar la base. --- */
        if (count($filas) > self::MAX_BATCH) {
            return $this->error_422(
                'El lote no puede superar las ' . self::MAX_BATCH . ' entradas por llamada y llegaron '
                    . count($filas) . '. No se escribió nada.',
                ['max_batch' => self::MAX_BATCH, 'recibidas' => count($filas)]
            );
        }

        /* --- Freno 2: enumeraciones y títulos, TODOS juntos y antes de resolver nada. Un valor
               inventado aborta el lote entero: es un error de armado. --- */
        $slugs_validos = LeadPipelineStatus::all_slugs();
        $titulos       = [];

        foreach ($filas as $indice => $fila) {
            $titulo = trim((string) (isset($fila['titulo']) ? $fila['titulo'] : ''));
            if ($titulo === '') {
                return $this->error_422(
                    'La entrada #' . ($indice + 1) . ' vino con el `titulo` vacío, y el título es la clave de '
                        . 'idempotencia de este endpoint. No se escribió nada.'
                );
            }

            if (in_array($titulo, $titulos, true)) {
                return $this->error_422(
                    'El título "' . $titulo . '" viene repetido en el lote. Como la carga es idempotente por '
                        . 'título, dos filas con el mismo se pisarían entre ellas sin que se sepa cuál ganó. '
                        . 'No se escribió nada.'
                );
            }
            $titulos[] = $titulo;

            $categoria = trim((string) (isset($fila['categoria']) ? $fila['categoria'] : ''));
            if (! in_array($categoria, self::CATEGORIAS, true)) {
                return $this->error_422(
                    'La categoría "' . $categoria . '" no existe: el ABM del protocolo sólo muestra las tres '
                        . 'declaradas, así que una entrada con otra categoría queda invisible en el panel. '
                        . 'No se escribió nada.',
                    ['categorias_validas' => self::CATEGORIAS, 'titulo' => $titulo]
                );
            }

            $estado = $this->texto_o_null(isset($fila['estado_aplicable']) ? $fila['estado_aplicable'] : null);
            if ($estado !== null && ! in_array($estado, $slugs_validos, true)) {
                return $this->error_422(
                    'El estado_aplicable "' . $estado . '" no es un slug del pipeline, así que ningún lead puede '
                        . 'tener nunca ese status y la entrada quedaría cargada sin aplicarle a nadie. Dejalo '
                        . 'vacío si aplica a todos los estados. No se escribió nada.',
                    ['estados_validos' => $slugs_validos, 'titulo' => $titulo]
                );
            }
        }

        /* Una sola consulta para todas las entradas del lote: nada de N+1. */
        $existentes = ProtocolEntry::query()->whereIn('titulo', $titulos)->get()->keyBy('titulo');

        $cambios    = [];
        $sin_cambio = [];

        foreach ($filas as $indice => $fila) {
            $titulo    = $titulos[$indice];
            $existente = $existentes->get($titulo);

            /* --- Freno 3: al CREAR, el contenido es obligatorio (las dos columnas son NOT NULL y
                   una entrada sin descripción ni mensaje no es una entrada). --- */
            if ($existente === null) {
                $faltantes = [];
                foreach (['descripcion', 'mensaje_template'] as $obligatorio) {
                    if ($this->texto_o_null(isset($fila[$obligatorio]) ? $fila[$obligatorio] : null) === null) {
                        $faltantes[] = $obligatorio;
                    }
                }

                if (! empty($faltantes)) {
                    return $this->error_422(
                        'La entrada "' . $titulo . '" no existe todavía, así que ' . implode(' y ', $faltantes)
                            . ' ' . (count($faltantes) === 1 ? 'es obligatorio' : 'son obligatorios')
                            . ' para crearla. No se escribió nada.',
                        ['titulo' => $titulo, 'faltantes' => $faltantes]
                    );
                }
            }

            $propuesto = $this->propuesto_para($fila, $existente);
            $actual    = $this->actual_de($existente);

            $diff = [];
            foreach ($propuesto as $columna => $valor_nuevo) {
                $valor_viejo = array_key_exists($columna, $actual) ? $actual[$columna] : null;
                if ($valor_viejo === $valor_nuevo) {
                    continue;
                }
                $diff[$columna] = ['actual' => $valor_viejo, 'propuesto' => $valor_nuevo];
            }

            $fila_resultado = [
                'titulo'    => $titulo,
                'accion'    => $existente === null ? 'crear' : 'actualizar',
                'actual'    => $actual,
                'propuesto' => $propuesto,
                'diff'      => $diff,
            ];

            if ($existente !== null && empty($diff)) {
                $sin_cambio[] = $fila_resultado;
                continue;
            }

            $cambios[] = $fila_resultado;
        }

        $cambiarian    = count($cambios);
        $confirm_token = $this->calcular_confirm_token($cambios);

        /* --- Freno 4: simulación, que es el default. --- */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            return response()->json([
                'dry_run'       => true,
                'cambiarian'    => $cambiarian,
                'sin_cambio'    => $sin_cambio,
                'cambios'       => $cambios,
                'confirm_token' => $confirm_token,
                'nota'          => 'Simulación: no se escribió ninguna entrada del protocolo. Para aplicar de '
                    . 'verdad, repetí la misma llamada con dry_run=false, confirm_count=' . $cambiarian
                    . ' y confirm_token=' . $confirm_token . '.',
            ], 200);
        }

        /* --- Freno 5: confirmación explícita del número exacto y del conjunto exacto. --- */
        if (! $request->filled('confirm_count')) {
            return $this->error_422(
                'confirm_count es obligatorio cuando dry_run es false. No se escribió nada.',
                ['cambiarian' => $cambiarian]
            );
        }

        if ((int) $request->input('confirm_count') !== $cambiarian) {
            return $this->error_422(
                'confirm_count (' . (int) $request->input('confirm_count') . ') no coincide con los ' . $cambiarian
                    . ' cambios reales. No se escribió nada: volvé a correr la simulación.',
                ['cambiarian' => $cambiarian]
            );
        }

        $token_recibido = trim((string) $request->input('confirm_token', ''));
        if ($token_recibido === '') {
            return $this->error_422(
                'confirm_token es obligatorio cuando dry_run es false. Corré primero la simulación.',
                ['confirm_token' => $confirm_token]
            );
        }

        if (! hash_equals($confirm_token, $token_recibido)) {
            return $this->error_422(
                'confirm_token no corresponde a este conjunto: alguna entrada cambió respecto de la simulación. '
                    . 'No se escribió nada.',
                ['confirm_token_esperado' => $confirm_token]
            );
        }

        $resultados = ['creadas' => 0, 'actualizadas' => 0, 'sin_cambio' => count($sin_cambio)];

        /* El lote entra o no entra: medio protocolo cargado es peor que ninguno, porque el que
           llamó no tiene cómo saber dónde se cortó. */
        DB::transaction(function () use ($cambios, &$resultados) {
            foreach ($cambios as $cambio) {
                $existente = ProtocolEntry::query()
                    ->where('titulo', $cambio['titulo'])
                    ->lockForUpdate()
                    ->first();

                if ($existente !== null) {
                    $existente->update($cambio['propuesto']);
                    $resultados['actualizadas']++;
                    continue;
                }

                ProtocolEntry::create(array_merge(['titulo' => $cambio['titulo']], $cambio['propuesto']));
                $resultados['creadas']++;
            }
        });

        $entradas = ProtocolEntry::query()
            ->whereIn('titulo', $titulos)
            ->orderBy('categoria')
            ->orderBy('id')
            ->get();

        /* Auditoría. 🔴 Nunca se loguea la clave del header: solo qué se escribió. */
        Log::channel('daily')->info('ClaudeProtocolEntriesController: protocolo de ventas actualizado.', [
            'resultados' => $resultados,
            'titulos'    => $titulos,
        ]);

        return response()->json([
            'dry_run'    => false,
            'resultados' => $resultados,
            'entradas'   => $entradas,
        ], 200);
    }

    /**
     * Las columnas tal cual quedarían después de aplicar una fila del payload.
     *
     * En una edición, un campo opcional ausente NO borra lo que ya estaba. `activa` siempre viene
     * (es `required`), así que no tiene rama de arrastre.
     *
     * @param array<string, mixed> $fila      Payload de una entrada, ya validado.
     * @param ProtocolEntry|null   $existente Fila que ya estaba, si la había.
     *
     * @return array<string, mixed>
     */
    protected function propuesto_para(array $fila, $existente): array
    {
        $propuesto = [
            'categoria' => trim((string) $fila['categoria']),
            'activa'    => filter_var($fila['activa'], FILTER_VALIDATE_BOOLEAN),
        ];

        /* `estado_aplicable` y `followup_numero` son nullable en la tabla, y ahí un null explícito
           SÍ es un dato: "aplica a todos los estados" / "no es un seguimiento numerado". Por eso se
           distingue "vino null" de "no vino", que es lo único que arrastra el valor viejo. */
        if (array_key_exists('estado_aplicable', $fila)) {
            $propuesto['estado_aplicable'] = $this->texto_o_null($fila['estado_aplicable']);
        } else {
            $propuesto['estado_aplicable'] = $existente !== null ? $existente->estado_aplicable : null;
        }

        if (array_key_exists('followup_numero', $fila)) {
            $propuesto['followup_numero'] = $this->entero_o_null($fila['followup_numero']);
        } else {
            $propuesto['followup_numero'] = $existente !== null && $existente->followup_numero !== null
                ? (int) $existente->followup_numero
                : null;
        }

        foreach (['descripcion', 'mensaje_template', 'notas_setter'] as $campo) {
            $valor = $this->texto_o_null(isset($fila[$campo]) ? $fila[$campo] : null);
            if ($valor !== null) {
                $propuesto[$campo] = $valor;
                continue;
            }

            /* `notas_setter` es nullable: un null explícito la vacía. `descripcion` y
               `mensaje_template` son NOT NULL, así que ausentes arrastran lo que había (y al crear
               ya vinieron obligadas por el freno 3). */
            if ($campo === 'notas_setter' && array_key_exists($campo, $fila)) {
                $propuesto[$campo] = null;
                continue;
            }

            $propuesto[$campo] = $existente !== null ? $existente->{$campo} : null;
        }

        return $propuesto;
    }

    /**
     * Las columnas que la entrada tiene HOY, con la misma forma que devuelve `propuesto_para()`
     * para que el diff se pueda hacer campo contra campo sin adaptadores de por medio.
     *
     * @param ProtocolEntry|null $existente Fila que ya estaba, si la había.
     *
     * @return array<string, mixed>
     */
    protected function actual_de($existente): array
    {
        if ($existente === null) {
            return [
                'categoria'        => null,
                'activa'           => null,
                'estado_aplicable' => null,
                'followup_numero'  => null,
                'descripcion'      => null,
                'mensaje_template' => null,
                'notas_setter'     => null,
            ];
        }

        return [
            'categoria'        => $existente->categoria,
            'activa'           => (bool) $existente->activa,
            'estado_aplicable' => $existente->estado_aplicable,
            'followup_numero'  => $existente->followup_numero !== null ? (int) $existente->followup_numero : null,
            'descripcion'      => $existente->descripcion,
            'mensaje_template' => $existente->mensaje_template,
            'notas_setter'     => $existente->notas_setter,
        ];
    }

    /**
     * Huella determinista del conjunto simulado: ata la confirmación a los títulos exactos y a los
     * valores exactos que se revisaron. Misma idea (y mismo motivo) que
     * `ClaudeLeadsPipelineController::calcular_confirm_token()`.
     *
     * @param array<int, array<string, mixed>> $cambios Lista ya resuelta.
     *
     * @return string
     */
    protected function calcular_confirm_token(array $cambios): string
    {
        $partes = [];
        foreach ($cambios as $cambio) {
            $partes[] = $cambio['titulo'] . ':' . json_encode($cambio['propuesto']);
        }
        sort($partes);

        return substr(hash('sha256', 'protocolo|' . implode('|', $partes)), 0, 32);
    }
}
