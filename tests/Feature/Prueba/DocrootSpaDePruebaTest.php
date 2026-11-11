<?php

namespace Tests\Feature\Prueba;

use App\Models\ClientApi;
use App\Models\TrialInstance;
use App\Services\ClientApiPathResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Contrato real del pool: alias público distinto del docroot físico, sin conexiones al VPS. */
class DocrootSpaDePruebaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.hostinger.domain' => 'comerciocity.com']);
    }

    private function instancia(array $campos = []): TrialInstance
    {
        return TrialInstance::create(array_merge([
            'nombre' => 'prueba-97', 'estado' => TrialInstance::ESTADO_ASIGNADA,
            'client_id' => 970001, 'erp_vps_path' => 'prueba-97',
            'ecommerce_vps_path' => 'tienda-prueba-97', 'alias_slug' => 'negocio-local',
        ], $campos));
    }

    private function api(array $campos = []): ClientApi
    {
        return new ClientApi(array_merge([
            'client_id' => 970001, 'hosting_type' => 'vps', 'vps_path' => 'prueba-97',
            'spa_url' => 'https://negocio-local.comerciocity.com',
            'url' => 'https://api-negocio-local.comerciocity.com', 'path' => 'negocio-local/api',
        ], $campos));
    }

    public function test_alias_y_host_pool_conservan_docroot_y_no_mutan_la_api()
    {
        $this->instancia();
        $resolver = new ClientApiPathResolver();
        foreach (['https://negocio-local.comerciocity.com', 'http://prueba-97.comerciocity.com/'] as $url) {
            $api = $this->api(['spa_url' => $url]);
            $antes = $api->getAttributes();
            $this->assertSame('/home/prueba-97/htdocs/prueba-97.comerciocity.com', $resolver->resolve_spa($api));
            $this->assertSame($resolver->resolve_spa($api), $resolver->spa_hosting_dir($api));
            $this->assertSame('/home/api-prueba-97/empresa-api', $resolver->resolve($api));
            $this->assertSame('api-prueba-97', $resolver->vps_site_user($api));
            $this->assertSame($antes, $api->getAttributes());
        }
    }

    public function test_promovida_a_cliente_sigue_usando_el_pool()
    {
        $this->instancia(['estado' => TrialInstance::ESTADO_CLIENTE]);
        $this->assertSame('/home/prueba-97/htdocs/prueba-97.comerciocity.com', (new ClientApiPathResolver())->resolve_spa($this->api()));
    }

    public function test_dominio_configurado_se_respeta_sin_hardcode()
    {
        config(['services.hostinger.domain' => 'pruebas.ejemplo.test']);
        $this->instancia();
        $this->assertSame('/home/prueba-97/htdocs/prueba-97.pruebas.ejemplo.test', (new ClientApiPathResolver())->resolve_spa(
            $this->api(['spa_url' => 'https://negocio-local.pruebas.ejemplo.test'])
        ));
    }

    /** @dataProvider dominios_invalidos */
    public function test_dominio_configurado_no_puede_ser_una_ruta(string $dominio)
    {
        $this->instancia();
        config(['services.hostinger.domain' => $dominio]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dominio configurado');
        (new ClientApiPathResolver())->resolve_spa($this->api());
    }

    public function dominios_invalidos(): array
    {
        return [['../otro'], [''], ['https://comerciocity.com'], ['comerciocity.com/otro']];
    }

    public function test_alias_corrupto_no_llega_a_una_ruta()
    {
        $this->instancia(['alias_slug' => '../otro']);
        $this->expectException(\InvalidArgumentException::class);
        (new ClientApiPathResolver())->resolve_spa($this->api());
    }

    public function test_dominio_cambiado_no_inventa_otra_ruta()
    {
        $this->instancia();
        config(['services.hostinger.domain' => 'otro.ejemplo.test']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('spa_url');
        (new ClientApiPathResolver())->resolve_spa($this->api());
    }

    /** @dataProvider urls_invalidas */
    public function test_url_no_perteneciente_o_ambigua_falla(string $url)
    {
        $this->instancia();
        $this->expectException(\RuntimeException::class);
        (new ClientApiPathResolver())->resolve_spa($this->api(['spa_url' => $url]));
    }

    public function urls_invalidas(): array
    {
        return array_map(function ($url) { return [$url]; }, [
            'https://ajeno.comerciocity.com', 'https://negocio-local.comerciocity.com/ruta',
            'https://negocio-local.comerciocity.com:443', 'https://u:p@negocio-local.comerciocity.com',
            'https://negocio-local.comerciocity.com?', 'https://negocio-local.comerciocity.com#',
            'ftp://negocio-local.comerciocity.com', '', 'https://..',
        ]);
    }

    public function test_instancia_ajena_no_cae_al_fallback_legacy()
    {
        $this->instancia(['client_id' => 970002]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no pertenece');
        (new ClientApiPathResolver())->resolve_spa($this->api(['spa_url' => 'https://prueba-97.comerciocity.com']));
    }

    public function test_sin_cliente_no_se_atribuye_instancia()
    {
        $this->instancia(['client_id' => null]);
        $this->expectException(\RuntimeException::class);
        (new ClientApiPathResolver())->resolve_spa($this->api(['client_id' => null]));
    }

    public function test_duplicadas_no_eligen_una_arbitrariamente()
    {
        $this->instancia();
        $this->instancia(['nombre' => 'prueba-98']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('más de una');
        (new ClientApiPathResolver())->resolve_spa($this->api());
    }

    public function test_nombre_discordante_no_arma_docroot()
    {
        $this->instancia(['nombre' => 'prueba-98']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no coinciden');
        (new ClientApiPathResolver())->resolve_spa($this->api());
    }

    public function test_historico_borrado_no_se_convierte_en_vps_legacy()
    {
        $this->instancia(['nombre' => 'prueba-97~vieja', 'estado' => TrialInstance::ESTADO_BORRADA]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('borrada');
        (new ClientApiPathResolver())->resolve_spa($this->api());
    }

    public function test_reutilizada_con_dueno_nuevo_ignora_solo_el_historico_borrado()
    {
        $this->instancia(['nombre' => 'prueba-97~vieja', 'estado' => TrialInstance::ESTADO_BORRADA, 'client_id' => 970002]);
        $this->instancia();
        $this->assertSame('/home/prueba-97/htdocs/prueba-97.comerciocity.com', (new ClientApiPathResolver())->resolve_spa($this->api()));
    }

    public function test_nombre_de_pool_sin_registro_preserva_vps_legacy()
    {
        $this->assertSame('/home/prueba-97/htdocs/negocio-local.comerciocity.com', (new ClientApiPathResolver())->resolve_spa($this->api()));
    }

    public function test_secundaria_vps_normal_y_shared_no_heredan_el_pool()
    {
        $this->instancia();
        $resolver = new ClientApiPathResolver();
        $this->assertSame('/home/prueba-972/htdocs/secundaria.ejemplo.test', $resolver->resolve_spa($this->api([
            'vps_path' => 'prueba-972', 'spa_url' => 'https://secundaria.ejemplo.test',
        ])));
        $this->assertSame('/home/normal/htdocs/negocio-local.comerciocity.com', $resolver->resolve_spa($this->api(['vps_path' => 'normal'])));
        $this->assertSame('domains/comerciocity.com/public_html/negocio-local/spa', $resolver->spa_hosting_dir($this->api(['hosting_type' => 'shared_hosting'])));
    }

    /** @dataProvider directorios_ajenos */
    public function test_guarda_exige_docroot_exacto_y_no_solo_usuario(string $dir)
    {
        $this->instancia();
        $this->expectException(\RuntimeException::class);
        (new ClientApiPathResolver())->assert_directorio_de_spa_borrable($this->api(), $dir);
    }

    public function directorios_ajenos(): array
    {
        return array_map(function ($dir) { return [$dir]; }, [
            '/home/prueba-97/htdocs/negocio-local.comerciocity.com', '/home/prueba-97/htdocs/otro.test',
            '/home/prueba-97/htdocs', '/home', '/home/prueba-97/htdocs/../otra',
            '/home/prueba-98/htdocs/prueba-98.comerciocity.com',
        ]);
    }

    public function test_guarda_acepta_la_ruta_canonica()
    {
        $this->instancia();
        $resolver = new ClientApiPathResolver();
        $dir = $resolver->resolve_spa($this->api());
        $resolver->assert_directorio_de_spa_borrable($this->api(), $dir);
        $this->assertSame('/home/prueba-97/htdocs/prueba-97.comerciocity.com', $dir);
    }
}
