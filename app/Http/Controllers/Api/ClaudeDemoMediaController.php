<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Multimedia de la demo, para la API de Claude.
 *
 * Hermano de {@see DemoMediaController} —la pantalla /multimedia-demo del admin— pero detrás de
 * `claude.task.key` en vez de `auth:sanctum`.
 *
 * 🔴 POR QUÉ EXISTE.
 *
 * Los clips de la demo los produce una sesión de Claude Code parada en la raíz del pool (skill
 * `/filmar`): escribe el guion, genera la voz, filma, monta y publica el mp4 en R2. Pero el
 * último paso —apuntar esa URL en el slot del catálogo— era el único que no podía hacer, porque
 * `PUT /demo-media` vive detrás de la sesión de usuario del admin. Resultado, medido el
 * 27/8/2026: el clip `0.1` estaba publicado y verificado desde el 26/8 y el panel del lead
 * seguía sirviendo el placeholder del intro viejo, y al 1.2 le pasó lo mismo apenas se subió.
 *
 * Un clip publicado que nadie apuntó es un clip que no existe para el lead. Y quedan ~16 por
 * filmar, así que el paso manual se repetiría en cada uno.
 *
 * 🔴 LA VALIDACIÓN Y EL GUARDADO NO SE DUPLICAN: se delega en {@see DemoMediaController}.
 *
 * Las reglas de qué es un slot válido, qué significa una URL vacía (borrar la fila y volver al
 * placeholder) y cómo se persiste son las mismas para los dos canales. Copiarlas acá crearía dos
 * definiciones de "URL válida" que se separan en cuanto alguien toque una sola. Si mañana la
 * pantalla del admin acepta algo nuevo, este endpoint lo acepta también, sin tocar este archivo.
 */
class ClaudeDemoMediaController extends Controller
{
    /**
     * GET /api/claude/demo-media
     *
     * Devuelve los slots del catálogo sincronizado y el mapa de URLs ya cargadas, para que la
     * sesión que filma pueda ver qué slot le corresponde a un clip y qué hay cargado hoy antes
     * de escribir nada.
     *
     * ⚠️ Si el catálogo todavía no se sincronizó desde GitHub, `slots` viene vacío — y en ese
     * caso `update_json()` va a rechazar CUALQUIER slot_id. Es la señal de que primero hay que
     * sincronizar el catálogo, no de que el slot no exista.
     *
     * @return JsonResponse
     */
    public function index_json(): JsonResponse
    {
        return (new DemoMediaController())->index_json();
    }

    /**
     * PUT /api/claude/demo-media
     *
     * Guarda (o borra, si `url` viene vacía o null) la URL de un slot puntual.
     *
     * Cuerpo: `{"slot_id": "1.2", "url": "https://media.comerciocity.store/1.2.mp4"}`
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_json(Request $request): JsonResponse
    {
        return (new DemoMediaController())->update_json($request);
    }
}
