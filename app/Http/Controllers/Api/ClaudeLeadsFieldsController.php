<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\LeadRescheduleFlagsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Edición de los campos descriptivos y de agenda de UN lead, desde los endpoints `claude/*`.
 *
 * Por qué existe: el bloque `claude/*` podía leer todo el pipeline, mandar mensajes y mover el
 * estado de un lead, pero no podía corregir un rubro mal cargado, guardar el dolor que el lead
 * contó en la conversación, cargarle el mail ni —sobre todo— AGENDAR LA DEMO, que es el objetivo
 * entero del chequeo diario de leads. Todo eso solo se podía hacer entrando al panel a mano.
 *
 * 🔴 LISTA BLANCA CERRADA, Y UN CAMPO NO DECLARADO ES 422 CON LA LISTA DE LOS VÁLIDOS
 * -----------------------------------------------------------------------------------
 * Nunca se ignora un campo en silencio. Mismo criterio que los filtros de `GET claude/query`: si
 * lo que mandaste no existe, tenés que enterarte en la respuesta y no descubrirlo tres días
 * después mirando por qué el dato no está. Los válidos son {@see self::CAMPOS} y los prohibidos
 * más probables llevan su motivo escrito en el cuerpo del 422 ({@see self::MOTIVOS_PROHIBIDOS}):
 * no alcanza con decir "ese no", hay que decir por dónde va.
 *
 * 🔴 LOS CAMPOS VAN ADENTRO DE `campos`, NO SUELTOS EN EL BODY. Si fueran sueltos, `dry_run` y
 * `confirm_count` viajarían al lado de los datos y habría que excluirlos a mano de la lista
 * blanca — una lista blanca con excepciones deja de ser una lista blanca, y el día que se agregue
 * un freno nuevo alguien se olvida de excluirlo y el freno se vuelve un campo del lead.
 *
 * 🔴 LOS FRENOS, en el orden en que corren:
 *   1. Todo campo del payload tiene que estar en la lista blanca.
 *   2. Un lead ya promovido a cliente (`promoted_client_id` no nulo) no se toca. Es el MISMO
 *      criterio que `ClaudeLeadsPipelineController::motivo_de_bloqueo()`. De ese criterio se copia
 *      solo esa mitad y no la del `cerrado_ganado`, a propósito: allá se bloquea el estado porque
 *      ese tramo cuelga de la promoción a Client, y acá el estado no se toca — corregirle el
 *      nombre de contacto a un lead que se cerró ganado hace un mes no rompe nada.
 *   3. `dry_run` en `true` por defecto, devolviendo el DIFF campo por campo (actual → propuesto).
 *      Sin `dry_run=false` explícito no se escribe absolutamente nada.
 *   4. Con `dry_run=false`, `confirm_count` tiene que ser exactamente la cantidad de campos que
 *      CAMBIAN (la que devolvió la simulación).
 *   5. Un campo que llega con el valor que ya tiene se reporta `sin_cambio` y no cuenta como
 *      escritura: reintentar la misma llamada es seguro.
 *
 * 🔴 REAGENDAR RESETEA LOS FLAGS DE RECORDATORIO, Y POR LA MISMA DEFINICIÓN QUE EL PANEL. Cambiar
 * `demo_date` llama a {@see LeadRescheduleFlagsService}, que es el mismo servicio que llaman
 * `LeadController::update()` y `LeadController::update_json()`. Ese bloque estaba escrito dos veces
 * adentro de `LeadController` y esta misión iba a ser la tercera; el porqué de la extracción está
 * en el docblock del servicio.
 *
 * ⚠️ Lo que este endpoint NO hace, igual que el panel: no mueve el evento de Google Calendar del
 * closer al reagendar (para eso está el botón aparte, `POST admin/lead/{id}/force-calendar-event`)
 * ni le avisa al lead por WhatsApp que se cambió el horario (`DemoScheduledWhatsappService`, que
 * solo dispara desde el flujo del agente en `LeadAiService`). Verificado el 3/9/2026 recorriendo
 * los consumidores de `demo_date`: el camino del panel tampoco hace ninguna de las dos, así que
 * este endpoint no queda por detrás de él. Si algún día el panel las hace, este endpoint tiene que
 * hacerlas también o dejar de ofrecer `demo_date`.
 */
class ClaudeLeadsFieldsController extends Controller
{
    use RespuestasParaClaude;

    /**
     * La lista blanca, con el tipo de cada campo. Nada que no esté acá se puede escribir.
     *
     * Tipos: `texto`, `email`, `fecha` (Y-m-d), `hora` (HH:MM o HH:MM:SS), `booleano`,
     * `fecha_hora`.
     */
    const CAMPOS = [
        'contact_name'         => ['tipo' => 'texto', 'max' => 150],
        'company_name'         => ['tipo' => 'texto', 'max' => 150],
        'business_type'        => ['tipo' => 'texto', 'max' => 80],
        /* 🔴 `notes` es el lugar del proyecto para el "dolor detectado": NO existe columna `dolor`
           y no se crea una (esta misión no lleva migraciones). */
        'notes'                => ['tipo' => 'texto', 'max' => 20000],
        'email'                => ['tipo' => 'email', 'max' => 150],
        'demo_date'            => ['tipo' => 'fecha'],
        'demo_start_time'      => ['tipo' => 'hora', 'max' => 32],
        'demo_end_time'        => ['tipo' => 'hora', 'max' => 32],
        'demo_flexible'        => ['tipo' => 'booleano'],
        'meeting_scheduled_at' => ['tipo' => 'fecha_hora'],
    ];

    /**
     * Campos que NO se pueden escribir desde acá, con el motivo. Van en el cuerpo del 422 porque un
     * "ese campo no existe" a secas manda al que llamó a buscar en el código; el motivo lo manda al
     * lugar correcto.
     */
    const MOTIVOS_PROHIBIDOS = [
        'status'              => 'El estado del pipeline se mueve con POST claude/leads/{id}/status (o el lote status-batch), que tiene sus propios frenos: no asigna cerrado_ganado y deja el evento en la conversación.',
        'phone'              => '🔴 Cambiar el teléfono redirige TODOS los envíos futuros de ese lead a otro número. Se toca desde el panel, mirando la conversación.',
        'contract_*'          => 'Los campos de contrato los arma la generación de contrato, no una edición suelta.',
        'promoted_client_id'  => 'Cuelga de la promoción a Client (contrato y alta). Escribirlo a mano dejaría un lead apuntando a un cliente que nadie dio de alta.',
        'user_id'             => 'Se asigna recién en la promoción a Client. El propio panel lo saca del request de edición a propósito.',
        'demo_ingreso_token_*' => 'Los tokens de ingreso a la demo los emite y revoca el sistema, con vencimiento propio.',
        'flags de automatización (claude_auto_reply, requiere_*, notificar_mensajes, automatizaciones_demo_activas, auto_*)' => 'Los pone una persona mirando la conversación. Un agente no decide dejar de verificar lo que le sale al lead.',
    ];

    /**
     * Actualiza campos de UN lead por lista blanca.
     *
     * @param Request    $request Body: campos{} (req), dry_run, confirm_count.
     * @param int|string $id      Lead objetivo.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'campos'        => 'required|array|min:1',
            'dry_run'       => 'nullable|boolean',
            'confirm_count' => 'nullable|integer|min:0',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $campos = (array) $request->input('campos', []);

        /* --- Freno 1: la lista blanca, ANTES de resolver el lead. Un campo inventado es un error
               de armado y aborta todo: nada se escribe y nada se ignora en silencio. --- */
        foreach (array_keys($campos) as $campo) {
            if (! array_key_exists($campo, self::CAMPOS)) {
                return $this->error_422(
                    'El campo "' . $campo . '" no se puede escribir desde acá. No se escribió nada.',
                    [
                        'campos_validos'             => array_keys(self::CAMPOS),
                        'motivos_de_los_prohibidos'  => self::MOTIVOS_PROHIBIDOS,
                    ]
                );
            }
        }

        $lead = Lead::find((int) $id);
        if ($lead === null) {
            return $this->error_404('No existe el lead ' . (int) $id . '.');
        }

        /* --- Freno 2: lead ya promovido a cliente. Mismo criterio que
               ClaudeLeadsPipelineController::motivo_de_bloqueo(); ver el docblock de la clase para
               por qué se copia sólo esa mitad. --- */
        if ($lead->promoted_client_id !== null) {
            return $this->error_422(
                'El lead ya está promovido a cliente (promoted_client_id=' . (int) $lead->promoted_client_id
                    . '); sus campos no se tocan desde acá. No se escribió nada.',
                ['lead_id' => (int) $lead->id]
            );
        }

        /* Normalización y validación de VALOR, campo por campo y en español. No se hace con reglas
           de Laravel porque `email`, la hora HH:MM y el booleano estricto no están en la lista de
           mensajes traducidos del trait, y una regla que no está ahí no falla: contesta en inglés
           (ver el docblock de RespuestasParaClaude::mensajes_de_validacion()). */
        $normalizados = [];
        foreach ($campos as $campo => $valor_crudo) {
            $normalizado = $this->normalizar($campo, $valor_crudo);
            if (! $normalizado['ok']) {
                return $this->error_422(
                    'El campo "' . $campo . '" ' . $normalizado['error'] . ' No se escribió nada.',
                    ['campo' => $campo, 'valor_recibido' => is_scalar($valor_crudo) ? $valor_crudo : null]
                );
            }

            $normalizados[$campo] = $normalizado['valor'];
        }

        /* --- Freno 5: el diff. Un campo que ya tiene el valor propuesto no cuenta como escritura. --- */
        $diff       = [];
        $sin_cambio = [];
        foreach ($normalizados as $campo => $valor_nuevo) {
            $valor_actual = $this->actual_de($lead, $campo);

            if ($this->son_iguales($valor_actual, $valor_nuevo)) {
                $sin_cambio[$campo] = $valor_actual;
                continue;
            }

            $diff[$campo] = ['actual' => $valor_actual, 'propuesto' => $valor_nuevo];
        }

        $cambiarian = count($diff);
        $reagenda   = array_key_exists('demo_date', $diff);

        /* --- Freno 3: simulación, que es el default. --- */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            $respuesta = [
                'dry_run'    => true,
                'lead_id'    => (int) $lead->id,
                'cambiarian' => $cambiarian,
                'diff'       => (object) $diff,
                'sin_cambio' => (object) $sin_cambio,
                'nota'       => 'Simulación: no se escribió nada en el lead. Para aplicar de verdad, repetí la '
                    . 'misma llamada con dry_run=false y confirm_count=' . $cambiarian . '.',
            ];

            if ($reagenda) {
                /* Se muestran leídos del MISMO servicio que después los escribe: si el dry_run
                   armara su propia lista, volvería a haber dos definiciones del reset. */
                $respuesta['reset_de_reagenda'] = app(LeadRescheduleFlagsService::class)->flags_reseteados();
                $respuesta['nota'] .= ' 🔴 Cambia demo_date: además de los campos, se resetean los flags de '
                    . 'recordatorio que están en reset_de_reagenda, igual que cuando se reagenda desde el panel.';
            }

            return response()->json($respuesta, 200);
        }

        /* --- Freno 4: confirmación explícita del número exacto de campos que cambian. --- */
        if (! $request->filled('confirm_count')) {
            return $this->error_422(
                'confirm_count es obligatorio cuando dry_run es false. No se escribió nada.',
                ['cambiarian' => $cambiarian]
            );
        }

        if ((int) $request->input('confirm_count') !== $cambiarian) {
            return $this->error_422(
                'confirm_count (' . (int) $request->input('confirm_count') . ') no coincide con los ' . $cambiarian
                    . ' campos que realmente cambian. No se escribió nada: volvé a correr la simulación.',
                ['cambiarian' => $cambiarian, 'diff' => (object) $diff]
            );
        }

        if ($cambiarian === 0) {
            /* Todo el payload venía con los valores que el lead ya tenía. No es un error: es la
               segunda corrida de la misma llamada, y tiene que ser barata y silenciosa. */
            return response()->json([
                'dry_run'    => false,
                'lead_id'    => (int) $lead->id,
                'escritos'   => 0,
                'sin_cambio' => (object) $sin_cambio,
                'reagendado' => false,
                'nota'       => 'Todos los campos ya tenían el valor propuesto: no se escribió nada ni se '
                    . 'resetearon flags.',
            ], 200);
        }

        $demo_date_original = $lead->getRawOriginal('demo_date');

        $a_escribir = [];
        foreach ($diff as $campo => $valores) {
            $a_escribir[$campo] = $valores['propuesto'];
        }
        $lead->update($a_escribir);

        /* 🔴 UNA SOLA DEFINICIÓN DEL RESET DE REAGENDA: el mismo servicio que usan los dos caminos
           del panel. Se refresca antes para leer el demo_date ya persistido, igual que hace
           LeadController::update_json(). */
        $lead->refresh();
        $reagendado = app(LeadRescheduleFlagsService::class)
            ->resetear_si_cambio_la_fecha($lead, $demo_date_original);

        Log::channel('daily')->info('ClaudeLeadsFieldsController: campos de lead actualizados.', [
            'lead_id'    => (int) $lead->id,
            'campos'     => array_keys($diff),
            'reagendado' => $reagendado,
        ]);

        return response()->json([
            'dry_run'    => false,
            'lead_id'    => (int) $lead->id,
            'escritos'   => $cambiarian,
            'diff'       => (object) $diff,
            'sin_cambio' => (object) $sin_cambio,
            'reagendado' => $reagendado,
        ], 200);
    }

    /**
     * Valida y normaliza el valor de UN campo de la lista blanca.
     *
     * Un `null` explícito significa "vaciar el campo" y se acepta en todos los tipos menos en
     * `booleano`, cuya columna es NOT NULL. ⚠️ El middleware global `ConvertEmptyStringsToNull`
     * convierte `""` en `null` antes de llegar acá, así que mandar el string vacío es lo mismo que
     * mandar null: vaciar.
     *
     * @param string $campo Nombre del campo, ya verificado contra la lista blanca.
     * @param mixed  $valor Valor crudo del payload.
     *
     * @return array{ok: bool, valor: mixed, error: string|null}
     */
    protected function normalizar(string $campo, $valor): array
    {
        $spec = self::CAMPOS[$campo];
        $tipo = $spec['tipo'];

        if (is_array($valor)) {
            return ['ok' => false, 'valor' => null, 'error' => 'llegó como lista y tiene que ser un valor simple.'];
        }

        if ($valor === null) {
            if ($tipo === 'booleano') {
                return ['ok' => false, 'valor' => null, 'error' => 'no admite null: es un booleano y la columna es NOT NULL. Mandá true o false.'];
            }

            return ['ok' => true, 'valor' => null, 'error' => null];
        }

        if ($tipo === 'booleano') {
            if (is_bool($valor)) {
                return ['ok' => true, 'valor' => $valor, 'error' => null];
            }

            $texto = strtolower(trim((string) $valor));
            if (! in_array($texto, ['1', '0', 'true', 'false'], true)) {
                return ['ok' => false, 'valor' => null, 'error' => 'tiene que ser booleano (true, false, 1 o 0).'];
            }

            return ['ok' => true, 'valor' => in_array($texto, ['1', 'true'], true), 'error' => null];
        }

        $texto = trim((string) $valor);
        if ($texto === '') {
            return ['ok' => true, 'valor' => null, 'error' => null];
        }

        if (isset($spec['max']) && mb_strlen($texto) > (int) $spec['max']) {
            return ['ok' => false, 'valor' => null, 'error' => 'supera el máximo de ' . (int) $spec['max'] . ' caracteres de la columna.'];
        }

        if ($tipo === 'email') {
            if (filter_var($texto, FILTER_VALIDATE_EMAIL) === false) {
                return ['ok' => false, 'valor' => null, 'error' => 'no es una dirección de mail válida.'];
            }

            return ['ok' => true, 'valor' => $texto, 'error' => null];
        }

        if ($tipo === 'fecha') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) !== 1) {
                return ['ok' => false, 'valor' => null, 'error' => 'tiene que venir en formato Y-m-d (por ejemplo 2026-09-15).'];
            }

            $partes = explode('-', $texto);
            if (! checkdate((int) $partes[1], (int) $partes[2], (int) $partes[0])) {
                return ['ok' => false, 'valor' => null, 'error' => 'es una fecha que no existe en el calendario.'];
            }

            return ['ok' => true, 'valor' => $texto, 'error' => null];
        }

        if ($tipo === 'hora') {
            /* HH:MM o HH:MM:SS. La columna es varchar(32) y el resto del sistema la parsea con
               `Carbon::parse($fecha . ' ' . $hora)`: cualquier otra cosa rompe ahí, lejos de acá. */
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $texto) !== 1) {
                return ['ok' => false, 'valor' => null, 'error' => 'tiene que venir como HH:MM o HH:MM:SS en 24 horas (por ejemplo 18:30).'];
            }

            return ['ok' => true, 'valor' => $texto, 'error' => null];
        }

        if ($tipo === 'fecha_hora') {
            $fecha = $this->parsear_o_null($texto);
            if ($fecha === null) {
                return ['ok' => false, 'valor' => null, 'error' => 'no es una fecha y hora válida.'];
            }

            /* 🔴 `parsear_o_null()` NO valida: `Carbon::parse('x')` devuelve AHORA sin lanzar (está
               escrito en su propio docblock). Se exige que el texto tenga al menos la forma de una
               fecha para que un `meeting_scheduled_at: "cuando pueda"` no se guarde como "ahora". */
            if (preg_match('/\d{4}-\d{2}-\d{2}/', $texto) !== 1) {
                return ['ok' => false, 'valor' => null, 'error' => 'tiene que venir en formato Y-m-d H:i (por ejemplo 2026-09-15 16:00).'];
            }

            return ['ok' => true, 'valor' => $fecha->format('Y-m-d H:i:s'), 'error' => null];
        }

        return ['ok' => true, 'valor' => $texto, 'error' => null];
    }

    /**
     * El valor que el lead tiene HOY en ese campo, con la MISMA forma que devuelve
     * `normalizar()`, para que el diff se pueda comparar sin adaptadores de por medio.
     *
     * Las dos columnas casteadas (`demo_date` a `date` y `meeting_scheduled_at` a `datetime`) se
     * leen con `getRawOriginal()`: comparar objetos Carbon compararía instancias distintas y daría
     * "cambió" siempre.
     *
     * @param Lead   $lead  Lead objetivo.
     * @param string $campo Campo de la lista blanca.
     *
     * @return mixed
     */
    protected function actual_de(Lead $lead, string $campo)
    {
        $tipo = self::CAMPOS[$campo]['tipo'];

        if ($campo === 'demo_date') {
            $crudo = $lead->getRawOriginal('demo_date');

            return $crudo === null || $crudo === '' ? null : substr((string) $crudo, 0, 10);
        }

        if ($tipo === 'fecha_hora') {
            $crudo = $lead->getRawOriginal($campo);

            return $crudo === null || $crudo === '' ? null : (string) $crudo;
        }

        if ($tipo === 'booleano') {
            return (bool) $lead->{$campo};
        }

        $valor = $lead->{$campo};

        return $valor === null || trim((string) $valor) === '' ? null : (string) $valor;
    }

    /**
     * Compara el valor actual con el propuesto sin las sorpresas de `==`.
     *
     * @param mixed $actual    Valor de hoy.
     * @param mixed $propuesto Valor del payload, ya normalizado.
     *
     * @return bool
     */
    protected function son_iguales($actual, $propuesto): bool
    {
        if ($actual === null || $propuesto === null) {
            return $actual === $propuesto;
        }

        if (is_bool($actual) || is_bool($propuesto)) {
            return (bool) $actual === (bool) $propuesto;
        }

        return (string) $actual === (string) $propuesto;
    }
}
