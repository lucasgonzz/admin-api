<?php

namespace Tests\Feature\BandejaDeLeads;

use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El resumen de acciones de la burbuja (`LeadMessage::build_actions_summary()`) con las acciones que
 * el agente devuelve desde la misión flujo-lead-landing-closer (17/10/2026):
 *
 *   - `guardar_calificacion: {rubro?, empleados?, sistema_actual?}` → «Guardar calificación: …», solo
 *     con las claves que vinieron; un objeto vacío no agrega línea;
 *   - `confirmar_interes_closer: true` → «Confirmar interés en la llamada con Thomás»;
 *   - `agendar_demo: {dia, hora, inicio_resuelto}` → «Agendar demo: para el DD/MM a las HH:MM», en vez
 *     de caer en la rama vieja y decir «Agendar demo» a secas (o «ahora»).
 *
 * Es lógica pura sobre el paquete: sin base, sin red. Lo que ya resumía (`guardar_email`,
 * `agendar_demo` con `ahora`, la forma vieja con `demo_date`) tiene que seguir igual.
 */
class ResumenDeAccionesNuevasDelAgenteTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Las tres claves de la calificación, en el orden rubro → empleados → sistema actual.
     *
     * @return void
     */
    public function test_guardar_calificacion_completa(): void
    {
        $resumen = LeadMessage::build_actions_summary([
            'guardar_calificacion' => ['rubro' => 'ferretería', 'empleados' => 3, 'sistema_actual' => 'Excel'],
        ], 'contactado');

        $this->assertSame(['Guardar calificación: rubro ferretería, 3 empleados, sistema actual Excel'], $resumen);
    }

    /**
     * Solo las claves que vinieron; «1 empleado» en singular.
     *
     * @return void
     */
    public function test_guardar_calificacion_parcial_muestra_solo_lo_que_vino(): void
    {
        $this->assertSame(
            ['Guardar calificación: 1 empleado'],
            LeadMessage::build_actions_summary(['guardar_calificacion' => ['empleados' => '1']], null)
        );

        $this->assertSame(
            ['Guardar calificación: rubro kiosco, sistema actual ninguno'],
            LeadMessage::build_actions_summary(['guardar_calificacion' => ['rubro' => 'kiosco', 'sistema_actual' => 'ninguno']], null)
        );
    }

    /**
     * Un objeto vacío, o con las claves vacías, no agrega línea. Y un valor que no es un objeto se ignora.
     *
     * @return void
     */
    public function test_guardar_calificacion_vacia_no_agrega_linea(): void
    {
        $this->assertSame([], LeadMessage::build_actions_summary(['guardar_calificacion' => []], null));
        $this->assertSame([], LeadMessage::build_actions_summary(['guardar_calificacion' => ['rubro' => '', 'empleados' => null]], null));
        $this->assertSame([], LeadMessage::build_actions_summary(['guardar_calificacion' => true], null));
    }

    /**
     * `confirmar_interes_closer` nombra al closer con TH (decisión D4), y en falso no agrega nada.
     *
     * @return void
     */
    public function test_confirmar_interes_closer(): void
    {
        $this->assertSame(
            ['Confirmar interés en la llamada con Thomás'],
            LeadMessage::build_actions_summary(['confirmar_interes_closer' => true], 'demo_realizada')
        );

        $this->assertSame([], LeadMessage::build_actions_summary(['confirmar_interes_closer' => false], 'demo_realizada'));
    }

    /**
     * `agendar_demo` para un momento concreto: muestra el instante resuelto por el servidor, legible, y
     * no dice «ahora».
     *
     * @return void
     */
    public function test_agendar_demo_para_un_momento_muestra_el_inicio_resuelto(): void
    {
        $resumen = LeadMessage::build_actions_summary([
            'agendar_demo' => ['dia' => 'manana', 'hora' => '10:00', 'inicio_resuelto' => '2026-10-20 10:00', 'demo_id_previsto' => 3],
        ], 'solicita_disponibilidad');

        $this->assertSame(['Agendar demo: para el 20/10 a las 10:00'], $resumen);
        $this->assertStringNotContainsString('ahora', $resumen[0]);
    }

    /**
     * Si el servidor no pudo resolver el instante (`inicio_ilegible`), se muestra lo que dijo el agente.
     *
     * @return void
     */
    public function test_agendar_demo_con_inicio_ilegible_muestra_lo_que_dijo_el_agente(): void
    {
        $resumen = LeadMessage::build_actions_summary([
            'agendar_demo' => ['dia' => 'el finde', 'hora' => 'a la tarde', 'inicio_ilegible' => true],
        ], null);

        $this->assertSame(['Agendar demo: para el finde a la tarde'], $resumen);
    }

    /**
     * Lo que ya resumía sigue igual: «ahora», la forma vieja con `demo_date`, y `guardar_email`.
     *
     * @return void
     */
    public function test_las_formas_anteriores_de_agendar_demo_no_cambian(): void
    {
        $this->assertSame(
            ['Agendar demo: ahora (la instancia libre se asigna al aprobar)'],
            LeadMessage::build_actions_summary(['agendar_demo' => ['ahora' => true, 'demo_id_previsto' => 2]], null)
        );

        $this->assertSame(
            ['Agendar demo: 20/10 10:00'],
            LeadMessage::build_actions_summary(['agendar_demo' => ['demo_date' => '2026-10-20', 'demo_start_time' => '10:00']], null)
        );

        $this->assertSame(
            ['Enviar mail de acceso a la demo a juana@eltornillo.test'],
            LeadMessage::build_actions_summary(['guardar_email' => 'juana@eltornillo.test'], null)
        );
    }

    /**
     * Un paquete con varias de las acciones nuevas y viejas las lista todas, en el orden del método.
     *
     * @return void
     */
    public function test_un_paquete_con_varias_acciones_las_lista_todas(): void
    {
        $resumen = LeadMessage::build_actions_summary([
            'agendar_demo'         => ['dia' => 'hoy', 'hora' => '18:00', 'inicio_resuelto' => '2026-10-17 18:00'],
            'guardar_email'        => 'juana@eltornillo.test',
            'guardar_calificacion' => ['rubro' => 'ferretería'],
        ], null);

        $this->assertSame([
            'Agendar demo: para el 17/10 a las 18:00',
            'Enviar mail de acceso a la demo a juana@eltornillo.test',
            'Guardar calificación: rubro ferretería',
        ], $resumen);
    }
}
