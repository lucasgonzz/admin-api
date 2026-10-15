<?php

namespace Tests\Feature\Recursos;

use App\Mail\ImplementacionMail;
use App\Models\RecursosLink;
use App\Models\RecursosUsuario;
use App\Models\RecursosVideo;
use App\Services\ImplementacionMailService;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\ImplementacionMail\BaseDelMailDeImplementacion;

/**
 * El botón de los tutoriales del mail "listo" de la implementación (misión
 * centro-de-recursos-clientes, 13/10/2026): con catálogo del centro de recursos cargado lleva el link
 * personal del dueño; sin catálogo, la carpeta de Drive de siempre.
 *
 * Lo que más importa:
 *  1. 🔴 Sin catálogo no cambia NADA respecto de antes de la misión (Drive).
 *  2. 🔴 La vista previa no escribe nada: no crea ni el usuario del centro ni el link.
 *  3. El link del mail abre de verdad la sesión del dueño, y reenviar el mail no crea otro.
 */
class MailListoConElCentroTest extends BaseDelMailDeImplementacion
{
    const DRIVE = 'https://drive.google.com/drive/folders/1pGWyUekgBok-ShmRWbXNrDhuxXMQvElp';

    /**
     * Un video del catálogo.
     *
     * @param bool $activo
     *
     * @return RecursosVideo
     */
    private function video(bool $activo = true): RecursosVideo
    {
        return RecursosVideo::create([
            'codigo' => 'T1.' . random_int(1, 99999), 'carpeta' => 1, 'carpeta_nombre' => 'Tus artículos', 'orden' => 1,
            'titulo' => 'Un video', 'duracion' => 60, 'url' => 'https://videos.test/x.mp4', 'activo' => $activo,
        ]);
    }

    /**
     * Mail::fake + cliente + implementación lista para el hito "listo".
     *
     * @return array{0: \App\Models\Client, 1: \App\Models\Implementation}
     */
    private function cliente_listo(): array
    {
        Mail::fake();

        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test', 'name' => 'Martina Gómez', 'user_id' => 500]);
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito('listo'));

        return [$client, $impl];
    }

    /**
     * El `recursos_url` con el que salió el último mail.
     *
     * @return string
     */
    private function recursos_url_enviado(): string
    {
        $url = null;

        Mail::assertSent(ImplementacionMail::class, function (ImplementacionMail $mail) use (&$url) {
            $url = isset($mail->vista['recursos_url']) ? $mail->vista['recursos_url'] : null;

            return true;
        });

        return (string) $url;
    }

    public function test_sin_catalogo_el_boton_sigue_yendo_a_drive(): void
    {
        list($client, $impl) = $this->cliente_listo();
        $this->video(false); // Uno inactivo no es catálogo.

        $html = ImplementacionMailService::previa($impl, 'listo', $this->datos_de_ejemplo('listo'), null)['html'];
        $this->assertStringContainsString(self::DRIVE, $html);

        $resultado = ImplementacionMailService::enviar($impl, 'listo', $this->datos_de_ejemplo('listo'), null, false);
        $this->assertSame('enviado', $resultado['estado']);
        $this->assertStringStartsWith(self::DRIVE, $this->recursos_url_enviado());

        $this->assertSame(0, RecursosUsuario::where('client_id', $client->id)->count());
        $this->assertSame(0, RecursosLink::count());
    }

    public function test_con_catalogo_el_mail_lleva_el_link_personal_del_dueno_y_abre_su_sesion(): void
    {
        list($client, $impl) = $this->cliente_listo();
        $this->video();

        // 🔴 La vista previa no crea nada: muestra la página, sin link personal todavía.
        $html = ImplementacionMailService::previa($impl, 'listo', $this->datos_de_ejemplo('listo'), null)['html'];
        $this->assertStringContainsString('href="https://recursos.comerciocity.com/"', $html);
        $this->assertStringNotContainsString(self::DRIVE, $html);
        $this->assertSame(0, RecursosUsuario::where('client_id', $client->id)->count());
        $this->assertSame(0, RecursosLink::count());

        $resultado = ImplementacionMailService::enviar($impl, 'listo', $this->datos_de_ejemplo('listo'), null, false);
        $this->assertSame('enviado', $resultado['estado']);

        $url = $this->recursos_url_enviado();
        $this->assertStringStartsWith('https://recursos.comerciocity.com/?t=', $url);
        $this->assertSame(1, RecursosLink::count());

        $dueno = RecursosUsuario::where('client_id', $client->id)->first();
        $this->assertTrue($dueno->es_dueno);
        $this->assertSame(500, $dueno->empresa_user_id);
        $this->assertSame('Martina Gómez', $dueno->nombre);

        // El link del mail abre la sesión del dueño.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->postJson('/api/recursos/sesion/link', ['t' => $query['t']])
            ->assertStatus(200)
            ->assertJsonPath('usuario.es_dueno', true)
            ->assertJsonPath('usuario.nombre', 'Martina Gómez');

        // Ya con el link vigente, la vista previa lo muestra; y reenviar no crea otro.
        $html = ImplementacionMailService::previa($impl, 'listo', $this->datos_de_ejemplo('listo'), null)['html'];
        $this->assertStringContainsString('href="' . $url . '"', $html);

        ImplementacionMailService::enviar($impl, 'listo', $this->datos_de_ejemplo('listo'), null, true);
        $this->assertSame(1, RecursosLink::count());
    }

    public function test_un_recursos_url_explicito_pisa_al_link_y_no_crea_nada(): void
    {
        list(, $impl) = $this->cliente_listo();
        $this->video();

        $datos = $this->datos_de_ejemplo('listo') + ['recursos_url' => 'https://ejemplo.test/otra-cosa'];

        ImplementacionMailService::enviar($impl, 'listo', $datos, null, false);

        $this->assertSame('https://ejemplo.test/otra-cosa', $this->recursos_url_enviado());
        $this->assertSame(0, RecursosLink::count());
    }

    public function test_los_otros_hitos_no_crean_el_link(): void
    {
        list(, $impl) = $this->cliente_listo();
        $this->video();

        ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame(0, RecursosLink::count());
    }
}
