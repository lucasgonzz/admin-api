<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Models\Admin;
use App\Models\Lead;
use App\Services\FfmpegLocator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Base común de los tests de la etapa E3 (grabación de la llamada de Juan) de la misión leads-referidos-compromisos.
 *
 * Extiende `BaseDeReferidosYCompromisos` (tablero de Juan sembrado, reloj clavado en el lunes 23/11/2026 12:00 de Argentina,
 * guardián de red por `Http::recorded()`, espía de WhatsApp, helpers del motor de compromisos) y le suma:
 *  - El disco `local` falso (`Storage::fake('local')`): las partes y la grabación unida no tocan el disco real.
 *  - OpenAI simulado: el endpoint de transcripción atiende desde una cola (`fakear_openai`) y anota cada pedido (archivo, bytes,
 *    campos, clave). La IA del resumen es la misma de E2 (`fakear_la_ia_de_compromisos`). Cualquier otro pedido de red lo denuncia el
 *    guardián en el `tearDown`: ningún test de E3 puede llamar a OpenAI de verdad.
 *  - ffmpeg y el cortador de tramos simulados: `FfmpegLocator` y `CortadorDeAudioEnTramos` se reemplazan en el contenedor
 *    (`fakear_ffmpeg`, `fakear_el_cortador`), así ningún test corre ffmpeg ni depende de que la máquina lo tenga.
 *  - Un oyente de `MessageLogged`: junta todo lo que el código loguea (cualquier canal) para probar que el audio y la transcripción
 *    —datos personales— no van a los logs.
 *  - Helpers para subir una grabación por las puertas reales (`iniciar_la_grabacion`, `subir_parte`, `completar_la_grabacion`),
 *    correr el job tal como quedó en la cola falsa y leer la tabla `lead_llamada_grabaciones` por query builder.
 *
 * 🔴 CONTRATO QUE FIJAN LOS TESTS (el PLAN no lo escribe; lo decidió el Programador al escribir los tests de E3):
 *  - Config (`config/services.php`): `openai_call_transcription` con `model` (default `gpt-4o-transcribe`), `tramo_segundos` (default
 *    600) y `max_bytes` (default 209715200 = 200 MB). La clave de OpenAI es la de siempre (`services.openai_transcription.api_key`) y el
 *    interruptor `services.openai_transcription.enabled` (el de los audios salientes) NO interviene: con él apagado la grabación se
 *    transcribe igual.
 *  - `App\Services\CortadorDeAudioEnTramos::cortar(string $ruta_absoluta, string $extension): array` devuelve la lista, en orden, de las
 *    rutas absolutas de los tramos (archivos que OpenAI acepta). Se obtiene del contenedor (`app(...)`). El servicio sólo lo usa si
 *    `FfmpegLocator::path()` (también del contenedor) no devuelve null.
 *  - `App\Services\LeadCompromisoIaService::resumir_transcripcion` hace UNA llamada a la IA y espera este JSON:
 *    `{"resumen": "...", "datos_clave": ["..."], "compromiso": {"fecha": "AAAA-MM-DD", "hora": "HH:MM", "accion": "llamar|escribir|esperar",
 *    "cita": "...", "momento": "..."}}` (`compromiso`, `hora` y `momento` son opcionales; el `momento` se guarda sólo si es limpio, como en E2).
 *  - Respuestas JSON: `{grabacion: {id, status, resumen, transcripcion, error_text, compromiso_propuesto, ...}}` en iniciar, partes,
 *    completar, el GET y reintentar; `GET lead/{id}/llamadas` suma `grabacion` (o null) a cada llamada. Errores 422 con `errors.nombre`
 *    y `errors.bytes`; `completar` con partes que faltan responde 422 con `faltantes` (lista de índices).
 *  - Juan (perfil «Sólo Leads») recibe esas respuestas completas: el filtro de salida de `AdminSoloLeadsPolicy` tiene que dejar pasar
 *    `grabacion` y `faltantes` en las rutas de la grabación (y `grabacion` en `GET lead/{id}/llamadas`).
 *  - El job es `App\Jobs\TranscribirGrabacionDeLlamadaJob` (cola `transcripciones`, `$timeout = 1500`, `$tries = 1`).
 *
 * No termina en `Test.php` a propósito, así PHPUnit no lo corre como suite.
 */
abstract class BaseDeGrabacionDeLlamada extends BaseDeReferidosYCompromisos
{
    /** Clave de OpenAI de los tests. */
    const CLAVE_DE_OPENAI = 'clave-de-openai-de-prueba-e3';

    /** Modelo de transcripción de los tests (el que debe viajar en cada pedido: sale de la config). */
    const MODELO_DE_LLAMADAS = 'modelo-de-llamadas-de-prueba';

    /** Endpoint de transcripción de OpenAI. */
    const URL_DE_TRANSCRIPCION = 'https://api.openai.com/v1/audio/transcriptions';

    /** Un megabyte. */
    const MB = 1048576;

    /** Tamaño de cada parte de la subida (4 MB). */
    const PARTE = 4194304;

    /** Extensiones que acepta la subida (PLAN §2, E3). */
    const EXTENSIONES = ['m4a', 'mp3', 'mp4', 'aac', 'ogg', 'opus', 'oga', 'wav', 'webm', 'flac', 'amr', '3gp'];

    /** @var array<int, mixed> Respuestas pendientes de OpenAI simulado: string = texto del tramo, int = HTTP de error, array = JSON tal cual. */
    protected $cola_openai = [];

    /** @var array<int, array<string, mixed>> Lo que se le pidió a OpenAI simulado, en orden (archivo, bytes, campos, autorización). */
    protected $pedidos_a_openai = [];

    /** @var array<int, string> Todo lo que el código logueó durante el test (nivel, mensaje y contexto en JSON). */
    protected $registros_del_log = [];

    /** @var string|null Carpeta temporal donde el cortador simulado deja los tramos. */
    protected $carpeta_de_tramos = null;

    /**
     * Deja el entorno: disco falso, OpenAI simulado con el HTTP cerrado, clave y modelo de prueba, oyente de logs y ffmpeg presente
     * (los tests que lo necesitan ausente lo apagan).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.openai_transcription.api_key'      => self::CLAVE_DE_OPENAI,
            'services.openai_transcription.enabled'      => false,
            'services.openai_call_transcription.model'   => self::MODELO_DE_LLAMADAS,
            'services.openai_call_transcription.max_bytes' => 200 * self::MB,
        ]);

        $this->cola_openai      = [];
        $this->pedidos_a_openai = [];
        $this->rearmar_el_http_cerrado();

        $this->registros_del_log = [];
        Event::listen(MessageLogged::class, function ($evento) {
            $this->registros_del_log[] = $evento->level . ' ' . $evento->message . ' ' . json_encode($evento->context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        });

        $this->fakear_ffmpeg(true);
    }

    /**
     * Borra los tramos temporales del cortador simulado y cierra el test (el guardián de red corre en el `tearDown` de la base).
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->carpeta_de_tramos !== null && is_dir($this->carpeta_de_tramos)) {
            foreach ((array) glob($this->carpeta_de_tramos . '/*') as $archivo) {
                @unlink($archivo);
            }
            @rmdir($this->carpeta_de_tramos);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // HTTP cerrado con OpenAI simulado
    // ------------------------------------------------------------------------------------------

    /**
     * Arma de nuevo la fábrica de HTTP con tres stubs, EN ESTE ORDEN (Laravel 8 se queda con el primer stub que responde):
     * la IA simulada (Anthropic, de la cola `cola_ia`), el endpoint de transcripción de OpenAI (de `cola_openai`) y, último, el
     * que marca todo lo demás como pedido sin dueño para que el guardián de la base lo denuncie.
     *
     * No alcanza con sumar un `Http::fake` después del de la base: el último stub de la base (`'*'`) responde a todo y taparía
     * al de OpenAI.
     *
     * @return void
     */
    protected function rearmar_el_http_cerrado(): void
    {
        Http::swap(new HttpFactory());

        /* La IA del resumen (api.anthropic.com), igual que en E2. */
        $this->fakear_http_del_flujo(false);

        /* El endpoint exacto de transcripción: otro path de OpenAI cae en el stub sin dueño y el guardián lo denuncia. */
        Http::fake([
            self::URL_DE_TRANSCRIPCION => function ($request) {
                $pedido = ['multipart' => $request->isMultipart(), 'archivo' => null, 'bytes' => 0, 'campos' => [], 'autorizacion' => null];

                $autorizacion = $request->header('Authorization');
                if (is_array($autorizacion) && isset($autorizacion[0])) {
                    $pedido['autorizacion'] = (string) $autorizacion[0];
                }

                foreach ((array) $request->data() as $parte) {
                    if (! is_array($parte) || ! isset($parte['name'])) {
                        continue;
                    }
                    if ($parte['name'] === 'file') {
                        $pedido['archivo'] = isset($parte['filename']) ? (string) $parte['filename'] : null;
                        $contenido         = isset($parte['contents']) ? $parte['contents'] : '';
                        if (is_resource($contenido)) {
                            $datos               = fstat($contenido);
                            $pedido['bytes']     = $datos === false ? 0 : (int) $datos['size'];
                        } else {
                            $pedido['bytes'] = strlen((string) $contenido);
                        }
                        continue;
                    }
                    $pedido['campos'][$parte['name']] = isset($parte['contents']) && is_scalar($parte['contents']) ? (string) $parte['contents'] : '';
                }

                $this->pedidos_a_openai[] = $pedido;

                if (empty($this->cola_openai)) {
                    return Http::response(['error' => ['message' => 'La cola de OpenAI simulado de este test está vacía.']], 500);
                }

                $siguiente = array_shift($this->cola_openai);
                if (is_int($siguiente)) {
                    return Http::response(['error' => ['message' => 'Falla simulada de OpenAI.']], $siguiente);
                }
                if (is_array($siguiente)) {
                    return Http::response($siguiente, 200);
                }

                return Http::response(['text' => (string) $siguiente], 200);
            },
        ]);

        Http::fake([
            '*' => function ($request) {
                return Http::response(['error' => self::MARCA_DE_PEDIDO_SIN_STUB], 500);
            },
        ]);
    }

    /**
     * Carga las respuestas que va a dar OpenAI simulado, una por pedido y en orden.
     *
     * @param array<int, mixed> $respuestas String = el texto transcripto de ese pedido; int = el pedido falla con ese HTTP; array = JSON tal cual.
     *
     * @return void
     */
    protected function fakear_openai(array $respuestas): void
    {
        $this->cola_openai      = array_values($respuestas);
        $this->pedidos_a_openai = [];
    }

    // ------------------------------------------------------------------------------------------
    // ffmpeg y cortador simulados
    // ------------------------------------------------------------------------------------------

    /**
     * Registra en el contenedor un `FfmpegLocator` que dice que ffmpeg está (con una ruta inventada, que nadie ejecuta) o que no está.
     *
     * @param bool $instalado
     *
     * @return void
     */
    protected function fakear_ffmpeg(bool $instalado): void
    {
        $localizador = new class($instalado) extends FfmpegLocator {
            /** @var bool */
            public $instalado;

            /**
             * @param bool $instalado
             */
            public function __construct(bool $instalado)
            {
                $this->instalado = $instalado;
            }

            /**
             * @return string|null La ruta inventada, o null si el test simula un servidor sin ffmpeg.
             */
            public function path(): ?string
            {
                return $this->instalado ? '/ruta/inventada/de/ffmpeg' : null;
            }
        };

        $this->app->instance(FfmpegLocator::class, $localizador);
    }

    /**
     * Registra en el contenedor un cortador que NO corta nada: devuelve, en orden, archivos de tramo que escribe en una carpeta
     * temporal con los contenidos pedidos (así cada tramo tiene un tamaño reconocible en el pedido a OpenAI). Anota cada llamada.
     *
     * @param array<int, string> $contenidos Contenido de cada tramo, en orden (la cantidad define cuántos tramos «salen»).
     *
     * @return object El cortador, con la propiedad pública `llamadas` (cada una con `ruta` y `extension`).
     */
    protected function fakear_el_cortador(array $contenidos)
    {
        $this->exigir_clase(\App\Services\CortadorDeAudioEnTramos::class, 'CA20: el cortador de audio en tramos');

        if ($this->carpeta_de_tramos === null) {
            $this->carpeta_de_tramos = sys_get_temp_dir() . '/cc_e3_tramos_' . bin2hex(random_bytes(6));
            mkdir($this->carpeta_de_tramos, 0777, true);
        }

        $cortador = new class($this->carpeta_de_tramos, array_values($contenidos)) extends \App\Services\CortadorDeAudioEnTramos {
            /** @var string */
            public $carpeta;

            /** @var array<int, string> */
            public $contenidos;

            /** @var array<int, array<string, string>> */
            public $llamadas = [];

            /**
             * @param string             $carpeta
             * @param array<int, string> $contenidos
             */
            public function __construct(string $carpeta, array $contenidos)
            {
                $this->carpeta    = $carpeta;
                $this->contenidos = $contenidos;
            }

            /**
             * Anota la llamada y escribe un archivo por tramo.
             *
             * @param string $ruta_absoluta
             * @param string $extension
             * @param mixed  ...$otros      Si el cortador real pide más parámetros, el simulado los ignora.
             *
             * @return array<int, string>
             */
            public function cortar(string $ruta_absoluta, string $extension, ...$otros): array
            {
                $this->llamadas[] = ['ruta' => $ruta_absoluta, 'extension' => $extension];

                $rutas = [];
                foreach ($this->contenidos as $posicion => $contenido) {
                    $ruta = $this->carpeta . '/tramo_' . ($posicion + 1) . '.mp3';
                    file_put_contents($ruta, $contenido);
                    $rutas[] = $ruta;
                }

                return $rutas;
            }
        };

        $this->app->instance(\App\Services\CortadorDeAudioEnTramos::class, $cortador);

        return $cortador;
    }

    // ------------------------------------------------------------------------------------------
    // Lo que tiene que existir de E3
    // ------------------------------------------------------------------------------------------

    /**
     * Falla con un mensaje claro si todavía no está la migración de E3 (tabla y modelo) o el job: es el motivo del rojo de todo lo
     * que escribe o lee `lead_llamada_grabaciones`.
     *
     * @return void
     */
    protected function exigir_e3(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('lead_llamada_grabaciones'),
            'Falta la migración de E3: la tabla `lead_llamada_grabaciones` (PLAN §2, E3).'
        );
        $this->exigir_clase(\App\Models\LeadLlamadaGrabacion::class, 'E3: modelo de la grabación de la llamada');
        $this->exigir_clase(\App\Jobs\TranscribirGrabacionDeLlamadaJob::class, 'E3: job que transcribe la grabación');
    }

    // ------------------------------------------------------------------------------------------
    // Llamadas y subida por las puertas reales
    // ------------------------------------------------------------------------------------------

    /**
     * Registra una llamada de Juan SIN fecha de próxima llamada (atendió, «sigue solo») y devuelve su id.
     *
     * @param Lead                      $lead
     * @param array<string, mixed>|null $cuerpo Si se pasa, reemplaza el cuerpo (por ejemplo para una llamada con fecha).
     *
     * @return int
     */
    protected function crear_llamada(Lead $lead, ?array $cuerpo = null): int
    {
        $this->exigir_e3();

        $respuesta = $this->registrar_llamada_por_el_panel($lead, $cuerpo !== null ? $cuerpo : [
            'atendio' => true, 'resultado' => 'sigue_solo', 'notas' => 'Charlamos de cómo le va con la prueba.',
        ]);
        $respuesta->assertStatus(201);

        return (int) $respuesta->json('llamada.id');
    }

    /**
     * URL de la grabación de una llamada del lead.
     *
     * @param int|string $lead_id
     * @param int|string $llamada_id
     * @param string     $resto      `''`, `/partes`, `/completar` o `/reintentar`.
     *
     * @return string
     */
    protected function url_de_la_grabacion($lead_id, $llamada_id, string $resto = ''): string
    {
        return '/api/admin/lead/' . $lead_id . '/llamadas/' . $llamada_id . '/grabacion' . $resto;
    }

    /**
     * `POST …/grabacion`: arranca la subida.
     *
     * @param Lead                 $lead
     * @param int|string           $llamada_id
     * @param array<string, mixed> $cuerpo     Pisa los defaults (`nombre`, `bytes`, `mime`).
     * @param Admin|null           $como       Quién sube (null = Juan).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function iniciar_la_grabacion(Lead $lead, $llamada_id, array $cuerpo = [], ?Admin $como = null)
    {
        $this->loguear_a($como !== null ? $como : $this->juan);

        return $this->postJson($this->url_de_la_grabacion($lead->id, $llamada_id), array_merge([
            'nombre' => 'llamada.m4a', 'bytes' => 1000, 'mime' => 'audio/mp4',
        ], $cuerpo));
    }

    /**
     * `POST …/grabacion/partes`: sube una parte (multipart `indice` + `parte`).
     *
     * @param Lead       $lead
     * @param int|string $llamada_id
     * @param int        $indice
     * @param string     $contenido
     * @param Admin|null $como
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function subir_parte(Lead $lead, $llamada_id, int $indice, string $contenido, ?Admin $como = null)
    {
        $this->loguear_a($como !== null ? $como : $this->juan);

        return $this->post(
            $this->url_de_la_grabacion($lead->id, $llamada_id, '/partes'),
            ['indice' => $indice, 'parte' => UploadedFile::fake()->createWithContent('parte.bin', $contenido)],
            ['Accept' => 'application/json']
        );
    }

    /**
     * `POST …/grabacion/completar`.
     *
     * @param Lead                 $lead
     * @param int|string           $llamada_id
     * @param array<string, mixed> $cuerpo     `sha256` opcional.
     * @param Admin|null           $como
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function completar_la_grabacion(Lead $lead, $llamada_id, array $cuerpo = [], ?Admin $como = null)
    {
        $this->loguear_a($como !== null ? $como : $this->juan);

        return $this->postJson($this->url_de_la_grabacion($lead->id, $llamada_id, '/completar'), $cuerpo);
    }

    /**
     * Sube una grabación entera por las tres puertas (iniciar, partes de 4 MB y completar) y devuelve la fila resultante (en `pendiente`).
     * Verifica cada paso: si algo falla, el test falla ahí con un mensaje claro.
     *
     * @param Lead       $lead
     * @param int|string $llamada_id
     * @param string     $contenido  Los bytes del «audio».
     * @param string     $nombre     Nombre original (define la extensión).
     * @param Admin|null $como       Quién sube (null = Juan).
     *
     * @return object La fila de `lead_llamada_grabaciones`.
     */
    protected function subir_la_grabacion_entera(Lead $lead, $llamada_id, string $contenido, string $nombre = 'llamada.m4a', ?Admin $como = null)
    {
        $inicio = $this->iniciar_la_grabacion($lead, $llamada_id, ['nombre' => $nombre, 'bytes' => strlen($contenido)], $como);
        $inicio->assertStatus(201);
        $grabacion_id = (int) $inicio->json('grabacion.id');
        $this->assertGreaterThan(0, $grabacion_id, 'El inicio de la subida devuelve la grabación (`grabacion.id`).');

        foreach (str_split($contenido, self::PARTE) as $indice => $parte) {
            $this->subir_parte($lead, $llamada_id, $indice, $parte, $como)->assertStatus(200);
        }

        $this->completar_la_grabacion($lead, $llamada_id, ['sha256' => hash('sha256', $contenido)], $como)->assertStatus(200);

        return $this->grabacion($grabacion_id);
    }

    /**
     * Una fila de `lead_llamada_grabaciones` por id, releída de la base.
     *
     * @param int|string $id
     *
     * @return object|null
     */
    protected function grabacion($id)
    {
        $this->exigir_e3();

        return DB::table('lead_llamada_grabaciones')->where('id', (int) $id)->first();
    }

    /**
     * Contenido de prueba de un tamaño exacto y con un «ADN» propio (dos contenidos con distinta semilla tienen distinto sha256).
     *
     * @param int    $bytes
     * @param string $semilla Un carácter.
     *
     * @return string
     */
    protected function contenido_de_audio(int $bytes, string $semilla = 'a'): string
    {
        return str_repeat($semilla, $bytes);
    }

    // ------------------------------------------------------------------------------------------
    // El job y los logs
    // ------------------------------------------------------------------------------------------

    /**
     * Corre el último `TranscribirGrabacionDeLlamadaJob` que quedó en la cola falsa, como lo haría el worker: si el job tira una
     * excepción, se llama a su `failed()` (que es lo que hace la cola cuando se agota el único intento).
     *
     * @return \Throwable|null La excepción que escapó del `handle`, o null si terminó bien.
     */
    protected function correr_la_transcripcion(): ?\Throwable
    {
        $trabajos = Queue::pushed(\App\Jobs\TranscribirGrabacionDeLlamadaJob::class);
        $this->assertGreaterThan(0, $trabajos->count(), 'No quedó ningún TranscribirGrabacionDeLlamadaJob en la cola.');
        $trabajo = $trabajos->last();

        try {
            $this->correr_job($trabajo);
        } catch (\Throwable $e) {
            if (method_exists($trabajo, 'failed')) {
                $trabajo->failed($e);
            }

            return $e;
        }

        return null;
    }

    /**
     * Todo lo que el código logueó durante el test, en un solo texto.
     *
     * @return string
     */
    protected function texto_del_log(): string
    {
        return implode("\n", $this->registros_del_log);
    }

    /**
     * Pone una grabación en un estado, con el inicio del procesamiento hace tantos minutos.
     *
     * @param int|string  $grabacion_id
     * @param string      $status
     * @param int|null    $hace_minutos Si es null, `started_at` queda como estaba.
     *
     * @return void
     */
    protected function poner_la_grabacion_en(int $grabacion_id, string $status, ?int $hace_minutos = null): void
    {
        $cambios = ['status' => $status];
        if ($hace_minutos !== null) {
            $cambios['started_at'] = \Carbon\Carbon::now()->subMinutes($hace_minutos)->format('Y-m-d H:i:s');
        }

        DB::table('lead_llamada_grabaciones')->where('id', $grabacion_id)->update($cambios);
    }
}
