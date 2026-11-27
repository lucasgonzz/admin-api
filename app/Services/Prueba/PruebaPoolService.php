<?php

namespace App\Services\Prueba;

use App\Jobs\CrearInstanciaDePoolJob;
use App\Models\EcommerceVersion;
use App\Models\TrialInstance;
use App\Models\UserIdBlock;
use App\Models\Version;
use App\Services\UserIdBlockAllocatorService;
use Illuminate\Support\Facades\Cache;
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

    /**
     * 🔴 Cupo de certificados de Let's Encrypt (A5, 14/11/2026). Cada armado emite un certificado por host
     * (`prueba-NN`, `api-prueba-NN`, `tienda-prueba-NN`, `api-tienda-prueba-NN`) y Let's Encrypt admite 5 por
     * semana para el mismo conjunto de nombres. Medido en producción: `prueba-05`, armado 7 veces en 7 días,
     * fallaba siempre en «certificados del pool» con «too many certificates (5) already issued». Un nombre que
     * ya se armó ARMADOS_MAXIMOS_POR_NOMBRE veces en la ventana no se elige: se deja uno de margen respecto
     * de los 5 porque un armado puede pedir el certificado más de una vez (un reintento desde el panel).
     */
    const ARMADOS_MAXIMOS_POR_NOMBRE = 4;

    /** Certificados que emite cada instancia: uno por host en el armado y otros cuatro (alias como SAN) en el alta. */
    const CERTIFICADOS_POR_INSTANCIA = 4;

    /** La ventana del cupo de Let's Encrypt: 7 días (el cupo se va devolviendo de a uno cada ~34 horas). */
    const VENTANA_ARMADOS_DIAS = 7;

    /** Cuántas instancias libres o en armado hay. */
    public static function libres_o_creando(): int
    {
        return TrialInstance::whereIn('estado', [TrialInstance::ESTADO_LIBRE, TrialInstance::ESTADO_CREANDO])->count();
    }

    /**
     * Cuántas instancias libres o en armado están AL DÍA: con la versión de empresa y la de tienda indicadas
     * (misión prueba-ultima-version, D3).
     *
     * @param string $version        Última versión publicada de empresa.
     * @param string $version_tienda Última versión publicada de tienda.
     *
     * @return int
     */
    public static function libres_o_creando_al_dia(string $version, string $version_tienda): int
    {
        return TrialInstance::whereIn('estado', [TrialInstance::ESTADO_LIBRE, TrialInstance::ESTADO_CREANDO])
            ->where('version', $version)
            ->where('ecommerce_version', $version_tienda)
            ->count();
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
     * Encola el armado de las instancias que falten para llegar al mínimo de libres AL DÍA.
     *
     * «Al día» = con la última versión publicada de empresa y de tienda (misión prueba-ultima-version, D3). Lo que
     * falta se separa en dos: el faltante real (no hay ni una vieja que lo cubra: se arma siempre) y el reemplazo
     * de libres viejas, que se limita por el presupuesto semanal de certificados de Let's Encrypt.
     *
     * @param int|null $minimo Mínimo a sostener (default: `prueba_pool_minimo`).
     *
     * @return array<int, string> Nombres encolados.
     */
    public function mantener(?int $minimo = null): array
    {
        $minimo  = $minimo === null ? PruebaSettings::pool_minimo() : max(0, $minimo);
        $nombres = [];

        /* Las versiones publicadas: contra ellas se mide qué instancia está al día. */
        $version        = $this->version_publicada_de_empresa();
        $version_tienda = $this->version_publicada_de_tienda();

        /* Sin versión publicada no hay forma de saber qué es «al día»: se cuenta como siempre. */
        $hay_versiones = $version !== '' && $version_tienda !== '';
        $al_dia        = $hay_versiones ? self::libres_o_creando_al_dia($version, $version_tienda) : self::libres_o_creando();
        $faltan        = $minimo - $al_dia;

        if ($faltan <= 0) {
            return [];
        }

        $motivo = $this->motivo_para_no_encolar();
        if ($motivo !== null) {
            Log::warning('PruebaPoolService: faltan ' . $faltan . ' pero no se encola: ' . $motivo);

            return [];
        }

        /* G1 (15/11/2026): crear una instancia recarga php8.4-fpm y le corta el demo setup a un alta en curso. Se
           espera: el comando programado lo vuelve a intentar en su próxima corrida. */
        $armado = self::armado_en_curso();
        if ($armado !== null) {
            if (Cache::add('prueba-pool:espera-armado:' . $armado->id, 1, now()->addMinutes(self::ventana_carrera_minutos()))) {
                Log::info('PruebaPoolService: faltan ' . $faltan . ' pero nada encolado: hay un armado en curso.', ['instancia' => $armado->nombre, 'etapa' => $armado->etapa]);
            }

            return [];
        }

        if (! $hay_versiones) {
            throw new \RuntimeException('No hay una versión publicada de empresa y de tienda para armar el pool.');
        }

        /* Libres o en armado que NO están al día: cubren el mínimo «de respaldo», pero hay que reemplazarlas. */
        $viejas = self::libres_o_creando() - $al_dia;

        /* Faltante real: ni sumando las viejas se llega al mínimo. Se arma siempre, sin tope. */
        $sin_cubrir = max(0, $minimo - ($al_dia + $viejas));

        /* Reemplazos de viejas, acotados por el presupuesto de certificados de la semana. */
        $renovar = $faltan - $sin_cubrir;
        if ($renovar > 0) {
            $cupo = $this->cupo_de_renovaciones();
            if ($renovar > $cupo) {
                if (Cache::add('prueba-pool:sin-cupo-certificados', 1, now()->addMinutes(self::ventana_carrera_minutos()))) {
                    Log::warning('PruebaPoolService: se renovarían ' . $renovar . ' libres viejas pero el presupuesto de certificados de la semana alcanza para ' . $cupo . '.', ['tope' => PruebaSettings::tope_certificados_semana()]);
                }
                $renovar = $cupo;
            }
        }

        $a_armar = $sin_cubrir + max(0, $renovar);
        for ($i = 0; $i < $a_armar; $i++) {
            $instancia = $this->crear_fila($version, $version_tienda);
            CrearInstanciaDePoolJob::dispatch($instancia->id);
            $nombres[] = $instancia->nombre;
        }

        if ($nombres !== []) {
            Log::info('PruebaPoolService: armado encolado.', ['nombres' => $nombres, 'version' => $version, 'tienda' => $version_tienda, 'renovaciones' => max(0, $renovar)]);
        }

        return $nombres;
    }

    /**
     * Cuántas instancias más se pueden armar para RENOVAR libres viejas sin pasarse del presupuesto semanal de
     * certificados. Gasto de la ventana de 7 días: 4 por cada fila creada (el armado del pool) y 4 por cada
     * instancia asignada (el alias del alta, como SAN).
     *
     * @return int Cantidad de renovaciones que entran (0 si no queda presupuesto).
     */
    private function cupo_de_renovaciones(): int
    {
        /* Desde cuándo se cuenta el gasto. */
        $desde = now()->subDays(self::VENTANA_ARMADOS_DIAS);

        $armados  = TrialInstance::where('created_at', '>=', $desde)->count();
        $asignadas = TrialInstance::whereNotNull('asignada_at')->where('asignada_at', '>=', $desde)->count();
        $usados   = self::CERTIFICADOS_POR_INSTANCIA * ($armados + $asignadas);

        return max(0, (int) floor((PruebaSettings::tope_certificados_semana() - $usados) / self::CERTIFICADOS_POR_INSTANCIA));
    }

    /**
     * Retira UNA libre desactualizada (misión prueba-ultima-version, D4), sólo si es seguro: sin motivo para no
     * armar, sin alta ni creación en curso (G1/G2) y con al menos `max(1, mínimo)` libres al día de respaldo. La
     * reclama pasándola a `borrando` bajo lock (así `tomar_libre` ya no la ve) y la borra con el ciclo. Las
     * asignadas, `cliente` y en armado nunca se tocan.
     *
     * @param PruebaCicloService $ciclo Servicio que hace el borrado real (respaldo, sitios, DNS).
     *
     * @return string|null Nombre de la instancia retirada, o null si no se retiró ninguna.
     */
    public function retirar_una_desactualizada(PruebaCicloService $ciclo): ?string
    {
        $version        = $this->version_publicada_de_empresa();
        $version_tienda = $this->version_publicada_de_tienda();
        if ($version === '' || $version_tienda === '') {
            return null;
        }

        if ($this->motivo_para_no_encolar() !== null || self::armado_en_curso() !== null || self::creacion_en_curso() !== null) {
            return null;
        }

        /* Libres al día YA listas (no cuentan las que se están creando): el pool nunca queda sin respaldo. */
        $libres_al_dia = TrialInstance::where('estado', TrialInstance::ESTADO_LIBRE)
            ->where('version', $version)
            ->where('ecommerce_version', $version_tienda)
            ->count();
        if ($libres_al_dia < max(1, PruebaSettings::pool_minimo())) {
            return null;
        }

        /* Se reclama la más vieja desactualizada, sin dueño, bajo lock. */
        $instancia = DB::transaction(function () use ($version, $version_tienda) {
            $candidata = TrialInstance::where('estado', TrialInstance::ESTADO_LIBRE)
                ->whereNull('client_id')
                ->whereNull('lead_id')
                ->whereNull('alias_slug')
                ->where(function ($query) use ($version, $version_tienda) {
                    $query->where('version', '!=', $version)->orWhere('ecommerce_version', '!=', $version_tienda);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($candidata === null) {
                return null;
            }

            $candidata->estado = TrialInstance::ESTADO_BORRANDO;
            $candidata->etapa  = 'borrando';
            $candidata->save();

            return $candidata;
        });

        if ($instancia === null) {
            return null;
        }

        /* Si el borrado falla, el ciclo la deja `fallida` en `borrando` y la excepción sube al comando. */
        $ciclo->borrar($instancia, 'renovación por versión nueva');

        Log::info('PruebaPoolService: libre desactualizada retirada.', ['instancia' => $instancia->nombre, 'version' => $instancia->version]);

        return (string) $instancia->nombre;
    }

    /** Minutos en los que un armado o una creación movidos por última vez cuentan como «en curso». */
    public static function ventana_carrera_minutos(): int
    {
        return max(1, (int) config('services.prueba.carrera_ventana_minutos', 15));
    }

    /**
     * La instancia ASIGNADA A UN NEGOCIO cuyo armado (`ArmarPruebaJob`) está en curso —todavía no llegó a `lista` ni
     * quedó `fallida`— y se movió dentro de la ventana, o null (G1, 15/11/2026). Una colgada más vieja no frena el pool.
     */
    public static function armado_en_curso(): ?TrialInstance
    {
        return TrialInstance::where('estado', TrialInstance::ESTADO_ASIGNANDO)
            ->whereNotNull('lead_id')
            ->where('updated_at', '>=', now()->subMinutes(self::ventana_carrera_minutos()))
            ->orderBy('id')
            ->first();
    }

    /**
     * La instancia del pool que se está creando en el VPS (`CrearInstanciaDePoolJob` ya pasó su arranque y está en
     * dns/crear/env/configurar: las etapas que terminan recargando php8.4-fpm) y se movió dentro de la ventana, o
     * null (G2, 15/11/2026). Una `encolada` todavía no tocó nada y no cuenta.
     */
    public static function creacion_en_curso(): ?TrialInstance
    {
        return TrialInstance::where(function ($query) {
                $query->where(function ($creando) {
                    $creando->where('estado', TrialInstance::ESTADO_CREANDO)->whereIn('etapa', ['dns', 'crear', 'env', 'configurar']);
                })->orWhere(function ($retiro) {
                    /* El retiro de una libre vieja (sin cliente) también recarga nginx/fpm. */
                    $retiro->where('estado', TrialInstance::ESTADO_BORRANDO)->whereNull('client_id');
                });
            })
            ->where('updated_at', '>=', now()->subMinutes(self::ventana_carrera_minutos()))
            ->orderBy('id')
            ->first();
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
            /* Las versiones publicadas: la instancia «al día» las tiene las dos (empresa y tienda). */
            $version        = $this->version_publicada_de_empresa();
            $version_tienda = $this->version_publicada_de_tienda();
            $hay_versiones  = $version !== '' && $version_tienda !== '';

            $instancia = null;
            if ($hay_versiones) {
                /* Primero la libre al día (la de id menor entre ellas). */
                $instancia = TrialInstance::where('estado', TrialInstance::ESTADO_LIBRE)
                    ->where('version', $version)
                    ->where('ecommerce_version', $version_tienda)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
            }

            if ($instancia === null) {
                /* Respaldo: no se pierde al lead. Con versiones publicadas, la desactualizada más nueva; sin ellas, la más vieja de siempre. */
                $respaldo = TrialInstance::where('estado', TrialInstance::ESTADO_LIBRE);
                $instancia = $hay_versiones
                    ? $respaldo->orderByDesc('id')->lockForUpdate()->first()
                    : $respaldo->orderBy('id')->lockForUpdate()->first();

                if ($instancia !== null && $hay_versiones) {
                    Log::warning('PruebaPoolService: alta con versión atrasada (no había libre al día).', [
                        'instancia' => $instancia->nombre,
                        'version'   => $instancia->version,
                        'publicada' => $version,
                    ]);
                }
            }

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

    /**
     * `prueba-01`, `prueba-02`... el primero que no exista en la tabla (las borradas liberan el nombre) y que
     * no esté gastado para Let's Encrypt (ver ARMADOS_MAXIMOS_POR_NOMBRE). Sin nombres gastados, el resultado
     * es el de siempre: el más bajo libre.
     */
    public function proximo_nombre_libre(): string
    {
        $ocupados = TrialInstance::where('estado', '!=', TrialInstance::ESTADO_BORRADA)->pluck('nombre')->all();
        $armados  = $this->armados_recientes_por_nombre();
        $gastados = [];

        for ($n = 1; $n <= self::MAX_NUMERO; $n++) {
            $nombre = PruebaNombres::nombre_numero($n);
            if (! in_array($nombre, $ocupados, true)) {
                if (isset($armados[$nombre]) && $armados[$nombre] >= self::ARMADOS_MAXIMOS_POR_NOMBRE) {
                    /* Gastado: armarlo de nuevo fallaría en los certificados. Se pasa al número siguiente. */
                    $gastados[] = $nombre . ' (' . $armados[$nombre] . ')';
                    continue;
                }
                if ($gastados !== []) {
                    Log::info('PruebaPoolService: nombres salteados por el cupo de Let\'s Encrypt.', ['salteados' => $gastados, 'elegido' => $nombre]);
                }

                /* Una fila borrada con ese nombre se renombra para que el único no choque. */
                TrialInstance::where('nombre', $nombre)
                    ->where('estado', TrialInstance::ESTADO_BORRADA)
                    ->update(['nombre' => $nombre . '~' . substr(md5((string) microtime(true)), 0, 6)]);

                return $nombre;
            }
        }

        if ($gastados !== []) {
            throw new \RuntimeException(
                'No quedan nombres libres en el pool (prueba-01 a prueba-99): los que no están ocupados ya se armaron '
                    . self::ARMADOS_MAXIMOS_POR_NOMBRE . ' o más veces en los últimos ' . self::VENTANA_ARMADOS_DIAS
                    . ' días y Let\'s Encrypt no les emitiría el certificado (' . implode(', ', $gastados) . ').'
            );
        }

        throw new \RuntimeException('No quedan nombres libres en el pool (prueba-01 a prueba-99).');
    }

    /**
     * Cuántas veces se armó cada nombre en la ventana del cupo de Let's Encrypt, en UNA consulta.
     *
     * Un armado es una fila de `trial_instances` creada en la ventana: la viva (`prueba-05`) y las borradas, que
     * al reciclar el nombre quedan renombradas `prueba-05~a1b2c3` y conservan su `created_at` (el renombrado es un
     * `update()` por query builder, que solo toca `updated_at`). La agrupación por nombre base se hace acá, cortando
     * en el primer `~`, y no con un `LIKE`: así `prueba-05~…` nunca se confunde con `prueba-050` ni con `prueba-5`.
     *
     * Cuenta también una fila cuyo armado falló ANTES de llegar a los certificados (en `dns` o en `crear`): no gastó
     * cupo, pero contarla es conservador y lo peor que hace es pasar al número siguiente antes de tiempo.
     *
     * @return array<string, int> nombre base => armados en la ventana.
     */
    private function armados_recientes_por_nombre(): array
    {
        $nombres = TrialInstance::where('created_at', '>=', now()->subDays(self::VENTANA_ARMADOS_DIAS))->pluck('nombre')->all();

        $armados = [];
        foreach ($nombres as $nombre) {
            $partes = explode('~', (string) $nombre, 2);
            $base   = $partes[0];
            $armados[$base] = isset($armados[$base]) ? $armados[$base] + 1 : 1;
        }

        return $armados;
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
