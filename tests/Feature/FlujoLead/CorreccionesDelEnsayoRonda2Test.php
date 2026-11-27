<?php

namespace Tests\Feature\FlujoLead;

use App\Models\Lead;
use App\Models\TrialInstance;
use App\Services\LeadFlujoEntradaService;
use App\Services\LeadOfertaDePruebaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ronda 2 de la misión martin-correcciones-ensayo: el aviso a Juan cuando no hay lugar en el pool.
 *
 * El Revisor encontró que el punto nuevo de «cómo arranco / me interesa…» del bloque del flujo, SIN lugar en el pool, le
 * dice al agente «Juan te escribe para darte el acceso» pero no le pide devolver `requiere_intervencion_humana: true`
 * con el motivo, que es lo único que avisa a Juan (la regla vigente del punto de «probar» sí lo pide). Sin esa marca
 * el lead recibe una promesa que nadie va a cumplir. Lo mismo vale para el punto de la carga gratis cuando no hay lugar.
 *
 * Con lugar, en cambio, el lead recibe el link de la prueba y no hace falta ninguna intervención: ahí la marca no aparece
 * en esos puntos.
 */
class CorreccionesDelEnsayoRonda2Test extends TestCase
{
    use DatabaseTransactions;
    use ArmadoDelFlujoDeEntrada;

    /** Marca que avisa a Juan. */
    private const MARCA_DE_AVISO = 'requiere_intervencion_humana: true';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_flujo_de_entrada(true);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->desarmar_flujo_de_entrada();

        parent::tearDown();
    }

    /**
     * Sin lugar en el pool, el punto de «cómo arranco» pide `requiere_intervencion_humana: true` con el motivo (además de
     * no pasar ningún link), igual que la regla vigente del punto de «probar».
     *
     * @return void
     */
    public function test_sin_lugar_como_arranco_avisa_a_juan(): void
    {
        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($this->lead_conversando(false));

        $punto = $this->punto_con($bloque, 'cómo arranco');
        $this->assertNotNull($punto, 'Falta el punto de «cómo arranco».');
        $this->assertStringContainsString(self::MARCA_DE_AVISO, $punto, 'Sin la marca, a Juan no le llega ningún aviso.');
        $this->assertStringContainsString('motivo', $punto);
        $this->assertStringNotContainsString(LeadOfertaDePruebaService::link(), $punto);
    }

    /**
     * Con lugar en el pool, el punto de «cómo arranco» manda el link y NO pide la intervención humana.
     *
     * @return void
     */
    public function test_con_lugar_como_arranco_no_pide_intervencion(): void
    {
        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($this->lead_conversando(true));

        $punto = $this->punto_con($bloque, 'cómo arranco');
        $this->assertNotNull($punto, 'Falta el punto de «cómo arranco».');
        $this->assertStringContainsString(LeadOfertaDePruebaService::link(), $punto);
        $this->assertStringNotContainsString(self::MARCA_DE_AVISO, $punto, 'Con lugar el lead recibe el link: no hay nada que avisar.');
    }

    /**
     * Sin lugar en el pool, quien pide la carga gratis: la prohibición de ayudar con la carga sigue, no se le pasa ningún
     * link y, como se le promete que Juan escribe, el punto pide `requiere_intervencion_humana: true` con el motivo.
     *
     * @return void
     */
    public function test_sin_lugar_la_carga_gratis_avisa_a_juan_y_no_ayuda(): void
    {
        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($this->lead_conversando(false));

        $this->assertStringContainsString('NO le ofrezcas ayuda con la carga', $bloque);

        $punto = $this->punto_con($bloque, 'se lo carguen gratis');
        $this->assertNotNull($punto, 'Falta el punto de la carga gratis cuando no hay lugar.');
        $this->assertStringContainsString('Juan te escribe para darte el acceso', $punto);
        $this->assertStringContainsString(self::MARCA_DE_AVISO, $punto);
        $this->assertStringContainsString('implementación', $punto, 'Aclara que la carga hecha por nosotros es la implementación paga.');
        $this->assertStringNotContainsString(LeadOfertaDePruebaService::link(), $punto);
    }

    /**
     * Con lugar, el punto de la carga gratis manda al video de la prueba y NO pide la intervención humana.
     *
     * @return void
     */
    public function test_con_lugar_la_carga_gratis_no_pide_intervencion(): void
    {
        $bloque = LeadFlujoEntradaService::bloque_para_el_agente($this->lead_conversando(true));

        $punto = $this->punto_con($bloque, 'se lo carguen gratis');
        $this->assertNotNull($punto, 'Falta el punto de la carga gratis.');
        $this->assertStringContainsString('video corto', $punto);
        $this->assertStringNotContainsString(self::MARCA_DE_AVISO, $punto);
    }

    /**
     * Lead del flujo en `contactado` que ya recibió la llegada y dijo su rubro, con o sin lugar en el pool.
     *
     * @param bool $con_lugar false = deja el pool sin instancias libres ni armándose.
     *
     * @return Lead
     */
    private function lead_conversando(bool $con_lugar): Lead
    {
        if (! $con_lugar) {
            TrialInstance::query()->whereIn('estado', [TrialInstance::ESTADO_LIBRE, TrialInstance::ESTADO_CREANDO])
                ->update(['estado' => TrialInstance::ESTADO_FALLIDA]);
        }

        $lead = $this->crear_lead_de_flujo('contactado');
        $this->dejar_entregado_el_paso_1($lead);
        $this->avanzar(60);
        $this->mensaje_del_lead($lead, 'Ferretería, unos 4000');

        $this->assertSame($con_lugar, LeadFlujoEntradaService::hay_lugar_en_el_pool(), 'Precondición: el pool está como pide el test.');

        return $lead->fresh();
    }

    /**
     * El punto del bloque (un renglón «  - » con sus líneas de continuación) que contiene el texto dado, sin distinguir
     * mayúsculas; null si ninguno lo trae.
     *
     * @param string $bloque Bloque FLUJO DE ENTRADA.
     * @param string $aguja  Texto a buscar.
     *
     * @return string|null
     */
    private function punto_con(string $bloque, string $aguja): ?string
    {
        foreach (explode("\n  - ", $bloque) as $punto) {
            if (mb_stripos($punto, $aguja) !== false) {
                return $punto;
            }
        }

        return null;
    }
}
