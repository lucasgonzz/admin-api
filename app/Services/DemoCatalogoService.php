<?php

namespace App\Services;

use App\Models\SyncedGithubFile;
use Illuminate\Support\Facades\Log;

/**
 * Lee el catálogo de la demo (`contexto/demo_catalogo.json`, sincronizado desde GitHub por
 * {@see AgentPromptSyncService} bajo la clave `demo_catalogo_json`) y lo expone en el formato
 * plano que consume la pantalla de multimedia del admin (grupo 300, prompt 02 —
 * `contexto/demo_experiencia.md` §3.18).
 *
 * La estructura (qué clips existen, su orden, tipo) vive en el repo y se versiona; las URLs
 * de cada slot viven en la base (tabla `demo_media`, ver {@see \App\Models\DemoMedia}). Este
 * servicio solo resuelve la parte "estructura": nunca lanza excepción, porque una pantalla de
 * configuración vacía es un problema menor y un 500 en el admin no lo es.
 */
class DemoCatalogoService
{
    /** Clave interna con la que AgentPromptSyncService persiste el catálogo sincronizado. */
    const SYNCED_FILE_KEY = 'demo_catalogo_json';

    /**
     * Decodifica el catálogo sincronizado desde `SyncedGithubFile`.
     *
     * Devuelve array vacío (con un `Log::warning`) si el archivo todavía no se sincronizó, si
     * vino vacío o si el JSON es inválido. Nunca lanza excepción.
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        // Registro sincronizado por AgentPromptSyncService bajo la clave del catálogo.
        $synced = SyncedGithubFile::obtener_por_key(self::SYNCED_FILE_KEY);

        if (! $synced || trim((string) $synced->content) === '') {
            Log::warning('DemoCatalogoService: el catálogo de la demo todavía no está sincronizado.');

            return [];
        }

        // Decodifica el JSON del catálogo a array asociativo.
        $decoded = json_decode((string) $synced->content, true);

        if (! is_array($decoded)) {
            Log::warning('DemoCatalogoService: el catálogo sincronizado no es un JSON válido.', [
                'json_error' => json_last_error_msg(),
            ]);

            return [];
        }

        return $decoded;
    }

    /**
     * Devuelve la lista PLANA de los slots del catálogo, en el orden en que se muestran en la
     * pantalla del admin: primero los clips de sección (agrupados según `orden_secciones`),
     * después los videos sueltos (intro/cierre) y por último las piezas del scroll de la página
     * inmersiva.
     *
     * Cada slot queda normalizado con `id`, `titulo`, `grupo` (nombre de sección, o "Videos fuera
     * de sección" / "Scroll de la página inmersiva") y los campos extra que traiga (`tipo`,
     * `condicion`, `marco`).
     *
     * Si el catálogo no está sincronizado o es inválido, devuelve `[]` (nunca lanza excepción,
     * delegado en {@see self::get()}).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function slots(): array
    {
        $catalogo = self::get();

        if (empty($catalogo)) {
            return [];
        }

        // Acumulador final, en el orden de exhibición: clips de sección, videos sueltos, scroll.
        $slots = [];

        // Orden de las secciones declarado en el catálogo (ej: "S1 - Listado", "S2 - Vender", ...).
        // Los clips se agrupan y ordenan según esta lista; una sección no listada acá (no debería
        // pasar, pero el JSON viene del repo y puede tener errores humanos) se ignora en el orden
        // pero no se pierde el clip: se agrega al final, antes de los videos sueltos.
        $orden_secciones = isset($catalogo['orden_secciones']) && is_array($catalogo['orden_secciones'])
            ? $catalogo['orden_secciones']
            : [];

        $clips = isset($catalogo['clips']) && is_array($catalogo['clips']) ? $catalogo['clips'] : [];

        // Recorre las secciones en el orden declarado y agrega los clips de cada una.
        foreach ($orden_secciones as $seccion) {
            foreach ($clips as $clip) {
                if (($clip['seccion'] ?? null) === $seccion) {
                    $slots[] = self::normalizar_slot($clip, $seccion);
                }
            }
        }

        // Clips cuya sección no apareció en orden_secciones: se agregan al final de los clips de
        // sección, en el orden en que vienen en el JSON, para no perderlos silenciosamente.
        $secciones_conocidas = $orden_secciones;
        foreach ($clips as $clip) {
            $seccion = $clip['seccion'] ?? null;
            if ($seccion !== null && ! in_array($seccion, $secciones_conocidas, true)) {
                $slots[] = self::normalizar_slot($clip, $seccion);
            }
        }

        // Videos sueltos (intro, cierre): van después de todos los clips de sección.
        $videos_sueltos = isset($catalogo['videos_sueltos']) && is_array($catalogo['videos_sueltos'])
            ? $catalogo['videos_sueltos']
            : [];
        foreach ($videos_sueltos as $video) {
            $slots[] = self::normalizar_slot($video, $video['grupo'] ?? 'Videos fuera de sección');
        }

        // Piezas del scroll de la página inmersiva: van al final de todo.
        $piezas_scroll = isset($catalogo['piezas_scroll']) && is_array($catalogo['piezas_scroll'])
            ? $catalogo['piezas_scroll']
            : [];
        foreach ($piezas_scroll as $pieza) {
            $slots[] = self::normalizar_slot($pieza, $pieza['grupo'] ?? 'Scroll de la página inmersiva');
        }

        return $slots;
    }

    /**
     * Normaliza una entrada cruda del catálogo (clip, video suelto o pieza de scroll) al formato
     * plano `id`/`titulo`/`grupo` + extras que consume la pantalla del admin.
     *
     * @param array<string, mixed> $entrada Entrada cruda del JSON del catálogo.
     * @param string                $grupo   Nombre de la sección o grupo al que pertenece.
     *
     * @return array<string, mixed>
     */
    protected static function normalizar_slot(array $entrada, string $grupo): array
    {
        return [
            'id'        => $entrada['id'] ?? null,
            'titulo'    => $entrada['titulo'] ?? null,
            'grupo'     => $grupo,
            // Campos extra opcionales, solo si vienen en la entrada original (no todas las
            // entradas del catálogo los traen: los videos sueltos, por ejemplo, no tienen
            // "condicion" ni "marco").
            'tipo'      => $entrada['tipo'] ?? null,
            'condicion' => $entrada['condicion'] ?? null,
            'marco'     => $entrada['marco'] ?? null,
        ];
    }
}
