<?php

namespace Tests\Feature\WhatsappPersonal;

use App\Helpers\WhatsappNormalizer;
use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * CA8 a CA14 — `POST api/admin/whatsapp-personal/enviar`: dry run por defecto, lista cerrada de claves,
 * ventana de 24 h propia, idempotencia por `op`, normalización, número listo y frenos anti-masivo.
 */
class EnvioDesdeElNumeroPersonalTest extends BaseDelNumeroPersonal
{
    /**
     * Principal y personal listos, y un agente.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crear_principal();
        $this->crear_personal();
        $this->iniciar_agente();
    }

    /**
     * Fakea el envío por el número personal con un wamid.
     *
     * @param string $wamid Id que devuelve Kapso.
     *
     * @return void
     */
    private function kapso_acepta(string $wamid = 'wamid.SALIDA1'): void
    {
        $this->fakear_http([
            $this->url_de_envio(self::PNI_PERSONAL) => Http::response(['messages' => [['id' => $wamid]]], 200),
        ]);
    }

    /**
     * Abre la ventana de 24 h del par (negocio, cliente) con un entrante reciente.
     *
     * @param string $telefono Chat.
     *
     * @return void
     */
    private function abrir_ventana(string $telefono = self::TEL_CLIENTE): void
    {
        $this->sembrar_mensaje(['chat_telefono' => $telefono, 'sent_at' => now()->subMinutes(30)]);
    }

    /**
     * Filas salientes del personal.
     *
     * @return \Illuminate\Support\Collection
     */
    private function salientes()
    {
        return DB::table('whatsapp_personal_messages')->where('direccion', 'out')->get();
    }

    /**
     * Pedidos HTTP recibidos por una URL de envío.
     *
     * @param string $phone_number_id Número.
     *
     * @return int
     */
    private function envios_por(string $phone_number_id): int
    {
        $url = $this->url_de_envio($phone_number_id);

        return Http::recorded(function (Request $request) use ($url) {
            return $request->url() === $url;
        })->count();
    }

    // ---------------------------------------------------------------------------------------------
    // CA8
    // ---------------------------------------------------------------------------------------------

    /**
     * CA8 — Sin `dry_run` (o con `true`) no se envía ni se guarda; responde el destino y la ventana.
     *
     * @return void
     */
    public function test_dry_run_por_defecto_no_envia_ni_guarda(): void
    {
        $this->abrir_ventana();

        foreach ([['telefono' => '3412345678', 'texto' => 'Hola'], ['telefono' => '3412345678', 'texto' => 'Hola', 'dry_run' => true]] as $cuerpo) {
            $respuesta = $this->enviar($cuerpo);

            $respuesta->assertStatus(200);
            $this->assertTrue($respuesta->json('dry_run'));
            $this->assertSame(self::TEL_CLIENTE, $respuesta->json('destino'));
            $this->assertTrue($respuesta->json('ventana.open'));
        }

        $this->assertSame(0, $this->envios_por(self::PNI_PERSONAL));
        $this->assertSame(0, $this->envios_por(self::PNI_PRINCIPAL));
        $this->assertCount(0, $this->salientes());
    }

    /**
     * CA8 — `dry_run=false` exige `confirm_telefono` (últimos 4 dígitos) y `op`.
     *
     * @return void
     */
    public function test_sin_confirmacion_o_sin_op_da_422(): void
    {
        $this->abrir_ventana();

        $sin_confirmacion = $this->cuerpo_de_envio();
        unset($sin_confirmacion['confirm_telefono']);

        $sin_op = $this->cuerpo_de_envio();
        unset($sin_op['op']);

        $casos = [
            $sin_confirmacion,
            $sin_op,
            $this->cuerpo_de_envio(['confirm_telefono' => '9999']),
            $this->cuerpo_de_envio(['op' => 'corta']),
            $this->cuerpo_de_envio(['op' => 'tiene espacios y /barras/ que no valen']),
        ];

        foreach ($casos as $cuerpo) {
            $this->enviar($cuerpo)->assertStatus(422);
        }

        $this->assertSame(0, $this->envios_por(self::PNI_PERSONAL));
        $this->assertCount(0, $this->salientes());
    }

    /**
     * CA8 — Un `dry_run` que no es booleano es 422 y no envía (nunca se interpreta como «enviar»).
     *
     * @return void
     */
    public function test_dry_run_no_booleano_es_422_y_no_envia(): void
    {
        $this->abrir_ventana();
        $this->kapso_acepta();

        foreach (['quizas', ['false'], 'no'] as $valor) {
            $this->enviar($this->cuerpo_de_envio(['dry_run' => $valor]))->assertStatus(422);
        }

        $this->assertSame(0, $this->envios_por(self::PNI_PERSONAL));
    }

    /**
     * CA8 — Una clave de más en el cuerpo es 422.
     *
     * @return void
     */
    public function test_clave_de_mas_es_422(): void
    {
        $this->abrir_ventana();
        $this->kapso_acepta();

        $this->enviar($this->cuerpo_de_envio(['phone_number_id' => self::PNI_PRINCIPAL]))->assertStatus(422);
        $this->enviar($this->cuerpo_de_envio(['kapso_api_key' => 'otra']))->assertStatus(422);

        $this->assertSame(0, $this->envios_por(self::PNI_PERSONAL));
        $this->assertSame(0, $this->envios_por(self::PNI_PRINCIPAL));
    }

    /**
     * CA8 — `telefono` como arreglo (un envío masivo disfrazado) es 422.
     *
     * @return void
     */
    public function test_telefono_como_arreglo_es_422(): void
    {
        $this->kapso_acepta();

        $this->enviar($this->cuerpo_de_envio(['telefono' => ['3412345678', '3411111111']]))->assertStatus(422);

        $this->assertSame(0, $this->envios_por(self::PNI_PERSONAL));
    }

    // ---------------------------------------------------------------------------------------------
    // CA9, CA10
    // ---------------------------------------------------------------------------------------------

    /**
     * CA9 🔴 — Texto con ventana abierta: UNA llamada, por el `phone_number_id` PERSONAL, con la clave de
     * Kapso y el texto. Ningún pedido sale por el principal.
     *
     * @return void
     */
    public function test_texto_con_ventana_abierta_sale_por_el_numero_personal(): void
    {
        $this->abrir_ventana();
        $this->kapso_acepta('wamid.SALIDA1');

        $respuesta = $this->enviar($this->cuerpo_de_envio());

        $respuesta->assertStatus(200);
        $this->assertSame('wamid.SALIDA1', $respuesta->json('wamid'));
        $this->assertSame('enviado', $respuesta->json('estado'));

        $this->assertSame(1, $this->envios_por(self::PNI_PERSONAL));
        $this->assertSame(0, $this->envios_por(self::PNI_PRINCIPAL));
        $this->assertSame(1, $this->pedidos_http(), 'Un solo pedido de red en total.');

        Http::assertSent(function (Request $request) {
            $cuerpo = $request->data();

            return $request->url() === $this->url_de_envio(self::PNI_PERSONAL)
                && $request->hasHeader('X-API-Key', self::CLAVE_KAPSO)
                && $cuerpo['to'] === '5493412345678'
                && $cuerpo['type'] === 'text'
                && $cuerpo['text']['body'] === 'Hola, te escribo desde mi número';
        });

        $fila = $this->salientes()->first();
        $this->assertNotNull($fila);
        $this->assertSame('cloud_api', $fila->origen);
        $this->assertSame('wamid.SALIDA1', $fila->whatsapp_message_id);
        $this->assertSame('enviado', $fila->estado);
        $this->assertSame('op-prueba-000001', $fila->op_id);
        $this->assertSame(self::TEL_CLIENTE, $fila->chat_telefono);
        $this->assertSame(self::PNI_PERSONAL, $fila->phone_number_id);
    }

    /**
     * CA10 — Texto con la ventana cerrada es 422 `fuera_de_ventana` y no sale nada: sin entrantes, o con un
     * único entrante de hace más de 24 h.
     *
     * @return void
     */
    public function test_texto_fuera_de_ventana_da_422(): void
    {
        $this->kapso_acepta();

        $sin_entrantes = $this->enviar($this->cuerpo_de_envio());
        $sin_entrantes->assertStatus(422);
        $this->assertSame('fuera_de_ventana', $sin_entrantes->json('code'));
        $this->assertNotNull($sin_entrantes->json('ventana'));

        $this->sembrar_mensaje(['sent_at' => now()->subHours(25)]);
        $this->enviar($this->cuerpo_de_envio(['op' => 'op-prueba-000002']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'fuera_de_ventana');

        $this->assertSame(0, $this->pedidos_http());
    }

    /**
     * CA10 🔴 — Un entrante de un lead (o de soporte) al mismo teléfono NO abre la ventana del personal:
     * es otro par de números.
     *
     * @return void
     */
    public function test_entrante_de_un_lead_al_mismo_telefono_no_abre_la_ventana_personal(): void
    {
        $this->kapso_acepta();

        $lead = Lead::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'contact_name' => 'Lead', 'phone' => self::TEL_CLIENTE]);
        LeadMessage::create(['lead_id' => $lead->id, 'sender' => 'lead', 'content' => 'hola', 'status' => 'enviado']);

        $this->enviar($this->cuerpo_de_envio())
            ->assertStatus(422)
            ->assertJsonPath('code', 'fuera_de_ventana');

        $this->assertSame(0, $this->pedidos_http());
    }

    /**
     * CA10 — Una plantilla sale aunque la ventana esté cerrada, por el número personal; con una variable
     * vacía es 422 y no sale.
     *
     * @return void
     */
    public function test_plantilla_fuera_de_ventana_sale(): void
    {
        $this->kapso_acepta('wamid.PLANTILLA1');

        $cuerpo = [
            'telefono'         => '3412345678',
            'plantilla'        => 'aviso_cliente',
            'variables'        => ['Juan'],
            'dry_run'          => false,
            'confirm_telefono' => '5678',
            'op'               => 'op-plantilla-001',
        ];

        $this->enviar($cuerpo)->assertStatus(200)->assertJsonPath('wamid', 'wamid.PLANTILLA1');

        Http::assertSent(function (Request $request) {
            $cuerpo = $request->data();

            return $request->url() === $this->url_de_envio(self::PNI_PERSONAL)
                && $cuerpo['type'] === 'template'
                && $cuerpo['template']['name'] === 'aviso_cliente'
                && $cuerpo['template']['language']['code'] === 'es_AR'
                && $cuerpo['template']['components'][0]['parameters'][0]['text'] === 'Juan';
        });
        $this->assertSame(0, $this->envios_por(self::PNI_PRINCIPAL));

        $antes = $this->pedidos_http();
        $this->enviar(array_merge($cuerpo, ['variables' => [''], 'op' => 'op-plantilla-002']))->assertStatus(422);
        $this->assertSame($antes, $this->pedidos_http(), 'Con una variable vacía no sale nada.');
    }

    // ---------------------------------------------------------------------------------------------
    // CA11
    // ---------------------------------------------------------------------------------------------

    /**
     * CA11 — La misma `op` con el mismo contenido manda una sola vez y devuelve el mismo wamid.
     *
     * @return void
     */
    public function test_misma_op_no_manda_dos_veces(): void
    {
        $this->abrir_ventana();
        $this->kapso_acepta('wamid.IDEM1');

        $primera = $this->enviar($this->cuerpo_de_envio());
        $segunda = $this->enviar($this->cuerpo_de_envio());

        $primera->assertStatus(200);
        $segunda->assertStatus(200);
        $this->assertTrue($segunda->json('ya_enviado'));
        $this->assertSame('wamid.IDEM1', $segunda->json('wamid'));
        $this->assertSame(1, $this->envios_por(self::PNI_PERSONAL));
        $this->assertCount(1, $this->salientes());
    }

    /**
     * CA11 — La misma `op` con otro contenido es 409 `op_con_otro_contenido` y no sale nada.
     *
     * @return void
     */
    public function test_misma_op_otro_contenido_409(): void
    {
        $this->abrir_ventana();
        $this->kapso_acepta();

        $this->enviar($this->cuerpo_de_envio())->assertStatus(200);
        $this->enviar($this->cuerpo_de_envio(['texto' => 'Otro texto distinto']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'op_con_otro_contenido');

        $this->assertSame(1, $this->envios_por(self::PNI_PERSONAL));
    }

    /**
     * CA11 — Una falla de red deja la fila `incierto` (puede haber salido) y repetir la `op` es 409
     * `op_incierta` sin llamar de nuevo.
     *
     * @return void
     */
    public function test_falla_transitoria_queda_incierta(): void
    {
        $this->abrir_ventana();
        $this->fakear_http([
            $this->url_de_envio(self::PNI_PERSONAL) => function () {
                throw new ConnectionException('timeout simulado');
            },
        ]);

        $this->enviar($this->cuerpo_de_envio())->assertStatus(502);

        $fila = $this->salientes()->first();
        $this->assertNotNull($fila);
        $this->assertSame('incierto', $fila->estado);

        $llamadas = $this->pedidos_http();
        $this->enviar($this->cuerpo_de_envio())
            ->assertStatus(409)
            ->assertJsonPath('code', 'op_incierta');
        $this->assertSame($llamadas, $this->pedidos_http(), 'Repetir la op no vuelve a llamar a Kapso.');
    }

    /**
     * CA11 — Un rechazo definitivo (HTTP 4xx) queda `fallido` y NO avisa a los admins por WhatsApp.
     *
     * @return void
     */
    public function test_falla_no_transitoria_queda_fallida_y_no_avisa(): void
    {
        $this->abrir_ventana();
        Admin::create([
            'name' => 'Admin avisado', 'email' => 'avisado@test.local', 'password' => bcrypt('x'),
            'phone_number' => '+5493415550999', 'notify_send_errors_whatsapp' => true,
        ]);
        $this->fakear_http([
            $this->url_de_envio(self::PNI_PERSONAL) => Http::response(['error' => ['message' => 'Invalid parameter']], 400),
        ]);

        $this->enviar($this->cuerpo_de_envio())->assertStatus(422);

        $fila = $this->salientes()->first();
        $this->assertNotNull($fila);
        $this->assertSame('fallido', $fila->estado);
        $this->assertNotEmpty($fila->estado_error);

        $this->assertSame(0, $this->envios_por(self::PNI_PRINCIPAL), 'Ningún aviso a admins por el principal.');
        $this->assertFalse(Cache::has('whatsapp_send_error_notify_lock'), 'No se disparó la notificación de fallos.');
    }

    // ---------------------------------------------------------------------------------------------
    // CA12, CA13
    // ---------------------------------------------------------------------------------------------

    /**
     * Formatos de un mismo teléfono argentino.
     *
     * @return array<string, array<int, string>>
     */
    public function formatos_de_telefono(): array
    {
        return [
            'local sin prefijo'  => ['3412345678'],
            'con cero'           => ['03412345678'],
            'internacional'      => ['+54 9 341 234-5678'],
            'formato WhatsApp'   => ['5493412345678'],
        ];
    }

    /**
     * CA12 — Todos los formatos terminan en el mismo chat y el mismo destino.
     *
     * @dataProvider formatos_de_telefono
     *
     * @param string $formato Teléfono tal como lo escribió quien llama.
     *
     * @return void
     */
    public function test_telefonos_argentinos_en_distintos_formatos_van_al_mismo_chat(string $formato): void
    {
        $this->assertSame(self::TEL_CLIENTE, WhatsappNormalizer::normalize($formato));

        $this->abrir_ventana();

        $respuesta = $this->enviar(['telefono' => $formato, 'texto' => 'Hola']);

        $respuesta->assertStatus(200);
        $this->assertSame(self::TEL_CLIENTE, $respuesta->json('destino'));
        $this->assertTrue($respuesta->json('ventana.open'), 'Misma ventana: es el mismo chat.');
    }

    /**
     * CA12 — Un teléfono que no es un teléfono es 422 `telefono_invalido`.
     *
     * @return void
     */
    public function test_telefono_invalido_422(): void
    {
        foreach (['abc', '1234567', 'juan@gmail.com'] as $malo) {
            $this->enviar(['telefono' => $malo, 'texto' => 'Hola'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'telefono_invalido');
        }
    }

    /**
     * CA13 🔴 — Sin fila, apagado o sin secreto: 409 `numero_no_listo` con `faltan[]` y NO sale ninguna
     * llamada, tampoco por el principal (a diferencia de `paraProposito`, que cae al principal).
     *
     * @return void
     */
    public function test_numero_personal_no_listo_no_cae_al_principal(): void
    {
        $this->abrir_ventana();
        $this->kapso_acepta();

        // Sin secreto del webhook.
        \App\Models\WhatsappConfig::where('phone_number_id', self::PNI_PERSONAL)->update(['webhook_secret' => '']);
        $sin_secreto = $this->enviar($this->cuerpo_de_envio());
        $sin_secreto->assertStatus(409);
        $this->assertSame('numero_no_listo', $sin_secreto->json('code'));
        $this->assertContains('webhook_secret', $sin_secreto->json('faltan'));

        // Apagado (aunque en dry run).
        \App\Models\WhatsappConfig::where('phone_number_id', self::PNI_PERSONAL)->update(['webhook_secret' => 'x', 'is_active' => false]);
        $this->enviar(['telefono' => '3412345678', 'texto' => 'Hola'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'numero_no_listo');

        // Sin fila.
        \App\Models\WhatsappConfig::where('phone_number_id', self::PNI_PERSONAL)->delete();
        $this->enviar($this->cuerpo_de_envio(['op' => 'op-prueba-000009']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'numero_no_listo');

        $this->assertSame(0, $this->pedidos_http(), 'Ni por el personal ni por el principal.');
    }

    // ---------------------------------------------------------------------------------------------
    // CA14
    // ---------------------------------------------------------------------------------------------

    /**
     * CA14 — El envío 21 en una hora es 429 `tope_de_envios` (el 20 todavía sale).
     *
     * @return void
     */
    public function test_tope_por_hora(): void
    {
        for ($i = 1; $i <= 19; $i++) {
            $this->sembrar_mensaje([
                'chat_telefono'       => '+54911' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'direccion'           => 'out',
                'origen'              => 'cloud_api',
                'estado'              => 'enviado',
                'op_id'               => 'op-sembrada-' . $i . '-xxxx',
                'sent_at'             => now()->subMinutes(20),
            ]);
        }
        $this->kapso_acepta();

        $plantilla = ['plantilla' => 'aviso_cliente', 'variables' => ['Ana'], 'dry_run' => false];

        $this->enviar($plantilla + ['telefono' => '3411111111', 'confirm_telefono' => '1111', 'op' => 'op-numero-veinte'])
            ->assertStatus(200);

        $this->enviar($plantilla + ['telefono' => '3412222222', 'confirm_telefono' => '2222', 'op' => 'op-numero-veintiuno'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'tope_de_envios');

        $this->assertSame(1, $this->envios_por(self::PNI_PERSONAL));
    }

    /**
     * CA14 — Dos envíos al mismo teléfono con menos de 10 segundos: el segundo es 429 `demasiado_rapido`;
     * pasados los 10 segundos sale.
     *
     * @return void
     */
    public function test_tope_por_telefono(): void
    {
        $this->abrir_ventana();
        $this->kapso_acepta();

        Carbon::setTestNow(Carbon::now());

        $this->enviar($this->cuerpo_de_envio())->assertStatus(200);

        $this->enviar($this->cuerpo_de_envio(['texto' => 'Segundo mensaje', 'op' => 'op-prueba-000002']))
            ->assertStatus(429)
            ->assertJsonPath('code', 'demasiado_rapido');
        $this->assertSame(1, $this->envios_por(self::PNI_PERSONAL));

        Carbon::setTestNow(Carbon::now()->addSeconds(11));

        $this->enviar($this->cuerpo_de_envio(['texto' => 'Segundo mensaje', 'op' => 'op-prueba-000002']))->assertStatus(200);
        $this->assertSame(2, $this->envios_por(self::PNI_PERSONAL));
    }
}
