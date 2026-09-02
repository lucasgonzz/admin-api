<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Lead;

/**
 * Decide QUÉ admins reciben cada aviso de la conversación de un lead.
 *
 * POR QUÉ EXISTE (decisión de Lucas, 2/9/2026). Hasta hoy todos los avisos de leads le llegaban al
 * mismo: el mensaje entrante iba a los suscritos por campanita —y el único suscrito era él—, la
 * verificación de agendamiento iba a Admin::all() y el escalado iba por notify_lead_escalation_whatsapp,
 * que es un flag opt-in y no un rol. Con el closer trabajando en paralelo hay que repartir, y el
 * criterio es de quién es el lead.
 *
 * LAS TRES REGLAS, y no son la misma:
 *
 *   1. Mensaje entrante común -> del dueño del lead según su estado: closer si está en
 *      Lead::ESTADOS_DUENO_CLOSER, setter en cualquier otro caso.
 *   2. Mensaje que requiere verificación -> SIEMPRE a los setters, aunque el lead esté en
 *      closer_activo. El que aprueba lo que sale es el setter; no es un aviso de "mirá esto",
 *      es una tarea que alguien tiene que hacer para que el mensaje salga.
 *   3. Escalado a humano -> SIEMPRE a los setters, por el mismo motivo: la conversación vuelve a
 *      manos del que la maneja, no del que va a cerrar.
 *
 * 🔴 CUÁNDO DISPARA CADA UNA, que no es lo mismo que a quién le llega:
 *
 *   - Regla 1 FUERA del tramo del closer: solo si alguien tiene la campanita prendida en ese lead.
 *     Es deliberado y es un pedido explícito de Lucas ("el rol debe notificar solo si hay un mensaje
 *     que requiera verificación o hubo un escalamiento"): sin ese gate, el ruteo convertiría cada
 *     mensaje de cada lead del sistema en un push y el volumen SUBIRÍA en vez de repartirse.
 *   - Regla 1 DENTRO del tramo del closer: siempre, haya campanita o no. La campanita se prende a
 *     mano y el closer no anda prendiéndola lead por lead; con el gate puesto acá, no se enteraba de
 *     nada. Ver el comentario adentro de for_mensaje_entrante().
 *   - Reglas 2 y 3: siempre. Nunca dependieron de la campanita y no empiezan ahora.
 *
 * 🔴 LA CAMPANITA PRENDIDA NOTIFICA SIEMPRE, en los tres caminos. lead_admin_notifications es una
 * suscripción explícita a un lead puntual y se SUMA al grupo de rol (unión, sin duplicados): si
 * alguien la prendió es porque quiere seguir ese lead, y ni el rol ni el estado se la pueden sacar.
 * Un admin que sea destinatario por rol Y esté en la campanita recibe UN solo push.
 *
 * FALLBACK ANTI-SILENCIO, en dos escalones y con alcances distintos:
 *
 *   1. Para los tres caminos: si el rol que corresponde no tiene a nadie marcado —nadie tildó el
 *      checkbox de closer, por ejemplo— se cae a los setters.
 *   2. Solo para verificación y escalado: si aun así no quedó nadie, van TODOS los admins. Ver
 *      con_red_de_ultimo_recurso(), que explica por qué esos dos caminos no pueden quedar en cero
 *      y el mensaje entrante sí.
 */
class LeadNotificationAudienceResolver
{
    /** Columna de `admins` que marca al closer (responsable de la llamada de cierre). */
    const ROL_CLOSER = 'is_closer';

    /** Columna de `admins` que marca al setter. Es también el grupo de rescate del fallback. */
    const ROL_SETTER = 'es_setter';

    /**
     * Destinatarios de un mensaje entrante común del lead (regla 1).
     *
     * Devuelve vacío si nadie tiene la campanita prendida en este lead: sin suscriptores no hay
     * aviso, igual que antes del ruteo por rol. Ver el bloque del docblock de clase.
     *
     * @param Lead $lead Lead que mandó el mensaje.
     *
     * @return array<int, int> Ids de admin, sin repetidos, ordenados.
     */
    public static function for_mensaje_entrante(Lead $lead): array
    {
        $campanita     = self::ids_campanita($lead);
        $es_del_closer = in_array((string) $lead->status, Lead::ESTADOS_DUENO_CLOSER, true);

        /* 🔴 El gate de campanita NO se aplica en el tramo del closer (decisión de Lucas, 2/9/2026,
         * después de que el chequeo mostrara que sin esto el closer no recibía nada).
         *
         * La campanita se prende SIEMPRE a mano —no hay una sola línea en el repo que la prenda
         * sola, ni al crear el lead ni al pasar a closer_activo— y el closer no tiene por qué andar
         * prendiéndola lead por lead. Si el gate se aplicara acá, un lead que llega, agenda, hace la
         * demo y pasa a closer_activo le escribiría al closer sin que el closer se entere nunca, que
         * es exactamente lo contrario de lo que se pidió.
         *
         * Fuera de ese tramo el gate sí se mantiene, y ahí está el punto: el volumen del setter no
         * sube ni un mensaje respecto de antes del ruteo. Lo único que se agregó son los leads que
         * ya son del closer. */
        if (! $es_del_closer && empty($campanita)) {
            return [];
        }

        $columna = $es_del_closer ? self::ROL_CLOSER : self::ROL_SETTER;

        return self::unir(self::ids_por_rol_con_fallback($columna), $campanita);
    }

    /**
     * Destinatarios de un mensaje que requiere verificación (regla 2): siempre los setters.
     *
     * @param Lead $lead Lead cuyo mensaje quedó esperando aprobación.
     *
     * @return array<int, int>
     */
    public static function for_verificacion(Lead $lead): array
    {
        return self::con_red_de_ultimo_recurso(self::unir(
            self::ids_por_rol_con_fallback(self::ROL_SETTER),
            self::ids_campanita($lead)
        ));
    }

    /**
     * Destinatarios de un escalado a humano (regla 3): siempre los setters.
     *
     * @param Lead $lead Lead cuya conversación el agente no pudo resolver.
     *
     * @return array<int, int>
     */
    public static function for_escalado(Lead $lead): array
    {
        return self::con_red_de_ultimo_recurso(self::unir(
            self::ids_por_rol_con_fallback(self::ROL_SETTER),
            self::ids_campanita($lead)
        ));
    }

    /**
     * Si no quedó ningún destinatario, devuelve todos los admins.
     *
     * 🔴 SOLO PARA VERIFICACIÓN Y ESCALADO, nunca para el mensaje entrante.
     *
     * Los dos caminos que usan esto son "algo está frenado esperando a una persona": un mensaje que
     * no sale hasta que alguien lo apruebe, o una conversación que el agente no supo seguir. Que un
     * aviso así no le llegue a nadie es peor que despertar a quien no le tocaba — el lead queda
     * esperando una respuesta que nunca va a salir.
     *
     * Y sin esto se podía llegar a cero de verdad, no en teoría: la verificación de agendamiento
     * iba a Admin::all() hasta el 2/9/2026, así que una base sin ningún `es_setter` marcado —el
     * estado en el que queda cualquier instalación hasta que alguien tilde el checkbox— pasaba de
     * avisarle a todo el mundo a no avisarle a nadie. Esta red devuelve ese piso.
     *
     * @param array<int, int> $ids Destinatarios ya resueltos.
     *
     * @return array<int, int>
     */
    private static function con_red_de_ultimo_recurso(array $ids): array
    {
        if (! empty($ids)) {
            return $ids;
        }

        $todos = Admin::pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        sort($todos);

        return $todos;
    }

    /**
     * Ids del rol pedido, cayendo a los setters si ese rol no tiene a nadie marcado.
     *
     * @param string $columna Columna de rol que manda en este caso.
     *
     * @return array<int, int>
     */
    private static function ids_por_rol_con_fallback(string $columna): array
    {
        $ids = self::ids_por_rol($columna);

        /* Nadie marcado en el rol que corresponde. Se cae a los setters, que es el grupo que
         * siempre existió. La comparación con ROL_SETTER evita repetir la misma consulta cuando el
         * rol que ya falló ERA el de setters. */
        if (empty($ids) && $columna !== self::ROL_SETTER) {
            $ids = self::ids_por_rol(self::ROL_SETTER);
        }

        return $ids;
    }

    /**
     * Une los dos conjuntos sacando repetidos y deja la lista ordenada.
     *
     * @param array<int, int> $por_rol
     * @param array<int, int> $campanita
     *
     * @return array<int, int>
     */
    private static function unir(array $por_rol, array $campanita): array
    {
        $ids = array_values(array_unique(array_merge($por_rol, $campanita)));
        sort($ids);

        return $ids;
    }

    /**
     * Ids de los admins que tienen una columna de rol activa.
     *
     * @param string $columna
     *
     * @return array<int, int>
     */
    private static function ids_por_rol(string $columna): array
    {
        return Admin::where($columna, true)
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }

    /**
     * Ids de los admins suscritos a ESTE lead por la campanita (pivot lead_admin_notifications).
     *
     * @param Lead $lead
     *
     * @return array<int, int>
     */
    private static function ids_campanita(Lead $lead): array
    {
        /* Columna calificada por claridad, no por necesidad: `pluck('id')` sobre un belongsToMany
         * resuelve bien porque lead_admin_notifications solo tiene lead_id y admin_id, sin columna
         * `id` propia. Se deja el prefijo para que siga funcionando si mañana la pivot gana un id. */
        return $lead->notification_admins()
            ->pluck('admins.id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }
}
