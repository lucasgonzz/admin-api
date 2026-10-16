<?php

namespace App\Mail\Helpers;

use App\Mail\LeadDemoAccesoMail;
use App\Models\Lead;
use App\Services\AsistenteDemoLeadService;
use App\Services\DemoTurnoTexto;
use App\Services\DemoUrlNormalizer;

/**
 * Arma la "carta de acceso" (`LeadDemoAccesoMail`) a partir de un Lead.
 *
 * Concentra el mapeo Lead → propiedades del Mailable para que quien lo mande (el servicio de IA
 * cuando el lead acepta la demo, un comando, lo que sea) no tenga que saber de dónde sale cada
 * dato. Mismo criterio que `LeadDemoMailHelper` para el mail viejo.
 *
 * De dónde sale cada cosa:
 * - Nombre: `Lead::contact_first_name` (primer nombre, decisión de Lucas del 8/9/2026). Si viene
 *   null o vacío se pasa '' y el blade saluda "Hola." a secas: acá no se inventa un "Cliente".
 * - Llave 1: `Lead::demo_experiencia_url` (página `/experiencia/{clave}` de admin-spa; la clave la
 *   decide el modelo). Null si el lead no tiene clave o falta `services.admin_spa.url`: se pasa
 *   como ''.
 * - Llave 2: `demos.ecommerce_spa_url` de la Demo asignada, normalizada a URL absoluta. Vacía si
 *   el lead no tiene demo o la instancia no tiene tienda; el blade oculta la llave.
 * - WhatsApp, firmante y logo: las mismas claves de `config('commerciocity.*')` que ya lee
 *   `LeadDemoMailHelper`, para que los dos mails firmen igual.
 * - Turno (misión mail-demo-turno-y-asistente, 16/10/2026): día y horas de la demo asignada, de
 *   `DemoTurnoTexto::de_lead()`. Null si el lead no tiene un turno completo o ya terminó.
 * - Asistente personal: número y enlace de `AsistenteDemoLeadService`, que solo los da cuando el
 *   número del asistente está LISTO. Mientras no lo esté, el mail sale sin ese bloque y no le
 *   promete al lead algo que no funciona.
 */
class LeadDemoAccesoMailHelper
{
    /**
     * Construye el `LeadDemoAccesoMail` listo para `Mail::to($lead->email)->send()`.
     *
     * @param Lead $lead Lead que aceptó la demo.
     *
     * @return LeadDemoAccesoMail
     */
    public static function build(Lead $lead): LeadDemoAccesoMail
    {
        // Primer nombre. El accessor devuelve null o '' cuando no hay nombre usable; en los dos
        // casos el Mailable recibe '' y el blade resuelve el saludo sin nombre.
        $nombre = trim((string) ($lead->contact_first_name ?? ''));

        // Llave 1: la página de experiencia. El accessor ya arma la URL absoluta sobre
        // `services.admin_spa.url`; null se trata como vacío.
        $url_experiencia = (string) ($lead->demo_experiencia_url ?? '');

        // Llave 2: la tienda online de la Demo asignada. `load` y no `loadMissing`: si el mismo
        // request cargó la relación cuando el lead todavía no tenía demo, quedó cacheada como null
        // y `loadMissing` no la volvería a leer (hallazgo del chequeo adversarial, 10/9/2026).
        $lead->load('demo');
        $url_tienda_raw = $lead->demo ? (string) $lead->demo->ecommerce_spa_url : '';
        $url_tienda     = self::normalize_mail_url($url_tienda_raw);

        // Mismas claves y mismos defaults que `LeadDemoMailHelper::build_view_data()`: los dos
        // mails de demo tienen que firmar con la misma persona y el mismo WhatsApp.
        $url_whatsapp   = (string) config('commerciocity.demo_mail.whatsapp_url', '');
        $presenter_name = (string) config('commerciocity.demo_mail.presenter_name', 'Equipo ComercioCity');
        $presenter_role = (string) config('commerciocity.demo_mail.presenter_role', 'Fundador');
        $logo_url       = (string) config('commerciocity.logo_url', '');

        // Turno de la demo y asistente: los dos pueden faltar, y el mail se arma igual sin ellos.
        $turno = DemoTurnoTexto::de_lead($lead);

        /* El asistente solo se ofrece DENTRO de un turno vigente (un reenvío con el turno vencido no puede
         * prometer un asistente que ya no contesta), a un lead al que se le pueda contestar y sobre una
         * instancia que pueda atenderlo. */
        $con_asistente = $turno !== null && AsistenteDemoLeadService::disponible_para($lead, $lead->demo);

        return new LeadDemoAccesoMail(
            $nombre,
            $url_experiencia,
            $url_tienda,
            $url_whatsapp,
            $logo_url,
            $presenter_name,
            $presenter_role,
            $turno,
            $con_asistente ? AsistenteDemoLeadService::numero_visible() : '',
            $con_asistente ? AsistenteDemoLeadService::url_whatsapp() : ''
        );
    }

    /**
     * Normaliza una URL de instancia demo para usarla en un href de mail.
     *
     * Copia del criterio de `LeadDemoMailHelper::normalize_mail_url()`, que es privado en ese
     * helper y ese archivo no se toca desde esta misión. No hay lógica duplicada de verdad: los
     * dos son envoltorios de `DemoUrlNormalizer::absolute()`, que es el único lugar donde vive la
     * regla (17/8/2026). Si el criterio cambia, cambia ahí y los dos helpers lo siguen solos.
     *
     * @param string $raw_url URL cruda tal como está guardada en `demos.ecommerce_spa_url`.
     *
     * @return string URL absoluta, o '' si venía vacía.
     */
    private static function normalize_mail_url(string $raw_url): string
    {
        return DemoUrlNormalizer::absolute($raw_url);
    }
}
