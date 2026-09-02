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
     * Intentos que enviar_partes() le da a CADA parte antes de darla por perdida, y los dos backoff
     * que espera entre ellos (1500ms + 3500ms). Están acá y no sueltos en la cuenta para que se vea
     * de dónde sale cada término: si alguien toca el bucle de enviar_partes(), estos son los dos
     * números que tiene que venir a mover.
     */
    private const INTENTOS_POR_PARTE = 3;
    private const BACKOFF_POR_PARTE_SEGUNDOS = 5;

    /**
     * Margen del tramo inicial: lo que puede tardar marcar() → primera parte enviada. Ahí corren la
     * revalidación de horarios ofrecidos y apply_pending_actions(), que le pegan a Google Calendar.
     * Es el único tramo del envío sin renovación posible, porque todavía no salió ninguna parte.
     */
    private const MARGEN_TRAMO_INICIAL_SEGUNDOS = 45;

    /**
     * Duración del lease, en segundos. Se RENUEVA con cada parte que sale (ver renovar()), así que
     * no es la cota del envío entero: es la cota de la brecha entre dos renovaciones.
     *
     * 🔴 NO es una constante, y esa es la decisión importante de este archivo. El peor caso sale de
     * `services.client_api.timeout` y `.retries`, que son configurables por entorno: una constante
     * calculada a mano a partir de esos dos valores queda vieja, en silencio, el día que alguien
     * suba `CLIENT_API_TIMEOUT` por .env — el envío se alarga y el lease no. Es el mismo criterio,
     * y por el mismo motivo, que `ClaudeLeadsOutboundController::segundos_de_lock()` (2/9/2026):
     * cuando dos lugares protegen el mismo cliente HTTP, los dos derivan del cliente, no del
     * recuerdo de lo que valía cuando se escribió.
     *
     * La cuenta, término por término:
     *
     *   - Peor caso de UN send_text(): el cliente usa `timeout` segundos y encima reintenta
     *     `retries` veces con 500ms de espera → `timeout * retries + retries`. Con la config por
     *     defecto (15 y 2), 32s.
     *   - Peor caso de UNA parte: enviar_partes() llama a send_text() hasta 3 veces, con 1500ms +
     *     3500ms de backoff entre medio → 3 * 32 + 5 = **101s**. (La versión anterior de este
     *     docblock decía "~50s" acá, y era la mitad de lo que corresponde: contaba un solo
     *     send_text() en vez de los tres del bucle.)
     *   - Más el tramo inicial sin renovación posible: +45s.
     *
     * Total con la config por defecto: ~146s.
     *
     * 🔴 Sí, eso pasa el techo de `max_execution_time` (120s en HTTP), y es a propósito. El lease
     * tiene UNA obligación —cubrir el envío que protege— y un lease más corto que su envío no
     * protege nada: vence en vuelo, el barrido borra la fila y volvemos al incidente que este
     * archivo existe para evitar. El costo de pasarse es acotado y chico: si el proceso muere por
     * ese fatal (que ningún `catch` atrapa, así que el `finally` no suelta el lease), el marcador
     * queda vivo el resto del TTL y en esa ventana el lead no recibe sugerencia nueva. Son ~26s de
     * sobra sobre el techo, contra los 180s de sobra que tenía la primera versión de 300s fijos.
     *
     * ⚠️ Y NO te apoyes en la cola para relativizar el techo. Es tentador razonar "en producción
     * `QUEUE_CONNECTION=database`, así que el auto-envío corre en un worker sin límite" — el dato
     * de la config es cierto y la conclusión es falsa: `LeadAiSuggestionAutoSendScheduler` tiene
     * `->onConnection('sync')` CABLEADO para el auto-envío inmediato (delay 0), o sea que ignora
     * `QUEUE_CONNECTION` y corre en el mismo proceso del webhook, con su `max_execution_time`
     * encima. Y `afterResponse()` no lo salva: el límite es por proceso, y esos callbacks corren
     * antes de que el proceso termine.
     *
     * O sea que el techo alcanza a los tres endpoints de aprobación humana Y al auto-envío
     * inmediato. La decisión de irse a 146 se sostiene igual, con los dos argumentos de arriba y
     * sin este tercero.
     *
     * @return int
     */
    private function segundos_de_lease(): int
    {
        $timeout  = (int) config('services.client_api.timeout', 15);
        $intentos = (int) config('services.client_api.retries', 2);

        if ($timeout < 1) {
            $timeout = 15;
        }

        if ($intentos < 1) {
            $intentos = 1;
        }

        /* Las esperas entre reintentos del cliente son de 500ms: se redondean a 1s por intento,
         * igual que en segundos_de_lock(), para no arrastrar decimales en una cota. */
        $peor_send_text = ($timeout * $intentos) + $intentos;

        $peor_parte = (self::INTENTOS_POR_PARTE * $peor_send_text) + self::BACKOFF_POR_PARTE_SEGUNDOS;

        return $peor_parte + self::MARGEN_TRAMO_INICIAL_SEGUNDOS;
    }

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

        Cache::put($this->cache_key($message_id), $token, $this->segundos_de_lease());

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

        Cache::put($this->cache_key($message_id), $token, $this->segundos_de_lease());

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
