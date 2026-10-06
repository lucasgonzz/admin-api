<?php

namespace Tests\Feature\PuertasDelUserSetup;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;
use App\Models\Implementation;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\ClaudeImplementaciones\BaseDeImplementaciones;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * Andamiaje común de los tests de las PUERTAS del user setup (misión `puertas-del-user-setup`, 6/10/2026).
 *
 * 🔴 El user setup de un cliente hace `migrate:fresh` del otro lado, y el admin llega a ese endpoint por cuatro puertas: el job de
 * `claude/implementations/{id}/user-setup`, el modo automático de la conversación, el botón `user_setup` del panel y el botón de
 * LEADS. Todas pasan por el mismo candado (`UserSetupCandadoService`), y estos tests fijan que ninguna llame al sistema de un
 * cliente que ya opera.
 *
 * Todo se arma con los constructores de `BaseDeImplementaciones` (la misma forma que producen los servicios reales) y con hosts
 * `.test`: una fixture con un dominio real es una URL real, y un test que llegó a llamarla (5/10/2026) le vació la base de
 * producción a un cliente. Los tests de las puertas que SÍ tienen que llamar lo hacen con su `Http::fake()`; los que no, afirman que
 * no salió ningún pedido.
 */
abstract class BaseDeLasPuertasDelUserSetup extends BaseDeImplementaciones
{
    /** URL de la API activa del cliente de prueba, SIN `/public` (como las que escribe el alta). El host es `.test`. */
    const URL_API = 'https://api-panchito.ejemplo.test';

    /** El nombre del negocio del cliente de prueba (`company_name`): lo que el panel pide escribir para confirmar. */
    const NEGOCIO = 'Panchito S.A.';

    /**
     * Una implementación lista para configurar, tal como la deja el camino normal: el cliente con su `setup_data`, las dos APIs
     * (la 1 activa), la implementación en la etapa pedida con el formulario enviado y la instalación real completada en la API
     * activa (creada DESPUÉS del arranque de la implementación).
     *
     * @param array<string, mixed> $opciones `etapa` (default 2), `automation_mode` (manual|auto, default manual), `hosting`
     *                                       (shared_hosting|vps, default shared_hosting), `formulario` (bool, default true: llena
     *                                       `form_submitted_at`), `instalacion` (bool, default true: la instalación completada),
     *                                       `setup_data` (se suma al del formulario), `sin_datos_del_formulario` (bool: el cliente no cargó nada,
     *                                       `setup_data` vacío) y `cliente` (atributos del cliente a pisar).
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    protected function escenario(array $opciones = []): array
    {
        // El cliente, con el nombre del negocio que se usa para confirmar.
        $cliente = $this->crear_cliente('Panchito Gómez', array_merge(
            ['company_name' => self::NEGOCIO],
            isset($opciones['cliente']) ? $opciones['cliente'] : []
        ));

        // Con `sin_datos_del_formulario` el cliente NO cargó nada: el `setup_data` queda vacío (la etapa 1 pudo completarse con "Avanzar etapa").
        $cliente->setup_data = ! empty($opciones['sin_datos_del_formulario']) ? [] : array_merge([
            'company_name'    => self::NEGOCIO,
            'email'           => 'panchito@ejemplo.test',
            'doc_number'      => '20304050607',
            'use_price_lists' => true,
            'price_lists'     => "Minorista\nMayorista",
            'use_deposits'    => false,
            'iva_included'    => true,
        ], isset($opciones['setup_data']) ? $opciones['setup_data'] : []);
        $cliente->save();

        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito', isset($opciones['hosting']) ? $opciones['hosting'] : 'shared_hosting');

        // La API activa apunta a una URL `.test` SIN `/public`, como las que escribe el alta.
        ClientApi::where('id', $cliente->active_client_api_id)->update(['url' => self::URL_API]);

        $implementacion = $this->crear_implementacion($cliente->refresh(), [
            'automation_mode' => isset($opciones['automation_mode']) ? $opciones['automation_mode'] : 'manual',
        ]);
        $implementacion = $this->llevar_a_la_etapa($implementacion, isset($opciones['etapa']) ? (int) $opciones['etapa'] : 2);

        if (! isset($opciones['formulario']) || $opciones['formulario']) {
            $implementacion->form_submitted_at = now();
            $implementacion->save();
        }

        if (! isset($opciones['instalacion']) || $opciones['instalacion']) {
            $this->crear_instalacion($cliente, ['status' => 'completada', 'kind' => 'completa']);
        }

        return ['cliente' => $cliente->refresh(), 'implementacion' => $implementacion->refresh()];
    }

    /**
     * Falsea al sistema del cliente: cualquier pedido HTTP lo atiende este fake con ese status, y queda anotado. Es lo que permite
     * afirmar "no salió ningún pedido" (`$registro->pedidos === []`) o "salió exactamente este".
     *
     * Parte de una fábrica nueva en cada llamada: con `Http::fake()` los stubs se ACUMULAN y gana el primero que matchea, así que
     * un test que falsea varias veces (un escenario distinto por vuelta) se quedaría con el primero.
     *
     * @param int $status Status con el que contesta el sistema del cliente.
     *
     * @return \stdClass Con `pedidos`: lista de `"MÉTODO url"` de lo que salió, y `cuerpos`: el cuerpo de cada uno.
     */
    protected function falsear_el_sistema_del_cliente(int $status = 200): \stdClass
    {
        // Lo que salió: se llena cuando el fake atiende un pedido.
        $registro          = new \stdClass();
        $registro->pedidos = [];
        $registro->cuerpos = [];

        Http::swap(new HttpFactorySinSalida());

        Http::fake(function ($pedido) use ($registro, $status) {
            $registro->pedidos[] = $pedido->method() . ' ' . $pedido->url();
            $registro->cuerpos[] = $pedido->data();

            return Http::response(['ok' => $status < 400], $status);
        });

        return $registro;
    }

    /**
     * El pedido que tiene que salir cuando el user setup SÍ se aplica a un cliente de shared hosting de esta fixture.
     *
     * @return string
     */
    protected function pedido_del_user_setup_en_shared(): string
    {
        return 'POST ' . self::URL_API . '/public/api/admin-sync/user-setup';
    }

    /**
     * Le agrega al cliente una actualización registrada (`client_version_upgrades`): la señal de "sistema vivo".
     *
     * @param Client $cliente El cliente.
     *
     * @return ClientVersionUpgrade
     */
    protected function agregar_un_sistema_vivo(Client $cliente): ClientVersionUpgrade
    {
        return ClientVersionUpgrade::create([
            'client_id'     => $cliente->id,
            'to_version_id' => $this->crear_version()->id,
            'status'        => 'pendiente',
        ]);
    }

    /**
     * Le agrega al cliente el lead del que salió, con el user setup en ese estado (el camino de LEADS lo escribe).
     *
     * @param Client $cliente El cliente.
     * @param string $estado  `pendiente`, `ejecutandose`, `exitoso`, `fallido` o `sin_confirmar`.
     *
     * @return Lead
     */
    protected function agregar_el_lead_con_el_setup(Client $cliente, string $estado): Lead
    {
        return $this->crear_lead(['promoted_client_id' => $cliente->id, 'user_setup_status' => $estado, 'status' => 'cerrado_ganado']);
    }

    /**
     * Llena el candado de la implementación (`user_setup_executed_at`): el user setup ya se aplicó.
     *
     * @param Implementation $implementacion La implementación.
     *
     * @return Implementation La implementación refrescada.
     */
    protected function llenar_el_candado(Implementation $implementacion): Implementation
    {
        $implementacion->user_setup_executed_at = now()->subDay();
        $implementacion->save();

        return $implementacion->refresh();
    }

    /**
     * Hace que la implementación haya arrancado DESPUÉS de la instalación completada: la instalación es de un sistema que ya
     * existía (instalado por afuera de este camino).
     *
     * @param Implementation $implementacion La implementación.
     *
     * @return Implementation La implementación refrescada.
     */
    protected function arrancar_la_implementacion_despues_de_la_instalacion(Implementation $implementacion): Implementation
    {
        DB::table('implementations')->where('id', $implementacion->id)->update(['started_at' => now()->addMinutes(30)]);

        return $implementacion->refresh();
    }
}
