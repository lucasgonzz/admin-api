<?php

namespace App\Models;

use App\ModelProperties\DemoProperties;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de demos disponibles para asignar a leads.
 */
class Demo extends Model
{
    use HasUuid;

    /**
     * Definición declarativa consumida por admin-spa/meta.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return DemoProperties::all();
    }

    protected $guarded = [];

    /**
     * Scope estándar para mantener contrato homogéneo con BaseController/fullModel.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        // Este recurso no requiere relaciones eager por ahora.
    }

    /**
     * Tienda (ecommerce) de esta demo, si ya se creó.
     *
     * Es `hasOne` y no `hasMany` por la misma razón que en `Client`: una demo tiene a lo sumo un
     * ecommerce. La fila de `client_ecommerces` que la apunta tiene `client_id` en NULL — ver el
     * hook `saving` de ClientEcommerce, que es donde se hace cumplir "un dueño y solo uno".
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function client_ecommerce()
    {
        return $this->hasOne(ClientEcommerce::class);
    }

    /**
     * Nombre a mostrar del comercio de esta demo.
     *
     * Es el equivalente de `clients.company_name ?? clients.name`: lo consume el pipeline de
     * ecommerce para el APP_NAME del .env de tienda-api, el nombre de la PWA y VUE_APP_SITE_NAME.
     *
     * Cae al slug del ERP (demo3) cuando `nombre` está vacío, que es el estado de las 2838 demos
     * que ya existían cuando se agregó la columna. Es un nombre feo, sí — pero el pipeline escribe
     * ese valor en tres lugares del build y quedarse con la cadena vacía deja la tienda sin
     * nombre en la pestaña del navegador, en el manifest de la PWA y en la etiqueta og:site_name.
     * Un feo visible se corrige; un vacío silencioso se publica.
     *
     * @return string
     */
    public function display_name(): string
    {
        $nombre = trim((string) ($this->nombre ?? ''));
        if ($nombre !== '') {
            return $nombre;
        }

        return (new \App\Services\DemoPathResolver())->slug($this);
    }
}
