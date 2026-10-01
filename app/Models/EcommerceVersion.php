<?php

namespace App\Models;

use App\ModelProperties\EcommerceVersionProperties;
use App\Services\VersionNumberComparator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Versión publicada del ecommerce (`tienda-spa` + `tienda-api`), misión cruzada `versiones-tienda`
 * (1/10/2026).
 *
 * Es el equivalente de `Version` para empresa, pero mucho más chico a propósito: una versión de
 * tienda es solamente el puntero a dos artefactos que GitHub Actions publica en el release del tag
 * `v{version}` de cada repo (`tienda-spa-v{version}-dist.zip` y `tienda-api-v{version}.zip`). No
 * tiene seeders, ni comandos, ni rango de upgrade: tienda no tiene migraciones propias.
 *
 * 🔴 "LA ÚLTIMA PUBLICADA" SE ORDENA POR `VersionNumberComparator`, NUNCA POR `id` NI POR STRING. Es
 * la misma clase de error que ese comparador vino a matar en empresa (18/8/2026): con un hotfix
 * cargado después (`1.0.1.1` dado de alta después de `1.1.0`) el `id` más alto no es la versión más
 * nueva, y ordenado como texto `"1.10.0"` queda antes que `"1.9.0"`.
 *
 * @property int                 $id
 * @property string              $version       Código semántico ("1.0.0").
 * @property string|null         $title
 * @property string|null         $description
 * @property string              $status        draft | published | archived.
 * @property \Carbon\Carbon|null $published_at
 */
class EcommerceVersion extends Model
{
    /** Borrador: no se despliega. */
    const STATUS_DRAFT = 'draft';

    /** Publicada: la única que el pipeline acepta desplegar. */
    const STATUS_PUBLISHED = 'published';

    /** Archivada: no se despliega ni aparece como opción. */
    const STATUS_ARCHIVED = 'archived';

    /** Los tres valores válidos de `status`, en el orden en que se muestran. */
    const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    /**
     * Sin lista blanca: la escritura la validan los controladores (claude y panel), que son los
     * únicos dos caminos que crean filas.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'published_at' => 'datetime',
    ];

    /**
     * Columnas y formulario del modelo para el SPA (`GET meta/ecommerce_version`).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return EcommerceVersionProperties::all();
    }

    /**
     * Relaciones que viajan con la fila completa. Vacío a propósito: una versión de tienda no
     * tiene hijos. Existe porque `BaseController::fullModel()` y el resto del panel lo llaman si
     * está definido, y así la convención del proyecto se cumple sin inventar relaciones.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * Solo las versiones publicadas (las únicas que se despliegan).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * Tiendas que tienen instalada esta versión hoy.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function client_ecommerces()
    {
        return $this->hasMany(ClientEcommerce::class);
    }

    /**
     * Corridas de instalación/actualización que desplegaron (o van a desplegar) esta versión.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function installations()
    {
        return $this->hasMany(ClientEcommerceInstallation::class);
    }

    /**
     * ¿Esta versión está publicada?
     *
     * @return bool
     */
    public function is_published(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * La última versión publicada, por orden SEMÁNTICO del código (ver el docblock de la clase).
     *
     * Trae todas las publicadas y las ordena en PHP: son decenas de filas como mucho, y el orden
     * semántico no se puede expresar en SQL sin partir el string en componentes.
     *
     * @return self|null  Null si no hay ninguna publicada.
     */
    public static function latest_published(): ?self
    {
        $ordenadas = self::sort_semantically(self::query()->published()->get());

        return $ordenadas->first();
    }

    /**
     * Ordena una colección de versiones por su código semántico, de la más nueva a la más vieja.
     *
     * A igualdad de código (no debería pasar: `version` es UNIQUE) desempata por `id` descendente,
     * para que el resultado sea determinista.
     *
     * @param  Collection  $versions  Versiones a ordenar.
     * @return Collection  Colección nueva, reindexada desde 0.
     */
    public static function sort_semantically(Collection $versions): Collection
    {
        return $versions->sort(function (self $a, self $b) {
            $comparacion = VersionNumberComparator::compare($b->version, $a->version);
            if ($comparacion !== 0) {
                return $comparacion;
            }

            return ((int) $b->id) <=> ((int) $a->id);
        })->values();
    }

    /**
     * Proyección mínima que viaja pegada a una tienda o a una corrida: `{id, version}`.
     *
     * @return array<string, mixed>
     */
    public function compact_payload(): array
    {
        return [
            'id'      => (int) $this->id,
            'version' => (string) $this->version,
        ];
    }
}
