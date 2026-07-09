<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Comprobante (Factura C) emitido contra AFIP (WSFE) por la mensualidad de un
 * Client (prompt 331). Cada fila es un intento de emisión: puede haber más de
 * una por client_id + periodo si un intento anterior fue rechazado ('R'); la
 * emisión autorizada ('A') con `cae` no nulo es la que cuenta como "ya facturado".
 *
 * @property int         $client_id                 Cliente facturado.
 * @property string       $periodo                   Período facturado ('YYYY-MM').
 * @property int          $cbte_tipo                 Tipo de comprobante AFIP (11 = Factura C).
 * @property string       $cbte_letra                Letra del comprobante ('C').
 * @property int|null     $cbte_numero               Número de comprobante asignado.
 * @property int|null     $punto_venta               Punto de venta usado.
 * @property string|null  $cuit_negocio              CUIT del emisor (ComercioCity).
 * @property string|null  $cuit_cliente              CUIT/documento del receptor.
 * @property int|null     $doc_tipo                  Tipo de documento AFIP del receptor (80 = CUIT).
 * @property string|null  $doc_nro                   Número de documento del receptor.
 * @property float|null   $importe_total             Importe total facturado.
 * @property float|null   $imp_neto                  Importe neto (= importe_total en Factura C).
 * @property float|null   $imp_iva                   Importe de IVA (siempre 0 en Factura C).
 * @property int|null     $condicion_iva_receptor_id Condición IVA del receptor enviada a AFIP.
 * @property string|null  $cae                       CAE devuelto por AFIP si fue autorizado.
 * @property string|null  $cae_expired_at            Fecha de vencimiento del CAE.
 * @property string|null  $resultado                 'A' (aprobado) o 'R' (rechazado).
 * @property string|null  $error_message             Mensaje de error legible si fue rechazado.
 * @property string|null  $request                   Request SOAP crudo enviado a AFIP.
 * @property string|null  $response                  Response SOAP crudo recibido de AFIP.
 * @property bool         $afip_produccion           Si se emitió contra producción u homologación.
 */
class MensualidadInvoice extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casteos de tipos para lectura y persistencia consistente.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'cbte_tipo' => 'integer',
        'cbte_numero' => 'integer',
        'punto_venta' => 'integer',
        'doc_tipo' => 'integer',
        'importe_total' => 'decimal:2',
        'imp_neto' => 'decimal:2',
        'imp_iva' => 'decimal:2',
        'condicion_iva_receptor_id' => 'integer',
        'cae_expired_at' => 'date',
        'afip_produccion' => 'boolean',
    ];

    /**
     * Scope requerido por convención del workspace para que Controller::fullModel()
     * pueda hacer eager load de relaciones cuando se exponga este modelo.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with('client');
    }

    /**
     * Cliente al que se le facturó esta mensualidad.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
