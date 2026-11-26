<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadCompromiso;
use App\Models\LeadLlamada;
use App\Models\LeadMessage;
use App\Services\Pipelines\PipelineFieldsService;
use App\Services\Pipelines\PipelineOpportunityService;
use App\Services\Pipelines\PipelineRuleException;
use App\Services\Pipelines\TableroDeJuanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El motor de compromisos de contacto con los leads (misión leads-referidos-compromisos, etapa E2).
 *
 * Un compromiso es «el próximo contacto acordado con este lead»: Juan lo llama el viernes, Martín le escribe el martes o
 * se espera que el lead avise. Este servicio reúne toda la lógica: crearlo, reemplazarlo, cancelarlo, decidir si algo
 * cambió desde que se creó y ejecutarlo a su hora.
 *
 * 🔴 TODO EL MOTOR NACE APAGADO (`lead_compromisos_activo` = '0'). Con el interruptor apagado ninguna de las puertas hace
 * nada y el sistema se comporta exactamente como antes de esta misión: no se crean compromisos, no se suspende ningún
 * seguimiento, el comando no ejecuta nada y Martín no ve ningún bloque. Los otros dos interruptores (detección con IA y
 * envío automático) sólo tienen efecto con el motor prendido.
 */
class LeadCompromisoService
{
    /** Clave de `admin_settings`: el motor entero. Default apagado. */
    const SETTING_ACTIVO = 'lead_compromisos_activo';

    /** Clave de `admin_settings`: detectar con IA el compromiso que el lead dice por WhatsApp. Default apagado. */
    const SETTING_DETECCION_IA = 'lead_compromisos_deteccion_ia';

    /** Clave de `admin_settings`: mandar solos los mensajes del motor (apagado = quedan como sugerencia). */
    const SETTING_ENVIO_AUTOMATICO = 'lead_compromisos_envio_automatico';

    /** Clave de `admin_settings`: plantilla de Meta con la que se retoma a un lead con la ventana de 24 h cerrada. */
    const SETTING_PLANTILLA = 'lead_compromiso_plantilla';

    /** Plantilla por defecto del compromiso («Te escribo como quedamos…»). */
    const PLANTILLA_POR_DEFECTO = 'cc_coord_dia_acordado';

    /**
     * ¿Está prendido el motor de compromisos?
     *
     * @return bool
     */
    public static function activo(): bool
    {
        return (string) AdminSetting::get(self::SETTING_ACTIVO, '0') === '1';
    }

    /**
     * ¿Está prendida la detección con IA de lo que el lead escribe? Sólo cuenta con el motor prendido.
     *
     * @return bool
     */
    public static function deteccion_ia_activa(): bool
    {
        return self::activo() && (string) AdminSetting::get(self::SETTING_DETECCION_IA, '0') === '1';
    }

    /**
     * ¿Los mensajes del motor salen solos? Apagado (default) quedan como sugerencia para aprobar.
     *
     * @return bool
     */
    public static function envio_automatico_activo(): bool
    {
        return (string) AdminSetting::get(self::SETTING_ENVIO_AUTOMATICO, '0') === '1';
    }

    /**
     * Nombre de la plantilla de Meta con la que se retoma a un lead que tiene la ventana de 24 h cerrada.
     *
     * @return string El configurado o, si está vacío, {@see self::PLANTILLA_POR_DEFECTO}.
     */
    public static function nombre_de_la_plantilla(): string
    {
        $nombre = trim((string) AdminSetting::get(self::SETTING_PLANTILLA, self::PLANTILLA_POR_DEFECTO));

        return $nombre !== '' ? $nombre : self::PLANTILLA_POR_DEFECTO;
    }

    /** Hasta cuántos días en el futuro puede estar un compromiso (más allá no es un compromiso, es una intención). */
    const MAX_DIAS = 90;

    /** Hora (de Argentina) a la que sale un compromiso `llamar` o `escribir` que sólo trae el día. */
    const HORA_POR_DEFECTO = 10;

    /** Hora (de Argentina) a la que vence un compromiso `esperar` que sólo trae el día: se le da el día entero al lead. */
    const HORA_DE_ESPERAR = 18;

    /** Tope de caracteres del resumen. */
    const MAX_RESUMEN = 2000;

    /** Tope de caracteres de lo que dijo el lead (la columna es string(500)). */
    const MAX_CITA = 500;

    /** Cuántos compromisos trae el listado de un lead. */
    const LIMITE_DEL_LISTADO = 50;

    /** Prefijo de la próxima acción de la tarjeta cuando la fija un compromiso. */
    const PREFIJO_DE_LA_TARJETA = 'Compromiso:';

    /** Nombres de los días, indexados como `Carbon::dayOfWeek` (0 = domingo). */
    const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    // ------------------------------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------------------------------

    /**
     * El compromiso abierto (vigente o procesando) del lead, o null. Como mucho hay uno vigente por lead.
     *
     * @param Lead $lead
     *
     * @return LeadCompromiso|null
     */
    public function abierto_de(Lead $lead)
    {
        return LeadCompromiso::query()
            ->where('lead_id', (int) $lead->id)
            ->whereIn('status', LeadCompromiso::STATUSES_ABIERTOS)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * ¿Hay que suspender los seguimientos genéricos de este lead? Sólo con el motor PRENDIDO y un compromiso abierto.
     *
     * Con el motor apagado devuelve false sin consultar la base: es lo que garantiza que el comportamiento de los
     * seguimientos sea idéntico al de antes de la misión.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function suspende_los_seguimientos(Lead $lead): bool
    {
        if (! self::activo()) {
            return false;
        }

        return LeadCompromiso::query()
            ->where('lead_id', (int) $lead->id)
            ->whereIn('status', LeadCompromiso::STATUSES_ABIERTOS)
            ->exists();
    }

    /**
     * Los compromisos del lead para el panel: el abierto primero y después el historial, del más nuevo al más viejo.
     *
     * @param Lead $lead
     *
     * @return array<int, LeadCompromiso>
     */
    public function listar(Lead $lead): array
    {
        $todos = LeadCompromiso::query()
            ->where('lead_id', (int) $lead->id)
            ->orderByDesc('id')
            ->limit(self::LIMITE_DEL_LISTADO)
            ->get();

        $abiertos = [];
        $cerrados = [];
        foreach ($todos as $compromiso) {
            if (in_array((string) $compromiso->status, LeadCompromiso::STATUSES_ABIERTOS, true)) {
                $abiertos[] = $compromiso;
            } else {
                $cerrados[] = $compromiso;
            }
        }

        return array_merge($abiertos, $cerrados);
    }

    /**
     * El compromiso en el formato que devuelven el panel y los endpoints de Claude.
     *
     * @param LeadCompromiso $compromiso
     *
     * @return array<string, mixed>
     */
    public function a_json(LeadCompromiso $compromiso): array
    {
        return [
            'id'                   => (int) $compromiso->id,
            'lead_id'              => (int) $compromiso->lead_id,
            'vence_at'             => $compromiso->vence_at->format('Y-m-d H:i:s'),
            'accion'               => (string) $compromiso->accion,
            'origen'               => (string) $compromiso->origen,
            'origen_id'            => $compromiso->origen_id,
            'resumen'              => $compromiso->resumen,
            'cita_del_lead'        => $compromiso->cita_del_lead,
            'status'               => (string) $compromiso->status,
            'resultado'            => $compromiso->resultado,
            'admin_task_id'        => $compromiso->admin_task_id,
            'lead_message_id'      => $compromiso->lead_message_id,
            'reemplazado_por_id'   => $compromiso->reemplazado_por_id,
            'created_by_admin_id'  => $compromiso->created_by_admin_id,
            'cerrado_por_admin_id' => $compromiso->cerrado_por_admin_id,
            'ejecutado_at'         => $compromiso->ejecutado_at !== null ? $compromiso->ejecutado_at->format('Y-m-d H:i:s') : null,
            'created_at'           => $compromiso->created_at !== null ? $compromiso->created_at->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * El bloque que lee Martín en cada turno cuando el lead tiene un contacto acordado, para que no proponga otro día.
     *
     * @param Lead $lead
     *
     * @return string Vacío si el motor está apagado o el lead no tiene un compromiso abierto.
     */
    public function bloque_para_el_agente(Lead $lead): string
    {
        if (! self::activo()) {
            return '';
        }

        $compromiso = $this->abierto_de($lead);
        if ($compromiso === null) {
            return '';
        }

        $quien = 'Juan lo llama.';
        if ((string) $compromiso->accion === LeadCompromiso::ACCION_ESCRIBIR) {
            $quien = 'Le escribís vos (Martín) ese día.';
        } elseif ((string) $compromiso->accion === LeadCompromiso::ACCION_ESPERAR) {
            $quien = 'El lead quedó en avisar; si no avisa, lo retomás vos (Martín).';
        }

        $lineas   = [];
        $lineas[] = 'PRÓXIMO CONTACTO ACORDADO:';
        $lineas[] = '- Cuándo: ' . $this->dia_en_palabras($compromiso->vence_at) . ' (hora de Argentina).';
        $lineas[] = '- Quién: ' . $quien;
        if (trim((string) $compromiso->resumen) !== '') {
            $lineas[] = '- De qué: ' . trim((string) $compromiso->resumen);
        }
        if (trim((string) $compromiso->cita_del_lead) !== '') {
            $lineas[] = '- Lo que dijo el lead: «' . trim((string) $compromiso->cita_del_lead) . '»';
        }
        $lineas[] = 'No propongas otro día salvo que el lead lo pida.';

        return implode("\n", $lineas);
    }

    /**
     * El día y la hora de un momento en palabras: «el viernes 27/11 a las 10:00».
     *
     * @param \DateTimeInterface $momento
     *
     * @return string
     */
    public function dia_en_palabras(\DateTimeInterface $momento): string
    {
        $fecha = Carbon::instance($momento)->setTimezone(FranjaHorariaDeSeguimientos::ZONA_HORARIA);

        return 'el ' . self::DIAS[$fecha->dayOfWeek] . ' ' . $fecha->format('d/m') . ' a las ' . $fecha->format('H:i');
    }

    // ------------------------------------------------------------------------------------------
    // Crear, cambiar, cancelar
    // ------------------------------------------------------------------------------------------

    /**
     * Valida y normaliza los datos de un compromiso. Es la única definición de «qué es un compromiso válido»: la usan el
     * panel, los endpoints de Claude, «Registrar llamada», la detección con IA y la re-evaluación.
     *
     * @param array<string, mixed> $datos           `vence_at` (texto `AAAA-MM-DD HH:MM`, `AAAA-MM-DD` o un Carbon), `accion`,
     *                                              `resumen`, `cita_del_lead`.
     * @param string|null          $accion_vigente  La acción que ya tiene el compromiso que se está editando (sirve para la
     *                                              hora por defecto cuando el pedido no trae `accion`).
     *
     * @return array{vence_at: Carbon, accion: string, resumen: string, cita_del_lead: string|null}
     *
     * @throws PipelineRuleException 422 con `errors.vence_at` y/o `errors.accion`.
     */
    public function limpiar_datos(array $datos, ?string $accion_vigente = null): array
    {
        $errores = [];

        $accion = array_key_exists('accion', $datos) && $datos['accion'] !== null ? trim((string) $datos['accion']) : (string) $accion_vigente;
        if (! in_array($accion, LeadCompromiso::ACCIONES, true)) {
            $errores['accion'][] = 'La acción tiene que ser llamar, escribir o esperar.';
        }

        $vence_at = $this->resolver_la_fecha(isset($datos['vence_at']) ? $datos['vence_at'] : null, $accion, $errores);

        if ($errores !== []) {
            throw PipelineRuleException::validacion($errores);
        }

        $resumen = isset($datos['resumen']) ? trim((string) $datos['resumen']) : '';
        $cita    = isset($datos['cita_del_lead']) ? trim((string) $datos['cita_del_lead']) : '';

        return [
            'vence_at'      => $vence_at,
            'accion'        => $accion,
            'resumen'       => mb_substr($resumen, 0, self::MAX_RESUMEN),
            'cita_del_lead' => $cita !== '' ? mb_substr($cita, 0, self::MAX_CITA) : null,
        ];
    }

    /**
     * Convierte lo que llegó como fecha en un momento de Argentina válido para un compromiso (futuro y a menos de 90 días).
     * Con sólo el día, pone la hora por defecto de la acción: 10:00 para llamar y escribir, 18:00 para esperar.
     *
     * @param mixed                                $valor   Texto, Carbon o null.
     * @param string                               $accion  Acción ya normalizada (puede ser inválida: se usa la hora de siempre).
     * @param array<string, array<int, string>>    $errores Salida: se suma `vence_at` si no sirve.
     *
     * @return Carbon|null
     */
    private function resolver_la_fecha($valor, string $accion, array &$errores)
    {
        $fecha = null;

        if ($valor instanceof \DateTimeInterface) {
            $fecha = Carbon::instance($valor);
        } elseif (is_string($valor) && trim($valor) !== '') {
            $texto = trim($valor);
            $fecha = PipelineFieldsService::parsear_fecha_de_la_spa($texto);
            if ($fecha !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1) {
                $hora  = $accion === LeadCompromiso::ACCION_ESPERAR ? self::HORA_DE_ESPERAR : self::HORA_POR_DEFECTO;
                $fecha = $fecha->setTime($hora, 0, 0);
            }
        }

        if ($fecha === null) {
            $errores['vence_at'][] = 'Indicá cuándo es el contacto, con formato AAAA-MM-DD HH:MM.';

            return null;
        }

        $ahora = AppTime::now();
        if ($fecha->lte($ahora)) {
            $errores['vence_at'][] = 'La fecha del compromiso tiene que ser futura.';

            return null;
        }
        if ($fecha->gt($ahora->copy()->addDays(self::MAX_DIAS))) {
            $errores['vence_at'][] = 'El compromiso no puede ser a más de ' . self::MAX_DIAS . ' días.';

            return null;
        }

        return $fecha;
    }

    /**
     * Crea un compromiso para el lead. Siempre hay como mucho UNO vigente por lead: el anterior queda `reemplazado` (gana el
     * último, que es el caso del lunes de Lucas).
     *
     * Con el motor apagado no hace nada y devuelve null: así ningún compromiso queda guardado sin que nadie lo ejecute.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $datos      Los de {@see self::limpiar_datos()} más `origen` (default `manual`) y `origen_id`.
     * @param int|null             $admin_id   Quién lo crea (null = un agente o el sistema).
     * @param array<string, mixed> $opciones   `tarjeta` (default true): si es false no toca la próxima acción de la tarjeta
     *                                         («Registrar llamada» ya la dejó fijada con su propia nota).
     *
     * @return LeadCompromiso|null Null con el motor apagado.
     *
     * @throws PipelineRuleException 422 si los datos no sirven.
     */
    public function crear(Lead $lead, array $datos, $admin_id = null, array $opciones = [])
    {
        if (! self::activo()) {
            return null;
        }

        $limpios = $this->limpiar_datos($datos);
        $origen  = isset($datos['origen']) && in_array((string) $datos['origen'], LeadCompromiso::ORIGENES, true)
            ? (string) $datos['origen']
            : LeadCompromiso::ORIGEN_MANUAL;
        $origen_id    = isset($datos['origen_id']) && $datos['origen_id'] !== null ? (int) $datos['origen_id'] : null;
        $con_tarjeta  = ! array_key_exists('tarjeta', $opciones) || $opciones['tarjeta'] !== false;

        $nuevo = DB::transaction(function () use ($lead, $limpios, $origen, $origen_id, $admin_id) {
            /* El candado del lead: dos compromisos que nacen a la vez (la llamada de Juan y el mensaje del lead) se hacen la cola
               y el segundo ve al primero como vigente en lugar de dejar dos. */
            $bloqueado = Lead::query()->whereKey((int) $lead->id)->lockForUpdate()->first();
            if ($bloqueado === null) {
                throw PipelineRuleException::no_existe('El lead', false);
            }

            $huella = $this->huella($bloqueado);

            $nuevo = LeadCompromiso::create(array_merge($huella, [
                'lead_id'             => (int) $bloqueado->id,
                'vence_at'            => $limpios['vence_at'],
                'accion'              => $limpios['accion'],
                'origen'              => $origen,
                'origen_id'           => $origen_id,
                'resumen'             => $limpios['resumen'],
                'cita_del_lead'       => $limpios['cita_del_lead'],
                'status'              => LeadCompromiso::STATUS_VIGENTE,
                'created_by_admin_id' => $admin_id !== null ? (int) $admin_id : null,
            ]));

            /* El vigente anterior queda reemplazado. Sólo el vigente: uno que está `procesando` lo está ejecutando una corrida
               del comando y lo termina ella. */
            LeadCompromiso::query()
                ->where('lead_id', (int) $bloqueado->id)
                ->where('status', LeadCompromiso::STATUS_VIGENTE)
                ->where('id', '!=', (int) $nuevo->id)
                ->update(['status' => LeadCompromiso::STATUS_REEMPLAZADO, 'reemplazado_por_id' => (int) $nuevo->id, 'updated_at' => AppTime::now()]);

            return $nuevo;
        });

        if ($con_tarjeta) {
            $this->fijar_la_tarjeta($lead, $nuevo, $admin_id);
        }

        return $nuevo->fresh();
    }

    /**
     * Cambia la fecha, la acción o el resumen de un compromiso vigente (el panel). Lo que no viene no se toca.
     *
     * Si cambia la fecha, la huella se renueva: quien lo movió miró el contexto de hoy, así que lo que pasó hasta ahora no
     * tiene por qué disparar una re-evaluación.
     *
     * @param LeadCompromiso       $compromiso
     * @param array<string, mixed> $datos      `vence_at`, `accion`, `resumen`, `cita_del_lead` (todos opcionales).
     * @param int|null             $admin_id
     *
     * @return LeadCompromiso
     *
     * @throws PipelineRuleException 422 si ya no está vigente o los datos no sirven.
     */
    public function actualizar(LeadCompromiso $compromiso, array $datos, $admin_id = null): LeadCompromiso
    {
        if ((string) $compromiso->status !== LeadCompromiso::STATUS_VIGENTE) {
            throw new PipelineRuleException('Este compromiso ya no está vigente: no se puede cambiar.');
        }

        $trae_fecha  = array_key_exists('vence_at', $datos);
        $trae_accion = array_key_exists('accion', $datos) && $datos['accion'] !== null;

        /* Sólo se validan los campos que vinieron: un vigente que ya pasó de hora (el motor estaba apagado) tiene que poder
           corregirse el resumen sin que le exijan una fecha futura. La fecha y la acción comparten la regla de la hora por
           defecto (10:00, o 18:00 para esperar). */
        $errores = [];
        $accion  = $trae_accion ? trim((string) $datos['accion']) : (string) $compromiso->accion;
        if (! in_array($accion, LeadCompromiso::ACCIONES, true)) {
            $errores['accion'][] = 'La acción tiene que ser llamar, escribir o esperar.';
        }
        $fecha = $trae_fecha ? $this->resolver_la_fecha($datos['vence_at'], $accion, $errores) : null;
        if ($errores !== []) {
            throw PipelineRuleException::validacion($errores);
        }

        if ($trae_fecha) {
            $compromiso->vence_at = $fecha;
        }
        if ($trae_accion) {
            $compromiso->accion = $accion;
        }
        if (array_key_exists('resumen', $datos)) {
            $compromiso->resumen = mb_substr(trim((string) $datos['resumen']), 0, self::MAX_RESUMEN);
        }
        if (array_key_exists('cita_del_lead', $datos)) {
            $cita = trim((string) $datos['cita_del_lead']);
            $compromiso->cita_del_lead = $cita !== '' ? mb_substr($cita, 0, self::MAX_CITA) : null;
        }

        if ($trae_fecha) {
            $lead = Lead::query()->find((int) $compromiso->lead_id);
            if ($lead !== null) {
                foreach ($this->huella($lead) as $columna => $valor) {
                    $compromiso->{$columna} = $valor;
                }
            }
        }
        $compromiso->save();

        $lead = isset($lead) ? $lead : Lead::query()->find((int) $compromiso->lead_id);
        if ($lead !== null && ($trae_fecha || array_key_exists('resumen', $datos))) {
            $this->fijar_la_tarjeta($lead, $compromiso, $admin_id);
        }

        return $compromiso->fresh();
    }

    /**
     * Cancela un compromiso abierto (o uno que quedó para revisar). La próxima acción de la tarjeta, si la había fijado el
     * compromiso, se limpia.
     *
     * @param LeadCompromiso $compromiso
     * @param string|null    $motivo     Por qué (queda en `resultado`).
     * @param int|null       $admin_id   Quién lo cierra (null = la IA o un agente).
     *
     * @return LeadCompromiso
     *
     * @throws PipelineRuleException 422 si ya estaba cerrado.
     */
    public function cancelar(LeadCompromiso $compromiso, ?string $motivo = null, $admin_id = null): LeadCompromiso
    {
        if (! in_array((string) $compromiso->status, [LeadCompromiso::STATUS_VIGENTE, LeadCompromiso::STATUS_PROCESANDO, LeadCompromiso::STATUS_REVISAR], true)) {
            throw new PipelineRuleException('Este compromiso ya está cerrado.');
        }

        $motivo = $motivo !== null ? trim($motivo) : '';

        $compromiso->status               = LeadCompromiso::STATUS_CANCELADO;
        $compromiso->resultado            = $motivo !== '' ? mb_substr($motivo, 0, 1000) : 'Cancelado.';
        $compromiso->cerrado_por_admin_id = $admin_id !== null ? (int) $admin_id : null;
        $compromiso->procesando_desde     = null;
        $compromiso->save();

        $lead = Lead::query()->find((int) $compromiso->lead_id);
        if ($lead !== null) {
            $this->limpiar_la_tarjeta($lead, $admin_id);
        }

        return $compromiso->fresh();
    }

    /**
     * Da por cumplido el compromiso vigente del lead: hubo contacto (una llamada sin fecha nueva) y ya no hace falta.
     *
     * @param Lead   $lead
     * @param string $resultado Qué pasó (queda en `resultado`).
     * @param int|null $admin_id
     *
     * @return void
     */
    public function cumplir_el_vigente(Lead $lead, string $resultado, $admin_id = null): void
    {
        LeadCompromiso::query()
            ->where('lead_id', (int) $lead->id)
            ->where('status', LeadCompromiso::STATUS_VIGENTE)
            ->update([
                'status'               => LeadCompromiso::STATUS_CUMPLIDO,
                'resultado'            => mb_substr($resultado, 0, 1000),
                'cerrado_por_admin_id' => $admin_id !== null ? (int) $admin_id : null,
                'updated_at'           => AppTime::now(),
            ]);
    }

    // ------------------------------------------------------------------------------------------
    // La huella y los cambios
    // ------------------------------------------------------------------------------------------

    /**
     * La huella del lead en este momento: el último mensaje del hilo, la última llamada y el estado. Es contra lo que se mide
     * si «cambió algo» cuando llega la hora del compromiso.
     *
     * @param Lead $lead
     *
     * @return array{baseline_lead_message_id: int|null, baseline_llamada_id: int|null, estado_lead_al_crear: string}
     */
    public function huella(Lead $lead): array
    {
        $mensaje = LeadMessage::query()->where('lead_id', (int) $lead->id)->max('id');
        $llamada = LeadLlamada::query()->where('lead_id', (int) $lead->id)->max('id');

        return [
            'baseline_lead_message_id' => $mensaje !== null ? (int) $mensaje : null,
            'baseline_llamada_id'      => $llamada !== null ? (int) $llamada : null,
            'estado_lead_al_crear'     => (string) $lead->status,
        ];
    }

    /**
     * ¿Cambió algo del lead desde que se creó el compromiso? Cuenta como cambio:
     *  - un mensaje del lead (cualquiera, menos una reacción) o un mensaje nuestro YA ENVIADO, posteriores a la huella;
     *  - una llamada registrada después de la huella;
     *  - un estado del lead distinto del que tenía.
     * NO cuentan los eventos de estado del hilo ni las sugerencias que nadie envió.
     *
     * @param LeadCompromiso $compromiso
     * @param Lead           $lead
     *
     * @return bool
     */
    public function hubo_cambios(LeadCompromiso $compromiso, Lead $lead): bool
    {
        $mensajes = LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('is_status_event', false)
            ->where(function ($query) {
                $query->where('sender', 'lead')->orWhere('status', 'enviado');
            })
            ->where(function ($query) {
                $query->whereNull('kind')->orWhere('kind', '!=', 'reaction');
            });
        if ($compromiso->baseline_lead_message_id !== null) {
            $mensajes->where('id', '>', (int) $compromiso->baseline_lead_message_id);
        }
        if ($mensajes->exists()) {
            return true;
        }

        $llamadas = LeadLlamada::query()->where('lead_id', (int) $lead->id);
        if ($compromiso->baseline_llamada_id !== null) {
            $llamadas->where('id', '>', (int) $compromiso->baseline_llamada_id);
        }
        if ($llamadas->exists()) {
            return true;
        }

        return $compromiso->estado_lead_al_crear !== null && (string) $lead->status !== (string) $compromiso->estado_lead_al_crear;
    }

    // ------------------------------------------------------------------------------------------
    // La tarjeta del tablero de Juan
    // ------------------------------------------------------------------------------------------

    /**
     * Pone la fecha del compromiso como próxima acción de la tarjeta abierta del lead (nota «Compromiso: …»), para que Juan lo
     * vea en el tablero. Si el lead no tiene tarjeta abierta no hace nada. Nunca lanza: un tablero con problemas no frena el
     * compromiso.
     *
     * @param Lead           $lead
     * @param LeadCompromiso $compromiso
     * @param int|null       $admin_id
     *
     * @return void
     */
    private function fijar_la_tarjeta(Lead $lead, LeadCompromiso $compromiso, $admin_id): void
    {
        try {
            $tarjeta = app(TableroDeJuanService::class)->tarjeta_abierta($lead);
            if ($tarjeta === null) {
                return;
            }

            $detalle = trim((string) $compromiso->resumen);
            $nota    = self::PREFIJO_DE_LA_TARJETA . ' ' . ($detalle !== '' ? mb_substr($detalle, 0, 200) : $this->descripcion_de_la_accion((string) $compromiso->accion));

            app(PipelineOpportunityService::class)->actualizar($tarjeta, [
                'next_action_at'   => $compromiso->vence_at->format('Y-m-d H:i'),
                'next_action_note' => $nota,
            ], $admin_id);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('LeadCompromisoService: no se pudo fijar la próxima acción de la tarjeta.', [
                'lead_id'       => $lead->id,
                'compromiso_id' => $compromiso->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Saca la próxima acción de la tarjeta abierta si la había fijado un compromiso (su nota empieza con «Compromiso:»). Una
     * próxima acción puesta por una persona no se toca. Nunca lanza.
     *
     * @param Lead     $lead
     * @param int|null $admin_id
     *
     * @return void
     */
    private function limpiar_la_tarjeta(Lead $lead, $admin_id): void
    {
        try {
            $tarjeta = app(TableroDeJuanService::class)->tarjeta_abierta($lead);
            if ($tarjeta === null || strpos((string) $tarjeta->next_action_note, self::PREFIJO_DE_LA_TARJETA) !== 0) {
                return;
            }

            app(PipelineOpportunityService::class)->actualizar($tarjeta, ['next_action_at' => null, 'next_action_note' => null], $admin_id);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('LeadCompromisoService: no se pudo limpiar la próxima acción de la tarjeta.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Qué es cada acción, en palabras, para la nota de la tarjeta cuando el compromiso no tiene resumen.
     *
     * @param string $accion
     *
     * @return string
     */
    private function descripcion_de_la_accion(string $accion): string
    {
        if ($accion === LeadCompromiso::ACCION_ESCRIBIR) {
            return 'escribirle al lead';
        }
        if ($accion === LeadCompromiso::ACCION_ESPERAR) {
            return 'el lead iba a avisar';
        }

        return 'llamar al lead';
    }
}
