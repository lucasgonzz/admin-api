<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Services\Pipelines\PipelineRuleException;
use App\Services\Pipelines\TableroDeJuanService;
use App\Services\Prueba\PruebaCicloService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La implementación contratada del cliente, la pausa de su prueba y las tareas que de ahí salen (misión embudo-juan-pipeline, E2).
 *
 * Es el servicio ÚNICO de la regla «Soporte importa sólo si el negocio contrató la implementación»: lo usan la ficha del cliente
 * (`PUT client/{id}/implementacion-contratada`), la llamada de Juan (`pago_la_mitad` con paquetes), la compuerta de importación
 * de Soporte, la entrega de la implementación y el contexto del agente.
 *
 * Valores de `clients.implementacion_contratada`: `no` (sin implementación), `mitad`, `completa` y `incluida` (los contratos
 * anteriores, que conservan «importación cuando lo necesite»: es el default de la columna).
 *
 * La pausa es «vencimiento al horizonte + marca `prueba_pausada_at`»: con eso `pruebas:vencer`, el asistente, el chat y el ERP
 * (C3) la tratan como vigente sin tocar sus consultas.
 */
class ImplementacionContratadaService
{
    /** Los estados posibles. */
    const ESTADOS = ['no', 'mitad', 'completa', 'incluida'];

    /** Los paquetes que se pueden implementar (Agentes Lite no tiene implementación). */
    const PAQUETES = ['gestion', 'tienda', 'agentes'];

    /** Hasta dónde se lleva el vencimiento de una prueba pausada. */
    const HORIZONTE_DE_PAUSA_DIAS = 365;

    /** Los días de prueba que corren desde la entrega. */
    const DIAS_DESDE_LA_ENTREGA = 7;

    /** Los días desde la entrega en que Juan vuelve a llamar. */
    const DIAS_HASTA_LA_LLAMADA_DE_CIERRE = 5;

    /** @var TableroDeJuanService */
    private $tablero;

    /** @var PruebaCicloService */
    private $ciclo;

    /**
     * @param TableroDeJuanService $tablero El tablero de Juan (tarjeta y lead).
     * @param PruebaCicloService   $ciclo   El ciclo de la prueba (vencimiento y C3).
     */
    public function __construct(TableroDeJuanService $tablero, PruebaCicloService $ciclo)
    {
        $this->tablero = $tablero;
        $this->ciclo   = $ciclo;
    }

    // ------------------------------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------------------------------

    /**
     * ¿Soporte importa para este cliente? `mitad`, `completa` o `incluida`.
     *
     * @param Client|null $client
     *
     * @return bool
     */
    public function importa_soporte($client): bool
    {
        return $client !== null && in_array((string) $client->implementacion_contratada, ['mitad', 'completa', 'incluida'], true);
    }

    /**
     * ¿Los artículos los importa Lucas (no el sistema)? `mitad` o `completa`.
     *
     * @param Client|null $client
     *
     * @return bool
     */
    public function articulos_los_importa_lucas($client): bool
    {
        return $client !== null && in_array((string) $client->implementacion_contratada, ['mitad', 'completa'], true);
    }

    /**
     * ¿La prueba está pausada? (`prueba_pausada_at` y sin reanudar).
     *
     * @param Client|null $client
     *
     * @return bool
     */
    public function esta_pausada($client): bool
    {
        return $client !== null && $client->prueba_pausada_at !== null && $client->prueba_reanudada_at === null;
    }

    /**
     * El bloque `implementacion_contratada` del contexto de Soporte.
     *
     * @param Client|null $client
     *
     * @return array<string, mixed>|null
     */
    public function bloque_para_soporte($client)
    {
        if ($client === null) {
            return null;
        }

        return [
            'estado'                      => (string) $client->implementacion_contratada,
            'paquetes'                    => array_values((array) $client->implementacion_paquetes),
            'importa_soporte'             => $this->importa_soporte($client),
            'articulos_los_importa_lucas' => $this->articulos_los_importa_lucas($client),
            'mitad_pagada_at'             => $this->fecha($client->implementacion_mitad_pagada_at),
            'articulos_recibidos_at'      => $this->fecha($client->implementacion_articulos_recibidos_at),
            'articulos_importados_at'     => $this->fecha($client->implementacion_articulos_ok_at),
            'prueba_pausada'              => $this->esta_pausada($client),
            'prueba_reanudada_at'         => $this->fecha($client->prueba_reanudada_at),
        ];
    }

    // ------------------------------------------------------------------------------------------
    // Fijar el estado
    // ------------------------------------------------------------------------------------------

    /**
     * Fija la implementación contratada del cliente. Si pasa de `no` o `incluida` a `mitad` o `completa`: registra la fecha del
     * pago, pausa la prueba, inicia la implementación, mueve al lead y a su tarjeta a «En implementación» y le crea a Lucas la
     * tarea de verificar el cobro y registrar la venta.
     *
     * @param Client        $client
     * @param string        $estado   Uno de {@see self::ESTADOS}.
     * @param array<mixed>  $paquetes Subconjunto de {@see self::PAQUETES} (obligatorio con `mitad` y `completa`).
     * @param Admin|null    $autor    Quien registra el pago (para sugerir el vendedor de la venta).
     *
     * @return Client El cliente actualizado.
     *
     * @throws PipelineRuleException 422 con `errors.estado` o `errors.paquetes`.
     */
    public function fijar(Client $client, string $estado, array $paquetes, $autor = null): Client
    {
        $paquetes = $this->validar($estado, $paquetes);

        $anterior = (string) $client->implementacion_contratada;
        $paga     = in_array($estado, ['mitad', 'completa'], true);
        $estrena  = $paga && in_array($anterior, ['no', 'incluida'], true);

        $client->implementacion_contratada = $estado;
        $client->implementacion_paquetes   = $paquetes === [] ? null : $paquetes;
        if ($estrena && $client->implementacion_mitad_pagada_at === null) {
            $client->implementacion_mitad_pagada_at = AppTime::now();
        }
        $client->save();

        if (! $estrena) {
            return $client->fresh();
        }

        if (in_array((string) $client->estado, PruebaCicloService::ESTADOS_EN_PRUEBA, true) && ! $this->esta_pausada($client)) {
            $this->pausar_prueba($client);
        }

        $this->iniciar_la_implementacion($client);
        $this->mover_el_lead_a_implementacion($client);
        $this->crear_la_tarea_del_cobro($client, $paquetes, $autor);

        return $client->fresh();
    }

    /**
     * Pausa la prueba: marca `prueba_pausada_at`, lleva el vencimiento al horizonte (un año) y le avisa al ERP (C3).
     *
     * @param Client $client
     *
     * @return void
     */
    public function pausar_prueba(Client $client)
    {
        $client->prueba_pausada_at   = AppTime::now();
        $client->prueba_reanudada_at = null;
        $client->save();

        $this->ciclo->fijar_vencimiento($client, AppTime::now()->addDays(self::HORIZONTE_DE_PAUSA_DIAS));
    }

    /**
     * Reanuda la prueba pausada: siete días desde la entrega, el lead vuelve a `prueba_activa` y su tarjeta a «Para cerrar» con la
     * llamada de cierre a los 5 días.
     *
     * @param Client      $client
     * @param Carbon|null $desde   Desde cuándo corren los días (por defecto, ahora).
     *
     * @return Client
     *
     * @throws PipelineRuleException 409 si la prueba no está pausada.
     */
    public function reanudar_prueba(Client $client, $desde = null): Client
    {
        if (! $this->esta_pausada($client)) {
            throw new PipelineRuleException('La prueba no está pausada.', [], 409);
        }

        $desde = $desde !== null ? $desde->copy() : AppTime::now();

        $this->ciclo->fijar_vencimiento($client, $desde->copy()->addDays(self::DIAS_DESDE_LA_ENTREGA), self::DIAS_DESDE_LA_ENTREGA);

        $client->prueba_reanudada_at = AppTime::now();
        $client->save();

        $lead = app(PruebaDelClienteService::class)->lead_del_cliente($client);
        if ($lead !== null) {
            if ((string) $lead->status === 'en_implementacion') {
                $lead->status = 'prueba_activa';
                $lead->save();
            }

            $this->tablero->mover_a($lead, TableroDeJuanService::ETAPA_PARA_CERRAR, [
                'next_action_at'   => $desde->copy()->addDays(self::DIAS_HASTA_LA_LLAMADA_DE_CIERRE)->setTime(10, 0, 0)->format('Y-m-d H:i'),
                'next_action_note' => 'Entregada: corre la semana con sus datos',
            ]);
        }

        return $client->fresh();
    }

    /**
     * La tarea para Lucas «Dar de alta e implementar a <empresa> (pagó la mitad, sin prueba)»: Juan registró el pago de un lead que
     * todavía no creó su prueba, así que no hay cliente al que marcarle el dato. Una sola por lead.
     *
     * @param Lead $lead
     *
     * @return AdminTask
     */
    public function crear_la_tarea_de_alta_sin_prueba(Lead $lead): AdminTask
    {
        $existente = AdminTask::query()->where('created_via', 'impl_alta')->where('lead_id', (int) $lead->id)->where('is_done', false)->first();
        if ($existente !== null) {
            return $existente;
        }

        $empresa = trim((string) $lead->company_name) !== '' ? trim((string) $lead->company_name) : trim((string) $lead->contact_name);

        return $this->crear_tarea(
            'impl_alta',
            'Dar de alta e implementar a ' . $empresa . ' (pagó la mitad, sin prueba)',
            'Juan registró que ' . $empresa . ' pagó la mitad de la implementación, pero todavía no creó su prueba. Verificá el cobro, '
                . 'dalo de alta e implementalo; el dato de la implementación se carga en la ficha del cliente cuando exista.',
            (int) $lead->id
        );
    }

    // ------------------------------------------------------------------------------------------
    // Artículos
    // ------------------------------------------------------------------------------------------

    /**
     * Soporte recibió el Excel de artículos de un negocio con implementación: no se importa solo, queda una tarea para Lucas.
     * Idempotente por cliente y adjunto.
     *
     * @param Client        $client
     * @param SupportTicket $ticket
     * @param string        $adjunto Descripción del archivo (`#<id> nombre`, o el PDF o la URL de origen).
     *
     * @return AdminTask
     */
    public function marcar_articulos_recibidos(Client $client, SupportTicket $ticket, string $adjunto): AdminTask
    {
        $marca = '[cliente:' . (int) $client->id . '] [adjunto:' . $adjunto . ']';

        $existente = AdminTask::query()->where('created_via', 'impl_articulos')->where('content', 'like', '%' . $this->escapar_like($marca) . '%')->first();
        if ($existente !== null) {
            return $existente;
        }

        if ($client->implementacion_articulos_recibidos_at === null) {
            $client->implementacion_articulos_recibidos_at = AppTime::now();
            $client->save();
        }

        $nombre = $this->nombre_del_cliente($client);
        $tarea  = $this->crear_tarea(
            'impl_articulos',
            'Importar artículos de ' . $nombre,
            "Llegó el Excel de artículos de {$nombre} por Soporte (ticket #{$ticket->id}, adjunto {$adjunto}). "
                . "Importalo vos desde el admin y, cuando esté, apretá «Artículos importados OK» en la ficha del cliente.\n" . $marca,
            null
        );

        $this->agregar_nota($client, 'Soporte recibió el Excel de artículos (ticket #' . $ticket->id . ', adjunto ' . $adjunto . '): lo importa Lucas.');

        return $tarea;
    }

    /**
     * «Artículos importados OK»: cierra las tareas de importar artículos, crea la de seguir la implementación (una sola vez) y deja
     * una nota en la etapa actual. Una segunda llamada no repite nada.
     *
     * @param Client     $client
     * @param Admin|null $admin
     *
     * @return Client
     */
    public function articulos_importados_ok(Client $client, $admin = null): Client
    {
        $marca = '[cliente:' . (int) $client->id . ']';

        if ($client->implementacion_articulos_ok_at === null) {
            $client->implementacion_articulos_ok_at = AppTime::now();
            $client->save();
        }

        AdminTask::query()
            ->where('created_via', 'impl_articulos')
            ->where('is_done', false)
            ->where('content', 'like', '%' . $this->escapar_like($marca) . '%')
            ->update(['is_done' => true, 'done_at' => AppTime::now(), 'done_by_admin_id' => $admin !== null ? (int) $admin->id : null]);

        $ya_hay = AdminTask::query()->where('created_via', 'impl_seguir')->where('content', 'like', '%' . $this->escapar_like($marca) . '%')->exists();
        if (! $ya_hay) {
            $nombre = $this->nombre_del_cliente($client);
            $this->crear_tarea(
                'impl_seguir',
                'Seguir la implementación de ' . $nombre . ' con /implementar',
                'Los artículos de ' . $nombre . ' ya están importados. Seguí la implementación con /implementar: imágenes desde los PDF y webs por código, '
                    . 'Serper para las que falten, descripciones y categorías, capacitación y entrega.' . "\n" . $marca,
                null
            );
            $this->agregar_nota($client, 'Artículos importados OK: sigue la implementación.');
        }

        return $client->fresh();
    }

    // ------------------------------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------------------------------

    /**
     * Valida el estado y los paquetes.
     *
     * @param string       $estado
     * @param array<mixed> $paquetes
     *
     * @return array<int, string> Los paquetes limpios, sin repetidos y en el orden de {@see self::PAQUETES}.
     *
     * @throws PipelineRuleException
     */
    public function validar(string $estado, array $paquetes): array
    {
        $errores = [];

        if (! in_array($estado, self::ESTADOS, true)) {
            $errores['estado'][] = 'El estado tiene que ser no, mitad, completa o incluida.';
        }

        $limpios = [];
        foreach ($paquetes as $paquete) {
            if (! is_string($paquete) || ! in_array($paquete, self::PAQUETES, true)) {
                $errores['paquetes'][] = 'Los paquetes posibles son gestion, tienda y agentes.';
                break;
            }
            $limpios[$paquete] = true;
        }

        if (in_array($estado, ['mitad', 'completa'], true) && $limpios === [] && ! isset($errores['paquetes'])) {
            $errores['paquetes'][] = 'Elegí al menos un paquete implementado.';
        }

        if ($errores !== []) {
            throw PipelineRuleException::validacion($errores);
        }

        return array_values(array_intersect(self::PAQUETES, array_keys($limpios)));
    }

    /**
     * Inicia la implementación manual del cliente si no tiene una, con las etapas 1 y 2 salteadas (la prueba ya está instalada) y la
     * 3 en curso.
     *
     * @param Client $client
     *
     * @return void
     */
    private function iniciar_la_implementacion(Client $client)
    {
        if (Implementation::query()->where('client_id', $client->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($client) {
            $implementacion = app(ImplementationStartService::class)->start($client, true);

            ImplementationStage::query()->where('implementation_id', $implementacion->id)->whereIn('stage_number', [1, 2])
                ->update(['status' => 'skipped', 'completed_at' => AppTime::now()]);
            ImplementationStage::query()->where('implementation_id', $implementacion->id)->where('stage_number', 3)
                ->update(['status' => 'in_progress', 'started_at' => AppTime::now()]);

            $implementacion->current_stage = 3;
            $implementacion->save();

            $this->agregar_nota($client, 'La prueba ya está instalada: se saltean las etapas 1 y 2.');
        });
    }

    /**
     * El lead del cliente pasa a `en_implementacion` y su tarjeta a «En implementación».
     *
     * @param Client $client
     *
     * @return void
     */
    private function mover_el_lead_a_implementacion(Client $client)
    {
        $lead = app(PruebaDelClienteService::class)->lead_del_cliente($client);
        if ($lead === null) {
            return;
        }

        if ((string) $lead->status !== 'en_implementacion') {
            \App\Models\LeadPipelineStatus::ensure_exists('en_implementacion');
            $lead->status = 'en_implementacion';
            $lead->save();
        }

        if ($this->tablero->tarjeta_abierta($lead) === null) {
            $this->tablero->entrar_en_interesados($lead, 'pagó la mitad de la implementación');
        }

        $this->tablero->mover_a($lead, TableroDeJuanService::ETAPA_EN_IMPLEMENTACION, ['next_action_at' => null]);
    }

    /**
     * La tarea para Lucas «Verificar el cobro de la mitad…», con los datos para cargar la venta en el módulo de siempre (la venta
     * no se crea sola: hacen falta cuotas, moneda y el cobro real).
     *
     * @param Client        $client
     * @param array<string> $paquetes
     * @param Admin|null    $autor
     *
     * @return AdminTask
     */
    private function crear_la_tarea_del_cobro(Client $client, array $paquetes, $autor): AdminTask
    {
        $nombre = $this->nombre_del_cliente($client);
        $lineas = [];
        $total  = 0.0;
        foreach ($paquetes as $clave) {
            $item = PreciosVigentesSettings::implementacion($clave);
            if ($item !== null) {
                $lineas[] = $item['nombre'] . ' ' . PreciosVigentesSettings::formato_pesos((float) $item['precio']);
                $total   += (float) $item['precio'];
            }
        }

        $config = PreciosVigentesSettings::to_array()['implementaciones'];
        if (count($paquetes) === 3 && isset($config['tres_juntos']['precio'])) {
            $total = (float) $config['tres_juntos']['precio'];
        }
        $porcentaje = (float) $config['anticipo_porcentaje'];

        $contenido = 'Juan registró que ' . $nombre . ' pagó la mitad de la implementación. Verificá el cobro y registrá la venta.' . "\n"
            . 'Para cargar la venta (módulo Ventas del cliente): producto «Implementación».' . "\n"
            . 'Paquetes: ' . implode(' · ', $lineas) . '.' . "\n"
            . 'Precio total: ' . PreciosVigentesSettings::formato_pesos($total) . '. Anticipo del '
            . PreciosVigentesSettings::formato_porcentaje($porcentaje) . ' %: ' . PreciosVigentesSettings::formato_pesos(PreciosVigentesSettings::anticipo_de($total)) . '.' . "\n"
            . 'Vendedor sugerido: ' . ($autor !== null ? $autor->name : 'quien registró la llamada') . '. Si es Juan, la comisión del 15 % se liquida sola.' . "\n"
            . '[cliente:' . (int) $client->id . ']';

        return $this->crear_tarea('impl_mitad', 'Verificar el cobro de la mitad de la implementación de ' . $nombre . ' y registrar la venta', $contenido, null);
    }

    /**
     * Crea una tarea para quien resuelve `AdminTaskAssignmentResolver::for_implementacion_task()`, con su aviso.
     *
     * @param string   $created_via
     * @param string   $titulo
     * @param string   $contenido
     * @param int|null $lead_id
     *
     * @return AdminTask
     */
    private function crear_tarea(string $created_via, string $titulo, string $contenido, $lead_id): AdminTask
    {
        $tarea = AdminTask::create([
            'created_by_admin_id' => 0,
            'assigned_admin_id'   => null,
            'lead_id'             => $lead_id,
            'title'               => $titulo,
            'content'             => $contenido,
            'todos'               => null,
            'is_done'             => false,
            'sort_order'          => 0,
            'created_via'         => $created_via,
        ]);

        /* La tarea ya está creada: un error de la asignación o del aviso no la tira. */
        try {
            $ids = AdminTaskAssignmentResolver::for_implementacion_task();
            if (! empty($ids)) {
                $tarea->assigned_admins()->sync($ids);
                $tarea->assigned_admin_id = $ids[0];
                $tarea->save();
            }

            AdminTaskNotificationService::create_for_task($tarea);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('ImplementacionContratadaService: la tarea quedó creada pero la asignación o el aviso fallaron.', ['task_id' => $tarea->id, 'error' => $e->getMessage()]);
        }

        return $tarea->fresh();
    }

    /**
     * Agrega una nota en `data.notas` de la etapa actual de la implementación del cliente (si tiene una).
     *
     * @param Client $client
     * @param string $texto
     *
     * @return void
     */
    private function agregar_nota(Client $client, string $texto)
    {
        $implementacion = Implementation::query()->where('client_id', $client->id)->orderByDesc('id')->first();
        if ($implementacion === null) {
            return;
        }

        $etapa = ImplementationStage::query()->where('implementation_id', $implementacion->id)->where('stage_number', (int) $implementacion->current_stage)->first();
        if ($etapa === null) {
            return;
        }

        $datos            = is_array($etapa->data) ? $etapa->data : [];
        $datos['notas']   = isset($datos['notas']) && is_array($datos['notas']) ? $datos['notas'] : [];
        $datos['notas'][] = ['texto' => $texto, 'at' => AppTime::now()->toISOString(), 'origen' => 'admin'];
        $etapa->data      = $datos;
        $etapa->save();
    }

    /**
     * El nombre para mostrar del cliente (empresa o nombre).
     *
     * @param Client $client
     *
     * @return string
     */
    private function nombre_del_cliente(Client $client): string
    {
        $nombre = trim((string) $client->company_name) !== '' ? trim((string) $client->company_name) : trim((string) $client->name);

        return $nombre !== '' ? $nombre : 'el cliente #' . $client->id;
    }

    /**
     * Una fecha para el contexto (`Y-m-d H:i:s`, hora de Argentina) o null.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    private function fecha($valor)
    {
        return $valor === null ? null : Carbon::parse($valor)->setTimezone(AppTime::now()->getTimezone())->format('Y-m-d H:i:s');
    }

    /**
     * Escapa los comodines de un LIKE.
     *
     * @param string $texto
     *
     * @return string
     */
    private function escapar_like(string $texto): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
    }
}
