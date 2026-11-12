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

    /**
     * El único estado de `client_ecommerces.status` con una tienda desplegada. Los otros dos que
     * escribe `EcommerceInstallationService` son `pending` (dada de alta, sin instalar) e
     * `installing` (corrida en curso o caída a mitad de camino).
     */
    const TIENDA_INSTALADA = 'active';

    /**
     * Palabras que no se buscan: no distinguen una novedad de otra y, con AND por palabra, una
     * sola que no figure en el texto deja la búsqueda en cero.
     */
    const PALABRAS_VACIAS = [
        'como', 'cómo', 'con', 'de', 'del', 'la', 'el', 'los', 'las', 'para', 'por', 'que', 'qué', 'un', 'una',
        'en', 'al', 'se', 'mi', 'su', 'es', 'lo', 'le', 'y', 'o', 'a',
    ];

    /** Cómo leer varias coincidencias de la misma función. Viaja en toda respuesta de `feature-lookup`. */
    const LECTURA = 'Si la misma función aparece en varias versiones, la que decide si el cliente la tiene es la más vieja publicada; las posteriores son mejoras.';

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
     * @param string               $q       Texto buscado: cada palabra útil tiene que aparecer.
     * @param string|null          $sistema `empresa`, `tienda` o null (los dos).
     * @param int                  $limit   Cantidad máxima de resultados.
     * @param array<string, mixed>|null $cliente Ítem de `versiones_de_un_cliente`, o null.
     *
     * @return array<string, mixed> `palabras` (las raíces que se buscaron; vacío = la búsqueda no
     *                              tenía ninguna palabra útil y no se buscó nada), `resultados`,
     *                              `hay_mas` y `resumen_cliente` (null si no se indicó cliente).
     */
    public function buscar_funcionalidad($q, $sistema, $limit, $cliente)
    {
        $palabras = $this->palabras($q);

        if ($palabras === []) {
            return ['palabras' => [], 'resultados' => [], 'hay_mas' => false, 'resumen_cliente' => null];
        }

        $crudos = [];

        if ($sistema === null || $sistema === 'empresa') {
            $crudos = array_merge($crudos, $this->novedades_que_coinciden($palabras), $this->versiones_que_coinciden($palabras));
        }

        if ($sistema === null || $sistema === 'tienda') {
            $crudos = array_merge($crudos, $this->versiones_de_tienda_que_coinciden($palabras));
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

            return strcmp($a['_desempate'], $b['_desempate']);
        });

        /* La disponibilidad se calcula sobre TODAS las coincidencias y no solo sobre la página:
           el resumen tiene que decir si el cliente tiene la función aunque la versión que la trajo
           haya quedado afuera del `limit`. */
        $todos = [];
        foreach ($crudos as $crudo) {
            $todos[] = [
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

        return [
            'palabras'        => $palabras,
            'resultados'      => array_slice($todos, 0, $limit),
            'hay_mas'         => count($todos) > $limit,
            'resumen_cliente' => $cliente === null ? null : $this->resumen_para_el_cliente($crudos, $todos),
        ];
    }

    /**
     * El resumen de TODAS las coincidencias para el cliente.
     *
     *  - `tiene_alguna`: true si en al menos una coincidencia `cliente.tiene` es true.
     *  - `version_mas_vieja_que_la_trae`: la versión más vieja, entre las coincidencias que se
     *    publicaron alguna vez, por orden semántico. Es la que decide: si la misma función aparece
     *    en varias versiones, las posteriores son mejoras. Null si ninguna se publicó.
     *  - `sistema`: de qué sistema es esa versión. Si la búsqueda trajo coincidencias de los dos
     *    (no se filtró por `sistema`), manda `empresa`: las numeraciones no se comparan entre sí.
     *
     * @param array<int, array<string, mixed>> $crudos Coincidencias crudas, ya ordenadas.
     * @param array<int, array<string, mixed>> $todos  Las mismas, con su `cliente` calculado.
     *
     * @return array<string, mixed>
     */
    private function resumen_para_el_cliente(array $crudos, array $todos)
    {
        $tiene_alguna = false;
        foreach ($todos as $resultado) {
            if ($resultado['cliente']['tiene'] === true) {
                $tiene_alguna = true;
                break;
            }
        }

        $mas_vieja = ['empresa' => null, 'tienda' => null];
        foreach ($crudos as $crudo) {
            if (! $crudo['_comparable']) {
                continue;
            }

            $sistema = $crudo['sistema'];
            if ($mas_vieja[$sistema] === null || VersionNumberComparator::compare($crudo['version'], $mas_vieja[$sistema]) < 0) {
                $mas_vieja[$sistema] = $crudo['version'];
            }
        }

        $sistema = $mas_vieja['empresa'] !== null ? 'empresa' : ($mas_vieja['tienda'] !== null ? 'tienda' : null);

        return [
            'tiene_alguna'                  => $tiene_alguna,
            'version_mas_vieja_que_la_trae' => $sistema === null ? null : $mas_vieja[$sistema],
            'sistema'                       => $sistema,
        ];
    }

    /**
     * ¿El cliente tiene la versión de este resultado?
     *
     *  - `true`: la versión se publicó y la del cliente es igual o posterior.
     *  - `false`: la versión se publicó y la del cliente es anterior, o la versión es un borrador
     *    (un borrador no lo tiene nadie).
     *  - `null`: no se puede saber — no se indicó cliente, el cliente no tiene versión registrada,
     *    la versión está archivada sin haberse publicado nunca, o (para tienda) el cliente no tiene
     *    una tienda instalada con versión registrada.
     *
     * 🔴 UNA VERSIÓN ARCHIVADA QUE SE PUBLICÓ CUENTA COMO PUBLICADA. Archivar una versión la saca
     * de las opciones de upgrade; no les saca la funcionalidad a los clientes que ya pasaron por
     * ella. Decir «no la tiene» por el estado de hoy de la versión sería falso.
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

        if (! $resultado['_comparable']) {
            return $this->sin_version_comparable($resultado, $version_cliente);
        }

        $aclaracion = $resultado['publicada'] ? '' : ' (versión hoy ' . $this->estado_legible($resultado['version_status']) . ', publicada en su momento)';

        if (VersionNumberComparator::compare($version_cliente, $resultado['version']) >= 0) {
            return [
                'tiene'           => true,
                'version_cliente' => $version_cliente,
                'motivo'          => 'El cliente está en la ' . $version_cliente . ', igual o posterior a la ' . $resultado['version'] . $aclaracion . '.',
            ];
        }

        return [
            'tiene'           => false,
            'version_cliente' => $version_cliente,
            'motivo'          => 'El cliente está en la ' . $version_cliente . ', anterior a la ' . $resultado['version'] . $aclaracion . ': le falta actualizar.',
        ];
    }

    /**
     * La misma pregunta, contra la(s) tienda(s) del cliente.
     *
     * 🔴 SOLO DECIDEN LAS TIENDAS INSTALADAS (`status = active`). Una tienda `pending` o
     * `installing` no tiene nada desplegado: su versión (si la tuviera) no dice qué ve el
     * comprador, y contarla haría que una segunda tienda a medio instalar le «saque» la función a
     * la que sí anda. El motivo dice cuántas se dejaron afuera.
     *
     * Con varias tiendas instaladas, `tiene` es true solo si TODAS la tienen; `version_cliente` es
     * la más vieja de las registradas, que es la que decide.
     *
     * @param array<string, mixed> $resultado Resultado crudo (sistema tienda).
     * @param array<string, mixed> $cliente   Ítem del cliente.
     *
     * @return array<string, mixed>
     */
    private function disponibilidad_en_la_tienda(array $resultado, array $cliente)
    {
        $todas = $cliente['ecommerce'];

        if ($todas === []) {
            return ['tiene' => null, 'version_cliente' => null, 'motivo' => 'El cliente no tiene tienda.'];
        }

        $tiendas = [];
        foreach ($todas as $tienda) {
            if ($tienda['status'] === self::TIENDA_INSTALADA) {
                $tiendas[] = $tienda;
            }
        }

        $sin_instalar = count($todas) - count($tiendas);
        $nota         = $sin_instalar === 0
            ? ''
            : ' No se cuenta' . ($sin_instalar === 1 ? ' 1 tienda que no está instalada' : 'n ' . $sin_instalar . ' tiendas que no están instaladas') . ' (estado distinto de active).';

        if ($tiendas === []) {
            return ['tiene' => null, 'version_cliente' => null, 'motivo' => 'El cliente no tiene ninguna tienda instalada (estado active).' . $nota];
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
            return ['tiene' => null, 'version_cliente' => null, 'motivo' => 'La tienda del cliente no tiene versión registrada en el admin.' . $nota];
        }

        usort($con_version, function ($a, $b) {
            return VersionNumberComparator::compare($a, $b);
        });

        $mas_vieja = $con_version[0];

        if (! $resultado['_comparable']) {
            $respuesta            = $this->sin_version_comparable($resultado, $mas_vieja);
            $respuesta['motivo'] .= $nota;

            return $respuesta;
        }

        $varias     = count($tiendas) > 1;
        $aclaracion = $resultado['publicada'] ? '' : ' (versión hoy ' . $this->estado_legible($resultado['version_status']) . ', publicada en su momento)';

        if (VersionNumberComparator::compare($mas_vieja, $resultado['version']) < 0) {
            return [
                'tiene'           => false,
                'version_cliente' => $mas_vieja,
                'motivo'          => ($varias
                    ? 'El cliente tiene ' . count($tiendas) . ' tiendas instaladas y al menos una está en la ' . $mas_vieja . ', anterior a la ' . $resultado['version'] . $aclaracion . '.'
                    : 'La tienda del cliente está en la ' . $mas_vieja . ', anterior a la ' . $resultado['version'] . $aclaracion . ': le falta actualizar.') . $nota,
            ];
        }

        if ($sin_version > 0) {
            return [
                'tiene'           => null,
                'version_cliente' => $mas_vieja,
                'motivo'          => 'El cliente tiene ' . count($tiendas) . ' tiendas instaladas y ' . $sin_version . ' no tiene versión registrada: no se puede afirmar que todas la tengan.' . $nota,
            ];
        }

        return [
            'tiene'           => true,
            'version_cliente' => $mas_vieja,
            'motivo'          => ($varias
                ? 'Las ' . count($tiendas) . ' tiendas instaladas del cliente están en la ' . $resultado['version'] . ' o posterior (la más vieja, en la ' . $mas_vieja . ')' . $aclaracion . '.'
                : 'La tienda del cliente está en la ' . $mas_vieja . ', igual o posterior a la ' . $resultado['version'] . $aclaracion . '.') . $nota,
        ];
    }

    /**
     * La respuesta para una versión que no se puede comparar: un borrador (nadie lo tiene: false)
     * o una versión archivada que nunca se publicó (no se sabe: null).
     *
     * @param array<string, mixed> $resultado       Resultado crudo.
     * @param string|null          $version_cliente Versión del cliente.
     *
     * @return array<string, mixed>
     */
    private function sin_version_comparable(array $resultado, $version_cliente)
    {
        if ($resultado['version_status'] === 'draft') {
            return [
                'tiene'           => false,
                'version_cliente' => $version_cliente,
                'motivo'          => 'La versión ' . $resultado['version'] . ' todavía no se publicó.',
            ];
        }

        return [
            'tiene'           => null,
            'version_cliente' => $version_cliente,
            'motivo'          => 'La versión ' . $resultado['version'] . ' está ' . $this->estado_legible($resultado['version_status'])
                . ' y no tiene fecha de publicación: no se puede afirmar que algún cliente la haya recibido.',
        ];
    }

    /**
     * El estado de una versión, en castellano.
     *
     * @param string $status Estado de la versión.
     *
     * @return string
     */
    private function estado_legible($status)
    {
        $nombres = ['draft' => 'en borrador', 'published' => 'publicada', 'archived' => 'archivada'];

        return isset($nombres[$status]) ? $nombres[$status] : 'en estado ' . $status;
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
     * Forma interna de un resultado, con las claves internas (`_comparable`, `_orden`, `_desempate`)
     * que no salen en la respuesta.
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
            /* Se puede comparar contra la versión de un cliente si se publicó alguna vez: hoy
               publicada, o archivada con fecha de publicación. */
            '_comparable'    => (bool) $publicada || ((string) $fila->status === 'archived' && $fila->published_at !== null),
            '_orden'         => $fecha === null ? '' : (string) $fecha,
            '_desempate'     => $desempate,
        ];
    }

    /**
     * Las RAÍCES que se buscan, sacadas del texto del usuario:
     *
     *  1. en minúscula y sin signos de los bordes («¿facturas?» → «facturas»);
     *  2. sin las palabras vacías: las de la lista y toda palabra de una sola letra. Con AND por
     *     palabra, «cómo exportar el listado» exigiría que la novedad dijera «cómo» y «el». Las
     *     de dos letras que NO están en la lista se buscan: «QR», «ML» y «MP» son funciones;
     *  3. con el plural simple sacado, en palabras de más de 4 letras: termina en `es` → sin `es`;
     *     si no, termina en `s` → sin `s`. «facturas electrónicas» busca `factura` y `electrónica`,
     *     y «clientes» busca `client`, que encuentra «cliente» y «clientes». Como se busca con
     *     LIKE por subcadena, una raíz de más siempre encuentra lo mismo o más, nunca menos.
     *
     * Los acentos no se tocan: los resuelve la collation de las columnas (`utf8mb4_unicode_ci`,
     * para la que `electronica` = `electrónica`).
     *
     * @param string $q Texto buscado.
     *
     * @return array<int, string> Vacío si no quedó ninguna palabra útil.
     */
    public function palabras($q)
    {
        $partes = preg_split('/\s+/u', trim((string) $q));
        $partes = $partes === false ? [] : $partes;
        $salida = [];

        foreach ($partes as $parte) {
            $palabra = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', mb_strtolower($parte, 'UTF-8'));
            $palabra = $palabra === null ? '' : $palabra;

            if (mb_strlen($palabra, 'UTF-8') < 2 || in_array($palabra, self::PALABRAS_VACIAS, true)) {
                continue;
            }

            if (mb_strlen($palabra, 'UTF-8') > 4) {
                if (mb_substr($palabra, -2, null, 'UTF-8') === 'es') {
                    $palabra = mb_substr($palabra, 0, -2, 'UTF-8');
                } elseif (mb_substr($palabra, -1, null, 'UTF-8') === 's') {
                    $palabra = mb_substr($palabra, 0, -1, 'UTF-8');
                }
            }

            if (! in_array($palabra, $salida, true)) {
                $salida[] = $palabra;
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
