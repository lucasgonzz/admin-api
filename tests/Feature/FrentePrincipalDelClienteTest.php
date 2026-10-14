<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\ModelProperties\ClientVersionUpgradeProperties;
use App\Services\ClientVersionUpgradeCreationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Misión `un-solo-frente` (14/10/2026) — cuál de las ClientApi de un cliente es su FRENTE PRINCIPAL.
 *
 * Decisión de Lucas del 14/10/2026: se deja de rotar entre las dos carpetas de cada cliente. El que
 * atiende desde su frente 1 se actualiza ahí mismo; el que atiende desde el 2 se lleva al 1 una
 * última vez. Todo eso depende de saber cuál es "el 1", y la base no lo dice: se deduce con la regla
 * de Client::frente_principal_entre(). Lo que estos tests fijan:
 *
 *  1. 🔴 La regla por PREFIJO ESTRICTO del nombre de carpeta, y que la de menor id es solo el
 *     último recurso. El caso que lo justifica es real: en golonorte (cliente 15, VPS) la 73 es
 *     `golonorte2` y la 74 es `golonorte`.
 *  2. Que `resolve_default_target_client_api_id()` —el default del panel, de `POST claude/upgrades`
 *     y del lote— devuelva el principal en los dos casos: cliente activo en el 1 y activo en el 2.
 *  3. 🔴 Que el accessor `frente_principal_client_api_id` no dispare consultas cuando la relación no
 *     está cargada (viaja en TODA serialización de un cliente) y que llegue al JSON de la ficha que
 *     lee el formulario de actualizaciones del panel.
 */
class FrentePrincipalDelClienteTest extends TestCase
{
    use DatabaseTransactions;

    /* ------------------------------------------------------------------------------------------
     | Armado
     |----------------------------------------------------------------------------------------- */

    /**
     * Cliente sin ClientApi.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client                  = new Client();
        $client->name            = 'Cliente frente ' . Str::random(6);
        $client->company_name    = 'Empresa frente';
        $client->slug            = 'cliente-frente-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->save();

        return $client;
    }

    /**
     * ClientApi del shared, con `path` `<carpeta>/api`.
     *
     * @param  Client  $client
     * @param  string  $carpeta
     * @return ClientApi
     */
    private function api_shared(Client $client, string $carpeta): ClientApi
    {
        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $carpeta . '.comerciocity.com/public';
        $api->path         = $carpeta . '/api';
        $api->spa_url      = 'https://' . $carpeta . '.comerciocity.com';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return $api;
    }

    /**
     * ClientApi del VPS, con `vps_path` = carpeta (y un `path` de resto, que no se tiene que mirar).
     *
     * @param  Client  $client
     * @param  string  $carpeta
     * @return ClientApi
     */
    private function api_vps(Client $client, string $carpeta): ClientApi
    {
        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $carpeta . '.comerciocity.com';
        $api->path         = 'resto-del-shared-' . Str::random(4) . '/api';
        $api->spa_url      = 'https://' . $carpeta . '.comerciocity.com';
        $api->hosting_type = 'vps';
        $api->vps_path     = $carpeta;
        $api->save();

        return $api;
    }

    /**
     * Frente principal leído de una instancia fresca (sin la relación cargada de antes).
     *
     * @param  Client  $client
     * @return int|null
     */
    private function principal_de(Client $client)
    {
        return Client::find($client->id)->resolver_frente_principal_client_api_id();
    }

    /* ==========================================================================================
     | 1) La regla
     |========================================================================================= */

    /**
     * 🔴 golonorte, tal cual está en producción: la ClientApi de MENOR id es `golonorte2` y la de
     * mayor id es `golonorte`. El principal es `golonorte` porque su nombre es prefijo estricto del
     * otro, no porque tenga el menor id. Con "menor id" a secas, el frente 1 de este cliente sería su
     * carpeta 2.
     */
    public function test_el_prefijo_gana_aunque_los_ids_esten_invertidos(): void
    {
        $client = $this->crear_cliente();
        $dos    = $this->api_vps($client, 'golonorte2');
        $uno    = $this->api_vps($client, 'golonorte');

        $this->assertLessThan($uno->id, $dos->id, 'El escenario necesita los ids invertidos, como en producción.');
        $this->assertSame((int) $uno->id, $this->principal_de($client));
    }

    /** `fenix` / `fenix-prueba` en el shared: el `/api` final no cuenta, y el sufijo `-prueba` sí. */
    public function test_el_sufijo_prueba_no_es_el_principal(): void
    {
        $client = $this->crear_cliente();
        $prueba = $this->api_shared($client, 'fenix-prueba');
        $uno    = $this->api_shared($client, 'fenix');

        $this->assertNotSame((int) $prueba->id, $this->principal_de($client));
        $this->assertSame((int) $uno->id, $this->principal_de($client));
    }

    /** `innovate` / `innovate-2` y `demo2` / `demo22`: el prefijo decide en los dos. */
    public function test_el_sufijo_numerico_con_y_sin_guion(): void
    {
        $client = $this->crear_cliente();
        $uno    = $this->api_shared($client, 'innovate');
        $this->api_shared($client, 'innovate-2');
        $this->assertSame((int) $uno->id, $this->principal_de($client));

        $otro      = $this->crear_cliente();
        $demo22    = $this->api_shared($otro, 'demo22');
        $demo2     = $this->api_shared($otro, 'demo2');
        $this->assertNotSame((int) $demo22->id, $this->principal_de($otro));
        $this->assertSame((int) $demo2->id, $this->principal_de($otro));
    }

    /** Con una sola ClientApi, esa (aunque su nombre no sea prefijo de nada). */
    public function test_con_una_sola_client_api_es_esa(): void
    {
        $client = $this->crear_cliente();
        $unica  = $this->api_shared($client, 'unica-' . Str::random(4));

        $this->assertSame((int) $unica->id, $this->principal_de($client));
    }

    /** `colman-beta` / `lucasgonzalez2`: ninguno es prefijo del otro, decide el menor id. */
    public function test_sin_prefijo_decide_el_menor_id(): void
    {
        $client  = $this->crear_cliente();
        $primera = $this->api_shared($client, 'colman-beta');
        $segunda = $this->api_shared($client, 'lucasgonzalez2');

        $this->assertLessThan($segunda->id, $primera->id);
        $this->assertSame((int) $primera->id, $this->principal_de($client));
    }

    /** Sin ninguna ClientApi no hay frente principal. */
    public function test_sin_client_api_no_hay_frente_principal(): void
    {
        $this->assertNull($this->principal_de($this->crear_cliente()));
    }

    /**
     * Con más de dos ClientApi (quedan altas duplicadas viejas), el principal es el único nombre que
     * es prefijo de otro y no tiene él mismo un prefijo; si no es único, decide el menor id.
     */
    public function test_con_mas_de_dos_client_api_la_regla_se_generaliza(): void
    {
        $client = $this->crear_cliente();
        $suelta = $this->api_shared($client, 'otra-cosa');
        $dos    = $this->api_shared($client, 'ferre2');
        $uno    = $this->api_shared($client, 'ferre');

        $this->assertSame((int) $uno->id, $this->principal_de($client));

        /* Dos familias con prefijo: no hay un único candidato y decide el menor id. */
        $otro    = $this->crear_cliente();
        $a       = $this->api_shared($otro, 'aaa');
        $this->api_shared($otro, 'aaa2');
        $this->api_shared($otro, 'bbb');
        $this->api_shared($otro, 'bbb2');

        $this->assertSame((int) $a->id, $this->principal_de($otro));
        $this->assertNotNull($suelta->id);
        $this->assertNotNull($dos->id);
    }

    /** La regla es pura: funciona sobre ClientApi en memoria, sin base. */
    public function test_la_regla_es_pura_y_no_toca_la_base(): void
    {
        $uno               = new ClientApi();
        $uno->id           = 501;
        $uno->hosting_type = 'shared_hosting';
        $uno->path         = 'quino2/api';

        $dos               = new ClientApi();
        $dos->id           = 502;
        $dos->hosting_type = 'shared_hosting';
        $dos->path         = 'quino/api';

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame(502, Client::frente_principal_entre([$uno, $dos]));
        $this->assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /* ==========================================================================================
     | 2) El destino por defecto de una actualización
     |========================================================================================= */

    /**
     * 🔴 El default ya no es "la primera que no sea la activa": es el frente principal, en los dos
     * casos. Con el cliente activo en el 1 eso es el en el lugar; con el cliente activo en el 2, la
     * última rotación hacia el 1.
     */
    public function test_el_destino_por_defecto_es_el_principal_este_activo_el_1_o_el_2(): void
    {
        $servicio = app(ClientVersionUpgradeCreationService::class);

        /* Activo en el 1. Los ids invertidos a propósito: el 2 tiene el menor id. */
        $en_el_1 = $this->crear_cliente();
        $dos     = $this->api_shared($en_el_1, 'pinturas2');
        $uno     = $this->api_shared($en_el_1, 'pinturas');
        $en_el_1->active_client_api_id = $uno->id;
        $en_el_1->save();

        $this->assertSame((int) $uno->id, $servicio->resolve_default_target_client_api_id(Client::find($en_el_1->id)));
        $this->assertSame(Client::MODO_EN_EL_LUGAR, Client::find($en_el_1->id)->modo_de_despliegue_hacia($uno->id));

        /* Activo en el 2. */
        $en_el_2 = $this->crear_cliente();
        $uno_b   = $this->api_shared($en_el_2, 'libreria');
        $dos_b   = $this->api_shared($en_el_2, 'libreria2');
        $en_el_2->active_client_api_id = $dos_b->id;
        $en_el_2->save();

        $this->assertSame((int) $uno_b->id, $servicio->resolve_default_target_client_api_id(Client::find($en_el_2->id)));
        $this->assertSame(Client::MODO_ROTACION, Client::find($en_el_2->id)->modo_de_despliegue_hacia($uno_b->id));
        $this->assertNotNull($dos->id);
    }

    /** La creación sin destino explícito (la del panel) toma el frente principal. */
    public function test_build_create_attributes_sin_destino_toma_el_principal(): void
    {
        $client = $this->crear_cliente();
        $uno    = $this->api_shared($client, 'bazar');
        $dos    = $this->api_shared($client, 'bazar2');
        $client->active_client_api_id = $dos->id;
        $client->save();

        $atributos = app(ClientVersionUpgradeCreationService::class)
            ->build_create_attributes(Client::find($client->id), null, 1, ['created_by_admin_id' => null]);

        $this->assertSame((int) $uno->id, $atributos['target_client_api_id']);
    }

    /** Un cliente sin API activa cae en rotación: no hay código vivo conocido que pisar. */
    public function test_sin_api_activa_el_modo_es_rotacion(): void
    {
        $client = $this->crear_cliente();
        $uno    = $this->api_shared($client, 'kiosco');

        $this->assertNull($client->active_client_api_id);
        $this->assertSame(Client::MODO_ROTACION, $client->modo_de_despliegue_hacia($uno->id));
        $this->assertNull($client->modo_de_despliegue_hacia(null));
    }

    /* ==========================================================================================
     | 3) El accessor y el JSON del cliente
     |========================================================================================= */

    /**
     * 🔴 Sin la relación cargada, el accessor devuelve null y NO consulta la base: viaja en el
     * `$appends` de todos los clientes serializados, y una consulta por cliente en el listado del
     * panel sería exactamente el defecto que `scopeWithAll()` vino a sacar.
     */
    public function test_el_accessor_sin_la_relacion_cargada_no_consulta_nada(): void
    {
        $client = $this->crear_cliente();
        $this->api_shared($client, 'mercado');

        $fresco = Client::find($client->id);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertNull($fresco->frente_principal_client_api_id);
        $this->assertCount(0, DB::getQueryLog(), 'El accessor disparó consultas sin la relación cargada.');
        $this->assertFalse($fresco->relationLoaded('client_apis'), 'El accessor cargó la relación por su cuenta.');

        DB::disableQueryLog();
    }

    /** Con la relación cargada, el accessor da el principal y viaja en el array del modelo. */
    public function test_el_accessor_con_la_relacion_cargada_viaja_en_el_json(): void
    {
        $client = $this->crear_cliente();
        $dos    = $this->api_shared($client, 'verduleria2');
        $uno    = $this->api_shared($client, 'verduleria');

        $con_relacion = Client::with('client_apis')->find($client->id);

        $this->assertSame((int) $uno->id, $con_relacion->toArray()['frente_principal_client_api_id']);
        $this->assertNotNull($dos->id);
    }

    /**
     * El campo llega a la ficha que pide el formulario de actualizaciones del panel
     * (`GET /api/admin/client/{id}`, que carga `withAll()`): es lo que preselecciona admin-spa.
     */
    public function test_la_ficha_del_cliente_del_panel_trae_el_frente_principal(): void
    {
        $admin           = new Admin();
        $admin->name     = 'Admin frente principal';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();
        Sanctum::actingAs($admin);

        $client = $this->crear_cliente();
        $dos    = $this->api_shared($client, 'carniceria2');
        $uno    = $this->api_shared($client, 'carniceria');
        $client->active_client_api_id = $dos->id;
        $client->save();

        $modelo = $this->getJson('/api/admin/client/' . $client->id)->assertStatus(200)->json('model');

        $this->assertSame((int) $uno->id, $modelo['frente_principal_client_api_id']);
        $this->assertSame((int) $dos->id, (int) $modelo['active_client_api_id']);
    }

    /**
     * El meta del panel suma `default_principal` y conserva `default_other_than_active`: un admin-spa
     * viejo sigue funcionando con el comportamiento de siempre.
     */
    public function test_el_meta_del_panel_pide_el_principal_sin_sacar_el_default_viejo(): void
    {
        $destino = null;
        foreach (ClientVersionUpgradeProperties::all() as $propiedad) {
            if (isset($propiedad['key']) && $propiedad['key'] === 'target_client_api_id') {
                $destino = $propiedad;
            }
        }

        $this->assertNotNull($destino);
        $this->assertTrue($destino['from_parent_field']['default_principal']);
        $this->assertTrue($destino['from_parent_field']['default_other_than_active']);
    }
}
