<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Marcador de "esta sugerencia se está enviando por WhatsApp EN ESTE MOMENTO", visible desde
 * cualquier otro request de la misma máquina.
 *
 * ¿QUÉ PROTEGE? Un inbound del lead dispara
 * LeadAiSuggestionScheduler::clear_stale_pending_suggestions(), que hace un DELETE real (LeadMessage
 * NO usa SoftDeletes) de toda sugerencia en estado 'sugerido'. Mientras tanto,
 * LeadSuggestionSendService::send_suggestion() puede estar a mitad de camino con ESA misma fila:
 * revalidando horarios contra Google, aplicando pending_actions, o mandando las partes por Kapso con
 * pausas de 1200ms entre una y otra y backoff de hasta 3500ms por reintento. La ventana peligrosa
 * son SEGUNDOS, no milisegundos, y el contestador automático de un lead contesta al toque. Cuando la
 * carrera se pierde, el lead recibe el mensaje y el hilo muestra un bloque rojo diciendo que no se
 * envió: el sistema le miente al setter en la dirección más cara, y el setter lo manda de nuevo.
 *
 * ¿POR QUÉ EN CACHE Y NO EN UNA COLUMNA DE lead_messages? Por dos razones que no son de comodidad:
 *
 *   1. El marcador tiene que vivir FUERA de la fila que protege. Una columna `send_started_at`
 *      guarda el escudo adentro de la cosa que puede desaparecer: cuando salta la red de seguridad
 *      —la fila ya no está— el marcador tampoco, justo cuando más falta hace consultarlo.
 *   2. El destrabador no puede ser el mismo proceso que puso el estado (clase del 13/8/2026 en
 *      APRENDER_NO_PARCHEAR.md: "todo estado intermedio necesita un proceso que lo destrabe que no
 *      sea el mismo que lo puso ahí"). Si el request muere a mitad —fatal por memoria, worker
 *      reciclado, kill -9— ningún `finally` corre. Acá el destrabador es el TTL, que no es nadie.
 *      Con una columna haría falta además una cota temporal en el WHERE del barrido; sin ella, un
 *      request muerto deja la sugerencia inmortal.
 *
 * ¿DE DÓNDE SALE EL TTL DE 300 SEGUNDOS? Es la cota del envío en el peor caso realista, medida sobre
 * enviar_partes(): 6 partes × 3 intentos de ~5s de HTTP + 5s de backoff acumulado (1500ms + 3500ms)
 * + 1,2s de pausa entre partes ≈ 130s, más apply_pending_actions() pegándole a Google Calendar ≈ 30s.
 * Total ≈ 160s. 300s deja margen y sigue siendo cortísimo frente al daño que evita: lo peor que
 * puede pasar si el TTL se queda corto es volver al bug de hoy, y lo peor si se pasa es que un
 * barrido de más no borre una sugerencia durante cinco minutos.
 *
 * ¿POR QUÉ LAS DOS CLAVES VIVEN JUNTAS ACÁ? Porque son las dos mitades del MISMO trato entre el
 * barrido y el envío, y separarlas garantiza que alguien cambie una y no la otra. Proteger la
 * sugerencia en vuelo abre un agujero nuevo: el barrido ya no la borra, así que
 * GenerateLeadAiSuggestionJob la ve pendiente (has_pending_non_followup_suggestion) y SE OMITE — el
 * mensaje que el lead acaba de escribir se queda sin respuesta hasta que vuelva a escribir. La marca
 * de inbound diferido cierra ese agujero: el barrido anota "llegó un inbound mientras esto se
 * enviaba" y el envío, al terminar, la consume y vuelve a programar la generación. Es determinista
 * (sin plazos que "casi siempre alcanzan") y no puede ciclar: la marca sólo la escribe el barrido y
 * se consume de a una, con Cache::pull.
 *
 * Su TTL es más largo (900s) a propósito: tiene que sobrevivir al envío entero que la generó, TTL
 * del marcador incluido, más el tramo final de send_suggestion() que corre después de liberarlo.
 */
class LeadSuggestionEnvioEnCurso
{
    /** Prefijo de la clave del marcador de envío en curso, una por mensaje sugerido. */
    private const CACHE_KEY_PREFIX = 'lead_suggestion_envio_en_curso:';

    /**
     * Cota del envío completo en el peor caso realista (≈160s medidos sobre enviar_partes() +
     * apply_pending_actions()), con margen. Ver la cuenta en el docblock de la clase.
     *
     * 🔴 Esto NO es un plazo elegido a ojo ni un número que se pueda bajar "porque en la práctica
     * tarda dos segundos": es el techo del caso lento (6 partes, todas con reintentos), y el caso
     * lento es justamente el que abre la ventana más grande para la carrera que esta clase cierra.
     */
    private const CACHE_TTL_SECONDS = 300;

    /** Prefijo de la clave de "llegó un inbound del lead mientras este mensaje se enviaba". */
    private const CACHE_KEY_PREFIX_INBOUND = 'lead_suggestion_inbound_durante_envio:';

    /**
     * TTL de la marca de inbound diferido. Más largo que el del marcador porque tiene que seguir
     * viva cuando el envío termina: se escribe al principio del envío y se consume al final.
     */
    private const CACHE_TTL_INBOUND_SECONDS = 900;

    /**
     * Declara que este mensaje sugerido está saliendo por WhatsApp ahora mismo.
     *
     * @param int $message_id
     *
     * @return void
     */
    public function marcar(int $message_id): void
    {
        Cache::put($this->cache_key($message_id), true, self::CACHE_TTL_SECONDS);
    }

    /**
     * Suelta el marcador. Idempotente: soltar algo que no está puesto no es un error.
     *
     * @param int $message_id
     *
     * @return void
     */
    public function liberar(int $message_id): void
    {
        Cache::forget($this->cache_key($message_id));
    }

    /**
     * Indica si ese mensaje está saliendo por WhatsApp en este instante, en algún request.
     *
     * @param int $message_id
     *
     * @return bool
     */
    public function esta_en_curso(int $message_id): bool
    {
        return (bool) Cache::get($this->cache_key($message_id), false);
    }

    /**
     * Anota que llegó un inbound del lead justo mientras este mensaje se estaba enviando, para que
     * el envío, al terminar, vuelva a programar la generación de la sugerencia siguiente.
     *
     * @param int $message_id
     *
     * @return void
     */
    public function anotar_inbound_durante_el_envio(int $message_id): void
    {
        Cache::put($this->cache_key_inbound($message_id), true, self::CACHE_TTL_INBOUND_SECONDS);
    }

    /**
     * Consume la marca de inbound diferido y dice si había una.
     *
     * 🔴 Es un `pull` (leer y borrar en un paso) y no un `get` seguido de `forget`: la marca tiene
     * que consumirse UNA sola vez. Con get+forget, dos caminos que terminen el mismo envío
     * reprogramarían la generación dos veces, y con delay 0 el job corre sync dentro del request —
     * o sea dos llamadas a Claude por un solo mensaje del lead.
     *
     * @param int $message_id
     *
     * @return bool True si había un inbound anotado durante el envío.
     */
    public function consumir_inbound_durante_el_envio(int $message_id): bool
    {
        return (bool) Cache::pull($this->cache_key_inbound($message_id), false);
    }

    /**
     * Arma la clave del marcador de envío en curso.
     *
     * @param int $message_id
     *
     * @return string
     */
    private function cache_key(int $message_id): string
    {
        return self::CACHE_KEY_PREFIX.$message_id;
    }

    /**
     * Arma la clave de la marca de inbound diferido.
     *
     * @param int $message_id
     *
     * @return string
     */
    private function cache_key_inbound(int $message_id): string
    {
        return self::CACHE_KEY_PREFIX_INBOUND.$message_id;
    }
}
