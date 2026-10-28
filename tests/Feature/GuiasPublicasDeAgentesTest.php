<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `GET api/guias/{archivo}`: las guías públicas en PDF para conectar el número a los Agentes de IA (misión
 * `implementacion-agentes-ia`, 28/10/2026). Se sirven desde `resources/guias/` porque `/deploy-admin` no
 * despliega `public/`.
 *
 * Lo que se mira: que las dos salgan sin sesión como PDF `inline`, y que cualquier otro nombre —incluido un
 * intento de salir de la carpeta— sea 404.
 */
class GuiasPublicasDeAgentesTest extends TestCase
{
    /**
     * Las dos guías salen sin sesión, como PDF para ver en el navegador, con el contenido del archivo.
     *
     * @return void
     */
    public function test_las_dos_guias_salen_como_pdf_inline_sin_sesion(): void
    {
        foreach (['agentes-ia-numero-propio.pdf', 'agentes-ia-linea-nueva.pdf'] as $nombre) {
            $respuesta = $this->get('/api/guias/' . $nombre);

            $respuesta->assertStatus(200);
            $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
            $this->assertStringStartsWith('inline', (string) $respuesta->headers->get('Content-Disposition'));
            $this->assertSame(resource_path('guias/' . $nombre), $respuesta->baseResponse->getFile()->getPathname());
            $this->assertSame('%PDF', substr((string) file_get_contents($respuesta->baseResponse->getFile()->getPathname()), 0, 4));
        }
    }

    /**
     * Cualquier otro nombre es 404: uno parecido, uno de otra carpeta y los intentos de salir de `resources/guias`.
     *
     * @return void
     */
    public function test_otro_nombre_es_404(): void
    {
        foreach ([
            '/api/guias/agentes-ia.pdf',
            '/api/guias/AGENTES-IA-NUMERO-PROPIO.pdf',
            '/api/guias/..%2F..%2F.env',
            '/api/guias/../../.env',
            '/api/guias/%2e%2e%2fcomposer.json',
            '/api/guias/composer.json',
        ] as $ruta) {
            $this->get($ruta)->assertStatus(404);
        }
    }
}
