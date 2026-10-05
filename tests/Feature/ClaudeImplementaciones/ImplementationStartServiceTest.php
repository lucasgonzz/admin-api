<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Services\ImplementationConversationService;
use App\Services\ImplementationStartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * `ImplementationStartService`: la lógica de "así nace una implementación", extraída de
 * `ImplementationController::start()` (misión `implementar-cliente`, 5/10/2026).
 *
 * Lo que fija `InicioDeImplementacionDelPanelTest` es lo que hace el BOTÓN. Acá se fija lo que
 * agrega el servicio para el otro llamador, `POST claude/implementations`: el parámetro
 * `$forzar_manual`.
 *
 *  1. 🔴 Con `$forzar_manual = true` la implementación nace SIEMPRE en `manual` y NO sale la
 *     plantilla de bienvenida, aunque el setting global diga `auto`. En modo `auto` el webhook de
 *     WhatsApp conversa solo con el cliente; operando desde Claude cada mensaje lo aprueba Lucas.
 *  2. Sin el parámetro (el panel) todo sigue como siempre: el setting manda y, en `auto`, sale la
 *     plantilla.
 *  3. Un fallo del envío de la bienvenida no tumba el alta: es best-effort.
 */
class ImplementationStartServiceTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::whereIn('key', ['implementation_assigned_admin_id', 'implementation_automation_mode'])->delete();
        AdminSetting::flush_memo();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        AdminSetting::flush_memo();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Cliente sin implementación.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client               = new Client();
        $client->name         = 'Doña Rosa';
        $client->company_name = 'Almacén Rosa';
        $client->slug         = 'rosa-' . Str::random(6);
        $client->phone        = '+5493415551111';
        $client->is_active    = true;
        $client->save();

        return $client->refresh();
    }

    /**
     * Reemplaza el servicio de conversación por un doble y devuelve las veces que se pidió la
     * bienvenida.
     *
     * @param \Closure|null $comportamiento Qué hace el doble al pedirle la bienvenida.
     *
     * @return \stdClass Con `llamadas`: int.
     */
    private function espiar_la_bienvenida(\Closure $comportamiento = null): \stdClass
    {
        $registro           = new \stdClass();
        $registro->llamadas = 0;

        $doble = Mockery::mock(ImplementationConversationService::class);
        $doble->shouldReceive('send_welcome_template')->andReturnUsing(function () use ($registro, $comportamiento) {
            $registro->llamadas++;

            if ($comportamiento !== null) {
                $comportamiento();
            }
        });

        $this->app->instance(ImplementationConversationService::class, $doble);

        return $registro;
    }

    /**
     * Sin parámetro y sin setting: manual, sin bienvenida, con sus ocho etapas.
     *
     * @return void
     */
    public function test_por_defecto_nace_manual_y_sin_bienvenida(): void
    {
        $registro = $this->espiar_la_bienvenida();
        $client   = $this->crear_cliente();

        $implementation = (new ImplementationStartService())->start($client);

        $this->assertInstanceOf(Implementation::class, $implementation);
        $this->assertSame('manual', $implementation->automation_mode);
        $this->assertSame('in_progress', $implementation->status);
        $this->assertSame(1, (int) $implementation->current_stage);
        $this->assertSame(36, strlen((string) $implementation->form_token));
        $this->assertSame(8, ImplementationStage::where('implementation_id', $implementation->id)->count());
        $this->assertSame(0, $registro->llamadas);
    }

    /**
     * 2. Sin el parámetro, el setting `auto` manda: nace en auto y sale la bienvenida (como el panel).
     *
     * @return void
     */
    public function test_sin_el_parametro_el_setting_auto_manda_y_sale_la_bienvenida(): void
    {
        AdminSetting::set('implementation_automation_mode', 'auto');
        $registro = $this->espiar_la_bienvenida();

        $implementation = (new ImplementationStartService())->start($this->crear_cliente());

        $this->assertSame('auto', $implementation->automation_mode);
        $this->assertSame(1, $registro->llamadas);
    }

    /**
     * 1. 🔴 Con `$forzar_manual`, el setting `auto` NO cuenta: nace manual y no se manda nada.
     *
     * @return void
     */
    public function test_con_forzar_manual_el_setting_auto_no_cuenta(): void
    {
        AdminSetting::set('implementation_automation_mode', 'auto');
        $registro = $this->espiar_la_bienvenida();

        $implementation = (new ImplementationStartService())->start($this->crear_cliente(), true);

        $this->assertSame('manual', $implementation->automation_mode);
        $this->assertSame('manual', Implementation::find($implementation->id)->automation_mode, 'Lo guardado en la base también tiene que ser manual.');
        $this->assertSame(0, $registro->llamadas, 'Con forzar_manual no puede salir la plantilla de bienvenida.');
    }

    /**
     * 3. Si la bienvenida revienta, el alta sigue: la implementación y sus etapas quedan creadas.
     *
     * @return void
     */
    public function test_un_fallo_de_la_bienvenida_no_tumba_el_alta(): void
    {
        AdminSetting::set('implementation_automation_mode', 'auto');
        $registro = $this->espiar_la_bienvenida(function () {
            throw new \RuntimeException('Meta no contesta');
        });

        $implementation = (new ImplementationStartService())->start($this->crear_cliente());

        $this->assertSame(1, $registro->llamadas);
        $this->assertNotNull(Implementation::find($implementation->id));
        $this->assertSame(8, ImplementationStage::where('implementation_id', $implementation->id)->count());
    }

    /**
     * El admin asignado sale del setting, con o sin forzar_manual (no tiene nada que ver con el modo).
     *
     * @return void
     */
    public function test_el_admin_asignado_sale_del_setting_aunque_se_fuerce_manual(): void
    {
        /* Un admin de verdad: la columna tiene una foreign key a `admins`. */
        $admin           = new Admin();
        $admin->name     = 'Martín';
        $admin->email    = 'martin+' . Str::random(10) . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        AdminSetting::set('implementation_assigned_admin_id', (string) $admin->id);

        $implementation = (new ImplementationStartService())->start($this->crear_cliente(), true);

        $this->assertSame((int) $admin->id, (int) $implementation->assigned_admin_id);
    }
}
