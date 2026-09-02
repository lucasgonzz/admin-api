<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Marcador de "esta sugerencia se está enviando por WhatsApp EN ESTE MOMENTO", visible desde
 * cualquier otro proceso de la misma instalación.
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
 *      reciclado, kill -9— ningún `finally` corre. Acá el destrabador es el vencimiento del lease,
 *      que no es nadie. Con una columna haría falta además una cota temporal en el WHERE del
 *      barrido; sin ella, un request muerto deja la sugerencia inmortal.
 *
 * 🔴 EXIGE UN DRIVER DE CACHE COMPARTIDO ENTRE PROCESOS: `file`, `redis`, `memcached` o `database`.
 * Con `array` —que es per-proceso— este marcador NO PROTEGE NADA: el barrido corre en otro proceso
 * (el webhook del inbound), ahí la clave no existe, `esta_en_curso()` devuelve siempre false y el
 * arreglo entero se vuelve un no-op silencioso, con los logs diciendo "sugerencias pendientes
 * descartadas" como si todo estuviera bien. Por eso `marcar()` avisa por log cuando el driver
 * configurado es `array` fuera del entorno de testing: un no-op ruidoso es barato, y es la
 * diferencia entre enterarse y no. (En testing el driver ES `array` a propósito —ver phpunit.xml—,
 * y ahí el aviso sólo ensuciaría la salida: el test corre en un solo proceso.)
 *
 * ¿POR QUÉ LAS DOS CLAVES VIVEN JUNTAS ACÁ? Porque son las dos mitades del MISMO trato entre el
 * barrido y el envío, y separarlas garantiza que alguien cambie una y no la otra. Proteger la
 * sugerencia en vuelo abre un agujero nuevo: el barrido ya no la borra, así que
 * GenerateLeadAiSuggestionJob la ve pendiente (has_pending_non_followup_suggestion) y SE OMITE — el
 * mensaje que el lead acaba de escribir se queda sin respuesta hasta que vuelva a escribir. La marca
 * de inbound diferido cierra ese agujero: el barrido anota "llegó un inbound mientras esto se
 * enviaba" y el envío, al terminar, la lee y vuelve a programar la generación. Es determinista (sin
 * plazos que "casi siempre alcanzan") y no puede ciclar: la marca sólo la escribe el barrido, y el
 * envío la borra recién después de haberla atendido.
 *
 * Su TTL es más largo (900s) a propósito: tiene que sobrevivir al envío entero que la generó, con
 * todas sus renovaciones de lease, más el tramo final de send_suggestion() que corre después de
 * soltarlo.
 */
class LeadSuggestionEnvioEnCurso
{
    /** Prefijo de la clave del marcador de envío en curso, una por mensaje sugerido. */
    private const CACHE_KEY_PREFIX = 'lead_suggestion_envio_en_curso:';

    /**
     * Duración del lease, en segundos. Se RENUEVA con cada parte que sale (ver renovar()), así que
     * no es la cota del envío entero: es la cota de la brecha entre dos renovaciones.
     *
     * 🔴 La cuenta, que es la que justifica el número y no al revés:
     *
     *   - Peor caso de UNA parte: WhatsappSendService::send_text() usa timeout de 15s
     *     (config/services.php, `client_api.timeout`) y encima `->retry(2, 500)`, o sea hasta 30,5s
     *     por llamada; enviar_partes() la reintenta hasta 3 veces con backoff de 1500ms + 3500ms.
     *     Una parte que sale recién en el 3er intento cuesta ~50s.
     *   - Peor caso del tramo inicial marcar() → primera parte: la revalidación de horarios y
     *     apply_pending_actions() pegándole a Google Calendar, ~30s.
     *
     * O sea que la brecha máxima entre dos renovaciones ronda los 80s, y 120 deja margen encima.
     *
     * 🔴 Y el número es corto A PROPÓSITO, en las dos direcciones:
     *
     *   - Hacia arriba: en HTTP el proceso muere a los 120s por `max_execution_time`, y eso es un
     *     fatal que NINGÚN `catch` atrapa, así que el `finally` que suelta el lease no corre. Un TTL
     *     más largo que ese techo deja el marcador vivo después de que el proceso murió, y con él la
     *     sugerencia protegida de un barrido que sí debería limpiarla: el lead se queda sin
     *     respuesta todo ese rato.
     *   - Hacia abajo: sin renovación, cualquier TTL fijo es una apuesta perdida en CLI, donde el
     *     envío puede durar mucho más (6 partes con reintentos pasan tranquilamente los 300s). Por
     *     eso el arreglo no fue recalibrar el número, sino renovar mientras el envío está vivo.
     */
    private const CACHE_TTL_SECONDS = 120;

    /** Prefijo de la clave de "llegó un inbound del lead mientras este mensaje se enviaba". */
    private const CACHE_KEY_PREFIX_INBOUND = 'lead_suggestion_inbound_durante_envio:';

    /**
     * TTL de la marca de inbound diferido. Más largo que el del lease porque tiene que seguir viva
     * cuando el envío termina: se escribe al principio del envío y se atiende al final.
     */
    private const CACHE_TTL_INBOUND_SECONDS = 900;

    /**
     * Toma el lease: declara que este mensaje sugerido está saliendo por WhatsApp ahora mismo.
     *
     * 🔴 Devuelve un TOKEN, y el token no es decorativo: `liberar()` y `renovar()` sólo actúan si el
     * que llama es el dueño del lease vigente. Sin eso, dos envíos concurrentes del MISMO mensaje
     * —que son alcanzables de verdad: AutoSendLeadAiSuggestionJob y una aprobación humana entran los
     * dos con `status = 'sugerido'`, porque el status no cambia hasta después del POST a Kapso—
     * hacen que el primero que termina desproteja al que sigue mandando, y el barrido borre la fila
     * del segundo en pleno envío. Que es exactamente el incidente que esta clase existe para evitar.
     *
     * @param int $message_id
     *
     * @return string Token del dueño del lease, para liberar() y renovar().
     */
    public function marcar(int $message_id): string
    {
        $this->advertir_si_el_driver_no_se_comparte();

        $token = uniqid('', true);

        Cache::put($this->cache_key($message_id), $token, self::CACHE_TTL_SECONDS);

        return $token;
    }

    /**
     * Estira el lease otro TTL completo, mientras el envío siga vivo.
     *
     * La llama el bucle de partes después de CADA parte que sale con éxito: mientras el envío
     * avanza, el lease se sostiene solo, sin importar cuántas partes tenga el mensaje ni cuántos
     * reintentos haya pagado cada una. Si el proceso muere, nadie renueva y el lease vence solo.
     *
     * @param int    $message_id
     * @param string $token      Token que devolvió marcar().
     *
     * @return bool True si este llamador era el dueño y el lease se estiró.
     */
    public function renovar(int $message_id, string $token): bool
    {
        if (! $this->es_el_dueno($message_id, $token)) {
            return false;
        }

        Cache::put($this->cache_key($message_id), $token, self::CACHE_TTL_SECONDS);

        return true;
    }

    /**
     * Suelta el lease, sólo si lo tiene este llamador.
     *
     * Idempotente: soltar algo que ya no está puesto (venció, o se lo llevó otro) no es un error, se
     * devuelve false y listo.
     *
     * @param int    $message_id
     * @param string $token      Token que devolvió marcar().
     *
     * @return bool True si este llamador era el dueño y el lease quedó suelto.
     */
    public function liberar(int $message_id, string $token): bool
    {
        if (! $this->es_el_dueno($message_id, $token)) {
            return false;
        }

        Cache::forget($this->cache_key($message_id));

        return true;
    }

    /**
     * Indica si ese mensaje está saliendo por WhatsApp en este instante, en algún proceso.
     *
     * @param int $message_id
     *
     * @return bool
     */
    public function esta_en_curso(int $message_id): bool
    {
        return Cache::get($this->cache_key($message_id)) !== null;
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
     * Dice si hay un inbound anotado durante el envío de ese mensaje, SIN consumirlo.
     *
     * 🔴 Leer y borrar están separados (antes era un solo `Cache::pull`) porque el orden importa: si
     * la marca se consume ANTES de actuar y la reprogramación tira —Claude caído, base lenta—, la
     * marca ya no está y el mensaje que el lead escribió se queda sin respuesta para siempre, sin
     * que nada lo denuncie. Se lee, se actúa, y recién si salió bien se borra
     * (olvidar_inbound_durante_el_envio()).
     *
     * @param int $message_id
     *
     * @return bool True si el barrido anotó un inbound durante el envío de este mensaje.
     */
    public function hay_inbound_durante_el_envio(int $message_id): bool
    {
        return (bool) Cache::get($this->cache_key_inbound($message_id), false);
    }

    /**
     * Borra la marca de inbound diferido, una vez atendida.
     *
     * @param int $message_id
     *
     * @return void
     */
    public function olvidar_inbound_durante_el_envio(int $message_id): void
    {
        Cache::forget($this->cache_key_inbound($message_id));
    }

    /**
     * Indica si el token que trae el llamador es el del lease vigente.
     *
     * La lectura y la escritura de quien llama después no son atómicas, y no hace falta que lo sean:
     * lo que esto evita no es una carrera de microsegundos, es que un envío que terminó le suelte el
     * lease a OTRO envío que todavía está mandando —una ventana de segundos, no de instrucciones.
     *
     * @param int    $message_id
     * @param string $token
     *
     * @return bool
     */
    private function es_el_dueno(int $message_id, string $token): bool
    {
        $vigente = Cache::get($this->cache_key($message_id));

        return $vigente !== null && (string) $vigente === $token;
    }

    /**
     * Avisa por log si el driver de cache configurado no cruza procesos.
     *
     * Con `array` este marcador no protege nada y el arreglo entero se vuelve un no-op SILENCIOSO
     * (ver el docblock de la clase). Convertirlo en uno ruidoso cuesta esta función.
     *
     * Se exceptúa el entorno de testing, donde el driver es `array` a propósito y todo corre en un
     * solo proceso: ahí el aviso sólo ensuciaría la salida de la suite.
     *
     * @return void
     */
    private function advertir_si_el_driver_no_se_comparte(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if ((string) config('cache.default') !== 'array') {
            return;
        }

        Log::channel('daily')->warning('LeadSuggestionEnvioEnCurso: CACHE_DRIVER=array — el marcador de envío en curso NO protege nada. Es per-proceso, así que el barrido de sugerencias (que corre en el request del webhook) nunca lo va a ver y va a borrar sugerencias que se están enviando por WhatsApp. Hace falta un driver compartido: file, redis, memcached o database.');
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
