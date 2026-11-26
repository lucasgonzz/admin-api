<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Jobs\TranscribirGrabacionDeLlamadaJob;
use App\Models\Lead;
use App\Services\CortadorDeAudioEnTramos;
use App\Services\FfmpegLocator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Ronda 2 de la revisión de E3 (misión leads-referidos-compromisos): lo que las tres miradas de la ronda 1 encontraron.
 * Tests NUEVOS: no tocan los de 3d893fe3.
 *
 *  1. Transcripción completa de verdad: tramos de 300 s re-codificados sin heredar metadatos, whisper-1 cuando no hay ffmpeg, y una guardia
 *     de corte (la respuesta de OpenAI con `usage.output_tokens` en el tope no puede quedar «lista» con el texto cortado).
 *  2. El resumen y el compromiso se calculan contra la fecha de la LLAMADA, no contra «ahora».
 *  3. (Pantalla; lo cubre `pruebas/leads-referidos-compromisos-e3-ronda2.cjs`.)
 *  4. Estados sin salida: `pendiente` con el encolado perdido se reintenta; «Volver a resumir».
 *  5. Un job viejo no pisa a uno nuevo (número de intento).
 *  6. Las partes y «completar» apuntan a un id de grabación (`grabacion_id`), no a «la última de la llamada».
 *  7. Los temporales `cc_tramos_*` de más de dos horas se barren al arrancar un job.
 */
class RondaDosDeRevisionDeE3Test extends BaseDeGrabacionDeLlamada
{
    /** Carpetas temporales que creó el test (se borran en el tearDown). */
    private $carpetas_de_prueba = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prender_el_motor();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->carpetas_de_prueba as $carpeta) {
            foreach ((array) glob($carpeta . '/*') as $archivo) {
                @unlink($archivo);
            }
            @rmdir($carpeta);
        }
        parent::tearDown();
    }

    /** Una grabación subida y `pendiente`. */
    private function pendiente(array $opciones = []): array
    {
        $lead    = isset($opciones['lead']) ? $opciones['lead'] : $this->lead_de_ro();
        $llamada = $this->crear_llamada($lead, isset($opciones['llamada']) ? $opciones['llamada'] : null);
        $fila    = $this->subir_la_grabacion_entera($lead, $llamada, isset($opciones['contenido']) ? $opciones['contenido'] : $this->contenido_de_audio(3000, 'r'), isset($opciones['nombre']) ? $opciones['nombre'] : 'llamada.m4a');

        return [$lead, $llamada, $fila];
    }

    private function resumen(array $cambios = []): array
    {
        return array_merge(['resumen' => 'Ro quiere probar el sistema.', 'datos_clave' => ['Quiere probar']], $cambios);
    }

    /** Respuesta de OpenAI con `usage` de salida. */
    private function con_usage(string $texto, int $salida): array
    {
        return ['text' => $texto, 'usage' => ['type' => 'tokens', 'input_tokens' => 4000, 'output_tokens' => $salida, 'total_tokens' => 4000 + $salida]];
    }

    /** Cortador que además sabe partir un tramo en dos mitades (lo que la guardia de corte necesita). */
    private function cortador_con_mitades(array $tramos, array $mitades)
    {
        if ($this->carpeta_de_tramos === null) {
            $this->carpeta_de_tramos = sys_get_temp_dir() . '/cc_e3_tramos_' . bin2hex(random_bytes(6));
            mkdir($this->carpeta_de_tramos, 0777, true);
        }
        $cortador = new class($this->carpeta_de_tramos, $tramos, $mitades) extends CortadorDeAudioEnTramos {
            public $carpeta;
            public $tramos;
            public $mitades;
            public $partidos = [];

            public function __construct($carpeta, $tramos, $mitades)
            {
                $this->carpeta = $carpeta;
                $this->tramos  = $tramos;
                $this->mitades = $mitades;
            }

            public function cortar(string $ruta_absoluta, string $extension): array
            {
                $rutas = [];
                foreach ($this->tramos as $i => $contenido) {
                    $rutas[] = $this->carpeta . '/tramo_' . ($i + 1) . '.mp3';
                    file_put_contents(end($rutas), $contenido);
                }

                return $rutas;
            }

            public function partir_en_dos(string $ruta_del_tramo): array
            {
                $this->partidos[] = basename($ruta_del_tramo);
                $a = $this->carpeta . '/mitad_a_' . count($this->partidos) . '.mp3';
                $b = $this->carpeta . '/mitad_b_' . count($this->partidos) . '.mp3';
                file_put_contents($a, $this->mitades[0]);
                file_put_contents($b, $this->mitades[1]);

                return [$a, $b];
            }
        };
        $this->app->instance(CortadorDeAudioEnTramos::class, $cortador);

        return $cortador;
    }

    private function correr(): void
    {
        $this->assertNull($this->correr_la_transcripcion());
    }

    // ------------------------------------------------------------------ 1. transcripción completa

    public function test_la_configuracion_nueva_por_defecto(): void
    {
        $servicios = require base_path('config/services.php');
        $c = $servicios['openai_call_transcription'];

        $this->assertSame(300, (int) $c['tramo_corto_segundos'], 'Tramos de 300 s por defecto.');
        $this->assertSame('whisper-1', $c['model_sin_ffmpeg'], 'Sin ffmpeg el archivo entero va a whisper-1.');
        $this->assertGreaterThan(0, (int) $c['max_output_tokens']);
        $this->assertLessThan(2000, (int) $c['max_output_tokens'], 'La guardia salta antes del tope de 2.000 tokens de salida.');
    }

    public function test_el_cortador_real_parte_en_tramos_de_300_y_recodifica_sin_metadatos(): void
    {
        $localizador = new class extends FfmpegLocator {
            public $llamadas = [];
            public function __construct() {}
            public function path(): ?string { return '/ruta/inventada/de/ffmpeg'; }
            public function duracion_en_segundos(string $ffmpeg_path, string $input_path, int $timeout_seconds = 30): ?int { return 650; }
            public function convert_audio(string $ffmpeg_path, string $input_path, string $output_path, string $codec_arguments, int $timeout_seconds = 30): ?string
            {
                $this->llamadas[] = $codec_arguments;
                file_put_contents($output_path, 'x');

                return null;
            }
        };
        $this->app->instance(FfmpegLocator::class, $localizador);

        $cortador = new CortadorDeAudioEnTramos();
        $rutas    = $cortador->cortar('/tmp/no-importa.m4a', 'm4a');
        $cortador->limpiar($rutas);

        $this->assertCount(3, $rutas, '650 s en tramos de 300 s son tres tramos.');
        $this->assertStringContainsString('-t 300', $localizador->llamadas[0]);
        $this->assertStringContainsString('-ss 600', $localizador->llamadas[2]);
        foreach ($localizador->llamadas as $argumentos) {
            $this->assertStringContainsString('-map_metadata -1', $argumentos, 'El tramo no hereda los metadatos (ni la duración) del original.');
            $this->assertStringContainsString('libmp3lame', $argumentos);
        }
    }

    public function test_un_tramo_cortado_se_reintenta_partido_en_dos(): void
    {
        list($lead, , $fila) = $this->pendiente();
        $cortador = $this->cortador_con_mitades(['uno-bytes', 'dos-bytes', 'tres-bytes'], ['m-a', 'm-bb']);
        $this->fakear_openai(['uno', $this->con_usage('dos cortado', 1990), 'dos-a', 'dos-b', 'tres']);
        $this->fakear_la_ia_de_compromisos([$this->resumen()]);

        $this->correr();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, (string) $listo->error_text);
        $this->assertSame("uno\n\ndos-a\n\ndos-b\n\ntres", $listo->transcripcion, 'El tramo cortado se reemplaza por sus dos mitades.');
        $this->assertSame(['tramo_2.mp3'], $cortador->partidos);
        $this->assertCount(5, $this->pedidos_a_openai);
    }

    public function test_si_la_mitad_tambien_sale_cortada_queda_en_error_sin_texto(): void
    {
        list($lead, , $fila) = $this->pendiente();
        $this->cortador_con_mitades(['uno-bytes', 'dos-bytes'], ['m-a', 'm-bb']);
        $this->fakear_openai(['uno', $this->con_usage('dos cortado', 2000), $this->con_usage('mitad cortada', 1950)]);
        $this->fakear_la_ia_de_compromisos([$this->resumen()]);

        $this->correr();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('error', $listo->status, 'Nunca «lista» con el texto cortado.');
        $this->assertStringContainsString('la transcripción salió cortada', (string) $listo->error_text);
        $this->assertEmpty($listo->transcripcion);
        $this->assertSame(0, $this->llamadas_a_la_ia());
    }

    public function test_un_tramo_con_salida_normal_no_dispara_la_guardia(): void
    {
        list($lead, , $fila) = $this->pendiente();
        $this->cortador_con_mitades(['uno-bytes', 'dos-bytes'], ['m-a', 'm-bb']);
        $this->fakear_openai([$this->con_usage('uno', 600), $this->con_usage('dos', 900)]);
        $this->fakear_la_ia_de_compromisos([$this->resumen()]);

        $this->correr();

        $this->assertSame('lista', $this->grabacion($fila->id)->status);
        $this->assertCount(2, $this->pedidos_a_openai);
    }

    public function test_sin_ffmpeg_el_archivo_entero_va_a_whisper_1(): void
    {
        $this->fakear_ffmpeg(false);
        list($lead, , $fila) = $this->pendiente(['contenido' => $this->contenido_de_audio(20 * self::MB, 's')]);
        $this->fakear_openai(['Texto entero.']);
        $this->fakear_la_ia_de_compromisos([$this->resumen()]);

        $this->correr();

        $this->assertSame('whisper-1', $this->pedidos_a_openai[0]['campos']['model']);
        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, (string) $listo->error_text);
        $this->assertSame('whisper-1', $listo->modelo);
    }

    public function test_sin_ffmpeg_una_respuesta_en_el_tope_tambien_es_error(): void
    {
        $this->fakear_ffmpeg(false);
        list($lead, , $fila) = $this->pendiente(['contenido' => $this->contenido_de_audio(20 * self::MB, 's')]);
        $this->fakear_openai([$this->con_usage('cortado', 1999)]);

        $this->correr();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('error', $listo->status);
        $this->assertStringContainsString('la transcripción salió cortada', (string) $listo->error_text);
        $this->assertEmpty($listo->transcripcion);
    }

    // ------------------------------------------------------------------ 2. fecha de la llamada

    public function test_el_compromiso_se_calcula_contra_la_fecha_de_la_llamada(): void
    {
        $this->clavar_el_reloj('2026-11-24 12:00');
        list($lead, , $fila) = $this->pendiente();
        $this->clavar_el_reloj('2026-11-30 12:00');
        $this->cortador_con_mitades(['uno'], ['a', 'b']);
        $this->fakear_openai(['llamame el viernes']);
        $this->fakear_la_ia_de_compromisos([$this->resumen(['compromiso' => [
            'fecha' => '2026-11-27', 'hora' => '10:00', 'accion' => 'llamar', 'cita' => 'llamame el viernes', 'momento' => 'el viernes',
        ]])]);

        $this->correr();

        $this->assertStringContainsString('- martes 24/11/2026', (string) $this->pedidos_a_la_ia[0], 'El calendario arranca el día de la llamada.');
        $this->assertStringContainsString('martes 24/11/2026', (string) $this->pedidos_a_la_ia[0]);
        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status, (string) $listo->error_text);
        $this->assertCount(0, $this->compromisos_de($lead), 'El viernes 27/11 ya pasó el lunes 30/11: no se agenda (ni el 4/12).');
        $this->assertNull($listo->compromiso_id);
        $this->assertStringContainsString('ya pasó', (string) $listo->compromiso_propuesto);
    }

    public function test_si_la_fecha_todavia_no_pasó_se_agenda_igual(): void
    {
        $this->clavar_el_reloj('2026-11-24 12:00');
        list($lead) = $this->pendiente();
        $this->clavar_el_reloj('2026-11-25 12:00');
        $this->cortador_con_mitades(['uno'], ['a', 'b']);
        $this->fakear_openai(['llamame el viernes']);
        $this->fakear_la_ia_de_compromisos([$this->resumen(['compromiso' => [
            'fecha' => '2026-11-27', 'hora' => '10:00', 'accion' => 'llamar', 'cita' => 'llamame el viernes',
        ]])]);

        $this->correr();

        $this->assertCount(1, $this->compromisos_de($lead, 'vigente'));
    }

    // ------------------------------------------------------------------ 4. estados sin salida

    public function test_una_grabacion_pendiente_hace_mucho_se_puede_reintentar(): void
    {
        list($lead, $llamada, $fila) = $this->pendiente();
        $url = $this->url_de_la_grabacion($lead->id, $llamada, '/reintentar');

        $this->postJson($url)->assertStatus(422);

        DB::table('lead_llamada_grabaciones')->where('id', $fila->id)->update(['updated_at' => Carbon::now()->subMinutes(45)->format('Y-m-d H:i:s')]);
        $this->postJson($url)->assertStatus(200);
        Queue::assertPushed(TranscribirGrabacionDeLlamadaJob::class, 2);
    }

    public function test_si_el_encolado_falla_la_grabacion_queda_en_error_y_se_puede_reintentar(): void
    {
        $lead    = $this->lead_de_ro();
        $llamada = $this->crear_llamada($lead);
        $inicio  = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'llamada.m4a', 'bytes' => 100]);
        $this->subir_parte($lead, $llamada, 0, $this->contenido_de_audio(100));

        $falso = \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $falso->shouldReceive('dispatch')->andThrow(new \RuntimeException('cola caída'));
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $falso);

        $this->completar_la_grabacion($lead, $llamada);

        $fila = $this->grabacion((int) $inicio->json('grabacion.id'));
        $this->assertSame('error', $fila->status, 'Un encolado perdido no deja la grabación esperando para siempre.');
        $this->assertNotEmpty($fila->error_text);
        $this->assertTrue((bool) $this->getJson($this->url_de_la_grabacion($lead->id, $llamada))->json('grabacion.puede_reintentar'));
    }

    public function test_volver_a_resumir_cuando_la_ia_fallo(): void
    {
        list($lead, $llamada, $fila) = $this->pendiente();
        $this->cortador_con_mitades(['uno', 'dos'], ['a', 'b']);
        $this->fakear_openai(['uno', 'dos']);
        $this->fakear_la_ia_caida();
        $this->correr();

        $sin_resumen = $this->getJson($this->url_de_la_grabacion($lead->id, $llamada));
        $this->assertSame('lista', $sin_resumen->json('grabacion.status'));
        $this->assertTrue((bool) $sin_resumen->json('grabacion.puede_resumir'));

        $this->postJson($this->url_de_la_grabacion($lead->id, $llamada, '/resumir'))->assertStatus(200);
        $this->fakear_la_ia_de_compromisos([$this->resumen()]);
        $this->correr();

        $listo = $this->grabacion($fila->id);
        $this->assertSame('lista', $listo->status);
        $this->assertSame("uno\n\ndos", $listo->transcripcion, 'No se vuelve a transcribir.');
        $this->assertSame('Ro quiere probar el sistema.', $listo->resumen);
        $this->assertCount(2, $this->pedidos_a_openai, 'Volver a resumir no llama a OpenAI otra vez.');
        $this->postJson($this->url_de_la_grabacion($lead->id, $llamada, '/resumir'))->assertStatus(422);
    }

    public function test_martin_lee_el_resumen_aunque_la_llamada_no_tenga_transcripcion(): void
    {
        list($lead, $llamada, $fila) = $this->pendiente();
        $this->cortador_con_mitades(['uno'], ['a', 'b']);
        $this->fakear_openai(['uno']);
        $this->fakear_la_ia_de_compromisos([$this->resumen(['resumen' => 'Habló de tres sucursales.'])]);
        $this->correr();

        $this->assertNull(DB::table('lead_llamadas')->where('id', $llamada)->value('transcripcion'));
        $this->assertStringContainsString('Habló de tres sucursales.', app(\App\Services\LeadLlamadaService::class)->bloque_para_agente($lead->fresh()));
    }

    // ------------------------------------------------------------------ 5. job viejo vs reintento

    public function test_un_job_viejo_no_pisa_a_uno_nuevo(): void
    {
        list($lead, $llamada, $fila) = $this->pendiente();
        $viejo = Queue::pushed(TranscribirGrabacionDeLlamadaJob::class)->first();
        $this->poner_la_grabacion_en((int) $fila->id, 'error');
        $this->postJson($this->url_de_la_grabacion($lead->id, $llamada, '/reintentar'))->assertStatus(200);
        $this->assertSame('pendiente', $this->grabacion($fila->id)->status);

        $viejo->failed(new \RuntimeException('El worker viejo se cayó.'));
        $this->assertSame('pendiente', $this->grabacion($fila->id)->status, 'El failed() del job viejo no toca la grabación reintentada.');

        $this->cortador_con_mitades(['uno'], ['a', 'b']);
        $this->fakear_openai(['no tendría que pedirse']);
        $this->correr_job($viejo);
        $this->assertSame('pendiente', $this->grabacion($fila->id)->status, 'El job viejo reofrecido no transcribe.');
        $this->assertCount(0, $this->pedidos_a_openai);
    }

    // ------------------------------------------------------------------ 6. partes con id de grabación

    public function test_las_partes_apuntan_a_la_grabacion_pedida(): void
    {
        $lead    = $this->lead_de_ro();
        $llamada = $this->crear_llamada($lead);
        $primera = (int) $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'a.m4a', 'bytes' => 100])->json('grabacion.id');
        $segunda = (int) $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'b.m4a', 'bytes' => 100])->json('grabacion.id');
        $this->assertNotSame($primera, $segunda);

        $parte = function ($id) use ($lead, $llamada) {
            return $this->post($this->url_de_la_grabacion($lead->id, $llamada, '/partes'), [
                'indice' => 0, 'grabacion_id' => $id, 'parte' => \Illuminate\Http\UploadedFile::fake()->createWithContent('p.bin', str_repeat('z', 100)),
            ], ['Accept' => 'application/json']);
        };

        $parte($primera)->assertStatus(404);
        $parte($segunda)->assertStatus(200);
        $this->assertSame(100, (int) $this->grabacion($segunda)->bytes_recibidos);

        $otra_llamada = $this->crear_llamada($lead);
        $ajena = (int) $this->iniciar_la_grabacion($lead, $otra_llamada, ['nombre' => 'c.m4a', 'bytes' => 100])->json('grabacion.id');
        $parte($ajena)->assertStatus(404);

        $this->postJson($this->url_de_la_grabacion($lead->id, $llamada, '/completar'), ['grabacion_id' => $primera])->assertStatus(404);
        $this->postJson($this->url_de_la_grabacion($lead->id, $llamada, '/completar'), ['grabacion_id' => $segunda])->assertStatus(200);
        $this->assertSame('pendiente', $this->grabacion($segunda)->status);
    }

    // ------------------------------------------------------------------ 7. temporales

    public function test_al_arrancar_un_job_se_barren_los_temporales_viejos(): void
    {
        $viejo = sys_get_temp_dir() . '/cc_tramos_viejo' . bin2hex(random_bytes(3));
        $nuevo = sys_get_temp_dir() . '/cc_tramos_nuevo' . bin2hex(random_bytes(3));
        foreach ([$viejo, $nuevo] as $carpeta) {
            mkdir($carpeta, 0700, true);
            file_put_contents($carpeta . '/tramo_001.mp3', 'x');
            $this->carpetas_de_prueba[] = $carpeta;
        }
        touch($viejo, time() - 3 * 3600);

        list($lead, , $fila) = $this->pendiente();
        $this->cortador_con_mitades(['uno'], ['a', 'b']);
        $this->fakear_openai(['uno']);
        $this->fakear_la_ia_de_compromisos([$this->resumen()]);
        $this->correr();

        $this->assertDirectoryDoesNotExist($viejo, 'Lo de más de dos horas se borra.');
        $this->assertDirectoryExists($nuevo, 'Lo reciente (puede ser de otro job en curso) no se toca.');
    }
}
