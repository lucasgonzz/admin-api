<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Mail\ClientVersionUpgradeMail;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientUpgradeNotice;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Artisan;
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
     * El reporte sin opciones no escribe nada Y lista lo que está debiendo.
     *
     * Las dos mitades importan y hasta ahora solo se aseveraba la primera: un reporte que no
     * escribe nada pero tampoco dice nada cumpliría el test y no serviría para nada, que es
     * justamente el caso que se busca evitar cuando alguien pregunta "a quién le falta el mail".
     *
     * @return void
     */
    public function test_el_reporte_sin_opciones_no_escribe_nada_y_lista_lo_que_falta()
    {
        Mail::fake();
        Http::fake(['*contacto-dueno*' => Http::response('', 404)]);

        $client = $this->crear_cliente(['email' => null]);
        $aviso  = $this->dejar_un_aviso_sin_mail($client);

        $this->assertSame(0, Artisan::call('aviso-actualizacion:reintentar'));

        $salida = Artisan::output();

        /* No escribió ni mando nada. */
        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_MAIL, $aviso->fresh()->estado);
        Mail::assertNothingSent();
        $this->assertSame(0, $this->whatsapp->cuantos_envios());

        /* Y lo dijo: el bloque, el aviso, el cliente y el estado. */
        $this->assertStringContainsString('sin mandar el mail', $salida);
        $this->assertStringContainsString('#' . $aviso->id, $salida);
        $this->assertStringContainsString($client->slug, $salida);
        $this->assertStringContainsString(ClientUpgradeNotice::ESTADO_SIN_MAIL, $salida);
        $this->assertStringContainsString('--aplicar', $salida);
    }

    /**
     * 🔴 **Un aviso trabado en `pendiente` aparece en el reporte.**
     *
     * El job tiene `$tries = 1` y no tiene `failed()`, y el propio `Console/Kernel.php` documenta
     * que el scheduler se muere por ratos: si el `queue:work` no corre, la fila se queda en
     * `pendiente` para siempre. Antes esa fila no entraba en ninguna consulta del comando y el
     * reporte imprimía "No hay ningún aviso esperando el mail" con el dueño sin enterarse de nada.
     *
     * @return void
     */
    public function test_el_reporte_lista_los_pendientes_que_la_cola_no_levanto()
    {
        Mail::fake();
        Http::fake();

        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $aviso  = $this->dejar_un_aviso_pendiente($client);

        $this->envejecer($aviso, ClientUpgradeNotice::MINUTOS_PARA_SOSPECHAR_DEL_WORKER + 1);

        $this->assertSame(0, Artisan::call('aviso-actualizacion:reintentar'));

        $salida = Artisan::output();

        $this->assertStringContainsString('esperando a la cola hace rato', $salida);
        $this->assertStringContainsString('#' . $aviso->id, $salida);
        $this->assertStringContainsString($client->slug, $salida);
        $this->assertStringContainsString('scheduler', $salida);

        /* Reportar no manda nada. */
        Mail::assertNothingSent();
        $this->assertSame(ClientUpgradeNotice::ESTADO_PENDIENTE, $aviso->fresh()->estado);
    }

    /**
     * Un `pendiente` recién creado NO aparece: ese lo tiene la cola y se trabaja en segundos.
     * Listarlo sería llenar el reporte de ruido justo cuando todo está funcionando.
     *
     * @return void
     */
    public function test_un_pendiente_recién_creado_no_aparece_en_el_reporte()
    {
        Mail::fake();
        Http::fake();

        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $this->dejar_un_aviso_pendiente($client);

        $this->assertSame(0, Artisan::call('aviso-actualizacion:reintentar'));

        $salida = Artisan::output();

        $this->assertStringNotContainsString('esperando a la cola hace rato', $salida);
        $this->assertStringNotContainsString($client->slug, $salida);
    }

    /**
     * 🔴 Y `--aplicar` lo puede mandar: es la única salida que tiene un aviso al que la cola
     * nunca levantó.
     *
     * @return void
     */
    public function test_el_reintento_a_mano_levanta_un_pendiente_trabado()
    {
        Mail::fake();
        Http::fake();

        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $aviso  = $this->dejar_un_aviso_pendiente($client);

        $this->envejecer($aviso, ClientUpgradeNotice::MINUTOS_PARA_SOSPECHAR_DEL_WORKER + 1);

        $this->artisan('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--aplicar' => true,
        ])->assertExitCode(0);

        $reintentado = $aviso->fresh();

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $reintentado->estado);
        $this->assertNotNull($reintentado->mail_enviado_at);

        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
    }

    /**
     * Un `enviando` colgado —el worker lo reclamó y se murió— también se levanta a mano.
     *
     * @return void
     */
    public function test_el_reintento_a_mano_levanta_un_reclamo_colgado()
    {
        Mail::fake();
        Http::fake();

        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $aviso  = $this->dejar_un_aviso_pendiente($client);

        ClientUpgradeNotice::where('id', $aviso->id)->update([
            'estado'              => ClientUpgradeNotice::ESTADO_ENVIANDO,
            'dispatch_started_at' => now()->subMinutes(ClientUpgradeNotice::MINUTOS_PARA_DAR_POR_COLGADO + 1),
        ]);

        $this->assertSame(0, Artisan::call('aviso-actualizacion:reintentar'));
        $this->assertStringContainsString('esperando a la cola hace rato', Artisan::output());

        $this->artisan('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--aplicar' => true,
        ])->assertExitCode(0);

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->fresh()->estado);
        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
    }

    /**
     * Un `sin_novedades` se reabre cuando alguien carga la novedad que faltaba.
     *
     * Es el caso real: el upgrade cierra el martes, la novedad de esa versión se publica el
     * miércoles. Reintentarlo ahí sí manda el mail — y si todavía no hay ninguna, lo vuelve a
     * dejar `sin_novedades` sin mandar nada.
     *
     * @return void
     */
    public function test_un_sin_novedades_se_puede_reabrir_cuando_se_carga_la_novedad()
    {
        Mail::fake();
        Http::fake();

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $version = $this->crear_version();
        /* Versión SIN novedades cargadas todavía. */
        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_NOVEDADES, $aviso->estado);
        Mail::assertNothingSent();

        /* Reintentarlo así como está lo deja igual y no manda nada. */
        $this->artisan('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--aplicar' => true,
        ])->assertExitCode(0);

        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_NOVEDADES, $aviso->fresh()->estado);
        Mail::assertNothingSent();

        /* Ahora sí: alguien publica la novedad de esa versión. */
        $this->crear_novedad($version, 'La novedad que faltaba', 'El cuerpo de la novedad.');

        $this->artisan('aviso-actualizacion:reintentar', [
            '--cliente' => $client->slug,
            '--aplicar' => true,
        ])->assertExitCode(0);

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->fresh()->estado);
        Mail::assertSent(ClientVersionUpgradeMail::class, 1);
    }

    /**
     * Deja un aviso recién registrado, en `pendiente`, con una novedad para mandar.
     *
     * @param Client $client
     *
     * @return ClientUpgradeNotice
     */
    private function dejar_un_aviso_pendiente(Client $client): ClientUpgradeNotice
    {
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');

        $upgrade = $this->crear_upgrade($client, [$version]);
        /* El hook registra la fila; el job queda encolado y no corre (Queue::fake en el setUp). */
        $upgrade->update(['status' => 'terminada']);

        $aviso = ClientUpgradeNotice::where('client_version_upgrade_id', $upgrade->id)->first();

        $this->assertInstanceOf(ClientUpgradeNotice::class, $aviso);
        $this->assertSame(ClientUpgradeNotice::ESTADO_PENDIENTE, $aviso->estado);

        return $aviso;
    }

    /**
     * Le corre la fecha de creación para atras, para simular que hace rato que está ahí.
     *
     * @param ClientUpgradeNotice $aviso
     * @param int                 $minutos
     *
     * @return void
     */
    private function envejecer(ClientUpgradeNotice $aviso, int $minutos): void
    {
        ClientUpgradeNotice::where('id', $aviso->id)->update([
            'created_at' => now()->subMinutes($minutos),
        ]);
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
