<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Admin;
use App\Models\Lead;
use App\Models\LicenciaCuota;
use App\Models\Sena;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Las señas de una venta (misión senas-en-ventas, 30/10/2026).
 *
 * Una seña es un pago a cuenta que el cliente deja antes (o aparte) de las cuotas. El caso que la
 * originó: Sebastián (Ferretería Plazoleta), un plan de USD 1.300 en 4 cuotas y una seña de USD 100.
 * Decisiones de Lucas (30/10/2026):
 *
 *  1. 🔴 **La seña es un pago a cuenta que se aplica sola** a las cuotas pendientes más próximas.
 *     No se crea una cuota nueva ni se toca el total de la venta: se reparte el monto en pagos
 *     parciales (o totales) de las cuotas, con `LicenciaCuotaService::registrar_pago()`, el MISMO
 *     camino de cualquier cobro. Por eso deuda, cobrado y comisión liberada del vendedor cuadran
 *     solos: este servicio NO tiene ninguna cuenta de plata propia ni toca la lógica de comisión.
 *  2. **Se puede registrar en el lead antes de promoverlo.** La seña queda anotada (sin venta) y,
 *     cuando la promoción crea la venta del contrato, se aplica sola.
 *
 * Orden de aplicación: cuotas `pendiente` o `parcial`, por `vencimiento` ascendente (las que no
 * tienen vencimiento, al final) y luego por `numero`. Cada cuota se cubre hasta lo que le falta
 * antes de pasar a la siguiente.
 *
 * Reglas (valen igual para el panel y para Claude, que comparten este servicio):
 *  - El monto tiene que ser mayor que cero.
 *  - 🔴 Una seña no puede superar lo pendiente de la venta: si lo hiciera sobraría plata sin cuota
 *    donde caer y `resumen.cobrado` pasaría a ser más que el total.
 *  - La moneda es siempre la de la venta (o la del contrato del lead).
 *  - Un lead ya promovido no recibe señas: se registran en la venta de su cliente.
 *
 * Los errores de negocio salen como `ValidationException` (422): el panel los devuelve con la forma
 * estándar de Laravel y Claude los convierte en `{"error": ...}`.
 */
class SenaService
{
    /**
     * Texto que se deja en la observación de cada cuota que recibió una parte de una seña.
     */
    const OBSERVACION_DE_CUOTA = 'Seña';

    /**
     * Tolerancia de comparación de importes en 2 decimales (menos de un centavo).
     */
    const TOLERANCIA = 0.009;

    /**
     * @var LicenciaCuotaService
     */
    protected $cuotas;

    /**
     * @param LicenciaCuotaService $cuotas
     */
    public function __construct(LicenciaCuotaService $cuotas)
    {
        $this->cuotas = $cuotas;
    }

    /**
     * Registra una seña directo en una venta y la aplica a sus cuotas, todo en una transacción.
     *
     * @param Venta       $venta
     * @param float       $monto  Importe de la seña (> 0 y como mucho lo pendiente de la venta).
     * @param string|null $fecha  Cuándo la dejó el cliente; default hoy.
     * @param string|null $nota   Nota libre (hasta 255).
     * @param Admin|null  $autor  Quién la registra (null = Claude sin admin o un proceso).
     * @param string      $origen Una de Sena::ORIGENES.
     *
     * @return Sena La seña guardada y ya aplicada.
     *
     * @throws ValidationException Si el monto es <= 0, supera lo pendiente o la venta no tiene nada pendiente (422).
     */
    public function registrar_en_venta(Venta $venta, float $monto, ?string $fecha, ?string $nota, ?Admin $autor, string $origen): Sena
    {
        $monto = round($monto, 2);

        if ($monto <= 0) {
            throw ValidationException::withMessages([
                'monto' => 'El monto de la seña tiene que ser mayor que cero.',
            ]);
        }

        $fecha_de_la_sena = $this->fecha_o_hoy($fecha);

        return DB::transaction(function () use ($venta, $monto, $fecha_de_la_sena, $nota, $autor, $origen) {
            /* Las cuotas se leen adentro de la transacción y con bloqueo: si dos señas (o una seña y un
               pago) llegaran a la vez, la segunda vería ya lo cobrado por la primera y no pasaría de
               lo pendiente. */
            $cuotas = LicenciaCuota::where('venta_id', $venta->id)
                ->lockForUpdate()
                ->get();

            $pendiente = $this->pendiente_de($cuotas);

            if ($pendiente <= 0) {
                throw ValidationException::withMessages([
                    'monto' => 'La venta no tiene nada pendiente de cobro: no hay cuotas a las que aplicar una seña.',
                ]);
            }

            if ($monto - $pendiente > self::TOLERANCIA) {
                throw ValidationException::withMessages([
                    'monto' => 'La seña (' . $this->plata($monto) . ' ' . $venta->moneda . ') supera lo pendiente de la venta ('
                        . $this->plata($pendiente) . ' ' . $venta->moneda . '). Una seña no puede pasar la deuda.',
                ]);
            }

            $aplicaciones = $this->aplicar_a_cuotas($cuotas, $monto, $fecha_de_la_sena);

            return Sena::create([
                'venta_id'                => $venta->id,
                'lead_id'                 => null,
                'client_id'               => $venta->client_id,
                'monto'                   => $monto,
                'moneda'                  => (string) $venta->moneda,
                'fecha'                   => $fecha_de_la_sena->toDateString(),
                'nota'                    => $this->nota_o_null($nota),
                'registrada_por_admin_id' => $autor ? $autor->id : null,
                'origen'                  => $this->origen_valido($origen),
                'aplicaciones'            => $aplicaciones,
                'aplicada_at'             => AppTime::now(),
            ]);
        });
    }

    /**
     * Registra una seña en un lead que todavía no se promovió. Solo guarda la fila: queda pendiente
     * (`venta_id` y `aplicada_at` nulos) hasta que la promoción cree la venta del contrato
     * (`aplicar_pendientes_del_lead()`). La moneda es la del contrato del lead (USD si no tiene).
     *
     * @param Lead        $lead
     * @param float       $monto  Importe de la seña (> 0).
     * @param string|null $fecha  Cuándo la dejó el cliente; default hoy.
     * @param string|null $nota   Nota libre (hasta 255).
     * @param Admin|null  $autor  Quién la registra.
     * @param string      $origen Una de Sena::ORIGENES.
     *
     * @return Sena La seña guardada, pendiente.
     *
     * @throws ValidationException Si el monto es <= 0 o el lead ya fue promovido (422).
     */
    public function registrar_en_lead(Lead $lead, float $monto, ?string $fecha, ?string $nota, ?Admin $autor, string $origen): Sena
    {
        $monto = round($monto, 2);

        if ($monto <= 0) {
            throw ValidationException::withMessages([
                'monto' => 'El monto de la seña tiene que ser mayor que cero.',
            ]);
        }

        $fecha_de_la_sena = $this->fecha_o_hoy($fecha);

        return DB::transaction(function () use ($lead, $monto, $fecha_de_la_sena, $nota, $autor, $origen) {
            /* El lead se relee con bloqueo: si se está promoviendo justo ahora, la seña no debe
               quedar pendiente de una promoción que ya pasó. */
            $actual = Lead::whereKey($lead->id)->lockForUpdate()->first() ?: $lead;

            if ($actual->promoted_client_id !== null) {
                throw ValidationException::withMessages([
                    'lead_id' => 'El lead ya fue promovido al cliente ' . (int) $actual->promoted_client_id
                        . ': la seña se registra en la venta de ese cliente.',
                ]);
            }

            return Sena::create([
                'venta_id'                => null,
                'lead_id'                 => $actual->id,
                'client_id'               => null,
                'monto'                   => $monto,
                'moneda'                  => $this->moneda_del_lead($actual),
                'fecha'                   => $fecha_de_la_sena->toDateString(),
                'nota'                    => $this->nota_o_null($nota),
                'registrada_por_admin_id' => $autor ? $autor->id : null,
                'origen'                  => $this->origen_valido($origen),
                'aplicaciones'            => null,
                'aplicada_at'             => null,
            ]);
        });
    }

    /**
     * Aplica a la venta recién creada las señas que el lead tenía pendientes. Se llama desde la
     * promoción del lead (`ClientContratoService::crear_venta_del_contrato()`), en orden de fecha.
     *
     * 🔴 NUNCA TIRA. La promoción de un lead no puede romperse por una seña: cualquier error se
     * loguea y la seña queda pendiente (se puede aplicar a mano registrándola de nuevo en la venta).
     * Cada seña va en su propia transacción: si una falla, no deja cuotas a medio pagar.
     *
     * Casos borde:
     *  - La seña es de otra moneda que la venta: no se aplica (sumar dólares a pesos sería mentir), se loguea.
     *  - La seña supera lo pendiente: se aplica hasta cubrirlo, la seña queda por lo aplicado y lo que
     *    sobra pasa a una seña pendiente nueva del mismo lead (se loguea).
     *  - La venta no tiene nada pendiente: la seña queda pendiente.
     *
     * @param Lead|int $lead  El lead (o su id).
     * @param Venta    $venta La venta recién creada.
     *
     * @return int Cuántas señas se aplicaron (total o parcialmente).
     */
    public function aplicar_pendientes_del_lead($lead, Venta $venta): int
    {
        $aplicadas = 0;

        try {
            $lead_id = $lead instanceof Lead ? (int) $lead->id : (int) $lead;

            if ($lead_id <= 0) {
                return 0;
            }

            $pendientes = Sena::where('lead_id', $lead_id)
                ->whereNull('aplicada_at')
                ->orderBy('fecha')->orderBy('id')
                ->get();

            foreach ($pendientes as $sena) {
                try {
                    if ($this->aplicar_una_pendiente($sena, $venta)) {
                        $aplicadas++;
                    }
                } catch (\Throwable $error) {
                    Log::error('SenaService: no se pudo aplicar la seña pendiente del lead a la venta; la seña queda pendiente.', [
                        'sena_id'  => $sena->id,
                        'lead_id'  => $lead_id,
                        'venta_id' => $venta->id,
                        'error'    => $error->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $error) {
            Log::error('SenaService: falló la búsqueda de señas pendientes del lead; la promoción sigue.', [
                'venta_id' => $venta->id,
                'error'    => $error->getMessage(),
            ]);
        }

        return $aplicadas;
    }

    /**
     * Todas las señas registradas en un lead, de la más vieja a la más nueva.
     *
     * @param Lead|int $lead
     *
     * @return Collection<int, Sena>
     */
    public function senas_del_lead($lead): Collection
    {
        $lead_id = $lead instanceof Lead ? (int) $lead->id : (int) $lead;

        return Sena::where('lead_id', $lead_id)->orderBy('fecha')->orderBy('id')->get();
    }

    /**
     * Las señas de un lead como viajan al front: la lista (con `aplicada` para distinguir las ya
     * aplicadas a una venta de las pendientes), el total PENDIENTE (lo que todavía no se aplicó) y
     * el total ya aplicado.
     *
     * @param Lead|int $lead
     *
     * @return array{senas: array<int, array<string, mixed>>, total: float, total_aplicado: float}
     */
    public function senas_del_lead_para_json($lead): array
    {
        $senas = $this->senas_del_lead($lead);

        return [
            'senas'          => $senas->map(function (Sena $sena) {
                return $this->sena_para_json($sena);
            })->values()->all(),
            'total'          => $this->total_de($senas->filter(function (Sena $sena) {
                return ! $sena->esta_aplicada();
            })),
            'total_aplicado' => $this->total_de($senas->filter(function (Sena $sena) {
                return $sena->esta_aplicada();
            })),
        ];
    }

    /**
     * Las señas de una venta como viajan en `venta_para_json()['senas']`. Usa la relación `senas`
     * si ya está cargada (las listas la traen de una) y la consulta si no. Una venta sin guardar
     * (la del dry_run de alta de Claude) no tiene señas.
     *
     * @param Venta $venta
     *
     * @return array<int, array<string, mixed>>
     */
    public function senas_de_la_venta_para_json(Venta $venta): array
    {
        return $this->senas_de_la_venta($venta)->map(function (Sena $sena) {
            return $this->sena_para_json($sena);
        })->values()->all();
    }

    /**
     * La suma de las señas de una venta (`resumen.sena_total`), redondeada a 2 decimales.
     *
     * @param Venta $venta
     *
     * @return float
     */
    public function total_de_la_venta(Venta $venta): float
    {
        return $this->total_de($this->senas_de_la_venta($venta));
    }

    /**
     * Una seña tal como viaja al front. Los decimales van como float (el cast los deja string).
     *
     * @param Sena $sena
     *
     * @return array<string, mixed>
     */
    public function sena_para_json(Sena $sena): array
    {
        return [
            'id'                      => (int) $sena->id,
            'venta_id'                => $sena->venta_id !== null ? (int) $sena->venta_id : null,
            'lead_id'                 => $sena->lead_id !== null ? (int) $sena->lead_id : null,
            'client_id'               => $sena->client_id !== null ? (int) $sena->client_id : null,
            'monto'                   => (float) $sena->monto,
            'moneda'                  => (string) $sena->moneda,
            'fecha'                   => $sena->fecha ? Carbon::parse($sena->fecha)->toDateString() : null,
            'nota'                    => $sena->nota,
            'origen'                  => (string) $sena->origen,
            'aplicada'                => $sena->esta_aplicada(),
            'aplicada_at'             => $sena->aplicada_at ? $sena->aplicada_at->toDateTimeString() : null,
            'aplicaciones'            => is_array($sena->aplicaciones) ? array_values($sena->aplicaciones) : [],
            'registrada_por_admin_id' => $sena->registrada_por_admin_id !== null ? (int) $sena->registrada_por_admin_id : null,
        ];
    }

    /**
     * Aplica UNA seña pendiente del lead a la venta recién creada, en su propia transacción.
     *
     * @param Sena  $sena
     * @param Venta $venta
     *
     * @return bool true si se aplicó (total o parcialmente).
     */
    protected function aplicar_una_pendiente(Sena $sena, Venta $venta): bool
    {
        return DB::transaction(function () use ($sena, $venta) {
            /* Se relee la seña con bloqueo: si otro proceso la aplicó mientras tanto, no se aplica dos veces. */
            $sena = Sena::whereKey($sena->id)->lockForUpdate()->first();

            if ($sena === null || $sena->esta_aplicada()) {
                return false;
            }

            if (strtoupper((string) $sena->moneda) !== strtoupper((string) $venta->moneda)) {
                Log::warning('SenaService: la seña del lead está en otra moneda que la venta; queda pendiente.', [
                    'sena_id'  => $sena->id,
                    'venta_id' => $venta->id,
                    'sena'     => $sena->moneda,
                    'venta'    => $venta->moneda,
                ]);

                return false;
            }

            $cuotas = LicenciaCuota::where('venta_id', $venta->id)->lockForUpdate()->get();
            $pendiente = $this->pendiente_de($cuotas);

            if ($pendiente <= 0) {
                Log::warning('SenaService: la venta no tiene nada pendiente; la seña del lead queda pendiente.', [
                    'sena_id'  => $sena->id,
                    'venta_id' => $venta->id,
                ]);

                return false;
            }

            $monto = round((float) $sena->monto, 2);
            $a_aplicar = min($monto, $pendiente);
            $sobrante = round($monto - $a_aplicar, 2);

            $aplicaciones = $this->aplicar_a_cuotas($cuotas, $a_aplicar, Carbon::parse($sena->fecha));

            if ($sobrante > self::TOLERANCIA) {
                /* La seña superaba lo pendiente: la fila queda por lo que se aplicó y lo que sobra pasa
                   a una seña pendiente nueva, para que `monto` siga siendo lo que de verdad entró en las cuotas. */
                Sena::create([
                    'venta_id'                => null,
                    'lead_id'                 => $sena->lead_id,
                    'client_id'               => null,
                    'monto'                   => $sobrante,
                    'moneda'                  => $sena->moneda,
                    'fecha'                   => $sena->fecha ? Carbon::parse($sena->fecha)->toDateString() : AppTime::now()->toDateString(),
                    'nota'                    => $this->nota_o_null('Resto sin aplicar de la seña ' . $sena->id . ($sena->nota ? ': ' . $sena->nota : '')),
                    'registrada_por_admin_id' => $sena->registrada_por_admin_id,
                    'origen'                  => $sena->origen,
                    'aplicaciones'            => null,
                    'aplicada_at'             => null,
                ]);

                Log::warning('SenaService: la seña del lead superaba lo pendiente de la venta; se aplicó hasta cubrirlo y el resto quedó pendiente.', [
                    'sena_id'  => $sena->id,
                    'venta_id' => $venta->id,
                    'sobrante' => $sobrante,
                ]);
            }

            $sena->monto        = $a_aplicar;
            $sena->venta_id     = $venta->id;
            $sena->client_id    = $venta->client_id;
            $sena->aplicaciones = $aplicaciones;
            $sena->aplicada_at  = AppTime::now();
            $sena->save();

            return true;
        });
    }

    /**
     * Reparte el monto en las cuotas de la venta, en orden, y devuelve a qué cuotas fue cada parte.
     * Es la única parte que escribe plata, y lo hace SOLO con `LicenciaCuotaService::registrar_pago()`.
     *
     * Orden: cuotas `pendiente` o `parcial` con algo por cobrar, por vencimiento ascendente (sin
     * vencimiento, al final) y luego por número.
     *
     * @param Collection $cuotas Todas las cuotas de la venta.
     * @param float      $monto  Lo que hay que repartir (ya validado contra lo pendiente).
     * @param Carbon     $fecha  Fecha del pago de cada cuota.
     *
     * @return array<int, array{cuota_id: int, monto: float}>
     */
    protected function aplicar_a_cuotas(Collection $cuotas, float $monto, Carbon $fecha): array
    {
        $aplicables = $cuotas->filter(function (LicenciaCuota $cuota) {
            return in_array($cuota->estado, [LicenciaCuota::ESTADO_PENDIENTE, LicenciaCuota::ESTADO_PARCIAL], true)
                && round($cuota->monto_pendiente(), 2) > 0;
        })->all();

        usort($aplicables, function (LicenciaCuota $a, LicenciaCuota $b) {
            // Las que no tienen vencimiento van al final.
            $va = $a->vencimiento ? Carbon::parse($a->vencimiento)->toDateString() : null;
            $vb = $b->vencimiento ? Carbon::parse($b->vencimiento)->toDateString() : null;

            if ($va !== $vb) {
                if ($va === null) {
                    return 1;
                }
                if ($vb === null) {
                    return -1;
                }

                return strcmp($va, $vb);
            }

            if ((int) $a->numero !== (int) $b->numero) {
                return (int) $a->numero <=> (int) $b->numero;
            }

            return (int) $a->id <=> (int) $b->id;
        });

        $resta = round($monto, 2);
        $aplicaciones = [];

        foreach ($aplicables as $cuota) {
            if ($resta <= 0) {
                break;
            }

            $parte = round(min($resta, round($cuota->monto_pendiente(), 2)), 2);

            if ($parte <= 0) {
                continue;
            }

            $this->cuotas->registrar_pago($cuota, $parte, $fecha->toDateString(), $this->observacion_de_la_cuota($cuota));

            $aplicaciones[] = [
                'cuota_id' => (int) $cuota->id,
                'monto'    => $parte,
            ];

            $resta = round($resta - $parte, 2);
        }

        return $aplicaciones;
    }

    /**
     * La observación de la cuota después de recibir una parte de una seña: «Seña», o lo que ya
     * decía con «· Seña» al final (registrar_pago() reemplaza la observación, y la que ya tenía la
     * cuota no se debe perder).
     *
     * @param LicenciaCuota $cuota
     *
     * @return string
     */
    protected function observacion_de_la_cuota(LicenciaCuota $cuota): string
    {
        $actual = trim((string) $cuota->observacion);

        if ($actual === '') {
            return self::OBSERVACION_DE_CUOTA;
        }

        if (mb_stripos($actual, self::OBSERVACION_DE_CUOTA) !== false) {
            return $actual;
        }

        return $actual . ' · ' . self::OBSERVACION_DE_CUOTA;
    }

    /**
     * Lo que falta cobrar de las cuotas (suma de `monto_pendiente()`), a 2 decimales. Es el mismo
     * número que `venta_para_json()['resumen']['pendiente']`.
     *
     * @param Collection $cuotas
     *
     * @return float
     */
    protected function pendiente_de(Collection $cuotas): float
    {
        return round((float) $cuotas->sum(function (LicenciaCuota $cuota) {
            return $cuota->monto_pendiente();
        }), 2);
    }

    /**
     * Las señas de la venta: la relación cargada, o una consulta si no lo está. Sin id (venta sin
     * guardar) no hay señas.
     *
     * @param Venta $venta
     *
     * @return Collection<int, Sena>
     */
    protected function senas_de_la_venta(Venta $venta): Collection
    {
        if ($venta->id === null) {
            return new Collection();
        }

        if ($venta->relationLoaded('senas')) {
            return $venta->getRelation('senas');
        }

        return Sena::where('venta_id', $venta->id)->orderBy('fecha')->orderBy('id')->get();
    }

    /**
     * La suma de los montos de unas señas, a 2 decimales.
     *
     * @param Collection $senas
     *
     * @return float
     */
    protected function total_de(Collection $senas): float
    {
        return round((float) $senas->sum(function (Sena $sena) {
            return (float) $sena->monto;
        }), 2);
    }

    /**
     * La moneda de una seña de lead: la del contrato (USD o ARS); cualquier otra cosa o vacío, USD.
     *
     * @param Lead $lead
     *
     * @return string
     */
    protected function moneda_del_lead(Lead $lead): string
    {
        $moneda = strtoupper(trim((string) $lead->contract_currency));

        return in_array($moneda, LicenciaCuota::MONEDAS, true) ? $moneda : 'USD';
    }

    /**
     * La fecha de la seña: la que vino, o hoy (hora de la app).
     *
     * @param string|null $fecha
     *
     * @return Carbon
     */
    protected function fecha_o_hoy(?string $fecha): Carbon
    {
        if ($fecha === null || trim($fecha) === '') {
            return AppTime::now()->copy()->startOfDay();
        }

        return Carbon::parse($fecha)->startOfDay();
    }

    /**
     * La nota recortada a 255, o null si quedó vacía.
     *
     * @param string|null $nota
     *
     * @return string|null
     */
    protected function nota_o_null(?string $nota): ?string
    {
        $nota = trim((string) $nota);

        return $nota === '' ? null : mb_substr($nota, 0, 255);
    }

    /**
     * El origen, o `manual` si no es uno de los conocidos.
     *
     * @param string $origen
     *
     * @return string
     */
    protected function origen_valido(string $origen): string
    {
        return in_array($origen, Sena::ORIGENES, true) ? $origen : Sena::ORIGEN_MANUAL;
    }

    /**
     * Un importe para un mensaje: dos decimales, coma decimal y punto de miles.
     *
     * @param float $monto
     *
     * @return string
     */
    protected function plata(float $monto): string
    {
        return number_format($monto, 2, ',', '.');
    }
}
