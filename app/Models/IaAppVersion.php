<?php

namespace App\Models;

use App\Services\VersionNumberComparator;
use Illuminate\Database\Eloquent\Model;

/**
 * Versión de la app `ia-spa` (`ia.comerciocity.com`), misión cruzada `app-ia-y-memoria` (29/10/2026).
 * Molde: `EcommerceVersion`. Es el puntero al asset `ia-spa-v{version}-dist.zip` del release `v{version}`.
 *
 * @property int                 $id
 * @property string              $version
 * @property string|null         $title
 * @property string|null         $description
 * @property string              $status       draft | published.
 * @property \Carbon\Carbon|null $published_at
 * @property \Carbon\Carbon|null $deployed_at
 */
class IaAppVersion extends Model
{
    const STATUS_DRAFT     = 'draft';
    const STATUS_PUBLISHED = 'published';

    /** Los dos valores válidos de `status` (plan §4 A.5). */
    const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    protected $table = 'ia_app_versions';

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'deployed_at'  => 'datetime',
    ];

    /**
     * @return bool
     */
    public function is_published()
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Ordena una colección de la más nueva a la más vieja por orden SEMÁNTICO del código (nunca por id ni
     * por texto: "1.10.0" va después de "1.9.0").
     *
     * @param \Illuminate\Support\Collection $versiones
     *
     * @return \Illuminate\Support\Collection
     */
    public static function ordenar_semanticamente($versiones)
    {
        return $versiones->sort(function ($a, $b) {
            $comparacion = VersionNumberComparator::compare((string) $b->version, (string) $a->version);

            return $comparacion !== 0 ? $comparacion : ((int) $b->id <=> (int) $a->id);
        })->values();
    }
}
