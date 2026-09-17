<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientVersionUpgrade;
use App\Models\Implementation;
use App\Models\Version;
use App\Services\ImplementationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las consultas de más que pagaban los listados del panel, y la promesa de que sacarlas NO cambió
 * lo que devuelve ninguna respuesta.
 *
 * Son tres problemas distintos con la misma forma: algo que se resuelve UNA VEZ POR FILA mientras
 * Laravel serializa.
 *
 *  1. `Implementation::$appends` trae `form_link`, y el accesor leía la URL base de admin_settings
 *     sin memo: una consulta por implementación serializada.
 *  2. `Client::$appends` trae los cuatro `ecommerce_*`, que resuelven contra `client_ecommerce`.
 *     `Client::scopeWithAll()` la precargaba, pero los diez lugares que traen `client` como
 *     relación no: una consulta por cliente.
 *  3. `GET /client?for_select=1` ignoraba el parámetro y devolvía todo con las 7 relaciones.
 *
 * 🔴 Lo que se protege acá no es el número de consultas: es que la FORMA del JSON haya quedado
 * igual. Estos modelos los serializan también los endpoints /api/claude/* y el pipeline de
 * despliegue, así que un campo que desaparece rompe algo que no se ve desde el SPA. Por eso los
 * tests de forma comparan contra el camino viejo reconstruido en el mismo test —serializando sin
 * el eager load— en vez de contra una lista de claves escrita a mano, que envejecería sola.
 *
 * La única respuesta que SÍ cambia de forma a propósito es la de `for_select`, y tiene su propio
 * test explicando qué campos usa cada llamador de admin-spa.
 */
class ConsultasDeMasDeLosListadosDelPanelTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Version de destino reutilizada por todos los upgrades del test.
     *
     * @var Version|null
     */
    private $version_destino = null;

    /**
     * El memo de admin_settings es estático: sin esto, un test se llevaría puesto al siguiente.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::flush_memo();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        AdminSetting::flush_memo();

        parent::tearDown();
    }

    /**
     * Admin autenticado del panel.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'lucas+' . Str::random(10) . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Cliente con su tienda online cargada (para que los accesores ecommerce_* tengan qué leer).
     *
     * @param string $name         Nombre del dueño.
     * @param string $company_name Razón social.
     * @param bool   $con_ecommerce Si además se le crea el ClientEcommerce.
     *
     * @return Client
     */
    private function crear_cliente(string $name, string $company_name, bool $con_ecommerce = true): Client
    {
        $client               = new Client();
        $client->name         = $name;
        $client->company_name = $company_name;
        $client->is_active    = true;
        $client->save();

        if ($con_ecommerce) {
            $ecommerce            = new ClientEcommerce();
            $ecommerce->client_id = $client->id;
            $ecommerce->domain    = Str::slug($company_name) . '.com.ar';
            $ecommerce->spa_url   = 'https://' . Str::slug($company_name) . '.com.ar';
            $ecommerce->api_url   = 'https://api.' . Str::slug($company_name) . '.com.ar';
            $ecommerce->save();
        }

        return $client;
    }

    /**
     * Implementación de un cliente, con su token de formulario.
     *
     * @param Client $client Cliente dueño.
     *
     * @return Implementation
     */
    private function crear_implementacion(Client $client): Implementation
    {
        $implementation                = new Implementation();
        $implementation->client_id     = $client->id;
        $implementation->current_stage = 1;
        $implementation->status        = 'in_progress';
        $implementation->form_token    = (string) Str::uuid();
        $implementation->save();

        return $implementation;
    }

    /**
     * Cuenta las consultas que se hicieron contra una tabla mientras corría el callback.
     *
     * @param string   $tabla    Nombre de la tabla a espiar.
     * @param callable $callback Lo que se quiere medir.
     *
     * @return int
     */
    private function consultas_contra(string $tabla, callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $consultas = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        $total = 0;
        foreach ($consultas as $consulta) {
            if (strpos($consulta['query'], $tabla) !== false) {
                $total++;
            }
        }

        return $total;
    }

    // ------------------------------------------------------------------ D1

    /**
     * Serializar N implementaciones lee la URL base UNA sola vez, no N.
     *
     * Antes eran N consultas a admin_settings: el accesor form_link está en el $appends del
     * modelo, así que se dispara por cada fila que Laravel serializa.
     *
     * @return void
     */
    public function test_serializar_varias_implementaciones_lee_la_url_del_formulario_una_sola_vez(): void
    {
        AdminSetting::set('implementation_form_url', 'https://admin.comerciocity.com/configuracion');
        AdminSetting::flush_memo();

        $implementaciones = collect();
        for ($i = 0; $i < 5; $i++) {
            $implementaciones->push($this->crear_implementacion($this->crear_cliente('Dueño ' . $i, 'Empresa ' . $i, false)));
        }

        $consultas = $this->consultas_contra('admin_settings', function () use ($implementaciones) {
            $implementaciones->toArray();
        });

        $this->assertSame(
            1,
            $consultas,
            'La URL base del formulario tiene que leerse una sola vez por request, no una por fila serializada.'
        );
    }

    /**
     * El form_link sigue armándose igual: url base + token, y null cuando falta alguno.
     *
     * @return void
     */
    public function test_el_form_link_no_cambio_de_valor_con_el_memo(): void
    {
        AdminSetting::set('implementation_form_url', 'https://admin.comerciocity.com/configuracion/');
        AdminSetting::flush_memo();

        $implementation = $this->crear_implementacion($this->crear_cliente('Ana', 'Panadería La Espiga', false));

        $serializada = $implementation->toArray();

        $this->assertArrayHasKey('form_link', $serializada, 'form_link tiene que seguir viajando en el JSON.');
        $this->assertSame(
            'https://admin.comerciocity.com/configuracion/' . $implementation->form_token,
            $serializada['form_link'],
            'La barra final de la URL base se sigue recortando y el token se sigue pegando.'
        );

        // Sin token no hay link, igual que antes.
        $sin_token             = $this->crear_implementacion($this->crear_cliente('Juan', 'Ferretería Sur', false));
        $sin_token->form_token = null;
        $this->assertNull($sin_token->form_link, 'Sin form_token el link tiene que seguir dando null.');
    }

    /**
     * 🔴 El caso que obliga a invalidar el memo: guardar la URL y leerla en el MISMO request.
     *
     * Es exactamente lo que hace la pantalla de configuración. Si el memo no se enterara de la
     * escritura, el admin guardaría una URL nueva y seguiría viendo (y mandándole al cliente) la
     * vieja hasta el próximo request.
     *
     * @return void
     */
    public function test_guardar_la_url_en_el_mismo_request_invalida_el_memo(): void
    {
        AdminSetting::set('implementation_form_url', 'https://viejo.comerciocity.com/configuracion');
        AdminSetting::flush_memo();

        // Primera lectura: deja el valor viejo memorizado.
        $this->assertSame('https://viejo.comerciocity.com/configuracion', ImplementationSettings::get_form_url());

        // Escritura en el mismo request, por el mismo camino que usa el controller.
        AdminSetting::set('implementation_form_url', 'https://nuevo.comerciocity.com/configuracion');

        $this->assertSame(
            'https://nuevo.comerciocity.com/configuracion',
            ImplementationSettings::get_form_url(),
            'Después de guardar, la lectura del mismo request tiene que devolver el valor nuevo.'
        );
    }

    /**
     * Una key sin fila también queda memorizada: si no, el caso "sin configurar" seguiría pagando
     * una consulta por fila serializada, que es justo el problema que esto viene a resolver.
     *
     * @return void
     */
    public function test_una_key_sin_fila_tambien_se_memoriza(): void
    {
        AdminSetting::where('key', 'implementation_form_url')->delete();
        AdminSetting::flush_memo();

        $consultas = $this->consultas_contra('admin_settings', function () {
            ImplementationSettings::get_form_url();
            ImplementationSettings::get_form_url();
            ImplementationSettings::get_form_url();
        });

        $this->assertSame(1, $consultas, 'Una key sin fila se consulta una vez y se recuerda como vacía.');
        $this->assertSame('', ImplementationSettings::get_form_url(), 'Sin fila, el fallback sigue siendo cadena vacía.');
    }

    // ------------------------------------------------------------------ D2

    /**
     * 🔴 La prueba de contrato de D2: el cliente serializado tiene EXACTAMENTE las mismas claves
     * con el eager load nuevo que sin él.
     *
     * El camino viejo se reconstruye acá mismo (un upgrade traído solo con `client`, sin
     * `client.client_ecommerce`) y se compara clave por clave contra el que trae `withAll()`.
     * Así el test no depende de una lista escrita a mano que envejezca.
     *
     * Que las claves coincidan no es casualidad: el accesor lazy ya dejaba la relación cargada
     * antes de que Laravel serializara las relaciones, así que `client_ecommerce` viajaba igual.
     * Lo único que cambió es cuántas consultas cuesta.
     *
     * @return void
     */
    public function test_el_cliente_del_upgrade_serializa_las_mismas_claves_que_antes(): void
    {
        $cliente = $this->crear_cliente('Juan Pérez', 'Distribuidora del Sur');
        $upgrade = $this->crear_upgrade($cliente);

        // Camino viejo: solo 'client'. Los accesores ecommerce_* resuelven lazy.
        $viejo = ClientVersionUpgrade::query()
            ->where('id', $upgrade->id)
            ->with('client')
            ->first()
            ->toArray();

        // Camino nuevo: withAll(), que ahora precarga client.client_ecommerce.
        $nuevo = ClientVersionUpgrade::query()
            ->where('id', $upgrade->id)
            ->withAll()
            ->first()
            ->toArray();

        $claves_viejas = array_keys($viejo['client']);
        $claves_nuevas = array_keys($nuevo['client']);
        sort($claves_viejas);
        sort($claves_nuevas);

        $this->assertSame(
            $claves_viejas,
            $claves_nuevas,
            'El eager load de client_ecommerce no puede agregar ni sacar ninguna clave del cliente serializado.'
        );

        // Y los cuatro accesores tienen que seguir trayendo el mismo valor, no solo la misma clave.
        foreach (['ecommerce_spa_url', 'ecommerce_api_url', 'ecommerce_spa_path', 'ecommerce_api_path'] as $accesor) {
            $this->assertArrayHasKey($accesor, $nuevo['client'], "Falta el accesor {$accesor} en el cliente serializado.");
            $this->assertSame(
                $viejo['client'][$accesor],
                $nuevo['client'][$accesor],
                "El accesor {$accesor} cambió de valor con el eager load."
            );
        }

        $this->assertSame(
            'https://distribuidora-del-sur.com.ar',
            $nuevo['client']['ecommerce_spa_url'],
            'El accesor tiene que seguir resolviendo contra la tienda del cliente.'
        );
    }

    /**
     * Un cliente SIN tienda online sigue serializando igual: cadena vacía en los cuatro accesores
     * y la relación en null. Es el caso de los clientes que todavía no tienen ecommerce.
     *
     * @return void
     */
    public function test_un_cliente_sin_tienda_serializa_igual_que_antes(): void
    {
        $cliente = $this->crear_cliente('Ana Gómez', 'Panadería La Espiga', false);
        $upgrade = $this->crear_upgrade($cliente);

        $viejo = ClientVersionUpgrade::query()->where('id', $upgrade->id)->with('client')->first()->toArray();
        $nuevo = ClientVersionUpgrade::query()->where('id', $upgrade->id)->withAll()->first()->toArray();

        $claves_viejas = array_keys($viejo['client']);
        $claves_nuevas = array_keys($nuevo['client']);
        sort($claves_viejas);
        sort($claves_nuevas);

        $this->assertSame($claves_viejas, $claves_nuevas, 'Sin tienda online las claves también tienen que coincidir.');
        $this->assertSame('', $nuevo['client']['ecommerce_spa_url'], 'Sin tienda, el accesor sigue dando cadena vacía.');
        $this->assertNull($nuevo['client']['client_ecommerce'], 'Sin tienda, la relación sigue viajando en null.');
    }

    /**
     * Traer N upgrades con withAll() consulta client_ecommerces UNA vez, no N.
     *
     * @return void
     */
    public function test_el_listado_de_upgrades_consulta_las_tiendas_una_sola_vez(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->crear_upgrade($this->crear_cliente('Dueño ' . $i, 'Comercio ' . $i));
        }

        $consultas = $this->consultas_contra('client_ecommerces', function () {
            ClientVersionUpgrade::query()->withAll()->get()->toArray();
        });

        $this->assertSame(
            1,
            $consultas,
            'Con el eager load tiene que haber una sola consulta a client_ecommerces para todo el listado.'
        );
    }

    // ------------------------------------------------------------------ D5

    /**
     * 🔴 `for_select` devuelve los tres campos que usan los llamadores de admin-spa, y nada más.
     *
     * Los llamadores son dos, y los dos son genéricos:
     *  - common-vue/components/model/form/Index.vue: usa model.id como value y como texto
     *    `property.relation_label || relation_display_key`. La única property del repo con
     *    `relation => 'client'` es client_id de ClientVersionUpgradeProperties, que declara
     *    `relation_label => 'company_name'`; y ClientProperties no tiene ninguna property con
     *    `reprecentar_model`, así que el default del SPA es 'name'.
     *  - column-filter/modal/fields/FilterSelect.vue: usa model.id y `field.relation_label ||
     *    'name'`, y descarta la fila si id viene null.
     *
     * De ahí los tres campos. Si alguna vez se agrega una property con `relation => 'client'` y
     * otro `relation_label`, este test no lo detecta solo — pero el de abajo
     * (test_las_properties_que_apuntan_a_client_no_piden_un_campo_que_for_select_no_manda) sí.
     *
     * @return void
     */
    public function test_for_select_devuelve_solo_id_nombre_y_razon_social(): void
    {
        $admin   = $this->crear_admin();
        $cliente = $this->crear_cliente('Juan Pérez', 'Distribuidora del Sur');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/client?for_select=1');

        $response->assertStatus(200);

        $payload = $response->json();

        // El envoltorio { models: [...] } es contrato: extract_models_array() del SPA devuelve
        // lista vacía —sin error visible— si la respuesta fuera un array pelado.
        $this->assertArrayHasKey('models', $payload, 'La respuesta tiene que seguir viniendo envuelta en "models".');
        $this->assertIsArray($payload['models']);

        $fila = null;
        foreach ($payload['models'] as $model) {
            if ((int) $model['id'] === (int) $cliente->id) {
                $fila = $model;
                break;
            }
        }

        $this->assertNotNull($fila, 'El cliente creado tiene que estar en el listado liviano.');

        $claves = array_keys($fila);
        sort($claves);

        $this->assertSame(
            ['company_name', 'id', 'name'],
            $claves,
            'for_select tiene que devolver exactamente los tres campos que usan los selects del SPA.'
        );

        $this->assertSame('Juan Pérez', $fila['name']);
        $this->assertSame('Distribuidora del Sur', $fila['company_name']);
    }

    /**
     * 🔴 El guardián del contrato de D5: ninguna property que apunte al modelo `client` puede
     * pedir como etiqueta un campo que `for_select` no manda.
     *
     * Este test lee las properties de verdad, así que el día que alguien agregue un
     * `relation => 'client'` con `relation_label => 'slug'` se pone en rojo acá y no en un
     * `<select>` mudo del panel.
     *
     * @return void
     */
    public function test_las_properties_que_apuntan_a_client_no_piden_un_campo_que_for_select_no_manda(): void
    {
        $admin = $this->crear_admin();
        $this->crear_cliente('Juan Pérez', 'Distribuidora del Sur');

        $fila = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/client?for_select=1')->json('models.0');
        $this->assertIsArray($fila, 'Hace falta al menos un cliente para poder comparar.');

        // Todas las clases de ModelProperties del repo, recorridas de verdad.
        $etiquetas_pedidas = [];
        foreach (glob(app_path('ModelProperties') . DIRECTORY_SEPARATOR . '*.php') as $archivo) {
            $clase = 'App\\ModelProperties\\' . basename($archivo, '.php');
            if (! class_exists($clase) || ! method_exists($clase, 'all')) {
                continue;
            }

            foreach ($clase::all() as $property) {
                if (! is_array($property)) {
                    continue;
                }
                $relacion = isset($property['relation']) ? $property['relation'] : null;
                if ($relacion !== 'client') {
                    continue;
                }
                // Sin relation_label, el SPA cae a 'name', que sí viaja.
                $etiqueta = isset($property['relation_label']) ? $property['relation_label'] : 'name';
                $etiquetas_pedidas[$etiqueta] = $clase;
            }
        }

        $this->assertNotEmpty(
            $etiquetas_pedidas,
            'Se esperaba al menos una property con relation => client (hoy, client_id de ClientVersionUpgrade).'
        );

        foreach ($etiquetas_pedidas as $etiqueta => $clase) {
            $this->assertArrayHasKey(
                $etiqueta,
                $fila,
                "{$clase} pide '{$etiqueta}' como etiqueta del select de client, y for_select no lo manda. "
                . 'O se agrega al select de ClientController::index_json(), o esa property no puede usar for_select.'
            );
        }
    }

    /**
     * `for_select` combinado con `page` también funciona.
     *
     * Es una rama que existe en el controller (index_json pagina cuando llega `page`) y que el
     * código nuevo tiene que saber recorrer: sobre el paginador, each() forwardea a la colección
     * de adentro. Los dos llamadores del SPA no mandan `page` hoy, pero el endpoint lo acepta y
     * no puede tirar un 500 si alguien lo combina.
     *
     * @return void
     */
    public function test_for_select_tambien_anda_paginado(): void
    {
        $admin = $this->crear_admin();
        for ($i = 0; $i < 3; $i++) {
            $this->crear_cliente('Dueño ' . $i, 'Comercio ' . $i);
        }

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/client?for_select=1&page=1&per_page=2');

        $response->assertStatus(200);

        $payload = $response->json();
        $this->assertArrayHasKey('models', $payload);
        // Paginado: el SPA lee models.data, que extract_models_array() también contempla.
        $this->assertArrayHasKey('data', $payload['models'], 'Con page tiene que seguir viniendo el paginador de Laravel.');
        $this->assertNotEmpty($payload['models']['data']);

        $claves = array_keys($payload['models']['data'][0]);
        sort($claves);

        $this->assertSame(
            ['company_name', 'id', 'name'],
            $claves,
            'Paginado también tiene que devolver los tres campos y ningún accesor lazy.'
        );
    }

    /**
     * El listado COMPLETO (sin el flag) no se tocó: sigue trayendo las relaciones y los accesores.
     *
     * Es la otra mitad del contrato de D5: cinco vistas de admin-spa le pegan a /client sin el
     * flag contando con withAll().
     *
     * @return void
     */
    public function test_el_listado_completo_de_clientes_sigue_trayendo_todo(): void
    {
        $admin   = $this->crear_admin();
        $cliente = $this->crear_cliente('Juan Pérez', 'Distribuidora del Sur');

        $fila = null;
        foreach ($this->actingAs($admin, 'sanctum')->getJson('/api/admin/client')->json('models') as $model) {
            if ((int) $model['id'] === (int) $cliente->id) {
                $fila = $model;
                break;
            }
        }

        $this->assertNotNull($fila, 'El cliente tiene que estar en el listado completo.');

        foreach (['client_apis', 'client_employees', 'implementation', 'client_ecommerce', 'current_version', 'active_client_api', 'shared_database_group'] as $relacion) {
            $this->assertArrayHasKey($relacion, $fila, "El listado completo dejó de traer la relación {$relacion}.");
        }

        foreach (['ecommerce_spa_url', 'ecommerce_api_url', 'ecommerce_spa_path', 'ecommerce_api_path'] as $accesor) {
            $this->assertArrayHasKey($accesor, $fila, "El listado completo dejó de traer el accesor {$accesor}.");
        }

        $this->assertSame('https://distribuidora-del-sur.com.ar', $fila['ecommerce_spa_url']);
    }

    /**
     * Upgrade mínimo de un cliente, para tener algo que traiga `client` como relación.
     *
     * @param Client $client Cliente dueño.
     *
     * @return ClientVersionUpgrade
     */
    private function crear_upgrade(Client $client): ClientVersionUpgrade
    {
        $upgrade                 = new ClientVersionUpgrade();
        $upgrade->client_id      = $client->id;
        $upgrade->to_version_id  = $this->version_de_destino()->id;
        $upgrade->status         = 'pendiente';
        $upgrade->scheduled_date = now()->toDateString();
        $upgrade->save();

        return $upgrade;
    }

    /**
     * Versión de destino compartida por todos los upgrades del test (to_version_id es obligatorio).
     *
     * @return Version
     */
    private function version_de_destino(): Version
    {
        if ($this->version_destino === null) {
            $version               = new Version();
            $version->version      = '9.9.9';
            $version->title        = 'Versión de prueba';
            $version->status       = 'published';
            $version->published_at = now();
            $version->save();

            $this->version_destino = $version;
        }

        return $this->version_destino;
    }
}
