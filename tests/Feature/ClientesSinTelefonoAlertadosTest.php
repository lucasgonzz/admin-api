<?php

namespace Tests\Feature;

use App\Console\Commands\CheckClientsWithoutPhone;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\AdminTask;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La alerta de clientes activos sin ningún teléfono cargado.
 *
 * El caso que justifica el comando es una falla silenciosa: si un cliente no tiene teléfono
 * en ninguna de las tres fuentes que mira el webhook, su mensaje de WhatsApp cae en el
 * pipeline de leads y nadie se entera. Lo que más importa verificar acá no es que alerte,
 * sino que NO alerte de más: un cliente con un empleado con teléfono, o con el lead del que
 * salió, el webhook lo resuelve igual y alertarlo sería ruido diario.
 */
class ClientesSinTelefonoAlertadosTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Deja dos admins: el dueño de soporte recibe la alerta, el otro figura como creador.
     *
     * @return Admin El dueño de soporte.
     */
    private function crear_admins(): Admin
    {
        $otro           = new Admin();
        $otro->name     = 'Otro admin';
        $otro->email    = 'otro-sin-telefono@test.local';
        $otro->password = bcrypt('secret');
        $otro->save();

        $owner                           = new Admin();
        $owner->name                     = 'Lucas';
        $owner->email                    = 'owner-sin-telefono@test.local';
        $owner->password                 = bcrypt('secret');
        $owner->is_default_support_owner = true;
        $owner->save();

        return $owner;
    }

    /**
     * Cliente activo con el teléfono de la ficha en el valor indicado.
     *
     * @param string $name  Razón social.
     * @param string $phone Teléfono de la ficha; vacío para dejarlo sin cargar.
     *
     * @return Client
     */
    private function crear_cliente(string $name, string $phone = ''): Client
    {
        $client            = new Client();
        $client->name      = $name;
        $client->phone     = $phone !== '' ? $phone : null;
        $client->is_active = true;
        $client->save();

        return $client;
    }

    /**
     * Corre el comando.
     *
     * @return void
     */
    private function correr(): void
    {
        $this->artisan('support:check-clients-without-phone')->assertExitCode(0);
    }

    /**
     * Tareas de alerta creadas para un cliente.
     *
     * @param string $client_name Razón social del cliente.
     *
     * @return int
     */
    private function tareas_de(string $client_name): int
    {
        return AdminTask::where('created_via', 'sin_telefono')
            ->where('title', 'like', '%' . $client_name . '%')
            ->count();
    }

    /**
     * Un cliente activo sin ningún teléfono genera la tarea de alerta.
     *
     * @return void
     */
    public function test_un_cliente_sin_ningun_telefono_genera_la_alerta()
    {
        $owner = $this->crear_admins();
        $this->crear_cliente('Distribuidora Pelada');

        $this->correr();

        $this->assertSame(1, $this->tareas_de('Distribuidora Pelada'), 'No se creó la tarea de alerta.');

        $task = AdminTask::where('created_via', 'sin_telefono')->latest('id')->first();
        $this->assertSame((int) $owner->id, (int) $task->assigned_admin_id, 'La alerta no quedó asignada al dueño de soporte.');
        $this->assertNotSame(
            (int) $owner->id,
            (int) $task->created_by_admin_id,
            'El creador es el mismo destinatario, así que el aviso in-app no le va a llegar.'
        );
    }

    /**
     * Un cliente con el teléfono en la ficha no se alerta.
     *
     * @return void
     */
    public function test_un_cliente_con_telefono_en_la_ficha_no_se_alerta()
    {
        $this->crear_admins();
        $this->crear_cliente('Distribuidora Con Telefono', '+5493411234567');

        $this->correr();

        $this->assertSame(0, $this->tareas_de('Distribuidora Con Telefono'));
    }

    /**
     * Un cliente sin teléfono propio pero con un empleado con teléfono no se alerta.
     *
     * @return void
     */
    public function test_un_cliente_con_empleado_con_telefono_no_se_alerta()
    {
        $this->crear_admins();
        $client = $this->crear_cliente('Distribuidora Con Empleado');

        $employee            = new ClientEmployee();
        $employee->client_id = $client->id;
        $employee->name      = 'Encargado';
        $employee->phone     = '+5493417654321';
        $employee->save();

        $this->correr();

        $this->assertSame(
            0,
            $this->tareas_de('Distribuidora Con Empleado'),
            'Se alertó un cliente que el webhook resuelve por el teléfono del empleado.'
        );
    }

    /**
     * Un cliente sin teléfono propio pero con el lead del que salió no se alerta.
     *
     * @return void
     */
    public function test_un_cliente_con_lead_promovido_con_telefono_no_se_alerta()
    {
        $this->crear_admins();
        $client = $this->crear_cliente('Distribuidora Del Lead');

        $lead                     = new Lead();
        $lead->contact_name       = 'Dueño';
        $lead->company_name       = 'Distribuidora Del Lead';
        $lead->phone              = '+5493411112222';
        $lead->status             = 'cliente';
        $lead->promoted_client_id = $client->id;
        $lead->save();

        $this->correr();

        $this->assertSame(
            0,
            $this->tareas_de('Distribuidora Del Lead'),
            'Se alertó un cliente que el webhook resuelve por el fallback del lead promovido.'
        );
    }

    /**
     * Un cliente inactivo no se alerta aunque no tenga teléfono.
     *
     * @return void
     */
    public function test_un_cliente_inactivo_no_se_alerta()
    {
        $this->crear_admins();
        $client            = $this->crear_cliente('Distribuidora Dada De Baja');
        $client->is_active = false;
        $client->save();

        $this->correr();

        $this->assertSame(0, $this->tareas_de('Distribuidora Dada De Baja'));
    }

    /**
     * Correr el comando dos veces seguidas no duplica la alerta.
     *
     * @return void
     */
    public function test_la_segunda_corrida_no_duplica_la_alerta()
    {
        $this->crear_admins();
        $this->crear_cliente('Distribuidora Repetida');

        $this->correr();
        $this->correr();

        $this->assertSame(
            1,
            $this->tareas_de('Distribuidora Repetida'),
            'La alerta se repitió en la segunda corrida.'
        );
    }

    /**
     * Si el cliente carga el teléfono, se olvida la marca y vuelve a alertar si se lo sacan.
     *
     * @return void
     */
    public function test_vuelve_a_alertar_si_le_sacan_el_telefono()
    {
        $this->crear_admins();
        $client = $this->crear_cliente('Distribuidora Ida Y Vuelta');

        $this->correr();
        $this->assertSame(1, $this->tareas_de('Distribuidora Ida Y Vuelta'));

        // Carga el teléfono: la marca de "ya alertado" tiene que borrarse.
        $client->phone = '+5493413334444';
        $client->save();
        $this->correr();

        $this->assertSame(
            '[]',
            (string) AdminSetting::get(CheckClientsWithoutPhone::KEY_ALERTED, ''),
            'La marca de alertado no se limpió cuando el cliente cargó el teléfono.'
        );

        // Se lo sacan de nuevo: tiene que volver a alertar sin esperar los 30 días.
        $client->phone = null;
        $client->save();
        $this->correr();

        $this->assertSame(
            2,
            $this->tareas_de('Distribuidora Ida Y Vuelta'),
            'No volvió a alertar después de que le sacaran el teléfono.'
        );
    }
}
