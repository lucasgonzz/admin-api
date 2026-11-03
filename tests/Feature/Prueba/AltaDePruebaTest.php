<?php

namespace Tests\Feature\Prueba;

use App\Jobs\ArmarPruebaJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientEcommerce;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Models\LeadPipelineStatus;
use App\Models\TrialInstance;
use App\Models\UserIdBlock;
use App\Services\LeadOrigenService;
use App\Services\Prueba\PruebaCicloService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Contrato C6, el alta: `POST api/landing/prueba/alta` y `GET api/landing/prueba/{uuid}/estado`.
 */
class AltaDePruebaTest extends BaseDePruebaGratuita
{
    public function test_el_alta_feliz_crea_lead_cliente_instancia_y_encola_el_armado(): void
    {
        $instancia = $this->crear_instancia_libre('prueba-01', 900100);

        $respuesta = $this->postear_alta();

        $respuesta->assertStatus(201);
        $uuid = $respuesta->json('uuid');
        $this->assertNotEmpty($uuid);

        /* El píxel de la landing dispara con los MISMOS ids que la CAPI del admin: Lead y StartTrial. */
        $this->assertSame(['Lead', 'StartTrial'], array_column($respuesta->json('meta_eventos'), 'nombre'));

        $lead = $this->lead_del_alta($uuid);
        $this->assertNotNull($lead);
        $this->assertSame(LeadPipelineStatus::PRUEBA_ACTIVA, $lead->status);
        $this->assertSame(LeadOrigenService::ORIGEN_LANDING, $lead->origen);
        $this->assertSame(self::TELEFONO_E164, $lead->phone);
        $this->assertSame('Prueba Test', $lead->company_name);
        $this->assertSame('ferreteria', $lead->demo_industria);
        $this->assertSame(7, (int) $lead->trial_days);
        $this->assertSame(64, strlen((string) $lead->demo_ingreso_token));
        $this->assertSame(64, strlen((string) $lead->demo_eventos_token));
        $this->assertNotEmpty($lead->doc_number);

        $client = Client::find($lead->promoted_client_id);
        $this->assertNotNull($client);
        $this->assertSame(PruebaCicloService::CLIENTE_PRUEBA, $client->estado);
        $this->assertSame(7, (int) $client->trial_days);
        $this->assertSame('2026-11-10 15:00:00', $client->trial_ends_at);
        $this->assertSame(900100, (int) $client->user_id);
        $this->assertSame('https://api-prueba-test.comerciocity.com', $client->api_url);
        $this->assertTrue((bool) $client->tiene_ecommerce);
        $this->assertSame(40, strlen((string) $client->api_key));

        /* Las dos ClientApi, en VPS, y la primera es la activa y la aprovisionada. */
        $apis = ClientApi::where('client_id', $client->id)->orderBy('id')->get();
        $this->assertCount(2, $apis);
        $this->assertSame('https://api-prueba-test.comerciocity.com', $apis[0]->url);
        $this->assertSame('https://prueba-test.comerciocity.com', $apis[0]->spa_url);
        $this->assertSame('vps', $apis[0]->hosting_type);
        $this->assertSame('prueba-01', $apis[0]->vps_path);
        $this->assertNotNull($apis[0]->hosting_provisioned_at);
        $this->assertSame('prueba-012', $apis[1]->vps_path);
        $this->assertSame((int) $apis[0]->id, (int) $client->active_client_api_id);
        $this->assertSame((int) $apis[0]->id, $client->resolver_frente_principal_client_api_id());

        /* La tienda (C7): un solo label. */
        $tienda = ClientEcommerce::where('client_id', $client->id)->first();
        $this->assertSame('tienda-prueba-test.comerciocity.com', $tienda->domain);
        $this->assertSame('https://api-tienda-prueba-test.comerciocity.com', $tienda->api_url);
        $this->assertTrue($tienda->is_vps());

        /* La instancia quedó tomada, con la contraseña cifrada y nunca en claro. */
        $instancia->refresh();
        $this->assertSame(TrialInstance::ESTADO_ASIGNANDO, $instancia->estado);
        $this->assertSame((int) $lead->id, (int) $instancia->lead_id);
        $this->assertSame((int) $client->id, (int) $instancia->client_id);
        $this->assertSame('prueba-test', $instancia->alias_slug);
        $this->assertSame('clave-de-prueba-123', Crypt::decryptString($instancia->password_cifrada));
        $this->assertStringNotContainsString('clave-de-prueba-123', json_encode($instancia->toArray()));

        /* El bloque de user_id quedó colgado del lead y del cliente. */
        $bloque = UserIdBlock::where('block_start', 900100)->first();
        $this->assertSame((int) $lead->id, (int) $bloque->lead_id);
        $this->assertSame((int) $client->id, (int) $bloque->client_id);

        /* Las conversiones, por el canal web y con el contexto del navegador. */
        $filas = LeadEventoMeta::where('lead_id', $lead->id)->orderBy('id')->get();
        $this->assertSame(['Lead', 'StartTrial'], $filas->pluck('evento')->all());
        $this->assertSame(['web', 'web'], $filas->pluck('canal')->all());
        $this->assertSame('https://comerciocity.com/prueba', $filas[0]->contexto_web['event_source_url']);
        $this->assertSame(array_column($respuesta->json('meta_eventos'), 'event_id'), $filas->pluck('event_id')->all());

        Queue::assertPushed(ArmarPruebaJob::class, function (ArmarPruebaJob $job) use ($instancia) {
            return $job->trial_instance_id === (int) $instancia->id;
        });

        /* El estado, recién pedido: creando. */
        $this->getJson('/api/landing/prueba/' . $uuid . '/estado')
            ->assertStatus(200)
            ->assertJson(['estado' => 'creando', 'progreso' => 5, 'video_url' => 'https://videos.test/bienvenida.mp4', 'whatsapp_soporte' => '+5493410000000'])
            ->assertJsonPath('url_ingreso', null);
    }

    public function test_el_slug_sale_de_la_empresa_y_no_pisa_uno_tomado(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        $this->crear_instancia_libre('prueba-02', 900200);

        ClientApi::create(['client_id' => 99999, 'url' => 'https://api-ferreteria-norte.comerciocity.com', 'path' => 'ferreteria-norte/api', 'spa_url' => 'https://ferreteria-norte.comerciocity.com', 'hosting_type' => 'vps', 'vps_path' => 'ferreteria-norte']);

        $this->postear_alta(['empresa' => 'Ferretería Norte', 'telefono' => '3444000001'])->assertStatus(201);
        $this->assertSame('ferreteria-norte-2', TrialInstance::where('nombre', 'prueba-01')->value('alias_slug'));

        /* Y un nombre largo se recorta a 20 sin perder la unicidad ni la forma. */
        $this->postear_alta(['empresa' => 'Distribuidora Mayorista del Litoral Sur S.R.L.', 'telefono' => '3444000002'])->assertStatus(201);
        $this->assertSame('distribuidora-mayori', TrialInstance::where('nombre', 'prueba-02')->value('alias_slug'));
    }

    public function test_el_slug_no_lleva_prefijo_de_host_y_no_pisa_la_tienda_ni_la_api_de_otro(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        $this->crear_instancia_libre('prueba-02', 900200);
        $this->crear_instancia_libre('prueba-03', 900300);
        $this->crear_instancia_libre('prueba-04', 900400);

        /* «Tienda Lola» no puede ser `tienda-lola`: sería la tienda de la prueba `lola`. Queda `lola`, y el cliente lleva el MISMO slug. */
        $this->postear_alta(['empresa' => 'Tienda Lola', 'telefono' => '3444000011'])->assertStatus(201);
        $this->assertSame('lola', TrialInstance::where('nombre', 'prueba-01')->value('alias_slug'));
        $this->assertSame('lola', Client::where('company_name', 'Tienda Lola')->value('slug'), 'El slug del cliente ES el alias.');

        /* «API Ferretotal» → `ferretotal`, que es el slug de un cliente real (sus cuatro hosts cuentan como tomados) → `ferretotal-2`. */
        Client::create(['name' => 'Ferre Total', 'is_active' => true, 'api_url' => '', 'api_key' => Str::random(40), 'inbound_api_key' => Str::random(40), 'slug' => 'ferretotal']);
        $this->postear_alta(['empresa' => 'API Ferretotal', 'telefono' => '3444000012'])->assertStatus(201);
        $this->assertSame('ferretotal-2', TrialInstance::where('nombre', 'prueba-02')->value('alias_slug'));

        /* La tienda de un cliente en `tienda-pepe.comerciocity.com` ocupa uno de los cuatro hosts de `pepe`: «Pepe» → `pepe-2`. */
        $pepe = Client::create(['name' => 'Pepe Real', 'is_active' => true, 'api_url' => '', 'api_key' => Str::random(40), 'inbound_api_key' => Str::random(40), 'slug' => 'pepe-real-' . uniqid()]);
        ClientEcommerce::create(['client_id' => $pepe->id, 'domain' => 'tienda-pepe.comerciocity.com', 'api_url' => 'https://api-tienda-pepe.comerciocity.com', 'spa_url' => 'https://tienda-pepe.comerciocity.com', 'status' => 'active']);
        $this->postear_alta(['empresa' => 'Pepe', 'telefono' => '3444000013'])->assertStatus(201);
        $this->assertSame('pepe-2', TrialInstance::where('nombre', 'prueba-03')->value('alias_slug'));

        /* Y el alias de otra prueba viva también: «Lola» → `lola-2`. */
        $this->postear_alta(['empresa' => 'Lola', 'telefono' => '3444000014'])->assertStatus(201);
        $this->assertSame('lola-2', TrialInstance::where('nombre', 'prueba-04')->value('alias_slug'));
    }

    public function test_una_empresa_que_solo_dice_tienda_o_api_da_422_sobre_empresa_sin_dejar_nada(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);

        $respuesta = $this->postear_alta(['empresa' => 'Tienda API', 'telefono' => '3444000021']);

        $respuesta->assertStatus(422);
        $this->assertArrayHasKey('empresa', $respuesta->json('errores'));
        $this->assertNull(Lead::where('phone', '+5493444000021')->first(), 'La transacción se deshizo: no queda lead.');
        $this->assertSame(TrialInstance::ESTADO_LIBRE, TrialInstance::where('nombre', 'prueba-01')->value('estado'));
    }

    public function test_el_uuid_del_alta_es_el_de_seguimiento_de_la_prueba_y_no_el_del_lead(): void
    {
        $instancia = $this->crear_instancia_libre('prueba-01', 900100);
        $uuid      = $this->postear_alta()->json('uuid');
        $lead      = $this->lead_del_alta($uuid);
        $instancia->refresh();

        $this->assertSame($uuid, $instancia->seguimiento_uuid);
        $this->assertNotSame($uuid, (string) $lead->uuid, 'El uuid del lead viaja en los links públicos de la demo: no abre el seguimiento.');
        $this->getJson('/api/landing/prueba/' . $uuid . '/estado')->assertStatus(200);
        $this->getJson('/api/landing/prueba/' . $lead->uuid . '/estado')->assertStatus(404);
    }

    public function test_la_contrasena_acepta_hasta_120_y_el_mensaje_del_minimo_habla_de_la_contrasena(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);

        $corta = $this->postear_alta(['password' => 'abc1234', 'telefono' => '3444000031']);
        $corta->assertStatus(422);
        $this->assertSame(['Revisá la contraseña: es demasiado corta.'], $corta->json('errores.password'));

        $this->postear_alta(['password' => str_repeat('a', 121), 'telefono' => '3444000032'])->assertStatus(422);
        $this->postear_alta(['password' => str_repeat('a', 120), 'telefono' => '3444000033'])->assertStatus(201);
    }

    public function test_un_telefono_con_prueba_en_curso_da_409_ya_tiene_prueba(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        $this->crear_instancia_libre('prueba-02', 900200);
        $this->postear_alta()->assertStatus(201);

        $this->postear_alta(['empresa' => 'Otra empresa'])
            ->assertStatus(409)
            ->assertJson(['code' => 'ya_tiene_prueba', 'codigo' => 'ya_tiene_prueba']);

        $this->assertSame(1, Client::where('phone', self::TELEFONO_E164)->count());
        $this->assertSame(TrialInstance::ESTADO_LIBRE, TrialInstance::where('nombre', 'prueba-02')->value('estado'));
    }

    public function test_el_telefono_de_un_cliente_activo_da_409_ya_es_cliente(): void
    {
        $this->crear_instancia_libre();
        Client::create(['name' => 'Ferre Total', 'is_active' => true, 'phone' => '+549 344 462-2139', 'api_url' => '', 'api_key' => Str::random(40), 'inbound_api_key' => Str::random(40), 'slug' => 'ferretotal-' . uniqid()]);

        $this->postear_alta()->assertStatus(409)->assertJson(['code' => 'ya_es_cliente']);

        $this->assertSame(0, Lead::where('phone', self::TELEFONO_E164)->count());
    }

    public function test_un_fijo_o_un_numero_extranjero_da_409_telefono_invalido(): void
    {
        $this->crear_instancia_libre();

        $this->postear_alta(['telefono' => '+1 212 555 0100'])->assertStatus(409)->assertJson(['code' => 'telefono_invalido']);
        $this->postear_alta(['telefono' => '12345'])->assertStatus(409)->assertJson(['code' => 'telefono_invalido']);

        $this->assertSame(0, Lead::where('phone', 'like', '%2125550100')->count());
        $this->assertSame(TrialInstance::ESTADO_LIBRE, TrialInstance::where('nombre', 'prueba-01')->value('estado'));
    }

    public function test_sin_instancia_libre_da_409_sin_capacidad_y_no_deja_nada_escrito(): void
    {
        $respuesta = $this->postear_alta();

        $respuesta->assertStatus(409)->assertJson(['code' => 'sin_capacidad']);
        $this->assertSame(0, Lead::where('phone', self::TELEFONO_E164)->count());
        $this->assertSame(0, Client::where('phone', self::TELEFONO_E164)->count());
    }

    public function test_el_tope_de_activas_tambien_da_409_sin_capacidad(): void
    {
        \App\Models\AdminSetting::set(\App\Services\Prueba\PruebaSettings::KEY_MAX_ACTIVAS, '1');
        $ocupada = $this->crear_instancia_libre('prueba-01', 900100);
        $ocupada->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'client_id' => 12345, 'lead_id' => 12345, 'alias_slug' => 'otro']);
        $this->crear_instancia_libre('prueba-02', 900200);

        $this->postear_alta()->assertStatus(409)->assertJson(['code' => 'sin_capacidad']);
        $this->assertSame(TrialInstance::ESTADO_LIBRE, TrialInstance::where('nombre', 'prueba-02')->value('estado'));
    }

    public function test_la_validacion_devuelve_422_con_errores_por_campo_en_castellano(): void
    {
        $this->crear_instancia_libre();

        $respuesta = $this->postear_alta(['password' => 'corta', 'email' => 'no-es-un-mail', 'empresa' => '']);

        $respuesta->assertStatus(422);
        $errores = $respuesta->json('errores');
        $this->assertArrayHasKey('password', $errores);
        $this->assertArrayHasKey('email', $errores);
        $this->assertArrayHasKey('empresa', $errores);
        $this->assertStringContainsString('contraseña', $errores['password'][0]);
    }

    public function test_el_honeypot_y_el_formulario_demasiado_rapido_dan_422_generico(): void
    {
        $this->crear_instancia_libre();

        $this->postear_alta(['sitio_web' => 'http://spam.test'])->assertStatus(422)->assertJson(['mensaje' => 'No pudimos tomar el pedido. Probá de nuevo en unos segundos.']);
        $this->postear_alta(['abierto_hace_ms' => 300])->assertStatus(422);

        $this->assertSame(0, Lead::where('phone', self::TELEFONO_E164)->count());
    }

    public function test_las_claves_desconocidas_se_ignoran_en_vez_de_dar_422(): void
    {
        $this->crear_instancia_libre();

        $this->postear_alta(['gclid' => 'abc', 'una_clave_rara' => 'x', 'utm_term' => 'ferreteria'])->assertStatus(201);
    }

    public function test_un_lead_existente_sin_prueba_se_reusa_y_conserva_sus_datos(): void
    {
        $this->crear_instancia_libre();
        $lead = Lead::create(['phone' => self::TELEFONO_E164, 'contact_name' => 'Lucas Viejo', 'email' => 'viejo@example.com', 'status' => 'contactado']);

        $respuesta = $this->postear_alta();

        $respuesta->assertStatus(201);
        $this->assertSame((int) $lead->id, (int) $this->lead_del_alta($respuesta->json('uuid'))->id, 'Se reusó el lead existente (el uuid devuelto es el de seguimiento de la prueba).');
        $lead->refresh();
        $this->assertSame('Lucas Viejo', $lead->contact_name);
        $this->assertSame('viejo@example.com', $lead->email);
        $this->assertSame('Prueba Test', $lead->company_name);
        $this->assertSame(LeadPipelineStatus::PRUEBA_ACTIVA, $lead->status);
        $this->assertSame(1, Lead::where('phone', self::TELEFONO_E164)->count());
    }

    public function test_el_estado_de_un_uuid_que_no_es_una_prueba_da_404(): void
    {
        $lead = Lead::create(['phone' => '+5493410000099', 'contact_name' => 'Sin prueba', 'status' => 'nuevo']);

        $this->getJson('/api/landing/prueba/' . $lead->uuid . '/estado')->assertStatus(404);
        $this->getJson('/api/landing/prueba/no-es-un-uuid/estado')->assertStatus(404);
    }

    public function test_el_estado_lista_trae_la_url_de_ingreso_y_fallida_trae_el_mensaje(): void
    {
        $instancia = $this->crear_instancia_libre();
        $uuid      = $this->postear_alta()->json('uuid');
        $lead      = $this->lead_del_alta($uuid);

        $instancia->refresh();
        $instancia->update(['estado' => TrialInstance::ESTADO_ASIGNADA, 'etapa' => 'lista', 'progreso' => 100]);

        $this->getJson('/api/landing/prueba/' . $uuid . '/estado')
            ->assertStatus(200)
            ->assertJson(['estado' => 'lista', 'progreso' => 100, 'url_ingreso' => 'https://prueba-test.comerciocity.com/demo/ingreso?t=' . $lead->demo_ingreso_token]);

        $instancia->update(['estado' => TrialInstance::ESTADO_FALLIDA, 'etapa' => 'demo_setup', 'error' => 'HTTP 500: algo']);

        $this->getJson('/api/landing/prueba/' . $uuid . '/estado')
            ->assertStatus(200)
            ->assertJson(['estado' => 'fallida', 'etapa' => 'Cargando datos de ejemplo de tu rubro'])
            ->assertJsonPath('url_ingreso', null);
        $this->assertNotEmpty($this->getJson('/api/landing/prueba/' . $uuid . '/estado')->json('mensaje'));
        /* El error técnico NO viaja a la landing. */
        $this->assertStringNotContainsString('HTTP 500', (string) $this->getJson('/api/landing/prueba/' . $uuid . '/estado')->json('mensaje'));
    }
}
