<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\LicenciaCuota;
use App\Models\MensualidadPago;
use App\Models\MensualidadPeriodo;
use App\Services\ClientMensualidadService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa al admin el historial de MENSUALIDADES y LICENCIAS del Excel `ComercioCity
 * administracion.xlsx` (misión modulo-cobranzas, 18/9/2026).
 *
 * No lee el Excel: lee el JSON que generó `generar_historial.py` en el repo de conocimiento
 * (`_cruzado/misiones/20260918-modulo-cobranzas/`) y que viaja en el repo como
 * `database/seeders/data/cobranzas_historial.json`. Ese JSON ya trae las decisiones de Lucas
 * aplicadas (qué clientes se crean, cuáles quedan afuera, qué color es qué estado), así que acá no
 * hay interpretación: solo escritura.
 *
 * Por cliente:
 *   - Se ubica por `client_id`; si no existe, aviso y se sigue. Con `crear`, se busca por
 *     `company_name` (insensible a mayúsculas) o por `slug` = clave, y si no está se crea SIN
 *     sistema instalado (sin ClientApi, sin bloque de user_id): Kas, Jorge Bello y Scrap Free.
 *   - Datos fiscales, precios, empleados, ecommerce y mes de inicio: solo donde el admin no tiene
 *     nada cargado. Lo que Lucas cargó a mano en el admin gana a la planilla.
 *   - Meses: `firstOrCreate` por (cliente, mes) con `importado` = 1. Los PAGADO van sin monto
 *     (decisión de Lucas). Un mes parcial con `faltan` deja el esperado y un pago importado por la
 *     diferencia, para que el saldo de la pantalla sea el de la planilla.
 *   - Cuotas de licencia: solo si el cliente no tiene ninguna importada. Una por elemento.
 *   - Observaciones sueltas: se agregan a `cobranzas_observaciones`, una por línea, si no están.
 *
 * Es idempotente: correrlo dos veces no duplica nada (cada escritura mira antes si ya está). Con
 * `--simular` recorre todo y muestra el resumen, pero no escribe: se hace dentro de una
 * transacción que se revierte al final, así el resumen es exactamente el de la corrida real.
 *
 * Corre en producción por el deploy (`pendientes.json` de /deploy-admin), no a mano.
 */
class CobranzasImportarPlanillaCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cobranzas:importar-planilla
        {--archivo=database/seeders/data/cobranzas_historial.json : Ruta del JSON (relativa a la raíz del proyecto o absoluta)}
        {--simular : Recorre y muestra el resumen sin escribir nada}';

    /**
     * @var string
     */
    protected $description = 'Importa el historial de mensualidades y licencias de la planilla de administración (JSON generado por el orquestador). Idempotente; --simular no escribe.';

    /**
     * Contadores para el resumen final.
     *
     * @var array<string, int>
     */
    private $conteo = [];

    /**
     * Avisos para el resumen final (clientes no encontrados, filas raras).
     *
     * @var array<int, string>
     */
    private $avisos = [];

    /**
     * @var ClientMensualidadService
     */
    private $mensualidad_service;

    /**
     * @param ClientMensualidadService $mensualidad_service
     *
     * @return int
     */
    public function handle(ClientMensualidadService $mensualidad_service): int
    {
        $this->mensualidad_service = $mensualidad_service;
        $this->conteo = [
            'clientes_procesados'  => 0,
            'clientes_creados'     => 0,
            'clientes_actualizados' => 0,
            'periodos_creados'     => 0,
            'periodos_existentes'  => 0,
            'pagos_creados'        => 0,
            'cuotas_creadas'       => 0,
            'cuotas_ya_importadas' => 0,
            'observaciones'        => 0,
        ];
        $this->avisos = [];

        $simular = (bool) $this->option('simular');

        $datos = $this->leer_json((string) $this->option('archivo'));
        if ($datos === null) {
            return 1;
        }

        $clientes = is_array($datos['clientes'] ?? null) ? $datos['clientes'] : [];

        $this->info(($simular ? '[SIMULACIÓN] ' : '') . 'Importando ' . count($clientes) . ' clientes de la planilla...');

        /* Todo dentro de una transacción: una corrida real es atómica (o entra toda o no entra
         * nada) y una simulación se revierte al final, después de haber contado exactamente lo que
         * habría escrito. Los ids consumidos por el autoincrement en una simulación no importan. */
        DB::beginTransaction();

        try {
            foreach ($clientes as $indice => $fila) {
                if (! is_array($fila)) {
                    $this->avisos[] = 'Elemento ' . $indice . ' de la planilla no es un objeto, se saltea.';
                    continue;
                }

                $this->importar_cliente($fila);
            }

            // Los avisos del propio generador (los clientes que Lucas dejó afuera) se muestran también.
            foreach ((array) ($datos['avisos'] ?? []) as $aviso) {
                $this->avisos[] = 'Planilla: ' . (string) $aviso;
            }

            if ($simular) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $error) {
            DB::rollBack();

            $this->error('La importación falló y no se escribió nada: ' . $error->getMessage());
            $this->line($error->getTraceAsString());

            return 1;
        }

        $this->mostrar_resumen($simular);

        return 0;
    }

    /**
     * Lee y decodifica el JSON. Ruta absoluta tal cual; relativa, contra la raíz del proyecto.
     *
     * @param string $ruta
     *
     * @return array|null
     */
    private function leer_json(string $ruta): ?array
    {
        $ruta_real = is_file($ruta) ? $ruta : base_path($ruta);

        if (! is_file($ruta_real)) {
            $this->error('No existe el archivo ' . $ruta_real);

            return null;
        }

        $datos = json_decode((string) file_get_contents($ruta_real), true);

        if (! is_array($datos) || ! isset($datos['clientes'])) {
            $this->error('El archivo ' . $ruta_real . ' no es el JSON de la planilla (falta la clave "clientes").');

            return null;
        }

        return $datos;
    }

    /**
     * Importa todo lo de un cliente de la planilla.
     *
     * @param array<string, mixed> $fila
     *
     * @return void
     */
    private function importar_cliente(array $fila): void
    {
        $nombre_planilla = (string) ($fila['planilla'] ?? '(sin nombre)');

        $client = $this->resolver_cliente($fila, $nombre_planilla);
        if ($client === null) {
            return;
        }

        $this->conteo['clientes_procesados']++;

        $cambio_cliente = false;
        $cambio_cliente = $this->completar_datos_fiscales($client, $fila) || $cambio_cliente;
        $cambio_cliente = $this->completar_precios($client, $fila) || $cambio_cliente;
        $cambio_cliente = $this->completar_inicio($client, $fila) || $cambio_cliente;
        $cambio_cliente = $this->agregar_observaciones($client, (array) ($fila['observaciones'] ?? [])) || $cambio_cliente;

        if ($cambio_cliente) {
            $client->save();
            $this->conteo['clientes_actualizados']++;
        }

        $this->importar_periodos($client, (array) ($fila['mensualidad_periodos'] ?? []), $fila);
        $this->importar_cuotas($client, (array) ($fila['licencia_cuotas'] ?? []), $nombre_planilla);
    }

    /**
     * Encuentra (o crea) el Client de una fila de la planilla.
     *
     * Con `client_id`, tiene que existir: si no, aviso y null (ese cliente se borró del admin o el
     * generador se equivocó, y ninguna de las dos se resuelve inventando un cliente). Con `crear`,
     * se busca primero por `company_name` y después por `slug` = clave, para que una segunda
     * corrida lo encuentre aunque Lucas le haya cambiado el nombre.
     *
     * @param array<string, mixed> $fila
     * @param string               $nombre_planilla
     *
     * @return Client|null
     */
    private function resolver_cliente(array $fila, string $nombre_planilla): ?Client
    {
        $client_id = $fila['client_id'] ?? null;

        if ($client_id !== null && $client_id !== '') {
            $client = Client::find((int) $client_id);

            if ($client === null) {
                $this->avisos[] = 'Cliente #' . $client_id . ' ("' . $nombre_planilla . '") no existe en el admin; se saltea.';
            }

            return $client;
        }

        $crear = $fila['crear'] ?? null;
        if (! is_array($crear)) {
            $this->avisos[] = '"' . $nombre_planilla . '" no tiene client_id ni instrucción de crear; se saltea.';

            return null;
        }

        $company_name = trim((string) ($crear['company_name'] ?? $nombre_planilla));
        $clave = trim((string) ($crear['clave'] ?? ''));
        $slug_base = Str::slug($clave !== '' ? $clave : $company_name);

        /** Ya existe: por razón social (insensible a mayúsculas) o por el slug con el que lo creamos. */
        $client = Client::query()
            ->whereRaw('LOWER(company_name) = ?', [mb_strtolower($company_name)])
            ->orderBy('id')
            ->first();

        if ($client === null && $slug_base !== '') {
            $client = Client::where('slug', $slug_base)->first();
        }

        if ($client !== null) {
            return $client;
        }

        /* Cliente nuevo SIN sistema: sin ClientApi ni bloque de user_id (no usan ComercioCity, o no
         * todavía; Scrap Free ni siquiera lo va a usar). `slug` único y las dos claves aleatorias,
         * como hace RunUserSetupService, para que si algún día se instala no haya que tocar nada. */
        $client = Client::create([
            'name'                    => trim((string) ($crear['name'] ?? $company_name)) ?: $company_name,
            'company_name'            => $company_name,
            'slug'                    => $this->slug_unico($slug_base),
            'api_key'                 => Str::random(40),
            'inbound_api_key'         => Str::random(40),
            'is_active'               => true,
            'cobranzas_observaciones' => isset($crear['nota']) && trim((string) $crear['nota']) !== '' ? trim((string) $crear['nota']) : null,
        ]);

        $this->conteo['clientes_creados']++;
        $this->line('  + Cliente creado: ' . $company_name . ' (#' . $client->id . ')');

        return $client;
    }

    /**
     * Mismo criterio que `RunUserSetupService::unique_client_slug()`: base, base-2, base-3...
     *
     * @param string $base
     *
     * @return string
     */
    private function slug_unico(string $base): string
    {
        if ($base === '') {
            $base = 'cliente';
        }

        $slug = $base;
        $i = 2;

        while (Client::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    /**
     * CUIT, razón social y condición IVA: solo donde el admin no tiene nada.
     *
     * @param Client               $client
     * @param array<string, mixed> $fila
     *
     * @return bool Si cambió algo.
     */
    private function completar_datos_fiscales(Client $client, array $fila): bool
    {
        $cambio = false;

        foreach (['afip_cuit', 'afip_razon_social', 'afip_condicion_iva'] as $campo) {
            $valor = isset($fila[$campo]) ? trim((string) $fila[$campo]) : '';

            if ($valor === '' || trim((string) $client->{$campo}) !== '') {
                continue;
            }

            $client->{$campo} = $valor;
            $cambio = true;
        }

        return $cambio;
    }

    /**
     * Precios, empleados y ecommerce desde la planilla, solo para lo que el admin tiene vacío:
     *
     *   - `precio_plan` y `precio_por_cuenta` vacíos/0 y hay `monto_mensual_planilla`: el monto va
     *     entero a `precio_plan` (todo incluido) y `precio_por_cuenta` queda en 0. Es para que el
     *     módulo tenga un monto esperado; los que ya tienen precios no se tocan.
     *   - `cantidad_empleados` de la planilla si el admin tiene 0.
     *   - `tiene_ecommerce` en true si la planilla lo dice y el admin lo tiene apagado.
     *
     * Si cambió algo (o si el cliente tiene precios pero nunca se le calculó el total), se recalcula
     * `total_mensualidad` con la fórmula de siempre.
     *
     * @param Client               $client
     * @param array<string, mixed> $fila
     *
     * @return bool Si cambió algo.
     */
    private function completar_precios(Client $client, array $fila): bool
    {
        $cambio = false;

        $sin_precios = (float) ($client->precio_plan ?? 0) == 0.0 && (float) ($client->precio_por_cuenta ?? 0) == 0.0;
        $monto_planilla = isset($fila['monto_mensual_planilla']) ? (float) $fila['monto_mensual_planilla'] : 0.0;

        if ($sin_precios && $monto_planilla > 0) {
            $client->precio_plan = round($monto_planilla, 2);
            $client->precio_por_cuenta = 0;
            $cambio = true;
        }

        $empleados_planilla = (int) ($fila['cantidad_empleados_planilla'] ?? 0);
        if ($empleados_planilla > 0 && (int) ($client->cantidad_empleados ?? 0) === 0) {
            $client->cantidad_empleados = $empleados_planilla;
            $cambio = true;
        }

        if (! empty($fila['tiene_ecommerce_planilla']) && ! $client->tiene_ecommerce) {
            $client->tiene_ecommerce = true;
            $cambio = true;
        }

        $tiene_precios = (float) ($client->precio_plan ?? 0) > 0 || (float) ($client->precio_por_cuenta ?? 0) > 0;

        if ($cambio || ($tiene_precios && $client->total_mensualidad === null)) {
            $client->total_mensualidad = $this->mensualidad_service->calcular_total([
                'precio_plan'          => $client->precio_plan,
                'precio_por_cuenta'    => $client->precio_por_cuenta,
                'cantidad_empleados'   => $client->cantidad_empleados,
                'tiene_ecommerce'      => $client->tiene_ecommerce,
                'tiene_mercado_libre'  => $client->tiene_mercado_libre,
                'tiene_tienda_nube'    => $client->tiene_tienda_nube,
                'precio_ecommerce'     => $client->precio_ecommerce,
                'precio_mercado_libre' => $client->precio_mercado_libre,
                'precio_tienda_nube'   => $client->precio_tienda_nube,
            ]);
            $cambio = true;
        }

        return $cambio;
    }

    /**
     * El primer mes que se cobra, solo si el admin no lo tiene.
     *
     * @param Client               $client
     * @param array<string, mixed> $fila
     *
     * @return bool Si cambió algo.
     */
    private function completar_inicio(Client $client, array $fila): bool
    {
        $inicio = isset($fila['mensualidad_inicio']) ? trim((string) $fila['mensualidad_inicio']) : '';

        if ($inicio === '' || $client->mensualidad_inicio !== null) {
            return false;
        }

        try {
            $client->mensualidad_inicio = Carbon::parse($inicio)->startOfMonth()->toDateString();
        } catch (\Throwable $error) {
            $this->avisos[] = 'Cliente #' . $client->id . ': mensualidad_inicio ilegible ("' . $inicio . '"), se ignora.';

            return false;
        }

        return true;
    }

    /**
     * Agrega las notas sueltas a `cobranzas_observaciones`, una por línea, si el texto no está ya.
     *
     * @param Client              $client
     * @param array<int, mixed>   $observaciones
     *
     * @return bool Si cambió algo.
     */
    private function agregar_observaciones(Client $client, array $observaciones): bool
    {
        $actual = (string) ($client->cobranzas_observaciones ?? '');
        $cambio = false;

        foreach ($observaciones as $observacion) {
            $texto = trim((string) $observacion);

            if ($texto === '' || strpos($actual, $texto) !== false) {
                continue;
            }

            $actual = $actual === '' ? $texto : $actual . "\n" . $texto;
            $cambio = true;
            $this->conteo['observaciones']++;
        }

        if ($cambio) {
            $client->cobranzas_observaciones = $actual;
        }

        return $cambio;
    }

    /**
     * Los meses de la planilla: `firstOrCreate` por (cliente, mes). Un mes parcial con `faltan`
     * deja el esperado (el monto mensual de la planilla) y un pago importado por la diferencia,
     * solo la primera vez que se crea.
     *
     * @param Client               $client
     * @param array<int, mixed>    $periodos
     * @param array<string, mixed> $fila
     *
     * @return void
     */
    private function importar_periodos(Client $client, array $periodos, array $fila): void
    {
        $monto_planilla = isset($fila['monto_mensual_planilla']) ? (float) $fila['monto_mensual_planilla'] : 0.0;

        foreach ($periodos as $periodo_fila) {
            if (! is_array($periodo_fila) || empty($periodo_fila['periodo'])) {
                continue;
            }

            $periodo = (string) $periodo_fila['periodo'];
            $estado = (string) ($periodo_fila['estado'] ?? MensualidadPeriodo::ESTADO_PENDIENTE);

            if (! in_array($estado, MensualidadPeriodo::ESTADOS, true)) {
                $this->avisos[] = 'Cliente #' . $client->id . ' ' . $periodo . ': estado "' . $estado . '" desconocido, se saltea.';
                continue;
            }

            $existente = MensualidadPeriodo::where('client_id', $client->id)->where('periodo', $periodo)->first();
            if ($existente !== null) {
                $this->conteo['periodos_existentes']++;
                continue;
            }

            $faltan = isset($periodo_fila['faltan']) && $periodo_fila['faltan'] !== null ? (float) $periodo_fila['faltan'] : null;
            $es_parcial_con_saldo = $estado === MensualidadPeriodo::ESTADO_PARCIAL && $faltan !== null && $monto_planilla > 0;

            MensualidadPeriodo::create([
                'client_id'      => $client->id,
                'periodo'        => $periodo,
                'estado'         => $estado,
                // Solo el parcial lleva esperado: los PAGADO van sin monto (decisión de Lucas).
                'monto_esperado' => $es_parcial_con_saldo ? round($monto_planilla, 2) : null,
                'observacion'    => isset($periodo_fila['observacion']) && trim((string) $periodo_fila['observacion']) !== ''
                    ? trim((string) $periodo_fila['observacion'])
                    : null,
                'importado'      => true,
            ]);
            $this->conteo['periodos_creados']++;

            // Lo que entró = esperado − lo que falta, para que el saldo de la pantalla sea el de la planilla.
            if ($es_parcial_con_saldo) {
                MensualidadPago::create([
                    'client_id'   => $client->id,
                    'periodo'     => $periodo,
                    'monto'       => round(max(0.0, $monto_planilla - $faltan), 2),
                    'fecha_pago'  => null,
                    'medio'       => null,
                    'observacion' => isset($periodo_fila['observacion']) ? trim((string) $periodo_fila['observacion']) : null,
                    'admin_id'    => null,
                    'importado'   => true,
                ]);
                $this->conteo['pagos_creados']++;
            }
        }
    }

    /**
     * Las cuotas de licencia, solo si el cliente no tiene ninguna importada. Una por elemento,
     * con `vencimiento` = primer día del mes.
     *
     * @param Client            $client
     * @param array<int, mixed> $cuotas
     * @param string            $nombre_planilla
     *
     * @return void
     */
    private function importar_cuotas(Client $client, array $cuotas, string $nombre_planilla): void
    {
        if (count($cuotas) === 0) {
            return;
        }

        if (LicenciaCuota::where('client_id', $client->id)->where('importado', true)->exists()) {
            $this->conteo['cuotas_ya_importadas']++;

            return;
        }

        foreach ($cuotas as $indice => $cuota) {
            if (! is_array($cuota) || empty($cuota['periodo'])) {
                continue;
            }

            $estado = (string) ($cuota['estado'] ?? LicenciaCuota::ESTADO_PENDIENTE);
            if (! in_array($estado, LicenciaCuota::ESTADOS, true)) {
                $this->avisos[] = '"' . $nombre_planilla . '" cuota ' . ($indice + 1) . ': estado "' . $estado . '" desconocido, se saltea.';
                continue;
            }

            $moneda = strtoupper(trim((string) ($cuota['moneda'] ?? 'USD')));

            LicenciaCuota::create([
                'client_id'    => $client->id,
                'numero'       => (int) ($cuota['numero'] ?? ($indice + 1)),
                'periodo'      => (string) $cuota['periodo'],
                'vencimiento'  => Carbon::createFromFormat('Y-m-d', (string) $cuota['periodo'] . '-01')->toDateString(),
                'monto'        => round((float) ($cuota['monto'] ?? 0), 2),
                'moneda'       => in_array($moneda, LicenciaCuota::MONEDAS, true) ? $moneda : 'USD',
                'estado'       => $estado,
                'monto_pagado' => null,
                'fecha_pago'   => null,
                'observacion'  => isset($cuota['observacion']) && trim((string) $cuota['observacion']) !== ''
                    ? trim((string) $cuota['observacion'])
                    : null,
                'importado'    => true,
            ]);
            $this->conteo['cuotas_creadas']++;
        }
    }

    /**
     * El resumen por consola.
     *
     * @param bool $simular
     *
     * @return void
     */
    private function mostrar_resumen(bool $simular): void
    {
        $this->newLine();
        $this->info($simular ? 'Resumen de la SIMULACIÓN (no se escribió nada):' : 'Resumen de la importación:');

        $this->table(['Qué', 'Cantidad'], [
            ['Clientes procesados', $this->conteo['clientes_procesados']],
            ['Clientes creados', $this->conteo['clientes_creados']],
            ['Clientes con datos completados', $this->conteo['clientes_actualizados']],
            ['Meses creados', $this->conteo['periodos_creados']],
            ['Meses que ya existían', $this->conteo['periodos_existentes']],
            ['Pagos parciales importados', $this->conteo['pagos_creados']],
            ['Cuotas de licencia creadas', $this->conteo['cuotas_creadas']],
            ['Clientes con cuotas ya importadas', $this->conteo['cuotas_ya_importadas']],
            ['Observaciones agregadas', $this->conteo['observaciones']],
        ]);

        if (count($this->avisos) > 0) {
            $this->warn('Avisos:');
            foreach ($this->avisos as $aviso) {
                $this->line('  - ' . $aviso);
            }
        }
    }
}
