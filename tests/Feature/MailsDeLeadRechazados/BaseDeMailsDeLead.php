<?php

namespace Tests\Feature\MailsDeLeadRechazados;

use App\Exceptions\MailRechazadoPorElServidorException;
use App\Models\Admin;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\ServidorSmtpFake;
use Tests\TestCase;

/**
 * Andamiaje común de los tests de los mails a leads que el servidor de correo rechaza
 * (misión mails-a-leads-rechazados-por-smtp, 6/10/2026).
 *
 * El defecto: si el servidor SMTP contesta 550 en el RCPT TO, SwiftMailer NO tira excepción. `send()`
 * vuelve normal y la casilla rechazada queda en `failures()` del mailer. Los nueve puntos que le mandan
 * un mail a un lead solo miraban si `send()` tiraba, así que daban por enviado un mail que nunca salió:
 * la ficha mostraba "Exitoso" y el recordatorio de la demo le decía al lead "los accesos están en el
 * mail que te mandamos".
 *
 * 🔴 **El servidor SMTP es de verdad** (`Tests\Fakes\ServidorSmtpFake`, un proceso aparte que contesta
 * 550 o acepta), y es lo único que prueba que SwiftMailer se porta así. Un Mockery de la fachada o
 * `Mail::fake()` no pueden mostrar este defecto: el transporte `array` nunca rechaza una casilla.
 *
 * Los envíos a leads usan el mailer POR DEFECTO (`Mail::to()`), no el `admin` de los avisos a clientes.
 * En `phpunit.xml` el default es `array`, así que cada test que necesita un SMTP real lo apunta ANTES de
 * usar el mailer por primera vez (el administrador de mails guarda cada mailer ya armado).
 *
 * Los tests que verifican "qué mail sale y a quién" con `Mail::fake()` no necesitan nada de esto: el
 * falso responde `failures()` vacío y el helper no tira.
 */
abstract class BaseDeMailsDeLead extends TestCase
{
    use DatabaseTransactions;

    /**
     * La casilla del lead de prueba: la que el servidor "rechaza". Los tests verifican que NO aparezca
     * entera en el motivo del error (la dirección ya está en la ficha, al lado).
     */
    const CASILLA = 'lead.privado@ejemplo.test';

    /**
     * Los servidores SMTP de mentira que levantó el test, para bajarlos al terminar.
     *
     * @var array<int, ServidorSmtpFake>
     */
    private $servidores_smtp = [];

    /**
     * 🔴 `Queue::fake()` en todos, y no es comodidad: `QUEUE_CONNECTION` es `sync` en los tests, así que
     * los eventos y los jobs que dispara el flujo de la IA (`LeadSuggestionCreated`, los broadcasts del
     * lead) correrían en línea y saldrían a Pusher. Con el fake no corre nada.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->servidores_smtp as $servidor) {
            $servidor->bajar();
        }

        $this->servidores_smtp = [];

        parent::tearDown();
    }

    /**
     * Levanta un servidor SMTP de verdad y deja el mailer POR DEFECTO apuntando a él. Si en este
     * entorno no se puede lanzar un proceso, el test se saltea.
     *
     * Hay que llamarlo ANTES de que el test use el mailer por primera vez.
     *
     * @param string $modo ServidorSmtpFake::MODO_RECHAZA | MODO_ACEPTA.
     *
     * @return ServidorSmtpFake
     */
    protected function levantar_un_smtp_por_defecto(string $modo): ServidorSmtpFake
    {
        $servidor = ServidorSmtpFake::levantar($modo);

        if ($servidor === null) {
            $this->markTestSkipped('No se pudo lanzar el servidor SMTP de prueba en este entorno.');
        }

        $this->servidores_smtp[] = $servidor;

        // El mailer `smtp` apunta al servidor de mentira y pasa a ser el de `Mail::to()`.
        $servidor->apuntar_el_mailer('smtp');
        config(['mail.default' => 'smtp']);

        return $servidor;
    }

    /**
     * El servidor contesta 550 a cada casilla: el mail "no existe".
     *
     * @return ServidorSmtpFake
     */
    protected function el_servidor_rechaza(): ServidorSmtpFake
    {
        return $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);
    }

    /**
     * El servidor acepta todo: el mail "sale" y el servidor lo descarta.
     *
     * @return ServidorSmtpFake
     */
    protected function el_servidor_acepta(): ServidorSmtpFake
    {
        return $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);
    }

    /**
     * El motivo que se le muestra a quien opera el panel cuando el servidor rechazó la casilla.
     *
     * @return string
     */
    protected function el_motivo(): string
    {
        return MailRechazadoPorElServidorException::MOTIVO;
    }

    /**
     * Admin del panel, para autenticar los pedidos.
     *
     * @return Admin
     */
    protected function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'admin-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Instancia de demo con tienda propia (`https://tienda-N.test`).
     *
     * @param int $n Número de la instancia.
     *
     * @return Demo
     */
    protected function crear_demo(int $n = 1): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-' . $n . '.test';
        $demo->erp_api_url       = 'https://demo-' . $n . '-api.test';
        $demo->ecommerce_spa_url = 'https://tienda-' . $n . '.test';
        $demo->ecommerce_api_url = 'https://tienda-' . $n . '-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * Lead mínimo con email, el que alcanza para la presentación y el seguimiento.
     *
     * @param array<string, mixed> $atributos Sobrescriben los del lead (las marcas `*_mail_sent_at`, por ejemplo).
     *
     * @return Lead
     */
    protected function crear_lead(array $atributos = []): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Guillermo González';
        $lead->company_name = 'Ferretería de prueba';
        $lead->phone        = '5493511234567';
        $lead->email        = self::CASILLA;
        $lead->status       = 'calificado';

        foreach ($atributos as $clave => $valor) {
            $lead->{$clave} = $valor;
        }

        $lead->save();

        return $lead->refresh();
    }

    /**
     * Lead de la dinámica NUEVA con una demo asignada: el que recibe la carta de acceso, no el Mail 1.
     *
     * 🔴 La dinámica se fija DESPUÉS del primer `save()`: el hook `creating` del modelo estampa la
     * dinámica por defecto al nacer y pisaría la que se le pase antes.
     *
     * @param array<string, mixed> $atributos Sobrescriben los del lead.
     *
     * @return Lead
     */
    protected function crear_lead_de_la_dinamica_nueva(array $atributos = []): Lead
    {
        $demo = $this->crear_demo(1);

        $lead = $this->crear_lead(array_merge([
            'status'  => 'demo_agendada',
            'demo_id' => $demo->id,
        ], $atributos));

        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Lead de la dinámica VIEJA con todos los datos que exige el Mail 1: nombre, documento, demo
     * asignada, día y horario.
     *
     * @param array<string, mixed> $atributos Sobrescriben los del lead.
     *
     * @return Lead
     */
    protected function crear_lead_de_la_dinamica_vieja(array $atributos = []): Lead
    {
        $demo = $this->crear_demo(1);

        $lead = $this->crear_lead(array_merge([
            'status'          => 'demo_agendada',
            'doc_number'      => '30111222',
            'demo_id'         => $demo->id,
            'demo_date'       => '2026-09-08',
            'demo_start_time' => '10:00',
            'demo_end_time'   => '11:00',
        ], $atributos));

        $lead->demo_experiencia = Lead::EXPERIENCIA_ACTUAL;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Lo que tiene que valer después de un envío que el servidor RECHAZÓ: la ficha NO lo cuenta como
     * enviado y deja el motivo (sin la casilla entera) donde el panel lo muestra como "Fallido".
     *
     * @param Lead        $lead         El lead después del intento.
     * @param string      $col_enviado  Columna `*_mail_sent_at` del envío.
     * @param string      $col_error    Columna `*_mail_last_error` del envío.
     * @param string|null $enviado_antes La fecha de un envío ANTERIOR que sí salió (`Y-m-d H:i:s`), o null si nunca salió uno.
     *
     * @return void
     */
    protected function afirmar_que_el_envio_no_cuenta_como_hecho(Lead $lead, string $col_enviado, string $col_error, ?string $enviado_antes = null): void
    {
        $fresco = $lead->fresh();

        if ($enviado_antes === null) {
            $this->assertNull($fresco->{$col_enviado}, 'El servidor rechazó la casilla: el mail no salió, no puede tener fecha de envío.');
        } else {
            $this->assertNotNull($fresco->{$col_enviado});
            $this->assertSame($enviado_antes, $fresco->{$col_enviado}->format('Y-m-d H:i:s'), 'La fecha del envío anterior no se pisa con un intento que no salió.');
        }

        $this->assertSame($this->el_motivo(), $fresco->{$col_error}, 'El motivo del rechazo queda en la ficha, donde el panel lo muestra.');
        $this->assertStringNotContainsString(self::CASILLA, (string) $fresco->{$col_error}, 'La casilla entera no se repite en el motivo.');
    }

    /**
     * Lo que tiene que valer después de un envío que el servidor ACEPTÓ: fecha de envío y sin error.
     *
     * @param Lead   $lead        El lead después del envío.
     * @param string $col_enviado Columna `*_mail_sent_at` del envío.
     * @param string $col_error   Columna `*_mail_last_error` del envío.
     *
     * @return void
     */
    protected function afirmar_que_el_envio_cuenta_como_hecho(Lead $lead, string $col_enviado, string $col_error): void
    {
        $fresco = $lead->fresh();

        $this->assertNotNull($fresco->{$col_enviado}, 'El mail salió: tiene que tener fecha de envío.');
        $this->assertNull($fresco->{$col_error}, 'El mail salió: no puede quedar un error.');
    }

    /**
     * Las acciones que el panel manda al aprobar, todas apagadas: cada disparador prende lo suyo.
     *
     * @param array<string, mixed> $prendidas Lo que este caso activa.
     *
     * @return array<string, mixed>
     */
    protected function final_actions(array $prendidas = []): array
    {
        return array_merge([
            'estado_sugerido'              => 'demo_agendada',
            'agendar_demo'                 => null,
            'forzar_slot'                  => false,
            'enviar_mail_demo'             => false,
            'reenviar_mail_demo'           => false,
            'guardar_nombre'               => null,
            'guardar_email'                => null,
            'cancelar_demo'                => false,
            'requiere_intervencion_humana' => false,
            'motivo_intervencion'          => null,
        ], $prendidas);
    }

    /**
     * Le da al lead un mensaje entrante, como tiene todo lead real que llegó hasta acá.
     *
     * @param Lead $lead
     *
     * @return Lead El mismo lead.
     */
    protected function con_un_mensaje_entrante(Lead $lead): Lead
    {
        $entrante              = new LeadMessage();
        $entrante->lead_id     = $lead->id;
        $entrante->sender      = 'lead';
        $entrante->status      = 'enviado';
        $entrante->is_followup = false;
        $entrante->content     = 'Dale, mandame todo por mail.';
        $entrante->save();

        return $lead;
    }

    /**
     * Un mensaje `sugerido` con su paquete de acciones, tal cual lo deja `generate_suggestion()`.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $extra Acciones del paquete original (`agendar_demo`, por ejemplo).
     *
     * @return LeadMessage
     */
    protected function crear_mensaje_pendiente(Lead $lead, array $extra = []): LeadMessage
    {
        $mensaje                        = new LeadMessage();
        $mensaje->lead_id               = $lead->id;
        $mensaje->sender                = 'sistema';
        $mensaje->status                = 'sugerido';
        $mensaje->is_followup           = false;
        $mensaje->requiere_verificacion = true;
        $mensaje->content               = 'Dale, te mando todo por mail.';
        $mensaje->pending_actions       = array_merge([
            'mensaje_sugerido' => $mensaje->content,
            'estado_sugerido'  => 'demo_agendada',
            'razonamiento'     => '',
        ], $extra);
        $mensaje->save();

        return $mensaje;
    }

    /**
     * Aprueba un paquete de acciones como lo hace el panel: crea el mensaje pendiente y lo aplica.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $pendientes    Acciones del paquete original de la IA.
     * @param array<string, mixed> $final_actions Lo que aprobó el panel.
     *
     * @return LeadMessage El mensaje ya aplicado, releído de la base.
     */
    protected function aprobar(Lead $lead, array $pendientes, array $final_actions): LeadMessage
    {
        $mensaje = $this->crear_mensaje_pendiente($lead, $pendientes);

        (new LeadAiService())->apply_pending_actions($mensaje, $final_actions);

        return $mensaje->fresh();
    }
}
