<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ficha de contexto de un cliente para el agente de soporte.
 *
 * Dos textos libres en markdown con destinos distintos:
 *   - `ficha_operativa`: se inyecta en el prompt del agente en CADA consulta.
 *   - `notas_internas`:  no se inyecta nunca. Es para el operador humano.
 *
 * 🔴 NADA CALCULABLE VIVE ACÁ. Tickets abiertos, antigüedad, versión que corre, cantidad de
 * mensajes y veces que se escaló los arma SupportClientContextService leyendo la base al momento
 * de construir el prompt. Si mañana alguien agrega una columna `tickets_abiertos` a esta tabla,
 * está creando un dato que se desactualiza en silencio y que el agente va a leer con confianza.
 */
class ClientSupportContext extends Model
{
    /**
     * Origen de una ficha cargada por la sesión de Claude vía POST claude/client-context.
     *
     * Mismo criterio que `admin_tasks.created_via` y `client_version_upgrades.created_via`.
     *
     * @var string
     */
    const CREATED_VIA_CLAUDE = 'claude';

    /**
     * Tabla de fichas de contexto de soporte.
     *
     * @var string
     */
    protected $table = 'client_support_contexts';

    /**
     * Campos asignables.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'ficha_operativa',
        'notas_internas',
        'created_via',
    ];

    /**
     * Cliente dueño de la ficha.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Devuelve la ficha operativa de un cliente, o null si no tiene ninguna cargada.
     *
     * 🔴 ESTE ES EL ÚNICO CAMINO POR EL QUE LA TABLA LLEGA AL PROMPT, Y POR ESO NO DEVUELVE UN
     * MODELO. Es un `value('ficha_operativa')`: un SELECT de UNA columna. Una instancia de
     * ClientSupportContext traería `notas_internas` cargada al mismo scope donde se arma el
     * prompt, a un `toArray()`, un `implode` o un log de debug de distancia — y esa nota es
     * justamente lo que nunca tiene que llegarle al agente, porque un juicio sobre la persona
     * ("es de trato difícil") condicionaría el tono de la respuesta que se le manda a esa misma
     * persona.
     *
     * ⚠️ Si alguien "simplifica" esto a `ClientSupportContext::where(...)->first()->ficha_operativa`
     * el test de fuga de ContextoDeClienteParaElAgenteTest no lo agarra —el prompt sigue sin la
     * nota— pero la garantía deja de ser estructural y pasa a depender de que nadie escriba una
     * línea de más. No se hace.
     *
     * @param int $client_id Id del cliente.
     *
     * @return string|null Texto de la ficha, o null si no hay fila o está vacía.
     */
    public static function ficha_operativa_de_cliente($client_id)
    {
        $ficha = DB::table('client_support_contexts')
            ->where('client_id', (int) $client_id)
            ->value('ficha_operativa');

        if ($ficha === null) {
            return null;
        }

        $ficha = trim((string) $ficha);

        return $ficha === '' ? null : $ficha;
    }
}
