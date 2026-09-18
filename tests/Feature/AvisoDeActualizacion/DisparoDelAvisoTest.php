<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Jobs\EnviarAvisoDeActualizacionJob;
use App\Mail\ClientVersionUpgradeMail;
use App\Models\ClientUpgradeNotice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * Cuándo se dispara el aviso, y las dos cosas que no puede hacer: duplicar y romper el upgrade.
 */
class DisparoDelAvisoTest extends BaseDelAviso
{
    /**
     * Cerrar un upgrade deja la fila del aviso y encola el envío. La fila se escribe en el hook
     * —es una sola inserción y es lo que deduplica—; todo lo que sale a la red queda para el job.
     *
     * @return void
     */
    public function test_cerrar_un_upgrade_registra_el_aviso_y_encola_el_envio()
    {
        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');
        $upgrade = $this->crear_upgrade($client, [$version]);

        $this->assertDatabaseMissing('client_upgrade_notices', [
            'client_version_upgrade_id' => $upgrade->id,
        ]);

        $upgrade->update(['status' => 'terminada']);

        $aviso = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first();

        $this->assertNotNull($aviso, 'cerrar el upgrade deja la fila del aviso');
        $this->assertSame((int) $client->id, (int) $aviso->client_id);
        $this->assertSame(ClientUpgradeNotice::ESTADO_PENDIENTE, $aviso->estado);
        $this->assertSame('dueno@ejemplo.test', $aviso->email, 'copia la casilla que ya tenía la ficha');
        $this->assertNull($aviso->mail_enviado_at, 'el hook no manda nada: eso es del job');

        Queue::assertPushed(EnviarAvisoDeActualizacionJob::class, function ($job) {
            /* La conexión tiene que ser la explícita: con la default (`sync`) el job correría
               INLINE adentro del request que cierra el upgrade, que es lo que vino a evitar. */
            return $job->connection === EnviarAvisoDeActualizacionJob::CONEXION_DE_COLA;
        });
    }

    /**
     * Un `save()` que no mueve el status a `terminada` no dispara nada.
     *
     * @return void
     */
    public function test_un_save_cualquiera_no_dispara_el_aviso()
    {
        $client  = $this->crear_cliente();
        $version = $this->crear_version();
        $upgrade = $this->crear_upgrade($client, [$version]);

        $upgrade->update(['status' => 'actualizandose']);
        $upgrade->update(['notes' => 'una nota cualquiera']);

        $this->assertDatabaseMissing('client_upgrade_notices', [
            'client_version_upgrade_id' => $upgrade->id,
        ]);

        Queue::assertNotPushed(EnviarAvisoDeActualizacionJob::class);
    }

    /**
     * 🔴 Cerrar DOS veces el mismo upgrade manda un solo mail.
     *
     * Devolverlo a `actualizandose` y volver a cerrarlo desde la grilla del admin-spa dispara el
     * hook otra vez, y sin la unicidad de (actualización, cliente) el dueño recibiría dos mails
     * contándole la misma actualización.
     *
     * @return void
     */
    public function test_cerrar_dos_veces_el_mismo_upgrade_no_manda_dos_mails()
    {
        Mail::fake();

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');
        $upgrade = $this->crear_upgrade($client, [$version]);

        $upgrade->update(['status' => 'terminada']);
        $this->servicio()->avisar($upgrade->fresh());

        Mail::assertSent(ClientVersionUpgradeMail::class, 1);

        /* La segunda pasada del hook: el ciclo completo que hace un operador en la grilla. */
        $upgrade->update(['status' => 'actualizandose']);
        $upgrade->update(['status' => 'terminada']);

        $this->assertNull(
            $this->servicio()->avisar($upgrade->fresh()),
            'el segundo aviso no tiene nada que hacer'
        );

        Mail::assertSent(ClientVersionUpgradeMail::class, 1);

        $this->assertSame(
            1,
            ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->count(),
            'una sola fila por actualización y cliente'
        );
    }

    /**
     * 🔴 Sin casilla en ningún lado no sale ni mail ni WhatsApp, y el upgrade IGUAL queda
     * `terminada`. El aviso es un extra; el upgrade es el trabajo.
     *
     * @return void
     */
    public function test_sin_casilla_no_sale_nada_y_el_upgrade_igual_queda_terminada()
    {
        Mail::fake();
        /* El cliente contesta que no tiene mail cargado: `contacto` con el email vacío. */
        Http::fake([
            '*contacto-dueno*' => Http::response(['contacto' => ['email' => null, 'name' => 'Dueño']], 200),
        ]);

        $client  = $this->crear_cliente(['email' => null]);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');
        $upgrade = $this->crear_upgrade($client, [$version]);

        $upgrade->update(['status' => 'terminada']);

        $this->assertSame('terminada', $upgrade->fresh()->status, 'el upgrade cerró igual');

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertNotNull($aviso);
        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_MAIL, $aviso->estado);
        $this->assertNull($aviso->mail_enviado_at);
        $this->assertNull($aviso->whatsapp_enviado_at);
        $this->assertNotNull($aviso->error, 'la fila dice por qué no salió');

        Mail::assertNothingSent();

        $this->assertSame(
            0,
            $this->whatsapp->cuantos_envios(),
            'sin mail no hay WhatsApp: el mensaje dice "te mandamos un mail"'
        );

        $this->assertSame('terminada', $upgrade->fresh()->status);
    }

    /**
     * Sin novedades para ese cliente el mail NO se manda: uno que anuncia una actualización y
     * abajo no dice nada es peor que ninguno.
     *
     * @return void
     */
    public function test_sin_novedades_no_se_manda_el_mail()
    {
        Mail::fake();

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $version = $this->crear_version();
        /* Versión sin ninguna novedad cargada. */
        $upgrade = $this->crear_upgrade($client, [$version]);

        $upgrade->update(['status' => 'terminada']);

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_NOVEDADES, $aviso->estado);
        $this->assertNull($aviso->mail_enviado_at);

        Mail::assertNothingSent();
        $this->assertSame(0, $this->whatsapp->cuantos_envios());
    }
}
