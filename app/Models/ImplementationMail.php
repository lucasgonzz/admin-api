<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un mail de hito de la implementación: qué hito salió, a quién y cuándo.
 *
 * Una fila por (implementación, hito); el porqué de cada columna está en la migración
 * `create_implementation_mails_table`. El que escribe estas filas es
 * `ImplementacionMailService::enviar()` y nadie más.
 *
 * @property int         $implementation_id Implementación a la que pertenece.
 * @property string      $hito              bienvenida | instalado | acceso | imagenes | categorias | listo.
 * @property string      $email             Dirección a la que salió (o se intentó mandar).
 * @property string      $asunto            Asunto con el que salió.
 * @property string      $estado            enviado | error.
 * @property string|null $error             Motivo legible: por qué no salió, o por qué falló el último reenvío.
 * @property \Illuminate\Support\Carbon|null $enviado_at Último envío exitoso.
 * @property int         $reenvios          Veces que se volvió a mandar un hito que ya había salido.
 */
class ImplementationMail extends Model
{
    /**
     * El mail salió al menos una vez.
     */
    const ESTADO_ENVIADO = 'enviado';

    /**
     * El mail no salió nunca; `error` dice por qué. Se puede reintentar sin pedir reenvío.
     */
    const ESTADO_ERROR = 'error';

    /**
     * @var string
     */
    protected $table = 'implementation_mails';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'implementation_id' => 'integer',
        'reenvios'          => 'integer',
        'enviado_at'        => 'datetime',
    ];

    /**
     * Implementación a la que pertenece el mail.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function implementation()
    {
        return $this->belongsTo(Implementation::class);
    }

    /**
     * Indica si el mail de este hito ya salió (al menos una vez).
     *
     * @return bool
     */
    public function esta_enviado(): bool
    {
        return (string) $this->estado === self::ESTADO_ENVIADO;
    }
}
