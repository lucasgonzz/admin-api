<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Sena;
use App\Models\Venta;
use App\Services\SenaService;
use App\Services\VentaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Las señas desde Claude/ChatGPT (misión senas-en-ventas, 30/10/2026).
 *
 * Una seña es un pago a cuenta que el cliente deja antes (o aparte) de las cuotas: se aplica sola a
 * las cuotas pendientes más próximas de la venta, así que deuda, cobrado y comisión liberada
 * cuadran como con cualquier cobro. Tres endpoints:
 *
 *  - `POST claude/ventas/{ventaId}/senas` — registra una seña en una venta y la reparte entre sus
 *    cuotas. El freno de nombre es el del CLIENTE de la venta (`confirm_client_name`).
 *  - `POST claude/leads/{id}/senas` — registra una seña en un lead que todavía no se promovió: queda
 *    pendiente y se aplica sola cuando el lead se promueve. El freno de nombre es el del LEAD
 *    (`confirm_lead_name` = `leads.contact_name`).
 *  - `GET claude/leads/{id}/senas` — las señas del lead (pendientes y ya aplicadas) y su total.
 *
 * Los frenos son los del bloque `claude/*` (el patrón de `ClaudeCobranzasVentasController`):
 *  1. `dry_run` por defecto true: se corre EL MISMO servicio que después escribe —adentro de una
 *     transacción que se deshace siempre— y se devuelve cómo quedaría todo (a qué cuotas se
 *     aplicaría la seña, la venta antes y después). Así un dry_run que pasa predice una escritura
 *     que pasa, y no queda ni una fila.
 *  2. Con `dry_run=false`, el nombre confirmado tiene que coincidir (recorte y minúsculas) con el
 *     del cliente de la venta o con el del lead; el error no revela el nombre correcto.
 *
 * 🔴 Ninguna cuenta de plata está escrita acá: se reusa `SenaService`, que reparte con
 * `LicenciaCuotaService::registrar_pago()`. Escribe filas del admin (señas y cuotas) pero no toca el
 * sistema de ningún negocio ni le manda nada a nadie: peligrosidad `baja` en el catálogo.
 *
 * Los errores salen con la forma única del bloque: `{"error": "..."}` con 404 o 422.
 */
class ClaudeSenasController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Mensajes de validación en español para las reglas que no cubre el trait (`numeric`).
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return [
            'required' => 'El parámetro :attribute es obligatorio.',
            'date'     => 'El parámetro :attribute tiene que ser una fecha válida.',
            'numeric'  => 'El parámetro :attribute tiene que ser un número.',
            'boolean'  => 'El parámetro :attribute tiene que ser booleano (1, 0, true o false).',
            'string'   => 'El parámetro :attribute tiene que ser texto.',
            'min'      => 'El parámetro :attribute está por debajo del mínimo permitido (la seña tiene que ser mayor que cero).',
            'max'      => 'El parámetro :attribute supera el máximo permitido.',
        ];
    }

    /**
     * Registra una seña en una venta y la reparte entre sus cuotas pendientes.
     *
     * Reglas (valen igual en el dry_run y en el real, porque corre el mismo servicio):
     *  - `monto` mayor que cero y como mucho lo pendiente de la venta (422 si no);
     *  - la moneda es siempre la de la venta;
     *  - se aplica a las cuotas pendientes/parciales por vencimiento (las sin vencimiento al final) y número.
     *
     * @param Request      $request {monto, fecha?, nota?, dry_run?, confirm_client_name?}
     * @param int|string   $ventaId Venta.
     * @param SenaService  $senas
     * @param VentaService $ventas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_venta_json(Request $request, $ventaId, SenaService $senas, VentaService $ventas)
    {
        $venta = Venta::with(['cuotas', 'comision_pagos', 'vendedor', 'client', 'senas'])->find((int) $ventaId);
        if ($venta === null) {
            return $this->error_404('no existe la venta ' . $ventaId);
        }

        $client = $venta->client;
        if ($client === null) {
            return $this->error_404('la venta ' . $ventaId . ' no tiene un cliente cargado');
        }

        $invalido = $this->validar_o_422($request, [
            'monto'               => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'fecha'               => ['nullable', 'date'],
            'nota'                => ['nullable', 'string', 'max:255'],
            'dry_run'             => ['nullable', 'boolean'],
            'confirm_client_name' => ['nullable', 'string', 'max:190'],
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $monto = round((float) $request->input('monto'), 2);
        $fecha = $this->texto_o_null($request->input('fecha'));
        $nota = $this->texto_o_null($request->input('nota'));

        $venta_antes = $ventas->venta_para_json($venta);

        /* Freno 1: sin `dry_run=false` explícito esto no escribe nada. */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            try {
                $simulado = $this->simular(function () use ($senas, $ventas, $venta, $monto, $fecha, $nota) {
                    $sena = $senas->registrar_en_venta($venta, $monto, $fecha, $nota, null, Sena::ORIGEN_CLAUDE);

                    return [
                        'sena'  => $senas->sena_para_json($sena),
                        'venta' => $ventas->venta_para_json($this->venta_fresca($venta->id)),
                    ];
                });
            } catch (ValidationException $e) {
                return $this->error_422($this->mensaje_de($e, 'La seña no se puede registrar') . ' No se escribió nada.', [
                    'client_id' => (int) $client->id,
                    'venta_id'  => (int) $venta->id,
                    'pendiente' => $venta_antes['resumen']['pendiente'],
                    'moneda'    => (string) $venta->moneda,
                    'detalles'  => $e->validator->errors()->all(),
                ]);
            }

            /* El id de la seña simulada no existe: la fila se deshizo. */
            $simulado['sena']['id'] = null;

            return response()->json([
                'dry_run'       => true,
                'escribio'      => false,
                'client'        => $this->client_json($venta),
                'sena'          => $simulado['sena'],
                'aplicaciones'  => $simulado['sena']['aplicaciones'],
                'venta_antes'   => $venta_antes,
                'venta_despues' => $simulado['venta'],
                'nota'          => 'No se escribió NADA. Así quedarían las cuotas con esta seña (en `aplicaciones` está qué parte iría a qué cuota). '
                    . 'Para registrarla de verdad, repetí la misma llamada con dry_run=false y confirm_client_name con el '
                    . 'nombre exacto del cliente de la venta.',
            ], 200);
        }

        /* Freno 2: el nombre del cliente DE LA VENTA. */
        $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se escribió nada.');
        if ($rechazo !== null) {
            return $rechazo;
        }

        try {
            $sena = $senas->registrar_en_venta($venta, $monto, $fecha, $nota, null, Sena::ORIGEN_CLAUDE);
        } catch (ValidationException $e) {
            return $this->error_422($this->mensaje_de($e, 'La seña no se puede registrar') . ' No se escribió nada.', [
                'client_id' => (int) $client->id,
                'venta_id'  => (int) $venta->id,
                'pendiente' => $venta_antes['resumen']['pendiente'],
                'moneda'    => (string) $venta->moneda,
                'detalles'  => $e->validator->errors()->all(),
            ]);
        }

        return response()->json(array_merge([
            'dry_run'  => false,
            'escribio' => true,
            'sena'     => $senas->sena_para_json($sena),
            'venta'    => $ventas->venta_para_json($this->venta_fresca($venta->id)),
        ], $ventas->lista_del_cliente($client)), 201);
    }

    /**
     * Registra una seña en un lead que todavía no se promovió. Queda pendiente (sin venta) y se
     * aplica sola a la venta del contrato cuando el lead se promueve.
     *
     * Un lead ya promovido es 422: la seña se registra en la venta de su cliente. La moneda es la
     * del contrato del lead (USD si no tiene).
     *
     * @param Request     $request {monto, fecha?, nota?, dry_run?, confirm_lead_name?}
     * @param int|string  $id      Lead.
     * @param SenaService $senas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_lead_json(Request $request, $id, SenaService $senas)
    {
        $lead = Lead::find((int) $id);
        if ($lead === null) {
            return $this->error_404('no existe el lead ' . $id);
        }

        $invalido = $this->validar_o_422($request, [
            'monto'             => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'fecha'             => ['nullable', 'date'],
            'nota'              => ['nullable', 'string', 'max:255'],
            'dry_run'           => ['nullable', 'boolean'],
            'confirm_lead_name' => ['nullable', 'string', 'max:190'],
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $monto = round((float) $request->input('monto'), 2);
        $fecha = $this->texto_o_null($request->input('fecha'));
        $nota = $this->texto_o_null($request->input('nota'));

        $antes = $senas->senas_del_lead_para_json($lead);

        /* Freno 1: sin `dry_run=false` explícito esto no escribe nada. */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            try {
                $simulado = $this->simular(function () use ($senas, $lead, $monto, $fecha, $nota) {
                    $sena = $senas->registrar_en_lead($lead, $monto, $fecha, $nota, null, Sena::ORIGEN_CLAUDE);

                    return [
                        'sena'   => $senas->sena_para_json($sena),
                        'estado' => $senas->senas_del_lead_para_json($lead),
                    ];
                });
            } catch (ValidationException $e) {
                return $this->error_422($this->mensaje_de($e, 'La seña no se puede registrar') . ' No se escribió nada.', [
                    'lead_id'  => (int) $lead->id,
                    'detalles' => $e->validator->errors()->all(),
                ]);
            }

            $simulado['sena']['id'] = null;

            return response()->json([
                'dry_run'                  => true,
                'escribio'                 => false,
                'lead'                     => $this->lead_json($lead),
                'sena'                     => $simulado['sena'],
                'senas_pendientes_antes'   => $antes['total'],
                'senas_pendientes_despues' => $simulado['estado']['total'],
                'nota'                     => 'No se escribió NADA. La seña quedaría anotada en el lead, pendiente, y se aplicaría sola a las cuotas '
                    . 'de la venta del contrato cuando el lead se promueva. Para registrarla de verdad, repetí la misma llamada con '
                    . 'dry_run=false y confirm_lead_name con el nombre exacto del lead.',
            ], 200);
        }

        /* Freno 2: el nombre del lead. */
        $rechazo = $this->rechazar_si_el_nombre_del_lead_no_confirma($request, $lead, 'No se escribió nada.');
        if ($rechazo !== null) {
            return $rechazo;
        }

        try {
            $sena = $senas->registrar_en_lead($lead, $monto, $fecha, $nota, null, Sena::ORIGEN_CLAUDE);
        } catch (ValidationException $e) {
            return $this->error_422($this->mensaje_de($e, 'La seña no se puede registrar') . ' No se escribió nada.', [
                'lead_id'  => (int) $lead->id,
                'detalles' => $e->validator->errors()->all(),
            ]);
        }

        return response()->json(array_merge([
            'dry_run'  => false,
            'escribio' => true,
            'lead'     => $this->lead_json($lead),
            'sena'     => $senas->sena_para_json($sena),
        ], $senas->senas_del_lead_para_json($lead)), 201);
    }

    /**
     * Las señas registradas en un lead: la lista (con `aplicada` para distinguir las pendientes de
     * las ya aplicadas a una venta), el total pendiente y el total ya aplicado. Solo lectura.
     *
     * @param int|string  $id Lead.
     * @param SenaService $senas
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_lead_json($id, SenaService $senas)
    {
        $lead = Lead::find((int) $id);
        if ($lead === null) {
            return $this->error_404('no existe el lead ' . $id);
        }

        return response()->json(array_merge(
            ['lead' => $this->lead_json($lead)],
            $senas->senas_del_lead_para_json($lead)
        ), 200);
    }

    /**
     * Freno del nombre para un LEAD: `confirm_lead_name` tiene que coincidir con `leads.contact_name`
     * (recorte y minúsculas). Igual que el del cliente, el error NO revela el nombre correcto: es un
     * freno, no un formulario a completar. Un lead sin nombre cargado no se puede confirmar nunca, y
     * el mensaje lo dice (en vez de mentir con «no coincide»).
     *
     * @param Request $request Request entrante.
     * @param Lead    $lead    Lead involucrado.
     * @param string  $cierre  Cómo termina el mensaje de error.
     *
     * @return \Illuminate\Http\JsonResponse|null Null si confirma bien.
     */
    protected function rechazar_si_el_nombre_del_lead_no_confirma(Request $request, Lead $lead, $cierre)
    {
        $recibido = $this->normalizar_nombre($request->input('confirm_lead_name'));
        $real     = $this->normalizar_nombre($lead->contact_name);

        if ($real === '') {
            return $this->error_422(
                'El lead NO tiene nombre de contacto cargado en el admin: por eso no se puede confirmar con '
                    . 'confirm_lead_name y esta operación no se puede hacer. ' . $cierre,
                [
                    'lead_id' => (int) $lead->id,
                    'ayuda'   => 'Abrí el lead en el admin y cargale el nombre de contacto. Sin nombre no hay con qué confirmar '
                        . 'contra qué lead se está operando, y este freno no se saltea.',
                ]
            );
        }

        if ($recibido !== '' && $recibido === $real) {
            return null;
        }

        return $this->error_422(
            'confirm_lead_name no coincide con el nombre del lead de esta operación. ' . $cierre,
            [
                'lead_id' => (int) $lead->id,
                'ayuda'   => 'Verificá contra qué lead estás operando con GET claude/leads/' . (int) $lead->id . '/senas. '
                    . 'La respuesta de este error no dice el nombre a propósito: es un freno, no un formulario a completar.',
            ]
        );
    }

    /**
     * Corre una acción de escritura adentro de una transacción que se DESHACE siempre, y devuelve lo
     * que la acción devolvió: es el dry_run. Se ejecuta el mismo servicio que después escribe (así
     * lo que el dry_run predice es lo que la escritura hace), pero no queda ni una fila. Si la acción
     * lanza (por ejemplo una `ValidationException`), la transacción se deshace igual y la excepción
     * sigue su camino. Es la misma técnica que `ClaudeCobranzasVentasController::simular()`.
     *
     * @param callable $accion Lo que se simula.
     *
     * @return mixed Lo que devolvió `$accion`.
     */
    protected function simular(callable $accion)
    {
        DB::beginTransaction();

        try {
            return $accion();
        } finally {
            DB::rollBack();
        }
    }

    /**
     * La venta con todo lo que necesita `venta_para_json()`, leída de la base en este momento.
     *
     * @param int $venta_id
     *
     * @return Venta
     */
    protected function venta_fresca($venta_id): Venta
    {
        return Venta::with(['cuotas', 'comision_pagos', 'vendedor', 'client', 'senas'])->findOrFail($venta_id);
    }

    /**
     * El primer mensaje de una `ValidationException`, o un texto por defecto si no trae ninguno.
     *
     * @param ValidationException $e
     * @param string              $por_defecto
     *
     * @return string
     */
    protected function mensaje_de(ValidationException $e, string $por_defecto): string
    {
        $mensajes = $e->validator->errors()->all();

        return count($mensajes) > 0 ? (string) $mensajes[0] : $por_defecto . '.';
    }

    /**
     * Los datos mínimos del cliente de la venta para el dry_run (id, uuid y nombre).
     *
     * @param Venta $venta
     *
     * @return array{id: int, uuid: string, name: string}
     */
    protected function client_json(Venta $venta): array
    {
        $client = $venta->client;

        return [
            'id'   => (int) $client->id,
            'uuid' => (string) $client->uuid,
            'name' => (string) $client->name,
        ];
    }

    /**
     * Los datos mínimos del lead (id, nombre de contacto y empresa).
     *
     * @param Lead $lead
     *
     * @return array{id: int, contact_name: string|null, company_name: string|null, promoted_client_id: int|null}
     */
    protected function lead_json(Lead $lead): array
    {
        return [
            'id'                 => (int) $lead->id,
            'contact_name'       => $lead->contact_name !== null ? (string) $lead->contact_name : null,
            'company_name'       => $lead->company_name !== null ? (string) $lead->company_name : null,
            'promoted_client_id' => $lead->promoted_client_id !== null ? (int) $lead->promoted_client_id : null,
        ];
    }
}
