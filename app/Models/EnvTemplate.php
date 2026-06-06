<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla base de variables .env del sistema.
 *
 * Cada variable puede marcarse como:
 * - is_common: su valor se contrasta con los clientes al ejecutar una actualización.
 * - is_manual_on_create: se muestra como recordatorio al dar de alta un sistema nuevo.
 *
 * @property int    $id
 * @property string $key                  Nombre de la variable (ej: MAIL_HOST).
 * @property string|null $value           Valor del template base.
 * @property string|null $group           Grupo funcional: mail, pusher, db, app, misc.
 * @property bool   $is_common            Contraste al actualizar clientes.
 * @property bool   $is_manual_on_create  Recordatorio al crear sistema nuevo.
 * @property string|null $notes           Notas internas para el operador.
 * @property int    $sort_order           Orden de aparición dentro del grupo.
 */
class EnvTemplate extends Model
{
    /**
     * Permite asignación masiva de todos los campos.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casts a tipos nativos para los campos booleanos y numérico.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_common'           => 'boolean',
        'is_manual_on_create' => 'boolean',
        'sort_order'          => 'integer',
    ];
}
