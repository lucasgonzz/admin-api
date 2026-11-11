<?php

namespace Tests\Feature\ImplementationFollowups;

use App\Models\AdminSetting;
use App\Models\ImplementationFollowup;
use App\Models\ImplementationFollowupEvent;
use App\Services\ImplementationFollowups\DiasHabiles;
use App\Services\ImplementationFollowups\ImplementationFollowupService;
use App\Services\Prueba\PruebaCicloService;
use Carbon\Carbon;

/**
 * El reloj del seguimiento (`php artisan implementaciones:seguimientos`, cada 15 minutos): altas automáticas,
 * contacto del cliente, fin de pausa, frenado tras 2 sin respuesta (con aviso a Lucas y sin escribirle al cliente),
 * terminado (implementación cerrada + hitos obligatorios) y el kill switch.
 *
 * El reloj está fijo el miércoles 11/11/2026 a las 11:00 de Argentina: 2 días hábiles después es el viernes 13 a
 * las 10:00.
 */
class RelojDelSeguimientoDeImplementacionesTest extends BaseDeSeguimientos
{
    /**
     * @return void
     */
    private function correr(): void
    {
        $this->artisan('implementaciones:seguimientos')->assertExitCode(0);
    }

    /**
     * Días hábiles: lunes a viernes, a las 10:00; un sábado cuenta desde el lunes.
     *
     * @return void
     */
    public function test_dias_habiles_y_horario(): void
    {
        $zona = 'America/Argentina/Buenos_Aires';

        $this->assertSame('2026-11-13 10:00', DiasHabiles::sumar(Carbon::parse('2026-11-11 15:00', $zona), 2)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-17 10:00', DiasHabiles::sumar(Carbon::parse('2026-11-13 09:00', $zona), 2)->format('Y-m-d H:i'), 'viernes + 2 = martes');
        $this->assertSame('2026-11-17 10:00', DiasHabiles::sumar(Carbon::parse('2026-11-14 12:00', $zona), 2)->format('Y-m-d H:i'), 'sábado + 2 = martes');
        $this->assertSame('2026-11-16 10:00', DiasHabiles::primero_despues_de(Carbon::parse('2026-11-13', $zona))->format('Y-m-d H:i'), 'después del viernes, el lunes');

        $this->assertTrue(DiasHabiles::en_horario(Carbon::parse('2026-11-11 10:00', $zona)));
        $this->assertTrue(DiasHabiles::en_horario(Carbon::parse('2026-11-11 17:59', $zona)));
        $this->assertFalse(DiasHabiles::en_horario(Carbon::parse('2026-11-11 18:00', $zona)));
        $this->assertFalse(DiasHabiles::en_horario(Carbon::parse('2026-11-11 09:59', $zona)));
        $this->assertFalse(DiasHabiles::en_horario(Carbon::parse('2026-11-14 11:00', $zona)), 'sábado');
    }

    /**
     * Alta automática: una implementación en curso de un cliente activo nace `activo`, vence a los 2 días hábiles a
     * las 10:00, con su evento `creado`. Una segunda corrida no crea otra (idempotente).
     *
     * @return void
     */
    public function test_alta_automatica_e_idempotente(): void
    {
        [$client, $implementation] = $this->escenario();

        $this->correr();
        $this->correr();

        $filas = ImplementationFollowup::where('client_id', $client->id)->get();
        $this->assertCount(1, $filas);

        $followup = $filas->first();
        $this->assertSame('activo', $followup->status);
        $this->assertSame((int) $implementation->id, (int) $followup->implementation_id);
        $this->assertSame(2, (int) $followup->interval_business_days);
        $this->assertSame(2, (int) $followup->max_unanswered);
        $this->assertSame(0, (int) $followup->unanswered_count);
        $this->assertSame('2026-11-13 10:00', $followup->next_due_at->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i'));
        $this->assertCount(1, $this->eventos($followup, ImplementationFollowupEvent::KIND_CREADO));
        $this->assertSame('sistema', $this->eventos($followup, ImplementationFollowupEvent::KIND_CREADO)->first()->source);
    }

    /**
     * Quedan afuera: el cliente en prueba gratuita, el dado de baja y la implementación que no está en curso.
     *
     * @return void
     */
    public function test_excluye_prueba_inactivos_y_no_en_curso(): void
    {
        [$en_prueba] = $this->escenario(3, ['estado' => PruebaCicloService::CLIENTE_PRUEBA]);
        [$de_baja]   = $this->escenario(3, ['is_active' => false]);
        $cerrada     = $this->crear_cliente('Cerrada');
        $this->crear_implementacion($cerrada, ['status' => 'completed', 'completed_at' => now()->subDay()]);

        $this->correr();

        $this->assertFalse(ImplementationFollowup::where('client_id', $en_prueba->id)->exists(), 'en prueba');
        $this->assertFalse(ImplementationFollowup::where('client_id', $de_baja->id)->exists(), 'dado de baja');
        $this->assertFalse(ImplementationFollowup::where('client_id', $cerrada->id)->exists(), 'implementación cerrada');
    }

    /**
     * Contacto del cliente: un entrante más nuevo que el último visto pone el contador en 0, saca de `frenado`
     * (evento `reanudado`) y corre el próximo a 2 días hábiles de ese contacto.
     *
     * @return void
     */
    public function test_el_contacto_del_cliente_reinicia_el_reloj_y_saca_de_frenado(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation, [
            'status'                 => 'frenado',
            'unanswered_count'       => 2,
            'last_client_contact_at' => now()->subDays(6),
            'next_due_at'            => now()->subDays(1),
        ]);

        $this->el_cliente_escribe($client, Carbon::parse('2026-11-11 09:30', 'America/Argentina/Buenos_Aires'));

        $this->correr();
        $followup->refresh();

        $this->assertSame('activo', $followup->status);
        $this->assertSame(0, (int) $followup->unanswered_count);
        $this->assertSame('2026-11-11 09:30', $followup->last_client_contact_at->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-13 10:00', $followup->next_due_at->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i'));
        $this->assertCount(1, $this->eventos($followup, ImplementationFollowupEvent::KIND_REANUDADO));

        /* Idempotente: otra corrida sin entrantes nuevos no cambia nada ni suma eventos. */
        $this->correr();
        $this->assertCount(1, $this->eventos($followup, ImplementationFollowupEvent::KIND_REANUDADO));
    }

    /**
     * Un contacto durante una pausa no la corta: el próximo es el primer hábil después de la pausa si es más tarde.
     *
     * @return void
     */
    public function test_el_contacto_durante_una_pausa_respeta_la_pausa(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation, [
            'status'       => 'pausado',
            'paused_until' => Carbon::parse('2026-11-20', 'America/Argentina/Buenos_Aires'),
            'next_due_at'  => Carbon::parse('2026-11-23 10:00', 'America/Argentina/Buenos_Aires'),
        ]);

        $this->el_cliente_escribe($client, now()->subMinutes(30));
        $this->correr();
        $followup->refresh();

        $this->assertSame('pausado', $followup->status);
        $this->assertSame('2026-11-23 10:00', $followup->next_due_at->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i'));
    }

    /**
     * Fin de pausa: `pausado` con `paused_until` anterior a hoy vuelve a `activo` con evento `reanudado`; una pausa
     * que vence hoy todavía no.
     *
     * @return void
     */
    public function test_fin_de_pausa(): void
    {
        [$client, $implementation] = $this->escenario();
        $vencida = $this->seguimiento($client, $implementation, [
            'status'       => 'pausado',
            'paused_until' => Carbon::parse('2026-11-10', 'America/Argentina/Buenos_Aires'),
            'next_due_at'  => Carbon::parse('2026-11-11 10:00', 'America/Argentina/Buenos_Aires'),
        ]);

        [$otro, $otra_impl] = $this->escenario();
        $hoy = $this->seguimiento($otro, $otra_impl, [
            'status'       => 'pausado',
            'paused_until' => Carbon::parse('2026-11-11', 'America/Argentina/Buenos_Aires'),
            'next_due_at'  => Carbon::parse('2026-11-12 10:00', 'America/Argentina/Buenos_Aires'),
        ]);

        $this->correr();

        $this->assertSame('activo', $vencida->refresh()->status);
        $this->assertCount(1, $this->eventos($vencida, ImplementationFollowupEvent::KIND_REANUDADO));
        $this->assertSame('pausado', $hoy->refresh()->status);
    }

    /**
     * Frenado: vencido con 2 sin respuesta → `frenado`, evento `frenado` y aviso a Lucas por push; no se le escribe
     * al cliente. Uno con 1 sin respuesta sigue activo.
     *
     * @return void
     */
    public function test_frenado_tras_dos_sin_respuesta_con_aviso_y_sin_envio(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation, ['unanswered_count' => 2]);

        [$otro, $otra_impl] = $this->escenario();
        $uno = $this->seguimiento($otro, $otra_impl, ['unanswered_count' => 1]);

        $this->correr();

        $this->assertSame('frenado', $followup->refresh()->status);
        $this->assertCount(1, $this->eventos($followup, ImplementationFollowupEvent::KIND_FRENADO));
        $this->assertSame('activo', $uno->refresh()->status);

        $avisos = $this->avisos();
        $this->assertCount(1, $avisos);
        $this->assertSame((int) $this->admin->id, $avisos[0]['admin_id']);
        $this->assertSame('frenado', $avisos[0]['data']['motivo']);
        $this->assertSame((int) $followup->id, $avisos[0]['data']['implementation_followup_id']);

        $evento = $this->eventos($followup, ImplementationFollowupEvent::KIND_FRENADO)->first();
        $this->assertSame([(int) $this->admin->id], $evento->data['aviso_a_lucas']['admin_ids']);

        $this->assertCount(0, $this->espia->textos);
        $this->assertCount(0, $this->espia->plantillas);

        /* Otra corrida no lo vuelve a avisar. */
        $this->correr();
        $this->assertCount(1, $this->avisos());
    }

    /**
     * Terminado: implementación `completed` y los 7 hitos obligatorios cumplidos o «no aplica» → `terminado` con
     * evento y aviso. Una `completed` con hitos pendientes sigue («puesta en marcha»).
     *
     * @return void
     */
    public function test_terminado_solo_con_la_implementacion_cerrada_y_los_obligatorios(): void
    {
        $hito = function ($cantidad) {
            return ['cantidad' => $cantidad, 'primero_at' => null, 'ultimo_at' => null];
        };
        $todos = [];
        foreach (ImplementationFollowupService::HITOS_OBLIGATORIOS as $clave) {
            $todos[$clave] = $hito(1);
        }
        $todos['venta_cc'] = $hito(0);
        $todos['pago_cc']  = $hito(0);

        [$client, $implementation] = $this->escenario(7);
        $implementation->status = 'completed';
        $implementation->completed_at = now()->subDay();
        $implementation->save();
        $listo = $this->seguimiento($client, $implementation, [
            'hitos'            => $todos,
            'hitos_no_aplican' => ['venta_cc', 'pago_cc'],
            'next_due_at'      => now()->addDay(),
        ]);

        [$otro, $otra_impl] = $this->escenario(7);
        $otra_impl->status = 'completed';
        $otra_impl->completed_at = now()->subDay();
        $otra_impl->save();
        $puesta_en_marcha = $this->seguimiento($otro, $otra_impl, ['hitos' => $todos, 'next_due_at' => now()->addDay()]);

        [$tercero, $en_curso] = $this->escenario(6);
        $sigue = $this->seguimiento($tercero, $en_curso, ['hitos' => $todos, 'hitos_no_aplican' => ['venta_cc', 'pago_cc'], 'next_due_at' => now()->addDay()]);

        $this->correr();

        $this->assertSame('terminado', $listo->refresh()->status);
        $this->assertCount(1, $this->eventos($listo, ImplementationFollowupEvent::KIND_TERMINADO));
        $this->assertSame('activo', $puesta_en_marcha->refresh()->status, 'completed con venta_cc y pago_cc pendientes sigue');
        $this->assertSame('activo', $sigue->refresh()->status, 'en curso no termina aunque tenga los hitos');

        $motivos = array_map(function ($aviso) {
            return $aviso['data']['motivo'];
        }, $this->avisos());
        $this->assertSame(['terminado'], $motivos);
    }

    /**
     * Un seguimiento con reserva vigente del monitor no se toca en la corrida.
     *
     * @return void
     */
    public function test_no_toca_un_seguimiento_reservado(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation, ['unanswered_count' => 2]);
        $followup->lease_token = hash('sha256', 'x');
        $followup->lease_expires_at = now()->addMinutes(10);
        $followup->save();

        $this->correr();

        $this->assertSame('activo', $followup->refresh()->status);
    }

    /**
     * Kill switch: con `implementation_followups_enabled = 0` el comando no da de alta ni frena nada.
     *
     * @return void
     */
    public function test_kill_switch_apaga_el_comando(): void
    {
        AdminSetting::set(ImplementationFollowupService::SETTING_ENABLED, '0');
        AdminSetting::flush_memo();

        [$client, $implementation] = $this->escenario();
        [$otro, $otra_impl]        = $this->escenario();
        $frenable = $this->seguimiento($otro, $otra_impl, ['unanswered_count' => 2]);

        $this->correr();

        $this->assertFalse(ImplementationFollowup::where('client_id', $client->id)->exists());
        $this->assertSame('activo', $frenable->refresh()->status);
        $this->assertCount(0, $this->avisos());
    }

    /**
     * El comando está agendado cada 15 minutos con `withoutOverlapping(10)`.
     *
     * @return void
     */
    public function test_esta_agendado_cada_quince_minutos(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $eventos  = array_values(array_filter($schedule->events(), function ($evento) {
            return strpos((string) $evento->command, 'implementaciones:seguimientos') !== false;
        }));

        $this->assertCount(1, $eventos);
        $this->assertSame('*/15 * * * *', $eventos[0]->expression);
        $this->assertTrue($eventos[0]->withoutOverlapping);
        $this->assertSame(10, $eventos[0]->expiresAt);
    }
}
