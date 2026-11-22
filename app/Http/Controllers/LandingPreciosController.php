<?php

namespace App\Http\Controllers;

use App\Services\PreciosVigentesSettings;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint público de sólo lectura con los precios vigentes y los costos de Meta por WhatsApp, que consume la
 * landing (comerciocity.com) para la sección de mensualidades y el botón «Ver costos de Meta» (misión
 * embudo-whatsapp-precios, etapa E1).
 *
 * `GET /api/landing/precios` → 200 con el contrato de §2.6 del PLAN SIN `actualizado_por`: el nombre de quien
 * editó no sale a internet. Sin credenciales; con cubeta propia por IP (`throttle:landing-precios`, en
 * `RouteServiceProvider`) y sin la de todo /api, como el resto de las rutas `landing/*`. CORS ya admite
 * `https://comerciocity.com` (`config/atribucion.php`).
 */
class LandingPreciosController extends Controller
{
    /** Segundos que el navegador y la CDN pueden reusar la respuesta. */
    private const SEGUNDOS_DE_CACHE = 300;

    /**
     * `GET /api/landing/precios`.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        return response()
            ->json(PreciosVigentesSettings::to_array_publico(), 200)
            ->header('Cache-Control', 'public, max-age=' . self::SEGUNDOS_DE_CACHE);
    }
}
