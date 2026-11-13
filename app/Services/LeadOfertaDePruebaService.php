<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaPoolService;
use App\Services\Prueba\PruebaSettings;
use Illuminate\Support\Facades\Log;

/**
 * La oferta de la prueba gratis que Martín le hace al lead que llega desde la WEB (misión
 * martin-link-prueba-landing, 13/11/2026).
 *
 * Pedido de Lucas: cuando un lead le escribe a Martín desde la landing, que Martín «le dé algo»: el
 * link directo para crear su prueba gratis y qué incluye, en lugar de preguntarle el rubro o
 * agendarle una demo. Medido antes del cambio: del 11 al 13/11 llegaron dos leads por el botón de la
 * web, Martín les contestó «Dale, te armo la demo. Antes, una sola pregunta: ¿qué tipo de negocio
 * tenés?» y ninguno volvió a escribir.
 *
 * 🔴 ESTE ARCHIVO ES EL ÚNICO PUNTO DE CAMBIO de los dos mensajes. Los textos por defecto son las
 * constantes de abajo; se pueden pisar sin deploy con las claves `KEY_MENSAJE_1` / `KEY_MENSAJE_2`
 * de `admin_settings`. El monto y la promo NO se escriben en el texto: salen de
 * `ContractPreciosService` (el plan Inicial y el descuento de bienvenida, lo mismo que publica la
 * landing) por los placeholders `{precio_desde}` y `{promo}`, y los días de la prueba de
 * `PruebaSettings::dias_default()` por `{dias_prueba}`. Si el precio o la promo cambian ahí, el
 * mensaje cambia solo; si la promo se apaga (porcentaje 0), el paréntesis desaparece y el precio es
 * el abono entero.
 *
 * Cuándo aplica ({@see aplica_al_primer_mensaje()}): el PRIMER mensaje del lead CONTIENE una frase
 * de la web (`LeadOrigenService::origen_por_frase()` === `landing`: la vieja, la de M6a y la nueva
 * «Hola, quiero probar ComercioCity»), el lead está en `nuevo`, no tiene una prueba creada, el pool
 * de pruebas tiene lugar y el interruptor `KEY_ACTIVA` está prendido. Instagram y el anuncio de
 * WhatsApp siguen con el onboarding de siempre.
 *
 * Después de los dos mensajes, el agente sigue la conversación con el bloque de
 * {@see bloque_para_el_agente()}: contesta dudas, vuelve a pasar el link si el lead quiere arrancar
 * y no califica ni empuja la demo, salvo que el lead la pida.
 */
class LeadOfertaDePruebaService
{
    /** Clave del interruptor: '1' (default) manda la oferta; '0' vuelve al onboarding de la demo. */
    public const KEY_ACTIVA = 'lead_whatsapp_oferta_prueba_activa';

    /** Clave opcional que pisa el texto del mensaje 1 (con los mismos placeholders). */
    public const KEY_MENSAJE_1 = 'lead_whatsapp_oferta_prueba_mensaje_1';

    /** Clave opcional que pisa el texto del mensaje 2 (con los mismos placeholders). */
    public const KEY_MENSAJE_2 = 'lead_whatsapp_oferta_prueba_mensaje_2';

    /** Clave opcional que pisa el link de la prueba. */
    public const KEY_LINK = 'lead_whatsapp_oferta_prueba_link';

    /**
     * El link de la prueba, con la atribución que la landing ya entiende: `atribucion-whatsapp.js`
     * lee los `utm_*` de la URL, los guarda en la sesión y los manda en `atribucion` al alta
     * (`LandingPruebaController::CLAVES_UTM`), que los deja en `lead_atribucion_contactos.datos` del
     * evento «Empezó su prueba gratis». Así se sabe que la prueba nació del link de Martín.
     */
    public const DEFAULT_LINK = 'https://comerciocity.com/prueba?utm_source=whatsapp&utm_medium=martin';

    /** Mensaje 1 (texto propuesto por Lucas el 13/11/2026, pendiente de su ok final). */
    public const DEFAULT_MENSAJE_1 = "¡Hola! Soy Martín, de ComercioCity 👋\n"
        . "\n"
        . "Lo mejor es que lo pruebes con tu propio negocio: tenés {dias_prueba} días gratis, sin tarjeta, con el sistema de gestión, la tienda online y los agentes de IA funcionando.\n"
        . "\n"
        . "Si te gusta y seguís, el sistema de gestión y la implementación son gratis: te cargamos tus productos nosotros y pagás solo el abono mensual, desde {precio_desde}{promo}.";

    /** Mensaje 2 (texto propuesto por Lucas el 13/11/2026, pendiente de su ok final). */
    public const DEFAULT_MENSAJE_2 = "Tu prueba la creás desde acá, en 2 minutos 👇\n"
        . "{link_prueba}\n"
        . "\n"
        . "Ponés tu nombre, tu WhatsApp, el nombre del negocio y una contraseña, y entrás en el momento.\n"
        . "\n"
        . "Cualquier duda me escribís por acá. Y si antes de arrancar querés saber si te sirve para algo puntual de tu negocio, preguntame ahora y lo vemos.";

    /**
     * Lo que reemplaza a `{promo}` mientras haya descuento de bienvenida. Con el espacio adelante: sin
     * promo, `{promo}` queda vacío y el renglón cierra en «desde $55.900.».
     */
    public const PLANTILLA_PROMO = ' (este mes, con {porcentaje} % off los primeros {meses} meses)';

    /** El plan cuyo abono es el «desde» (el más barato de los vigentes). */
    public const PLAN_DESDE = 'inicial';

    /**
     * ¿La oferta está prendida? Default sí.
     *
     * @return bool
     */
    public static function activa(): bool
    {
        return (string) AdminSetting::get(self::KEY_ACTIVA, '1') !== '0';
    }

    /**
     * ¿Al lead cuyo PRIMER mensaje es `$primer_mensaje` le corresponde la oferta de la prueba?
     *
     * @param Lead        $lead
     * @param string|null $primer_mensaje El texto del inbound (sin el código de atribución).
     *
     * @return bool
     */
    public static function aplica_al_primer_mensaje(Lead $lead, ?string $primer_mensaje): bool
    {
        if ($primer_mensaje === null || trim($primer_mensaje) === '' || ! self::activa()) {
            return false;
        }

        if ((string) $lead->status !== 'nuevo' || self::tiene_prueba($lead)) {
            return false;
        }

        if (LeadOrigenService::origen_por_frase($primer_mensaje) !== LeadOrigenService::ORIGEN_LANDING) {
            return false;
        }

        /* Con el pool lleno el alta rechaza (SinCapacidadDePrueba): mandarle el link sería mandarlo a
           un formulario que le va a decir que no. Ahí sigue el onboarding de la demo, como hasta hoy. */
        try {
            if (! PruebaPoolService::hay_capacidad()) {
                Log::channel('daily')->info('LeadOfertaDePrueba: pool de pruebas sin lugar; sigue el onboarding de la demo.', [
                    'lead_id' => $lead->id,
                ]);

                return false;
            }
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('LeadOfertaDePrueba: no se pudo medir la capacidad del pool; sigue el onboarding de la demo.', [
                'lead_id' => $lead->id,
                'error'   => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * ¿El lead ya tiene una prueba creada (en curso)?
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function tiene_prueba(Lead $lead): bool
    {
        if ((string) $lead->status === LeadPipelineStatus::PRUEBA_ACTIVA) {
            return true;
        }

        if (! $lead->promoted_client_id) {
            return false;
        }

        return PruebaCicloService::esta_en_prueba(Client::query()->find((int) $lead->promoted_client_id));
    }

    /**
     * ¿Ya le mandamos la oferta (el mensaje del link)?
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function ya_se_ofrecio(Lead $lead): bool
    {
        return LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('sender', 'sistema')
            ->where('system_message_kind', LeadWhatsappOnboardingService::KIND_PRUEBA_LINK)
            ->exists();
    }

    /**
     * El link de la prueba (el guardado si hay uno, si no el de siempre).
     *
     * @return string
     */
    public static function link(): string
    {
        $guardado = trim((string) AdminSetting::get(self::KEY_LINK, ''));

        return $guardado !== '' ? $guardado : self::DEFAULT_LINK;
    }

    /**
     * Los dos mensajes, en orden, ya con el precio, la promo, los días y el link.
     *
     * @return array<int, string>
     */
    public static function mensajes(): array
    {
        $reemplazos = self::reemplazos();

        return [
            self::renderizar(self::plantilla(self::KEY_MENSAJE_1, self::DEFAULT_MENSAJE_1), $reemplazos),
            self::renderizar(self::plantilla(self::KEY_MENSAJE_2, self::DEFAULT_MENSAJE_2), $reemplazos),
        ];
    }

    /**
     * El «desde» del abono: el plan Inicial con el descuento de bienvenida, o entero si no hay promo.
     * Formato de la landing: «$27.950».
     *
     * @return string
     */
    public static function precio_desde(): string
    {
        $abono = (float) ContractPreciosService::PLANES_GESTION[self::PLAN_DESDE]['abono'];
        if (self::hay_promo()) {
            $abono = ContractPreciosService::abono_con_descuento_inicial($abono);
        }

        return '$' . number_format(round($abono), 0, ',', '.');
    }

    /**
     * El paréntesis de la promo, o '' si no hay descuento de bienvenida.
     *
     * @return string
     */
    public static function promo(): string
    {
        if (! self::hay_promo()) {
            return '';
        }

        return strtr(self::PLANTILLA_PROMO, [
            '{porcentaje}' => (string) (int) ContractPreciosService::DESCUENTO_INICIAL['porcentaje'],
            '{meses}'      => (string) (int) ContractPreciosService::DESCUENTO_INICIAL['meses'],
        ]);
    }

    /**
     * El bloque que el agente de leads lee en cada turno con un lead al que ya se le ofreció la prueba
     * (o que ya la tiene). Va AL FINAL del contexto, después del de la demo, y dice explícito que lo
     * pisa: el bloque DEMO DIRECTA y los recursos de calificación mandan calificar y ofrecer la demo.
     *
     * @param Lead $lead
     *
     * @return string '' si no corresponde.
     */
    public static function bloque_para_el_agente(Lead $lead): string
    {
        $tiene_prueba = self::tiene_prueba($lead);
        if (! $tiene_prueba && ! self::ya_se_ofrecio($lead)) {
            return '';
        }

        if ($tiene_prueba) {
            return "\n\nPRUEBA GRATIS (esto PISA lo que digan los bloques de arriba y los recursos sobre pasar links, calificar u ofrecer la demo):\n"
                . "  - Este lead YA CREO SU PRUEBA GRATIS. NO le vuelvas a pasar el link para crearla ni le hables como si no la tuviera.\n"
                . "  - Contesta sus dudas con lo que dicen los recursos. El acceso a su prueba lo vio en la pagina al crearla y le llego\n"
                . "    por correo. Si no puede entrar o tiene un problema con su prueba, requiere_intervencion_humana: true con el motivo.\n"
                . "  - NO le pidas datos para calificarlo ni le ofrezcas la demo, salvo que el la pida explicitamente.\n";
        }

        return "\n\nPRUEBA GRATIS (lead de la web; esto PISA lo que digan los bloques de arriba y los recursos sobre calificar, pasar su\n"
            . "pagina u ofrecer y agendar la demo):\n"
            . "  - Ya le mandaste la oferta de la prueba gratis y el link para crearla (son los mensajes de SISTEMA del principio).\n"
            . "  - Link de la prueba (copiar textual, sin cambiarle nada): " . self::link() . "\n"
            . "  - Tu objetivo es que cree su prueba. Contesta sus dudas con lo que dicen los recursos (posicionamiento, precios) y\n"
            . "    nada mas.\n"
            . "  - Si quiere arrancar, pregunta como se hace, dice que si o que le interesa: pasale de nuevo el link de la prueba,\n"
            . "    copiado textual, en un mensaje corto.\n"
            . "  - NO le pidas datos para calificarlo (rubro, cuantas personas trabajan, que sistema usa, mail, nombre) y NO le hagas\n"
            . "    preguntas de calificacion. Si los cuenta solo, guardalos como siempre (guardar_calificacion, guardar_email).\n"
            . "  - NO le ofrezcas la demo ni una llamada, NO le pases el link de su pagina, NO devuelvas agendar_demo y NO le pidas\n"
            . "    disponibilidad. SALVO que el lead pida EXPLICITAMENTE una demo, una llamada o una videollamada: ahi segui el flujo\n"
            . "    de la demo de arriba.\n"
            . "  - La prueba se crea solo desde ese link: no la crees por WhatsApp ni le pidas la contrasena.\n";
    }

    /**
     * @return bool true si hay descuento de bienvenida vigente.
     */
    private static function hay_promo(): bool
    {
        return (int) ContractPreciosService::DESCUENTO_INICIAL['porcentaje'] > 0
            && (int) ContractPreciosService::DESCUENTO_INICIAL['meses'] > 0;
    }

    /**
     * @return array<string, string>
     */
    private static function reemplazos(): array
    {
        return [
            '{precio_desde}' => self::precio_desde(),
            '{promo}'        => self::promo(),
            '{dias_prueba}'  => (string) PruebaSettings::dias_default(),
            '{link_prueba}'  => self::link(),
        ];
    }

    /**
     * El texto guardado en `admin_settings` si hay uno, si no el por defecto.
     *
     * @param string $clave
     * @param string $por_defecto
     *
     * @return string
     */
    private static function plantilla(string $clave, string $por_defecto): string
    {
        $guardado = trim((string) AdminSetting::get($clave, ''));

        return $guardado !== '' ? $guardado : $por_defecto;
    }

    /**
     * @param string                $plantilla
     * @param array<string, string> $reemplazos
     *
     * @return string
     */
    private static function renderizar(string $plantilla, array $reemplazos): string
    {
        return trim(strtr($plantilla, $reemplazos));
    }
}
