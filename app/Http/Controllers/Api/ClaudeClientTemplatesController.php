<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alta y lectura de las plantillas de CLIENTE desde afuera, por Claude.
 *
 * El circuito real es este: Lucas y Claude diseñan las plantillas, Lucas las hace aprobar en Meta,
 * y después Claude las carga acá con la clave del header. No hay pantalla de alta: el que escribe
 * es un proceso externo, y por eso la validación es explícita y detallada (a diferencia del resto
 * del backend, que valida en el frontend porque el que llama es el SPA).
 *
 * 🔴 Dos propiedades que no son adorno y hay que conservar si esto se toca:
 *
 *  1. IDEMPOTENCIA POR `template_name`. Claude no manda diffs: reenvía el lote entero cada vez que
 *     corrige una descripción, una categoría o un orden. Sin idempotencia, la segunda corrida deja
 *     la tabla con la plantilla repetida y el selector mostrándola dos veces.
 *  2. ES ESTRICTAMENTE ADITIVO: nunca borra una fila que no vino en el payload. Mismo criterio que
 *     ClaudeVersionItemsIngestController. Un lote parcial (o un lote mal armado) no puede vaciar
 *     el selector de soporte.
 *
 * Nada de esto toca `followup_templates`: las plantillas de lead las levanta el motor de
 * seguimiento automático y son otro juego.
 */
class ClaudeClientTemplatesController extends Controller
{
    /**
     * Devuelve todo lo cargado, para que Claude vea el estado antes de escribir.
     *
     * No crea ni modifica nada: es el hermano de lectura de store_json(), y sirve para que un
     * alta se arme sobre lo que ya hay en vez de a ciegas.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        $templates = ClientTemplate::query()
            ->orderBy('categoria_orden')
            ->orderBy('categoria')
            ->orderBy('template_name')
            ->get();

        return response()->json([
            'templates'  => $templates,
            'total'      => $templates->count(),
            'categorias' => $this->resumen_de_categorias($templates),
        ], 200);
    }

    /**
     * Alta idempotente de un lote de plantillas de cliente.
     *
     * Reenviar el mismo `template_name` actualiza la fila; nunca crea una segunda. Lo que no vino
     * en el payload se queda donde está.
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        // Validación de contrato externo: este endpoint lo llama Claude con la clave del header,
        // no el SPA, así que acá sí conviene validar campo por campo.
        $request->validate([
            'templates'                              => 'required|array|min:1',
            'templates.*.template_name'              => 'required|string|max:120',
            'templates.*.language_code'              => 'nullable|string|max:10',
            'templates.*.categoria'                  => 'required|string|max:60',
            'templates.*.categoria_label'            => 'nullable|string|max:120',
            'templates.*.categoria_orden'            => 'nullable|integer|min:1|max:9999',
            'templates.*.titulo'                     => 'nullable|string|max:200',
            'templates.*.body_template'              => 'nullable|string',
            'templates.*.descripcion'                => 'nullable|string',
            'templates.*.variables'                  => 'nullable|array',
            'templates.*.variables.*.placeholder'    => 'required|string|max:20',
            'templates.*.variables.*.label'          => 'required|string|max:120',
            'templates.*.variables.*.field'          => 'nullable|string|max:60',
            'templates.*.variables.*.ai_suggestable' => 'nullable|boolean',
            'templates.*.activa'                     => 'nullable|boolean',
        ]);

        // Filas del payload, filtradas con is_array por robustez aunque la validación ya lo cubra.
        $filas = array_values(array_filter((array) $request->input('templates', []), 'is_array'));

        $resultados = ['creadas' => 0, 'actualizadas' => 0];

        // Nombres tocados en esta corrida: se usa al final para devolver exactamente las filas que
        // el payload afectó, no la tabla entera.
        $nombres_tocados = [];

        // El lote entra o no entra: si una fila explota a mitad, no puede quedar medio lote cargado
        // y la mitad afuera, porque Claude no tiene cómo saber dónde se cortó.
        DB::transaction(function () use ($filas, &$resultados, &$nombres_tocados) {
            foreach ($filas as $fila) {
                // Un nombre con espacios de más rompería la idempotencia: la segunda corrida no
                // encontraría la fila y crearía una gemela. Se trimea antes de buscar y de guardar.
                $template_name = trim((string) ($fila['template_name'] ?? ''));
                if ($template_name === '') {
                    continue;
                }

                // El lock es lo que hace que la idempotencia aguante dos corridas encimadas.
                //
                // Sin él esto es un SELECT y después un INSERT: dos requests con el mismo nombre
                // nuevo pueden pasar las dos por el SELECT antes de que cualquiera inserte, y el
                // índice único hace que la segunda tire una QueryException. Como el lote entero
                // va en una transacción, esa excepción voltea las cincuenta plantillas de esa
                // corrida, no solo la que chocó, y Claude recibe un 500 sin haber guardado nada.
                // No es hipotético: el docblock de este endpoint dice que Claude reenvía el lote
                // completo cada vez, y un timeout con reintento alcanza para encimar dos.
                $existente = ClientTemplate::query()
                    ->where('template_name', $template_name)
                    ->lockForUpdate()
                    ->first();

                $datos = $this->fila_de_datos($fila, $template_name, $existente);

                if ($existente !== null) {
                    $existente->update($datos);
                    $resultados['actualizadas']++;
                } else {
                    ClientTemplate::create($datos);
                    $resultados['creadas']++;
                }

                $nombres_tocados[] = $template_name;
            }
        });

        $templates = ClientTemplate::query()
            ->whereIn('template_name', $nombres_tocados)
            ->orderBy('categoria_orden')
            ->orderBy('categoria')
            ->orderBy('template_name')
            ->get();

        // Auditoría de la ingesta. 🔴 Nunca se loguea la clave del header, ni la recibida ni la
        // configurada: solo qué se cargó.
        Log::channel('daily')->info('ClaudeClientTemplatesController: alta de plantillas de cliente.', [
            'resultados'     => $resultados,
            'template_names' => $nombres_tocados,
        ]);

        return response()->json([
            'resultados' => $resultados,
            'templates'  => $templates,
        ], 200);
    }

    /**
     * Arma la fila de columnas a guardar a partir del payload de una plantilla.
     *
     * En una actualización, un campo opcional ausente NO borra lo que ya estaba: Claude puede
     * mandar un lote corregido sin repetir la descripción entera, y perderla en silencio sería
     * peor que ignorar el campo.
     *
     * @param  array               $fila          Payload de una plantilla, ya validado.
     * @param  string              $template_name Nombre de Meta, ya trimeado.
     * @param  ClientTemplate|null $existente     Fila que ya estaba, si la había.
     * @return array<string, mixed>
     */
    protected function fila_de_datos(array $fila, string $template_name, $existente): array
    {
        $datos = ['template_name' => $template_name];

        // Campos nullable que se pisan solo si vinieron en el payload.
        $opcionales = ['categoria_label', 'titulo', 'body_template', 'descripcion'];
        foreach ($opcionales as $campo) {
            if (array_key_exists($campo, $fila)) {
                $datos[$campo] = $fila[$campo] !== null ? trim((string) $fila[$campo]) : null;
            }
        }

        // `language_code` no es nullable en la tabla: un null explícito del payload se ignora en
        // vez de romper el insert. Un idioma vacío no es un dato, es un campo que no vino.
        if (array_key_exists('language_code', $fila) && trim((string) $fila['language_code']) !== '') {
            $datos['language_code'] = trim((string) $fila['language_code']);
        }

        // `categoria` es required en la validación, así que siempre llega.
        $datos['categoria'] = trim((string) ($fila['categoria'] ?? ''));

        if (array_key_exists('categoria_orden', $fila) && $fila['categoria_orden'] !== null) {
            $datos['categoria_orden'] = (int) $fila['categoria_orden'];
        }

        if (array_key_exists('variables', $fila)) {
            $datos['variables'] = $fila['variables'] !== null
                ? array_values(array_filter((array) $fila['variables'], 'is_array'))
                : null;
        }

        if (array_key_exists('activa', $fila) && $fila['activa'] !== null) {
            $datos['activa'] = filter_var($fila['activa'], FILTER_VALIDATE_BOOLEAN);
        }

        // Defaults de alta: solo para una fila nueva. En una actualización, no mandarlos deja lo
        // que la fila ya tenía.
        if ($existente === null) {
            if (! array_key_exists('language_code', $datos)) {
                $datos['language_code'] = 'es_AR';
            }
            if (! array_key_exists('activa', $datos)) {
                $datos['activa'] = true;
            }
        }

        return $datos;
    }

    /**
     * Cuenta cuántas plantillas hay por categoría, con su etiqueta y su orden.
     *
     * Se calcula sobre la colección ya cargada y no con un group by en SQL a propósito: la
     * etiqueta y el orden pasan por los accessors del modelo, que son los que tapan las filas
     * cargadas sin esos campos. Un group by crudo devolvería NULL justo en esos casos.
     *
     * @param  \Illuminate\Support\Collection $templates
     * @return array<int, array<string, mixed>>
     */
    protected function resumen_de_categorias($templates): array
    {
        $categorias = [];

        foreach ($templates as $template) {
            $slug = (string) $template->categoria;

            if (! isset($categorias[$slug])) {
                $categorias[$slug] = [
                    'categoria'       => $slug,
                    'categoria_label' => (string) $template->categoria_label,
                    'categoria_orden' => (int) $template->categoria_orden,
                    'cantidad'        => 0,
                ];
            }

            $categorias[$slug]['cantidad']++;
        }

        return array_values($categorias);
    }
}
