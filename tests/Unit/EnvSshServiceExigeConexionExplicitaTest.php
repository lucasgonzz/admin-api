<?php

namespace Tests\Unit;

use App\Services\EnvSshService;
use PHPUnit\Framework\TestCase;

/**
 * EnvSshService no conecta solo.
 *
 * Antes cargaba fija la credencial de hosting compartido en el constructor y abría la sesión al
 * primer uso, así que ningún llamador declaraba contra qué servidor estaba trabajando — que es
 * exactamente cómo un flujo de VPS terminaba escribiendo el .env en el hosting compartido. Ahora
 * conectar es explícito, y usar el servicio sin haber conectado falla en vez de adivinar servidor.
 */
class EnvSshServiceExigeConexionExplicitaTest extends TestCase
{
    public function test_leer_sin_conectar_falla_en_vez_de_adivinar_el_servidor(): void
    {
        $service = new EnvSshService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No hay una sesión SSH abierta');

        $service->read_env('domains/comerciocity.com/public_html/cualquiera/api');
    }

    public function test_escribir_sin_conectar_falla_en_vez_de_adivinar_el_servidor(): void
    {
        $service = new EnvSshService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No hay una sesión SSH abierta');

        $service->write_env_vars('domains/comerciocity.com/public_html/cualquiera/api', ['FOO' => 'bar']);
    }
}
