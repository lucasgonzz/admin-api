<?php

namespace App\Support;

/**
 * Regla única de qué variables de .env son secretos.
 *
 * Antes vivía privada dentro de EnvBulkChangeService. Se extrajo para que la plantilla .env
 * (EnvTemplate) y el cambio masivo (env-bulk) decidan lo mismo sobre qué es sensible: si cada uno
 * tuviera su propia lista, una variable podría quedar cifrada en la plantilla y en claro en el
 * historial del lote (o al revés) sin que nadie lo note.
 *
 * Un valor sensible nunca se guarda ni se devuelve en claro por ninguna respuesta: sólo
 * enmascarado.
 */
class EnvSensitiveKeys
{
    /**
     * Fragmentos que marcan una variable como sensible: su valor nunca se guarda ni se devuelve
     * en claro, sólo enmascarado.
     *
     * @var array<int, string>
     */
    const FRAGMENTOS = ['KEY', 'SECRET', 'PASSWORD', 'PASS', 'TOKEN', 'DSN', 'CREDENTIAL'];

    /**
     * Variables sensibles que ningún fragmento de arriba atrapa, y que igual llevan un secreto.
     *
     * Las `*_URL` de conexión traen `usuario:password@host` adentro: Laravel 8 soporta DATABASE_URL,
     * REDIS_URL y MAIL_URL de forma nativa, y ninguna matchea PASSWORD ni KEY. MAIL_USERNAME parece
     * inofensivo hasta que el proveedor es SendGrid, donde el username literal es la API key.
     *
     * Este endpoint acepta CUALQUIER nombre de variable, no sólo las del template, así que la lista
     * es un piso y no una garantía: ante la duda, agregá la variable acá.
     *
     * @var array<int, string>
     */
    const NOMBRES = ['DATABASE_URL', 'DB_URL', 'REDIS_URL', 'MAIL_URL', 'QUEUE_URL', 'MAIL_USERNAME'];

    /**
     * Indica si el valor de esa variable es un secreto que no debe guardarse ni devolverse en claro.
     *
     * @param  string  $key  Nombre de la variable (se compara sin distinguir mayúsculas).
     * @return bool
     */
    public static function es_sensible(string $key): bool
    {
        $upper = strtoupper($key);

        if (in_array($upper, self::NOMBRES, true)) {
            return true;
        }

        foreach (self::FRAGMENTOS as $fragmento) {
            if (strpos($upper, $fragmento) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enmascara el valor si la variable es sensible; si no, lo devuelve tal cual.
     *
     * Las variables que no son secretos (DB_HOST, APP_URL, MAIL_PORT) se muestran enteras: son las
     * que hace falta leer para confirmar que el cambio es el correcto.
     *
     * @param  string  $key    Nombre de la variable.
     * @param  string  $valor  Valor en claro.
     * @return string
     */
    public static function enmascarar(string $key, string $valor): string
    {
        if (! self::es_sensible($key)) {
            return $valor;
        }

        if ($valor === '') {
            return '(vacío)';
        }

        /* Valores cortos no muestran ningún prefijo: cuatro caracteres de una clave de ocho es mucho. */
        if (strlen($valor) <= 8) {
            return str_repeat('*', 8);
        }

        return substr($valor, 0, 4) . str_repeat('*', 8);
    }
}
