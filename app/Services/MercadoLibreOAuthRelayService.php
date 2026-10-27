<?php

namespace App\Services;

/**
 * Reenvía el callback de OAuth de Mercado Libre al empresa-api del cliente que lo pidió.
 *
 * Por qué existe (misión mercado-libre-integracion, 27/10/2026): Mercado Libre exige que la
 * `redirect_uri` esté registrada EXACTA en la app (`4899702990695294`, "ComercioCity ERP"), y hoy solo
 * tiene la de demo2 y la de ht52. Con una URL por cliente eso no escala. Entonces TODOS los ERP mandan a
 * Mercado Libre la misma `redirect_uri` —`https://api-admin.comerciocity.com/api/mercadolibre/callback`—
 * y acá se reenvía el `code` al sistema que lo originó. Es el mismo mecanismo que el de Mercado Pago.
 *
 * 🔴 ANTI OPEN-REDIRECT: se hereda TAL CUAL de MercadoPagoOAuthRelayService. El destino NUNCA sale de
 * la request: el host del `state` (`<64 alfanuméricos>_<host de la API del ERP>`) se busca en
 * `client_apis.url` / `demos.erp_api_url` y la URL se arma desde la base registrada más una ruta FIJA.
 * Esta clase no pisa ningún método: solo cambia la ruta fija y el prefijo del log.
 *
 * La ruta fija es una ruta WEB del empresa-api (no `/api/...`): `routes/web.php` →
 * `/platform-connector/mercadolibre/callback`. La base normalizada ya trae `/public` en shared hosting,
 * así que el destino queda `https://<host>/public/platform-connector/mercadolibre/callback` (shared) o
 * `https://<host>/platform-connector/mercadolibre/callback` (VPS).
 *
 * El admin NO tiene el `client_secret` de la app de Mercado Libre ni canjea nada: el canje del `code`
 * sigue en cada ERP (con esta misma URL como `redirect_uri`, que ML exige idéntica en los dos pasos).
 */
class MercadoLibreOAuthRelayService extends MercadoPagoOAuthRelayService
{
    /**
     * Ruta FIJA del callback de Mercado Libre en el empresa-api (ruta web). Nunca sale de la request.
     */
    const ERP_CALLBACK_PATH = '/platform-connector/mercadolibre/callback';

    /**
     * Prefijo de los mensajes de log de este relay.
     */
    const LOG_TAG = '[ML_OAUTH_RELAY]';
}
