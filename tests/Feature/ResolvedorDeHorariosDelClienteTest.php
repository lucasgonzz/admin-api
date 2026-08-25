<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use App\Services\ClientScheduleResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El resolvedor de horarios del cliente: la pieza que decide si el negocio está abierto, cerrado
 * o sin configurar, y cuándo cierra.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. Que `sin_configurar` NUNCA se lea como `cerrado`. Si se confunden, el post-cierre de una
 *     actualización arranca sobre un negocio abierto y con gente adentro del sistema.
 *  2. Que `proximo_cierre` devuelva el CIERRE DEL DÍA y no el fin del rango vigente. Un negocio
 *     8–13 / 16–21 cierra a las 21, no a las 13: reabre.
 *  3. Que la fila del día puntual pise a la fila 'todos' incluso estando vacía, que es la forma
 *     de decir "el martes cerramos". Es la regla literal que dictó Lucas.
 */
class ResolvedorDeHorariosDelClienteTest extends TestCase
{
    use DatabaseTransactions;

    /** Lunes 24/8/2026, en el timezone de la app. */
    const LUNES = '2026-08-24';

    /**
     * Cliente mínimo para colgarle horarios.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client                  = new Client();
        $client->name            = 'Cliente de horarios';
        $client->slug            = 'cliente-horarios-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->save();

        return $client;
    }

    /**
     * Carga una fila de día con sus rangos. Sin rangos = ese día cerrado.
     *
     * @param Client                       $client  Cliente dueño del horario.
     * @param string                       $day_key Clave del día ('todos', 'martes', …).
     * @param array<int, array<int,string>> $rangos  Pares [desde, hasta] en formato 'H:i'.
     *
     * @return ClientScheduleDay
     */
    private function cargar_dia(Client $client, string $day_key, array $rangos = []): ClientScheduleDay
    {
        $dia            = new ClientScheduleDay();
        $dia->client_id = $client->id;
        $dia->day_key   = $day_key;
        $dia->save();

        $orden = 0;
        foreach ($rangos as $par) {
            $rango                         = new ClientScheduleRange();
            $rango->client_schedule_day_id = $dia->id;
            $rango->start_time             = $par[0];
            $rango->end_time               = $par[1];
            $rango->sort_order             = $orden;
            $rango->save();
            $orden++;
        }

        return $dia;
    }

    /**
     * Instante en el timezone de la app.
     *
     * @param string $fecha Fecha 'Y-m-d'.
     * @param string $hora  Hora 'H:i'.
     *
     * @return Carbon
     */
    private function momento(string $fecha, string $hora): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $fecha . ' ' . $hora, config('app.timezone'))->seconds(0);
    }

    /**
     * @return ClientScheduleResolver
     */
    private function resolvedor(): ClientScheduleResolver
    {
        return new ClientScheduleResolver();
    }

    /** 1) Solo la fila 'todos': los siete días resuelven ese horario y declaran de dónde salió. */
    public function test_la_fila_todos_los_dias_rige_toda_la_semana()
    {
        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);

        $dias = $this->resolvedor()->resolve_dias($client, $this->momento(self::LUNES, '00:00'), 7);

        $this->assertCount(7, $dias);

        foreach ($dias as $dia) {
            $this->assertSame('todos_los_dias', $dia['origen'], 'El día ' . $dia['dia'] . ' tendría que heredar de la fila todos.');
            $this->assertSame('con_horario', $dia['estado']);
            $this->assertSame([['desde' => '09:00', 'hasta' => '18:00']], $dia['rangos']);
            $this->assertSame('18:00', $dia['cierre_del_dia']);
            $this->assertSame(config('app.timezone'), $dia['timezone']);
        }
    }

    /** 2) La regla literal de Lucas: el día con fila propia pisa a 'todos'; el resto hereda. */
    public function test_el_dia_con_fila_propia_pisa_a_todos_los_dias()
    {
        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);
        $this->cargar_dia($client, 'martes', [['08:00', '13:00']]);

        $dias = $this->resolvedor()->resolve_dias($client, $this->momento(self::LUNES, '00:00'), 7);

        $martes = 0;
        foreach ($dias as $dia) {
            if ($dia['dia'] === 'martes') {
                $martes++;
                $this->assertSame('dia_propio', $dia['origen']);
                $this->assertSame('con_horario', $dia['estado']);
                $this->assertSame([['desde' => '08:00', 'hasta' => '13:00']], $dia['rangos']);
                continue;
            }

            $this->assertSame('todos_los_dias', $dia['origen']);
            $this->assertSame([['desde' => '09:00', 'hasta' => '18:00']], $dia['rangos']);
        }

        $this->assertSame(1, $martes, 'En una ventana de siete días tiene que haber exactamente un martes.');
    }

    /**
     * 3) El caso que justifica las dos tablas: fila propia SIN rangos = ese día cerrado.
     *
     * Con una sola tabla, "cero filas del domingo" sería indistinguible de "el domingo no está
     * configurado, heredá de todos", y sería imposible expresar "el domingo cerramos".
     */
    public function test_un_dia_con_fila_propia_y_sin_rangos_esta_cerrado()
    {
        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);
        $this->cargar_dia($client, 'domingo');

        $dias = $this->resolvedor()->resolve_dias($client, $this->momento(self::LUNES, '00:00'), 7);

        foreach ($dias as $dia) {
            if ($dia['dia'] === 'domingo') {
                $this->assertSame('dia_propio', $dia['origen']);
                $this->assertSame('cerrado', $dia['estado']);
                $this->assertSame([], $dia['rangos']);
                $this->assertNull($dia['cierre_del_dia']);
                continue;
            }

            $this->assertSame('todos_los_dias', $dia['origen']);
            $this->assertSame('con_horario', $dia['estado']);
        }
    }

    /**
     * 4) 🔴 Cliente sin ninguna fila: los siete días son 'sin_configurar', NUNCA 'cerrado'.
     *
     * Es el test que impide que "no sé" se lea como "está cerrado".
     */
    public function test_un_cliente_sin_horarios_cargados_queda_sin_configurar_y_nunca_cerrado()
    {
        $client = $this->crear_cliente();

        $dias = $this->resolvedor()->resolve_dias($client, $this->momento(self::LUNES, '00:00'), 7);

        $this->assertCount(7, $dias);

        foreach ($dias as $dia) {
            $this->assertSame('sin_configurar', $dia['estado']);
            $this->assertSame('sin_configurar', $dia['origen']);
            $this->assertNotSame('cerrado', $dia['estado']);
            $this->assertSame([], $dia['rangos']);
        }

        $this->assertSame(
            'sin_configurar',
            $this->resolvedor()->estado_en($client, $this->momento(self::LUNES, '12:00'))
        );
    }

    /** 5) Un día con dos rangos: el hueco del mediodía está cerrado y a la tarde vuelve a abrir. */
    public function test_estado_en_respeta_el_hueco_entre_dos_rangos_del_mismo_dia()
    {
        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'todos', [['08:00', '13:00'], ['16:00', '21:00']]);

        $resolvedor = $this->resolvedor();

        $this->assertSame('abierto', $resolvedor->estado_en($client, $this->momento(self::LUNES, '12:00')));
        $this->assertSame('cerrado', $resolvedor->estado_en($client, $this->momento(self::LUNES, '14:00')));
        $this->assertSame('abierto', $resolvedor->estado_en($client, $this->momento(self::LUNES, '17:00')));
        $this->assertSame('cerrado', $resolvedor->estado_en($client, $this->momento(self::LUNES, '22:00')));
    }

    /**
     * 6) 🔴 El test que vale: `proximo_cierre` a las 12:00 devuelve las 21:00, NO las 13:00.
     *
     * A las 13:00 termina un rango, pero el negocio reabre a las 16. Si este test pasara a las
     * 13:00, el post-cierre correría seeders y comandos con el cliente trabajando a las 16.
     */
    public function test_proximo_cierre_devuelve_el_cierre_del_dia_y_no_el_fin_del_rango_vigente()
    {
        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'todos', [['08:00', '13:00'], ['16:00', '21:00']]);

        $cierre = $this->resolvedor()->proximo_cierre($client, $this->momento(self::LUNES, '12:00'), 7);

        $this->assertNotNull($cierre);
        $this->assertSame(
            $this->momento(self::LUNES, '21:00')->toDateTimeString(),
            $cierre->toDateTimeString()
        );
    }

    /**
     * 7) Un día sin configurar en la ventana CORTA la búsqueda: null con motivo, nunca adivinar.
     *
     * El cliente tiene cargado solo el martes; la búsqueda arranca un lunes, que no tiene fila
     * propia ni fila 'todos'. Saltearlo hasta el martes sería inventar que el lunes está cerrado.
     */
    public function test_proximo_cierre_se_corta_ante_un_dia_sin_configurar()
    {
        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'martes', [['09:00', '18:00']]);

        $detalle = $this->resolvedor()->proximo_cierre_detallado($client, $this->momento(self::LUNES, '10:00'), 7);

        $this->assertNull($detalle['instante']);
        $this->assertSame('sin_configurar', $detalle['motivo']);
        $this->assertNull($this->resolvedor()->proximo_cierre($client, $this->momento(self::LUNES, '10:00'), 7));
    }

    /** 8) Un día cerrado NO corta la búsqueda: se saltea y el cierre sale del día siguiente. */
    public function test_proximo_cierre_saltea_los_dias_cerrados_y_toma_el_siguiente_con_rangos()
    {
        $client = $this->crear_cliente();
        // Hoy (lunes) fila propia sin rangos = cerrado. Mañana (martes) 9 a 18.
        $this->cargar_dia($client, 'lunes');
        $this->cargar_dia($client, 'martes', [['09:00', '18:00']]);

        $cierre = $this->resolvedor()->proximo_cierre($client, $this->momento(self::LUNES, '10:00'), 7);

        $this->assertNotNull($cierre);
        $this->assertSame(
            $this->momento('2026-08-25', '18:00')->toDateTimeString(),
            $cierre->toDateTimeString()
        );
    }

    /** 9) Siete días resueltos no pueden costar siete consultas: se carga todo una sola vez. */
    public function test_resolver_una_semana_no_dispara_una_consulta_por_dia()
    {
        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);
        $this->cargar_dia($client, 'martes', [['08:00', '13:00']]);
        $this->cargar_dia($client, 'domingo');

        // Instancia limpia: sin relaciones cargadas, para medir lo que cuesta resolver de verdad.
        $fresco = Client::find($client->id);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $dias = $this->resolvedor()->resolve_dias($fresco, $this->momento(self::LUNES, '00:00'), 7);

        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(7, $dias);
        $this->assertLessThanOrEqual(
            2,
            $consultas,
            'resolve_dias() tiene que cargar los días y sus rangos una sola vez, no una consulta por fecha.'
        );
    }
}
