<?php

namespace Tests\Feature\MailsDeLeadRechazados;

use App\Mail\ComercioCityMail;
use App\Mail\LeadProposalMail;
use App\Models\Lead;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Los dos envíos de mail a un lead de la vista Blade del panel viejo (`POST leads/{lead}/send-...`, grupo
 * `auth` web), cuando el servidor de correo RECHAZA la casilla.
 *
 * Son los puntos 1 y 2 de la misión mails-a-leads-rechazados-por-smtp (6/10/2026):
 *
 *   1. `send-presentation-mail` — la tarjeta de presentación (`LeadController::send_presentation_mail`).
 *   2. `send-followup-mail`     — el seguimiento post-reunión (`LeadController::send_followup_mail`).
 *
 * Son los gemelos "con redirect" de los puntos 3 y 4 (los del SPA, ver `EnviosDelPanelRechazadosTest`): la
 * misma lógica, pero la respuesta no es un 422 con JSON sino un redirect a la ficha con el aviso en la
 * sesión (`error` o `success`). El defecto es el mismo: con un 550 en el RCPT TO SwiftMailer no tira, y
 * la ficha anotaba `*_mail_sent_at` y mostraba "Mail de presentación enviado a <casilla>" para un mail que
 * nunca salió.
 *
 * Cada punto lleva sus cuatro casos: (a) rechazo (y que no pise la fecha de un envío anterior), (b) control
 * con un servidor que acepta, (c) control con `Mail::fake()` de qué sale y a quién, (d) un envío exitoso
 * después de uno rechazado limpia el error.
 */
class EnviosBladeRechazadosTest extends BaseDeMailsDeLead
{
    /**
     * Los dos puntos Blade, para el data provider.
     *
     * @return array<string, array<int, string>>
     */
    public static function puntos_blade(): array
    {
        return [
            'punto 1: tarjeta de presentación' => ['presentacion'],
            'punto 2: seguimiento (Mail 2)'    => ['seguimiento'],
        ];
    }

    /**
     * Cómo se dispara cada punto y qué dice cada aviso de la sesión.
     *
     * @param string $clave Una de las claves de `puntos_blade()`.
     *
     * @return array{ruta: string, col_enviado: string, col_error: string, mailable: string, aviso_de_error: string, aviso_de_exito: string}
     */
    private function punto(string $clave): array
    {
        if ($clave === 'presentacion') {
            return [
                'ruta'           => 'leads.send_presentation_mail',
                'col_enviado'    => 'presentation_mail_sent_at',
                'col_error'      => 'presentation_mail_last_error',
                'mailable'       => ComercioCityMail::class,
                'aviso_de_error' => 'No se pudo enviar el mail: ',
                'aviso_de_exito' => 'Mail de presentación enviado a ',
            ];
        }

        return [
            'ruta'           => 'leads.send_followup_mail',
            'col_enviado'    => 'followup_mail_sent_at',
            'col_error'      => 'followup_mail_last_error',
            'mailable'       => LeadProposalMail::class,
            'aviso_de_error' => 'No se pudo enviar el mail de seguimiento: ',
            'aviso_de_exito' => 'Mail de seguimiento enviado a ',
        ];
    }

    /**
     * Dispara el envío del punto como un admin logueado en la vista Blade (guard `web`, sesión).
     *
     * @param array<string, string> $punto Descriptor de `punto()`.
     * @param Lead                  $lead  Lead al que se le manda.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function enviar(array $punto, Lead $lead)
    {
        return $this->actingAs($this->crear_admin())->post(route($punto['ruta'], ['lead' => $lead->id]));
    }

    /**
     * (a) El centro del defecto: el servidor contesta 550 y el envío NO puede quedar como hecho. Vuelve a la
     * ficha con el aviso de error (el motivo, sin la casilla), sin fecha de envío y con el motivo en
     * `*_mail_last_error`. Y sobre todo: NO dice "enviado".
     *
     * @dataProvider puntos_blade
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_un_rechazo_del_servidor_no_queda_como_enviado(string $clave): void
    {
        $punto = $this->punto($clave);
        $lead  = $this->crear_lead();

        $this->el_servidor_rechaza();

        $respuesta = $this->enviar($punto, $lead);

        // Primero lo que importa: la ficha. Si el servidor dijo 550 y la ficha tiene fecha de envío, el sistema miente.
        $this->afirmar_que_el_envio_no_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error']);

        $respuesta->assertRedirect(route('leads.show', $lead->id));
        $respuesta->assertSessionHas('error', $punto['aviso_de_error'] . $this->el_motivo());
        $respuesta->assertSessionMissing('success');
    }

    /**
     * (a) Si ya había salido un mail antes, un intento rechazado NO pisa esa fecha.
     *
     * @dataProvider puntos_blade
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_un_rechazo_no_pisa_la_fecha_de_un_envio_anterior(string $clave): void
    {
        $punto = $this->punto($clave);
        $lead  = $this->crear_lead([$punto['col_enviado'] => '2026-09-20 10:00:00']);

        // Lo que quedó guardado, tal como lo devuelve el modelo (con su zona horaria): lo que tiene que sobrevivir.
        $antes = $lead->fresh()->{$punto['col_enviado']}->format('Y-m-d H:i:s');

        $this->el_servidor_rechaza();

        $respuesta = $this->enviar($punto, $lead);

        $this->afirmar_que_el_envio_no_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error'], $antes);
        $respuesta->assertSessionHas('error');
    }

    /**
     * (b) El control: con un servidor que ACEPTA a todos, el mismo camino sale bien: aviso de éxito, fecha de
     * envío y sin error.
     *
     * @dataProvider puntos_blade
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_con_un_smtp_que_acepta_sale_y_queda_enviado(string $clave): void
    {
        $punto = $this->punto($clave);
        $lead  = $this->crear_lead();

        $this->el_servidor_acepta();

        $respuesta = $this->enviar($punto, $lead);

        $respuesta->assertRedirect(route('leads.show', $lead->id));
        $respuesta->assertSessionHas('success', $punto['aviso_de_exito'] . $lead->email);
        $respuesta->assertSessionMissing('error');

        $this->afirmar_que_el_envio_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error']);
    }

    /**
     * (c) El control de "qué sale y a quién": con `Mail::fake()` sale exactamente UN mail, de la clase de ese
     * punto, al email del lead, y ningún otro.
     *
     * @dataProvider puntos_blade
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_sale_exactamente_un_mail_de_la_clase_esperada_al_email_del_lead(string $clave): void
    {
        $punto = $this->punto($clave);
        $lead  = $this->crear_lead();

        Mail::fake();

        $this->enviar($punto, $lead)->assertSessionHas('success');

        Mail::assertSent($punto['mailable'], 1);
        Mail::assertSent($punto['mailable'], function (Mailable $mail) use ($lead) {
            return $mail->hasTo($lead->email) && count($mail->to) === 1;
        });
        $this->assertCount(1, Mail::sent(Mailable::class), 'No sale ningún otro mail además del esperado.');

        $this->afirmar_que_el_envio_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error']);
    }

    /**
     * (d) Un envío que sale bien DESPUÉS de uno rechazado limpia el error.
     *
     * @dataProvider puntos_blade
     *
     * @param string $clave
     *
     * @return void
     */
    public function test_un_envio_exitoso_despues_de_un_rechazo_limpia_el_error(string $clave): void
    {
        $punto = $this->punto($clave);
        $lead  = $this->crear_lead();

        $this->el_servidor_rechaza();

        $primera = $this->enviar($punto, $lead);

        $this->afirmar_que_el_envio_no_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error']);
        $primera->assertSessionHas('error');

        // Se corrige la casilla en la ficha y esta vez el servidor la acepta (con el falso, que no rechaza nada).
        Mail::fake();

        $this->enviar($punto, $lead)->assertSessionHas('success');

        $this->afirmar_que_el_envio_cuenta_como_hecho($lead, $punto['col_enviado'], $punto['col_error']);
    }
}
