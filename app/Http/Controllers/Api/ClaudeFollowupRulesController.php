<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AppTime;
use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\FollowupRule;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use Carbon\Carbon;
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
 * ✅ Y ese índice único es también lo que hace que acá NO exista el agujero de concurrencia que sí
 * tiene {@see ClaudeProtocolEntriesController} (dos altas simultáneas del mismo título crean dos
 * filas porque `protocol_entries.titulo` no tiene índice único). Otra diferencia con el protocolo:
 * `estado` pasa por `LeadPipelineStatus::normalize_slug()`, que lo deja en `[a-z0-9_]`, así que la
 * colación `utf8mb4_unicode_ci` de la columna no puede sorprender a nadie — no hay mayúsculas ni
 * acentos que plegar. En el protocolo sí, y allá hay todo un método explicando por qué.
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
 *   2. `activa` OBLIGATORIO Y EXPLÍCITO. Ver {@see propuesto_para()}: hasta el 3/9/2026 caía a un
 *      default `true` y esa asimetría con el protocolo estaba al revés del riesgo.
 *   3. `horas_espera` entre {@see HORAS_ESPERA_MIN} y {@see HORAS_ESPERA_MAX}, y `max_followups`
 *      hasta {@see MAX_FOLLOWUPS_MAX}. Los dos números tienen su porqué escrito en la constante:
 *      un mínimo sin motivo lo saca el próximo que pase.
 *   4. `dry_run` en `true` por defecto. La simulación devuelve, por cada regla, el valor ACTUAL, el
 *      PROPUESTO, CUÁNTOS LEADS hay hoy en ese estado y —lo que de verdad importa— a cuántos de
 *      esos les sale el mensaje EN LA PRÓXIMA CORRIDA DEL CRON. Ver {@see horas_desde_el_ultimo_mensaje()}.
 *   5. Encima de todo eso, el estado del MOTOR: cuántas reglas activas hay hoy y cuántas quedan
 *      después. Apagar la última exige `confirm_apagar_todas` explícito.
 *   6. Con `dry_run=false`: `confirm_count` exacto y `confirm_token` del conjunto simulado, que
 *      cubre también la POBLACIÓN de leads y el disparo inmediato.
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
     * 🔴 MÍNIMO DE `horas_espera` = 3, Y EL 3 SALE DEL CRON, NO DE NINGÚN GUSTO.
     *
     * `app/Console/Kernel.php` (línea ~23) tiene
     * `$schedule->command('leads:check-followups')->everyTwoHours()`, y
     * `LeadFollowupService::process_lead()` (línea ~224) decide con
     * `if ($hours < (int) $rule->horas_espera) { return null; }`.
     *
     * Con `horas_espera = 1` o `= 2`, CADA corrida del cron cumple la condición: el lead recibe un
     * mensaje, el reloj se reinicia con ese mensaje, dos horas después vuelve a cumplirse. La
     * cadencia real pasa a ser "un mensaje cada dos horas" —diez mensajes en veinte horas a una
     * persona real, que es el tope de `max_followups`— y el mínimo de 1 que había hasta el 3/9/2026
     * NO frenaba nada de eso: era un mínimo que decía impedir el martilleo y lo dejaba pasar entero.
     *
     * 3 es el primer valor que obliga a saltear una corrida: a las 2 horas `2 < 3` y no manda, a
     * las 4 sí. O sea que el piso REAL entre dos mensajes queda en 4 horas.
     */
    const HORAS_ESPERA_MIN = 3;

    /**
     * 🔴 MÁXIMO DE `horas_espera` = 720 horas (30 días), y también tiene su porqué.
     *
     * Dos motivos, y el segundo es el que importa:
     *   1. La columna es `int unsigned` (medido el 3/9/2026 en `information_schema.COLUMNS` sobre
     *      `admin_testing_s4`), o sea que entra hasta 4.294.967.295. Un `4294967296` —que la
     *      validación aceptaba porque no tenía NINGÚN `max`— pasaba la simulación con 200 y
     *      reventaba con **500** al escribir, justo lo que este bloque promete que no pasa nunca.
     *   2. Pero `4294967295`, que SÍ entra en la columna, era peor: la regla quedaba **activa**, se
     *      veía en el panel del admin como una regla que anda, y esperaba 490.000 años. Es
     *      exactamente el mismo modo de fallo por el que un `estado` inventado es 422 —"queda
     *      cargada pareciendo que anda"—, por el otro campo.
     *
     * 30 días es el techo de lo que todavía es una cadencia: más que eso, el lead está frío y lo
     * que se quiere no es esperarlo sino apagar la regla, que se hace con `activa => false` y se ve.
     */
    const HORAS_ESPERA_MAX = 720;

    /**
     * Máximo de `max_followups`. Un número alto hace lo mismo que un `horas_espera` bajo, pero más
     * lento: sigue siendo martilleo.
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
     * @param Request $request Body: reglas[] (req), dry_run, confirm_count, confirm_token,
     *                         confirm_apagar_todas.
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
            'reglas.*.horas_espera'  => 'nullable|integer|min:' . self::HORAS_ESPERA_MIN . '|max:' . self::HORAS_ESPERA_MAX,
            'reglas.*.max_followups' => 'nullable|integer|min:1|max:' . self::MAX_FOLLOWUPS_MAX,
            /* 🔴 `required` y no `nullable`: una regla no se prende sola. Ver propuesto_para(). */
            'reglas.*.activa'        => 'required|boolean',
            /* ⚠️ `descripcion` es una columna TEXT, o sea 65.535 BYTES. Acá NO hace falta el chequeo
               en bytes que sí tiene ClaudeProtocolEntriesController, porque 2.000 caracteres UTF-8
               ocupan como máximo 8.000 bytes: el `max` de caracteres ya deja el valor bien adentro
               del límite de la columna. Si algún día se sube este 2000, hay que mirar los bytes. */
            'reglas.*.descripcion'   => 'nullable|string|max:2000',
            'dry_run'                => 'nullable|boolean',
            'confirm_count'          => 'nullable|integer|min:0',
            'confirm_token'          => 'nullable|string|max:64',
            'confirm_apagar_todas'   => 'nullable|boolean',
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

        $horas_por_estado = $this->horas_desde_el_ultimo_mensaje($estados);

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

            /* --- Freno 3: el rango se chequea sobre el valor RESUELTO, no sobre el que vino. Sin
                   esto, una regla vieja con `horas_espera = 1` (de antes de que el mínimo fuera 3)
                   se podía dejar prendida editándole cualquier otro campo: el valor viejo se
                   arrastra y la validación de Laravel, que sólo mira lo que llegó, ni lo ve. Si la
                   regla queda APAGADA se deja pasar a propósito: apagar una regla rota tiene que ser
                   siempre posible, y apagada no le manda nada a nadie. --- */
            if ($propuesto['activa'] === true) {
                $fuera_de_rango = $this->horas_fuera_de_rango($propuesto['horas_espera']);
                if ($fuera_de_rango !== null) {
                    return $this->error_422(
                        'La regla del estado "' . $estado . '" quedaría activa con horas_espera='
                            . (int) $propuesto['horas_espera'] . ', y ' . $fuera_de_rango . ' No se escribió nada.',
                        [
                            'estado'           => $estado,
                            'horas_espera'     => (int) $propuesto['horas_espera'],
                            'horas_espera_min' => self::HORAS_ESPERA_MIN,
                            'horas_espera_max' => self::HORAS_ESPERA_MAX,
                            'ayuda'            => 'Mandá un horas_espera válido en esta misma llamada, o mandá '
                                . 'activa=false si lo que querés es apagarla.',
                        ]
                    );
                }
            }

            $diff = [];
            foreach ($propuesto as $columna => $valor_nuevo) {
                $valor_viejo = array_key_exists($columna, $actual) ? $actual[$columna] : null;
                if ($valor_viejo === $valor_nuevo) {
                    continue;
                }
                $diff[$columna] = ['actual' => $valor_viejo, 'propuesto' => $valor_nuevo];
            }

            $disparo_inmediato = $this->disparo_inmediato($horas_por_estado, $estado, $propuesto);

            $fila_resultado = [
                'estado'               => $estado,
                'accion'               => $existente === null ? 'crear' : 'actualizar',
                'leads_en_ese_estado'  => (int) ($leads_por_estado->get($estado) ?: 0),
                'disparo_inmediato'    => $disparo_inmediato,
                'actual'               => $actual,
                'propuesto'            => $propuesto,
                'diff'                 => $diff,
            ];

            /* Las dos advertencias no se pisan nunca: un estado que el cron saltea no tiene leads en
               el conteo de disparo inmediato (se excluyen en la consulta), así que el disparo ahí es
               siempre 0. */
            if (in_array($estado, self::ESTADOS_QUE_EL_CRON_NUNCA_PROCESA, true)) {
                $fila_resultado['advertencia'] = 'LeadFollowupService::process_all_active_leads() saltea siempre los '
                    . 'leads en "' . $estado . '", así que esta regla es inerte: el cron nunca la va a aplicar.';
            } elseif ($disparo_inmediato > 0) {
                $fila_resultado['advertencia'] = 'ESTO NO ES UNA CADENCIA QUE ARRANCA MAÑANA: hay '
                    . $disparo_inmediato . ' lead(s) en "' . $estado . '" cuyo último mensaje ya es más viejo que '
                    . 'las ' . (int) $propuesto['horas_espera'] . ' horas propuestas, así que reciben el seguimiento '
                    . 'en la PRÓXIMA corrida del cron, dentro de las 2 horas. Un lead sin ningún mensaje cuenta '
                    . 'desde que se creó (LeadFollowupService::last_message_at()), o sea que los parados hace meses '
                    . 'cumplen cualquier horas_espera.';
            }

            if ($existente !== null && empty($diff)) {
                $sin_cambio[] = $fila_resultado;
                continue;
            }

            $cambios[] = $fila_resultado;
        }

        $cambiarian = count($cambios);
        $motor      = $this->estado_del_motor($cambios);
        $apagaria_todas = isset($motor['apagaria_todas']) ? (bool) $motor['apagaria_todas'] : false;
        unset($motor['apagaria_todas']);

        $confirm_token = $this->calcular_confirm_token($cambios, $motor);

        /* --- Freno 4: simulación, que es el default. --- */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            return response()->json([
                'dry_run'               => true,
                'cambiarian'            => $cambiarian,
                'motor_de_seguimientos' => $motor,
                'sin_cambio'            => $sin_cambio,
                'cambios'               => $cambios,
                'confirm_token'         => $confirm_token,
                'nota'                  => 'Simulación: no se escribió ninguna regla. Mirá DOS números de cada fila '
                    . 'antes de seguir: `leads_en_ese_estado` es a cuánta gente real le cambia la cadencia, y '
                    . '`disparo_inmediato` es a cuántos de ésos les sale el mensaje en la próxima corrida del cron '
                    . '(dentro de las 2 horas), no dentro de las horas_espera. Y mirá `motor_de_seguimientos`, que '
                    . 'dice cuántas reglas activas quedan después de este lote. Para aplicar de verdad, repetí la '
                    . 'misma llamada con dry_run=false, confirm_count=' . $cambiarian . ' y confirm_token='
                    . $confirm_token . '.',
            ], 200);
        }

        /* --- Freno 5, y el ORDEN de estas dos ramas importa. "No hay nada que cambiar" se resuelve
               ANTES que `confirm_count`, porque si no el reintento que el docblock declara seguro
               —el mismo body, tal cual— daba 422: traía `confirm_count=1` contra `cambiarian=0`. No
               duplicaba nada (fallaba del lado seguro), pero la rama de 200 sólo se alcanzaba
               mandando `confirm_count=0` a propósito, que no es lo que hace un reintento. Acá no se
               afloja nada: con `cambiarian === 0` no hay ninguna escritura que confirmar. --- */
        if ($cambiarian === 0) {
            return $this->respuesta_de_escritura(
                $estados,
                ['creadas' => 0, 'actualizadas' => 0, 'sin_cambio' => count($sin_cambio)],
                'No había nada que cambiar: las reglas mandadas ya están así en la base. No se escribió nada y '
                    . 'reintentar esta misma llamada vuelve a dar esto mismo.'
            );
        }

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
                'confirm_token no corresponde a este conjunto: cambió alguna regla, o la POBLACIÓN de leads a la '
                    . 'que le pega, respecto de la simulación. No se escribió nada.',
                ['confirm_token_esperado' => $confirm_token, 'motor_de_seguimientos' => $motor]
            );
        }

        /* --- Freno 6: apagar la ÚLTIMA regla activa deja el motor de seguimientos mudo. Un lote de
               baja no puede salir tan barato como uno de alta. --- */
        if ($apagaria_todas && ! $request->boolean('confirm_apagar_todas')) {
            return $this->error_422(
                'Este lote deja el motor de seguimientos SIN NINGUNA REGLA ACTIVA: hoy hay '
                    . $motor['reglas_activas_hoy'] . ' y quedarían 0. El cron `leads:check-followups` va a seguir '
                    . 'corriendo cada dos horas y no le va a mandar un seguimiento a NADIE, sin que nada lo avise. '
                    . 'No se escribió nada. Si es lo que querés, repetí la llamada agregando '
                    . 'confirm_apagar_todas=true.',
                ['motor_de_seguimientos' => $motor]
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

        /* Auditoría. 🔴 Nunca se loguea la clave del header: solo qué se escribió. */
        Log::channel('daily')->info('ClaudeFollowupRulesController: cadencia de seguimientos actualizada.', [
            'resultados' => $resultados,
            'estados'    => $estados,
        ]);

        return $this->respuesta_de_escritura(
            $estados,
            $resultados,
            $apagaria_todas
                ? 'El motor de seguimientos quedó SIN NINGUNA REGLA ACTIVA: el cron `leads:check-followups` sigue '
                    . 'corriendo cada dos horas y no le manda nada a nadie. Se aplicó porque vino '
                    . 'confirm_apagar_todas=true.'
                : null
        );
    }

    /**
     * La respuesta de un `dry_run=false`, con las reglas tal cual quedaron y el estado REAL del
     * motor después de escribir (no el proyectado).
     *
     * @param array<int, string> $estados    Estados del lote.
     * @param array<string, int> $resultados Contadores.
     * @param string|null        $nota       Nota opcional.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_de_escritura(array $estados, array $resultados, $nota)
    {
        $activas = (int) FollowupRule::query()->where('activa', true)->count();

        $motor = ['reglas_activas_despues' => $activas];
        if ($activas === 0) {
            $motor['advertencia'] = 'NO HAY NINGUNA REGLA DE SEGUIMIENTO ACTIVA. El cron `leads:check-followups` '
                . 'corre cada dos horas y no le va a mandar un seguimiento a nadie.';
        }

        $cuerpo = [
            'dry_run'               => false,
            'resultados'            => $resultados,
            'motor_de_seguimientos' => $motor,
            'reglas'                => FollowupRule::query()->whereIn('estado', $estados)->orderBy('estado')->get(),
        ];

        if ($nota !== null) {
            $cuerpo['nota'] = $nota;
        }

        return response()->json($cuerpo, 200);
    }

    /**
     * Cuántas reglas activas hay hoy y cuántas quedarían después de aplicar el lote.
     *
     * 🔴 POR QUÉ ESTE BLOQUE EXISTE. Medido el 3/9/2026: con 15 reglas activas, un lote de 15 filas
     * con `activa: false` las apagaba todas y devolvía 200 sin que ni la simulación ni la respuesta
     * mencionaran que el cron de seguimientos había quedado sin ninguna regla. Un lote de baja
     * salía igual de barato que uno de alta, y el motor entero se puede apagar sin querer con un
     * payload que en el diff se ve como quince cambios chiquitos de un booleano.
     *
     * @param array<int, array<string, mixed>> $cambios Filas que sí cambian.
     *
     * @return array<string, mixed> Con `apagaria_todas`, que el llamador saca antes de publicarlo.
     */
    protected function estado_del_motor(array $cambios): array
    {
        $activas_hoy = (int) FollowupRule::query()->where('activa', true)->count();
        $delta       = 0;

        foreach ($cambios as $cambio) {
            $antes   = $cambio['actual']['activa'];
            $despues = $cambio['propuesto']['activa'];

            if ($despues === true && $antes !== true) {
                $delta++;
                continue;
            }

            if ($despues !== true && $antes === true) {
                $delta--;
            }
        }

        $despues = $activas_hoy + $delta;
        $motor   = [
            'reglas_activas_hoy'     => $activas_hoy,
            'reglas_activas_despues' => $despues,
            'apagaria_todas'         => $activas_hoy > 0 && $despues === 0,
        ];

        if ($motor['apagaria_todas']) {
            $motor['advertencia'] = 'ESTE LOTE APAGA LA ÚLTIMA REGLA ACTIVA: el motor de seguimientos queda mudo. El '
                . 'cron `leads:check-followups` va a seguir corriendo cada dos horas y no le va a mandar un '
                . 'seguimiento a NADIE, y nada lo va a avisar. Para aplicarlo hay que mandar '
                . 'confirm_apagar_todas=true además del confirm_token.';
        }

        return $motor;
    }

    /**
     * Para cada estado del lote, las horas que pasaron desde el último mensaje de cada uno de sus
     * leads — que es el número exacto con el que `LeadFollowupService::process_lead()` decide si
     * manda o no.
     *
     * 🔴 POR QUÉ HACE FALTA, Y ES EL AGUJERO MENOS OBVIO DE TODOS.
     * `LeadFollowupService::last_message_at()` (línea ~563) cae a `$lead->created_at` cuando el lead
     * no tiene ningún mensaje. O sea que un lead parado hace meses tiene un `$hours` enorme y cumple
     * CUALQUIER `horas_espera`. Prender una regla nueva en un estado con leads viejos adentro no es
     * "empezar una cadencia": es mandarles un WhatsApp a todos ellos dentro de las dos horas
     * siguientes. `leads_en_ese_estado` no alcanzaba para verlo — decía cuántos hay, no a cuántos
     * les sale el mensaje ya.
     *
     * ⚠️ Es una ESTIMACIÓN, y de las que se pasan para arriba, no para abajo:
     *   - se usa `MAX(created_at)` de los mensajes y el servicio usa el último por `id`; con los
     *     `created_at` desordenados podrían diferir por minutos;
     *   - no se replica el guard de `demo_agendada` con demo futura (el cron ahí no manda), así que
     *     en ese estado el número puede quedar más alto que la realidad.
     * Una cota superior es el error que corresponde para un número que existe para frenar a alguien.
     *
     * @param array<int, string> $estados Estados del lote.
     *
     * @return array<string, array<int, int>> Horas por lead, agrupadas por estado.
     */
    protected function horas_desde_el_ultimo_mensaje(array $estados): array
    {
        $procesables = array_values(array_diff($estados, self::ESTADOS_QUE_EL_CRON_NUNCA_PROCESA));
        if (empty($procesables)) {
            return [];
        }

        $ultimo = LeadMessage::query()
            ->selectRaw('MAX(created_at)')
            ->whereColumn('lead_messages.lead_id', 'leads.id')
            ->where('lead_messages.status', '!=', 'rechazado');

        $leads = Lead::query()
            ->whereIn('status', $procesables)
            /* El cron saltea al lead que ya tiene una sugerencia esperando aprobación. */
            ->where(function ($query) {
                $query->whereNull('tiene_sugerencia_pendiente')
                    ->orWhere('tiene_sugerencia_pendiente', false);
            })
            ->select(['leads.id', 'leads.status', 'leads.created_at'])
            ->selectSub($ultimo, 'ultimo_mensaje_at')
            ->get();

        $ahora = AppTime::now();
        $horas = [];

        foreach ($leads as $lead) {
            $referencia = $lead->ultimo_mensaje_at !== null ? $lead->ultimo_mensaje_at : $lead->created_at;
            if ($referencia === null) {
                continue;
            }

            $horas[$lead->status][] = (int) Carbon::parse($referencia)->diffInHours($ahora);
        }

        return $horas;
    }

    /**
     * Cuántos leads del estado reciben el seguimiento en la PRÓXIMA corrida del cron si se aplica
     * la regla propuesta. Una regla que queda apagada no dispara nada, y se informa 0.
     *
     * @param array<string, array<int, int>> $horas_por_estado Salida de horas_desde_el_ultimo_mensaje().
     * @param string                         $estado           Estado de la regla.
     * @param array<string, mixed>           $propuesto        Regla tal cual quedaría.
     *
     * @return int
     */
    protected function disparo_inmediato(array $horas_por_estado, $estado, array $propuesto): int
    {
        if ($propuesto['activa'] !== true || $propuesto['horas_espera'] === null) {
            return 0;
        }

        if (! isset($horas_por_estado[$estado])) {
            return 0;
        }

        $espera  = (int) $propuesto['horas_espera'];
        $cuantos = 0;

        foreach ($horas_por_estado[$estado] as $horas) {
            /* Misma comparación que LeadFollowupService::process_lead(): manda cuando NO se cumple
               `$hours < $rule->horas_espera`. */
            if ($horas >= $espera) {
                $cuantos++;
            }
        }

        return $cuantos;
    }

    /**
     * Por qué un `horas_espera` resuelto no sirve, o null si está bien.
     *
     * @param int|null $horas Valor resuelto.
     *
     * @return string|null
     */
    protected function horas_fuera_de_rango($horas)
    {
        if ($horas === null) {
            return null;
        }

        $horas = (int) $horas;

        if ($horas < self::HORAS_ESPERA_MIN) {
            return 'el mínimo es ' . self::HORAS_ESPERA_MIN . ': el cron `leads:check-followups` corre cada DOS '
                . 'horas (app/Console/Kernel.php, everyTwoHours()), así que con 1 o con 2 cada corrida cumple la '
                . 'condición y la cadencia real pasa a ser un mensaje cada dos horas.';
        }

        if ($horas > self::HORAS_ESPERA_MAX) {
            return 'el máximo es ' . self::HORAS_ESPERA_MAX . ' horas (30 días): más que eso la regla queda activa '
                . 'y visible en el panel pero no dispara nunca, que es lo mismo que tenerla apagada sin que se note. '
                . 'Para apagarla, activa=false.';
        }

        return null;
    }

    /**
     * Las columnas tal cual quedarían después de aplicar una fila del payload.
     *
     * En una edición, un campo opcional ausente NO borra lo que ya estaba: se arrastra el valor de
     * la fila existente. En un alta, `horas_espera` y `max_followups` ya vinieron obligados por el
     * freno 2.
     *
     * 🔴 `activa` NO TIENE DEFAULT Y ES OBLIGATORIO, y hasta el 3/9/2026 caía a `true`. La
     * asimetría con `POST claude/protocol-entries` —donde siempre fue obligatorio— estaba al revés
     * del riesgo: una entrada del protocolo es texto que lee una persona antes de usarlo, mientras
     * que una regla hace que el cron le escriba por WhatsApp a leads reales sin que nadie apriete
     * nada. El campo que se activa solo tiene que ser el inofensivo, no el que manda mensajes.
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
            'activa'        => filter_var($fila['activa'], FILTER_VALIDATE_BOOLEAN),
        ];

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
     * Huella determinista del conjunto simulado: ata la confirmación a los estados exactos, a los
     * valores exactos que se revisaron Y A LA POBLACIÓN A LA QUE LE PEGAN.
     *
     * 🔴 LOS DOS MOTIVOS POR LOS QUE ESTE MÉTODO NO ES EL OBVIO:
     *
     *   1. EL TOKEN CUBRE `leads_en_ese_estado` Y `disparo_inmediato`, que son justo los números que
     *      la nota de la simulación pide mirar. Medido el 3/9/2026: se simulaba con
     *      `leads_en_ese_estado: 1`, entraban cinco leads más a ese estado y se confirmaba con el
     *      mismo token y el mismo `confirm_count` — 200, escribía, y la población a la que le
     *      cambiaba la cadencia había pasado de 1 a 6 sin que el token se enterara. Un token que
     *      sólo cubre lo que se manda no protege el número por el que se decidió mandarlo. Va
     *      también `motor_de_seguimientos`, por lo mismo.
     *   2. SE ARMA SOBRE UNA ESTRUCTURA SERIALIZADA ENTERA, NO CONCATENANDO CON SEPARADORES. Acá la
     *      parte izquierda es un slug normalizado (`[a-z0-9_]`), así que meter un `:` o un `|` en un
     *      estado no se puede y el agujero no era explotable — pero en
     *      `ClaudeProtocolEntriesController`, donde la izquierda es texto libre, SÍ lo era y está
     *      medido. Los dos tokens se arman igual para que nadie tenga que averiguar cuál de los dos
     *      era el seguro.
     *
     * @param array<int, array<string, mixed>> $cambios Lista ya resuelta.
     * @param array<string, mixed>             $motor   Estado del motor de seguimientos.
     *
     * @return string
     */
    protected function calcular_confirm_token(array $cambios, array $motor): string
    {
        $partes = [];
        foreach ($cambios as $cambio) {
            $partes[] = $this->serializar([
                'estado'              => $cambio['estado'],
                'propuesto'           => $cambio['propuesto'],
                'leads_en_ese_estado' => $cambio['leads_en_ese_estado'],
                'disparo_inmediato'   => $cambio['disparo_inmediato'],
            ]);
        }
        sort($partes);

        return substr(
            hash('sha256', $this->serializar([
                'dominio' => 'cadencia',
                'cambios' => $partes,
                'motor'   => [
                    'reglas_activas_hoy'     => $motor['reglas_activas_hoy'],
                    'reglas_activas_despues' => $motor['reglas_activas_despues'],
                ],
            ])),
            0,
            32
        );
    }

    /**
     * Serializa una estructura de forma determinista para el token.
     *
     * El fallback a `serialize()` cubre el único caso en que `json_encode()` devuelve `false`:
     * texto que no es UTF-8 válido. Sin él, dos conjuntos distintos podrían compartir el hash de la
     * cadena vacía, que es exactamente lo que un token no puede permitirse.
     *
     * @param array<string, mixed> $estructura Estructura a serializar.
     *
     * @return string
     */
    protected function serializar(array $estructura): string
    {
        $json = json_encode($estructura);

        return $json !== false ? $json : serialize($estructura);
    }
}
