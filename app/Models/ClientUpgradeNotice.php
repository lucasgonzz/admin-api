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
 * @property string      $estado                    pendiente | enviando | enviado | sin_mail | sin_novedades | error
 * @property \Illuminate\Support\Carbon|null $dispatch_started_at Cuándo un worker reclamó este aviso.
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
     * Un worker RECLAMÓ este aviso y lo está trabajando ahora mismo.
     *
     * 🔴 **Es el estado que hace que dos workers no manden dos mails al mismo dueño.** El
     * scheduler corre `queue:work database --stop-when-empty` cada minuto y a propósito NO usa
     * `withoutOverlapping()` (está escrito y explicado en `Console/Kernel.php`), así que puede
     * haber dos workers vivos a la vez. Sin este estado los dos leen la fila en `pendiente`, los
     * dos pasan la resolución de la casilla (HTTP, hasta 15 s), la consulta de novedades y el
     * SMTP antes de que ninguno escriba `mail_enviado_at`, y salen dos mails.
     *
     * El reclamo lo hace `AvisoDeActualizacionService::reclamar()` con un UPDATE condicional: el
     * que lo gana afecta una fila, el que llega segundo afecta cero y se va sin mandar nada.
     *
     * Es un estado de PASO: todo camino normal lo deja en `enviado`, `sin_mail`, `sin_novedades` o
     * `error` antes de devolver. Si queda acá, el proceso murió a mitad — y para eso está
     * `dispatch_started_at` y el scope `colgados()`.
     */
    const ESTADO_ENVIANDO = 'enviando';

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
     * A los cuántos minutos un aviso reclamado (`enviando`) se da por colgado.
     *
     * El job tiene `$timeout = 120`, así que a los diez minutos el worker que lo reclamó ya no
     * existe de ninguna manera: o terminó y dejó la fila en otro estado, o lo mató el sistema
     * operativo sin darle tiempo a soltar el reclamo. El margen es holgado a propósito — dar por
     * colgado uno que sigue vivo es exactamente el mail duplicado que el reclamo vino a evitar.
     */
    const MINUTOS_PARA_DAR_POR_COLGADO = 10;

    /**
     * A los cuántos minutos un aviso en `pendiente` deja de ser "lo tiene la cola" y pasa a ser
     * "el worker no está corriendo".
     *
     * El scheduler despacha `queue:work database --stop-when-empty` cada minuto, así que un aviso
     * recién creado se trabaja en segundos. Media hora sin moverse no es demora: es que nadie lo
     * está consumiendo. `Console/Kernel.php` documenta que ese scheduler se muere por ratos, y el
     * job tiene `$tries = 1` y no tiene `failed()`, así que no hay nada más que lo levante.
     */
    const MINUTOS_PARA_SOSPECHAR_DEL_WORKER = 30;

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
        'dispatch_started_at'       => 'datetime',
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

    /**
     * Avisos reclamados por un worker que ya no existe.
     *
     * Un aviso queda así solo si el proceso murió entre el reclamo y el resultado: todos los
     * caminos normales de `AvisoDeActualizacionService` lo sacan de `enviando` antes de devolver,
     * incluido el `catch` que lo cierra con `error`. O sea que esto atrapa lo que ningún `catch`
     * puede atrapar — el proceso matado.
     *
     * 🔴 Es lo único que impide que un reclamo sea una trampa permanente. Sin esto, un worker
     * muerto a mitad dejaría el aviso en `enviando` para siempre: invisible para el hook (que solo
     * despacha los `pendiente`) y para el reintento a mano.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeColgados($query)
    {
        return $query->where('estado', self::ESTADO_ENVIANDO)
            ->where(function ($sub) {
                $sub->whereNull('dispatch_started_at')
                    ->orWhere('dispatch_started_at', '<=', now()->subMinutes(self::MINUTOS_PARA_DAR_POR_COLGADO));
            });
    }

    /**
     * Avisos que hace rato están esperando que la cola los tome.
     *
     * Son los `pendiente` viejos —el job nunca corrió— y los `colgados()` —el job corrió y se
     * murió a mitad—. Los dos se miran por el mismo motivo y con el mismo ojo: si esta lista no
     * está vacía, el `queue:work` del scheduler no está corriendo.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEsperandoALaCola($query)
    {
        return $query->whereNull('mail_enviado_at')
            ->where(function ($sub) {
                $sub->where(function ($pendientes) {
                    $pendientes->where('estado', self::ESTADO_PENDIENTE)
                        ->where('created_at', '<=', now()->subMinutes(self::MINUTOS_PARA_SOSPECHAR_DEL_WORKER));
                })->orWhere(function ($colgados) {
                    $colgados->colgados();
                });
            });
    }
}
