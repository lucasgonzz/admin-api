<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Jobs\TranscribirGrabacionDeLlamadaJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * La subida de la grabación de una llamada de Juan, por partes: criterio CA19 de la etapa E3 de la misión leads-referidos-compromisos.
 *
 * Juan llama a un lead, registra la llamada y sube la grabación que hizo con el celular. El archivo puede pesar decenas de megas y el
 * límite de subida del servidor no está medido, así que se sube en partes de 4 MB:
 *  - `POST admin/lead/{id}/llamadas/{llamada}/grabacion` `{nombre, bytes, mime}` → 201 (`subiendo`). Sólo audio (12 extensiones) y hasta
 *    200 MB; un `.pdf` o 210 MB → 422 con `errors.nombre` / `errors.bytes`;
 *  - `POST …/grabacion/partes` multipart `{indice, parte}` (≤ 4 MB), idempotente por índice (repetir una parte no duplica nada);
 *  - `POST …/grabacion/completar` `{sha256?}`: con partes que faltan → 422 con `faltantes`; completa → une, calcula el sha256, pasa a
 *    `pendiente` y manda `TranscribirGrabacionDeLlamadaJob` a la cola `transcripciones`. El mismo archivo (mismo sha256) en otra llamada del
 *    MISMO lead → 409 con la grabación que ya estaba;
 *  - una llamada de OTRO lead da 404 en todas las puertas.
 *
 * Además de CA19 (pedido de Lucas): Juan (perfil «Sólo Leads») puede subir la grabación de sus leads y no de otros, y la respuesta le llega
 * completa (el filtro de salida del perfil no se la come); el nombre del archivo y los datos del lead no van a los logs.
 *
 * Hoy están EN ROJO: la tabla `lead_llamada_grabaciones`, las rutas y el job no existen.
 */
class SubidaDeGrabacionDeLlamadaTest extends BaseDeGrabacionDeLlamada
{
    /**
     * CA19. Antes de arrancar: un `.pdf` (422 `errors.nombre`), 210 MB (422 `errors.bytes`), un archivo vacío y un pedido sin nombre se
     * rechazan sin dejar nada. Con `llamada.m4a` de 10.485.760 bytes → 201, `subiendo`, con lo declarado guardado.
     *
     * @return void
     */
    public function test_extension_y_tamano(): void
    {
        $lead    = $this->lead_de_ro();
        $llamada = $this->crear_llamada($lead);

        $pdf = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'llamada.pdf', 'bytes' => 1000, 'mime' => 'application/pdf']);
        $pdf->assertStatus(422);
        $this->assertNotNull($pdf->json('errors.nombre'), 'Un .pdf no es audio: el error viene en `errors.nombre`.');

        $pesado = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'llamada.m4a', 'bytes' => 210 * self::MB]);
        $pesado->assertStatus(422);
        $this->assertNotNull($pesado->json('errors.bytes'), '210 MB pasa el tope de 200 MB: el error viene en `errors.bytes`.');

        $vacio = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'llamada.m4a', 'bytes' => 0]);
        $vacio->assertStatus(422);
        $this->assertNotNull($vacio->json('errors.bytes'), 'Un archivo vacío no se sube.');

        $sin_nombre = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => '', 'bytes' => 1000]);
        $sin_nombre->assertStatus(422);
        $this->assertNotNull($sin_nombre->json('errors.nombre'));

        $this->assertSame(0, DB::table('lead_llamada_grabaciones')->count(), 'Los pedidos rechazados no dejan ninguna fila.');

        $bien = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'llamada.m4a', 'bytes' => 10485760, 'mime' => 'audio/mp4']);
        $bien->assertStatus(201);
        $this->assertSame('subiendo', $bien->json('grabacion.status'));

        $fila = $this->grabacion((int) $bien->json('grabacion.id'));
        $this->assertNotNull($fila);
        $this->assertSame('subiendo', $fila->status);
        $this->assertSame((int) $lead->id, (int) $fila->lead_id);
        $this->assertSame($llamada, (int) $fila->lead_llamada_id);
        $this->assertSame('llamada.m4a', $fila->nombre_original);
        $this->assertSame('m4a', $fila->extension);
        $this->assertSame('audio/mp4', $fila->mime);
        $this->assertSame(10485760, (int) $fila->bytes_declarados);
        $this->assertSame(0, (int) $fila->bytes_recibidos);
        $this->assertSame((int) $this->juan->id, (int) $fila->created_by_admin_id, 'Quedó asentado quién la subió.');
    }

    /**
     * CA19. Las doce extensiones del PLAN se aceptan (también en mayúsculas, que es como las guardan algunos celulares) y se guardan en
     * minúsculas; una extensión de otro tipo, o doble con un ejecutable, o sin extensión, se rechaza.
     *
     * @return void
     */
    public function test_extensiones_aceptadas_y_rechazadas(): void
    {
        $lead = $this->lead_de_ro();

        foreach (self::EXTENSIONES as $extension) {
            $llamada = $this->crear_llamada($lead);
            $mayuscula = strtoupper($extension);

            $respuesta = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'Llamada ' . $mayuscula . '.' . $mayuscula, 'bytes' => 1000]);
            $respuesta->assertStatus(201);
            $this->assertSame($extension, $this->grabacion((int) $respuesta->json('grabacion.id'))->extension, 'La extensión se guarda en minúsculas.');
        }

        foreach (['llamada.exe', 'llamada.m4a.exe', 'llamada', 'llamada.txt', 'llamada.mov'] as $nombre) {
            $llamada   = $this->crear_llamada($lead);
            $respuesta = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => $nombre, 'bytes' => 1000]);

            $respuesta->assertStatus(422);
            $this->assertNotNull($respuesta->json('errors.nombre'), '«' . $nombre . '» no es una grabación.');
        }
    }

    /**
     * CA19. Partes 0, 1 y 2 (4 MB, 4 MB y 2 MB) de un archivo de 10.485.760 bytes: cada una queda guardada como `parte_<indice>` en la
     * carpeta `lead_llamadas/<lead>/<grabacion>/` y se van sumando los bytes. Repetir la parte 1 no duplica nada.
     *
     * @return void
     */
    public function test_partes_idempotentes(): void
    {
        $lead      = $this->lead_de_ro();
        $llamada   = $this->crear_llamada($lead);
        $contenido = $this->contenido_de_audio(10485760);
        $partes    = str_split($contenido, self::PARTE);
        $this->assertCount(3, $partes, 'Precondición: 10.485.760 bytes son tres partes (4 MB, 4 MB, 2 MB).');

        $inicio       = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'llamada.m4a', 'bytes' => 10485760]);
        $inicio->assertStatus(201);
        $grabacion_id = (int) $inicio->json('grabacion.id');
        $carpeta      = 'lead_llamadas/' . $lead->id . '/' . $grabacion_id;

        $this->subir_parte($lead, $llamada, 0, $partes[0])->assertStatus(200);
        $this->subir_parte($lead, $llamada, 1, $partes[1])->assertStatus(200);
        $this->subir_parte($lead, $llamada, 1, $partes[1])->assertStatus(200);

        $this->assertSame(2 * self::PARTE, (int) $this->grabacion($grabacion_id)->bytes_recibidos, 'Repetir la parte 1 no suma dos veces.');
        $this->assertTrue(Storage::disk('local')->exists($carpeta . '/parte_0'), 'La parte 0 se guarda como parte_0 en la carpeta de la grabación.');
        $this->assertTrue(Storage::disk('local')->exists($carpeta . '/parte_1'));
        $this->assertCount(2, Storage::disk('local')->allFiles($carpeta), 'Sólo dos archivos: la parte repetida no se duplicó.');

        $this->subir_parte($lead, $llamada, 2, $partes[2])->assertStatus(200);

        $fila = $this->grabacion($grabacion_id);
        $this->assertSame(10485760, (int) $fila->bytes_recibidos);
        $this->assertSame('subiendo', $fila->status, 'Hasta que no se completa sigue subiendo.');
        $this->assertCount(3, Storage::disk('local')->allFiles($carpeta));
    }

    /**
     * CA19. Una parte con un índice que no existe para ese tamaño (la 3 de un archivo de tres partes), o de más de 4 MB, se rechaza y no
     * cambia lo recibido.
     *
     * @return void
     */
    public function test_indice_fuera_de_rango_y_parte_de_mas_de_4_mb(): void
    {
        $lead      = $this->lead_de_ro();
        $llamada   = $this->crear_llamada($lead);
        $inicio    = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'llamada.m4a', 'bytes' => 10485760]);
        $inicio->assertStatus(201);
        $grabacion_id = (int) $inicio->json('grabacion.id');

        $this->subir_parte($lead, $llamada, 3, $this->contenido_de_audio(1000))->assertStatus(422);
        $this->subir_parte($lead, $llamada, -1, $this->contenido_de_audio(1000))->assertStatus(422);
        $this->subir_parte($lead, $llamada, 0, $this->contenido_de_audio(self::PARTE + 1))->assertStatus(422);

        $this->assertSame(0, (int) $this->grabacion($grabacion_id)->bytes_recibidos, 'Nada de eso se guardó.');
        $this->assertCount(0, Storage::disk('local')->allFiles('lead_llamadas/' . $lead->id . '/' . $grabacion_id));
    }

    /**
     * CA19. `completar` con la parte 2 sin subir → 422 con `faltantes: [2]`; no pasa a `pendiente` ni encola nada. Con la parte que
     * faltaba, completa. Si los índices están todos pero los bytes no suman lo declarado, también 422.
     *
     * @return void
     */
    public function test_completar_con_faltante(): void
    {
        Queue::fake();
        $lead      = $this->lead_de_ro();
        $llamada   = $this->crear_llamada($lead);
        $contenido = $this->contenido_de_audio(10485760, 'b');
        $partes    = str_split($contenido, self::PARTE);

        $inicio = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'llamada.m4a', 'bytes' => 10485760]);
        $inicio->assertStatus(201);
        $grabacion_id = (int) $inicio->json('grabacion.id');
        $this->subir_parte($lead, $llamada, 0, $partes[0])->assertStatus(200);
        $this->subir_parte($lead, $llamada, 1, $partes[1])->assertStatus(200);

        $incompleto = $this->completar_la_grabacion($lead, $llamada);
        $incompleto->assertStatus(422);
        $this->assertSame([2], $incompleto->json('faltantes'), 'Falta la parte 2.');
        $this->assertSame('subiendo', $this->grabacion($grabacion_id)->status);
        Queue::assertNothingPushed();

        $this->subir_parte($lead, $llamada, 2, $partes[2])->assertStatus(200);
        $this->completar_la_grabacion($lead, $llamada)->assertStatus(200);
        $this->assertSame('pendiente', $this->grabacion($grabacion_id)->status);
    }

    /**
     * CA19. Las tres partes están, pero la última pesa 1 MB en vez de 2: los bytes recibidos no coinciden con los declarados → 422, sigue
     * `subiendo` y no se encola nada (un audio cortado no se transcribe).
     *
     * @return void
     */
    public function test_completar_con_bytes_distintos_a_los_declarados(): void
    {
        Queue::fake();
        $lead    = $this->lead_de_ro();
        $llamada = $this->crear_llamada($lead);

        $inicio = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'llamada.m4a', 'bytes' => 10485760]);
        $inicio->assertStatus(201);
        $grabacion_id = (int) $inicio->json('grabacion.id');
        $this->subir_parte($lead, $llamada, 0, $this->contenido_de_audio(self::PARTE))->assertStatus(200);
        $this->subir_parte($lead, $llamada, 1, $this->contenido_de_audio(self::PARTE))->assertStatus(200);
        $this->subir_parte($lead, $llamada, 2, $this->contenido_de_audio(self::MB))->assertStatus(200);

        $this->completar_la_grabacion($lead, $llamada)->assertStatus(422);

        $this->assertSame('subiendo', $this->grabacion($grabacion_id)->status);
        Queue::assertNothingPushed();
    }

    /**
     * CA19. Completa → `pendiente`, con la grabación unida en `ruta` (dentro de la carpeta de la grabación, del tamaño declarado), el
     * sha256 calculado y UN `TranscribirGrabacionDeLlamadaJob` en la cola `transcripciones`.
     *
     * @return void
     */
    public function test_completar_encola(): void
    {
        Queue::fake();
        $lead      = $this->lead_de_ro();
        $llamada   = $this->crear_llamada($lead);
        $contenido = $this->contenido_de_audio(10485760, 'c');

        $fila = $this->subir_la_grabacion_entera($lead, $llamada, $contenido);

        $this->assertSame('pendiente', $fila->status);
        $this->assertSame(hash('sha256', $contenido), $fila->sha256);
        $this->assertSame(10485760, (int) $fila->bytes_recibidos);
        $this->assertNotEmpty($fila->ruta, 'La grabación unida queda en `ruta`.');
        $this->assertStringStartsWith('lead_llamadas/' . $lead->id . '/' . $fila->id . '/', (string) $fila->ruta);
        $this->assertTrue(Storage::disk('local')->exists($fila->ruta));
        $this->assertSame(10485760, Storage::disk('local')->size($fila->ruta), 'Las tres partes se unieron enteras y en orden.');
        $this->assertSame(hash('sha256', $contenido), hash_file('sha256', Storage::disk('local')->path($fila->ruta)));

        Queue::assertPushed(TranscribirGrabacionDeLlamadaJob::class, 1);
        Queue::assertPushedOn('transcripciones', TranscribirGrabacionDeLlamadaJob::class);
    }

    /**
     * CA19. El mismo archivo (mismo sha256) en otra llamada del MISMO lead → 409 con la grabación que ya estaba, y no se transcribe dos
     * veces. El mismo archivo en OTRO lead no es repetido: sigue su camino.
     *
     * @return void
     */
    public function test_sha_repetido_409(): void
    {
        Queue::fake();
        $lead      = $this->lead_de_ro();
        $otro_lead = $this->lead_de_ro(['phone' => '+54935112' . random_int(10000, 99999)]);
        $contenido = $this->contenido_de_audio(2000, 'd');

        $primera_llamada = $this->crear_llamada($lead);
        $primera         = $this->subir_la_grabacion_entera($lead, $primera_llamada, $contenido);

        $segunda_llamada = $this->crear_llamada($lead);
        $inicio          = $this->iniciar_la_grabacion($lead, $segunda_llamada, ['nombre' => 'llamada.m4a', 'bytes' => 2000]);
        $inicio->assertStatus(201);
        $this->subir_parte($lead, $segunda_llamada, 0, $contenido)->assertStatus(200);
        $repetida = $this->completar_la_grabacion($lead, $segunda_llamada, ['sha256' => hash('sha256', $contenido)]);

        $repetida->assertStatus(409);
        $this->assertSame((int) $primera->id, (int) $repetida->json('grabacion.id'), 'El 409 trae la grabación que ya estaba.');
        $segunda_fila = $this->grabacion((int) $inicio->json('grabacion.id'));
        $this->assertTrue($segunda_fila === null || $segunda_fila->status !== 'pendiente', 'La repetida no queda esperando transcripción.');
        Queue::assertPushed(TranscribirGrabacionDeLlamadaJob::class, 1);

        $llamada_del_otro = $this->crear_llamada($otro_lead);
        $en_el_otro       = $this->subir_la_grabacion_entera($otro_lead, $llamada_del_otro, $contenido);
        $this->assertSame('pendiente', $en_el_otro->status, 'El mismo audio en otro lead no es repetido.');
        Queue::assertPushed(TranscribirGrabacionDeLlamadaJob::class, 2);
    }

    /**
     * CA19. Una llamada de OTRO lead da 404 en las cinco puertas (iniciar, partes, completar, estado y reintentar), también si no existe
     * la llamada o el lead; no se crea ninguna fila. Vale para un admin común y para Juan.
     *
     * @return void
     */
    public function test_llamada_de_otro_lead_404(): void
    {
        $lead      = $this->lead_de_ro();
        $otro_lead = $this->lead_de_ro(['phone' => '+54935113' . random_int(10000, 99999)]);
        $llamada_del_otro = $this->crear_llamada($otro_lead);

        foreach ([$this->admin, $this->juan] as $quien) {
            $this->loguear_a($quien);

            $this->iniciar_la_grabacion($lead, $llamada_del_otro, [], $quien)->assertStatus(404);
            $this->subir_parte($lead, $llamada_del_otro, 0, $this->contenido_de_audio(10), $quien)->assertStatus(404);
            $this->completar_la_grabacion($lead, $llamada_del_otro, [], $quien)->assertStatus(404);
            $this->getJson($this->url_de_la_grabacion($lead->id, $llamada_del_otro))->assertStatus(404);
            $this->postJson($this->url_de_la_grabacion($lead->id, $llamada_del_otro, '/reintentar'))->assertStatus(404);

            $this->iniciar_la_grabacion($lead, 999999, [], $quien)->assertStatus(404);
            $this->postJson($this->url_de_la_grabacion(999999, $llamada_del_otro), ['nombre' => 'llamada.m4a', 'bytes' => 1000])->assertStatus(404);
        }

        $this->assertSame(0, DB::table('lead_llamada_grabaciones')->count(), 'Ningún 404 deja una fila.');
    }

    /**
     * Pedido de Lucas. Juan (perfil «Sólo Leads») sube la grabación de la llamada de uno de sus leads de punta a punta: iniciar, partes,
     * completar, ver el estado, verla en el listado de llamadas y reintentar. Las respuestas le llegan completas (el filtro de salida del
     * perfil no se come `grabacion`). En la llamada de otro lead no puede.
     *
     * @return void
     */
    public function test_juan_sube_la_grabacion_de_sus_leads(): void
    {
        Queue::fake();
        $this->assertTrue((bool) $this->juan->solo_leads, 'Precondición: Juan tiene el perfil «Sólo Leads».');
        $lead      = $this->lead_de_ro();
        $llamada   = $this->crear_llamada($lead);
        $contenido = $this->contenido_de_audio(5 * self::MB, 'e');

        $inicio = $this->iniciar_la_grabacion($lead, $llamada, ['nombre' => 'llamada.m4a', 'bytes' => strlen($contenido)], $this->juan);
        $inicio->assertStatus(201);
        $this->assertSame('subiendo', $inicio->json('grabacion.status'), 'Juan ve la grabación en la respuesta.');
        $grabacion_id = (int) $inicio->json('grabacion.id');

        foreach (str_split($contenido, self::PARTE) as $indice => $parte) {
            $this->subir_parte($lead, $llamada, $indice, $parte, $this->juan)->assertStatus(200);
        }
        $completa = $this->completar_la_grabacion($lead, $llamada, [], $this->juan);
        $completa->assertStatus(200);
        $this->assertSame('pendiente', $completa->json('grabacion.status'));
        $this->assertSame((int) $this->juan->id, (int) $this->grabacion($grabacion_id)->created_by_admin_id);
        Queue::assertPushedOn('transcripciones', TranscribirGrabacionDeLlamadaJob::class);

        $estado = $this->getJson($this->url_de_la_grabacion($lead->id, $llamada));
        $estado->assertStatus(200);
        $this->assertSame('pendiente', $estado->json('grabacion.status'));

        $listado = $this->getJson('/api/admin/lead/' . $lead->id . '/llamadas');
        $listado->assertStatus(200);
        $this->assertSame('pendiente', $listado->json('models.0.grabacion.status'), 'La lista de llamadas que ve Juan trae la grabación.');

        $this->poner_la_grabacion_en($grabacion_id, 'error');
        $reintento = $this->postJson($this->url_de_la_grabacion($lead->id, $llamada, '/reintentar'));
        $reintento->assertStatus(200);
        $this->assertSame('pendiente', $reintento->json('grabacion.status'));

        $otro_lead        = $this->lead_de_ro(['phone' => '+54935114' . random_int(10000, 99999)]);
        $llamada_del_otro = $this->crear_llamada($otro_lead);
        $this->iniciar_la_grabacion($lead, $llamada_del_otro, [], $this->juan)->assertStatus(404);
        $this->assertSame(0, DB::table('lead_llamada_grabaciones')->where('lead_id', (int) $otro_lead->id)->count());
    }

    /**
     * Sin iniciar sesión nada de esto se puede usar.
     *
     * @return void
     */
    public function test_sin_sesion_401(): void
    {
        $this->exigir_e3();
        $lead = $this->lead_de_ro();

        $this->postJson($this->url_de_la_grabacion($lead->id, 1), ['nombre' => 'llamada.m4a', 'bytes' => 1000])->assertStatus(401);
        $this->getJson($this->url_de_la_grabacion($lead->id, 1))->assertStatus(401);
    }

    /**
     * El estado: antes de subir nada la llamada no tiene grabación (404); después trae estado, resumen, transcripción y compromiso
     * propuesto (vacíos hasta que se transcribe).
     *
     * @return void
     */
    public function test_el_estado_de_la_grabacion(): void
    {
        Queue::fake();
        $lead    = $this->lead_de_ro();
        $llamada = $this->crear_llamada($lead);

        $this->loguear_a($this->juan);
        $this->getJson($this->url_de_la_grabacion($lead->id, $llamada))->assertStatus(404);

        $this->subir_la_grabacion_entera($lead, $llamada, $this->contenido_de_audio(3000, 'f'));

        $estado = $this->getJson($this->url_de_la_grabacion($lead->id, $llamada));
        $estado->assertStatus(200);
        $grabacion = $estado->json('grabacion');
        foreach (['id', 'status', 'resumen', 'transcripcion', 'error_text', 'compromiso_propuesto'] as $clave) {
            $this->assertArrayHasKey($clave, $grabacion, 'El estado trae «' . $clave . '».');
        }
        $this->assertSame('pendiente', $grabacion['status']);
        $this->assertEmpty($grabacion['transcripcion']);
    }

    /**
     * `GET lead/{id}/llamadas` suma `grabacion` a cada llamada (aditivo; la que no tiene trae `grabacion` en null, el resto de la llamada
     * queda igual).
     *
     * @return void
     */
    public function test_el_listado_de_llamadas_trae_la_grabacion(): void
    {
        Queue::fake();
        $lead        = $this->lead_de_ro();
        $sin_audio   = $this->crear_llamada($lead);
        $con_audio   = $this->crear_llamada($lead);
        $this->subir_la_grabacion_entera($lead, $con_audio, $this->contenido_de_audio(3000, 'g'));

        $this->loguear_a($this->admin);
        $listado = $this->getJson('/api/admin/lead/' . $lead->id . '/llamadas');
        $listado->assertStatus(200);

        $por_id = [];
        foreach ($listado->json('models') as $modelo) {
            $por_id[(int) $modelo['id']] = $modelo;
        }

        $this->assertArrayHasKey('grabacion', $por_id[$con_audio]);
        $this->assertSame('pendiente', $por_id[$con_audio]['grabacion']['status']);
        $this->assertArrayHasKey('grabacion', $por_id[$sin_audio], 'La clave está siempre (aditivo).');
        $this->assertNull($por_id[$sin_audio]['grabacion']);
        $this->assertArrayHasKey('notas', $por_id[$sin_audio], 'Lo que ya traía la llamada sigue ahí.');
    }

    /**
     * Los valores por defecto de la configuración de la grabación (leídos del archivo de configuración, sin los pisados de estos tests):
     * modelo `gpt-4o-transcribe`, tramos de 600 segundos y 200 MB de tope.
     *
     * @return void
     */
    public function test_configuracion_por_defecto(): void
    {
        $servicios = require base_path('config/services.php');

        $this->assertArrayHasKey('openai_call_transcription', $servicios, 'Falta `openai_call_transcription` en config/services.php.');
        $configuracion = $servicios['openai_call_transcription'];
        $this->assertSame('gpt-4o-transcribe', $configuracion['model']);
        $this->assertSame(600, (int) $configuracion['tramo_segundos']);
        $this->assertSame(209715200, (int) $configuracion['max_bytes']);
    }

    /**
     * Los datos personales no van a los logs: ni el nombre del archivo (que suele traer el nombre de la persona), ni el teléfono del
     * lead, en todo el camino de la subida (iniciar, partes, completar y estado).
     *
     * @return void
     */
    public function test_la_subida_no_deja_datos_personales_en_los_logs(): void
    {
        Queue::fake();
        $lead    = $this->lead_de_ro(['phone' => '+5493515550199']);
        $llamada = $this->crear_llamada($lead);

        $this->subir_la_grabacion_entera($lead, $llamada, $this->contenido_de_audio(3000, 'h'), 'Llamada con Rosario Benítez 3515550199.m4a');
        $this->getJson($this->url_de_la_grabacion($lead->id, $llamada))->assertStatus(200);

        $log = $this->texto_del_log();
        $this->assertStringNotContainsString('Rosario', $log, 'El nombre del archivo no se loguea.');
        $this->assertStringNotContainsString('Benítez', $log);
        $this->assertStringNotContainsString('3515550199', $log, 'El teléfono del lead no se loguea.');
    }
}
