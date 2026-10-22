<?php

namespace App\Console\Commands;

use App\Helpers\AppTime;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Services\MetaConversionesService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Informa a Meta las compras de los leads que YA son clientes (misión purchase-capi-al-promover,
 * 22/10/2026: «que además se informen los leads que ya son clientes»).
 *
 * Desde esta misión, cada promoción manda su `Purchase` sola ({@see \App\Observers\ConversionesMetaObserver}).
 * Este comando es para las de antes. 🔴 **Simula por defecto**: lista cada lead promovido, el canal,
 * la fecha de la promoción, la moneda y el valor, y qué haría. `--enviar` registra y encola, y pide
 * confirmación (`--confirmar` la da sin preguntar, para correrlo por SSH).
 *
 * 🔴 **La ventana de Meta.** La API de conversiones rechaza un `event_time` de más de 7 días atrás
 * (doc de parámetros del evento y de eventos offline, medido el 22/10/2026; un evento viejo hace
 * rechazar el lote entero). Los 62 días que menciona la doc son una recomendación para ventas en local
 * físico (`action_source: physical_store`): una venta cerrada por WhatsApp no lo es. Por eso:
 *
 *   - una promoción de los últimos {@see LeadEventoMeta::DIAS_DE_VIGENCIA} días se manda con SU fecha
 *     (el `created_at` del cliente, que nace en la promoción);
 *   - una más vieja, o sin fecha confiable, NO se manda con otra fecha: con `--enviar` queda
 *     `omitido` con {@see LeadEventoMeta::MOTIVO_FUERA_DE_VENTANA}, así nadie la manda después. Esas
 *     compras le llegan a Meta por la audiencia de clientes (`GET claude/audiencias?etapa=clientes`);
 *   - un lead que ya tiene su `Purchase` registrado (en cualquier estado) no se toca.
 *
 * Correrlo dos veces no duplica nada: el único (`lead_id`, `canal`, `evento`) y el chequeo previo.
 */
class InformarComprasMetaCommand extends Command
{
    /** Qué haría con cada lead. */
    const ACCION_MANDAR = 'mandar';
    const ACCION_OMITIR = 'omitir';
    const ACCION_NADA   = 'nada';

    /**
     * @var string
     */
    protected $signature = 'leads:meta-capi-compras
        {--enviar : Registra y encola (sin esto, solo simula)}
        {--confirmar : Da la confirmación de --enviar sin preguntar}
        {--probar= : Manda el body de la compra del lead como evento CompraDePrueba con este test_event_code, sin registrar nada (va con --lead)}
        {--lead= : El lead de la prueba}';

    /**
     * @var string
     */
    protected $description = 'Informa a Meta (CAPI) el Purchase de los leads ya promovidos a cliente: simula por defecto; solo manda las promociones de la última semana';

    /**
     * @param MetaConversionesService $service Inyectado por el contenedor.
     *
     * @return int
     */
    public function handle(MetaConversionesService $service): int
    {
        if ((string) $this->option('probar') !== '') {
            return $this->probar($service);
        }

        $enviar = (bool) $this->option('enviar');
        $ahora  = AppTime::now();
        $desde  = $ahora->copy()->subDays(LeadEventoMeta::DIAS_DE_VIGENCIA);

        $planes = [];
        Lead::query()
            ->whereNotNull('promoted_client_id')
            ->orderBy('id')
            ->chunkById(200, function ($leads) use ($service, $desde, $ahora, &$planes) {
                foreach ($leads as $lead) {
                    $planes[] = $this->plan_del_lead($service, $lead, $desde, $ahora);
                }
            });

        $this->line('Ventana de Meta: se mandan las promociones desde ' . $desde->format('d/m/Y H:i') . ' (' . LeadEventoMeta::DIAS_DE_VIGENCIA . ' días; Meta acepta hasta 7).');
        $this->table(
            ['Lead', 'Cliente', 'Canal', 'Promovido', 'Moneda', 'Valor', 'Tel/Mail', 'Purchase hoy', 'Acción', 'Por qué'],
            array_map(function (array $plan) {
                return [
                    $plan['lead_id'],
                    $plan['client_id'],
                    $plan['canal'] !== null ? $plan['canal'] : '-',
                    $plan['momento'] !== null ? $plan['momento']->format('d/m/Y H:i') : 'desconocido',
                    $plan['moneda'],
                    $plan['valor'],
                    $plan['contacto'],
                    $plan['existente'] !== null ? $plan['existente'] : '-',
                    $plan['accion'],
                    $plan['motivo'],
                ];
            }, $planes)
        );

        $cuenta = ['mandar' => 0, 'omitir' => 0, 'nada' => 0];
        foreach ($planes as $plan) {
            $cuenta[$plan['accion']]++;
        }
        $this->line('Promovidos: ' . count($planes) . ' · a mandar: ' . $cuenta['mandar'] . ' · fuera de la ventana: ' . $cuenta['omitir'] . ' · sin cambios: ' . $cuenta['nada'] . '.');

        foreach ([LeadEventoMeta::CANAL_WHATSAPP, LeadEventoMeta::CANAL_WEB] as $canal) {
            if (! MetaConversionesService::config_completa($canal)) {
                $this->warn('Canal ' . $canal . ': falta ' . MetaConversionesService::config_que_falta($canal) . '. Lo de ese canal quedaría sin_configurar.');
            }
        }

        if (! $enviar) {
            $this->info('Simulación: no se registró ni se mandó nada. Para hacerlo: --enviar.');

            return 0;
        }

        if ($cuenta['mandar'] + $cuenta['omitir'] === 0) {
            $this->info('No hay nada que registrar.');

            return 0;
        }

        if (! $this->option('confirmar')
            && ! $this->confirm('Se registran ' . $cuenta['mandar'] . ' Purchase para mandar y ' . $cuenta['omitir'] . ' omitidos. ¿Seguimos?', false)) {
            $this->warn('Cancelado: no se registró nada.');

            return 1;
        }

        $mandados = 0;
        $omitidos = 0;
        foreach ($planes as $plan) {
            if ($plan['accion'] === self::ACCION_NADA) {
                continue;
            }

            $lead = Lead::query()->find($plan['lead_id']);
            if ($lead === null) {
                continue;
            }

            if ($plan['accion'] === self::ACCION_MANDAR) {
                $mandados += $service->registrar_compra($lead, $plan['momento']) !== null ? 1 : 0;
            } else {
                $omitidos += $service->registrar_compra($lead, $plan['momento'] !== null ? $plan['momento'] : $ahora, LeadEventoMeta::ESTADO_OMITIDO, $plan['motivo_fila']) !== null ? 1 : 0;
            }
        }

        $this->info('Registrados: ' . $mandados . ' Purchase encolados y ' . $omitidos . ' omitidos. El estado de cada uno queda en lead_eventos_meta.');

        return 0;
    }

    /**
     * `--probar=TEST… --lead=N`: un Purchase de prueba del lead, para verlo en «Probar eventos» de
     * Events Manager. No registra nada. Muestra el body (los datos de contacto ya van hasheados) y
     * la respuesta de Meta.
     *
     * @param MetaConversionesService $service
     *
     * @return int
     */
    private function probar(MetaConversionesService $service): int
    {
        $lead = Lead::query()->find((int) $this->option('lead'));
        if ($lead === null) {
            $this->error('Falta --lead con el id de un lead que exista.');

            return 1;
        }

        $resultado = $service->enviar_prueba($lead, (string) $this->option('probar'));
        if ($resultado['payload'] !== null) {
            $this->line(json_encode($resultado['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $this->line('HTTP ' . $resultado['status'] . ': ' . json_encode($resultado['respuesta'], JSON_UNESCAPED_UNICODE));

        if ($resultado['error'] !== null) {
            $this->error($resultado['error']);

            return 1;
        }

        $this->info('Evento de prueba «' . MetaConversionesService::EVENTO_DE_PRUEBA . '» mandado (no como Purchase: un evento con código de prueba cuenta para Meta). Miralo en Events Manager → Probar eventos.');

        return 0;
    }

    /**
     * Qué se haría con un lead promovido, y con qué datos.
     *
     * @param MetaConversionesService $service
     * @param Lead                    $lead
     * @param Carbon                  $desde
     * @param Carbon                  $ahora
     *
     * @return array<string, mixed>
     */
    private function plan_del_lead(MetaConversionesService $service, Lead $lead, Carbon $desde, Carbon $ahora): array
    {
        $canal   = MetaConversionesService::canal_de_la_compra($lead);
        $momento = $this->momento_de_la_promocion($lead);

        $existente = LeadEventoMeta::query()
            ->where('lead_id', (int) $lead->id)
            ->where('evento', LeadEventoMeta::EVENTO_PURCHASE)
            ->orderBy('id')
            ->first();

        /* Lo mismo que viajaría, sin mandarlo: moneda y valor salen del armado real del payload. */
        $muestra = new LeadEventoMeta([
            'lead_id'    => (int) $lead->id,
            'canal'      => $canal !== null ? $canal : LeadEventoMeta::CANAL_WEB,
            'evento'     => LeadEventoMeta::EVENTO_PURCHASE,
            'event_id'   => MetaConversionesService::event_id_para((int) $lead->id, LeadEventoMeta::EVENTO_PURCHASE, $canal !== null ? $canal : LeadEventoMeta::CANAL_WEB),
            'event_time' => $momento !== null ? $momento : $ahora,
        ]);
        $datos       = $service->armar_payload($muestra, $lead)['data'][0];
        $user_data   = isset($datos['user_data']) ? $datos['user_data'] : [];
        $tiene_ph    = ! empty($user_data['ph']);
        $tiene_em    = ! empty($user_data['em']);
        $tiene_clave = $canal === LeadEventoMeta::CANAL_WHATSAPP ? ! empty($user_data['ctwa_clid']) : ($tiene_ph || $tiene_em);

        $plan = [
            'lead_id'   => (int) $lead->id,
            'client_id' => (int) $lead->promoted_client_id,
            'canal'     => $canal,
            'momento'   => $momento,
            'moneda'    => $datos['custom_data']['currency'],
            'valor'     => $datos['custom_data']['value'],
            'contacto'  => $canal === LeadEventoMeta::CANAL_WHATSAPP
                ? 'ctwa_clid'
                : ($tiene_ph ? 'tel' : '-') . '/' . ($tiene_em ? 'mail' : '-') . (! empty($user_data['fbc']) ? '/fbc' : ''),
            'existente' => $existente !== null ? $existente->canal . ':' . $existente->estado : null,
            'accion'    => self::ACCION_NADA,
            'motivo'    => '',
            /* Lo que queda en `ultimo_error` de un omitido. */
            'motivo_fila' => LeadEventoMeta::MOTIVO_FUERA_DE_VENTANA,
        ];

        if ($existente !== null) {
            $plan['motivo'] = 'ya tiene su Purchase registrado';

            return $plan;
        }

        if ($momento === null || $momento->lt($desde) || $momento->gt($ahora)) {
            $plan['accion'] = self::ACCION_OMITIR;
            $plan['motivo'] = $momento === null ? 'fecha de promoción desconocida' : 'promovido hace más de ' . LeadEventoMeta::DIAS_DE_VIGENCIA . ' días';

            return $plan;
        }

        if (! $tiene_clave) {
            $plan['accion']      = self::ACCION_OMITIR;
            $plan['motivo']      = 'sin dato que Meta pueda cruzar';
            $plan['motivo_fila'] = 'Sin teléfono, mail ni ctwa_clid: Meta no tiene con qué cruzar la compra.';

            return $plan;
        }

        $plan['accion'] = self::ACCION_MANDAR;
        $plan['motivo'] = 'dentro de la ventana, con su fecha real'
            . ($plan['valor'] === 0 ? ' (ojo: valor 0, el contrato no tiene precio legible)' : '');

        return $plan;
    }

    /**
     * Cuándo se promovió: el `created_at` del cliente, que `RunUserSetupService` crea en la misma
     * promoción. Si el cliente no existe o es anterior al lead (se vinculó uno que ya estaba), la
     * fecha no se conoce y no se inventa.
     *
     * @param Lead $lead
     *
     * @return Carbon|null
     */
    private function momento_de_la_promocion(Lead $lead): ?Carbon
    {
        $cliente = Client::query()->find((int) $lead->promoted_client_id);
        if ($cliente === null || $cliente->created_at === null) {
            return null;
        }

        $creado = Carbon::instance($cliente->created_at);
        if ($lead->created_at !== null && $creado->lt($lead->created_at)) {
            return null;
        }

        return $creado;
    }
}
