<?php

namespace Tests\Feature\PuertasDelUserSetup;

use App\Jobs\EjecutarUserSetupDeImplementacionJob;
use App\Models\ClientApi;
use App\Models\Implementation;
use App\Services\ImplementationUserSetupService;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * El PUNTO DE LLAMADA del user setup se cierra solo (misión `puertas-del-user-setup`, 6/10/2026).
 *
 * 🔴 `ImplementationUserSetupService::trigger_user_setup()` es el único lugar desde donde las puertas de implementaciones le pegan
 * al endpoint `admin-sync/user-setup` del sistema del cliente, que hace `migrate:fresh`. Antes de esta misión no miraba nada: el job
 * de `claude/*` chequeaba ANTES de despachar, el modo automático y el botón del panel (con `force`) no chequeaban nada. Ahora el
 * propio servicio evalúa las protecciones del candado (instalación anterior a la implementación, sistema vivo, user setup ya
 * aplicado por el camino de leads, candado de la implementación lleno) y, si alguna falla, NO llama: un llamador nuevo que no sepa
 * del candado queda frenado por defecto. Solo se saltea con `$confirmado_por_una_persona = true`, que únicamente pasa el botón del
 * panel después de validar la confirmación por nombre.
 *
 * Y el job de `claude/*` reconoce ese frenado como "no corrió" (`puede_haber_corrido = false`): sin eso lo leería como "pudo haber
 * corrido" y mandaría a conciliar o reintentar un setup que ni salió.
 */
class PuntoDeLlamadaDelUserSetupTest extends BaseDeLasPuertasDelUserSetup
{
    /** El token (`iniciado_at`) del intento que se está corriendo. */
    const TOKEN = '2026-10-06T10:00:00.000000Z';

    /**
     * Cada protección, con la forma de provocarla y el nombre con el que aparece en el mensaje.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function protecciones(): array
    {
        return [
            'un sistema vivo'               => ['sistema_vivo', 'sin_sistema_vivo'],
            'el user setup del lead'        => ['lead_exitoso', 'lead_sin_user_setup'],
            'el candado de la implementación' => ['candado_lleno', 'sin_aplicar_antes'],
            'una instalación anterior'      => ['instalacion_anterior', 'instalacion_de_esta_implementacion'],
        ];
    }

    /**
     * Las protecciones que pueden aparecer DESPUÉS del despacho de un job: todas menos el candado lleno, que el propio job descarta
     * antes de llegar al punto de llamada (`tomar_el_turno()`).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function protecciones_que_aparecen_en_la_cola(): array
    {
        $protecciones = $this->protecciones();

        unset($protecciones['el candado de la implementación']);

        return $protecciones;
    }

    /**
     * Arma el escenario con la protección pedida ya disparada.
     *
     * @param string $proteccion Cuál (ver `protecciones()`).
     *
     * @return Implementation La implementación, con su cliente y la protección disparada.
     */
    private function escenario_con(string $proteccion): Implementation
    {
        $e = $this->escenario();

        switch ($proteccion) {
            case 'sistema_vivo':
                $this->agregar_un_sistema_vivo($e['cliente']);

                return $e['implementacion'];
            case 'lead_exitoso':
                $this->agregar_el_lead_con_el_setup($e['cliente'], 'exitoso');

                return $e['implementacion'];
            case 'candado_lleno':
                return $this->llenar_el_candado($e['implementacion']);
            default:
                return $this->arrancar_la_implementacion_despues_de_la_instalacion($e['implementacion']);
        }
    }

    /* ------------------------------------------------------------------------------------------
     | trigger_user_setup() directo
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Con cualquiera de las cuatro protecciones disparadas, el servicio NO llama: devuelve `ok = false`, el mensaje empieza con
     * `Frenado por el candado` y dice cuál fue, y no sale ningún pedido. Es lo que el servicio no hacía antes de la misión.
     *
     * @dataProvider protecciones
     *
     * @param string $proteccion Cómo se dispara.
     * @param string $nombre     Cómo aparece en el mensaje.
     *
     * @return void
     */
    public function test_el_punto_de_llamada_no_llama_con_una_proteccion_disparada(string $proteccion, string $nombre): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $impl    = $this->escenario_con($proteccion);

        $resultado = (new ImplementationUserSetupService())->trigger_user_setup($impl);

        $this->assertFalse($resultado['ok'], 'Llamó al sistema del cliente con ' . $proteccion . '.');
        $this->assertStringStartsWith(ImplementationUserSetupService::PREFIJO_FRENADO_POR_EL_CANDADO, $resultado['message']);
        $this->assertStringContainsString($nombre, $resultado['message']);
        $this->assertSame([], $sistema->pedidos, 'Salió un pedido al sistema del cliente: el candado no frenó.');
    }

    /**
     * 🔴 Con `$confirmado_por_una_persona = true` el candado no frena y el pedido sale, al destino normalizado.
     *
     * @dataProvider protecciones
     *
     * @param string $proteccion Cómo se dispara.
     *
     * @return void
     */
    public function test_confirmado_por_una_persona_el_punto_de_llamada_si_llama(string $proteccion): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $impl    = $this->escenario_con($proteccion);

        $resultado = (new ImplementationUserSetupService())->trigger_user_setup($impl, null, true);

        $this->assertTrue($resultado['ok'], $resultado['message']);
        $this->assertSame([$this->pedido_del_user_setup_en_shared()], $sistema->pedidos);
    }

    /**
     * Sin ninguna señal, el servicio llama como siempre: en shared con `/public`, en VPS sin él.
     *
     * @return void
     */
    public function test_sin_senales_el_punto_de_llamada_llama_como_siempre(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $shared  = $this->escenario();

        $this->assertTrue((new ImplementationUserSetupService())->trigger_user_setup($shared['implementacion'])['ok']);
        $this->assertSame([$this->pedido_del_user_setup_en_shared()], $sistema->pedidos);

        $sistema = $this->falsear_el_sistema_del_cliente();
        $vps     = $this->escenario(['hosting' => 'vps']);

        $this->assertTrue((new ImplementationUserSetupService())->trigger_user_setup($vps['implementacion'])['ok']);
        $this->assertSame(['POST ' . self::URL_API . '/api/admin-sync/user-setup'], $sistema->pedidos);
    }

    /**
     * El mensaje nombra TODAS las protecciones que fallan, en castellano corrido, y no llama.
     *
     * @return void
     */
    public function test_el_mensaje_nombra_todas_las_protecciones_que_fallan(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();

        $this->agregar_un_sistema_vivo($e['cliente']);
        $this->agregar_el_lead_con_el_setup($e['cliente'], 'exitoso');
        $impl = $this->llenar_el_candado($e['implementacion']);

        $resultado = (new ImplementationUserSetupService())->trigger_user_setup($impl);

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('(sin_sistema_vivo, lead_sin_user_setup y sin_aplicar_antes)', $resultado['message']);
        $this->assertSame([], $sistema->pedidos);
    }

    /**
     * La etapa NO es del punto de llamada (es de cada puerta): una implementación en la etapa 1 sin ninguna señal sigue llamando.
     * Hay llamadores y tests que le piden el servicio a una implementación que no está en la etapa 2.
     *
     * @return void
     */
    public function test_la_etapa_no_la_mira_el_punto_de_llamada(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['etapa' => 1]);

        $resultado = (new ImplementationUserSetupService())->trigger_user_setup($e['implementacion']);

        $this->assertTrue($resultado['ok'], $resultado['message']);
        $this->assertSame([$this->pedido_del_user_setup_en_shared()], $sistema->pedidos);
    }

    /**
     * Confirmar a mano NO habilita un host real desde un test: el freno del entorno (`Bloqueado: …`) sigue en pie y es otra
     * barrera, independiente del candado.
     *
     * @return void
     */
    public function test_confirmar_a_mano_no_habilita_un_host_real_en_un_test(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();

        ClientApi::where('id', $e['cliente']->active_client_api_id)->update(['url' => 'https://api-panchito.comerciocity.com']);
        $this->agregar_un_sistema_vivo($e['cliente']);

        $resultado = (new ImplementationUserSetupService())->trigger_user_setup($e['implementacion'], null, true);

        $this->assertFalse($resultado['ok']);
        $this->assertStringStartsWith(ImplementationUserSetupService::PREFIJO_BLOQUEADO, $resultado['message']);
        $this->assertSame([], $sistema->pedidos);
        $this->assertSame([], HttpFactorySinSalida::frenados(), 'El servicio tenía que negarse antes de armar el pedido.');
    }

    /* ------------------------------------------------------------------------------------------
     | El job de claude/* reconoce el frenado como "no corrió"
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Con un sistema vivo (o cualquier otra protección) que aparece DESPUÉS del despacho —el endpoint chequeó, el job esperó en
     * la cola—, el job no llama, deja el registro en `error` y dice que el setup NO corrió: `puede_haber_corrido = false`. Sin
     * reconocer el prefijo, el texto caía en "ante un texto que no se reconoce se asume que pudo haber corrido" y mandaba a conciliar
     * o reintentar algo que ni salió.
     *
     * @dataProvider protecciones_que_aparecen_en_la_cola
     *
     * @param string $proteccion Cómo se dispara.
     * @param string $nombre     Cómo aparece en el mensaje.
     *
     * @return void
     */
    public function test_el_job_frenado_por_el_candado_deja_error_y_dice_que_no_corrio(string $proteccion, string $nombre): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();

        /* Como la deja el endpoint antes de despachar: etapa 2 con el registro `en_curso` y el token del intento. */
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => self::TOKEN, 'terminado_at' => null, 'error' => null]]);

        /* Y DESPUÉS del despacho aparece la señal. */
        switch ($proteccion) {
            case 'sistema_vivo':
                $this->agregar_un_sistema_vivo($e['cliente']);
                break;
            case 'lead_exitoso':
                $this->agregar_el_lead_con_el_setup($e['cliente'], 'exitoso');
                break;
            default:
                $this->arrancar_la_implementacion_despues_de_la_instalacion($e['implementacion']);
        }

        (new EjecutarUserSetupDeImplementacionJob($e['implementacion']->id, self::TOKEN))->handle();

        $this->assertSame([], $sistema->pedidos, 'El job llamó al sistema del cliente con ' . $proteccion . '.');

        $registro = $this->data_de_la_etapa($e['implementacion']->refresh(), 2)['user_setup'];

        $this->assertSame('error', $registro['estado']);
        $this->assertFalse($registro['puede_haber_corrido'], 'Un setup frenado por el candado NO corrió: no puede decir que pudo haber corrido.');
        $this->assertStringContainsString(ImplementationUserSetupService::PREFIJO_FRENADO_POR_EL_CANDADO, $registro['error']);
        $this->assertStringContainsString($nombre, $registro['error']);
        $this->assertStringContainsString('no corrió', $registro['error']);
        $this->assertStringNotContainsString('conciliar', $registro['error'], 'Un setup que no salió no pide conciliar ni reintentar a ciegas.');
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at, 'Un setup que no salió no llena el candado.');
    }
}
