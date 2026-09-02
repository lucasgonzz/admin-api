<?php

namespace Tests\Feature;

use App\Exceptions\HorarioYaNoDisponibleException;
use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\SyncedGithubFile;
use App\Services\LeadAiService;
use App\Services\LeadDemoSettings;
use App\Services\WhatsappProtocolService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El turno que se agenda DESPUÉS de una apertura flexible.
 *
 * EL PROBLEMA QUE ESTE ARCHIVO CUIDA. La oferta flexible ("ahora mismo o cuando te quede cómodo")
 * no declara ningún horario, así que el mensaje de apertura ya no puede caducar — eso es la mitad
 * del arreglo. Pero el problema se MUDARÍA al mensaje siguiente: el lead contesta "dale, ahora", el
 * modelo elige el primer slot de una grilla fresca, el mensaje espera aprobación, y al aprobar la
 * revalidación corre CON margen. El rescate que ya existía no lo salva, porque su permiso vive en
 * `horarios_ofrecidos` y ese horario nunca se le ofreció a nadie: lo eligió PHP hace un minuto.
 * Resultado sin este fix: 422 y lead trabado. Habríamos movido el bug de lugar.
 *
 * LA SEGUNDA FUENTE DEL PERMISO, y sus cuatro límites. `LeadMessage::ultima_oferta_fue_flexible()`
 * habilita saltarse el margen cuando lo último que el lead recibió del sistema fue una apertura
 * flexible del tramo de agenda. Eso es TODO lo que afloja: sigue siendo sólo hoy, sólo dinámica
 * nueva, sólo un slot que no arrancó y sólo un slot que nadie más tiene. Los casos 16 y 17 son
 * justamente esos dos últimos límites, y son los que impiden que esto abra doble-booking.
 *
 * 🔴 Se CALCA el montaje de OfertaAceptadaNoCaducaPorMargenTest (mismo día, misma hora, mismo
 * margen de 5 minutos, mismo borde de 17:02 contra 17:05). Ese archivo NO se edita: los dos
 * permisos son independientes y cada uno tiene que poder romperse solo.
 */
class AgendarDespuesDeUnaOfertaFlexibleTest extends TestCase
{
    use DatabaseTransactions;

    /** El día del caso original: martes, laborable. */
    const FECHA = '2026-08-25';

    /** El horario que el sistema elige de la grilla fresca cuando el lead dice "dale, ahora". */
    const HORA = '17:05';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* Ningún test de este archivo sale a la red. */
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_SABADO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_HORARIO_DOMINGO, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_CLOSER_HORARIO_LUNES_VIERNES, '00:00-23:59');
        AdminSetting::set(LeadDemoSettings::KEY_FRECUENCIA_SLOTS_MINUTOS, '5');
        AdminSetting::set(LeadDemoSettings::KEY_DURACION_MINUTOS, '60');
        AdminSetting::set(LeadDemoSettings::KEY_GRACIA_MINUTOS_POST, '10');
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_MINUTOS_ANTES, '15');
        AdminSetting::set(LeadDemoSettings::KEY_VENTANA_EXTENDIDA_MAX_HORAS, '6');

        /* Margen de 5 minutos: con el reloj a las 17:02 deja afuera las 17:05 por tres minutos, que
         * es exactamente el borde donde el permiso tiene que actuar. */
        AdminSetting::set(LeadDemoSettings::KEY_DEMO_MINIMO_MINUTOS_DESDE_AHORA, '5');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * (14) 🔴 EL CASO. Apertura flexible enviada 16:57, el lead contesta "dale, ahora", el sistema
     *      elige las 17:05 de una grilla fresca, y para cuando el admin aprueba son las 17:02: el
     *      margen ya sacó ese slot. El turno está libre y la cadena es flexible: se agenda.
     *
     * @return void
     */
    public function test_el_slot_que_eligio_el_sistema_tras_una_oferta_flexible_se_rescata_del_margen(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());
        $this->sembrar_md(true);

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->apertura_flexible_ya_enviada($lead);

        $mensaje = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        /* Montaje verificado, y es lo que hace que este test PUEDA fallar: en este instante la
         * grilla normal (con margen) ya no trae las 17:05. */
        $this->assertNotContains(
            self::HORA,
            $this->slots_de($this->grilla_con_margen($lead), $demo->id, self::FECHA),
            'Montaje inválido: las 17:05 siguen en la grilla con margen, así que el test pasaría sin el permiso flexible.'
        );

        $this->service()->apply_pending_actions($mensaje, $this->final_actions_del_panel($demo, self::FECHA, self::HORA));

        $lead->refresh();
        $this->assertSame(self::HORA, $lead->demo_start_time, 'El turno elegido tras una apertura flexible no se agendó: el bug se mudó al mensaje siguiente.');
        $this->assertSame(self::FECHA, $lead->demo_date->format('Y-m-d'));
        $this->assertSame('demo_agendada', $lead->status);
    }

    /**
     * (15) Sin apertura flexible previa, el mismo horario se sigue rechazando exactamente como
     *      antes. El permiso no es una amnistía al margen: es un permiso acotado a una cadena
     *      concreta. Acá el lead SÍ recibió un mensaje del sistema, pero uno que declaró otro
     *      horario — o sea que lo que falla no es "no hay mensajes", es que el último no fue flexible.
     *
     * @return void
     */
    public function test_sin_oferta_flexible_previa_el_mismo_horario_se_sigue_rechazando(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());
        $this->sembrar_md(true);

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();

        LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => 'Te puedo dar hoy a las 19:00. ¿Te sirve?',
            'status'                => 'enviado',
            'is_followup'           => false,
            'sent_at'               => AppTime::now(),
            'suggested_lead_status' => 'solicita_disponibilidad',
            'horarios_ofrecidos'    => [
                ['fecha' => self::FECHA, 'desde' => '19:00', 'hasta' => '19:00'],
            ],
        ]);

        $mensaje = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        $this->assertSeFrena($mensaje, $demo, $lead);
    }

    /**
     * (16) 🔴 Límite 1: "ya pasó" sigue en pie. El permiso flexible no rescata un horario que ya
     *      arrancó — de eso se ocupa la grilla margen-0, que este fix no toca.
     *
     * @return void
     */
    public function test_el_permiso_flexible_no_rescata_un_horario_que_ya_arranco(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());
        $this->sembrar_md(true);

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->apertura_flexible_ya_enviada($lead);

        $mensaje = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);

        /* El reloj se pasó del turno: 17:06 contra un turno de las 17:05. */
        Carbon::setTestNow(Carbon::parse(self::FECHA . ' 17:06:00', 'America/Argentina/Buenos_Aires'));

        $this->assertSeFrena($mensaje, $demo, $lead);
    }

    /**
     * (17) 🔴 Límite 2, y es EL que prueba que esto no abre doble-booking: "lo ocupó otro" sigue en
     *      pie. La cadena flexible es la misma, el turno no arrancó, pero otro lead ya lo tiene, y
     *      la grilla margen-0 pasa igual por el bloqueo por demo_id.
     *
     * @return void
     */
    public function test_el_permiso_flexible_no_rescata_un_slot_ocupado_por_otro_lead(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());
        $this->sembrar_md(true);

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->apertura_flexible_ya_enviada($lead);

        $mensaje = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);

        /* El que se metió en el medio: mismo demo_id, mismo día, arrancando 17:00. Con 60 de
         * duración, 15 de setup y 10 de gracia, bloquea de 16:45 a 18:10 — las 17:05 quedan
         * adentro. */
        $intruso = $this->crear_lead('demo_agendada');
        $intruso->demo_id         = $demo->id;
        $intruso->demo_date       = self::FECHA;
        $intruso->demo_start_time = '17:00';
        $intruso->demo_end_time   = '18:00';
        $intruso->save();

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        $this->assertSeFrena($mensaje, $demo, $lead);
    }

    /**
     * (18) Con el contrato apagado (el `.md` viejo todavía vivo) el permiso flexible no existe: el
     *      código nuevo es inerte hasta que el documento que lo explica está vivo, y el rollback de
     *      la misión —revertir el `.md`— apaga esto solo, sin deploy.
     *
     * @return void
     */
    public function test_con_el_contrato_apagado_el_permiso_flexible_no_existe(): void
    {
        Carbon::setTestNow($this->momento_de_la_oferta());
        $this->sembrar_md(false);

        $demo = $this->crear_demo();
        $lead = $this->crear_lead();
        $this->apertura_flexible_ya_enviada($lead);

        $mensaje = $this->mensaje_pendiente($lead, $demo, self::FECHA, self::HORA);

        Carbon::setTestNow($this->momento_de_la_aceptacion());

        $this->assertSeFrena($mensaje, $demo, $lead);
    }

    /* ------------------------------------------------------------------ */
    /* Aserciones compartidas                                              */
    /* ------------------------------------------------------------------ */

    /**
     * La aprobación se frena, no se agenda nada y el lead queda marcado para intervención humana:
     * el mismo desenlace que ya tenía cualquier horario que no se puede sostener.
     *
     * @param LeadMessage $mensaje
     * @param Demo        $demo
     * @param Lead        $lead
     *
     * @return void
     */
    private function assertSeFrena(LeadMessage $mensaje, Demo $demo, Lead $lead): void
    {
        $tiro = false;
        try {
            $this->service()->apply_pending_actions($mensaje, $this->final_actions_del_panel($demo, self::FECHA, self::HORA));
        } catch (HorarioYaNoDisponibleException $e) {
            $tiro = true;
        }

        $this->assertTrue($tiro, 'No se tiró HorarioYaNoDisponibleException: el permiso flexible se comió un límite que tenía que respetar.');
        $this->assertNull($lead->fresh()->demo_start_time, 'Se agendó un horario que el permiso flexible no podía sostener.');
    }

    /* ------------------------------------------------------------------ */
    /* Montaje                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * 16:57: el instante en que el sistema le manda la apertura flexible al lead.
     *
     * @return Carbon
     */
    private function momento_de_la_oferta(): Carbon
    {
        return Carbon::parse(self::FECHA . ' 16:57:00', 'America/Argentina/Buenos_Aires');
    }

    /**
     * 17:02: el instante en que el admin aprueba. Las 17:05 ya no pasan el margen de 5 minutos,
     * pero el turno sigue libre.
     *
     * @return Carbon
     */
    private function momento_de_la_aceptacion(): Carbon
    {
        return Carbon::parse(self::FECHA . ' 17:02:00', 'America/Argentina/Buenos_Aires');
    }

    /**
     * El recurso `demo_agenda` de la dinámica nueva: con o sin el marcador del contrato.
     *
     * @param bool $con_marcador
     *
     * @return void
     */
    private function sembrar_md(bool $con_marcador): void
    {
        SyncedGithubFile::create([
            'key'       => WhatsappProtocolService::RECURSO_KEY_PREFIX_V2 . 'demo_agenda',
            'repo_path' => 'agentes/lead/recursos/v2/demo_agenda.md',
            'content'   => $con_marcador
                ? "# Agenda de la demo\n\n## APERTURA FLEXIBLE\n\nDevolvé el campo oferta_flexible en true.\n"
                : "# Agenda de la demo\n\nOfrecele el primer horario disponible del JSON, uno solo y con la hora.\n",
            'synced_at' => AppTime::now(),
        ]);
    }

    /**
     * La apertura flexible que el lead YA recibió: sin ninguna hora en el texto y con
     * `horarios_ofrecidos` en `[]` EXPLÍCITO, que es la marca que el permiso lee.
     *
     * @param Lead $lead
     *
     * @return LeadMessage
     */
    private function apertura_flexible_ya_enviada(Lead $lead): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => 'Si querés te la dejo lista ahora mismo, o para el horario que te quede cómodo — vos decime.',
            'status'                => 'enviado',
            'is_followup'           => false,
            'is_status_event'       => false,
            'is_error'              => false,
            'sent_at'               => AppTime::now(),
            'suggested_lead_status' => 'solicita_disponibilidad',
            'horarios_ofrecidos'    => [],
        ]);
    }

    /**
     * El mensaje que quedó esperando la aprobación humana, con el paquete de agendamiento adentro.
     *
     * @param Lead   $lead
     * @param Demo   $demo
     * @param string $fecha
     * @param string $hora
     *
     * @return LeadMessage
     */
    private function mensaje_pendiente(Lead $lead, Demo $demo, string $fecha, string $hora): LeadMessage
    {
        return LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => 'Listo, te confirmo la demo hoy a las ' . $hora . '. Te paso el link apenas la preparo.',
            'status'                => 'sugerido',
            'is_followup'           => false,
            'requiere_verificacion' => true,
            'sent_by_admin_id'      => null,
            'pending_actions'       => [
                'mensaje_sugerido' => 'Listo, te confirmo la demo hoy a las ' . $hora . '. Te paso el link apenas la preparo.',
                'estado_sugerido'  => 'demo_agendada',
                'agendar_demo'     => [
                    'demo_id'         => $demo->id,
                    'demo_date'       => $fecha,
                    'demo_start_time' => $hora,
                ],
            ],
        ]);
    }

    /**
     * Lo que manda el panel de verificación al aprobar, sin editar nada.
     *
     * @param Demo   $demo
     * @param string $fecha
     * @param string $hora
     *
     * @return array<string, mixed>
     */
    private function final_actions_del_panel(Demo $demo, string $fecha, string $hora): array
    {
        return [
            'estado_sugerido' => 'demo_agendada',
            'agendar_demo'    => [
                'demo_id'         => $demo->id,
                'demo_date'       => $fecha,
                'demo_start_time' => $hora,
            ],
            'forzar_slot' => false,
        ];
    }

    /**
     * La grilla tal como la ve el agente para OFRECER: con el margen puesto (sexto argumento en
     * null), que es la que borra el slot al turno siguiente.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    private function grilla_con_margen(Lead $lead): array
    {
        $snapshot = null;
        $config   = null;
        $ventanas = null;

        return $this->service()->build_availability_json(
            LeadAiService::DIAS_DISPONIBILIDAD,
            $snapshot,
            self::FECHA,
            $lead->id,
            true,
            null,
            $config,
            $ventanas
        );
    }

    /**
     * Slots de una demo en una fecha, buscando la clave que termina en ese Y-m-d.
     *
     * @param array<string, mixed> $datos
     * @param int                  $demo_id
     * @param string               $fecha
     *
     * @return array<int, string>
     */
    private function slots_de(array $datos, int $demo_id, string $fecha): array
    {
        $por_fecha = isset($datos['demos'][$demo_id]) ? $datos['demos'][$demo_id] : [];
        foreach ($por_fecha as $label => $slots) {
            if (substr((string) $label, -strlen($fecha)) === $fecha) {
                return array_map('strval', $slots);
            }
        }

        return [];
    }

    /**
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new LeadAiService();
    }

    /**
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-erp.test';
        $demo->erp_api_url       = 'https://demo-erp-api.test';
        $demo->ecommerce_spa_url = 'https://demo-tienda.test';
        $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * Lead de la dinámica nueva, sin turno.
     *
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $status = 'calificado'): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = $status;
        $lead->save();

        /* Después del save: el hook `creating` estampa la dinámica por defecto. */
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }
}
