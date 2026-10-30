<?php

namespace Tests\Feature\AccesoSoporte;

use App\Services\ClientAccesoSoporteService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Lo que `POST claude/clients/{id}/acceso-soporte` NO deja salir (misión `acceso-soporte-firmado`, 30/10/2026). Es lo más importante de la misión
 * y por eso tiene su archivo: una clave o un token en una respuesta o en un log no se puede des-mostrar.
 *
 *  1. 🔴 **La `clients.api_key` del cliente no sale NUNCA**: ni en la respuesta de éxito, ni en un error, ni en el log, ni escapada como JSON ni
 *     partida por el borde de un recorte, aunque el cliente (o la excepción de red) la repita.
 *  2. 🔴 **El token solo existe en el campo `url` de la respuesta de éxito.** Ni en un error —aunque el cliente lo mande en un 500—, ni en el log.
 *  3. 🔴 **El log lleva SOLO** client_id, client_api_id, solicitante, motivo, host del frente, estado y milisegundos: no el cuerpo del cliente, no
 *     la URL de entrada, no la ruta completa de la API.
 *  4. Un dry_run no deja rastro en el log y no emite nada.
 */
class ElAccesoDeSoporteNoFiltraSecretosTest extends BaseDelAccesoDeSoporte
{
    /** Las claves que el contexto de la línea de log del servicio puede tener, y nada más. */
    const CLAVES_DEL_LOG = ['client_id', 'client_api_id', 'solicitante', 'motivo', 'host', 'estado', 'ms'];

    /**
     * Las líneas de log que dejó el servicio de acceso de soporte.
     *
     * @param \Psr\Log\AbstractLogger $registro El registro devuelto por `capturar_el_log()`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineas_del_servicio($registro): array
    {
        return array_values(array_filter($registro->registros, function ($r) {
            return strpos($r['mensaje'], 'ClientAccesoSoporteService') === 0;
        }));
    }

    /* ------------------------------------------------------------------------------------------
     | 1. El log de un acceso emitido
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un acceso emitido deja UNA línea de log, a nivel info, con SOLO las siete claves del contrato y ni la clave del cliente ni el token en
     * ninguna línea del registro. La respuesta de éxito no lleva la clave del cliente (el token sí: es la `url`).
     *
     * @return void
     */
    public function test_el_log_de_un_acceso_emitido_lleva_solo_lo_acordado_y_nunca_la_clave_ni_el_token(): void
    {
        $registro = $this->capturar_el_log();

        $this->el_cliente_contesta($this->emision_valida() + ['eco_de_la_clave' => $this->clave], 200);

        $respuesta = $this->pedir_de_verdad(['solicitante' => 'codex', 'motivo' => 'Ver las ventas de ayer']);

        $respuesta->assertStatus(200);
        $this->assertSinLaClave($respuesta, $this->clave);

        $propias = $this->lineas_del_servicio($registro);

        $this->assertCount(1, $propias, 'Un acceso emitido deja una sola línea de log del servicio.');
        $this->assertSame('info', $propias[0]['nivel']);

        $contexto = $propias[0]['contexto'];

        $this->assertEqualsCanonicalizing(self::CLAVES_DEL_LOG, array_keys($contexto), 'El log lleva SOLO lo acordado.');
        $this->assertSame((int) $this->cliente->id, $contexto['client_id']);
        $this->assertSame((int) $this->cliente->active_client_api->id, $contexto['client_api_id']);
        $this->assertSame('codex', $contexto['solicitante']);
        $this->assertSame('Ver las ventas de ayer', $contexto['motivo']);
        $this->assertSame('api-doblep.ejemplo.test', $contexto['host'], 'El host del frente, sin ruta ni /public.');
        $this->assertSame(ClientAccesoSoporteService::ESTADO_OK, $contexto['estado']);
        $this->assertIsInt($contexto['ms']);

        $this->assertLogSinLaClave($registro, $this->clave);
        $this->assertLogSinElToken($registro);

        foreach ($registro->lineas as $linea) {
            $this->assertStringNotContainsString('acceso-soporte#', $linea, 'La URL de entrada no se loguea.');
            $this->assertStringNotContainsString('/public/api', $linea, 'La ruta de la API no se loguea: solo el host.');
        }
    }

    /**
     * El `motivo` ausente queda `null` en el log (no una cadena vacía) y el `solicitante` por defecto es `claude`.
     *
     * @return void
     */
    public function test_el_log_sin_motivo_lo_deja_en_null_y_el_solicitante_por_defecto_es_claude(): void
    {
        $registro = $this->capturar_el_log();

        $this->el_cliente_emite();

        $this->pedir_de_verdad()->assertStatus(200);

        $propias = $this->lineas_del_servicio($registro);

        $this->assertCount(1, $propias);
        $this->assertSame('claude', $propias[0]['contexto']['solicitante']);
        $this->assertNull($propias[0]['contexto']['motivo']);
    }

    /**
     * Lo que va a quedar en el log ya vino saneado: un salto de línea en el motivo no parte la línea ni falsifica otra.
     *
     * @return void
     */
    public function test_un_salto_de_linea_en_el_motivo_no_parte_la_linea_de_log(): void
    {
        $registro = $this->capturar_el_log();

        $this->el_cliente_emite();

        $this->pedir_de_verdad(['motivo' => "ver ventas\nINFO acceso falso concedido"])->assertStatus(200);

        $propias = $this->lineas_del_servicio($registro);

        $this->assertCount(1, $propias);
        $this->assertSame('ver ventas INFO acceso falso concedido', $propias[0]['contexto']['motivo']);

        foreach ($registro->lineas as $linea) {
            $this->assertStringNotContainsString("\n", $linea);
        }
    }

    /**
     * Si alguien pega la clave del cliente en el `motivo`, el log la tapa (el pedido al cliente es su propia clave y no se toca, pero el log no la
     * conserva).
     *
     * @return void
     */
    public function test_si_la_clave_aparece_en_el_motivo_el_log_la_tapa(): void
    {
        $registro = $this->capturar_el_log();

        $this->el_cliente_emite();

        $this->pedir_de_verdad(['motivo' => 'prueba con ' . $this->clave])->assertStatus(200);

        $this->assertLogSinLaClave($registro, $this->clave);

        $propias = $this->lineas_del_servicio($registro);

        $this->assertStringContainsString('[clave oculta]', (string) $propias[0]['contexto']['motivo']);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. El log de cada error
     |----------------------------------------------------------------------------------------- */

    /**
     * Cada estado de error: lo que contesta el cliente (repitiendo SU clave y el token adentro, que es lo peor que puede pasar), el estado que
     * debe quedar en el log, y a qué nivel.
     *
     * @return array<string, array<int, mixed>>
     */
    public function los_errores_que_se_loguean(): array
    {
        return [
            'version_vieja'       => [404, 'version_vieja'],
            'clave_rechazada'     => [401, 'clave_rechazada'],
            'acceso_desactivado'  => [403, 'acceso_desactivado'],
            'dueno_no_resoluble'  => [409, 'dueno_no_resoluble'],
            'respuesta_invalida'  => [500, 'respuesta_invalida'],
        ];
    }

    /**
     * 🔴 Aunque el cliente repita en su cuerpo la clave y un token, ni la respuesta de error ni el log los llevan. El log deja una línea `warning` con
     * el estado y las mismas siete claves.
     *
     * @dataProvider los_errores_que_se_loguean
     *
     * @param int    $status Lo que contesta el cliente.
     * @param string $estado El estado que tiene que quedar en el log.
     *
     * @return void
     */
    public function test_un_error_no_filtra_ni_la_clave_ni_el_token_y_deja_una_linea_warning(int $status, string $estado): void
    {
        $registro = $this->capturar_el_log();

        $codes = [403 => 'acceso_soporte_desactivado', 409 => 'dueno_no_resoluble'];

        $cuerpo = [
            'message' => 'Error del cliente con la clave ' . $this->clave,
            'token'   => $this->token,
            'debug'   => ['headers' => ['x-admin-api-key' => $this->clave]],
        ];

        if (isset($codes[$status])) {
            $cuerpo['code'] = $codes[$status];
        }

        $this->el_cliente_contesta($cuerpo, $status);

        $respuesta = $this->pedir_de_verdad();

        $this->assertContains($respuesta->status(), [409, 502]);
        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertSinElToken($respuesta);

        $propias = $this->lineas_del_servicio($registro);

        $this->assertCount(1, $propias);
        $this->assertSame('warning', $propias[0]['nivel']);
        $this->assertSame($estado, $propias[0]['contexto']['estado']);
        $this->assertEqualsCanonicalizing(self::CLAVES_DEL_LOG, array_keys($propias[0]['contexto']));

        $this->assertLogSinLaClave($registro, $this->clave);
        $this->assertLogSinElToken($registro);
    }

    /**
     * 🔴 Un 200 inválido que trae algo con forma de token (pero no lo es) tampoco se repite en la respuesta ni en el log.
     *
     * @return void
     */
    public function test_un_200_invalido_no_repite_lo_que_mando_el_cliente(): void
    {
        $registro = $this->capturar_el_log();

        $this->el_cliente_contesta(['ok' => true, 'token' => $this->token . '-', 'vence_en_segundos' => 120], 200);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(502);
        $this->assertSame('respuesta_invalida', $respuesta->json('error'));
        $this->assertSinElToken($respuesta);
        $this->assertLogSinElToken($registro);

        $propias = $this->lineas_del_servicio($registro);

        $this->assertSame('respuesta_invalida', $propias[0]['contexto']['estado']);
    }

    /**
     * 🔴 Un timeout cuyo mensaje trae la clave: el log tiene el estado `no_responde` y nada del texto de la excepción (ni la clave, ni la URL
     * completa, ni la query).
     *
     * @return void
     */
    public function test_un_timeout_no_deja_el_texto_de_la_excepcion_en_el_log(): void
    {
        $registro = $this->capturar_el_log();
        $clave    = $this->clave;

        $this->http_falso(['*' => function () use ($clave) {
            throw new ConnectionException(
                'cURL error 28: timed out (X-Admin-Api-Key: ' . $clave . ') for '
                . 'https://api-doblep.ejemplo.test/public/api/admin-sync/acceso-soporte?secreto=DATO-DEL-NEGOCIO'
            );
        }]);

        $respuesta = $this->pedir_de_verdad();

        $respuesta->assertStatus(502);
        $this->assertSinLaClave($respuesta, $clave);

        $propias = $this->lineas_del_servicio($registro);

        $this->assertCount(1, $propias);
        $this->assertSame('no_responde', $propias[0]['contexto']['estado']);

        $this->assertLogSinLaClave($registro, $clave);
        $this->assertStringNotContainsString('DATO-DEL-NEGOCIO', implode("\n", $registro->lineas));
        $this->assertStringNotContainsString('timed out', implode("\n", $registro->lineas), 'El texto de la excepción no va al log.');
    }

    /**
     * Los cortes de configuración en modo real también dejan su línea (`warning`) y no llevan la clave, que en este caso ni existe.
     *
     * @return void
     */
    public function test_un_corte_de_configuracion_deja_su_linea_de_log(): void
    {
        $registro = $this->capturar_el_log();
        $cliente  = $this->crear_cliente('Cliente sin SPA', 'sk-corte-' . Str::random(30));
        $this->crear_frente_a_medida($cliente, ['spa_url' => ''], true);

        Http::fake();

        $this->pedir_de_verdad([], $cliente)->assertStatus(409);

        $propias = $this->lineas_del_servicio($registro);

        $this->assertCount(1, $propias);
        $this->assertSame('warning', $propias[0]['nivel']);
        $this->assertSame('sin_spa_url', $propias[0]['contexto']['estado']);
        $this->assertSame((int) $cliente->id, $propias[0]['contexto']['client_id']);

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------------------------------
     | 3. Una clave rara y un dry_run
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Una clave con comillas, barras y caracteres que se escapan en JSON y en una URL se tapa en TODAS sus formas: si el cliente la repite escapada
     * o si viaja dentro de la excepción, no queda a la vista.
     *
     * @return void
     */
    public function test_una_clave_con_caracteres_raros_se_tapa_en_todas_sus_formas(): void
    {
        $clave   = 'sk/con"barras\\y-comillas-' . Str::random(20);
        $cliente = $this->crear_cliente('Cliente de clave rara', $clave);
        $this->crear_frente($cliente, 'clarar', 'shared_hosting', null, true);

        $registro = $this->capturar_el_log();

        $this->http_falso(['*' => function () use ($clave) {
            throw new ConnectionException(
                'cURL error 7: (header ' . $clave . ') json ' . substr((string) json_encode($clave), 1, -1) . ' url ' . rawurlencode($clave)
            );
        }]);

        $respuesta = $this->pedir_de_verdad([], $cliente);

        $respuesta->assertStatus(502);
        $this->assertSinLaClave($respuesta, $clave);
        $this->assertStringNotContainsString(substr((string) json_encode($clave), 1, -1), (string) $respuesta->getContent());
        $this->assertStringNotContainsString(rawurlencode($clave), (string) $respuesta->getContent());
        $this->assertLogSinLaClave($registro, $clave);
    }

    /**
     * Un dry_run no deja NADA en el log del servicio (no emitió ni pidió nada) y su respuesta no lleva la clave.
     *
     * @return void
     */
    public function test_un_dry_run_no_deja_rastro_en_el_log_ni_lleva_la_clave(): void
    {
        $registro = $this->capturar_el_log();

        Http::fake();

        $respuesta = $this->pedir([]);

        $respuesta->assertStatus(200);
        $this->assertSinLaClave($respuesta, $this->clave);
        $this->assertSame([], $this->lineas_del_servicio($registro), '`dry_run` no deja rastro en el log.');
        $this->assertLogSinLaClave($registro, $this->clave);

        Http::assertNothingSent();
    }

    /**
     * El token de un acceso emitido no queda en ningún lado del cliente del admin: la fila del cliente (`clients`) y sus frentes no lo guardan.
     *
     * @return void
     */
    public function test_el_token_no_se_guarda_en_la_base_del_admin(): void
    {
        $this->el_cliente_emite();

        $this->pedir_de_verdad()->assertStatus(200);

        $cliente = \Illuminate\Support\Facades\DB::table('clients')->where('id', $this->cliente->id)->first();
        $frentes = \Illuminate\Support\Facades\DB::table('client_apis')->where('client_id', $this->cliente->id)->get();

        $this->assertStringNotContainsString($this->token, json_encode($cliente));
        $this->assertStringNotContainsString($this->token, json_encode($frentes));
    }
}
