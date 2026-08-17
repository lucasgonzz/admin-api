<?php

namespace App\Http\Controllers;

use App\Models\DemoMedia;
use Illuminate\Http\JsonResponse;

/**
 * Hermano de LECTURA del canal de eventos de la demo (misión cruzada demo-panel-recorrido,
 * 17/8/2026): le devuelve a una instancia de demo el mapa de URLs de multimedia **de ahora**.
 *
 * Reusa tal cual la autenticación del canal de eventos ({@see \App\Http\Middleware\DemoEventosKey}):
 * el header `X-Demo-Eventos-Key` ES el identificador del lead, y para cuando se llega acá el
 * middleware ya resolvió el Lead y lo adjuntó a la request. Por eso el método no valida nada.
 *
 * 🔴 POR QUÉ EXISTE ESTE ENDPOINT, Y POR QUÉ NO ALCANZA CON EL PAYLOAD DEL SETUP.
 *
 * Hasta esta misión las URLs viajaban a la instancia ÚNICAMENTE adentro del payload del demo setup
 * (`RunDemoSetupService.php:503` → {@see DemoMedia::url_por_slot()} → `DemoSetupHelper.php:194` →
 * `demo_tracking_config.media_urls` del lado de empresa). O sea: la instancia se quedaba con una
 * FOTO del mapa sacada en el instante del setup, y esa foto nunca se refrescaba.
 *
 * El caso concreto que lo rompió, medido: el 17/8/2026 Lucas cargó 28 slots desde /multimedia-demo a
 * las 15:51, pero la instancia había recibido su mapa a las 15:02 — 49 minutos antes. Resultado:
 * `demo_tracking_config.media_urls` tenía una sola clave (`intro`), ningún `clip.id` del plan (`1.1`,
 * `2.1`, …) estaba adentro, y TODOS los clips del panel del lead decían "Este video todavía no está
 * disponible" aunque los links estuvieran cargados en el admin.
 *
 * Las URLs son CONTENIDO y se editan sin deploy; el plan es ESTRUCTURA y se congela. Un contenido que
 * se edita en cualquier momento no puede viajar sólo en un evento que ocurre una única vez.
 */
class DemoMediaUrlsController extends Controller
{
    /**
     * GET /api/demo-media-urls
     *
     * Devuelve el mapa `slot_id => url` completo y actual. Sin paginar y sin filtrar por lead: los
     * slots son el catálogo de la demo, iguales para todos los leads, y son a lo sumo unas decenas.
     *
     * 🔴 SI ESTÁS ACÁ PARA "SIMPLIFICARLO": este endpoint no es redundante con el payload del setup.
     * Sacarlo, o volver a leer las URLs desde lo que quedó guardado en la instancia, reinstala
     * exactamente el bug del 17/8/2026 descrito en el docblock de la clase — el mapa se congela en el
     * momento del setup y todo video cargado después queda invisible para el lead. La única forma de
     * que la instancia vea un link cargado hace un minuto es preguntarlo ahora.
     *
     * @return JsonResponse `{"media_urls": {"1.1": "https://...", "2.1": "..."}}`
     */
    public function index(): JsonResponse
    {
        // `url_por_slot()` ya filtra las filas sin URL (null o cadena vacía): una fila sin link es
        // "sin cargar todavía", no un slot con URL vacía. La clave del json es `media_urls` y es
        // parte del contrato con empresa-api: renombrarla rompe al consumidor, que no se despliega
        // al mismo tiempo que este lado.
        return response()->json(['media_urls' => DemoMedia::url_por_slot()], 200);
    }
}
