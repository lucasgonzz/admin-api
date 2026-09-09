<?php

namespace Tests\Feature;

use App\Helpers\AdminApiPublicUrl;
use App\Models\Lead;
use App\Services\RunDemoSetupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * La URL del canal de eventos que viaja en el payload del demo setup tiene que ser la URL PÚBLICA
 * de la API del admin, no `APP_URL` a secas (misión demo-seguimiento-y-setup-rapido, 9/9/2026).
 *
 * El caso que originó esto, medido en producción: `APP_URL=https://api-admin.comerciocity.com`,
 * la API responde bajo `/public/api/...`, y la instancia de demo recibía 404 en cada push. Cero
 * filas en `demo_eventos_recibidos` desde que existe el canal.
 */
class UrlPublicaDelCanalDeEventosDeDemoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Lead de la dinámica nueva con canal emitido, como los que arma RunDemoSetupService.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead                     = new Lead();
        $lead->uuid               = (string) Str::uuid();
        $lead->contact_name       = 'Juana Pérez';
        $lead->demo_experiencia   = Lead::EXPERIENCIA_NUEVA;
        $lead->demo_eventos_token = Str::random(64);
        $lead->demo_plan          = ['version_catalogo' => 2, 'secciones' => []];
        $lead->save();

        return $lead;
    }

    /**
     * Invoca el bloque de claves de la dinámica nueva (protegido: no es API del servicio).
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    private function claves(Lead $lead): array
    {
        $metodo = new ReflectionMethod(RunDemoSetupService::class, 'claves_de_la_dinamica_nueva');
        $metodo->setAccessible(true);

        return $metodo->invoke(new RunDemoSetupService(), $lead);
    }

    /**
     * Con la variable explícita cargada, la URL del canal sale de ella tal cual (sin barra final
     * duplicada) — es la configuración que corresponde en producción.
     */
    public function test_con_la_variable_explicita_la_url_del_canal_lleva_el_public(): void
    {
        config(['services.admin_api.public_url' => 'https://api-admin.comerciocity.com/public/']);
        config(['app.url' => 'https://api-admin.comerciocity.com']);

        $claves = $this->claves($this->crear_lead());

        $this->assertSame(
            'https://api-admin.comerciocity.com/public/api/demo-eventos',
            $claves['demo_eventos_url']
        );
    }

    /**
     * Sin la variable, fuera del shared hosting, la URL sigue saliendo de APP_URL: es el caso de
     * local y del VPS, y es exactamente lo que hacía el código antes de esta misión.
     */
    public function test_sin_la_variable_y_fuera_del_shared_hosting_queda_app_url(): void
    {
        config(['services.admin_api.public_url' => null]);
        config(['app.url' => 'http://localhost:8004/']);

        $claves = $this->claves($this->crear_lead());

        $this->assertSame('http://localhost:8004/api/demo-eventos', $claves['demo_eventos_url']);
    }

    /**
     * El repliegue deduce el `/public` SOLO cuando el proyecto corre bajo `public_html/`, que es
     * la convención del shared hosting de Hostinger. Se prueba con los dos insumos explícitos
     * porque `base_path()` no se puede fingir desde un test.
     */
    public function test_el_repliegue_agrega_public_solo_bajo_public_html(): void
    {
        $app_url = 'https://api-admin.comerciocity.com';

        $this->assertSame(
            'https://api-admin.comerciocity.com/public',
            AdminApiPublicUrl::base_deducida($app_url, '/home/u767360347/domains/comerciocity.com/public_html/admin/api')
        );

        // Ya con /public en APP_URL no se duplica.
        $this->assertSame(
            'https://api-admin.comerciocity.com/public',
            AdminApiPublicUrl::base_deducida($app_url . '/public', '/home/u767360347/domains/comerciocity.com/public_html/admin/api')
        );

        // VPS (docroot ya es public/) y local: APP_URL tal cual.
        $this->assertSame($app_url, AdminApiPublicUrl::base_deducida($app_url, '/home/api-admin/admin-api'));
        $this->assertSame('http://localhost', AdminApiPublicUrl::base_deducida('http://localhost/', 'C:\\wamp64\\www\\admin-api'));

        // Sin APP_URL no hay de dónde deducir nada: vacío, no "/public" suelto.
        $this->assertSame('', AdminApiPublicUrl::base_deducida('', '/home/x/public_html/admin/api'));
    }

    /**
     * `api()` pone el prefijo `/api` una sola vez, venga la ruta con o sin barra inicial.
     */
    public function test_api_arma_la_ruta_con_el_prefijo_una_sola_vez(): void
    {
        config(['services.admin_api.public_url' => 'https://api-admin.comerciocity.com/public']);

        $this->assertSame('https://api-admin.comerciocity.com/public/api/demo-eventos', AdminApiPublicUrl::api('demo-eventos'));
        $this->assertSame('https://api-admin.comerciocity.com/public/api/demo-eventos', AdminApiPublicUrl::api('/demo-eventos'));
    }
}
