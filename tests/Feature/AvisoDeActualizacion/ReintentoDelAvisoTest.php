<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Mail\ClientVersionUpgradeMail;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientUpgradeNotice;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * El reintento a mano, que es la punta que destraba lo que el canal automático deja debiendo.
 *
 * 🔴 La unicidad de (actualización, cliente) es lo que evita el mail duplicado, y su precio es que
 * un aviso en `sin_mail` no se puede reabrir guardando el upgrade otra vez. La reapertura entra
 * por `aviso-actualizacion:reintentar`, y lo que hace segura esa reapertura es que el servicio
 * decide cada tramo por SU PROPIA fecha (`mail_enviado_at`, `whatsapp_enviado_at`) y no por el
 * `estado` de la fila.
 */
class ReintentoDelAvisoTest extends BaseDelAviso
{
    /**
     * El caso que motiva el comando: el aviso quedó `sin_mail`, alguien consigue la casilla, y el
     * reintento la guarda y manda.
     *
     * @return void
     */
    public function test_el_reintento_carga_la_casilla_y_manda_el_aviso()
    {
        Mail::fake();
        Http::fake(['*contacto-dueno*' => Http::response('', 404)]);

        $client = $this->crear_cliente(['email' => null]);
        $aviso  = $this->dejar_un_aviso_sin_mail($client);

        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_MAIL, $aviso->estado);
        Mail::assertNothingSent();

        /* Modo reporte: no escribe ni manda. */
        $this->artisan('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--email'   => 'conseguido@ejemplo.test',
        ])->assertExitCode(0);

        $this->assertNull($client->fresh()->email, 'sin --aplicar no se tocó la ficha');
        Mail::assertNothingSent();

        /* Ahora sí. */
        $this->artisan('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--email'   => 'conseguido@ejemplo.test',
            '--aplicar' => true,
        ])->assertExitCode(0);

        $this->assertSame('conseguido@ejemplo.test', $client->fresh()->email);

        $reintentado = $aviso->fresh();

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $reintentado->estado);
        $this->assertNotNull($reintentado->mail_enviado_at);
        $this->assertSame('conseguido@ejemplo.test', $reintentado->email);

        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
        Mail::assertSent(ClientVersionUpgradeMail::class, function ($mail) {
            return $mail->hasTo('conseguido@ejemplo.test');
        });
    }

    /**
     * Una casilla mal tipeada se rechaza antes de tocar nada.
     *
     * @return void
     */
    public function test_una_casilla_invalida_no_toca_nada()
    {
        Mail::fake();
        Http::fake(['*contacto-dueno*' => Http::response('', 404)]);

        $client = $this->crear_cliente(['email' => null]);
        $this->dejar_un_aviso_sin_mail($client);

        $this->artisan('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--email'   => 'esto no es un mail',
            '--aplicar' => true,
        ])->assertExitCode(1);

        $this->assertNull($client->fresh()->email);
        Mail::assertNothingSent();
    }

    /**
     * 🔴 Un aviso que ya salió entero no manda nada, por más veces que se reintente.
     *
     * @return void
     */
    public function test_reintentar_un_aviso_ya_enviado_no_manda_nada()
    {
        Mail::fake();
        Http::fake();

        AdminSetting::set(AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_NAME, 'cc_sistema_actualizado');

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');
        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertNotNull($aviso->mail_enviado_at);
        $this->assertNotNull($aviso->whatsapp_enviado_at, 'precondición: salió todo');
        Mail::assertSent(ClientVersionUpgradeMail::class, 1);

        $mando_antes = $this->whatsapp->cuantos_envios();

        $this->artisan('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--aplicar' => true,
        ])->assertExitCode(0);

        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
        $this->assertSame($mando_antes, $this->whatsapp->cuantos_envios(), 'no se repitió el WhatsApp');

        /* Y el reintento directo sobre la fila tampoco duplica. */
        $this->servicio()->reintentar($aviso->fresh());

        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
    }

    /**
     * 🔴 **El caso fino: el mail salió y el WhatsApp no.**
     *
     * Pasa todo el tiempo mientras la plantilla de Meta no esté creada. Ese aviso queda `enviado`
     * con `mail_enviado_at` puesto, `whatsapp_enviado_at` en null y el motivo en `error`. Al
     * reintentarlo —una vez cargada la plantilla— sale SOLO el WhatsApp: el mail no se repite,
     * porque no se puede desmandar.
     *
     * @return void
     */
    public function test_con_el_mail_mandado_y_el_whatsapp_debiendo_el_reintento_no_duplica_el_mail()
    {
        Mail::fake();
        Http::fake();

        /* Primera pasada: sin plantilla cargada y con la ventana cerrada. */
        $this->ventana->abierta = false;

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');
        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado);
        $this->assertNotNull($aviso->mail_enviado_at, 'el mail salió');
        $this->assertNull($aviso->whatsapp_enviado_at, 'el WhatsApp quedó debiendo');
        $this->assertNotNull($aviso->error, 'con el motivo escrito');

        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
        $this->assertSame(0, $this->whatsapp->cuantos_envios());

        /* Ahora sí existe la plantilla en Meta y alguien la carga. */
        AdminSetting::set(AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_NAME, 'cc_sistema_actualizado');

        $this->artisan('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--aplicar' => true,
        ])->assertExitCode(0);

        $reintentado = $aviso->fresh();

        $this->assertNotNull($reintentado->whatsapp_enviado_at, 'ahora sí salió el WhatsApp');
        $this->assertSame('wamid.DEPRUEBA', $reintentado->whatsapp_message_id);
        $this->assertNull($reintentado->error, 'y la nota de lo que faltaba se limpió');

        $this->assertSame(
            1,
            Mail::sent(ClientVersionUpgradeMail::class)->count(),
            '🔴 el mail NO se volvió a mandar'
        );

        $this->assertSame(
            $aviso->mail_enviado_at->toDateTimeString(),
            $reintentado->mail_enviado_at->toDateTimeString(),
            'la fecha del mail original no se pisó'
        );

        $this->assertCount(1, $this->whatsapp->plantillas);
    }

    /**
     * El reporte sin opciones no escribe nada y lista lo que está debiendo.
     *
     * @return void
     */
    public function test_el_reporte_sin_opciones_no_escribe_nada()
    {
        Mail::fake();
        Http::fake(['*contacto-dueno*' => Http::response('', 404)]);

        $client = $this->crear_cliente(['email' => null]);
        $aviso  = $this->dejar_un_aviso_sin_mail($client);

        $this->artisan('aviso-actualizacion:reintentar')->assertExitCode(0);

        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_MAIL, $aviso->fresh()->estado);
        Mail::assertNothingSent();
    }

    /**
     * Un cliente que no existe corta con código 1 y sin tocar nada.
     *
     * @return void
     */
    public function test_un_cliente_inexistente_corta()
    {
        $this->artisan('aviso-actualizacion:reintentar', [
            '--cliente' => 'no-existe-este-slug',
            '--aplicar' => true,
        ])->assertExitCode(1);
    }

    /**
     * Deja un aviso en `sin_mail`: cliente sin casilla y su sistema contestando 404.
     *
     * @param Client $client
     *
     * @return ClientUpgradeNotice
     */
    private function dejar_un_aviso_sin_mail(Client $client): ClientUpgradeNotice
    {
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');

        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        return $this->servicio()->avisar($upgrade->fresh());
    }
}
