<?php

namespace Tests\Feature\FlujoLead;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadAiService;
use Database\Seeders\LeadPipelineStatusGroupOrderSeeder;
use Database\Seeders\LeadPipelineStatusSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Calificación mínima (misión flujo-lead-landing-closer, 17/10/2026, D1 del plan): la acción
 * `guardar_calificacion` escribe rubro (`business_type`), `cantidad_empleados` y `sistema_actual`, y la
 * guarda del servidor degrada `calificado` a `contactado` cuando no hay al menos un empleado registrado.
 */
class CalificacionMinimaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();

        /* El catálogo de estados con su orden real (ver GateDeAprobacionTest::setUp). */
        $this->seed(LeadPipelineStatusSeeder::class);
        $this->seed(LeadPipelineStatusGroupOrderSeeder::class);
    }

    /**
     * (1) El paquete con los tres datos los escribe y el lead queda `calificado`.
     *
     * @return void
     */
    public function test_guardar_calificacion_escribe_los_tres_datos_y_califica(): void
    {
        $lead = $this->crear_lead('contactado');

        $msg = $this->service()->aplicar($lead, [
            'mensaje_sugerido'     => 'Bárbaro, una ferretería con tres personas... ¿hoy llevan todo en Excel?',
            'estado_sugerido'      => 'calificado',
            'guardar_calificacion' => ['rubro' => 'ferretería', 'empleados' => 3, 'sistema_actual' => 'Excel'],
        ]);

        $lead->refresh();
        $this->assertSame('ferretería', (string) $lead->business_type);
        $this->assertSame(3, (int) $lead->cantidad_empleados);
        $this->assertSame('Excel', (string) $lead->sistema_actual);
        $this->assertSame('calificado', $msg->suggested_lead_status);
    }

    /**
     * (2) 🔴 Con cero empleados (trabaja solo) el `calificado` se degrada a `contactado`: el lead sigue
     *     recibiendo todo, pero no cuenta como calificado.
     *
     * @return void
     */
    public function test_con_cero_empleados_calificado_se_degrada_a_contactado(): void
    {
        $lead = $this->crear_lead('contactado');

        $msg = $this->service()->aplicar($lead, [
            'mensaje_sugerido'     => 'Dale, un kiosco donde atendés vos solo...',
            'estado_sugerido'      => 'calificado',
            'guardar_calificacion' => ['rubro' => 'kiosco', 'empleados' => 0, 'sistema_actual' => 'ninguno'],
        ]);

        $lead->refresh();
        $this->assertNotNull($lead->cantidad_empleados, 'El cero se escribe: es un dato, no la falta de uno.');
        $this->assertSame(0, (int) $lead->cantidad_empleados);
        $this->assertSame('contactado', (string) $lead->status);
        $this->assertNull($msg->suggested_lead_status, 'contactado == contactado: sin badge de cambio.');
    }

    /**
     * (2 bis) «Solo yo», «trabajo solo», «ninguno» son un dato (cero), no la falta de uno: se guarda 0 y el bloque
     *         deja de decir (sin dato), así el agente no vuelve a preguntar.
     *
     * @return void
     */
    public function test_solo_yo_y_ninguno_se_guardan_como_cero(): void
    {
        foreach (['solo yo', 'Trabajo solo', 'ninguno', 'Nadie', 'yo sola'] as $i => $dicho) {
            $lead = $this->crear_lead('contactado');
            $lead->phone = '549351999100' . $i;
            $lead->save();

            $this->service()->aplicar($lead, [
                'mensaje_sugerido'     => 'Dale.',
                'estado_sugerido'      => 'contactado',
                'guardar_calificacion' => ['empleados' => $dicho],
            ]);

            $lead->refresh();
            $this->assertNotNull($lead->cantidad_empleados, "«{$dicho}» tiene que guardarse, no quedar sin dato.");
            $this->assertSame(0, (int) $lead->cantidad_empleados, "«{$dicho}» es cero.");
        }

        /* "varios" sigue sin ser un número. */
        $vago = $this->crear_lead('contactado');
        $vago->phone = '5493519991009';
        $vago->save();
        $this->service()->aplicar($vago, [
            'mensaje_sugerido'     => 'Dale.',
            'estado_sugerido'      => 'contactado',
            'guardar_calificacion' => ['empleados' => 'varios'],
        ]);
        $this->assertNull($vago->refresh()->cantidad_empleados);
    }

    /**
     * (3) Sin ningún empleado registrado (ni antes ni en el paquete), `calificado` tampoco pasa; y
     *     recién con la cuenta en un turno posterior, sí.
     *
     * @return void
     */
    public function test_sin_empleados_registrados_no_califica_y_con_la_cuenta_despues_si(): void
    {
        $lead = $this->crear_lead('contactado');

        $primero = $this->service()->aplicar($lead, [
            'mensaje_sugerido'     => 'Una distribuidora, perfecto... ¿cuántas personas trabajan con vos?',
            'estado_sugerido'      => 'calificado',
            'guardar_calificacion' => ['rubro' => 'distribuidora'],
        ]);
        $this->assertNull($primero->suggested_lead_status);
        $this->assertSame('contactado', (string) $lead->refresh()->status);

        $segundo = $this->service()->aplicar($lead, [
            'mensaje_sugerido'     => 'Cinco, bien. ¿Y hoy usan algún sistema?',
            'estado_sugerido'      => 'calificado',
            'guardar_calificacion' => ['empleados' => '5 personas'],
        ]);
        $this->assertSame('calificado', $segundo->suggested_lead_status);
        $this->assertSame(5, (int) $lead->refresh()->cantidad_empleados, '"5 personas" se lee como 5.');
        $this->assertSame('distribuidora', (string) $lead->business_type, 'El rubro del turno anterior se conserva.');
    }

    /**
     * (4) Un valor vacío nunca pisa un dato ya cargado; uno con contenido sí (es una corrección).
     *
     * @return void
     */
    public function test_los_vacios_no_pisan_y_los_valores_si(): void
    {
        $lead                     = $this->crear_lead('contactado');
        $lead->business_type      = 'Kiosco';
        $lead->cantidad_empleados = 2;
        $lead->sistema_actual     = 'cuaderno';
        $lead->save();

        $this->service()->aplicar($lead, [
            'mensaje_sugerido'     => 'Ok.',
            'estado_sugerido'      => 'calificado',
            'guardar_calificacion' => ['rubro' => '', 'empleados' => null, 'sistema_actual' => ''],
        ]);

        $lead->refresh();
        $this->assertSame('Kiosco', (string) $lead->business_type);
        $this->assertSame(2, (int) $lead->cantidad_empleados);
        $this->assertSame('cuaderno', (string) $lead->sistema_actual);

        $this->service()->aplicar($lead, [
            'mensaje_sugerido'     => 'Ah, cuatro entonces.',
            'estado_sugerido'      => 'calificado',
            'guardar_calificacion' => ['empleados' => 4],
        ]);
        $this->assertSame(4, (int) $lead->refresh()->cantidad_empleados);
    }

    /**
     * (5) La guarda es para el AVANCE a calificado: un lead que ya está en `calificado` sin la cuenta
     *     cargada no se retrocede.
     *
     * @return void
     */
    public function test_un_lead_ya_calificado_no_se_retrocede(): void
    {
        $lead = $this->crear_lead('calificado');

        $msg = $this->service()->aplicar($lead, [
            'mensaje_sugerido' => 'Dale.',
            'estado_sugerido'  => 'calificado',
        ]);

        $this->assertNull($msg->suggested_lead_status);
        $this->assertSame('calificado', (string) $lead->refresh()->status);
    }

    /**
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $status): Lead
    {
        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->phone        = '5493519990001';
        $lead->status       = $status;
        $lead->save();

        $inbound              = new LeadMessage();
        $inbound->lead_id     = $lead->id;
        $inbound->sender      = 'lead';
        $inbound->status      = 'enviado';
        $inbound->is_followup = false;
        $inbound->content     = 'Tengo una ferretería.';
        $inbound->save();

        return $lead->refresh();
    }

    /**
     * Instancia del service con `apply_parsed_response()` expuesto (es `protected`). Mismo patrón que
     * ConfirmarIngresoYMarcarNoIngresoTest::service(); la subclase no cambia lógica.
     *
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            public function aplicar(Lead $lead, array $parsed): LeadMessage
            {
                return $this->apply_parsed_response($lead, $parsed, false);
            }
        };
    }
}
