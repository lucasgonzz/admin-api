<?php

namespace Tests\Feature\PuertasDelUserSetup;

use App\Models\Client;
use App\Services\UserSetupCandadoService;

/**
 * Las piezas del candado compartido del user setup (misión `puertas-del-user-setup`, 6/10/2026) que las cuatro puertas usan y que los tests de
 * cada puerta no miran de cerca: cómo se arma la frase de los bloqueos, cómo se compara el nombre de confirmación, qué instalación cuenta y en
 * qué orden salen las protecciones.
 *
 * 🔴 `plan_estricto()` y los textos de las protecciones de `claude/*` los fijan los 341 tests de `ClaudeImplementaciones` (que no pueden
 * cambiar): acá solo lo nuevo del servicio.
 */
class UserSetupCandadoServiceTest extends BaseDeLasPuertasDelUserSetup
{
    /**
     * @return UserSetupCandadoService
     */
    private function candado(): UserSetupCandadoService
    {
        return new UserSetupCandadoService();
    }

    /* ------------------------------------------------------------------------------------------
     | bloqueos() y frase_de_bloqueos()
     |----------------------------------------------------------------------------------------- */

    /**
     * `bloqueos()` devuelve solo los chequeos que tienen `ok === false`, en el mismo orden.
     *
     * @return void
     */
    public function test_bloqueos_devuelve_los_que_fallan_en_orden(): void
    {
        $chequeos = [
            $this->candado()->chequeo('a', true, 'bien'),
            $this->candado()->chequeo('b', false, 'mal'),
            $this->candado()->chequeo('c', true, 'bien'),
            $this->candado()->chequeo('d', false, 'mal'),
        ];

        $this->assertSame(['b', 'd'], array_column($this->candado()->bloqueos($chequeos), 'chequeo'));
        $this->assertSame([], $this->candado()->bloqueos([$chequeos[0], $chequeos[2]]));
        $this->assertSame([], $this->candado()->bloqueos([]));
    }

    /**
     * La frase de los bloqueos va en castellano corrido: "a", "a y b", "a, b y c".
     *
     * @return void
     */
    public function test_frase_de_bloqueos_en_castellano_corrido(): void
    {
        $bloqueo = function (string $nombre) {
            return $this->candado()->chequeo($nombre, false, 'x');
        };

        $this->assertSame('', $this->candado()->frase_de_bloqueos([]));
        $this->assertSame('uno', $this->candado()->frase_de_bloqueos([$bloqueo('uno')]));
        $this->assertSame('uno y dos', $this->candado()->frase_de_bloqueos([$bloqueo('uno'), $bloqueo('dos')]));
        $this->assertSame('uno, dos y tres', $this->candado()->frase_de_bloqueos([$bloqueo('uno'), $bloqueo('dos'), $bloqueo('tres')]));
        $this->assertSame('a, b, c y d', $this->candado()->frase_de_bloqueos([$bloqueo('a'), $bloqueo('b'), $bloqueo('c'), $bloqueo('d')]));
    }

    /* ------------------------------------------------------------------------------------------
     | El nombre de confirmación
     |----------------------------------------------------------------------------------------- */

    /**
     * El nombre que hay que escribir es el del negocio (la razón social y, si no hay, el nombre del contacto). Un cliente sin ninguno de
     * los dos cae a `Cliente #<id>`: si no, no podría confirmar nunca y el botón quedaría trabado.
     *
     * @return void
     */
    public function test_nombre_para_confirmar(): void
    {
        $con_negocio = $this->crear_cliente('Panchito Gómez', ['company_name' => 'Panchito S.A.']);
        $this->assertSame('Panchito S.A.', $this->candado()->nombre_para_confirmar($con_negocio));

        $sin_negocio = $this->crear_cliente('Rosa Fernández', ['company_name' => null]);
        $this->assertSame('Rosa Fernández', $this->candado()->nombre_para_confirmar($sin_negocio));

        // Un cliente sin ningún nombre (no debería existir): se arma en memoria, sin guardar.
        $sin_nombre               = new Client();
        $sin_nombre->id           = 4321;
        $sin_nombre->name         = '   ';
        $sin_nombre->company_name = '';

        $this->assertSame('Cliente #4321', $this->candado()->nombre_para_confirmar($sin_nombre));
    }

    /**
     * La confirmación compara recortado y sin distinguir mayúsculas; lo vacío, lo que no es un texto y lo que no coincide NUNCA confirman.
     *
     * @return void
     */
    public function test_confirma_el_nombre(): void
    {
        $cliente = $this->crear_cliente('Panchito Gómez', ['company_name' => 'Panchito S.A.']);

        foreach (['Panchito S.A.', 'panchito s.a.', 'PANCHITO S.A.', '   Panchito S.A.   '] as $bien) {
            $this->assertTrue($this->candado()->confirma_el_nombre($cliente, $bien), '«' . $bien . '» tendría que confirmar.');
        }

        // 🔴 Lo mismo que recorta el `trim()` de JavaScript con el que el modal habilita el botón: el espacio duro (U+00A0) de un nombre copiado de una
        // web o de Excel, otros separadores Unicode, la marca de orden de bytes (U+FEFF) y los saltos de línea. Con el `trim()` de PHP la pantalla
        // habilitaba el botón y la API rechazaba el mismo texto con "no coincide".
        foreach (["Panchito S.A.\u{00A0}", "\u{00A0}Panchito S.A.", "\u{FEFF}Panchito S.A.", "Panchito S.A.\u{2003}", "\t Panchito S.A.\r\n"] as $bien) {
            $this->assertTrue($this->candado()->confirma_el_nombre($cliente, $bien), 'Con espacios raros en las puntas tendría que confirmar: ' . json_encode($bien));
        }

        // Lo vacío, un nombre incompleto o con un espacio de más ADENTRO, otro nombre y lo que no es un texto. Un espacio duro ADENTRO tampoco
        // coincide (el `trim()` de JS solo recorta las puntas).
        foreach (['', '   ', "\u{00A0}", 'Panchito', 'Panchito  S.A.', "Panchito\u{00A0}S.A.", 'Otro', null, 0, 1, true, false, ['Panchito S.A.']] as $mal) {
            $this->assertFalse($this->candado()->confirma_el_nombre($cliente, $mal), 'No tendría que confirmar: ' . json_encode($mal));
        }
    }

    /* ------------------------------------------------------------------------------------------
     | La instalación contra la que se compara el arranque
     |----------------------------------------------------------------------------------------- */

    /**
     * Solo cuenta la instalación COMPLETA y COMPLETADA, la más reciente: el esqueleto del subdominio hermano no es "el sistema", y una
     * pendiente, instalando o fallida no es un sistema instalado.
     *
     * @return void
     */
    public function test_la_instalacion_que_cuenta_es_la_completa_y_completada_mas_reciente(): void
    {
        $e = $this->escenario(['instalacion' => false]);

        $this->assertNull($this->candado()->instalacion_completada_del_cliente($e['cliente']));

        $this->crear_instalacion($e['cliente'], ['status' => 'pendiente', 'kind' => 'completa']);
        $this->crear_instalacion($e['cliente'], ['status' => 'fallida', 'kind' => 'completa']);
        $this->crear_instalacion($e['cliente'], ['status' => 'completada', 'kind' => 'esqueleto']);

        $this->assertNull($this->candado()->instalacion_completada_del_cliente($e['cliente']), 'Solo hay una pendiente, una fallida y un esqueleto completado.');

        $primera = $this->crear_instalacion($e['cliente'], ['status' => 'completada', 'kind' => 'completa']);
        $this->assertSame($primera->id, $this->candado()->instalacion_completada_del_cliente($e['cliente'])->id);

        $ultima = $this->crear_instalacion($e['cliente'], ['status' => 'completada', 'kind' => 'completa']);
        $this->assertSame($ultima->id, $this->candado()->instalacion_completada_del_cliente($e['cliente'])->id);
    }

    /* ------------------------------------------------------------------------------------------
     | Las protecciones
     |----------------------------------------------------------------------------------------- */

    /**
     * Las cuatro protecciones de una implementación salen en este orden y con estos nombres (son los de `claude/*`): sin la etapa, que es de
     * cada puerta.
     *
     * @return void
     */
    public function test_las_protecciones_de_una_implementacion_salen_en_el_orden_de_siempre(): void
    {
        $e = $this->escenario();

        $protecciones = $this->candado()->protecciones_de_implementacion($e['implementacion'], $e['cliente'], $this->candado()->instalacion_completada_del_cliente($e['cliente']));

        $this->assertSame(['instalacion_de_esta_implementacion', 'sin_sistema_vivo', 'lead_sin_user_setup', 'sin_aplicar_antes'], array_column($protecciones, 'chequeo'));
        $this->assertSame([], $this->candado()->bloqueos($protecciones));

        foreach ($protecciones as $proteccion) {
            $this->assertSame(['chequeo', 'ok', 'detalle'], array_keys($proteccion), 'Los chequeos no pueden ganar claves: viajan en las respuestas de claude/*.');
        }
    }

    /**
     * Las tres protecciones de la puerta de leads salen en este orden, y con un lead sin cliente promovido los dos chequeos que miran al cliente
     * "no aplican" (pasan) y el del propio lead sí cuenta.
     *
     * @return void
     */
    public function test_las_protecciones_de_leads_salen_en_orden_y_sin_cliente_solo_cuenta_el_lead(): void
    {
        $lead = $this->crear_lead(['status' => 'cerrado_ganado', 'user_setup_status' => 'pendiente']);

        $sin_cliente = $this->candado()->protecciones_de_lead($lead, null);

        $this->assertSame(['sin_sistema_vivo', 'lead_sin_user_setup', 'implementacion_sin_user_setup'], array_column($sin_cliente, 'chequeo'));
        $this->assertSame([], $this->candado()->bloqueos($sin_cliente));

        $lead->user_setup_status = 'exitoso';
        $lead->save();

        $this->assertSame(['lead_sin_user_setup'], array_column($this->candado()->bloqueos($this->candado()->protecciones_de_lead($lead, null)), 'chequeo'));
    }
}
