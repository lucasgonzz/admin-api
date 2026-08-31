<?php

namespace App\Models;

use App\Services\DemoPathResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Tienda online (ecommerce) asociada a un cliente o a una demo.
 *
 * 🔴 EL DUEÑO ES POLIMÓRFICO Y ES EXACTAMENTE UNO (31/8/2026). Hasta esta misión, una tienda
 * colgaba siempre de un `Client`, y para instalar o actualizar el ecommerce de una demo había que
 * crear un cliente falso llamado "demo". Ahora la fila tiene `client_id` O `demo_id` cargado —
 * nunca los dos, nunca ninguno—, y el pipeline de ~2900 líneas de EcommerceInstallationService
 * sigue siendo el mismo para los dos casos. La regla la hace cumplir el hook `saving` de abajo,
 * porque la base no puede expresar "exactamente uno de estos dos" con una FK y porque hay más de
 * un camino de escritura (el modal del cliente, el módulo de demos, los endpoints de arranque).
 *
 * @property int|null    $client_id            Cliente dueño de la tienda (null si el dueño es una demo).
 * @property int|null    $demo_id              Demo dueña de la tienda (null si el dueño es un cliente).
 * @property string|null $domain               Dominio final de la tienda.
 * @property string|null $api_url              URL de la API de la tienda.
 * @property string|null $spa_url              URL del SPA de la tienda.
 * @property string|null $api_path             Path efectivo de instalación de la API (cargado a mano o derivado).
 * @property string|null $spa_path             Path efectivo de instalación del SPA (cargado a mano o derivado).
 * @property string      $status               pending | installing | active.
 * @property array|null  $ecommerce_setup_data Configuración recolectada por WhatsApp.
 */
class ClientEcommerce extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'demo_id',
        'domain',
        'api_url',
        'spa_url',
        'api_path',
        'spa_path',
        'status',
        'ecommerce_setup_data',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'ecommerce_setup_data' => 'array',
    ];

    /**
     * Engancha la guarda de dueño único a TODA escritura del modelo.
     *
     * 🔴 POR QUÉ UN HOOK `saving` Y NO UNA VALIDACIÓN EN EL CONTROLADOR. La regla "un dueño y solo
     * uno" no la puede expresar la base (dos FK nullable admiten perfectamente las dos cargadas o
     * ninguna) y hay al menos cuatro caminos de escritura distintos hacia esta tabla: el modal del
     * cliente (ClientController::sync_ecommerce_urls_from_request), el firstOrCreate de
     * EcommerceImplementationController, los endpoints de arranque de ecommerce por demo, y
     * cualquier `update(['status' => ...])` del propio pipeline. Validar en uno solo de esos
     * lugares es la forma conocida de que el quinto camino, escrito dentro de seis meses, entre
     * una fila sin dueño — y una fila sin dueño no rompe nada visible: rompe recién adentro del
     * pipeline, con `$this->client` en null a mitad de un deploy.
     *
     * El costo es que la guarda corre también en cada `update()` del pipeline. Es cálculo puro
     * sobre dos enteros ya cargados: no toca la base ni las relaciones.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function (ClientEcommerce $ecommerce) {
            $ecommerce->assert_tiene_un_solo_dueno();
        });
    }

    /**
     * Valida que la tienda tenga exactamente un dueño: un cliente o una demo.
     *
     * Es `public` para que el servicio y el controlador puedan llamarlo explícitamente antes de
     * arrancar una corrida (fallar en el arranque es mucho más legible que fallar al guardar),
     * pero la garantía real la da el hook `saving` de arriba, que no depende de que nadie se
     * acuerde de llamarlo.
     *
     * @return void
     * @throws \RuntimeException
     */
    public function assert_tiene_un_solo_dueno(): void
    {
        $tiene_cliente = $this->client_id !== null;
        $tiene_demo    = $this->demo_id !== null;

        if ($tiene_cliente && $tiene_demo) {
            throw new \RuntimeException(
                'Una tienda no puede pertenecer a un cliente y a una demo a la vez (client_id='
                . $this->client_id . ', demo_id=' . $this->demo_id . '). El pipeline resuelve el '
                . 'nombre, el id de comercio y la api key según el dueño, así que con los dos '
                . 'cargados desplegaría datos de uno sobre la tienda del otro.'
            );
        }

        if (! $tiene_cliente && ! $tiene_demo) {
            throw new \RuntimeException(
                'Una tienda tiene que pertenecer a un cliente o a una demo, y esta no tiene '
                . 'ninguno de los dos cargados.'
            );
        }
    }

    /**
     * Cliente dueño de esta tienda. Null si el dueño es una demo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Demo dueña de esta tienda. Null si el dueño es un cliente.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function demo()
    {
        return $this->belongsTo(Demo::class);
    }

    /**
     * ¿El dueño de esta tienda es una demo?
     *
     * Se decide por `demo_id` y no por si la relación está cargada: la relación puede no estar
     * cargada todavía, y `assert_tiene_un_solo_dueno()` ya garantiza que las dos columnas nunca
     * están cargadas a la vez.
     *
     * @return bool
     */
    public function is_demo(): bool
    {
        return $this->demo_id !== null;
    }

    /**
     * Dueño de esta tienda: el `Client` o la `Demo`, según cuál esté cargado.
     *
     * @return \App\Models\Client|\App\Models\Demo|null  Null solo si la fila quedó huérfana
     *                                                   (el dueño se borró por fuera de la FK).
     */
    public function owner()
    {
        return $this->is_demo() ? $this->demo : $this->client;
    }

    /**
     * Demo dueña de esta tienda, o excepción si no la hay.
     *
     * Lo usan los helpers de path de abajo: llegar ahí con `demo_id` cargado y `demo` en null
     * significa que la demo se borró de la base por fuera de la FK, y seguir con una demo nula
     * armaría rutas vacías —o sea un `rm -rf` sobre un directorio equivocado, que es justo lo que
     * todas las guardas de este archivo tratan de evitar.
     *
     * @return \App\Models\Demo
     * @throws \RuntimeException
     */
    protected function assert_demo(): Demo
    {
        $demo = $this->demo;
        if ($demo === null) {
            throw new \RuntimeException(
                'La tienda dice pertenecer a la demo ' . $this->demo_id . ', pero esa demo ya no '
                . 'existe en el catálogo. No se pueden resolver sus rutas de instalación.'
            );
        }

        return $demo;
    }

    /**
     * Corridas del pipeline de instalación/actualización de esta tienda.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function installations()
    {
        return $this->hasMany(ClientEcommerceInstallation::class);
    }

    /**
     * Normaliza una URL: castea a string, recorta espacios y saca la barra final.
     *
     * Se usa para comparar/persistir spa_url y api_url de forma consistente,
     * evitando duplicados por diferencias de barra final o espacios sueltos.
     *
     * @param  mixed  $url  Valor crudo recibido (puede venir null, número, etc.)
     * @return string       URL normalizada, o cadena vacía si no queda nada útil.
     */
    public static function normalize_url($url)
    {
        $value = trim((string) $url);
        if ($value === '') {
            return '';
        }

        return rtrim($value, '/');
    }

    /**
     * Recorta los espacios "invisibles" de las dos puntas usando EL MISMO conjunto de caracteres
     * que `String.prototype.trim()` de JavaScript.
     *
     * POR QUÉ NO ALCANZA `trim()` de PHP (defecto encontrado en el chequeo de la misión
     * ecommerce-paths-subcarpeta): `trim()` recorta solo " \t\n\r\0\x0B", mientras que el `.trim()`
     * de `ClientEcommerceUrls.vue` —que promete en su docblock ser equivalente a esta
     * implementación— recorta todo el whitespace Unicode, incluidos el espacio duro (U+00A0) y el
     * BOM (U+FEFF). Un path pegado desde una página web o desde un chat arrastra uno de esos con
     * mucha facilidad: el hint del modal mostraba la ruta limpia y la columna terminaba guardando
     * la ruta con el carácter invisible pegado, así que el deploy creaba en el hosting una carpeta
     * con un carácter invisible al final y nadie entendía por qué el (sub)dominio no servía nada.
     *
     * @param  string  $value  Texto crudo.
     * @return string          Texto sin espacios (en el sentido de JS) al principio ni al final.
     */
    protected static function js_trim(string $value): string
    {
        // Mismo conjunto que WhiteSpace + LineTerminator de la spec de ECMAScript.
        $espacios = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}'
            . '\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

        $trimmed = preg_replace('/^[' . $espacios . ']+|[' . $espacios . ']+$/u', '', $value);

        // preg_replace() devuelve null si el string no es UTF-8 válido. En ese caso se cae al trim
        // clásico de PHP en vez de perder el valor entero.
        return $trimmed === null ? trim($value) : $trimmed;
    }

    /**
     * Normaliza un path de instalación del hosting cargado a mano: lo deja relativo a `domains/`,
     * sin barras al inicio/fin, sin barras dobles y sin tramos "." ni "..".
     *
     * POR QUÉ ES ASÍ Y NO MÁS SIMPLE (no lo "achiques" a un trim de barras): este valor lo escribe
     * Lucas a mano en el modal y termina siendo el destino de un `rm -rf` en el swap atómico del
     * deploy (`EcommerceInstallationService::build_spa_atomic_deploy_shell()`). Lo que se pega en
     * ese campo, en la práctica, es una de tres cosas: la ruta relativa correcta, la ruta con el
     * prefijo `domains/` de más, o la ruta absoluta entera copiada de una sesión SSH
     * (`/home/uXXXXXXXX/domains/...`). Las tres tienen que terminar en el mismo string.
     *
     * @param  mixed  $path  Valor crudo del formulario.
     * @return string        Path relativo a `domains/`, o cadena vacía si no queda nada usable.
     */
    public static function normalize_hosting_path($path)
    {
        // Un valor que no es escalar no puede ser un path (defecto del chequeo de la misión
        // ecommerce-paths-subcarpeta): `(string) []` da literalmente "Array" —con warning— y ese
        // "Array" terminaba guardado como path de instalación, o sea como destino de un `rm -rf`.
        // null también cae acá y sale por el mismo camino que un campo vacío.
        if (! is_scalar($path)) {
            return '';
        }

        $value = self::js_trim((string) $path);
        if ($value === '') {
            return '';
        }

        // Barras invertidas a barras normales (una ruta copiada de WinSCP/Windows).
        $value = str_replace('\\', '/', $value);

        $segments = explode('/', $value);

        // Última aparición de un tramo llamado exactamente "domains": se descarta todo lo anterior
        // y ese tramo también. De una sola pasada resuelve "domains/x/public_html/..." y
        // "/home/u123/domains/x/public_html/...", porque el prefijo `domains/` lo agrega después
        // EcommerceInstallationService::HOSTING_PREFIX y no debe quedar duplicado en la columna.
        // (Contrapartida asumida: una carpeta real llamada "domains" adentro de un public_html
        //  cortaría mal. No existe ni tiene sentido que exista en este hosting.)
        $last_domains_index = -1;
        foreach ($segments as $index => $segment) {
            if ($segment === 'domains') {
                $last_domains_index = $index;
            }
        }
        if ($last_domains_index >= 0) {
            $segments = array_slice($segments, $last_domains_index + 1);
        }

        $clean_segments = [];
        foreach ($segments as $segment) {
            $segment = self::js_trim($segment);

            // Barras dobles y "./" no cambian el significado del path: se descartan.
            if ($segment === '' || $segment === '.') {
                continue;
            }

            // "Subir un nivel" apuntaría fuera de domains/ y el pipeline hace `rm -rf` sobre este
            // path: se rechaza la entrada ENTERA (se devuelve vacío) y el sistema vuelve a la
            // derivación automática, que siempre es una ruta segura. Es a propósito que no se
            // "limpie" el ".." resolviéndolo: resolver silenciosamente una ruta que alguien
            // escribió mal es peor que ignorarla.
            if ($segment === '..') {
                return '';
            }

            $clean_segments[] = $segment;
        }

        // ─────────────────────────────────────────────────────────────────────────────────────
        // GUARDA DE FORMA (agregada por el chequeo independiente de la misión
        // ecommerce-paths-subcarpeta). Este valor termina siendo el destino de un `rm -rf` en el
        // swap atómico del deploy: build_spa_atomic_deploy_shell() hace `mv "$DOCROOT" "$OLD"` y
        // después `rm -rf "$OLD"`. Por eso una entrada que no tiene forma de path de instalación
        // NO se "arregla" ni se acepta a medias: se rechaza ENTERA devolviendo cadena vacía, y el
        // sistema se cae a la derivación automática, que siempre es una ruta segura.
        // ─────────────────────────────────────────────────────────────────────────────────────

        // 1) Menos de dos segmentos nunca es un path de instalación válido: el primero es siempre
        //    el dominio, y abajo tiene que colgar por lo menos el docroot.
        //
        //    EL CASO CONCRETO QUE LO ORIGINÓ: pegar `comerciocity.store` a secas (olvidándose la
        //    cola), o pegar `/home/u123456/domains/comerciocity.store` copiado de una sesión SSH,
        //    que normaliza a lo mismo. Con eso guardado como spa_path, get_spa_docroot() daba
        //    `domains/comerciocity.store` y el próximo deploy movía y borraba el public_html
        //    ENTERO de ese dominio, con todas las otras tiendas que colgaran de ahí. El warning
        //    de ensure_hosting_spa_directory() no cubre este caso: el padre (`domains`) siempre
        //    existe, así que el deploy no tenía nada que denunciar.
        if (count($clean_segments) < 2) {
            return '';
        }

        // 2) El primer segmento tiene que PARECER un dominio, o sea tener al menos un punto.
        //
        //    EL CASO CONCRETO QUE LO ORIGINÓ: el File Manager de hPanel, cuando estás parado
        //    adentro del dominio, muestra `public_html/tienda/spa` — sin el dominio adelante.
        //    Pegado tal cual quedaba como `domains/public_html/tienda/spa`, una carpeta inventada
        //    al lado de los dominios reales, que ningún vhost sirve.
        //
        // 🔴 LO QUE ESTA GUARDA NO RECHAZA, Y NO LE AGREGUES NADA QUE LO RECHACE:
        //    `{dominio}/public_html` (dos segmentos) es el path derivado y legítimo de los ~40
        //    clientes en producción, igual que `{dominio}/public_html/api`. Una guarda del estilo
        //    "mínimo tres segmentos" o "prohibido terminar en public_html" rompe a todos.
        if (strpos($clean_segments[0], '.') === false) {
            return '';
        }

        return implode('/', $clean_segments);
    }

    /**
     * Resuelve el host (dominio) de una URL, sin el prefijo "www.".
     *
     * Si el valor no trae esquema (http/https) se le antepone "https://" antes
     * de parsear, porque parse_url() no puede resolver el host de una URL sin
     * esquema (la interpreta toda como path).
     *
     * @param  mixed  $url  URL o dominio suelto (con o sin esquema).
     * @return string       Dominio en minúsculas sin "www.", o cadena vacía si no se pudo resolver.
     */
    public static function domain_from_url($url)
    {
        $value = trim((string) $url);
        if ($value === '') {
            return '';
        }

        // Si no trae esquema, se lo agregamos para que parse_url() pueda resolver el host.
        if (strpos($value, '://') === false) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (empty($host)) {
            return '';
        }

        $host = strtolower($host);

        // Prohibido str_starts_with (PHP 7.4): se usa strpos === 0.
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Dominio efectivo de la tienda.
     *
     * La columna `domain` siempre gana si tiene valor (permite pisar un caso
     * especial a mano en la base sin tocar código). Si está vacía, se deriva
     * del host de `spa_url`.
     *
     * @return string
     */
    public function resolve_domain()
    {
        $domain = trim((string) $this->domain);
        if ($domain !== '') {
            return $domain;
        }

        // Dueño demo: el dominio sale del catálogo de demos (`ecommerce_spa_url`), resuelto por
        // DemoPathResolver. En la práctica da lo mismo que derivarlo de `spa_url` —los endpoints
        // de arranque copian una en la otra—, pero el catálogo es la fuente de verdad y así un
        // cambio de dominio en el módulo de Demos manda sin depender de que se haya re-copiado.
        if ($this->is_demo()) {
            return (new DemoPathResolver())->ecommerce_spa_domain($this->assert_demo());
        }

        return self::domain_from_url($this->spa_url);
    }

    /**
     * Path del SPA DERIVADO del dominio, ignorando la columna `spa_path`.
     *
     * Existe separado de resolve_spa_path() porque hay dos lugares que necesitan saber cuál sería
     * el path si nadie hubiera cargado uno a mano: manual_spa_path() (para decidir si lo guardado
     * es un path manual o el derivado que materializó el propio guardado) y el hint del modal.
     *
     * @return string
     */
    public function derived_spa_path(): string
    {
        // Dueño demo: lo resuelve DemoPathResolver con sus propios métodos `ecommerce_*`, que son
        // los que además frenan si esa demo tiene el ecommerce marcado como VPS (todavía no
        // soportado) o si el dominio quedaría vacío. Para un cliente, ni una línea cambia.
        if ($this->is_demo()) {
            return (new DemoPathResolver())->ecommerce_spa_path($this->assert_demo());
        }

        $domain = $this->resolve_domain();
        if ($domain === '') {
            return '';
        }

        return $domain.'/public_html';
    }

    /**
     * Path de la API DERIVADO del dominio, ignorando la columna `api_path`.
     *
     * Misma razón de ser que derived_spa_path(), para la API.
     *
     * @return string
     */
    public function derived_api_path(): string
    {
        // Ver derived_spa_path(): misma delegación, mismos motivos.
        if ($this->is_demo()) {
            return (new DemoPathResolver())->ecommerce_api_path($this->assert_demo());
        }

        $domain = $this->resolve_domain();
        if ($domain === '') {
            return '';
        }

        return $domain.'/public_html/api';
    }

    /**
     * Path de instalación del SPA, relativo a `domains/` en el hosting.
     *
     * Convención (definición de Lucas, 22/7/2026): la tienda de cada cliente
     * vive en su propio dominio de Hostinger, no como subcarpeta de
     * comerciocity.com. El SPA se sirve desde `domains/{dominio}/public_html`;
     * acá se guarda solo la parte relativa a `domains/`, o sea
     * `{dominio}/public_html` — el prefijo `domains/` lo agrega el servicio
     * de instalación. La columna `spa_path` siempre gana si tiene valor.
     *
     * Desde la misión ecommerce-paths-subcarpeta esa columna también puede traer un path cargado
     * a mano en el modal, que apunta a una carpeta física arbitraria del hosting (por ejemplo
     * `comerciocity.store/public_html/tienda/spa`) sin relación con el host de la URL pública.
     * La derivación por dominio vive ahora en derived_spa_path().
     *
     * @return string
     */
    public function resolve_spa_path()
    {
        $spa_path = trim((string) $this->spa_path, '/');
        if ($spa_path !== '') {
            return $spa_path;
        }

        return $this->derived_spa_path();
    }

    /**
     * Path de instalación de la API, relativo a `domains/` en el hosting.
     *
     * Misma convención que resolve_spa_path(): tienda-api se sirve desde
     * `domains/{dominio}/public_html/api`. La columna `api_path` siempre
     * gana si tiene valor, y desde la misión ecommerce-paths-subcarpeta ese valor puede ser un
     * path cargado a mano (una carpeta arbitraria del hosting, incluso hermana de la del SPA).
     *
     * @return string
     */
    public function resolve_api_path()
    {
        $api_path = trim((string) $this->api_path, '/');
        if ($api_path !== '') {
            return $api_path;
        }

        return $this->derived_api_path();
    }

    /**
     * Path del SPA cargado A MANO, o cadena vacía si lo que hay guardado es el derivado.
     *
     * POR QUÉ EXISTE (no lo reemplaces por `$this->spa_path` a secas): la columna `spa_path`
     * guarda SIEMPRE el path efectivo, incluido el derivado, porque
     * ClientController::sync_ecommerce_urls_from_request() lo materializa en cada guardado (y así
     * se puede mirar la base y ver dónde está instalada cada tienda). O sea que "columna con
     * valor" NO significa "path cargado a mano". La única forma de distinguirlos sin agregar una
     * columna es comparar contra la derivación: si coinciden, no es manual.
     *
     * De esto dependen dos cosas: que el campo del modal se vea VACÍO en los 40 clientes que hoy
     * tienen el path derivado guardado, y que el recálculo por cambio de dominio siga funcionando
     * para ellos.
     *
     * Limitación conocida y aceptada: si alguien carga a mano un path que es exactamente el
     * derivado, el sistema lo trata como derivado (el campo se ve vacío la próxima vez). El
     * efecto es idéntico salvo que después se cambie el dominio.
     *
     * @return string
     */
    public function manual_spa_path(): string
    {
        $stored = self::normalize_hosting_path($this->spa_path);
        if ($stored === '' || $stored === $this->derived_spa_path()) {
            return '';
        }

        return $stored;
    }

    /**
     * Path de la API cargado A MANO, o cadena vacía si lo guardado es el derivado.
     *
     * Misma lógica y mismas advertencias que manual_spa_path().
     *
     * @return string
     */
    public function manual_api_path(): string
    {
        $stored = self::normalize_hosting_path($this->api_path);
        if ($stored === '' || $stored === $this->derived_api_path()) {
            return '';
        }

        return $stored;
    }

    /**
     * Tramo de resolve_api_path() que queda anidado ADENTRO de resolve_spa_path() (prompt 191/01).
     *
     * Por la convención de subdominios de Hostinger confirmada el 22/7/2026 (ver
     * `resolve_api_path()`), lo normal es que tienda-api viva dentro del docroot del SPA
     * (`{dominio}/public_html/api`). Ese subpath hay que preservarlo explícitamente cuando el
     * deploy del SPA reemplaza el docroot entero (`build_spa_atomic_deploy_shell()` en
     * `EcommerceInstallationService`), porque de lo contrario el `rm -rf` del docroot viejo se
     * lleva puesta la API instalada del cliente.
     *
     * Devuelve cadena vacía cuando no hay nada que preservar: si algún cliente tiene la API
     * cargada a mano en un dominio o carpeta separada del SPA (caso legítimo, columnas `spa_path`/
     * `api_path` con valores independientes), no hay anidamiento y el deploy debe comportarse
     * exactamente como antes de este fix, sin preservar nada de más.
     *
     * @return string  Subpath relativo (sin barra inicial ni final), o '' si la API no está anidada.
     */
    public function api_subpath_inside_spa_docroot(): string
    {
        // Normaliza ambos paths sin barras al inicio/fin para poder compararlos como texto plano.
        $spa_path = trim((string) $this->resolve_spa_path(), '/');
        $api_path = trim((string) $this->resolve_api_path(), '/');

        // Sin dominio/paths cargados no hay nada que resolver.
        if ($spa_path === '' || $api_path === '') {
            return '';
        }

        // La API está anidada solo si su path arranca exactamente con "{spa_path}/". Si no
        // matchea (API en otro dominio, o mismo string que el SPA sin subcarpeta), no hay
        // anidamiento: se devuelve vacío para no preservar nada que no corresponda.
        $spa_prefix = $spa_path . '/';
        if (substr($api_path, 0, strlen($spa_prefix)) !== $spa_prefix) {
            return '';
        }

        // Resto del path después del prefijo del SPA (con la convención actual, "api").
        return substr($api_path, strlen($spa_prefix));
    }
}
