<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Models\ClientUpgradeNotice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * 🔴 **El caso más frecuente en producción durante semanas, y el que más importa.**
 *
 * `admin-sync/contacto-dueno` es nuevo del lado de `empresa-api` y ningún cliente lo tiene hasta
 * que se actualiza a una versión que lo traiga: los ~45 corren versiones distintas. O sea que la
 * respuesta esperable hoy es **404**, y eso no puede explotar, no puede hacer fallar el upgrade y
 * no puede ensuciar la lista de errores.
 */
class ClienteSinElEndpointTest extends BaseDelAviso
{
    /**
     * El 404 del cliente que corre una versión vieja deja el aviso en `sin_mail` y nada más.
     *
     * ⚠️ Con `retry()` activo (el default de `services.client_api.retries` es 2), Laravel no
     * devuelve la respuesta fallida: la convierte en excepción después de agotar los intentos. Este
     * test recorre ese camino, que es justamente el que un `if (! $response->successful())` solo
     * no cubriría.
     *
     * @return void
     */
    public function test_el_404_del_cliente_deja_el_aviso_en_sin_mail_sin_explotar()
    {
        Mail::fake();
        Http::fake([
            '*contacto-dueno*' => Http::response('', 404),
        ]);

        $client  = $this->crear_cliente(['email' => null]);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');
        $upgrade = $this->crear_upgrade($client, [$version]);

        $upgrade->update(['status' => 'terminada']);

        $this->assertSame('terminada', $upgrade->fresh()->status, 'el upgrade cerró bien igual');

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertNotNull($aviso);
        $this->assertSame(
            ClientUpgradeNotice::ESTADO_SIN_MAIL,
            $aviso->estado,
            'el 404 es un dato que falta, no un error del sistema'
        );
        $this->assertNull($aviso->mail_enviado_at);
        $this->assertNull($aviso->email);

        Mail::assertNothingSent();
        $this->assertSame(0, $this->whatsapp->cuantos_envios());

        $this->assertNull($client->fresh()->email, 'no se escribió ninguna casilla inventada');
    }

    /**
     * Lo mismo para todo lo demás que puede volver de un cliente: rechazo de la api_key, error del
     * servidor, body que no es JSON, y una respuesta 200 sin `contacto`. Los cuatro se tratan
     * igual: `sin_mail`, una línea en el log, y seguir.
     *
     * @dataProvider respuestas_que_no_sirven
     *
     * @param mixed $body
     * @param int   $status
     *
     * @return void
     */
    public function test_cualquier_respuesta_que_no_sirve_deja_el_aviso_en_sin_mail($body, int $status)
    {
        Mail::fake();
        Http::fake([
            '*contacto-dueno*' => Http::response($body, $status),
        ]);

        $client  = $this->crear_cliente(['email' => null]);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');
        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_MAIL, $aviso->estado);
        Mail::assertNothingSent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function respuestas_que_no_sirven(): array
    {
        return [
            'api_key rechazada'    => ['', 401],
            'error del servidor'   => ['', 500],
            'no es JSON'           => ['<html>Error</html>', 200],
            'sin contacto'         => [['otra_cosa' => true], 200],
            'contacto sin email'   => [['contacto' => ['name' => 'Juan']], 200],
            'email vacío'          => [['contacto' => ['email' => '']], 200],
        ];
    }

    /**
     * Un cliente sin `api_key` no se consulta siquiera, y tampoco rompe.
     *
     * @return void
     */
    public function test_un_cliente_sin_api_key_no_rompe()
    {
        Mail::fake();
        Http::fake();

        $client  = $this->crear_cliente(['email' => null, 'api_key' => null]);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');
        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_MAIL, $aviso->estado);
        Http::assertNothingSent();
    }
}
