<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FollowupTemplate;
use App\Services\MetaTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Crear, listar y consultar plantillas de WhatsApp EN META desde afuera del navegador.
 *
 * Es el punto 4 de la misión M5 del programa «embudo comercial». Lo que resuelve: hasta hoy, para
 * tener una plantilla nueva alguien tenía que entrar a Meta Business Suite con la sesión de Lucas,
 * cargarla a mano (`contexto/cuentas_meta.md` del repo de conocimiento) y después registrarla en el
 * admin con `POST claude/followup-templates`. O sea que `/leads` —que corre de noche, sin Lucas—
 * podía proponer el texto de una plantilla pero no tenerla nunca.
 *
 * 🔴 NO ES UN HERMANO DE `POST claude/followup-templates`, Y CONFUNDIRLOS SALE CARO. Aquél REGISTRA
 * en la base una plantilla que YA está aprobada en Meta, y es idempotente a propósito porque Claude
 * reenvía el lote entero cada vez. Éste la CREA en Meta: es una escritura en un sistema de un
 * tercero, con revisión humana de hasta 24 horas, un tope de 100 creaciones por hora por WABA y un
 * nombre que después no se puede reusar. Por eso acá:
 *
 *   1. `dry_run` viene en TRUE por defecto, como en todos los endpoints de escritura peligrosa de
 *      este bloque. Sin `dry_run=false` explícito no se llama a Meta ni se escribe una fila.
 *   2. Un `template_name` que ya tiene fila es 422 y NO se reenvía a Meta. No hay «upsert»: crear
 *      dos veces la misma plantilla consume cupo del tope por hora y deja a Meta decidiendo cuál
 *      gana. Para traer el estado de una que ya existe está `{name}/refresh`.
 *   3. La fila nace con `activa = false` y `meta_status = PENDING`. Quien la enciende es Meta al
 *      aprobarla, vía `templates:sync-meta` o `refresh` — nunca este endpoint.
 *   4. Las reglas de formato de Meta se validan ACÁ, antes de salir a la red: nombre, largo,
 *      y los tres defectos que Meta rechaza automáticamente (variable al principio, variable al
 *      final, dos variables seguidas). Mandarlas a que las rechace Meta gasta una de las 100
 *      creaciones por hora y devuelve un error opaco.
 *
 * 🔴 `estado` SIGUE SIENDO `manual_*`, y no es cosmético. `LeadFollowupService::find_template_for()`
 * busca con `where('estado', $lead->status)`: una plantilla cuyo `estado` coincida con un slug real
 * del pipeline la empieza a disparar SOLA el cron de cada 2 horas, a todos los leads de ese estado.
 * Este endpoint fuerza el prefijo `manual_` y rechaza un `estado` que no lo tenga — a diferencia de
 * `POST claude/followup-templates`, que acepta cualquiera porque es un alta genérica y la
 * convención corre por cuenta de quien arma el payload. Acá el payload lo arma `/leads` sola, de
 * noche: la convención tiene que ser del código.
 */
class ClaudeMetaTemplatesController extends Controller
{
    /**
     * Tope de caracteres del cuerpo de una plantilla.
     *
     * Es el límite de Meta para el componente BODY. Lo pide explícitamente el plan de M5 y se
     * valida acá para no gastar una creación del tope por hora en un texto que va a rebotar.
     */
    const MAX_CARACTERES_CUERPO = 1024;

    /**
     * Las dos categorías que este endpoint acepta.
     *
     * `AUTHENTICATION` existe en Meta y queda afuera a propósito: es para códigos de un solo uso,
     * tiene su propia plantilla con botón de copiado y no se envía con el componente `body` de
     * texto posicional que es lo único que sabe armar `WhatsappSendService::send_template()`.
     */
    const CATEGORIAS = ['UTILITY', 'MARKETING'];

    /**
     * Crea una plantilla en Meta y la registra en `followup_templates`.
     *
     * @param Request             $request Body: name, category, language, body, ejemplos[], uso,
     *                                     estado, dia_numero, dry_run.
     * @param MetaTemplateService $meta
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request, MetaTemplateService $meta)
    {
        /* En try/catch y no `validate()` pelado, por lo mismo que ClaudeLeadsOutboundController:
           sin un `Accept: application/json` del otro lado, Laravel responde un 302 en vez del 422 y
           eso es indiagnosticable desde una skill. */
        try {
            $request->validate([
                'name'       => 'required|string|max:120',
                'category'   => 'required|string|max:20',
                'language'   => 'nullable|string|max:10',
                'body'       => 'required|string',
                'ejemplos'   => 'nullable|array',
                'ejemplos.*' => 'required|string|max:120',
                'uso'        => 'required|string|max:255',
                'estado'     => 'nullable|string|max:40',
                'dia_numero' => 'nullable|integer|min:1',
                'dry_run'    => 'nullable|boolean',
            ], [
                'name.required'     => 'name es obligatorio: es el nombre con el que la plantilla queda en Meta.',
                'category.required' => 'category es obligatoria: UTILITY cuando retoma algo que el lead pidió, MARKETING para nutrición.',
                'body.required'     => 'body es obligatorio: es el texto de la plantilla, con sus {{n}}.',
                'uso.required'      => 'uso es obligatorio: es el texto corto que dice CUÁNDO usarla, y es lo que le '
                    . 'deja elegir plantilla a /leads sin leer el cuerpo de las treinta y ocho.',
                'ejemplos.*.required' => 'Cada ejemplo tiene que tener un valor: Meta rechaza una muestra vacía.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Parámetros inválidos. No se creó nada en Meta.',
                'errors'  => $e->errors(),
            ], 422);
        }

        $name     = trim((string) $request->input('name', ''));
        $cuerpo   = trim((string) $request->input('body', ''));
        $uso      = trim((string) $request->input('uso', ''));
        $idioma   = trim((string) $request->input('language', '')) !== ''
            ? trim((string) $request->input('language'))
            : 'es_AR';
        $categoria = strtoupper(trim((string) $request->input('category', '')));
        $ejemplos  = $this->normalizar_ejemplos($request->input('ejemplos', []));

        if (! in_array($categoria, self::CATEGORIAS, true)) {
            return response()->json([
                'message'            => 'category tiene que ser UTILITY o MARKETING. No se creó nada en Meta.',
                'motivo'             => 'categoria_invalida',
                'categorias_validas' => self::CATEGORIAS,
            ], 422);
        }

        /* --- Validación del formato de Meta, toda antes de salir a la red. --- */
        $errores = $this->errores_de_formato($name, $cuerpo, $ejemplos);
        if (! empty($errores)) {
            return response()->json([
                'message' => 'La plantilla no cumple las reglas de Meta. No se creó nada: Meta la habría '
                    . 'rechazado igual y habría consumido una de las 100 creaciones por hora.',
                'motivo'  => 'formato_invalido',
                'errors'  => ['plantilla' => $errores],
            ], 422);
        }

        /* --- Freno 2: no se crea dos veces la misma. --- */
        $ya_existe = FollowupTemplate::query()->where('template_name', $name)->first();
        if ($ya_existe !== null) {
            return response()->json([
                'message' => 'Ya hay una plantilla registrada con el nombre "' . $name . '" (id ' . (int) $ya_existe->id
                    . ', estado en Meta: ' . ($ya_existe->meta_status !== null ? $ya_existe->meta_status : 'sin consultar')
                    . '). No se creó nada en Meta. Si querés traer su estado actual, '
                    . 'POST claude/templates/meta/' . $name . '/refresh. Si querés una variante, usá otro nombre (_v2).',
                'motivo'    => 'nombre_ya_registrado',
                'plantilla' => $this->resumen($ya_existe),
            ], 422);
        }

        $estado = $this->resolver_estado($request->input('estado'), $name);
        if ($estado === null) {
            return response()->json([
                'message' => 'estado tiene que empezar con "manual_". Un estado que coincida con un slug real del '
                    . 'pipeline haría que el cron de seguimientos (cada 2 horas) le empiece a mandar esta plantilla '
                    . 'sola a todos los leads de ese estado. No se creó nada en Meta.',
                'motivo'  => 'estado_invalido',
            ], 422);
        }

        $componentes = $this->armar_componentes($cuerpo, $ejemplos);

        /* --- Freno 1: dry_run por defecto. --- */
        /* `filled` y no `boolean('dry_run', true)`: un `"dry_run": null` o `""` explícito daría
           false y CREARÍA la plantilla en Meta. Ausente o vacío es simulación; mismo patrón que el
           lote de envío. */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run === true) {
            return response()->json([
                'dry_run' => true,
                'creada'  => false,
                'message' => 'Simulación: no se creó nada en Meta ni se escribió ninguna fila. Para crearla de '
                    . 'verdad, repetí la llamada con dry_run=false.',
                'se_mandaria_a_meta' => [
                    'name'             => $name,
                    'language'         => $idioma,
                    'category'         => $categoria,
                    'parameter_format' => 'POSITIONAL',
                    'components'       => $componentes,
                ],
                'se_registraria_como' => [
                    'template_name' => $name,
                    'estado'        => $estado,
                    'dia_numero'    => $this->resolver_dia_numero($request->input('dia_numero')),
                    'activa'        => false,
                    'meta_status'   => 'PENDING',
                    'uso'           => $uso,
                    'language_code' => $idioma,
                ],
                'variables' => $this->placeholders($cuerpo),
            ], 200);
        }

        $respuesta = $meta->crear([
            'name'       => $name,
            'language'   => $idioma,
            'category'   => $categoria,
            'components' => $componentes,
        ]);

        if ($respuesta['ok'] !== true) {
            return response()->json([
                'creada'  => false,
                'message' => 'Meta no creó la plantilla: ' . ($respuesta['mensaje'] !== null ? $respuesta['mensaje'] : 'sin detalle')
                    . '. No se escribió ninguna fila.',
                'motivo'  => $respuesta['motivo'],
                'http'    => $respuesta['http'],
                /* 🔴 La señal del plan B. Con 401/403 no hay reintento que sirva: la credencial no
                   tiene permiso de plantillas sobre esa WABA y la plantilla se carga a mano con el
                   procedimiento de Chrome. Que venga explícito evita que una skill reintente
                   veinte minutos contra una puerta cerrada. */
                'plan_b'  => $respuesta['motivo'] === 'sin_permiso_en_meta'
                    ? 'La clave de Kapso no tiene permiso de plantillas sobre esta WABA. Cargala a mano con el '
                        . 'procedimiento de contexto/cuentas_meta.md (Meta Business Suite con Chrome) y después '
                        . 'registrala con POST claude/followup-templates.'
                    : null,
            ], 422);
        }

        $estado_de_meta = isset($respuesta['datos']['status']) && trim((string) $respuesta['datos']['status']) !== ''
            ? strtoupper(trim((string) $respuesta['datos']['status']))
            : 'PENDING';

        $fila = FollowupTemplate::create([
            'template_name'    => $name,
            'estado'           => $estado,
            'dia_numero'       => $this->resolver_dia_numero($request->input('dia_numero')),
            'body_template'    => $cuerpo,
            'language_code'    => $idioma,
            /* 🔴 `activa = false` SIEMPRE, incluso si Meta contestara APPROVED de una. Una plantilla
               recién creada no puede quedar disponible para enviarse en la misma corrida: la activa
               el sync o el refresh después de confirmar el estado contra Meta, que es el único lugar
               donde vive esa decisión. */
            'activa'           => false,
            'meta_status'      => $estado_de_meta,
            'meta_category'    => isset($respuesta['datos']['category']) && trim((string) $respuesta['datos']['category']) !== ''
                ? strtoupper(trim((string) $respuesta['datos']['category']))
                : $categoria,
            'meta_template_id' => isset($respuesta['datos']['id']) ? (string) $respuesta['datos']['id'] : null,
            'meta_rechazo'     => null,
            'meta_synced_at'   => now(),
            'components'       => $componentes,
            'uso'              => $uso,
            'solo_si_ingreso_confirmado' => false,
        ]);

        Log::channel('daily')->info('ClaudeMetaTemplatesController: plantilla creada en Meta y registrada.', [
            'template_name' => $name,
            'estado'        => $estado,
            'meta_status'   => $estado_de_meta,
            'categoria'     => $categoria,
        ]);

        return response()->json([
            'dry_run'   => false,
            'creada'    => true,
            'message'   => 'Plantilla creada en Meta y registrada con activa=false y meta_status=' . $estado_de_meta
                . '. Meta revisa hasta 24 horas: consultá POST claude/templates/meta/' . $name . '/refresh, o esperá '
                . 'a que templates:sync-meta la active sola cuando pase a APPROVED.',
            'plantilla' => $this->resumen($fila),
            'variables' => $this->placeholders($cuerpo),
        ], 200);
    }

    /**
     * Lista las plantillas registradas con su estado de Meta y su `uso`.
     *
     * Lee la tabla local, no Meta, y eso es deliberado: la tabla la refresca `templates:sync-meta`
     * cada hora, así que una corrida de `/leads` que empieza eligiendo plantilla no paga una
     * llamada a la red (ni se queda sin biblioteca si Kapso está caído). Para el estado de UNA
     * plantilla al instante está `{name}/refresh`.
     *
     * @param Request $request Query: status, category, activa, estado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        try {
            $request->validate([
                'status'   => 'nullable|string|max:20',
                'category' => 'nullable|string|max:20',
                'activa'   => 'nullable|boolean',
                'estado'   => 'nullable|string|max:40',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Parámetros inválidos.',
                'errors'  => $e->errors(),
            ], 422);
        }

        $query = FollowupTemplate::query();

        if (trim((string) $request->input('status', '')) !== '') {
            $query->where('meta_status', strtoupper(trim((string) $request->input('status'))));
        }
        if (trim((string) $request->input('category', '')) !== '') {
            $query->where('meta_category', strtoupper(trim((string) $request->input('category'))));
        }
        if ($request->filled('activa')) {
            $query->where('activa', $request->boolean('activa') ? 1 : 0);
        }
        if (trim((string) $request->input('estado', '')) !== '') {
            $query->where('estado', trim((string) $request->input('estado')));
        }

        $filas = $query->orderBy('estado')->orderBy('dia_numero')->orderBy('id')->get();

        $data = [];
        foreach ($filas as $fila) {
            $data[] = $this->resumen($fila);
        }

        return response()->json([
            'data'  => $data,
            'count' => count($data),
            /* Para que quien lea esto sepa de cuándo es el estado sin tener que mirar fila por fila:
               una biblioteca sincronizada hace tres días no es una biblioteca al día. */
            'sincronizada_mas_vieja' => $filas->whereNotNull('meta_synced_at')->min('meta_synced_at'),
            'sin_sincronizar'        => $filas->whereNull('meta_synced_at')->count(),
        ], 200);
    }

    /**
     * Consulta el estado de UNA plantilla en Meta y lo baja a la fila.
     *
     * Es lo que le deja a `/leads` crear una plantilla y volver a mirarla un rato después dentro de
     * la misma corrida, sin esperar la hora del scheduler.
     *
     * @param Request             $request
     * @param string              $name
     * @param MetaTemplateService $meta
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh_json(Request $request, $name, MetaTemplateService $meta)
    {
        $name = trim((string) $name);

        $fila = FollowupTemplate::query()->where('template_name', $name)->first();
        if ($fila === null) {
            return response()->json([
                'message' => 'No hay ninguna plantilla registrada con el nombre "' . $name . '". '
                    . 'GET claude/templates/meta lista las que hay.',
                'motivo'  => 'no_registrada',
            ], 404);
        }

        $respuesta = $meta->estado($name);
        if ($respuesta['ok'] !== true) {
            return response()->json([
                'message' => 'No se pudo consultar el estado en Meta: '
                    . ($respuesta['mensaje'] !== null ? $respuesta['mensaje'] : 'sin detalle')
                    . '. La fila quedó como estaba.',
                'motivo'  => $respuesta['motivo'],
                'http'    => $respuesta['http'],
                'plan_b'  => $respuesta['motivo'] === 'sin_permiso_en_meta'
                    ? 'La clave de Kapso no tiene permiso de plantillas sobre esta WABA. El estado se mira a mano '
                        . 'en Meta Business Suite (contexto/cuentas_meta.md).'
                    : null,
                'plantilla' => $this->resumen($fila),
            ], 422);
        }

        $cambio = $meta->aplicar_estado_de_meta($fila, $respuesta['datos']);

        return response()->json([
            'message' => $this->mensaje_del_refresh($name, $cambio),
            'cambio'  => $cambio,
            'plantilla' => $this->resumen($fila->fresh()),
        ], 200);
    }

    /**
     * Mensaje legible de qué pasó en un refresh.
     *
     * @param string $name
     * @param array  $cambio Lo que devolvió aplicar_estado_de_meta().
     *
     * @return string
     */
    protected function mensaje_del_refresh(string $name, array $cambio): string
    {
        if ($cambio['existe_en_meta'] !== true) {
            return 'Meta no devolvió la plantilla "' . $name . '" al consultarla por nombre. No se cambió su '
                . 'estado ni se desactivó: una consulta vacía no es un rechazo. Revisá el nombre, o mirala a mano '
                . 'en Meta Business Suite.';
        }
        if ($cambio['activada'] === true) {
            return 'La plantilla "' . $name . '" pasó a APPROVED y quedó activa: ya se puede enviar.';
        }
        if ($cambio['desactivada'] === true) {
            return 'La plantilla "' . $name . '" quedó en ' . $cambio['estado_nuevo'] . ' y se desactivó: no se '
                . 'puede enviar. El motivo de Meta quedó guardado en meta_rechazo.';
        }
        if ($cambio['cambio'] === true) {
            return 'La plantilla "' . $name . '" pasó de ' . ($cambio['estado_anterior'] !== null ? $cambio['estado_anterior'] : 'sin estado')
                . ' a ' . $cambio['estado_nuevo'] . '.';
        }

        return 'La plantilla "' . $name . '" sigue en ' . ($cambio['estado_nuevo'] !== null ? $cambio['estado_nuevo'] : 'sin estado') . '.';
    }

    /**
     * Los defectos de formato que Meta rechaza automáticamente, más los nuestros.
     *
     * 🔴 Las tres reglas del cuerpo —no empezar con variable, no terminar con variable, no poner
     * dos seguidas— no son una interpretación: son las que ya documenta
     * `comercial/plantillas_chequeo_diario.md` como «romperlas es rechazo automático», y las que
     * los diez textos del chequeo diario respetan. Validarlas acá convierte un rechazo de Meta de
     * hasta 24 horas más tarde en un 422 inmediato que dice qué arreglar.
     *
     * @param string               $name
     * @param string               $cuerpo
     * @param array<int, string>   $ejemplos
     *
     * @return array<int, string> Lista de errores legibles; vacía si está bien.
     */
    protected function errores_de_formato(string $name, string $cuerpo, array $ejemplos): array
    {
        $errores = [];

        if (preg_match('/^[a-z0-9_]+$/', $name) !== 1) {
            $errores[] = 'El nombre "' . $name . '" no cumple ^[a-z0-9_]+$: Meta solo acepta minúsculas, números y '
                . 'guiones bajos. Convención de ComercioCity: prefijo cc_ y versionado con _v2.';
        }

        if ($cuerpo === '') {
            $errores[] = 'El cuerpo no puede estar vacío.';

            return $errores;
        }

        /* mb_strlen y no strlen: el tope de Meta es de caracteres, y un texto con acentos y «» (o
           sea, todos los nuestros) tiene más bytes que caracteres. Con strlen se rechazarían textos
           válidos. */
        if (mb_strlen($cuerpo) > self::MAX_CARACTERES_CUERPO) {
            $errores[] = 'El cuerpo tiene ' . mb_strlen($cuerpo) . ' caracteres y el tope de Meta es de '
                . self::MAX_CARACTERES_CUERPO . '.';
        }

        $numeros = $this->placeholders($cuerpo);

        /* Numeración: 1..N sin saltos. Un {{1}} y un {{3}} sin {{2}} se aprueba y después el envío
           manda dos parámetros que Meta asigna al 1 y al 2: el mensaje sale con el texto corrido. */
        $esperados = range(1, count($numeros));
        if (count($numeros) > 0 && $numeros !== $esperados) {
            $errores[] = 'Las variables tienen que ser {{1}}, {{2}}… sin saltos ni repetidas, y vinieron: {{'
                . implode('}}, {{', $numeros) . '}}.';
        }

        $recortado = trim($cuerpo);
        if (preg_match('/^\{\{\s*\d+\s*\}\}/', $recortado) === 1) {
            $errores[] = 'El cuerpo no puede EMPEZAR con una variable: Meta lo rechaza automáticamente. '
                . 'Poné texto fijo adelante (ej. "Hola {{1}}", no "{{1}}, …").';
        }
        if (preg_match('/\{\{\s*\d+\s*\}\}$/', $recortado) === 1) {
            $errores[] = 'El cuerpo no puede TERMINAR con una variable: Meta lo rechaza automáticamente. '
                . 'Cerrá con texto fijo después del último hueco.';
        }
        if (preg_match('/\{\{\s*\d+\s*\}\}[\s,.;:!?-]*\{\{\s*\d+\s*\}\}/', $cuerpo) === 1) {
            $errores[] = 'No puede haber dos variables seguidas (ni separadas solo por espacios o puntuación): '
                . 'Meta lo rechaza automáticamente. Meté texto fijo entre las dos.';
        }

        if (count($numeros) !== count($ejemplos)) {
            $errores[] = 'Hacen falta ' . count($numeros) . ' ejemplo(s) —uno por variable, en orden— y llegaron '
                . count($ejemplos) . '. Meta exige una muestra por hueco para poder revisar la plantilla.';
        }

        return $errores;
    }

    /**
     * Los números de las variables `{{n}}` del cuerpo, en orden de aparición y sin repetir.
     *
     * @param string $cuerpo
     *
     * @return array<int, int>
     */
    protected function placeholders(string $cuerpo): array
    {
        $numeros = [];
        if (preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $cuerpo, $coincidencias) === false) {
            return $numeros;
        }

        foreach ($coincidencias[1] as $crudo) {
            $numero = (int) $crudo;
            if (! in_array($numero, $numeros, true)) {
                $numeros[] = $numero;
            }
        }

        return $numeros;
    }

    /**
     * Arma el array `components` tal como lo espera Meta.
     *
     * Solo el componente BODY: ni header, ni footer, ni botones. No es una simplificación — es lo
     * único que `WhatsappSendService::send_template()` sabe enviar (arma `['type' => 'body',
     * 'parameters' => […]]` y nada más). Aprobar una plantilla con header o botones con variables
     * daría una plantilla imposible de mandar desde este admin.
     *
     * @param string             $cuerpo
     * @param array<int, string> $ejemplos
     *
     * @return array<int, array<string, mixed>>
     */
    protected function armar_componentes(string $cuerpo, array $ejemplos): array
    {
        $body = [
            'type' => 'BODY',
            'text' => $cuerpo,
        ];

        /* `example.body_text` es un array DE arrays (una muestra por conjunto de variables), y con
           variables vacío no va la clave: Meta rechaza un `example` sin valores. */
        if (! empty($ejemplos)) {
            $body['example'] = ['body_text' => [array_values($ejemplos)]];
        }

        return [$body];
    }

    /**
     * Resuelve el `estado` de la fila, forzando la convención `manual_*`.
     *
     * @param mixed  $crudo Lo que mandó el llamador, o null.
     * @param string $name  Nombre de la plantilla, del que se deriva el estado si no vino.
     *
     * @return string|null null si el llamador mandó un estado que no empieza con `manual_`.
     */
    protected function resolver_estado($crudo, string $name): ?string
    {
        $pedido = trim((string) $crudo);
        if ($pedido !== '') {
            if (strpos($pedido, 'manual_') !== 0) {
                return null;
            }

            return substr($pedido, 0, 40);
        }

        /* Derivado del nombre: cc_coord_dia_acordado -> manual_coord_dia_acordado. El prefijo `cc_`
           se saca porque es la marca de la cuenta, no parte de para qué sirve la plantilla. */
        $slug = strpos($name, 'cc_') === 0 ? substr($name, 3) : $name;
        $slug = preg_replace('/[^a-z0-9_]/', '', strtolower($slug));
        if ($slug === '' || $slug === null) {
            $slug = 'plantilla';
        }

        /* 40 es el largo de la columna `estado`: se recorta acá y no se descubre en el insert. */
        return substr('manual_' . $slug, 0, 40);
    }

    /**
     * `dia_numero` de la fila. La columna es NOT NULL, así que siempre tiene que haber uno.
     *
     * Para una plantilla manual el día no significa nada (no hay cadencia: el cron no la levanta
     * nunca), pero `LeadFollowupService::find_template_for()` ordena por esta columna y el panel
     * también, así que 1 es el valor correcto para «la primera y única de su estado».
     *
     * @param mixed $crudo
     *
     * @return int
     */
    protected function resolver_dia_numero($crudo): int
    {
        $numero = (int) $crudo;

        return $numero >= 1 ? $numero : 1;
    }

    /**
     * Normaliza los ejemplos: strings trimeados, sin vacíos, reindexados.
     *
     * @param mixed $crudos
     *
     * @return array<int, string>
     */
    protected function normalizar_ejemplos($crudos): array
    {
        if (! is_array($crudos)) {
            return [];
        }

        $ejemplos = [];
        foreach ($crudos as $valor) {
            if (is_array($valor)) {
                continue;
            }
            $texto = trim((string) $valor);
            if ($texto !== '') {
                $ejemplos[] = $texto;
            }
        }

        return $ejemplos;
    }

    /**
     * La fila como la necesita quien elige plantilla: estado de Meta, `uso` y el cuerpo.
     *
     * @param FollowupTemplate $fila
     *
     * @return array<string, mixed>
     */
    protected function resumen($fila): array
    {
        return [
            'id'               => (int) $fila->id,
            'template_name'    => $fila->template_name,
            'estado'           => $fila->estado,
            'dia_numero'       => (int) $fila->dia_numero,
            'uso'              => $fila->uso,
            'body_template'    => $fila->body_template,
            'language_code'    => $fila->language_code,
            'activa'           => (bool) $fila->activa,
            'meta_status'      => $fila->meta_status,
            'meta_category'    => $fila->meta_category,
            'meta_template_id' => $fila->meta_template_id,
            'meta_rechazo'     => $fila->meta_rechazo,
            'meta_synced_at'   => $fila->meta_synced_at,
            /* Qué significa cada {{n}}, derivado del cuerpo y de los ejemplos guardados. Es el
               accessor del modelo, el mismo que corrige el punto 5 de esta misión. */
            'variables'        => $fila->variables,
            'enviable'         => (bool) $fila->activa && (string) $fila->meta_status === 'APPROVED',
        ];
    }
}
