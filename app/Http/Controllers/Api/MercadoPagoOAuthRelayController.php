<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MercadoPagoOAuthRelayService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Callback central de OAuth de Mercado Pago (misión mp-redireccion-central-admin, 17/10/2026).
 *
 * Es la ÚNICA `redirect_uri` que se registra en la app de Mercado Pago:
 * `https://api-admin.comerciocity.com/api/mercadopago/callback`. Mercado Pago manda acá al
 * comerciante después de autorizar, y este controller lo devuelve al empresa-api del cliente que
 * inició la conexión. Toda la lógica (validar el `state`, resolver el destino solo contra la base)
 * vive en MercadoPagoOAuthRelayService.
 */
class MercadoPagoOAuthRelayController extends Controller
{
    /**
     * Recibe el redirect de Mercado Pago y responde 302 al ERP, o 422 con una página corta.
     *
     * Las dos respuestas llevan `Cache-Control: no-store` (el `code` viaja en la URL) y
     * `Referrer-Policy: no-referrer` (que ni el `code` ni el `state` se filtren por el Referer).
     *
     * @param  Request                       $request
     * @param  MercadoPagoOAuthRelayService  $relay
     * @return Response
     */
    public function callback(Request $request, MercadoPagoOAuthRelayService $relay): Response
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
            . '<h1 style="font-size:1.3rem">No pudimos completar la conexión con Mercado Pago</h1>'
            . '<p>El enlace no es válido o ya venció. Volvé a tu sistema e intentá conectar de nuevo.</p>'
            . '</body></html>';
    }
}
