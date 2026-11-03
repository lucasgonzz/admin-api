<?php

namespace App\Services\Prueba;

use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El ciclo de vida de una prueba después del alta (decisiones D4, D6 y D7 del plan de
 * `prueba-alta-instancia`, 3/11/2026): vencer (dormir), despertar, contratar y borrar.
 *
 *  - Vencer = `clients.estado = prueba_vencida` + `supervisorctl stop` de los dos programas de la
 *    instancia (`prueba-dormir.sh`). El negocio ve la pantalla de vencimiento del ERP (M3), que no
 *    necesita worker.
 *  - Despertar = `supervisorctl start`; al contratar, o a mano desde el panel.
 *  - Contratar = el cliente pasa a `activo`, la instancia a `cliente`, el ERP se entera de que ya no
 *    vence (C3 con `vence_at: null`) y se despierta si dormía. NO se reinstala nada: la instancia de la
 *    prueba ES la instalación definitiva.
 *  - Borrar = respaldo + baja de los 4 sitios, la base y el supervisor (`prueba-borrar.sh`), los A
 *    records por la API (`PruebaDnsService`), `clients.estado = prueba_borrada` e `is_active = false`,
 *    lead `cerrado_perdido`.
 *
 * Todo lo del VPS pasa por `PruebaVpsService`; nada de acá abre SSH por su cuenta.
 */
class PruebaCicloService
{
    const CLIENTE_ACTIVO         = 'activo';
    const CLIENTE_PRUEBA         = 'prueba';
    const CLIENTE_PRUEBA_VENCIDA = 'prueba_vencida';
    const CLIENTE_PRUEBA_BORRADA = 'prueba_borrada';

    /** Estados del cliente en los que la prueba está EN CURSO o VENCIDA (todavía se puede contratar). */
    const ESTADOS_EN_PRUEBA = [self::CLIENTE_PRUEBA, self::CLIENTE_PRUEBA_VENCIDA];

    /** @var PruebaVpsService */
    private $vps;

    public function __construct(PruebaVpsService $vps)
    {
        $this->vps = $vps;
    }

    /** ¿El cliente es (o fue) una prueba sin contratar? */
    public static function esta_en_prueba(?Client $client): bool
    {
        return $client !== null && in_array((string) $client->estado, self::ESTADOS_EN_PRUEBA, true);
    }

    /** La instancia que ocupa un cliente, si la hay (la más nueva no borrada). */
    public static function instancia_de(Client $client): ?TrialInstance
    {
        return TrialInstance::where('client_id', $client->id)
            ->whereNotIn('estado', [TrialInstance::ESTADO_BORRADA])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Vence la prueba de un cliente: estado `prueba_vencida` y la instancia dormida.
     *
     * Idempotente: un cliente ya vencido o una instancia ya dormida no vuelven a tocar el VPS.
     */
    public function vencer(Client $client): void
    {
        if ((string) $client->estado !== self::CLIENTE_PRUEBA && (string) $client->estado !== self::CLIENTE_PRUEBA_VENCIDA) {
            return;
        }

        if ((string) $client->estado === self::CLIENTE_PRUEBA) {
            $client->estado = self::CLIENTE_PRUEBA_VENCIDA;
            $client->save();
        }

        $instancia = self::instancia_de($client);
        if ($instancia !== null) {
            $this->dormir($instancia);
        }

        Log::info('PruebaCicloService: prueba vencida.', ['client_id' => $client->id]);
    }

    /** `prueba.vencida` del canal de hitos: vence al cliente del lead. */
    public function vencer_por_lead(Lead $lead): void
    {
        $client = $lead->promoted_client_id ? Client::find($lead->promoted_client_id) : null;
        if ($client === null) {
            return;
        }

        $this->vencer($client);
    }

    /** Para el worker y el scheduler de la instancia. */
    public function dormir(TrialInstance $instancia): void
    {
        if ($instancia->esta_dormida()) {
            return;
        }
        if (! in_array($instancia->estado, TrialInstance::ESTADOS_OCUPADA, true)) {
            throw new \RuntimeException('Solo se duerme una instancia ocupada; ' . $instancia->nombre . ' está ' . $instancia->estado . '.');
        }

        $this->vps->prueba_dormir($instancia->nombre);

        $instancia->dormida_at = Carbon::now();
        $instancia->save();
    }

    /** Vuelve a levantar el worker y el scheduler de la instancia. */
    public function despertar(TrialInstance $instancia): void
    {
        if (! $instancia->esta_dormida()) {
            return;
        }

        $this->vps->prueba_despertar($instancia->nombre);

        $instancia->dormida_at = null;
        $instancia->save();
    }

    /**
     * Contrató (D6): el cliente deja de ser una prueba y la instancia queda como su sistema definitivo.
     *
     * Lo llama `PromoteLeadToClientService` cuando el lead promovido ya tiene un cliente en prueba.
     *
     * @return array<string, mixed> El resultado de C3 (`estado` ok | sin_ruta | ...), para el informe.
     */
    public function contratar(Client $client): array
    {
        $client->estado        = self::CLIENTE_ACTIVO;
        $client->trial_ends_at = null;
        $client->is_active     = true;
        $client->save();

        $instancia = self::instancia_de($client);
        if ($instancia !== null) {
            if ($instancia->esta_dormida()) {
                $this->despertar($instancia);
            }
            $instancia->estado = TrialInstance::ESTADO_CLIENTE;
            $instancia->save();
        }

        /* C3 con null: el ERP saca el vencimiento. Un empresa viejo contesta 404 y se tolera. */
        $resultado = app(PruebaVencimientoService::class)->enviar_vencimiento($client, null);

        Log::info('PruebaCicloService: cliente en prueba contratado.', [
            'client_id' => $client->id,
            'instancia' => $instancia ? $instancia->nombre : null,
            'c3'        => $resultado['estado'],
        ]);

        return $resultado;
    }

    /**
     * Cambia los días de la prueba de un cliente: `trial_days`, `trial_ends_at` (desde el inicio de la
     * prueba, que es `created_at` del cliente) y C3 hacia el ERP.
     *
     * @return array<string, mixed> El resultado de C3.
     */
    public function cambiar_dias(Client $client, int $dias): array
    {
        if ($dias < 1 || $dias > PruebaSettings::MAX_DIAS) {
            throw new \InvalidArgumentException('Los días de prueba van de 1 a ' . PruebaSettings::MAX_DIAS . '.');
        }
        if (! self::esta_en_prueba($client)) {
            throw new \RuntimeException('El cliente no está en prueba.');
        }

        $inicio   = $client->created_at instanceof Carbon ? $client->created_at->copy() : Carbon::now();
        $vence_at = $inicio->addDays($dias);

        $client->trial_days    = $dias;
        $client->trial_ends_at = $vence_at;

        /* Si con los días nuevos todavía no venció, vuelve a `prueba` y se despierta. */
        if ($vence_at->isFuture() && (string) $client->estado === self::CLIENTE_PRUEBA_VENCIDA) {
            $client->estado = self::CLIENTE_PRUEBA;
        }
        $client->save();

        $instancia = self::instancia_de($client);
        if ($instancia !== null && $vence_at->isFuture() && $instancia->esta_dormida()) {
            try {
                $this->despertar($instancia);
            } catch (\Throwable $e) {
                Log::error('PruebaCicloService: no se pudo despertar la instancia al extender la prueba.', ['client_id' => $client->id, 'error' => $e->getMessage()]);
            }
        }

        $lead = Lead::where('promoted_client_id', $client->id)->first();
        if ($lead !== null) {
            Lead::query()->whereKey($lead->id)->update(['trial_days' => $dias, 'demo_ingreso_token_expira_at' => $vence_at]);
        }

        return app(PruebaVencimientoService::class)->enviar_vencimiento($client, $vence_at);
    }

    /**
     * Borra la instancia de un cliente en prueba (D7): respaldo, sitios, base, supervisor, DNS.
     *
     * @param string $motivo Para el log y el error de la instancia.
     *
     * @return string Ruta del respaldo en el VPS.
     */
    public function borrar(TrialInstance $instancia, string $motivo = 'baja'): string
    {
        if ($instancia->estado === TrialInstance::ESTADO_BORRADA) {
            return (string) $instancia->respaldo_ruta;
        }
        if ($instancia->estado === TrialInstance::ESTADO_CLIENTE) {
            throw new \RuntimeException('La instancia ' . $instancia->nombre . ' es el sistema de un cliente que contrató: no se borra desde acá.');
        }

        $slug = trim((string) $instancia->alias_slug);

        $instancia->estado = TrialInstance::ESTADO_BORRANDO;
        $instancia->etapa  = 'borrando';
        $instancia->save();

        try {
            /* Sin slug (una instancia del pool que nunca se asignó) el script recibe el nombre como slug:
               no hay alias que sacar y el respaldo se nombra por el nombre. */
            $resultado = $this->vps->prueba_borrar($instancia->nombre, $slug !== '' ? $slug : $instancia->nombre);
            $respaldo  = isset($resultado['respaldo']) ? (string) $resultado['respaldo'] : '';

            if ($slug !== '') {
                app(PruebaDnsService::class)->borrar_registros($slug);
            }
            app(PruebaDnsService::class)->borrar_registros($instancia->nombre);
        } catch (\Throwable $e) {
            $instancia->estado = TrialInstance::ESTADO_FALLIDA;
            $instancia->error  = 'Borrado fallido (' . $motivo . '): ' . $e->getMessage();
            $instancia->save();

            throw $e;
        }

        DB::transaction(function () use ($instancia, $respaldo, $motivo) {
            $instancia->estado        = TrialInstance::ESTADO_BORRADA;
            $instancia->etapa         = 'borrada';
            $instancia->respaldo_ruta = $respaldo;
            $instancia->borrada_at    = Carbon::now();
            $instancia->password_cifrada = null;
            $instancia->error         = null;
            $instancia->save();

            if ($instancia->client_id) {
                Client::query()->whereKey($instancia->client_id)->update([
                    'estado'     => self::CLIENTE_PRUEBA_BORRADA,
                    'is_active'  => false,
                    'updated_at' => Carbon::now(),
                ]);
            }

            if ($instancia->lead_id) {
                $lead = Lead::find($instancia->lead_id);
                if ($lead !== null && (string) $lead->status !== 'cerrado_ganado') {
                    $lead->status = 'cerrado_perdido';
                    $lead->save();
                }
            }
        });

        Log::info('PruebaCicloService: instancia borrada.', ['instancia' => $instancia->nombre, 'motivo' => $motivo, 'respaldo' => $respaldo]);

        return $respaldo;
    }

    /**
     * Los clientes en prueba cuya fecha ya pasó y que todavía no se vencieron.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function clientes_por_vencer(?Carbon $ahora = null)
    {
        $ahora = $ahora ?: Carbon::now();

        return Client::where('estado', self::CLIENTE_PRUEBA)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $ahora)
            ->orderBy('id')
            ->get();
    }

    /**
     * Las instancias de clientes vencidos hace más de la gracia y sin contratar: las que
     * `pruebas:borrar` borra.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function instancias_por_borrar(?Carbon $ahora = null)
    {
        $ahora  = $ahora ?: Carbon::now();
        $limite = $ahora->copy()->subDays(PruebaSettings::dias_gracia_borrado());

        $clientes = Client::whereIn('estado', self::ESTADOS_EN_PRUEBA)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $limite)
            ->pluck('id')
            ->all();

        if ($clientes === []) {
            return TrialInstance::whereRaw('1 = 0')->get();
        }

        return TrialInstance::whereIn('client_id', $clientes)
            ->whereIn('estado', [TrialInstance::ESTADO_ASIGNADA, TrialInstance::ESTADO_FALLIDA, TrialInstance::ESTADO_ASIGNANDO])
            ->orderBy('id')
            ->get();
    }
}
