<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Demo;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El link de ingreso que devuelven los endpoints que CAMBIAN el token tiene que ser el link del
 * token vigente después de la acción, no el de antes.
 *
 * Bug reportado por Lucas el 26/8/2026, con la demo lista para grabar los videotutoriales: corrió
 * el demo setup a mano, el setup dio `exitoso`, abrió el link del bloque "Link de ingreso a la
 * demo" del modal y la instancia le contestó "Este acceso a la demo ya no está disponible.". En
 * las capturas se ve la falla directa: el campo "Token de ingreso" mostraba un token y el campo
 * "Link de ingreso" llevaba OTRO.
 *
 * La cadena: cada corrida del setup emite un token nuevo y la instancia borra el anterior; el link
 * no es una columna sino el accesor `Lead::getDemoIngresoUrlAttribute()`, que sólo viajaba cuando
 * alguien lo appendeaba —y eso pasaba únicamente en `show_json()`—; y el SPA fusiona la respuesta
 * con `Object.assign()`, que deja intactas las claves que NO vienen. Resultado: el token se
 * actualizaba en pantalla y el link se quedaba con el valor muerto.
 *
 * 🔴 Todos los casos entran por el REQUEST HTTP, no por el accesor. El accesor nunca estuvo roto:
 * un test que lo llame directo pasa igual con el bug puesto y no prueba nada.
 */
class LinkDeIngresoFrescoTrasAccionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Sustituye entera a la instancia: ningún test de este archivo sale a la red.
     *
     * 🔴 Va acá y NO en setUp(), a propósito. `Http::fake()` ACUMULA stubs y gana el primero que
     * matchea: un `'*'` registrado en setUp se comería cualquier stub más específico que quiera
     * poner un test, y el caso del 422 —que necesita que la instancia conteste 500— pasaría en
     * verde por 200 sin haber ejercido nunca la rama que dice probar.
     *
     * @param array<string, mixed> $stubs Stubs específicos, antes del catch-all.
     *
     * @return void
     */
    private function fakear_instancia(array $stubs = []): void
    {
        Http::fake($stubs + ['*' => Http::response(['ok' => true], 200)]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Admin autenticado por Sanctum, como exige el grupo de rutas del panel.
     *
     * @return Admin
     */
    private function autenticar_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'admin+' . Str::random(6) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Demo con las cuatro URLs que el modelo necesita.
     *
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-erp.test';
        $demo->erp_api_url       = 'https://demo-erp-api.test';
        $demo->ecommerce_spa_url = 'https://demo-tienda.test';
        $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * Lead con demo asignada, turno agendado y un token de ingreso YA emitido: el estado en el que
     * Lucas aprieta los botones del modal. El token previo es lo que el link tiene que dejar de
     * mostrar después de cada acción.
     *
     * 🔴 `$token_revocado` existe desde el 27/8/2026, y es una precondición, no una variante.
     *
     * Desde esa fecha el demo setup **reutiliza** el token vigente en vez de emitir uno nuevo en
     * cada corrida (`RunDemoSetupService::asegurar_token_de_ingreso()`): rotarlo antes del POST era
     * lo que dejaba al panel mostrando un link que la instancia no conocía cuando el POST rebotaba
     * con 409. Los dos tests que ejercen `run-demo-setup` necesitan que el setup SI emita un token
     * nuevo para que haya un "link viejo" del que despegarse — y el único caso que lo hace es el
     * token revocado.
     *
     * O sea: lo que cambió es el escenario que hay que armar para reproducir el bug de ayer, no lo
     * que ese bug era. Las aserciones de esos tests quedaron intactas a propósito.
     *
     * @param bool $token_revocado true = el token arranca revocado, así el setup emite uno nuevo.
     *
     * @return Lead
     */
    private function crear_lead_con_token(bool $token_revocado = false): Lead
    {
        $demo = $this->crear_demo();

        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = 'demo_agendada';
        $lead->save();

        // Después del save: el hook `creating` del modelo estampa la dinámica por defecto.
        $lead->demo_id                      = $demo->id;
        $lead->demo_date                    = '2026-08-26';
        $lead->demo_start_time              = '09:00';
        $lead->demo_end_time                = '23:00';
        $lead->demo_setup_status            = 'pendiente';
        $lead->demo_ingreso_token           = 'token-viejo-' . Str::random(40);
        $lead->demo_ingreso_token_expira_at = Carbon::parse('2026-08-26 23:10:00', 'America/Argentina/Buenos_Aires');

        if ($token_revocado) {
            $lead->demo_ingreso_token_revocado_at = Carbon::parse('2026-08-26 12:00:00', 'America/Argentina/Buenos_Aires');
        }

        $lead->save();

        return $lead->refresh();
    }

    /**
     * Aserción compartida: la respuesta trae el link y ese link lleva el token que quedó vigente
     * en la base DESPUÉS de la acción. Es la aserción que reproduce el bug — con el append sacado
     * de los endpoints, `model.demo_ingreso_url` no viene y esto da rojo.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta
     * @param Lead                             $lead
     * @param string                           $token_anterior
     *
     * @return void
     */
    private function assert_link_fresco($respuesta, Lead $lead, string $token_anterior): void
    {
        $url = $respuesta->json('model.demo_ingreso_url');

        $this->assertNotNull(
            $url,
            'La respuesta no trae `model.demo_ingreso_url`: el SPA fusiona con Object.assign y se queda con el link viejo.'
        );

        $token_vigente = $lead->fresh()->demo_ingreso_token;

        $this->assertSame(
            'https://demo-erp.test/demo/ingreso?t=' . $token_vigente,
            $url,
            'El link devuelto no lleva el token vigente después de la acción.'
        );

        $this->assertStringNotContainsString(
            $token_anterior,
            (string) $url,
            'El link devuelto todavía lleva el token anterior, que la instancia ya borró.'
        );
    }

    /**
     * 1. El caso de Lucas: correr el demo setup desde el modal. El setup re-emite el token, así que
     *    el link de la respuesta tiene que ser el nuevo.
     *
     * @return void
     */
    public function test_run_demo_setup_devuelve_el_link_con_el_token_recien_emitido(): void
    {
        $this->fakear_instancia();
        $this->autenticar_admin();

        // Revocado: es el caso en el que el setup emite un token nuevo (ver crear_lead_con_token()).
        $lead           = $this->crear_lead_con_token(true);
        $token_anterior = $lead->demo_ingreso_token;

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/run-demo-setup');
        $respuesta->assertStatus(200);

        // Precondición del caso: el setup efectivamente cambió el token.
        $this->assertNotSame($token_anterior, $lead->fresh()->demo_ingreso_token, 'El setup no re-emitió el token: el caso no se está reproduciendo.');

        $this->assert_link_fresco($respuesta, $lead, $token_anterior);
    }

    /**
     * 2. Reemitir el token: su único propósito es cambiarlo, así que el link viejo queda muerto en
     *    el mismo request.
     *
     * @return void
     */
    public function test_reemitir_el_token_devuelve_el_link_nuevo(): void
    {
        $this->fakear_instancia();
        $this->autenticar_admin();

        $lead           = $this->crear_lead_con_token();
        $token_anterior = $lead->demo_ingreso_token;

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/demo-token/reemitir');
        $respuesta->assertStatus(200);

        $this->assertNotSame($token_anterior, $lead->fresh()->demo_ingreso_token, 'La reemisión no cambió el token: el caso no se está reproduciendo.');

        $this->assert_link_fresco($respuesta, $lead, $token_anterior);
    }

    /**
     * 3. Revocar: no cambia el valor del token, pero sí el estado del acceso que ese link
     *    representa, y el modal se refresca con esta respuesta. El link tiene que venir igual.
     *
     * @return void
     */
    public function test_revocar_el_token_devuelve_el_link_del_token_vigente(): void
    {
        $this->fakear_instancia();
        $this->autenticar_admin();

        $lead = $this->crear_lead_con_token();

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/demo-token/revocar');
        $respuesta->assertStatus(200);

        $this->assertNotNull($lead->fresh()->demo_ingreso_token_revocado_at, 'La revocación no se registró: el caso no se está reproduciendo.');

        $this->assertSame(
            'https://demo-erp.test/demo/ingreso?t=' . $lead->fresh()->demo_ingreso_token,
            $respuesta->json('model.demo_ingreso_url'),
            'La respuesta de revocar no trae el link del token vigente.'
        );
    }

    /**
     * 4. La rama 422 también trae el link, y no es un detalle: el setup emite el token nuevo ANTES
     *    de llamar a la instancia, así que un setup fallido igual dejó muerto el link anterior. Si
     *    el modal se quedara con el viejo, Lucas se lo mandaría al lead creyendo que sirve.
     *
     * @return void
     */
    public function test_el_422_del_setup_tambien_trae_el_link_vigente(): void
    {
        $this->autenticar_admin();

        // La instancia responde 500 al armado: el endpoint termina en 422.
        $this->fakear_instancia([
            '*/api/admin-sync/demo-setup' => Http::response(['message' => 'explotó'], 500),
        ]);

        // Revocado: es el caso en el que el setup emite un token nuevo (ver crear_lead_con_token()).
        $lead           = $this->crear_lead_con_token(true);
        $token_anterior = $lead->demo_ingreso_token;

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/run-demo-setup');
        $respuesta->assertStatus(422);

        $this->assertNotSame($token_anterior, $lead->fresh()->demo_ingreso_token, 'El setup fallido igual re-emite el token: si esto no pasa, el caso cambió.');

        $this->assert_link_fresco($respuesta, $lead, $token_anterior);
    }

    /**
     * 5. Lo que ya andaba sigue andando: el detalle del lead trae el link. Es el único camino que
     *    lo tenía antes de este arreglo, y el refactor que unificó el append no puede haberlo roto.
     *
     * @return void
     */
    public function test_el_detalle_del_lead_sigue_trayendo_el_link(): void
    {
        $this->fakear_instancia();
        $this->autenticar_admin();

        $lead = $this->crear_lead_con_token();

        $respuesta = $this->getJson('/api/admin/lead/' . $lead->id);
        $respuesta->assertStatus(200);

        $this->assertSame(
            'https://demo-erp.test/demo/ingreso?t=' . $lead->demo_ingreso_token,
            $respuesta->json('model.demo_ingreso_url')
        );
    }

    /**
     * 6. El detalle también trae el link de la página de experiencia (`/experiencia/{uuid}` de
     *    admin-spa), que es el otro bloque del grupo Demo del modal. Va por el REQUEST y no por el
     *    accesor, mismo criterio que el resto del archivo: el accesor anda desde el grupo 300, lo
     *    que puede faltar es el append en el detalle, y eso solo se ve entrando por HTTP.
     *
     * @return void
     */
    public function test_el_detalle_del_lead_trae_el_link_de_la_pagina_de_experiencia(): void
    {
        $this->fakear_instancia();
        $this->autenticar_admin();

        $lead = $this->crear_lead_con_token();

        config(['services.admin_spa.url' => 'https://admin.test/']);

        $respuesta = $this->getJson('/api/admin/lead/' . $lead->id);
        $respuesta->assertStatus(200);

        $this->assertSame(
            'https://admin.test/experiencia/' . $lead->uuid,
            $respuesta->json('model.demo_experiencia_url'),
            'El detalle no trae `model.demo_experiencia_url`: el bloque del modal queda vacío.'
        );
    }
}
