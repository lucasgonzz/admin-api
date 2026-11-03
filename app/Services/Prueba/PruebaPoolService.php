<?php

namespace App\Services\Prueba;

use App\Jobs\CrearInstanciaDePoolJob;
use App\Models\EcommerceVersion;
use App\Models\TrialInstance;
use App\Models\UserIdBlock;
use App\Models\Version;
use App\Services\UserIdBlockAllocatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * El pool de instancias pre-armadas (decisión D1 del plan de `prueba-alta-instancia`, 3/11/2026).
 *
 *  - `mantener()`: si hay menos de `prueba_pool_minimo` instancias libres (contando las que se están
 *    creando), encola `CrearInstanciaDePoolJob` para las que falten, con el próximo nombre `prueba-NN`
 *    libre y un bloque de `user_id` reservado.
 *  - `tomar_libre()`: la que usa el alta, bajo lock, para que dos altas simultáneas no se lleven la
 *    misma.
 *  - `hay_capacidad()`: el tope de activas (D4).
 */
class PruebaPoolService
{
    const MAX_NUMERO = 99;

    const SOURCE_BLOQUE = 'prueba_pool';

    /** Cuántas instancias libres o en armado hay. */
    public static function libres_o_creando(): int
    {
        return TrialInstance::whereIn('estado', [TrialInstance::ESTADO_LIBRE, TrialInstance::ESTADO_CREANDO])->count();
    }

    /** Cuántas instancias ocupadas y despiertas hay (las que cuentan para el tope). */
    public static function activas(): int
    {
        return TrialInstance::whereIn('estado', TrialInstance::ESTADOS_OCUPADA)
            ->whereNull('dormida_at')
            ->count();
    }

    /** ¿Entra una prueba más? */
    public static function hay_capacidad(): bool
    {
        return self::activas() < PruebaSettings::max_activas();
    }

    /**
     * Encola el armado de las instancias que falten para llegar al mínimo.
     *
     * @return array<int, string> Nombres encolados.
     */
    public function mantener(?int $minimo = null): array
    {
        $minimo  = $minimo === null ? PruebaSettings::pool_minimo() : max(0, $minimo);
        $faltan  = $minimo - self::libres_o_creando();
        $nombres = [];

        if ($faltan <= 0) {
            return [];
        }

        $motivo = $this->motivo_para_no_encolar();
        if ($motivo !== null) {
            Log::warning('PruebaPoolService: faltan ' . $faltan . ' pero no se encola: ' . $motivo);

            return [];
        }

        $version        = $this->version_publicada_de_empresa();
        $version_tienda = $this->version_publicada_de_tienda();

        if ($version === '' || $version_tienda === '') {
            throw new \RuntimeException('No hay una versión publicada de empresa y de tienda para armar el pool.');
        }

        for ($i = 0; $i < $faltan; $i++) {
            $instancia = $this->crear_fila($version, $version_tienda);
            CrearInstanciaDePoolJob::dispatch($instancia->id);
            $nombres[] = $instancia->nombre;
        }

        Log::info('PruebaPoolService: armado encolado.', ['nombres' => $nombres, 'version' => $version, 'tienda' => $version_tienda]);

        return $nombres;
    }

    /**
     * Por qué NO corresponde encolar un armado ahora, o null si corresponde (chequeo C1, 3/11/2026): con el
     * interruptor de DNS apagado cada corrida del scheduler fabricaría una `fallida` y quemaría un bloque de
     * 100 user_id; y una `fallida` sin asignar se destraba con «Reintentar» (o se borra), no armando otra al lado.
     */
    public function motivo_para_no_encolar(): ?string
    {
        if (! app(PruebaDnsService::class)->write_enabled()) {
            return 'el interruptor PRUEBA_DNS_WRITE_ENABLED está apagado: sin DNS no se arma ninguna instancia (se prende cuando el pool entra en servicio).';
        }

        $fallida = TrialInstance::where('estado', TrialInstance::ESTADO_FALLIDA)->whereNull('client_id')->orderBy('id')->first();
        if ($fallida !== null) {
            return 'hay una instancia fallida sin asignar (' . $fallida->nombre . ', etapa ' . $fallida->etapa . '): reintentala o borrala desde /pruebas antes de armar otra.';
        }

        return null;
    }

    /** Reserva un bloque si la instancia no tiene (se liberó por un fallo en `dns` y ahora se reintenta). */
    public function asegurar_bloque(TrialInstance $instancia): int
    {
        if ($instancia->user_id) {
            return (int) $instancia->user_id;
        }
        $instancia->user_id = $this->reservar_bloque($instancia->nombre);
        $instancia->save();

        return (int) $instancia->user_id;
    }

    /**
     * Devuelve el bloque de `user_id` de una instancia que no llegó a usarlo (falló en `dns`, o se borra sin
     * haberse asignado nunca): se saca la fila de `user_id_blocks` y la instancia queda sin `user_id`.
     */
    public function liberar_bloque(TrialInstance $instancia): void
    {
        if (! $instancia->user_id) {
            return;
        }
        UserIdBlock::where('block_start', (int) $instancia->user_id)
            ->where('source', self::SOURCE_BLOQUE)
            ->whereNull('lead_id')
            ->whereNull('client_id')
            ->delete();
        $instancia->user_id = null;
        $instancia->save();
    }

    /**
     * Crea la fila `creando` de una instancia nueva, con el próximo nombre libre y un bloque de
     * `user_id` reservado en `user_id_blocks` (así ningún lead ni cliente lo recibe después).
     */
    public function crear_fila(string $version, string $version_tienda): TrialInstance
    {
        return DB::transaction(function () use ($version, $version_tienda) {
            $nombre  = $this->proximo_nombre_libre();
            $user_id = $this->reservar_bloque($nombre);

            return TrialInstance::create([
                'nombre'             => $nombre,
                'estado'             => TrialInstance::ESTADO_CREANDO,
                'user_id'            => $user_id,
                'erp_vps_path'       => $nombre,
                'ecommerce_vps_path' => 'tienda-' . $nombre,
                'version'            => $version,
                'ecommerce_version'  => $version_tienda,
                'etapa'              => 'encolada',
                'progreso'           => 0,
            ]);
        });
    }

    /**
     * Toma una instancia libre para un alta, bajo lock, y la deja en `asignando`.
     *
     * @return TrialInstance|null Null si no hay ninguna libre.
     */
    public function tomar_libre(int $lead_id, string $slug): ?TrialInstance
    {
        return DB::transaction(function () use ($lead_id, $slug) {
            $instancia = TrialInstance::where('estado', TrialInstance::ESTADO_LIBRE)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($instancia === null) {
                return null;
            }

            $instancia->estado      = TrialInstance::ESTADO_ASIGNANDO;
            $instancia->lead_id     = $lead_id;
            $instancia->alias_slug  = PruebaNombres::assert_slug($slug);
            /* El uuid propio del seguimiento de ESTA prueba (lo devuelve el alta y lo lee la landing). */
            $instancia->seguimiento_uuid = (string) Str::uuid();
            $instancia->etapa       = 'reservada';
            $instancia->progreso    = 5;
            $instancia->error       = null;
            $instancia->asignada_at = now();
            $instancia->save();

            return $instancia;
        });
    }

    /** `prueba-01`, `prueba-02`... el primero que no exista en la tabla (las borradas liberan el nombre). */
    public function proximo_nombre_libre(): string
    {
        $ocupados = TrialInstance::where('estado', '!=', TrialInstance::ESTADO_BORRADA)->pluck('nombre')->all();

        for ($n = 1; $n <= self::MAX_NUMERO; $n++) {
            $nombre = PruebaNombres::nombre_numero($n);
            if (! in_array($nombre, $ocupados, true)) {
                /* Una fila borrada con ese nombre se renombra para que el único no choque. */
                TrialInstance::where('nombre', $nombre)
                    ->where('estado', TrialInstance::ESTADO_BORRADA)
                    ->update(['nombre' => $nombre . '~' . substr(md5((string) microtime(true)), 0, 6)]);

                return $nombre;
            }
        }

        throw new \RuntimeException('No quedan nombres libres en el pool (prueba-01 a prueba-99).');
    }

    /**
     * Reserva el próximo bloque de 100 para la instancia, en `user_id_blocks`, sin lead (`source =
     * prueba_pool`). Al asignarla a un lead, `AltaDePruebaService` le cuelga el lead y el cliente.
     */
    private function reservar_bloque(string $nombre): int
    {
        $user_id = (int) app(UserIdBlockAllocatorService::class)->suggest_next_block_start();

        UserIdBlock::updateOrCreate(
            ['block_start' => $user_id],
            ['source' => self::SOURCE_BLOQUE, 'lead_id' => null, 'client_id' => null, 'notes' => 'Instancia de prueba ' . $nombre]
        );

        return $user_id;
    }

    /** La última versión publicada de empresa (el mismo criterio que `RunUserSetupService`: la de id más alto). */
    public function version_publicada_de_empresa(): string
    {
        $version = Version::where('status', 'published')->orderByDesc('id')->first();

        return $version ? trim((string) $version->version) : '';
    }

    /** La última versión publicada de tienda (`EcommerceVersion::latest_published()`, por orden semántico). */
    public function version_publicada_de_tienda(): string
    {
        $version = EcommerceVersion::latest_published();

        return $version ? trim((string) $version->version) : '';
    }
}
