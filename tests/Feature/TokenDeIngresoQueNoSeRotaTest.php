<?php

namespace Tests\Feature;

use App\Models\Demo;
use App\Models\Lead;
use App\Services\RunDemoSetupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Correr el demo setup NO rota el token de ingreso de un lead que ya tiene uno vigente.
 *
 * Bug reportado por Lucas el 27/8/2026, en producción. Apretó "Correr demo setup ahora" sobre un
 * lead cuya instancia ya tenía una corrida viva; la instancia rebotó con 409 (candado `flock` de
 * `DemoSetupLockHelper`) y el panel le mostró "Ya hay un demo setup corriendo". Cuando después
 * abrió el link de ingreso, la demo le contestó "Este acceso a la demo ya no está disponible" —
 * con el token sin vencer y sin revocar.
 *
 * La cadena: `run()` rota el token ANTES del POST, porque el token viaja adentro del payload. Si
 * el POST rebota con 409, la instancia no tocó nada y se quedó con el token de la corrida viva,
 * mientras admin-api ya había escrito uno nuevo. El link del panel apunta entonces a un token que
 * la demo no conoce, y cada click del botón empeora la desincronización.
 *
 * El arreglo es que el setup REUTILICE el token vigente. Con eso no hay nada que rotar, así que
 * no hay nada que pueda quedar desincronizado — y de paso deja de matar el link que un lead ya
 * haya recibido por WhatsApp (hallazgo #1 del informe `20260826-link-demo-fresco-tras-setup.md`).
 *
 * 🔴 Los casos entran por `RunDemoSetupService::run()` y no por el método protegido: lo que hay
 * que probar es que el token sobrevive a la corrida entera, incluida la rama de error.
 */
class TokenDeIngresoQueNoSeRotaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Sustituye entera a la instancia: ningún test de este archivo sale a la red.
     *
     * 🔴 Va acá y NO en setUp(): `Http::fake()` acumula stubs y gana el primero que matchea, así
     * que un `'*'` registrado en setUp se comería el stub del 409 y el caso pasaría en verde sin
     * haber ejercido nunca la rama que dice probar. Es el mismo error que ya se corrigió el
     * 26/8/2026 en `LinkDeIngresoFrescoTrasAccionTest`.
     *
     * @param array<string, mixed> $stubs Stubs específicos, antes del catch-all.
     *
     * @return void
     */
    private function fakear_instancia(array $stubs = []): void
    {
        Http::fake($stubs + ['*' => Http::response(['ok' => true], 200)]);
    }

    /**
     * La instancia contesta lo mismo que `DemoSetupController::store()` cuando el candado está
     * tomado: 409 con `en_curso`.
     *
     * @return void
     */
    private function fakear_instancia_ocupada(): void
    {
        $this->fakear_instancia([
            '*/api/admin-sync/demo-setup' => Http::response([
                'error'    => 'Ya hay un demo setup corriendo en esta instancia. Esperá a que termine.',
                'en_curso' => true,
            ], 409),
        ]);
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
     * Demo con las URLs que el service necesita para resolver el destino del POST.
     *
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-erp.test';
        $demo->erp_api_url       = 'https://demo-erp-api.test';
        $demo->ecommerce_spa_url = 'https://demo-tienda.test';
        $demo->ecommerce_api_url = 'https://demo-tienda-api.test';
        $demo->save();

        return $demo;
    }

    /**
     * Lead con demo asignada y turno agendado. El token se pasa por parámetro para poder armar los
     * cuatro estados que decide el arreglo: vigente, revocado, ausente.
     *
     * @param array<string, mixed> $campos Campos del token a pisar.
     *
     * @return Lead
     */
    private function crear_lead(array $campos = []): Lead
    {
        $demo = $this->crear_demo();

        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead de prueba';
        $lead->company_name = 'Empresa de prueba';
        $lead->status       = 'demo_agendada';
        $lead->save();

        // Después del save: el hook `creating` del modelo estampa la dinámica por defecto.
        $lead->demo_id           = $demo->id;
        $lead->demo_date         = '2026-08-27';
        $lead->demo_start_time   = '09:00';
        $lead->demo_end_time     = '23:00';
        $lead->demo_setup_status = 'pendiente';

        foreach ($campos as $columna => $valor) {
            $lead->{$columna} = $valor;
        }

        $lead->save();

        return $lead->refresh();
    }

    /**
     * Token de ingreso vigente: emitido y sin revocar.
     *
     * @return array<string, mixed>
     */
    private function token_vigente(): array
    {
        return [
            'demo_ingreso_token'             => 'token-vigente-' . Str::random(40),
            'demo_ingreso_token_expira_at'   => Carbon::parse('2026-08-27 23:10:00', 'America/Argentina/Buenos_Aires'),
            'demo_ingreso_token_revocado_at' => null,
        ];
    }

    /**
     * 1. EL CASO DE LUCAS. La instancia rebota con 409 y el token del lead queda intacto.
     *
     * Sin el arreglo, `run()` ya había escrito un token nuevo antes del POST y este test da rojo:
     * el lead termina con un token que la instancia nunca recibió.
     *
     * @return void
     */
    public function test_el_409_de_la_instancia_no_deja_el_token_desincronizado(): void
    {
        $this->fakear_instancia_ocupada();

        $lead           = $this->crear_lead($this->token_vigente());
        $token_anterior = $lead->demo_ingreso_token;

        (new RunDemoSetupService())->run($lead);

        $this->assertSame(
            $token_anterior,
            $lead->fresh()->demo_ingreso_token,
            'El setup rotó el token aunque la instancia rebotó con 409: el link del panel apunta a un token que la demo no conoce.'
        );
    }

    /**
     * 2. El 409 sigue dejando el lead en `sin_confirmar` con su motivo. Es el comportamiento que ya
     *    existía y que este arreglo NO puede haber cambiado: con `fallido` el panel volvería a
     *    mostrar el botón encima de una corrida viva.
     *
     * @return void
     */
    public function test_el_409_sigue_dejando_el_lead_en_sin_confirmar(): void
    {
        $this->fakear_instancia_ocupada();

        $lead = $this->crear_lead($this->token_vigente());

        (new RunDemoSetupService())->run($lead);

        $fresco = $lead->fresh();

        $this->assertSame(RunDemoSetupService::ESTADO_SIN_CONFIRMAR, $fresco->demo_setup_status);
        $this->assertStringContainsString('Ya hay un demo setup corriendo', (string) $fresco->demo_setup_last_error);
    }

    /**
     * 3. El camino feliz tampoco rota el token, y el payload que recibe la instancia lleva ESE
     *    mismo valor. Las dos aserciones son la misma verdad vista de los dos lados: lo que queda
     *    en la base del admin y lo que viaja por el cable tienen que coincidir.
     *
     * @return void
     */
    public function test_el_setup_exitoso_reutiliza_el_token_y_lo_manda_en_el_payload(): void
    {
        $this->fakear_instancia();

        $lead           = $this->crear_lead($this->token_vigente());
        $token_anterior = $lead->demo_ingreso_token;

        (new RunDemoSetupService())->run($lead);

        $this->assertSame(
            $token_anterior,
            $lead->fresh()->demo_ingreso_token,
            'El setup exitoso rotó el token: mata el link que el lead ya pueda tener.'
        );

        Http::assertSent(function ($request) use ($token_anterior) {
            return Str::contains($request->url(), '/api/admin-sync/demo-setup')
                && $request['demo_ingreso_token'] === $token_anterior;
        });
    }

    /**
     * 4. Un token REVOCADO sí se reemplaza, y la revocación se limpia. Revocar es un acto explícito
     *    de "ese link no vale más": reutilizarlo lo resucitaría.
     *
     * @return void
     */
    public function test_un_token_revocado_se_reemplaza_por_uno_nuevo(): void
    {
        $this->fakear_instancia();

        /* `array_merge` y no `+`: la unión de arrays NO pisa las claves que ya existen, y
         * `token_vigente()` trae `demo_ingreso_token_revocado_at => null`. Con `+` la revocación
         * no se escribía y el test pasaba probando el caso equivocado. */
        $lead = $this->crear_lead(array_merge($this->token_vigente(), [
            'demo_ingreso_token_revocado_at' => Carbon::parse('2026-08-27 10:00:00'),
        ]));

        $token_anterior = $lead->demo_ingreso_token;

        $this->assertNotNull($lead->demo_ingreso_token_revocado_at, 'Precondición: el token tiene que estar revocado.');

        (new RunDemoSetupService())->run($lead);

        $fresco = $lead->fresh();

        $this->assertNotSame($token_anterior, $fresco->demo_ingreso_token, 'Un token revocado tiene que reemplazarse.');
        $this->assertNull($fresco->demo_ingreso_token_revocado_at, 'La revocación no se limpió al emitir el token nuevo.');
    }

    /**
     * 5. Un lead sin token estrena uno. Es el camino del primer setup de cualquier lead.
     *
     * @return void
     */
    public function test_un_lead_sin_token_recibe_uno_nuevo(): void
    {
        $this->fakear_instancia();

        $lead = $this->crear_lead(['demo_ingreso_token' => null]);

        (new RunDemoSetupService())->run($lead);

        $this->assertNotEmpty($lead->fresh()->demo_ingreso_token, 'Un lead sin token tiene que recibir uno.');
    }

    /**
     * 6. El VENCIMIENTO se recalcula aunque el token se reutilice. Es lo que permite que un lead que
     *    ya tiene el link entre igual después de un reagendamiento, y es el único control de tiempo
     *    que tiene ese link.
     *
     * @return void
     */
    public function test_el_vencimiento_se_recalcula_aunque_el_token_se_reutilice(): void
    {
        $this->fakear_instancia();

        $lead = $this->crear_lead($this->token_vigente());

        // El turno se corre a mañana: el vencimiento tiene que acompañar.
        $lead->demo_date     = '2026-08-28';
        $lead->demo_end_time = '23:00';
        $lead->save();

        $token_anterior     = $lead->fresh()->demo_ingreso_token;
        $vencimiento_previo = $lead->fresh()->demo_ingreso_token_expira_at;

        (new RunDemoSetupService())->run($lead);

        $fresco = $lead->fresh();

        $this->assertSame($token_anterior, $fresco->demo_ingreso_token, 'El token no tenía que cambiar.');
        $this->assertTrue(
            $fresco->demo_ingreso_token_expira_at->gt($vencimiento_previo),
            'El vencimiento no siguió al turno reagendado.'
        );
    }

    /**
     * 7. El secreto del canal de eventos sigue el mismo criterio. Si se rotara y el POST no llegara
     *    a correr, la instancia seguiría emitiendo con el viejo y `DemoEventosKey` lo rechazaría:
     *    un 401 permanente sobre el canal por el que la demo reporta lo que hace el lead.
     *
     * @return void
     */
    public function test_el_token_de_eventos_tampoco_se_rota(): void
    {
        $this->fakear_instancia_ocupada();

        /* La dinámica va explícita: el canal de eventos existe SOLO en la nueva
         * (`asegurar_token_de_ingreso()` ni le escribe la columna a un lead de la actual), así que
         * sin esto el test se saltea la rama entera que dice probar y pasa en verde sin ejercerla. */
        $lead = $this->crear_lead(array_merge($this->token_vigente(), [
            'demo_experiencia'   => Lead::EXPERIENCIA_NUEVA,
            'demo_eventos_token' => 'eventos-' . Str::random(40),
        ]));

        $this->assertTrue($lead->usa_experiencia_demo_nueva(), 'Precondición: el lead tiene que usar la dinámica nueva.');

        $token_eventos_anterior = $lead->demo_eventos_token;

        (new RunDemoSetupService())->run($lead);

        $this->assertSame(
            $token_eventos_anterior,
            $lead->fresh()->demo_eventos_token,
            'El token de eventos se rotó sin que la instancia lo reciba: la deja emitiendo contra un 401 permanente.'
        );
    }

    /**
     * 8. Bug adyacente, medido el mismo día: el `last_error` de un intento rebotado tiene que
     *    borrarse cuando una corrida termina bien.
     *
     * Con `$lead->update()` no se borraba —el modelo en memoria ya tenía `null` desde el claim, así
     * que Eloquent dejaba la columna afuera del UPDATE— y el panel mostraba "Estado: exitoso" con
     * el error del 409 colgado abajo. Se reproduce escribiendo el error por afuera, que es lo que
     * hace en producción el segundo disparo mientras el primero sigue en vuelo.
     *
     * @return void
     */
    public function test_una_corrida_exitosa_limpia_el_error_que_dejo_un_intento_anterior(): void
    {
        $this->fakear_instancia();

        $lead = $this->crear_lead($this->token_vigente());

        // Lo que dejó escrito el intento que rebotó con 409, por afuera de esta instancia en memoria.
        Lead::where('id', $lead->id)->update([
            'demo_setup_last_error' => 'Ya hay un demo setup corriendo en la instancia.',
        ]);

        (new RunDemoSetupService())->run($lead);

        $fresco = $lead->fresh();

        $this->assertSame('exitoso', $fresco->demo_setup_status);
        $this->assertNull(
            $fresco->demo_setup_last_error,
            'El panel muestra "exitoso" con el error viejo colgado abajo.'
        );
    }
}
