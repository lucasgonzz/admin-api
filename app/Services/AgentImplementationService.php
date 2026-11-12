<?php

namespace App\Services;

use App\Models\AgentImplementation;
use App\Models\AgentImplementationEvent;
use App\Models\AgentImplementationStage;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Las reglas de las implementaciones de Agentes de IA (misión `implementacion-agentes-ia`, 28/10/2026):
 * alta con sus ocho etapas, registro de etapas (mezclando `data`), bitácora, cambios de datos y cierre, y
 * el armado del estado (`impl`) que devuelve `claude/agent-implementations/*`.
 *
 * 🔴 Vive en un servicio y no en el controlador para que haya UNA sola definición de «así se registra una
 * etapa»: hoy la usa `ClaudeAgentImplementationOpsController` y la nota de Martín
 * (`SupportCodexService::implementation_note` con `tipo: agentes`) usa `add_event()`; el día que el panel
 * tenga pantalla, usa lo mismo.
 *
 * Lo que este servicio NUNCA hace: mandar nada al cliente (eso es `AgentImplementationSupportWhatsappService`)
 * ni tocar el sistema del cliente (la extensión, la conexión de Kapso). Solo escribe filas del admin.
 */
class AgentImplementationService
{
    /** Cuántos eventos (los más nuevos) viajan en el estado `impl`. */
    const EVENTS_IN_PAYLOAD = 50;

    /**
     * La implementación ABIERTA (`in_progress`) de un cliente, o null.
     *
     * @param int  $client_id Id del cliente.
     * @param bool $lock      true = `lockForUpdate()` (adentro de una transacción).
     *
     * @return AgentImplementation|null
     */
    public function find_open_for_client($client_id, $lock = false)
    {
        $query = AgentImplementation::where('client_id', (int) $client_id)
            ->where('status', AgentImplementation::STATUS_IN_PROGRESS)
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Da de alta la implementación de un cliente con sus ocho etapas: la 1 `in_progress` (con el camino en
     * su `data`, que es lo primero que se registra) y el resto `pending`. Deja un evento `avance`.
     *
     * 🔴 Una sola abierta por cliente: se chequea con la fila del cliente bloqueada, así dos altas
     * simultáneas no crean dos (la base no tiene unique compuestos por regla del repo). Si ya hay una,
     * devuelve `['created' => false, 'existing' => $abierta]` y no escribe nada.
     *
     * @param Client $client El cliente.
     * @param string $camino nuevo | propio.
     *
     * @return array{created: bool, implementation: AgentImplementation|null, existing: AgentImplementation|null}
     */
    public function create(Client $client, $camino, $paquete = AgentImplementation::PAQUETE_FULL)
    {
        return DB::transaction(function () use ($client, $camino, $paquete) {
            Client::where('id', $client->id)->lockForUpdate()->first();

            $existing = $this->find_open_for_client($client->id, true);
            if ($existing !== null) {
                return ['created' => false, 'implementation' => null, 'existing' => $existing];
            }

            $now = now();

            $implementation = AgentImplementation::create([
                'client_id'     => (int) $client->id,
                'status'        => AgentImplementation::STATUS_IN_PROGRESS,
                'camino'        => $camino === null ? null : (string) $camino,
                'paquete'       => (string) $paquete,
                'flujo_version' => AgentImplementation::FLUJO_ENTREGA_PERSONAL,
                'current_stage' => AgentImplementation::FIRST_STAGE,
                'started_at'    => $now,
            ]);

            for ($number = AgentImplementation::FIRST_STAGE; $number <= AgentImplementation::LAST_STAGE; $number++) {
                $is_first = $number === AgentImplementation::FIRST_STAGE;
                $solo_full = ! $is_first && $paquete === AgentImplementation::PAQUETE_LITE;

                AgentImplementationStage::create([
                    'agent_implementation_id' => $implementation->id,
                    'stage_number'            => $number,
                    'status'                  => $solo_full ? AgentImplementationStage::STATUS_SKIPPED : ($is_first ? AgentImplementationStage::STATUS_IN_PROGRESS : AgentImplementationStage::STATUS_PENDING),
                    'data'                    => $is_first ? ($camino === null ? null : ['camino' => (string) $camino]) : ($solo_full ? ['motivo' => 'paquete_lite'] : null),
                    'started_at'              => $is_first ? $now : null,
                    'completed_at'            => $solo_full ? $now : null,
                ]);
            }

            $this->add_event(
                $implementation,
                'Arrancó la implementación de Agentes de IA ' . ucfirst($paquete) . ($camino === null ? '' : ' (camino ' . $camino . ')') . '.',
                AgentImplementation::FIRST_STAGE,
                AgentImplementationEvent::KIND_AVANCE,
                AgentImplementationEvent::SOURCE_CLAUDE
            );

            return ['created' => true, 'implementation' => $implementation->fresh(), 'existing' => null];
        });
    }

    /**
     * Registra una etapa: cambia su estado, MEZCLA `data` con lo que ya tenía (las claves nuevas pisan las
     * viejas del mismo nombre, el resto queda) y, si vino, deja la `nota` como evento. Después recalcula
     * `current_stage`.
     *
     * Fechas: `in_progress` pone `started_at` si no tenía y limpia `completed_at`; `completed` y `skipped`
     * ponen `completed_at` (y `started_at` si no tenía); `pending` limpia las dos (es «volver atrás»).
     *
     * Si el estado cambió, deja un evento `avance` («Etapa 3 (Conexión con Meta): completed»). Una llamada
     * que solo suma `data` con el mismo estado no ensucia la bitácora con un avance que no fue.
     *
     * 🔴 `whatsapp_ops` NO se escribe desde acá (lo rechaza el controlador con 422): es la memoria de la
     * idempotencia de los envíos, y pisarla permitiría mandar dos veces el mismo mensaje.
     *
     * @param AgentImplementation       $implementation La implementación.
     * @param int                       $stage_number   1 a 8.
     * @param string                    $status         pending | in_progress | completed | skipped.
     * @param array<string, mixed>|null $data           Lo que se suma a `data` (o null).
     * @param string|null               $nota           Nota opcional (≤ 1000).
     * @param string                    $source         Quién lo registra (martin | claude | panel).
     *
     * @return AgentImplementation La implementación recargada.
     */
    public function update_stage(AgentImplementation $implementation, $stage_number, $status, $data = null, $nota = null, $source = AgentImplementationEvent::SOURCE_CLAUDE)
    {
        DB::transaction(function () use ($implementation, $stage_number, $status, $data, $nota, $source) {
            $locked = AgentImplementation::where('id', $implementation->id)->lockForUpdate()->first();
            $this->validar_avance($locked, (int) $stage_number, (string) $status, $data);

            $stage = AgentImplementationStage::where('agent_implementation_id', $implementation->id)
                ->where('stage_number', (int) $stage_number)
                ->lockForUpdate()
                ->first();

            /* Una implementación creada por este servicio tiene las ocho; si falta (borrada a mano), se crea
               en vez de fallar: registrar una etapa no puede depender de que nadie haya tocado la base. */
            if ($stage === null) {
                $stage = new AgentImplementationStage([
                    'agent_implementation_id' => $implementation->id,
                    'stage_number'            => (int) $stage_number,
                    'status'                  => AgentImplementationStage::STATUS_PENDING,
                ]);
            }

            $previous_status = (string) $stage->status;
            $now             = now();

            if (is_array($data) && count($data) > 0) {
                $current     = is_array($stage->data) ? $stage->data : [];
                $stage->data = array_replace($current, $data);
            }

            $stage->status = (string) $status;
            $this->apply_stage_dates($stage, (string) $status, $now);
            $stage->save();

            if ($previous_status !== (string) $status) {
                $this->add_event(
                    $implementation,
                    'Etapa ' . (int) $stage_number . ' (' . AgentImplementation::stage_name($stage_number) . '): ' . $status . '.',
                    (int) $stage_number,
                    AgentImplementationEvent::KIND_AVANCE,
                    $source
                );
            }

            $nota = trim((string) $nota);
            if ($nota !== '') {
                $this->add_event($implementation, $nota, (int) $stage_number, AgentImplementationEvent::KIND_NOTA, $source);
            }

            $this->refresh_current_stage($implementation);
        });

        return $implementation->fresh();
    }

    /**
     * Cambia los datos de la implementación (camino, ids de Kapso y Meta, teléfono de contacto) y/o la cierra.
     *
     * - Los ids y el teléfono se guardan en sus columnas y se deja un evento `dato` que nombra QUÉ se cargó
     *   pero no los valores: la bitácora viaja al contexto de Martín y ahí no van ids de Meta ni teléfonos.
     * - `status` `completed` o `cancelled` cierra la implementación con `completed_at` = ahora (si cambia
     *   de estado) y deja un evento `avance`. Mandar el mismo estado que ya tiene no hace nada.
     *
     * @param AgentImplementation  $implementation La implementación.
     * @param array<string, mixed> $fields         Solo las claves que vinieron (un null explícito borra el valor).
     *
     * @return AgentImplementation La implementación recargada.
     */
    public function update_fields(AgentImplementation $implementation, array $fields)
    {
        DB::transaction(function () use ($implementation, $fields) {
            $locked = AgentImplementation::where('id', $implementation->id)->lockForUpdate()->first();

            if (($fields['status'] ?? null) === AgentImplementation::STATUS_COMPLETED
                && (int) $locked->flujo_version >= AgentImplementation::FLUJO_ENTREGA_PERSONAL) {
                $entrega = $this->entrega_personal($locked);
                if (! $entrega['completa']) {
                    throw new \DomainException('Primero hay que entregar el asistente personal: ' . implode(', ', $entrega['pendientes']) . '. No se escribió nada.');
                }
                $abiertas = AgentImplementationStage::where('agent_implementation_id', $locked->id)
                    ->whereNotIn('status', [AgentImplementationStage::STATUS_COMPLETED, AgentImplementationStage::STATUS_SKIPPED])->exists();
                if ($abiertas) {
                    throw new \DomainException('Hay etapas pendientes de la implementación. Completalas antes de cerrarla. No se escribió nada.');
                }
            }

            $changed_data = [];
            foreach (['camino', 'kapso_customer_id', 'phone_number_id', 'waba_id', 'business_portfolio_id', 'contact_phone'] as $column) {
                if (! array_key_exists($column, $fields)) {
                    continue;
                }

                $value = $fields[$column] === null ? null : trim((string) $fields[$column]);
                $value = $value === '' ? null : $value;

                if ($locked->{$column} !== $value) {
                    $locked->{$column} = $value;
                    $changed_data[]    = $column;
                }
            }

            $closed_to = null;
            if (array_key_exists('status', $fields) && $fields['status'] !== null && (string) $fields['status'] !== (string) $locked->status) {
                $locked->status       = (string) $fields['status'];
                $locked->completed_at = now();
                $closed_to            = (string) $fields['status'];
            }

            $locked->save();

            if (count($changed_data) > 0) {
                /* Solo los NOMBRES de lo que cambió (y el camino, que no es un dato sensible): los ids de Meta
                   y el teléfono de contacto no van a la bitácora. */
                $parts = [];
                foreach ($changed_data as $column) {
                    $parts[] = $column === 'camino'
                        ? 'camino = ' . ($locked->camino === null ? 'sin definir' : $locked->camino)
                        : $column . ($locked->{$column} === null ? ' (borrado)' : '');
                }

                $this->add_event(
                    $locked,
                    'Se actualizaron datos de la implementación: ' . implode(', ', $parts) . '.',
                    null,
                    AgentImplementationEvent::KIND_DATO,
                    AgentImplementationEvent::SOURCE_CLAUDE
                );
            }

            if ($closed_to !== null) {
                $this->add_event(
                    $locked,
                    $closed_to === AgentImplementation::STATUS_COMPLETED
                        ? 'Se cerró la implementación: completada.'
                        : 'Se cerró la implementación: cancelada.',
                    null,
                    AgentImplementationEvent::KIND_AVANCE,
                    AgentImplementationEvent::SOURCE_CLAUDE
                );
            }
        });

        return $implementation->fresh();
    }

    /**
     * Agrega un evento a la bitácora (recortando el texto al tope de la columna).
     *
     * @param AgentImplementation $implementation La implementación.
     * @param string              $texto          El texto.
     * @param int|null            $stage_number   La etapa (null = general).
     * @param string              $kind           nota | avance | whatsapp | dato.
     * @param string              $source         martin | claude | panel.
     *
     * @return AgentImplementationEvent
     */
    public function add_event(AgentImplementation $implementation, $texto, $stage_number, $kind, $source)
    {
        return AgentImplementationEvent::create([
            'agent_implementation_id' => (int) $implementation->id,
            'stage_number'            => $stage_number === null ? null : (int) $stage_number,
            'kind'                    => (string) $kind,
            'source'                  => (string) $source,
            'texto'                   => AgentImplementationEvent::fit_texto($texto),
        ]);
    }

    /**
     * Recalcula y guarda `current_stage` (la primera etapa que no está completed ni skipped; 8 si todas).
     *
     * @param AgentImplementation $implementation La implementación.
     *
     * @return int La etapa actual.
     */
    public function refresh_current_stage(AgentImplementation $implementation)
    {
        $stages  = AgentImplementationStage::where('agent_implementation_id', $implementation->id)->get(['stage_number', 'status']);
        $current = $implementation->paquete === AgentImplementation::PAQUETE_LITE
            ? AgentImplementation::FIRST_STAGE
            : AgentImplementation::compute_current_stage($stages);

        AgentImplementation::where('id', $implementation->id)->update(['current_stage' => $current]);

        return $current;
    }

    /**
     * El estado `impl` del contrato: las columnas + `etapas` + `eventos` (los últimos 50, más nuevos
     * primero) + `cliente: {id, name}`.
     *
     * 🔴 Se arma campo por campo y no con `toArray()`: `Client` no oculta sus claves de API, y un
     * `toArray()` de la relación las sacaría a una respuesta que una sesión pega en una conversación.
     * `data` de cada etapa sale SIN `whatsapp_ops` (es la memoria interna de la idempotencia, con huellas)
     * y como objeto JSON aunque esté vacía (`{}`, no `[]`), para que el que la lee pueda pedirle claves.
     *
     * @param AgentImplementation $implementation La implementación.
     *
     * @return array<string, mixed>
     */
    public function to_payload(AgentImplementation $implementation)
    {
        $client = Client::find((int) $implementation->client_id);

        $stages = [];
        foreach (AgentImplementationStage::where('agent_implementation_id', $implementation->id)->orderBy('stage_number')->get() as $stage) {
            $data = $stage->public_data();

            $stages[] = [
                'numero'       => (int) $stage->stage_number,
                'nombre'       => AgentImplementation::stage_name($stage->stage_number),
                'estado'       => (string) $stage->status,
                'data'         => count($data) > 0 ? $data : new \stdClass(),
                'started_at'   => $this->instant($stage->started_at),
                'completed_at' => $this->instant($stage->completed_at),
            ];
        }

        $events = [];
        $rows   = AgentImplementationEvent::where('agent_implementation_id', $implementation->id)
            ->orderByDesc('id')
            ->limit(self::EVENTS_IN_PAYLOAD)
            ->get();
        foreach ($rows as $event) {
            $events[] = $this->event_payload($event);
        }

        return [
            'id'                    => (int) $implementation->id,
            'client_id'             => (int) $implementation->client_id,
            'paquete'               => $implementation->paquete ?: AgentImplementation::PAQUETE_FULL,
            'flujo_version'         => (int) ($implementation->flujo_version ?: 1),
            'entrega_personal'      => $this->entrega_personal($implementation),
            'status'                => (string) $implementation->status,
            'camino'                => $implementation->camino === null ? null : (string) $implementation->camino,
            'current_stage'         => (int) $implementation->current_stage,
            'kapso_customer_id'     => $implementation->kapso_customer_id,
            'phone_number_id'       => $implementation->phone_number_id,
            'waba_id'               => $implementation->waba_id,
            'business_portfolio_id' => $implementation->business_portfolio_id,
            /* Enmascarado: la respuesta la lee una sesión que la pega en una conversación, y para saber si
               está cargado y cuál es alcanza con el final (mismo criterio que el destino del WhatsApp del
               sistema). */
            'contact_phone'         => $this->mask_phone($implementation->contact_phone),
            'started_at'            => $this->instant($implementation->started_at),
            'completed_at'          => $this->instant($implementation->completed_at),
            'created_at'            => $this->instant($implementation->created_at),
            'updated_at'            => $this->instant($implementation->updated_at),
            'etapas'                => $stages,
            'eventos'               => $events,
            'cliente'               => $client === null ? null : ['id' => (int) $client->id, 'name' => (string) $client->name],
        ];
    }

    /** Tres instancias de la primera entrega, sin confundir un link enviado con el onboarding terminado. */
    public function entrega_personal(AgentImplementation $implementation, ?array $data = null)
    {
        if ($data === null) {
            $stage = AgentImplementationStage::where('agent_implementation_id', $implementation->id)->where('stage_number', 1)->first();
            $data = $stage !== null && is_array($stage->data) ? $stage->data : [];
        }

        $instancias = [];
        $pendientes = [];
        foreach (AgentImplementation::ENTREGA_PERSONAL as $clave => $config) {
            $hitos = [];
            $faltan = [];
            foreach ($config['hitos'] as $key => $nombre) {
                $completado = ($data[$key] ?? false) === true;
                $hitos[] = ['clave' => $key, 'nombre' => $nombre, 'completado' => $completado];
                if (! $completado) {
                    $faltan[] = $key;
                }
            }
            if ($clave === 'guia' && ! $this->es_url_de_guia($data['pdf_agentes_url'] ?? null)) {
                $faltan[] = 'pdf_agentes_url';
            }
            $instancias[] = ['clave' => $clave, 'nombre' => $config['nombre'], 'completa' => count($faltan) === 0, 'hitos' => $hitos, 'pendientes' => $faltan];
            $pendientes = array_merge($pendientes, $faltan);
        }

        return [
            'requerida' => (int) $implementation->flujo_version >= AgentImplementation::FLUJO_ENTREGA_PERSONAL,
            'completa' => count($pendientes) === 0,
            'instancias' => $instancias,
            'pendientes' => $pendientes,
            'app_url' => 'https://ia.comerciocity.com',
            'pdf_agentes_url' => $this->es_url_de_guia($data['pdf_agentes_url'] ?? null) ? $data['pdf_agentes_url'] : null,
            'pdf_disponible_url' => url('/api/guias/agentes-ia-primeros-pasos.pdf'),
        ];
    }

    /** La guarda vive bajo el mismo lock que la escritura: dos avances simultáneos no pueden saltearla. */
    protected function validar_avance(AgentImplementation $implementation, $numero, $status, $data)
    {
        if ((int) $implementation->flujo_version < AgentImplementation::FLUJO_ENTREGA_PERSONAL) {
            return;
        }
        if ($numero > 1) {
            if ($implementation->paquete === AgentImplementation::PAQUETE_LITE) {
                throw new \DomainException('El paquete Lite no implementa el agente que atiende a clientes por WhatsApp: las etapas 2 a 8 no aplican. No se escribió nada.');
            }
            if ($status !== AgentImplementationStage::STATUS_PENDING && ! $this->entrega_personal($implementation)['completa']) {
                throw new \DomainException('Primero completá la configuración del asistente personal, el onboarding y la entrega del PDF antes de seguir con el agente de clientes. No se escribió nada.');
            }
            if ($status !== AgentImplementationStage::STATUS_PENDING && ! in_array($implementation->camino, AgentImplementation::CAMINOS, true)) {
                throw new \DomainException('Antes de implementar el agente de clientes definí el camino nuevo o propio. La entrega del asistente personal no necesita esa elección. No se escribió nada.');
            }
            return;
        }

        $stage = AgentImplementationStage::where('agent_implementation_id', $implementation->id)->where('stage_number', 1)->first();
        $merged = array_replace($stage !== null && is_array($stage->data) ? $stage->data : [], is_array($data) ? $data : []);
        $entrega = $this->entrega_personal($implementation, $merged);
        if ($status === AgentImplementationStage::STATUS_SKIPPED) {
            throw new \DomainException('La entrega del asistente personal es obligatoria en Full y Lite y no se puede saltear. No se escribió nada.');
        }
        if ($status === AgentImplementationStage::STATUS_COMPLETED && ! $entrega['completa']) {
            throw new \DomainException('Falta completar la primera entrega: ' . implode(', ', $entrega['pendientes']) . '. No se escribió nada.');
        }
        // El PDF se entrega después de dejar funcional el asistente y completar el contexto del negocio.
        if (($merged['pdf_agentes_enviado'] ?? false) === true
            && (! $entrega['instancias'][0]['completa'] || ! $entrega['instancias'][1]['completa'] || ! $this->es_url_de_guia($merged['pdf_agentes_url'] ?? null))) {
            throw new \DomainException('Antes de registrar el PDF enviado, completá WhatsApp y onboarding y anotá la URL HTTPS del PDF. No se escribió nada.');
        }
    }

    protected function es_url_de_guia($url)
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && preg_match('/\.pdf$/i', (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    /**
     * Un teléfono enmascarado (todo menos los últimos cuatro dígitos), o null si no hay.
     *
     * @param string|null $phone El teléfono.
     *
     * @return string|null
     */
    public function mask_phone($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        return str_repeat('•', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    /**
     * Un evento con la forma del contrato: `{id, etapa, kind, source, texto, created_at}`.
     *
     * @param AgentImplementationEvent $event El evento.
     *
     * @return array<string, mixed>
     */
    public function event_payload(AgentImplementationEvent $event)
    {
        return [
            'id'         => (int) $event->id,
            'etapa'      => $event->stage_number === null ? null : (int) $event->stage_number,
            'kind'       => (string) $event->kind,
            'source'     => (string) $event->source,
            'texto'      => (string) $event->texto,
            'created_at' => $this->instant($event->created_at),
        ];
    }

    /**
     * Pone las fechas de la etapa según el estado al que pasa (ver `update_stage()`).
     *
     * @param AgentImplementationStage $stage  La etapa.
     * @param string                   $status El estado nuevo.
     * @param Carbon                   $now    Ahora.
     *
     * @return void
     */
    protected function apply_stage_dates(AgentImplementationStage $stage, $status, Carbon $now)
    {
        if ($status === AgentImplementationStage::STATUS_PENDING) {
            $stage->started_at   = null;
            $stage->completed_at = null;

            return;
        }

        if ($stage->started_at === null) {
            $stage->started_at = $now;
        }

        if ($status === AgentImplementationStage::STATUS_IN_PROGRESS) {
            $stage->completed_at = null;

            return;
        }

        /* completed o skipped: se cierra ahora si no estaba cerrada (re-registrar una completed no le
           cambia la fecha de cierre). */
        if ($stage->completed_at === null) {
            $stage->completed_at = $now;
        }
    }

    /**
     * Un instante como ISO 8601 con el huso, o null (mismo formato que el resto de `claude/*`).
     *
     * @param mixed $value Carbon, string o null.
     *
     * @return string|null
     */
    protected function instant($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable $exception) {
            return (string) $value;
        }
    }
}
