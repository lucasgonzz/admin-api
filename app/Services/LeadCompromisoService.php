<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\AdminTask;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadCompromiso;
use App\Models\LeadLlamada;
use App\Models\LeadMessage;
use App\Models\LeadScheduledMessage;
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
        /* Lo que carga un admin «Sólo Leads» (o lo que hereda de uno al reprogramarse) nunca sale solo. */
        $sin_envio_automatico = $this->es_de_solo_leads($admin_id) || ! empty($opciones['sin_envio_automatico']);
        $con_tarjeta  = ! array_key_exists('tarjeta', $opciones) || $opciones['tarjeta'] !== false;

        $nuevo = DB::transaction(function () use ($lead, $limpios, $origen, $origen_id, $admin_id, $sin_envio_automatico) {
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
                'sin_envio_automatico' => $sin_envio_automatico,
            ]));

            /* El vigente anterior (y uno que quedó para revisar) queda reemplazado. Uno que está `procesando` lo está ejecutando una
               corrida del comando y lo termina ella. */
            LeadCompromiso::query()
                ->where('lead_id', (int) $bloqueado->id)
                ->whereIn('status', [LeadCompromiso::STATUS_VIGENTE, LeadCompromiso::STATUS_REVISAR])
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
        /* Si lo toca un admin «Sólo Leads», el mensaje que genere nunca sale solo (aunque lo haya cargado otro). */
        if ($this->es_de_solo_leads($admin_id)) {
            $compromiso->sin_envio_automatico = true;
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
     * Da por cumplido el compromiso vigente del lead (y uno que había quedado para revisar): hubo contacto (una llamada sin fecha
     * nueva) y ya no hace falta.
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
            ->whereIn('status', [LeadCompromiso::STATUS_VIGENTE, LeadCompromiso::STATUS_REVISAR])
            ->update([
                'status'               => LeadCompromiso::STATUS_CUMPLIDO,
                'resultado'            => mb_substr($resultado, 0, 1000),
                'cerrado_por_admin_id' => $admin_id !== null ? (int) $admin_id : null,
                'updated_at'           => AppTime::now(),
            ]);
    }

    /**
     * Cancela todos los compromisos abiertos del lead (vigente, procesando o para revisar): lo usa una llamada que cierra el caso.
     *
     * @param Lead        $lead
     * @param string      $motivo
     * @param int|null    $admin_id
     *
     * @return void
     */
    public function cancelar_los_abiertos(Lead $lead, string $motivo, $admin_id = null): void
    {
        $abiertos = LeadCompromiso::query()
            ->where('lead_id', (int) $lead->id)
            ->whereIn('status', [LeadCompromiso::STATUS_VIGENTE, LeadCompromiso::STATUS_PROCESANDO, LeadCompromiso::STATUS_REVISAR])
            ->get();

        foreach ($abiertos as $compromiso) {
            $this->cancelar($compromiso, $motivo, $admin_id);
        }
    }

    /**
     * ¿El admin tiene el perfil «Sólo Leads» (Juan)? Sus compromisos nunca mandan un mensaje solos.
     *
     * @param int|null $admin_id
     *
     * @return bool
     */
    private function es_de_solo_leads($admin_id): bool
    {
        if ($admin_id === null) {
            return false;
        }

        $admin = \App\Models\Admin::query()->find((int) $admin_id);

        return $admin !== null && app(AdminSoloLeadsPolicy::class)->tiene_perfil($admin);
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

    // ------------------------------------------------------------------------------------------
    // La ejecución (el comando `leads:compromisos`, cada minuto)
    // ------------------------------------------------------------------------------------------

    /** `created_via` de las tareas que deja el motor. 🔴 `admin_tasks.created_via` es varchar(20): 17 caracteres entran, y el
     *  prefijo `embudo_` hace que «Registrar llamada» la cierre sola. */
    const CREATED_VIA_DE_LA_TAREA = 'embudo_compromiso';

    /** `system_message_kind` de los mensajes que deja el motor. */
    const KIND_DEL_MENSAJE = 'lead_compromiso';

    /** Cuántos compromisos vencidos toma cada corrida del comando. */
    const LOTE_POR_CORRIDA = 20;

    /** Minutos que un compromiso puede estar «procesando» antes de darlo por colgado (la corrida murió). */
    const MINUTOS_PARA_DAR_POR_COLGADO = 15;

    /** Cuántas veces se reintenta un compromiso colgado antes de mandarlo a revisar. */
    const INTENTOS_MAXIMOS = 3;

    /**
     * Una corrida del comando: recupera los compromisos colgados, toma los vencidos (hasta 20) y los ejecuta o re-evalúa.
     * Con el motor apagado no hace nada.
     *
     * @return array{recuperados: int, tomados: int, ejecutados: int}
     */
    public function correr(): array
    {
        $estadisticas = ['recuperados' => 0, 'tomados' => 0, 'ejecutados' => 0];
        if (! self::activo()) {
            return $estadisticas;
        }

        $estadisticas['recuperados'] = $this->recuperar_los_colgados();

        $vencidos = LeadCompromiso::query()
            ->where('status', LeadCompromiso::STATUS_VIGENTE)
            ->where('vence_at', '<=', AppTime::now())
            ->orderBy('vence_at')
            ->limit(self::LOTE_POR_CORRIDA)
            ->pluck('id');

        foreach ($vencidos as $id) {
            /* 🔴 El candado contra corridas dobles: se toma con un UPDATE condicional. Una fila afectada = es mío; cero = otra
               corrida (o una persona que lo reemplazó o canceló) se me adelantó. */
            $tomado = LeadCompromiso::query()
                ->where('id', (int) $id)
                ->where('status', LeadCompromiso::STATUS_VIGENTE)
                ->update(['status' => LeadCompromiso::STATUS_PROCESANDO, 'procesando_desde' => AppTime::now(), 'updated_at' => AppTime::now()]);
            if ($tomado !== 1) {
                continue;
            }

            $estadisticas['tomados']++;
            $compromiso = LeadCompromiso::query()->find((int) $id);
            try {
                $this->procesar($compromiso);
                $estadisticas['ejecutados']++;
            } catch (\Throwable $e) {
                /* Queda `procesando`: la recuperación de los colgados lo vuelve a poner en cola (contando el intento) y, al tercero,
                   lo manda a revisar. Nunca un mensaje al lead desde una falla. */
                Log::channel('daily')->error('LeadCompromisoService: falló la ejecución de un compromiso.', ['compromiso_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $estadisticas;
    }

    /**
     * Los compromisos que quedaron «procesando» más de 15 minutos (la corrida murió) vuelven a la cola contando el intento; al
     * tercero pasan a `revisar` con una tarea para Juan.
     *
     * @return int Cuántos se tocaron.
     */
    private function recuperar_los_colgados(): int
    {
        $colgados = LeadCompromiso::query()
            ->where('status', LeadCompromiso::STATUS_PROCESANDO)
            ->where('procesando_desde', '<', AppTime::now()->subMinutes(self::MINUTOS_PARA_DAR_POR_COLGADO))
            ->orderBy('id')
            ->limit(self::LOTE_POR_CORRIDA)
            ->get();

        foreach ($colgados as $compromiso) {
            $intentos = (int) $compromiso->intentos + 1;

            if ($intentos >= self::INTENTOS_MAXIMOS) {
                $compromiso->intentos = $intentos;
                $compromiso->save();
                $lead = Lead::query()->find((int) $compromiso->lead_id);
                if ($lead !== null) {
                    $this->mandar_a_revisar($compromiso, $lead, 'No se pudo ejecutar después de ' . self::INTENTOS_MAXIMOS . ' intentos.');
                } else {
                    $this->cerrar($compromiso, LeadCompromiso::STATUS_CANCELADO, 'El lead ya no existe.');
                }
                continue;
            }

            /* Si mientras tanto nació otro vigente, el colgado ya no es el contacto acordado: queda reemplazado (nunca dos abiertos). */
            $otro = LeadCompromiso::query()
                ->where('lead_id', (int) $compromiso->lead_id)
                ->where('status', LeadCompromiso::STATUS_VIGENTE)
                ->where('id', '!=', (int) $compromiso->id)
                ->orderByDesc('id')
                ->first();
            $cambios = ['status' => LeadCompromiso::STATUS_VIGENTE, 'procesando_desde' => null, 'intentos' => $intentos, 'updated_at' => AppTime::now()];
            if ($otro !== null) {
                $cambios['status']             = LeadCompromiso::STATUS_REEMPLAZADO;
                $cambios['reemplazado_por_id'] = (int) $otro->id;
            }

            LeadCompromiso::query()
                ->where('id', (int) $compromiso->id)
                ->where('status', LeadCompromiso::STATUS_PROCESANDO)
                ->update($cambios);
        }

        return $colgados->count();
    }

    /**
     * Procesa un compromiso ya tomado (`procesando`): si nada cambió desde su huella ejecuta lo acordado; si cambió algo, lo
     * re-evalúa con IA con el contexto completo.
     *
     * @param LeadCompromiso $compromiso
     *
     * @return void
     */
    public function procesar(LeadCompromiso $compromiso): void
    {
        $lead = Lead::query()->find((int) $compromiso->lead_id);
        if ($lead === null) {
            $this->cerrar($compromiso, LeadCompromiso::STATUS_CANCELADO, 'El lead ya no existe.');

            return;
        }

        if ($this->hubo_cambios($compromiso, $lead)) {
            $this->reevaluar($compromiso, $lead);

            return;
        }

        $this->ejecutar($compromiso, $lead);
    }

    /**
     * Ejecuta lo acordado: `llamar` deja una tarea para Juan; `escribir` y `esperar` dejan el mensaje de Martín.
     *
     * @param LeadCompromiso $compromiso
     * @param Lead           $lead
     *
     * @return void
     */
    private function ejecutar(LeadCompromiso $compromiso, Lead $lead): void
    {
        if ((string) $compromiso->accion === LeadCompromiso::ACCION_LLAMAR) {
            $this->dejar_la_tarea_de_llamar($compromiso, $lead);

            return;
        }

        $this->dejar_el_mensaje($compromiso, $lead);
    }

    /**
     * La re-evaluación: la IA decide con el contexto completo si se ejecuta, se reprograma o se cancela. Cualquier cosa que no se
     * pueda creer (IA caída, JSON roto, fecha inventada) manda el compromiso a revisar: nunca un mensaje al lead desde una falla.
     *
     * @param LeadCompromiso $compromiso
     * @param Lead           $lead
     *
     * @return void
     */
    private function reevaluar(LeadCompromiso $compromiso, Lead $lead): void
    {
        $decision = app(LeadCompromisoIaService::class)->reevaluar($lead, $compromiso);

        if (! $decision['ok']) {
            $this->mandar_a_revisar($compromiso, $lead, 'La re-evaluación no dio una decisión creíble (' . (string) $decision['motivo'] . ').');

            return;
        }

        if ($decision['decision'] === 'ejecutar') {
            $this->ejecutar($compromiso, $lead);

            return;
        }

        if ($decision['decision'] === 'cancelar') {
            $this->cerrar($compromiso, LeadCompromiso::STATUS_CANCELADO, 'Cancelado al re-evaluar: ' . (string) $decision['motivo']);
            $this->limpiar_la_tarjeta($lead, null);

            return;
        }

        /* Reprogramar: nace el compromiso nuevo (con huella nueva, así no se re-evalúa solo) y el viejo queda `reprogramado`. */
        try {
            $nuevo = $this->crear($lead, [
                'vence_at'      => $decision['vence_at'],
                'accion'        => $decision['accion'],
                'resumen'       => (string) $compromiso->resumen,
                'cita_del_lead' => $compromiso->cita_del_lead,
                'origen'        => LeadCompromiso::ORIGEN_REEVALUACION,
                'origen_id'     => (int) $compromiso->id,
            ], null, ['sin_envio_automatico' => (bool) $compromiso->sin_envio_automatico]);
        } catch (PipelineRuleException $e) {
            $nuevo = null;
        }

        if ($nuevo === null) {
            $this->mandar_a_revisar($compromiso, $lead, 'No se pudo crear el compromiso reprogramado.');

            return;
        }

        LeadCompromiso::query()
            ->where('id', (int) $compromiso->id)
            ->where('status', LeadCompromiso::STATUS_PROCESANDO)
            ->update([
                'status'             => LeadCompromiso::STATUS_REPROGRAMADO,
                'reemplazado_por_id' => (int) $nuevo->id,
                'resultado'          => 'Reprogramado al re-evaluar: ' . (string) $decision['motivo'],
                'procesando_desde'   => null,
                'updated_at'         => AppTime::now(),
            ]);
    }

    /**
     * `llamar`: una tarea para Juan con todo lo que hay que saber para la llamada. Idempotente: si el compromiso ya dejó su
     * tarea (una corrida anterior murió después de crearla) no crea otra.
     *
     * @param LeadCompromiso $compromiso
     * @param Lead           $lead
     *
     * @return void
     */
    private function dejar_la_tarea_de_llamar(LeadCompromiso $compromiso, Lead $lead): void
    {
        if ($compromiso->admin_task_id === null) {
            $this->crear_la_tarea($compromiso, $lead, 'Llamar a ' . $this->nombre_para_el_titulo($lead) . ': quedaron para hoy', $this->contenido_de_la_tarea($compromiso, $lead));
        }

        $this->cerrar($compromiso->fresh(), LeadCompromiso::STATUS_EJECUTADO, 'Tarea para llamar creada.');
    }

    /**
     * `escribir` y `esperar`: el mensaje de Martín que retoma lo último que dijo el lead.
     *
     * Reglas, en orden (todas fijadas por los hallazgos de E1: nada de lo que decida la IA las saltea):
     *  1. Un lead que pidió no recibir mensajes, que ya es cliente o que está cerrado no recibe nada: el compromiso se cancela.
     *  2. Fuera de la franja de 9 a 21 h el compromiso se corre al próximo inicio de franja.
     *  3. Con la ventana de 24 h abierta lo redacta la IA (validado); con la ventana cerrada sale la plantilla del compromiso si está
     *     aprobada. Si no hay un texto válido ni una plantilla aprobada, se le avisa a Juan con una tarea: nunca se manda algo que
     *     no se pueda justificar.
     *  4. Queda como SUGERENCIA para aprobar; con el envío automático prendido sale por el camino de siempre.
     *
     * @param LeadCompromiso $compromiso
     * @param Lead           $lead
     *
     * @return void
     */
    private function dejar_el_mensaje(LeadCompromiso $compromiso, Lead $lead): void
    {
        /* Una corrida anterior ya dejó el mensaje: no se duplica. */
        if ($compromiso->lead_message_id !== null) {
            $this->cerrar($compromiso, LeadCompromiso::STATUS_EJECUTADO, 'El mensaje ya estaba creado.');

            return;
        }

        if (! empty($lead->no_recibe_mensajes_at)) {
            $this->cerrar($compromiso, LeadCompromiso::STATUS_CANCELADO, 'El lead pidió no recibir mensajes: no se le escribe.');
            $this->limpiar_la_tarjeta($lead, null);

            return;
        }
        if (! empty($lead->promoted_client_id) || in_array((string) $lead->status, LeadScheduledMessage::STATUSES_DE_LEAD_QUE_FRENAN, true)) {
            $this->cerrar($compromiso, LeadCompromiso::STATUS_CANCELADO, 'El lead ya es cliente o está cerrado: no se le escribe.');
            $this->limpiar_la_tarjeta($lead, null);

            return;
        }

        /* El número de un cliente (o de su equipo, o de un admin) no recibe mensajes de lead: el mismo freno que los otros envíos
           automáticos ({@see ClientPhoneDirectory}). */
        $motivo_de_cliente = (new ClientPhoneDirectory())->motivo_de_lead_de_un_cliente($lead);
        if ($motivo_de_cliente !== null) {
            $this->cerrar($compromiso, LeadCompromiso::STATUS_CANCELADO, 'El teléfono es de un cliente (' . $motivo_de_cliente . '): no se le escribe.');
            $this->limpiar_la_tarjeta($lead, null);

            return;
        }

        $telefono = trim((string) $lead->phone);
        if ($telefono === '') {
            $this->crear_la_tarea($compromiso, $lead, 'Escribile a ' . $this->nombre_para_el_titulo($lead) . ': quedaron para hoy (no tiene teléfono cargado)', $this->contenido_de_la_tarea($compromiso, $lead));
            $this->cerrar($compromiso->fresh(), LeadCompromiso::STATUS_EJECUTADO, 'Sin teléfono: tarea para Juan.');

            return;
        }

        /* Fuera de la franja no sale nada: se corre al próximo inicio de franja (09:01) y el compromiso sigue vigente. */
        if (! FranjaHorariaDeSeguimientos::ahora_esta_dentro()) {
            LeadCompromiso::query()
                ->where('id', (int) $compromiso->id)
                ->where('status', LeadCompromiso::STATUS_PROCESANDO)
                ->update([
                    'status'           => LeadCompromiso::STATUS_VIGENTE,
                    'vence_at'         => FranjaHorariaDeSeguimientos::momento_para_reprogramar(AppTime::now())->format('Y-m-d H:i:s'),
                    'procesando_desde' => null,
                    'updated_at'       => AppTime::now(),
                ]);

            return;
        }

        $texto           = null;
        $plantilla       = null;
        $variables       = [];
        $por_que_no      = null;
        $ventana_cerrada = ! app(WhatsappSessionWindowService::class)->is_open($telefono);

        /* Ventana cerrada: sale la plantilla del compromiso si se puede usar. */
        if ($ventana_cerrada) {
            $estado = FollowupTemplate::estado_para_enviar(self::nombre_de_la_plantilla());
            if ($estado['motivo'] !== null) {
                $por_que_no = 'la ventana de 24 h está cerrada y la plantilla no se puede usar (' . (string) $estado['motivo'] . ')';
            } else {
                $armado = $this->armar_la_plantilla($estado['fila'], $lead, $compromiso);
                if ($armado === null) {
                    $por_que_no = 'la plantilla pide datos que el compromiso no tiene';
                } else {
                    $plantilla = $estado['fila'];
                    $texto     = $armado['texto'];
                    $variables = $armado['variables'];
                }
            }
        }

        /* Ventana abierta, o cerrada sin plantilla usable: lo redacta la IA (validado). Con la ventana cerrada es sólo un borrador
           para que una persona decida cómo retomarlo: nunca sale solo (un texto libre no se puede mandar fuera de la ventana). */
        if ($texto === null) {
            $redaccion = app(LeadCompromisoIaService::class)->redactar($lead, $compromiso);
            if ($redaccion['ok']) {
                $texto = (string) $redaccion['texto'];
            } else {
                $por_que_no = ($por_que_no !== null ? $por_que_no . '; ' : '') . 'la IA no dio un texto válido (' . (string) $redaccion['motivo'] . ')';
            }
        }

        if ($texto === null) {
            $this->crear_la_tarea($compromiso, $lead, 'Escribile a ' . $this->nombre_para_el_titulo($lead) . ': quedaron para hoy', $this->contenido_de_la_tarea($compromiso, $lead) . "\n\nNo se le escribió solo: " . $por_que_no . '.');
            $this->cerrar($compromiso->fresh(), LeadCompromiso::STATUS_EJECUTADO, 'Tarea para escribirle: ' . $por_que_no . '.');

            return;
        }

        $mensaje = LeadMessage::create([
            'lead_id'               => (int) $lead->id,
            'sender'                => 'sistema',
            'content'               => $texto,
            'status'                => 'sugerido',
            'is_followup'           => $plantilla !== null,
            'followup_template_id'  => $plantilla !== null ? (int) $plantilla->id : null,
            'requiere_verificacion' => true,
            'system_message_kind'   => self::KIND_DEL_MENSAJE,
            'ai_reasoning'          => 'Compromiso #' . $compromiso->id . ': ' . ($compromiso->resumen !== null && trim((string) $compromiso->resumen) !== '' ? trim((string) $compromiso->resumen) : 'retomar el contacto acordado')
                . ($ventana_cerrada && $plantilla === null ? ' (la ventana de 24 h está cerrada: hay que retomarlo con una plantilla)' : ''),
        ]);
        LeadCompromiso::query()->where('id', (int) $compromiso->id)->update(['lead_message_id' => (int) $mensaje->id]);

        /* Sin el temporizador de auto-envío de respaldo a propósito: con el envío automático apagado nada sale sin que una persona lo
           apruebe. */
        $lead->fresh()->sync_suggestion_flags();
        \App\Services\LeadBroadcastService::emit_conversation_updated((int) $lead->id, (int) $mensaje->id);

        $solo_borrador = $ventana_cerrada && $plantilla === null;
        /* Un borrador con la ventana cerrada no se puede mandar como texto: además de la sugerencia, Juan recibe su tarea (nadie
           queda sin contacto). */
        if ($solo_borrador) {
            $this->crear_la_tarea($compromiso, $lead, 'Escribile a ' . $this->nombre_para_el_titulo($lead) . ': quedaron para hoy', $this->contenido_de_la_tarea($compromiso, $lead) . "\n\nDejé un borrador como sugerencia en la conversación, pero la ventana de 24 h está cerrada: " . (string) $por_que_no . '.');
        }

        $resultado = 'Mensaje sugerido para aprobar.';
        if (self::envio_automatico_activo() && ! $solo_borrador && ! (bool) $compromiso->sin_envio_automatico) {
            $enviado   = app(LeadSuggestionSendService::class)->send_suggestion($mensaje, null, null, true);
            $resultado = (string) $enviado->status === 'enviado' ? 'Mensaje enviado.' : 'Mensaje creado; quedó para aprobar.';
        }

        $this->cerrar($compromiso->fresh(), LeadCompromiso::STATUS_EJECUTADO, $resultado);
    }

    /**
     * Las variables y el texto de la plantilla del compromiso, completos de punta a punta. {{1}} es el primer nombre (la regla de
     * siempre); cualquier otra variable lleva lo que dijo el lead tal cual (`cita_del_lead`), que es lo que pide
     * `cc_coord_dia_acordado` ({{2}} = el momento en sus palabras).
     *
     * 🔴 Es la ÚNICA definición de qué se ve y qué sale: la usa quien arma la sugerencia y la usa el envío al aprobarla
     * ({@see self::armar_para_el_envio()}), así lo que lee Juan es lo que recibe el lead. Si no se puede completar (cuerpo vacío o
     * alguna variable sin valor) devuelve null y la plantilla no se usa.
     *
     * @param FollowupTemplate    $plantilla
     * @param Lead                $lead
     * @param LeadCompromiso|null $compromiso
     *
     * @return array{texto: string, variables: array<int, string>}|null
     */
    public function armar_la_plantilla(FollowupTemplate $plantilla, Lead $lead, $compromiso)
    {
        $cuerpo = trim((string) $plantilla->body_template);
        if ($cuerpo === '') {
            return null;
        }

        $huecos  = is_array($plantilla->variables) ? array_values($plantilla->variables) : [];
        $valores = array_values(app(LeadFollowupService::class)->build_template_variables($plantilla, $lead));

        /* Meta rechaza saltos de línea y espacios repetidos en una variable: se acomoda lo que dijo el lead. */
        $cita = $compromiso !== null ? trim((string) preg_replace('/\s+/u', ' ', (string) $compromiso->cita_del_lead)) : '';
        $cita = mb_substr($cita, 0, 100);

        $variables = [];
        $texto     = $cuerpo;
        foreach ($huecos as $indice => $hueco) {
            $valor = isset($valores[$indice]) ? trim((string) $valores[$indice]) : '';
            if ($valor === '' && $indice > 0) {
                $valor = $cita;
            }
            if ($valor === '' || ! isset($hueco['placeholder'])) {
                return null;
            }
            $variables[] = $valor;
            $texto       = str_replace((string) $hueco['placeholder'], $valor, $texto);
        }

        return ['texto' => $texto, 'variables' => $variables];
    }

    /**
     * Lo mismo que se vio en la sugerencia, rearmado al aprobarla: busca el compromiso que dejó ese mensaje y resuelve la plantilla
     * igual. Lo llama {@see LeadSuggestionSendService} para los mensajes del motor.
     *
     * @param LeadMessage      $mensaje
     * @param FollowupTemplate $plantilla
     * @param Lead             $lead
     *
     * @return array{texto: string, variables: array<int, string>}|null Null si ya no se puede completar: el envío se frena.
     */
    public function armar_para_el_envio(LeadMessage $mensaje, FollowupTemplate $plantilla, Lead $lead)
    {
        $compromiso = LeadCompromiso::query()->where('lead_message_id', (int) $mensaje->id)->first();
        if ($compromiso === null) {
            return null;
        }

        return $this->armar_la_plantilla($plantilla, $lead, $compromiso);
    }

    /**
     * Un compromiso que no se pudo resolver solo: queda en `revisar` y Juan recibe una tarea «Revisá a …».
     *
     * @param LeadCompromiso $compromiso
     * @param Lead           $lead
     * @param string         $motivo
     *
     * @return void
     */
    private function mandar_a_revisar(LeadCompromiso $compromiso, Lead $lead, string $motivo): void
    {
        if ($compromiso->admin_task_id === null) {
            $this->crear_la_tarea($compromiso, $lead, 'Revisá a ' . $this->nombre_para_el_titulo($lead) . ': el compromiso de hoy cambió de contexto', $this->contenido_de_la_tarea($compromiso, $lead) . "\n\nPor qué lo tenés que mirar: " . $motivo);
        }

        $this->cerrar($compromiso->fresh(), LeadCompromiso::STATUS_REVISAR, $motivo);
    }

    /**
     * Cierra un compromiso que está `procesando` con su status final. Sólo actúa si sigue `procesando`: uno que una persona
     * reemplazó o canceló mientras tanto no se pisa.
     *
     * @param LeadCompromiso $compromiso
     * @param string         $status
     * @param string         $resultado
     *
     * @return void
     */
    private function cerrar(LeadCompromiso $compromiso, string $status, string $resultado): void
    {
        $cambios = [
            'status'           => $status,
            'resultado'        => mb_substr($resultado, 0, 1000),
            'procesando_desde' => null,
            'updated_at'       => AppTime::now(),
        ];
        if ($status === LeadCompromiso::STATUS_EJECUTADO) {
            $cambios['ejecutado_at'] = AppTime::now();
        }

        LeadCompromiso::query()
            ->where('id', (int) $compromiso->id)
            ->where('status', LeadCompromiso::STATUS_PROCESANDO)
            ->update($cambios);
    }

    // ------------------------------------------------------------------------------------------
    // Las tareas de Juan
    // ------------------------------------------------------------------------------------------

    /**
     * «Ro (Ferretería de Ro)» para el título de una tarea; sin nombre, la empresa o el teléfono.
     *
     * @param Lead $lead
     *
     * @return string
     */
    private function nombre_para_el_titulo(Lead $lead): string
    {
        $nombre  = trim((string) $lead->contact_name);
        $empresa = trim((string) $lead->company_name);

        if ($nombre === '') {
            $nombre  = $empresa !== '' ? $empresa : (trim((string) $lead->phone) !== '' ? trim((string) $lead->phone) : 'Lead #' . $lead->id);
            $empresa = '';
        }

        return $nombre . ($empresa !== '' ? ' (' . $empresa . ')' : '');
    }

    /**
     * El cuerpo de la tarea: qué se acordó y todo lo que Juan necesita para retomar (lo que dijo el lead, su última llamada, los
     * últimos mensajes y el link a la conversación).
     *
     * @param LeadCompromiso $compromiso
     * @param Lead           $lead
     *
     * @return string
     */
    private function contenido_de_la_tarea(LeadCompromiso $compromiso, Lead $lead): string
    {
        $lineas = [];
        if (trim((string) $compromiso->resumen) !== '') {
            $lineas[] = 'Quedaron en: ' . trim((string) $compromiso->resumen);
        }
        if (trim((string) $compromiso->cita_del_lead) !== '') {
            $lineas[] = 'Lo que dijo el lead: «' . trim((string) $compromiso->cita_del_lead) . '»';
        }
        $lineas[] = 'Teléfono: ' . (trim((string) $lead->phone) !== '' ? trim((string) $lead->phone) : 'sin cargar');

        $llamada = LeadLlamada::query()->where('lead_id', (int) $lead->id)->orderByDesc('id')->first();
        if ($llamada !== null) {
            $detalle  = $llamada->atendio ? 'atendió' : 'no atendió';
            $lineas[] = 'Última llamada (' . $llamada->created_at->format('d/m H:i') . ', ' . $detalle . '): ' . (trim((string) $llamada->notas) !== '' ? mb_substr(trim((string) $llamada->notas), 0, 300) : 'sin notas');
        }

        $ultimos = LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('is_status_event', false)
            ->where(function ($query) {
                $query->where('sender', 'lead')->orWhere('status', 'enviado');
            })
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->reverse();
        if ($ultimos->count() > 0) {
            $lineas[] = 'Últimos mensajes:';
            foreach ($ultimos as $mensaje) {
                $lineas[] = '- ' . ((string) $mensaje->sender === 'lead' ? 'Lead' : 'Nosotros') . ': ' . mb_substr(trim((string) $mensaje->content), 0, 200);
            }
        }

        $base     = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'https://admin.comerciocity.com')), '/');
        $lineas[] = 'Conversación: ' . $base . '/leads/' . (int) $lead->id . '/conversacion';

        return implode("\n", $lineas);
    }

    /**
     * Crea la tarea para Juan (asignada como las del embudo) y la ata al compromiso. La tarea y el vínculo se guardan juntos: si
     * la corrida muere después, el reintento ve `admin_task_id` y no crea otra. El aviso va aparte: un aviso caído no tira la tarea.
     *
     * @param LeadCompromiso $compromiso
     * @param Lead           $lead
     * @param string         $titulo
     * @param string         $contenido
     *
     * @return AdminTask|null
     */
    private function crear_la_tarea(LeadCompromiso $compromiso, Lead $lead, string $titulo, string $contenido)
    {
        $tarea = DB::transaction(function () use ($compromiso, $lead, $titulo, $contenido) {
            $tarea = AdminTask::create([
                /* Creador cero: la tarea la crea el sistema (mismo criterio que {@see LeadTareaVendedorService}). */
                'created_by_admin_id' => 0,
                'assigned_admin_id'   => null,
                'lead_id'             => (int) $lead->id,
                'title'               => mb_substr($titulo, 0, 250),
                'content'             => $contenido,
                'todos'               => null,
                'is_done'             => false,
                'sort_order'          => 0,
                'created_via'         => self::CREATED_VIA_DE_LA_TAREA,
            ]);
            LeadCompromiso::query()->where('id', (int) $compromiso->id)->update(['admin_task_id' => (int) $tarea->id]);

            return $tarea;
        });

        try {
            $ids = AdminTaskAssignmentResolver::for_embudo_task();
            if (! empty($ids)) {
                $tarea->assigned_admins()->sync($ids);
                $tarea->assigned_admin_id = $ids[0];
                $tarea->save();
            }
            AdminTaskNotificationService::create_for_task($tarea);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('LeadCompromisoService: la tarea quedó creada pero la asignación o el aviso fallaron.', ['task_id' => $tarea->id, 'error' => $e->getMessage()]);
        }

        return $tarea;
    }
}
