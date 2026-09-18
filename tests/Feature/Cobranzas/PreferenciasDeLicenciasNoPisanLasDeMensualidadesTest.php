<?php

namespace Tests\Feature\Cobranzas;

/**
 * El merge de preferencias de Cobranzas (pedido 4, misión cobranzas-mejoras, 18/9/2026):
 * Mensualidades.vue manda `{meses, orden}` y Licencias.vue manda `{licencias_meses}`, cada una sin
 * la clave de la otra. Si el PUT reemplazara el objeto entero en vez de mergearlo, guardar una
 * selección borraría la de la otra vista cada vez que alguien toca un mes. Este test prueba que
 * eso NO pasa, en los dos sentidos.
 */
class PreferenciasDeLicenciasNoPisanLasDeMensualidadesTest extends BaseDeCobranzas
{
    /**
     * 1. Mensualidades primero, Licencias después: las dos quedan guardadas.
     *
     * @return void
     */
    public function test_guardar_licencias_no_pisa_lo_de_mensualidades(): void
    {
        $admin = $this->admin_logueado();

        $this->putJson('/api/admin/cobranzas/preferencias', [
            'meses' => ['2026-08', '2026-09'],
            'orden' => 'sin_pago',
        ])->assertStatus(200);

        $response = $this->putJson('/api/admin/cobranzas/preferencias', [
            'licencias_meses' => ['2026-07', '2026-10'],
        ]);
        $response->assertStatus(200);

        $this->assertSame(['2026-08', '2026-09'], $response->json('meses'), 'Lo de Mensualidades sigue como estaba.');
        $this->assertSame('sin_pago', $response->json('orden'));
        $this->assertSame(['2026-07', '2026-10'], $response->json('licencias_meses'));

        $admin->refresh();
        $this->assertSame(['2026-08', '2026-09'], $admin->cobranzas_preferencias['meses']);
        $this->assertSame('sin_pago', $admin->cobranzas_preferencias['orden']);
        $this->assertSame(['2026-07', '2026-10'], $admin->cobranzas_preferencias['licencias_meses']);
    }

    /**
     * 2. Al revés: Licencias primero, Mensualidades después.
     *
     * @return void
     */
    public function test_guardar_mensualidades_no_pisa_lo_de_licencias(): void
    {
        $admin = $this->admin_logueado();

        $this->putJson('/api/admin/cobranzas/preferencias', [
            'licencias_meses' => ['2026-07', '2026-10'],
        ])->assertStatus(200);

        $response = $this->putJson('/api/admin/cobranzas/preferencias', [
            'meses' => ['2026-08', '2026-09'],
            'orden' => 'sin_factura',
        ]);
        $response->assertStatus(200);

        $this->assertSame(['2026-07', '2026-10'], $response->json('licencias_meses'), 'Lo de Licencias sigue como estaba.');
        $this->assertSame(['2026-08', '2026-09'], $response->json('meses'));
        $this->assertSame('sin_factura', $response->json('orden'));

        $admin->refresh();
        $this->assertSame(['2026-07', '2026-10'], $admin->cobranzas_preferencias['licencias_meses']);
    }

    /**
     * 3. Defaults: sin nada guardado, `licencias_meses` cae al mes corriente igual que `meses`.
     *
     * @return void
     */
    public function test_licencias_meses_tiene_default_igual_que_meses(): void
    {
        $this->admin_logueado();

        $response = $this->getJson('/api/admin/cobranzas/preferencias');
        $response->assertStatus(200);
        $this->assertSame([self::MES_CORRIENTE], $response->json('meses'));
        $this->assertSame([self::MES_CORRIENTE], $response->json('licencias_meses'));
    }

    /**
     * 4. Validación: `licencias_meses` también respeta el formato YYYY-MM y el mínimo de 1.
     *
     * @return void
     */
    public function test_licencias_meses_valida_formato(): void
    {
        $this->admin_logueado();

        $this->putJson('/api/admin/cobranzas/preferencias', ['licencias_meses' => ['2026-13']])
            ->assertStatus(422);
        $this->putJson('/api/admin/cobranzas/preferencias', ['licencias_meses' => []])
            ->assertStatus(422);
    }

    /**
     * 5. Sin duplicados y ordenados, igual que `meses`.
     *
     * @return void
     */
    public function test_licencias_meses_sin_duplicados_y_ordenados(): void
    {
        $this->admin_logueado();

        $response = $this->putJson('/api/admin/cobranzas/preferencias', [
            'licencias_meses' => ['2026-09', '2026-07', '2026-09'],
        ]);

        $response->assertStatus(200);
        $this->assertSame(['2026-07', '2026-09'], $response->json('licencias_meses'));
    }
}
