<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Prueba que las credenciales de Zipnova y de Mercado Pago cargadas para las demos AUTENTIQUEN
 * contra el servicio real (misión cruzada credenciales-demo-zipnova-mp, 14/10/2026).
 *
 * Existe para el botón "Probar" de Configuración → Implementación: cargar una credencial mal
 * copiada no falla al guardar (solo se valida el formato), falla horas después, cuando un demo-setup
 * deja la demo sin envíos o sin cobros. Acá se ve en el momento.
 *
 * 🔴 Reglas que no se aflojan:
 *  - Solo lecturas: GET /v2/accounts (Zipnova) y GET /users/me (Mercado Pago). Nada se crea ni se cobra.
 *  - Timeout corto: es un botón de un panel, no un proceso. Si el servicio no responde, se dice.
 *  - Nunca devuelve ni loguea una credencial: ni en la respuesta, ni en el log, ni en el motivo. El
 *    motivo es un código cerrado (ver MOTIVO_*), jamás el texto de una excepción o de la respuesta.
 *  - No cambia ningún estado: si la prueba falla, las credenciales quedan guardadas como estaban.
 */
class DemoCredentialsProbeService
{
    /** Zipnova: endpoint de cuentas de la API v2 (el mismo que usa el conector de empresa-api). */
    const URL_ZIPNOVA_CUENTAS = 'https://api.zipnova.com.ar/v2/accounts';

    /** Mercado Pago: endpoint del usuario dueño del access token. */
    const URL_MERCADOPAGO_USUARIO = 'https://api.mercadopago.com/users/me';

    /** Segundos máximos de espera por respuesta. */
    const TIMEOUT_SEGUNDOS = 8;

    /** Motivo: no hay un par de credenciales completo cargado. */
    const MOTIVO_NO_CARGADA = 'no_cargada';

    /** Motivo: hay credenciales guardadas pero no se pudieron descifrar (APP_KEY distinta). */
    const MOTIVO_ILEGIBLE = 'ilegible';

    /** Motivo: el servicio respondió 401 o 403. */
    const MOTIVO_RECHAZADAS = 'credenciales_rechazadas';

    /** Motivo: no se pudo conectar o venció el timeout. */
    const MOTIVO_SIN_RESPUESTA = 'sin_respuesta';

    /** Motivo: el servicio respondió otro código de error (se agrega el número: 'error_http_500'). */
    const MOTIVO_ERROR_HTTP = 'error_http_';

    /** Motivo: falló algo inesperado de este lado. */
    const MOTIVO_ERROR_INESPERADO = 'error_inesperado';

    /**
     * Prueba las credenciales de Zipnova cargadas para las demos.
     *
     * @return array{cargada: bool, autentica: bool, cuenta: string|null, motivo: string|null, aviso: string|null}
     */
    public function probar_zipnova(): array
    {
        $estado = ImplementationSettings::estado_zipnova_demo();

        // Algo guardado que no se puede leer: se dice, porque "no cargada" mandaría a buscar donde no es.
        if (! $estado['cargada']) {
            return $this->resultado(false, false, null, $estado['ilegible'] ? self::MOTIVO_ILEGIBLE : self::MOTIVO_NO_CARGADA);
        }

        $credenciales = ImplementationSettings::get_zipnova_demo_credenciales();

        try {
            $respuesta = Http::withBasicAuth($credenciales['api_token'], $credenciales['api_secret'])
                ->acceptJson()
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->get(self::URL_ZIPNOVA_CUENTAS);
        } catch (ConnectionException $e) {
            // El mensaje de la excepción NO se loguea ni se devuelve: no se sabe qué trae.
            Log::warning('DemoCredentialsProbeService: Zipnova no respondió (timeout o conexión caída).');

            return $this->resultado(true, false, null, self::MOTIVO_SIN_RESPUESTA);
        } catch (\Throwable $e) {
            Log::warning('DemoCredentialsProbeService: error inesperado al probar Zipnova (' . get_class($e) . ').');

            return $this->resultado(true, false, null, self::MOTIVO_ERROR_INESPERADO);
        }

        if (! $respuesta->successful()) {
            return $this->resultado(true, false, null, $this->motivo_de_la_respuesta($respuesta->status()));
        }

        // data[] = las cuentas a las que dan acceso las credenciales: {id, name, company_name, ...}.
        $cuentas = $respuesta->json('data');
        $cuentas = is_array($cuentas) ? $cuentas : [];

        // Si se cargó un account id, se busca esa cuenta; si no, se muestra la primera.
        $cuenta_elegida = null;
        foreach ($cuentas as $cuenta) {
            if (! is_array($cuenta)) {
                continue;
            }

            if ($estado['account_id'] !== '' && isset($cuenta['id']) && (string) $cuenta['id'] === $estado['account_id']) {
                $cuenta_elegida = $cuenta;
                break;
            }

            if ($cuenta_elegida === null) {
                $cuenta_elegida = $cuenta;
            }
        }

        // Aviso (no error): el account id cargado no es de ninguna cuenta de estas credenciales.
        $aviso = null;
        if ($estado['account_id'] !== '' && $cuenta_elegida !== null && (string) ($cuenta_elegida['id'] ?? '') !== $estado['account_id']) {
            $aviso = 'El id de cuenta cargado no coincide con ninguna cuenta de estas credenciales. En el demo-setup gana lo que dice Zipnova.';
        }

        $nombre = null;
        if ($cuenta_elegida !== null) {
            $nombre = trim((string) ($cuenta_elegida['name'] ?? ($cuenta_elegida['company_name'] ?? '')));
            $nombre = $nombre !== '' ? $nombre : null;
        }

        return $this->resultado(true, true, $nombre, null, $aviso);
    }

    /**
     * Prueba las credenciales de Mercado Pago cargadas para las demos.
     *
     * Solo se prueba el access token (la public key no autentica nada por sí sola).
     *
     * @return array{cargada: bool, autentica: bool, cuenta: string|null, motivo: string|null, aviso: string|null}
     */
    public function probar_mercadopago(): array
    {
        $estado = ImplementationSettings::estado_mercadopago_demo();

        if (! $estado['cargada']) {
            return $this->resultado(false, false, null, $estado['ilegible'] ? self::MOTIVO_ILEGIBLE : self::MOTIVO_NO_CARGADA);
        }

        $credenciales = ImplementationSettings::get_mercadopago_demo_credenciales();

        try {
            $respuesta = Http::withToken($credenciales['access_token'])
                ->acceptJson()
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->get(self::URL_MERCADOPAGO_USUARIO);
        } catch (ConnectionException $e) {
            Log::warning('DemoCredentialsProbeService: Mercado Pago no respondió (timeout o conexión caída).');

            return $this->resultado(true, false, null, self::MOTIVO_SIN_RESPUESTA);
        } catch (\Throwable $e) {
            Log::warning('DemoCredentialsProbeService: error inesperado al probar Mercado Pago (' . get_class($e) . ').');

            return $this->resultado(true, false, null, self::MOTIVO_ERROR_INESPERADO);
        }

        if (! $respuesta->successful()) {
            return $this->resultado(true, false, null, $this->motivo_de_la_respuesta($respuesta->status()));
        }

        // Del usuario se muestra solo el apodo: el mail y el resto son datos personales que el panel no necesita.
        $nombre = trim((string) $respuesta->json('nickname'));

        // Un access token de producción en las demos cobraría plata de verdad: se avisa.
        $aviso = $estado['tipo_access_token'] === 'APP_USR'
            ? 'El access token cargado es de PRODUCCIÓN (APP_USR). Para las demos conviene uno de prueba (TEST).'
            : null;

        return $this->resultado(true, true, $nombre !== '' ? $nombre : null, null, $aviso);
    }

    /**
     * Código cerrado de motivo según el estado HTTP de un fallo.
     *
     * @param int $status Código HTTP de la respuesta.
     *
     * @return string
     */
    private function motivo_de_la_respuesta(int $status): string
    {
        if ($status === 401 || $status === 403) {
            return self::MOTIVO_RECHAZADAS;
        }

        return self::MOTIVO_ERROR_HTTP . $status;
    }

    /**
     * Arma la respuesta con siempre las mismas claves.
     *
     * @param bool        $cargada   Hay un par de credenciales completo.
     * @param bool        $autentica El servicio las aceptó.
     * @param string|null $cuenta    Nombre de la cuenta (apodo, en Mercado Pago).
     * @param string|null $motivo    Código cerrado de por qué no autentica.
     * @param string|null $aviso     Observación que no es un error.
     *
     * @return array{cargada: bool, autentica: bool, cuenta: string|null, motivo: string|null, aviso: string|null}
     */
    private function resultado(bool $cargada, bool $autentica, $cuenta, $motivo, $aviso = null): array
    {
        return [
            'cargada'   => $cargada,
            'autentica' => $autentica,
            'cuenta'    => $cuenta,
            'motivo'    => $motivo,
            'aviso'     => $aviso,
        ];
    }
}
