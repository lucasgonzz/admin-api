<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\SupportCodexOperation;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaCicloService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SupportTourService
{
    private const PATH = 'api/admin-sync/support/tours';
    public const INVITACION = 'Querés que te prepare un tour con Robotito?';
    public const ACEPTAR_DESDE_CUENTA_PROPIA = 'Para aceptar, entrá al sistema con tu cuenta propia y respondé en este mismo chat.';
    public const RETOMAR_DESDE_CUENTA_PROPIA = 'Entrá al sistema con tu cuenta propia, volvé a este mismo chat y respondé «Sí, quiero el tour».';
    public const ACTUALIZAR_PARA_ABRIR = 'Para abrir la guía personalizada, primero necesitás actualizar tu sistema a una versión compatible con Robotito.';
    public const REVISION_ANTES_DE_PREPARAR = 'Si aceptás, Soporte tiene que revisar tu respuesta antes de que se prepare la guía.';
    private const UMBRAL_DE_OFERTA = '3.2.47';
    private const VERSION_TOUR_PERSONALIZADO = '4.3.44';

    public const AVISO = 'Te mandé a Robotito a tu sistema para que te lo muestre. Tocá su aviso en la barra de navegación para empezar.';

    public const TEMA_REVERTIR_IMPORTACION = 'revertir_importacion_articulos';
    public const TEMA_DINAMICO = 'guia_dinamica';
    public const MAX_IMPORT_HISTORY_ID = 9007199254740991;

    public const TEMAS = [
        'crear_ticket' => 3,
        'buscar_articulo_nombre' => 3,
        'buscar_articulo_codigo' => 1,
        'centro_recursos' => 2,
        self::TEMA_REVERTIR_IMPORTACION => 3,
    ];

    public function __construct(ClientEmpresaApiUrlResolver $urls)
    {
        $this->urls = $urls;
    }

    private $urls;

    /** Solo se admiten recorridos y parámetros que Empresa conoce. */
    public static function guion($datos): ?array
    {
        if (! is_array($datos) || ! is_string($datos['tema'] ?? null)
            || (! isset(self::TEMAS[$datos['tema']]) && $datos['tema'] !== self::TEMA_DINAMICO)
            || ! is_string($datos['titulo'] ?? null)) {
            return null;
        }

        $titulo = trim($datos['titulo']);
        if ($titulo === '' || mb_strlen($titulo) > 120) {
            return null;
        }

        $guion = ['tema' => $datos['tema'], 'titulo' => $titulo];
        if ($guion['tema'] === self::TEMA_DINAMICO) {
            $pasos = self::pasos_dinamicos($datos);
            return $pasos === null ? null : $guion + ['pasos' => $pasos];
        }
        if ($guion['tema'] === self::TEMA_REVERTIR_IMPORTACION) {
            $id = $datos['import_history_id'] ?? null;
            if (! is_int($id) || $id < 1 || $id > self::MAX_IMPORT_HISTORY_ID) {
                return null;
            }
            $guion['import_history_id'] = $id;
        } elseif (array_key_exists('import_history_id', $datos)) {
            return null;
        }

        if (! array_key_exists('textos', $datos)) {
            return $guion;
        }

        $textos = $datos['textos'];
        $cantidad = self::TEMAS[$guion['tema']];
        if (! is_array($textos) || array_keys($textos) !== range(0, $cantidad - 1)) {
            return null;
        }
        foreach ($textos as $texto) {
            if (! is_string($texto) || trim($texto) === '' || mb_strlen(trim($texto)) > 240) {
                return null;
            }
        }

        $guion['textos'] = array_map('trim', $textos);
        return $guion;
    }

    private static function pasos_dinamicos(array $datos): ?array
    {
        $pasos = $datos['pasos'] ?? null;
        if (array_key_exists('textos', $datos) || array_key_exists('import_history_id', $datos)
            || ! is_array($pasos) || count($pasos) < 1 || count($pasos) > 8 || array_keys($pasos) !== range(0, count($pasos) - 1)) {
            return null;
        }

        $normalizados = [];
        foreach ($pasos as $paso) {
            if (! is_array($paso) || array_diff(array_keys($paso), ['clave', 'texto', 'destino']) !== []
                || ! is_string($paso['clave'] ?? null) || ! preg_match('/^[a-z0-9_.]{1,80}$/D', $paso['clave'])
                || ! is_string($paso['texto'] ?? null) || trim($paso['texto']) === '' || mb_strlen(trim($paso['texto'])) > 240) {
                return null;
            }

            $normalizado = ['clave' => $paso['clave'], 'texto' => trim($paso['texto'])];
            if (array_key_exists('destino', $paso)) {
                if (! is_int($paso['destino']) || $paso['destino'] < 1 || $paso['destino'] > self::MAX_IMPORT_HISTORY_ID) {
                    return null;
                }
                $normalizado['destino'] = $paso['destino'];
            }
            $normalizados[] = $normalizado;
        }
        return $normalizados;
    }

    public static function tiene_invitacion(string $texto): bool
    {
        $lineas = preg_split('/\R/u', trim($texto));
        return ltrim(trim((string) end($lineas)), '¿') === self::INVITACION;
    }

    public static function quitar_invitacion(string $texto): string
    {
        if (! self::tiene_invitacion($texto)) {
            return $texto;
        }

        $lineas = preg_split('/\R/u', trim($texto));
        array_pop($lineas);
        $texto = rtrim(implode("\n", $lineas));
        foreach ([self::REVISION_ANTES_DE_PREPARAR, self::ACEPTAR_DESDE_CUENTA_PROPIA, self::ACTUALIZAR_PARA_ABRIR, self::RETOMAR_DESDE_CUENTA_PROPIA] as $leyenda) {
            $aviso = "\n\n" . $leyenda;
            if (substr($texto, -strlen($aviso)) === $aviso) {
                $texto = substr($texto, 0, -strlen($aviso));
            }
        }
        return $texto;
    }

    /**
     * Una invitación a Robotito que el agente escribió A MANO (misión prueba-e2e-campana-imperio, 11/11/2026,
     * ticket 101): «…¿Querés que te prepare un tour con Robotito?» en el medio del texto o con otras palabras, sin
     * el indicador `tour_requested`. Sin esto, esa invitación no queda registrada y el «sí» de la persona da
     * `tour_consent_required`.
     *
     * Devuelve el mismo texto con la pregunta canónica en su renglón final, listo para `preparar_oferta()`, o null
     * si el texto no invita a Robotito (o si no queda ninguna explicación además de la pregunta).
     *
     * @param string $texto
     *
     * @return string|null
     */
    public static function invitacion_escrita_a_mano(string $texto): ?string
    {
        if (self::tiene_invitacion($texto)) {
            return $texto;
        }

        $patron = '/[ \t]*¿?[ \t]*(?:quer[eé]s|te gustar[ií]a|te parece|te sirve)\s+que\s+te\s+(?:lo\s+)?(?:prepare|arme|mande|muestre|haga|ense[ñn]e)\b[^?\n]{0,80}\brobotito\b[^?\n]{0,30}\?/iu';
        if (preg_match($patron, $texto) !== 1) {
            return null;
        }

        $explicacion = trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace($patron, '', $texto, 1)));

        return $explicacion === '' ? null : $explicacion . "\n\n" . self::INVITACION;
    }

    public static function con_invitacion(
        string $texto,
        bool $desde_cuenta_propia,
        bool $requiere_actualizar = false,
        bool $requiere_revision = false
    ): string {
        $texto = self::quitar_invitacion($texto);
        if ($requiere_actualizar) {
            $texto .= "\n\n" . self::ACTUALIZAR_PARA_ABRIR;
        } elseif ($desde_cuenta_propia) {
            $texto .= "\n\n" . self::ACEPTAR_DESDE_CUENTA_PROPIA;
        }
        if ($requiere_revision) {
            $texto .= "\n\n" . self::REVISION_ANTES_DE_PREPARAR;
        }

        return $texto . "\n\n" . self::INVITACION;
    }

    public function guion_aceptado(SupportTicket $ticket, SupportMessage $entrante): ?array
    {
        $invitacion = $this->invitacion_aceptada($ticket, $entrante);
        return self::guion($invitacion->erp_auto_reply['tour_invitation'] ?? null);
    }

    public function solicitud_aceptada(SupportTicket $ticket, SupportMessage $entrante): ?SupportMessage
    {
        $invitacion = $this->invitacion_aceptada($ticket, $entrante);
        if (($invitacion === $entrante || ($invitacion->erp_auto_reply['tour_requested'] ?? null) === true)
            && self::version_admite_tour_personalizado($ticket) !== false) {
            return $invitacion;
        }

        return null;
    }

    public function recordatorio_de_invitacion(SupportTicket $ticket, SupportMessage $entrante): ?array
    {
        if (! self::intento_erp_sin_robotito($ticket, $entrante)
            || $ticket->requiere_verificacion_mensajes
            || self::version_admite_tour_personalizado($ticket) === false) {
            return null;
        }

        $invitacion = $this->invitacion_del_turno($ticket, $entrante);
        if ($invitacion === null || ($invitacion->erp_auto_reply['tour_requested'] ?? null) !== true) {
            return null;
        }

        // El recordatorio conserva el objetivo y señala la misma oferta, que sigue siendo de un solo uso.
        return [
            'body' => "La invitación de Robotito sigue disponible.\n\n" . self::RETOMAR_DESDE_CUENTA_PROPIA . "\n\n" . self::INVITACION,
            'meta' => array_intersect_key($invitacion->erp_auto_reply, ['tour_requested' => true, 'tour_import_history_id' => true])
                + ['tour_original_offer_id' => (int) $invitacion->id],
        ];
    }

    public static function entrante_admite_robotito(SupportTicket $ticket, ?SupportMessage $entrante): bool
    {
        if (! self::es_entrante_del_ticket($ticket, $entrante)) {
            return false;
        }

        // Identidad acreditada y presencia de Robotito son capacidades distintas del ERP.
        return $ticket->source === 'whatsapp' || ($ticket->source === 'erp'
            && ($entrante->erp_auto_reply['inbound_version'] ?? null) === SupportMessage::VERSION_ROBOTITO_ERP);
    }

    public static function entrante_admite_oferta(SupportTicket $ticket, ?SupportMessage $entrante): bool
    {
        return self::es_entrante_del_ticket($ticket, $entrante)
            && self::version_u_origen_admite_oferta($ticket, $entrante, self::version_del_sistema($ticket));
    }

    public static function version_habilita_oferta(SupportTicket $ticket): ?bool
    {
        $version = self::version_del_sistema($ticket);
        return $version === null ? null : VersionNumberComparator::compare($version, self::UMBRAL_DE_OFERTA) > 0;
    }

    public static function version_admite_tour_personalizado(SupportTicket $ticket): ?bool
    {
        $version = self::version_del_sistema($ticket);
        return $version === null ? null : VersionNumberComparator::compare($version, self::VERSION_TOUR_PERSONALIZADO) >= 0;
    }

    public static function preparar_oferta(
        SupportTicket $ticket,
        ?SupportMessage $entrante,
        string $texto,
        bool $uso_confirmado = false
    ): ?string {
        if (! self::es_entrante_del_ticket($ticket, $entrante)) {
            return null;
        }
        $version = self::version_del_sistema($ticket);
        if (! self::version_u_origen_admite_oferta($ticket, $entrante, $version)
            || ! self::respuesta_admite_oferta($entrante, $texto, $uso_confirmado)) {
            return null;
        }

        return self::con_invitacion($texto, ! self::entrante_admite_robotito($ticket, $entrante),
            $version !== null && VersionNumberComparator::compare($version, self::VERSION_TOUR_PERSONALIZADO) < 0,
            (bool) $ticket->requiere_verificacion_mensajes);
    }

    private static function es_entrante_del_ticket(SupportTicket $ticket, ?SupportMessage $entrante): bool
    {
        return $entrante !== null && (int) $entrante->support_ticket_id === (int) $ticket->id
            && $entrante->sender_type === 'user' && SupportMessage::es_mensaje_real($entrante);
    }

    private static function version_u_origen_admite_oferta(SupportTicket $ticket, SupportMessage $entrante, ?string $version): bool
    {
        if ($version !== null) {
            return VersionNumberComparator::compare($version, self::UMBRAL_DE_OFERTA) > 0;
        }

        // Las instalaciones sin versión registrada conservan la oferta por sesión u origen autenticado.
        return self::entrante_admite_robotito($ticket, $entrante)
            || ($ticket->source === 'erp'
                && ($entrante->erp_auto_reply[SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX] ?? null) === true);
    }

    private static function version_del_sistema(SupportTicket $ticket): ?string
    {
        $cliente = $ticket->client;
        if ($cliente === null) {
            return null;
        }

        $instancia = PruebaCicloService::instancia_de($cliente);
        if ($instancia !== null && in_array($instancia->estado, [TrialInstance::ESTADO_ASIGNADA, TrialInstance::ESTADO_CLIENTE], true)) {
            $version = self::normalizar_version($instancia->version);
            if ($version !== null) {
                return $version;
            }
        }

        return self::normalizar_version(optional($cliente->current_version)->version);
    }

    private static function normalizar_version(?string $version): ?string
    {
        $version = preg_replace('/^[vV]|\+[a-zA-Z0-9.-]+$/D', '', trim((string) $version));
        return VersionNumberComparator::isValid($version) ? $version : null;
    }

    public static function respuesta_admite_oferta(SupportMessage $entrante, string $texto, bool $uso_confirmado): bool
    {
        $consulta = Str::ascii(mb_strtolower(trim((string) $entrante->body)));
        // El signo del enlace del Centro de recursos no es una pregunta en la respuesta.
        $explicacion = trim(preg_replace('~https?://\S+~u', '', self::quitar_invitacion($texto)));
        if ($explicacion === '' || preg_match('/[?¿]/u', $explicacion)
            || preg_match('/^no[,.! ]+(?:gracias|hace falta)\b|\b(?:no quiero|no necesito|prefiero no)\b[^.!?]*\b(?:tour|robotito|recorrido)\b/', $consulta)) {
            return false;
        }
        if ($uso_confirmado) {
            return true;
        }

        // Respaldo para monitores anteriores: una consulta directa y pasos sobre el mismo tema.
        $explicacion = Str::ascii(mb_strtolower($explicacion));
        if (preg_match('/\b(?:escribi|escribime|decime|indicame|confirmame|aclarame|contame|necesito saber)\s+(?:si|que|cual|como|donde|cuando|quien)\b/', $explicacion)) {
            return false;
        }
        if (! preg_match('/(?:^|[!?.;,]\s*)\s*(?:me (?:explicas|decis|indicas|mostras|ensenas)\s+)?(?:como|donde|en que (?:menu|pantalla|seccion)|que pasos)\s+/', $consulta)
            || ! preg_match('/\b(?:abri|entra|anda|elegi|toca|busca|selecciona|marca|tilda|activa|revisa|presiona|escribi|ingresa|completa|carga|hace clic|podes|guarda)\b/', $explicacion)) {
            return false;
        }

        preg_match_all('/[a-z0-9]{4,}/', $consulta, $palabras_consulta);
        preg_match_all('/[a-z0-9]{4,}/', $explicacion, $palabras_explicacion);
        $tema = array_diff($palabras_consulta[0], [
            'como', 'donde', 'puedo', 'puede', 'podes', 'hacer', 'hago', 'quiero', 'tengo',
            'desde', 'para', 'pasos', 'pantalla', 'seccion', 'sistema', 'hola', 'buenas',
        ]);
        $singular = static function (string $palabra): string {
            return rtrim($palabra, 's');
        };
        return array_intersect(array_map($singular, $tema), array_map($singular, $palabras_explicacion[0])) !== [];
    }

    private function invitacion_aceptada(SupportTicket $ticket, SupportMessage $entrante): ?SupportMessage
    {
        if (! self::entrante_admite_robotito($ticket, $entrante) || ! $ticket->mensaje_confiable_para_codex($entrante)) {
            return null;
        }

        // La pregunta del formulario de una guía en curso autoriza recalcularla. La marca
        // se guarda solo al recibir el mensaje firmado del titular y no se infiere del texto.
        if ($ticket->source === 'erp' && $ticket->status === 'open' && $entrante->kind === 'text'
            && ($entrante->erp_auto_reply[SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX] ?? null) === true
            && ($entrante->erp_auto_reply[SupportMessage::MARCA_PREGUNTA_ROBOTITO] ?? null) === true) {
            return $entrante;
        }

        return $this->invitacion_del_turno($ticket, $entrante)
            ?? $this->invitacion_anterior_al_aviso($ticket, $entrante)
            ?? ($this->es_pedido_propio($ticket, $entrante) ? $entrante : null);
    }

    /**
     * La persona pidió el recorrido con sus palabras («mostrámelo con el robotito ese»), sin que hubiera una
     * invitación pendiente (ticket 107, 12/11/2026). Ese pedido ES el consentimiento: no hace falta invitarla para
     * que conteste «sí». El mensaje hace de invitación y de aceptación, como la pregunta escrita dentro de una guía.
     */
    private function es_pedido_propio(SupportTicket $ticket, SupportMessage $entrante): bool
    {
        if ($entrante->kind !== 'text' || ($ticket->source === 'erp' && ! self::entrada_erp_verificada($ticket, $entrante))) {
            return false;
        }

        return self::pide_recorrido(self::normalizar_respuesta($entrante));
    }

    /**
     * @param string $respuesta Ya normalizada (minúsculas, sin acentos).
     */
    public static function pide_recorrido(string $respuesta): bool
    {
        // Un mensaje que empieza con un sí contesta algo: lo resuelven las reglas de la invitación, que exigen que
        // esté entregada y sea contigua. Y rechazarlo o preguntar qué es no es pedirlo.
        if (preg_match('/^(?:si+|sip|dale|ok|okey|bueno|claro|obvio|de una|de acuerdo)\b/', $respuesta)
            || preg_match('/\b(?:no|nunca|tampoco|sin)\b[^.!?]{0,40}\b(?:robotito|tour|recorrido)\b/', $respuesta)
            || preg_match('/\b(?:que es|quien es|para que sirve|como funciona)\b[^.!?]{0,40}\b(?:robotito|tour)\b/', $respuesta)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(?:mostrame|mostramelo|muestrame|muestramelo|mostras|mostrar|ensename|ensenamelo|ensenas|preparame|preparamelo|armame|armamelo'
            . '|mandame|mandamelo|pasame|pasamelo|enviame|enviamelo|guiame|explicame|explicamelo|haceme|hacelo|hagamos|quiero|queria|quisiera'
            . '|necesito|prefiero|dale)\b[^?]{0,80}\b(?:robotito|tour|recorrido guiado)\b/',
            $respuesta
        );
    }

    private function invitacion_del_turno(SupportTicket $ticket, SupportMessage $entrante): ?SupportMessage
    {
        $anteriores = SupportMessage::where('support_ticket_id', $ticket->id)->where('id', '<', $entrante->id);
        SupportMessage::apply_mensaje_real_conditions($anteriores, 'support_messages');
        $invitacion = (clone $anteriores)->latest('id')->first();
        $respuesta = self::normalizar_respuesta($entrante);
        $cita = $entrante->reply_to_message_id;
        if ($invitacion !== null && $invitacion->sender_type === 'user') {
            if ($cita !== null) {
                $invitacion = $anteriores->where('sender_type', 'admin')->latest('id')->first();
            } elseif (preg_match('/^(?:por favor|porfa|por fi)[.!, ]*$/D', $respuesta) && $invitacion->reply_to_message_id === null
                && self::entrante_admite_robotito($ticket, $invitacion) && $ticket->mensaje_confiable_para_codex($invitacion)) {
                $respuesta = Str::ascii(mb_strtolower(trim((string) $invitacion->body))) . ' ' . $respuesta;
                $invitacion = $anteriores->where('id', '<', $invitacion->id)->latest('id')->first();
            }
        }
        if ($invitacion === null || ($cita !== null && (int) $cita !== (int) $invitacion->id)) {
            return null;
        }
        $texto = $this->texto_de_invitacion($ticket, $entrante, $invitacion);
        if ($texto === null || ! self::respuesta_acepta_invitacion($texto, $respuesta)) {
            return null;
        }

        $original_id = $ticket->source === 'erp' ? ($invitacion->erp_auto_reply['tour_original_offer_id'] ?? null) : null;
        if ($original_id === null) {
            return $invitacion;
        }

        if (! is_int($original_id) || $original_id < 1 || ! self::entrada_erp_verificada($ticket, $entrante)) {
            return null;
        }

        $original = (clone $anteriores)->where('id', '<', $invitacion->id)->find($original_id);
        return $original !== null && ($original->erp_auto_reply['tour_requested'] ?? null) === true
            && ! isset($original->erp_auto_reply['tour_original_offer_id'])
            && $this->texto_de_invitacion($ticket, $entrante, $original) !== null ? $original : null;
    }

    private function texto_de_invitacion(SupportTicket $ticket, SupportMessage $entrante, SupportMessage $invitacion): ?string
    {
        if ($invitacion->sender_type !== 'admin'
            || ! $ticket->mensaje_confiable_para_codex($invitacion)
            || ! SupportMessage::es_mensaje_real($invitacion)) {
            return null;
        }

        $entregada = $ticket->source === 'erp' ? $invitacion->synced_to_client_at !== null
            : trim((string) $invitacion->whatsapp_message_id) !== '' && $invitacion->remote_delivery_status !== 'not_received'
                && $invitacion->whatsapp_delivery_status !== 'fallido';
        $guion = self::guion($invitacion->erp_auto_reply['tour_invitation'] ?? null);
        $uuid_usado = $invitacion->erp_auto_reply['tour_acceptance_uuid'] ?? null;
        $solicitud = ($invitacion->erp_auto_reply['tour_requested'] ?? null) === true;
        $texto = $ticket->source === 'whatsapp'
            ? (string) ($invitacion->ai_body_before_template ?? $invitacion->body) : (string) $invitacion->body;
        if (! $entregada || ($guion === null && ! $solicitud) || ($uuid_usado !== null && $uuid_usado !== $entrante->uuid)
            || ! self::tiene_invitacion($texto)) {
            return null;
        }

        return $texto;
    }

    private static function respuesta_acepta_invitacion(string $texto, string $respuesta): bool
    {
        // Una respuesta breve solo es inequívoca si la invitación era la única pregunta.
        if (self::aceptacion_explicita($respuesta) || self::aceptacion_nombrando_a_robotito($respuesta)) {
            return true;
        }
        if (preg_match('/[?¿]/u', preg_replace('~https?://\S+~u', '', self::quitar_invitacion($texto)))) {
            return false;
        }
        if (self::aceptacion_insistente($respuesta)) {
            return true;
        }

        return (bool) preg_match('/^(?:si|dale|de una|de acuerdo|(?:ok|bueno)[,.! ]+dale)(?:[,.! ]+(?:si|dale|quiero|mandamelo|preparamelo|pasamelo|enviamelo|por favor|porfa|por fi|gracias|me (?:sirve|vendria bien|gustaria)|quiero que (?:me )?lo prepares))*[.!, ]*$/D', $respuesta);
    }

    private function invitacion_anterior_al_aviso(SupportTicket $ticket, SupportMessage $entrante): ?SupportMessage
    {
        if (! self::entrada_erp_verificada($ticket, $entrante) || $entrante->reply_to_message_id !== null
            || ! self::aceptacion_explicita(self::normalizar_respuesta($entrante))) {
            return null;
        }

        // Compatibilidad con el aviso anterior: solo los tres mensajes reales contiguos y la operación exacta.
        $mensajes = SupportMessage::where('support_ticket_id', $ticket->id)->where('id', '<', $entrante->id);
        SupportMessage::apply_mensaje_real_conditions($mensajes, 'support_messages');
        $previos = $mensajes->latest('id')->limit(3)->get();
        if ($previos->count() !== 3) {
            return null;
        }
        [$aviso, $intento, $oferta] = $previos->all();
        if ($aviso->sender_type !== 'admin' || $aviso->sender_admin_id !== null || $aviso->ai_generated_at === null
            || $aviso->synced_to_client_at === null || $aviso->erp_auto_reply !== null
            || ! Str::containsAll(self::normalizar_respuesta($aviso), ['cuenta propia', 'mismo chat', 'tour'])
            || ! self::intento_erp_sin_robotito($ticket, $intento)
            || ($intento->reply_to_message_id !== null && (int) $intento->reply_to_message_id !== (int) $oferta->id)
            || ($oferta->erp_auto_reply['tour_requested'] ?? null) !== true
            || isset($oferta->erp_auto_reply['tour_original_offer_id'])) {
            return null;
        }
        $texto = $this->texto_de_invitacion($ticket, $entrante, $oferta);
        if ($texto === null || ! self::respuesta_acepta_invitacion($texto, self::normalizar_respuesta($intento))) {
            return null;
        }

        $operacion = SupportCodexOperation::where('support_ticket_id', $ticket->id)
            ->where('message_id', $aviso->id)->where('expected_message_id', $intento->id)
            ->where('action', 'responder')->where('status', 'sent')->exists();
        return $operacion ? $oferta : null;
    }

    private static function intento_erp_sin_robotito(SupportTicket $ticket, SupportMessage $entrante): bool
    {
        return self::entrada_erp_verificada($ticket, $entrante) && ! self::entrante_admite_robotito($ticket, $entrante);
    }

    private static function entrada_erp_verificada(SupportTicket $ticket, SupportMessage $entrante): bool
    {
        return $ticket->source === 'erp' && $ticket->status === 'open' && $entrante->kind === 'text'
            && self::es_entrante_del_ticket($ticket, $entrante)
            && ($entrante->erp_auto_reply[SupportMessage::MARCA_ERP_VERIFICADO_PARA_CODEX] ?? null) === true;
    }

    private static function normalizar_respuesta(SupportMessage $entrante): string
    {
        return ltrim(Str::ascii(mb_strtolower(trim((string) $entrante->body))), '! ');
    }

    /**
     * «sí dale, mostrame con el robotito ese»: un sí seguido de un pedido que nombra a Robotito, el tour o el
     * recorrido (ticket 101, 11/11/2026). Tiene que EMPEZAR con el sí, no puede traer una pregunta ni una negación,
     * y solo vale como respuesta a una invitación registrada y entregada: lo decide quien llama.
     *
     * @param string $respuesta Ya normalizada (minúsculas, sin acentos).
     *
     * @return bool
     */
    private static function aceptacion_nombrando_a_robotito(string $respuesta): bool
    {
        if (preg_match('/[?¿]|\b(?:no|nunca|todavia|despues|luego|mas tarde|otro dia|ahora no)\b/', $respuesta)) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:(?:si+|dale|ok|okey|bueno|de una|claro|obvio|genial|por favor|porfa)[,.! ]+)+'
            . '(?:mostrame|mostramelo|muestrame|ensename|ensenamelo|explicame|preparame|preparamelo|mandame|mandamelo|pasame|pasamelo|enviame|enviamelo|hacelo|hagamoslo|probemos|quiero)\b'
            . '[a-z ,]{0,60}\b(?:robotito|tour|recorrido)\b[a-z .,!]{0,40}$/D',
            $respuesta
        );
    }

    /**
     * «sí, ya te dije que sí», «sí sí, dale nomás», «dale, hacelo»: un sí que no calza en la lista corta de arriba
     * (ticket 107, 12/11/2026: esa respuesta daba `tour_consent_required` y el dueño había aceptado dos veces).
     * Tiene que EMPEZAR con el sí, ser breve y no traer pregunta, negación ni condición. Solo vale si la invitación
     * era la única pregunta del mensaje: lo decide quien llama.
     *
     * @param string $respuesta Ya normalizada (minúsculas, sin acentos).
     */
    private static function aceptacion_insistente(string $respuesta): bool
    {
        if (mb_strlen($respuesta) > 80
            || preg_match('/[?¿]|\b(?:no|nunca|tampoco|todavia|despues|luego|mas tarde|otro dia|pero|aunque|antes|primero|salvo|si no)\b/', $respuesta)
            // Sin acentos «sí» y «si» se escriben igual: «si me decís cuánto sale» es una condición.
            || preg_match('/^si (?:me|te|se|lo|la|el|es|hay|podes|puedo|puede|queres|tenes|tengo|fuera|funciona|sirve)\b/', $respuesta)) {
            return false;
        }

        return (bool) preg_match('/^(?:si+|sip|dale|claro|obvio|de una|de acuerdo)\b/', $respuesta);
    }

    private static function aceptacion_explicita(string $respuesta): bool
    {
        return (bool) preg_match('/^(?:(?:si|dale|ok)[,.! ]+)?(?:preparame|mandame|pasame|enviame|quiero(?: hacer)?) (?:el|un) (?:tour|recorrido)(?: de robotito)?(?:[,.! ]+(?:por favor|porfa|gracias))*[.!, ]*$/D', $respuesta);
    }

    public function objetivo_disponible(SupportTicket $ticket, SupportMessage $entrante, int $import_history_id): bool
    {
        $ticket->loadMissing('client');
        $persona = $ticket->source === 'whatsapp' ? $this->destinatario_whatsapp($ticket, $entrante) : (int) $ticket->client_user_id;
        if ($persona === false) {
            return false;
        }
        return $this->buscar_importaciones($ticket, $persona ?? (int) optional($ticket->client)->user_id, $import_history_id) !== [];
    }

    public function catalogo_dinamico(SupportTicket $ticket, SupportMessage $entrante, ?int $id): array
    {
        $query = $this->query_persona($ticket, $entrante);
        if ($query === null) {
            return [];
        }
        if ($id !== null) {
            $query['import_history_id'] = $id;
        }
        return $this->consultar_empresa($ticket, '/catalogo', $query);
    }

    private function query_persona(SupportTicket $ticket, SupportMessage $entrante): ?array
    {
        if ($ticket->source === 'whatsapp') {
            $persona = $this->destinatario_whatsapp($ticket, $entrante);
        } else {
            $persona = (int) $ticket->client_user_id;
        }
        if ($persona === false || ($persona !== null && $persona < 1)) {
            return null;
        }

        // Un dueño de WhatsApp ya verificado se resuelve en Empresa, como al enviarle el tour.
        return $persona === null ? [] : ['user_id' => $persona];
    }

    private function buscar_importaciones(SupportTicket $ticket, int $persona, ?int $import_history_id): array
    {
        if ($persona < 1) {
            return [];
        }
        $query = ['user_id' => $persona];
        if ($import_history_id !== null) {
            $query['import_history_id'] = $import_history_id;
        }
        $modelos = $this->consultar_empresa($ticket, '/importaciones/candidatas', $query)['model'] ?? null;
        if (! is_array($modelos)) {
            return [];
        }

        $candidatas = [];
        foreach (array_slice($modelos, 0, 30) as $modelo) {
            if (! is_array($modelo) || ! is_int($modelo['id'] ?? null) || $modelo['id'] < 1
                || ! is_string($modelo['created_at'] ?? null)
                || ($import_history_id !== null && $modelo['id'] !== $import_history_id)) {
                continue;
            }
            $candidatas[] = array_intersect_key($modelo, array_flip(['id', 'created_at', 'provider_id', 'provider_name']));
        }
        return $candidatas;
    }

    private function consultar_empresa(SupportTicket $ticket, string $ruta, array $query): array
    {
        $ticket->loadMissing('client');
        $cliente = $ticket->client;
        if ($cliente === null || ! $cliente->is_active || trim((string) $cliente->api_key) === '') {
            return [];
        }
        $url = $this->urls->admin_sync_url($cliente, self::PATH . $ruta);
        if ($url === '') {
            return [];
        }

        try {
            $respuesta = $this->http($cliente)->get($url, $query);
        } catch (ConnectionException $exception) {
            return [];
        }

        $datos = $respuesta->json();
        return $respuesta->successful() && is_array($datos) ? $datos : [];
    }

    /** El mensaje entrante es la clave idempotente y fija el destinatario. */
    public function ofrecer(SupportTicket $ticket, SupportMessage $entrante, array $guion): array
    {
        $ticket->loadMissing('client');
        $cliente = $ticket->client;
        if ($cliente === null || ! $cliente->is_active || ! Str::isUuid((string) $ticket->uuid)
            || ! Str::isUuid((string) $entrante->uuid) || (int) $entrante->support_ticket_id !== (int) $ticket->id
            || $entrante->sender_type !== 'user') {
            return $this->fallo(409, 'tour_recipient_unresolved', 'No se pudo verificar la cuenta de quien hizo la consulta.');
        }

        $invitacion = $this->invitacion_aceptada($ticket, $entrante);
        if ($invitacion === null) {
            return $this->fallo(409, 'tour_consent_required', 'La persona todavía no aceptó una invitación enviada en este ticket.');
        }
        $aceptado = self::guion($invitacion->erp_auto_reply['tour_dynamic_plan'] ?? $invitacion->erp_auto_reply['tour_invitation'] ?? null);
        if ($aceptado !== $guion) {
            return $this->fallo(409, 'tour_invitation_mismatch', 'El recorrido no coincide con el que aceptó la persona.');
        }

        $es_whatsapp = $ticket->source === 'whatsapp';
        $usuario = $es_whatsapp ? $this->destinatario_whatsapp($ticket, $entrante) : (int) $ticket->client_user_id;
        if ($usuario === false || (! $es_whatsapp && $usuario < 1)) {
            return $this->fallo(409, 'tour_recipient_unresolved', 'No se pudo verificar la cuenta de quien hizo la consulta.');
        }

        $body = $guion + [
            'uuid' => $entrante->uuid,
            'ticket_uuid' => $ticket->uuid,
            'source' => $es_whatsapp ? 'whatsapp' : 'sistema',
        ];
        if ($usuario !== null) {
            $body['user_id'] = $usuario;
            if ($es_whatsapp) {
                $body['recipient_phone'] = (string) $entrante->whatsapp_remitente_crudo;
            }
        }

        $url = $this->urls->admin_sync_url($cliente, self::PATH);
        if ($url === '' || trim((string) $cliente->api_key) === '') {
            return $this->fallo(503, 'tour_unavailable', 'El sistema del comercio todavía no está conectado a Robotito.');
        }

        // Las dos entradas mantienen bloqueado el ticket. Si llega otro «sí», no puede
        // recrear la misma invitación con otro UUID, aunque la confirmación HTTP se pierda.
        $invitacion->erp_auto_reply = $invitacion->erp_auto_reply + ['tour_acceptance_uuid' => $entrante->uuid];
        $invitacion->save();

        try {
            $respuesta = $this->http($cliente)->post($url, $body);
        } catch (ConnectionException $exception) {
            return $this->fallo(502, 'tour_unconfirmed', 'El sistema no confirmó que recibió el tour.');
        }

        if ($respuesta->successful() && $respuesta->json('ok') === true && $respuesta->json('uuid') === $entrante->uuid) {
            return ['ok' => true, 'uuid' => $entrante->uuid];
        }
        if (in_array($respuesta->status(), [404, 405], true) && ! $respuesta->json('code')) {
            return $this->fallo(409, 'tour_unsupported', 'El sistema del comercio todavía no admite tours de soporte.');
        }
        if (in_array($respuesta->status(), [404, 409, 422], true)) {
            return $this->fallo(422, 'tour_rejected', 'El sistema del comercio rechazó el destinatario o el recorrido.');
        }

        return $this->fallo(502, 'tour_unconfirmed', 'El sistema no confirmó que recibió el tour.');
    }

    public function seguimiento(SupportTicket $ticket): array
    {
        $ticket->loadMissing('client');
        $cliente = $ticket->client;
        if ($cliente === null || ! Str::isUuid((string) $ticket->uuid)) {
            return ['model' => [], 'available' => false];
        }
        $url = $this->urls->admin_sync_url($cliente, self::PATH . '/' . $ticket->uuid);
        if ($url === '' || trim((string) $cliente->api_key) === '') {
            return ['model' => [], 'available' => false];
        }

        try {
            $respuesta = $this->http($cliente)->get($url);
        } catch (ConnectionException $exception) {
            return $this->fallo(502, 'tour_unavailable', 'No pudimos consultar los tours del comercio.');
        }
        if (in_array($respuesta->status(), [404, 405], true) && ! $respuesta->json('code')) {
            return ['model' => [], 'available' => false];
        }
        $model = $respuesta->json('model');
        if (! $respuesta->successful() || ! is_array($model)) {
            return $this->fallo(502, 'tour_unavailable', 'No pudimos consultar los tours del comercio.');
        }

        return ['model' => $model, 'available' => true];
    }

    private function http(Client $cliente)
    {
        return Http::withHeaders(['X-Admin-Api-Key' => $cliente->api_key])
            ->acceptJson()->timeout(6)->withOptions(['allow_redirects' => false]);
    }

    /** null identifica al dueño verificado. false indica identidad ambigua o sin usuario sincronizado. */
    private function destinatario_whatsapp(SupportTicket $ticket, SupportMessage $entrante)
    {
        $remitente = (string) $entrante->whatsapp_remitente_crudo;
        if (! WhatsappNormalizer::equals_estricto((string) $ticket->whatsapp_phone, $remitente)) {
            return false;
        }

        $empleados = ClientEmployee::whereNotNull('phone')->whereHas('client', function ($consulta) {
            $consulta->where('is_active', true);
        })->get(['id', 'client_id', 'phone', 'empresa_employee_id'])->filter(function (ClientEmployee $empleado) use ($remitente) {
            return WhatsappNormalizer::equals_estricto((string) $empleado->phone, $remitente);
        });
        $duenos = Client::where('is_active', true)->get(['id', 'phone', 'asistente_phone'])->filter(function (Client $cliente) use ($remitente) {
            return WhatsappNormalizer::equals_estricto((string) $cliente->phone, $remitente)
                || WhatsappNormalizer::equals_estricto((string) $cliente->asistente_phone, $remitente);
        });

        if ($ticket->client_employee_id === null) {
            return $empleados->isEmpty() && $duenos->count() === 1
                && (int) $duenos->first()->id === (int) $ticket->client_id ? null : false;
        }

        $empleado = $empleados->first();
        if ($empleados->count() !== 1 || $duenos->isNotEmpty() || (int) $empleado->id !== (int) $ticket->client_employee_id
            || (int) $empleado->client_id !== (int) $ticket->client_id || (int) $empleado->empresa_employee_id < 1) {
            return false;
        }

        return (int) $empleado->empresa_employee_id;
    }

    private function fallo(int $status, string $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
