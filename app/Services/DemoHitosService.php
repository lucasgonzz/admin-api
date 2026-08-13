<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadDemoHito;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Genera y mueve los hitos del roadmap de la demo de un lead (misión 48, pieza 3).
 *
 * Pedido literal de Lucas: *"que el roadmap aparezca con todos los puntos que el lead puede hacer,
 * que por defecto aparezcan todos sin hacerse, y a medida que los vaya haciendo se vayan
 * marcando"*. De ahí las dos mitades de esta clase: `generar()` los crea todos en `pendiente` al
 * congelar el plan, y `aplicar()` los va moviendo con lo que reporta la instancia.
 */
class DemoHitosService
{
    /** Título del hito común a todos los leads. */
    const TITULO_INGRESO = 'Entrar a la demo';

    /** Evento con el que la instancia confirma que el lead entró de verdad. */
    const EVENTO_INGRESO = 'demo.ingreso';

    /** Evento con el que la instancia informa que se terminó de ver el tutorial de un clip. */
    const EVENTO_CLIP_TERMINADO = 'clip.terminado';

    /**
     * Crea los hitos del roadmap a partir del plan congelado del lead.
     *
     * Idempotente: si el lead ya tiene hitos, no se toca nada. Volver a generar sobre un lead que
     * ya avanzó pisaría estados ya ganados, que es exactamente lo que la regla de "nunca
     * retroceder" prohíbe.
     *
     * @param Lead $lead Lead con `demo_plan` ya congelado.
     *
     * @return int Cantidad de hitos creados (0 si ya los tenía o si no hay plan).
     */
    public static function generar(Lead $lead): int
    {
        // Segunda de las dos puertas de escritura de la misión 48, y se defiende sola por el
        // mismo motivo que DemoPlanResolver::congelar_en_memoria(): el endpoint público del
        // formulario no mira la dinámica. Un lead 'actual' no tiene roadmap ni nada que marcar.
        if (! $lead->usa_experiencia_demo_nueva()) {
            return 0;
        }

        $plan = $lead->demo_plan;

        // Sin plan no hay roadmap. No es un error: el catálogo puede no estar sincronizado.
        if (! is_array($plan) || empty($plan)) {
            return 0;
        }

        // Idempotencia: un lead que ya tiene hitos no se regenera.
        $ya_tiene = LeadDemoHito::where('lead_id', $lead->id)->exists();
        if ($ya_tiene) {
            return 0;
        }

        $orden   = 0;
        $creados = 0;

        // 1) Siempre primero, y para todos los leads por igual: entrar a la demo.
        $orden++;
        LeadDemoHito::create([
            'lead_id'         => $lead->id,
            'orden'           => $orden,
            'tipo'            => LeadDemoHito::TIPO_INGRESO,
            'seccion'         => null,
            'clip_id'         => null,
            'titulo'          => self::TITULO_INGRESO,
            'evento_esperado' => self::EVENTO_INGRESO,
            'estado'          => LeadDemoHito::ESTADO_PENDIENTE,
        ]);
        $creados++;

        // 2) Un hito por cada clip de NÚCLEO, en orden de sección y de clip.
        $secciones = isset($plan['secciones']) && is_array($plan['secciones']) ? $plan['secciones'] : [];

        foreach ($secciones as $seccion) {
            $clips = isset($seccion['clips']) && is_array($seccion['clips']) ? $seccion['clips'] : [];

            foreach ($clips as $clip) {
                // 3) Los clips de biblioteca NO generan hito: por definición del catálogo (§1 de
                // `demo_catalogo.md`) son material opcional y no suman al progreso del lead.
                if (! isset($clip['tipo']) || $clip['tipo'] !== 'nucleo') {
                    continue;
                }

                $orden++;
                LeadDemoHito::create([
                    'lead_id'         => $lead->id,
                    'orden'           => $orden,
                    'tipo'            => LeadDemoHito::TIPO_TUTORIAL,
                    'seccion'         => isset($seccion['id']) ? $seccion['id'] : null,
                    'clip_id'         => isset($clip['id']) ? $clip['id'] : null,
                    'titulo'          => isset($clip['titulo']) ? $clip['titulo'] : '',
                    'evento_esperado' => isset($clip['evento_esperado']) ? $clip['evento_esperado'] : null,
                    'estado'          => LeadDemoHito::ESTADO_PENDIENTE,
                ]);
                $creados++;
            }
        }

        return $creados;
    }

    /**
     * Aplica un evento reportado por la instancia a los hitos del lead.
     *
     * @param Lead                 $lead   Lead dueño de los hitos.
     * @param array<string, mixed> $evento Evento normalizado: `nombre`, `clip_id`, `ocurrido_at`.
     *
     * @return int Cantidad de hitos efectivamente modificados.
     */
    public static function aplicar(Lead $lead, array $evento): int
    {
        $nombre = isset($evento['nombre']) ? (string) $evento['nombre'] : '';
        if ($nombre === '') {
            return 0;
        }

        $clip_id  = isset($evento['clip_id']) && $evento['clip_id'] !== '' ? (string) $evento['clip_id'] : null;
        $ocurrido = self::resolver_momento($evento);

        /* Todo el bloque va en una transacción con `lockForUpdate` sobre los hitos que va a tocar,
         * y esto es lo que hace REAL la regla de que un estado no retrocede.
         *
         * Sin el lock, la comparación de pesos de marcar() se hace contra la copia en memoria que
         * trajo el `first()`, mientras que el `save()` de Eloquent escribe la columna sin ningún
         * WHERE sobre el estado anterior. Con dos POST solapados —que es el caso normal: el emisor
         * reintenta y la red reordena— eso alcanza para volver un hito de `completo` a `parcial`:
         *
         *   R1 lee el hito en `pendiente` y se frena
         *   R2 corre entero y lo deja en `completo`
         *   R1 retoma con su copia vieja, calcula `parcial` sobre ella, ve 1 > 0 y guarda
         *
         * El chequeo de uuid del controlador no lo cubre: en el momento en que R2 miró, la fila de
         * R1 todavía no existía. Misión 48. */
        return DB::transaction(function () use ($lead, $nombre, $clip_id, $ocurrido) {
            return self::aplicar_bajo_lock($lead, $nombre, $clip_id, $ocurrido);
        });
    }

    /**
     * Cuerpo de `aplicar()`, ya adentro de la transacción. Todas las lecturas de hitos toman
     * `lockForUpdate` para que nadie más pueda leerlos hasta que este evento termine de aplicarse.
     *
     * @param Lead        $lead
     * @param string      $nombre   Nombre del evento.
     * @param string|null $clip_id  Clip al que refiere, si aplica.
     * @param Carbon      $ocurrido Momento del evento.
     *
     * @return int Cantidad de hitos efectivamente modificados.
     */
    protected static function aplicar_bajo_lock(Lead $lead, string $nombre, $clip_id, Carbon $ocurrido): int
    {
        // El lead entró de verdad a la demo: el hito de ingreso queda completo.
        if ($nombre === self::EVENTO_INGRESO) {
            $hito = LeadDemoHito::where('lead_id', $lead->id)
                ->where('tipo', LeadDemoHito::TIPO_INGRESO)
                ->lockForUpdate()
                ->first();

            if ($hito === null) {
                return 0;
            }

            return self::marcar($hito, LeadDemoHito::ESTADO_COMPLETO, $ocurrido, true, true) ? 1 : 0;
        }

        // Terminó de ver el tutorial de un clip: ese hito pasa a parcial (todavía no hizo la
        // acción). Si el evento de negocio ya había llegado antes, marcar() lo resuelve a
        // completo, porque el estado se recalcula desde las dos marcas y no desde este evento.
        if ($nombre === self::EVENTO_CLIP_TERMINADO) {
            if ($clip_id === null) {
                return 0;
            }

            $hito = LeadDemoHito::where('lead_id', $lead->id)
                ->where('tipo', LeadDemoHito::TIPO_TUTORIAL)
                ->where('clip_id', $clip_id)
                ->lockForUpdate()
                ->first();

            if ($hito === null) {
                return 0;
            }

            return self::marcar($hito, null, $ocurrido, true, false) ? 1 : 0;
        }

        // Cualquier otro evento es un evento de negocio: alcanza a todos los hitos que lo
        // declaren como su `evento_esperado`. Puede ser ninguno, y no es un error.
        $hitos = LeadDemoHito::where('lead_id', $lead->id)
            ->where('evento_esperado', $nombre)
            ->lockForUpdate()
            ->get();

        $modificados = 0;
        foreach ($hitos as $hito) {
            if (self::marcar($hito, null, $ocurrido, false, true)) {
                $modificados++;
            }
        }

        return $modificados;
    }

    /**
     * Escribe las marcas del hito y recalcula su estado, sin retroceder nunca.
     *
     * El estado NO se toma del evento que llegó: se recalcula desde las dos marcas del hito
     * (`tutorial_visto_at` y `accion_hecha_at`). Es lo que hace que el orden en que lleguen los
     * eventos no importe — un `articulo.creado` que llega antes que el `clip.terminado` termina
     * en el mismo estado que al revés.
     *
     * @param LeadDemoHito $hito             Hito a mover.
     * @param string|null  $estado_forzado   Estado a imponer (sólo el hito de ingreso lo usa); null
     *                                       = recalcular desde las marcas.
     * @param Carbon       $momento          Cuándo ocurrió el evento.
     * @param bool         $marca_tutorial   Si este evento prueba que vio el tutorial.
     * @param bool         $marca_accion     Si este evento prueba que hizo la acción.
     *
     * @return bool `true` si el hito cambió y se guardó.
     */
    protected static function marcar(LeadDemoHito $hito, $estado_forzado, Carbon $momento, bool $marca_tutorial, bool $marca_accion): bool
    {
        $cambio = false;

        // Las marcas temporales se escriben una sola vez: la primera vez que pasó es el dato
        // útil, y un reenvío no puede correr la hora hacia adelante.
        if ($marca_tutorial && $hito->tutorial_visto_at === null) {
            $hito->tutorial_visto_at = $momento;
            $cambio = true;
        }

        if ($marca_accion && $hito->accion_hecha_at === null) {
            $hito->accion_hecha_at = $momento;
            $cambio = true;
        }

        $estado_nuevo = $estado_forzado !== null ? $estado_forzado : self::estado_segun_marcas($hito);

        /* Un estado NUNCA retrocede, bajo ninguna combinación de eventos: ni por uno que llega
         * desordenado, ni por uno repetido con otro uuid, ni por un `clip.terminado` que llega
         * después del evento de negocio. Se compara el peso del estado en vez de asignar directo,
         * porque el orden de llegada no está garantizado: el emisor reintenta y la red reordena.
         * Misión 48. */
        $peso_actual = isset(LeadDemoHito::PESO_ESTADOS[$hito->estado])
            ? LeadDemoHito::PESO_ESTADOS[$hito->estado]
            : 0;
        $peso_nuevo = isset(LeadDemoHito::PESO_ESTADOS[$estado_nuevo])
            ? LeadDemoHito::PESO_ESTADOS[$estado_nuevo]
            : 0;

        if ($peso_nuevo > $peso_actual) {
            $hito->estado = $estado_nuevo;
            $cambio = true;
        }

        if ($cambio) {
            $hito->save();
        }

        return $cambio;
    }

    /**
     * Estado que le corresponde a un hito según sus dos marcas.
     *
     * Un hito con `evento_esperado` null NUNCA llega a `completo`: con el tutorial visto queda en
     * `parcial` y ahí se queda. No es un bug a "arreglar" marcándolo completo — que la mitad de
     * los clips no tengan un evento verificable es un dato real del estado del sistema, y el
     * roadmap tiene que mostrarlo como lo que es.
     *
     * @param LeadDemoHito $hito
     *
     * @return string Uno de los tres estados.
     */
    protected static function estado_segun_marcas(LeadDemoHito $hito): string
    {
        $vio_tutorial = $hito->tutorial_visto_at !== null;
        $hizo_accion  = $hito->accion_hecha_at !== null;

        if ($vio_tutorial && $hizo_accion) {
            return LeadDemoHito::ESTADO_COMPLETO;
        }

        if ($vio_tutorial || $hizo_accion) {
            return LeadDemoHito::ESTADO_PARCIAL;
        }

        return LeadDemoHito::ESTADO_PENDIENTE;
    }

    /**
     * Momento del evento: el que declara la instancia si es parseable, y si no el de recepción.
     *
     * @param array<string, mixed> $evento
     *
     * @return Carbon
     */
    protected static function resolver_momento(array $evento): Carbon
    {
        $crudo = isset($evento['ocurrido_at']) ? (string) $evento['ocurrido_at'] : '';

        if (trim($crudo) === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($crudo);
        } catch (\Exception $e) {
            // Una fecha ilegible no puede tirar el canal abajo: el evento vale igual, y la hora
            // de recepción es una aproximación suficiente para el roadmap.
            return Carbon::now();
        }
    }

    /**
     * Marca el hito de ingreso como `parcial` — el lead pulsó el botón y se le dio la URL.
     *
     * No es lo mismo que haber entrado: el `completo` lo pone el evento `demo.ingreso` que manda
     * la instancia al validar el token. La distinción es la que hace visible el caso "pulsó y no
     * entró", que hoy no se puede ver desde ningún lado.
     *
     * @param Lead $lead
     *
     * @return bool `true` si el hito cambió.
     */
    public static function marcar_ingreso_pulsado(Lead $lead): bool
    {
        // Misma guardia: lo llama ingresar_json(), que también es público y tampoco mira la
        // dinámica. Hoy sería inocuo por sí solo (un lead 'actual' no tiene hitos), pero eso lo
        // garantizarían las otras dos guardias y no ésta — y una defensa que depende de que otra
        // no falle no es una defensa.
        if (! $lead->usa_experiencia_demo_nueva()) {
            return false;
        }

        // Mismo lock que aplicar(): este método corre desde el endpoint del botón de ingreso, que
        // puede solaparse con el evento `demo.ingreso` que manda la instancia en ese mismo momento.
        return DB::transaction(function () use ($lead) {
            $hito = LeadDemoHito::where('lead_id', $lead->id)
                ->where('tipo', LeadDemoHito::TIPO_INGRESO)
                ->lockForUpdate()
                ->first();

            if ($hito === null) {
                return false;
            }

            return self::marcar($hito, null, Carbon::now(), true, false);
        });
    }
}
