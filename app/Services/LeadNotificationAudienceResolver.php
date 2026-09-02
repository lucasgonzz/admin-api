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
 * 🔴 EL DISPARO DE LA REGLA 1 SIGUE SIENDO LA CAMPANITA, Y ESO ES DELIBERADO. Si nadie está
 * suscrito a ese lead, for_mensaje_entrante() devuelve vacío y no se notifica a nadie — exactamente
 * como venía funcionando. Pedido explícito de Lucas: "el rol debe notificar solo si hay un mensaje
 * que requiera verificación o hubo un escalamiento". Sin esto, el ruteo por rol convertiría cada
 * mensaje de cada lead del sistema en un push, y el volumen SUBIRÍA en vez de repartirse, que es lo
 * contrario de lo que se pidió. Las reglas 2 y 3 sí disparan solas porque nunca dependieron de la
 * campanita.
 *
 * 🔴 LA CAMPANITA PRENDIDA NOTIFICA SIEMPRE, en los tres caminos. lead_admin_notifications es una
 * suscripción explícita a un lead puntual y se SUMA al grupo de rol (unión, sin duplicados): si
 * alguien la prendió es porque quiere seguir ese lead, y ni el rol ni el estado se la pueden sacar.
 * Un admin que sea destinatario por rol Y esté en la campanita recibe UN solo push.
 *
 * FALLBACK ANTI-SILENCIO, y es medio punto de este servicio. Si el grupo de rol que corresponde
 * está vacío —nadie marcó el checkbox de closer, por ejemplo— se cae a los setters; y si tampoco
 * hay setters, queda el que se suscribió por campanita. Hoy siempre le llega a alguien y esto no
 * puede empeorarlo: un mensaje de un lead que no le llega a nadie es peor que uno que le llega a
 * quien no le tocaba.
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
        $campanita = self::ids_campanita($lead);

        if (empty($campanita)) {
            return [];
        }

        $es_del_closer = in_array((string) $lead->status, Lead::ESTADOS_DUENO_CLOSER, true);
        $columna       = $es_del_closer ? self::ROL_CLOSER : self::ROL_SETTER;

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
        return self::unir(
            self::ids_por_rol_con_fallback(self::ROL_SETTER),
            self::ids_campanita($lead)
        );
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
        return self::unir(
            self::ids_por_rol_con_fallback(self::ROL_SETTER),
            self::ids_campanita($lead)
        );
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
        /* Columna calificada: la pivot también tiene admin_id y sin el prefijo MySQL tira
         * "Column 'id' in field list is ambiguous". */
        return $lead->notification_admins()
            ->pluck('admins.id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }
}
