<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un mensaje del número PERSONAL de WhatsApp (misión wa-personal-coexistencia).
 *
 * Puede ser un entrante de un cliente, un saliente enviado por la API o el eco de lo que Lucas escribió desde
 * la app de WhatsApp Business. Vive en su propia tabla y ningún otro código del admin la lee.
 */
class WhatsappPersonalMessage extends Model
{
    /** Dirección: lo escribió el cliente. */
    const DIRECCION_ENTRANTE = 'in';

    /** Dirección: salió del número personal. */
    const DIRECCION_SALIENTE = 'out';

    /** Origen: enviado por la API de Kapso (desde este admin). */
    const ORIGEN_API = 'cloud_api';

    /** Origen: escrito por Lucas desde la app de WhatsApp Business (eco). */
    const ORIGEN_APP = 'business_app';

    /** Origen: importado en una sincronización de historial; nunca abre ventana ni cuenta como no leído. */
    const ORIGEN_HISTORIAL = 'history_sync';

    /** Origen: cualquier otro que mande Kapso. */
    const ORIGEN_OTRO = 'otro';

    /**
     * Nombre de la tabla.
     *
     * @var string
     */
    protected $table = 'whatsapp_personal_messages';

    /**
     * Todo es asignable: sólo lo escriben los servicios de WhatsappPersonal, nunca un pedido HTTP directo.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casteos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /**
     * Scope vacío por convención del repo para modelos nuevos.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Consulta.
     *
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}
