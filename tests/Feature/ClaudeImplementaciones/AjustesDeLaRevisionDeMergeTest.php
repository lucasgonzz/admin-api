<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Jobs\EjecutarInstalacionDeImplementacionJob;
use App\Models\AdminSetting;
use App\Models\ClientInstallation;
use App\Models\Implementation;
use App\Models\ImplementationMail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\TestHandler;

/**
 * Lo que encontró el revisor de merge de los tres últimos commits de la rama (5/10/2026, misión `implementar-cliente`) y se
 * corrigió antes de mergear:
 *
 *  - Un user setup `en_curso` se da por colgado a los 45 minutos contados desde que el job LLAMÓ al cliente
 *    (`llamada_iniciada_at`), no desde que se encoló: con la cola atrasada, un setup que está corriendo no puede figurar colgado
 *    ni invitar a reintentar.
 *  - `EjecutarInstalacionDeImplementacionJob::handle()` alinea la versión del cliente DESPUÉS de correr el grupo (hasta ahora
 *    solo se probaba el método suelto: borrar la línea de `handle()` no rompía ningún test).
 *  - El mail que SALIÓ y no se pudo anotar no repite la casilla entera ni en el log crítico ni en el `aviso`: un
 *    `QueryException` trae el SQL con sus valores.
 */
class AjustesDeLaRevisionDeMergeTest extends BaseDeImplementaciones
{
    /** Casilla del dueño: tiene que NO aparecer entera en ningún motivo ni log. */
    const CASILLA = 'dueno.secreto@ejemplo.test';

    /**
     * Sin mails reales y con la URL del formulario cargada (el mail de bienvenida la necesita).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        AdminSetting::set('implementation_form_url', 'https://admin.ejemplo.test/configuracion');
    }

    /**
     * Una implementación con el user setup en curso: etapa 2, las dos APIs y el registro `en_curso` con lo que se le pase.
     *
     * @param array<string, mixed> $registro Lo que se le suma al registro `en_curso` (iniciado_at, llamada_iniciada_at…).
     *
     * @return Implementation
     */
    private function implementacion_en_curso(array $registro): Implementation
    {
        $cliente = $this->crear_cliente('Panchito Gómez');
        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');

        $implementacion = $this->llevar_a_la_etapa($this->crear_implementacion($cliente), 2);

        $this->escribir_data_de_la_etapa($implementacion, 2, ['user_setup' => array_merge(['estado' => 'en_curso'], $registro)]);

        return $implementacion->refresh();
    }

    /* ------------------------------------------------------------------------------------------
     | "Colgado" se cuenta desde la llamada
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Los 45 minutos corren desde `llamada_iniciada_at` si el job ya llamó, y desde `iniciado_at` si no.
     *
     * Antes se contaban siempre desde que se encolaba: con la cola atrasada el job podía escribir la marca al minuto 44 y a los 45
     * el endpoint ya decía "colgado, pudo haber corrido" con la llamada viva hacía un minuto.
     *
     * @return void
     */
    public function test_los_45_minutos_de_un_colgado_se_cuentan_desde_la_llamada(): void
    {
        $casos = [
            'encolado hace 50 y la llamada salió hace 1: está corriendo'       => [50, 1, false],
            'encolado hace 50 y la llamada salió hace 46: se pasó del techo'   => [50, 46, true],
            'encolado hace 50 y la llamada nunca salió: la cola estaba parada' => [50, null, true],
            'encolado hace 10 y la llamada nunca salió: todavía espera turno'  => [10, null, false],
        ];

        foreach ($casos as $nombre => [$encolado, $llamada, $colgado]) {
            $registro = ['iniciado_at' => now()->subMinutes($encolado)->toISOString()];

            if ($llamada !== null) {
                $registro['llamada_iniciada_at'] = now()->subMinutes($llamada)->toISOString();
            }

            $implementacion = $this->implementacion_en_curso($registro);

            $respuesta = $this->getJson('/api/claude/implementations/' . $implementacion->id, $this->headers());

            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('user_setup.estado', 'en_curso');

            if ($colgado) {
                $respuesta->assertJsonPath('user_setup.colgado', true);
                $respuesta->assertJsonPath('user_setup.puede_haber_corrido', $llamada !== null);
            } else {
                $this->assertArrayNotHasKey('colgado', $respuesta->json('user_setup'), $nombre);
            }
        }
    }

    /**
     * Y el `advance` coincide con el estado: con la llamada recién salida el setup NO está colgado, así que cerrar la etapa 2 es 409.
     *
     * @return void
     */
    public function test_con_la_llamada_recien_salida_advance_da_409_aunque_se_haya_encolado_hace_rato(): void
    {
        $implementacion = $this->implementacion_en_curso([
            'iniciado_at'         => now()->subMinutes(50)->toISOString(),
            'llamada_iniciada_at' => now()->subMinute()->toISOString(),
        ]);

        $respuesta = $this->postJson(
            '/api/claude/implementations/' . $implementacion->id . '/advance',
            ['etapa_actual' => 2, 'dry_run' => false],
            $this->headers()
        );

        $respuesta->assertStatus(409);
        $this->assertStringContainsString('user setup en curso', (string) $respuesta->json('error'));
        $this->assertSame(2, (int) $implementacion->refresh()->current_stage, 'No se avanzó nada.');
    }

    /**
     * Una marca de llamada ilegible no cuenta: se cae al encolado (y no se da por colgado un setup reciente por una fecha rota).
     *
     * @return void
     */
    public function test_una_marca_de_llamada_ilegible_se_cae_al_encolado(): void
    {
        $reciente = $this->implementacion_en_curso(['iniciado_at' => now()->subMinutes(3)->toISOString(), 'llamada_iniciada_at' => 'no-es-una-fecha']);
        $viejo    = $this->implementacion_en_curso(['iniciado_at' => now()->subMinutes(50)->toISOString(), 'llamada_iniciada_at' => 'no-es-una-fecha']);

        $this->assertArrayNotHasKey(
            'colgado',
            $this->getJson('/api/claude/implementations/' . $reciente->id, $this->headers())->json('user_setup')
        );

        $this->getJson('/api/claude/implementations/' . $viejo->id, $this->headers())->assertJsonPath('user_setup.colgado', true);
    }

    /* ------------------------------------------------------------------------------------------
     | El job de instalación alinea la versión pasando por handle()
     |----------------------------------------------------------------------------------------- */

    /**
     * Un job cuyo "grupo de instalaciones" no corre el pipeline de verdad (no se puede en un test: SSH, Hostinger) sino que deja
     * las filas en el estado que se le diga, como lo haría el pipeline al terminar.
     *
     * @param array<int, string> $uuids  Los uuids de las filas.
     * @param string             $estado El estado en que las deja.
     *
     * @return EjecutarInstalacionDeImplementacionJob
     */
    private function job_que_termina_en(array $uuids, string $estado): EjecutarInstalacionDeImplementacionJob
    {
        return new class($uuids, $estado) extends EjecutarInstalacionDeImplementacionJob {
            /** @var array<int, string> */
            private $uuids_de_prueba;

            /** @var string */
            private $estado_final;

            /**
             * @param array<int, string> $uuids
             * @param string             $estado
             */
            public function __construct(array $uuids, $estado)
            {
                parent::__construct($uuids);

                $this->uuids_de_prueba = $uuids;
                $this->estado_final    = $estado;
            }

            /**
             * @return void
             */
            protected function correr_el_grupo_de_instalaciones()
            {
                ClientInstallation::query()->whereIn('uuid', $this->uuids_de_prueba)->update(['status' => $this->estado_final]);
            }
        };
    }

    /**
     * 🔴 `handle()` corre el grupo y DESPUÉS alinea la versión del cliente con la que quedó instalada. Si alguien borra esa línea de
     * `handle()`, este test falla (el método suelto ya lo probaban otros).
     *
     * @return void
     */
    public function test_handle_deja_al_cliente_en_la_version_instalada(): void
    {
        $vieja = $this->crear_version();
        $nueva = $this->crear_version();

        $cliente = $this->crear_cliente('Panchito Gómez', ['current_version_id' => $vieja->id]);
        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');

        $real = $this->crear_instalacion($cliente, ['kind' => 'completa', 'status' => 'instalando', 'version_id' => $nueva->id]);

        $this->job_que_termina_en([$real->uuid], 'completada')->handle();

        $this->assertSame((int) $nueva->id, (int) $cliente->refresh()->current_version_id);
    }

    /**
     * Y si el grupo termina mal, `handle()` no toca la versión del cliente.
     *
     * @return void
     */
    public function test_handle_no_toca_la_version_si_la_instalacion_fallo(): void
    {
        $vieja = $this->crear_version();
        $nueva = $this->crear_version();

        $cliente = $this->crear_cliente('Panchito Gómez', ['current_version_id' => $vieja->id]);
        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');

        $real = $this->crear_instalacion($cliente, ['kind' => 'completa', 'status' => 'instalando', 'version_id' => $nueva->id]);

        $this->job_que_termina_en([$real->uuid], 'fallida')->handle();

        $this->assertSame((int) $vieja->id, (int) $cliente->refresh()->current_version_id);
    }

    /* ------------------------------------------------------------------------------------------
     | El mail que salió y no se pudo anotar no repite la casilla
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El log crítico y el `aviso` de un mail que SALIÓ pero no se pudo anotar no llevan la casilla entera: el `QueryException`
     * trae el SQL con sus valores, y la casilla ya está en la columna `email` de la fila (si llegó a escribirse).
     *
     * @return void
     */
    public function test_el_log_y_el_aviso_del_mail_no_anotado_no_repiten_la_casilla(): void
    {
        // El canal `daily` pasa a un manejador en memoria: lo que la aplicación le escribe se puede leer sin tocar el disco.
        config(['logging.channels.daily' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        Log::forgetChannel('daily');

        $cliente        = $this->crear_cliente('Panchito Gómez', ['email' => self::CASILLA]);
        $cliente        = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');
        $implementacion = $this->crear_implementacion($cliente);

        ImplementationMail::saving(function () {
            throw new QueryException(
                'insert into `implementation_mails` (`email`, `estado`) values (?, ?)',
                [self::CASILLA, 'enviado'],
                new \Exception('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')
            );
        });

        $respuesta = $this->postJson(
            '/api/claude/implementations/' . $implementacion->id . '/mail',
            ['hito' => 'bienvenida', 'dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'],
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('enviado', true);

        $aviso = (string) $respuesta->json('aviso');
        $this->assertStringContainsString('NO lo reenvíes', $aviso);
        $this->assertStringContainsString('MySQL server has gone away', $aviso, 'El motivo sí tiene que estar.');
        $this->assertStringNotContainsString(self::CASILLA, $aviso);
        $this->assertStringNotContainsString('values (', $aviso, 'El SQL con sus valores no va en el aviso.');

        $criticos = [];

        foreach (Log::channel('daily')->getLogger()->getHandlers() as $manejador) {
            foreach ($manejador->getRecords() as $registro) {
                if ($registro['level_name'] === 'CRITICAL') {
                    $criticos[] = $registro;
                }
            }
        }

        $this->assertCount(1, $criticos, 'Tiene que quedar el log crítico de un mail que salió sin anotarse.');

        $volcado = $criticos[0]['message'] . ' ' . json_encode($criticos[0]['context']);

        $this->assertStringContainsString('MySQL server has gone away', $volcado);
        $this->assertStringNotContainsString(self::CASILLA, $volcado);
        $this->assertStringNotContainsString('values (', $volcado);
    }
}
