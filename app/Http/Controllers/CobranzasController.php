<?php

namespace App\Http\Controllers;

use App\Helpers\AppTime;
use App\Models\Client;
use App\Models\LicenciaCuota;
use App\Models\Venta;
use App\Services\CobranzasMensualidadService;
use App\Services\LicenciaCuotaService;
use App\Services\VentaService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * El módulo Cobranzas del admin (misión modulo-cobranzas, 18/9/2026): las dos tablas de todos
 * los clientes —Mensualidades y Licencias— y las preferencias de vista de cada admin.
 *
 * Es la versión calculada del Excel `ComercioCity administracion.xlsx`: una fila por cliente,
 * una columna por mes, y el color de la fila según el mes de referencia. La lógica de estados vive
 * en `CobranzasMensualidadService`; acá solo se valida el request y se arma la respuesta.
 *
 * No hay permisos por módulo en el admin: Cobranzas lo ven todos los admins, como el resto de
 * los módulos (supuesto declarado en el plan de la misión).
 */
class CobranzasController extends Controller
{
    /**
     * Regla de validación de un mes 'YYYY-MM'.
     */
    const REGLA_PERIODO = 'regex:/^\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * Cuántos meses como máximo se pueden pedir de una vez (una columna por mes en la tabla).
     */
    const MAXIMO_MESES = 24;

    /**
     * Órdenes admitidos para la tabla de Mensualidades. El orden en sí lo aplica el front (es
     * sobre el estado del mes de referencia, que él elige); acá solo se valida y se persiste.
     *
     * @var array<int, string>
     */
    const ORDENES = ['carga', 'sin_pago', 'sin_factura'];

    /**
     * La tabla de Mensualidades: todos los clientes con el estado de cada mes pedido.
     *
     * `meses` viene como lista separada por coma en la query (`?meses=2026-09,2026-08`), 1 a 24
     * valores 'YYYY-MM'. Es obligatorio: el front ya sabe qué meses quiere (los tiene guardados en
     * sus preferencias) y una tabla sin meses no significa nada.
     *
     * `rango` es el tramo de meses que la tira de arriba ofrece para elegir: desde el inicio más
     * viejo de todos los clientes (o 12 meses atrás) hasta el corriente más 3.
     *
     * `resumen` son las dos tarjetas de plata —lo cobrado y lo que falta cobrar— relativas al mes más
     * reciente de `meses` (ver `CobranzasMensualidadService::resumen_cobranza()`).
     *
     * @param  Request                     $request
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function mensualidades_json(Request $request, CobranzasMensualidadService $service)
    {
        $meses = $this->meses_desde_query($request->query('meses'));

        if ($meses === null) {
            return response()->json([
                'message' => 'Indicá entre 1 y ' . self::MAXIMO_MESES . ' meses con formato YYYY-MM, separados por coma.',
                'errors'  => ['meses' => ['Entre 1 y ' . self::MAXIMO_MESES . ' meses YYYY-MM separados por coma.']],
            ], 422);
        }

        $ahora = AppTime::now();

        /** El inicio más viejo de todos los clientes, para que la tira llegue hasta ahí. */
        $inicio_minimo = Client::whereNotNull('mensualidad_inicio')->min('mensualidad_inicio');

        $desde = $inicio_minimo
            ? Carbon::parse($inicio_minimo)->format('Y-m')
            : $ahora->copy()->subMonthsNoOverflow(CobranzasMensualidadService::MESES_ATRAS_DEFAULT)->format('Y-m');

        /** Último mes que la tira ofrece: el corriente más 3. */
        $hasta = $ahora->copy()->addMonthsNoOverflow(CobranzasMensualidadService::MESES_ADELANTE_DEFAULT)->format('Y-m');

        /** Mes de referencia = el más reciente de los pedidos (`$meses` ya viene ordenado). */
        $referencia = end($meses);

        return response()->json([
            'meses'         => $meses,
            'mes_corriente' => $service->mes_corriente(),
            'clientes'      => $service->tabla_mensualidades($meses),
            'rango'         => [
                'desde' => $desde,
                'hasta' => $hasta,
            ],
            // Las dos tarjetas de plata (cobrado y falta cobrar) relativas al mes más reciente de los
            // pedidos, que es el que el front toma como mes de referencia. Clave NUEVA y opcional para
            // el front: un SPA viejo la ignora (misión cobranzas-cobrado-y-falta-cobrar, 16/10/2026).
            // Si el mes de referencia pasa del último que ofrece la tira (solo se llega por la API a
            // mano) va null: el recorrido de «falta cobrar» crece con los meses hasta ese mes, y un
            // 9999-12 era cuestión de minutos y un error 500. El SPA ya tolera `resumen: null`.
            'resumen'       => $referencia <= $hasta ? $service->resumen_cobranza($referencia) : null,
        ]);
    }

    /**
     * La tabla de Licencias: todos los clientes con sus cuotas y el resumen por moneda.
     *
     * Van TODOS los clientes, también los que no tienen cuotas: el front decide si los muestra
     * (checkbox "Mostrar clientes sin cuotas"). `rango` es el tramo de meses que cubren las cuotas
     * de todos, para armar una columna por mes; sin cuotas en ninguna parte, el mes corriente.
     *
     * @param  LicenciaCuotaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function licencias_json(LicenciaCuotaService $service)
    {
        $clientes = Client::query()->orderBy('id')->get();

        /** Todas las cuotas de todos los clientes en una consulta, agrupadas por cliente. */
        $cuotas_por_cliente = LicenciaCuota::query()
            ->orderBy('numero')->orderBy('id')
            ->get()
            ->groupBy('client_id');

        $filas = [];
        $periodo_minimo = null;
        $periodo_maximo = null;

        foreach ($clientes as $client) {
            /** @var \Illuminate\Support\Collection $cuotas */
            $cuotas = $cuotas_por_cliente->get($client->id, collect());

            $filas[] = [
                'id'                 => $client->id,
                'nombre'             => $client->resolve_display_name(),
                'name'               => $client->name,
                'company_name'       => $client->company_name,
                'is_active'          => (bool) $client->is_active,
                'mensualidad_inicio' => $client->mensualidad_inicio ? $client->mensualidad_inicio->toDateString() : null,
                'cuotas'             => $cuotas->map(function (LicenciaCuota $cuota) use ($service) {
                    return $service->cuota_para_json($cuota);
                })->values()->all(),
                'resumen'            => $service->resumen_de($cuotas),
            ];

            foreach ($cuotas as $cuota) {
                if ($periodo_minimo === null || $cuota->periodo < $periodo_minimo) {
                    $periodo_minimo = $cuota->periodo;
                }
                if ($periodo_maximo === null || $cuota->periodo > $periodo_maximo) {
                    $periodo_maximo = $cuota->periodo;
                }
            }
        }

        $mes_corriente = AppTime::now()->format('Y-m');

        return response()->json([
            'clientes'      => $filas,
            'mes_corriente' => $mes_corriente,
            'rango'         => [
                'desde' => $periodo_minimo ?? $mes_corriente,
                'hasta' => $periodo_maximo ?? $mes_corriente,
            ],
        ]);
    }

    /**
     * La tabla de Ventas (misión ventas-y-comisiones, 28/10/2026): un renglón por cliente CON
     * ventas, cada uno con sus ventas (cuotas, lo que debe el cliente y la comisión del vendedor) y
     * el resumen por moneda.
     *
     * A diferencia de Licencias, acá van solo los clientes que tienen al menos una venta: la tabla
     * es de lo vendido, no del padrón. Todo sale en tres consultas (ventas con cuotas, pagos de
     * comisión, vendedor y cliente), sin una por fila.
     *
     * `resumen_por_moneda` de arriba (clave agregada al contrato) son las cifras de la cabecera:
     * lo que deben todos los clientes y lo que hay que pagarles a los vendedores, por moneda.
     *
     * @param  VentaService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function ventas_json(VentaService $service)
    {
        $ventas = Venta::query()
            ->with(['cuotas', 'comision_pagos', 'vendedor', 'client'])
            ->orderBy('client_id')->orderBy('fecha')->orderBy('id')
            ->get();

        $filas = [];

        foreach ($ventas->groupBy('client_id') as $client_id => $del_cliente) {
            $client = $del_cliente->first()->client;

            $filas[] = [
                'client_id'          => (int) $client_id,
                'nombre'             => $client ? $client->resolve_display_name() : null,
                'is_active'          => $client ? (bool) $client->is_active : null,
                'ventas'             => $del_cliente->map(function (Venta $venta) use ($service) {
                    return $service->venta_para_json($venta);
                })->values()->all(),
                'resumen_por_moneda' => $service->resumen_por_moneda($del_cliente),
            ];
        }

        return response()->json([
            'clientes'           => $filas,
            'vendedores'         => $service->vendedores(),
            'productos'          => Venta::PRODUCTOS,
            'resumen_por_moneda' => $service->resumen_por_moneda($ventas),
        ]);
    }

    /**
     * Las preferencias del admin autenticado para la tabla de Mensualidades. Sin nada guardado:
     * el mes corriente y orden de carga.
     *
     * @param  Request                     $request
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function preferencias_json(Request $request, CobranzasMensualidadService $service)
    {
        $admin = $request->user();

        return response()->json($this->preferencias_normalizadas(
            $admin ? $admin->cobranzas_preferencias : null,
            $service->mes_corriente()
        ));
    }

    /**
     * Guarda las preferencias del admin autenticado: los meses seleccionados (1 a 24, 'YYYY-MM')
     * y el orden de Mensualidades, y/o la selección de meses de Licencias. Se persiste en
     * `admins.cobranzas_preferencias`, así cada admin tiene la suya.
     *
     * Aditivo y con semántica de MERGE (misión cobranzas-mejoras, 18/9/2026, pedido 4):
     * Mensualidades.vue manda `{meses, orden}` y Licencias.vue manda `{licencias_meses}`, cada una
     * sin la clave de la otra — por eso todos los campos son `sometimes` (una request que solo
     * manda una de las dos no tiene por qué mandar la otra) y la escritura parte de lo ya guardado
     * en vez de reemplazarlo entero. Sin este cuidado, guardar una selección borraría la de la
     * otra vista cada vez que alguien toca un mes.
     *
     * @param  Request                     $request  {meses?: [...], orden?, licencias_meses?: [...]}
     * @param  CobranzasMensualidadService $service
     * @return \Illuminate\Http\JsonResponse
     */
    public function guardar_preferencias_json(Request $request, CobranzasMensualidadService $service)
    {
        $validated = $request->validate([
            'meses'             => ['sometimes', 'array', 'min:1', 'max:' . self::MAXIMO_MESES],
            'meses.*'           => ['sometimes', 'string', self::REGLA_PERIODO],
            'orden'             => ['sometimes', 'string', 'in:' . implode(',', self::ORDENES)],
            'licencias_meses'   => ['sometimes', 'array', 'min:1', 'max:' . self::MAXIMO_MESES],
            'licencias_meses.*' => ['sometimes', 'string', self::REGLA_PERIODO],
        ]);

        $admin = $request->user();

        // Se parte de lo ya guardado y se pisa SOLO lo que vino validado en este request.
        $preferencias = is_array($admin->cobranzas_preferencias) ? $admin->cobranzas_preferencias : [];

        if (array_key_exists('meses', $validated)) {
            /** Sin duplicados y ordenados: la tira se dibuja siempre igual sin importar cómo se clickeó. */
            $meses = array_values(array_unique($validated['meses']));
            sort($meses);
            $preferencias['meses'] = $meses;
        }

        if (array_key_exists('orden', $validated)) {
            $preferencias['orden'] = $validated['orden'];
        }

        if (array_key_exists('licencias_meses', $validated)) {
            $licencias_meses = array_values(array_unique($validated['licencias_meses']));
            sort($licencias_meses);
            $preferencias['licencias_meses'] = $licencias_meses;
        }

        $admin->cobranzas_preferencias = $preferencias;
        $admin->save();

        return response()->json($this->preferencias_normalizadas($admin->cobranzas_preferencias, $service->mes_corriente()));
    }

    /**
     * Parsea `?meses=2026-09,2026-08` a una lista validada, o null si no sirve.
     *
     * @param mixed $crudo
     *
     * @return array<int, string>|null
     */
    private function meses_desde_query($crudo): ?array
    {
        if (is_array($crudo)) {
            $partes = $crudo;
        } else {
            $partes = explode(',', (string) $crudo);
        }

        $meses = [];
        foreach ($partes as $parte) {
            $parte = trim((string) $parte);
            if ($parte === '') {
                continue;
            }
            if (! CobranzasMensualidadService::es_periodo_valido($parte)) {
                return null;
            }
            $meses[] = $parte;
        }

        $meses = array_values(array_unique($meses));
        sort($meses);

        if (count($meses) < 1 || count($meses) > self::MAXIMO_MESES) {
            return null;
        }

        return $meses;
    }

    /**
     * Las preferencias con defaults aplicados y basura descartada: meses que no son 'YYYY-MM' se
     * ignoran, y si no queda ninguno se usa el corriente; un orden desconocido cae a 'carga'.
     * `licencias_meses` (misión cobranzas-mejoras, 18/9/2026) se sanea con la misma lógica que
     * `meses`, pero es independiente: Licencias no tiene "orden".
     *
     * @param mixed  $guardadas    Lo que hay en `admins.cobranzas_preferencias` (array o null).
     * @param string $mes_corriente
     *
     * @return array{meses: array<int, string>, orden: string, licencias_meses: array<int, string>}
     */
    private function preferencias_normalizadas($guardadas, string $mes_corriente): array
    {
        $meses = $this->meses_saneados($guardadas, 'meses', $mes_corriente);
        $licencias_meses = $this->meses_saneados($guardadas, 'licencias_meses', $mes_corriente);

        $orden = is_array($guardadas) && isset($guardadas['orden']) && in_array($guardadas['orden'], self::ORDENES, true)
            ? $guardadas['orden']
            : 'carga';

        return [
            'meses'           => $meses,
            'orden'           => $orden,
            'licencias_meses' => $licencias_meses,
        ];
    }

    /**
     * Saca de `$guardadas[$clave]` los 'YYYY-MM' válidos, sin duplicados y ordenados; si no queda
     * ninguno, el mes corriente. Extraído para que `meses` y `licencias_meses` compartan
     * exactamente el mismo saneo dentro de `preferencias_normalizadas()`.
     *
     * @param mixed  $guardadas
     * @param string $clave         'meses' o 'licencias_meses'.
     * @param string $mes_corriente
     *
     * @return array<int, string>
     */
    private function meses_saneados($guardadas, string $clave, string $mes_corriente): array
    {
        $meses = [];
        if (is_array($guardadas) && isset($guardadas[$clave]) && is_array($guardadas[$clave])) {
            foreach ($guardadas[$clave] as $mes) {
                if (CobranzasMensualidadService::es_periodo_valido($mes)) {
                    $meses[] = $mes;
                }
            }
        }
        $meses = array_values(array_unique($meses));
        sort($meses);

        return count($meses) === 0 ? [$mes_corriente] : $meses;
    }
}
