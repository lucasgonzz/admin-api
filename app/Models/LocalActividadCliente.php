<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un evento que el sistema de un cliente le avisó al admin (misión el-local, 3/11/2026, contrato C): un
 * inicio de sesión, uso, un escaneo de factura, una respuesta del agente del negocio…
 *
 * Lo escribe {@see \App\Services\Local\ActividadClientesService} desde `POST api/inbound/local/actividad`
 * y lo lee la escena de «El local». Solo tiene `created_at` (los eventos no se editan) y se borra a los
 * 7 días con `local:podar`.
 *
 * @property int         $client_id
 * @property string      $tipo
 * @property string|null $usuario
 * @property array|null  $detalle
 * @property string      $huella
 */
class LocalActividadCliente extends Model
{
    /** Tipos de evento que acepta el contrato C. */
    const TIPOS = ['login', 'actividad', 'logout', 'escaneo', 'agente_negocio', 'asistente', 'otro'];

    /** El escaneo de una factura de compra: lo muestra el robot de facturas. */
    const TIPO_ESCANEO = 'escaneo';

    /** El agente de WhatsApp del negocio le respondió a un cliente final: lo muestra el robot del negocio. */
    const TIPO_AGENTE_NEGOCIO = 'agente_negocio';

    /** El usuario cerró sesión: es irse del local, no actividad. */
    const TIPO_LOGOUT = 'logout';

    /** Los eventos no se editan: no hay updated_at. */
    const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'local_actividad_clientes';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'tipo',
        'usuario',
        'detalle',
        'ocurrido_at',
        'huella',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'   => 'integer',
        'detalle'     => 'array',
        'ocurrido_at' => 'datetime',
    ];
}
