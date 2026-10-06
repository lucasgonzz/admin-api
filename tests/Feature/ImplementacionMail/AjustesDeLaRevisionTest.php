<?php

namespace Tests\Feature\ImplementacionMail;

use App\Mail\ImplementacionMail;
use App\Models\ImplementationMail;
use App\Services\ImplementacionMailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que encontró la revisión independiente del mail de hito y se corrigió antes del merge (misión `implementar-cliente`,
 * 5/10/2026). Cada test fija un hallazgo:
 *
 *  - MEDIO-1. El mail SALIÓ pero no se pudo anotar en `implementation_mails` (una carrera que el lock no cubrió, o la base que
 *    cae entre el SMTP y el INSERT): se responde `enviado`, nunca un 500 ni un error que invite a mandarlo dos veces. El índice
 *    único solo impide dos filas; el mail ya salió.
 *  - MEDIO-2. La migración es idempotente (se renumeró, y los slots donde ya corrió tienen la tabla).
 *  - BAJO-4. El texto del error del SMTP no lleva la casilla entera.
 *  - BAJO-5. Validaciones que faltaban: las fotos por revisar no superan el total, el resumen no tiene más fotos que artículos,
 *    la nota sale sin caracteres de control ni de dirección del texto, la URL de recursos sin usuario ni comillas, las tres
 *    opciones con nombres distintos.
 *  - BAJO-6. El nombre del negocio sale en una línea y hasta 80 caracteres.
 *  - D-08. `arca: false` saca del mail `listo` la línea de la facturación electrónica.
 *  - D-12 y D-27. Los mails de acceso y de fotos no prometen lo que todavía no existe ni afirman de dónde salieron las fotos.
 */
class AjustesDeLaRevisionTest extends BaseDelMailDeImplementacion
{
    /**
     * Un cliente con casilla y su implementación en el estado del hito.
     *
     * @param string $hito El hito, para elegir el estado de las etapas.
     *
     * @return array{0: \App\Models\Client, 1: \App\Models\Implementation}
     */
    private function cliente_e_implementacion(string $hito = 'instalado', array $atributos_del_cliente = []): array
    {
        $client = $this->crear_cliente(array_merge(['email' => 'lucas@gmail.com'], $atributos_del_cliente));
        $impl   = $this->crear_implementacion($client, $this->estados_del_hito($hito));

        return [$client, $impl];
    }

    /**
     * Hace que el próximo envío se caiga con ese mensaje.
     *
     * @param string $mensaje Lo que dice el SMTP.
     *
     * @return void
     */
    private function hacer_que_el_smtp_se_caiga(string $mensaje): void
    {
        Mail::shouldReceive('mailer')->once()->with('admin')->andReturnSelf();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException($mensaje));
    }

    /* ------------------------------------------------------------------------------------------
     | MEDIO-1: el mail salió y no se pudo anotar
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Si anotar el envío falla (la base cae entre el SMTP y el INSERT), la respuesta es `enviado` con un `aviso` de que no se
     * reenvíe: nunca `error`, que invitaría a mandar el mail otra vez al mismo dueño.
     *
     * @return void
     */
    public function test_un_mail_que_salio_y_no_se_pudo_anotar_se_responde_como_enviado_con_aviso(): void
    {
        Mail::fake();
        [$client, $impl] = $this->cliente_e_implementacion();

        ImplementationMail::saving(function () {
            throw new \RuntimeException('Connection lost: la base se cayó');
        });

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertNull($resultado['error']);
        $this->assertNotNull($resultado['enviado_at']);
        $this->assertArrayHasKey('aviso', $resultado);
        $this->assertStringContainsString('NO lo reenvíes', $resultado['aviso']);
        $this->assertStringContainsString('SALIÓ', $resultado['aviso']);

        Mail::assertSent(ImplementacionMail::class, 1);
        $this->assertSame(0, ImplementationMail::where('implementation_id', $impl->id)->count(), 'No se pudo anotar: no hay fila.');
    }

    /**
     * Si otra llamada ya escribió la fila de este hito mientras ésta mandaba (el lock no alcanzó), el INSERT choca con el índice
     * único: se relee la fila y se la deja `enviado`, sin aviso, y queda UNA sola fila.
     *
     * @return void
     */
    public function test_si_otra_llamada_escribio_la_fila_mientras_se_mandaba_queda_una_sola_enviada(): void
    {
        Mail::fake();
        [$client, $impl] = $this->cliente_e_implementacion();

        ImplementationMail::creating(function ($fila) {
            DB::table('implementation_mails')->insert([
                'implementation_id' => $fila->implementation_id,
                'hito'              => $fila->hito,
                'email'             => 'otra-llamada@ejemplo.test',
                'asunto'            => 'Tu sistema ya está instalado',
                'estado'            => 'enviado',
                'enviado_at'        => now(),
                'reenvios'          => 0,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        });

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertArrayNotHasKey('aviso', $resultado, 'La fila se pudo dejar escrita: no hay nada que avisar.');

        $filas = ImplementationMail::where('implementation_id', $impl->id)->where('hito', 'instalado')->get();

        $this->assertCount(1, $filas);
        $this->assertSame('enviado', $filas[0]->estado);
        $this->assertSame('lucas@gmail.com', $filas[0]->email, 'La fila quedó con la casilla a la que salió ESTA llamada.');
        $this->assertNull($filas[0]->error);
    }

    /* ------------------------------------------------------------------------------------------
     | MEDIO-2 y BAJO-4
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 La migración se puede correr con la tabla ya creada: no rompe el deploy ni un `migrate` en un slot donde ya corrió.
     *
     * @return void
     */
    public function test_la_migracion_se_puede_correr_dos_veces(): void
    {
        require_once base_path('database/migrations/2026_10_05_120000_create_implementation_mails_table.php');

        $this->assertTrue(Schema::hasTable('implementation_mails'));

        (new \CreateImplementationMailsTable())->up();

        $this->assertTrue(Schema::hasTable('implementation_mails'));
        $this->assertTrue(Schema::hasColumn('implementation_mails', 'hito'));
    }

    /**
     * El motivo de un error del SMTP suele repetir la dirección: no se guarda ni se devuelve entera (la entera ya está en la
     * columna `email`).
     *
     * @return void
     */
    public function test_el_error_del_smtp_no_lleva_la_casilla_entera(): void
    {
        [$client, $impl] = $this->cliente_e_implementacion();

        $this->hacer_que_el_smtp_se_caiga('550 5.1.1 <lucas@gmail.com>: Recipient address rejected: User unknown');

        $resultado = ImplementacionMailService::enviar($impl, 'instalado', [], null, false);

        $this->assertSame('error', $resultado['estado']);
        $this->assertStringNotContainsString('lucas@gmail.com', (string) $resultado['error']);
        $this->assertStringContainsString('l***@gmail.com', (string) $resultado['error']);

        $fila = ImplementationMail::where('implementation_id', $impl->id)->first();

        $this->assertStringNotContainsString('lucas@gmail.com', (string) $fila->error);
        $this->assertSame('lucas@gmail.com', $fila->email, 'La casilla entera sigue en su columna.');
    }

    /* ------------------------------------------------------------------------------------------
     | BAJO-5: validaciones
     |----------------------------------------------------------------------------------------- */

    /**
     * Las fotos por revisar no pueden superar el total.
     *
     * @return void
     */
    public function test_las_fotos_por_revisar_no_pueden_superar_el_total(): void
    {
        $errores = ImplementacionMailService::validar_datos('imagenes', ['con_foto' => 1, 'total' => 10, 'a_revisar' => 999999]);

        $this->assertArrayHasKey('a_revisar', $errores);
        $this->assertStringContainsString('más fotos por revisar que artículos', $errores['a_revisar']);

        $this->assertSame([], ImplementacionMailService::validar_datos('imagenes', ['con_foto' => 5, 'total' => 10, 'a_revisar' => 10]));
    }

    /**
     * El resumen del mail `listo` no puede tener más fotos que artículos.
     *
     * @return void
     */
    public function test_el_resumen_del_listo_no_puede_tener_mas_fotos_que_articulos(): void
    {
        $errores = ImplementacionMailService::validar_datos('listo', ['resumen' => ['articulos' => 1, 'con_foto' => 999]]);

        $this->assertArrayHasKey('resumen.con_foto', $errores);

        $this->assertSame([], ImplementacionMailService::validar_datos('listo', ['resumen' => ['articulos' => 10, 'con_foto' => 10]]));
        $this->assertSame([], ImplementacionMailService::validar_datos('listo', ['resumen' => ['con_foto' => 10]]), 'Sin artículos en el resumen no hay contra qué comparar.');
    }

    /**
     * `arca` es un booleano opcional del hito `listo` y de ningún otro.
     *
     * @return void
     */
    public function test_arca_tiene_que_ser_un_booleano_y_solo_vale_en_listo(): void
    {
        $this->assertSame([], ImplementacionMailService::validar_datos('listo', ['arca' => false]));
        $this->assertSame([], ImplementacionMailService::validar_datos('listo', ['arca' => true]));

        foreach (['no', 0, 'false', ['x']] as $valor) {
            $this->assertArrayHasKey('arca', ImplementacionMailService::validar_datos('listo', ['arca' => $valor]), 'arca = ' . json_encode($valor));
        }

        $this->assertArrayHasKey('arca', ImplementacionMailService::validar_datos('acceso', ['articulos' => 1, 'arca' => false]), 'Es un dato del hito listo, de ningún otro.');
    }

    /**
     * La nota pierde los caracteres de control y los que dan vuelta el texto (RTL override): no llegan al mail.
     *
     * @return void
     */
    public function test_la_nota_pierde_los_caracteres_de_control_y_de_direccion(): void
    {
        [$client, $impl] = $this->cliente_e_implementacion();

        $previa = ImplementacionMailService::previa($impl, 'instalado', ['nota' => "Hola\x00 mundo\u{202E}txet\u{200B}!"], null);

        $this->assertStringNotContainsString("\x00", $previa['html']);
        $this->assertStringNotContainsString("\u{202E}", $previa['html']);
        $this->assertStringNotContainsString("\u{200B}", $previa['html']);
        $this->assertStringContainsString('Hola mundotxet!', $previa['html']);
    }

    /**
     * La URL de los recursos no puede llevar usuario:clave@ ni comillas, ángulos o espacios (va a un `href`).
     *
     * @return void
     */
    public function test_la_url_de_los_recursos_no_lleva_usuario_ni_comillas(): void
    {
        foreach (['https://usuario:clave@ejemplo.test/recursos', 'https://usuario@ejemplo.test/recursos', 'https://ejemplo.test/a"b', "https://ejemplo.test/a'b", 'https://ejemplo.test/<x>', 'https://ejemplo.test/a b'] as $url) {
            $errores = ImplementacionMailService::validar_datos('listo', ['recursos_url' => $url]);

            $this->assertArrayHasKey('recursos_url', $errores, $url);
        }

        $this->assertSame([], ImplementacionMailService::validar_datos('listo', ['recursos_url' => 'https://drive.google.com/drive/folders/abc123?usp=drive_link']));
    }

    /**
     * Tres opciones con el mismo nombre son una opción repetida, no tres formas de ordenar el catálogo.
     *
     * @return void
     */
    public function test_las_tres_opciones_de_categorias_tienen_nombres_distintos(): void
    {
        $opciones = $this->opciones_de_ejemplo();

        $opciones[1]['nombre'] = '  POR TIPO de producto ';

        $errores = ImplementacionMailService::validar_datos('categorias', ['opciones' => $opciones]);

        $this->assertArrayHasKey('opciones', $errores);
        $this->assertStringContainsString('nombres distintos', $errores['opciones']);

        $this->assertSame([], ImplementacionMailService::validar_datos('categorias', ['opciones' => $this->opciones_de_ejemplo()]));
    }

    /* ------------------------------------------------------------------------------------------
     | BAJO-6, D-08, D-12 y D-27: lo que dice el mail
     |----------------------------------------------------------------------------------------- */

    /**
     * El nombre del negocio es texto libre del cliente: una sola línea y hasta 80 caracteres.
     *
     * @return void
     */
    public function test_el_nombre_del_negocio_sale_en_una_linea_y_con_tope(): void
    {
        [$client, $impl] = $this->cliente_e_implementacion('bienvenida', ['setup_data' => ['company_name' => str_repeat('A', 70) . "\n\n" . str_repeat('B', 200)]]);

        $previa = ImplementacionMailService::previa($impl, 'bienvenida', [], null);

        $this->assertStringNotContainsString(str_repeat('B', 20), $previa['html'], 'El nombre se cortó a 80 caracteres: no entra más que un puñado de B.');
        $this->assertStringContainsString(str_repeat('A', 70) . ' ' . str_repeat('B', 9), $previa['html'], 'Una sola línea: los saltos se vuelven un espacio.');
    }

    /**
     * 🔴 Con `arca: false` el mail `listo` no dice que se conectó la facturación electrónica (ni en la versión HTML ni en la de
     * texto); sin el dato, o con `arca: true`, la línea va.
     *
     * @return void
     */
    public function test_con_arca_false_el_mail_listo_no_dice_que_se_conecto_arca(): void
    {
        Mail::fake();
        [$client, $impl] = $this->cliente_e_implementacion('listo');

        $sin_arca = array_merge($this->datos_de_ejemplo('listo'), ['arca' => false]);
        $con_arca = $this->datos_de_ejemplo('listo');

        $previa_sin = ImplementacionMailService::previa($impl, 'listo', $sin_arca, null);
        $previa_con = ImplementacionMailService::previa($impl, 'listo', $con_arca, null);

        $this->assertStringNotContainsString('ARCA', $previa_sin['html']);
        $this->assertStringContainsString('Conectamos la facturación electrónica con ARCA.', $previa_con['html']);
        $this->assertStringContainsString('videollamada', $previa_sin['html'], 'Lo demás de "lo que sigue" no cambia.');
        $this->assertStringContainsString('Soporte por WhatsApp', $previa_sin['html']);

        ImplementacionMailService::enviar($impl, 'listo', $sin_arca, null, false);

        Mail::assertSent(ImplementacionMail::class, function ($mail) {
            $mail->build();

            return strpos((string) $mail->textView, 'ARCA') === false && strpos((string) $mail->textView, 'videollamada') !== false;
        });
    }

    /**
     * Los mails de acceso y de fotos no prometen "el orden de tu catálogo" (la pantalla de categorías puede no estar en la versión
     * del cliente) ni afirman que las fotos salieron de catálogos de proveedores o de códigos de barras (puede no haber ninguno).
     *
     * @return void
     */
    public function test_los_mails_de_acceso_y_de_fotos_no_prometen_ni_afirman_de_mas(): void
    {
        [$client, $impl_acceso] = $this->cliente_e_implementacion('acceso');
        $acceso = ImplementacionMailService::previa($impl_acceso, 'acceso', $this->datos_de_ejemplo('acceso'), null);

        $this->assertStringNotContainsString('orden de tu catálogo', $acceso['html']);
        $this->assertStringContainsString('seguimos con las fotos de tus productos', $acceso['html']);

        [$client2, $impl_fotos] = $this->cliente_e_implementacion('imagenes');
        $fotos = ImplementacionMailService::previa($impl_fotos, 'imagenes', $this->datos_de_ejemplo('imagenes'), null);

        $this->assertStringNotContainsString('catálogos de tus proveedores', $fotos['html']);
        $this->assertStringNotContainsString('código de barras', $fotos['html']);
        $this->assertStringContainsString('Catálogo Inteligente', $fotos['html']);
    }
}
