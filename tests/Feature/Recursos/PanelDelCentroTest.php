<?php

namespace Tests\Feature\Recursos;

use App\Models\RecursosEncuesta;
use App\Models\RecursosLink;
use App\Models\RecursosPregunta;
use App\Models\RecursosProgreso;
use App\Models\RecursosUsuario;
use App\Models\RecursosVoto;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Facades\Http;

/**
 * El panel del centro de recursos en el admin (misión centro-de-recursos-clientes, 13/10/2026):
 * preguntas (responder, ocultar, borrador con IA), videos peor puntuados primero, avance por cliente
 * y el link personal del dueño.
 *
 * 🔴 La IA está SIEMPRE falseada (`Http::fake` de api.anthropic.com): ningún test sale a internet.
 */
class PanelDelCentroTest extends BaseDelCentroDeRecursos
{
    public function test_sin_admin_el_panel_da_401(): void
    {
        $client = $this->crear_cliente('api-a.test');
        list(, $token_del_centro) = $this->usuario_con_sesion($client);

        $rutas = [
            ['GET', '/api/admin/recursos/preguntas'],
            ['PUT', '/api/admin/recursos/preguntas/1'],
            ['POST', '/api/admin/recursos/preguntas/1/borrador'],
            ['GET', '/api/admin/recursos/videos'],
            ['GET', '/api/admin/recursos/videos/T1.1/mejoras'],
            ['GET', '/api/admin/recursos/clientes'],
            ['GET', '/api/admin/recursos/clientes/' . $client->id],
            ['POST', '/api/admin/recursos/clientes/' . $client->id . '/link-dueno'],
        ];

        foreach ($rutas as $ruta) {
            $this->json($ruta[0], $ruta[1])->assertStatus(401);
            // 🔴 Ni con un token del centro (por eso la sesión del centro no es Sanctum).
            $this->json($ruta[0], $ruta[1], [], $this->con_sesion($token_del_centro))->assertStatus(401);
        }

        $this->assertSame(0, RecursosLink::count());
    }

    /* ------------------------------------------------------------------------------------------
     | Preguntas
     |----------------------------------------------------------------------------------------- */

    public function test_las_preguntas_pendientes_van_primero_y_la_mas_vieja_arriba_con_usuario_y_cliente(): void
    {
        $this->admin_logueado();
        $video  = $this->crear_video('T1.1');
        $client = $this->crear_cliente('api-a.test', ['company_name' => 'Ferretería El Tornillo']);
        list($usuario) = $this->usuario_con_sesion($client, ['nombre' => 'Marcela Gómez', 'email' => 'marcela@tornillo.test']);

        $vieja      = $this->pregunta($video->id, $usuario->id, 'pendiente vieja', ['created_at' => now()->subDays(3)]);
        $nueva      = $this->pregunta($video->id, $usuario->id, 'pendiente nueva', ['created_at' => now()->subDay()]);
        $respondida = $this->pregunta($video->id, $usuario->id, 'respondida', ['created_at' => now()->subDays(5), 'respuesta' => 'ok', 'respondido_at' => now()]);
        $oculta     = $this->pregunta($video->id, $usuario->id, 'oculta', ['created_at' => now(), 'oculta' => true]);

        $todas = $this->getJson('/api/admin/recursos/preguntas');
        $todas->assertStatus(200);
        $this->assertSame([$vieja->id, $nueva->id, $oculta->id, $respondida->id], array_column($todas->json('preguntas'), 'id'));
        $todas->assertJsonPath('totales', ['pendientes' => 2, 'respondidas' => 1, 'ocultas' => 1]);

        $todas->assertJsonPath('preguntas.0.usuario.nombre', 'Marcela Gómez');
        $todas->assertJsonPath('preguntas.0.usuario.email', 'marcela@tornillo.test');
        $todas->assertJsonPath('preguntas.0.cliente.nombre', 'Ferretería El Tornillo');
        $todas->assertJsonPath('preguntas.0.codigo', 'T1.1');
        $todas->assertJsonPath('preguntas.0.estado', 'pendiente');

        $this->assertSame([$vieja->id, $nueva->id], array_column($this->getJson('/api/admin/recursos/preguntas?estado=pendientes')->json('preguntas'), 'id'));
        $this->assertSame([$respondida->id], array_column($this->getJson('/api/admin/recursos/preguntas?estado=respondidas')->json('preguntas'), 'id'));
        $this->assertSame([$oculta->id], array_column($this->getJson('/api/admin/recursos/preguntas?estado=ocultas')->json('preguntas'), 'id'));
        $this->getJson('/api/admin/recursos/preguntas?estado=cualquiera')->assertStatus(422);
    }

    public function test_responder_la_ve_todo_el_mundo_y_le_avisa_al_autor(): void
    {
        $admin  = $this->admin_logueado();
        $video  = $this->crear_video('T1.1');
        $client = $this->crear_cliente('api-a.test');
        list($autor, $token_autor) = $this->usuario_con_sesion($client);

        $pregunta = $this->pregunta($video->id, $autor->id, '¿Cómo importo?');

        // Antes de responder, un invitado no la ve.
        $this->assertSame([], $this->getJson('/api/recursos/videos/T1.1')->json('preguntas'));

        $respuesta = $this->putJson('/api/admin/recursos/preguntas/' . $pregunta->id, ['respuesta' => 'Desde Listado → Importar.']);
        $respuesta->assertStatus(200)
            ->assertJsonPath('estado', 'respondida')
            ->assertJsonPath('respuesta', 'Desde Listado → Importar.')
            ->assertJsonPath('respondido_por', $admin->name);

        $pregunta->refresh();
        $this->assertSame((int) $admin->id, $pregunta->respondido_por_admin_id);
        $this->assertNotNull($pregunta->respondido_at);

        $this->assertSame([$pregunta->id], array_column($this->getJson('/api/recursos/videos/T1.1')->json('preguntas'), 'id'));

        $this->getJson('/api/recursos/yo', $this->con_sesion($token_autor))
            ->assertJsonPath('respuestas_nuevas', [['codigo' => 'T1.1', 'pregunta_id' => $pregunta->id]]);

        // Una respuesta vacía la vuelve a dejar pendiente.
        $this->putJson('/api/admin/recursos/preguntas/' . $pregunta->id, ['respuesta' => '  '])
            ->assertStatus(200)
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonPath('respuesta', null);

        $this->putJson('/api/admin/recursos/preguntas/' . $pregunta->id, [])->assertStatus(422);
        $this->putJson('/api/admin/recursos/preguntas/999999999', ['oculta' => true])->assertStatus(404);
    }

    public function test_ocultar_la_saca_de_la_pagina_incluso_para_su_autor(): void
    {
        $this->admin_logueado();
        $video  = $this->crear_video('T1.1');
        $client = $this->crear_cliente('api-a.test');
        list($autor, $token_autor) = $this->usuario_con_sesion($client);

        $pregunta = $this->pregunta($video->id, $autor->id, 'algo feo', ['respuesta' => 'ok', 'respondido_at' => now()]);

        $this->putJson('/api/admin/recursos/preguntas/' . $pregunta->id, ['oculta' => true])
            ->assertStatus(200)
            ->assertJsonPath('estado', 'oculta');

        $this->assertSame([], $this->getJson('/api/recursos/videos/T1.1')->json('preguntas'));
        $this->assertSame([], $this->getJson('/api/recursos/videos/T1.1', $this->con_sesion($token_autor))->json('preguntas'));

        $this->putJson('/api/admin/recursos/preguntas/' . $pregunta->id, ['oculta' => 'quizas'])->assertStatus(422);
    }

    /* ------------------------------------------------------------------------------------------
     | Borrador con IA (falseada)
     |----------------------------------------------------------------------------------------- */

    public function test_el_borrador_sale_de_la_ia_con_el_video_y_se_guarda(): void
    {
        $this->admin_logueado();
        config(['services.anthropic.api_key' => 'clave-de-prueba', 'services.anthropic.model' => 'modelo-de-prueba']);

        $this->crear_video('T1.9', ['titulo' => 'Importar desde Excel', 'orden' => 9]);
        $video  = $this->crear_video('T1.1', ['transcripcion' => 'Para cargar un artículo tocá el botón verde.', 'relacionados' => ['T1.9']]);
        $client = $this->crear_cliente('api-a.test');
        list($autor) = $this->usuario_con_sesion($client);
        $pregunta = $this->pregunta($video->id, $autor->id, '¿Puedo importar muchos de una?');

        Http::fake(['https://api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '  Eso no está en este video: fijate en «Importar desde Excel».  ']],
        ], 200)]);

        $this->postJson('/api/admin/recursos/preguntas/' . $pregunta->id . '/borrador')
            ->assertStatus(200)
            ->assertExactJson(['borrador' => 'Eso no está en este video: fijate en «Importar desde Excel».']);

        $this->assertSame('Eso no está en este video: fijate en «Importar desde Excel».', $pregunta->fresh()->borrador);
        // Un borrador NO es una respuesta: el autor sigue sin respuesta.
        $this->assertNull($pregunta->fresh()->respuesta);

        Http::assertSent(function (PedidoHttp $pedido) {
            $prompt = (string) $pedido['messages'][0]['content'];

            return $pedido->url() === 'https://api.anthropic.com/v1/messages'
                && $pedido->header('x-api-key') === ['clave-de-prueba']
                && $pedido['model'] === 'modelo-de-prueba'
                && strpos($prompt, 'tocá el botón verde') !== false
                && strpos($prompt, 'T1.9 · Importar desde Excel') !== false
                && strpos($prompt, '¿Puedo importar muchos de una?') !== false
                && strpos($prompt, 'voseo') !== false;
        });
    }

    public function test_sin_clave_de_ia_el_borrador_da_422_sin_llamar_a_nadie(): void
    {
        $this->admin_logueado();
        config(['services.anthropic.api_key' => '']);

        $video  = $this->crear_video('T1.1');
        $client = $this->crear_cliente('api-a.test');
        list($autor) = $this->usuario_con_sesion($client);
        $pregunta = $this->pregunta($video->id, $autor->id, 'hola');

        Http::fake();

        $this->postJson('/api/admin/recursos/preguntas/' . $pregunta->id . '/borrador')
            ->assertStatus(422)
            ->assertJsonPath('error', 'sin_clave_ia');

        Http::assertNothingSent();
        $this->assertNull($pregunta->fresh()->borrador);
    }

    public function test_si_la_ia_falla_el_borrador_da_422_con_el_motivo(): void
    {
        $this->admin_logueado();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $video  = $this->crear_video('T1.1');
        $client = $this->crear_cliente('api-a.test');
        list($autor) = $this->usuario_con_sesion($client);
        $pregunta = $this->pregunta($video->id, $autor->id, 'hola');

        Http::fake(['https://api.anthropic.com/*' => Http::response(['error' => 'sobrecargado'], 529)]);

        $this->postJson('/api/admin/recursos/preguntas/' . $pregunta->id . '/borrador')
            ->assertStatus(422)
            ->assertJsonPath('error', 'ia_no_disponible')
            ->assertJsonPath('motivo', 'La IA respondió HTTP 529.');

        $this->assertNull($pregunta->fresh()->borrador);
    }

    /* ------------------------------------------------------------------------------------------
     | Videos
     |----------------------------------------------------------------------------------------- */

    public function test_los_videos_salen_del_peor_puntuado_al_mejor(): void
    {
        $this->admin_logueado();
        $bueno  = $this->crear_video('T1.1', ['orden' => 1]);
        $malo   = $this->crear_video('T1.2', ['orden' => 2]);
        $medio  = $this->crear_video('T1.3', ['orden' => 3]);
        $this->crear_video('T1.4', ['orden' => 4]);
        $client = $this->crear_cliente('api-a.test');

        $usuarios = [];
        foreach (range(1, 2) as $n) {
            list($usuarios[]) = $this->usuario_con_sesion($client);
        }

        // Bueno: 2 👍. Puntaje 0.
        foreach ($usuarios as $u) {
            RecursosVoto::create(['recursos_usuario_id' => $u->id, 'recursos_video_id' => $bueno->id, 'valor' => 1]);
        }

        // Malo: 👎 + útil no + entendió no → 3 malas de 3. Puntaje 1.
        RecursosVoto::create(['recursos_usuario_id' => $usuarios[0]->id, 'recursos_video_id' => $malo->id, 'valor' => -1]);
        RecursosEncuesta::create(['recursos_usuario_id' => $usuarios[0]->id, 'recursos_video_id' => $malo->id, 'util' => 'no', 'entendio' => 'no', 'mejora' => 'Muy rápido']);

        // Medio: útil sí + más o menos → 0,5 de 2. Puntaje 0,25.
        RecursosEncuesta::create(['recursos_usuario_id' => $usuarios[1]->id, 'recursos_video_id' => $medio->id, 'util' => 'si', 'entendio' => 'mas_o_menos']);

        RecursosProgreso::create(['recursos_usuario_id' => $usuarios[0]->id, 'recursos_video_id' => $malo->id, 'vistos' => '', 'porcentaje' => 95, 'terminado_at' => now()]);
        RecursosProgreso::create(['recursos_usuario_id' => $usuarios[1]->id, 'recursos_video_id' => $malo->id, 'vistos' => '', 'porcentaje' => 10]);

        $videos = $this->getJson('/api/admin/recursos/videos')->assertStatus(200)->json('videos');

        $this->assertSame(['T1.2', 'T1.3', 'T1.1', 'T1.4'], array_column($videos, 'codigo'));
        $this->assertEquals(1, $videos[0]['puntaje']);
        $this->assertEquals(0.25, $videos[1]['puntaje']);
        $this->assertEquals(0, $videos[2]['puntaje']);
        $this->assertNull($videos[3]['puntaje']);

        $this->assertSame(2, $videos[0]['vistas']);
        $this->assertSame(1, $videos[0]['terminados']);
        $this->assertSame(['positivos' => 0, 'negativos' => 1], $videos[0]['votos']);
        $this->assertSame(1, $videos[0]['mejoras']);
        $this->assertSame(1, $videos[0]['encuestas']['util_no']);

        $this->getJson('/api/admin/recursos/videos/T1.2/mejoras')
            ->assertStatus(200)
            ->assertJsonPath('mejoras.0.texto', 'Muy rápido')
            ->assertJsonPath('mejoras.0.cliente', 'Ferretería El Tornillo');
        $this->getJson('/api/admin/recursos/videos/T9.9/mejoras')->assertStatus(404);
    }

    public function test_el_desempate_es_por_cantidad_de_respuestas(): void
    {
        $this->admin_logueado();
        $pocas  = $this->crear_video('T1.1', ['orden' => 1]);
        $muchas = $this->crear_video('T1.2', ['orden' => 2]);
        $client = $this->crear_cliente('api-a.test');

        list($u1) = $this->usuario_con_sesion($client);
        list($u2) = $this->usuario_con_sesion($client);

        RecursosVoto::create(['recursos_usuario_id' => $u1->id, 'recursos_video_id' => $pocas->id, 'valor' => -1]);
        RecursosVoto::create(['recursos_usuario_id' => $u1->id, 'recursos_video_id' => $muchas->id, 'valor' => -1]);
        RecursosVoto::create(['recursos_usuario_id' => $u2->id, 'recursos_video_id' => $muchas->id, 'valor' => -1]);

        $this->assertSame(['T1.2', 'T1.1'], array_column($this->getJson('/api/admin/recursos/videos')->json('videos'), 'codigo'));
    }

    /* ------------------------------------------------------------------------------------------
     | Clientes
     |----------------------------------------------------------------------------------------- */

    /**
     * El avance se cuenta igual que en la página: sobre los videos activos, terminado = 1 y si no su
     * porcentaje.
     */
    public function test_el_avance_por_cliente_y_por_carpeta_se_cuenta_como_en_la_pagina(): void
    {
        $this->admin_logueado();
        $v1 = $this->crear_video('T1.1', ['orden' => 1, 'carpeta' => 1]);
        $v2 = $this->crear_video('T1.2', ['orden' => 2, 'carpeta' => 1]);
        $v3 = $this->crear_video('T2.1', ['orden' => 3, 'carpeta' => 2, 'carpeta_nombre' => 'Ventas']);
        $v4 = $this->crear_video('T2.2', ['orden' => 4, 'carpeta' => 2, 'carpeta_nombre' => 'Ventas']);
        $inactivo = $this->crear_video('T9.1', ['orden' => 9, 'activo' => false]);

        $client = $this->crear_cliente('api-a.test', ['company_name' => 'Kiosco Uno']);
        list($dueno) = $this->usuario_con_sesion($client, ['nombre' => 'Ana Dueña', 'es_dueno' => true, 'ultimo_ingreso_at' => now()]);
        list($empleado) = $this->usuario_con_sesion($client, ['nombre' => 'Beto Empleado']);

        // Dueña: T1.1 terminado al 92 % (cuenta 1) + T1.2 al 50 % → 1,5 de 4 = 37,5 %.
        RecursosProgreso::create(['recursos_usuario_id' => $dueno->id, 'recursos_video_id' => $v1->id, 'vistos' => '', 'porcentaje' => 92, 'terminado_at' => now()]);
        RecursosProgreso::create(['recursos_usuario_id' => $dueno->id, 'recursos_video_id' => $v2->id, 'vistos' => '', 'porcentaje' => 50]);
        // Un video inactivo no cuenta.
        RecursosProgreso::create(['recursos_usuario_id' => $dueno->id, 'recursos_video_id' => $inactivo->id, 'vistos' => '', 'porcentaje' => 100, 'terminado_at' => now()]);
        // Empleado: T2.1 al 20 % → 0,2 de 4 = 5 %.
        RecursosProgreso::create(['recursos_usuario_id' => $empleado->id, 'recursos_video_id' => $v3->id, 'vistos' => '', 'porcentaje' => 20]);

        // La lista trae a TODOS los clientes activos (ver el test de abajo): se mira la fila de éste, que
        // por tener actividad va primera.
        $clientes = $this->getJson('/api/admin/recursos/clientes')->assertStatus(200)->json('clientes');
        $this->assertSame((int) $client->id, $clientes[0]['client_id']);
        $this->assertSame('Kiosco Uno', $clientes[0]['nombre']);
        $this->assertSame(2, $clientes[0]['usuarios']);
        $this->assertEquals(21.3, $clientes[0]['avance']);
        $this->assertSame(1, $clientes[0]['videos_terminados']);
        $this->assertNotNull($clientes[0]['ultima_actividad']);

        $detalle = $this->getJson('/api/admin/recursos/clientes/' . $client->id)->assertStatus(200)->json();
        $this->assertSame(2, $detalle['resumen']['usuarios']);
        $this->assertSame(4, $detalle['resumen']['videos_activos']);
        $this->assertNull($detalle['link_dueno']);

        $this->assertSame('Ana Dueña', $detalle['usuarios'][0]['nombre']);
        $this->assertTrue($detalle['usuarios'][0]['es_dueno']);
        $this->assertEquals(37.5, $detalle['usuarios'][0]['avance']);
        $this->assertSame(1, $detalle['usuarios'][0]['terminados']);
        $this->assertEquals(
            [
                ['nro' => 1, 'nombre' => 'Tus artículos (Listado)', 'total' => 2, 'terminados' => 1, 'avance' => 75],
                ['nro' => 2, 'nombre' => 'Ventas', 'total' => 2, 'terminados' => 0, 'avance' => 0],
            ],
            $detalle['usuarios'][0]['carpetas']
        );
        $this->assertEquals(5, $detalle['usuarios'][1]['avance']);
        $this->assertEquals(10, $detalle['usuarios'][1]['carpetas'][1]['avance']);
    }

    /**
     * 🔴 La lista trae a TODOS los clientes activos, también los que nunca entraron (en cero): desde ahí
     * se saca el link del dueño, y el que más lo necesita es el que todavía no entró. Primero los que
     * tienen actividad (la más reciente arriba), después el resto por nombre. Los dados de baja no.
     */
    public function test_la_lista_de_clientes_trae_tambien_a_los_que_nunca_entraron(): void
    {
        $this->admin_logueado();
        $video = $this->crear_video('T1.1');

        $viejo   = $this->crear_cliente('api-viejo.test', ['company_name' => 'Almacén Viejo']);
        $reciente = $this->crear_cliente('api-reciente.test', ['company_name' => 'Zapatería Reciente']);
        $nunca_b = $this->crear_cliente('api-b.test', ['company_name' => 'Bazar Nunca']);
        $nunca_a = $this->crear_cliente('api-a.test', ['company_name' => 'almacén Nunca']);
        $baja    = $this->crear_cliente('api-baja.test', ['company_name' => 'Dado De Baja', 'is_active' => false]);

        list($u_viejo)    = $this->usuario_con_sesion($viejo, ['ultimo_ingreso_at' => now()->subDays(10)]);
        list($u_reciente) = $this->usuario_con_sesion($reciente, ['ultimo_ingreso_at' => now()->subHour()]);
        RecursosProgreso::create(['recursos_usuario_id' => $u_reciente->id, 'recursos_video_id' => $video->id, 'vistos' => '', 'porcentaje' => 50]);
        $this->usuario_con_sesion($baja, ['ultimo_ingreso_at' => now()]);

        $clientes = $this->getJson('/api/admin/recursos/clientes')->assertStatus(200)->json('clientes');
        $mios     = array_values(array_filter($clientes, function ($fila) use ($viejo, $reciente, $nunca_a, $nunca_b, $baja) {
            return in_array($fila['client_id'], [(int) $viejo->id, (int) $reciente->id, (int) $nunca_a->id, (int) $nunca_b->id, (int) $baja->id], true);
        }));

        $this->assertSame(
            [(int) $reciente->id, (int) $viejo->id, (int) $nunca_a->id, (int) $nunca_b->id],
            array_column($mios, 'client_id')
        );

        $this->assertEquals(50, $mios[0]['avance']);
        $this->assertSame(1, $mios[1]['usuarios']);
        $this->assertSame(
            ['client_id' => (int) $nunca_a->id, 'nombre' => 'almacén Nunca', 'slug' => $nunca_a->slug, 'usuarios' => 0, 'avance' => 0, 'videos_terminados' => 0, 'ultima_actividad' => null],
            $mios[2]
        );

        // Y del que nunca entró se saca el link del dueño.
        $this->postJson('/api/admin/recursos/clientes/' . $nunca_a->id . '/link-dueno')->assertStatus(200);
    }

    public function test_un_cliente_que_no_entro_contesta_en_cero_y_uno_que_no_existe_404(): void
    {
        $this->admin_logueado();
        $this->crear_video('T1.1');
        $client = $this->crear_cliente('api-a.test');

        $this->getJson('/api/admin/recursos/clientes/' . $client->id)
            ->assertStatus(200)
            ->assertJsonPath('usuarios', [])
            ->assertJsonPath('resumen.usuarios', 0)
            ->assertJsonPath('resumen.videos_terminados', 0);

        $this->getJson('/api/admin/recursos/clientes/999999999')->assertStatus(404);
        $this->postJson('/api/admin/recursos/clientes/999999999/link-dueno')->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | Link del dueño
     |----------------------------------------------------------------------------------------- */

    public function test_el_link_del_dueno_se_crea_una_vez_y_se_reusa_mientras_este_vigente(): void
    {
        $admin  = $this->admin_logueado();
        $client = $this->crear_cliente('api-a.test', ['name' => 'Marcela Gómez', 'user_id' => 300]);

        $primero = $this->postJson('/api/admin/recursos/clientes/' . $client->id . '/link-dueno')->assertStatus(200)->json();
        $this->assertStringStartsWith('https://recursos.comerciocity.com/?t=', $primero['url']);
        $this->assertNotNull($primero['expires_at']);

        $segundo = $this->postJson('/api/admin/recursos/clientes/' . $client->id . '/link-dueno')->assertStatus(200)->json();
        $this->assertSame($primero, $segundo);
        $this->assertSame(1, RecursosLink::count());
        $this->assertSame((int) $admin->id, (int) RecursosLink::first()->creado_por_admin_id);

        // El token no queda en claro en la base.
        parse_str((string) parse_url($primero['url'], PHP_URL_QUERY), $query);
        $this->assertSame(0, RecursosLink::where('token_hash', $query['t'])->count());
        $crudo = (string) \Illuminate\Support\Facades\DB::table('recursos_links')->value('token_cifrado');
        $this->assertStringNotContainsString($query['t'], $crudo);
        $this->assertSame($query['t'], RecursosLink::first()->token_cifrado);

        $dueno = RecursosUsuario::where('client_id', $client->id)->first();
        $this->assertTrue($dueno->es_dueno);
        $this->assertSame(300, $dueno->empresa_user_id);
        $this->assertSame('Marcela Gómez', $dueno->nombre);

        $this->getJson('/api/admin/recursos/clientes/' . $client->id)->assertJsonPath('link_dueno.expires_at', $primero['expires_at']);

        // Vencido, se genera otro.
        RecursosLink::query()->update(['expires_at' => now()->subMinute()]);
        $tercero = $this->postJson('/api/admin/recursos/clientes/' . $client->id . '/link-dueno')->assertStatus(200)->json();
        $this->assertNotSame($primero['url'], $tercero['url']);
        $this->assertSame(1, RecursosUsuario::where('client_id', $client->id)->count());
    }

    /**
     * Si el dueño ya entró desde su sistema (tiene fila con id), el link abre ESA cuenta aunque
     * `clients.user_id` no esté cargado.
     */
    public function test_el_link_usa_la_cuenta_del_dueno_que_ya_entro(): void
    {
        $this->admin_logueado();
        $client = $this->crear_cliente('api-a.test', ['user_id' => null]);
        list($dueno) = $this->usuario_con_sesion($client, ['es_dueno' => true, 'empresa_user_id' => 41, 'nombre' => 'Marcela Gómez']);

        $link = $this->postJson('/api/admin/recursos/clientes/' . $client->id . '/link-dueno')->assertStatus(200)->json();
        parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $query);

        $this->assertSame(1, RecursosUsuario::where('client_id', $client->id)->count());
        $this->assertSame((int) $dueno->id, (int) RecursosLink::first()->recursos_usuario_id);

        $this->postJson('/api/recursos/sesion/link', ['t' => $query['t']])->assertStatus(200)->assertJsonPath('usuario.nombre', 'Marcela Gómez');
    }

    /**
     * Una pregunta de prueba.
     *
     * @param int                  $video_id
     * @param int                  $usuario_id
     * @param string               $texto
     * @param array<string, mixed> $atributos
     *
     * @return RecursosPregunta
     */
    private function pregunta(int $video_id, int $usuario_id, string $texto, array $atributos = []): RecursosPregunta
    {
        $pregunta = new RecursosPregunta(array_merge([
            'recursos_video_id'   => $video_id,
            'recursos_usuario_id' => $usuario_id,
            'texto'               => $texto,
        ], $atributos));

        if (isset($atributos['created_at'])) {
            $pregunta->timestamps = false;
            $pregunta->updated_at = $atributos['created_at'];
        }

        $pregunta->save();
        $pregunta->timestamps = true;

        return $pregunta;
    }
}
