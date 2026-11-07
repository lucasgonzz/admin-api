<?php

namespace App\Models;

use App\Services\Prueba\PruebaNombres;
use Illuminate\Database\Eloquent\Model;

/**
 * Una instancia del pool de la prueba gratuita (`prueba-NN` en el VPS). Ver la migración
 * `create_trial_instances_table` y el plan de la misión `prueba-alta-instancia` (D1, D3, D4).
 *
 * Ciclo: `creando` (la arma `CrearInstanciaDePoolJob`) → `libre` → `asignando` (la tomó un alta:
 * corre `ArmarPruebaJob`) → `asignada` (lista para el negocio) → `cliente` (contrató) | `borrando` →
 * `borrada`. `fallida` puede aparecer desde `creando` o desde `asignando`, con `error` cargado.
 *
 * @property int         $id
 * @property string      $nombre
 * @property string      $estado
 * @property int|null    $client_id
 * @property int|null    $lead_id
 * @property int|null    $user_id
 * @property string      $erp_vps_path
 * @property string      $ecommerce_vps_path
 * @property string|null $version
 * @property string|null $ecommerce_version
 * @property string|null $alias_slug
 * @property string|null $etapa
 * @property int         $progreso
 * @property string|null $error
 * @property string|null $password_cifrada
 */
class TrialInstance extends Model
{
    const ESTADO_CREANDO   = 'creando';
    const ESTADO_LIBRE     = 'libre';
    const ESTADO_ASIGNANDO = 'asignando';
    const ESTADO_ASIGNADA  = 'asignada';
    const ESTADO_FALLIDA   = 'fallida';
    const ESTADO_CLIENTE   = 'cliente';
    const ESTADO_BORRANDO  = 'borrando';
    const ESTADO_BORRADA   = 'borrada';

    /** Estados en los que la instancia está OCUPADA por un negocio (cuentan para el tope de activas). */
    const ESTADOS_OCUPADA = [self::ESTADO_ASIGNANDO, self::ESTADO_ASIGNADA, self::ESTADO_CLIENTE];

    protected $guarded = [];

    protected $casts = [
        'client_id'   => 'integer',
        'lead_id'     => 'integer',
        'user_id'     => 'integer',
        'progreso'    => 'integer',
        'dormida_at'  => 'datetime',
        'asignada_at' => 'datetime',
        'lista_at'    => 'datetime',
        'borrada_at'  => 'datetime',
        'dns_labels'  => 'array',
        'contexto_meta' => 'array',
    ];

    /** Ni la contraseña ni el contexto privado del navegador salen serializados. */
    protected $hidden = ['password_cifrada', 'contexto_meta'];

    /**
     * Los labels de A records que esta instancia escribió (del nombre del pool y del slug). Es lo único que
     * `PruebaDnsService` acepta como «propio» si el label ya existe en la zona.
     *
     * @return array<int, string>
     */
    public function dns_labels_escritos(): array
    {
        $labels = $this->dns_labels;

        return is_array($labels) ? array_values(array_unique(array_map('strval', $labels))) : [];
    }

    /**
     * Anota labels como escritos por esta instancia. Se llama ANTES del PUT a la zona: si el PUT llega a
     * escribir y después falla la verificación, el reintento los encuentra anotados y no los toma por ajenos.
     *
     * @param array<int, string> $labels
     */
    public function registrar_dns_labels(array $labels): void
    {
        $this->dns_labels = array_values(array_unique(array_merge($this->dns_labels_escritos(), array_map('strval', $labels))));
        $this->save();
    }

    /** Saca labels de la lista de escritos (después de borrarlos de la zona). */
    public function olvidar_dns_labels(array $labels): void
    {
        $this->dns_labels = array_values(array_diff($this->dns_labels_escritos(), array_map('strval', $labels)));
        $this->save();
    }

    public function scopeWithAll($query)
    {
        $query->with('client', 'lead');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /** ¿Está durmiendo (supervisor parado)? */
    public function esta_dormida(): bool
    {
        return $this->dormida_at !== null;
    }

    /** ¿Está libre para que un alta la tome? */
    public function esta_libre(): bool
    {
        return $this->estado === self::ESTADO_LIBRE;
    }

    /** Hosts de los cuatro sitios del pool (sin alias): `api-prueba-NN.<dominio>`, etc. */
    public function hosts_del_pool(): array
    {
        return PruebaNombres::hosts_del_pool($this->nombre);
    }

    /** Hosts del alias del negocio, o [] si todavía no tiene slug. */
    public function hosts_del_slug(): array
    {
        $slug = trim((string) $this->alias_slug);

        return $slug === '' ? [] : PruebaNombres::hosts_del_slug($slug);
    }

    /** URL de la API del ERP por el host del POOL (el que tiene certificado desde el armado). */
    public function erp_api_url_del_pool(): string
    {
        return 'https://' . $this->hosts_del_pool()['erp_api'];
    }

    /** Ruta absoluta del ERP en el VPS. */
    public function ruta_erp(): string
    {
        return '/home/api-' . $this->erp_vps_path . '/empresa-api';
    }

    /** Ruta absoluta de la tienda-api en el VPS. */
    public function ruta_tienda(): string
    {
        return '/home/api-' . $this->ecommerce_vps_path . '/tienda-api';
    }

    /** Docroot del SPA del ERP en el VPS. */
    public function docroot_spa_erp(): string
    {
        return '/home/' . $this->erp_vps_path . '/htdocs/' . $this->hosts_del_pool()['erp_spa'];
    }

    /** Docroot del SPA de la tienda en el VPS. */
    public function docroot_spa_tienda(): string
    {
        return '/home/' . $this->ecommerce_vps_path . '/htdocs/' . $this->hosts_del_pool()['tienda_spa'];
    }
}
