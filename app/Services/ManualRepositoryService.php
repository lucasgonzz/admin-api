<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lectura del manual del sistema desde el repositorio de conocimiento.
 *
 * El manual vive en `lucasgonzz/claude-comerciocity`, rama `main`, bajo el prefijo
 * `manual_sistema/`. Es contraintuitivo y conviene decirlo fuerte: los agentes leen la FUENTE
 * del manual, no el repo publicado. Por eso no dependen del paso de publicación ni necesitan
 * credenciales aparte de las que ya usa el admin.
 *
 * Nació el 2/9/2026 sacando esta lógica de `SupportAiSuggestionService`, cuando el agente de
 * leads también necesitó leer el manual. Hasta ese día soporte leía todo `manual_sistema/` y
 * leads solo sus 9 recursos de protocolo (1126 líneas), y esa asimetría le dejaba al agente de
 * leads dos únicas salidas ante una pregunta de producto que no estuviera en sus recursos:
 * escalar algo que el sistema sí hace, o inventar el detalle operativo.
 */
class ManualRepositoryService
{
    /**
     * URL base de la GitHub API (REST v3).
     */
    private const GITHUB_API_BASE = 'https://api.github.com';

    /**
     * Repositorio de conocimiento donde vive la fuente del manual.
     */
    private const GITHUB_REPO = 'lucasgonzz/claude-comerciocity';

    /**
     * Rama del repositorio a consultar.
     */
    private const GITHUB_BRANCH = 'main';

    /**
     * Único prefijo de ruta que este servicio sirve. Ver `verificar_ruta_del_manual()`.
     */
    private const PREFIJO_MANUAL = 'manual_sistema/';

    /**
     * Texto que se devuelve cuando el índice no se pudo leer.
     *
     * 🔴 No lo toques. `SupportAiSuggestionService::build_system_prompt()` detecta el fallo
     * mirando que la respuesta arranque con paréntesis y llena `$fallos_repositorio`, que es lo
     * que dispara `KnowledgeGroundingGate::escalar_por_repositorio_caido()`. Cambiar el string
     * (o sacarle el paréntesis inicial) deja al agente de soporte respondiendo sin manual y sin
     * enterarse de que le falta.
     */
    const FALLBACK_LISTA_NO_DISPONIBLE = '(Lista de archivos no disponible temporalmente.)';

    /**
     * Texto que se devuelve cuando el árbol cargó pero no trajo ningún `.md` del manual.
     * Mismo motivo que el de arriba para no tocarlo: arranca con paréntesis a propósito.
     */
    const FALLBACK_SIN_ARCHIVOS = '(No se encontraron archivos .md en el repositorio.)';

    /**
     * Clave de caché del índice de archivos del manual.
     */
    private const CACHE_KEY_INDICE = 'manual_repository:file_list';

    /**
     * Minutos que vive el índice cacheado.
     *
     * 🔴 POR QUÉ 10 MINUTOS Y NO MÁS. El índice es la lista de archivos del manual, y cambia
     * cuando alguien publica un archivo nuevo al repo de conocimiento. Diez minutos es el tiempo
     * que estamos dispuestos a que un archivo recién publicado sea invisible para el agente:
     * corto de sobra para que nadie lo note en una jornada de trabajo, largo de sobra para que
     * una ráfaga de sugerencias no salga a GitHub una vez por prompt. No se sube sin mirar el
     * otro lado: un índice viejo hace que el agente no vea un archivo que existe, que es
     * exactamente la clase de hueco que abrió esta misión.
     */
    private const CACHE_MINUTOS_INDICE = 10;

    /**
     * Índice de archivos del manual, cacheado unos minutos.
     *
     * Existe aparte de {@see self::file_list()} y no como su reemplazo a propósito: el agente de
     * soporte llama al método pelado y su conducta queda byte por byte como estaba (una lectura
     * por armado de prompt, que es donde el gate de citas mide si el repositorio está caído). El
     * cacheado lo usa el agente de leads, que arma el bloque del manual en cada system prompt y
     * lo hace desde cuatro sitios distintos: ahí la lectura era una llamada a GitHub con
     * `timeout(15)` metida en el camino de generación de una sugerencia.
     *
     * 🔴 UN FALLO NO SE CACHEA. Si el índice no se pudo bajar, `file_list()` devuelve su texto
     * de fallback y acá se devuelve tal cual **sin guardarlo**: cachear el fallback convertiría
     * un mal minuto de GitHub en diez minutos de agente sin manual, que es un fallo transitorio
     * ascendido a permanente. Solo se guarda un índice que se leyó de verdad.
     *
     * @return string Índice listo para el prompt, o el texto de fallback si no se pudo leer.
     */
    public function file_list_cacheada(): string
    {
        $cacheado = Cache::get(self::CACHE_KEY_INDICE);

        if (is_string($cacheado) && $cacheado !== '') {
            return $cacheado;
        }

        $indice = $this->file_list();

        /* Los dos fallbacks arrancan con paréntesis —es su marca, ver las constantes de arriba—
         * y son justamente lo que no se guarda. Se compara contra las constantes y no contra el
         * paréntesis: si mañana el fallback cambia de forma, esto sigue diciendo lo que quiere
         * decir. */
        if ($indice === self::FALLBACK_LISTA_NO_DISPONIBLE || $indice === self::FALLBACK_SIN_ARCHIVOS) {
            return $indice;
        }

        Cache::put(self::CACHE_KEY_INDICE, $indice, now()->addMinutes(self::CACHE_MINUTOS_INDICE));

        return $indice;
    }

    /**
     * Índice de archivos del manual, formateado para inyectar en un system prompt.
     *
     * No lanza: un índice que no carga se resuelve distinto en cada agente (soporte escala,
     * leads sigue con su protocolo), así que la decisión es del llamador y acá solo se devuelve
     * el texto de fallback.
     *
     * @return string Lista con prefijo "- " por línea, o mensaje de fallback si falla la API.
     */
    public function file_list(): string
    {
        try {
            $url      = self::GITHUB_API_BASE.'/repos/'.self::GITHUB_REPO.'/git/trees/'.self::GITHUB_BRANCH.'?recursive=1';
            $response = $this->build_github_http_client()->get($url);

            if ($response->failed()) {
                return self::FALLBACK_LISTA_NO_DISPONIBLE;
            }

            $tree  = $response->json('tree') ?? [];
            $paths = [];

            foreach ($tree as $node) {
                if (! is_array($node) || ($node['type'] ?? '') !== 'blob') {
                    continue;
                }

                $path = (string) ($node['path'] ?? '');

                /* Los dos filtros de siempre: solo `.md` y solo bajo el prefijo del manual.
                 * `strncmp()` y no `str_starts_with()`: esto corre en PHP 7.4. */
                if (substr($path, -3) !== '.md') {
                    continue;
                }

                if (strncmp($path, self::PREFIJO_MANUAL, strlen(self::PREFIJO_MANUAL)) !== 0) {
                    continue;
                }

                $paths[] = '- '.$path;
            }

            return empty($paths)
                ? self::FALLBACK_SIN_ARCHIVOS
                : implode("\n", $paths);

        } catch (\Throwable $e) {
            Log::warning('ManualRepositoryService: no se pudo obtener lista de archivos del manual.', [
                'error' => $e->getMessage(),
            ]);

            return self::FALLBACK_LISTA_NO_DISPONIBLE;
        }
    }

    /**
     * Descarga y decodifica el contenido de un archivo del manual.
     *
     * Lanza en vez de devolver vacío a propósito: el llamador tiene que poder distinguir "leí
     * el archivo y dice tal cosa" de "no lo pude leer", porque de esa distinción depende que la
     * ruta entre o no en la evidencia contra la que `KnowledgeGroundingGate` verifica las citas.
     * Un string vacío indistinguible de un archivo vacío rompe justamente eso.
     *
     * @param string $path Ruta dentro del repo, con el prefijo `manual_sistema/` incluido.
     *
     * @return string Contenido del archivo en texto plano.
     *
     * @throws \RuntimeException Si la ruta está vacía, cae fuera del manual, o la API responde
     *                           con error.
     */
    public function get_file(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new \RuntimeException('La ruta del archivo no puede estar vacía.');
        }

        /* El modelo escribe la ruta a mano y a veces le pone la barra inicial. Se saca antes de
         * la guarda —no dentro— para que la guarda siga viendo una sola forma canónica. Es la
         * misma normalización que hace KnowledgeGroundingGate::normalizar_lista() del otro lado,
         * así que la ruta que se anota como leída y la que el agente cita coinciden igual. */
        $path = ltrim($path, '/');

        $this->verificar_ruta_del_manual($path);

        $encoded_path = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url          = self::GITHUB_API_BASE.'/repos/'.self::GITHUB_REPO.'/contents/'.$encoded_path.'?ref='.self::GITHUB_BRANCH;

        $response = $this->build_github_http_client()->get($url);

        if ($response->failed()) {
            throw new \RuntimeException('GitHub API error '.$response->status().' al leer '.$path.'.');
        }

        $encoding = (string) ($response->json('encoding') ?? '');
        $content  = (string) ($response->json('content') ?? '');

        if ($encoding === 'base64') {
            return base64_decode(str_replace("\n", '', $content));
        }

        // Fallback: la API puede devolver el texto directo en repos pequeños.
        return $content;
    }

    /**
     * Verifica que la ruta pedida caiga adentro del manual.
     *
     * @param string $path Ruta ya trimeada.
     *
     * @return void
     *
     * @throws \RuntimeException Si la ruta cae fuera de `manual_sistema/`.
     */
    private function verificar_ruta_del_manual(string $path): void
    {
        /* 🔴 ESTA GUARDA NO SE SACA, y si te parece de más es porque estás mirando solo el
         * manual. `lucasgonzz/claude-comerciocity` NO es el repo del manual: es el repo de
         * conocimiento entero, con el material comercial, los informes internos de cada misión
         * y el contexto maestro. La ruta que llega acá la elige el modelo a partir de lo que le
         * pide un lead por WhatsApp, así que el modelo no puede ser el control: sin este
         * chequeo, la tool `get_manual_file` es un lector arbitrario de todo el repo y cualquiera
         * que sepa pedir la ruta se lleva puesto material que no va a un prompt de ventas.
         * Si algún día hace falta servir otra carpeta, se agrega otro prefijo explícito acá.
         * No se afloja el chequeo. */
        if (strncmp($path, self::PREFIJO_MANUAL, strlen(self::PREFIJO_MANUAL)) !== 0) {
            throw new \RuntimeException(
                'Ruta fuera del manual: solo se pueden leer archivos bajo "'.self::PREFIJO_MANUAL.'".'
            );
        }

        /* `..` escapa del prefijo sin dejar de empezar con él ("manual_sistema/../comercial/x.md"),
         * y `rawurlencode()` lo deja pasar tal cual porque el punto es un carácter no reservado.
         * Se compara el segmento entero y no el substring para no voltear un nombre de archivo
         * legítimo que tenga dos puntos seguidos. */
        foreach (explode('/', $path) as $segmento) {
            if ($segmento === '..') {
                throw new \RuntimeException('Ruta inválida: no se permiten segmentos "..".');
            }
        }
    }

    /**
     * Cliente HTTP para GitHub API con token de autenticación si está configurado.
     *
     * @return PendingRequest
     */
    private function build_github_http_client(): PendingRequest
    {
        $token = (string) config('services.github.token', '');

        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'ComercioCity-Admin/1.0',
        ];

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $http = Http::withHeaders($headers)->timeout(15);

        // Misma configuración TLS que Anthropic (WAMP/Windows suele requerir ca_bundle o verify_ssl=false).
        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }
}
