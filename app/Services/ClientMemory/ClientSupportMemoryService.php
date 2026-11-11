<?php

namespace App\Services\ClientMemory;

use App\Models\Client;
use App\Models\ClientSupportContext;
use App\Models\ClientSupportMemoryEntry;
use App\Models\ImplementationFollowup;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\ImplementationFollowups\ImplementationFollowupService;
use App\Services\SupportCodexService;
use App\Services\SupportImplementationContextService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * La ficha del cliente de Martín y el índice de sus tickets (misión cruzada `ficha-del-cliente`, 11/11/2026).
 *
 * Martín atiende cada ticket sin acordarse de los anteriores: «a veces 10 tickets después vuelve a pasar algo
 * relacionado y no saben qué pasó» (Lucas). Este servicio guarda la MEMORIA del equipo con cada cliente —una idea por
 * entrada: una decisión, una preferencia, un problema con su solución, un compromiso con fecha, cómo trabaja el
 * negocio— y la hace viajar SIEMPRE en el contexto del ticket (`ficha_cliente`) y del seguimiento. Al lado van el
 * índice de TODOS los tickets del cliente (`indice_tickets`: número, título y resumen) y la búsqueda en todo su
 * historial, para traer el detalle de uno viejo a demanda.
 *
 * Reglas de la ficha (las del contrato del plan):
 *  - Una entrada nunca se edita en el lugar: `reemplazar` crea una nueva vigente con `replaces_id` y pasa la vieja a
 *    `reemplazada`; `archivar` la pasa a `archivada` con motivo. Desde el panel, «editar» = reemplazar (`lucas`).
 *  - Las operaciones de un pedido entran todas o ninguna (transacción).
 *  - Un `id` de otro cliente (o inexistente) → 422 `entrada_ajena`; uno que ya no está vigente → 422
 *    `entrada_no_vigente`.
 *  - Tope de 60 vigentes por cliente: pasarlo → 422 `ficha_llena` (Martín consolida).
 *  - 🔴 El texto no puede llevar lo que el admin ya oculta como secreto en el historial (lo que sigue a
 *    «contraseña» / «clave», `SupportImplementationContextService::ocultar_claves()`, la MISMA regla) → 422
 *    `dato_sensible`. La ficha viaja en cada prompt: un secreto adentro se repetiría para siempre.
 *  - Reintentar es inofensivo: agregar el mismo texto en la misma categoría, reemplazar con el texto que ya quedó o
 *    archivar una archivada devuelven la entrada que ya estaba con `ya_estaba: true`.
 */
class ClientSupportMemoryService
{
    /** Entradas vigentes por cliente. */
    const TOPE_VIGENTES = 60;

    /** Operaciones por pedido. */
    const TOPE_OPERACIONES = 10;

    /** Largo del texto de una entrada. */
    const LARGO_TEXTO = 600;

    /** Largo del motivo de un archivo. */
    const LARGO_MOTIVO = 300;

    /** Tope (aproximado, en caracteres de JSON) de las entradas del bloque `ficha_cliente`. */
    const TOPE_CARACTERES_FICHA = 12000;

    /** Tope (aproximado, en caracteres de JSON) de los tickets del bloque `indice_tickets`. */
    const TOPE_CARACTERES_INDICE = 10000;

    /** Largo del resumen de cada ticket en el índice. */
    const RESUMEN_EN_EL_INDICE = 200;

    /** Caracteres a cada lado de la coincidencia en el fragmento de la búsqueda. */
    const CONTEXTO_DEL_FRAGMENTO = 200;

    /** Largo del primer mensaje de cada ticket en la lista de la búsqueda. */
    const LARGO_PRIMER_MENSAJE = 150;

    /** Coincidencias por defecto y máximas de la búsqueda. */
    const COINCIDENCIAS_POR_DEFECTO = 20;
    const COINCIDENCIAS_MAXIMAS = 40;

    /** Largo del título y del resumen del ticket. */
    const TITULO_MINIMO = 3;
    const TITULO_MAXIMO = 80;
    const RESUMEN_MAXIMO = 600;

    /** Zona de los vencimientos (`vigente_hasta` es un día de Argentina). */
    const ZONA = 'America/Argentina/Buenos_Aires';

    /**
     * Las reglas de validación de `operaciones`, para los dos controladores que las reciben (monitor y Lucas).
     *
     * @return array<string, string>
     */
    public static function reglas_de_operaciones()
    {
        $categorias = implode(',', ClientSupportMemoryEntry::CATEGORIAS);

        return [
            'operaciones'                 => 'required|array|min:1|max:' . self::TOPE_OPERACIONES,
            'operaciones.*'               => 'array',
            'operaciones.*.accion'        => 'required|string|in:agregar,reemplazar,archivar',
            'operaciones.*.categoria'     => 'required_if:operaciones.*.accion,agregar|nullable|string|in:' . $categorias,
            'operaciones.*.texto'         => 'required_unless:operaciones.*.accion,archivar|nullable|string|max:' . self::LARGO_TEXTO,
            'operaciones.*.id'            => 'required_unless:operaciones.*.accion,agregar|nullable|integer|min:1',
            'operaciones.*.motivo'        => 'required_if:operaciones.*.accion,archivar|nullable|string|max:' . self::LARGO_MOTIVO,
            'operaciones.*.vigente_hasta' => 'sometimes|nullable|date_format:Y-m-d',
        ];
    }

    /* ==============================================================================================
     | Operaciones
     |============================================================================================= */

    /**
     * `POST claude/support-codex/tickets/{id}/client-memory`: Martín escribe en la ficha con la reserva del TICKET
     * (la misma validación que `implementation-note`: 423 `invalid_lease`). El cliente sale del ticket, nunca del
     * body (409 `sin_cliente` si el ticket no tiene).
     *
     * @param int                              $ticket_id   El ticket.
     * @param string                           $lease_token La reserva.
     * @param array<int, array<string, mixed>> $operaciones Ya validadas por el controlador.
     *
     * @return array<string, mixed> `{ok, resultados, ficha_cliente}`.
     */
    public function operar_desde_el_ticket($ticket_id, $lease_token, array $operaciones)
    {
        return DB::transaction(function () use ($ticket_id, $lease_token, $operaciones) {
            $ticket = $this->ticket_con_reserva($ticket_id, $lease_token);

            if (empty($ticket->client_id)) {
                throw new FichaClienteException(409, 'sin_cliente', 'Este ticket no tiene cliente: no hay ficha donde escribir.');
            }

            $resultados = $this->aplicar((int) $ticket->client_id, $operaciones, ClientSupportMemoryEntry::SOURCE_MARTIN, (int) $ticket->id, null);

            return ['ok' => true, 'resultados' => $resultados, 'ficha_cliente' => $this->bloque_para_el_cliente((int) $ticket->client_id)];
        });
    }

    /**
     * `POST claude/support-codex/implementation-followups/{id}/client-memory`: igual, con la reserva del SEGUIMIENTO
     * (la validación por hash de `ImplementationFollowupService`). Sin reserva vigente: 423 `invalid_lease`.
     *
     * @param int                              $followup_id El seguimiento.
     * @param string                           $lease_token La reserva.
     * @param array<int, array<string, mixed>> $operaciones Ya validadas.
     *
     * @return array<string, mixed>
     */
    public function operar_desde_el_seguimiento($followup_id, $lease_token, array $operaciones)
    {
        return DB::transaction(function () use ($followup_id, $lease_token, $operaciones) {
            $followup = $this->seguimiento_con_reserva($followup_id, $lease_token, true);

            $resultados = $this->aplicar((int) $followup->client_id, $operaciones, ClientSupportMemoryEntry::SOURCE_MARTIN, null, null);

            return ['ok' => true, 'resultados' => $resultados, 'ficha_cliente' => $this->bloque_para_el_cliente((int) $followup->client_id)];
        });
    }

    /**
     * Las operaciones de Lucas (`POST claude/client-memory` y el panel): `source: lucas`.
     *
     * @param int                              $client_id   El cliente.
     * @param array<int, array<string, mixed>> $operaciones Ya validadas.
     * @param int|null                         $admin_id    Quién, si fue desde el panel.
     *
     * @return array<string, mixed>
     */
    public function operar_como_lucas($client_id, array $operaciones, $admin_id = null)
    {
        return DB::transaction(function () use ($client_id, $operaciones, $admin_id) {
            $resultados = $this->aplicar((int) $client_id, $operaciones, ClientSupportMemoryEntry::SOURCE_LUCAS, null, $admin_id === null ? null : (int) $admin_id);

            return ['ok' => true, 'resultados' => $resultados, 'ficha_cliente' => $this->bloque_para_el_cliente((int) $client_id)];
        });
    }

    /**
     * Aplica las operaciones sobre la ficha del cliente. Tiene que correr adentro de una transacción (los tres
     * caminos públicos la abren): un freno a mitad tira la excepción y no queda nada escrito.
     *
     * @param int                              $client_id   El cliente.
     * @param array<int, array<string, mixed>> $operaciones Las operaciones.
     * @param string                           $source      martin | lucas.
     * @param int|null                         $ticket_id   El ticket donde se escribió.
     * @param int|null                         $admin_id    El admin del panel.
     *
     * @return array<int, array<string, mixed>> `[{accion, id, replaces_id?, ya_estaba?}]`.
     */
    protected function aplicar($client_id, array $operaciones, $source, $ticket_id, $admin_id)
    {
        if (count($operaciones) < 1 || count($operaciones) > self::TOPE_OPERACIONES) {
            throw new FichaClienteException(422, 'operaciones_invalidas', 'Mandá entre 1 y ' . self::TOPE_OPERACIONES . ' operaciones.');
        }

        /* El bloqueo de las vigentes del cliente serializa dos escrituras encimadas sobre la misma ficha (el tope de 60
           se cuenta adentro de la misma transacción). */
        $antes = ClientSupportMemoryEntry::where('client_id', (int) $client_id)
            ->where('status', ClientSupportMemoryEntry::STATUS_VIGENTE)
            ->lockForUpdate()
            ->count();

        $base = ['client_id' => (int) $client_id, 'source' => $source, 'support_ticket_id' => $ticket_id, 'admin_id' => $admin_id];

        $resultados = [];
        foreach (array_values($operaciones) as $indice => $operacion) {
            $operacion = is_array($operacion) ? $operacion : [];
            $accion    = isset($operacion['accion']) ? (string) $operacion['accion'] : '';

            if ($accion === 'agregar') {
                $resultados[] = $this->agregar($base, $operacion, $indice);
            } elseif ($accion === 'reemplazar') {
                $resultados[] = $this->reemplazar($base, $operacion, $indice);
            } elseif ($accion === 'archivar') {
                $resultados[] = $this->archivar((int) $client_id, $operacion, $indice);
            } else {
                throw new FichaClienteException(422, 'operaciones_invalidas', 'La operación ' . ($indice + 1) . ' no tiene una acción válida (agregar, reemplazar o archivar).', ['operacion' => $indice]);
            }
        }

        $despues = ClientSupportMemoryEntry::where('client_id', (int) $client_id)
            ->where('status', ClientSupportMemoryEntry::STATUS_VIGENTE)
            ->count();

        if ($despues > self::TOPE_VIGENTES && $despues > $antes) {
            throw new FichaClienteException(422, 'ficha_llena', 'La ficha de este cliente llegaría a ' . $despues . ' entradas vigentes y el tope es '
                . self::TOPE_VIGENTES . '. Consolidá: reemplazá varias entradas parecidas por una, o archivá las que ya no sirven. No se escribió nada.', [
                'vigentes' => $antes,
                'tope'     => self::TOPE_VIGENTES,
            ]);
        }

        return $resultados;
    }

    /**
     * @param array<string, mixed> $base      client_id, source, support_ticket_id, admin_id.
     * @param array<string, mixed> $operacion La operación.
     * @param int                  $indice    Su posición (para el mensaje de error).
     *
     * @return array<string, mixed>
     */
    protected function agregar(array $base, array $operacion, $indice)
    {
        $categoria = $this->categoria_valida(isset($operacion['categoria']) ? $operacion['categoria'] : null, $indice);
        $texto     = $this->texto_valido(isset($operacion['texto']) ? $operacion['texto'] : null, $indice, 'texto', self::LARGO_TEXTO);
        $hasta     = $this->fecha_valida(isset($operacion['vigente_hasta']) ? $operacion['vigente_hasta'] : null, $indice);

        $existente = ClientSupportMemoryEntry::where('client_id', $base['client_id'])
            ->where('status', ClientSupportMemoryEntry::STATUS_VIGENTE)
            ->where('categoria', $categoria)
            ->where('texto', $texto)
            ->orderBy('id')
            ->first();
        if ($existente !== null) {
            return ['accion' => 'agregar', 'id' => (int) $existente->id, 'ya_estaba' => true];
        }

        $entrada = ClientSupportMemoryEntry::create(array_merge($base, [
            'categoria'     => $categoria,
            'texto'         => $texto,
            'vigente_hasta' => $hasta,
            'status'        => ClientSupportMemoryEntry::STATUS_VIGENTE,
        ]));

        return ['accion' => 'agregar', 'id' => (int) $entrada->id];
    }

    /**
     * @param array<string, mixed> $base      client_id, source, support_ticket_id, admin_id.
     * @param array<string, mixed> $operacion La operación.
     * @param int                  $indice    Su posición.
     *
     * @return array<string, mixed>
     */
    protected function reemplazar(array $base, array $operacion, $indice)
    {
        $vieja = $this->entrada_del_cliente((int) $base['client_id'], isset($operacion['id']) ? $operacion['id'] : null, $indice);
        $texto = $this->texto_valido(isset($operacion['texto']) ? $operacion['texto'] : null, $indice, 'texto', self::LARGO_TEXTO);

        $categoria = isset($operacion['categoria']) && $operacion['categoria'] !== null && $operacion['categoria'] !== ''
            ? $this->categoria_valida($operacion['categoria'], $indice)
            : (string) $vieja->categoria;
        $hasta = array_key_exists('vigente_hasta', $operacion)
            ? $this->fecha_valida($operacion['vigente_hasta'], $indice)
            : ($vieja->vigente_hasta === null ? null : $vieja->vigente_hasta->format('Y-m-d'));

        if ($vieja->status !== ClientSupportMemoryEntry::STATUS_VIGENTE) {
            /* Reintento de un reemplazo que ya se hizo: la nueva con ese texto ya está. */
            $hecha = $vieja->status === ClientSupportMemoryEntry::STATUS_REEMPLAZADA
                ? ClientSupportMemoryEntry::where('replaces_id', (int) $vieja->id)->where('texto', $texto)->orderByDesc('id')->first()
                : null;
            if ($hecha !== null) {
                return ['accion' => 'reemplazar', 'id' => (int) $hecha->id, 'replaces_id' => (int) $vieja->id, 'ya_estaba' => true];
            }

            throw new FichaClienteException(422, 'entrada_no_vigente', 'La entrada ' . (int) $vieja->id . ' ya no está vigente (' . $vieja->status
                . '): no se puede reemplazar. Agregá una nueva si hace falta. No se escribió nada.', ['operacion' => $indice, 'id' => (int) $vieja->id]);
        }

        $vieja_hasta = $vieja->vigente_hasta === null ? null : $vieja->vigente_hasta->format('Y-m-d');
        if ($texto === (string) $vieja->texto && $categoria === (string) $vieja->categoria && $hasta === $vieja_hasta) {
            return ['accion' => 'reemplazar', 'id' => (int) $vieja->id, 'replaces_id' => null, 'ya_estaba' => true];
        }

        $nueva = ClientSupportMemoryEntry::create(array_merge($base, [
            'categoria'     => $categoria,
            'texto'         => $texto,
            'vigente_hasta' => $hasta,
            'status'        => ClientSupportMemoryEntry::STATUS_VIGENTE,
            'replaces_id'   => (int) $vieja->id,
        ]));

        $vieja->status = ClientSupportMemoryEntry::STATUS_REEMPLAZADA;
        $vieja->save();

        return ['accion' => 'reemplazar', 'id' => (int) $nueva->id, 'replaces_id' => (int) $vieja->id];
    }

    /**
     * @param int                  $client_id El cliente.
     * @param array<string, mixed> $operacion La operación.
     * @param int                  $indice    Su posición.
     *
     * @return array<string, mixed>
     */
    protected function archivar($client_id, array $operacion, $indice)
    {
        $entrada = $this->entrada_del_cliente((int) $client_id, isset($operacion['id']) ? $operacion['id'] : null, $indice);
        $motivo  = $this->texto_valido(isset($operacion['motivo']) ? $operacion['motivo'] : null, $indice, 'motivo', self::LARGO_MOTIVO);

        if ($entrada->status === ClientSupportMemoryEntry::STATUS_ARCHIVADA) {
            return ['accion' => 'archivar', 'id' => (int) $entrada->id, 'ya_estaba' => true];
        }
        if ($entrada->status !== ClientSupportMemoryEntry::STATUS_VIGENTE) {
            throw new FichaClienteException(422, 'entrada_no_vigente', 'La entrada ' . (int) $entrada->id . ' ya fue reemplazada: archivá la que la reemplazó. No se escribió nada.', [
                'operacion' => $indice,
                'id'        => (int) $entrada->id,
            ]);
        }

        $entrada->status          = ClientSupportMemoryEntry::STATUS_ARCHIVADA;
        $entrada->archived_reason = $motivo;
        $entrada->save();

        return ['accion' => 'archivar', 'id' => (int) $entrada->id];
    }

    /**
     * La entrada `id` bloqueada, si es del cliente. 🔴 Si no existe o es de otro cliente: 422 `entrada_ajena` (sin
     * distinguir: decirle a quien escribe que el id existe en otro cliente ya es contarle algo de ese cliente).
     *
     * @param int   $client_id El cliente.
     * @param mixed $id        El id pedido.
     * @param int   $indice    Posición de la operación.
     *
     * @return ClientSupportMemoryEntry
     */
    protected function entrada_del_cliente($client_id, $id, $indice)
    {
        $entrada = is_numeric($id) && (int) $id > 0
            ? ClientSupportMemoryEntry::where('id', (int) $id)->lockForUpdate()->first()
            : null;

        if ($entrada === null || (int) $entrada->client_id !== (int) $client_id) {
            throw new FichaClienteException(422, 'entrada_ajena', 'La entrada ' . (is_scalar($id) ? (string) $id : '?')
                . ' no es de la ficha de este cliente. No se escribió nada.', ['operacion' => $indice]);
        }

        return $entrada;
    }

    /**
     * @param mixed $categoria La categoría pedida.
     * @param int   $indice    Posición.
     *
     * @return string
     */
    protected function categoria_valida($categoria, $indice)
    {
        $categoria = is_string($categoria) ? trim($categoria) : '';
        if (! in_array($categoria, ClientSupportMemoryEntry::CATEGORIAS, true)) {
            throw new FichaClienteException(422, 'categoria_invalida', 'La operación ' . ($indice + 1) . ' trae una categoría que no existe. Válidas: '
                . implode(', ', ClientSupportMemoryEntry::CATEGORIAS) . '.', ['operacion' => $indice]);
        }

        return $categoria;
    }

    /**
     * El texto recortado de espacios, no vacío, dentro del largo y sin secretos.
     *
     * @param mixed  $texto  El texto.
     * @param int    $indice Posición.
     * @param string $campo  texto | motivo.
     * @param int    $largo  Máximo.
     *
     * @return string
     */
    protected function texto_valido($texto, $indice, $campo, $largo)
    {
        $texto = is_string($texto) ? trim($texto) : '';
        if ($texto === '') {
            throw new FichaClienteException(422, 'texto_vacio', 'El ' . $campo . ' de la operación ' . ($indice + 1) . ' está vacío. No se escribió nada.', ['operacion' => $indice]);
        }
        if (mb_strlen($texto) > $largo) {
            throw new FichaClienteException(422, 'texto_largo', 'El ' . $campo . ' de la operación ' . ($indice + 1) . ' supera los ' . $largo . ' caracteres. Una entrada = una idea. No se escribió nada.', ['operacion' => $indice]);
        }
        if ($this->tiene_dato_sensible($texto)) {
            throw new FichaClienteException(422, 'dato_sensible', 'El ' . $campo . ' de la operación ' . ($indice + 1) . ' trae lo que el historial oculta como secreto (lo que sigue a «contraseña» o «clave»). '
                . 'En la ficha nunca van contraseñas, Clave Fiscal ni datos de acceso; si no es un secreto, escribilo sin esas palabras. No se escribió nada.', ['operacion' => $indice]);
        }

        return $texto;
    }

    /**
     * 🔴 La MISMA regla que oculta secretos en el historial del monitor: si `ocultar_claves()` cambiaría el texto,
     * el texto tiene un secreto (o algo con su forma) y no entra en la ficha.
     *
     * @param string $texto El texto.
     *
     * @return bool
     */
    public function tiene_dato_sensible($texto)
    {
        return SupportImplementationContextService::ocultar_claves((string) $texto) !== (string) $texto;
    }

    /**
     * @param mixed $fecha Y-m-d o null.
     * @param int   $indice Posición.
     *
     * @return string|null
     */
    protected function fecha_valida($fecha, $indice)
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        $parseada = is_string($fecha) ? \DateTime::createFromFormat('!Y-m-d', $fecha) : false;
        if ($parseada === false || $parseada->format('Y-m-d') !== $fecha) {
            throw new FichaClienteException(422, 'fecha_invalida', 'La fecha vigente_hasta de la operación ' . ($indice + 1) . ' tiene que venir como AAAA-MM-DD.', ['operacion' => $indice]);
        }

        return $fecha;
    }

    /* ==============================================================================================
     | Reservas
     |============================================================================================= */

    /**
     * El ticket bloqueado, con la reserva vigente de Martín (`SupportCodexService::assert_lease()`, la de
     * `implementation-note`). 404 si no existe; 423 `invalid_lease` si la reserva no es la vigente.
     *
     * @param int    $ticket_id El ticket.
     * @param string $token     La reserva.
     *
     * @return SupportTicket
     */
    protected function ticket_con_reserva($ticket_id, $token)
    {
        $ticket = SupportTicket::where('id', (int) $ticket_id)->lockForUpdate()->first();
        if ($ticket === null) {
            throw new FichaClienteException(404, 'sin_ticket', 'No existe el ticket ' . (int) $ticket_id . '.');
        }

        $caso = SupportCodexCase::where('support_ticket_id', $ticket->id)->lockForUpdate()->first();
        try {
            app(SupportCodexService::class)->assert_lease($caso, (string) $token);
        } catch (HttpException $e) {
            throw new FichaClienteException($e->getStatusCode(), 'invalid_lease', 'La reserva venció o pertenece a otro proceso.');
        }

        return $ticket;
    }

    /**
     * El seguimiento (bloqueado si `$bloquear`), con la reserva vigente del monitor (la validación por hash de
     * `ImplementationFollowupService`). 404 `sin_seguimiento`; 423 `invalid_lease`.
     *
     * @param int    $followup_id El seguimiento.
     * @param string $token       La reserva.
     * @param bool   $bloquear    true = lockForUpdate (escrituras).
     *
     * @return ImplementationFollowup
     */
    public function seguimiento_con_reserva($followup_id, $token, $bloquear)
    {
        $consulta = ImplementationFollowup::where('id', (int) $followup_id);
        $followup = $bloquear ? $consulta->lockForUpdate()->first() : $consulta->first();

        if ($followup === null) {
            throw new FichaClienteException(404, 'sin_seguimiento', 'No existe el seguimiento ' . (int) $followup_id . '.');
        }
        if (! app(ImplementationFollowupService::class)->reserva_valida($followup, (string) $token)) {
            throw new FichaClienteException(423, 'invalid_lease', 'La reserva del seguimiento venció o pertenece a otro proceso.');
        }

        return $followup;
    }

    /* ==============================================================================================
     | El bloque `ficha_cliente`
     |============================================================================================= */

    /**
     * La clave `ficha_cliente` del contexto de un ticket. null si el ticket no tiene cliente. Nunca lanza.
     *
     * Viaja aunque el contacto sea un empleado sin `can_query_system`: es contexto interno de Martín, y lo que puede
     * decirle a ese contacto lo limita su protocolo.
     *
     * @param SupportTicket $ticket El ticket.
     *
     * @return array<string, mixed>|null
     */
    public function bloque_para_el_ticket(SupportTicket $ticket)
    {
        if (empty($ticket->client_id)) {
            return null;
        }

        return $this->bloque_para_el_cliente((int) $ticket->client_id);
    }

    /**
     * `{entradas: [...], total_vigentes, truncada, ficha_operativa}`: las vigentes en el orden del contrato
     * (compromiso y decisión primero; después problema, preferencia, implementación, negocio y otro; dentro de cada
     * una, la más nueva primero), hasta ~12.000 caracteres. Nunca lanza: ante un error, null y al log (el contexto se
     * arma en cada claim y no puede caerse por la ficha).
     *
     * @param int $client_id El cliente.
     *
     * @return array<string, mixed>|null
     */
    public function bloque_para_el_cliente($client_id)
    {
        try {
            $vigentes = $this->ordenar_para_el_bloque(
                ClientSupportMemoryEntry::where('client_id', (int) $client_id)
                    ->where('status', ClientSupportMemoryEntry::STATUS_VIGENTE)
                    ->get()
            );

            $entradas  = [];
            $usados    = 0;
            $truncada  = false;
            foreach ($vigentes as $entrada) {
                $fila  = $this->entrada_para_el_monitor($entrada);
                $largo = mb_strlen((string) json_encode($fila, JSON_UNESCAPED_UNICODE));
                if ($usados + $largo > self::TOPE_CARACTERES_FICHA) {
                    $truncada = true;
                    break;
                }
                $usados    += $largo;
                $entradas[] = $fila;
            }

            return [
                'entradas'        => $entradas,
                'total_vigentes'  => $vigentes->count(),
                'truncada'        => $truncada,
                'ficha_operativa' => ClientSupportContext::ficha_operativa_de_cliente((int) $client_id),
            ];
        } catch (\Throwable $e) {
            Log::channel('daily')->error('Ficha del cliente: no se pudo armar el bloque.', [
                'client_id' => (int) $client_id,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param Collection $entradas Las vigentes.
     *
     * @return Collection
     */
    protected function ordenar_para_el_bloque(Collection $entradas)
    {
        $orden = array_flip(ClientSupportMemoryEntry::CATEGORIAS);

        return $entradas->sort(function (ClientSupportMemoryEntry $a, ClientSupportMemoryEntry $b) use ($orden) {
            $pa = isset($orden[$a->categoria]) ? $orden[$a->categoria] : count($orden);
            $pb = isset($orden[$b->categoria]) ? $orden[$b->categoria] : count($orden);
            if ($pa !== $pb) {
                return $pa < $pb ? -1 : 1;
            }

            return (int) $b->id <=> (int) $a->id;
        })->values();
    }

    /**
     * Una entrada como la lee Martín.
     *
     * @param ClientSupportMemoryEntry $entrada La entrada.
     *
     * @return array<string, mixed>
     */
    protected function entrada_para_el_monitor(ClientSupportMemoryEntry $entrada)
    {
        return [
            'id'                => (int) $entrada->id,
            'categoria'         => (string) $entrada->categoria,
            'texto'             => (string) $entrada->texto,
            'vigente_hasta'     => $entrada->vigente_hasta === null ? null : $entrada->vigente_hasta->format('Y-m-d'),
            'vencida'           => $this->vencida($entrada),
            'source'            => (string) $entrada->source,
            'support_ticket_id' => $entrada->support_ticket_id === null ? null : (int) $entrada->support_ticket_id,
            'updated_at'        => $entrada->updated_at === null ? null : $entrada->updated_at->toIso8601String(),
        ];
    }

    /**
     * Si el vencimiento ya pasó (hoy en Argentina todavía vale).
     *
     * @param ClientSupportMemoryEntry $entrada La entrada.
     *
     * @return bool
     */
    protected function vencida(ClientSupportMemoryEntry $entrada)
    {
        return $entrada->vigente_hasta !== null
            && $entrada->vigente_hasta->format('Y-m-d') < Carbon::now(self::ZONA)->format('Y-m-d');
    }

    /* ==============================================================================================
     | Lectura de Lucas (API y panel)
     |============================================================================================= */

    /**
     * La ficha completa de un cliente para Lucas: las entradas (las vigentes, o todas con `$incluir_historial`) con
     * quién y cuándo, la `ficha_operativa` y el bloque tal como lo lee Martín.
     *
     * @param int  $client_id         El cliente.
     * @param bool $incluir_historial true = también las reemplazadas y archivadas.
     *
     * @return array<string, mixed>
     */
    public function listar($client_id, $incluir_historial)
    {
        $consulta = ClientSupportMemoryEntry::with('admin')->where('client_id', (int) $client_id);
        if (! $incluir_historial) {
            $consulta->where('status', ClientSupportMemoryEntry::STATUS_VIGENTE);
        }
        $entradas = $consulta->orderByDesc('id')->get();

        $reemplazos = ClientSupportMemoryEntry::where('client_id', (int) $client_id)->whereNotNull('replaces_id')
            ->pluck('id', 'replaces_id');

        $total_vigentes = ClientSupportMemoryEntry::where('client_id', (int) $client_id)
            ->where('status', ClientSupportMemoryEntry::STATUS_VIGENTE)->count();

        return [
            'client_id'       => (int) $client_id,
            'categorias'      => ClientSupportMemoryEntry::CATEGORIAS,
            'tope_vigentes'   => self::TOPE_VIGENTES,
            'total_vigentes'  => $total_vigentes,
            'ficha_operativa' => ClientSupportContext::ficha_operativa_de_cliente((int) $client_id),
            'entradas'        => $entradas->map(function (ClientSupportMemoryEntry $entrada) use ($reemplazos) {
                return array_merge($this->entrada_para_el_monitor($entrada), [
                    'status'          => (string) $entrada->status,
                    'replaces_id'     => $entrada->replaces_id === null ? null : (int) $entrada->replaces_id,
                    'reemplazada_por' => isset($reemplazos[(int) $entrada->id]) ? (int) $reemplazos[(int) $entrada->id] : null,
                    'admin_id'        => $entrada->admin_id === null ? null : (int) $entrada->admin_id,
                    'admin_name'      => $entrada->admin === null ? null : (string) $entrada->admin->name,
                    'archived_reason' => $entrada->archived_reason,
                    'created_at'      => $entrada->created_at === null ? null : $entrada->created_at->toIso8601String(),
                ]);
            })->values()->all(),
        ];
    }

    /**
     * Los tickets del cliente para la sección «Ficha de soporte» del panel: todos (Lucas ve los de todos los
     * contactos), el más nuevo primero, con número, título, resumen, estado, fechas, canal y contacto.
     *
     * @param int $client_id El cliente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tickets_para_el_panel($client_id)
    {
        return SupportTicket::with('client', 'client_employee')
            ->where('client_id', (int) $client_id)
            ->withCount(['messages as mensajes_reales' => function ($mensajes) {
                SupportMessage::apply_mensaje_real_conditions($mensajes, 'support_messages');
            }])
            ->orderByDesc('id')
            ->get()
            ->map(function (SupportTicket $ticket) {
                return [
                    'id'                 => (int) $ticket->id,
                    'titulo'             => $this->titulo_de($ticket),
                    'resumen'            => $ticket->summary === null ? null : (string) $ticket->summary,
                    'summary_updated_at' => $ticket->summary_updated_at === null ? null : $ticket->summary_updated_at->toIso8601String(),
                    'name_source'        => $ticket->name_source,
                    'status'             => $ticket->status,
                    'source'             => $ticket->source,
                    'contact_name'       => $ticket->es_erp_sin_identidad_verificada() ? null : $ticket->resolve_contact_display_name(),
                    'opened_at'          => $ticket->opened_at ? $ticket->opened_at->toIso8601String() : null,
                    'closed_at'          => $ticket->closed_at ? $ticket->closed_at->toIso8601String() : null,
                    'mensajes'           => (int) $ticket->mensajes_reales,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Guarda la `ficha_operativa` (la prosa de Lucas) del cliente. Solo esa columna: `notas_internas` no se toca.
     *
     * @param int         $client_id El cliente.
     * @param string|null $texto     La prosa, o null/'' para vaciarla.
     *
     * @return string|null La que quedó.
     */
    public function guardar_ficha_operativa($client_id, $texto)
    {
        $texto = $texto === null ? null : trim((string) $texto);
        $texto = $texto === '' ? null : $texto;

        DB::transaction(function () use ($client_id, $texto) {
            $fila = ClientSupportContext::where('client_id', (int) $client_id)->lockForUpdate()->first();

            if ($fila !== null) {
                $fila->ficha_operativa = $texto;
                $fila->save();

                return;
            }
            if ($texto !== null) {
                ClientSupportContext::create(['client_id' => (int) $client_id, 'ficha_operativa' => $texto, 'created_via' => 'panel']);
            }
        });

        return ClientSupportContext::ficha_operativa_de_cliente((int) $client_id);
    }

    /* ==============================================================================================
     | Título y resumen del ticket
     |============================================================================================= */

    /**
     * `POST claude/support-codex/tickets/{id}/title`: Martín pone el título (`support_tickets.name`) y el resumen
     * del ticket, con la reserva del ticket. 🔴 Si Lucas ya le puso nombre a mano (`name_source = lucas`), el título
     * no se pisa: se guarda solo el resumen y vuelve `titulo_conservado: true`.
     *
     * @param int         $ticket_id   El ticket.
     * @param string      $lease_token La reserva.
     * @param string      $titulo      3..80.
     * @param string|null $resumen     ≤ 600, o null para no tocarlo.
     *
     * @return array<string, mixed> `{ok, titulo_conservado, titulo, resumen}`.
     */
    public function titular($ticket_id, $lease_token, $titulo, $resumen)
    {
        $titulo  = trim(preg_replace('/\s+/u', ' ', (string) $titulo));
        $resumen = $resumen === null ? null : trim((string) $resumen);

        if (mb_strlen($titulo) < self::TITULO_MINIMO || mb_strlen($titulo) > self::TITULO_MAXIMO) {
            throw new FichaClienteException(422, 'titulo_invalido', 'El título tiene que tener entre ' . self::TITULO_MINIMO . ' y ' . self::TITULO_MAXIMO . ' caracteres.');
        }
        if ($resumen !== null && mb_strlen($resumen) > self::RESUMEN_MAXIMO) {
            throw new FichaClienteException(422, 'resumen_largo', 'El resumen supera los ' . self::RESUMEN_MAXIMO . ' caracteres.');
        }
        if ($this->tiene_dato_sensible($titulo) || ($resumen !== null && $this->tiene_dato_sensible($resumen))) {
            throw new FichaClienteException(422, 'dato_sensible', 'El título o el resumen traen lo que el historial oculta como secreto (lo que sigue a «contraseña» o «clave»). Escribilos sin datos de acceso.');
        }

        $resultado = DB::transaction(function () use ($ticket_id, $lease_token, $titulo, $resumen) {
            $ticket = $this->ticket_con_reserva($ticket_id, $lease_token);

            $conservado = $ticket->name_source === ClientSupportMemoryEntry::SOURCE_LUCAS && trim((string) $ticket->name) !== '';
            if (! $conservado) {
                $ticket->name        = $titulo;
                $ticket->name_source = ClientSupportMemoryEntry::SOURCE_MARTIN;
            }
            if ($resumen !== null && $resumen !== '') {
                $ticket->summary            = $resumen;
                $ticket->summary_updated_at = now();
            }
            $ticket->save();

            return [
                'ok'                => true,
                'titulo_conservado' => $conservado,
                'titulo'            => (string) $ticket->name,
                'resumen'           => $ticket->summary === null ? null : (string) $ticket->summary,
                'ticket_id'         => (int) $ticket->id,
            ];
        });

        /* La bandeja muestra «#N · título»: que se entere sin recargar. Fuera de la transacción y sin dejar que una
           falla del broadcast voltee lo guardado. */
        try {
            \App\Events\SupportTicketUpdated::dispatch((int) $resultado['ticket_id']);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('Título del ticket: no se pudo avisar a la bandeja.', ['support_ticket_id' => (int) $resultado['ticket_id'], 'error' => $e->getMessage()]);
        }

        unset($resultado['ticket_id']);

        return $resultado;
    }

    /**
     * El título de un ticket como lo ve Martín (null si es un ticket del sistema sin identidad verificada, como en
     * el contexto).
     *
     * @param SupportTicket $ticket El ticket.
     *
     * @return string|null
     */
    protected function titulo_de(SupportTicket $ticket)
    {
        if ($ticket->es_erp_sin_identidad_verificada()) {
            return null;
        }

        $nombre = trim((string) $ticket->name);

        return $nombre === '' ? null : $nombre;
    }

    /* ==============================================================================================
     | El índice de tickets
     |============================================================================================= */

    /**
     * La clave `indice_tickets`: TODOS los tickets del cliente visibles para el contacto (la regla de
     * `previous_tickets`: un empleado ve solo los suyos), el más nuevo primero, con el actual marcado, hasta
     * ~10.000 caracteres. `{tickets: [{id, titulo, resumen, status, opened_at, closed_at, mensajes, actual}], total,
     * truncado}`. Nunca lanza: ante un error, null y al log.
     *
     * @param SupportTicket $ticket El ticket (puede ser uno en memoria, sin id, del dueño).
     *
     * @return array<string, mixed>|null
     */
    public function indice_de_tickets(SupportTicket $ticket)
    {
        try {
            $consulta = app(SupportCodexService::class)->consulta_de_tickets_visibles($ticket);
            if ($consulta === null) {
                return ['tickets' => [], 'total' => 0, 'truncado' => false];
            }

            $tickets = $consulta->withCount(['messages as mensajes_reales' => function ($mensajes) {
                SupportMessage::apply_mensaje_real_conditions($mensajes, 'support_messages');
            }])->orderByDesc('id')->get();

            $filas    = [];
            $usados   = 0;
            $truncado = false;
            foreach ($tickets as $uno) {
                $resumen = $uno->summary === null ? null : (string) $uno->summary;
                if ($resumen !== null && mb_strlen($resumen) > self::RESUMEN_EN_EL_INDICE) {
                    $resumen = rtrim(mb_substr($resumen, 0, self::RESUMEN_EN_EL_INDICE - 1)) . '…';
                }
                $fila = [
                    'id'        => (int) $uno->id,
                    'titulo'    => $this->titulo_de($uno),
                    'resumen'   => $resumen,
                    'status'    => $uno->status,
                    'opened_at' => $uno->opened_at ? $uno->opened_at->toIso8601String() : null,
                    'closed_at' => $uno->closed_at ? $uno->closed_at->toIso8601String() : null,
                    'mensajes'  => (int) $uno->mensajes_reales,
                    'actual'    => $ticket->id !== null && (int) $uno->id === (int) $ticket->id,
                ];
                $largo = mb_strlen((string) json_encode($fila, JSON_UNESCAPED_UNICODE));
                if ($usados + $largo > self::TOPE_CARACTERES_INDICE) {
                    $truncado = true;
                    break;
                }
                $usados += $largo;
                $filas[] = $fila;
            }

            return ['tickets' => $filas, 'total' => $tickets->count(), 'truncado' => $truncado];
        } catch (\Throwable $e) {
            Log::channel('daily')->error('Ficha del cliente: no se pudo armar el índice de tickets.', [
                'support_ticket_id' => $ticket->id === null ? null : (int) $ticket->id,
                'client_id'         => (int) $ticket->client_id,
                'error'             => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Un ticket de WhatsApp del dueño EN MEMORIA (nunca se guarda) para armar lo que es por ticket desde el
     * seguimiento: el dueño ve todos los tickets del cliente.
     *
     * @param Client $client El cliente.
     *
     * @return SupportTicket
     */
    public function ticket_del_dueno(Client $client)
    {
        $ticket                     = new SupportTicket();
        $ticket->client_id          = (int) $client->id;
        $ticket->client_employee_id = null;
        $ticket->client_user_id     = 0;
        $ticket->source             = 'whatsapp';
        $ticket->status             = 'open';
        $ticket->setRelation('client', $client);
        $ticket->setRelation('client_employee', null);

        return $ticket;
    }

    /* ==============================================================================================
     | Búsqueda en todo el historial
     |============================================================================================= */

    /**
     * `GET .../history-search?q=`: busca `q` en los mensajes REALES (sin borradores del agente) de TODOS los tickets
     * del cliente visibles para el contacto, y devuelve además la lista de esos tickets.
     *
     * 🔴 No es O(n) en PHP: un solo `LIKE` en SQL acotado por `support_ticket_id IN (tickets del cliente)` (índice
     * `support_messages_support_ticket_id_index`), con tope de filas. A PHP llegan solo las coincidencias, para el
     * filtro de confianza de los tickets del sistema (`mensaje_confiable_para_codex`) y el fragmento. El fragmento
     * (±200 caracteres) sale del cuerpo YA saneado como el historial: en los mensajes del equipo lo que sigue a
     * «contraseña» / «clave» llega como `[oculta]`.
     *
     * @param SupportTicket $ticket El ticket (o uno en memoria del dueño, desde el seguimiento).
     * @param string        $q      2..100 caracteres.
     * @param int           $limite ≤ 40.
     *
     * @return array<string, mixed> `{q, coincidencias, tickets, total_tickets}`.
     */
    public function buscar_en_el_historial(SupportTicket $ticket, $q, $limite)
    {
        $q      = trim((string) $q);
        $limite = max(1, min(self::COINCIDENCIAS_MAXIMAS, (int) $limite));

        $consulta = app(SupportCodexService::class)->consulta_de_tickets_visibles($ticket);
        if ($consulta === null) {
            return ['q' => $q, 'coincidencias' => [], 'tickets' => [], 'total_tickets' => 0];
        }

        $tickets = $consulta->withCount(['messages as mensajes_reales' => function ($mensajes) {
            SupportMessage::apply_mensaje_real_conditions($mensajes, 'support_messages');
        }])->orderByDesc('id')->get()->keyBy('id');
        $ids = $tickets->keys()->map(function ($id) {
            return (int) $id;
        })->all();

        if ($ids === []) {
            return ['q' => $q, 'coincidencias' => [], 'tickets' => [], 'total_tickets' => 0];
        }

        $encontrados = SupportMessage::whereIn('support_ticket_id', $ids);
        SupportMessage::apply_mensaje_real_conditions($encontrados, 'support_messages');
        /* Un poco más que el límite: algunas pueden caer en el filtro de confianza de los tickets del sistema. */
        $encontrados = $encontrados->where('body', 'like', '%' . $this->escapar_like($q) . '%')
            ->orderByDesc('id')
            ->limit($limite + 20)
            ->get();

        $coincidencias = [];
        foreach ($encontrados as $mensaje) {
            $suyo = $tickets->get((int) $mensaje->support_ticket_id);
            if ($suyo === null || ! $suyo->mensaje_confiable_para_codex($mensaje)) {
                continue;
            }
            $coincidencias[] = [
                'ticket_id'   => (int) $mensaje->support_ticket_id,
                'message_id'  => (int) $mensaje->id,
                'created_at'  => $mensaje->created_at ? $mensaje->created_at->toIso8601String() : null,
                'sender_type' => $mensaje->sender_type,
                'fragmento'   => $this->fragmento($this->cuerpo_saneado($mensaje), $q),
            ];
            if (count($coincidencias) >= $limite) {
                break;
            }
        }

        $primeros = $this->primeros_mensajes_del_cliente($tickets);

        return [
            'q'             => $q,
            'coincidencias' => $coincidencias,
            'tickets'       => $tickets->values()->map(function (SupportTicket $uno) use ($primeros) {
                return [
                    'id'             => (int) $uno->id,
                    'titulo'         => $this->titulo_de($uno),
                    'status'         => $uno->status,
                    'opened_at'      => $uno->opened_at ? $uno->opened_at->toIso8601String() : null,
                    'closed_at'      => $uno->closed_at ? $uno->closed_at->toIso8601String() : null,
                    'mensajes'       => (int) $uno->mensajes_reales,
                    'primer_mensaje' => isset($primeros[(int) $uno->id]) ? $primeros[(int) $uno->id] : null,
                ];
            })->all(),
            'total_tickets' => $tickets->count(),
        ];
    }

    /**
     * El primer mensaje real del CLIENTE de cada ticket (≤ 150 caracteres), en dos consultas (MIN(id) agrupado y los
     * cuerpos), no una por ticket.
     *
     * @param Collection $tickets Los tickets, por id.
     *
     * @return array<int, string>
     */
    protected function primeros_mensajes_del_cliente(Collection $tickets)
    {
        $minimos = SupportMessage::whereIn('support_ticket_id', $tickets->keys()->all())->where('sender_type', 'user');
        SupportMessage::apply_mensaje_real_conditions($minimos, 'support_messages');
        $minimos = $minimos->groupBy('support_ticket_id')->selectRaw('MIN(id) as primero')->pluck('primero')->all();

        if ($minimos === []) {
            return [];
        }

        $primeros = [];
        foreach (SupportMessage::whereIn('id', $minimos)->get() as $mensaje) {
            $suyo = $tickets->get((int) $mensaje->support_ticket_id);
            if ($suyo === null || ! $suyo->mensaje_confiable_para_codex($mensaje)) {
                continue;
            }
            $cuerpo = trim(preg_replace('/\s+/u', ' ', (string) $this->cuerpo_saneado($mensaje)));
            if ($cuerpo === '') {
                continue;
            }
            $primeros[(int) $mensaje->support_ticket_id] = mb_strlen($cuerpo) > self::LARGO_PRIMER_MENSAJE
                ? rtrim(mb_substr($cuerpo, 0, self::LARGO_PRIMER_MENSAJE - 1)) . '…'
                : $cuerpo;
        }

        return $primeros;
    }

    /**
     * El cuerpo como lo lee el monitor en el historial: a los mensajes del EQUIPO se les oculta lo que sigue a
     * «contraseña» / «clave» (la regla de `SupportCodexService::cuerpo_para_el_monitor()`); los del cliente, tal cual.
     *
     * @param SupportMessage $mensaje El mensaje.
     *
     * @return string
     */
    protected function cuerpo_saneado(SupportMessage $mensaje)
    {
        if ($mensaje->sender_type !== 'admin') {
            return (string) $mensaje->body;
        }

        return (string) SupportImplementationContextService::ocultar_claves($mensaje->body);
    }

    /**
     * ±200 caracteres alrededor de la primera aparición de `q` (sin distinguir mayúsculas ni tildes, como el LIKE
     * de MySQL), con «…» donde se cortó. Si `q` no aparece en el cuerpo saneado (cayó en lo oculto), el principio.
     *
     * @param string $cuerpo El cuerpo saneado.
     * @param string $q      Lo buscado.
     *
     * @return string
     */
    protected function fragmento($cuerpo, $q)
    {
        $cuerpo = (string) $cuerpo;
        $largo  = mb_strlen($cuerpo);

        $posicion = mb_stripos($cuerpo, $q);
        if ($posicion === false) {
            $plano   = Str::ascii($cuerpo);
            $buscado = Str::ascii($q);
            $posicion = mb_strlen($plano) === $largo && $buscado !== '' ? stripos($plano, $buscado) : false;
        }
        $posicion = $posicion === false ? 0 : (int) $posicion;

        $desde = max(0, $posicion - self::CONTEXTO_DEL_FRAGMENTO);
        $hasta = min($largo, $posicion + mb_strlen($q) + self::CONTEXTO_DEL_FRAGMENTO);

        return ($desde > 0 ? '…' : '') . mb_substr($cuerpo, $desde, $hasta - $desde) . ($hasta < $largo ? '…' : '');
    }

    /**
     * Neutraliza los comodines de LIKE (`%`, `_` y la barra) en lo que pidió Martín.
     *
     * @param string $texto El texto.
     *
     * @return string
     */
    protected function escapar_like($texto)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $texto);
    }
}
