<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\Lead;
use App\Models\LeadLlamada;
use App\Models\LeadPipelineStatus;
use App\Models\PipelineOpportunity;
use App\Models\PipelineStage;
use App\Services\Pipelines\PipelineFieldsService;
use App\Services\Pipelines\PipelineOpportunityService;
use App\Services\Pipelines\PipelinePresenter;
use App\Services\Pipelines\PipelineRuleException;
use App\Services\Pipelines\TableroDeJuanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «Registrar llamada» del tablero de Juan (misión embudo-juan-pipeline, E1 y E1b).
 *
 * Juan llama al lead y deja asentado qué habló: si atendió, el resultado, lo que cotizó y las notas. Lo hace él desde el
 * modal del tablero, o el agente de OpenClaw en su nombre a partir de un audio de Juan (`origen = audio_juan`, E1b).
 *
 * `registrar()` corre en una transacción con `lockForUpdate` del lead (el candado contra el doble clic y contra el audio
 * procesado dos veces) y, en orden:
 *  1. valida (422 con `errors.<campo>`) y, si es un audio ya cargado, devuelve la llamada que ya existe sin ningún efecto;
 *  2. si el lead no tiene tarjeta abierta, la crea en «Interesados»;
 *  3. guarda la fila en `lead_llamadas` y deja la nota (canal `call`) en el historial de la tarjeta;
 *  4. mueve la tarjeta y aplica el efecto sobre el lead según el resultado;
 *  5. cierra las tareas abiertas del embudo de ESE lead (`created_via` que empieza con `embudo_`).
 *
 * También arma los bloques de contexto de los agentes: {@see self::bloque_para_agente()} (Martín).
 */
class LeadLlamadaService
{
    /** Tope de caracteres de las notas. */
    const MAX_NOTAS = 5000;

    /** Tope de caracteres de lo cotizado y del motivo. */
    const MAX_CORTO = 255;

    /** Tope de caracteres de la transcripción de un audio. */
    const MAX_TRANSCRIPCION = 20000;

    /** Tope de la clave de idempotencia. */
    const MAX_ORIGEN_CLAVE = 120;

    /** Estados del lead desde los que «implementación acordada» lo pasa a `implementacion_pedida`. */
    const ESTADOS_QUE_PASAN_A_IMPLEMENTACION_PEDIDA = ['nuevo', 'contactado', 'calificado', 'link_enviado', 'prueba_activa'];

    /** Cuántas llamadas ve Martín en su contexto. */
    const LLAMADAS_PARA_MARTIN = 5;

    /** Caracteres de las notas de cada llamada en el contexto de Martín. */
    const NOTAS_PARA_MARTIN = 300;

    /** Caracteres de la transcripción de cada llamada en el contexto de Martín. */
    const TRANSCRIPCION_PARA_MARTIN = 1500;

    /** @var TableroDeJuanService */
    private $tablero;

    /** @var PipelineOpportunityService */
    private $oportunidades;

    /**
     * @param TableroDeJuanService       $tablero       Las columnas y la entrada al tablero.
     * @param PipelineOpportunityService $oportunidades Alta, mover, nota y próxima acción de las tarjetas.
     */
    public function __construct(TableroDeJuanService $tablero, PipelineOpportunityService $oportunidades)
    {
        $this->tablero       = $tablero;
        $this->oportunidades = $oportunidades;
    }

    /**
     * Registra una llamada de Juan con un lead.
     *
     * @param Lead                 $lead
     * @param Admin                $actor Quien hace el pedido (Juan o Lucas desde el panel, o el agente con su token).
     * @param array<string, mixed> $datos atendio, resultado, motivo, cotizado, notas, proxima_llamada_at, origen,
     *                                    origen_clave, transcripcion y hecha_por_admin_id (sólo lo lee un agente).
     *
     * @return array{llamada: LeadLlamada, tarjeta: PipelineOpportunity|null, duplicada: bool}
     *
     * @throws PipelineRuleException 422 con `errors.<campo>`.
     */
    public function registrar(Lead $lead, Admin $actor, array $datos)
    {
        $pipeline = $this->tablero->pipeline();
        if ($pipeline === null) {
            throw new PipelineRuleException('El pipeline de leads todavía no está sembrado.');
        }

        /* Un audio ya cargado se reconoce ANTES de validar: reprocesarlo cuando su `proxima_llamada_at` ya pasó tiene que
           responder «duplicada», no un 422 por fecha vencida. Se vuelve a chequear adentro del candado. */
        $previa = $this->llamada_ya_cargada($lead, $datos);
        if ($previa !== null) {
            return ['llamada' => $previa->load(['admin', 'cargada_por']), 'tarjeta' => $this->tarjeta_para_responder($lead), 'duplicada' => true];
        }

        $limpios  = $this->validar($datos, (array) $pipeline->lost_reasons);

        /* E2: «pagó la mitad» de un lead que ya tiene prueba (cliente) pide los paquetes implementados; sin cliente (todavía no
           creó la prueba) los paquetes son opcionales. Se valida ANTES de guardar nada. */
        $paquetes = [];
        $cliente  = $lead->promoted_client_id !== null ? \App\Models\Client::query()->find((int) $lead->promoted_client_id) : null;
        if ($limpios['resultado'] === LeadLlamada::RESULTADO_PAGO_LA_MITAD && $cliente !== null) {
            $paquetes = app(ImplementacionContratadaService::class)->validar('mitad', isset($datos['paquetes']) && is_array($datos['paquetes']) ? $datos['paquetes'] : []);
        }
        $limpios['cliente']  = $cliente;
        $limpios['paquetes'] = $paquetes;
        $hecha_por = $this->quien_hizo_la_llamada($actor, $datos);

        return DB::transaction(function () use ($lead, $actor, $limpios, $hecha_por) {
            /* El candado: dos pedidos del mismo lead se hacen la cola acá. */
            $lead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if ($limpios['origen'] === LeadLlamada::ORIGEN_AUDIO_DE_JUAN) {
                $existente = $this->llamada_ya_cargada($lead, $limpios);

                if ($existente !== null) {
                    return ['llamada' => $existente->load(['admin', 'cargada_por']), 'tarjeta' => $this->tarjeta_para_responder($lead), 'duplicada' => true];
                }
            }

            $tarjeta = $this->tablero->tarjeta_abierta($lead);
            $ganada  = null;
            if ($tarjeta === null) {
                /* Un lead que ya es cliente (tarjeta ganada) no abre otra tarjeta en «Interesados»: la llamada queda en el
                   historial de la tarjeta ganada, que no se mueve. */
                $ganada = $this->tarjeta_ganada($lead);
                $tarjeta = $ganada !== null ? $ganada : $this->tablero->entrar_en_interesados($lead, 'llamada registrada');
            }

            $llamada = LeadLlamada::create([
                'lead_id'              => (int) $lead->id,
                'opportunity_id'       => $tarjeta !== null ? (int) $tarjeta->id : null,
                'admin_id'             => $hecha_por,
                'atendio'              => $limpios['atendio'],
                'resultado'            => $limpios['resultado'],
                'motivo'               => $limpios['motivo'],
                'cotizado'             => $limpios['cotizado'],
                'notas'                => $limpios['notas'],
                'proxima_llamada_at'   => $limpios['proxima_llamada_at'],
                'origen'               => $limpios['origen'],
                'cargada_por_admin_id' => $limpios['origen'] === LeadLlamada::ORIGEN_AUDIO_DE_JUAN ? (int) $actor->id : null,
                'origen_clave'         => $limpios['origen_clave'],
                'transcripcion'        => $limpios['transcripcion'],
            ]);

            if ($tarjeta !== null) {
                $this->oportunidades->agregar_nota($tarjeta, $this->cuerpo_de_la_nota($llamada), 'call', null, $hecha_por);
                if ($ganada === null) {
                    $tarjeta = $this->aplicar_el_resultado($lead, $tarjeta, $limpios, $hecha_por);
                } elseif ($limpios['resultado'] === LeadLlamada::RESULTADO_PAGO_LA_MITAD) {
                    /* Un lead que ya es cliente: el tablero no se mueve, pero Lucas tiene que cargar la implementación desde la ficha. */
                    app(ImplementacionContratadaService::class)->crear_la_tarea_desde_la_ficha($lead);
                }
            }

            $this->cerrar_las_tareas_del_embudo($lead, $hecha_por);

            return ['llamada' => $llamada->fresh(['admin', 'cargada_por']), 'tarjeta' => $this->tarjeta_para_responder($lead), 'duplicada' => false];
        });
    }

    /**
     * La llamada de un audio de Juan que ya estaba cargada para el lead (misma `origen_clave`), o null.
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $datos Pedido (o datos limpios): usa `origen` y `origen_clave`.
     *
     * @return LeadLlamada|null
     */
    private function llamada_ya_cargada(Lead $lead, array $datos)
    {
        if ($this->texto($datos, 'origen') !== LeadLlamada::ORIGEN_AUDIO_DE_JUAN) {
            return null;
        }

        $clave = $this->texto($datos, 'origen_clave');
        if ($clave === null) {
            return null;
        }

        return LeadLlamada::query()->where('lead_id', (int) $lead->id)->where('origen_clave', $clave)->orderBy('id')->first();
    }

    /**
     * La última tarjeta del lead en el pipeline si está cerrada como GANADA («Cliente») y no tiene ninguna abierta.
     *
     * @param Lead $lead
     *
     * @return PipelineOpportunity|null
     */
    private function tarjeta_ganada(Lead $lead)
    {
        $pipeline = $this->tablero->pipeline();
        if ($pipeline === null) {
            return null;
        }

        $ultima = PipelineOpportunity::query()->where('pipeline_id', $pipeline->id)->where('lead_id', (int) $lead->id)->orderByDesc('id')->first();
        if ($ultima === null || $ultima->closed_at === null) {
            return null;
        }

        $etapa = PipelineStage::query()->find((int) $ultima->stage_id);

        return $etapa !== null && $etapa->type === PipelineStage::TYPE_WON ? $ultima : null;
    }

    /**
     * La llamada como la ve la pantalla.
     *
     * @param LeadLlamada $llamada
     *
     * @return array<string, mixed>
     */
    public function a_json(LeadLlamada $llamada)
    {
        $llamada->loadMissing(['admin', 'cargada_por']);

        return [
            'id'                   => (int) $llamada->id,
            'lead_id'              => (int) $llamada->lead_id,
            'opportunity_id'       => $llamada->opportunity_id === null ? null : (int) $llamada->opportunity_id,
            'admin_id'             => $llamada->admin_id === null ? null : (int) $llamada->admin_id,
            'admin'                => $llamada->admin ? ['id' => (int) $llamada->admin->id, 'name' => (string) $llamada->admin->name] : null,
            'atendio'              => (bool) $llamada->atendio,
            'resultado'            => $llamada->resultado,
            'resultado_label'      => $llamada->resultado_label(),
            'motivo'               => $llamada->motivo,
            'cotizado'             => $llamada->cotizado,
            'notas'                => $llamada->notas,
            'proxima_llamada_at'   => $llamada->proxima_llamada_at ? $llamada->proxima_llamada_at->format('Y-m-d H:i:s') : null,
            'origen'               => (string) $llamada->origen,
            'cargada_por_admin_id' => $llamada->cargada_por_admin_id === null ? null : (int) $llamada->cargada_por_admin_id,
            'cargada_por'          => $llamada->cargada_por ? ['id' => (int) $llamada->cargada_por->id, 'name' => (string) $llamada->cargada_por->name] : null,
            'transcripcion'        => $llamada->transcripcion,
            'created_at'           => $llamada->created_at ? $llamada->created_at->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * La tarjeta del lead con la forma del tablero (la que arma `PipelinePresenter`).
     *
     * @param PipelineOpportunity|null $tarjeta
     *
     * @return array<string, mixed>|null
     */
    public function tarjeta_a_json($tarjeta)
    {
        return $tarjeta === null ? null : app(PipelinePresenter::class)->opportunity($tarjeta->fresh());
    }

    /**
     * El bloque `LLAMADAS DEL EQUIPO (Juan)` del contexto de Martín: las últimas 5 llamadas con fecha, si atendió, resultado,
     * cotizado, notas (300 caracteres) y transcripción (1.500), y la instrucción de no repetir preguntas.
     *
     * @param Lead $lead
     *
     * @return string Cadena vacía si el lead no tiene llamadas.
     */
    public function bloque_para_agente(Lead $lead): string
    {
        $llamadas = LeadLlamada::query()
            ->where('lead_id', (int) $lead->id)
            ->orderByDesc('id')
            ->limit(self::LLAMADAS_PARA_MARTIN)
            ->get();

        if ($llamadas->isEmpty()) {
            return '';
        }

        $lineas = ['LLAMADAS DEL EQUIPO (Juan):'];
        foreach ($llamadas as $llamada) {
            $partes = [
                $llamada->created_at ? $llamada->created_at->format('d/m/Y H:i') : 's/f',
                $llamada->atendio ? 'atendió' : 'no atendió',
            ];
            if ($llamada->resultado_label() !== null) {
                $partes[] = 'Resultado: ' . $llamada->resultado_label();
            }
            if ((string) $llamada->cotizado !== '') {
                $partes[] = 'Cotizado: ' . $llamada->cotizado;
            }
            if ((string) $llamada->notas !== '') {
                $partes[] = 'Notas: ' . $this->recortar((string) $llamada->notas, self::NOTAS_PARA_MARTIN);
            }
            if ((string) $llamada->transcripcion !== '') {
                $partes[] = 'Transcripción: ' . $this->recortar((string) $llamada->transcripcion, self::TRANSCRIPCION_PARA_MARTIN);
            }
            $lineas[] = '- ' . implode(' · ', $partes);
        }
        $lineas[] = 'No le vuelvas a preguntar lo que ya está acá.';

        return implode("\n", $lineas);
    }

    /**
     * Recorta un texto a `$maximo` caracteres, agregando «…» si lo cortó.
     *
     * @param string $texto
     * @param int    $maximo
     *
     * @return string
     */
    public function recortar(string $texto, int $maximo): string
    {
        return mb_strlen($texto) > $maximo ? mb_substr($texto, 0, $maximo) . '…' : $texto;
    }

    // ------------------------------------------------------------------------------------------
    // Validación
    // ------------------------------------------------------------------------------------------

    /**
     * Valida el pedido y lo deja limpio. No toca la base.
     *
     * @param array<string, mixed> $datos
     * @param array<int, string>   $motivos_de_perdida Los `lost_reasons` del pipeline.
     *
     * @return array<string, mixed> atendio, resultado, motivo, cotizado, notas, proxima_llamada_at (Carbon|null), origen,
     *                              origen_clave, transcripcion.
     *
     * @throws PipelineRuleException
     */
    private function validar(array $datos, array $motivos_de_perdida): array
    {
        $errores = [];
        $ahora   = AppTime::now();

        $atendio = array_key_exists('atendio', $datos) && $datos['atendio'] !== null
            ? filter_var($datos['atendio'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        if ($atendio === null) {
            $errores['atendio'][] = 'Indicá si atendió.';
        }

        $origen = $this->texto($datos, 'origen');
        $origen = $origen === null ? LeadLlamada::ORIGEN_MANUAL : $origen;
        if (! in_array($origen, [LeadLlamada::ORIGEN_MANUAL, LeadLlamada::ORIGEN_AUDIO_DE_JUAN], true)) {
            $errores['origen'][] = 'El origen tiene que ser manual o audio_juan.';
        }

        $origen_clave = $this->texto($datos, 'origen_clave');
        if ($origen === LeadLlamada::ORIGEN_AUDIO_DE_JUAN && $origen_clave === null) {
            $errores['origen_clave'][] = 'Un audio de Juan necesita su clave (origen_clave).';
        }
        if ($origen_clave !== null && mb_strlen($origen_clave) > self::MAX_ORIGEN_CLAVE) {
            $errores['origen_clave'][] = 'La clave no puede tener más de ' . self::MAX_ORIGEN_CLAVE . ' caracteres.';
        }

        $notas         = $this->texto($datos, 'notas');
        $cotizado      = $this->texto($datos, 'cotizado');
        $transcripcion = $this->texto($datos, 'transcripcion');
        if ($notas !== null && mb_strlen($notas) > self::MAX_NOTAS) {
            $errores['notas'][] = 'Las notas no pueden tener más de ' . self::MAX_NOTAS . ' caracteres.';
        }
        if ($cotizado !== null && mb_strlen($cotizado) > self::MAX_CORTO) {
            $errores['cotizado'][] = 'Lo cotizado no puede tener más de ' . self::MAX_CORTO . ' caracteres.';
        }
        if ($transcripcion !== null && mb_strlen($transcripcion) > self::MAX_TRANSCRIPCION) {
            $errores['transcripcion'][] = 'La transcripción no puede tener más de ' . self::MAX_TRANSCRIPCION . ' caracteres.';
        }

        $resultado = null;
        $motivo    = null;
        $fecha     = null;
        $texto_de_la_fecha = $this->texto($datos, 'proxima_llamada_at');
        if ($texto_de_la_fecha !== null) {
            $fecha = PipelineFieldsService::parsear_fecha_de_la_spa($texto_de_la_fecha);
            if ($fecha === null) {
                $errores['proxima_llamada_at'][] = 'La fecha tiene que tener formato AAAA-MM-DD HH:MM.';
            }
        }

        if ($atendio === false) {
            /* Sin atender no hay resultado: se ignora lo que mande la pantalla. */
            if ($fecha === null && $texto_de_la_fecha === null) {
                $errores['proxima_llamada_at'][] = 'Indicá cuándo volver a llamar.';
            } elseif ($fecha !== null && $fecha->lte($ahora)) {
                $errores['proxima_llamada_at'][] = 'La próxima llamada tiene que ser futura.';
            }
        } elseif ($atendio === true) {
            $resultado = $this->texto($datos, 'resultado');
            if ($resultado === null || ! isset(LeadLlamada::RESULTADOS[$resultado])) {
                $errores['resultado'][] = 'Elegí cómo terminó la llamada.';
                $resultado = null;
            } elseif ($resultado === LeadLlamada::RESULTADO_VOLVER_A_LLAMAR) {
                if ($texto_de_la_fecha === null) {
                    $errores['proxima_llamada_at'][] = 'Indicá cuándo volver a llamar.';
                } elseif ($fecha !== null && $fecha->lte($ahora)) {
                    $errores['proxima_llamada_at'][] = 'La próxima llamada tiene que ser futura.';
                }
            } elseif ($resultado === LeadLlamada::RESULTADO_NO_LE_INTERESA) {
                $motivo = $this->texto($datos, 'motivo');
                if ($motivo === null || ! in_array($motivo, $motivos_de_perdida, true)) {
                    $errores['motivo'][] = 'Elegí el motivo de pérdida de la lista.';
                }
            }
        }

        if ($errores !== []) {
            throw PipelineRuleException::validacion($errores);
        }

        return [
            'atendio'            => (bool) $atendio,
            'resultado'          => $atendio ? $resultado : null,
            'motivo'             => $motivo,
            'cotizado'           => $cotizado,
            'notas'              => $notas,
            'proxima_llamada_at' => $fecha,
            'origen'             => $origen,
            'origen_clave'       => $origen_clave,
            'transcripcion'      => $transcripcion,
        ];
    }

    /**
     * Un valor de texto recortado de los datos del pedido (null si no vino o quedó vacío).
     *
     * @param array<string, mixed> $datos
     * @param string               $clave
     *
     * @return string|null
     */
    private function texto(array $datos, string $clave)
    {
        if (! array_key_exists($clave, $datos) || $datos[$clave] === null || ! is_scalar($datos[$clave])) {
            return null;
        }

        $texto = trim((string) $datos[$clave]);

        return $texto === '' ? null : $texto;
    }

    /**
     * Quién hizo la llamada: el usuario autenticado, salvo que sea un agente, que la carga EN NOMBRE de un vendedor
     * (`hecha_por_admin_id`, o el primero del equipo de ventas si no lo manda). Un humano que mande el campo es ignorado.
     *
     * @param Admin                $actor
     * @param array<string, mixed> $datos
     *
     * @return int
     *
     * @throws PipelineRuleException 422 si el elegido no es vendedor o no hay ninguno.
     */
    private function quien_hizo_la_llamada(Admin $actor, array $datos): int
    {
        if (! (bool) $actor->es_agente) {
            return (int) $actor->id;
        }

        $pedido = $this->texto($datos, 'hecha_por_admin_id');
        if ($pedido !== null) {
            $elegido = Admin::query()->whereKey((int) $pedido)->first();
            if ($elegido === null || ! ((bool) $elegido->solo_leads || (bool) $elegido->es_vendedor)) {
                throw PipelineRuleException::validacion(['hecha_por_admin_id' => ['El admin elegido no es un vendedor.']]);
            }

            return (int) $elegido->id;
        }

        /* Primero «Sólo Leads», después un vendedor que no sea closer y, por último, el closer (que `ventas:migrar-licencias` también marca
           como vendedor y suele ser más viejo que Juan). */
        $juan = AdminTaskAssignmentResolver::vendedor_de_juan(false);
        if ($juan !== null) {
            return (int) $juan->id;
        }

        throw PipelineRuleException::validacion(['hecha_por_admin_id' => ['No hay un vendedor para asignarle la llamada.']]);
    }

    // ------------------------------------------------------------------------------------------
    // Efectos
    // ------------------------------------------------------------------------------------------

    /**
     * El texto de la nota del historial: «Llamada: atendió · <resultado> · <notas> · Cotizado: <cotizado>», o «Llamada (por
     * audio de Juan): …» más «Transcripción: …» (recortada para que la nota no pase de 5.000 caracteres).
     *
     * @param LeadLlamada $llamada
     *
     * @return string
     */
    private function cuerpo_de_la_nota(LeadLlamada $llamada): string
    {
        $audio  = $llamada->origen === LeadLlamada::ORIGEN_AUDIO_DE_JUAN;
        $partes = [$llamada->atendio ? 'atendió' : 'no atendió'];

        if ($llamada->resultado_label() !== null) {
            $partes[] = $llamada->resultado_label();
        }
        if ((string) $llamada->notas !== '') {
            $partes[] = (string) $llamada->notas;
        }
        if ((string) $llamada->cotizado !== '') {
            $partes[] = 'Cotizado: ' . $llamada->cotizado;
        }

        $cuerpo = ($audio ? 'Llamada (por audio de Juan): ' : 'Llamada: ') . implode(' · ', $partes);

        if ($audio && (string) $llamada->transcripcion !== '') {
            $cuerpo .= ' · Transcripción: ' . $llamada->transcripcion;
        }

        return mb_strlen($cuerpo) > self::MAX_NOTAS ? mb_substr($cuerpo, 0, self::MAX_NOTAS) : $cuerpo;
    }

    /**
     * Mueve la tarjeta y aplica el efecto sobre el lead según el resultado (tabla de §2.1.3 del plan).
     *
     * @param Lead                 $lead
     * @param PipelineOpportunity  $tarjeta
     * @param array<string, mixed> $datos    Salida de `validar()`.
     * @param int                  $hecha_por
     *
     * @return PipelineOpportunity La tarjeta ya movida.
     */
    private function aplicar_el_resultado(Lead $lead, PipelineOpportunity $tarjeta, array $datos, int $hecha_por)
    {
        $ahora = AppTime::now();

        if (! $datos['atendio']) {
            return $this->colocar($tarjeta, TableroDeJuanService::ETAPA_VOLVER_A_LLAMAR, $datos['proxima_llamada_at'], 'Volver a llamar (no atendió)', [], $hecha_por);
        }

        switch ($datos['resultado']) {
            case LeadLlamada::RESULTADO_VOLVER_A_LLAMAR:
                return $this->colocar($tarjeta, TableroDeJuanService::ETAPA_VOLVER_A_LLAMAR, $datos['proxima_llamada_at'], 'Volver a llamar', [], $hecha_por);

            case LeadLlamada::RESULTADO_SIGUE_SOLO:
                return $this->colocar($tarjeta, TableroDeJuanService::ETAPA_EN_PRUEBA_SOLA, $this->dia_cinco_de_la_prueba($lead, $ahora), 'Llamada 2 (día 5)', [], $hecha_por);

            case LeadLlamada::RESULTADO_IMPLEMENTACION_ACORDADA:
                $fecha = $datos['proxima_llamada_at'] !== null ? $datos['proxima_llamada_at'] : $ahora->copy()->addDays(2)->setTime(10, 0, 0);
                $tarjeta = $this->colocar($tarjeta, TableroDeJuanService::ETAPA_IMPLEMENTACION_ACORDADA, $fecha, 'Coordinar la implementación', [], $hecha_por);
                $this->pasar_el_lead_a_implementacion_pedida($lead);

                return $tarjeta;

            case LeadLlamada::RESULTADO_PAGO_LA_MITAD:
                $tarjeta = $this->colocar($tarjeta, TableroDeJuanService::ETAPA_EN_IMPLEMENTACION, null, null, [], $hecha_por);
                $this->cambiar_el_estado_del_lead($lead, 'en_implementacion');

                /* E2: con prueba, el mismo servicio de la ficha (pausa, implementación, tarea del cobro); sin prueba, una tarea
                   para dar de alta e implementar. */
                if ($datos['cliente'] !== null) {
                    app(ImplementacionContratadaService::class)->fijar($datos['cliente'], 'mitad', $datos['paquetes'], \App\Models\Admin::query()->find($hecha_por));
                } else {
                    app(ImplementacionContratadaService::class)->crear_la_tarea_de_alta_sin_prueba($lead);
                }

                return $tarjeta;

            case LeadLlamada::RESULTADO_NO_LE_INTERESA:
                /* El funnel cierra el lead como `cerrado_perdido` al entrar a la etapa perdida. */
                return $this->colocar($tarjeta, TableroDeJuanService::ETAPA_NO_AVANZA, null, null, ['lost_reason' => $datos['motivo']], $hecha_por);

            case LeadLlamada::RESULTADO_YA_CONTRATO:
                $tarjeta = $this->colocar($tarjeta, TableroDeJuanService::ETAPA_CLIENTE, null, null, [], $hecha_por);
                $this->crear_la_tarea_del_contrato($lead);

                return $tarjeta;
        }

        return $tarjeta;
    }

    /**
     * Deja la tarjeta en la columna pedida con su próxima acción. Si ya está ahí (dos «no atendió» seguidos), sólo corre la
     * próxima acción, porque mover a la misma etapa es un error del CRM.
     *
     * @param PipelineOpportunity  $tarjeta
     * @param string               $clave     `stage_key` destino.
     * @param Carbon|null          $fecha     Próxima acción (null = sin próxima acción).
     * @param string|null          $nota      Nota de la próxima acción (sólo con fecha).
     * @param array<string, mixed> $extra     Datos extra del movimiento (`lost_reason`).
     * @param int                  $admin_id
     *
     * @return PipelineOpportunity
     */
    private function colocar(PipelineOpportunity $tarjeta, string $clave, $fecha, $nota, array $extra, int $admin_id)
    {
        $proxima = [
            'next_action_at'   => $fecha !== null ? $fecha->format('Y-m-d H:i') : null,
            'next_action_note' => $fecha !== null ? $nota : null,
        ];

        $etapa = PipelineStage::query()->where('pipeline_id', $tarjeta->pipeline_id)->where('stage_key', $clave)->firstOrFail();

        if ((int) $tarjeta->stage_id === (int) $etapa->id) {
            if ($etapa->is_open()) {
                $resultado = $this->oportunidades->actualizar($tarjeta, $proxima, $admin_id);

                return $resultado[0];
            }

            return $tarjeta;
        }

        $resultado = $this->oportunidades->mover($tarjeta, array_merge($proxima, $extra, ['stage_id' => (int) $etapa->id]), $admin_id);

        return $resultado[0];
    }

    /**
     * El día 5 de la prueba del lead a las 10:00; sin prueba, dentro de 4 días a las 10:00.
     *
     * @param Lead   $lead
     * @param Carbon $ahora
     *
     * @return Carbon
     */
    private function dia_cinco_de_la_prueba(Lead $lead, Carbon $ahora): Carbon
    {
        if ($lead->promoted_client_id !== null) {
            $cliente = \App\Models\Client::query()->find((int) $lead->promoted_client_id);
            if ($cliente !== null) {
                $datos = app(PruebaDelClienteService::class)->datos($cliente);
                if (! empty($datos['inicio_at'])) {
                    return Carbon::parse($datos['inicio_at'])->setTimezone($ahora->getTimezone())->addDays(4)->setTime(10, 0, 0);
                }
            }
        }

        return $ahora->copy()->addDays(4)->setTime(10, 0, 0);
    }

    /**
     * El lead pasa a `implementacion_pedida` (sólo desde los estados de la lista) y se crea la tarea del embudo, una sola vez.
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function pasar_el_lead_a_implementacion_pedida(Lead $lead)
    {
        if (! in_array((string) $lead->status, self::ESTADOS_QUE_PASAN_A_IMPLEMENTACION_PEDIDA, true)) {
            return;
        }

        $this->cambiar_el_estado_del_lead($lead, 'implementacion_pedida');
        /* Sin aviso: la tarea nace para cerrarse en este mismo pedido y Juan no puede abrir `/tasks`. */
        LeadTareaVendedorService::crear($lead->fresh(), LeadTareaVendedorService::MOTIVO_IMPLEMENTACION_PEDIDA, false);
    }

    /**
     * Cambia el estado del lead (con su alta en el catálogo si falta). No hace nada si ya está en ese estado.
     *
     * @param Lead   $lead
     * @param string $slug
     *
     * @return void
     */
    private function cambiar_el_estado_del_lead(Lead $lead, string $slug)
    {
        if ((string) $lead->status === $slug) {
            return;
        }

        LeadPipelineStatus::ensure_exists($slug);

        $lead->status = $slug;
        $lead->save();
    }

    /**
     * Crea la tarea para Lucas «Registrar el contrato de <empresa>» (Juan dice que el lead ya contrató).
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function crear_la_tarea_del_contrato(Lead $lead)
    {
        /* Idempotente: una sola tarea abierta por lead. */
        $abierta = AdminTask::query()->where('lead_id', (int) $lead->id)->where('created_via', 'tablero_ya_contrato')->where('is_done', false)->exists();
        if ($abierta) {
            return;
        }

        $empresa = trim((string) $lead->company_name) !== '' ? trim((string) $lead->company_name) : trim((string) $lead->contact_name);

        $tarea = AdminTask::create([
            'created_by_admin_id' => 0,
            'assigned_admin_id'   => null,
            'lead_id'             => (int) $lead->id,
            'title'               => 'Registrar el contrato de ' . $empresa . ' (Juan dice que ya contrató)',
            'content'             => 'Juan registró una llamada con «Ya contrató». Registrá el contrato en el admin y pasá al lead a «Cliente».',
            'todos'               => null,
            'is_done'             => false,
            'sort_order'          => 0,
            'created_via'         => 'tablero_ya_contrato',
        ]);

        /* Asignada a quien recibe las tareas de leads de Lucas (`for_lead_task`); sin nadie, queda para cualquiera. La tarea ya
           está creada: un error de la asignación o del aviso no la tira. */
        try {
            $ids = AdminTaskAssignmentResolver::for_lead_task();
            if (! empty($ids)) {
                $tarea->assigned_admins()->sync($ids);
                $tarea->assigned_admin_id = $ids[0];
                $tarea->save();
            }

            AdminTaskNotificationService::create_for_task($tarea);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('daily')->error('LeadLlamadaService: la tarea del contrato quedó creada pero la asignación o el aviso fallaron.', ['task_id' => $tarea->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cierra las tareas abiertas del embudo del lead (`created_via` que empieza con `embudo_`). No toca ninguna otra tarea.
     *
     * @param Lead $lead
     * @param int  $admin_id Quién las cierra (el que hizo la llamada).
     *
     * @return void
     */
    private function cerrar_las_tareas_del_embudo(Lead $lead, int $admin_id)
    {
        AdminTask::query()
            ->where('lead_id', (int) $lead->id)
            ->where('created_via', 'like', 'embudo\\_%')
            ->where('is_done', false)
            ->update(['is_done' => true, 'done_at' => AppTime::now(), 'done_by_admin_id' => $admin_id]);
    }

    /**
     * La tarjeta del lead para devolver en la respuesta: la abierta o, si no hay, la última.
     *
     * @param Lead $lead
     *
     * @return PipelineOpportunity|null
     */
    private function tarjeta_para_responder(Lead $lead)
    {
        $abierta = $this->tablero->tarjeta_abierta($lead);
        if ($abierta !== null) {
            return $abierta->fresh();
        }

        $pipeline = $this->tablero->pipeline();

        return $pipeline === null ? null : PipelineOpportunity::query()->where('pipeline_id', $pipeline->id)->where('lead_id', (int) $lead->id)->orderByDesc('id')->first();
    }
}
