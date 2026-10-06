<?php

namespace Tests\Feature\PuertasDelUserSetup;

use App\Models\ImplementationStage;
use App\Models\Implementation;
use App\Services\ImplementationActionService;

/**
 * Puerta 3 del user setup: el botón `user_setup` del PANEL con `force` (misión `puertas-del-user-setup`, 6/10/2026).
 *
 * 🔴 `ImplementationActionService::execute()` (el botón del panel) tenía un `force` que saltaba el candado de "ya se aplicó"
 * y NADA más: sin `force` y con el candado vacío no miraba si el sistema ya operaba, y con `force` bastaba apretar "Aplicar de
 * nuevo" para que el sistema del cliente corriera `migrate:fresh` otra vez. Ahora:
 *  - los DUROS (formulario, etapa 2 o más, última instalación completada, nada en curso) no se saltean ni con `force`;
 *  - las protecciones que fallan (candado lleno, sistema vivo, user setup de leads, instalación anterior, etapa mayor a 2) se saltean
 *    SOLO con `force` + el nombre del cliente (`confirm_client_name`, `codigo: confirmacion_requerida`) y, si hay señales de que el
 *    sistema está EN USO, reconociéndolo (`confirm_live_system`, `codigo: falta_confirmar_sistema_en_uso`);
 *  - los booleanos se leen estrictos: el texto "false" no fuerza;
 *  - `state()` expone los datos de esa confirmación (claves aditivas: un SPA viejo las ignora).
 *
 * Es el CONTRATO con admin-spa, que se construye en paralelo contra el plan: los nombres de las claves no se cambian.
 */
class BotonDelPanelDelUserSetupTest extends BaseDeLasPuertasDelUserSetup
{
    /* ------------------------------------------------------------------------------------------
     | Helpers
     |----------------------------------------------------------------------------------------- */

    /**
     * El estado del botón `user_setup` tal como lo recibe el panel (`GET implementation/{id}/actions`).
     *
     * @param Implementation $implementacion La implementación.
     *
     * @return array<string, mixed> El item `user_setup` de `actions`.
     */
    private function estado_del_boton(Implementation $implementacion): array
    {
        $estado = $this->actingAs($this->crear_admin(), 'sanctum')
            ->getJson('/api/admin/implementation/' . $implementacion->id . '/actions')
            ->assertStatus(200)
            ->json();

        foreach ($estado['actions'] as $accion) {
            if ($accion['key'] === 'user_setup') {
                return $accion;
            }
        }

        $this->fail('El estado del panel no trae la acción user_setup.');
    }

    /**
     * Aprieta el botón `user_setup` del panel.
     *
     * @param Implementation       $implementacion La implementación.
     * @param array<string, mixed> $cuerpo         Lo que manda el panel (`force`, `confirm_client_name`, `confirm_live_system`).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function apretar_el_boton(Implementation $implementacion, array $cuerpo = [])
    {
        return $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/implementation/' . $implementacion->id . '/actions/user_setup', $cuerpo);
    }

    /**
     * Cuántas veces quedó registrada la acción `user_setup` en las etapas de la implementación.
     *
     * @param Implementation $implementacion La implementación.
     *
     * @return array<int, array<string, mixed>> Las entradas `user_setup` de `data.actions`.
     */
    private function acciones_registradas(Implementation $implementacion): array
    {
        $entradas = [];

        foreach (ImplementationStage::where('implementation_id', $implementacion->id)->get() as $etapa) {
            $data = is_array($etapa->data) ? $etapa->data : [];

            foreach (isset($data['actions']) ? $data['actions'] : [] as $accion) {
                if ($accion['action'] === 'user_setup') {
                    $entradas[] = $accion;
                }
            }
        }

        return $entradas;
    }

    /* ------------------------------------------------------------------------------------------
     | state(): lo que le llega al panel
     |----------------------------------------------------------------------------------------- */

    /**
     * Normal (nada frena): no está bloqueado, no se puede forzar, y las claves nuevas están SIEMPRE presentes y vacías. El nombre que hay
     * que escribir ya viaja (el panel lo muestra solo en el re-aplicar).
     *
     * @return void
     */
    public function test_state_normal_trae_las_claves_nuevas_vacias(): void
    {
        $e     = $this->escenario();
        $boton = $this->estado_del_boton($e['implementacion']);

        $this->assertFalse($boton['blocked']);
        $this->assertNull($boton['blocked_reason']);
        $this->assertFalse($boton['can_force']);
        $this->assertNull($boton['executed_at']);

        $this->assertFalse($boton['force_requires_confirmation']);
        $this->assertSame(self::NEGOCIO, $boton['force_confirm_name']);
        $this->assertSame([], $boton['force_reasons']);
        $this->assertFalse($boton['live_system']);
        $this->assertSame([], $boton['live_system_reasons']);
    }

    /**
     * 🔴 Las claves nuevas son SOLO de `user_setup`: las demás acciones conservan exactamente las claves de siempre (un SPA viejo no ve nada
     * distinto) y las que ya existían en `user_setup` conservan nombre y tipo.
     *
     * @return void
     */
    public function test_las_claves_nuevas_son_solo_de_user_setup_y_las_viejas_no_cambian(): void
    {
        $e      = $this->escenario();
        $estado = $this->actingAs($this->crear_admin(), 'sanctum')
            ->getJson('/api/admin/implementation/' . $e['implementacion']->id . '/actions')
            ->assertStatus(200)
            ->json();

        $viejas = ['key', 'label', 'available', 'recipient_label', 'window_open', 'last_executed_at', 'blocked', 'blocked_reason', 'can_force', 'executed_at', 'typical_stage', 'kind'];
        $nuevas = ['force_requires_confirmation', 'force_confirm_name', 'force_reasons', 'live_system', 'live_system_reasons'];

        foreach ($estado['actions'] as $accion) {
            if ($accion['key'] === 'user_setup') {
                $this->assertSame(array_merge($viejas, $nuevas), array_keys($accion));

                continue;
            }

            $this->assertSame($viejas, array_keys($accion), 'La acción ' . $accion['key'] . ' ganó o perdió claves.');
        }
    }

    /**
     * El "re-aplicar" clásico (candado lleno y nada más): bloqueado pero forzable, con el texto DE SIEMPRE en `blocked_reason` (compatibilidad
     * con el panel viejo), sin señales de uso (pide solo el nombre) y con la razón en `force_reasons`.
     *
     * @return void
     */
    public function test_state_con_el_candado_lleno_es_el_reaplicar_clasico(): void
    {
        $e     = $this->escenario();
        $impl  = $this->llenar_el_candado($e['implementacion']);
        $boton = $this->estado_del_boton($impl);
        $fecha = $impl->user_setup_executed_at->format('d/m/Y H:i');

        $this->assertTrue($boton['blocked']);
        $this->assertTrue($boton['can_force']);
        $this->assertSame('El UserSetup ya se aplicó el ' . $fecha . '. Usá "Forzar" para volver a aplicarlo.', $boton['blocked_reason']);
        $this->assertNotNull($boton['executed_at']);

        $this->assertTrue($boton['force_requires_confirmation']);
        $this->assertSame(self::NEGOCIO, $boton['force_confirm_name']);
        $this->assertSame(['El user setup ya se aplicó el ' . $fecha . '.'], $boton['force_reasons']);
        $this->assertFalse($boton['live_system'], 'El candado lleno solo pide el nombre, no el reconocimiento de sistema en uso.');
        $this->assertSame([], $boton['live_system_reasons']);
    }

    /**
     * 🔴 Un sistema vivo con el candado VACÍO (lo que antes no se miraba): bloqueado, forzable, con la frase corta, y `live_system` en true
     * con la señal.
     *
     * @return void
     */
    public function test_state_con_un_sistema_vivo_pide_forzar_y_reconocer_el_uso(): void
    {
        $e = $this->escenario();

        $this->agregar_un_sistema_vivo($e['cliente']);

        $boton = $this->estado_del_boton($e['implementacion']);

        $this->assertTrue($boton['blocked']);
        $this->assertTrue($boton['can_force']);
        $this->assertSame('El sistema del cliente ya se configuró o ya opera (1 motivo): para aplicarlo de nuevo hay que forzarlo y confirmar.', $boton['blocked_reason']);
        $this->assertNull($boton['executed_at']);

        $this->assertTrue($boton['force_requires_confirmation']);
        $this->assertTrue($boton['live_system']);
        $this->assertCount(1, $boton['live_system_reasons']);
        $this->assertStringContainsString('sistema vivo', $boton['live_system_reasons'][0]);
        $this->assertSame($boton['live_system_reasons'], $boton['force_reasons'], 'Con un solo motivo, las razones del bloqueo y las señales son las mismas.');
    }

    /**
     * Con varias protecciones fallando la frase cuenta los motivos en plural.
     *
     * @return void
     */
    public function test_state_con_varios_motivos_los_cuenta(): void
    {
        $e = $this->escenario();

        $this->agregar_un_sistema_vivo($e['cliente']);
        $this->agregar_el_lead_con_el_setup($e['cliente'], 'exitoso');
        $impl = $this->llenar_el_candado($e['implementacion']);

        $boton = $this->estado_del_boton($impl);

        $this->assertSame('El sistema del cliente ya se configuró o ya opera (3 motivos): para aplicarlo de nuevo hay que forzarlo y confirmar.', $boton['blocked_reason']);
        $this->assertCount(3, $boton['force_reasons']);
        $this->assertCount(2, $boton['live_system_reasons'], 'El candado lleno no es una señal de uso: solo el sistema vivo y el user setup del lead.');
    }

    /**
     * Una implementación que ya pasó de la etapa 2 (con nada más) también pide forzar, pero sin señales de uso: pide solo el nombre.
     *
     * @return void
     */
    public function test_state_con_la_etapa_mayor_a_2_pide_forzar_pero_no_es_una_senal_de_uso(): void
    {
        $e     = $this->escenario(['etapa' => 3]);
        $boton = $this->estado_del_boton($e['implementacion']);

        $this->assertTrue($boton['blocked']);
        $this->assertTrue($boton['can_force']);
        // 🔴 Lo único que frena es la etapa: el texto no dice "ya se configuró o ya opera" (no hay nada configurado), dice la etapa.
        $this->assertSame('La implementación ya pasó de la etapa 2 (está en la etapa 3): el user setup solo se aplica en la etapa 2. Para aplicarlo igual hay que forzarlo y confirmar.', $boton['blocked_reason']);
        $this->assertStringContainsString('etapa 3', $boton['force_reasons'][0]);
        $this->assertFalse($boton['live_system']);
    }

    /**
     * Los DUROS: bloqueado, SIN forzar, con el texto de siempre del gate del panel. Cada uno por separado.
     *
     * @return void
     */
    public function test_state_con_un_duro_no_se_puede_forzar_y_dice_el_texto_de_siempre(): void
    {
        /* La instalación del cliente no terminó. */
        $e     = $this->escenario(['instalacion' => false]);
        $boton = $this->estado_del_boton($e['implementacion']);

        $this->assertTrue($boton['blocked']);
        $this->assertFalse($boton['can_force']);
        $this->assertFalse($boton['force_requires_confirmation']);
        $this->assertSame("El sistema todavía no terminó de instalarse (la instalación no está en 'completada').", $boton['blocked_reason']);

        /* Todavía no llegó a la etapa 2. */
        $e     = $this->escenario(['etapa' => 1]);
        $boton = $this->estado_del_boton($e['implementacion']);

        $this->assertFalse($boton['can_force']);
        $this->assertSame('La implementación todavía no avanzó a la Etapa 2.', $boton['blocked_reason']);

        /* El formulario no se completó (ni enviado, ni la etapa 1 completada). */
        $e = $this->escenario(['formulario' => false]);
        ImplementationStage::where('implementation_id', $e['implementacion']->id)->where('stage_number', 1)->update(['status' => 'in_progress']);

        $boton = $this->estado_del_boton($e['implementacion']);

        $this->assertFalse($boton['can_force']);
        $this->assertSame('Todavía no se completó el formulario (Etapa 1).', $boton['blocked_reason']);
    }

    /**
     * 🔴 Un user setup EN CURSO (el job de `claude/*` está corriendo) es un duro nuevo: dos `migrate:fresh` a la vez no tienen confirmación que
     * los habilite. Uno colgado (más de 45 minutos) no cuenta.
     *
     * @return void
     */
    public function test_un_user_setup_en_curso_es_un_duro_y_uno_colgado_no(): void
    {
        $e = $this->escenario();

        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->subMinutes(5)->toISOString(), 'terminado_at' => null, 'error' => null]]);

        $boton = $this->estado_del_boton($e['implementacion']);

        $this->assertTrue($boton['blocked']);
        $this->assertFalse($boton['can_force']);
        $this->assertStringContainsString('Ya hay un user setup en curso', $boton['blocked_reason']);

        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->subMinutes(90)->toISOString(), 'terminado_at' => null, 'error' => null]]);

        $this->assertFalse($this->estado_del_boton($e['implementacion'])['blocked'], 'Uno colgado no frena.');
    }

    /**
     * Si falla un duro Y una protección, manda el duro: no se puede forzar. (Antes `state()` decía `can_force` con el candado lleno aunque
     * `execute()` lo rechazara por el gate.)
     *
     * @return void
     */
    public function test_con_un_duro_y_una_proteccion_manda_el_duro(): void
    {
        $e    = $this->escenario(['instalacion' => false]);
        $impl = $this->llenar_el_candado($e['implementacion']);

        $boton = $this->estado_del_boton($impl);

        $this->assertFalse($boton['can_force']);
        $this->assertSame("El sistema todavía no terminó de instalarse (la instalación no está en 'completada').", $boton['blocked_reason']);
    }

    /* ------------------------------------------------------------------------------------------
     | execute(): el camino normal
     |----------------------------------------------------------------------------------------- */

    /**
     * Normal (nada frena): sale el pedido al destino normalizado, se llena el candado y se registra la acción (canal `panel`). La respuesta es
     * la de siempre: 200 con `result` y `model`.
     *
     * @return void
     */
    public function test_normal_aplica_cierra_el_candado_y_registra_la_accion(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();

        $respuesta = $this->apretar_el_boton($e['implementacion']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('result.ok', true);
        $respuesta->assertJsonPath('result.message', 'Configuración aplicada correctamente.');
        $this->assertNotNull($respuesta->json('model'));
        $this->assertSame([$this->pedido_del_user_setup_en_shared()], $sistema->pedidos);

        $this->assertNotNull($e['implementacion']->refresh()->user_setup_executed_at);

        $acciones = $this->acciones_registradas($e['implementacion']);
        $this->assertCount(1, $acciones);
        $this->assertSame('panel', $acciones[0]['canal']);
        $this->assertSame(2, $acciones[0]['stage']);
    }

    /**
     * Si el sistema del cliente contesta con error, no se llena el candado ni se registra la acción, y el 422 trae el motivo (como siempre).
     *
     * @return void
     */
    public function test_si_el_sistema_del_cliente_falla_no_cierra_el_candado(): void
    {
        $this->falsear_el_sistema_del_cliente(500);
        $e = $this->escenario();

        $respuesta = $this->apretar_el_boton($e['implementacion']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('status 500', $respuesta->json('message'));
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
        $this->assertSame([], $this->acciones_registradas($e['implementacion']));
    }

    /**
     * Quien llama a `execute()` con los parámetros de antes (los cinco primeros) sigue andando: los nuevos van al final y son opcionales.
     *
     * @return void
     */
    public function test_los_llamadores_viejos_con_cinco_parametros_siguen_andando(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();

        $resultado = (new ImplementationActionService())->execute($e['implementacion'], 'user_setup', null, null, false);

        $this->assertTrue($resultado['ok'], $resultado['message']);
        $this->assertSame([$this->pedido_del_user_setup_en_shared()], $sistema->pedidos);
    }

    /* ------------------------------------------------------------------------------------------
     | execute(): sin force
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Sin `force` y con un sistema vivo (y el candado vacío, que es lo que antes no se miraba): 422, ningún pedido, sin `codigo`.
     *
     * @return void
     */
    public function test_sin_force_con_un_sistema_vivo_no_llama(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();

        $this->agregar_un_sistema_vivo($e['cliente']);

        $respuesta = $this->apretar_el_boton($e['implementacion']);

        $respuesta->assertStatus(422);
        $this->assertArrayNotHasKey('codigo', $respuesta->json());
        $this->assertStringContainsString('sin_sistema_vivo', $respuesta->json('message'));
        $this->assertStringContainsString('forzarla y confirmar el nombre del cliente', $respuesta->json('message'));
        $this->assertSame([], $sistema->pedidos, 'El botón llamó al sistema de un cliente que ya opera sin que nadie forzara.');
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
    }

    /**
     * Sin `force` y con el candado lleno: el MISMO mensaje de siempre ("Reintentá con "Forzar" si necesitás re-aplicarlo.").
     *
     * @return void
     */
    public function test_sin_force_con_el_candado_lleno_dice_el_mensaje_de_siempre(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();
        $impl    = $this->llenar_el_candado($e['implementacion']);

        $respuesta = $this->apretar_el_boton($impl);

        $respuesta->assertStatus(422);
        $this->assertSame(
            'El UserSetup ya se aplicó el ' . $impl->user_setup_executed_at->format('d/m/Y H:i') . '. Reintentá con "Forzar" si necesitás re-aplicarlo.',
            $respuesta->json('message')
        );
        $this->assertArrayNotHasKey('codigo', $respuesta->json());
        $this->assertSame([], $sistema->pedidos);
    }

    /* ------------------------------------------------------------------------------------------
     | execute(): con force, la confirmación por nombre
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 `force` SIN nombre: 422 `confirmacion_requerida`, el mensaje nombra al cliente y manda a recargar el panel, ningún pedido, y el candado
     * no se toca. Es lo que recibe un panel viejo (una pestaña abierta sin recargar) que fuerza un re-aplicar: el viejo ya muestra
     * `response_data.message` en el modal, así que se ve el motivo.
     *
     * @return void
     */
    public function test_force_sin_nombre_pide_la_confirmacion_y_no_llama(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();
        $impl    = $this->llenar_el_candado($e['implementacion']);
        $antes   = $impl->user_setup_executed_at->toDateTimeString();

        $respuesta = $this->apretar_el_boton($impl, ['force' => true]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('codigo', 'confirmacion_requerida');
        $this->assertIsString($respuesta->json('message'));
        $this->assertStringContainsString(self::NEGOCIO, $respuesta->json('message'));
        $this->assertStringContainsString('recargá el panel: la versión nueva pide la confirmación', $respuesta->json('message'));
        $this->assertSame([], $sistema->pedidos, 'Forzó sin la confirmación por nombre.');
        $this->assertSame($antes, $impl->refresh()->user_setup_executed_at->toDateTimeString());
    }

    /**
     * Un nombre equivocado (o uno que no es un texto) tampoco confirma: 422 `confirmacion_requerida`, ningún pedido.
     *
     * @return void
     */
    public function test_force_con_el_nombre_equivocado_no_llama(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();
        $impl    = $this->llenar_el_candado($e['implementacion']);

        foreach (['Otro Negocio', 'Panchito', self::NEGOCIO . ' y Cia', '   ', ['x']] as $recibido) {
            $respuesta = $this->apretar_el_boton($impl, ['force' => true, 'confirm_client_name' => $recibido]);

            $respuesta->assertStatus(422);
            $respuesta->assertJsonPath('codigo', 'confirmacion_requerida');
        }

        $this->assertSame([], $sistema->pedidos);
    }

    /**
     * Solo el candado lleno (el "re-aplicar" clásico) + el nombre: LLAMA, sin pedir reconocer sistema en uso. El nombre se compara recortado y sin
     * distinguir mayúsculas. El candado se RE-ESTAMPA (fecha nueva) y se registra la acción.
     *
     * @return void
     */
    public function test_el_reaplicar_clasico_con_el_nombre_llama_y_re_estampa_el_candado(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();
        $impl    = $this->llenar_el_candado($e['implementacion']);
        $antes   = $impl->user_setup_executed_at->toDateTimeString();

        $respuesta = $this->apretar_el_boton($impl, ['force' => true, 'confirm_client_name' => '  ' . strtolower(self::NEGOCIO) . '  ']);

        $respuesta->assertStatus(200);
        $this->assertSame([$this->pedido_del_user_setup_en_shared()], $sistema->pedidos);
        $this->assertNotSame($antes, $impl->refresh()->user_setup_executed_at->toDateTimeString(), 'El candado no se re-estampó.');
        $this->assertCount(1, $this->acciones_registradas($impl));
    }

    /**
     * 🔴 Con señales de que el sistema está EN USO (un sistema vivo), `force` + el nombre NO alcanzan: 422 `falta_confirmar_sistema_en_uso`
     * con las señales en el mensaje, y ningún pedido.
     *
     * @return void
     */
    public function test_con_un_sistema_en_uso_el_nombre_no_alcanza(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();

        $this->agregar_un_sistema_vivo($e['cliente']);

        $respuesta = $this->apretar_el_boton($e['implementacion'], ['force' => true, 'confirm_client_name' => self::NEGOCIO]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('codigo', 'falta_confirmar_sistema_en_uso');
        $this->assertStringContainsString('sistema vivo', $respuesta->json('message'));
        $this->assertStringContainsString(self::NEGOCIO, $respuesta->json('message'));
        // Lo lee una persona: no nombra el parámetro de la API (el modal le muestra la casilla que tiene que tildar).
        $this->assertStringNotContainsString('confirm_live_system', $respuesta->json('message'));
        $this->assertSame([], $sistema->pedidos, 'Forzó sobre un sistema en uso sin reconocerlo.');
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
    }

    /**
     * Con TODO (force + el nombre + el reconocimiento) y un sistema vivo: LLAMA y llena el candado. Sigue siendo posible re-aplicar desde el
     * panel, con una persona mirando: es lo que pidió Lucas.
     *
     * @return void
     */
    public function test_con_todo_confirmado_se_puede_reaplicar_sobre_un_sistema_vivo(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();

        $this->agregar_un_sistema_vivo($e['cliente']);
        $this->agregar_el_lead_con_el_setup($e['cliente'], 'exitoso');

        $respuesta = $this->apretar_el_boton($e['implementacion'], ['force' => true, 'confirm_client_name' => self::NEGOCIO, 'confirm_live_system' => true]);

        $respuesta->assertStatus(200);
        $this->assertSame([$this->pedido_del_user_setup_en_shared()], $sistema->pedidos);
        $this->assertNotNull($e['implementacion']->refresh()->user_setup_executed_at);
        $this->assertCount(1, $this->acciones_registradas($e['implementacion']));
    }

    /**
     * Una implementación que ya pasó de la etapa 2 (con nada más) pide solo el nombre: no es una señal de uso.
     *
     * @return void
     */
    public function test_con_la_etapa_mayor_a_2_alcanza_con_el_nombre(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario(['etapa' => 3]);

        $sin_forzar = $this->apretar_el_boton($e['implementacion']);
        $sin_forzar->assertStatus(422);

        // 🔴 El mensaje dice la verdad: lo único que frena es la etapa, no hay nada configurado que "ya opere" (antes decía "ya se configuró o ya opera").
        $this->assertStringContainsString('ya pasó de la etapa 2 (está en la etapa 3)', $sin_forzar->json('message'));
        $this->assertStringNotContainsString('ya se configuró o ya opera', $sin_forzar->json('message'));

        $this->apretar_el_boton($e['implementacion'], ['force' => true])->assertStatus(422)->assertJsonPath('codigo', 'confirmacion_requerida');
        $this->assertSame([], $sistema->pedidos);

        $this->apretar_el_boton($e['implementacion'], ['force' => true, 'confirm_client_name' => self::NEGOCIO])->assertStatus(200);
        $this->assertSame([$this->pedido_del_user_setup_en_shared()], $sistema->pedidos);
    }

    /* ------------------------------------------------------------------------------------------
     | execute(): lo que no se saltea
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Los DUROS no se saltean con `force`, ni con el nombre, ni con el reconocimiento: 422 con el texto de siempre, ningún pedido y SIN `codigo`.
     *
     * @return void
     */
    public function test_los_duros_no_se_saltean_con_force(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $todo    = ['force' => true, 'confirm_client_name' => self::NEGOCIO, 'confirm_live_system' => true];

        /* La instalación no terminó. */
        $e = $this->escenario(['instalacion' => false]);
        $r = $this->apretar_el_boton($this->llenar_el_candado($e['implementacion']), $todo);

        $r->assertStatus(422);
        $this->assertSame("El sistema todavía no terminó de instalarse (la instalación no está en 'completada').", $r->json('message'));
        $this->assertArrayNotHasKey('codigo', $r->json());

        /* El formulario no se completó. */
        $e = $this->escenario(['formulario' => false]);
        ImplementationStage::where('implementation_id', $e['implementacion']->id)->where('stage_number', 1)->update(['status' => 'in_progress']);
        $r = $this->apretar_el_boton($e['implementacion'], $todo);

        $r->assertStatus(422);
        $this->assertSame('Todavía no se completó el formulario (Etapa 1).', $r->json('message'));

        /* Todavía no llegó a la etapa 2. */
        $e = $this->escenario(['etapa' => 1]);
        $r = $this->apretar_el_boton($e['implementacion'], $todo);

        $r->assertStatus(422);
        $this->assertSame('La implementación todavía no avanzó a la Etapa 2.', $r->json('message'));

        /* Hay un user setup en curso. */
        $e = $this->escenario();
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->subMinutes(5)->toISOString(), 'terminado_at' => null, 'error' => null]]);
        $r = $this->apretar_el_boton($e['implementacion'], $todo);

        $r->assertStatus(422);
        $this->assertStringContainsString('Ya hay un user setup en curso', $r->json('message'));

        $this->assertSame([], $sistema->pedidos, 'Un duro se saltó con force.');
    }

    /**
     * 🔴 Los booleanos se leen ESTRICTOS: `(bool) "false"` es `true`, y el texto "false" NO fuerza (ni confirma el sistema en uso). "true" sí.
     *
     * @return void
     */
    public function test_el_texto_false_no_fuerza_ni_confirma(): void
    {
        $sistema = $this->falsear_el_sistema_del_cliente();
        $e       = $this->escenario();
        $impl    = $this->llenar_el_candado($e['implementacion']);

        /* `force: "false"` no fuerza: es como no mandarlo, y lo único que falla es el candado: el mensaje de siempre. */
        $r = $this->apretar_el_boton($impl, ['force' => 'false', 'confirm_client_name' => self::NEGOCIO]);

        $r->assertStatus(422);
        $this->assertArrayNotHasKey('codigo', $r->json());
        $this->assertStringContainsString('Reintentá con "Forzar"', $r->json('message'));

        /* `force: "true"` fuerza, pero sin el nombre pide la confirmación. */
        $this->apretar_el_boton($impl, ['force' => 'true'])->assertStatus(422)->assertJsonPath('codigo', 'confirmacion_requerida');

        /* `confirm_live_system: "false"` no reconoce el sistema en uso. */
        $this->agregar_un_sistema_vivo($e['cliente']);
        $r = $this->apretar_el_boton($impl, ['force' => true, 'confirm_client_name' => self::NEGOCIO, 'confirm_live_system' => 'false']);

        $r->assertStatus(422);
        $r->assertJsonPath('codigo', 'falta_confirmar_sistema_en_uso');

        $this->assertSame([], $sistema->pedidos);
    }

    /**
     * El 422 que recibe un cliente VIEJO (un panel sin recargar que manda `force: true` sin nombre) trae `message`, que es lo que el modal viejo
     * ya muestra (`response_data.message`).
     *
     * @return void
     */
    public function test_el_422_del_cliente_viejo_trae_message(): void
    {
        $this->falsear_el_sistema_del_cliente();
        $e = $this->escenario();

        $respuesta = $this->apretar_el_boton($this->llenar_el_candado($e['implementacion']), ['force' => true]);

        $respuesta->assertStatus(422);
        $this->assertIsString($respuesta->json('message'));
        $this->assertNotSame('', $respuesta->json('message'));
    }

    /**
     * Las demás acciones no cambian: el `force` y las claves de la confirmación no les afectan, y las respuestas siguen siendo las de siempre.
     *
     * @return void
     */
    public function test_las_otras_acciones_no_cambian(): void
    {
        $e = $this->escenario();

        /* `crear_instalacion` con la instalación ya hecha: el mensaje de siempre, 200. */
        $r = $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/implementation/' . $e['implementacion']->id . '/actions/crear_instalacion', ['force' => true, 'confirm_client_name' => 'x']);

        $r->assertStatus(200);
        $r->assertJsonPath('result.message', 'La instalación de este cliente ya existía; no se creó una nueva.');

        /* Una acción inexistente: 422 con el mensaje de siempre. */
        $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/implementation/' . $e['implementacion']->id . '/actions/no_existe')
            ->assertStatus(422);
    }
}
