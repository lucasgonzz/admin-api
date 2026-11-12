<?php

namespace App\Services;

use App\Models\EcommerceVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lo que soporte necesita leer del admin para contestar «¿este cliente tiene tal funcionalidad?»
 * (misión `acceso-soporte-al-admin-lectura`, 11/11/2026):
 *
 *  - en qué versión está cada cliente, del sistema y de su tienda, y cuánto le falta para la
 *    última publicada (`GET claude/support-codex/client-versions`);
 *  - en qué versión salió una funcionalidad, buscando en las novedades y en las versiones, y si un
 *    cliente dado ya la tiene (`GET claude/support-codex/feature-lookup`).
 *
 * Todo es de solo lectura y va con `DB::table`, no con Eloquent: `Client::$appends` tiene
 * accessors que tocan `client_ecommerce`, y serializar el modelo en un listado es una consulta por
 * cliente (la trampa que documenta `EndpointsDeClientesYVersionesParaClaudeTest`).
 *
 * 🔴 «MÁS NUEVA» ES SIEMPRE POR `VersionNumberComparator`, nunca por `id` ni por texto: con un
 * hotfix cargado después el `id` más alto no es la versión más nueva, y como texto `4.3.10` queda
 * antes que `4.3.9`.
 *
 * 🔴 LA DISPONIBILIDAD ES POR VERSIÓN Y NADA MÁS. Que la novedad figure en una versión publicada
 * y el cliente esté en esa o en una posterior no dice si tiene la extensión o el plan que la
 * habilitan. La respuesta lo avisa (`aviso`); el que la lee no puede prometerle al cliente más que eso.
 */
class ClaudeSupportAdminLecturaService
{
    /**
     * El valor de `versions.status` de una versión publicada. `Version` no tiene constantes: el
     * enum de la migración es `draft | published | archived` y todo el admin compara contra este
     * literal (`PublishVersionService`, `SupportCodexReleaseService`).
     */
    const VERSION_PUBLICADA = 'published';

    /** Tope de caracteres del texto de cada resultado de `buscar_funcionalidad`. */
    const TOPE_DE_TEXTO = 600;

    /** Filas que se traen como mucho de cada fuente antes de ordenar y cortar. */
    const TOPE_POR_FUENTE = 300;

    /** El aviso que acompaña toda respuesta de disponibilidad. */
    const AVISO = 'La disponibilidad es por versión; una extensión o el plan pueden condicionarla.';

    /**
     * ¿Existe el cliente?
     *
     * @param int $client_id Id del cliente.
     *
     * @return bool
     */
    public function existe_el_cliente($client_id)
    {
        return DB::table('clients')->where('id', (int) $client_id)->exists();
    }

    /**
     * Una página de clientes con sus versiones.
     *
     * @param int|null    $client_id Solo ese cliente, o null.
     * @param string|null $q         Texto a buscar en name / company_name / slug, o null.
     * @param int         $limit     Tamaño de página.
     * @param int|null    $after_id  Cursor: ids mayores que este.
     *
     * @return array<string, mixed> `data`, `ultima_version_sistema`, `ultima_version_ecommerce`,
     *                              `has_more`, `next_after_id`.
     */
    public function versiones_de_clientes($client_id, $q, $limit, $after_id)
    {
        $query = DB::table('clients')
            ->select(['id', 'name', 'company_name', 'slug', 'estado', 'is_active', 'tiene_ecommerce', 'current_version_id', 'active_client_api_id'])
            ->orderBy('id', 'asc');

        if ($client_id !== null) {
            $query->where('id', (int) $client_id);
        }

        if ($q !== null && $q !== '') {
            $patron = '%' . $this->escapar_like($q) . '%';
            $query->where(function ($sub) use ($patron) {
                $sub->where('name', 'like', $patron)
                    ->orWhere('company_name', 'like', $patron)
                    ->orWhere('slug', 'like', $patron);
            });
        }

        if ($after_id !== null) {
            $query->where('id', '>', (int) $after_id);
        }

        $clientes = $query->limit($limit + 1)->get();
        $has_more = $clientes->count() > $limit;

        if ($has_more) {
            $clientes = $clientes->slice(0, $limit)->values();
        }

        $versiones = $this->versiones_del_sistema();
        $tienda    = $this->versiones_de_tienda();
        $data      = $this->armar_clientes($clientes->all(), $versiones, $tienda);

        return [
            'data'                     => $data,
            'ultima_version_sistema'   => $this->ultima_publicada($versiones),
            'ultima_version_ecommerce' => $this->ultima_publicada($tienda),
            'has_more'                 => $has_more,
            'next_after_id'            => $has_more && $data !== [] ? (int) $data[count($data) - 1]['client_id'] : null,
        ];
    }

    /**
     * Un cliente con sus versiones, con la misma forma que un ítem de `versiones_de_clientes`.
     *
     * @param int $client_id Id del cliente.
     *
     * @return array<string, mixed>|null Null si no existe.
     */
    public function versiones_de_un_cliente($client_id)
    {
        $pagina = $this->versiones_de_clientes((int) $client_id, null, 1, null);

        return $pagina['data'] === [] ? null : $pagina['data'][0];
    }

    /**
     * Busca una funcionalidad en las novedades y versiones del sistema y en las versiones de tienda.
     *
     * @param string               $q       Texto buscado: cada palabra tiene que aparecer.
     * @param string|null          $sistema `empresa`, `tienda` o null (los dos).
     * @param int                  $limit   Cantidad máxima de resultados.
     * @param array<string, mixed>|null $cliente Ítem de `versiones_de_un_cliente`, o null.
     *
     * @return array<string, mixed> `resultados` y `hay_mas`.
     */
    public function buscar_funcionalidad($q, $sistema, $limit, $cliente)
    {
        $palabras = $this->palabras($q);
        $crudos   = [];

        if ($palabras !== []) {
            if ($sistema === null || $sistema === 'empresa') {
                $crudos = array_merge($crudos, $this->novedades_que_coinciden($palabras), $this->versiones_que_coinciden($palabras));
            }

            if ($sistema === null || $sistema === 'tienda') {
                $crudos = array_merge($crudos, $this->versiones_de_tienda_que_coinciden($palabras));
            }
        }

        /* Más nuevas primero: por fecha (publicación; si no se publicó, alta), y a igualdad por
           número de versión. La fecha y no solo el número porque acá conviven dos sistemas con
           numeraciones que no se comparan entre sí. */
        usort($crudos, function ($a, $b) {
            if ($a['_orden'] !== $b['_orden']) {
                return strcmp($b['_orden'], $a['_orden']);
            }

            $por_version = VersionNumberComparator::compare($b['version'], $a['version']);
            if ($por_version !== 0) {
                return $por_version;
            }

            return $a['_desempate'] <=> $b['_desempate'];
        });

        $hay_mas    = count($crudos) > $limit;
        $crudos     = array_slice($crudos, 0, $limit);
        $resultados = [];

        foreach ($crudos as $crudo) {
            $resultados[] = [
                'sistema'        => $crudo['sistema'],
                'version'        => $crudo['version'],
                'version_status' => $crudo['version_status'],
                'publicada'      => $crudo['publicada'],
                'published_at'   => $crudo['published_at'],
                'titulo'         => $crudo['titulo'],
                'texto'          => $crudo['texto'],
                'origen'         => $crudo['origen'],
                'cliente'        => $this->disponibilidad_para_el_cliente($crudo, $cliente),
            ];
        }

        return ['resultados' => $resultados, 'hay_mas' => $hay_mas];
    }

    /**
     * ¿El cliente tiene la versión de este resultado?
     *
     *  - `true`: la versión está publicada y la del cliente es igual o posterior.
     *  - `false`: no la tiene, o la versión no está publicada (un borrador no lo tiene nadie).
     *  - `null`: no se puede saber — no se indicó cliente, el cliente no tiene versión registrada,
     *    o (para tienda) no tiene tienda o su tienda no tiene versión registrada.
     *
     * @param array<string, mixed>      $resultado Resultado crudo.
     * @param array<string, mixed>|null $cliente   Ítem del cliente, o null.
     *
     * @return array<string, mixed> `tiene`, `version_cliente`, `motivo`.
     */
    private function disponibilidad_para_el_cliente(array $resultado, $cliente)
    {
        if ($cliente === null) {
            return ['tiene' => null, 'version_cliente' => null, 'motivo' => 'No se indicó un cliente.'];
        }

        if ($resultado['sistema'] === 'tienda') {
            return $this->disponibilidad_en_la_tienda($resultado, $cliente);
        }

        $version_cliente = $cliente['sistema']['version'];

        if ($version_cliente === null) {
            return ['tiene' => null, 'version_cliente' => null, 'motivo' => 'El cliente no tiene versión registrada en el admin.'];
        }

        if (! $resultado['publicada']) {
            return ['tiene' => false, 'version_cliente' => $version_cliente, 'motivo' => $this->motivo_de_no_publicada($resultado)];
        }

        if (VersionNumberComparator::compare($version_cliente, $resultado['version']) >= 0) {
            return [
                'tiene'           => true,
                'version_cliente' => $version_cliente,
                'motivo'          => 'El cliente está en la ' . $version_cliente . ', igual o posterior a la ' . $resultado['version'] . '.',
            ];
        }

        return [
            'tiene'           => false,
            'version_cliente' => $version_cliente,
            'motivo'          => 'El cliente está en la ' . $version_cliente . ', anterior a la ' . $resultado['version'] . ': le falta actualizar.',
        ];
    }

    /**
     * La misma pregunta, contra la(s) tienda(s) del cliente. Con varias tiendas, `tiene` es true
     * solo si TODAS la tienen; `version_cliente` es la más vieja de las registradas, que es la que
     * decide.
     *
     * @param array<string, mixed> $resultado Resultado crudo (sistema tienda).
     * @param array<string, mixed> $cliente   Ítem del cliente.
     *
     * @return array<string, mixed>
     */
    private function disponibilidad_en_la_tienda(array $resultado, array $cliente)
    {
        $tiendas = $cliente['ecommerce'];

        if ($tiendas === []) {
            return ['tiene' => null, 'version_cliente' => null, 'motivo' => 'El cliente no tiene tienda.'];
        }

        $con_version = [];
        $sin_version = 0;

        foreach ($tiendas as $tienda) {
            if ($tienda['version'] === null) {
                $sin_version++;
                continue;
            }

            $con_version[] = (string) $tienda['version'];
        }

        if ($con_version === []) {
            return ['tiene' => null, 'version_cliente' => null, 'motivo' => 'La tienda del cliente no tiene versión registrada en el admin.'];
        }

        usort($con_version, function ($a, $b) {
            return VersionNumberComparator::compare($a, $b);
        });

        $mas_vieja = $con_version[0];

        if (! $resultado['publicada']) {
            return ['tiene' => false, 'version_cliente' => $mas_vieja, 'motivo' => $this->motivo_de_no_publicada($resultado)];
        }

        $varias = count($tiendas) > 1;

        if (VersionNumberComparator::compare($mas_vieja, $resultado['version']) < 0) {
            return [
                'tiene'           => false,
                'version_cliente' => $mas_vieja,
                'motivo'          => $varias
                    ? 'El cliente tiene ' . count($tiendas) . ' tiendas y al menos una está en la ' . $mas_vieja . ', anterior a la ' . $resultado['version'] . '.'
                    : 'La tienda del cliente está en la ' . $mas_vieja . ', anterior a la ' . $resultado['version'] . ': le falta actualizar.',
            ];
        }

        if ($sin_version > 0) {
            return [
                'tiene'           => null,
                'version_cliente' => $mas_vieja,
                'motivo'          => 'El cliente tiene ' . count($tiendas) . ' tiendas y ' . $sin_version . ' no tiene versión registrada: no se puede afirmar que todas la tengan.',
            ];
        }

        return [
            'tiene'           => true,
            'version_cliente' => $mas_vieja,
            'motivo'          => $varias
                ? 'Las ' . count($tiendas) . ' tiendas del cliente están en la ' . $resultado['version'] . ' o posterior (la más vieja, en la ' . $mas_vieja . ').'
                : 'La tienda del cliente está en la ' . $mas_vieja . ', igual o posterior a la ' . $resultado['version'] . '.',
        ];
    }

    /**
     * Por qué una versión no publicada no la tiene nadie.
     *
     * @param array<string, mixed> $resultado Resultado crudo.
     *
     * @return string
     */
    private function motivo_de_no_publicada(array $resultado)
    {
        if ($resultado['version_status'] === 'draft') {
            return 'La versión ' . $resultado['version'] . ' todavía no se publicó.';
        }

        return 'La versión ' . $resultado['version'] . ' no está publicada (estado: ' . $resultado['version_status'] . ').';
    }

    /**
     * Arma el ítem de cada cliente, con tres consultas para toda la página (frente activo, último
     * upgrade y tiendas), no una por cliente.
     *
     * @param array<int, object>            $clientes  Filas de `clients`.
     * @param array<int, array<string, mixed>> $versiones Versiones del sistema, por id.
     * @param array<int, array<string, mixed>> $tienda    Versiones de tienda, por id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function armar_clientes(array $clientes, array $versiones, array $tienda)
    {
        if ($clientes === []) {
            return [];
        }

        $ids     = [];
        $api_ids = [];
        foreach ($clientes as $cliente) {
            $ids[] = (int) $cliente->id;

            if ($cliente->active_client_api_id !== null) {
                $api_ids[] = (int) $cliente->active_client_api_id;
            }
        }

        $hosting = $api_ids === []
            ? []
            : DB::table('client_apis')->whereIn('id', $api_ids)->pluck('hosting_type', 'id')->all();

        $ultimos_ids = DB::table('client_version_upgrades')
            ->whereIn('client_id', $ids)
            ->groupBy('client_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id')
            ->all();

        $upgrades = [];
        if ($ultimos_ids !== []) {
            $filas = DB::table('client_version_upgrades')
                ->whereIn('id', $ultimos_ids)
                ->get(['id', 'client_id', 'status', 'to_version_id', 'finished_at']);

            foreach ($filas as $fila) {
                $upgrades[(int) $fila->client_id] = $fila;
            }
        }

        /* Solo tiendas de CLIENTES: una tienda de demo tiene `client_id` null y no entra. */
        $tiendas = [];
        $filas   = DB::table('client_ecommerces')
            ->whereIn('client_id', $ids)
            ->orderBy('id', 'asc')
            ->get(['id', 'client_id', 'domain', 'status', 'ecommerce_version_id']);

        foreach ($filas as $fila) {
            $tiendas[(int) $fila->client_id][] = $fila;
        }

        $ultima_sistema = $this->ultima_publicada($versiones);
        $ultima_tienda  = $this->ultima_publicada($tienda);

        $data = [];
        foreach ($clientes as $cliente) {
            $id         = (int) $cliente->id;
            $version_id = $cliente->current_version_id === null ? null : (int) $cliente->current_version_id;
            $version    = $version_id !== null && isset($versiones[$version_id]) ? $versiones[$version_id] : null;
            $numero     = $version === null ? null : $version['version'];

            $ultimo_upgrade = null;
            if (isset($upgrades[$id])) {
                $upgrade        = $upgrades[$id];
                $destino_id     = $upgrade->to_version_id === null ? null : (int) $upgrade->to_version_id;
                $ultimo_upgrade = [
                    'id'          => (int) $upgrade->id,
                    'status'      => $upgrade->status,
                    'to_version'  => $destino_id !== null && isset($versiones[$destino_id]) ? $versiones[$destino_id]['version'] : null,
                    'finished_at' => $this->fecha($upgrade->finished_at),
                ];
            }

            $ecommerce = [];
            foreach (isset($tiendas[$id]) ? $tiendas[$id] : [] as $fila) {
                $tienda_version_id = $fila->ecommerce_version_id === null ? null : (int) $fila->ecommerce_version_id;
                $tienda_numero     = $tienda_version_id !== null && isset($tienda[$tienda_version_id])
                    ? $tienda[$tienda_version_id]['version']
                    : null;

                $ecommerce[] = [
                    'id'               => (int) $fila->id,
                    'domain'           => $fila->domain,
                    'status'           => $fila->status,
                    'version'          => $tienda_numero,
                    'ultima_publicada' => $ultima_tienda,
                    'al_dia'           => $this->al_dia($tienda_numero, $ultima_tienda),
                ];
            }

            $api_id = $cliente->active_client_api_id === null ? null : (int) $cliente->active_client_api_id;

            $data[] = [
                'client_id'       => $id,
                'name'            => $cliente->name,
                'company_name'    => $cliente->company_name,
                'slug'            => $cliente->slug,
                'estado'          => $cliente->estado,
                'is_active'       => (bool) $cliente->is_active,
                'sistema'         => [
                    'version'              => $numero,
                    'version_id'           => $version === null ? null : $version_id,
                    'published_at'         => $version === null ? null : $version['published_at'],
                    'ultima_publicada'     => $ultima_sistema,
                    'al_dia'               => $this->al_dia($numero, $ultima_sistema),
                    'versiones_pendientes' => $this->versiones_pendientes($numero, $versiones),
                    'hosting_type'         => $api_id !== null && isset($hosting[$api_id]) ? $hosting[$api_id] : null,
                    'ultimo_upgrade'       => $ultimo_upgrade,
                ],
                'tiene_ecommerce' => (bool) $cliente->tiene_ecommerce,
                'ecommerce'       => $ecommerce,
            ];
        }

        return $data;
    }

    /**
     * Todas las versiones del sistema, por id. Son decenas de filas: se traen una vez por request.
     *
     * @return array<int, array<string, mixed>>
     */
    private function versiones_del_sistema()
    {
        $versiones = [];

        foreach (DB::table('versions')->get(['id', 'version', 'status', 'published_at']) as $fila) {
            $versiones[(int) $fila->id] = [
                'version'      => (string) $fila->version,
                'status'       => (string) $fila->status,
                'publicada'    => $fila->status === self::VERSION_PUBLICADA,
                'published_at' => $this->fecha($fila->published_at),
            ];
        }

        return $versiones;
    }

    /**
     * Todas las versiones de tienda, por id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function versiones_de_tienda()
    {
        $versiones = [];

        foreach (DB::table('ecommerce_versions')->get(['id', 'version', 'status', 'published_at']) as $fila) {
            $versiones[(int) $fila->id] = [
                'version'      => (string) $fila->version,
                'status'       => (string) $fila->status,
                'publicada'    => $fila->status === EcommerceVersion::STATUS_PUBLISHED,
                'published_at' => $this->fecha($fila->published_at),
            ];
        }

        return $versiones;
    }

    /**
     * El número de la última versión publicada, por orden semántico.
     *
     * @param array<int, array<string, mixed>> $versiones Versiones por id.
     *
     * @return string|null Null si no hay ninguna publicada.
     */
    private function ultima_publicada(array $versiones)
    {
        $ultima = null;

        foreach ($versiones as $version) {
            if (! $version['publicada']) {
                continue;
            }

            if ($ultima === null || VersionNumberComparator::compare($version['version'], $ultima) > 0) {
                $ultima = $version['version'];
            }
        }

        return $ultima;
    }

    /**
     * ¿La versión instalada es la última publicada (o posterior)? Null si no se puede saber.
     *
     * @param string|null $instalada Versión instalada.
     * @param string|null $ultima    Última publicada.
     *
     * @return bool|null
     */
    private function al_dia($instalada, $ultima)
    {
        if ($instalada === null || $ultima === null) {
            return null;
        }

        return VersionNumberComparator::compare($instalada, $ultima) >= 0;
    }

    /**
     * Cuántas versiones PUBLICADAS del sistema son posteriores a la del cliente.
     *
     * @param string|null                      $instalada Versión del cliente.
     * @param array<int, array<string, mixed>> $versiones Versiones por id.
     *
     * @return int|null Null si el cliente no tiene versión registrada.
     */
    private function versiones_pendientes($instalada, array $versiones)
    {
        if ($instalada === null) {
            return null;
        }

        $pendientes = 0;

        foreach ($versiones as $version) {
            if ($version['publicada'] && VersionNumberComparator::compare($version['version'], $instalada) > 0) {
                $pendientes++;
            }
        }

        return $pendientes;
    }

    /**
     * Novedades activas del sistema cuyo título o cuerpo tienen todas las palabras.
     *
     * @param array<int, string> $palabras Palabras buscadas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function novedades_que_coinciden(array $palabras)
    {
        $query = DB::table('version_notifications as n')
            ->join('versions as v', 'v.id', '=', 'n.version_id')
            ->where('n.is_active', 1);

        $this->exigir_palabras($query, $palabras, ['n.title', 'n.body']);

        $filas = $query->orderBy('n.id', 'desc')
            ->limit(self::TOPE_POR_FUENTE)
            ->get(['n.id', 'n.title', 'n.body', 'n.sort_order', 'v.version', 'v.status', 'v.published_at', 'v.created_at']);

        $salida = [];
        foreach ($filas as $fila) {
            $salida[] = $this->resultado_crudo('empresa', 'novedad', $fila, $fila->title, $fila->body, $fila->status === self::VERSION_PUBLICADA, '0-' . str_pad((string) (int) $fila->sort_order, 6, '0', STR_PAD_LEFT) . '-' . $fila->id);
        }

        return $salida;
    }

    /**
     * Versiones del sistema cuyo título o descripción tienen todas las palabras.
     *
     * @param array<int, string> $palabras Palabras buscadas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function versiones_que_coinciden(array $palabras)
    {
        $query = DB::table('versions as v');

        $this->exigir_palabras($query, $palabras, ['v.title', 'v.description']);

        $filas = $query->orderBy('v.id', 'desc')
            ->limit(self::TOPE_POR_FUENTE)
            ->get(['v.id', 'v.title', 'v.description', 'v.version', 'v.status', 'v.published_at', 'v.created_at']);

        $salida = [];
        foreach ($filas as $fila) {
            $salida[] = $this->resultado_crudo('empresa', 'version', $fila, $fila->title, $fila->description, $fila->status === self::VERSION_PUBLICADA, '1-' . $fila->id);
        }

        return $salida;
    }

    /**
     * Versiones de tienda cuyo título o descripción tienen todas las palabras.
     *
     * @param array<int, string> $palabras Palabras buscadas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function versiones_de_tienda_que_coinciden(array $palabras)
    {
        $query = DB::table('ecommerce_versions as v');

        $this->exigir_palabras($query, $palabras, ['v.title', 'v.description']);

        $filas = $query->orderBy('v.id', 'desc')
            ->limit(self::TOPE_POR_FUENTE)
            ->get(['v.id', 'v.title', 'v.description', 'v.version', 'v.status', 'v.published_at', 'v.created_at']);

        $salida = [];
        foreach ($filas as $fila) {
            $salida[] = $this->resultado_crudo('tienda', 'version', $fila, $fila->title, $fila->description, $fila->status === EcommerceVersion::STATUS_PUBLISHED, '1-' . $fila->id);
        }

        return $salida;
    }

    /**
     * Agrega a la query «cada palabra aparece en alguna de estas columnas». El LIKE no distingue
     * mayúsculas porque las columnas usan una collation `_ci`.
     *
     * @param \Illuminate\Database\Query\Builder $query    Query en construcción.
     * @param array<int, string>                 $palabras Palabras buscadas.
     * @param array<int, string>                 $columnas Columnas donde buscar.
     *
     * @return void
     */
    private function exigir_palabras($query, array $palabras, array $columnas)
    {
        foreach ($palabras as $palabra) {
            $patron = '%' . $this->escapar_like($palabra) . '%';

            $query->where(function ($sub) use ($columnas, $patron) {
                foreach ($columnas as $columna) {
                    $sub->orWhere($columna, 'like', $patron);
                }
            });
        }
    }

    /**
     * Forma interna de un resultado, con las claves de orden (`_orden`, `_desempate`) que no salen.
     *
     * @param string      $sistema    empresa | tienda.
     * @param string      $origen     novedad | version.
     * @param object      $fila       Fila con version, status, published_at y created_at.
     * @param string|null $titulo     Título.
     * @param string|null $texto      Cuerpo o descripción.
     * @param bool        $publicada  Si la versión está publicada.
     * @param string      $desempate  Clave de desempate dentro de la misma versión.
     *
     * @return array<string, mixed>
     */
    private function resultado_crudo($sistema, $origen, $fila, $titulo, $texto, $publicada, $desempate)
    {
        $fecha = $fila->published_at !== null ? $fila->published_at : $fila->created_at;

        return [
            'sistema'        => $sistema,
            'version'        => (string) $fila->version,
            'version_status' => (string) $fila->status,
            'publicada'      => (bool) $publicada,
            'published_at'   => $this->fecha($fila->published_at),
            'titulo'         => $titulo,
            'texto'          => $this->texto_corto($texto),
            'origen'         => $origen,
            '_orden'         => $fecha === null ? '' : (string) $fecha,
            '_desempate'     => $desempate,
        ];
    }

    /**
     * Las palabras de la búsqueda, sin repetir y sin vacías.
     *
     * @param string $q Texto buscado.
     *
     * @return array<int, string>
     */
    private function palabras($q)
    {
        $partes = preg_split('/\s+/u', trim((string) $q));
        $partes = $partes === false ? [] : $partes;
        $salida = [];

        foreach ($partes as $parte) {
            if ($parte !== '' && ! in_array(mb_strtolower($parte, 'UTF-8'), $salida, true)) {
                $salida[] = mb_strtolower($parte, 'UTF-8');
            }
        }

        return $salida;
    }

    /**
     * Texto plano, en una línea, de hasta `TOPE_DE_TEXTO` caracteres.
     *
     * @param string|null $texto Texto original (puede traer HTML o saltos de línea).
     *
     * @return string|null
     */
    private function texto_corto($texto)
    {
        if ($texto === null) {
            return null;
        }

        $plano = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $texto)));

        if (mb_strlen($plano, 'UTF-8') > self::TOPE_DE_TEXTO) {
            return mb_substr($plano, 0, self::TOPE_DE_TEXTO - 1, 'UTF-8') . '…';
        }

        return $plano;
    }

    /**
     * Escapa los comodines de LIKE para que `50%` busque el texto «50%».
     *
     * @param string $texto Texto del usuario.
     *
     * @return string
     */
    private function escapar_like($texto)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $texto);
    }

    /**
     * Fecha de la base en ISO 8601, o null.
     *
     * @param mixed $valor Valor de una columna de fecha.
     *
     * @return string|null
     */
    private function fecha($valor)
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor)->toIso8601String();
        } catch (\Throwable $e) {
            return (string) $valor;
        }
    }
}
