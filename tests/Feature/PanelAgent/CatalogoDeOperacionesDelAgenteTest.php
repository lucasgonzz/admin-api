<?php

namespace Tests\Feature\PanelAgent;

use App\Services\PanelAgent\PanelAgentOperationClassifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Misión admin-control-total — el catálogo de operaciones se deriva del router y coincide con lo que
 * hace el middleware: lo que el catálogo marca como frenado es lo que devuelve 428.
 *
 * Contrato del cuerpo de `GET api/admin/agente/operaciones` (lo fijan estos tests):
 *  - `operaciones`: lista de {metodo, ruta (patrón sin `api/admin/`), uri, nombre, accion, categoria,
 *    frenada, motivo_freno, nota}.
 *  - `resumen`: {total, por_categoria: {categoria: cantidad}}.
 *
 * Cubre CA10 del PLAN. Los conteos NO se fijan (otras misiones agregan rutas): se fijan rutas
 * representativas e invariantes.
 */
class CatalogoDeOperacionesDelAgenteTest extends TestCase
{
    use DatabaseTransactions;
    use AgentePanelTestHelpers;

    /** Categorías válidas del catálogo. */
    private const CATEGORIAS = ['lectura', 'escritura', 'credenciales', 'borrado', 'masiva', 'plata', 'terceros', 'excluida'];

    /**
     * Pide el catálogo completo (opcionalmente filtrado) como un agente.
     *
     * @param  array<string, string> $filtros Query string (`categoria`, `buscar`, `metodo`).
     * @return array<string, mixed>
     */
    private function catalogo(array $filtros = []): array
    {
        $agente = $this->crear_agente();
        $token  = $this->token_de($agente);

        $respuesta = $this->pedir_como($token, 'GET', '/api/admin/agente/operaciones', $filtros);
        $respuesta->assertOk();

        $cuerpo = $respuesta->json();
        $this->assertArrayHasKey('operaciones', $cuerpo);
        $this->assertArrayHasKey('resumen', $cuerpo);

        return $cuerpo;
    }

    /**
     * Busca una operación por método y patrón de ruta.
     *
     * @param  array<int, array<string, mixed>> $operaciones Lista del catálogo.
     * @param  string                           $metodo      Verbo HTTP.
     * @param  string                           $ruta        Patrón sin `api/admin/`.
     * @return array<string, mixed>|null
     */
    private function buscar_operacion(array $operaciones, string $metodo, string $ruta)
    {
        foreach ($operaciones as $operacion) {
            if ($operacion['metodo'] === $metodo && $operacion['ruta'] === $ruta) {
                return $operacion;
            }
        }

        return null;
    }

    /**
     * Pares (método, uri) que el router expone bajo `api/admin/*` con `auth:sanctum` (sin HEAD ni OPTIONS).
     *
     * @return array<int, string> Claves únicas «MÉTODO uri».
     */
    private function pares_del_router(): array
    {
        $pares = [];

        foreach (Route::getRoutes() as $ruta) {
            if (strpos($ruta->uri(), 'api/admin/') !== 0) {
                continue;
            }
            if (! in_array('auth:sanctum', $ruta->gatherMiddleware(), true)) {
                continue;
            }
            foreach ($ruta->methods() as $metodo) {
                if (in_array($metodo, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $pares[$metodo . ' ' . $ruta->uri()] = true;
            }
        }

        return array_keys($pares);
    }

    /**
     * CA10 — Rutas representativas con su categoría y su freno.
     *
     * @return void
     */
    public function test_rutas_representativas(): void
    {
        $operaciones = $this->catalogo()['operaciones'];

        $esperadas = [
            ['PUT',    'client/{id}',                                   'escritura',    false],
            ['GET',    'client',                                        'lectura',      false],
            ['DELETE', 'client/{id}',                                   'borrado',      true],
            ['POST',   'client/{clientId}/mensualidad/pagos',           'plata',        true],
            ['POST',   'lead/{id}/send-direct-message',                 'terceros',     true],
            ['POST',   'mass-update/{model}',                           'masiva',       true],
            ['GET',    'client-apis/{client_api}/hosting-credentials',  'credenciales', true],
            ['GET',    'agente/operaciones',                            'lectura',      false],
            ['GET',    'agente/auditoria',                              'lectura',      false],
        ];

        foreach ($esperadas as $caso) {
            list($metodo, $ruta, $categoria, $frenada) = $caso;
            $operacion = $this->buscar_operacion($operaciones, $metodo, $ruta);
            $this->assertNotNull($operacion, "$metodo $ruta tiene que estar en el catálogo.");
            $this->assertSame($categoria, $operacion['categoria'], "$metodo $ruta: categoría.");
            $this->assertSame($frenada, $operacion['frenada'], "$metodo $ruta: frenada.");
            $this->assertSame('api/admin/' . $ruta, $operacion['uri'], "$metodo $ruta: uri completa.");
        }

        $logout = $this->buscar_operacion($operaciones, 'POST', 'logout');
        $this->assertNotNull($logout);
        $this->assertSame('excluida', $logout['categoria']);

        $prompt = $this->buscar_operacion($operaciones, 'PUT', 'ai-system-prompt');
        $this->assertNotNull($prompt);
        $this->assertSame('escritura', $prompt['categoria']);
        $this->assertTrue($this->contiene($prompt['nota'], 'agent-prompts:sync'), 'La nota avisa que el prompt se pisa cada 10 min.');

        $identidad = $this->buscar_operacion($operaciones, 'PUT', 'settings/agent-identity');
        $this->assertNotNull($identidad);
        $this->assertTrue($this->contiene($identidad['nota'], 'agent-prompts:sync'));

        $borrar = $this->buscar_operacion($operaciones, 'DELETE', 'client/{id}');
        $this->assertSame('ClientController@destroy_json', $borrar['accion']);
        $this->assertNotEmpty($borrar['motivo_freno'], 'Toda operación frenada explica por qué.');
    }

    /**
     * CA10 — Invariantes contra el router real: cantidad exacta, suma del resumen, todo DELETE frenado, sin categoría vacía.
     *
     * @return void
     */
    public function test_invariantes_contra_el_router(): void
    {
        $cuerpo      = $this->catalogo();
        $operaciones = $cuerpo['operaciones'];
        $resumen     = $cuerpo['resumen'];

        $this->assertCount(count($this->pares_del_router()), $operaciones, 'Una operación por cada par (método, ruta) del router.');

        // Cada operación del catálogo existe en el router y no hay repetidas.
        $claves = [];
        foreach ($operaciones as $operacion) {
            $claves[$operacion['metodo'] . ' ' . $operacion['uri']] = true;
        }
        $this->assertCount(count($operaciones), $claves, 'No hay operaciones repetidas.');
        $this->assertEqualsCanonicalizing($this->pares_del_router(), array_keys($claves));

        // El resumen suma el total.
        $this->assertArrayHasKey('total', $resumen);
        $this->assertArrayHasKey('por_categoria', $resumen);
        $this->assertSame(count($operaciones), (int) $resumen['total']);
        $this->assertSame(count($operaciones), (int) array_sum($resumen['por_categoria']));

        foreach ($operaciones as $operacion) {
            $this->assertNotEmpty($operacion['categoria'], $operacion['metodo'] . ' ' . $operacion['ruta'] . ' sin categoría.');
            $this->assertContains($operacion['categoria'], self::CATEGORIAS, $operacion['metodo'] . ' ' . $operacion['ruta']);
            if ($operacion['metodo'] === 'DELETE') {
                $this->assertTrue($operacion['frenada'], 'Todo DELETE frena: ' . $operacion['ruta']);
            }
            if ($operacion['frenada']) {
                $this->assertNotEmpty($operacion['motivo_freno'], 'Toda frenada tiene motivo: ' . $operacion['ruta']);
            }
        }
    }

    /**
     * CA10 — Los filtros `categoria`, `buscar` y `metodo`.
     *
     * @return void
     */
    public function test_filtros(): void
    {
        $plata = $this->catalogo(['categoria' => 'plata', 'buscar' => 'mensualidad'])['operaciones'];
        $this->assertNotEmpty($plata, 'Hay rutas de plata con «mensualidad».');
        foreach ($plata as $operacion) {
            $this->assertSame('plata', $operacion['categoria']);
            $texto = strtolower($operacion['ruta'] . ' ' . $operacion['accion']);
            $this->assertTrue($this->contiene($texto, 'mensualidad'), 'No contiene «mensualidad»: ' . $operacion['ruta']);
        }
        $this->assertNotNull($this->buscar_operacion($plata, 'POST', 'client/{clientId}/mensualidad/pagos'));

        $borrados = $this->catalogo(['metodo' => 'DELETE'])['operaciones'];
        $this->assertNotEmpty($borrados);
        foreach ($borrados as $operacion) {
            $this->assertSame('DELETE', $operacion['metodo']);
        }

        $nada = $this->catalogo(['buscar' => 'esta-cadena-no-existe-en-ninguna-ruta-zzqq'])['operaciones'];
        $this->assertSame([], $nada);
    }

    /**
     * CA10 — Coherencia catálogo ↔ middleware: «frenada» en el catálogo ⇔ 428 al pedirla sin código.
     *
     * Se usan rutas sin binding de modelo (un id inexistente cortaría con 404 antes del guard) y, para las
     * libres, pedidos que no producen efectos (lecturas, o un PUT sobre un id inexistente → 404).
     *
     * @return void
     */
    public function test_catalogo_y_middleware_coinciden(): void
    {
        $operaciones = $this->catalogo()['operaciones'];
        $agente      = $this->crear_agente();
        $token       = $this->token_de($agente);

        $casos = [
            ['DELETE', 'message-variant/{id}',                 '/api/admin/message-variant/987654321', []],
            ['DELETE', 'client/{id}',                          '/api/admin/client/987654321', []],
            ['POST',   'client/{clientId}/mensualidad/pagos',  '/api/admin/client/987654321/mensualidad/pagos', ['periodo' => '2026-11']],
            ['POST',   'lead/{id}/send-direct-message',        '/api/admin/lead/987654321/send-direct-message', ['content' => 'x']],
            ['POST',   'mass-update/{model}',                  '/api/admin/mass-update/client', []],
            ['GET',    'client',                               '/api/admin/client', []],
            ['GET',    'me',                                   '/api/admin/me', []],
            ['PUT',    'client/{id}',                          '/api/admin/client/987654321', ['is_active' => true]],
        ];

        foreach ($casos as $caso) {
            list($metodo, $ruta, $uri, $cuerpo) = $caso;
            $operacion = $this->buscar_operacion($operaciones, $metodo, $ruta);
            $this->assertNotNull($operacion, "$metodo $ruta en el catálogo.");

            $respuesta = $this->pedir_como($token, $metodo, $uri, $cuerpo);

            if ($operacion['frenada']) {
                $this->assertSame(428, $respuesta->getStatusCode(), "$metodo $ruta figura frenada: tiene que dar 428.");
            } else {
                $this->assertNotSame(428, $respuesta->getStatusCode(), "$metodo $ruta figura libre: no puede dar 428.");
                $this->assertNotSame(403, $respuesta->getStatusCode(), "$metodo $ruta figura libre: no puede ser excluida.");
            }
        }

        // La excluida figura en el catálogo y da 403.
        $logout = $this->buscar_operacion($operaciones, 'POST', 'logout');
        $this->assertSame('excluida', $logout['categoria']);
        $this->assertSame(403, $this->pedir_como($token, 'POST', '/api/admin/logout')->getStatusCode());
    }

    /**
     * El clasificador es la única fuente de verdad: cubre cada categoría de la tabla de patrones del PLAN §2.4,
     * respeta el orden de prioridad y cierra por defecto lo que no es del panel.
     *
     * @return void
     */
    public function test_el_clasificador_cubre_cada_categoria_de_la_tabla(): void
    {
        $this->assertTrue(class_exists(PanelAgentOperationClassifier::class), 'Falta el servicio PanelAgentOperationClassifier.');

        /** @var PanelAgentOperationClassifier $clasificador */
        $clasificador = $this->app->make(PanelAgentOperationClassifier::class);

        $tabla = [
            // excluida
            ['POST',   'api/admin/logout',                                    'excluida'],
            ['PUT',    'api/admin/me',                                        'excluida'],
            ['POST',   'api/admin/push/subscribe',                            'excluida'],
            ['GET',    'api/admin/calendar/google/{admin_id}/connect',        'excluida'],
            ['GET',    'api/otra-cosa/que-no-es-del-panel',                   'excluida'],
            // credenciales
            ['PUT',    'api/admin/admin-user/{id}',                           'credenciales'],
            ['DELETE', 'api/admin/admin-user/{id}',                           'credenciales'],
            ['GET',    'api/admin/client-apis/{client_api}/hosting-credentials', 'credenciales'],
            ['PUT',    'api/admin/comerciocity-afip-config',                  'credenciales'],
            ['PUT',    'api/admin/settings/implementation-google-api-key',    'credenciales'],
            ['PUT',    'api/admin/whatsapp-config',                           'credenciales'],
            ['POST',   'api/admin/env-template',                              'credenciales'],
            ['GET',    'api/admin/client/{id}/env-values',                    'credenciales'],
            // borrado (y su prioridad sobre terceros)
            ['DELETE', 'api/admin/client/{id}',                               'borrado'],
            ['POST',   'api/admin/prueba/instancias/{id}/borrar',             'borrado'],
            ['POST',   'api/admin/lead/{id}/demo-token/revocar',              'borrado'],
            // masiva
            ['POST',   'api/admin/mass-update/{model}',                       'masiva'],
            ['POST',   'api/admin/lead/batch-recover-unanswered',             'masiva'],
            ['POST',   'api/admin/env-bulk/apply',                            'masiva'],
            // plata
            ['POST',   'api/admin/client/{clientId}/mensualidad/pagos',       'plata'],
            ['PUT',    'api/admin/client/{id}/mensualidad',                   'plata'],
            ['POST',   'api/admin/client/{clientId}/ai-plan',                 'plata'],
            // lectura (y su prioridad sobre terceros)
            ['GET',    'api/admin/client',                                    'lectura'],
            ['GET',    'api/admin/client/{id}/mensualidad',                   'lectura'],
            ['POST',   'api/admin/search-from-modal/{model}',                 'lectura'],
            ['POST',   'api/admin/update/preview',                            'lectura'],
            ['POST',   'api/admin/env-bulk/preview',                          'lectura'],
            ['POST',   'api/admin/client/suggest-subdomain',                  'lectura'],
            // terceros
            ['POST',   'api/admin/lead/{id}/send-direct-message',             'terceros'],
            ['POST',   'api/admin/installations',                             'terceros'],
            ['POST',   'api/admin/support-ticket',                            'terceros'],
            // excepciones de terceros → caen al default
            ['PUT',    'api/admin/lead-message/{id}/cancel-auto-send',        'escritura'],
            // escritura (default)
            ['PUT',    'api/admin/client/{id}',                               'escritura'],
            ['POST',   'api/admin/client',                                    'escritura'],
            ['PUT',    'api/admin/ai-system-prompt',                          'escritura'],
            ['PUT',    'api/admin/settings/agent-identity',                   'escritura'],
        ];

        foreach ($tabla as $caso) {
            list($metodo, $uri, $esperada) = $caso;
            $this->assertSame($esperada, $clasificador->classify($metodo, $uri), "$metodo $uri");
        }

        foreach (['credenciales', 'borrado', 'masiva', 'plata', 'terceros'] as $frenada) {
            $this->assertTrue($clasificador->is_braked($frenada), "$frenada frena.");
        }
        foreach (['lectura', 'escritura', 'excluida'] as $libre) {
            $this->assertFalse($clasificador->is_braked($libre), "$libre no es una categoría frenada.");
        }
    }
}
