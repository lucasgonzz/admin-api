<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La edición de los campos descriptivos y de agenda de UN lead (`PATCH claude/leads/{id}`).
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 QUE LA LISTA BLANCA SEA CERRADA Y UN CAMPO NO DECLARADO SEA 422 CON LA LISTA DE LOS
 *     VÁLIDOS. Ignorar un campo en silencio es el peor final posible: el que llamó cree que
 *     escribió y se entera tres días después mirando por qué el dato no está.
 *  2. 🔴 QUE `status` Y `phone` NO SE PUEDAN ESCRIBIR DESDE ACÁ, y que el 422 diga por dónde va
 *     cada uno. `status` tiene su propio endpoint con sus propios frenos; cambiar `phone` redirige
 *     TODOS los envíos futuros de ese lead a otro número.
 *  3. 🔴 QUE `dry_run` SEA EL DEFAULT Y NO ESCRIBA NADA.
 *  4. Que reagendar (cambiar `demo_date`) resetee los flags de recordatorio. La comparación contra
 *     el camino del panel vive en `ResetDeReagendaUnicoTest`, que es el test de la extracción.
 *  5. Que un campo que llega con el valor que ya tiene no cuente como escritura: reintentar la
 *     misma llamada tiene que ser barato y seguro.
 */
class EdicionDeCamposDeLeadPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del bloque claude/*. */
    const CLAVE = 'clave-de-prueba-campos-de-lead';

    /**
     * Setea la clave de ingesta: en el `.env.testing` del slot está vacía y el middleware es
     * fail-closed.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Lead de prueba, con los campos que después se van a editar ya cargados.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead                              = new Lead();
        $lead->uuid                        = (string) Str::uuid();
        $lead->contact_name                = 'Juana Pérez';
        $lead->company_name                = 'Distribuidora Pérez';
        $lead->business_type               = 'almacen';
        $lead->phone                       = '+549341' . random_int(1000000, 9999999);
        $lead->status                      = 'calificado';
        $lead->demo_date                   = '2026-09-10';
        $lead->demo_start_time             = '18:00';
        $lead->recordatorio_demo_enviado   = true;
        $lead->recordatorio_manana_enviado = true;
        $lead->demo_fin_check_reprogramado_para = '2026-09-10 19:15:00';
        $lead->save();

        return $lead;
    }

    /**
     * Pega al endpoint.
     *
     * @param Lead                 $lead    Lead objetivo.
     * @param array<string, mixed> $payload Body completo.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pegar(Lead $lead, array $payload)
    {
        return $this->withHeaders($this->headers())->patchJson('/api/claude/leads/' . $lead->id, $payload);
    }

    /**
     * Simula y después aplica, con el `confirm_count` que devolvió la simulación.
     *
     * @param Lead                 $lead   Lead objetivo.
     * @param array<string, mixed> $campos Campos a escribir.
     *
     * @return \Illuminate\Testing\TestResponse La respuesta de la escritura real.
     */
    private function simular_y_aplicar(Lead $lead, array $campos)
    {
        $simulacion = $this->pegar($lead, ['campos' => $campos]);
        $simulacion->assertStatus(200);

        return $this->pegar($lead, [
            'campos'        => $campos,
            'dry_run'       => false,
            'confirm_count' => $simulacion->json('cambiarian'),
        ]);
    }

    /* ------------------------------------------------------------------------------------------
     | La puerta
     |------------------------------------------------------------------------------------------ */

    /**
     * Sin la clave del header no entra nada.
     *
     * @return void
     */
    public function test_sin_la_clave_el_endpoint_rechaza(): void
    {
        $lead = $this->crear_lead();

        $this->patchJson('/api/claude/leads/' . $lead->id, [
            'campos'  => ['contact_name' => 'Otro nombre'],
            'dry_run' => false,
        ])->assertStatus(401);

        $this->assertSame('Juana Pérez', $lead->fresh()->contact_name);
    }

    /**
     * Un lead que no existe es 404.
     *
     * @return void
     */
    public function test_un_lead_que_no_existe_es_404(): void
    {
        $this->withHeaders($this->headers())
            ->patchJson('/api/claude/leads/99999999', ['campos' => ['contact_name' => 'X']])
            ->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | Camino feliz e idempotencia
     |------------------------------------------------------------------------------------------ */

    /**
     * El camino feliz: simular y aplicar escribe exactamente los campos pedidos.
     *
     * @return void
     */
    public function test_el_camino_feliz_escribe_los_campos_de_la_lista_blanca(): void
    {
        $lead = $this->crear_lead();

        $response = $this->simular_y_aplicar($lead, [
            'contact_name'  => 'Juana Pérez Gómez',
            'business_type' => 'ferreteria',
            'notes'         => 'Dolor: pierde ventas por no tener stock al día.',
            'email'         => 'juana@distribuidoraperez.test',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('dry_run', false);
        $response->assertJsonPath('escritos', 4);
        $response->assertJsonPath('reagendado', false);

        $fresco = $lead->fresh();
        $this->assertSame('Juana Pérez Gómez', $fresco->contact_name);
        $this->assertSame('ferreteria', $fresco->business_type);
        $this->assertSame('Dolor: pierde ventas por no tener stock al día.', $fresco->notes);
        $this->assertSame('juana@distribuidoraperez.test', $fresco->email);
        $this->assertSame('Distribuidora Pérez', $fresco->company_name, 'Se tocó un campo que no vino en el payload.');
    }

    /**
     * 🔴 Reintentar la misma llamada no escribe nada: todos los campos quedan en `sin_cambio`.
     *
     * @return void
     */
    public function test_reintentar_la_misma_llamada_no_escribe_nada(): void
    {
        $lead   = $this->crear_lead();
        $campos = ['contact_name' => 'Juana Pérez Gómez', 'business_type' => 'ferreteria'];

        $this->simular_y_aplicar($lead, $campos)->assertStatus(200);

        $segunda = $this->pegar($lead, ['campos' => $campos]);
        $segunda->assertStatus(200);
        $segunda->assertJsonPath('cambiarian', 0);

        $sin_cambio = (array) $segunda->json('sin_cambio');
        $this->assertArrayHasKey('contact_name', $sin_cambio);
        $this->assertArrayHasKey('business_type', $sin_cambio);

        /* Y aplicada de verdad con confirm_count=0 tampoco escribe ni resetea nada. */
        $tercera = $this->pegar($lead, array_merge(['campos' => $campos], ['dry_run' => false, 'confirm_count' => 0]));
        $tercera->assertStatus(200);
        $tercera->assertJsonPath('escritos', 0);
        $tercera->assertJsonPath('reagendado', false);
    }

    /**
     * Un solo campo del payload con valor nuevo y otro con el que ya tiene: cuenta uno solo.
     *
     * @return void
     */
    public function test_un_campo_con_el_valor_que_ya_tiene_no_cuenta_como_escritura(): void
    {
        $lead = $this->crear_lead();

        $simulacion = $this->pegar($lead, ['campos' => [
            'contact_name'  => 'Juana Pérez',
            'business_type' => 'ferreteria',
        ]]);

        $simulacion->assertStatus(200);
        $simulacion->assertJsonPath('cambiarian', 1);
        $this->assertArrayHasKey('contact_name', (array) $simulacion->json('sin_cambio'));
        $this->assertArrayHasKey('business_type', (array) $simulacion->json('diff'));
    }

    /* ------------------------------------------------------------------------------------------
     | Los frenos, uno por uno
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 `dry_run` es el default y NO escribe absolutamente nada, pero devuelve el diff.
     *
     * @return void
     */
    public function test_dry_run_es_el_default_y_no_escribe_nada(): void
    {
        $lead = $this->crear_lead();

        $response = $this->pegar($lead, ['campos' => ['contact_name' => 'Nombre nuevo']]);

        $response->assertStatus(200);
        $response->assertJsonPath('dry_run', true);
        $response->assertJsonPath('cambiarian', 1);
        $response->assertJsonPath('diff.contact_name.actual', 'Juana Pérez');
        $response->assertJsonPath('diff.contact_name.propuesto', 'Nombre nuevo');

        $this->assertSame('Juana Pérez', $lead->fresh()->contact_name, 'La simulación escribió en el lead.');
    }

    /**
     * 🔴 EL FRENO CENTRAL: un campo que no está en la lista blanca es 422 con la lista de los
     * válidos Y el motivo de los prohibidos en el cuerpo.
     *
     * @return void
     */
    public function test_un_campo_no_declarado_es_422_con_la_lista_de_los_validos(): void
    {
        $lead = $this->crear_lead();

        $response = $this->pegar($lead, ['campos' => [
            'contact_name' => 'Nombre nuevo',
            'dolor'        => 'no llega a fin de mes con el stock',
        ], 'dry_run' => false, 'confirm_count' => 1]);

        $response->assertStatus(422);

        $validos = (array) $response->json('campos_validos');
        $this->assertNotEmpty($validos, 'El 422 no trajo la lista de campos válidos en el cuerpo.');
        $this->assertContains('notes', $validos, 'El lugar del "dolor detectado" es `notes` y no está en los válidos.');
        $this->assertNotContains('dolor', $validos);

        /* 🔴 Y no escribió NI SIQUIERA el campo que sí era válido: el lote entra entero o no entra. */
        $this->assertSame('Juana Pérez', $lead->fresh()->contact_name);
    }

    /**
     * 🔴 `status` y `phone` no se escriben desde acá, y el 422 dice por dónde va cada uno.
     *
     * @return void
     */
    public function test_status_y_phone_son_422_con_el_motivo_escrito(): void
    {
        $lead     = $this->crear_lead();
        $telefono = $lead->phone;

        $respuesta_status = $this->pegar($lead, ['campos' => ['status' => 'demo_agendada']]);
        $respuesta_status->assertStatus(422);
        $motivos = (array) $respuesta_status->json('motivos_de_los_prohibidos');
        $this->assertArrayHasKey('status', $motivos);
        $this->assertStringContainsString('claude/leads/{id}/status', $motivos['status']);

        $respuesta_phone = $this->pegar($lead, ['campos' => ['phone' => '+5493410000000']]);
        $respuesta_phone->assertStatus(422);
        $motivos_phone = (array) $respuesta_phone->json('motivos_de_los_prohibidos');
        $this->assertArrayHasKey('phone', $motivos_phone);

        $fresco = $lead->fresh();
        $this->assertSame('calificado', $fresco->status, 'Se escribió el status desde el endpoint de campos.');
        $this->assertSame($telefono, $fresco->phone, 'Se escribió el teléfono desde el endpoint de campos.');
    }

    /**
     * `confirm_count` que no coincide con la simulación es 422 y no escribe nada.
     *
     * @return void
     */
    public function test_confirm_count_que_no_coincide_es_422(): void
    {
        $lead = $this->crear_lead();

        $response = $this->pegar($lead, [
            'campos'        => ['contact_name' => 'Nombre nuevo', 'business_type' => 'ferreteria'],
            'dry_run'       => false,
            'confirm_count' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('cambiarian', 2);
        $this->assertSame('Juana Pérez', $lead->fresh()->contact_name);
    }

    /**
     * Sin `confirm_count` con `dry_run=false` es 422.
     *
     * @return void
     */
    public function test_sin_confirm_count_con_dry_run_false_es_422(): void
    {
        $lead = $this->crear_lead();

        $this->pegar($lead, ['campos' => ['contact_name' => 'Nombre nuevo'], 'dry_run' => false])
            ->assertStatus(422);

        $this->assertSame('Juana Pérez', $lead->fresh()->contact_name);
    }

    /**
     * 🔴 Un lead ya promovido a cliente no se toca. Mismo criterio que
     * `ClaudeLeadsPipelineController::motivo_de_bloqueo()`.
     *
     * @return void
     */
    public function test_un_lead_promovido_a_cliente_no_se_toca(): void
    {
        $client                  = new Client();
        $client->name            = 'Cliente promovido de prueba';
        $client->slug            = 'cliente-campos-claude-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->save();

        $lead                     = $this->crear_lead();
        $lead->promoted_client_id = $client->id;
        $lead->save();

        $response = $this->pegar($lead, [
            'campos'        => ['contact_name' => 'Nombre nuevo'],
            'dry_run'       => false,
            'confirm_count' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('lead_id', (int) $lead->id);
        $this->assertSame('Juana Pérez', $lead->fresh()->contact_name);
    }

    /* ------------------------------------------------------------------------------------------
     | Validación de valor, campo por campo
     |------------------------------------------------------------------------------------------ */

    /**
     * Un mail sin forma de mail es 422 y no escribe nada.
     *
     * @return void
     */
    public function test_un_email_invalido_es_422(): void
    {
        $lead = $this->crear_lead();

        $this->pegar($lead, ['campos' => ['email' => 'juana arroba algo']])->assertStatus(422);

        $this->assertNull($lead->fresh()->email);
    }

    /**
     * Una `demo_date` fuera del formato Y-m-d, o inexistente en el calendario, es 422.
     *
     * @return void
     */
    public function test_una_demo_date_mal_formada_es_422(): void
    {
        $lead = $this->crear_lead();

        $this->pegar($lead, ['campos' => ['demo_date' => '15/09/2026']])->assertStatus(422);
        $this->pegar($lead, ['campos' => ['demo_date' => '2026-02-31']])->assertStatus(422);

        $this->assertSame('2026-09-10', $lead->fresh()->getRawOriginal('demo_date'));
    }

    /**
     * Un horario que no es HH:MM en 24 horas es 422: el resto del sistema lo parsea con
     * `Carbon::parse($fecha . ' ' . $hora)` y rompería lejos de acá.
     *
     * @return void
     */
    public function test_un_horario_mal_formado_es_422(): void
    {
        $lead = $this->crear_lead();

        $this->pegar($lead, ['campos' => ['demo_start_time' => '6 de la tarde']])->assertStatus(422);
        $this->pegar($lead, ['campos' => ['demo_start_time' => '25:00']])->assertStatus(422);

        $this->assertSame('18:00', $lead->fresh()->demo_start_time);
    }

    /**
     * `demo_flexible` no admite null: la columna es NOT NULL.
     *
     * @return void
     */
    public function test_demo_flexible_en_null_es_422(): void
    {
        $lead = $this->crear_lead();

        $this->pegar($lead, ['campos' => ['demo_flexible' => null]])->assertStatus(422);
    }

    /* ------------------------------------------------------------------------------------------
     | Reagendar
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 Reagendar por este camino resetea los tres flags de recordatorio.
     *
     * La comparación contra el camino del panel —que es lo que fija la extracción del servicio—
     * está en `ResetDeReagendaUnicoTest`.
     *
     * @return void
     */
    public function test_reagendar_resetea_los_flags_de_recordatorio(): void
    {
        $lead = $this->crear_lead();

        $response = $this->simular_y_aplicar($lead, ['demo_date' => '2026-09-17']);

        $response->assertStatus(200);
        $response->assertJsonPath('reagendado', true);

        $fresco = $lead->fresh();
        $this->assertSame('2026-09-17', $fresco->getRawOriginal('demo_date'));
        $this->assertFalse((bool) $fresco->recordatorio_demo_enviado, 'No se reseteó recordatorio_demo_enviado.');
        $this->assertFalse((bool) $fresco->recordatorio_manana_enviado, 'No se reseteó recordatorio_manana_enviado.');
        $this->assertNull($fresco->demo_fin_check_reprogramado_para, 'No se reseteó demo_fin_check_reprogramado_para.');
    }

    /**
     * La simulación de una reagenda MUESTRA qué flags se van a resetear, leídos del mismo servicio
     * que después los escribe.
     *
     * @return void
     */
    public function test_la_simulacion_de_una_reagenda_muestra_los_flags_que_se_van_a_resetear(): void
    {
        $lead = $this->crear_lead();

        $response = $this->pegar($lead, ['campos' => ['demo_date' => '2026-09-17']]);

        $response->assertStatus(200);
        $reset = (array) $response->json('reset_de_reagenda');
        $this->assertArrayHasKey('recordatorio_demo_enviado', $reset);
        $this->assertArrayHasKey('recordatorio_manana_enviado', $reset);
        $this->assertArrayHasKey('demo_fin_check_reprogramado_para', $reset);

        /* Y no escribió nada. */
        $this->assertTrue((bool) $lead->fresh()->recordatorio_demo_enviado);
    }

    /**
     * Editar un campo que NO es `demo_date` no resetea ningún flag.
     *
     * @return void
     */
    public function test_editar_otro_campo_no_resetea_los_flags(): void
    {
        $lead = $this->crear_lead();

        $this->simular_y_aplicar($lead, ['contact_name' => 'Nombre nuevo'])
            ->assertStatus(200)
            ->assertJsonPath('reagendado', false);

        $fresco = $lead->fresh();
        $this->assertTrue((bool) $fresco->recordatorio_demo_enviado, 'Se reseteó un flag sin reagendar.');
        $this->assertNotNull($fresco->demo_fin_check_reprogramado_para, 'Se reseteó un flag sin reagendar.');
    }
}
