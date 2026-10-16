<?php

namespace Tests\Feature\Recursos;

use App\Models\RecursosBusqueda;
use App\Services\RecursosSesionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lo que se busca en el buscador del centro de recursos (misión busquedas-centro-recursos, 16/10/2026):
 * el registro desde la página (`POST recursos/busquedas`, contrato C-BUSQ-1), el panel
 * (`GET admin/recursos/busquedas`, C-BUSQ-2) y la purga por retención.
 *
 * Lo que más importa, en orden:
 *  1. 🔴 Privacidad: lo que parece un dato personal (mail, link, teléfono, CUIT, tarjeta) NO se guarda,
 *     aunque venga con espacios o guiones, y se mira antes de recortar la consulta.
 *  2. Un registro por búsqueda: el segundo pedido con el mismo `evento` no pisa nada, solo completa el
 *     primer clic.
 *  3. Soporte (sesión de solo lectura) no cuenta; el invitado sí.
 *  4. El panel agrupa por consulta y respeta el período (inclusivo en los dos extremos).
 */
class BusquedasDelCentroTest extends BaseDelCentroDeRecursos
{
    const RUTA = '/api/recursos/busquedas';

    const PANEL = '/api/admin/recursos/busquedas';

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Un body válido; `$extra` pisa lo que haga falta.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function cuerpo_de_busqueda(array $extra = []): array
    {
        return array_merge([
            'evento'     => 'ev-' . bin2hex(random_bytes(8)),
            'consulta'   => 'como cargar un articulo',
            'resultados' => 3,
            'primero'    => 'T1.1',
            'completo'   => true,
        ], $extra);
    }

    /**
     * Una fila directa en la tabla (para armar el panel y la purga).
     *
     * @param string               $consulta
     * @param array<string, mixed> $atributos
     *
     * @return RecursosBusqueda
     */
    private function fila(string $consulta, array $atributos = []): RecursosBusqueda
    {
        return RecursosBusqueda::create(array_merge([
            'evento'     => 'fila-' . bin2hex(random_bytes(8)),
            'consulta'   => $consulta,
            'resultados' => 0,
            'completo'   => false,
        ], $atributos));
    }

    /* ------------------------------------------------------------------------------------------
     | Guardado y normalización
     |----------------------------------------------------------------------------------------- */

    public function test_guarda_la_busqueda_de_un_invitado_con_todos_sus_campos(): void
    {
        $cuerpo = $this->cuerpo_de_busqueda(['evento' => 'evento-0001']);

        $this->postJson(self::RUTA, $cuerpo)->assertStatus(200)->assertExactJson(['registrado' => true]);

        $fila = RecursosBusqueda::where('evento', 'evento-0001')->first();

        $this->assertNotNull($fila);
        $this->assertNull($fila->recursos_usuario_id);
        $this->assertSame('como cargar un articulo', $fila->consulta);
        $this->assertSame(3, $fila->resultados);
        $this->assertSame('T1.1', $fila->primero_codigo);
        $this->assertTrue($fila->completo);
        $this->assertNull($fila->abierto_codigo);
        $this->assertNull($fila->abierto_posicion);
    }

    public function test_normaliza_minusculas_acentos_emojis_y_espacios(): void
    {
        $casos = [
            '  Cómo   CARGAR un Artículo  '   => 'como cargar un articulo',
            "ñandú\tcon\nsalto"               => 'nandu con salto',
            'fiado 😀'                          => 'fiado',
            'Factura  2024'                   => 'factura 2024',
            'T5.15'                           => 't5.15',
        ];

        foreach ($casos as $entrada => $esperada) {
            $evento = 'norm-' . bin2hex(random_bytes(6));

            $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => $evento, 'consulta' => (string) $entrada]))
                ->assertStatus(200)
                ->assertExactJson(['registrado' => true]);

            $this->assertSame($esperada, RecursosBusqueda::where('evento', $evento)->value('consulta'), 'entrada: ' . json_encode($entrada));
        }
    }

    public function test_un_codigo_con_pocos_digitos_no_se_descarta(): void
    {
        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'codigo-001', 'consulta' => 't5.15']))
            ->assertExactJson(['registrado' => true]);

        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'codigo-002', 'consulta' => 'iva 21 y 10,5']))
            ->assertExactJson(['registrado' => true]);

        $this->assertSame(2, RecursosBusqueda::count());
    }

    public function test_recorta_la_consulta_a_120_sin_dejar_espacio_al_final(): void
    {
        // 'abcd ' x 40 = 200 caracteres: el corte en 120 cae justo después de un espacio.
        $larga = str_repeat('abcd ', 40);

        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'larga-0001', 'consulta' => $larga]))
            ->assertExactJson(['registrado' => true]);

        $guardada = (string) RecursosBusqueda::where('evento', 'larga-0001')->value('consulta');

        $this->assertSame(119, strlen($guardada));
        $this->assertSame(substr(trim($larga), 0, 119), $guardada);
        $this->assertSame($guardada, rtrim($guardada));

        // Con la de 500 caracteres (el máximo del contrato) también.
        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'larga-0002', 'consulta' => str_repeat('x', 500)]))
            ->assertExactJson(['registrado' => true]);

        $this->assertSame(120, strlen((string) RecursosBusqueda::where('evento', 'larga-0002')->value('consulta')));
    }

    /* ------------------------------------------------------------------------------------------
     | Descarte de datos personales
     |----------------------------------------------------------------------------------------- */

    public function test_descarta_lo_que_parece_un_dato_personal_y_no_guarda_nada(): void
    {
        $descartables = [
            'mail'                      => 'mi mail es juan@ferreteria.com',
            'arroba suelta'             => '@juan',
            'cinco digitos corridos'    => 'cuit 12345',
            'tarjeta corrida'           => '4509953566233704',
            'siete digitos con espacios' => 'llamame al 11 5555 123',
            'cuit con guiones'          => '20-12345678-9',
            'telefono con puntos'       => '1155.551.234',
            'link http'                 => 'https://youtube.com/watch',
            'link sin esquema'          => 'www.misitio.com.ar',
            'http a secas'              => 'como http',
            'solo emojis'               => '😀😀😀',
            'muy corta'                 => 'a',
            'solo signos'               => '?? ... !!',
            'espacios'                  => '    ',
            // El número está pasados los 120 caracteres: se mira ANTES de recortar.
            'numero largo despues del recorte' => str_repeat('a', 130) . ' 12345678',
        ];

        foreach ($descartables as $nombre => $consulta) {
            $respuesta = $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['consulta' => $consulta]));

            // Una consulta vacía de verdad es 422 (el campo es obligatorio); el resto es 200 descartada.
            if (trim($consulta) === '') {
                $respuesta->assertStatus(422);
                continue;
            }

            $respuesta->assertStatus(200);
            $this->assertSame(['registrado' => false, 'motivo' => 'descartada'], $respuesta->json(), $nombre);
        }

        $this->assertSame(0, RecursosBusqueda::count());
    }

    public function test_un_clic_sobre_una_busqueda_descartada_tampoco_crea_la_fila(): void
    {
        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'privada-01', 'consulta' => 'juan@x.com', 'abrio' => 'T1.1', 'posicion' => 1]))
            ->assertExactJson(['registrado' => false, 'motivo' => 'descartada']);

        $this->assertSame(0, RecursosBusqueda::count());
    }

    /* ------------------------------------------------------------------------------------------
     | Upsert por evento: el primer clic gana
     |----------------------------------------------------------------------------------------- */

    public function test_el_clic_completa_la_fila_y_el_primero_gana(): void
    {
        $base = $this->cuerpo_de_busqueda(['evento' => 'upsert-001', 'consulta' => 'cobrar con qr', 'resultados' => 4]);

        $this->postJson(self::RUTA, $base)->assertExactJson(['registrado' => true]);

        // El clic llega con el mismo evento. Aunque traiga otra consulta u otros resultados, no se pisa nada.
        $this->postJson(self::RUTA, array_merge($base, ['consulta' => 'otra cosa', 'resultados' => 0, 'primero' => 'T9.9', 'abrio' => 'T1.3', 'posicion' => 2]))
            ->assertStatus(200)
            ->assertExactJson(['registrado' => true]);

        $fila = RecursosBusqueda::where('evento', 'upsert-001')->first();

        $this->assertSame(1, RecursosBusqueda::count());
        $this->assertSame('cobrar con qr', $fila->consulta);
        $this->assertSame(4, $fila->resultados);
        $this->assertSame('T1.1', $fila->primero_codigo);
        $this->assertSame('T1.3', $fila->abierto_codigo);
        $this->assertSame(2, $fila->abierto_posicion);

        // Un segundo clic (otro resultado) no cambia el primero.
        $this->postJson(self::RUTA, array_merge($base, ['abrio' => 'T1.4', 'posicion' => 3]))->assertExactJson(['registrado' => true]);

        $fila->refresh();
        $this->assertSame('T1.3', $fila->abierto_codigo);
        $this->assertSame(2, $fila->abierto_posicion);

        // Reenviar la búsqueda sin clic tampoco borra el clic.
        $this->postJson(self::RUTA, $base)->assertExactJson(['registrado' => true]);

        $this->assertSame('T1.3', $fila->fresh()->abierto_codigo);
        $this->assertSame(1, RecursosBusqueda::count());
    }

    public function test_un_clic_sin_fila_previa_crea_la_fila_con_el_clic(): void
    {
        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'directo-01', 'abrio' => 'T1.2', 'posicion' => 5]))
            ->assertExactJson(['registrado' => true]);

        $fila = RecursosBusqueda::where('evento', 'directo-01')->first();

        $this->assertSame('T1.2', $fila->abierto_codigo);
        $this->assertSame(5, $fila->abierto_posicion);
    }

    public function test_la_posicion_sin_codigo_abierto_no_se_guarda(): void
    {
        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'sinabrir-1', 'posicion' => 4]))
            ->assertExactJson(['registrado' => true]);

        $fila = RecursosBusqueda::where('evento', 'sinabrir-1')->first();

        $this->assertNull($fila->abierto_codigo);
        $this->assertNull($fila->abierto_posicion);
    }

    /**
     * Dos pedidos con el mismo evento a la vez: el segundo chequea que no existe, pero el primero lo
     * inserta justo antes de su INSERT. Se simula metiendo la fila competidora en el evento `creating`.
     * El único frena el INSERT, el pedido lo toma como update y el clic se completa; no hay 500.
     */
    public function test_la_carrera_de_dos_pedidos_con_el_mismo_evento_no_rompe_y_completa_el_clic(): void
    {
        $insertada = false;

        RecursosBusqueda::creating(function () use (&$insertada) {
            if (! $insertada) {
                $insertada = true;

                DB::table('recursos_busquedas')->insert([
                    'evento'     => 'carrera-01',
                    'consulta'   => 'la que llego primero',
                    'resultados' => 2,
                    'completo'   => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'carrera-01', 'consulta' => 'la que llego segunda', 'abrio' => 'T1.7', 'posicion' => 1]))
            ->assertStatus(200)
            ->assertExactJson(['registrado' => true]);

        $this->assertTrue($insertada);
        $this->assertSame(1, RecursosBusqueda::count());

        $fila = RecursosBusqueda::where('evento', 'carrera-01')->first();

        $this->assertSame('la que llego primero', $fila->consulta);
        $this->assertSame('T1.7', $fila->abierto_codigo);
        $this->assertSame(1, $fila->abierto_posicion);
    }

    /* ------------------------------------------------------------------------------------------
     | Invitado, usuario y soporte
     |----------------------------------------------------------------------------------------- */

    public function test_con_sesion_guarda_el_usuario_y_sin_sesion_queda_null(): void
    {
        $client = $this->crear_cliente('api-a.test');
        list($usuario, $token) = $this->usuario_con_sesion($client);

        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'con-sesion-1']), $this->con_sesion($token))
            ->assertExactJson(['registrado' => true]);

        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'invitado-01']))
            ->assertExactJson(['registrado' => true]);

        // Un token que no sirve no corta el pedido (la sesión es opcional): entra como invitado.
        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'token-malo1']), $this->con_sesion('inventado'))
            ->assertExactJson(['registrado' => true]);

        $this->assertSame($usuario->id, RecursosBusqueda::where('evento', 'con-sesion-1')->value('recursos_usuario_id'));
        $this->assertNull(RecursosBusqueda::where('evento', 'invitado-01')->value('recursos_usuario_id'));
        $this->assertNull(RecursosBusqueda::where('evento', 'token-malo1')->value('recursos_usuario_id'));
    }

    public function test_la_sesion_de_soporte_no_registra_nada(): void
    {
        $client = $this->crear_cliente('api-a.test');
        list($usuario) = $this->usuario_con_sesion($client);

        $token_soporte = (new RecursosSesionService())->crear_sesion($usuario, true);

        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(), $this->con_sesion($token_soporte))
            ->assertStatus(200)
            ->assertExactJson(['registrado' => false, 'motivo' => 'soporte']);

        // Tampoco el clic.
        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['abrio' => 'T1.1', 'posicion' => 1]), $this->con_sesion($token_soporte))
            ->assertExactJson(['registrado' => false, 'motivo' => 'soporte']);

        $this->assertSame(0, RecursosBusqueda::count());
    }

    /* ------------------------------------------------------------------------------------------
     | Validación
     |----------------------------------------------------------------------------------------- */

    public function test_los_datos_invalidos_dan_422_con_detalle_y_no_guardan_nada(): void
    {
        $invalidos = [
            'sin evento'              => ['evento' => null],
            'evento corto'            => ['evento' => 'corto'],
            'evento con espacio'      => ['evento' => 'tiene espacio 123'],
            'evento muy largo'        => ['evento' => str_repeat('a', 41)],
            'sin consulta'            => ['consulta' => null],
            'consulta vacia'          => ['consulta' => ''],
            'consulta de mas'         => ['consulta' => str_repeat('a', 501)],
            'consulta que no es texto' => ['consulta' => ['a', 'b']],
            'resultados negativos'    => ['resultados' => -1],
            'resultados de mas'       => ['resultados' => 100001],
            'resultados texto'        => ['resultados' => 'muchos'],
            'resultados decimal'      => ['resultados' => 2.5],
            'resultados booleano'     => ['resultados' => true],
            'sin resultados'          => ['resultados' => null],
            'completo cualquiera'     => ['completo' => 'si'],
            'sin completo'            => ['completo' => null],
            'primero muy largo'       => ['primero' => str_repeat('T', 21)],
            'primero que no es texto' => ['primero' => 5],
            'abrio muy largo'         => ['abrio' => str_repeat('T', 21)],
            'posicion cero'           => ['posicion' => 0],
            'posicion texto'          => ['posicion' => 'primera'],
        ];

        foreach ($invalidos as $nombre => $extra) {
            $respuesta = $this->postJson(self::RUTA, $this->cuerpo_de_busqueda($extra));

            $respuesta->assertStatus(422);
            $this->assertSame('datos_invalidos', $respuesta->json('error'), $nombre);
            $this->assertIsString($respuesta->json('detalle'), $nombre);
            $this->assertNotSame('', $respuesta->json('detalle'), $nombre);
        }

        $this->assertSame(0, RecursosBusqueda::count());
    }

    public function test_completo_acepta_true_false_1_y_0_y_los_opcionales_pueden_faltar(): void
    {
        foreach ([[true, true], [false, false], [1, true], [0, false], ['1', true], ['0', false]] as $i => $par) {
            $this->postJson(self::RUTA, $this->cuerpo_de_busqueda(['evento' => 'completo-0' . $i, 'completo' => $par[0]]))
                ->assertExactJson(['registrado' => true]);

            $this->assertSame($par[1], RecursosBusqueda::where('evento', 'completo-0' . $i)->first()->completo, json_encode($par[0]));
        }

        // `primero`, `abrio` y `posicion` son opcionales: sin ellos o en null.
        $minimo = ['evento' => 'minimo-001', 'consulta' => 'iva', 'resultados' => 0, 'completo' => false];

        $this->postJson(self::RUTA, $minimo)->assertExactJson(['registrado' => true]);
        $this->postJson(self::RUTA, array_merge($minimo, ['evento' => 'minimo-002', 'primero' => null, 'abrio' => null, 'posicion' => null]))
            ->assertExactJson(['registrado' => true]);

        $fila = RecursosBusqueda::where('evento', 'minimo-002')->first();
        $this->assertNull($fila->primero_codigo);
        $this->assertNull($fila->abierto_codigo);
    }

    /* ------------------------------------------------------------------------------------------
     | Limitador
     |----------------------------------------------------------------------------------------- */

    public function test_el_limitador_corta_en_el_pedido_31_por_ip_y_es_una_cubeta_propia(): void
    {
        foreach (range(1, 30) as $i) {
            $this->postJson(self::RUTA, $this->cuerpo_de_busqueda())->assertStatus(200);
        }

        $this->postJson(self::RUTA, $this->cuerpo_de_busqueda())->assertStatus(429);

        // Las otras cubetas del centro siguen andando.
        $this->getJson('/api/recursos/catalogo')->assertStatus(200);
        $this->assertSame(30, RecursosBusqueda::count());
    }

    /* ------------------------------------------------------------------------------------------
     | Retención
     |----------------------------------------------------------------------------------------- */

    public function test_el_comando_borra_lo_de_mas_de_90_dias_y_deja_lo_nuevo(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');

        $vieja  = $this->fila('muy vieja', ['created_at' => now()->subDays(91)]);
        $limite = $this->fila('justo en el limite', ['created_at' => now()->subDays(89)->subHours(23)]);
        $nueva  = $this->fila('de ayer', ['created_at' => now()->subDay()]);

        $this->artisan('recursos:purgar-busquedas')->assertExitCode(0);

        $this->assertNull(RecursosBusqueda::find($vieja->id));
        $this->assertNotNull(RecursosBusqueda::find($limite->id));
        $this->assertNotNull(RecursosBusqueda::find($nueva->id));
    }

    public function test_el_comando_acepta_dias_y_rechaza_valores_que_no_sirven(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');

        $hace_20 = $this->fila('hace veinte', ['created_at' => now()->subDays(20)]);
        $hace_5  = $this->fila('hace cinco', ['created_at' => now()->subDays(5)]);

        foreach (['0', '-3', 'abc', '2.5'] as $malo) {
            $this->artisan('recursos:purgar-busquedas', ['--dias' => $malo])->assertExitCode(1);
        }

        $this->assertSame(2, RecursosBusqueda::count());

        $this->artisan('recursos:purgar-busquedas', ['--dias' => '10'])->assertExitCode(0);

        $this->assertNull(RecursosBusqueda::find($hace_20->id));
        $this->assertNotNull(RecursosBusqueda::find($hace_5->id));
    }

    public function test_la_purga_usa_la_retencion_de_la_config_y_borra_en_tandas(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        config(['recursos.busquedas.retencion_dias' => 30]);

        $viejas = [];

        // Más que una tanda (1000) para cubrir el bucle.
        foreach (range(1, 1005) as $i) {
            $viejas[] = [
                'evento'     => 'vieja-' . $i,
                'consulta'   => 'consulta vieja',
                'resultados' => 0,
                'completo'   => 0,
                'created_at' => now()->subDays(45),
                'updated_at' => now()->subDays(45),
            ];
        }

        foreach (array_chunk($viejas, 200) as $tanda) {
            DB::table('recursos_busquedas')->insert($tanda);
        }

        $reciente = $this->fila('reciente', ['created_at' => now()->subDays(10)]);

        $this->artisan('recursos:purgar-busquedas')->assertExitCode(0);

        $this->assertSame(1, RecursosBusqueda::count());
        $this->assertNotNull(RecursosBusqueda::find($reciente->id));
    }

    public function test_el_scheduler_agenda_la_purga_a_las_0330(): void
    {
        $eventos = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(function ($evento) {
            return strpos((string) $evento->command, 'recursos:purgar-busquedas') !== false;
        });

        $this->assertCount(1, $eventos);
        $this->assertSame('30 3 * * *', $eventos->first()->expression);
    }

    /* ------------------------------------------------------------------------------------------
     | Panel
     |----------------------------------------------------------------------------------------- */

    public function test_el_panel_pide_admin_y_un_token_del_centro_no_entra(): void
    {
        $client = $this->crear_cliente('api-a.test');
        list(, $token_del_centro) = $this->usuario_con_sesion($client);

        $this->getJson(self::PANEL)->assertStatus(401);
        $this->getJson(self::PANEL, $this->con_sesion($token_del_centro))->assertStatus(401);
    }

    public function test_el_panel_sin_datos_contesta_con_listas_vacias_y_totales_en_cero(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->admin_logueado();

        $this->getJson(self::PANEL)
            ->assertStatus(200)
            ->assertExactJson([
                'desde'         => '2026-09-17',
                'hasta'         => '2026-10-16',
                'totales'       => ['busquedas' => 0, 'sin_resultados' => 0, 'incompletas' => 0, 'sin_clic' => 0, 'con_clic' => 0],
                'sin_encontrar' => [],
                'sin_clic'      => [],
            ]);
    }

    /**
     * El conjunto de datos del panel (ver el comentario de cada fila).
     *
     * @return void
     */
    private function sembrar_el_panel(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');

        $this->crear_video('T4.8');
        $this->crear_video('T2.1');
        $this->crear_video('T1.1');

        // fiado: dos veces sin resultados y una vez con 3 resultados pero sin que alguno tenga todas las palabras.
        $this->fila('fiado', ['resultados' => 0, 'completo' => false, 'created_at' => now()->subDays(3)]);
        $this->fila('fiado', ['resultados' => 0, 'completo' => false, 'created_at' => now()->subDays(2)]);
        $this->fila('fiado', ['resultados' => 3, 'completo' => false, 'primero_codigo' => 'T4.8', 'created_at' => now()->subHour()]);
        // plata: encontró siempre algo completo, y nadie abrió nada.
        $this->fila('plata', ['resultados' => 12, 'completo' => true, 'primero_codigo' => 'T2.1', 'created_at' => now()->subHours(5)]);
        $this->fila('plata', ['resultados' => 12, 'completo' => true, 'primero_codigo' => 'T2.1', 'created_at' => now()->subHours(2)]);
        // factura: encontró y alguien abrió: no es ningún problema.
        $this->fila('factura', ['resultados' => 5, 'completo' => true, 'primero_codigo' => 'T1.1', 'abierto_codigo' => 'T1.1', 'abierto_posicion' => 1, 'created_at' => now()->subDay()]);
        $this->fila('factura', ['resultados' => 5, 'completo' => true, 'primero_codigo' => 'T1.1', 'created_at' => now()->subDay()]);
        // dolar: nunca devolvió nada.
        $this->fila('dolar', ['resultados' => 0, 'completo' => false, 'created_at' => now()->subDays(4)]);
    }

    public function test_el_panel_agrupa_por_consulta_con_totales_y_las_dos_listas(): void
    {
        $this->sembrar_el_panel();
        $this->admin_logueado();

        $respuesta = $this->getJson(self::PANEL)->assertStatus(200);

        $this->assertSame('2026-09-17', $respuesta->json('desde'));
        $this->assertSame('2026-10-16', $respuesta->json('hasta'));

        // 8 filas; sin resultados: fiado x2 y dolar; incompletas (con resultados y sin "completo"): la tercera
        // de fiado; sin clic (con resultados): fiado(3.ª), plata x2 y la segunda factura; con clic: la primera factura.
        $this->assertSame(
            ['busquedas' => 8, 'sin_resultados' => 3, 'incompletas' => 1, 'sin_clic' => 4, 'con_clic' => 1],
            $respuesta->json('totales')
        );

        // «Sin encontrar»: solo las filas con 0 resultados o sin "completo"; más veces primero.
        $this->assertSame([
            [
                'consulta'       => 'fiado',
                'veces'          => 3,
                'resultados_max' => 3,
                'sin_resultados' => 2,
                // Lo que devolvió la fila más reciente, con el título del video.
                'primero_codigo' => 'T4.8',
                'primero_titulo' => 'Video T4.8',
                'ultima'         => '2026-10-16 11:00:00',
            ],
            [
                'consulta'       => 'dolar',
                'veces'          => 1,
                'resultados_max' => 0,
                'sin_resultados' => 1,
                'primero_codigo' => null,
                'primero_titulo' => null,
                'ultima'         => '2026-10-12 12:00:00',
            ],
        ], $respuesta->json('sin_encontrar'));

        // «Nadie abrió»: consultas con resultados y ninguna fila con clic. «factura» (tuvo un clic) y «dolar» (0 resultados) no.
        $this->assertSame([
            [
                'consulta'       => 'fiado',
                'veces'          => 3,
                'resultados_max' => 3,
                'sin_resultados' => 2,
                'primero_codigo' => 'T4.8',
                'primero_titulo' => 'Video T4.8',
                'ultima'         => '2026-10-16 11:00:00',
            ],
            [
                'consulta'       => 'plata',
                'veces'          => 2,
                'resultados_max' => 12,
                'sin_resultados' => 0,
                'primero_codigo' => 'T2.1',
                'primero_titulo' => 'Video T2.1',
                'ultima'         => '2026-10-16 10:00:00',
            ],
        ], $respuesta->json('sin_clic'));
    }

    public function test_el_panel_ordena_por_veces_luego_ultima_y_luego_consulta(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->admin_logueado();

        // Todas con la misma cantidad de veces (1): desempata la más reciente, y a igual fecha, el nombre.
        $this->fila('zeta', ['created_at' => now()->subDays(2)]);
        $this->fila('beta', ['created_at' => now()->subDay()]);
        $this->fila('alfa', ['created_at' => now()->subDay()]);
        // Una con más veces va primero aunque sea más vieja.
        $this->fila('omega', ['created_at' => now()->subDays(9)]);
        $this->fila('omega', ['created_at' => now()->subDays(8)]);

        $orden = array_column($this->getJson(self::PANEL)->assertStatus(200)->json('sin_encontrar'), 'consulta');

        $this->assertSame(['omega', 'alfa', 'beta', 'zeta'], $orden);
    }

    public function test_el_panel_filtra_por_fechas_con_los_dos_extremos_inclusivos(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->admin_logueado();

        $this->fila('antes del periodo', ['created_at' => '2026-10-10 23:59:59']);
        $this->fila('primer segundo', ['created_at' => '2026-10-11 00:00:00']);
        $this->fila('ultimo segundo', ['created_at' => '2026-10-16 23:59:59']);
        $this->fila('despues del periodo', ['created_at' => '2026-10-17 00:00:00']);

        $respuesta = $this->getJson(self::PANEL . '?desde=2026-10-11&hasta=2026-10-16')->assertStatus(200);

        $this->assertSame('2026-10-11', $respuesta->json('desde'));
        $this->assertSame('2026-10-16', $respuesta->json('hasta'));
        $this->assertSame(2, $respuesta->json('totales.busquedas'));
        $this->assertEqualsCanonicalizing(['primer segundo', 'ultimo segundo'], array_column($respuesta->json('sin_encontrar'), 'consulta'));

        // Un solo día.
        $uno = $this->getJson(self::PANEL . '?desde=2026-10-17&hasta=2026-10-17')->assertStatus(200);
        $this->assertSame(['despues del periodo'], array_column($uno->json('sin_encontrar'), 'consulta'));
    }

    public function test_el_periodo_por_defecto_son_los_ultimos_30_dias_con_hoy_incluido(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->admin_logueado();

        $this->fila('fuera', ['created_at' => '2026-09-16 23:59:59']);
        $this->fila('dentro el primer dia', ['created_at' => '2026-09-17 00:00:00']);
        $this->fila('dentro hoy', ['created_at' => '2026-10-16 08:00:00']);

        $respuesta = $this->getJson(self::PANEL)->assertStatus(200);

        $this->assertEqualsCanonicalizing(['dentro el primer dia', 'dentro hoy'], array_column($respuesta->json('sin_encontrar'), 'consulta'));

        // Solo `hasta`: desde sigue siendo hoy menos 29 días, no el de `hasta`.
        $solo_hasta = $this->getJson(self::PANEL . '?hasta=2026-09-30')->assertStatus(200);
        $this->assertSame('2026-09-17', $solo_hasta->json('desde'));
        $this->assertSame(['dentro el primer dia'], array_column($solo_hasta->json('sin_encontrar'), 'consulta'));
    }

    public function test_el_panel_rechaza_fechas_mal_escritas_o_invertidas_con_422(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->admin_logueado();

        $malas = [
            '?desde=2026-10-17&hasta=2026-10-16',
            '?desde=basura',
            '?hasta=16/10/2026',
            '?desde=2026-02-30',
            '?desde=2026-1-5',
            '?desde[]=2026-10-01',
            // Con un solo extremo que deja al otro invertido contra el valor por defecto.
            '?desde=2026-11-01',
        ];

        foreach ($malas as $query) {
            $respuesta = $this->getJson(self::PANEL . $query);

            $respuesta->assertStatus(422);
            $this->assertSame('datos_invalidos', $respuesta->json('error'), $query);
            $this->assertIsString($respuesta->json('detalle'), $query);
        }
    }

    public function test_el_limit_va_de_1_a_200_por_lista(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->admin_logueado();

        foreach (['uno', 'dos', 'tres'] as $nombre) {
            $this->fila($nombre, ['created_at' => now()->subHour()]);
        }

        $this->assertCount(2, $this->getJson(self::PANEL . '?limit=2')->assertStatus(200)->json('sin_encontrar'));
        $this->assertCount(3, $this->getJson(self::PANEL)->assertStatus(200)->json('sin_encontrar'));

        // Fuera de rango se acota, no se rechaza.
        $this->assertCount(1, $this->getJson(self::PANEL . '?limit=0')->assertStatus(200)->json('sin_encontrar'));
        $this->assertCount(3, $this->getJson(self::PANEL . '?limit=100000')->assertStatus(200)->json('sin_encontrar'));

        // Los totales no se recortan con el limit.
        $this->assertSame(3, $this->getJson(self::PANEL . '?limit=1')->json('totales.busquedas'));
    }

    public function test_los_titulos_se_piden_con_una_sola_consulta_y_un_video_borrado_da_null(): void
    {
        $this->sembrar_el_panel();
        $this->admin_logueado();

        // «plata» apunta a un video que ya no existe.
        \App\Models\RecursosVideo::where('codigo', 'T2.1')->delete();

        $consultas_a_videos = 0;

        DB::listen(function ($consulta) use (&$consultas_a_videos) {
            if (stripos($consulta->sql, 'from `recursos_videos`') !== false) {
                $consultas_a_videos++;
            }
        });

        $respuesta = $this->getJson(self::PANEL)->assertStatus(200);

        $this->assertSame(1, $consultas_a_videos);

        $plata = collect($respuesta->json('sin_clic'))->firstWhere('consulta', 'plata');
        $this->assertSame('T2.1', $plata['primero_codigo']);
        $this->assertNull($plata['primero_titulo']);
    }
}
