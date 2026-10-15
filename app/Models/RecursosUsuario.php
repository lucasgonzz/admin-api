<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un usuario del centro de recursos: el espejo de un `User` del `empresa-api` de un cliente
 * (misión centro-de-recursos-clientes, 13/10/2026). NO es un usuario del admin ni tiene tokens de
 * Sanctum (ver `App\Http\Middleware\RecursosSesion`).
 *
 * @property int         $id
 * @property int         $client_id
 * @property int|null    $empresa_user_id
 * @property string      $nombre
 * @property string|null $email
 * @property bool        $es_dueno
 * @property \Carbon\Carbon|null $ultimo_ingreso_at
 */
class RecursosUsuario extends Model
{
    protected $table = 'recursos_usuarios';

    protected $guarded = [];

    protected $casts = [
        'client_id'         => 'integer',
        'empresa_user_id'   => 'integer',
        'es_dueno'          => 'boolean',
        'ultimo_ingreso_at' => 'datetime',
    ];

    /**
     * El nombre de pila: la primera palabra del nombre. Es lo ÚNICO que los demás clientes ven del
     * autor de una pregunta (decisión de Lucas, 13/10/2026): ni el apellido ni el negocio.
     *
     * @param string|null $nombre
     *
     * @return string
     */
    public static function nombre_de_pila(?string $nombre): string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', (string) $nombre));

        if ($limpio === '') {
            return 'Usuario';
        }

        $partes = explode(' ', $limpio);

        return $partes[0];
    }

    /**
     * El usuario como lo ve ÉL MISMO en la cabecera de la página (`yo`, `sesion/*`). Es la única
     * respuesta que lleva el negocio: nunca se arma para mostrar a otro usuario.
     *
     * @return array<string, mixed>
     */
    public function para_si_mismo(): array
    {
        $client = $this->client;

        return [
            'nombre'      => $this->nombre,
            'nombre_pila' => self::nombre_de_pila($this->nombre),
            'es_dueno'    => (bool) $this->es_dueno,
            'negocio'     => $client instanceof Client ? $client->resolve_display_name() : '',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function sesiones()
    {
        return $this->hasMany(RecursosSesion::class, 'recursos_usuario_id');
    }

    public function links()
    {
        return $this->hasMany(RecursosLink::class, 'recursos_usuario_id');
    }

    public function progresos()
    {
        return $this->hasMany(RecursosProgreso::class, 'recursos_usuario_id');
    }
}
