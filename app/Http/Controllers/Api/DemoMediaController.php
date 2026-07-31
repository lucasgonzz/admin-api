<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DemoMedia;
use App\Services\DemoCatalogoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pantalla de multimedia editable de la demo (grupo 300, prompt 02 —
 * `contexto/demo_experiencia.md` §3.18): un único GET arma toda la pantalla a partir del
 * catálogo sincronizado (estructura, desde el repo) más las URLs cargadas (desde `demo_media`),
 * y un único PUT guarda/borra la URL de un slot puntual.
 */
class DemoMediaController extends Controller
{
    /**
     * GET /demo-media
     *
     * Devuelve la lista de slots del catálogo (estructura, ordenada) junto con el mapa de URLs
     * ya cargadas. Un solo request para pintar toda la pantalla del admin.
     *
     * @return JsonResponse
     */
    public function index_json(): JsonResponse
    {
        return response()->json([
            'slots' => DemoCatalogoService::slots(),
            'urls'  => DemoMedia::url_por_slot(),
        ], 200);
    }

    /**
     * PUT /demo-media
     *
     * Guarda (o borra, si `url` viene vacía/null) la URL de un slot puntual.
     *
     * Valida que `slot_id` exista en el catálogo sincronizado: evita filas huérfanas de slots
     * que ya no existen (por ejemplo, si un clip se sacó del repo). Si el catálogo todavía no
     * está sincronizado, `slots()` devuelve `[]` y CUALQUIER slot_id falla la validación — es el
     * comportamiento esperado: no hay forma de confirmar que el slot exista.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_json(Request $request): JsonResponse
    {
        // Ids válidos según el catálogo sincronizado: la validación de slot_id se resuelve
        // contra esta lista, no contra un enum fijo en código (el catálogo puede crecer sin
        // deploy, ver DemoCatalogoService).
        $slot_ids_validos = collect(DemoCatalogoService::slots())
            ->pluck('id')
            ->filter()
            ->all();

        $validated = $request->validate([
            'slot_id' => 'required|string|in:' . implode(',', $slot_ids_validos),
            // No se usa la regla nativa "url" a secas: "nullable" solo exceptúa null, no exceptúa
            // el string vacío (que es el caso "borrar" del criterio de éxito #4), y "url" sí
            // rechazaría "" por no ser una URL válida. El closure de abajo solo exige formato de
            // URL cuando el valor viene realmente no vacío.
            'url'     => ['nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                $limpio = trim((string) $value);
                if ($limpio !== '' && ! filter_var($limpio, FILTER_VALIDATE_URL)) {
                    $fail('El campo url debe ser una URL válida.');
                }
            }],
        ]);

        // Normaliza: string vacío se trata igual que null (ambos son "sin cargar").
        $url = trim((string) ($validated['url'] ?? ''));

        if ($url === '') {
            // Guardar vacío borra la fila: es como se vuelve al placeholder.
            DemoMedia::query()->where('slot_id', $validated['slot_id'])->delete();

            return response()->json([
                'slot_id' => $validated['slot_id'],
                'url'     => null,
            ], 200);
        }

        // updateOrCreate por slot_id: crea la fila si es la primera vez que se carga este slot,
        // o actualiza la existente sin duplicar.
        $media = DemoMedia::query()->updateOrCreate(
            ['slot_id' => $validated['slot_id']],
            ['url' => $url]
        );

        return response()->json([
            'slot_id' => $media->slot_id,
            'url'     => $media->url,
        ], 200);
    }
}
