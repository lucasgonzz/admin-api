<?php

namespace Tests\Feature\MailsDeLeadRechazados;

use App\Mail\ComercioCityMail;
use App\Mail\LeadDemoAccesoMail;
use App\Mail\LeadDemoMail;
use App\Mail\LeadProposalMail;
use App\Models\Lead;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Los cuatro envíos de mail a un lead que dispara el panel de admin-spa (rutas `POST /api/admin/lead/{id}/...`),
 * cuando el servidor de correo RECHAZA la casilla.
 *
 * Son los puntos 3, 4, 5 y 6 de la misión mails-a-leads-rechazados-por-smtp (6/10/2026):
 *
 *   3. `send-presentation-mail`          — la tarjeta de presentación.
 *   4. `send-followup-mail`              — el seguimiento post-reunión (Mail 2). Tenía un chequeo de rechazos
 *                                          que NUNCA corrió: `method_exists(Mail::getFacadeRoot(), 'failures')`
 *                                          da false, porque `failures()` le llega al `MailManager` por `__call`.
 *   5. `send-demo-mail`, dinámica nueva  — la carta de acceso.
 *   6. `send-demo-mail`, dinámica vieja  — el Mail 1 con credenciales.
 *
 * Los cuatro hacían lo mismo: `Mail::to($lead->email)->send(...)` y, si no tiró, anotaban `*_mail_sent_at` y
 * limpiaban `*_mail_last_error`. Con un 550 en el RCPT TO SwiftMailer no tira, así que la ficha mostraba
 * "Exitoso" para un mail que nunca salió.
 *
 * Cada punto lleva sus casos, que corren con un data provider (el mismo cuerpo, el armado de cada punto es lo
 * que cambia):
 *   (a) el servidor RECHAZA: 422 con el `model`, sin fecha de envío y con el motivo en `*_mail_last_error`
 *       (sin la casilla entera); y que no pise la fecha de un envío anterior que sí salió;
 *   (b) el control con un servidor que ACEPTA: sale, con fecha y sin error;
 *   (c) el control con `Mail::fake()`: sale exactamente un mail de la clase esperada, al email del lead
 *       (fija "sin cambiar lo que se manda ni a quién");
 *   (d) un envío exitoso DESPUÉS de uno rechazado limpia el error.
 *
 * Lo que el SPA ya consume y estos tests fijan: el 422 trae `{message, model}` y el `model` trae
 * `*_mail_last_error` cargado, que es lo que pinta la tarjeta "Fallido".
 */
class EnviosDelPanelRechazadosTest extends BaseDeMailsDeLead
{
    /**
     * Los cuatro puntos del panel, para el data provider.
     *
     * @return array<string, array<int, string>>
     */
    public static function puntos_del_panel(): array
    {
        return [
            'punto 3: tarjeta de presentación'           => ['presentacion'],
            'punto 4: seguimiento (Mail 2)'              => ['seguimiento'],
            'punto 5: carta de acceso (dinámica nueva)'  => ['carta'],
            'punto 6: Mail 1 (dinámica vieja)'           => ['mail_1'],
        ];
    }

    /**
     * Cómo se arma y se dispara cada punto: la URL, el lead que lo habilita, las columnas que anota, la clase
     * de mail que sale y cómo arranca el mensaje de error de la respuesta.
     *
     * @param string $clave Una de las claves de `puntos_del_panel()`.
     *
     * @return array{url: callable, armar: callable, col_enviado: string, col_error: string, mailable: string, prefijo: string}
     */
    private function punto(string $clave): array
    {
        switch ($clave) {
            case 'presentacion':
                return [
                    'url'         => function (Lead $lead) {
                        return '/api/admin/lead/' . $lead->id . '/send-presentation-mail';
                    },
                    'armar'       => function (array $extra) {
                        return $this->crear_lead($extra);
                    },
                    'col_enviado' => 'presentation_mail_sent_at',
                    'col_error'   => 'presentation_mail_last_error',
                    'mailable'    => ComercioCityMail::class,
                    'prefijo'     => 'No se pudo enviar el mail: ',
                ];

            case 'seguimiento':
                return [
                    'url'         => function (Lead $lead) {
                        return '/api/admin/lead/' . $lead->id . '/send-followup-mail';
                    },
                    'armar'       => function (array $extra) {
                        return $this->crear_lead($extra);
                    },
                    'col_enviado' => 'followup_mail_sent_at',
                    'col_error'   => 'followup_mail_last_error',
                    'mailable'    => LeadProposalMail::class,
                    'prefijo'     => 'No se pudo enviar el mail de seguimiento: ',
                ];

            case 'carta':
                return [
                    'url'         => function (Lead $lead) {
                        return '/api/admin/lead/' . $lead->id . '/send-demo-mail';
                    },
                    'armar'       => function (array $extra) {
                        return $this->crear_lead_de_la_dinamica_nueva($extra);
                    },
                    'col_enviado' => 'demo_mail_sent_at',
                    'col_error'   => 'demo_mail_last_error',
                    'mailable'    => LeadDemoAccesoMail::class,
                    'prefijo'     => 'No se pudo enviar el mail de acceso a la demo: ',
                ];

            default:
                return [
                    'url'         => function (Lead $lead) {
                        return '/api/admin/lead/' . $lead->id . '/send-demo-mail';
                    },
                    'armar'       => function (array $extra) {
                        return $this->crear_lead_de_la_dinamica_vieja($extra);
                    },
                    'col_enviado' => 'demo_mail_sent_at',
                    'col_error'   => 'demo_mail_last_error',
                    'mailable'    => LeadDemoMail::class,
                    'prefijo'     => 'No se pudo enviar el mail de demo: ',
                ];
        }
    }

    /**
     * Dispara el envío del punto como un admin autenticado por Sanctum (así llama admin-spa).
     *
     * @param array<string, mixed> $punto Descriptor de `punto()`.
     * @param Lead                 $lead  Lead al que se le manda.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function enviar(array $punto, Lead $lead)
    {
        return $this->actingAs($this->crear_admin(), 'sanctum')->postJson($punto['url']($lead));
    }

    /**
     * (a) El centro del defecto: el servidor contesta 550, `send()` vuelve normal, y el envío NO puede quedar
     * como hecho. La respuesta es el 422 de siempre (`message` + `model`), la ficha no tiene fecha de envío y
     * el motivo —sin la casilla entera— queda en `*_mail_last_error`, que es lo que pinta la tarjeta "Fallido".
     *
     * @dataProvider puntos_del_panel
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_un_rechazo_del_servidor_no_queda_como_enviado(string $clave): void
    {
        $punto = $this->punto($clave);
        $lead  = $punto['armar']([]);

        $this->el_servidor_rechaza();

        $respuesta = $this->enviar($punto, $lead);

        // Primero lo que importa: la ficha. Si el servidor dijo 550 y la ficha tiene fecha de envío, el sistema miente.
        $this->afirmar_que_el_envio_no_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error']);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('message', $punto['prefijo'] . $this->el_motivo());
        $this->assertStringNotContainsString(self::CASILLA, (string) $respuesta->json('message'), 'La respuesta no repite la casilla.');

        // El SPA repinta la ficha con el `model` de la respuesta de error: ahí tiene que venir el fallo cargado.
        $respuesta->assertJsonPath('model.id', $lead->id);
        $respuesta->assertJsonPath('model.' . $punto['col_error'], $this->el_motivo());
        $respuesta->assertJsonPath('model.' . $punto['col_enviado'], null);
    }

    /**
     * (a) Si ya había salido un mail antes, un intento rechazado NO pisa esa fecha: sigue siendo la del envío
     * que sí salió, y el error del intento fallido queda anotado al lado.
     *
     * @dataProvider puntos_del_panel
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_un_rechazo_no_pisa_la_fecha_de_un_envio_anterior(string $clave): void
    {
        $punto = $this->punto($clave);
        $lead  = $punto['armar']([$punto['col_enviado'] => '2026-09-20 10:00:00']);

        // Lo que quedó guardado, tal como lo devuelve el modelo (con su zona horaria): lo que tiene que sobrevivir.
        $antes = $lead->fresh()->{$punto['col_enviado']}->format('Y-m-d H:i:s');

        $this->el_servidor_rechaza();

        $respuesta = $this->enviar($punto, $lead);

        $this->afirmar_que_el_envio_no_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error'], $antes);
        $respuesta->assertStatus(422);
    }

    /**
     * (b) El control: con un servidor que ACEPTA a todos, el mismo camino sale bien, con fecha y sin error. Sin
     * esto, un arreglo que marcara todo como rechazado pasaría el test de arriba.
     *
     * @dataProvider puntos_del_panel
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_con_un_smtp_que_acepta_sale_y_queda_enviado(string $clave): void
    {
        $punto = $this->punto($clave);
        $lead  = $punto['armar']([]);

        $this->el_servidor_acepta();

        $respuesta = $this->enviar($punto, $lead);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('model.id', $lead->id);
        $respuesta->assertJsonPath('model.' . $punto['col_error'], null);
        $this->assertNotNull($respuesta->json('model.' . $punto['col_enviado']), 'El model de la respuesta trae la fecha de envío.');

        $this->afirmar_que_el_envio_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error']);
    }

    /**
     * (c) El control de "qué sale y a quién": con `Mail::fake()` sale exactamente UN mail, de la clase de ese
     * punto, al email del lead, y no sale ninguna otra cosa. Fija que el arreglo no cambia lo que se manda ni a
     * quién, y que bajo el falso (que responde `failures()` vacío) el helper no tira.
     *
     * @dataProvider puntos_del_panel
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_sale_exactamente_un_mail_de_la_clase_esperada_al_email_del_lead(string $clave): void
    {
        $punto = $this->punto($clave);
        $lead  = $punto['armar']([]);

        Mail::fake();

        $this->enviar($punto, $lead)->assertStatus(200);

        Mail::assertSent($punto['mailable'], 1);
        Mail::assertSent($punto['mailable'], function (Mailable $mail) use ($lead) {
            return $mail->hasTo($lead->email) && count($mail->to) === 1;
        });
        $this->assertCount(1, Mail::sent(Mailable::class), 'No sale ningún otro mail además del esperado.');

        $this->afirmar_que_el_envio_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error']);
    }

    /**
     * (d) Un envío que sale bien DESPUÉS de uno rechazado limpia el error: sin esto la tarjeta del panel
     * quedaría en "Fallido" para siempre, porque ese estado tiene prioridad sobre la fecha de envío.
     *
     * @dataProvider puntos_del_panel
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_un_envio_exitoso_despues_de_un_rechazo_limpia_el_error(string $clave): void
    {
        $punto = $this->punto($clave);
        $lead  = $punto['armar']([]);

        $this->el_servidor_rechaza();

        $primera = $this->enviar($punto, $lead);

        $this->afirmar_que_el_envio_no_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error']);
        $primera->assertStatus(422);

        // Se corrige la casilla en la ficha y esta vez el servidor la acepta (con el falso, que no rechaza nada).
        Mail::fake();

        $this->enviar($punto, $lead)->assertStatus(200);

        $this->afirmar_que_el_envio_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error']);
    }
}
