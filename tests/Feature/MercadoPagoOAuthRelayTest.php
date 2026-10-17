<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Demo;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Callback central de OAuth de Mercado Pago (misión mp-redireccion-central-admin, 17/10/2026).
 *
 * `GET /api/mercadopago/callback` es la única `redirect_uri` registrada en la app de Mercado Pago:
 * recibe el `code` y reenvía al empresa-api del cliente cuyo host viaja en el `state`
 * (`<64 alfanuméricos>_<host>`).
 *
 * Lo crítico que se prueba es el anti open-redirect: el destino NUNCA sale de la request, sale de
 * `client_apis.url` / `demos.erp_api_url`. Todo lo que no resuelva contra la base (hosts de ataque,
 * formas raras, sufijos, mayúsculas, puertos) tiene que dar 422 SIN cabecera `Location`.
 */
class MercadoPagoOAuthRelayTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Ruta del callback central.
     */
    const RUTA = '/api/mercadopago/callback';

    /**
     * Ruta fija del callback en el ERP.
     */
    const RUTA_ERP = '/api/integraciones/mercadopago/callback';

    /**
     * Cliente con una ClientApi de un hosting dado.
     *
     * @param  string       $host          Host de la API (sin esquema).
     * @param  string       $hosting_type  'shared_hosting' | 'vps'
     * @param  string|null  $url           URL completa; si es null se arma https://<host>.
     * @return ClientApi
     */
    private function crear_api(string $host, string $hosting_type, ?string $url = null): ClientApi
    {
        $client                  = new Client();
        $client->name            = 'Cliente ' . Str::random(6);
        $client->company_name    = 'Empresa ' . Str::random(6);
        $client->slug            = 'c-' . Str::lower(Str::random(10));
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = $url !== null ? $url : 'https://' . $host;
        $api->path         = 'ejemplo/' . Str::random(6);
        $api->hosting_type = $hosting_type;
        $api->save();

        return $api;
    }

    /**
     * Demo con la URL de API dada.
     *
     * @param  string  $erp_api_url
     * @param  string  $erp_hosting_type
     * @return Demo
     */
    private function crear_demo(string $erp_api_url, string $erp_hosting_type = 'shared_hosting'): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://spa-' . Str::lower(Str::random(6)) . '.ejemplo.test';
        $demo->erp_api_url       = $erp_api_url;
        $demo->ecommerce_spa_url = 'https://tienda-' . Str::lower(Str::random(6)) . '.ejemplo.test';
        $demo->ecommerce_api_url = 'https://api-tienda-' . Str::lower(Str::random(6)) . '.ejemplo.test';
        $demo->erp_hosting_type  = $erp_hosting_type;
        $demo->save();

        return $demo;
    }

    /**
     * Host único para que ningún dato de la base de testing se cruce con el del test.
     *
     * @return string
     */
    private function host_unico(): string
    {
        return 'api-' . Str::lower(Str::random(8)) . '.relay.ejemplo.test';
    }

    /**
     * 64 alfanuméricos, como los que guarda OauthState en el ERP.
     *
     * @return string
     */
    private function aleatorio(): string
    {
        return Str::random(64);
    }

    /**
     * Pega al callback central con la query dada.
     *
     * @param  array  $query
     * @return \Illuminate\Testing\TestResponse
     */
    private function pegar(array $query)
    {
        return $this->get(self::RUTA . '?' . http_build_query($query));
    }

    /**
     * Parte la URL de `Location` en [base sin query, query parseada].
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return array
     */
    private function destino($respuesta): array
    {
        $location = $respuesta->headers->get('Location');
        $this->assertNotNull($location, 'Se esperaba una redirección con Location.');

        $partes = explode('?', $location, 2);
        parse_str(isset($partes[1]) ? $partes[1] : '', $query);

        return [$partes[0], $query];
    }

    /**
     * Un 422 sin redirección: la forma de TODO rechazo.
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return void
     */
    private function assert_rechazo($respuesta): void
    {
        $respuesta->assertStatus(422);
        $this->assertNull($respuesta->headers->get('Location'), 'Un rechazo no puede traer Location.');
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-referrer', (string) $respuesta->headers->get('Referrer-Policy'));
    }

    /**
     * Cliente de shared hosting: el reenvío entra por /public.
     *
     * @return void
     */
    public function test_cliente_de_shared_reenvia_por_public(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'shared_hosting');
        $random = $this->aleatorio();

        $respuesta = $this->pegar(['code' => 'TG-ABC123', 'state' => $random . '_' . $host]);

        $respuesta->assertStatus(302);
        [$base, $query] = $this->destino($respuesta);
        $this->assertSame('https://' . $host . '/public' . self::RUTA_ERP, $base);
        $this->assertSame(['code' => 'TG-ABC123', 'state' => $random], $query);
    }

    /**
     * Cliente de VPS: sin /public.
     *
     * @return void
     */
    public function test_cliente_de_vps_reenvia_sin_public(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'vps');
        $random = $this->aleatorio();

        $respuesta = $this->pegar(['code' => 'TG-XYZ', 'state' => $random . '_' . $host]);

        $respuesta->assertStatus(302);
        [$base, $query] = $this->destino($respuesta);
        $this->assertSame('https://' . $host . self::RUTA_ERP, $base);
        $this->assertSame($random, $query['state']);
    }

    /**
     * Una URL guardada con /public repetido no duplica el sufijo (la regla es la del resolver).
     *
     * @return void
     */
    public function test_url_guardada_con_public_no_lo_duplica(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'shared_hosting', 'https://' . $host . '/public/public');

        $respuesta = $this->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $host]);

        [$base] = $this->destino($respuesta);
        $this->assertSame('https://' . $host . '/public' . self::RUTA_ERP, $base);
    }

    /**
     * Demo de shared: su base es demos.erp_api_url con /public.
     *
     * @return void
     */
    public function test_demo_de_shared_resuelve_su_base(): void
    {
        $host = $this->host_unico();
        $this->crear_demo('https://' . $host . '/public', 'shared_hosting');
        $random = $this->aleatorio();

        $respuesta = $this->pegar(['code' => 'c1', 'state' => $random . '_' . $host]);

        $respuesta->assertStatus(302);
        [$base, $query] = $this->destino($respuesta);
        $this->assertSame('https://' . $host . '/public' . self::RUTA_ERP, $base);
        $this->assertSame($random, $query['state']);
    }

    /**
     * Demo de VPS: sin /public aunque la columna lo traiga.
     *
     * @return void
     */
    public function test_demo_de_vps_resuelve_sin_public(): void
    {
        $host = $this->host_unico();
        $this->crear_demo('https://' . $host . '/public', 'vps');

        $respuesta = $this->pegar(['code' => 'c1', 'state' => $this->aleatorio() . '_' . $host]);

        [$base] = $this->destino($respuesta);
        $this->assertSame('https://' . $host . self::RUTA_ERP, $base);
    }

    /**
     * Un host que ni client_apis ni demos conocen: 422 sin Location.
     *
     * @return void
     */
    public function test_host_desconocido_da_422_sin_location(): void
    {
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $this->host_unico()]));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function hosts_de_ataque(): array
    {
        return [
            'dominio ajeno'                     => ['evil.com'],
            'sufijo del registrado'             => ['{host}.evil.com'],
            'arroba (userinfo)'                 => ['{host}@evil.com'],
            'arroba antes del registrado'       => ['evil.com@{host}'],
            'con puerto'                        => ['{host}:8080'],
            'con puerto vacío'                  => ['{host}:'],
            'mayúsculas'                        => ['API-{HOST}'],
            'registrado en mayúsculas'          => ['{HOST}'],
            'doble punto'                       => ['api..{host}'],
            'doble punto en el medio'           => ['api-x..ejemplo.test'],
            'vacío'                             => [''],
            'con barra'                         => ['{host}/evil.com'],
            'con barra invertida'               => ['{host}\\evil.com'],
            'con CRLF e inyección'              => ["{host}\r\nLocation: https://evil.com"],
            'con espacio'                       => ['{host} evil.com'],
            'punto al final'                    => ['{host}.'],
            'punto al principio'                => ['.{host}'],
            'guion al principio'                => ['-{host}'],
            'con guion bajo'                    => ['{host}_evil.com'],
            'con numeral'                       => ['{host}#evil.com'],
            'con interrogación'                 => ['{host}?evil.com'],
            'solo una parte del registrado'     => ['relay.ejemplo.test'],
            'prefijo del registrado'            => ['api-'],
        ];
    }

    /**
     * @dataProvider hosts_de_ataque
     *
     * Con un sistema legítimo registrado (para probar que los ataques no se le cuelan), todo host
     * que no sea EXACTAMENTE el registrado da 422 y jamás una redirección.
     *
     * @param  string  $plantilla
     * @return void
     */
    public function test_hosts_de_ataque_dan_422_sin_location(string $plantilla): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'vps');
        $this->crear_demo('https://' . $this->host_unico() . '/public', 'shared_hosting');

        $atacante = str_replace(['{host}', '{HOST}'], [$host, strtoupper($host)], $plantilla);

        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $atacante]));
    }

    /**
     * Un salto de línea o espacio AL FINAL del state lo recorta el middleware TrimStrings de Laravel antes de llegar
     * al servicio: el resultado es el state limpio, que apunta al host registrado. No es una ruta de ataque (el
     * destino sigue saliendo de la base) y se deja documentado para que nadie lo tome por un hueco de la regex,
     * que de por sí es estricta (`/D`: sin eso `$` aceptaría el salto final).
     *
     * @return void
     */
    public function test_un_espacio_final_en_el_state_lo_recorta_el_framework_y_el_destino_sigue_siendo_el_registrado(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'vps');
        $random = $this->aleatorio();

        $respuesta = $this->pegar(['code' => 'c', 'state' => $random . '_' . $host . "
"]);

        $respuesta->assertStatus(302);
        [$base, $query] = $this->destino($respuesta);
        $this->assertSame('https://' . $host . self::RUTA_ERP, $base);
        $this->assertSame($random, $query['state']);

        // La regex, sin el middleware de por medio, sí lo rechaza.
        $this->assertNull(app(\App\Services\MercadoPagoOAuthRelayService::class)->parse_state($random . '_' . $host . "
"));
    }

    /**
     * Un state con sufijo que es solo el final de un host registrado no matchea (se compara el host exacto).
     *
     * @return void
     */
    public function test_un_sufijo_o_prefijo_del_host_registrado_no_alcanza(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'vps');

        // sufijo: le saco el "api-xxxx." del principio.
        $sufijo = substr($host, strpos($host, '.') + 1);
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $sufijo]));

        // prefijo: le saco el final.
        $prefijo = substr($host, 0, -5);
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $prefijo]));
    }

    /**
     * Una URL registrada con barra invertida, arroba o caracteres de control no se usa de destino: `parse_url`
     * dice que el host es el último, pero un navegador toma `\` como `/` y terminaría en el primero.
     *
     * @return void
     */
    public function test_una_url_registrada_con_barra_invertida_o_arroba_no_se_usa_de_destino(): void
    {
        $host_1 = $this->host_unico();
        $this->crear_api($host_1, 'vps', 'https://evil.example\\@' . $host_1);
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $host_1]));

        $host_2 = $this->host_unico();
        $this->crear_api($host_2, 'vps', 'https://usuario@' . $host_2);
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $host_2]));

        $host_3 = $this->host_unico();
        $this->crear_demo('https://evil.example\\@' . $host_3);
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $host_3]));
    }

    /**
     * El state sin sufijo (como el de un ERP viejo), cortos, largos o con basura: 422.
     *
     * @return void
     */
    public function test_state_mal_formado_da_422(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'vps');
        $random = $this->aleatorio();

        // Sin sufijo.
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $random]));
        // Sin state.
        $this->assert_rechazo($this->pegar(['code' => 'c']));
        // Aleatorio demasiado corto / largo.
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => substr($random, 0, 63) . '_' . $host]));
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $random . 'a_' . $host]));
        // Caracteres no alfanuméricos en el aleatorio.
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => substr($random, 0, 63) . '-_' . $host]));
        // Separador distinto.
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $random . '-' . $host]));
        // Doble separador.
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $random . '__' . $host]));
        // Un state que es un array.
        $this->assert_rechazo($this->get(self::RUTA . '?code=c&state[]=' . $random . '_' . $host));
        // Un host larguísimo.
        $this->assert_rechazo($this->pegar(['code' => 'c', 'state' => $random . '_' . str_repeat('a', 300)]));
        // Sin nada.
        $this->assert_rechazo($this->get(self::RUTA));
    }

    /**
     * Solo viajan code / state / error / error_description: cualquier otra clave se descarta.
     *
     * @return void
     */
    public function test_los_parametros_extra_no_se_reenvian(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'vps');
        $random = $this->aleatorio();

        $respuesta = $this->pegar([
            'code'         => 'TG-1',
            'state'        => $random . '_' . $host,
            'redirect_uri' => 'https://evil.com/robo',
            'url'          => 'https://evil.com',
            'next'         => '//evil.com',
            'mp'           => 'ok',
        ]);

        $respuesta->assertStatus(302);
        [$base, $query] = $this->destino($respuesta);
        $this->assertSame('https://' . $host . self::RUTA_ERP, $base);
        $this->assertSame(['code' => 'TG-1', 'state' => $random], $query);
        $this->assertStringNotContainsString('evil.com', (string) $respuesta->headers->get('Location'));
    }

    /**
     * Un `code` que viene como array no se reenvía (y el reenvío igual ocurre, el ERP lo rechaza solo).
     *
     * @return void
     */
    public function test_un_parametro_en_forma_de_array_no_se_reenvia(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'vps');
        $random = $this->aleatorio();

        $respuesta = $this->get(self::RUTA . '?code[]=a&code[]=b&state=' . $random . '_' . $host);

        $respuesta->assertStatus(302);
        [, $query] = $this->destino($respuesta);
        $this->assertSame(['state' => $random], $query);
    }

    /**
     * Si el comerciante cancela, Mercado Pago manda error + error_description y eso SÍ se reenvía.
     *
     * @return void
     */
    public function test_el_error_de_mercado_pago_se_reenvia(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'shared_hosting');
        $random = $this->aleatorio();

        $respuesta = $this->pegar([
            'error'             => 'access_denied',
            'error_description' => 'Autorización denegada & cancelada por el usuario',
            'state'             => $random . '_' . $host,
        ]);

        $respuesta->assertStatus(302);
        [$base, $query] = $this->destino($respuesta);
        $this->assertSame('https://' . $host . '/public' . self::RUTA_ERP, $base);
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame('Autorización denegada & cancelada por el usuario', $query['error_description']);
        $this->assertSame($random, $query['state']);
        $this->assertArrayNotHasKey('code', $query);
    }

    /**
     * El 302 y el 422 llevan no-store (el code viaja en la URL) y no-referrer.
     *
     * @return void
     */
    public function test_las_cabeceras_de_seguridad_estan_en_el_302_y_en_el_422(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'vps');

        $ok = $this->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $host]);
        $ok->assertStatus(302);
        $this->assertStringContainsString('no-store', (string) $ok->headers->get('Cache-Control'));
        $ok->assertHeader('Referrer-Policy', 'no-referrer');

        $mal = $this->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $this->host_unico()]);
        $mal->assertStatus(422);
        $this->assertStringContainsString('no-store', (string) $mal->headers->get('Cache-Control'));
        $mal->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('text/html', (string) $mal->headers->get('Content-Type'));
        // Página corta, en español y sin detalle de la causa.
        $mal->assertSee('No pudimos completar la conexión', false);
        $this->assertStringNotContainsString($host, $mal->getContent());
    }

    /**
     * El `code` (ni el aleatorio del state) aparece en ningún log, ni en el éxito ni en los rechazos.
     *
     * @return void
     */
    public function test_el_code_no_aparece_en_el_log(): void
    {
        $registros = [];
        Event::listen(MessageLogged::class, function (MessageLogged $evento) use (&$registros) {
            $registros[] = $evento->level . ' ' . $evento->message . ' ' . json_encode($evento->context);
        });

        $host = $this->host_unico();
        $this->crear_api($host, 'vps');
        $random = $this->aleatorio();
        $code   = 'TG-SECRETO-' . Str::random(20);

        // Éxito.
        $this->pegar(['code' => $code, 'state' => $random . '_' . $host])->assertStatus(302);
        // Host no registrado.
        $this->pegar(['code' => $code, 'state' => $random . '_otro-' . $host])->assertStatus(422);
        // Formato inválido (host de ataque).
        $this->pegar(['code' => $code, 'state' => $random . '_' . $host . '@evil.com'])->assertStatus(422);
        // Sin sufijo.
        $this->pegar(['code' => $code, 'state' => $random])->assertStatus(422);

        $todo = implode("\n", $registros);

        $this->assertStringContainsString('MP_OAUTH_RELAY', $todo, 'Los rechazos tienen que dejar su motivo en el log.');
        $this->assertStringContainsString('otro-' . $host, $todo, 'El motivo lleva el host.');
        $this->assertStringNotContainsString($code, $todo);
        $this->assertStringNotContainsString('TG-SECRETO', $todo);
        $this->assertStringNotContainsString($random, $todo);
    }

    /**
     * El mismo host en client_apis y en demos: gana client_apis.
     *
     * @return void
     */
    public function test_el_mismo_host_en_client_apis_y_demos_gana_client_apis(): void
    {
        $host = $this->host_unico();
        // La demo diría "shared" (con /public); el cliente, "vps" (sin /public).
        $this->crear_demo('https://' . $host . '/public', 'shared_hosting');
        $this->crear_api($host, 'vps');

        $respuesta = $this->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $host]);

        [$base] = $this->destino($respuesta);
        $this->assertSame('https://' . $host . self::RUTA_ERP, $base);
    }

    /**
     * Sea cual sea el state, el host de Location es siempre uno de los registrados en la base.
     *
     * @return void
     */
    public function test_el_destino_siempre_es_el_host_registrado(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'vps');
        $random = $this->aleatorio();

        $respuesta = $this->pegar([
            'code'              => 'https://evil.com',
            'state'             => $random . '_' . $host,
            'error_description' => '//evil.com\\@evil.com',
        ]);

        $respuesta->assertStatus(302);
        $this->assertSame($host, parse_url($respuesta->headers->get('Location'), PHP_URL_HOST));
    }

    /**
     * La ruta es pública: no pide Authorization ni clave.
     *
     * @return void
     */
    public function test_la_ruta_es_publica(): void
    {
        $host = $this->host_unico();
        $this->crear_api($host, 'vps');

        $this->withHeaders([])->pegar(['code' => 'c', 'state' => $this->aleatorio() . '_' . $host])->assertStatus(302);
    }

    /**
     * La URL central que Lucas registra en Mercado Pago es exactamente esta (una sola vez).
     * El ERP la lleva como default de config; este test fija el lado del admin.
     *
     * @return void
     */
    public function test_la_ruta_central_es_la_url_registrada_en_mercado_pago(): void
    {
        $this->assertSame(
            'https://api-admin.comerciocity.com/api/mercadopago/callback',
            'https://api-admin.comerciocity.com/' . ltrim(app('router')->getRoutes()->match(
                \Illuminate\Http\Request::create(self::RUTA, 'GET')
            )->uri(), '/')
        );
    }
}
