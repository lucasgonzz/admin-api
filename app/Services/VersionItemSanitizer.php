<?php

namespace App\Services;

/**
 * Saneamiento de los seeders y comandos que trae una versión, ANTES de que se guarden.
 *
 * 🔴 POR QUÉ EXISTE, Y POR QUÉ ES UN SERVICIO Y NO CÓDIGO ADENTRO DE UN CONTROLADOR.
 *
 * `version_seeders` y `version_commands` se escriben por TRES puertas distintas:
 *
 *   - `Api\ClaudeVersionItemsIngestController` — por acá publican las misiones.
 *   - `VersionSeederController::store/update`  — el panel.
 *   - `VersionCommandController::store/update` — el panel.
 *
 * Los dos defectos que colgaron la actualización de masquito a 4.0.11 el 3/9/2026 son datos que
 * entraron por alguna de esas puertas y que el pipeline después ejecutó tal cual:
 *
 *   1. `seeder_class` con el namespace adelante (`Database\Seeders\Xxx`). El shell del hosting se
 *      come la barra invertida y el seeder muere con "Target class does not exist" (upgrade 75).
 *   2. `php artisan migrate` sin `--force`. Con `APP_ENV=production` artisan pide confirmación por
 *      stdin y el pipeline se cuelga 30 minutos hasta el timeout del job (upgrade 76).
 *
 * Poner la regla en un solo cuerpo compartido —y no copiada en las tres puertas— es deliberado:
 * tres copias de la misma validación divergen, y revisar dos y no la tercera no produce ninguna
 * señal. Es la lección que APRENDER_NO_PARCHEAR dejó escrita con los tres closures del servido de
 * archivos.
 *
 * ⚠️ Esto es defensa en profundidad, no la única barrera: `DeploymentService` ya escapa el
 * `--class` y ejecuta todo con stdin en /dev/null. Acá se evita que el dato malo entre; allá se
 * evita que un dato malo que ya está guardado haga daño.
 */
class VersionItemSanitizer
{
    /**
     * Prefijo de namespace que `db:seed --class=` resuelve solo, y que por eso sobra en el dato.
     */
    const NAMESPACE_POR_DEFECTO = 'Database\\Seeders\\';

    /**
     * Subcomandos de artisan que borran o revierten datos. No tienen nada que hacer en el
     * despliegue de un cliente, y "arreglarlos" agregándoles `--force` sería peor que dejarlos
     * fallar: hoy se cancelan por falta de confirmación, y con `--force` correrían de verdad
     * contra la base de un negocio.
     *
     * @var array<int, string>
     */
    const SUBCOMANDOS_DESTRUCTIVOS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'db:wipe',
    ];

    /**
     * Subcomandos que necesitan `--force` para correr sin terminal, y que es seguro completar.
     *
     * @var array<int, string>
     */
    const SUBCOMANDOS_CONFIRMABLES = [
        'migrate',
        'db:seed',
    ];

    /**
     * Saca el namespace por defecto de un `seeder_class`.
     *
     * Solo el prefijo EXACTO `Database\Seeders\`, que es el que artisan agrega solo. Un seeder en
     * un sub-namespace real (`Database\Seeders\Demo\FooSeeder`) conserva lo suyo: recortarle todo
     * hasta la última barra lo dejaría irresoluble, que es el defecto opuesto al que se arregla.
     *
     * @param  string|null  $seeder_class
     * @return string
     */
    public static function sanear_seeder_class($seeder_class): string
    {
        $limpio = trim((string) $seeder_class);

        if (strpos($limpio, static::NAMESPACE_POR_DEFECTO) === 0) {
            $limpio = substr($limpio, strlen(static::NAMESPACE_POR_DEFECTO));
        }

        return $limpio;
    }

    /**
     * Motivo por el que un `seeder_class` no se puede guardar, o null si está bien.
     *
     * Se evalúa DESPUÉS de sanear: lo que quede con una barra invertida es un sub-namespace que
     * no sobrevive al viaje por SSH, y eso hay que decirlo en vez de guardarlo.
     *
     * @param  string|null  $seeder_class
     * @return string|null
     */
    public static function motivo_de_rechazo_de_seeder($seeder_class)
    {
        $saneado = static::sanear_seeder_class($seeder_class);

        if ($saneado === '') {
            return 'El seeder_class no puede quedar vacío.';
        }

        if (strpos($saneado, '\\') !== false) {
            return 'El seeder_class "' . $saneado . '" tiene un sub-namespace. Solo se quita el '
                . 'prefijo Database\\Seeders\\, que artisan resuelve solo; lo que quede con barras '
                . 'invertidas no sobrevive al shell del hosting. Movelo a Database\\Seeders\\ o '
                . 'cargá el comando completo a mano.';
        }

        return null;
    }

    /**
     * Completa un comando de versión para que pueda correr sin terminal.
     *
     * Hoy: le agrega `--force` a los artisan que lo necesitan. No toca nada más — ni el orden de
     * los argumentos, ni un comando que ya lo traiga, ni uno que no sea de artisan.
     *
     * @param  string|null  $comando
     * @return string
     */
    public static function sanear_comando($comando): string
    {
        $limpio = trim((string) $comando);

        if ($limpio === '' || ! static::necesita_force($limpio)) {
            return $limpio;
        }

        /*
         * 🔴 SOLO SE COMPLETA UN COMANDO SUELTO. Un encadenado NO se toca: se rechaza aparte.
         *
         * Pegar ' --force' al final de `php artisan db:seed --class=A && php artisan cache:clear`
         * se lo agrega a `cache:clear` —que no acepta esa opción y haría fallar el deployment— y
         * deja al `db:seed` sin el suyo. Y como esto se PERSISTE en la base, el dato quedaría
         * corrupto, no solo mal ejecutado. Lo levantó la verificación independiente de esta misión.
         */
        if (static::es_encadenado($limpio)) {
            return $limpio;
        }

        return $limpio . ' --force';
    }

    /**
     * ¿El comando encadena más de una invocación?
     *
     * Con `&&`, `||`, `;` o una tubería no hay "un" comando al que agregarle el flag, así que el
     * saneamiento automático no aplica y la decisión pasa a ser del que lo carga.
     *
     * @param  string  $comando
     * @return bool
     */
    public static function es_encadenado(string $comando): bool
    {
        return (bool) preg_match('/(&&|\|\||;|\|)/', $comando);
    }

    /**
     * Motivo por el que un comando no se puede guardar, o null si está bien.
     *
     * @param  string|null  $comando
     * @return string|null
     */
    public static function motivo_de_rechazo_de_comando($comando)
    {
        $limpio = trim((string) $comando);

        if ($limpio === '') {
            return 'El comando no puede quedar vacío.';
        }

        $destructivo = static::subcomando_destructivo($limpio);
        if ($destructivo !== null) {
            return 'El comando usa "' . $destructivo . '", que borra o revierte datos. Un '
                . 'despliegue de cliente no corre eso: si de verdad hace falta, se ejecuta a mano '
                . 'y con respaldo hecho.';
        }

        /*
         * Un encadenado que necesita `--force` se rechaza en vez de completarse: agregarle el flag
         * al final se lo pondría al último eslabón, que no es el que lo necesita (ver
         * sanear_comando()). Se pide que lo escriban explícito, que además deja claro a cuál de
         * los comandos aplica.
         */
        if (static::es_encadenado($limpio) && static::necesita_force($limpio)) {
            return 'El comando encadena varias invocaciones y alguna necesita --force. Escribilo '
                . 'explícito en el comando que lo necesita: agregarlo automáticamente se lo pondría '
                . 'al último de la cadena, que no es el que lo pide.';
        }

        return null;
    }

    /**
     * ¿Este comando es un artisan confirmable al que le falta `--force`?
     *
     * @param  string  $comando
     * @return bool
     */
    public static function necesita_force(string $comando): bool
    {
        if (strpos($comando, 'artisan') === false) {
            return false;
        }

        if (strpos($comando, '--force') !== false) {
            return false;
        }

        // Un destructivo NO se completa: se rechaza aparte. Completarlo lo volvería ejecutable.
        if (static::subcomando_destructivo($comando) !== null) {
            return false;
        }

        foreach (static::SUBCOMANDOS_CONFIRMABLES as $subcomando) {
            /*
             * El subcomando tiene que aparecer como palabra suelta detrás de `artisan`. Sin el
             * borde, "migrate" matchearía dentro de "migrate:fresh" y le agregaría --force a un
             * destructivo, que es exactamente lo que no se quiere.
             */
            if (preg_match('/artisan\s+' . preg_quote($subcomando, '/') . '(\s|$)/', $comando)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El subcomando destructivo que usa este comando, o null si no usa ninguno.
     *
     * @param  string  $comando
     * @return string|null
     */
    public static function subcomando_destructivo(string $comando)
    {
        foreach (static::SUBCOMANDOS_DESTRUCTIVOS as $subcomando) {
            if (preg_match('/artisan\s+' . preg_quote($subcomando, '/') . '(\s|$)/', $comando)) {
                return $subcomando;
            }
        }

        return null;
    }
}
