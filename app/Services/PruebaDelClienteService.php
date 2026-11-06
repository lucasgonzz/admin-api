<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Client;
use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use App\Services\Prueba\PruebaCicloService;
use App\Services\Prueba\PruebaVencimientoService;
use Illuminate\Support\Facades\Log;

/**
 * Todo lo que el admin necesita saber de un negocio en PRUEBA GRATUITA (programa «Prueba gratuita de 7
 * días», misión cruzada prueba-martin-importacion, 3/11/2026): si un cliente está en prueba, cuál es su
 * lead, cuántos días le quedan y en qué hito va.
 *
 * Las columnas que lo definen son de la misión M2 (prueba-alta-instancia): `clients.estado` (`prueba`,
 * `prueba_vencida`, `prueba_borrada`; ver `PruebaCicloService`), `clients.trial_days`, `clients.trial_ends_at`
 * y `leads.trial_days`. «En prueba» acá es lo mismo que para M2: en curso O vencida sin contratar
 * (`PruebaCicloService::ESTADOS_EN_PRUEBA`); una prueba vencida sigue siendo del programa (Martín la atiende,
 * Tomás la llama), lo que no recibe más son empujones.
 *
 * Los hitos salen de DOS fuentes, en este orden: la instancia del negocio (C3, `GET
 * admin-sync/prueba/estado`, por el puente del admin con la clave del cliente) y, si esa instancia no
 * tiene la ruta todavía (404) o no responde, los eventos `prueba.*` que la instancia reportó por C2 y
 * quedaron en `demo_eventos_recibidos`. El resultado dice de cuál vino (`fuente`).
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados ni union types.
 */
class PruebaDelClienteService
{
    /** `clients.estado` de un negocio con la prueba en curso (M2, `PruebaCicloService::CLIENTE_PRUEBA`). */
    const ESTADO_PRUEBA = PruebaCicloService::CLIENTE_PRUEBA;

    /** `leads.status` del lead en prueba (M2). Por slug, con fallback: si no existe en el catálogo, no importa. */
    const LEAD_STATUS_PRUEBA = 'prueba_activa';

    /** Días de prueba si el cliente no tiene `trial_days`. */
    const DIAS_POR_DEFECTO = 7;

    /** Día de la prueba (desde el inicio) en el que se le avisa a Tomás que la prueba está por vencer. */
    const DIA_DEL_AVISO_A_TOMAS = 5;

    /** Prefijo de los eventos de C2 que son hitos de la prueba (`prueba.ingreso`, `prueba.venta`…). */
    const PREFIJO_EVENTO = 'prueba.';

    /**
     * Los hitos de la prueba, en el orden en que se le sugieren al negocio, con su rótulo. La clave es el
     * `tipo` de C2 (`prueba.<tipo>`) y de C3 (`hitos.<tipo>`), y el sufijo de la plantilla
     * `cc_prueba_paso_<tipo>` (PruebaPlantillasService).
     *
     * @var array<string, string>
     */
    const HITOS = [
        'ingreso'         => 'Entró al sistema',
        'importo'         => 'Importó su información',
        'articulo_creado' => 'Creó un artículo',
        'venta'           => 'Hizo una venta',
        'cliente_creado'  => 'Cargó un cliente',
        'pago_cc'         => 'Registró un pago de cuenta corriente',
        'compra'          => 'Cargó una compra',
        'gasto'           => 'Cargó un gasto',
        'tienda_pedido'   => 'Recibió un pedido de la tienda',
        'agente'          => 'Habló con su agente personal',
    ];

    /** El hito de C2 que cierra la prueba. No es un paso: es el fin. */
    const HITO_VENCIDA = 'vencida';

    /** Ruta de C3 relativa a `admin-sync`, tal como la lee la lista blanca del puente. */
    const RUTA_ESTADO_REMOTO = '/prueba/estado';

    /** @var ClientAdminSyncPuenteService */
    protected $puente;

    /**
     * @param ClientAdminSyncPuenteService|null $puente Inyectable para las pruebas.
     */
    public function __construct(?ClientAdminSyncPuenteService $puente = null)
    {
        $this->puente = $puente === null ? new ClientAdminSyncPuenteService() : $puente;
    }

    /* ------------------------------------------------------------------------------------------
     | Quién está en prueba
     |----------------------------------------------------------------------------------------- */

    /**
     * Si el cliente es un negocio en prueba gratuita (en curso o vencida sin contratar, como para M2).
     *
     * @param Client|null $client
     *
     * @return bool
     */
    public function es_cliente_en_prueba(?Client $client): bool
    {
        return PruebaCicloService::esta_en_prueba($client);
    }

    /**
     * Todos los clientes en prueba (en curso o vencida), activos.
     *
     * @return Collection<int, Client>
     */
    public function clientes_en_prueba(): Collection
    {
        return Client::query()
            ->whereIn('estado', PruebaCicloService::ESTADOS_EN_PRUEBA)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * El cliente en prueba de un lead: por `promoted_client_id` o, si M2 no lo vinculó así, por teléfono
     * contra los clientes en prueba.
     *
     * @param Lead $lead
     *
     * @return Client|null
     */
    public function cliente_del_lead(Lead $lead): ?Client
    {
        if (! empty($lead->promoted_client_id)) {
            $client = Client::find((int) $lead->promoted_client_id);
            if ($client !== null && $this->es_cliente_en_prueba($client)) {
                return $client;
            }
        }

        $telefono = WhatsappNormalizer::normalize((string) ($lead->phone ?? ''));
        if ($telefono === '') {
            return null;
        }

        foreach ($this->clientes_en_prueba() as $client) {
            foreach ([(string) $client->phone, (string) $client->getAttribute('asistente_phone')] as $candidato) {
                if (trim($candidato) !== '' && WhatsappNormalizer::normalize($candidato) === $telefono) {
                    return $client;
                }
            }
        }

        return null;
    }

    /**
     * El lead de un cliente en prueba: el promovido a este cliente o, si no, el lead en `prueba_activa`
     * con el mismo teléfono. Null si no hay.
     *
     * @param Client $client
     *
     * @return Lead|null
     */
    public function lead_del_cliente(Client $client): ?Lead
    {
        $promovido = Lead::query()->where('promoted_client_id', (int) $client->id)->orderByDesc('id')->first();
        if ($promovido !== null) {
            return $promovido;
        }

        $telefono = WhatsappNormalizer::normalize((string) $client->phone);
        if ($telefono === '') {
            return null;
        }

        $candidatos = Lead::query()
            ->where('status', self::LEAD_STATUS_PRUEBA)
            ->whereNotNull('phone')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'phone']);

        foreach ($candidatos as $candidato) {
            if (WhatsappNormalizer::normalize((string) $candidato->phone) === $telefono) {
                return Lead::find((int) $candidato->id);
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------------------------------
     | Días
     |----------------------------------------------------------------------------------------- */

    /**
     * Los días de la prueba del cliente: cuántos son, cuándo vence, cuántos quedan, en qué día va.
     *
     * `inicio_at` = `vence_at - dias`: no hay columna de inicio y así el día 5 se calcula igual aunque Lucas
     * le haya estirado la prueba desde el admin (C3).
     *
     * @param Client $client
     *
     * @return array{dias: int, vence_at: string|null, inicio_at: string|null, dias_restantes: int|null, dia_actual: int|null, vencida: bool}
     */
    public function datos(Client $client): array
    {
        $dias = self::DIAS_POR_DEFECTO;

        $crudo = $client->trial_days;
        if (is_numeric($crudo) && (int) $crudo > 0) {
            $dias = (int) $crudo;
        }

        /* M2 la deja `prueba_vencida` cuando vence (por el cron o por el `prueba.vencida` de la instancia): vale
           aunque la fecha no diga lo mismo (por ejemplo, si el admin no tiene `trial_ends_at`). */
        $vencida_por_estado = (string) $client->estado === PruebaCicloService::CLIENTE_PRUEBA_VENCIDA;

        $vence = $this->a_carbon($client->trial_ends_at);

        if ($vence === null) {
            return [
                'dias'           => $dias,
                'vence_at'       => null,
                'inicio_at'      => null,
                'dias_restantes' => $vencida_por_estado ? 0 : null,
                'dia_actual'     => null,
                'vencida'        => $vencida_por_estado,
            ];
        }

        $ahora  = Carbon::now();
        $inicio = $vence->copy()->subDays($dias);

        /* Días que quedan, redondeados para arriba: a las 20 h de un día que vence mañana a las 14 h le queda 1. */
        $restantes = $vence->isPast() ? 0 : (int) ceil($ahora->diffInSeconds($vence, false) / 86400);

        /* En qué día va, desde 1: el primer día de la prueba es el día 1. */
        $dia_actual = (int) floor($inicio->diffInSeconds($ahora, false) / 86400) + 1;
        if ($dia_actual < 1) {
            $dia_actual = 1;
        }

        return [
            'dias'           => $dias,
            'vence_at'       => $vence->toIso8601String(),
            'inicio_at'      => $inicio->toIso8601String(),
            'dias_restantes' => $vencida_por_estado ? 0 : $restantes,
            'dia_actual'     => $dia_actual,
            'vencida'        => $vence->isPast() || $vencida_por_estado,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Hitos
     |----------------------------------------------------------------------------------------- */

    /**
     * El estado de la prueba según la INSTANCIA del negocio (C3). `disponible = false` si el cliente no
     * tiene la ruta todavía (404), no tiene clave o no respondió: ahí vale el fallback local.
     *
     * @param Client $client
     *
     * @return array{disponible: bool, motivo: string|null, vence_at: string|null, vencida: bool|null, semilla_presente: bool|null, hitos: array<string, string|null>, imagenes: array|null}
     */
    public function estado_remoto(Client $client): array
    {
        $vacio = ['disponible' => false, 'motivo' => null, 'vence_at' => null, 'vencida' => null, 'semilla_presente' => null, 'hitos' => [], 'imagenes' => null];

        try {
            $resultado = $this->puente->reenviar($client, 'GET', self::RUTA_ESTADO_REMOTO, null);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaDelClienteService: no se pudo consultar el estado de la prueba en la instancia.', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);

            return array_merge($vacio, ['motivo' => 'excepcion']);
        }

        if (! isset($resultado['estado']) || $resultado['estado'] !== ClientAdminSyncPuenteService::ESTADO_RESPONDIO) {
            return array_merge($vacio, ['motivo' => isset($resultado['estado']) ? (string) $resultado['estado'] : 'sin_respuesta']);
        }

        $status = isset($resultado['status']) ? (int) $resultado['status'] : 0;

        /* El puente decodifica SIN assoc (un `{}` vuelve como `{}`): el cuerpo llega como stdClass. Acá se lee como array. */
        $cuerpo = isset($resultado['cuerpo']) ? $resultado['cuerpo'] : null;
        if (is_object($cuerpo)) {
            $cuerpo = json_decode((string) json_encode($cuerpo), true);
        }

        if ($status !== 200 || ! is_array($cuerpo)) {
            return array_merge($vacio, ['motivo' => $status === 404 ? 'version_vieja' : 'http_' . $status]);
        }
        $hitos  = [];
        if (isset($cuerpo['hitos']) && is_array($cuerpo['hitos'])) {
            foreach ($cuerpo['hitos'] as $tipo => $momento) {
                $tipo = (string) $tipo;
                if (strpos($tipo, self::PREFIJO_EVENTO) === 0) {
                    $tipo = substr($tipo, strlen(self::PREFIJO_EVENTO));
                }
                $hitos[$tipo] = is_string($momento) && trim($momento) !== '' ? trim($momento) : null;
            }
        }

        return [
            'disponible'       => true,
            'motivo'           => null,
            'vence_at'         => isset($cuerpo['vence_at']) && is_string($cuerpo['vence_at']) ? $cuerpo['vence_at'] : null,
            'vencida'          => isset($cuerpo['vencida']) ? (bool) $cuerpo['vencida'] : null,
            'semilla_presente' => isset($cuerpo['semilla_presente']) ? (bool) $cuerpo['semilla_presente'] : null,
            'hitos'            => $hitos,
            'imagenes'         => PruebaVencimientoService::leer_cupo_imagenes($cuerpo),
        ];
    }

    /**
     * Los hitos según los eventos `prueba.*` que llegaron por C2 (la primera vez de cada uno), para cuando
     * la instancia no contesta C3.
     *
     * @param Lead|null $lead
     *
     * @return array<string, string|null>
     */
    public function hitos_locales(?Lead $lead): array
    {
        $hitos = [];
        foreach (array_keys(self::HITOS) as $tipo) {
            $hitos[$tipo] = null;
        }

        if ($lead === null) {
            return $hitos;
        }

        $eventos = DemoEventoRecibido::query()
            ->where('lead_id', (int) $lead->id)
            ->where('nombre', 'like', self::PREFIJO_EVENTO . '%')
            ->orderBy('id')
            ->get(['nombre', 'ocurrido_at', 'created_at']);

        foreach ($eventos as $evento) {
            $tipo = substr((string) $evento->nombre, strlen(self::PREFIJO_EVENTO));
            if ($tipo === false || $tipo === '') {
                continue;
            }
            if (isset($hitos[$tipo]) && $hitos[$tipo] !== null) {
                continue;
            }
            $momento = $evento->ocurrido_at !== null ? $evento->ocurrido_at : $evento->created_at;
            $hitos[$tipo] = $momento !== null ? Carbon::parse($momento)->toIso8601String() : null;
        }

        return $hitos;
    }

    /**
     * Los hitos de la prueba: C3 si la instancia contesta, y si no los locales. Siempre con TODOS los tipos
     * del catálogo (los que no pasaron, en null), en el orden del catálogo.
     *
     * @param Client    $client
     * @param Lead|null $lead
     * @param bool      $consultar_instancia false = solo locales (para no pegarle al cliente, p. ej. en un listado).
     *
     * @return array{fuente: string, hitos: array<string, string|null>, semilla_presente: bool|null, vence_at: string|null, vencida: bool|null, imagenes: array|null, cupo_imagenes_estado: string|null}
     */
    public function hitos(Client $client, ?Lead $lead, bool $consultar_instancia = true): array
    {
        $remoto = $consultar_instancia ? $this->estado_remoto($client) : null;
        $base   = $this->hitos_locales($lead);

        if ($remoto !== null && $remoto['disponible'] === true) {
            foreach ($remoto['hitos'] as $tipo => $momento) {
                if ($momento !== null || ! isset($base[$tipo])) {
                    $base[$tipo] = $momento;
                }
            }

            return [
                'fuente'           => 'instancia',
                'hitos'            => $base,
                'semilla_presente' => $remoto['semilla_presente'],
                'vence_at'         => $remoto['vence_at'],
                'vencida'          => $remoto['vencida'],
                'imagenes'         => $remoto['imagenes'],
                'cupo_imagenes_estado' => $remoto['imagenes'] === null ? 'version_vieja' : 'ok',
            ];
        }

        return [
            'fuente'           => 'admin',
            'hitos'            => $base,
            'semilla_presente' => null,
            'vence_at'         => null,
            'vencida'          => null,
            'imagenes'         => null,
            'cupo_imagenes_estado' => $remoto['motivo'] ?? null,
        ];
    }

    /**
     * El primer hito del catálogo que todavía no pasó, o null si pasaron todos.
     *
     * @param array<string, string|null> $hitos
     *
     * @return string|null
     */
    public static function proximo_hito_faltante(array $hitos): ?string
    {
        foreach (array_keys(self::HITOS) as $tipo) {
            if (! isset($hitos[$tipo]) || $hitos[$tipo] === null) {
                return $tipo;
            }
        }

        return null;
    }

    /**
     * Rótulo de un hito.
     *
     * @param string|null $tipo
     *
     * @return string|null
     */
    public static function label_del_hito(?string $tipo): ?string
    {
        if ($tipo === null) {
            return null;
        }

        return isset(self::HITOS[$tipo]) ? self::HITOS[$tipo] : ucfirst(str_replace('_', ' ', $tipo));
    }

    /* ------------------------------------------------------------------------------------------
     | El resumen que ven Martín (contexto) y la pestaña
     |----------------------------------------------------------------------------------------- */

    /**
     * Todo junto: días, hitos, próximo paso y los envíos de WhatsApp de la prueba.
     *
     * @param Client    $client
     * @param Lead|null $lead
     * @param bool      $consultar_instancia
     *
     * @return array<string, mixed>
     */
    public function resumen(Client $client, ?Lead $lead, bool $consultar_instancia = true): array
    {
        $datos = $this->datos($client);
        $hitos = $this->hitos($client, $lead, $consultar_instancia);

        /* La instancia sabe mejor si venció (C3): si contesta, manda ella. */
        $vencida = $hitos['vencida'] !== null ? (bool) $hitos['vencida'] : (bool) $datos['vencida'];

        $proximo = self::proximo_hito_faltante($hitos['hitos']);

        $lista = [];
        foreach ($hitos['hitos'] as $tipo => $momento) {
            $lista[] = ['tipo' => $tipo, 'label' => self::label_del_hito($tipo), 'at' => $momento];
        }

        $envios = PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $bienvenida = $envios->first(function ($envio) {
            return in_array($envio->plantilla, PruebaPlantillasService::BIENVENIDAS, true) && $envio->estado === PruebaWhatsappEnvio::ESTADO_ENVIADO;
        });

        /* La bienvenida que todavía no salió porque Meta no aprobó la plantilla: va a salir sola; Martín no se presenta. */
        $bienvenida_en_cola = $bienvenida === null && $envios->first(function ($envio) {
            return in_array($envio->plantilla, PruebaPlantillasService::BIENVENIDAS, true) && $envio->estado === PruebaWhatsappEnvio::ESTADO_EN_COLA;
        }) !== null;

        $ultimo_empujon = $envios->first(function ($envio) {
            return strpos((string) $envio->plantilla, PruebaPlantillasService::PASO_PREFIJO) === 0 && $envio->estado === PruebaWhatsappEnvio::ESTADO_ENVIADO;
        });

        return [
            'es_prueba'           => true,
            'estado'              => (string) $client->estado,
            'client_id'           => (int) $client->id,
            'lead_id'             => $lead !== null ? (int) $lead->id : null,
            'dias'                => $datos['dias'],
            'vence_at'            => $datos['vence_at'] !== null ? $datos['vence_at'] : $hitos['vence_at'],
            'inicio_at'           => $datos['inicio_at'],
            'dias_restantes'      => $datos['dias_restantes'],
            'dia_actual'          => $datos['dia_actual'],
            'vencida'             => $vencida,
            'semilla_presente'    => $hitos['semilla_presente'],
            'hitos_fuente'        => $hitos['fuente'],
            'hitos'               => $hitos['hitos'],
            'hitos_lista'         => $lista,
            'imagenes'            => $hitos['imagenes'],
            'cupo_imagenes_estado' => $hitos['cupo_imagenes_estado'],
            'proximo_hito'        => $proximo,
            'proximo_hito_label'  => self::label_del_hito($proximo),
            'bienvenida_enviada'  => $bienvenida !== null,
            'bienvenida_en_cola'  => $bienvenida_en_cola,
            'bienvenida_at'       => $bienvenida !== null && $bienvenida->enviado_at !== null ? $bienvenida->enviado_at->toIso8601String() : null,
            'ultimo_empujon_at'   => $ultimo_empujon !== null && $ultimo_empujon->enviado_at !== null ? $ultimo_empujon->enviado_at->toIso8601String() : null,
            'envios'              => $envios->map(function ($envio) {
                return [
                    'id'                  => (int) $envio->id,
                    'plantilla'           => $envio->plantilla,
                    'hito'                => $envio->hito,
                    'estado'              => $envio->estado,
                    'detalle'             => $envio->detalle,
                    'whatsapp_message_id' => $envio->whatsapp_message_id,
                    'enviado_at'          => $envio->enviado_at !== null ? $envio->enviado_at->toIso8601String() : null,
                    'created_at'          => $envio->created_at !== null ? $envio->created_at->toIso8601String() : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Una fecha cualquiera (string, Carbon, DateTime) a Carbon, o null si no se puede leer.
     *
     * @param mixed $valor
     *
     * @return Carbon|null
     */
    protected function a_carbon($valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return Carbon::instance($valor);
        }

        try {
            return Carbon::parse((string) $valor);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
