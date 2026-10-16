<?php

use App\Models\EnvTemplate;
use App\Support\EnvSensitiveKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Cifra los valores de las variables sensibles que la plantilla .env (env_templates) guardaba en claro.
 *
 * Desde esta migración, `env_templates.value` de una variable sensible (EnvSensitiveKeys: ANTHROPIC_API_KEY,
 * PUSHER_APP_SECRET, MAIL_PASSWORD...) lleva el prefijo `enc:v1:` + Crypt::encryptString. El modelo EnvTemplate
 * cifra solo al guardar y descifra solo al leer; esta migración cifra lo que ya estaba guardado.
 *
 * Se recorre con DB::table() a propósito: el modelo ya descifra al leer y re-cifraría por su cuenta, y acá
 * hace falta ver y escribir el valor CRUDO.
 *
 * Idempotente: un valor que ya trae el prefijo no se toca, así que correrla dos veces (o correrla después de
 * que el modelo ya haya cifrado alguna fila) no cifra dos veces. Sin foreign keys ni cambios de esquema.
 */
return new class extends Migration
{
    /**
     * Cifra los valores sensibles en claro.
     *
     * @return void
     */
    public function up()
    {
        $filas = DB::table('env_templates')->select('id', 'key', 'value')->whereNotNull('value')->get();

        foreach ($filas as $fila) {
            /* Sólo las variables sensibles. */
            if (! EnvSensitiveKeys::es_sensible((string) $fila->key)) {
                continue;
            }

            $crudo = (string) $fila->value;

            /* Vacío: no hay nada que cifrar. */
            if (trim($crudo) === '') {
                continue;
            }

            /* Ya cifrado (segunda corrida, o fila que el modelo ya cifró): no se vuelve a cifrar. */
            if (strpos($crudo, EnvTemplate::PREFIJO_CIFRADO) === 0) {
                continue;
            }

            DB::table('env_templates')
                ->where('id', $fila->id)
                ->update(['value' => EnvTemplate::PREFIJO_CIFRADO . Crypt::encryptString($crudo)]);
        }
    }

    /**
     * Deja en claro los valores cifrados (requiere la misma APP_KEY con la que se cifraron).
     *
     * Un valor que no se puede descifrar se deja como está: dejarlo roto es mejor que borrarlo.
     *
     * @return void
     */
    public function down()
    {
        $filas = DB::table('env_templates')->select('id', 'value')->whereNotNull('value')->get();

        foreach ($filas as $fila) {
            $crudo = (string) $fila->value;

            /* Sólo los que tienen el prefijo. */
            if (strpos($crudo, EnvTemplate::PREFIJO_CIFRADO) !== 0) {
                continue;
            }

            try {
                $claro = Crypt::decryptString(substr($crudo, strlen(EnvTemplate::PREFIJO_CIFRADO)));
            } catch (\Throwable $e) {
                /* No se loguea el valor ni el mensaje: pueden traer fragmentos del secreto. */
                continue;
            }

            DB::table('env_templates')->where('id', $fila->id)->update(['value' => $claro]);
        }
    }
};
