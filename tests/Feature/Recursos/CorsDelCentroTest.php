<?php

namespace Tests\Feature\Recursos;

/**
 * El CORS del admin acepta la página del centro de recursos (misión centro-de-recursos-clientes,
 * 13/10/2026): `https://recursos.comerciocity.com` va escrito en `config/recursos.php` y se suma a los
 * orígenes de `ADMIN_SPA_URL`, porque el deploy del admin no toca el `.env` de producción.
 */
class CorsDelCentroTest extends BaseDelCentroDeRecursos
{
    const PAGINA = 'https://recursos.comerciocity.com';

    public function test_el_origen_de_la_pagina_se_suma_a_los_del_env(): void
    {
        $origenes = (array) config('cors.allowed_origins');

        $this->assertContains(self::PAGINA, $origenes);
        // Los del `.env` siguen estando (en el de testing, el SPA local).
        $this->assertContains('http://localhost:8080', $origenes);
        $this->assertNotContains('*', $origenes);
    }

    public function test_el_preflight_de_la_pagina_pasa_y_el_de_otro_origen_no(): void
    {
        $preflight = $this->call('OPTIONS', '/api/recursos/progreso', [], [], [], [
            'HTTP_ORIGIN'                         => self::PAGINA,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD'  => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $this->assertSame(self::PAGINA, $preflight->headers->get('Access-Control-Allow-Origin'));

        $ajeno = $this->call('OPTIONS', '/api/recursos/progreso', [], [], [], [
            'HTTP_ORIGIN'                        => 'https://otro-sitio.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNull($ajeno->headers->get('Access-Control-Allow-Origin'));

        $this->getJson('/api/recursos/catalogo', ['Origin' => self::PAGINA])
            ->assertStatus(200)
            ->assertHeader('Access-Control-Allow-Origin', self::PAGINA);
    }

    /**
     * Sin `ADMIN_SPA_URL` el CORS sigue abierto como antes de la misión (`['*']`): sumar el origen del
     * centro no puede cerrar un admin que hoy funciona así.
     */
    public function test_sin_admin_spa_url_sigue_abierto(): void
    {
        $antes = [getenv('ADMIN_SPA_URL'), $_ENV['ADMIN_SPA_URL'] ?? null, $_SERVER['ADMIN_SPA_URL'] ?? null];

        try {
            putenv('ADMIN_SPA_URL=');
            $_ENV['ADMIN_SPA_URL']    = '';
            $_SERVER['ADMIN_SPA_URL'] = '';

            $cors = require config_path('cors.php');

            $this->assertSame(['*'], $cors['allowed_origins']);
        } finally {
            $antes[0] === false ? putenv('ADMIN_SPA_URL') : putenv('ADMIN_SPA_URL=' . $antes[0]);

            if ($antes[1] === null) {
                unset($_ENV['ADMIN_SPA_URL']);
            } else {
                $_ENV['ADMIN_SPA_URL'] = $antes[1];
            }

            if ($antes[2] === null) {
                unset($_SERVER['ADMIN_SPA_URL']);
            } else {
                $_SERVER['ADMIN_SPA_URL'] = $antes[2];
            }
        }
    }
}
