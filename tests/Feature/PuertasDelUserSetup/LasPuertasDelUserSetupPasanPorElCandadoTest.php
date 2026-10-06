<?php

namespace Tests\Feature\PuertasDelUserSetup;

use App\Services\ImplementationUserSetupService;
use App\Services\RunUserSetupService;

/**
 * La CLASE de error detrás de las puertas del user setup (misión `puertas-del-user-setup`, 6/10/2026): un camino nuevo que llega al
 * endpoint `admin-sync/user-setup` del sistema de un cliente —que hace `migrate:fresh`— sin pasar por el candado.
 *
 * 🔴 Los tests de las puertas prueban las cuatro que existen hoy. Este es el que denuncia la QUINTA: si alguien agrega un lugar que le pega
 * a ese endpoint, o saca el candado de uno de los dos que existen, o le abre a otro llamador la confirmación "una persona lo vio", falla
 * acá con un mensaje que dice qué hacer. Es un test estático: lee el código de `app/` SIN los comentarios (los comentarios de esta misión
 * nombran el endpoint a propósito, para explicar por qué hay un candado) y mira dónde vive cada cosa.
 *
 * Qué fija:
 *  1. Los únicos sitios de `app/` que le pegan al endpoint son `ImplementationUserSetupService` (la puerta de implementaciones, a la que
 *     llegan el job de `claude/*`, el modo automático y el botón del panel) y `RunUserSetupService` (la de leads). El candado
 *     (`UserSetupCandadoService`) lo nombra solo para MOSTRAR el destino en el dry-run, y no hace ningún pedido HTTP.
 *  2. Los dos sitios evalúan el candado ANTES de armar el pedido y de escribir nada.
 *  3. El único llamador que le dice al punto de llamada "una persona confirmó, no frenes" es el botón del panel, y el default es protegido.
 */
class LasPuertasDelUserSetupPasanPorElCandadoTest extends BaseDeLasPuertasDelUserSetup
{
    /** El endpoint remoto que hace `migrate:fresh`. */
    const ENDPOINT = 'admin-sync/user-setup';

    /** Los archivos de `app/` que le PEGAN al endpoint (rutas relativas a `app/`, con `/`). */
    const LOS_QUE_LLAMAN = [
        'Services/ImplementationUserSetupService.php',
        'Services/RunUserSetupService.php',
    ];

    /** Los archivos de `app/` que lo NOMBRAN sin llamarlo: el dry-run muestra el destino. */
    const LOS_QUE_SOLO_LO_MUESTRAN = [
        'Services/UserSetupCandadoService.php',
    ];

    /* ------------------------------------------------------------------------------------------
     | Helpers: el código ejecutable, sin comentarios
     |----------------------------------------------------------------------------------------- */

    /**
     * El código de un archivo SIN comentarios ni docblocks, conservando el número de línea de cada cosa (cada comentario se reemplaza por
     * la misma cantidad de saltos de línea).
     *
     * @param string $fuente El contenido del archivo.
     *
     * @return string
     */
    private function sin_comentarios(string $fuente): string
    {
        $ejecutable = '';

        foreach (token_get_all($fuente) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $ejecutable .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                $ejecutable .= $token[1];

                continue;
            }

            $ejecutable .= $token;
        }

        return $ejecutable;
    }

    /**
     * El código ejecutable (sin comentarios) de todos los archivos de `app/` que nombran el endpoint.
     *
     * @return array<string, string> Ruta relativa a `app/` (con `/`) => código sin comentarios.
     */
    private function archivos_que_nombran_el_endpoint(): array
    {
        $encontrados = [];

        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterador as $archivo) {
            if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }

            $fuente = (string) file_get_contents($archivo->getPathname());

            // El filtro barato primero: solo se tokeniza lo que nombra el endpoint (también en comentarios).
            if (strpos($fuente, self::ENDPOINT) === false) {
                continue;
            }

            $codigo = $this->sin_comentarios($fuente);

            if (strpos($codigo, self::ENDPOINT) !== false) {
                $relativa = str_replace('\\', '/', substr($archivo->getPathname(), strlen(app_path()) + 1));

                $encontrados[$relativa] = $codigo;
            }
        }

        ksort($encontrados);

        return $encontrados;
    }

    /**
     * El código ejecutable (sin comentarios) de UN método.
     *
     * @param string $clase  La clase.
     * @param string $metodo El método.
     *
     * @return string
     */
    private function codigo_del_metodo(string $clase, string $metodo): string
    {
        $reflexion = new \ReflectionMethod($clase, $metodo);
        $lineas    = explode("\n", $this->sin_comentarios((string) file_get_contents($reflexion->getFileName())));

        return implode("\n", array_slice($lineas, $reflexion->getStartLine() - 1, $reflexion->getEndLine() - $reflexion->getStartLine() + 1));
    }

    /**
     * Posición de lo primero que aparece, o `false` si no está.
     *
     * @param string $codigo  Donde se busca.
     * @param string $aguja   Lo que se busca.
     *
     * @return int|false
     */
    private function donde(string $codigo, string $aguja)
    {
        return strpos($codigo, $aguja);
    }

    /* ------------------------------------------------------------------------------------------
     | 1. Quién le pega al endpoint
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Solo DOS sitios de `app/` le pegan al endpoint del user setup. Si aparece un tercero, esta puerta no tiene candado y el
     * próximo `migrate:fresh` sobre un negocio que opera sale de ahí.
     *
     * @return void
     */
    public function test_solo_dos_archivos_le_pegan_al_endpoint_del_user_setup(): void
    {
        $nombran = array_keys($this->archivos_que_nombran_el_endpoint());

        $esperados = array_merge(self::LOS_QUE_LLAMAN, self::LOS_QUE_SOLO_LO_MUESTRAN);
        sort($esperados);

        $this->assertSame(
            $esperados,
            $nombran,
            'Cambió quién nombra `' . self::ENDPOINT . '` en el código de app/. Ese endpoint hace migrate:fresh en el sistema del cliente: '
                . 'TODO sitio que lo llame tiene que pasar por el candado (App\\Services\\UserSetupCandadoService) ANTES de armar el pedido, '
                . 'y sumarse a LOS_QUE_LLAMAN de este test con un test propio que pruebe que no llama a un sistema que ya opera. '
                . 'Si es una puerta nueva, no la abras sin ese candado.'
        );
    }

    /**
     * El candado nombra el endpoint solo para mostrarlo en el dry-run: no hace ningún pedido HTTP. Un servicio que "lee y decide" y
     * además llama deja de ser un candado.
     *
     * @return void
     */
    public function test_el_candado_no_hace_pedidos_http(): void
    {
        $codigo = $this->archivos_que_nombran_el_endpoint()['Services/UserSetupCandadoService.php'];

        foreach (['Http::', 'curl_', 'Guzzle', 'file_get_contents(\'http', 'file_get_contents("http', 'fsockopen'] as $cliente_http) {
            $this->assertFalse(
                strpos($codigo, $cliente_http) !== false,
                'UserSetupCandadoService usa `' . $cliente_http . '`: el candado lee y decide, no llama a nadie.'
            );
        }
    }

    /* ------------------------------------------------------------------------------------------
     | 2. Los dos sitios pasan por el candado, y antes de todo
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El punto de llamada de implementaciones evalúa el candado ANTES de armar el payload y de hacer el pedido: sacar esa línea (o
     * moverla después del POST) reabre las tres puertas que pasan por acá.
     *
     * @return void
     */
    public function test_el_punto_de_llamada_evalua_el_candado_antes_de_armar_el_pedido(): void
    {
        $llamada = $this->codigo_del_metodo(ImplementationUserSetupService::class, 'trigger_user_setup');

        $candado = $this->donde($llamada, '$this->frenar_si_el_candado_lo_pide(');

        $this->assertNotFalse($candado, 'trigger_user_setup() ya no evalúa el candado.');

        foreach (['$this->build_payload(', 'Http::', '->post('] as $despues) {
            $posicion = $this->donde($llamada, $despues);

            $this->assertNotFalse($posicion, 'trigger_user_setup() ya no tiene `' . $despues . '`: revisá este test.');
            $this->assertLessThan($posicion, $candado, 'El candado se evalúa DESPUÉS de `' . $despues . '`: ya no frena nada.');
        }

        // Y lo que evalúa es la definición compartida, no una copia.
        $evaluacion = $this->codigo_del_metodo(ImplementationUserSetupService::class, 'frenar_si_el_candado_lo_pide');

        $this->assertStringContainsString('UserSetupCandadoService', $evaluacion);
        $this->assertStringContainsString('protecciones_de_implementacion(', $evaluacion);
    }

    /**
     * 🔴 La puerta de leads evalúa el candado ANTES de todo lo que escribe: antes de `ensure_production_client()` (que pisa los datos del cliente),
     * antes de `mark_failed()` y de marcar `ejecutandose` (que sobrescriben el estado del lead, la señal de los demás candados) y antes del
     * pedido. Y lo frenado se lanza como excepción, sin tocar nada.
     *
     * @return void
     */
    public function test_la_puerta_de_leads_evalua_el_candado_antes_de_tocar_nada(): void
    {
        $run = $this->codigo_del_metodo(RunUserSetupService::class, 'run');

        $candado = $this->donde($run, '$this->frenar_si_el_candado_lo_pide(');

        $this->assertNotFalse($candado, 'RunUserSetupService::run() ya no evalúa el candado.');

        foreach (['$this->ensure_production_client(', '$this->mark_failed(', "'ejecutandose'", 'Http::', '->post('] as $despues) {
            $posicion = $this->donde($run, $despues);

            $this->assertNotFalse($posicion, 'RunUserSetupService::run() ya no tiene `' . $despues . '`: revisá este test.');
            $this->assertLessThan($posicion, $candado, 'El candado se evalúa DESPUÉS de `' . $despues . '`: ya tocó algo antes de frenar.');
        }

        $evaluacion = $this->codigo_del_metodo(RunUserSetupService::class, 'frenar_si_el_candado_lo_pide');

        $this->assertStringContainsString('UserSetupCandadoService', $evaluacion);
        $this->assertStringContainsString('protecciones_de_lead(', $evaluacion);
        $this->assertStringContainsString('UserSetupBloqueadoException', $evaluacion);
    }

    /* ------------------------------------------------------------------------------------------
     | 3. Quién puede decir "una persona lo confirmó"
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El tercer parámetro de `trigger_user_setup()` (`$confirmado_por_una_persona`) tiene default `false`: un llamador nuevo que no sepa del
     * candado queda frenado por defecto.
     *
     * @return void
     */
    public function test_el_punto_de_llamada_esta_protegido_por_defecto(): void
    {
        $parametros = (new \ReflectionMethod(ImplementationUserSetupService::class, 'trigger_user_setup'))->getParameters();

        $this->assertCount(3, $parametros);
        $this->assertSame('confirmado_por_una_persona', $parametros[2]->getName());
        $this->assertTrue($parametros[2]->isDefaultValueAvailable());
        $this->assertFalse($parametros[2]->getDefaultValue());
    }

    /**
     * 🔴 Solo el botón del panel (`ImplementationActionService`) le pasa un tercer argumento a `trigger_user_setup()`, y lo hace DESPUÉS de validar la
     * confirmación por nombre. El job de `claude/*` y el modo automático lo llaman sin él: si alguno empieza a pasarlo, un cambio ajeno
     * le saltea el candado sin que nadie lo vea.
     *
     * @return void
     */
    public function test_solo_el_boton_del_panel_confirma_a_mano(): void
    {
        $con_tres_argumentos = [];
        $llamadas            = 0;

        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterador as $archivo) {
            if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }

            $fuente = (string) file_get_contents($archivo->getPathname());

            if (strpos($fuente, 'trigger_user_setup(') === false) {
                continue;
            }

            $codigo   = $this->sin_comentarios($fuente);
            $relativa = str_replace('\\', '/', substr($archivo->getPathname(), strlen(app_path()) + 1));

            $desde = 0;

            while (($posicion = strpos($codigo, 'trigger_user_setup(', $desde)) !== false) {
                $desde = $posicion + 1;

                // La definición del método no es una llamada.
                if (substr(rtrim(substr($codigo, max(0, $posicion - 12), 12)), -8) === 'function') {
                    continue;
                }

                $llamadas++;

                if ($this->cantidad_de_argumentos($codigo, $posicion + strlen('trigger_user_setup(')) >= 3) {
                    $con_tres_argumentos[$relativa] = true;
                }
            }
        }

        $this->assertGreaterThanOrEqual(3, $llamadas, 'Tendría que haber al menos las llamadas del job, del modo automático y del panel.');
        $this->assertSame(
            ['Services/ImplementationActionService.php'],
            array_keys($con_tres_argumentos),
            'Otro archivo le pasa el tercer argumento a trigger_user_setup() (`$confirmado_por_una_persona`): solo el botón del panel puede, después de '
                . 'validar la confirmación por nombre. Cualquier otro llamador pasa por el candado.'
        );
    }

    /**
     * Cuenta los argumentos de una llamada, a partir de lo que sigue al paréntesis de apertura (comas del primer nivel).
     *
     * @param string $codigo     El código.
     * @param int    $despues_de La posición justo después del `(` de la llamada.
     *
     * @return int
     */
    private function cantidad_de_argumentos(string $codigo, int $despues_de): int
    {
        $profundidad = 1;
        $argumentos  = 1;
        $largo       = strlen($codigo);

        for ($i = $despues_de; $i < $largo && $profundidad > 0; $i++) {
            $caracter = $codigo[$i];

            if ($caracter === '(' || $caracter === '[') {
                $profundidad++;
            } elseif ($caracter === ')' || $caracter === ']') {
                $profundidad--;
            } elseif ($caracter === ',' && $profundidad === 1) {
                $argumentos++;
            } elseif ($caracter === "'" || $caracter === '"') {
                // Se saltea el texto entre comillas (puede traer paréntesis y comas).
                $i++;

                while ($i < $largo && $codigo[$i] !== $caracter) {
                    if ($codigo[$i] === '\\') {
                        $i++;
                    }

                    $i++;
                }
            }
        }

        // Una llamada sin nada entre los paréntesis no tiene argumentos.
        return trim(substr($codigo, $despues_de, 1)) === ')' ? 0 : $argumentos;
    }
}
