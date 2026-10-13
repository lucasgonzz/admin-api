<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\AudienciasMetaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los públicos personalizados para Meta armados con el pipeline (misión
 * origen-del-lead-y-conversiones-meta, 13/10/2026): `GET claude/audiencias` y
 * `leads:exportar-audiencias`.
 *
 * Lo que se protege: quién entra y quién no en cada etapa; que la ventana de días se mida por el
 * último mensaje DEL LEAD (un seguimiento nuestro reciente no lo mete); el hash exacto que pide Meta;
 * que una persona sea una sola fila; y que nada salga en claro.
 *
 * La base de testing del slot puede traer leads y clientes propios: por eso las afirmaciones son
 * sobre los hashes de lo sembrado acá (con teléfonos al azar), nunca sobre el total.
 */
class AudienciasMetaTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta de claude/*. */
    private const CLAVE = 'clave-de-prueba-claude-audiencias';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
        config(['services.meta_capi.token' => '', 'services.meta_capi.dataset_id' => '']);
    }

    /* ------------------------------------------------------------------------------------------
     | Ayudas
     |----------------------------------------------------------------------------------------- */

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Claude-Task-Key' => self::CLAVE, 'Accept' => 'application/json'];
    }

    /**
     * Teléfono al azar con código de país, sin formato.
     *
     * @return string
     */
    private function telefono_al_azar(): string
    {
        return '54911' . random_int(10000000, 99999999);
    }

    /**
     * @param string               $status
     * @param string|null          $telefono
     * @param string|null          $email
     * @param array<string, mixed> $extras
     *
     * @return Lead
     */
    private function crear_lead(string $status, ?string $telefono, ?string $email = null, array $extras = []): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = 'Lead ' . Str::random(6);
        $lead->phone        = $telefono;
        $lead->email        = $email;
        $lead->status       = $status;
        foreach ($extras as $columna => $valor) {
            $lead->{$columna} = $valor;
        }
        $lead->save();

        return $lead;
    }

    /**
     * Mensaje en el hilo del lead, hace N días.
     *
     * @param Lead   $lead
     * @param string $sender
     * @param int    $dias
     *
     * @return LeadMessage
     */
    private function mensaje(Lead $lead, string $sender, int $dias): LeadMessage
    {
        $mensaje                        = new LeadMessage();
        $mensaje->lead_id               = $lead->id;
        $mensaje->sender                = $sender;
        $mensaje->status                = 'enviado';
        $mensaje->is_followup           = $sender !== 'lead';
        $mensaje->requiere_verificacion = false;
        $mensaje->content               = $sender === 'lead' ? 'Hola, quería saber el precio' : '¿Pudiste ver la propuesta?';
        $mensaje->sent_at               = Carbon::now()->subDays($dias);
        $mensaje->save();

        return $mensaje;
    }

    /**
     * Cliente con teléfono y mail del dueño.
     *
     * @param string|null $telefono
     * @param string|null $email
     *
     * @return Client
     */
    private function crear_cliente(?string $telefono, ?string $email): Client
    {
        $cliente                  = new Client();
        $cliente->name            = 'Cliente ' . Str::random(5);
        $cliente->company_name    = 'Negocio de prueba';
        $cliente->slug            = 'audiencias-' . Str::lower(Str::random(10));
        $cliente->api_url         = 'https://ejemplo.test';
        $cliente->api_key         = 'clave-sembrada-' . Str::random(10);
        $cliente->inbound_api_key = 'clave-sembrada-inbound-' . Str::random(10);
        $cliente->phone           = $telefono;
        $cliente->email           = $email;
        $cliente->is_active       = true;
        $cliente->save();

        return $cliente;
    }

    /**
     * Filas de una etapa por el endpoint.
     *
     * @param string $etapa
     * @param int    $dias
     *
     * @return array<int, array<string, string|null>>
     */
    private function filas(string $etapa, int $dias = 14): array
    {
        $respuesta = $this->getJson('/api/claude/audiencias?etapa=' . $etapa . '&dias=' . $dias, $this->headers());
        $respuesta->assertStatus(200);

        return $respuesta->json('filas');
    }

    /**
     * Cuántas filas llevan este hash de teléfono.
     *
     * @param array<int, array<string, string|null>> $filas
     * @param string                                 $telefono_crudo
     *
     * @return int
     */
    private function filas_con_telefono(array $filas, string $telefono_crudo): int
    {
        $hash = AudienciasMetaService::hash_telefono($telefono_crudo);

        return count(array_filter($filas, function ($fila) use ($hash) {
            return $fila['phone_sha256'] === $hash;
        }));
    }

    /* ------------------------------------------------------------------------------------------
     | 1. El hash
     |----------------------------------------------------------------------------------------- */

    /**
     * El hash exacto que pide Meta: teléfono solo dígitos con código de país, mail sin espacios y en
     * minúsculas.
     *
     * @return void
     */
    public function test_el_hash_es_el_que_pide_meta(): void
    {
        $this->assertSame(hash('sha256', '5493411234567'), AudienciasMetaService::hash_telefono('+549 341 123-4567'));
        $this->assertSame(hash('sha256', '5493411234567'), AudienciasMetaService::hash_telefono('3411234567'));
        $this->assertSame(hash('sha256', 'ana.perez@ejemplo.com'), AudienciasMetaService::hash_email('  Ana.Perez @Ejemplo.COM '));
        $this->assertNull(AudienciasMetaService::hash_telefono(''));
        $this->assertNull(AudienciasMetaService::hash_email('sin arroba'));
    }

    /**
     * Teléfono cargado a mano → el número que se hashea (null = no encaja y no se adivina).
     *
     * @return array<string, array<int, string|null>>
     */
    public function telefonos_cargados_a_mano(): array
    {
        return [
            'ya normalizado por el webhook'      => ['+5493411234567', '5493411234567'],
            'con formato'                        => ['+549 341 123-4567', '5493411234567'],
            '10 dígitos'                         => ['3411234567', '5493411234567'],
            'con 0 y 15 (característica de 3)'   => ['0341 15 512-3456', '5493415123456'],
            'con 0 y 15 (característica de 2)'   => ['011 15 1234-5678', '5491112345678'],
            'con 0 y 15 (característica de 4)'   => ['02954 15 123456', '5492954123456'],
            'fijo con 0'                         => ['0341 4512345', '5493414512345'],
            '54 + 10'                            => ['54 341 512 3456', '5493415123456'],
            '+54 9 + 10'                         => ['+54 9 341 512-3456', '5493415123456'],
            '+54 + 15'                           => ['+54 341 15 512-3456', '5493415123456'],
            '0054 9 + 10'                        => ['0054 9 341 512 3456', '5493415123456'],
            '549 + 10 sin +'                     => ['5493415123456', '5493415123456'],
            'España con +'                       => ['+34 612 345 678', '34612345678'],
            'España con 00'                      => ['0034 612 345 678', '34612345678'],
            'Estados Unidos'                     => ['+1 (305) 555-0100', '13055550100'],
            'corto'                              => ['12345', null],
            'característica sola'                => ['0341 123', null],
            '+549 incompleto'                    => ['+549341', null],
            '11 dígitos que no son nada'         => ['34112345678', null],
        ];
    }

    /**
     * La normalización propia de las audiencias (no la del webhook, que convierte «0341 15 512-3456»
     * en un número de España y le fuerza el 549 a cualquier extranjero).
     *
     * @dataProvider telefonos_cargados_a_mano
     *
     * @param string      $crudo
     * @param string|null $esperado
     *
     * @return void
     */
    public function test_la_normalizacion_de_telefonos_cargados_a_mano(string $crudo, ?string $esperado): void
    {
        $this->assertSame(
            $esperado !== null ? hash('sha256', $esperado) : null,
            AudienciasMetaService::hash_telefono($crudo),
            'Teléfono: ' . $crudo
        );
    }

    /**
     * Un teléfono que no encaja se deja afuera y se cuenta en `descartados_por_telefono`; si el
     * contacto tiene mail, entra igual por el mail.
     *
     * @return void
     */
    public function test_los_telefonos_que_no_encajan_se_cuentan_como_descartados(): void
    {
        $antes = $this->getJson('/api/claude/audiencias?etapa=clientes', $this->headers());
        $antes->assertStatus(200);
        $descartados_antes = $antes->json('descartados_por_telefono');
        $this->assertIsInt($descartados_antes);

        $email = 'telefono.raro.' . Str::lower(Str::random(6)) . '@ejemplo.com';
        $this->crear_cliente('12345', $email);

        $despues = $this->getJson('/api/claude/audiencias?etapa=clientes', $this->headers());
        $despues->assertStatus(200);
        $this->assertSame($descartados_antes + 1, $despues->json('descartados_por_telefono'));

        $hash_email = hash('sha256', $email);
        $del_raro   = array_values(array_filter($despues->json('filas'), function ($fila) use ($hash_email) {
            return $fila['email_sha256'] === $hash_email;
        }));
        $this->assertCount(1, $del_raro, 'Con el teléfono descartado, entra por el mail.');
        $this->assertNull($del_raro[0]['phone_sha256']);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. Las tres etapas
     |----------------------------------------------------------------------------------------- */

    /**
     * conversaron_sin_agendar: entran los que escribieron en la ventana y no agendaron; quedan afuera
     * el que escribió hace más (aunque le hayamos escrito ayer), el que tiene demo, el inalcanzable y
     * el promovido. El mismo teléfono con dos formatos es UNA fila, con su mail.
     *
     * @return void
     */
    public function test_conversaron_sin_agendar(): void
    {
        $ana = $this->crear_lead('contactado', '+549 341 123-4567', ' Ana.Perez@Ejemplo.COM ');
        $this->mensaje($ana, 'lead', 2);

        /* La misma persona cargada dos veces, con el teléfono pelado y sin mail. */
        $ana_otra_vez = $this->crear_lead('nuevo', '5493411234567', null);
        $this->mensaje($ana_otra_vez, 'lead', 1);

        $calificado = $this->crear_lead('calificado', $tel_calificado = $this->telefono_al_azar());
        $this->mensaje($calificado, 'lead', 5);

        $callado = $this->crear_lead('contactado', $tel_callado = $this->telefono_al_azar());
        $this->mensaje($callado, 'lead', 20);
        $this->mensaje($callado, 'sistema', 1);

        $con_demo = $this->crear_lead('calificado', $tel_con_demo = $this->telefono_al_azar(), null, [
            'demo_date' => Carbon::now()->addDay()->format('Y-m-d'),
        ]);
        $this->mensaje($con_demo, 'lead', 1);

        $inalcanzable = $this->crear_lead('contactado', $tel_inalcanzable = $this->telefono_al_azar(), null, [
            'no_recibe_mensajes_at' => Carbon::now()->subDay(),
        ]);
        $this->mensaje($inalcanzable, 'lead', 1);

        $agendado = $this->crear_lead('demo_agendada', $tel_agendado = $this->telefono_al_azar());
        $this->mensaje($agendado, 'lead', 1);

        $filas = $this->filas(AudienciasMetaService::ETAPA_CONVERSARON_SIN_AGENDAR);

        $hash_ana = hash('sha256', '5493411234567');
        $de_ana   = array_values(array_filter($filas, function ($fila) use ($hash_ana) {
            return $fila['phone_sha256'] === $hash_ana;
        }));
        $this->assertCount(1, $de_ana, 'La misma persona tiene que quedar en una sola fila.');
        $this->assertSame(hash('sha256', 'ana.perez@ejemplo.com'), $de_ana[0]['email_sha256']);
        $this->assertSame(hash('sha256', '543411234567'), $de_ana[0]['phone_sha256_sin_9'], 'La variante sin el 9.');

        $this->assertSame(1, $this->filas_con_telefono($filas, $tel_calificado));
        $this->assertSame(0, $this->filas_con_telefono($filas, $tel_callado), 'Un seguimiento nuestro reciente no cuenta como conversación.');
        $this->assertSame(0, $this->filas_con_telefono($filas, $tel_con_demo));
        $this->assertSame(0, $this->filas_con_telefono($filas, $tel_inalcanzable));
        $this->assertSame(0, $this->filas_con_telefono($filas, $tel_agendado));

        /* Con una ventana de 30 días, el que escribió hace 20 entra. */
        $this->assertSame(1, $this->filas_con_telefono($this->filas(AudienciasMetaService::ETAPA_CONVERSARON_SIN_AGENDAR, 30), $tel_callado));

        /* Nada viaja en claro. */
        $cuerpo = json_encode($filas);
        $this->assertStringNotContainsString('5493411234567', $cuerpo);
        $this->assertStringNotContainsString('ana.perez', strtolower($cuerpo));
    }

    /**
     * agendaron_sin_entrar: demo agendada o pendiente de ingreso, sin el ingreso confirmado.
     *
     * @return void
     */
    public function test_agendaron_sin_entrar(): void
    {
        $this->crear_lead('demo_agendada', $tel_agendado = $this->telefono_al_azar(), null, ['demo_ingreso_confirmado' => false]);
        $this->crear_lead('demo_pendiente_de_ingreso', $tel_pendiente = $this->telefono_al_azar(), null, ['demo_ingreso_confirmado' => false]);
        $this->crear_lead('demo_agendada', $tel_entro = $this->telefono_al_azar(), null, ['demo_ingreso_confirmado' => true]);
        $this->crear_lead('demo_realizada', $tel_realizada = $this->telefono_al_azar());

        $filas = $this->filas(AudienciasMetaService::ETAPA_AGENDARON_SIN_ENTRAR);

        $this->assertSame(1, $this->filas_con_telefono($filas, $tel_agendado));
        $this->assertSame(1, $this->filas_con_telefono($filas, $tel_pendiente));
        $this->assertSame(0, $this->filas_con_telefono($filas, $tel_entro));
        $this->assertSame(0, $this->filas_con_telefono($filas, $tel_realizada));
    }

    /**
     * agendaron: los que agendaron ALGUNA VEZ, estén donde estén hoy (estado de demo agendada en
     * adelante, mail2_enviado incluido, o con demo_date, o con el ingreso confirmado), sin ventana de
     * días. Quedan afuera los que nunca agendaron.
     *
     * @return void
     */
    public function test_agendaron_alguna_vez(): void
    {
        $this->crear_lead('demo_realizada', $tel_realizada = $this->telefono_al_azar());
        $this->crear_lead('closer_activo', $tel_closer = $this->telefono_al_azar());
        $this->crear_lead('mail2_enviado', $tel_mail2 = $this->telefono_al_azar());
        $this->crear_lead('cerrado_ganado', $tel_ganado = $this->telefono_al_azar());
        $this->crear_lead('demo_agendada', $tel_agendado = $this->telefono_al_azar());
        $this->crear_lead('cerrado_perdido', $tel_perdido_con_demo = $this->telefono_al_azar(), null, [
            'demo_date' => Carbon::now()->subMonths(3)->format('Y-m-d'),
        ]);
        $this->crear_lead('calificado', $tel_ingreso = $this->telefono_al_azar(), null, ['demo_ingreso_confirmado' => true]);

        $this->crear_lead('contactado', $tel_contactado = $this->telefono_al_azar());
        $this->crear_lead('cerrado_perdido', $tel_perdido = $this->telefono_al_azar());
        $this->crear_lead('en_pausa', $tel_pausa = $this->telefono_al_azar());

        $filas = $this->filas(AudienciasMetaService::ETAPA_AGENDARON);

        foreach ([$tel_realizada, $tel_closer, $tel_mail2, $tel_ganado, $tel_agendado, $tel_perdido_con_demo, $tel_ingreso] as $adentro) {
            $this->assertSame(1, $this->filas_con_telefono($filas, $adentro), 'Tenía que entrar: ' . $adentro);
        }
        foreach ([$tel_contactado, $tel_perdido, $tel_pausa] as $afuera) {
            $this->assertSame(0, $this->filas_con_telefono($filas, $afuera), 'No agendó nunca: ' . $afuera);
        }
    }

    /**
     * dias=0 en conversaron_sin_agendar: sin límite de tiempo («escribieron alguna vez y no
     * agendaron»). Igual hace falta un mensaje del propio lead.
     *
     * @return void
     */
    public function test_conversaron_sin_agendar_con_dias_cero_no_tiene_limite(): void
    {
        $viejo = $this->crear_lead('contactado', $tel_viejo = $this->telefono_al_azar());
        $this->mensaje($viejo, 'lead', 200);

        $sin_mensajes_del_lead = $this->crear_lead('nuevo', $tel_mudo = $this->telefono_al_azar());
        $this->mensaje($sin_mensajes_del_lead, 'sistema', 200);

        $this->assertSame(0, $this->filas_con_telefono($this->filas(AudienciasMetaService::ETAPA_CONVERSARON_SIN_AGENDAR, 14), $tel_viejo));

        $sin_limite = $this->filas(AudienciasMetaService::ETAPA_CONVERSARON_SIN_AGENDAR, 0);
        $this->assertSame(1, $this->filas_con_telefono($sin_limite, $tel_viejo), 'Con dias=0 entra el que escribió hace 200 días.');
        $this->assertSame(0, $this->filas_con_telefono($sin_limite, $tel_mudo), 'Sin ningún mensaje del lead no conversó nunca.');
    }

    /**
     * clientes: leads promovidos o en cerrado_ganado, más la tabla clients. El lead ganado y su
     * cliente (mismo teléfono) son una sola fila con el mail del cliente.
     *
     * @return void
     */
    public function test_clientes(): void
    {
        $tel_ganado = $this->telefono_al_azar();
        $this->crear_lead('cerrado_ganado', $tel_ganado);
        $cliente = $this->crear_cliente($tel_ganado, 'Dueno.Ganado@Ejemplo.com');

        $this->crear_lead('closer_activo', $tel_promovido = $this->telefono_al_azar(), null, ['promoted_client_id' => $cliente->id]);

        $email_viejo = 'cliente.viejo.' . Str::lower(Str::random(6)) . '@ejemplo.com';
        $this->crear_cliente(null, $email_viejo);

        $this->crear_lead('cerrado_perdido', $tel_perdido = $this->telefono_al_azar());

        $filas = $this->filas(AudienciasMetaService::ETAPA_CLIENTES);

        $hash_ganado = AudienciasMetaService::hash_telefono($tel_ganado);
        $del_ganado  = array_values(array_filter($filas, function ($fila) use ($hash_ganado) {
            return $fila['phone_sha256'] === $hash_ganado;
        }));
        $this->assertCount(1, $del_ganado);
        $this->assertSame(hash('sha256', 'dueno.ganado@ejemplo.com'), $del_ganado[0]['email_sha256']);

        $this->assertSame(1, $this->filas_con_telefono($filas, $tel_promovido));
        $this->assertSame(0, $this->filas_con_telefono($filas, $tel_perdido));

        $hash_viejo = hash('sha256', $email_viejo);
        $del_viejo  = array_values(array_filter($filas, function ($fila) use ($hash_viejo) {
            return $fila['email_sha256'] === $hash_viejo;
        }));
        $this->assertCount(1, $del_viejo, 'Un cliente sin teléfono entra por el mail.');
        $this->assertNull($del_viejo[0]['phone_sha256']);
    }

    /* ------------------------------------------------------------------------------------------
     | 3. El contrato del endpoint y el comando
     |----------------------------------------------------------------------------------------- */

    /**
     * El JSON trae la forma del contrato; el CSV, el encabezado y las filas hasheadas; una etapa
     * inválida es 422 con las válidas.
     *
     * @return void
     */
    public function test_el_endpoint_respeta_el_contrato(): void
    {
        $this->crear_lead('demo_agendada', $telefono = $this->telefono_al_azar(), 'agendado@ejemplo.com');
        $this->crear_lead('demo_agendada', '+34 612 345 678');

        $json = $this->getJson('/api/claude/audiencias?etapa=agendaron_sin_entrar', $this->headers());
        $json->assertStatus(200)
            ->assertJsonPath('etapa', 'agendaron_sin_entrar')
            ->assertJsonPath('dias', 14)
            ->assertJsonStructure([
                'etapa', 'dias', 'cantidad', 'descripcion', 'descartados_por_telefono',
                'filas' => ['*' => ['phone_sha256', 'email_sha256', 'phone_sha256_sin_9']],
            ]);
        $this->assertSame(count($json->json('filas')), $json->json('cantidad'));
        $this->assertIsString($json->json('descripcion'));
        $this->assertIsInt($json->json('descartados_por_telefono'));

        /* El celular argentino trae las dos variantes; el extranjero, solo la suya. */
        $hash_con_9  = AudienciasMetaService::hash_telefono($telefono);
        $hash_sin_9  = hash('sha256', '54' . substr($telefono, 3));
        $del_agendado = array_values(array_filter($json->json('filas'), function ($fila) use ($hash_con_9) {
            return $fila['phone_sha256'] === $hash_con_9;
        }));
        $this->assertCount(1, $del_agendado);
        $this->assertSame($hash_sin_9, $del_agendado[0]['phone_sha256_sin_9']);

        $hash_extranjero = hash('sha256', '34612345678');
        $del_extranjero  = array_values(array_filter($json->json('filas'), function ($fila) use ($hash_extranjero) {
            return $fila['phone_sha256'] === $hash_extranjero;
        }));
        $this->assertCount(1, $del_extranjero);
        $this->assertNull($del_extranjero[0]['phone_sha256_sin_9']);

        /* CSV: el celular argentino en dos filas (una por variante, el mail repetido); el extranjero
           en una. */
        $csv = $this->get('/api/claude/audiencias?etapa=agendaron_sin_entrar&formato=csv', $this->headers());
        $csv->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $lineas = explode("\n", trim($csv->getContent()));
        $this->assertSame('phone,email', $lineas[0]);
        $hash_email = hash('sha256', 'agendado@ejemplo.com');
        $this->assertContains($hash_con_9 . ',' . $hash_email, $lineas);
        $this->assertContains($hash_sin_9 . ',' . $hash_email, $lineas);
        $this->assertCount(1, array_filter($lineas, function ($linea) use ($hash_extranjero) {
            return strpos($linea, $hash_extranjero) === 0;
        }));
        $this->assertStringNotContainsString($telefono, $csv->getContent());

        $this->getJson('/api/claude/audiencias?etapa=todos', $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('etapas_validas', AudienciasMetaService::ETAPAS);
        $this->assertSame(['conversaron_sin_agendar', 'agendaron_sin_entrar', 'agendaron', 'clientes'], AudienciasMetaService::ETAPAS);
        $this->getJson('/api/claude/audiencias', $this->headers())->assertStatus(422);
        $this->getJson('/api/claude/audiencias?etapa=clientes&dias=-1', $this->headers())->assertStatus(422);
        $this->getJson('/api/claude/audiencias?etapa=clientes&dias=366', $this->headers())->assertStatus(422);
        $this->getJson('/api/claude/audiencias?etapa=clientes&dias=0', $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('dias', 0);
        $this->getJson('/api/claude/audiencias?etapa=clientes&formato=xml', $this->headers())->assertStatus(422);

        /* Sin la clave de ingesta no contesta. */
        $this->getJson('/api/claude/audiencias?etapa=clientes', ['Accept' => 'application/json'])->assertStatus(401);
    }

    /**
     * `leads:exportar-audiencias` escribe el CSV en `--salida`, y una etapa inválida no escribe nada.
     *
     * @return void
     */
    public function test_el_comando_escribe_el_csv_en_la_salida(): void
    {
        $telefono = $this->telefono_al_azar();
        $this->crear_lead('cerrado_ganado', $telefono);

        $salida = storage_path('app/audiencias-test-' . Str::random(8) . '/clientes.csv');

        try {
            $this->assertSame(0, Artisan::call('leads:exportar-audiencias', ['--etapa' => 'clientes', '--salida' => $salida]));
            $this->assertFileExists($salida);

            $contenido = (string) file_get_contents($salida);
            $lineas    = explode("\n", trim($contenido));
            $this->assertSame('phone,email', $lineas[0]);
            $this->assertContains(AudienciasMetaService::hash_telefono($telefono) . ',', $lineas);
            $this->assertContains(AudienciasMetaService::hash_telefono_sin_9($telefono) . ',', $lineas, 'El celular argentino va también sin el 9.');
            $this->assertStringNotContainsString($telefono, $contenido);

            $otra = dirname($salida) . '/no-se-escribe.csv';
            $this->assertSame(1, Artisan::call('leads:exportar-audiencias', ['--etapa' => 'todos', '--salida' => $otra]));
            $this->assertFileDoesNotExist($otra);
        } finally {
            if (is_file($salida)) {
                unlink($salida);
            }
            if (is_dir(dirname($salida))) {
                rmdir(dirname($salida));
            }
        }
    }
}
