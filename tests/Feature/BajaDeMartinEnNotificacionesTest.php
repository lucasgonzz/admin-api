<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminPushSubscription;
use Database\Seeders\BajaDeMartinEnNotificacionesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Baja de Martín de todos los canales de aviso (2/9/2026).
 *
 * El seeder no busca a Martín: apaga los flags de destinatario en TODOS los admins y después se los
 * prende únicamente al de Lucas. Es lo que pidió Lucas y es más robusto que adivinar cómo está
 * escrito el nombre de alguien que ya no está.
 *
 * Lo que más importa probar acá es la GUARDA: si no encuentra a Lucas no tiene que apagar nada,
 * porque un sistema sin ningún setter deja todos los avisos de verificación y escalado colgando del
 * fallback de campanita.
 */
class BajaDeMartinEnNotificacionesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Saca de escena a los Lucas reales de la base del slot renombrándolos.
     *
     * No se borran: sus ids cuelgan de support_tickets, lead_messages y admin_task_assignees. El
     * renombre va adentro de la transacción de DatabaseTransactions y se revierte solo.
     *
     * @return void
     */
    private function esconder_a_los_lucas_reales(): void
    {
        Admin::whereIn('name', BajaDeMartinEnNotificacionesSeeder::NOMBRES_LUCAS)
            ->update(['name' => 'Preexistente del slot']);

        Admin::whereIn('email', BajaDeMartinEnNotificacionesSeeder::EMAILS_LUCAS)
            ->update(['email' => Str::uuid() . '@preexistente.local']);
    }

    /**
     * Crea un admin con todos los flags de destinatario encendidos.
     *
     * @param string $nombre
     * @param string $email
     *
     * @return Admin
     */
    private function crear_admin_con_todo_prendido(string $nombre, string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = $nombre;
        $admin->email    = $email;
        $admin->password = bcrypt('secret');

        foreach (BajaDeMartinEnNotificacionesSeeder::COLUMNAS_A_APAGAR as $columna) {
            $admin->{$columna} = true;
        }

        $admin->save();

        return $admin;
    }

    /**
     * Registra un device push para el admin.
     *
     * @param Admin $admin
     *
     * @return void
     */
    private function registrar_device(Admin $admin): void
    {
        $endpoint = 'https://web.push.apple.com/' . str_repeat('B', 300) . $admin->id;

        $sub                = new AdminPushSubscription();
        $sub->admin_id      = $admin->id;
        $sub->endpoint      = $endpoint;
        $sub->endpoint_hash = hash('sha256', $endpoint);
        $sub->p256dh        = 'p';
        $sub->auth          = 'a';
        $sub->save();
    }

    /**
     * A Martín se le apagan las nueve columnas de destinatario.
     *
     * @return void
     */
    public function test_apaga_las_nueve_columnas_de_martin()
    {
        $this->esconder_a_los_lucas_reales();

        $martin = $this->crear_admin_con_todo_prendido('Martin', 'baja-martin@test.local');
        $this->crear_admin_con_todo_prendido('Lucas', 'baja-lucas@test.local');

        $this->seed(BajaDeMartinEnNotificacionesSeeder::class);

        $martin->refresh();

        foreach (BajaDeMartinEnNotificacionesSeeder::COLUMNAS_A_APAGAR as $columna) {
            $this->assertFalse((bool) $martin->{$columna}, "Martín quedó con {$columna} encendido.");
        }
    }

    /**
     * A Martín se le borran los devices de push: es el canal que le sigue sonando en el teléfono.
     *
     * @return void
     */
    public function test_le_borra_los_devices_de_push_a_martin()
    {
        $this->esconder_a_los_lucas_reales();

        $martin = $this->crear_admin_con_todo_prendido('Martin', 'baja-martin2@test.local');
        $this->registrar_device($martin);
        $this->crear_admin_con_todo_prendido('Lucas', 'baja-lucas2@test.local');

        $this->assertSame(1, AdminPushSubscription::where('admin_id', $martin->id)->count());

        $this->seed(BajaDeMartinEnNotificacionesSeeder::class);

        $this->assertSame(0, AdminPushSubscription::where('admin_id', $martin->id)->count());
    }

    /**
     * 🔴 El registro de admin NO se borra: su id cuelga de tickets, mensajes y tareas.
     *
     * @return void
     */
    public function test_no_borra_el_registro_de_admin_de_martin()
    {
        $this->esconder_a_los_lucas_reales();

        $martin = $this->crear_admin_con_todo_prendido('Martin', 'baja-martin3@test.local');
        $this->crear_admin_con_todo_prendido('Lucas', 'baja-lucas3@test.local');

        $this->seed(BajaDeMartinEnNotificacionesSeeder::class);

        $this->assertNotNull(Admin::find($martin->id), 'Se borró el registro de admin de Martín.');
    }

    /**
     * Lucas queda con el rol de setter y los dos "por defecto", y conserva su device.
     *
     * @return void
     */
    public function test_deja_a_lucas_como_setter_y_le_conserva_el_device()
    {
        $this->esconder_a_los_lucas_reales();

        $this->crear_admin_con_todo_prendido('Martin', 'baja-martin4@test.local');

        $lucas = $this->crear_admin_con_todo_prendido('Lucas', 'baja-lucas4@test.local');
        $this->registrar_device($lucas);

        $this->seed(BajaDeMartinEnNotificacionesSeeder::class);

        $lucas->refresh();

        foreach (BajaDeMartinEnNotificacionesSeeder::COLUMNAS_A_PRENDER_EN_LUCAS as $columna) {
            $this->assertTrue((bool) $lucas->{$columna}, "Lucas quedó sin {$columna}.");
        }

        $this->assertSame(
            1,
            AdminPushSubscription::where('admin_id', $lucas->id)->count(),
            'Se le borró el device a Lucas.'
        );
    }

    /**
     * 🔴 Sin Lucas en la base, el seeder no apaga NADA.
     *
     * Es la guarda que importa: apagar los flags de todos y después no poder reasignarlos dejaría el
     * sistema sin ningún setter, y con eso los avisos de verificación y escalado caerían en el
     * fallback de campanita. Un seeder que corre a medias es peor que uno que no corre.
     *
     * @return void
     */
    public function test_sin_lucas_en_la_base_no_apaga_nada()
    {
        $this->esconder_a_los_lucas_reales();

        $martin = $this->crear_admin_con_todo_prendido('Martin', 'baja-martin5@test.local');

        $this->seed(BajaDeMartinEnNotificacionesSeeder::class);

        $martin->refresh();

        foreach (BajaDeMartinEnNotificacionesSeeder::COLUMNAS_A_APAGAR as $columna) {
            $this->assertTrue(
                (bool) $martin->{$columna},
                "Se apagó {$columna} sin haber encontrado a Lucas: el sistema queda sin setter."
            );
        }
    }

    /**
     * Correrlo dos veces deja el mismo estado que correrlo una.
     *
     * @return void
     */
    public function test_es_idempotente_al_correrlo_dos_veces()
    {
        $this->esconder_a_los_lucas_reales();

        $martin = $this->crear_admin_con_todo_prendido('Martin', 'baja-martin6@test.local');
        $lucas  = $this->crear_admin_con_todo_prendido('Lucas', 'baja-lucas6@test.local');

        $this->seed(BajaDeMartinEnNotificacionesSeeder::class);
        $this->seed(BajaDeMartinEnNotificacionesSeeder::class);

        $martin->refresh();
        $lucas->refresh();

        $this->assertFalse((bool) $martin->es_setter);
        $this->assertTrue((bool) $lucas->es_setter);
    }

    /**
     * El closer no pierde su rol ni su device: sigue trabajando.
     *
     * @return void
     */
    public function test_no_le_toca_el_rol_ni_el_device_al_closer()
    {
        $this->esconder_a_los_lucas_reales();

        $closer            = $this->crear_admin_con_todo_prendido('Tommy', 'baja-closer@test.local');
        $closer->is_closer = true;
        $closer->save();
        $this->registrar_device($closer);

        $this->crear_admin_con_todo_prendido('Lucas', 'baja-lucas7@test.local');

        $this->seed(BajaDeMartinEnNotificacionesSeeder::class);

        $closer->refresh();

        $this->assertTrue((bool) $closer->is_closer, 'El closer perdió su rol.');
        $this->assertSame(
            1,
            AdminPushSubscription::where('admin_id', $closer->id)->count(),
            'Se le borró el device al closer.'
        );
    }
}
