<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Helpers\WhatsappNormalizer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\MensualidadActualizacion;
use App\Models\MensualidadInvoice;
use App\Models\MensualidadPago;
use App\Models\MensualidadPeriodo;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El seguimiento de la mensualidad mes a mes: el Excel de Lucas, pero calculado (misión
 * modulo-cobranzas, 18/9/2026).
 *
 * La idea central es que **el estado de un mes se DEDUCE, no se guarda**, salvo cuando alguien
 * afirmó algo de ese mes. `estado_de()` junta las cuatro fuentes —la fila afirmada
 * (`mensualidad_periodos`), la Factura C autorizada (`mensualidad_invoices`), el mes de inicio del
 * cliente y el mes corriente— y devuelve uno de siete estados:
 *
 *   sin_cargo  — alguien dijo que ese mes no se cobra.
 *   pagado     — alguien dijo que está cobrado (con pagos registrados o a mano).
 *   parcial    — entró una parte; hay saldo.
 *   facturado  — hay Factura C autorizada por AFIP y nadie registró el pago todavía.
 *   no_aplica  — el cliente todavía no arrancaba ese mes (o no arrancó nunca).
 *   futuro     — el mes todavía no llegó.
 *   pendiente  — le tocaba, no hay factura y no hay pago. El rojo de la tabla.
 *
 * El orden importa y es exactamente ese: lo afirmado gana a lo deducido (un mes marcado
 * `sin_cargo` es sin cargo aunque haya factura), y dentro de lo deducido la factura gana al
 * calendario (un mes futuro facturado por adelantado es `facturado`, no `futuro`).
 *
 * Los pagos son filas aparte (`mensualidad_pagos`) porque un mes se puede pagar en dos veces; el
 * estado afirmado del mes se recalcula desde la suma de sus pagos cada vez que se agrega o borra
 * uno (`recalcular_por_pagos()`).
 *
 * La otra mitad del servicio son las **actualizaciones de precio**: el historial de los cinco
 * precios con la marca de cuál fue la última OFICIAL (la de IPC), para saber hace cuánto no se
 * actualiza cada cliente y cuándo vence la próxima según los meses del contrato.
 *
 * Todo lo que compara meses lo hace sobre strings 'YYYY-MM', que ordenan bien tal cual. El mes
 * corriente sale de `AppTime::now()` para que los tests puedan fijar la fecha con
 * `Carbon::setTestNow()` y para respetar el reloj virtual del entorno local.
 */
class CobranzasMensualidadService
{
    /** Hay Factura C autorizada y nadie registró el pago. Deducido, nunca guardado. */
    const ESTADO_FACTURADO = 'facturado';

    /** El cliente todavía no arrancaba ese mes. Deducido, nunca guardado. */
    const ESTADO_NO_APLICA = 'no_aplica';

    /** El mes todavía no llegó. Deducido, nunca guardado. */
    const ESTADO_FUTURO = 'futuro';

    /** Cuántos meses hacia atrás se listan cuando el cliente no tiene `mensualidad_inicio`. */
    const MESES_ATRAS_DEFAULT = 12;

    /** Cuántos meses hacia adelante del corriente se listan por defecto. */
    const MESES_ADELANTE_DEFAULT = 3;

    /**
     * @var ClientMensualidadService
     */
    protected $mensualidad_service;

    /**
     * @var ClientMensualidadSyncService
     */
    protected $sync_service;

    /**
     * @param ClientMensualidadService     $mensualidad_service Aplica los precios y calcula el total.
     * @param ClientMensualidadSyncService $sync_service        Trae los conteos vivos del empresa-api.
     */
    public function __construct(ClientMensualidadService $mensualidad_service, ClientMensualidadSyncService $sync_service)
    {
        $this->mensualidad_service = $mensualidad_service;
        $this->sync_service = $sync_service;
    }

    /* ------------------------------------------------------------------ *
     |  Meses
     * ------------------------------------------------------------------ */

    /**
     * El mes corriente como 'YYYY-MM', desde el reloj de la app.
     *
     * @return string
     */
    public function mes_corriente(): string
    {
        return AppTime::now()->format('Y-m');
    }

    /**
     * El primer día de un mes 'YYYY-MM' como Carbon. Se construye con el día explícito y no con
     * `createFromFormat('Y-m')`, que toma el día de hoy y un 31 desborda al mes siguiente.
     *
     * @param string $periodo
     *
     * @return Carbon
     */
    public function primer_dia(string $periodo): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $periodo . '-01')->startOfDay();
    }

    /**
     * ¿Es un 'YYYY-MM' válido?
     *
     * @param mixed $periodo
     *
     * @return bool
     */
    public static function es_periodo_valido($periodo): bool
    {
        return is_string($periodo) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) === 1;
    }

    /**
     * Todos los meses entre dos 'YYYY-MM' inclusive, en orden.
     *
     * @param string $desde
     * @param string $hasta
     *
     * @return array<int, string>
     */
    public function meses_entre(string $desde, string $hasta): array
    {
        if ($desde > $hasta) {
            return [];
        }

        $meses = [];
        $cursor = $this->primer_dia($desde);
        $fin = $this->primer_dia($hasta);

        while ($cursor->lte($fin)) {
            $meses[] = $cursor->format('Y-m');
            $cursor->addMonthNoOverflow();
        }

        return $meses;
    }

    /**
     * El mes de inicio del cliente como 'YYYY-MM', o null si todavía no arrancó.
     *
     * @param Client $client
     *
     * @return string|null
     */
    public function mes_de_inicio(Client $client): ?string
    {
        if (empty($client->mensualidad_inicio)) {
            return null;
        }

        return Carbon::parse($client->mensualidad_inicio)->format('Y-m');
    }

    /* ------------------------------------------------------------------ *
     |  El estado de un mes
     * ------------------------------------------------------------------ */

    /**
     * El estado calculado de un mes de un cliente, con todo lo que la pantalla necesita para esa
     * celda. Ver el docblock de la clase para el orden de las reglas.
     *
     * Recibe la fila, la factura y los pagos ya cargados (en vez de consultarlos) para que la tabla
     * de todos los clientes pueda armarse con tres consultas en total y no con tres por celda.
     *
     * @param Client                  $client
     * @param string                  $periodo 'YYYY-MM'.
     * @param MensualidadPeriodo|null $fila    Lo afirmado de ese mes, si hay fila.
     * @param MensualidadInvoice|null $factura La Factura C AUTORIZADA de ese mes, si hay.
     * @param array<int, MensualidadPago> $pagos Los pagos imputados a ese mes.
     *
     * @return array<string, mixed>
     */
    public function estado_de(Client $client, string $periodo, ?MensualidadPeriodo $fila, ?MensualidadInvoice $factura, array $pagos): array
    {
        /** Lo que se esperaba cobrar: lo que dice la fila o, si no dice, el total actual del cliente. */
        $monto_esperado = $fila !== null && $fila->monto_esperado !== null
            ? (float) $fila->monto_esperado
            : ($client->total_mensualidad !== null ? (float) $client->total_mensualidad : null);

        /* Un mes que la planilla dio por pagado sin monto (Lucas, 18/9/2026: "sin monto, solo el
         * estado") no tiene esperado: mostrarle el precio de HOY sería inventarle un importe a un
         * mes de hace un año, en el que el cliente pagaba otra cosa. Queda null y la pantalla
         * muestra "—". Los meses vivos (pendiente, parcial, futuro) sí caen al total actual. */
        if ($fila !== null && $fila->importado && $fila->monto_esperado === null
            && $fila->estado === MensualidadPeriodo::ESTADO_PAGADO) {
            $monto_esperado = null;
        }

        /** Lo que entró: suma de los pagos CON monto (los importados sin monto no suman). */
        $monto_pagado = 0.0;
        foreach ($pagos as $pago) {
            if ($pago->monto !== null) {
                $monto_pagado += (float) $pago->monto;
            }
        }
        $monto_pagado = round($monto_pagado, 2);

        $estado = $this->deducir_estado($client, $periodo, $fila, $factura);

        // El saldo solo tiene sentido en un mes parcial; en los demás es 0 (pagado, sin cargo, futuro...).
        $saldo = 0.0;
        if ($estado === MensualidadPeriodo::ESTADO_PARCIAL && $monto_esperado !== null) {
            $saldo = round(max(0.0, $monto_esperado - $monto_pagado), 2);
        }

        return [
            'periodo'        => $periodo,
            'estado'         => $estado,
            'monto_esperado' => $monto_esperado,
            'monto_pagado'   => $monto_pagado,
            'saldo'          => $saldo,
            'factura'        => $factura !== null ? [
                'id'          => $factura->id,
                'cbte_numero' => $factura->cbte_numero,
                'punto_venta' => $factura->punto_venta,
                'cae'         => $factura->cae,
            ] : null,
            'pagos'          => array_map(function (MensualidadPago $pago) {
                return $this->pago_para_json($pago);
            }, array_values($pagos)),
            'observacion'    => $fila !== null ? $fila->observacion : null,
            'importado'      => $fila !== null ? (bool) $fila->importado : false,
        ];
    }

    /**
     * Las siete reglas, en orden. Separado de `estado_de()` para que se lea como la tabla del
     * docblock y no como un `if` de veinte líneas.
     *
     * @param Client                  $client
     * @param string                  $periodo
     * @param MensualidadPeriodo|null $fila
     * @param MensualidadInvoice|null $factura
     *
     * @return string
     */
    protected function deducir_estado(Client $client, string $periodo, ?MensualidadPeriodo $fila, ?MensualidadInvoice $factura): string
    {
        // 1 a 3: lo afirmado gana a todo lo demás.
        if ($fila !== null && in_array($fila->estado, [
            MensualidadPeriodo::ESTADO_SIN_CARGO,
            MensualidadPeriodo::ESTADO_PAGADO,
            MensualidadPeriodo::ESTADO_PARCIAL,
        ], true)) {
            return $fila->estado;
        }

        // 4: hay Factura C autorizada por AFIP y nadie registró el pago.
        if ($factura !== null && $this->factura_autorizada($factura)) {
            return self::ESTADO_FACTURADO;
        }

        // 5: el cliente todavía no arrancaba ese mes (o no arrancó nunca).
        $inicio = $this->mes_de_inicio($client);
        if ($inicio === null || $periodo < $inicio) {
            return self::ESTADO_NO_APLICA;
        }

        // 6: el mes todavía no llegó.
        if ($periodo > $this->mes_corriente()) {
            return self::ESTADO_FUTURO;
        }

        // 7: le tocaba y no hay nada. El rojo.
        return MensualidadPeriodo::ESTADO_PENDIENTE;
    }

    /**
     * Una factura cuenta como emitida solo si AFIP la autorizó: `resultado` 'A' y CAE presente.
     * Los intentos rechazados ('R') quedan en la misma tabla y no son un comprobante.
     *
     * @param MensualidadInvoice $factura
     *
     * @return bool
     */
    protected function factura_autorizada(MensualidadInvoice $factura): bool
    {
        return $factura->resultado === 'A' && ! empty($factura->cae);
    }

    /**
     * Un pago tal como viaja al front.
     *
     * @param MensualidadPago $pago
     *
     * @return array<string, mixed>
     */
    protected function pago_para_json(MensualidadPago $pago): array
    {
        return [
            'id'          => $pago->id,
            'periodo'     => $pago->periodo,
            'monto'       => $pago->monto !== null ? (float) $pago->monto : null,
            'fecha_pago'  => $pago->fecha_pago ? Carbon::parse($pago->fecha_pago)->toDateString() : null,
            'medio'       => $pago->medio,
            'observacion' => $pago->observacion,
            'admin_id'    => $pago->admin_id,
            'importado'   => (bool) $pago->importado,
            'created_at'  => $pago->created_at ? $pago->created_at->toDateTimeString() : null,
        ];
    }

    /**
     * `estado_de()` de un mes consultando la base (para los endpoints de un solo cliente, donde
     * tres consultas por mes no le duelen a nadie).
     *
     * @param Client $client
     * @param string $periodo
     *
     * @return array<string, mixed>
     */
    public function estado_del_mes(Client $client, string $periodo): array
    {
        $fila = MensualidadPeriodo::where('client_id', $client->id)->where('periodo', $periodo)->first();
        $factura = $this->factura_autorizada_de($client->id, $periodo);
        $pagos = MensualidadPago::where('client_id', $client->id)->where('periodo', $periodo)->orderBy('id')->get()->all();

        return $this->estado_de($client, $periodo, $fila, $factura, $pagos);
    }

    /**
     * La Factura C autorizada de un (cliente, mes), si existe. Si por algún motivo hubiera más de
     * una, la más reciente.
     *
     * @param int    $client_id
     * @param string $periodo
     *
     * @return MensualidadInvoice|null
     */
    protected function factura_autorizada_de(int $client_id, string $periodo): ?MensualidadInvoice
    {
        return MensualidadInvoice::where('client_id', $client_id)
            ->where('periodo', $periodo)
            ->where('resultado', 'A')
            ->whereNotNull('cae')
            ->orderByDesc('id')
            ->first();
    }

    /* ------------------------------------------------------------------ *
     |  Pagos y estado afirmado
     * ------------------------------------------------------------------ */

    /**
     * Registra un pago de la mensualidad y recalcula el estado del mes.
     *
     * Con `cerrar_periodo` (default true, es lo que hace el checkbox "el mes queda pagado
     * completo") el mes queda `pagado` aunque el monto no llegue al esperado: es Lucas diciendo
     * "con esto estamos". Sin él, decide la suma de los pagos contra el monto esperado.
     *
     * Un click hace todo (pedido 7, misión cobranzas-mejoras, 18/9/2026): cuando el pago cierra el
     * mes completo Y el cliente ya tiene una fecha de próximo pago cargada, el mismo acto de
     * registrar el pago además adelanta `payment_expired_at` un mes y avisa al sistema del cliente
     * (ver `avanzar_vencimiento_si_corresponde()`). Un pago parcial, o un cliente sin fecha
     * cargada, no tocan el vencimiento: no hay nada que adelantar.
     *
     * 🔴 Todo lo que escribe en la base PROPIA (el pago, el período, y si corresponde el adelanto
     * del vencimiento) va atado a una sola transacción (hallazgo del chequeo independiente, misión
     * cobranzas-mejoras, 18/9/2026): si algo revienta a mitad de camino no queda un pago fantasma
     * creado con el resto a medio escribir. El llamado de red a `actualizar_en_cliente()` queda
     * AFUERA de la transacción a propósito: ya se banca sus propios fallos con try/catch y puede
     * tardar varios segundos (timeout + reintentos) — no tiene sentido mantener abierta una
     * transacción de base mientras se espera una respuesta HTTP del cliente.
     *
     * @param Client     $client
     * @param array      $datos  {periodo, monto?, fecha_pago?, medio?, observacion?, cerrar_periodo?}
     * @param Admin|null $admin  Quién lo registra.
     *
     * @return array<string, mixed> El `estado_de()` del mes ya recalculado, con una clave más:
     *                              `vencimiento_avanzado` = {ocurrio, nueva_fecha, sincronizado,
     *                              motivo_no_sincronizado, motivo}.
     */
    public function registrar_pago(Client $client, array $datos, ?Admin $admin = null): array
    {
        $periodo = (string) $datos['periodo'];

        /** Si no vino, se cierra: es el default del formulario y el caso normal (paga el mes entero). */
        $cerrar = ! array_key_exists('cerrar_periodo', $datos) || $datos['cerrar_periodo'] === null
            ? true
            : filter_var($datos['cerrar_periodo'], FILTER_VALIDATE_BOOLEAN);

        // Todo lo de acá adentro es escritura en la base propia: ver el 🔴 del docblock.
        $avance = DB::transaction(function () use ($client, $datos, $admin, $periodo, $cerrar) {
            MensualidadPago::create([
                'client_id'   => $client->id,
                'periodo'     => $periodo,
                'monto'       => isset($datos['monto']) && $datos['monto'] !== '' ? round((float) $datos['monto'], 2) : null,
                'fecha_pago'  => ! empty($datos['fecha_pago']) ? Carbon::parse($datos['fecha_pago'])->toDateString() : null,
                'medio'       => isset($datos['medio']) && $datos['medio'] !== '' ? (string) $datos['medio'] : null,
                'observacion' => isset($datos['observacion']) && $datos['observacion'] !== '' ? (string) $datos['observacion'] : null,
                'admin_id'    => $admin ? $admin->id : null,
                'importado'   => false,
            ]);

            $fila = $this->fila_del_mes($client, $periodo);

            if ($cerrar) {
                $fila->estado = MensualidadPeriodo::ESTADO_PAGADO;
                $fila->save();
            } else {
                $this->recalcular_por_pagos($fila);
            }

            return $this->avanzar_vencimiento_si_corresponde($client, $fila, $cerrar);
        });

        $vencimiento_avanzado = [
            'ocurrio'                => $avance['ocurrio'],
            'nueva_fecha'            => $avance['nueva_fecha'],
            'sincronizado'           => false,
            'motivo_no_sincronizado' => null,
            'motivo'                 => $avance['motivo'],
        ];

        /* Best-effort, YA FUERA de la transacción (misma filosofía que `traer_del_cliente()`/
         * `actualizar_en_cliente()`: se degradan solas): si el adelanto ocurrió de verdad, recién
         * ahí vale la pena avisarle al sistema del cliente. Si el cliente no soporta
         * sincronización, el pago y la fecha ya quedaron guardados en admin igual (la transacción
         * de arriba ya cerró); lo único que no pasa es el aviso, y eso se informa acá para que no
         * sea un silencio. Nunca tira excepción (el camino de red está en try/catch adentro de
         * ClientMensualidadSyncService), así que no hace falta un try/catch acá tampoco. */
        if ($avance['ocurrio']) {
            $sincronizado = $this->sync_service->actualizar_en_cliente($client);
            $vencimiento_avanzado['sincronizado'] = ! empty($sincronizado['soportado']);
            if (empty($sincronizado['soportado'])) {
                $vencimiento_avanzado['motivo_no_sincronizado'] = $sincronizado['error'] ?? null;
            }
        }

        $resultado = $this->estado_del_mes($client, $periodo);
        $resultado['vencimiento_avanzado'] = $vencimiento_avanzado;

        return $resultado;
    }

    /**
     * La mitad "de base" de un click de "Registrar pago" que cierra el mes (pedido 7, misión
     * cobranzas-mejoras, 18/9/2026): si corresponde, adelanta `payment_expired_at` un mes y lo
     * persiste. Corre DENTRO de la transacción de `registrar_pago()` — no hace ningún llamado de
     * red (eso lo hace `registrar_pago()` después, con la transacción ya cerrada).
     *
     * 🔴 Idempotente por (cliente, período), no por cada llamada (hallazgo del chequeo
     * independiente, 18/9/2026): sin esto, borrar un pago mal cargado y volver a registrar el
     * mismo mes adelanta el vencimiento DOS VECES por un solo mes real. Se recuerda en
     * `mensualidad_periodos.vencimiento_avanzado_en` de ESE período —no en el cliente— porque lo
     * que hay que saber es "¿esto ya se adelantó por SEPTIEMBRE?", no "¿esto se adelantó alguna
     * vez?".
     *
     * 🔴 `eliminar_pago()` NO limpia `vencimiento_avanzado_en` a propósito. Si de verdad hace falta
     * revertir un adelanto (se borra el pago y no se vuelve a cargar nunca más), queda el camino
     * manual de siempre: editar `payment_expired_at` a mano. Es más seguro dejar ese caso
     * rarísimo sin revertir solo que arriesgarse a que el caso común —corregir un importe mal
     * cargado, borrar y volver a registrar el mismo mes— adelante dos veces sin que nadie lo note.
     *
     * @param Client             $client
     * @param MensualidadPeriodo $fila   Ya guardada por este mismo registro (con `estado` actualizado).
     * @param bool               $cerrar Si este pago cerró el mes completo (`cerrar_periodo` del request).
     *
     * @return array{ocurrio: bool, nueva_fecha: string|null, motivo: string|null}
     */
    protected function avanzar_vencimiento_si_corresponde(Client $client, MensualidadPeriodo $fila, bool $cerrar): array
    {
        // Un pago parcial no adelanta nada (no se terminó de pagar el mes) — y acá no hay nada
        // raro que explicarle al operador, por eso `motivo` queda en null.
        if (! $cerrar) {
            return ['ocurrio' => false, 'nueva_fecha' => null, 'motivo' => null];
        }

        // Sin fecha cargada en admin no hay de dónde adelantar (mismo guard que ya usa
        // `actualizar_en_cliente()`).
        if (empty($client->payment_expired_at)) {
            return ['ocurrio' => false, 'nueva_fecha' => null, 'motivo' => 'Este cliente no tiene fecha de próximo pago cargada.'];
        }

        // El candado de idempotencia: este período ya había adelantado el vencimiento antes.
        if ($fila->vencimiento_avanzado_en !== null) {
            return ['ocurrio' => false, 'nueva_fecha' => null, 'motivo' => 'El vencimiento ya se había adelantado antes por este mismo mes; no se repite.'];
        }

        // Misma regla de fin de mes que ya usa este archivo en otro lado: 31 ene + 1 mes → 28/29
        // feb, no 3 mar.
        $nueva_fecha = Carbon::parse($client->payment_expired_at)->addMonthNoOverflow();

        /* Mismo patrón defensivo que ya usan `registrar_actualizacion()` y `sincronizar_empleados()`
         * más arriba en este archivo: se le pasan SIEMPRE los valores actuales del cliente para
         * todo lo que un pago no toca (precios, empleados, toggles). Sin este cuidado, registrar un
         * pago apagaría el ecommerce del cliente o le resetearía los precios. */
        $this->mensualidad_service->guardar($client, [
            'precio_plan'          => $client->precio_plan,
            'precio_por_cuenta'    => $client->precio_por_cuenta,
            'precio_ecommerce'     => $client->precio_ecommerce,
            'precio_mercado_libre' => $client->precio_mercado_libre,
            'precio_tienda_nube'   => $client->precio_tienda_nube,
            'cantidad_empleados'   => (int) $client->cantidad_empleados,
            'tiene_ecommerce'      => (bool) $client->tiene_ecommerce,
            'tiene_mercado_libre'  => (bool) $client->tiene_mercado_libre,
            'tiene_tienda_nube'    => (bool) $client->tiene_tienda_nube,
            'payment_expired_at'   => $nueva_fecha->toDateString(),
        ]);

        // Se marca el candado recién ACÁ, con el vencimiento ya guardado de verdad.
        $fila->vencimiento_avanzado_en = AppTime::now();
        $fila->save();

        return ['ocurrio' => true, 'nueva_fecha' => $nueva_fecha->toDateString(), 'motivo' => null];
    }

    /**
     * Borra un pago y recalcula el estado del mes.
     *
     * Una fila importada de la planilla que queda sin pagos NO se toca: ese mes lo cerró la
     * planilla (Lucas lo marcó PAGADO a mano en el Excel), no un pago registrado acá, y borrar un
     * pago que alguien agregó después no puede reabrirlo.
     *
     * @param Client          $client
     * @param MensualidadPago $pago
     *
     * @return array<string, mixed> El `estado_de()` del mes ya recalculado.
     */
    public function eliminar_pago(Client $client, MensualidadPago $pago): array
    {
        $periodo = $pago->periodo;
        $pago->delete();

        $fila = MensualidadPeriodo::where('client_id', $client->id)->where('periodo', $periodo)->first();

        if ($fila !== null) {
            $quedan_pagos = MensualidadPago::where('client_id', $client->id)->where('periodo', $periodo)->exists();

            if (! ($fila->importado && ! $quedan_pagos)) {
                $this->recalcular_por_pagos($fila);
            }
        }

        return $this->estado_del_mes($client, $periodo);
    }

    /**
     * Marca a mano el estado de un mes: `sin_cargo`, `pendiente` (para reabrirlo) o `pagado` sin
     * registrar un pago. Es un upsert explícito sobre la fila afirmada.
     *
     * @param Client      $client
     * @param string      $periodo        'YYYY-MM'.
     * @param string      $estado         Uno de MensualidadPeriodo::ESTADOS.
     * @param string|null $observacion    Reemplaza la nota si viene (null la deja como está).
     * @param float|null  $monto_esperado Reemplaza el esperado si viene.
     *
     * @return array<string, mixed> El `estado_de()` del mes.
     */
    public function marcar_periodo(Client $client, string $periodo, string $estado, ?string $observacion = null, ?float $monto_esperado = null): array
    {
        if (! in_array($estado, MensualidadPeriodo::ESTADOS, true)) {
            throw new \InvalidArgumentException('Estado de período inválido: ' . $estado);
        }

        $fila = $this->fila_del_mes($client, $periodo);
        $fila->estado = $estado;

        if ($observacion !== null) {
            $fila->observacion = $observacion !== '' ? $observacion : null;
        }

        if ($monto_esperado !== null) {
            $fila->monto_esperado = round($monto_esperado, 2);
        }

        $fila->save();

        return $this->estado_del_mes($client, $periodo);
    }

    /**
     * La fila afirmada de un mes, creándola en memoria si no existe (con el esperado = total
     * actual del cliente, que es lo que ese mes valía cuando se afirmó algo de él).
     *
     * @param Client $client
     * @param string $periodo
     *
     * @return MensualidadPeriodo
     */
    protected function fila_del_mes(Client $client, string $periodo): MensualidadPeriodo
    {
        $fila = MensualidadPeriodo::firstOrNew([
            'client_id' => $client->id,
            'periodo'   => $periodo,
        ]);

        if (! $fila->exists) {
            $fila->estado = MensualidadPeriodo::ESTADO_PENDIENTE;
            $fila->importado = false;
        }

        if ($fila->monto_esperado === null && $client->total_mensualidad !== null) {
            $fila->monto_esperado = round((float) $client->total_mensualidad, 2);
        }

        return $fila;
    }

    /**
     * Recalcula el estado afirmado de un mes desde la suma de sus pagos con monto: cubre el
     * esperado (y el esperado es > 0) → `pagado`; entró algo → `parcial`; nada → `pendiente`.
     *
     * Persiste la fila.
     *
     * @param MensualidadPeriodo $fila
     *
     * @return void
     */
    protected function recalcular_por_pagos(MensualidadPeriodo $fila): void
    {
        $suma = (float) MensualidadPago::where('client_id', $fila->client_id)
            ->where('periodo', $fila->periodo)
            ->whereNotNull('monto')
            ->sum('monto');

        $esperado = $fila->monto_esperado !== null ? (float) $fila->monto_esperado : 0.0;

        if ($suma <= 0) {
            $fila->estado = MensualidadPeriodo::ESTADO_PENDIENTE;
        } elseif ($esperado <= 0) {
            /* Sin monto esperado (cliente sin precios cargados) no hay contra qué comparar: un mes
             * que ya estaba cerrado como pagado se queda pagado, y uno abierto queda parcial hasta
             * que alguien lo cierre. Bajarlo a parcial automáticamente decía "debe" sin saber cuánto. */
            if ($fila->estado !== MensualidadPeriodo::ESTADO_PAGADO) {
                $fila->estado = MensualidadPeriodo::ESTADO_PARCIAL;
            }
        } elseif ($suma >= $esperado) {
            $fila->estado = MensualidadPeriodo::ESTADO_PAGADO;
        } else {
            $fila->estado = MensualidadPeriodo::ESTADO_PARCIAL;
        }

        $fila->save();
    }

    /* ------------------------------------------------------------------ *
     |  Listados
     * ------------------------------------------------------------------ */

    /**
     * El rango de meses por defecto de un cliente: desde su inicio (o 12 meses atrás si no tiene)
     * hasta el corriente más 3.
     *
     * @param Client $client
     *
     * @return array{desde: string, hasta: string}
     */
    public function rango_default(Client $client): array
    {
        $ahora = AppTime::now();
        $inicio = $this->mes_de_inicio($client);

        return [
            'desde' => $inicio ?? $ahora->copy()->subMonthsNoOverflow(self::MESES_ATRAS_DEFAULT)->format('Y-m'),
            'hasta' => $ahora->copy()->addMonthsNoOverflow(self::MESES_ADELANTE_DEFAULT)->format('Y-m'),
        ];
    }

    /**
     * El `estado_de()` de cada mes de un cliente entre `desde` y `hasta`, incluidos los meses sin
     * fila. Tres consultas en total (filas, facturas, pagos), no tres por mes.
     *
     * @param Client      $client
     * @param string|null $desde 'YYYY-MM'; default según `rango_default()`.
     * @param string|null $hasta 'YYYY-MM'; default según `rango_default()`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function periodos(Client $client, ?string $desde = null, ?string $hasta = null): array
    {
        $default = $this->rango_default($client);
        $desde = $desde ?? $default['desde'];
        $hasta = $hasta ?? $default['hasta'];

        $meses = $this->meses_entre($desde, $hasta);
        if (count($meses) === 0) {
            return [];
        }

        $cargado = $this->cargar_para([$client->id], $meses);

        $lista = [];
        foreach ($meses as $periodo) {
            $lista[] = $this->estado_de(
                $client,
                $periodo,
                $cargado['filas'][$client->id][$periodo] ?? null,
                $cargado['facturas'][$client->id][$periodo] ?? null,
                $cargado['pagos'][$client->id][$periodo] ?? []
            );
        }

        return $lista;
    }

    /**
     * La tabla del módulo: TODOS los clientes (orden `id`) con el estado de cada uno de los meses
     * pedidos. Filas, facturas y pagos de todos los clientes para esos meses se cargan en tres
     * consultas; después todo es memoria.
     *
     * @param array<int, string> $meses 'YYYY-MM' cada uno.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tabla_mensualidades(array $meses): array
    {
        $meses = array_values(array_unique(array_filter($meses, [self::class, 'es_periodo_valido'])));
        sort($meses);

        $clientes = Client::query()->orderBy('id')->get();

        $cargado = $this->cargar_para($clientes->pluck('id')->all(), $meses);

        $tabla = [];
        foreach ($clientes as $client) {
            $por_mes = [];
            foreach ($meses as $periodo) {
                $celda = $this->estado_de(
                    $client,
                    $periodo,
                    $cargado['filas'][$client->id][$periodo] ?? null,
                    $cargado['facturas'][$client->id][$periodo] ?? null,
                    $cargado['pagos'][$client->id][$periodo] ?? []
                );
                // La lista de pagos no va en la tabla: es detalle del modal, y multiplicado por
                // 50 clientes y N meses engorda la respuesta sin que nadie la mire.
                unset($celda['pagos']);
                $por_mes[$periodo] = $celda;
            }

            $tabla[] = [
                'id'                      => $client->id,
                'nombre'                  => $client->resolve_display_name(),
                'name'                    => $client->name,
                'company_name'            => $client->company_name,
                'is_active'               => (bool) $client->is_active,
                'mensualidad_inicio'      => $client->mensualidad_inicio ? Carbon::parse($client->mensualidad_inicio)->toDateString() : null,
                'total_mensualidad'       => $client->total_mensualidad !== null ? (float) $client->total_mensualidad : null,
                'cantidad_empleados'      => (int) $client->cantidad_empleados,
                'tiene_ecommerce'         => (bool) $client->tiene_ecommerce,
                'afip_cuit'               => $client->afip_cuit,
                // Teléfono del dueño, crudo tal como está cargado (misión cobranzas-mejoras,
                // 18/9/2026): por si el frontend lo necesita para mostrarlo tal cual.
                'phone'                   => $client->phone,
                // Ya normalizado a dígitos puros, listo para wa.me/ (hallazgo del chequeo
                // independiente, 18/9/2026): `clients.phone` es de formato libre, y el frontend no
                // tiene por qué reimplementar la normalización de teléfonos argentinos. El
                // frontend usa ESTE campo directo, sin volver a tocarlo.
                'phone_whatsapp'          => $this->phone_para_whatsapp($client->phone),
                'cobranzas_observaciones' => $client->cobranzas_observaciones,
                'actualizacion'           => $this->resumen_actualizacion($client, $cargado['ultimas_oficiales'][$client->id] ?? null, true),
                'meses'                   => $por_mes,
            ];
        }

        return $tabla;
    }

    /**
     * Normaliza `clients.phone` (formato libre) a dígitos puros, listo para `wa.me/<numero>`
     * (hallazgo del chequeo independiente, misión cobranzas-mejoras, 18/9/2026).
     *
     * Usa `WhatsappNormalizer` y NO `ArgentinePhoneNormalizer`: se grepearon los dos antes de
     * elegir. `WhatsappNormalizer` es el que ya usan `WhatsappSendService` para mandar mensajes
     * de verdad y `ClientPhoneDirectory` para reconocer los teléfonos de un cliente —el mismo tipo
     * de uso que necesita este link—; `ArgentinePhoneNormalizer` es del flujo de formularios de
     * implementación (`ImplementationConversationService`/`ImplementationFormMapper`), un dominio
     * distinto.
     *
     * @param string|null $phone Crudo, tal como está en `clients.phone`.
     *
     * @return string|null Solo dígitos (sin '+'), o null si no se pudo normalizar (vacío, sin dígitos).
     */
    protected function phone_para_whatsapp(?string $phone): ?string
    {
        $normalizado = WhatsappNormalizer::normalize((string) $phone);

        return $normalizado !== '' ? ltrim($normalizado, '+') : null;
    }

    /**
     * Carga en tres consultas (más una para las últimas actualizaciones oficiales) todo lo que
     * `estado_de()` necesita de un conjunto de clientes para un conjunto de meses, indexado por
     * `[client_id][periodo]`.
     *
     * @param array<int, int>    $client_ids
     * @param array<int, string> $meses
     *
     * @return array{filas: array, facturas: array, pagos: array, ultimas_oficiales: array}
     */
    protected function cargar_para(array $client_ids, array $meses): array
    {
        $resultado = ['filas' => [], 'facturas' => [], 'pagos' => [], 'ultimas_oficiales' => []];

        if (count($client_ids) === 0 || count($meses) === 0) {
            return $resultado;
        }

        MensualidadPeriodo::whereIn('client_id', $client_ids)->whereIn('periodo', $meses)->get()
            ->each(function (MensualidadPeriodo $fila) use (&$resultado) {
                $resultado['filas'][$fila->client_id][$fila->periodo] = $fila;
            });

        // Solo las autorizadas, ordenadas ascendente para que la más reciente quede última y pise.
        MensualidadInvoice::whereIn('client_id', $client_ids)->whereIn('periodo', $meses)
            ->where('resultado', 'A')->whereNotNull('cae')
            ->orderBy('id')
            ->get(['id', 'client_id', 'periodo', 'cbte_numero', 'punto_venta', 'cae', 'resultado'])
            ->each(function (MensualidadInvoice $factura) use (&$resultado) {
                $resultado['facturas'][$factura->client_id][$factura->periodo] = $factura;
            });

        MensualidadPago::whereIn('client_id', $client_ids)->whereIn('periodo', $meses)->orderBy('id')->get()
            ->each(function (MensualidadPago $pago) use (&$resultado) {
                $resultado['pagos'][$pago->client_id][$pago->periodo][] = $pago;
            });

        // La última actualización OFICIAL de cada cliente, en una consulta: ordenadas de la más
        // reciente a la más vieja, la primera que aparece por cliente es la que queda.
        MensualidadActualizacion::whereIn('client_id', $client_ids)->where('es_oficial', true)
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get()
            ->each(function (MensualidadActualizacion $actualizacion) use (&$resultado) {
                if (! isset($resultado['ultimas_oficiales'][$actualizacion->client_id])) {
                    $resultado['ultimas_oficiales'][$actualizacion->client_id] = $actualizacion;
                }
            });

        return $resultado;
    }

    /* ------------------------------------------------------------------ *
     |  Actualizaciones de precio
     * ------------------------------------------------------------------ */

    /**
     * Cuándo fue la última actualización OFICIAL de precios y cuándo vence la próxima.
     *
     * `meses` son los del contrato (`contract_meses_actualizacion`, default 6). `proxima_fecha` =
     * última oficial + meses. `vencida` = la próxima ya pasó. `dias_restantes` = días desde hoy
     * hasta la próxima, con signo (negativo = hace cuántos días venció); null si no hay oficial.
     *
     * @param Client                       $client
     * @param MensualidadActualizacion|null $ultima_oficial Ya cargada (para la tabla, que la trae en
     *                                                       una consulta para todos); si no viene se
     *                                                       consulta, salvo que `$ya_cargada` diga que
     *                                                       el null ES el resultado.
     * @param bool                         $ya_cargada     True cuando el llamador ya buscó y null
     *                                                       significa "no tiene".
     *
     * @return array<string, mixed>
     */
    public function resumen_actualizacion(Client $client, ?MensualidadActualizacion $ultima_oficial = null, bool $ya_cargada = false): array
    {
        if ($ultima_oficial === null && ! $ya_cargada) {
            $ultima_oficial = MensualidadActualizacion::where('client_id', $client->id)
                ->where('es_oficial', true)
                ->orderByDesc('fecha')->orderByDesc('id')
                ->first();
        }

        $meses = (int) ($client->contract_meses_actualizacion ?? 0);
        if ($meses <= 0) {
            $meses = ClientContratoService::MESES_ACTUALIZACION_DEFAULT;
        }

        if ($ultima_oficial === null) {
            return [
                'ultima_oficial_fecha' => null,
                'ultima_oficial_id'    => null,
                'meses'                => $meses,
                'proxima_fecha'        => null,
                'vencida'              => false,
                'dias_restantes'       => null,
                'sin_oficial'          => true,
            ];
        }

        $hoy = AppTime::now()->startOfDay();
        $ultima = Carbon::parse($ultima_oficial->fecha)->startOfDay();
        $proxima = $ultima->copy()->addMonthsNoOverflow($meses);

        return [
            'ultima_oficial_fecha' => $ultima->toDateString(),
            'ultima_oficial_id'    => $ultima_oficial->id,
            'meses'                => $meses,
            'proxima_fecha'        => $proxima->toDateString(),
            'vencida'              => $proxima->lt($hoy),
            // diffInDays con signo: positivo si falta, negativo si ya pasó.
            'dias_restantes'       => (int) $hoy->diffInDays($proxima, false),
            'sin_oficial'          => false,
        ];
    }

    /**
     * El historial de actualizaciones de un cliente, la más reciente primero, con el nombre del
     * admin que la registró.
     *
     * @param Client $client
     *
     * @return array<int, array<string, mixed>>
     */
    public function actualizaciones(Client $client): array
    {
        return MensualidadActualizacion::with('admin')
            ->where('client_id', $client->id)
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get()
            ->map(function (MensualidadActualizacion $actualizacion) {
                return [
                    'id'                   => $actualizacion->id,
                    'fecha'                => Carbon::parse($actualizacion->fecha)->toDateString(),
                    'precio_plan'          => (float) $actualizacion->precio_plan,
                    'precio_por_cuenta'    => (float) $actualizacion->precio_por_cuenta,
                    'precio_ecommerce'     => $actualizacion->precio_ecommerce !== null ? (float) $actualizacion->precio_ecommerce : null,
                    'precio_mercado_libre' => $actualizacion->precio_mercado_libre !== null ? (float) $actualizacion->precio_mercado_libre : null,
                    'precio_tienda_nube'   => $actualizacion->precio_tienda_nube !== null ? (float) $actualizacion->precio_tienda_nube : null,
                    'es_oficial'           => (bool) $actualizacion->es_oficial,
                    'observacion'          => $actualizacion->observacion,
                    'admin_id'             => $actualizacion->admin_id,
                    'admin_nombre'         => $actualizacion->admin ? $actualizacion->admin->name : null,
                    'created_at'           => $actualizacion->created_at ? $actualizacion->created_at->toDateTimeString() : null,
                ];
            })
            ->all();
    }

    /**
     * Registra una actualización de precios Y la aplica al cliente.
     *
     * La aplicación va por `ClientMensualidadService::guardar()`, que es el único lugar donde se
     * escriben los precios y se recalcula `total_mensualidad`. 🔴 Ese método exige `precio_plan`,
     * `precio_por_cuenta` y `cantidad_empleados`, y setea los tres `tiene_*` con `! empty()`: por
     * eso acá se le pasan SIEMPRE los valores actuales del cliente para todo lo que una
     * actualización de precios no toca (empleados, toggles, fecha de pago). Si no, registrar un
     * precio nuevo apagaría el ecommerce del cliente.
     *
     * @param Client     $client
     * @param array      $datos {fecha?, precio_plan, precio_por_cuenta, precio_ecommerce?,
     *                          precio_mercado_libre?, precio_tienda_nube?, es_oficial?, observacion?}
     * @param Admin|null $admin
     *
     * @return array{snapshot: array, actualizaciones: array, resumen: array}
     */
    public function registrar_actualizacion(Client $client, array $datos, ?Admin $admin = null): array
    {
        /** Un precio de módulo: número o null (null = cae a precio_por_cuenta, como siempre). */
        $precio_opcional = function ($clave) use ($datos) {
            return isset($datos[$clave]) && $datos[$clave] !== '' ? round((float) $datos[$clave], 2) : null;
        };

        $actualizacion = MensualidadActualizacion::create([
            'client_id'            => $client->id,
            'admin_id'             => $admin ? $admin->id : null,
            'fecha'                => ! empty($datos['fecha']) ? Carbon::parse($datos['fecha'])->toDateString() : AppTime::now()->toDateString(),
            'precio_plan'          => round((float) $datos['precio_plan'], 2),
            'precio_por_cuenta'    => round((float) $datos['precio_por_cuenta'], 2),
            'precio_ecommerce'     => $precio_opcional('precio_ecommerce'),
            'precio_mercado_libre' => $precio_opcional('precio_mercado_libre'),
            'precio_tienda_nube'   => $precio_opcional('precio_tienda_nube'),
            'es_oficial'           => array_key_exists('es_oficial', $datos) ? filter_var($datos['es_oficial'], FILTER_VALIDATE_BOOLEAN) : false,
            'observacion'          => isset($datos['observacion']) && $datos['observacion'] !== '' ? (string) $datos['observacion'] : null,
        ]);

        $this->mensualidad_service->guardar($client, [
            'precio_plan'          => $actualizacion->precio_plan,
            'precio_por_cuenta'    => $actualizacion->precio_por_cuenta,
            'precio_ecommerce'     => $actualizacion->precio_ecommerce,
            'precio_mercado_libre' => $actualizacion->precio_mercado_libre,
            'precio_tienda_nube'   => $actualizacion->precio_tienda_nube,
            // Lo que una actualización de precios NO toca, tal como está.
            'cantidad_empleados'   => (int) $client->cantidad_empleados,
            'tiene_ecommerce'      => (bool) $client->tiene_ecommerce,
            'tiene_mercado_libre'  => (bool) $client->tiene_mercado_libre,
            'tiene_tienda_nube'    => (bool) $client->tiene_tienda_nube,
            'payment_expired_at'   => $client->payment_expired_at,
        ]);

        return [
            'snapshot'        => $this->mensualidad_service->estado($client),
            'actualizaciones' => $this->actualizaciones($client),
            'resumen'         => $this->resumen_actualizacion($client),
        ];
    }

    /**
     * Sincroniza la cantidad de empleados con el conteo vivo del empresa-api del cliente.
     *
     * Solo eso: los precios y los toggles se le pasan a `guardar()` tal como están (ver la nota
     * en `registrar_actualizacion()`), y el resto del snapshot que trae el sync (ecommerce, ML,
     * datos fiscales) se devuelve para que el front lo muestre, sin escribirlo.
     *
     * @param Client $client
     *
     * @return array<string, mixed> {soportado, error?} o {soportado, cantidad_empleados, total_mensualidad, snapshot}
     */
    public function sincronizar_empleados(Client $client): array
    {
        $traido = $this->sync_service->traer_del_cliente($client);

        if (empty($traido['soportado'])) {
            return [
                'soportado' => false,
                'error'     => $traido['error'] ?? 'No se pudo consultar al cliente.',
            ];
        }

        $this->mensualidad_service->guardar($client, [
            'precio_plan'          => $client->precio_plan,
            'precio_por_cuenta'    => $client->precio_por_cuenta,
            'precio_ecommerce'     => $client->precio_ecommerce,
            'precio_mercado_libre' => $client->precio_mercado_libre,
            'precio_tienda_nube'   => $client->precio_tienda_nube,
            'cantidad_empleados'   => (int) ($traido['cantidad_empleados'] ?? 0),
            'tiene_ecommerce'      => (bool) $client->tiene_ecommerce,
            'tiene_mercado_libre'  => (bool) $client->tiene_mercado_libre,
            'tiene_tienda_nube'    => (bool) $client->tiene_tienda_nube,
            'payment_expired_at'   => $client->payment_expired_at,
        ]);

        return [
            'soportado'          => true,
            'cantidad_empleados' => (int) $client->cantidad_empleados,
            'total_mensualidad'  => $client->total_mensualidad !== null ? (float) $client->total_mensualidad : null,
            'snapshot'           => $traido,
        ];
    }
}
