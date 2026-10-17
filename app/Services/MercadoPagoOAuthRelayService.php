<?php

namespace App\Services;

use App\Models\ClientApi;
use App\Models\Demo;
use Illuminate\Support\Facades\Log;

/**
 * Reenvía el callback de OAuth de Mercado Pago al empresa-api del cliente que lo pidió.
 *
 * Por qué existe (misión mp-redireccion-central-admin, 17/10/2026): Mercado Pago exige que la
 * `redirect_uri` de la app esté registrada de antemano, y cada alta exige un SMS al celular de
 * Lucas. Con una URL por cliente eso no escala. Entonces TODOS los ERP mandan a Mercado Pago la
 * misma `redirect_uri` —la del admin— y acá se reenvía el `code` al sistema que lo originó.
 *
 * El `state` que arma el ERP es `<64 alfanuméricos>_<host de la API del ERP>`. El aleatorio es el que
 * el ERP ya guardó (`OauthState`); el host es solo el dato para saber a quién devolvérselo.
 *
 * 🔴 ANTI OPEN-REDIRECT: ESTO ES LO CRÍTICO. El destino de la redirección NUNCA sale de la request.
 * La request aporta un host, y ese host se BUSCA en la base del admin (`client_apis.url` y, si no
 * está, `demos.erp_api_url`). Si no está registrado, no hay redirección. Si está, la URL se arma
 * desde la base registrada (esquema y host de nuestra fila) más una ruta FIJA. Un atacante puede
 * elegir CUÁL de los sistemas registrados recibe el reenvío —y ese sistema rechaza un `state` que
 * no emitió—, pero jamás una URL propia.
 *
 * El admin NO tiene el `MP_APP_SECRET` ni canjea nada: el canje del `code` sigue en cada ERP. Acá
 * no se guarda ni se loguea ningún `code`: viaja en la URL y se reenvía tal cual.
 */
class MercadoPagoOAuthRelayService
{
    /**
     * Forma estricta del `state`: 64 alfanuméricos, un guion bajo y un hostname en minúsculas.
     *
     * El `_` no aparece en el aleatorio (alfanumérico) ni en un hostname válido, así que se parte sin
     * ambigüedad. Sin puerto, sin `/`, sin `@`, sin `:`. Modificador `D`: sin él, `$` aceptaría un
     * salto de línea final y el host llegaría con basura. El doble punto lo rechaza `parse_state()`
     * aparte, porque la regex sola lo deja pasar.
     */
    const STATE_PATTERN = '/^([A-Za-z0-9]{64})_([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)$/D';

    /**
     * Largo máximo de un hostname (RFC 1035).
     */
    const HOST_MAX_LENGTH = 253;

    /**
     * Ruta FIJA del callback en el empresa-api. Se concatena a la base registrada; nunca sale de la request.
     */
    const ERP_CALLBACK_PATH = '/api/integraciones/mercadopago/callback';

    /**
     * Tope de largo de cada parámetro que se reenvía (`code`, `error`, `error_description`).
     * Un `code` de Mercado Pago ronda los 60 caracteres: lo que pase de esto no es de Mercado Pago.
     */
    const PARAM_MAX_LENGTH = 2000;

    /**
     * Resuelve el callback recibido a la URL de retorno del ERP.
     *
     * Loguea el motivo cuando no se puede (con el host, NUNCA el `code` ni el aleatorio del `state`).
     *
     * @param  array  $query  Query string tal cual la mandó Mercado Pago (`code`, `state`, `error`, ...).
     * @return string|null  URL absoluta a la que redirigir, o null si no hay destino válido (→ 422).
     */
    public function resolve_redirect_url(array $query): ?string
    {
        $state = isset($query['state']) && is_string($query['state']) ? $query['state'] : '';

        $parsed = $this->parse_state($state);
        if ($parsed === null) {
            Log::warning('[MP_OAUTH_RELAY] state con formato inválido.', [
                'state_largo' => strlen($state),
                'host_crudo'  => $this->sanitize_for_log($this->raw_host_of($state)),
            ]);

            return null;
        }

        $base_url = $this->resolve_base_url($parsed['host']);
        if ($base_url === null) {
            Log::warning('[MP_OAUTH_RELAY] host no registrado en client_apis ni en demos.', [
                'host' => $parsed['host'],
            ]);

            return null;
        }

        return $this->build_target($base_url, $parsed['random'], $query);
    }

    /**
     * Parte y valida el `state`.
     *
     * @param  string  $state  Valor crudo de la query.
     * @return array{random: string, host: string}|null  null si no cumple la forma estricta.
     */
    public function parse_state(string $state): ?array
    {
        if (strlen($state) > 64 + 1 + self::HOST_MAX_LENGTH) {
            return null;
        }

        if (! preg_match(self::STATE_PATTERN, $state, $matches)) {
            return null;
        }

        $host = $matches[2];

        // La regex deja pasar "a..b": un hostname real no lo tiene.
        if (strpos($host, '..') !== false) {
            return null;
        }

        return ['random' => $matches[1], 'host' => $host];
    }

    /**
     * Busca el host SOLO contra la base del admin y devuelve la URL base del empresa-api (sin barra final).
     *
     * Prioridad: `client_apis.url` y, si no está, `demos.erp_api_url`. Se compara el host EXACTO (no un
     * sufijo ni un `LIKE` a secas): el `LIKE` solo preselecciona filas y después se filtra en PHP.
     * El host ya viene validado por `parse_state()` (solo `a-z 0-9 . -`), así que no hay comodines en el `LIKE`.
     *
     * @param  string  $host  Host ya validado por parse_state().
     * @return string|null  Base normalizada (con `/public` si es shared), o null si no hay registro.
     */
    public function resolve_base_url(string $host): ?string
    {
        $resolver = app(ClientEmpresaApiUrlResolver::class);

        $client_apis = ClientApi::where('url', 'like', '%' . $host . '%')->orderBy('id')->get();
        foreach ($client_apis as $client_api) {
            if ($this->host_of((string) $client_api->url) !== $host) {
                continue;
            }

            $base_url = $resolver->normalize_api_base_url($client_api->url, $client_api->hosting_type ?? 'shared_hosting');
            if ($this->is_safe_base($base_url, $host)) {
                return $base_url;
            }
        }

        $demos = Demo::where('erp_api_url', 'like', '%' . $host . '%')->orderBy('id')->get();
        foreach ($demos as $demo) {
            if ($this->host_of((string) $demo->erp_api_url) !== $host) {
                continue;
            }

            $base_url = $resolver->normalize_demo_api_base_url(
                $demo->erp_api_url,
                app(DemoPathResolver::class)->hosting_type($demo)
            );
            if ($this->is_safe_base($base_url, $host)) {
                return $base_url;
            }
        }

        return null;
    }

    /**
     * Arma la URL de retorno: base registrada + ruta fija + solo las claves permitidas.
     *
     * Se reenvían `code`, `error` y `error_description` tal cual (si son texto) y `state` con SOLO el
     * aleatorio (sin el sufijo del host), exactamente como lo guardó el ERP. Cualquier otra clave se descarta.
     *
     * @param  string  $base_url  Base ya resuelta contra la base del admin.
     * @param  string  $random    Los 64 alfanuméricos del state.
     * @param  array   $query     Query original (de ahí salen solo las tres claves permitidas).
     * @return string
     */
    public function build_target(string $base_url, string $random, array $query): string
    {
        $params = [];

        if ($this->forwardable($query, 'code')) {
            $params['code'] = $query['code'];
        }

        $params['state'] = $random;

        foreach (['error', 'error_description'] as $key) {
            if ($this->forwardable($query, $key)) {
                $params[$key] = $query[$key];
            }
        }

        return rtrim($base_url, '/') . self::ERP_CALLBACK_PATH . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * ¿La clave viene como texto no vacío y de largo razonable? (un array o un texto gigante no se reenvía)
     *
     * @param  array   $query
     * @param  string  $key
     * @return bool
     */
    protected function forwardable(array $query, string $key): bool
    {
        return isset($query[$key])
            && is_string($query[$key])
            && $query[$key] !== ''
            && strlen($query[$key]) <= self::PARAM_MAX_LENGTH;
    }

    /**
     * Host (en minúsculas) de una URL guardada en la base, o cadena vacía si no es una URL absoluta http/https.
     *
     * @param  string  $url
     * @return string
     */
    protected function host_of(string $url): string
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            return '';
        }

        // Defensa en profundidad: `parse_url` es más laxo que el parser de un navegador. Con una URL guardada
        // como `https://evil.com\@api-x.comerciocity.com` dice que el host es el segundo, pero el navegador
        // toma la barra invertida como `/` y va al primero. Una URL registrada con `\`, `@` o caracteres de
        // control/espacios no se usa nunca como destino de un reenvío.
        if (preg_match('/[\\\\@\x00-\x20\x7f]/', $url)) {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * Defensa en profundidad: la base normalizada tiene que ser http/https y su host el mismo que se pidió.
     *
     * @param  string  $base_url
     * @param  string  $host
     * @return bool
     */
    protected function is_safe_base(string $base_url, string $host): bool
    {
        return $base_url !== '' && $this->host_of($base_url) === $host;
    }

    /**
     * Lo que viene después del primer `_` del state, para el log cuando el formato es inválido.
     * Nunca se loguea la parte previa: es el aleatorio del state.
     *
     * @param  string  $state
     * @return string
     */
    protected function raw_host_of(string $state): string
    {
        $pos = strpos($state, '_');

        return $pos === false ? '' : substr($state, $pos + 1);
    }

    /**
     * Texto de la request apto para el log: sin caracteres de control ni nada raro, y acotado.
     *
     * @param  string  $value
     * @return string
     */
    protected function sanitize_for_log(string $value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9._:@\/-]/', '?', $value), 0, 120);
    }
}
