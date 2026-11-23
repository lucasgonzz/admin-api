<?php

namespace App\Services;

use App\Models\AdminTask;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * La tarea del embudo (lo que antes se llamaba «tarea para Juan»; misión embudo-whatsapp-precios, E2).
 *
 * Cuando un lead crea su prueba gratis o pide que se la carguen nosotros (`implementacion_pedida`), alguien del
 * equipo lo tiene que llamar. Se crea UNA `AdminTask` por lead y motivo, asignada según
 * {@see AdminTaskAssignmentResolver::for_embudo_task()} y con su aviso (push y pila de avisos) creado por
 * {@see AdminTaskNotificationService}.
 *
 * Es un aviso interno: no cambia nada de lo que ve el lead, así que corre siempre, con o sin el interruptor del
 * flujo de entrada.
 */
class LeadTareaVendedorService
{
    /** Motivo: el lead creó su prueba gratis. */
    public const MOTIVO_PRUEBA_CREADA = 'prueba_creada';

    /** Motivo: el lead pidió que se lo carguemos nosotros (implementación). */
    public const MOTIVO_IMPLEMENTACION_PEDIDA = 'implementacion_pedida';

    /**
     * Motivo => [valor de `created_via`, cola del título].
     *
     * 🔴 `admin_tasks.created_via` es varchar(20): `embudo_implementacion_pedida` (28) no entra, por eso el
     * valor de la implementación es `embudo_impl_pedida` (18). No se agrega una migración por esto.
     */
    private const MOTIVOS = [
        self::MOTIVO_PRUEBA_CREADA          => ['created_via' => 'embudo_prueba_creada', 'titulo' => 'creó su prueba'],
        self::MOTIVO_IMPLEMENTACION_PEDIDA => ['created_via' => 'embudo_impl_pedida', 'titulo' => 'pidió la implementación'],
    ];

    /**
     * Crea la tarea del embudo del lead para el motivo dado, una sola vez.
     *
     * Idempotente: si ya hay una tarea del lead con el mismo `created_via` (abierta o cerrada) no crea otra y
     * devuelve la existente. Un motivo distinto es otra tarea. Si falla la asignación o el aviso, la tarea queda
     * creada igual (se loguea): un aviso caído no puede tirar el alta de la prueba ni la respuesta del agente.
     *
     * @param Lead   $lead
     * @param string $motivo `prueba_creada` o `implementacion_pedida`.
     * @param bool   $avisar false = no crea el aviso (push y pila) de la tarea: la tarea nace para quedar cerrada en el mismo pedido.
     *
     * @return AdminTask|null La tarea (nueva o existente); null si el motivo no existe o no se pudo crear.
     */
    public static function crear(Lead $lead, string $motivo, bool $avisar = true): ?AdminTask
    {
        if (! isset(self::MOTIVOS[$motivo])) {
            Log::channel('daily')->warning('LeadTareaVendedor: motivo desconocido.', ['lead_id' => $lead->id, 'motivo' => $motivo]);

            return null;
        }

        $created_via = self::MOTIVOS[$motivo]['created_via'];

        $existente = AdminTask::query()->where('lead_id', (int) $lead->id)->where('created_via', $created_via)->first();
        if ($existente !== null) {
            return $existente;
        }

        try {
            $task = AdminTask::create([
                /* Creador cero: la tarea la crea el sistema, no una persona. AdminTaskNotificationService nunca le
                   avisa al creador: poner acá a un admin real dejaría sin aviso justo a quien tiene que enterarse
                   (mismo criterio que support:check-clients-without-phone). */
                'created_by_admin_id' => 0,
                'assigned_admin_id'   => null,
                'lead_id'             => (int) $lead->id,
                'title'               => self::titulo($lead, self::MOTIVOS[$motivo]['titulo']),
                'content'             => self::contenido($lead),
                'todos'               => null,
                'is_done'             => false,
                'sort_order'          => 0,
                'created_via'         => $created_via,
            ]);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('LeadTareaVendedor: no se pudo crear la tarea del embudo.', [
                'lead_id' => $lead->id,
                'motivo'  => $motivo,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }

        /* La asignación y el aviso van en su propio try: la tarea YA está creada, reportar «no se pudo crear» sería
           mentira y el aviso no se reintentaría nunca. */
        try {
            $ids = AdminTaskAssignmentResolver::for_embudo_task();
            if (! empty($ids)) {
                $task->assigned_admins()->sync($ids);
                /* Columna legacy sincronizada con el primer asignado, como el resto de los orígenes de tareas. */
                $task->assigned_admin_id = $ids[0];
                $task->save();
            }

            if ($avisar) {
                AdminTaskNotificationService::create_for_task($task);
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->error('LeadTareaVendedor: la tarea quedó creada pero la asignación o el aviso fallaron.', [
                'task_id' => $task->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $task;
    }

    /**
     * Título: «Llamar a <nombre> (<empresa>): <motivo>». Sin la empresa, sin los paréntesis.
     *
     * @param Lead   $lead
     * @param string $cola Cola del título («creó su prueba», «pidió la implementación»).
     *
     * @return string
     */
    private static function titulo(Lead $lead, string $cola): string
    {
        $nombre  = trim((string) $lead->contact_name);
        $empresa = trim((string) $lead->company_name);

        if ($nombre === '') {
            $nombre = $empresa !== '' ? $empresa : (trim((string) $lead->phone) !== '' ? trim((string) $lead->phone) : 'Lead #' . $lead->id);
            $empresa = '';
        }

        return 'Llamar a ' . $nombre . ($empresa !== '' ? ' (' . $empresa . ')' : '') . ': ' . $cola;
    }

    /**
     * Contenido: teléfono, rubro y link a la conversación del lead en el admin.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private static function contenido(Lead $lead): string
    {
        $base = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'https://admin.comerciocity.com')), '/');

        $lineas   = [];
        $lineas[] = 'Teléfono: ' . (trim((string) $lead->phone) !== '' ? trim((string) $lead->phone) : 'sin cargar');
        $rubro    = trim((string) $lead->business_type);
        if ($rubro !== '') {
            $lineas[] = 'Rubro: ' . $rubro;
        }
        $lineas[] = 'Conversación: ' . $base . '/leads/' . (int) $lead->id . '/conversacion';

        return implode("\n", $lineas);
    }
}
