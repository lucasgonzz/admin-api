<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Mail\ClientVersionUpgradeMail;
use App\Models\ClientUpgradeNotice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * El mail: a dónde sale y qué lleva adentro.
 */
class MailDelAvisoTest extends BaseDelAviso
{
    /**
     * Con la casilla cargada en la ficha, el mail sale a esa dirección y con las novedades de la
     * actualización. Y no se le pregunta nada al cliente: el paso 1 de la cascada corta.
     *
     * @return void
     */
    public function test_con_la_casilla_en_la_ficha_el_mail_sale_con_las_novedades()
    {
        Mail::fake();
        Http::fake();

        $client  = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $version = $this->crear_version();

        $this->crear_novedad($version, 'Escaneo de facturas', 'Subís la factura del proveedor y el sistema carga los artículos solo.', 1);
        $this->crear_novedad($version, 'Buscador más rápido', 'Buscar un artículo en Vender ahora es instantáneo.', 2);

        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado);
        $this->assertNotNull($aviso->mail_enviado_at);
        $this->assertSame('dueno@ejemplo.test', $aviso->email);

        Mail::assertSent(ClientVersionUpgradeMail::class, function ($mail) use ($version) {
            if (! $mail->hasTo('dueno@ejemplo.test')) {
                return false;
            }

            $etiquetas = array_column($mail->payload->detail_lines, 'label');
            $valores   = array_column($mail->payload->detail_lines, 'value');

            return $etiquetas === ['Escaneo de facturas', 'Buscador más rápido']
                && $valores[0] === 'Subís la factura del proveedor y el sistema carga los artículos solo.'
                && strpos($mail->payload->subject, $version->version) !== false;
        });

        Http::assertNothingSent();
    }

    /**
     * Paso 2 de la cascada: sin casilla en la ficha se le pregunta al `empresa-api` del cliente, y
     * lo que trae se GUARDA en `clients.email` para no volver a preguntar.
     *
     * @return void
     */
    public function test_la_casilla_que_trae_el_cliente_se_guarda_y_se_usa()
    {
        Mail::fake();
        Http::fake([
            '*contacto-dueno*' => Http::response([
                'contacto' => [
                    'email'        => 'traido@ejemplo.test',
                    'name'         => 'Juan Pérez',
                    'company_name' => 'Ferretería Pérez',
                    'phone'        => '3444999888',
                ],
            ], 200),
        ]);

        $client  = $this->crear_cliente(['email' => null]);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');
        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_ENVIADO, $aviso->estado);
        $this->assertSame('traido@ejemplo.test', $aviso->email);

        $this->assertSame(
            'traido@ejemplo.test',
            $client->fresh()->email,
            'la casilla queda escrita en la ficha para no volver a preguntar'
        );

        Mail::assertSent(ClientVersionUpgradeMail::class, function ($mail) {
            return $mail->hasTo('traido@ejemplo.test');
        });

        /* Y se le pidió con la api_key del cliente en el header. */
        Http::assertSent(function ($request) use ($client) {
            return strpos($request->url(), 'api/admin-sync/contacto-dueno') !== false
                && $request->hasHeader('X-Admin-Api-Key', $client->api_key)
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    /**
     * Una casilla inválida —del lado que sea— no se usa. El paso 1 valida igual que el paso 2:
     * `clients.email` se carga a mano desde el panel y un tipeo mal ahí haría fallar el envío.
     *
     * @return void
     */
    public function test_una_casilla_invalida_no_se_usa()
    {
        Mail::fake();
        Http::fake([
            '*contacto-dueno*' => Http::response(['contacto' => ['email' => 'tampoco-es-un-mail']], 200),
        ]);

        $client  = $this->crear_cliente(['email' => 'esto no es un mail']);
        $version = $this->crear_version();
        $this->crear_novedad($version, 'Una novedad', 'El cuerpo de la novedad.');
        $upgrade = $this->crear_upgrade($client, [$version]);
        $upgrade->update(['status' => 'terminada']);

        $aviso = $this->servicio()->avisar($upgrade->fresh());

        $this->assertSame(ClientUpgradeNotice::ESTADO_SIN_MAIL, $aviso->estado);
        Mail::assertNothingSent();
    }

    /**
     * 🔴 Solo entran las novedades de las versiones que trajo ESTE upgrade (las de la pivot
     * `client_version_upgrade_versions`), y las restringidas a otro cliente quedan afuera.
     *
     * Los dos filtros son distintos y los dos importan: uno es "qué versiones instaló", el otro es
     * "qué de eso le corresponde ver a este dueño".
     *
     * @return void
     */
    public function test_solo_entran_las_novedades_de_sus_versiones_y_las_que_le_corresponden()
    {
        Mail::fake();
        Http::fake();

        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $otro   = $this->crear_cliente(['email' => 'otro@ejemplo.test']);

        $del_upgrade   = $this->crear_version();
        $de_otra_tanda = $this->crear_version();

        $this->crear_novedad($del_upgrade, 'Para todos', 'Novedad sin restricción.', 1);
        $this->crear_novedad($del_upgrade, 'Solo para este cliente', 'Novedad restringida a él.', 2, $client);
        $this->crear_novedad($del_upgrade, 'Solo para el otro', 'Novedad restringida a otro cliente.', 3, $otro);

        /* Novedad inactiva: no se muestra aunque sea de una versión del upgrade. */
        $inactiva = $this->crear_novedad($del_upgrade, 'Apagada', 'No tiene que salir.', 4);
        $inactiva->update(['is_active' => false]);

        /* Novedad de una versión que este upgrade NO trajo. */
        $this->crear_novedad($de_otra_tanda, 'De otra versión', 'No entró en esta actualización.', 1);

        $upgrade = $this->crear_upgrade($client, [$del_upgrade]);
        $upgrade->update(['status' => 'terminada']);

        $this->servicio()->avisar($upgrade->fresh());

        Mail::assertSent(ClientVersionUpgradeMail::class, function ($mail) {
            $etiquetas = array_column($mail->payload->detail_lines, 'label');

            return $etiquetas === ['Para todos', 'Solo para este cliente'];
        });
    }
}
