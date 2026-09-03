<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\FollowupRule;
use App\Models\Lead;
use App\Models\LeadPipelineStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alta y edición en lote de la CADENCIA de los seguimientos automáticos de lead
 * (`followup_rules`), desde afuera, por Claude.
 *
 * Molde: {@see ClaudeFollowupTemplatesController}, que hace el alta idempotente en lote de las
 * PLANTILLAS. Son las dos mitades del mismo motor y no hay que confundirlas: la plantilla es QUÉ
 * texto se manda; la regla es CUÁNDO y CUÁNTAS VECES. Hasta esta misión el bloque `claude/*` podía
 * cargar plantillas y no tenía ninguna ruta para la cadencia, así que "este estado manda un
 * seguimiento cada 24 horas hasta tres veces" solo se podía tocar entrando a la base.
 *
 * 🔴 IDEMPOTENTE POR `estado`, Y NO ES UNA ELECCIÓN DE ESTILO
 * -----------------------------------------------------------
 * `estado` es la clave real de esta tabla, verificado el 3/9/2026 en las tres puntas:
 *   - `LeadFollowupService` la levanta con `FollowupRule::where('activa', true)->get()->keyBy('estado')`
 *     en `process_all_active_leads()`, `process_single_lead()` y `force_followup_now()`. En un
 *     `keyBy` dos reglas activas para el mismo estado se pisan EN SILENCIO y gana la última que
 *     salga del SELECT, que no tiene orden garantizado.
 *   - `database/seeders/FollowupRulesSeeder.php` usa `updateOrCreate(['estado' => ...])`.
 *   - el esquema tiene `estado` como índice ÚNICO (`followup_rules.estado`, UNI).
 * O sea que un alta que no fuera idempotente por `estado` ni siquiera llegaría a duplicar: le
 * explotaría el índice único en la cara al segundo intento.
 *
 * 🔴 ES ESTRICTAMENTE ADITIVO: nunca borra una regla que no vino en el payload. Mismo criterio que
 * ClaudeFollowupTemplatesController y ClaudeClientTemplatesController. Para apagar una regla se
 * manda con `activa => false`, que es reversible y queda escrito; borrarla no.
 *
 * 🔴 LA ASIMETRÍA DELIBERADA CON `POST claude/followup-templates`: ACÁ UN `estado` QUE NO ES DEL
 * PIPELINE ES 422, ALLÁ ES VÁLIDO A PROPÓSITO
 * ------------------------------------------------------------------------------------------------
 * En las PLANTILLAS, un `estado` que no es un slug real del pipeline es la convención de las
 * plantillas `manual_*` del chequeo diario (`manual_coordinacion`, `manual_closer`,
 * `manual_nutricion`): justamente porque `LeadFollowupService::find_template_for()` busca
 * `where('estado', $lead->status)` y `$lead->status` siempre es un slug real, esas plantillas NO
 * las puede disparar sola el cron y quedan disponibles solo para envío manual. Ahí el estado
 * inexistente es la garantía.
 *
 * En las REGLAS es exactamente al revés. Una regla es lo que HACE que el cron actúe: si su `estado`
 * no existe en el pipeline, ningún lead va a tener nunca ese `status`, el `keyBy('estado')` nunca
 * la va a encontrar y la regla queda cargada en la tabla PARECIENDO que anda. Nadie recibe nada y
 * nada lo denuncia. Por eso acá el estado se valida contra `LeadPipelineStatus` y un slug inventado
 * es 422 con la lista de los válidos en el cuerpo.
 *
 * 🔴 LOS FRENOS, Y POR QUÉ ACÁ HACEN FALTA MÁS QUE EN LAS PLANTILLAS. Una plantilla no le manda
 * nada a nadie hasta que alguien la elige; una regla gobierna a TODOS los leads de ese estado, de
 * una y sin que nadie apriete nada. En el orden en que corren:
 *   1. `estado` tiene que ser un slug real del pipeline (arriba).
 *   2. `horas_espera` mínimo 1 y `max_followups` máximo 10. Un `horas_espera = 0` convierte el cron
 *      de cada dos horas en un martilleo: cada corrida vería el tiempo cumplido y volvería a
 *      mandar. Un `max_followups` alto hace lo mismo pero más lento.
 *   3. `dry_run` en `true` por defecto. La simulación devuelve, por cada regla, el valor ACTUAL, el
 *      PROPUESTO y CUÁNTOS LEADS hay hoy en ese estado — que es el número que dice a cuánta gente
 *      real le cambia la cadencia.
 *   4. Con `dry_run=false`: `confirm_count` exacto y `confirm_token` del conjunto simulado.
 */
class ClaudeFollowupRulesController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Tope duro de reglas por llamada. El pipeline tiene quince estados: un lote más grande que
     * esto es un error de armado, no una carga legítima.
     */
    const MAX_BATCH = 50;

    /**
     * Mínimo de `horas_espera`. Ver el freno 2 del docblock de la clase.
     */
    const HORAS_ESPERA_MIN = 1;

    /**
     * Máximo de `max_followups`. Ver el freno 2 del docblock de la clase.
     */
    const MAX_FOLLOWUPS_MAX = 10;

    /**
     * Estados que `LeadFollowupService::process_all_active_leads()` saltea siempre, sea cual sea la
     * regla: son los cierres y la pausa. Una regla para uno de estos es inerte, y la simulación lo
     * dice en vez de dejar que alguien la cargue creyendo que hace algo.
     */
    const ESTADOS_QUE_EL_CRON_NUNCA_PROCESA = ['cerrado_ganado', 'cerrado_perdido', 'en_pausa'];

    /**
     * Alta/edición idempotente de un lote de reglas de cadencia.
     *
     * @param Request $request Body: reglas[] (req), dry_run, confirm_count, confirm_token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'reglas'                 => 'required|array|min:1',
            'reglas.*.estado'        => 'required|string|max:40',
            /* Los dos son `nullable` en la validación y OBLIGATORIOS AL CREAR, chequeado más abajo
               contra la fila existente: sin esto no habría forma de apagar una regla ya cargada sin
               reenviarle la cadencia entera, y el que quiere apagarla justamente no la sabe de
               memoria. En una edición, un campo ausente deja lo que la fila ya tenía. */
            'reglas.*.horas_espera'  => 'nullable|integer|min:' . self::HORAS_ESPERA_MIN,
            'reglas.*.max_followups' => 'nullable|integer|min:1|max:' . self::MAX_FOLLOWUPS_MAX,
            'reglas.*.activa'        => 'nullable|boolean',
            'reglas.*.descripcion'   => 'nullable|string|max:2000',
            'dry_run'                => 'nullable|boolean',
            'confirm_count'          => 'nullable|integer|min:0',
            'confirm_token'          => 'nullable|string|max:64',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $filas = array_values(array_filter((array) $request->input('reglas', []), 'is_array'));

        /* --- Freno 0: tope duro por llamada, antes de tocar la base. --- */
        if (count($filas) > self::MAX_BATCH) {
            return $this->error_422(
                'El lote no puede superar las ' . self::MAX_BATCH . ' reglas por llamada y llegaron '
                    . count($filas) . '. No se escribió nada.',
                ['max_batch' => self::MAX_BATCH, 'recibidas' => count($filas)]
            );
        }

        /* --- Freno 1: los estados, TODOS juntos y antes de resolver nada. Un slug inventado aborta
               el lote entero: es un error de armado, no una regla salteable. --- */
        $slugs_validos = LeadPipelineStatus::all_slugs();
        $estados       = [];

        foreach ($filas as $indice => $fila) {
            $estado = LeadPipelineStatus::normalize_slug((string) (isset($fila['estado']) ? $fila['estado'] : ''));

            if ($estado === '') {
                return $this->error_422(
                    'La regla #' . ($indice + 1) . ' vino con un `estado` vacío o sin caracteres válidos. '
                        . 'No se escribió nada.',
                    ['estados_validos' => $slugs_validos]
                );
            }

            if (! in_array($estado, $slugs_validos, true)) {
                return $this->error_422(
                    'El estado "' . $estado . '" no es un slug del pipeline, así que ningún lead puede tener '
                        . 'nunca ese status: la regla quedaría cargada pareciendo que anda y el cron de '
                        . 'seguimientos jamás la levantaría. No se escribió nada.',
                    [
                        'estados_validos' => $slugs_validos,
                        'nota'            => 'Ojo con la asimetría: en POST claude/followup-templates un estado '
                            . 'que NO es del pipeline sí es válido a propósito (las plantillas manual_* del '
                            . 'chequeo diario). Acá no, y el motivo está en el docblock de '
                            . 'ClaudeFollowupRulesController.',
                    ]
                );
            }

            if (in_array($estado, $estados, true)) {
                return $this->error_422(
                    'El estado "' . $estado . '" viene repetido en el lote. Como la regla es idempotente por '
                        . 'estado, dos filas para el mismo se pisarían entre ellas sin que se sepa cuál ganó. '
                        . 'No se escribió nada.'
                );
            }

            $estados[] = $estado;
        }

        /* Una sola consulta para todas las reglas del lote: nada de N+1. */
        $existentes = FollowupRule::query()->whereIn('estado', $estados)->get()->keyBy('estado');

        /* Y una sola para los leads: cuántos hay hoy en cada estado del lote. Es el número que
           convierte "cambio una regla" en "le cambio la cadencia a 34 personas". */
        $leads_por_estado = Lead::query()
            ->whereIn('status', $estados)
            ->groupBy('status')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'status');

        $cambios = [];
        $sin_cambio = [];

        foreach ($filas as $indice => $fila) {
            $estado    = $estados[$indice];
            $existente = $existentes->get($estado);

            /* --- Freno 2: al CREAR, la cadencia entera es obligatoria. Una regla nueva a la que le
                   falte `horas_espera` o `max_followups` no se puede escribir (las columnas son NOT
                   NULL y sin default) y, más importante, sería una cadencia a medias. --- */
            if ($existente === null) {
                $faltantes = [];
                if ($this->entero_o_null(isset($fila['horas_espera']) ? $fila['horas_espera'] : null) === null) {
                    $faltantes[] = 'horas_espera';
                }
                if ($this->entero_o_null(isset($fila['max_followups']) ? $fila['max_followups'] : null) === null) {
                    $faltantes[] = 'max_followups';
                }

                if (! empty($faltantes)) {
                    return $this->error_422(
                        'La regla del estado "' . $estado . '" no existe todavía, así que ' . implode(' y ', $faltantes)
                            . ' ' . (count($faltantes) === 1 ? 'es obligatorio' : 'son obligatorios')
                            . ' para crearla: una cadencia a medias no es una cadencia. No se escribió nada.',
                        ['estado' => $estado, 'faltantes' => $faltantes]
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
                'estado'               => $estado,
                'accion'               => $existente === null ? 'crear' : 'actualizar',
                'leads_en_ese_estado'  => (int) ($leads_por_estado->get($estado) ?: 0),
                'actual'               => $actual,
                'propuesto'            => $propuesto,
                'diff'                 => $diff,
            ];

            if (in_array($estado, self::ESTADOS_QUE_EL_CRON_NUNCA_PROCESA, true)) {
                $fila_resultado['advertencia'] = 'LeadFollowupService::process_all_active_leads() saltea siempre los '
                    . 'leads en "' . $estado . '", así que esta regla es inerte: el cron nunca la va a aplicar.';
            }

            if ($existente !== null && empty($diff)) {
                $sin_cambio[] = $fila_resultado;
                continue;
            }

            $cambios[] = $fila_resultado;
        }

        $cambiarian    = count($cambios);
        $confirm_token = $this->calcular_confirm_token($cambios);

        /* --- Freno 3: simulación, que es el default. --- */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            return response()->json([
                'dry_run'       => true,
                'cambiarian'    => $cambiarian,
                'sin_cambio'    => $sin_cambio,
                'cambios'       => $cambios,
                'confirm_token' => $confirm_token,
                'nota'          => 'Simulación: no se escribió ninguna regla. REVISÁ `leads_en_ese_estado` de cada '
                    . 'fila antes de seguir — ése es el número de gente real a la que le cambia la cadencia. Para '
                    . 'aplicar de verdad, repetí la misma llamada con dry_run=false, confirm_count=' . $cambiarian
                    . ' y confirm_token=' . $confirm_token . '.',
            ], 200);
        }

        /* --- Freno 4: confirmación explícita del número exacto y del conjunto exacto. --- */
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
                'confirm_token no corresponde a este conjunto: alguna regla cambió respecto de la simulación. '
                    . 'No se escribió nada.',
                ['confirm_token_esperado' => $confirm_token]
            );
        }

        $resultados = ['creadas' => 0, 'actualizadas' => 0, 'sin_cambio' => count($sin_cambio)];

        /* El lote entra o no entra: si una fila explota a mitad, no puede quedar media cadencia
           cargada, porque el que llamó no tiene cómo saber dónde se cortó. */
        DB::transaction(function () use ($cambios, &$resultados) {
            foreach ($cambios as $cambio) {
                $existente = FollowupRule::query()
                    ->where('estado', $cambio['estado'])
                    ->lockForUpdate()
                    ->first();

                if ($existente !== null) {
                    $existente->update($cambio['propuesto']);
                    $resultados['actualizadas']++;
                    continue;
                }

                FollowupRule::create(array_merge(['estado' => $cambio['estado']], $cambio['propuesto']));
                $resultados['creadas']++;
            }
        });

        $reglas = FollowupRule::query()
            ->whereIn('estado', $estados)
            ->orderBy('estado')
            ->get();

        /* Auditoría. 🔴 Nunca se loguea la clave del header: solo qué se escribió. */
        Log::channel('daily')->info('ClaudeFollowupRulesController: cadencia de seguimientos actualizada.', [
            'resultados' => $resultados,
            'estados'    => $estados,
        ]);

        return response()->json([
            'dry_run'    => false,
            'resultados' => $resultados,
            'reglas'     => $reglas,
        ], 200);
    }

    /**
     * Las columnas tal cual quedarían después de aplicar una fila del payload.
     *
     * En una edición, un campo opcional ausente NO borra lo que ya estaba: se arrastra el valor de
     * la fila existente. En un alta, `horas_espera` y `max_followups` ya vinieron obligados por el
     * freno 2, y `activa` cae al default `true` (una regla que se carga es una regla que se quiere
     * usar; para cargarla apagada se manda `activa => false` explícito).
     *
     * @param array<string, mixed> $fila      Payload de una regla, ya validado.
     * @param FollowupRule|null    $existente Fila que ya estaba, si la había.
     *
     * @return array<string, mixed>
     */
    protected function propuesto_para(array $fila, $existente): array
    {
        $horas = $this->entero_o_null(isset($fila['horas_espera']) ? $fila['horas_espera'] : null);
        $max   = $this->entero_o_null(isset($fila['max_followups']) ? $fila['max_followups'] : null);

        $propuesto = [
            'horas_espera'  => $horas !== null ? $horas : ($existente !== null ? (int) $existente->horas_espera : null),
            'max_followups' => $max !== null ? $max : ($existente !== null ? (int) $existente->max_followups : null),
        ];

        if (array_key_exists('activa', $fila) && $fila['activa'] !== null) {
            $propuesto['activa'] = filter_var($fila['activa'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $propuesto['activa'] = $existente !== null ? (bool) $existente->activa : true;
        }

        if (array_key_exists('descripcion', $fila) && $fila['descripcion'] !== null) {
            $propuesto['descripcion'] = trim((string) $fila['descripcion']);
        } else {
            $propuesto['descripcion'] = $existente !== null ? $existente->descripcion : null;
        }

        return $propuesto;
    }

    /**
     * Las columnas que la regla tiene HOY, con la misma forma que devuelve `propuesto_para()` para
     * que el diff se pueda hacer campo contra campo sin adaptadores de por medio.
     *
     * @param FollowupRule|null $existente Fila que ya estaba, si la había.
     *
     * @return array<string, mixed>
     */
    protected function actual_de($existente): array
    {
        if ($existente === null) {
            return [
                'horas_espera'  => null,
                'max_followups' => null,
                'activa'        => null,
                'descripcion'   => null,
            ];
        }

        return [
            'horas_espera'  => (int) $existente->horas_espera,
            'max_followups' => (int) $existente->max_followups,
            'activa'        => (bool) $existente->activa,
            'descripcion'   => $existente->descripcion,
        ];
    }

    /**
     * Huella determinista del conjunto simulado: ata la confirmación a los estados exactos y a los
     * valores exactos que se revisaron. Cambiar una hora de espera de una sola regla da otro token.
     * Misma idea (y mismo motivo) que `ClaudeLeadsPipelineController::calcular_confirm_token()`.
     *
     * @param array<int, array<string, mixed>> $cambios Lista ya resuelta.
     *
     * @return string
     */
    protected function calcular_confirm_token(array $cambios): string
    {
        $partes = [];
        foreach ($cambios as $cambio) {
            $partes[] = $cambio['estado'] . ':' . json_encode($cambio['propuesto']);
        }
        sort($partes);

        return substr(hash('sha256', 'cadencia|' . implode('|', $partes)), 0, 32);
    }
}
