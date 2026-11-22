<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadMessageAttachment;
use App\Services\ClaudeCatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Claude puede bajar el adjunto de un mensaje de un lead (audio, imagen, PDF), igual que el de un ticket.
 *
 * El endpoint hermano es `GET claude/support-codex/tickets/{id}/attachments/{attachment_id}`. Este es
 * `GET claude/leads/{id}/attachments/{attachment_id}`: se descubre el id con
 * `claude/query?model=lead_message_attachment&lead_message_id=…` y se descarga con la clave de ingesta.
 *
 * Lo que más importa NO es el camino feliz: es que no se pueda bajar el adjunto de OTRO lead, ni un archivo
 * fuera del disco público, ni nada sin la clave. Por eso todos los tests de 404 y 401 abren con un CONTROL
 * (el mismo pedido válido responde 200): un 404 por "la ruta no existe" no prueba nada.
 *
 * Criterios de aceptación que cubre: CA7, CA8 y CA9 de `.mision/PLAN.md`.
 */
class AdjuntosDeLeadsParaClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude';

    /** Bytes del archivo guardado: lo que se tiene que recibir idéntico. */
    const BYTES = "OggS\x00\x02-audio-de-prueba-\x00\xff\x10";

    /**
     * Archivos creados fuera del disco falseado, para borrarlos al terminar.
     *
     * @var array<int, string>
     */
    private $archivos_a_borrar = [];

    /**
     * Setea la clave de ingesta (en el .env del slot está vacía y el middleware es fail-closed) y el disco falso.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Borra lo que el test dejó fuera del disco falseado.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->archivos_a_borrar as $archivo) {
            if (is_file($archivo)) {
                @unlink($archivo);
            }
        }
        $this->archivos_a_borrar = [];

        parent::tearDown();
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
     * Lead con teléfono cargado.
     *
     * @return Lead
     */
    private function crear_lead(): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = 'Dany';
        $lead->company_name = 'Empresa de Dany';
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->status       = 'contactado';
        $lead->save();

        return $lead;
    }

    /**
     * Mensaje del lead con un adjunto cuyo archivo está (o no) en el disco público falseado.
     *
     * @param Lead        $lead       Lead dueño del mensaje.
     * @param string|null $mime       Mime declarado del adjunto.
     * @param string|null $path       Ruta guardada en la fila; null arma una normal.
     * @param bool        $con_archivo Si se escribe el archivo en el disco.
     *
     * @return LeadMessageAttachment
     */
    private function crear_adjunto(Lead $lead, ?string $mime = 'audio/ogg', ?string $path = null, bool $con_archivo = true): LeadMessageAttachment
    {
        $mensaje = LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'lead',
            'kind'                  => 'audio',
            'content'               => 'Audio del lead',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
            'whatsapp_message_id'   => 'wamid.adjunto.' . uniqid(),
            'sent_at'               => now(),
        ]);

        if ($path === null) {
            $path = 'lead_messages/' . $lead->id . '/in_' . uniqid() . '.ogg';
        }
        if ($con_archivo) {
            Storage::disk('public')->put($path, self::BYTES);
        }

        return LeadMessageAttachment::create([
            'lead_message_id' => $mensaje->id,
            'disk'            => 'public',
            'path'            => $path,
            'mime'            => $mime,
            'size'            => strlen(self::BYTES),
        ]);
    }

    /**
     * URL del endpoint nuevo.
     *
     * @param int|string $lead_id       Id (o texto) del lead.
     * @param int|string $attachment_id Id (o texto) del adjunto.
     *
     * @return string
     */
    private function url($lead_id, $attachment_id): string
    {
        return '/api/claude/leads/' . $lead_id . '/attachments/' . $attachment_id;
    }

    /**
     * Contenido del archivo que devolvió una respuesta de descarga.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta 200 de la descarga.
     *
     * @return string
     */
    private function bytes_de_la_descarga($respuesta): string
    {
        return (string) file_get_contents($respuesta->baseResponse->getFile()->getPathname());
    }

    // ------------------------------------------------------------------------------------------------
    // CA7 — descarga
    // ------------------------------------------------------------------------------------------------

    /**
     * CA7: se baja el adjunto de su lead, byte por byte, con las mismas cabeceras que el endpoint de tickets.
     *
     * @return void
     */
    public function test_baja_el_adjunto_de_su_lead_con_las_cabeceras_del_de_tickets()
    {
        $lead    = $this->crear_lead();
        $adjunto = $this->crear_adjunto($lead, 'audio/ogg');

        $respuesta = $this->get($this->url($lead->id, $adjunto->id), $this->headers());

        $respuesta->assertStatus(200);
        $this->assertSame(self::BYTES, $this->bytes_de_la_descarga($respuesta), 'El contenido no es idéntico al guardado.');
        $this->assertSame('audio/ogg', $respuesta->headers->get('Content-Type'));
        $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
        $disposicion = (string) $respuesta->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment', $disposicion);
        $this->assertStringContainsString('filename=adjunto-' . $adjunto->id, str_replace('"', '', $disposicion));

        // Sin mime declarado, el tipo genérico: nunca se adivina uno que el navegador pueda renderizar.
        $sin_mime = $this->crear_adjunto($lead, null);
        $respuesta_sin_mime = $this->get($this->url($lead->id, $sin_mime->id), $this->headers());
        $respuesta_sin_mime->assertStatus(200);
        $this->assertSame('application/octet-stream', $respuesta_sin_mime->headers->get('Content-Type'));
        $this->assertSame('nosniff', $respuesta_sin_mime->headers->get('X-Content-Type-Options'));
    }

    // ------------------------------------------------------------------------------------------------
    // CA8 — 404 y 401
    // ------------------------------------------------------------------------------------------------

    /**
     * Casos en que el adjunto pedido no se puede bajar con ese par (lead, adjunto).
     *
     * @return array<string, array{0: string}>
     */
    public function casos_de_404(): array
    {
        return [
            'el adjunto es de otro lead' => ['otro_lead'],
            'el adjunto no existe'       => ['adjunto_inexistente'],
            'el lead no existe'          => ['lead_inexistente'],
            'el archivo no está en disco' => ['archivo_ausente'],
        ];
    }

    /**
     * CA8: 404 si el adjunto es de otro lead, no existe, el lead no existe o el archivo no está en el disco.
     *
     * Abre con un control: el pedido bien armado del lead A responde 200. Así el 404 viene de la regla y no de
     * que la ruta no exista.
     *
     * @dataProvider casos_de_404
     *
     * @param string $caso Cuál de los casos probar.
     *
     * @return void
     */
    public function test_404_si_el_adjunto_no_es_del_lead_o_no_existe_o_no_esta_en_disco(string $caso)
    {
        $lead_a    = $this->crear_lead();
        $adjunto_a = $this->crear_adjunto($lead_a);
        $lead_b    = $this->crear_lead();
        $adjunto_b = $this->crear_adjunto($lead_b);

        // Control: el par correcto se baja.
        $this->get($this->url($lead_a->id, $adjunto_a->id), $this->headers())->assertStatus(200);
        $this->get($this->url($lead_b->id, $adjunto_b->id), $this->headers())->assertStatus(200);

        if ($caso === 'otro_lead') {
            // El adjunto de B pedido bajo el lead A: no se filtra entre leads.
            $url = $this->url($lead_a->id, $adjunto_b->id);
        } elseif ($caso === 'adjunto_inexistente') {
            $url = $this->url($lead_a->id, 987654321);
        } elseif ($caso === 'lead_inexistente') {
            $url = $this->url(987654321, $adjunto_a->id);
        } else {
            Storage::disk('public')->delete($adjunto_a->path);
            $url = $this->url($lead_a->id, $adjunto_a->id);
        }

        $respuesta = $this->get($url, $this->headers());
        $respuesta->assertStatus(404);
        $this->assertStringNotContainsString(self::BYTES, (string) $respuesta->getContent(), 'El 404 devolvió el archivo.');
    }

    /**
     * CA8: una ruta guardada con `..` (que apunta fuera del disco público) da 404 y no sirve el archivo.
     *
     * Se prueba con la ruta del plan (`../../.env`) y con un archivo REAL puesto fuera de la raíz del disco, para
     * que el 404 no pueda deberse a que el destino no existe.
     *
     * @return void
     */
    public function test_404_con_path_que_sale_del_disco()
    {
        $lead   = $this->crear_lead();
        $normal = $this->crear_adjunto($lead);

        // Control: el adjunto normal del mismo lead se baja.
        $this->get($this->url($lead->id, $normal->id), $this->headers())->assertStatus(200);

        // Archivo real fuera de la raíz del disco público falseado.
        $raiz   = dirname(Storage::disk('public')->path('control.txt'));
        $fuera  = dirname($raiz) . DIRECTORY_SEPARATOR . 'fuera_del_disco_' . uniqid() . '.txt';
        file_put_contents($fuera, 'SECRETO-FUERA-DEL-DISCO');
        $this->archivos_a_borrar[] = $fuera;

        $traversal_real = $this->crear_adjunto($lead, 'text/plain', '../' . basename($fuera), false);
        $traversal_env  = $this->crear_adjunto($lead, 'text/plain', '../../.env', false);

        foreach ([$traversal_real, $traversal_env] as $adjunto) {
            $respuesta = $this->get($this->url($lead->id, $adjunto->id), $this->headers());
            $respuesta->assertStatus(404);
            $this->assertStringNotContainsString('SECRETO-FUERA-DEL-DISCO', (string) $respuesta->getContent());
        }
    }

    /**
     * CA8: un id que no es numérico no llega al controlador: 404 (igual que `whereNumber` en el de tickets).
     *
     * Con el control del pedido válido para no confundirlo con "la ruta no existe".
     *
     * @return void
     */
    public function test_404_con_id_no_numerico()
    {
        $lead    = $this->crear_lead();
        $adjunto = $this->crear_adjunto($lead);

        $this->get($this->url($lead->id, $adjunto->id), $this->headers())->assertStatus(200);

        $this->get($this->url('abc', $adjunto->id), $this->headers())->assertStatus(404);
        $this->get($this->url($lead->id, 'abc'), $this->headers())->assertStatus(404);
    }

    /**
     * CA8: sin la clave de ingesta (o con una equivocada) el endpoint responde 401 y no entrega nada.
     *
     * @return void
     */
    public function test_401_sin_clave()
    {
        $lead    = $this->crear_lead();
        $adjunto = $this->crear_adjunto($lead);

        // Control: con la clave, el mismo pedido responde 200.
        $this->get($this->url($lead->id, $adjunto->id), $this->headers())->assertStatus(200);

        $this->get($this->url($lead->id, $adjunto->id), ['Accept' => 'application/json'])->assertStatus(401);
        $this->get($this->url($lead->id, $adjunto->id), ['X-Claude-Task-Key' => 'clave-equivocada', 'Accept' => 'application/json'])->assertStatus(401);
    }

    // ------------------------------------------------------------------------------------------------
    // CA9 — catálogo
    // ------------------------------------------------------------------------------------------------

    /**
     * CA9: la ruta nueva está declarada en el catálogo de endpoints de Claude, como lectura sin frenos.
     *
     * No agrega una ruta más a las "sin declarar" que ya venían rotas de antes (otra misión las arregla): lo que
     * se afirma es que ésta no está entre ellas y que la entrada no apunta a una ruta inexistente.
     *
     * @return void
     */
    public function test_la_ruta_esta_declarada_en_el_catalogo()
    {
        $clave = 'GET api/claude/leads/{id}/attachments/{attachment_id}';

        // Entradas declaradas del catálogo (clave `METODO ruta`).
        $entradas = (array) config('claude_catalog.endpoints', []);
        $this->assertArrayHasKey($clave, $entradas, 'Falta la entrada en config/claude_catalog.php.');
        $this->assertFalse($entradas[$clave]['escribe']);
        $this->assertSame('lectura', $entradas[$clave]['peligrosidad']);
        $this->assertSame([], $entradas[$clave]['frenos']);
        $this->assertNotSame('', trim((string) $entradas[$clave]['para_que']));

        $cotejo = app(ClaudeCatalogService::class)->cotejar();
        $this->assertNotContains($clave, $cotejo['sin_descripcion'], 'La ruta quedó viva pero sin describir en el catálogo.');
        $this->assertNotContains($clave, $cotejo['declaradas_que_ya_no_existen'], 'La entrada del catálogo apunta a una ruta que no existe.');
        $this->assertSame([], $cotejo['declaradas_que_ya_no_existen']);
    }
}
