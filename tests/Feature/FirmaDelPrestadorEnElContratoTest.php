<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Services\ContractSignatureService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Firma del PRESTADOR en el PDF del contrato de un lead.
 *
 * Lo que estos tests defienden, en orden de importancia:
 *
 * 1. **Compatibilidad hacia atrás.** Sin firma cargada, o con el interruptor apagado, el PDF
 *    tiene que salir como salía antes de esta funcionalidad. Y una SPA vieja que mande `{}`
 *    tiene que seguir recibiendo un contrato válido.
 * 2. **Que un contrato nunca deje de generarse.** Una firma corrupta, una setting apuntando a
 *    un archivo que no está, un disco que no responde: el contrato sale sin firma, no falla.
 *    Un contrato sin firma es un inconveniente; uno que no se genera frena una venta.
 * 3. **Que la UI no mienta.** Si la firma no se va a poder estampar, `cargada` tiene que decir
 *    `false`, aunque haya un archivo en disco.
 * 4. **Que los cuatro endpoints estén detrás de `auth:sanctum`.** Es la firma de una persona.
 *
 * 🔴 `Storage::fake('local')` va en el `setUp()`. Sin eso la suite escribiría sobre la firma
 * real del entorno de Lucas, que vive en el mismo disco `local` y con el mismo nombre fijo.
 */
class FirmaDelPrestadorEnElContratoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Ruta base de los cuatro endpoints de la firma.
     */
    const RUTA_FIRMA = '/api/admin/settings/contract-signature';

    /**
     * Archivos que los ayudantes dejan en el temp del sistema y hay que barrer al terminar.
     *
     * @var array<int, string>
     */
    private $archivos_temporales = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 El aislamiento de toda la suite depende de esta línea. No la saques.
        Storage::fake('local');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->archivos_temporales as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }

        $this->archivos_temporales = [];

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     |  Ayudantes
     * ------------------------------------------------------------------ */

    /**
     * Crea un admin para autenticar con Sanctum. No hay `database/factories/` en este repo.
     *
     * @param string $email
     *
     * @return Admin
     */
    private function crear_admin(string $email = 'firma@test.local'): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Crea un lead con datos de contrato suficientes para que el PDF salga completo.
     *
     * @return Lead
     */
    private function crear_lead_con_contrato(): Lead
    {
        $lead = new Lead();
        $lead->contact_name                 = 'Prospecto de prueba';
        $lead->company_name                 = 'Comercio de prueba';
        $lead->email                        = 'prospecto@test.local';
        $lead->status                       = 'nuevo';
        $lead->contract_client_name         = 'Juan Pérez';
        $lead->contract_client_razon_social = 'Juan Pérez S.A.';
        $lead->contract_client_cuit         = '20-11111111-1';
        $lead->contract_currency            = 'USD';
        $lead->contract_precio_licencia     = '1500';
        $lead->contract_fecha_emision       = '2026-08-21';
        $lead->contract_mensualidad_moneda  = 'USD';
        $lead->contract_mensualidad_base    = '100';
        $lead->contract_usuarios_incluidos  = 3;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * PNG de prueba con la relación de aspecto que se le pida.
     *
     * @param int    $ancho
     * @param int    $alto
     * @param string $nombre
     *
     * @return UploadedFile
     */
    private function png(int $ancho = 320, int $alto = 386, string $nombre = 'firma.png'): UploadedFile
    {
        return UploadedFile::fake()->image($nombre, $ancho, $alto);
    }

    /**
     * PNG con ruido: cada píxel de un color distinto, así el archivo NO comprime.
     *
     * ⚠️ Existe por una razón concreta y medida. `UploadedFile::fake()->image()` genera una
     * imagen de un solo color: dompdf la comprime a casi nada y el PDF con firma pesa apenas
     * **712 bytes** más que el PDF sin firma. Con esa imagen, cualquier aserción de tamaño es
     * o inútil (un umbral de 500 bytes, que pasa de casualidad) o directamente falsa (los
     * "+10 KB" que estimaba el plan, medidos con el PNG real de Lucas). Con ruido, la imagen
     * embebida pesa de verdad —el delta medido pasa de 712 bytes a **~12 KB**— y la aserción de
     * tamaño vuelve a ser una red que sirve.
     *
     * @param int $ancho
     * @param int $alto
     *
     * @return UploadedFile
     */
    private function png_con_ruido(int $ancho, int $alto): UploadedFile
    {
        $imagen = imagecreatetruecolor($ancho, $alto);

        for ($x = 0; $x < $ancho; $x++) {
            for ($y = 0; $y < $alto; $y++) {
                imagesetpixel($imagen, $x, $y, imagecolorallocate($imagen, ($x * 7 + $y * 13) % 256, ($x * 31 + $y * 3) % 256, ($x * 17 + $y * 29) % 256));
            }
        }

        // `tempnam` crea el archivo sin extensión y hay que barrer los DOS: el que crea él y el
        // `.png` que escribe `imagepng`. Si solo se barriera el segundo, cada corrida de la suite
        // dejaría un archivo huérfano en el temp del sistema.
        $base = tempnam(sys_get_temp_dir(), 'firma');
        $ruta = $base . '.png';

        imagepng($imagen, $ruta);
        imagedestroy($imagen);

        $this->archivos_temporales[] = $base;
        $this->archivos_temporales[] = $ruta;

        // El último `true` es el modo test del UploadedFile: sin él, `isValid()` da false
        // porque el archivo no vino de un upload HTTP real.
        return new UploadedFile($ruta, 'firma-con-ruido.png', 'image/png', null, true);
    }

    /**
     * Deja una firma cargada por la vía real: el endpoint de subida.
     *
     * @param Admin $admin
     * @param int   $ancho
     * @param int   $alto
     *
     * @return void
     */
    private function subir_firma(Admin $admin, int $ancho = 320, int $alto = 386): void
    {
        $this->actingAs($admin, 'sanctum')
            ->post(self::RUTA_FIRMA, ['firma' => $this->png($ancho, $alto)])
            ->assertStatus(200);
    }

    /**
     * Genera el contrato del lead y devuelve el binario del PDF.
     *
     * @param Admin      $admin
     * @param Lead       $lead
     * @param array|null $body Cuerpo del POST. `null` manda el body vacío de una SPA vieja.
     *
     * @return string
     */
    private function generar_contrato(Admin $admin, Lead $lead, array $body = null): string
    {
        $respuesta = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/generate-contract', $body === null ? [] : $body);

        $respuesta->assertStatus(200);

        $pdf = (string) $respuesta->getContent();

        $this->assertStringStartsWith('%PDF', $pdf, 'La respuesta no es un PDF.');

        return $pdf;
    }

    /**
     * ¿El PDF tiene una imagen embebida?
     *
     * Medido: dompdf escribe `/Subtype /Image` en el binario cuando hay una imagen y no lo
     * escribe cuando no la hay. Es una aserción directa, no una estimación.
     *
     * @param string $pdf
     *
     * @return bool
     */
    private function tiene_imagen(string $pdf): bool
    {
        return strpos($pdf, '/Image') !== false;
    }

    /* ------------------------------------------------------------------ *
     |  1 a 4 — el PDF y el interruptor
     * ------------------------------------------------------------------ */

    /**
     * 1. Sin firma cargada, el contrato sale como salía antes de esta funcionalidad.
     *
     * @return void
     */
    public function test_sin_firma_cargada_el_contrato_sale_como_siempre(): void
    {
        $admin = $this->crear_admin();
        $lead  = $this->crear_lead_con_contrato();

        $pdf = $this->generar_contrato($admin, $lead);

        $this->assertFalse(
            $this->tiene_imagen($pdf),
            'El contrato trae una imagen embebida sin que haya ninguna firma cargada.'
        );
    }

    /**
     * 2. Con firma cargada, la imagen entra en el PDF.
     *
     * La aserción que manda es la de `/Image`: aparece con firma y no aparece sin ella, así que
     * discrimina perfecto. La del tamaño va de red por si algún día dompdf cambia cómo serializa
     * y `/Image` deja de ser un indicador confiable — y para que esa red sirva, la firma de este
     * test se sube con ruido (ver `png_con_ruido`), no con la imagen uniforme del generador.
     *
     * @return void
     */
    public function test_con_firma_cargada_el_contrato_lleva_la_imagen(): void
    {
        $admin = $this->crear_admin();
        $lead  = $this->crear_lead_con_contrato();

        $sin_firma = $this->generar_contrato($admin, $lead);

        $this->actingAs($admin, 'sanctum')
            ->post(self::RUTA_FIRMA, ['firma' => $this->png_con_ruido(320, 386)])
            ->assertStatus(200);

        $con_firma = $this->generar_contrato($admin, $lead);

        $this->assertTrue(
            $this->tiene_imagen($con_firma),
            'El contrato salió sin imagen embebida aunque hay una firma cargada.'
        );

        $this->assertGreaterThan(
            strlen($sin_firma) + 5000,
            strlen($con_firma),
            'El PDF con firma no pesa lo que tiene que pesar de más: la imagen no entró en el binario.'
        );
    }

    /**
     * 3. El interruptor apagado saca la firma aunque esté cargada.
     *
     * @return void
     */
    public function test_el_interruptor_apagado_saca_la_firma(): void
    {
        $admin = $this->crear_admin();
        $lead  = $this->crear_lead_con_contrato();

        $this->subir_firma($admin);

        $pdf = $this->generar_contrato($admin, $lead, ['incluir_firma' => false]);

        $this->assertFalse(
            $this->tiene_imagen($pdf),
            'La firma se estampó igual con el interruptor apagado.'
        );
    }

    /**
     * 4. Un body vacío —una SPA vieja que todavía no manda el interruptor— recibe el contrato
     *    CON firma. Es la compatibilidad hacia atrás del contrato de API, no un detalle.
     *
     * @return void
     */
    public function test_por_defecto_la_firma_va_incluida(): void
    {
        $admin = $this->crear_admin();
        $lead  = $this->crear_lead_con_contrato();

        $this->subir_firma($admin);

        $pdf = $this->generar_contrato($admin, $lead, null);

        $this->assertTrue(
            $this->tiene_imagen($pdf),
            'Un body sin `incluir_firma` tiene que salir CON firma: es lo que manda una SPA vieja.'
        );
    }

    /* ------------------------------------------------------------------ *
     |  5 a 11 — los cuatro endpoints
     * ------------------------------------------------------------------ */

    /**
     * 5. La subida deja el archivo en disco y la ruta en la setting.
     *
     * @return void
     */
    public function test_subir_la_firma_guarda_el_archivo_y_la_ruta(): void
    {
        $admin = $this->crear_admin();

        $respuesta = $this->actingAs($admin, 'sanctum')
            ->post(self::RUTA_FIRMA, ['firma' => $this->png(320, 386)]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson([
            'cargada' => true,
            'ancho'   => 320,
            'alto'    => 386,
        ]);

        $ruta = AdminSetting::get(ContractSignatureService::CLAVE_RUTA);

        $this->assertSame('firmas/firma-prestador.png', $ruta);
        Storage::disk('local')->assertExists($ruta);

        $this->assertNotNull(
            AdminSetting::get(ContractSignatureService::CLAVE_ACTUALIZADA_EN),
            'No quedó registrada la fecha de la última subida.'
        );

        // No puede quedar ningún temporal de la escritura en dos pasos dando vueltas.
        $sobrantes = array_filter(
            Storage::disk('local')->files(ContractSignatureService::CARPETA),
            function ($archivo) {
                return strpos($archivo, '.tmp') !== false;
            }
        );

        $this->assertSame([], array_values($sobrantes), 'Quedó un archivo temporal de la subida.');
    }

    /**
     * 6. Un archivo que no es imagen rebota con 422 y no deja setting.
     *
     * @return void
     */
    public function test_rechaza_un_archivo_que_no_es_imagen(): void
    {
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::RUTA_FIRMA, [
                'firma' => UploadedFile::fake()->createWithContent('contrato.pdf', '%PDF-1.4 no soy una firma'),
            ])
            ->assertStatus(422);

        $this->assertNull(
            AdminSetting::get(ContractSignatureService::CLAVE_RUTA),
            'Un archivo rechazado dejó igual la setting de la firma.'
        );
    }

    /**
     * 7. Una imagen fuera del rango de dimensiones permitido rebota con 422.
     *
     * ⚠️ Se prueba con 4.500 × 100 (pasada de ancho) y con 100 × 30 (el favicon subido por
     * error), y no con los 5000 × 5000 del plan: esa imagen también supera `PIXELES_MAX`, así
     * que un verde no diría cuál de las dos reglas la frenó — y además cuesta 100 MB generarla.
     * El tope de píxeles tiene su propio test más abajo.
     *
     * @return void
     */
    public function test_rechaza_una_imagen_fuera_de_las_dimensiones_permitidas(): void
    {
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::RUTA_FIRMA, ['firma' => $this->png(4500, 100, 'ancha.png')])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::RUTA_FIRMA, ['firma' => $this->png(100, 30, 'favicon.png')])
            ->assertStatus(422);

        $this->assertNull(AdminSetting::get(ContractSignatureService::CLAVE_RUTA));
    }

    /**
     * 8. Reemplazar la firma borra el archivo anterior y no duplica la setting.
     *
     * @return void
     */
    public function test_reemplazar_la_firma_borra_el_archivo_anterior(): void
    {
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')
            ->post(self::RUTA_FIRMA, ['firma' => $this->png(320, 386, 'primera.png')])
            ->assertStatus(200);

        Storage::disk('local')->assertExists('firmas/firma-prestador.png');

        $this->actingAs($admin, 'sanctum')
            ->post(self::RUTA_FIRMA, ['firma' => UploadedFile::fake()->image('segunda.jpg', 300, 200)])
            ->assertStatus(200);

        Storage::disk('local')->assertMissing('firmas/firma-prestador.png');
        Storage::disk('local')->assertExists('firmas/firma-prestador.jpg');

        $this->assertSame(
            'firmas/firma-prestador.jpg',
            AdminSetting::get(ContractSignatureService::CLAVE_RUTA)
        );

        $this->assertSame(
            1,
            AdminSetting::where('key', ContractSignatureService::CLAVE_RUTA)->count(),
            'El reemplazo duplicó la fila de configuración en vez de actualizarla.'
        );
    }

    /**
     * 9. La vista previa devuelve los bytes con su Content-Type.
     *
     * @return void
     */
    public function test_la_vista_previa_devuelve_la_imagen_con_su_content_type(): void
    {
        $admin = $this->crear_admin();
        $this->subir_firma($admin);

        $respuesta = $this->actingAs($admin, 'sanctum')->get(self::RUTA_FIRMA . '/file');

        $respuesta->assertStatus(200);
        $respuesta->assertHeader('Content-Type', 'image/png');

        $cuerpo = (string) $respuesta->getContent();

        $this->assertNotSame('', $cuerpo, 'La vista previa devolvió un cuerpo vacío.');
        $this->assertStringStartsWith("\x89PNG", $cuerpo, 'Lo que devolvió la vista previa no es un PNG.');
    }

    /**
     * 10. Sin firma cargada, la vista previa da 404.
     *
     * @return void
     */
    public function test_la_vista_previa_sin_firma_da_404(): void
    {
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson(self::RUTA_FIRMA . '/file')
            ->assertStatus(404);
    }

    /**
     * 11. El borrado saca el archivo y las dos claves, y es idempotente.
     *
     * ⚠️ `borrar()` ELIMINA las filas de `admin_settings`, así que la setting queda en `null`,
     * no en cadena vacía.
     *
     * @return void
     */
    public function test_borrar_la_firma_saca_el_archivo_y_las_claves(): void
    {
        $admin = $this->crear_admin();
        $this->subir_firma($admin);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson(self::RUTA_FIRMA)
            ->assertStatus(200)
            ->assertJson(['cargada' => false]);

        Storage::disk('local')->assertMissing('firmas/firma-prestador.png');

        $this->assertNull(AdminSetting::get(ContractSignatureService::CLAVE_RUTA));
        $this->assertNull(AdminSetting::get(ContractSignatureService::CLAVE_ACTUALIZADA_EN));

        // Idempotencia: el segundo borrado tampoco falla.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson(self::RUTA_FIRMA)
            ->assertStatus(200)
            ->assertJson(['cargada' => false]);
    }

    /**
     * 12. 🔴 Los cuatro endpoints exigen autenticación.
     *
     * Este es el que defiende que la firma de una persona no quede colgada de una URL pública.
     * Si mañana alguien mueve una de las rutas fuera del grupo `auth:sanctum`, se pone rojo acá.
     *
     * @return void
     */
    public function test_los_endpoints_de_firma_exigen_autenticacion(): void
    {
        $this->getJson(self::RUTA_FIRMA)->assertStatus(401);
        $this->postJson(self::RUTA_FIRMA, ['firma' => $this->png()])->assertStatus(401);
        $this->getJson(self::RUTA_FIRMA . '/file')->assertStatus(401);
        $this->deleteJson(self::RUTA_FIRMA)->assertStatus(401);
    }

    /* ------------------------------------------------------------------ *
     |  13 y 14 — casos borde del PDF y de las medidas
     * ------------------------------------------------------------------ */

    /**
     * 13. Setting apuntando a un archivo que no está: el contrato sale igual, sin firma.
     *
     * Es el caso "deployé admin y me olvidé de subir la firma en el servidor": `storage/app/`
     * está gitignoreado, así que la firma NO viaja en el repo.
     *
     * @return void
     */
    public function test_si_la_setting_apunta_a_un_archivo_que_no_esta_el_contrato_sale_igual(): void
    {
        $admin = $this->crear_admin();
        $lead  = $this->crear_lead_con_contrato();

        AdminSetting::set(ContractSignatureService::CLAVE_RUTA, 'firmas/firma-prestador.png');
        AdminSetting::set(ContractSignatureService::CLAVE_ACTUALIZADA_EN, now()->toIso8601String());

        $pdf = $this->generar_contrato($admin, $lead);

        $this->assertFalse(
            $this->tiene_imagen($pdf),
            'El contrato embebió una imagen apuntando a un archivo que no existe.'
        );
    }

    /**
     * 14. Una firma apaisada no se sale de la columna, y el hueco arriba de la línea sigue
     *     midiendo lo mismo.
     *
     * El invariante que sostiene todo el diseño: alto + separación + margen superior = HUECO_PT,
     * venga la imagen con la proporción que venga. Es lo que mantiene las dos líneas de firma
     * (PRESTADOR y CLIENTE) a la misma altura y lo que hace que los saltos de página no cambien.
     *
     * @return void
     */
    public function test_una_firma_apaisada_no_se_sale_de_la_columna(): void
    {
        $admin = $this->crear_admin();
        $this->subir_firma($admin, 2000, 200);

        $medidas = ContractSignatureService::medidas_en_puntos();

        $this->assertNotNull($medidas, 'El servicio no pudo medir una firma recién subida.');

        $this->assertLessThanOrEqual(
            ContractSignatureService::ANCHO_MAX_PT,
            $medidas['ancho_pt'],
            'La firma apaisada se sale del ancho de la columna.'
        );

        $this->assertLessThanOrEqual(
            ContractSignatureService::ALTO_MAX_PT,
            $medidas['alto_pt'],
            'La firma se pasa del alto máximo.'
        );

        $this->assertEqualsWithDelta(
            ContractSignatureService::HUECO_PT,
            $medidas['alto_pt'] + ContractSignatureService::SEPARACION_PT + $medidas['margen_superior_pt'],
            0.01,
            'El hueco arriba de la línea dejó de valer HUECO_PT: las dos líneas de firma van a quedar desalineadas.'
        );
    }

    /* ------------------------------------------------------------------ *
     |  15 a 19 — los que salieron de los arreglos posteriores al chequeo
     * ------------------------------------------------------------------ */

    /**
     * 15. Una imagen que pasa las dimensiones máximas pero supera el tope de píxeles rebota
     *     con 422, no con un 500.
     *
     * 1.600 × 4.000 = 6.400.000 px: entra holgado adentro de `max_width=4000,max_height=4000`
     * y de `max:2048` (un PNG casi uniforme comprime a nada), pero pasa `PIXELES_MAX`.
     *
     * ⚠️ Este test verifica el 422; NO reproduce el fatal de memoria que el tope previene. Bajo
     * `phpunit.xml` el `memory_limit` es 512M y el bitmap de esta imagen son ~25 MB: acá entra
     * de sobra. El fatal aparece con los 128M del runtime web, que es el escenario real y el que
     * no se puede montar desde la suite. O sea: un verde acá dice que la puerta está puesta, no
     * que el fatal esté reproducido. No concluyas de más.
     *
     * @return void
     */
    public function test_rechaza_una_imagen_que_supera_el_tope_de_pixeles(): void
    {
        $admin = $this->crear_admin();

        $pixeles = 1600 * 4000;

        $this->assertGreaterThan(
            ContractSignatureService::PIXELES_MAX,
            $pixeles,
            'La imagen del test dejó de superar PIXELES_MAX: hay que agrandarla o el test no prueba nada.'
        );

        $respuesta = $this->actingAs($admin, 'sanctum')
            ->postJson(self::RUTA_FIRMA, ['firma' => $this->png(1600, 4000, 'enorme.png')]);

        $respuesta->assertStatus(422);

        $this->assertStringContainsString(
            'píxeles',
            (string) $respuesta->json('message'),
            'El 422 no vino del tope de píxeles sino de otra regla.'
        );

        $this->assertNull(AdminSetting::get(ContractSignatureService::CLAVE_RUTA));
    }

    /**
     * 16. Un `.png` de bytes basura en disco NO puede reportarse como firma cargada.
     *
     * Si `cargada` dijera `true`, la SPA pintaría el badge verde y Lucas mandaría el contrato
     * creyendo que va firmado, cuando el PDF sale sin firma. La UI y el PDF tienen que
     * descartar por los mismos dos motivos.
     *
     * @return void
     */
    public function test_una_firma_corrupta_en_disco_no_se_reporta_como_cargada(): void
    {
        $admin = $this->crear_admin();

        Storage::disk('local')->put('firmas/firma-prestador.png', 'esto no es un PNG ni de casualidad');
        AdminSetting::set(ContractSignatureService::CLAVE_RUTA, 'firmas/firma-prestador.png');
        AdminSetting::set(ContractSignatureService::CLAVE_ACTUALIZADA_EN, now()->toIso8601String());

        $this->actingAs($admin, 'sanctum')
            ->getJson(self::RUTA_FIRMA)
            ->assertStatus(200)
            ->assertJson(['cargada' => false]);
    }

    /**
     * 17. Una extensión fuera del mapa de MIMEs tampoco se reporta como cargada.
     *
     * El archivo está en disco y `existe()` da true, pero `mime()` da null y el contrato sale
     * sin firma igual. Mismo razonamiento que el test anterior.
     *
     * @return void
     */
    public function test_una_extension_fuera_del_mapa_de_mimes_no_se_reporta_como_cargada(): void
    {
        $admin = $this->crear_admin();

        Storage::disk('local')->put('firmas/firma-prestador.gif', 'GIF89a bytes cualesquiera');
        AdminSetting::set(ContractSignatureService::CLAVE_RUTA, 'firmas/firma-prestador.gif');
        AdminSetting::set(ContractSignatureService::CLAVE_ACTUALIZADA_EN, now()->toIso8601String());

        $this->actingAs($admin, 'sanctum')
            ->getJson(self::RUTA_FIRMA)
            ->assertStatus(200)
            ->assertJson(['cargada' => false]);
    }

    /**
     * 18. Si la escritura en disco falla, la firma vieja SOBREVIVE y la respuesta es 422.
     *
     * `putFileAs` no tira excepción: devuelve `false`. Si el servicio no mirara ese valor,
     * `AdminSetting::set` guardaría una cadena vacía y la API contestaría 200 "listo" sobre un
     * estado vacío, después de haber borrado la firma que Lucas tenía cargada.
     *
     * El disco se reemplaza por un mock parcial que delega todo en el real menos `putFileAs`.
     *
     * @return void
     */
    public function test_si_falla_la_escritura_la_firma_vieja_sobrevive_y_la_respuesta_es_422(): void
    {
        $admin = $this->crear_admin();

        $this->subir_firma($admin);

        $disco_real       = Storage::disk('local');
        $bytes_originales = $disco_real->get('firmas/firma-prestador.png');

        $disco_roto = \Mockery::mock($disco_real);
        $disco_roto->shouldReceive('putFileAs')->andReturn(false);
        Storage::set('local', $disco_roto);

        $respuesta = $this->actingAs($admin, 'sanctum')
            ->postJson(self::RUTA_FIRMA, ['firma' => $this->png(400, 200, 'reemplazo.png')]);

        $respuesta->assertStatus(422);

        // Que el 422 venga del disco y no de una regla de validación que se coló: si no se
        // chequea el mensaje, este test podría quedar verde por el motivo equivocado.
        $this->assertStringContainsString(
            'No se pudo escribir el archivo de la firma en el disco',
            (string) $respuesta->json('message'),
            'El 422 no vino del fallo de escritura sino de otra cosa.'
        );

        // Se consulta el disco real, no el mock, para que la aserción no dependa del doble.
        $this->assertTrue(
            $disco_real->exists('firmas/firma-prestador.png'),
            'Una escritura fallida se llevó puesta la firma que ya estaba cargada.'
        );

        $this->assertSame(
            $bytes_originales,
            $disco_real->get('firmas/firma-prestador.png'),
            'La firma anterior quedó pisada por una escritura que falló.'
        );

        $this->assertSame(
            'firmas/firma-prestador.png',
            AdminSetting::get(ContractSignatureService::CLAVE_RUTA),
            'La setting quedó apuntando a otra cosa después de una escritura fallida.'
        );
    }

    /**
     * 19. El contrato con la firma corrupta deja una línea de warning en el log.
     *
     * Sin esto la generación devuelve 200, el contrato sale sin firma y en los logs no queda
     * NADA: la condición de error que nunca llega a nadie. Lucas manda un contrato creyendo que
     * va firmado y no hay forma de enterarse después.
     *
     * @return void
     */
    public function test_el_contrato_con_la_firma_corrupta_deja_un_warning_en_el_log(): void
    {
        $admin = $this->crear_admin();
        $lead  = $this->crear_lead_con_contrato();

        Storage::disk('local')->put('firmas/firma-prestador.png', 'bytes que no son una imagen');
        AdminSetting::set(ContractSignatureService::CLAVE_RUTA, 'firmas/firma-prestador.png');
        AdminSetting::set(ContractSignatureService::CLAVE_ACTUALIZADA_EN, now()->toIso8601String());

        Log::spy();

        $pdf = $this->generar_contrato($admin, $lead);

        $this->assertFalse(
            $this->tiene_imagen($pdf),
            'El contrato embebió una firma que no se puede leer.'
        );

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje) {
            return is_string($mensaje) && strpos($mensaje, 'firma del PRESTADOR') !== false;
        })->atLeast()->once();
    }
}
