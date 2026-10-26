<?php

namespace App\Services;

use App\Http\Controllers\Api\ClaudeImplementationOpsController;
use App\Models\Client;
use App\Models\EcommerceImplementation;
use App\Models\EcommerceImplementationMessage;
use App\Models\EcommerceImplementationStageConfig;
use App\Models\Implementation;
use App\Models\ImplementationMail;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStageConfig;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Arma el bloque `implementation` que recibe Martín (el soporte de Codex) en el contexto de un ticket
 * (misión soporte-con-contexto-de-implementacion, 26/10/2026).
 *
 * Hasta esta misión, un cliente con la implementación en curso no llegaba nunca al soporte: el webhook
 * mandaba su mensaje al hilo de la implementación y, en modo manual, no lo contestaba nadie. Desde ahora,
 * con el circuito de Codex prendido, ese mensaje abre (o sigue) un ticket, y Martín tiene que poder
 * seguir la conversación de la implementación donde la dejó Lucas. Este servicio le da lo que necesita
 * para eso: en qué etapa está cada implementación (la del sistema y la de la tienda), qué se hizo en
 * cada una, qué mails de hito salieron y lo último que se habló.
 *
 * Reglas que valen para todo el archivo:
 *
 *  - 🔴 **SIN DATOS PERSONALES.** No sale ningún teléfono (ni el del dueño ni el del responsable de la
 *    migración), ninguna casilla de mail, ni nada de lo que el cliente cargó en el formulario. El link
 *    del formulario sale solo si el que escribe es el DUEÑO (`client_employee_id === null`): es un link
 *    que deja cargar los datos de la empresa, y un empleado no tiene por qué recibirlo.
 *  - **Una implementación cerrada hace más de 30 días no viaja.** Ya no es contexto: es historia, y
 *    meterla haría que Martín le hable al cliente de algo terminado hace meses.
 *  - **Una cerrada hace ≤ 30 días viaja resumida:** `abierta: false`, con etapas y mails pero con
 *    `conversacion` y `form_link` en null. Sirve para contestar «¿ya quedó todo?» sin reabrir nada.
 *  - **Nunca lanza.** El contexto se arma en cada `claim` y en cada `GET tickets/{id}`; si una consulta
 *    de acá fallara, el ticket se quedaría sin atender. Ante un error el bloque lo DICE (`error`) en vez
 *    de volver null, porque null significa «no tiene implementación» y Martín lo leería así.
 *
 * Lógica replicada (no reutilizada) de `ClaudeImplementationOpsController`: sus métodos son `protected`
 * y están atados al request de la skill. Acá se usan solo sus constantes públicas (`ETAPAS`,
 * `ETAPAS_HEREDADAS`) como respaldo de los nombres de etapas.
 */
class SupportImplementationContextService
{
    /**
     * Días que una implementación cerrada sigue viajando en el contexto (resumida).
     *
     * @var int
     */
    const DIAS_DE_CERRADA_RECIENTE = 30;

    /**
     * Cuántos mensajes de la conversación de la implementación del sistema viajan (los últimos).
     *
     * @var int
     */
    const MENSAJES_DEL_SISTEMA = 40;

    /**
     * Cuántos mensajes de la conversación de la implementación de la tienda viajan (los últimos).
     *
     * @var int
     */
    const MENSAJES_DE_LA_TIENDA = 20;

    /**
     * Tope de caracteres de cada texto que viaja (cuerpo de un mensaje). Un mensaje de WhatsApp largo
     * (una lista de precios pegada, un mail reenviado) no puede comerse el contexto entero.
     *
     * @var int
     */
    const TOPE_DE_CARACTERES = 2000;

    /**
     * Prefijo del `whatsapp_message_id` de los mensajes que la skill `/implementar` registra cuando los
     * manda por WhatsApp Web (el teléfono de Lucas), y no por el número de Kapso.
     *
     * @var string
     */
    const PREFIJO_WHATSAPP_WEB = 'waweb-';

    /**
     * Nombres de las etapas de la tienda, de respaldo para cuando el catálogo de la base no los tiene
     * (una base sin seeders, un entorno de tests). Son los mismos del seeder
     * `EcommerceImplementationStageConfigSeeder`; manda el de la base si está.
     *
     * @var array<int, string>
     */
    const ETAPAS_DE_LA_TIENDA = [
        1 => 'Configuración de la tienda',
        2 => 'Compra y delegación del dominio',
        3 => 'Instalación del API',
        4 => 'Instalación del SPA',
        5 => 'Activación',
    ];

    /**
     * Arma el bloque `implementation` para un ticket.
     *
     * @param SupportTicket $ticket Ticket de soporte (con o sin `client` cargado).
     *
     * @return array<string, mixed>|null null = el cliente no tiene ninguna implementación abierta ni
     *                                   cerrada en los últimos 30 días.
     */
    public function bloque_para_el_ticket(SupportTicket $ticket)
    {
        try {
            $ticket->loadMissing('client');
            $client = $ticket->client;

            if ($client === null) {
                return null;
            }

            /* El dueño es el contacto que no resolvió a ningún empleado: no hay otro flag de dueño en
             * este repo (ver la rama del asistente en `WhatsappWebhookController::receive`). */
            $es_el_dueno = $ticket->client_employee_id === null;

            $sistema = $this->bloque_del_sistema($client, $es_el_dueno);
            $tienda  = $this->bloque_de_la_tienda($client);

            if ($sistema === null && $tienda === null) {
                return null;
            }

            return [
                'sistema' => $sistema,
                'tienda'  => $tienda,
            ];
        } catch (\Throwable $exception) {
            Log::channel('daily')->error('Soporte Codex: no se pudo armar el contexto de la implementación.', [
                'support_ticket_id' => $ticket->id,
                'error'             => $exception->getMessage(),
            ]);

            return [
                'sistema' => null,
                'tienda'  => null,
                'error'   => 'No se pudo leer la implementación de este cliente. No des por hecho que no tiene una: consultalo con Lucas.',
            ];
        }
    }

    /**
     * El bloque `sistema`: la implementación del sistema del cliente, si viaja.
     *
     * @param Client $client      Cliente del ticket.
     * @param bool   $es_el_dueno true = el que escribe es el dueño (habilita `form_link`).
     *
     * @return array<string, mixed>|null
     */
    protected function bloque_del_sistema(Client $client, $es_el_dueno)
    {
        $implementation = Implementation::where('client_id', $client->id)->with('stages')->first();

        if ($implementation === null || ! $this->viaja($implementation->status, $implementation->completed_at)) {
            return null;
        }

        $abierta = $this->esta_abierta($implementation->status);
        $nombres = $this->nombres_de_etapas_del_sistema();
        $actual  = (int) $implementation->current_stage;

        return [
            'id'                     => (int) $implementation->id,
            'status'                 => (string) $implementation->status,
            'abierta'                => $abierta,
            'automation_mode'        => (string) ($implementation->automation_mode ?: 'manual'),
            'started_at'             => $this->instante($implementation->started_at),
            'completed_at'           => $this->instante($implementation->completed_at),
            'etapa_actual'           => $actual > 0 && isset($nombres[$actual])
                ? ['numero' => $actual, 'nombre' => $nombres[$actual]]
                : null,
            'etapas'                 => $this->etapas_del_sistema($implementation, $nombres),
            'form_submitted_at'      => $this->instante($implementation->form_submitted_at),
            'user_setup_executed_at' => $this->instante($implementation->user_setup_executed_at),
            'form_link'              => $abierta && $es_el_dueno ? $implementation->form_link : null,
            'tienda_contratada'      => $this->tienda_contratada($client),
            'sistema_url'            => $this->vacio_a_null((new ClientEmpresaApiUrlResolver())->resolve_spa_url($client)),
            'mails'                  => $this->mails_del_sistema($implementation),
            'conversacion'           => $abierta ? $this->conversacion_del_sistema($implementation) : null,
        ];
    }

    /**
     * El bloque `tienda`: la implementación de la tienda online del cliente, si viaja.
     *
     * @param Client $client Cliente del ticket.
     *
     * @return array<string, mixed>|null
     */
    protected function bloque_de_la_tienda(Client $client)
    {
        $implementation = EcommerceImplementation::where('client_id', $client->id)
            ->with('stages', 'client_ecommerce')
            ->first();

        if ($implementation === null || ! $this->viaja($implementation->status, $implementation->completed_at)) {
            return null;
        }

        $abierta = $this->esta_abierta($implementation->status);
        $nombres = $this->nombres_de_etapas_de_la_tienda();
        $actual  = (int) $implementation->current_stage;

        return [
            'id'           => (int) $implementation->id,
            'status'       => (string) $implementation->status,
            'abierta'      => $abierta,
            'started_at'   => $this->instante($implementation->started_at),
            'completed_at' => $this->instante($implementation->completed_at),
            'etapa_actual' => $actual > 0 && isset($nombres[$actual])
                ? ['numero' => $actual, 'nombre' => $nombres[$actual]]
                : null,
            'etapas'       => $this->etapas_de_la_tienda($implementation, $nombres),
            'dominio'      => $this->dominio_de_la_tienda($implementation),
            'conversacion' => $abierta ? $this->conversacion_de_la_tienda($implementation) : null,
        ];
    }

    /**
     * ¿La implementación viaja en el contexto? Sí si está abierta, o si se cerró hace ≤ 30 días.
     *
     * Una `completed` sin `completed_at` no viaja: no hay forma de saber si es reciente, y dar por
     * reciente algo que puede tener un año es lo que esta regla quiere evitar.
     *
     * @param string|null $status       Estado de la implementación.
     * @param mixed       $completed_at Carbon o null.
     *
     * @return bool
     */
    protected function viaja($status, $completed_at)
    {
        if ($this->esta_abierta($status)) {
            return true;
        }

        if (! $completed_at instanceof Carbon) {
            return false;
        }

        return $completed_at->greaterThanOrEqualTo(now()->subDays(self::DIAS_DE_CERRADA_RECIENTE));
    }

    /**
     * ¿Está abierta? Todo lo que no sea `completed` (`in_progress`, `paused`, `pending`): una pausada
     * sigue siendo una implementación sin terminar, y Martín tiene que saberlo.
     *
     * @param string|null $status Estado de la implementación.
     *
     * @return bool
     */
    protected function esta_abierta($status)
    {
        return (string) $status !== 'completed';
    }

    /**
     * Las etapas del sistema: las siete del flujo y la 8 heredada solo si llegó a arrancar (mismo
     * criterio que `ClaudeImplementationOpsController::bloque_de_etapas()`), con las acciones que
     * registraron el panel y la skill en `data['actions']`.
     *
     * @param Implementation     $implementation La implementación, con `stages` cargadas.
     * @param array<int, string> $nombres        Nombres por número de etapa.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function etapas_del_sistema(Implementation $implementation, array $nombres)
    {
        $por_etapa = [];

        foreach ($implementation->stages as $etapa) {
            $por_etapa[(int) $etapa->stage_number] = $etapa;
        }

        $numeros = range(1, Implementation::ULTIMA_ETAPA);

        if (isset($por_etapa[Implementation::ETAPA_HEREDADA])
            && ! in_array((string) $por_etapa[Implementation::ETAPA_HEREDADA]->status, ['pending', 'skipped'], true)) {
            $numeros[] = Implementation::ETAPA_HEREDADA;
        }

        $bloque = [];

        foreach ($numeros as $numero) {
            $etapa    = isset($por_etapa[$numero]) ? $por_etapa[$numero] : null;
            $datos    = $etapa !== null && is_array($etapa->data) ? $etapa->data : [];
            $entradas = isset($datos['actions']) && is_array($datos['actions']) ? $datos['actions'] : [];
            $acciones = [];

            foreach ($entradas as $entrada) {
                if (! is_array($entrada)) {
                    continue;
                }

                /* Solo qué, cuándo y por dónde. El texto y el teléfono que la skill guarda en la acción
                 * no viajan: el texto ya está en la conversación y el teléfono es un dato personal. */
                $acciones[] = [
                    'accion' => isset($entrada['action']) ? (string) $entrada['action'] : null,
                    'at'     => isset($entrada['at']) ? (string) $entrada['at'] : null,
                    'canal'  => isset($entrada['canal']) ? (string) $entrada['canal'] : null,
                ];
            }

            $bloque[] = [
                'numero'       => $numero,
                'nombre'       => isset($nombres[$numero]) ? $nombres[$numero] : ('Etapa ' . $numero),
                'estado'       => $etapa === null ? 'pending' : (string) $etapa->status,
                'started_at'   => $etapa === null ? null : $this->instante($etapa->started_at),
                'completed_at' => $etapa === null ? null : $this->instante($etapa->completed_at),
                'acciones'     => $acciones,
            ];
        }

        return $bloque;
    }

    /**
     * Las etapas de la tienda (1 a 5), con su estado. Sin acciones: el flujo de la tienda no las registra.
     *
     * @param EcommerceImplementation $implementation La implementación, con `stages` cargadas.
     * @param array<int, string>      $nombres        Nombres por número de etapa.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function etapas_de_la_tienda(EcommerceImplementation $implementation, array $nombres)
    {
        $por_etapa = [];

        foreach ($implementation->stages as $etapa) {
            $por_etapa[(int) $etapa->stage_number] = $etapa;
        }

        $numeros = array_unique(array_merge(array_keys($nombres), array_keys($por_etapa)));
        sort($numeros);

        $bloque = [];

        foreach ($numeros as $numero) {
            $etapa = isset($por_etapa[$numero]) ? $por_etapa[$numero] : null;

            $bloque[] = [
                'numero'       => (int) $numero,
                'nombre'       => isset($nombres[$numero]) ? $nombres[$numero] : ('Etapa ' . $numero),
                'estado'       => $etapa === null ? 'pending' : (string) $etapa->status,
                'started_at'   => $etapa === null ? null : $this->instante($etapa->started_at),
                'completed_at' => $etapa === null ? null : $this->instante($etapa->completed_at),
            ];
        }

        return $bloque;
    }

    /**
     * Los nombres de las etapas del sistema: del catálogo de la base y, donde falte alguno, de las
     * constantes públicas de `ClaudeImplementationOpsController` (mismo criterio que su `nombres_de_etapas()`).
     *
     * @return array<int, string>
     */
    protected function nombres_de_etapas_del_sistema()
    {
        $nombres = ClaudeImplementationOpsController::ETAPAS + ClaudeImplementationOpsController::ETAPAS_HEREDADAS;

        foreach (ImplementationStageConfig::query()->get(['stage_number', 'name']) as $config) {
            $numero = (int) $config->stage_number;

            if ($numero >= 1 && $numero <= Implementation::ETAPA_HEREDADA && trim((string) $config->name) !== '') {
                $nombres[$numero] = (string) $config->name;
            }
        }

        return $nombres;
    }

    /**
     * Los nombres de las etapas de la tienda: del catálogo de la base y, donde falte, de `ETAPAS_DE_LA_TIENDA`.
     *
     * @return array<int, string>
     */
    protected function nombres_de_etapas_de_la_tienda()
    {
        $nombres = self::ETAPAS_DE_LA_TIENDA;

        foreach (EcommerceImplementationStageConfig::query()->get(['stage_number', 'name']) as $config) {
            $numero = (int) $config->stage_number;

            if ($numero >= 1 && trim((string) $config->name) !== '') {
                $nombres[$numero] = (string) $config->name;
            }
        }

        return $nombres;
    }

    /**
     * ¿El contrato incluye la tienda online? Misma regla que el bloque `cliente` del estado de
     * `claude/implementations/{id}`: null = el contrato no lo dice (o es el caso híbrido de perfiles de
     * ecommerce cobrados sin la modalidad), y un "no" solo con la lista cargada y sin `ecommerce`.
     *
     * @param Client $client Cliente.
     *
     * @return bool|null
     */
    protected function tienda_contratada(Client $client)
    {
        $sistemas = is_array($client->contract_sistemas)
            ? array_values(array_filter($client->contract_sistemas, 'is_string'))
            : [];

        if (count($sistemas) === 0) {
            return null;
        }

        if (in_array('ecommerce', $sistemas, true)) {
            return true;
        }

        return (int) $client->contract_perfiles_ecommerce > 0 ? null : false;
    }

    /**
     * Los mails de hito de la implementación: cuál, en qué estado y cuándo salió. Sin la casilla.
     *
     * Si la tabla todavía no existe en la base (un entorno sin esa migración) se devuelve lista vacía,
     * igual que en el estado de la skill: el contexto no puede caerse por un dato accesorio.
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function mails_del_sistema(Implementation $implementation)
    {
        if (! Schema::hasTable('implementation_mails')) {
            return [];
        }

        $bloque = [];

        foreach (ImplementationMail::where('implementation_id', $implementation->id)->orderBy('id')->get() as $fila) {
            $bloque[] = [
                'hito'       => (string) $fila->hito,
                'estado'     => (string) $fila->estado,
                'enviado_at' => $this->instante($fila->enviado_at),
            ];
        }

        return $bloque;
    }

    /**
     * Los últimos mensajes de la implementación del sistema, en orden cronológico. Sin teléfonos.
     *
     * `canal` dice por dónde salió: `whatsapp_web` = lo mandó la skill desde el teléfono de Lucas (su
     * `whatsapp_message_id` empieza con `waweb-`); `whatsapp` = el número de Kapso (o un entrante).
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function conversacion_del_sistema(Implementation $implementation)
    {
        $filas = ImplementationMessage::where('implementation_id', $implementation->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::MENSAJES_DEL_SISTEMA)
            ->get()
            ->reverse()
            ->values();

        $bloque = [];

        foreach ($filas as $fila) {
            $bloque[] = $this->mensaje($fila->direction, $fila->stage_number, $fila->whatsapp_message_id, $fila->body, $fila->sent_at ?: $fila->created_at);
        }

        return $bloque;
    }

    /**
     * Los últimos mensajes de la implementación de la tienda, en orden cronológico.
     *
     * @param EcommerceImplementation $implementation La implementación de la tienda.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function conversacion_de_la_tienda(EcommerceImplementation $implementation)
    {
        $filas = EcommerceImplementationMessage::where('ecommerce_implementation_id', $implementation->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::MENSAJES_DE_LA_TIENDA)
            ->get()
            ->reverse()
            ->values();

        $bloque = [];

        foreach ($filas as $fila) {
            $bloque[] = $this->mensaje($fila->direction, $fila->stage_number, $fila->whatsapp_message_id, $fila->body, $fila->sent_at ?: $fila->created_at);
        }

        return $bloque;
    }

    /**
     * Un mensaje de la conversación, con la forma del contrato.
     *
     * @param string|null $direction           inbound | outbound.
     * @param int|null    $stage_number        Etapa en la que se registró.
     * @param string|null $whatsapp_message_id Id externo (decide el canal).
     * @param string|null $body                Texto.
     * @param mixed       $at                  Carbon o null.
     *
     * @return array<string, mixed>
     */
    protected function mensaje($direction, $stage_number, $whatsapp_message_id, $body, $at)
    {
        $id = (string) $whatsapp_message_id;

        return [
            'direction' => (string) $direction,
            'etapa'     => $stage_number === null ? null : (int) $stage_number,
            'canal'     => strpos($id, self::PREFIJO_WHATSAPP_WEB) === 0 ? 'whatsapp_web' : 'whatsapp',
            'body'      => $this->recortar($body),
            'at'        => $this->instante($at),
        ];
    }

    /**
     * El dominio de la tienda: el de la tienda online cargada y, si falta, el que el cliente eligió en
     * la etapa 1 (`data['domain']`), igual que `EcommerceImplementationConversationService`.
     *
     * @param EcommerceImplementation $implementation La implementación de la tienda.
     *
     * @return string|null
     */
    protected function dominio_de_la_tienda(EcommerceImplementation $implementation)
    {
        $tienda = $implementation->client_ecommerce;
        $dominio = $tienda !== null ? trim((string) $tienda->domain) : '';

        if ($dominio !== '') {
            return $dominio;
        }

        foreach ($implementation->stages as $etapa) {
            if ((int) $etapa->stage_number === 1 && is_array($etapa->data) && isset($etapa->data['domain'])) {
                $dominio = trim((string) $etapa->data['domain']);
            }
        }

        return $dominio !== '' ? $dominio : null;
    }

    /**
     * Un texto recortado a `TOPE_DE_CARACTERES`, con una marca de que se cortó.
     *
     * @param string|null $texto Texto original.
     *
     * @return string|null
     */
    protected function recortar($texto)
    {
        if ($texto === null) {
            return null;
        }

        $texto = (string) $texto;

        if (mb_strlen($texto) <= self::TOPE_DE_CARACTERES) {
            return $texto;
        }

        return mb_substr($texto, 0, self::TOPE_DE_CARACTERES) . '…';
    }

    /**
     * Un instante como ISO 8601 con el huso, o null.
     *
     * @param mixed $valor Carbon, string de la base o null.
     *
     * @return string|null
     */
    protected function instante($valor)
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof Carbon) {
            return $valor->toIso8601String();
        }

        try {
            return Carbon::parse((string) $valor)->toIso8601String();
        } catch (\Throwable $exception) {
            return (string) $valor;
        }
    }

    /**
     * Un string vacío como null (el resolutor de URLs devuelve '' cuando no tiene ninguna).
     *
     * @param string|null $valor Valor.
     *
     * @return string|null
     */
    protected function vacio_a_null($valor)
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
