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
 * entrada (`ProtocolEntryProperties`: es uno de los campos con `show => true`).
 *
 * 🔴 Y ACÁ VA LO PRIMERO QUE ALGUIEN VA A QUERER "SIMPLIFICAR": LA COMPARACIÓN DE TÍTULOS NO PUEDE
 * SER `===`. NUNCA. VER {@see clave_de_titulo()}.
 *
 * 🔴 ES ESTRICTAMENTE ADITIVO: nunca borra una entrada que no vino en el payload. Para sacar una de
 * circulación se manda con `activa => false`, que es reversible y queda escrito.
 *
 * 🔴 TAMPOCO REESCRIBE EL `titulo` DE UNA FILA QUE YA EXISTE. Si el payload trae "Como abrir la
 * conversacion" y en la base está "Cómo abrir la conversación", se ACTUALIZA esa fila (para MySQL
 * son el mismo título) pero el título guardado queda como estaba, y la simulación lo dice en
 * `titulo_en_base`. Renombrar por acento sería indistinguible de un error de tipeo, y el título es
 * la identidad de la entrada.
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
 * ⚠️ LIMITACIÓN CONOCIDA: CONCURRENCIA. `protocol_entries.titulo` NO tiene índice único (verificado
 * el 3/9/2026 con `SHOW INDEX`), así que dos altas simultáneas del mismo título —dos llamadas a
 * este endpoint que resuelvan "no existe" antes de que cualquiera de las dos escriba— crean dos
 * filas y nada lo impide. Adentro de una misma llamada sí está cubierto: la escritura resuelve la
 * fila con `lockForUpdate()` y aborta el lote entero si dos títulos del payload caen en la misma
 * fila. El arreglo que corresponde es un índice `UNIQUE` sobre `titulo` (con la colación de la
 * columna, que es la que decide qué es "el mismo título"), y NO se hizo porque esta misión no lleva
 * migraciones. `followup_rules.estado` sí lo tiene, y por eso a la cadencia esto no le pasa.
 *
 * 🔴 LOS FRENOS, en el orden en que corren: tope de lote, `categoria` de la enumeración real,
 * `estado_aplicable` que sea un slug del pipeline (o vacío = "todos"), largo REAL EN BYTES de los
 * tres campos `TEXT`, `titulo` no repetido dentro del lote *según la colación de MySQL*, `dry_run`
 * por defecto con el diff entrada por entrada, y `confirm_count` + `confirm_token` del conjunto
 * simulado cuando `dry_run` es false.
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
     * Los tres campos de contenido, que son columnas `TEXT`.
     */
    const CAMPOS_DE_TEXTO = ['descripcion', 'mensaje_template', 'notas_setter'];

    /**
     * 🔴 EL TOPE DE UNA COLUMNA `TEXT` SON 65.535 **BYTES**, NO CARACTERES, Y ESA DIFERENCIA ERA UN
     * 500 EN PRODUCCIÓN.
     *
     * Medido el 3/9/2026 sobre `admin_testing_s4` con `information_schema.COLUMNS`:
     * `descripcion`, `mensaje_template` y `notas_setter` son `text utf8mb4_unicode_ci` con
     * `CHARACTER_MAXIMUM_LENGTH = CHARACTER_OCTET_LENGTH = 65535`. El `max:20000` que tenía la
     * validación lo cuenta Laravel en CARACTERES (`mb_strlen`): 20.000 emoji son 20.000 caracteres
     * y **80.000 bytes**, así que pasaban la validación y le reventaban a MySQL en la cara con un
     * 500 — justo lo que este bloque promete que no pasa nunca.
     *
     * ⚠️ `titulo` NO entra en esta cuenta y no es un olvido: un `varchar(N)` de MySQL cuenta
     * CARACTERES (`CHARACTER_MAXIMUM_LENGTH = 255`, `CHARACTER_OCTET_LENGTH = 1020`), así que ahí
     * el `max:255` de Laravel mide exactamente lo mismo que la columna.
     */
    const MAX_BYTES_TEXT = 65535;

    /**
     * Equivalencias de `utf8mb4_unicode_ci` para el alfabeto latino: acentos, diacríticos y las
     * expansiones (ß = ss, æ = ae, œ = oe). Se aplica con `strtr()` en {@see clave_de_titulo()}.
     */
    const PLIEGUE_DE_ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
        'ă' => 'a', 'ą' => 'a', 'ª' => 'a',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e',
        'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i',
        'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j', 'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ō' => 'o', 'ŏ' => 'o',
        'ő' => 'o', 'ø' => 'o', 'º' => 'o',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'þ' => 'th',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u',
        'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w',
        'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ĳ' => 'ij',
    ];

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
               y el chequeo de obligatorios al crear está más abajo, contra la fila existente.

               🔴 El `max` de acá es una guarda GRUESA (caracteres, que es lo único que sabe medir
               Laravel) y NO es el freno real: el freno real es el de BYTES, que corre más abajo
               contra `MAX_BYTES_TEXT`. Ver el docblock de esa constante. */
            'entradas.*.descripcion'      => 'nullable|string|max:' . self::MAX_BYTES_TEXT,
            'entradas.*.mensaje_template' => 'nullable|string|max:' . self::MAX_BYTES_TEXT,
            'entradas.*.notas_setter'     => 'nullable|string|max:' . self::MAX_BYTES_TEXT,
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

        /* --- Freno 2: enumeraciones, largos y títulos, TODOS juntos y antes de resolver nada. Un
               valor inventado aborta el lote entero: es un error de armado. --- */
        $slugs_validos = LeadPipelineStatus::all_slugs();
        $titulos       = [];
        $claves        = [];

        foreach ($filas as $indice => $fila) {
            $titulo = trim((string) (isset($fila['titulo']) ? $fila['titulo'] : ''));
            if ($titulo === '') {
                return $this->error_422(
                    'La entrada #' . ($indice + 1) . ' vino con el `titulo` vacío, y el título es la clave de '
                        . 'idempotencia de este endpoint. No se escribió nada.'
                );
            }

            /* 🔴 La comparación es por CLAVE NORMALIZADA y no por `===`. Ver clave_de_titulo(). */
            $clave = $this->clave_de_titulo($titulo);
            if (in_array($clave, $claves, true)) {
                return $this->error_422(
                    'El título "' . $titulo . '" ya viene en el lote (para MySQL es el mismo que otro de los '
                        . 'mandados: `protocol_entries.titulo` es utf8mb4_unicode_ci, así que no distingue '
                        . 'mayúsculas ni acentos). Como la carga es idempotente por título, las dos filas se '
                        . 'pisarían entre ellas sin que se sepa cuál ganó. No se escribió nada.',
                    ['titulo' => $titulo, 'clave_normalizada' => $clave]
                );
            }
            $claves[]  = $clave;
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

            $pasado_de_bytes = $this->campo_pasado_de_bytes($fila);
            if ($pasado_de_bytes !== null) {
                return $this->error_422(
                    'El campo `' . $pasado_de_bytes['campo'] . '` de la entrada "' . $titulo . '" ocupa '
                        . $pasado_de_bytes['bytes'] . ' bytes y la columna es TEXT, que entra hasta '
                        . self::MAX_BYTES_TEXT . '. Ojo que el límite de la base es en BYTES y no en caracteres: '
                        . 'son ' . $pasado_de_bytes['caracteres'] . ' caracteres, pero los emoji y los acentos '
                        . 'ocupan varios bytes cada uno. No se escribió nada.',
                    [
                        'titulo'     => $titulo,
                        'campo'      => $pasado_de_bytes['campo'],
                        'bytes'      => $pasado_de_bytes['bytes'],
                        'max_bytes'  => self::MAX_BYTES_TEXT,
                        'caracteres' => $pasado_de_bytes['caracteres'],
                    ]
                );
            }
        }

        /* Una sola consulta para todas las entradas del lote: nada de N+1. Y ojo con lo que hace
           MySQL acá: este `whereIn` compara con la colación de la columna, así que TRAE la fila
           "Cómo abrir la conversación" cuando el payload dice "Como abrir la conversacion". El bug
           que esto arregla no estaba en la consulta: estaba en el `keyBy('titulo')` que venía
           después, que reindexaba por el título GUARDADO y no lo encontraba de vuelta. */
        $existentes = $this->indexar_por_clave(
            ProtocolEntry::query()->whereIn('titulo', $titulos)->orderBy('id')->get()
        );

        $cambios    = [];
        $sin_cambio = [];

        foreach ($filas as $indice => $fila) {
            $titulo    = $titulos[$indice];
            $clave     = $claves[$indice];
            $existente = isset($existentes[$clave]) ? $existentes[$clave] : null;

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

            /* El título del payload y el guardado no son iguales letra por letra, pero para MySQL
               sí: se dice cuál es la fila que se va a tocar y que el título guardado no se cambia. */
            if ($existente !== null && $existente->titulo !== $titulo) {
                $fila_resultado['titulo_en_base'] = $existente->titulo;
                $fila_resultado['advertencia']    = 'El título mandado y el guardado se escriben distinto pero para '
                    . 'MySQL son el mismo (`titulo` es utf8mb4_unicode_ci: no distingue mayúsculas ni acentos), así '
                    . 'que esto ACTUALIZA la entrada "' . $existente->titulo . '" y no crea una nueva. El título '
                    . 'guardado NO se reescribe: si lo que querés es renombrarla, hacelo desde el ABM del panel.';
            }

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

        /* --- Freno 5, y el ORDEN de estas dos ramas importa. "No hay nada que cambiar" se resuelve
               ANTES que `confirm_count`, porque si no el reintento que el docblock declara seguro
               —el mismo body, tal cual— daba 422: traía `confirm_count=1` contra `cambiarian=0` y
               chocaba con la comparación exacta. No duplicaba nada (fallaba del lado seguro), pero
               la rama de 200 sólo se alcanzaba mandando `confirm_count=0` a propósito, que no es lo
               que hace un reintento. Acá no se afloja nada: con `cambiarian === 0` no hay ninguna
               escritura que confirmar. --- */
        if ($cambiarian === 0) {
            return $this->respuesta_de_escritura(
                $titulos,
                ['creadas' => 0, 'actualizadas' => 0, 'sin_cambio' => count($sin_cambio)],
                'No había nada que cambiar: las entradas mandadas ya están así en la base. No se escribió nada y '
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
                'confirm_token no corresponde a este conjunto: alguna entrada cambió respecto de la simulación. '
                    . 'No se escribió nada.',
                ['confirm_token_esperado' => $confirm_token]
            );
        }

        $resultados = ['creadas' => 0, 'actualizadas' => 0, 'sin_cambio' => count($sin_cambio)];
        $colision   = null;

        /* El lote entra o no entra: medio protocolo cargado es peor que ninguno, porque el que
           llamó no tiene cómo saber dónde se cortó. */
        try {
            DB::transaction(function () use ($cambios, &$resultados, &$colision) {
                $ids_tocados = [];

                foreach ($cambios as $cambio) {
                    /* 🔴 Se resuelve la fila con la comparación de MySQL —la misma que después va a
                       decidir si el UPDATE pega o no— y con `orderBy('id')` para que sea la misma
                       fila siempre. */
                    $existente = ProtocolEntry::query()
                        ->where('titulo', $cambio['titulo'])
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();

                    if ($existente !== null) {
                        /* Red de última instancia, y la única que no depende de que
                           `clave_de_titulo()` le haya acertado a la colación: si dos títulos del
                           lote terminan resolviendo a la MISMA fila, el lote entero se cae. Sin
                           esto, el segundo pisaba al primero y la respuesta decía
                           "creadas:1, actualizadas:1" con una sola fila en la base. */
                        if (in_array((int) $existente->id, $ids_tocados, true)) {
                            $colision = ['titulo' => $cambio['titulo'], 'titulo_en_base' => $existente->titulo];
                            throw new \RuntimeException('colision de titulos en el lote');
                        }
                        $ids_tocados[] = (int) $existente->id;

                        $existente->update($cambio['propuesto']);
                        $resultados['actualizadas']++;
                        continue;
                    }

                    $nueva = ProtocolEntry::create(
                        array_merge(['titulo' => $cambio['titulo']], $cambio['propuesto'])
                    );
                    $ids_tocados[] = (int) $nueva->id;
                    $resultados['creadas']++;
                }
            });
        } catch (\RuntimeException $e) {
            if ($colision === null) {
                throw $e;
            }

            return $this->error_422(
                'Dos títulos del lote resolvieron a la misma fila del protocolo ("' . $colision['titulo_en_base']
                    . '"), porque para MySQL son el mismo título. No se escribió nada: el lote entero se dio vuelta.',
                ['titulo' => $colision['titulo'], 'titulo_en_base' => $colision['titulo_en_base']]
            );
        }

        /* Auditoría. 🔴 Nunca se loguea la clave del header: solo qué se escribió. */
        Log::channel('daily')->info('ClaudeProtocolEntriesController: protocolo de ventas actualizado.', [
            'resultados' => $resultados,
            'titulos'    => $titulos,
        ]);

        return $this->respuesta_de_escritura($titulos, $resultados, null);
    }

    /**
     * La respuesta de un `dry_run=false`, con las entradas tal cual quedaron.
     *
     * @param array<int, string>   $titulos    Títulos del lote.
     * @param array<string, int>   $resultados Contadores.
     * @param string|null          $nota       Nota opcional.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_de_escritura(array $titulos, array $resultados, $nota)
    {
        $cuerpo = [
            'dry_run'    => false,
            'resultados' => $resultados,
            'entradas'   => ProtocolEntry::query()
                ->whereIn('titulo', $titulos)
                ->orderBy('categoria')
                ->orderBy('id')
                ->get(),
        ];

        if ($nota !== null) {
            $cuerpo['nota'] = $nota;
        }

        return response()->json($cuerpo, 200);
    }

    /**
     * 🔴 LA COMPARACIÓN DE TÍTULOS NO PUEDE SER `===`, Y ESTE ES EL CASO CONCRETO POR EL QUE NO.
     * ------------------------------------------------------------------------------------------
     * `protocol_entries.titulo` es `varchar(255)` con colación **`utf8mb4_unicode_ci`** (medido el
     * 3/9/2026 con `SELECT COLLATION_NAME FROM information_schema.COLUMNS` sobre
     * `admin_testing_s4`). Esa colación compara **sin distinguir mayúsculas ni acentos**: para
     * MySQL, `'Cómo abrir la conversación' = 'Como abrir la conversacion'` es VERDADERO. O sea que
     * el `where('titulo', ...)` del UPDATE y el `whereIn` de la búsqueda usan una equivalencia
     * distinta de la que usa `===` en PHP, y de esa grieta salían dos agujeros medidos:
     *
     *   a) Lote con "ZZ Objecion de precio" y "zz objecion de precio", las dos nuevas: el freno del
     *      repetido no las veía iguales, la simulación decía `cambiarian=2`, la respuesta decía
     *      `{"creadas":1,"actualizadas":1}` y **en la base quedaba UNA fila** — la segunda escritura
     *      encontraba a la primera y la pisaba.
     *   b) Con "Cómo abrir la conversación" ya cargada, mandar "Como abrir la conversacion" daba
     *      simulación `accion: crear` con `actual` todo en null y después
     *      `{"creadas":0,"actualizadas":1}`: **pisaba la fila existente y le borraba los campos que
     *      no venían en el payload** (`estado_aplicable`, `followup_numero`, `notas_setter`), porque
     *      el arrastre de campos ausentes salía de un `$existente` que el código creía inexistente.
     *      31 de los 46 títulos reales del protocolo tienen acento o eñe, así que el error más
     *      probable —comerse una tilde al retipear— borraba contenido mostrando un diff que juraba
     *      que no pisaba nada.
     *
     * Por eso la clave es minúsculas + acentos plegados: es la misma equivalencia que hace la base.
     *
     * ⚠️ Es una APROXIMACIÓN de `utf8mb4_unicode_ci`, construida para ser **igual o más gruesa** que
     * la de MySQL sobre alfabeto latino, que es lo que tiene el protocolo. Si en algún caso raro
     * quedara más fina, no se pierde nada: la escritura resuelve la fila con la comparación de MySQL
     * y aborta el lote entero si dos títulos caen en la misma fila (ver `store_json()`). El error
     * posible del otro lado es un 422 de más, que es el lado seguro.
     *
     * ⚠️ NO SE PUDO CONFIRMAR LA COLACIÓN DE PRODUCCIÓN, sólo la de `admin_testing_s4`. Si allá
     * fuera una `_as_cs` (que distingue acentos y mayúsculas), el problema directamente no se daría
     * y este arreglo no molesta: dos títulos que difieren en un acento serían dos filas, y el 422
     * del repetido sería un poco más estricto de lo necesario. Anda en los dos casos.
     *
     * @param mixed $titulo Título crudo.
     *
     * @return string
     */
    protected function clave_de_titulo($titulo): string
    {
        $clave = mb_strtolower(trim((string) $titulo), 'UTF-8');

        /* `utf8mb4_unicode_ci` es PAD SPACE: los espacios del final no cuentan para la comparación. */
        return rtrim(strtr($clave, self::PLIEGUE_DE_ACENTOS));
    }

    /**
     * Indexa las filas existentes por la clave normalizada del título. Si dos filas cayeran en la
     * misma clave (posible: la tabla no tiene índice único), gana la de menor id, que es la misma
     * que resuelve la escritura con su `orderBy('id')`.
     *
     * @param \Illuminate\Support\Collection $filas Filas traídas de la base.
     *
     * @return array<string, ProtocolEntry>
     */
    protected function indexar_por_clave($filas): array
    {
        $indice = [];

        foreach ($filas as $fila) {
            $clave = $this->clave_de_titulo($fila->titulo);
            if (! isset($indice[$clave])) {
                $indice[$clave] = $fila;
            }
        }

        return $indice;
    }

    /**
     * El primer campo de texto de la fila que no entra en su columna, medido en BYTES.
     *
     * @param array<string, mixed> $fila Payload de una entrada.
     *
     * @return array<string, mixed>|null Null si entran todos.
     */
    protected function campo_pasado_de_bytes(array $fila)
    {
        foreach (self::CAMPOS_DE_TEXTO as $campo) {
            if (! isset($fila[$campo]) || is_array($fila[$campo])) {
                continue;
            }

            $valor = (string) $fila[$campo];
            /* `strlen()` a propósito: cuenta BYTES, que es lo que mide la columna. */
            $bytes = strlen($valor);
            if ($bytes > self::MAX_BYTES_TEXT) {
                return ['campo' => $campo, 'bytes' => $bytes, 'caracteres' => mb_strlen($valor, 'UTF-8')];
            }
        }

        return null;
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

        foreach (self::CAMPOS_DE_TEXTO as $campo) {
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
     * 🔴 EL TOKEN SE ARMA SOBRE UNA ESTRUCTURA SERIALIZADA ENTERA, NO CONCATENANDO CON
     * SEPARADORES, Y ESO TAMPOCO ES ESTILO. `titulo` es texto libre de 255 caracteres y no se valida
     * contra `:` ni `|`. Mientras el token fue
     * `sha256('protocolo|' . implode('|', ["<titulo>:<json>", ...]))`, un lote de UNA entrada cuyo
     * título fuera `'<A>:<json de A>|<B>'` armaba EXACTAMENTE la misma cadena que el lote de dos
     * entradas A y B — mismo token, y con él se podía escribir un conjunto distinto del que se
     * revisó. Medido el 3/9/2026. Con `json_encode()` de la estructura completa las comillas de
     * adentro salen escapadas, así que ningún contenido puede fabricar un límite.
     *
     * ⚠️ Mismo criterio en `ClaudeFollowupRulesController::calcular_confirm_token()`. Ahí la parte
     * izquierda es un slug de lista cerrada y el agujero no se podía explotar, pero los dos tokens
     * se arman igual para que nadie tenga que averiguar cuál de los dos era el seguro.
     *
     * @param array<int, array<string, mixed>> $cambios Lista ya resuelta.
     *
     * @return string
     */
    protected function calcular_confirm_token(array $cambios): string
    {
        $partes = [];
        foreach ($cambios as $cambio) {
            $partes[] = $this->serializar([
                'titulo'         => $cambio['titulo'],
                'titulo_en_base' => array_key_exists('titulo_en_base', $cambio) ? $cambio['titulo_en_base'] : null,
                'propuesto'      => $cambio['propuesto'],
            ]);
        }
        sort($partes);

        return substr(
            hash('sha256', $this->serializar(['dominio' => 'protocolo', 'cambios' => $partes])),
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
