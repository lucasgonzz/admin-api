<?php

namespace Tests\Feature\PerfilSoloLeads;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * Canales de broadcasting del perfil «Sólo Leads» (misión embudo-whatsapp-precios, E5; decisión del
 * Coordinador sobre el pendiente que dejó el autor de los tests de aceptación).
 *
 * Por qué existe este archivo: `POST /broadcasting/auth` pasa por el middleware `admin.solo_leads`, pero eso
 * sólo decide si el perfil puede PEDIR autorización. Quién puede escuchar cada canal lo deciden los callbacks
 * de `routes/channels.php`, y `.env.testing` usa `BROADCAST_DRIVER=log`, que no firma nada: por HTTP no se
 * puede probar. Entonces se llama directo al verificador del broadcaster registrado (el mismo que usa
 * `/broadcasting/auth` con Pusher), con un pedido cuyo usuario es el admin en prueba.
 *
 * Regla de la decisión: un admin con el perfil sólo escucha su `admin.{id}` y
 * `verificacion-agendamiento-alerts`; todos los demás canales, incluso uno que se agregue mañana, le dan
 * acceso denegado. Un admin normal conserva exactamente el comportamiento de hoy.
 *
 * No toca la base: los admins son modelos en memoria (los callbacks sólo leen atributos).
 */
class CanalesDeBroadcastingDelPerfilTest extends TestCase
{
    /** Id del admin en prueba. */
    private const ID_DEL_ADMIN = 7;

    /**
     * Admin en memoria con el flag dado.
     *
     * @param bool|null $solo_leads Valor del flag, o null para no definirlo (admin «de antes»).
     * @param bool      $is_closer  Si es closer (el canal `closer-alerts` lo exige).
     *
     * @return Admin
     */
    private function crear_admin(?bool $solo_leads, bool $is_closer = false): Admin
    {
        $admin = new Admin(['name' => 'Admin en memoria', 'is_closer' => $is_closer]);

        if ($solo_leads !== null) {
            $admin->solo_leads = $solo_leads;
        }
        $admin->id = self::ID_DEL_ADMIN;

        return $admin;
    }

    /**
     * Broadcaster registrado (el del driver por defecto) con sus canales de `routes/channels.php`.
     *
     * @return object
     */
    private function broadcaster()
    {
        return Broadcast::driver();
    }

    /**
     * Pide autorización de un canal como el admin dado. Devuelve true si la concede y false si responde
     * acceso denegado (la excepción que `/broadcasting/auth` convierte en 403).
     *
     * @param Admin  $admin Usuario del pedido.
     * @param string $canal Nombre del canal sin el prefijo `private-`.
     *
     * @return bool
     */
    private function autoriza(Admin $admin, string $canal): bool
    {
        $pedido = Request::create('/broadcasting/auth', 'POST');
        $pedido->setUserResolver(function () use ($admin) {
            return $admin;
        });

        $metodo = new \ReflectionMethod($this->broadcaster(), 'verifyUserCanAccessChannel');
        $metodo->setAccessible(true);

        try {
            $metodo->invoke($this->broadcaster(), $pedido, $canal);

            return true;
        } catch (AccessDeniedHttpException $excepcion) {
            return false;
        }
    }

    /**
     * Nombres concretos de todos los canales registrados (cada marcador `{x}` pasa a ser el id del admin
     * en prueba, así el canal propio coincide). Si mañana se agrega un canal, aparece acá solo.
     *
     * @return array<int, string>
     */
    private function canales_registrados(): array
    {
        $propiedad = new \ReflectionProperty(\Illuminate\Broadcasting\Broadcasters\Broadcaster::class, 'channels');
        $propiedad->setAccessible(true);

        $nombres = [];
        foreach (array_keys($propiedad->getValue($this->broadcaster())) as $patron) {
            $nombres[] = preg_replace('/\{[^}]+\}/', (string) self::ID_DEL_ADMIN, $patron);
        }

        return $nombres;
    }

    /**
     * El perfil escucha su canal propio y el de verificación por agendamiento (los dos que lista el plan).
     *
     * @return void
     */
    public function test_el_perfil_escucha_su_canal_y_el_de_agendamiento(): void
    {
        $restringido = $this->crear_admin(true);

        $this->assertTrue($this->autoriza($restringido, 'admin.' . self::ID_DEL_ADMIN), 'Su admin.{id} tiene que seguir autorizado.');
        $this->assertTrue($this->autoriza($restringido, 'verificacion-agendamiento-alerts'));
    }

    /**
     * El perfil NO escucha el canal de otro admin ni ninguno de los demás canales, aunque el flag
     * `is_closer` o el id coincidan (acá `App.Models.User.{id}` coincide con su id y aun así se niega).
     *
     * @return void
     */
    public function test_el_perfil_no_escucha_ningun_otro_canal(): void
    {
        $restringido = $this->crear_admin(true, true);

        $this->assertFalse($this->autoriza($restringido, 'admin.' . (self::ID_DEL_ADMIN + 1)), 'El admin.{id} de otro admin.');
        $this->assertFalse($this->autoriza($restringido, 'closer-alerts'), 'closer-alerts, incluso siendo is_closer.');
        $this->assertFalse($this->autoriza($restringido, 'deployment.123'), 'deployment.{upgradeId}.');
        $this->assertFalse($this->autoriza($restringido, 'App.Models.User.' . self::ID_DEL_ADMIN), 'App.Models.User.{id}.');
    }

    /**
     * Cerrado por defecto: de TODOS los canales registrados, el perfil sólo recibe los dos permitidos.
     * Un canal nuevo que se agregue a `routes/channels.php` rompe acá hasta que alguien lo decida.
     *
     * @return void
     */
    public function test_los_unicos_canales_que_concede_al_perfil_son_los_dos_permitidos(): void
    {
        $restringido = $this->crear_admin(true, true);

        $concedidos = [];
        foreach ($this->canales_registrados() as $canal) {
            if ($this->autoriza($restringido, $canal)) {
                $concedidos[] = $canal;
            }
        }
        sort($concedidos);

        $esperados = ['admin.' . self::ID_DEL_ADMIN, 'verificacion-agendamiento-alerts'];
        sort($esperados);

        $this->assertGreaterThanOrEqual(5, count($this->canales_registrados()), 'Guarda anti-vacuidad: hoy hay 5 canales registrados.');
        $this->assertSame($esperados, $concedidos);
    }

    /**
     * Admin normal (con el flag en false o sin definir): el comportamiento de hoy, sin cambios. GUARDA DE
     * REGRESIÓN: verde antes y después del cambio.
     *
     * @return void
     */
    public function test_un_admin_normal_conserva_todos_sus_canales(): void
    {
        foreach ([false, null] as $valor_del_flag) {
            $normal = $this->crear_admin($valor_del_flag, true);

            $this->assertTrue($this->autoriza($normal, 'admin.' . self::ID_DEL_ADMIN));
            $this->assertFalse($this->autoriza($normal, 'admin.' . (self::ID_DEL_ADMIN + 1)), 'Nunca el canal de otro admin.');
            $this->assertTrue($this->autoriza($normal, 'verificacion-agendamiento-alerts'));
            $this->assertTrue($this->autoriza($normal, 'closer-alerts'), 'Un closer sigue escuchando closer-alerts.');
            $this->assertTrue($this->autoriza($normal, 'deployment.123'));
            $this->assertTrue($this->autoriza($normal, 'App.Models.User.' . self::ID_DEL_ADMIN));
        }

        $no_closer = $this->crear_admin(false, false);
        $this->assertFalse($this->autoriza($no_closer, 'closer-alerts'), 'Quien no es closer sigue sin closer-alerts.');
    }
}
