<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Jobs\EnviarAvisoDeActualizacionJob;
use App\Mail\ClientVersionUpgradeMail;
use App\Models\Client;
use App\Models\ClientUpgradeNotice;
use App\Models\ClientVersionUpgrade;
use App\Models\Implementation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * Un cliente con la implementación ABIERTA no recibe el aviso de actualización.
 *
 * 🔴 Desde el 14/10/2026 al cliente se le muestra el sistema una sola vez, en la entrega, ya con su
 * información cargada. Antes de esa entrega se lo actualiza a la última versión, y un mail que
 * dice "te actualizamos el sistema" a alguien que todavía no lo recibió no tiene sentido.
 *
 * "Abierta" es `implementations.status = in_progress`. El aviso se registra igual —queda la fila
 * en `omitido`, que es lo que le dice a quien mire que fue a propósito— pero no se encola nada, y
 * es terminal: ni el hook ni `aviso-actualizacion:reintentar` lo vuelven a abrir.
 */
class ClienteEnImplementacionTest extends BaseDelAviso
{
    /**
     * Cerrar el upgrade de un cliente en implementación deja la fila `omitido` con el motivo y
     * NO encola el job.
     *
     * @return void
     */
    public function test_cerrar_el_upgrade_de_un_cliente_en_implementacion_deja_el_aviso_omitido_y_no_encola_nada()
    {
        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $upgrade = $this->upgrade_con_novedad($client);
        $this->abrir_implementacion($client, 'in_progress');

        $upgrade->update(['status' => 'terminada']);

        $aviso = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first();

        $this->assertNotNull($aviso, 'queda la fila: es lo que dice que se omitió a propósito');
        $this->assertSame(ClientUpgradeNotice::ESTADO_OMITIDO, $aviso->estado);
        $this->assertSame(
            'El cliente está en implementación: todavía no recibió su sistema',
            $aviso->error
        );
        $this->assertNull($aviso->mail_enviado_at);
        $this->assertNull($aviso->whatsapp_enviado_at);

        Queue::assertNotPushed(EnviarAvisoDeActualizacionJob::class);

        /* Y el upgrade en sí cerró bien: el aviso es un extra. */
        $this->assertSame('terminada', $upgrade->fresh()->status);
    }

    /**
     * 🔴 Ni mail ni WhatsApp, aunque alguien llame al servicio a mano.
     *
     * @return void
     */
    public function test_avisar_a_un_cliente_en_implementacion_no_manda_ni_mail_ni_whatsapp()
    {
        Mail::fake();
        Http::fake();

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $upgrade = $this->upgrade_con_novedad($client);
        $this->abrir_implementacion($client, 'in_progress');
        $upgrade->update(['status' => 'terminada']);

        $this->assertNull($this->servicio()->avisar($upgrade->fresh()), 'no hay nada que hacer');

        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertSame(0, $this->whatsapp->cuantos_envios());
        $this->assertSame(
            1,
            ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->count()
        );
    }

    /**
     * Implementación cerrada, sin arrancar, en pausa o inexistente: el aviso sale como siempre.
     *
     * @return void
     */
    public function test_con_la_implementacion_cerrada_o_sin_implementacion_el_aviso_sale_como_siempre()
    {
        foreach (['completed', 'pending', 'paused', null] as $status) {
            Mail::fake();
            Queue::fake();

            $client  = $this->crear_cliente(['email' => 'dueno-' . ($status ?? 'sin') . '@ejemplo.test']);
            $upgrade = $this->upgrade_con_novedad($client);

            if ($status !== null) {
                $this->abrir_implementacion($client, $status);
            }

            $upgrade->update(['status' => 'terminada']);

            $aviso = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first();
            $etiqueta = $status ?? 'sin implementación';

            $this->assertSame(ClientUpgradeNotice::ESTADO_PENDIENTE, $aviso->estado, $etiqueta);
            Queue::assertPushed(EnviarAvisoDeActualizacionJob::class, 1);

            $this->servicio()->avisar($upgrade->fresh());

            $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->fresh()->estado, $etiqueta);
            Mail::assertSent(ClientVersionUpgradeMail::class, 1);
        }
    }

    /**
     * La implementación abierta es de OTRO cliente: a este no le afecta.
     *
     * @return void
     */
    public function test_la_implementacion_abierta_de_otro_cliente_no_afecta_a_este()
    {
        $en_implementacion = $this->crear_cliente(['email' => 'uno@ejemplo.test']);
        $this->abrir_implementacion($en_implementacion, 'in_progress');

        $otro    = $this->crear_cliente(['email' => 'otro@ejemplo.test']);
        $upgrade = $this->upgrade_con_novedad($otro);
        $upgrade->update(['status' => 'terminada']);

        $this->assertSame(
            ClientUpgradeNotice::ESTADO_PENDIENTE,
            ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first()->estado
        );
        Queue::assertPushed(EnviarAvisoDeActualizacionJob::class, 1);
    }

    /**
     * 🔴 El `omitido` es terminal: cerrar de nuevo el mismo upgrade no lo reabre, ni siquiera
     * después de la entrega.
     *
     * @return void
     */
    public function test_el_omitido_es_terminal_y_cerrar_otra_vez_el_upgrade_no_lo_reabre()
    {
        Mail::fake();

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $upgrade = $this->upgrade_con_novedad($client);
        $implementacion = $this->abrir_implementacion($client, 'in_progress');

        $upgrade->update(['status' => 'terminada']);

        /* Se entrega el sistema y la implementación se cierra. */
        $implementacion->update(['status' => 'completed']);

        /* Un operador devuelve el upgrade y lo vuelve a cerrar desde la grilla. */
        $upgrade->update(['status' => 'actualizandose']);
        $upgrade->update(['status' => 'terminada']);

        $this->assertNull($this->servicio()->avisar($upgrade->fresh()));

        $filas = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->get();

        $this->assertCount(1, $filas);
        $this->assertSame(ClientUpgradeNotice::ESTADO_OMITIDO, $filas->first()->estado);
        Mail::assertNothingSent();
        Queue::assertNotPushed(EnviarAvisoDeActualizacionJob::class);
    }

    /**
     * Una fila que ya estaba `pendiente` y el cliente entró en implementación antes de que el job
     * la trabajara: se omite en vez de salir.
     *
     * @return void
     */
    public function test_un_pendiente_cuyo_cliente_entra_en_implementacion_antes_del_job_se_omite()
    {
        Mail::fake();

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $upgrade = $this->upgrade_con_novedad($client);

        /* El hook crea la fila `pendiente` y encola. */
        $upgrade->update(['status' => 'terminada']);
        $aviso = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first();
        $this->assertSame(ClientUpgradeNotice::ESTADO_PENDIENTE, $aviso->estado);

        /* Antes de que corra el job se abre la implementación. */
        $this->abrir_implementacion($client, 'in_progress');

        $this->assertNull($this->servicio()->avisar($upgrade->fresh()));

        $this->assertSame(ClientUpgradeNotice::ESTADO_OMITIDO, $aviso->fresh()->estado);
        Mail::assertNothingSent();
        $this->assertSame(0, $this->whatsapp->cuantos_envios());
    }

    /**
     * Lo que ya salió, salió: un aviso `enviado` no se pisa con `omitido` porque después se abra
     * una implementación.
     *
     * @return void
     */
    public function test_un_aviso_ya_enviado_no_se_pisa_si_despues_se_abre_una_implementacion()
    {
        Mail::fake();

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $upgrade = $this->upgrade_con_novedad($client);
        $upgrade->update(['status' => 'terminada']);
        $this->servicio()->avisar($upgrade->fresh());

        $this->abrir_implementacion($client, 'in_progress');

        $this->assertNull($this->servicio()->avisar($upgrade->fresh()));

        $this->assertSame(
            ClientUpgradeNotice::ESTADO_ENVIADO,
            ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first()->estado
        );
        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
    }

    /**
     * 🔴 El reporte de `aviso-actualizacion:reintentar` no lista los `omitido`, y reintentar al
     * cliente —con o sin casilla nueva— no manda nada ni los cambia.
     *
     * @return void
     */
    public function test_el_comando_de_reintento_no_levanta_los_omitidos()
    {
        Mail::fake();
        Http::fake(['*contacto-dueno*' => Http::response('', 404)]);

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $upgrade = $this->upgrade_con_novedad($client);
        $implementacion = $this->abrir_implementacion($client, 'in_progress');
        $upgrade->update(['status' => 'terminada']);

        $aviso = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first();
        $this->assertSame(ClientUpgradeNotice::ESTADO_OMITIDO, $aviso->estado);

        /* El reporte: no hay nada esperando, y no nombra al aviso omitido. */
        $this->assertSame(0, Artisan::call('aviso-actualizacion:reintentar'));
        $salida = Artisan::output();

        $this->assertStringContainsString('No hay ningún aviso esperando el mail', $salida);
        $this->assertStringContainsString('No hay ningún aviso con el mail mandado y el WhatsApp debiendo', $salida);
        $this->assertStringNotContainsString($client->slug, $salida);

        /* Después de la entrega, reintentar a mano: sigue sin tocarlo. */
        $implementacion->update(['status' => 'completed']);

        $this->assertSame(0, Artisan::call('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--aplicar' => true,
        ]));
        $this->assertStringContainsString('no tiene ningún aviso pendiente', Artisan::output());

        $this->assertSame(0, Artisan::call('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--email'   => 'conseguido@ejemplo.test',
            '--aplicar' => true,
        ]));

        $despues = $aviso->fresh();

        $this->assertSame(ClientUpgradeNotice::ESTADO_OMITIDO, $despues->estado);
        $this->assertNull($despues->mail_enviado_at);
        $this->assertNull($despues->whatsapp_enviado_at);
        Mail::assertNothingSent();
        $this->assertSame(0, $this->whatsapp->cuantos_envios());
    }

    /**
     * Ni llamando al servicio directo con la fila omitida se manda algo.
     *
     * @return void
     */
    public function test_reintentar_directo_sobre_un_omitido_no_manda_nada()
    {
        Mail::fake();

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $upgrade = $this->upgrade_con_novedad($client);
        $this->abrir_implementacion($client, 'in_progress');
        $upgrade->update(['status' => 'terminada']);

        $aviso = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first();

        $resultado = $this->servicio()->reintentar($aviso);

        $this->assertSame(ClientUpgradeNotice::ESTADO_OMITIDO, $resultado->estado);
        Mail::assertNothingSent();
        $this->assertSame(0, $this->whatsapp->cuantos_envios());
    }

    /**
     * 🔴 El hueco del reintento: el aviso quedó `sin_mail` (o `error`) cuando el cliente todavía no
     * estaba en implementación, y DESPUÉS la abrió. El reintento a mano no pasa por `registrar()`
     * y tiene que respetar la regla igual.
     *
     * @return void
     */
    public function test_reintentar_un_aviso_debiendo_el_mail_de_un_cliente_que_entro_en_implementacion_no_manda_nada()
    {
        Mail::fake();
        Http::fake(['*contacto-dueno*' => Http::response('', 404)]);

        $client  = $this->crear_cliente(['email' => null]);
        $upgrade = $this->upgrade_con_novedad($client);
        $upgrade->update(['status' => 'terminada']);
        $this->servicio()->avisar($upgrade->fresh());

        $aviso = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first();
        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_MAIL, $aviso->estado);

        $this->abrir_implementacion($client, 'in_progress');

        $this->assertSame(0, Artisan::call('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--email'   => 'conseguido@ejemplo.test',
            '--aplicar' => true,
        ]));

        $despues = $aviso->fresh();

        $this->assertSame(ClientUpgradeNotice::ESTADO_OMITIDO, $despues->estado);
        $this->assertNull($despues->mail_enviado_at);
        Mail::assertNothingSent();
        $this->assertSame(0, $this->whatsapp->cuantos_envios());

        /* Lo mismo con un aviso que quedó en `error`, por el servicio. */
        $otro         = $this->crear_cliente(['email' => 'otro@ejemplo.test']);
        $upgrade_otro = $this->upgrade_con_novedad($otro);
        $upgrade_otro->update(['status' => 'terminada']);
        $aviso_error = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade_otro->id)->first();
        $aviso_error->update(['estado' => ClientUpgradeNotice::ESTADO_ERROR, 'error' => 'se cayó']);
        $this->abrir_implementacion($otro, 'in_progress');

        $resultado = $this->servicio()->reintentar($aviso_error->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_OMITIDO, $resultado->estado);
        Mail::assertNothingSent();
    }

    /**
     * Si el mail del aviso ya salió, la implementación abierta no lo da vuelta: lo que salió, salió.
     *
     * @return void
     */
    public function test_el_reintento_no_pisa_un_aviso_cuyo_mail_ya_salio()
    {
        Mail::fake();

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $upgrade = $this->upgrade_con_novedad($client);
        $upgrade->update(['status' => 'terminada']);
        $this->servicio()->avisar($upgrade->fresh());

        $aviso = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first();
        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado);

        $this->abrir_implementacion($client, 'in_progress');

        $resultado = $this->servicio()->reintentar($aviso->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $resultado->estado);
        $this->assertNotNull($resultado->mail_enviado_at);
        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
    }

    /**
     * Upgrade `pendiente` del cliente, con una versión que trae una novedad.
     *
     * @param Client $client
     *
     * @return ClientVersionUpgrade
     */
    private function upgrade_con_novedad(Client $client): ClientVersionUpgrade
    {
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');

        return $this->crear_upgrade($client, [$version]);
    }

    /**
     * Le abre al cliente su implementación con el estado indicado.
     *
     * @param Client $client
     * @param string $status pending | in_progress | completed | paused.
     *
     * @return Implementation
     */
    private function abrir_implementacion(Client $client, string $status): Implementation
    {
        return Implementation::create([
            'client_id' => $client->id,
            'status'    => $status,
        ]);
    }
}
