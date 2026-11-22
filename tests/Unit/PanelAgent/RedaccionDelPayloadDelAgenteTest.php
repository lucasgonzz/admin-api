<?php

namespace Tests\Unit\PanelAgent;

use App\Services\PanelAgent\PanelAgentPayloadRedactor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Misión admin-control-total — el redactor de la auditoría del agente: ningún secreto llega a la base.
 *
 * Usa `Tests\TestCase` (sin base de datos) porque el patrón de claves sensibles sale de `config/panel_agent.php`.
 *
 * Cubre la parte unitaria de CA9 del PLAN.
 */
class RedaccionDelPayloadDelAgenteTest extends TestCase
{
    /**
     * Resuelve el redactor desde el contenedor (como lo hace el middleware).
     *
     * @return PanelAgentPayloadRedactor
     */
    private function redactor(): PanelAgentPayloadRedactor
    {
        $this->assertTrue(class_exists(PanelAgentPayloadRedactor::class), 'Falta el servicio PanelAgentPayloadRedactor.');

        return $this->app->make(PanelAgentPayloadRedactor::class);
    }

    /**
     * Redacta claves sensibles anidadas, también dentro de listas, y deja intactas las inocentes.
     *
     * @return void
     */
    public function test_redacta_claves_sensibles_anidadas_en_listas(): void
    {
        $salida = $this->redactor()->redact([
            'name'      => 'Comercio Uno',
            'is_active' => false,
            'api_key'   => 'SECRETO-U-1',
            'extra'     => [
                'password' => 'SECRETO-U-2',
                'lista'    => [
                    ['token' => 'SECRETO-U-3', 'nombre' => 'inocente'],
                    ['nombre' => 'otro inocente'],
                ],
            ],
        ]);

        $this->assertSame('Comercio Uno', $salida['name']);
        $this->assertFalse($salida['is_active']);
        $this->assertSame('[REDACTADO]', $salida['api_key']);
        $this->assertSame('[REDACTADO]', $salida['extra']['password']);
        $this->assertSame('[REDACTADO]', $salida['extra']['lista'][0]['token']);
        $this->assertSame('inocente', $salida['extra']['lista'][0]['nombre']);
        $this->assertSame('otro inocente', $salida['extra']['lista'][1]['nombre']);
        $this->assertFalse(strpos(json_encode($salida), 'SECRETO-U'), 'Ningún secreto sobrevive en el resultado.');
    }

    /**
     * El patrón de claves no distingue mayúsculas y cubre los nombres en español y los headers de autenticación.
     *
     * @return void
     */
    public function test_el_patron_de_claves_ignora_mayusculas_y_cubre_nombres_en_espanol(): void
    {
        $salida = $this->redactor()->redact([
            'Authorization' => 'Bearer SECRETO-U-4',
            'CLAVE_API'     => 'SECRETO-U-5',
            'client_secret' => 'SECRETO-U-6',
            'certificado'   => 'SECRETO-U-7',
            'private_key'   => 'SECRETO-U-8',
            'cookie'        => 'SECRETO-U-9',
            'descripcion'   => 'texto libre inocente',
        ]);

        $this->assertFalse(strpos(json_encode($salida), 'SECRETO-U'), 'Ninguna de estas claves puede filtrar su valor.');
        $this->assertSame('texto libre inocente', $salida['descripcion']);
    }

    /**
     * Los valores que no son arrays pasan tal cual, y los objetos se recorren como arrays.
     *
     * @return void
     */
    public function test_escalares_pasan_tal_cual_y_los_objetos_se_recorren(): void
    {
        $redactor = $this->redactor();

        $this->assertSame('texto', $redactor->redact('texto'));
        $this->assertSame(5, $redactor->redact(5));
        $this->assertNull($redactor->redact(null));
        $this->assertTrue($redactor->redact(true));

        $codificado = json_encode($redactor->redact((object) ['clave' => 'SECRETO-U-10', 'name' => 'visible']));
        $this->assertFalse(strpos($codificado, 'SECRETO-U-10'));
        $this->assertNotFalse(strpos($codificado, '"name":"visible"'));
    }

    /**
     * Un archivo subido nunca se guarda: queda sólo su nombre y su tamaño.
     *
     * @return void
     */
    public function test_reemplaza_los_archivos_subidos_por_nombre_y_bytes(): void
    {
        $archivo = UploadedFile::fake()->create('factura.pdf', 3);

        $salida = $this->redactor()->redact(['adjunto' => $archivo, 'nota' => 'sin secretos']);

        $this->assertSame(['archivo' => 'factura.pdf', 'bytes' => $archivo->getSize()], $salida['adjunto']);
        $this->assertGreaterThan(0, $salida['adjunto']['bytes']);
        $this->assertSame('sin secretos', $salida['nota']);
    }

    /**
     * El extracto JSON queda redactado y no supera el máximo pedido.
     *
     * @return void
     */
    public function test_el_extracto_json_se_redacta_y_se_recorta_al_maximo(): void
    {
        $redactor = $this->redactor();

        $this->assertSame('{"name":"x"}', $redactor->to_json_excerpt(['name' => 'x'], 1000));
        $this->assertSame('{"password":"[REDACTADO]"}', $redactor->to_json_excerpt(['password' => 'SECRETO-U-11'], 1000));

        $largo = $redactor->to_json_excerpt(['texto' => str_repeat('x', 500)], 100);
        $this->assertLessThanOrEqual(100, mb_strlen($largo), 'El extracto no supera el máximo.');
        $this->assertGreaterThan(0, mb_strlen($largo));
    }
}
