<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Demo;
use App\Models\Lead;
use App\Services\LeadDemoSettings;
use App\Services\RunDemoSetupService;
use Carbon\Carbon;
use Database\Seeders\SubirDemoSetupTimeoutMinutosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dos corridas de demo setup no se pueden solapar, y el admin deja de mentir sobre cómo terminó
 * una corrida que no vio terminar (misión cruzada del 25/8/2026).
 *
 * Lo que se midió el 25/8/2026 y estos tests protegen:
 *
 *  - Una corrida SANA de `DemoSetupHelper::run()` tarda 565,7 s (9 m 26 s), contra el techo de
 *    300 s que admin-api le daba (`CLIENT_API_TIMEOUT × 20`). O sea que el camino normal terminaba
 *    SIEMPRE en timeout.
 *  - Vencido el techo, la corrida del otro lado sigue viva igual (`ignore_user_abort(true)` +
 *    `set_time_limit(0)`, a propósito), pero el admin la marcaba `fallido`, el panel volvía a
 *    mostrar el botón, y el segundo click le hacía otro `migrate:fresh` a la base que la primera
 *    corrida estaba sembrando.
 *  - Y el umbral del comando de vencimiento, en 10 minutos, era una de las causas y no la red:
 *    declaraba muerto un armado de 9 m 26 s que estaba andando bien.
 *
 * De ahí el estado `sin_confirmar`: no es un sinónimo de `fallido`, es "no sé cómo terminó". Y de
 * ahí que lo destrabe `leads:vencer-demo-setups-colgados` — un estado intermedio sin un proceso que
 * lo saque de ahí es la fuga del 13/8/2026 con otra cara (APRENDER_NO_PARCHEAR).
 */
class DemoSetupSinCorridasSolapadasTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Libera el reloj fijado por los tests que lo mueven.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Momento base fijo: la suite no puede cambiar de comportamiento según la hora a la que corra.
     *
     * @return Carbon
     */
    private function momento_base(): Carbon
    {
        return Carbon::parse('2026-08-25 10:00:00', RunDemoSetupService::TZ);
    }

    /**
     * Demo con las cuatro URLs que el modelo necesita.
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
     * Lead agendado con la dinámica y el estado de setup que pida cada caso.
     *
     * @param string      $experiencia Lead::EXPERIENCIA_NUEVA | Lead::EXPERIENCIA_ACTUAL.
     * @param string      $setup       Valor inicial de `demo_setup_status`.
     * @param Carbon|null $last_run    Valor de `demo_setup_last_run_at`.
     *
     * @return Lead
     */
    private function crear_lead(
        string $experiencia = Lead::EXPERIENCIA_NUEVA,
        string $setup = 'pendiente',
        ?Carbon $last_run = null
    ): Lead {
        $demo = $this->crear_demo();

        $inicio = $this->momento_base()->copy()->addMinutes(5);

        $lead                         = new Lead();
        $lead->uuid                   = (string) Str::uuid();
        $lead->contact_name           = 'Lead de prueba';
        $lead->company_name           = 'Empresa de prueba';
        $lead->status                 = 'demo_agendada';
        $lead->demo_id                = $demo->id;
        $lead->demo_date              = $inicio->copy()->format('Y-m-d');
        $lead->demo_start_time        = $inicio->copy()->format('H:i');
        $lead->demo_end_time          = $inicio->copy()->addMinutes(60)->format('H:i');
        $lead->demo_setup_status      = $setup;
        $lead->demo_setup_last_run_at = $last_run;
        $lead->save();

        // Después del save: el hook `creating` del modelo estampa la dinámica por defecto, así que
        // asignarla antes la pisaría.
        $lead->demo_experiencia = $experiencia;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Momento anterior al umbral de vencimiento, para dar por colgado un setup.
     *
     * @return Carbon
     */
    private function ya_vencido(): Carbon
    {
        return $this->momento_base()->copy()->subMinutes(LeadDemoSettings::get_setup_timeout_minutos() + 5);
    }

    /**
     * 1. 🔴 Un timeout de la llamada deja el lead en `sin_confirmar`, NO en `fallido`.
     *
     * Es el corazón del arreglo. El `ConnectionException` es lo que tira el cliente HTTP de Laravel
     * cuando se vence el `timeout()`; del otro lado la corrida sigue viva. Marcarla `fallido`
     * afirma algo que nadie midió y devuelve el botón al panel.
     *
     * Este test es además el caso de compatibilidad hacia atrás que importa: una instancia con un
     * deploy anterior al 22/8/2026 no sabe nada del 409, así que lo único que el admin nuevo puede
     * observar contra ella es exactamente esto — y lo tiene que manejar bien.
     */
    public function test_el_timeout_de_la_llamada_deja_el_setup_sin_confirmar(): void
    {
        Carbon::setTestNow($this->momento_base());

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $lead = $this->crear_lead();

        (new RunDemoSetupService())->run($lead);

        $lead->refresh();

        $this->assertSame(
            RunDemoSetupService::ESTADO_SIN_CONFIRMAR,
            (string) $lead->demo_setup_status,
            'Un timeout no es un fallo del armado: es no saber cómo terminó.'
        );
        $this->assertStringContainsString('Sin respuesta del setup', (string) $lead->demo_setup_last_error);
        $this->assertStringContainsString('puede seguir viva', (string) $lead->demo_setup_last_error);
    }

    /**
     * 2. 🔴 Un HTTP 409 de la instancia deja el lead en `sin_confirmar` con el motivo del candado.
     *
     * El 409 lo devuelve empresa-api cuando ya tiene una corrida viva, y lo devuelve SIN tocar la
     * base. No es un fallo: es la confirmación de que hay otro setup en curso. Si esto se marcara
     * `fallido`, el panel devolvería el botón justo encima de esa corrida.
     */
    public function test_el_409_de_la_instancia_deja_el_setup_sin_confirmar(): void
    {
        Carbon::setTestNow($this->momento_base());

        Http::fake([
            '*' => Http::response(['error' => 'Ya hay un demo setup en curso.', 'en_curso' => true], 409),
        ]);

        $lead = $this->crear_lead();

        (new RunDemoSetupService())->run($lead);

        $lead->refresh();

        $this->assertSame(
            RunDemoSetupService::ESTADO_SIN_CONFIRMAR,
            (string) $lead->demo_setup_status,
            'El 409 dice que hay otra corrida viva, no que el armado falló.'
        );
        $this->assertStringContainsString('Ya hay un demo setup corriendo', (string) $lead->demo_setup_last_error);
    }

    /**
     * 3. El resto de los códigos no exitosos sigue yendo a `fallido`, con el cuerpo guardado.
     *
     * Es la mitad que importa de los dos tests de arriba: si `sin_confirmar` se comiera también los
     * fallos reales, el estado dejaría de significar nada.
     */
    public function test_un_500_del_armado_sigue_siendo_fallido(): void
    {
        Carbon::setTestNow($this->momento_base());

        Http::fake([
            '*' => Http::response('Undefined index: doc_number', 500),
        ]);

        $lead = $this->crear_lead();

        (new RunDemoSetupService())->run($lead);

        $lead->refresh();

        $this->assertSame('fallido', (string) $lead->demo_setup_status);
        $this->assertStringContainsString('HTTP 500', (string) $lead->demo_setup_last_error);
        $this->assertStringContainsString('doc_number', (string) $lead->demo_setup_last_error);
    }

    /**
     * 4. 🔴 Un 500 dispara UNA sola llamada: la operación destructiva no se reintenta.
     *
     * En Laravel 8, con `tries > 1` una respuesta no exitosa se relanza (`PendingRequest::send`,
     * línea 702 del vendor) y le re-dispara a la instancia el `migrate:fresh` entero 500 ms
     * después. Si alguien repone el `->retry()` "para emparejarlo con el resto de los llamadores",
     * este test se pone rojo antes de que lo pague una demo.
     */
    public function test_la_llamada_destructiva_no_se_reintenta(): void
    {
        Carbon::setTestNow($this->momento_base());

        Http::fake([
            '*' => Http::response('boom', 500),
        ]);

        $lead = $this->crear_lead();

        (new RunDemoSetupService())->run($lead);

        /* Contamos sólo los POST al endpoint del setup: `run()` no hace ninguna otra llamada
         * saliente, pero contar el total ataría el test a ese detalle. */
        $disparos = 0;
        Http::recorded(function ($request) use (&$disparos) {
            if (strpos($request->url(), '/api/admin-sync/demo-setup') !== false) {
                $disparos++;
            }

            return true;
        });

        $this->assertSame(1, $disparos, 'El endpoint que vacía la base no puede tener retry automático.');
    }

    /**
     * 5. El vencimiento saca del limbo a `sin_confirmar` Y a `ejecutandose`, con motivos distintos.
     *
     * Un solo comando para los dos estados (`leads:vencer-demo-setups-colgados`): dos vencedores
     * corriendo cada minuto con umbrales distintos es exactamente la clase de cosa que después
     * nadie entiende.
     *
     * Y los motivos no dicen lo mismo porque no se sabe lo mismo: desde `ejecutandose` no hubo
     * ningún reporte y lo más probable es que el proceso se haya muerto; desde `sin_confirmar` sí
     * se sabe que la corrida seguía viva del otro lado.
     */
    public function test_el_vencimiento_destraba_los_dos_estados_intermedios(): void
    {
        Carbon::setTestNow($this->momento_base());

        $sin_confirmar = $this->crear_lead(
            Lead::EXPERIENCIA_NUEVA,
            RunDemoSetupService::ESTADO_SIN_CONFIRMAR,
            $this->ya_vencido()
        );
        $ejecutandose = $this->crear_lead(Lead::EXPERIENCIA_NUEVA, 'ejecutandose', $this->ya_vencido());

        $this->artisan('leads:vencer-demo-setups-colgados')->assertExitCode(0);

        $this->assertSame('fallido', (string) $sin_confirmar->refresh()->demo_setup_status);
        $this->assertStringContainsString(
            'seguía viva en la instancia',
            (string) $sin_confirmar->demo_setup_last_error,
            'Desde sin_confirmar se sabe que la corrida estaba viva: el texto no puede decir que el proceso murió.'
        );

        $this->assertSame('fallido', (string) $ejecutandose->refresh()->demo_setup_status);
        $this->assertStringContainsString('no reportó resultado', (string) $ejecutandose->demo_setup_last_error);
    }

    /**
     * 6. El mismo vencimiento NO toca lo que todavía no venció.
     *
     * Es la mitad que importa del test 5. Vencer antes de tiempo devuelve el botón al panel encima
     * de una corrida viva, que es exactamente el daño que la misión vino a evitar. Y un lead sin
     * `demo_setup_last_run_at` no se toca nunca: sin fecha de arranque no hay nada que medir.
     */
    public function test_el_vencimiento_no_toca_lo_que_todavia_no_vencio(): void
    {
        Carbon::setTestNow($this->momento_base());

        $reciente = $this->crear_lead(
            Lead::EXPERIENCIA_NUEVA,
            RunDemoSetupService::ESTADO_SIN_CONFIRMAR,
            $this->momento_base()->copy()->subMinute()
        );
        $sin_fecha = $this->crear_lead(
            Lead::EXPERIENCIA_NUEVA,
            RunDemoSetupService::ESTADO_SIN_CONFIRMAR,
            null
        );

        $this->artisan('leads:vencer-demo-setups-colgados')->assertExitCode(0);

        $this->assertSame(
            RunDemoSetupService::ESTADO_SIN_CONFIRMAR,
            (string) $reciente->refresh()->demo_setup_status
        );
        $this->assertSame(
            RunDemoSetupService::ESTADO_SIN_CONFIRMAR,
            (string) $sin_fecha->refresh()->demo_setup_status
        );
    }

    /**
     * 7. 🔴 El criterio de dinámica es DISTINTO para cada estado, y a propósito.
     *
     *  - Un lead 'actual' en `sin_confirmar` vencido **sí** se destraba. Ese estado nació el
     *    25/8/2026 y lo escribe `RunDemoSetupService` para las dos dinámicas (ni el timeout ni el
     *    409 miran la dinámica del lead). Si el vencimiento lo barriera sólo para la nueva, un lead
     *    'actual' quedaría colgado ahí para siempre: la fuga del 13/8/2026 otra vez.
     *  - Un lead 'actual' en `ejecutandose` vencido **no** se toca. Ahí sí hay leads viejos parados,
     *    y el criterio de aceptación de la misión 60 sigue valiendo: ningún lead con
     *    `demo_experiencia` distinto de 'nueva' cambia de comportamiento en ningún camino.
     *
     * Si alguien "unifica" el filtro en cualquiera de las dos direcciones, este test se pone rojo.
     */
    public function test_el_criterio_de_dinamica_es_distinto_para_cada_estado(): void
    {
        Carbon::setTestNow($this->momento_base());

        $actual_sin_confirmar = $this->crear_lead(
            Lead::EXPERIENCIA_ACTUAL,
            RunDemoSetupService::ESTADO_SIN_CONFIRMAR,
            $this->ya_vencido()
        );
        $actual_ejecutandose = $this->crear_lead(
            Lead::EXPERIENCIA_ACTUAL,
            'ejecutandose',
            $this->ya_vencido()
        );

        $this->artisan('leads:vencer-demo-setups-colgados')->assertExitCode(0);

        $this->assertSame(
            'fallido',
            (string) $actual_sin_confirmar->refresh()->demo_setup_status,
            'sin_confirmar se destraba para las dos dinámicas: nadie puede quedarse colgado ahí.'
        );
        $this->assertStringContainsString('seguía viva en la instancia', (string) $actual_sin_confirmar->demo_setup_last_error);

        $this->assertSame(
            'ejecutandose',
            (string) $actual_ejecutandose->refresh()->demo_setup_status,
            'ejecutandose sigue siendo sólo de la dinámica nueva, como desde la misión 60.'
        );
        $this->assertEmpty((string) $actual_ejecutandose->demo_setup_last_error);
    }

    /**
     * 8. 🔴 Un `exitoso` ya escrito no se pisa con `sin_confirmar`.
     *
     * Es el orden normal desde que el techo de la llamada son 900 s: la instancia termina de
     * armarse, avisa por el canal de eventos (`demo.setup.completado` → `exitoso`), y recién
     * después se vence nuestra espera. Sin la guarda, ese orden dejaría al lead sin botón de
     * ingreso teniendo la demo perfectamente armada.
     */
    public function test_un_exitoso_ya_escrito_no_se_pisa_con_sin_confirmar(): void
    {
        Carbon::setTestNow($this->momento_base());

        $lead = $this->crear_lead();

        Http::fake(function () use ($lead) {
            // Simula el aviso del canal de eventos llegando mientras el POST todavía espera.
            Lead::where('id', $lead->id)->update(['demo_setup_status' => 'exitoso']);

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        (new RunDemoSetupService())->run($lead);

        $this->assertSame('exitoso', (string) $lead->refresh()->demo_setup_status);
    }

    /**
     * 9. 🔴 El seeder sube el umbral viejo a 25, y no pisa uno más alto elegido a mano.
     *
     * El default en código no alcanza para producción: `seed_defaults_if_missing()` sólo escribe
     * las claves en null, y allá `demo_setup_timeout_minutos` ya vale 10. La clave tampoco es
     * editable desde el panel. Sin el seeder, el arreglo se queda en el repo.
     *
     * Y el piso es un piso, no un techo: 25 salieron de medir 9 m 26 s en local, y el hosting
     * compartido puede necesitar más. Si alguien eligió 40, se respeta.
     */
    public function test_el_seeder_sube_el_umbral_viejo_y_respeta_uno_mas_alto(): void
    {
        // (a) El caso de producción: la clave existe y vale menos que el piso medido.
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_TIMEOUT_MINUTOS, '10');

        $this->seed(SubirDemoSetupTimeoutMinutosSeeder::class);

        $this->assertSame(
            25,
            (int) AdminSetting::get(LeadDemoSettings::KEY_SETUP_TIMEOUT_MINUTOS),
            'Un umbral de 10 minutos declara muerto un armado sano de 9 m 26 s.'
        );

        // (b) Idempotente: correrlo de nuevo sobre una base ya corregida no cambia nada.
        $this->seed(SubirDemoSetupTimeoutMinutosSeeder::class);

        $this->assertSame(25, (int) AdminSetting::get(LeadDemoSettings::KEY_SETUP_TIMEOUT_MINUTOS));

        // (c) Sólo sube: un valor mayor elegido a mano no se toca.
        AdminSetting::set(LeadDemoSettings::KEY_SETUP_TIMEOUT_MINUTOS, '40');

        $this->seed(SubirDemoSetupTimeoutMinutosSeeder::class);

        $this->assertSame(
            40,
            (int) AdminSetting::get(LeadDemoSettings::KEY_SETUP_TIMEOUT_MINUTOS),
            'El seeder nunca baja el umbral: 25 es un piso, no un techo.'
        );
    }
}
