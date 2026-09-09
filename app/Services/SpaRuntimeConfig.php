<?php

namespace App\Services;

/**
 * Renderiza el `config.js` que `empresa-spa` lee EN RUNTIME para conocer las variables propias de
 * cada frente (`VUE_APP_API_URL`, `VUE_APP_APP_URL`, la key de Pusher, etc.).
 *
 * 🔴 ES UN CONTRATO ENTRE DOS REPOS, no un detalle de formato. Del lado de la SPA,
 * `src/runtime_config.js` hace `window.__CC_CONFIG__[key]` con los MISMOS nombres `VUE_APP_*` que
 * antes leía de `process.env`, y cae a `process.env[key]` si el objeto no está o no trae la clave.
 * De ahí las tres reglas:
 *
 *  - las claves son los nombres `VUE_APP_*` tal cual: sin renombrar, sin prefijar;
 *  - los valores son SIEMPRE strings, igual que en un `.env`. El código de la SPA compara con
 *    `'true'`/`'false'` y un booleano JSON lo rompería en silencio;
 *  - el archivo es JavaScript ejecutable (`<script src="config.js">` síncrono en `index.html`),
 *    así que se escribe la asignación a la global y no un JSON pelado.
 *
 * Es una clase pura a propósito: sin config, sin base, sin SSH. Lo que sale de acá se compara byte
 * a byte en un test contra lo que la SPA espera.
 *
 * Nace en la misión `actualizar-sin-el-vps` (9/9/2026). Hasta entonces cada `ClientVersionUpgrade`
 * compilaba la SPA UNA VEZ POR FRENTE en el VPS de builds sólo para cocinar estas variables en el
 * bundle. Con `config.js` el bundle es uno por versión (lo publica GitHub Actions en el release) y
 * lo que cambia por frente viaja en este archivo, que escribe `DeploymentService::step_upload_spa()`.
 */
final class SpaRuntimeConfig
{
    /** Nombre de la global que lee `empresa-spa/src/runtime_config.js`. */
    const VARIABLE_GLOBAL = 'window.__CC_CONFIG__';

    /**
     * Contenido completo de `config.js` para un conjunto de variables.
     *
     * @param array<string, mixed> $vars Variables `VUE_APP_*` => valor. Los valores escalares se
     *                                   convierten a string y las claves conservan el orden recibido.
     *
     * @return string Una sola línea `window.__CC_CONFIG__ = {...};` con salto de línea final.
     *
     * @throws \InvalidArgumentException Si algún valor no es escalar (un array u objeto no es una
     *                                   variable de entorno).
     * @throws \RuntimeException         Si el conjunto no se puede serializar (texto que no es UTF-8).
     */
    public static function render(array $vars): string
    {
        $valores = [];

        foreach ($vars as $clave => $valor) {
            if ($valor !== null && ! is_scalar($valor)) {
                throw new \InvalidArgumentException(
                    'La variable ' . $clave . ' del config.js del SPA no es un escalar: '
                    . 'las variables de entorno son strings.'
                );
            }

            $valores[(string) $clave] = (string) $valor;
        }

        /*
         * JSON_UNESCAPED_SLASHES: las URLs se leen como URLs (`https://api-x…`, no `https:\/\/`).
         * JSON_UNESCAPED_UNICODE: `VUE_APP_ATTEMPT_TEXT` y compañía llevan tildes que un humano tiene
         *   que poder leer en el archivo. U+2028/U+2029 siguen escapados (PHP no los libera sin
         *   JSON_UNESCAPED_LINE_TERMINATORS), así que el resultado sigue siendo JavaScript válido.
         * JSON_FORCE_OBJECT: con cero variables sale `{}` y no `[]`; la SPA indexa por clave.
         */
        $json = json_encode($valores, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);

        if ($json === false) {
            throw new \RuntimeException(
                'No se pudo serializar la configuración de runtime del SPA: ' . json_last_error_msg()
            );
        }

        return self::VARIABLE_GLOBAL . ' = ' . $json . ";\n";
    }
}
