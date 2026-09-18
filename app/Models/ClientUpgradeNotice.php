<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El aviso que se le manda al dueño cuando su sistema se actualiza.
 *
 * Una fila por (actualización, cliente), y es el estado del aviso, no una bitácora: si la fila
 * existe, el aviso de esa actualización ya se trabajó y no se vuelve a trabajar solo. El porqué de
 * cada columna está en la migración `create_client_upgrade_notices_table`.
 *
 * @property int         $client_version_upgrade_id Actualización que disparó el aviso.
 * @property int         $client_id                 Cliente al que se le avisó.
 * @property string|null $email                     Dirección a la que salió el mail.
 * @property string      $estado                    pendiente | enviado | sin_mail | sin_novedades | error
 * @property string|null $whatsapp_message_id       wamid que devolvió Meta.
 * @property string|null $error                     Motivo legible, para `error` y para el WhatsApp que no salió.
 */
class ClientUpgradeNotice extends Model
{
    /**
     * La fila la creó el hook al cerrarse el upgrade; el job todavía no la trabajó.
     */
    const ESTADO_PENDIENTE = 'pendiente';

    /**
     * El mail salió. 🔴 No dice nada del WhatsApp: ese puede haber quedado pendiente (sin
     * plantilla cargada, sin teléfono, o rechazado por Meta) y eso se lee en `whatsapp_enviado_at`
     * y en `error`. El mail es lo que lleva el contenido; el WhatsApp es el golpecito en el hombro.
     */
    const ESTADO_ENVIADO = 'enviado';

    /**
     * No hay casilla: ni en `clients.email` ni en lo que contestó el `empresa-api` del cliente.
     *
     * 🔴 Está separado de `error` a propósito, y es el estado MÁS FRECUENTE durante semanas: el
     * endpoint `admin-sync/contacto-dueno` es nuevo y ningún cliente lo tiene hasta que se
     * actualiza a una versión que lo traiga. Eso no es una falla del sistema, es un dato que
     * todavía no está — y mezclarlo con `error` haría que la lista de errores sea inútil justo
     * cuando hay que mirarla.
     */
    const ESTADO_SIN_MAIL = 'sin_mail';

    /**
     * Hay casilla, pero esas versiones no traen ninguna novedad visible para ESTE cliente (porque
     * no se cargó ninguna, o porque las que hay están restringidas a otros clientes).
     *
     * 🔴 Estado propio y no `enviado` con una nota. El mail no se manda a propósito: uno que dice
     * "actualizamos tu sistema" y abajo no tiene nada es peor que no mandarlo. Marcarlo `enviado`
     * sería escribir en la tabla que salió algo que no salió, y el día que alguien pregunte "¿a
     * quién le avisamos?" la respuesta estaría mal.
     */
    const ESTADO_SIN_NOVEDADES = 'sin_novedades';

    /**
     * Falló de verdad: el mail reventó, o el aviso entero se cayó. El motivo queda en `error`.
     */
    const ESTADO_ERROR = 'error';

    /**
     * Todas las filas las escribe el propio canal (el hook y el job), nunca un request de usuario:
     * no hay entrada de afuera de la que protegerse con una lista blanca.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_version_upgrade_id' => 'integer',
        'client_id'                 => 'integer',
        'mail_enviado_at'           => 'datetime',
        'whatsapp_enviado_at'       => 'datetime',
    ];

    /**
     * Cliente al que se le avisó.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Actualización que disparó el aviso.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client_version_upgrade()
    {
        return $this->belongsTo(ClientVersionUpgrade::class);
    }

    /**
     * Si este aviso todavía está esperando que alguien lo trabaje.
     *
     * Es la pregunta que hacen el hook (antes de despachar) y el job (antes de salir a la red),
     * para que cerrar dos veces la misma actualización no mande dos mails.
     *
     * @return bool
     */
    public function esta_pendiente(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    /**
     * Si todavía falta mandar el mail.
     *
     * 🔴 Se pregunta por la FECHA y no por el estado, y esa es la diferencia que hace que un
     * reintento no mande dos veces lo mismo. `mail_enviado_at` es un hecho —el mail salió, no se
     * puede desmandar—; el `estado` es un resumen que el reintento va a reescribir.
     *
     * @return bool
     */
    public function mail_pendiente(): bool
    {
        return is_null($this->mail_enviado_at);
    }

    /**
     * Si todavía falta mandar el WhatsApp.
     *
     * El caso que esto habilita es el más fino del canal: el mail salió y el WhatsApp no (porque
     * no había plantilla cargada en Meta, o porque Kapso lo rechazó). Ese aviso queda `enviado`
     * —el estado dice la verdad: el contenido llegó— con `whatsapp_enviado_at` en null y el motivo
     * en `error`, y un reintento posterior manda SOLO el WhatsApp.
     *
     * @return bool
     */
    public function whatsapp_pendiente(): bool
    {
        return is_null($this->whatsapp_enviado_at);
    }
}
