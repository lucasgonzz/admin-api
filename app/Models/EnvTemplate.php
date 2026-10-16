<?php

namespace App\Models;

use App\Services\ImplementationSettings;
use App\Support\EnvSensitiveKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Plantilla base de variables .env del sistema.
 *
 * Cada variable puede marcarse como:
 * - is_common: su valor se contrasta con los clientes al ejecutar una actualización.
 * - is_manual_on_create: se muestra como recordatorio al dar de alta un sistema nuevo.
 *
 * 🔴 Los valores de las variables SENSIBLES (ANTHROPIC_API_KEY, DEEPSEEK_API_KEY, PUSHER_APP_SECRET,
 * MAIL_PASSWORD...; la regla es EnvSensitiveKeys) se guardan CIFRADOS en la columna `value`, con el
 * prefijo `enc:v1:` delante del texto que devuelve Crypt::encryptString (APP_KEY del admin).
 *
 * - Al escribir, el evento `saving` cifra el valor si la variable es sensible. Así cubre todos los
 *   caminos de escritura (controlador, seeders, tests) sin que ninguno tenga que acordarse.
 * - Al leer `$template->value`, el accessor lo devuelve DESCIFRADO. Por eso todos los lectores del
 *   backend (InstallationService, DeploymentService, etc.) siguen andando sin tocarlos.
 * - Al serializar a JSON/array el valor sensible NO sale: se informa `value_sensitive`, `value_set`
 *   y `value_tail` (últimos caracteres, sólo si el valor es largo). El valor en claro no viaja por
 *   ninguna respuesta de la API.
 *
 * @property int    $id
 * @property string $key                  Nombre de la variable (ej: MAIL_HOST).
 * @property string|null $value           Valor del template base (descifrado al leerlo; cifrado en la base si es sensible).
 * @property string|null $group           Grupo funcional: mail, pusher, db, app, misc.
 * @property string $scope                Ámbito de la variable: 'empresa' (default) o 'tienda'.
 * @property bool   $is_common            Contraste al actualizar clientes.
 * @property bool   $is_manual_on_create  Recordatorio al crear sistema nuevo.
 * @property string|null $notes           Notas internas para el operador.
 * @property int    $sort_order           Orden de aparición dentro del grupo.
 */
class EnvTemplate extends Model
{
    /**
     * Prefijo que marca a un valor de la columna `value` como cifrado (y la versión del formato).
     *
     * Sin el prefijo, el valor se considera en claro: es lo que hace posible la lectura tolerante
     * durante la ventana entre desplegar el código y correr la migración que cifra lo existente.
     *
     * @var string
     */
    const PREFIJO_CIFRADO = 'enc:v1:';

    /**
     * Permite asignación masiva de todos los campos.
     *
     * Nota: este modelo usa $guarded = [] (no $fillable) para permitir asignación
     * masiva de todos los atributos, incluida la columna `scope` agregada luego
     * (migración add_scope_to_env_templates_table): al no existir un array
     * $fillable explícito no hace falta declarar `scope` en ningún lado para que
     * sea mass-assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casts a tipos nativos para los campos booleanos y numérico.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_common'           => 'boolean',
        'is_manual_on_create' => 'boolean',
        'sort_order'          => 'integer',
    ];

    /**
     * Engancha el cifrado al guardar.
     *
     * 🔴 El evento `saving` corre con TODOS los atributos ya asignados (también en
     * `EnvTemplate::create([...])`), así que acá la `key` ya se conoce sin importar el orden en que
     * se asignaron los campos.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function (EnvTemplate $plantilla) {
            $plantilla->cifrar_valor_si_corresponde();
        });
    }

    /**
     * Cifra el valor crudo si la variable es sensible. Idempotente.
     *
     * - Variable no sensible: no se toca nada (el valor queda en claro, como siempre).
     * - Valor ausente: no se toca (la columna ni se menciona en el UPDATE).
     * - Valor vacío o sólo espacios: se guarda null (no hay nada que cifrar).
     * - Valor que ya trae el prefijo: no se cifra de nuevo (evita el doble cifrado al re-guardar
     *   una fila cargada sin cambiar su valor).
     *
     * Se lee el valor CRUDO con getAttributes(): el accessor devolvería el descifrado.
     *
     * @return void
     */
    protected function cifrar_valor_si_corresponde(): void
    {
        $atributos = $this->getAttributes();

        /* Sin valor asignado, la columna no entra en el guardado: nada que hacer. */
        if (! array_key_exists('value', $atributos)) {
            return;
        }

        $clave = isset($atributos['key']) ? (string) $atributos['key'] : '';

        if (! EnvSensitiveKeys::es_sensible($clave)) {
            return;
        }

        $crudo = $atributos['value'];

        /* Null queda null; vacío o sólo espacios también termina en null. */
        if ($crudo === null || trim((string) $crudo) === '') {
            $this->setAttribute('value', null);

            return;
        }

        /* Ya viene cifrado: no se vuelve a cifrar. */
        if (strpos((string) $crudo, self::PREFIJO_CIFRADO) === 0) {
            return;
        }

        /*
         * Se recorta antes de cifrar: lo que prueba ProbadorDeClaves y lo que escriben env-bulk y
         * apply-diff tiene que ser EXACTAMENTE el mismo string, sin espacios ni tabs al borde (una
         * clave pegada con un espacio al final es el error de carga más común).
         */
        $recortado = trim((string) $crudo);

        $this->setAttribute('value', self::PREFIJO_CIFRADO . Crypt::encryptString($recortado));
    }

    /**
     * Accessor de `value`: devuelve el valor DESCIFRADO.
     *
     * Lectura tolerante: si el crudo no trae el prefijo, es un valor en claro (legado anterior a la
     * migración que cifra, o variable no sensible) y se devuelve tal cual. Si trae el prefijo pero no
     * se puede descifrar (APP_KEY cambiada, payload dañado) se devuelve null y se deja un
     * Log::error SIN el valor: explotar acá tumbaría el armado del .env de una instalación entera.
     *
     * @param  string|null  $valor  Valor crudo de la columna.
     * @return string|null
     */
    public function getValueAttribute($valor)
    {
        if ($valor === null) {
            return null;
        }

        if (strpos((string) $valor, self::PREFIJO_CIFRADO) !== 0) {
            return $valor;
        }

        $descifrado = $this->intentar_descifrar((string) $valor);

        if ($descifrado === null) {
            /* Ni el valor ni el mensaje de la excepción van al log: pueden traer fragmentos del secreto. */
            Log::error('EnvTemplate: no se pudo descifrar el valor de la variable.', [
                'env_template_id' => $this->getKey(),
                'key'             => isset($this->attributes['key']) ? $this->attributes['key'] : null,
            ]);
        }

        return $descifrado;
    }

    /**
     * Descifra un valor crudo que trae el prefijo. Devuelve null si no se puede (sin loguear nada).
     *
     * @param  string  $crudo  Valor crudo con el prefijo `enc:v1:`.
     * @return string|null
     */
    private function intentar_descifrar(string $crudo)
    {
        try {
            return Crypt::decryptString(substr($crudo, strlen(self::PREFIJO_CIFRADO)));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Indica si el valor guardado es ILEGIBLE: la variable es sensible, el crudo trae el prefijo de
     * cifrado y no se puede descifrar (APP_KEY del admin cambiada, payload dañado).
     *
     * 🔴 Distinguirlo de "vacío" importa: el accessor devuelve null en los dos casos, y quien arma un
     * .env con ese null escribiría la variable vacía en el servidor de un cliente. Los endpoints que
     * escriben (apply-diff*, env-bulk con plantilla) se niegan a trabajar con una variable ilegible.
     *
     * @return bool
     */
    public function esta_ilegible(): bool
    {
        $clave = isset($this->attributes['key']) ? (string) $this->attributes['key'] : '';

        if (! EnvSensitiveKeys::es_sensible($clave)) {
            return false;
        }

        $crudo = isset($this->attributes['value']) ? (string) $this->attributes['value'] : '';

        if (strpos($crudo, self::PREFIJO_CIFRADO) !== 0) {
            return false;
        }

        return $this->intentar_descifrar($crudo) === null;
    }

    /**
     * Serialización a array/JSON: el valor de una variable sensible NUNCA sale.
     *
     * Para una sensible se saca `value` y se suman `value_sensitive` (true), `value_ilegible` (hay un
     * cifrado que no se puede descifrar; en ese caso `value_set` es false), `value_set` (si hay algo
     * cargado) y `value_tail` (últimos caracteres para reconocer cuál está cargada, sólo si el valor
     * tiene el largo mínimo y la variable no es una contraseña; si no, null). Para una no sensible
     * queda igual que siempre más `value_sensitive: false`.
     *
     * @return array<string, mixed>
     */
    public function attributesToArray()
    {
        $array = parent::attributesToArray();

        $clave = isset($this->attributes['key']) ? (string) $this->attributes['key'] : '';

        if (! EnvSensitiveKeys::es_sensible($clave)) {
            $array['value_sensitive'] = false;

            return $array;
        }

        $ilegible = $this->esta_ilegible();

        /* Descifrado sólo para calcular los indicadores: el valor no se agrega al array. */
        $valor = isset($this->attributes['value']) ? (string) $this->attributes['value'] : null;

        if ($valor !== null && strpos($valor, self::PREFIJO_CIFRADO) === 0) {
            $valor = $this->intentar_descifrar($valor);
        }

        unset($array['value']);

        $array['value_sensitive'] = true;
        $array['value_ilegible']  = $ilegible;
        $array['value_set']       = ! $ilegible && $valor !== null && $valor !== '';

        /*
         * Sin cola para las contraseñas (PASSWORD, PASS...): los últimos caracteres de una contraseña
         * dicen demasiado; la pantalla sólo muestra "Cargada".
         */
        $array['value_tail'] = strpos(strtoupper($clave), 'PASS') !== false ? null : self::cola_de($valor);

        return $array;
    }

    /**
     * Últimos caracteres de un valor, para reconocer cuál está cargado sin exponerlo. Null si no hay
     * valor o si es tan corto que la cola sería una parte grande de él.
     *
     * Mismo criterio y mismas constantes que ImplementationSettings (credenciales de las demos).
     *
     * @param  string|null  $valor  Valor en claro.
     * @return string|null
     */
    public static function cola_de($valor)
    {
        if ($valor === null || strlen((string) $valor) < ImplementationSettings::LARGO_MINIMO_PARA_MOSTRAR_LA_COLA) {
            return null;
        }

        return substr((string) $valor, -ImplementationSettings::LARGO_DE_LA_COLA);
    }
}
