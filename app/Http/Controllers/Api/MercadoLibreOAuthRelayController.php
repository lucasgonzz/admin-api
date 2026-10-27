<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MercadoLibreOAuthRelayService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Callback central de OAuth de Mercado Libre (misión mercado-libre-integracion, 27/10/2026).
 *
 * Es la ÚNICA `redirect_uri` que se registra en la app de Mercado Libre:
 * `https://api-admin.comerciocity.com/api/mercadolibre/callback`. Mercado Libre manda acá al
 * comerciante después de autorizar, y este controller lo devuelve al empresa-api del cliente que
 * inició la conexión. Es el espejo de MercadoPagoOAuthRelayController: toda la lógica (validar el
 * `state`, resolver el destino solo contra la base) vive en MercadoLibreOAuthRelayService, que hereda
 * la de Mercado Pago.
 */
class MercadoLibreOAuthRelayController extends Controller
{
    /**
     * Recibe el redirect de Mercado Libre y responde 302 al ERP, o 422 con una página corta.
     *
     * Las dos respuestas llevan `Cache-Control: no-store` (el `code` viaja en la URL) y
     * `Referrer-Policy: no-referrer` (que ni el `code` ni el `state` se filtren por el Referer).
     *
     * @param  Request                        $request
     * @param  MercadoLibreOAuthRelayService  $relay
     * @return Response
     */
    public function callback(Request $request, MercadoLibreOAuthRelayService $relay): Response
    {
        $target = $relay->resolve_redirect_url($request->query());

        if ($target === null) {
            $response = response($this->error_page(), 422, ['Content-Type' => 'text/html; charset=UTF-8']);
        } else {
            $response = response('', 302, ['Location' => $target]);
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * Página de error genérica: sin detalle de por qué falló (eso va al log, no al navegador).
     *
     * @return string
     */
    protected function error_page(): string
    {
        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="referrer" content="no-referrer">'
            . '<title>No pudimos completar la conexión</title></head>'
            . '<body style="font-family:sans-serif;max-width:480px;margin:15vh auto;padding:0 16px;text-align:center">'
            . '<h1 style="font-size:1.3rem">No pudimos completar la conexión con Mercado Libre</h1>'
            . '<p>El enlace no es válido o ya venció. Volvé a tu sistema e intentá conectar de nuevo.</p>'
            . '</body></html>';
    }
}
