<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadPipelineStatus;

/**
 * Política del perfil «Sólo Leads» (misión embudo-whatsapp-precios, etapa E5).
 *
 * Es la ÚNICA fuente de lo que ese perfil puede hacer: la lista de rutas permitidas, la restricción por
 * parámetro de cada una, los campos que puede escribir y los canales de broadcasting que puede escuchar.
 * El middleware `admin.solo_leads` (SoloLeadsAllowlist) y `routes/channels.php` sólo la consultan: ninguna
 * otra parte del código mira el flag `admins.solo_leads` ni repite la lista.
 *
 * Es una lista BLANCA a propósito: una ruta nueva queda cerrada para el perfil hasta que alguien la agregue
 * acá, de forma explícita. Una lista negra dejaría abierta cada ruta que se escriba mañana.
 *
 * Decisión de producto (P4 de la misión): el perfil sólo LEE las conversaciones; cambia el estado del lead
 * y edita su nota. No le escribe al lead (envíos, aprobaciones, reacciones: rutas «WA», P5) ni cambia el
 * estado compartido del chat de los setters (marcar leído, fijar, respondido).
 *
 * Es casi pura: no toca la red y no depende del request. La única consulta a la base es el catálogo de
 * estados del pipeline (`estado_de_lead_valido()`). Los tests la invocan directo.
 *
 * Salida (hallazgo bloqueante de la revisión de E5): además de CERRAR las rutas, la política decide qué
 * puede VER el perfil en las respuestas de las rutas que sí tiene. Es una lista blanca de salida
 * (`filtrar_salida()`), con cierre por defecto: lo que no está listado se elimina. El middleware la aplica
 * a toda respuesta JSON del perfil; un admin normal nunca pasa por ahí.
 */
class AdminSoloLeadsPolicy
{
    /**
     * Código del cuerpo del 403 del perfil. El SPA lo usa para no mostrar toast en los pedidos de fondo.
     */
    const CODIGO_DE_RESPUESTA = 'solo_leads';

    /**
     * Mensaje del cuerpo del 403 del perfil (contrato del plan, §2.6).
     */
    const MENSAJE_DE_RESPUESTA = 'Tu perfil sólo tiene acceso a Leads.';

    /**
     * Rutas permitidas: cadenas "<MÉTODO> <uri>" exactamente como las devuelve `Route::uri()` (sin barra
     * inicial y con los marcadores de Laravel). Cada entrada tiene que corresponder a una ruta real que
     * lleve el middleware `admin.solo_leads` (lo verifica TodaRutaAutenticadaPasaPorElPerfilTest).
     *
     * @var array<int, string>
     */
    const RUTAS_PERMITIDAS = [
        // Sesión y arranque de la SPA.
        'GET api/admin/me',
        'POST api/admin/logout',
        'GET api/admin/version',
        // Campos del modal del lead (sólo para el modelo lead: ver RESTRICCIONES_DE_PARAMETROS).
        'GET api/admin/meta/{model}',
        // Lista de leads, tarjetas por estado, chats y contadores.
        'GET api/admin/lead',
        'GET api/admin/lead/status-cards',
        'GET api/admin/lead/chats',
        'GET api/admin/lead/chats/{id}',
        'GET api/admin/lead/unread-badges',
        // Ficha e hilo de la conversación; cambiar estado y nota (campos en CAMPOS_EDITABLES).
        'GET api/admin/lead/{id}',
        'PUT api/admin/lead/{id}',
        // Su propia campanita del lead.
        'POST api/admin/lead/{id}/toggle-notify-messages',
        // Lo que lee la conversación y el panel de demos al abrir.
        'GET api/admin/settings/lead-whatsapp-onboarding',
        'GET api/admin/settings/lead-demo',
        // Pila de avisos de tareas.
        'GET api/admin/task-notification/pending',
        'POST api/admin/task-notification/{id}/seen',
        'POST api/admin/task-notification/seen-all',
        // Notificaciones push.
        'GET api/admin/push/vapid-public-key',
        'POST api/admin/push/subscribe',
        'POST api/admin/push/unsubscribe',
        'POST api/admin/push/subscription-status',
        // Autorización de sus canales privados (fuera de api/admin) y salida del panel Blade.
        'POST broadcasting/auth',
        'POST logout',
    ];

    /**
     * Rutas del tablero de Juan (misión embudo-juan-pipeline, E1): se suman a `RUTAS_PERMITIDAS` SIN tocarla, porque los tests
     * de E5 que fijan esa lista (23 rutas) tienen que seguir verdes. Mismo formato: «<MÉTODO> <uri>» como `Route::uri()`.
     * Cada entrada existe y lleva `admin.solo_leads` (lo verifica `PerfilSoloLeadsEnElTableroTest`).
     *
     * `GET lead-tablero/candidatos` (E1b) NO está: es del agente, Juan no la necesita y para él da 403.
     *
     * @var array<int, string>
     */
    const RUTAS_DEL_TABLERO = [
        'GET api/admin/pipelines/meta',
        'GET api/admin/pipelines',
        'GET api/admin/pipelines/{id}/opportunities',
        'GET api/admin/pipeline-opportunities/{id}',
        'PUT api/admin/pipeline-opportunities/{id}',
        'POST api/admin/pipeline-opportunities/{id}/move',
        'POST api/admin/pipeline-opportunities/{id}/notes',
        'POST api/admin/lead/{id}/llamadas',
        'GET api/admin/lead/{id}/llamadas',
        'GET api/admin/lead-tablero/mis-tareas',
        'POST api/admin/lead-tablero/tareas/{id}/hecha',
    ];

    /**
     * Rutas del tablero cuyo recurso hay que verificar contra la base: «<MÉTODO> <uri>» => regla de
     * {@see SoloLeadsRecursos}. Sin esto el perfil abriría el tablero y las tarjetas de CUALQUIER pipeline (por ejemplo
     * «Agentes»): la URL sola no dice a qué pipeline pertenece el `{id}`.
     *
     * @var array<string, string>
     */
    const RECURSOS_DEL_TABLERO = [
        'GET api/admin/pipelines/{id}/opportunities'       => SoloLeadsRecursos::PIPELINE_DE_LEADS,
        'GET api/admin/pipeline-opportunities/{id}'        => SoloLeadsRecursos::OPORTUNIDAD_DE_LEADS,
        'PUT api/admin/pipeline-opportunities/{id}'        => SoloLeadsRecursos::OPORTUNIDAD_DE_LEADS,
        'POST api/admin/pipeline-opportunities/{id}/move'  => SoloLeadsRecursos::OPORTUNIDAD_DE_LEADS,
        'POST api/admin/pipeline-opportunities/{id}/notes' => SoloLeadsRecursos::OPORTUNIDAD_DE_LEADS,
    ];

    /**
     * Claves anidadas que el perfil ve SÓLO en las respuestas de `RUTAS_DEL_TABLERO` (las rutas de Leads no cambian de
     * filtro). `call`, la Meet con su transcripción, queda afuera a propósito.
     *
     * @var array<int, string>
     */
    const CLAVES_DEL_TABLERO = [
        'opportunities', 'opportunity', 'activity', 'activities', 'resumen', 'por_etapa', 'motivos_perdida', 'agenda',
        'stage', 'stages', 'subject', 'owner', 'last_activity', 'pipeline', 'pipelines', 'fields', 'lost_reasons',
        'ultima_llamada', 'llamada', 'oportunidad', 'admin', 'cargada_por',
    ];

    /**
     * Restricciones por parámetro de ruta: "<MÉTODO> <uri>" => [nombre del parámetro => valores permitidos].
     * Una ruta con restricción sólo se permite si TODOS sus parámetros restringidos traen un valor de la lista.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    const RESTRICCIONES_DE_PARAMETROS = [
        // `meta/{model}` sirve el catálogo de campos de cualquier modelo: el perfil sólo ve el de lead.
        'GET api/admin/meta/{model}' => ['model' => ['lead']],
    ];

    /**
     * Campos del cuerpo que el perfil puede escribir en una ruta: "<MÉTODO> <uri>" => [campos]. En las rutas
     * de esta lista el resto del cuerpo se descarta (no se rechaza). Las demás rutas pasan sin tocar.
     *
     * @var array<string, array<int, string>>
     */
    const CAMPOS_EDITABLES = [
        // Cambiar el estado y la nota; nada más del lead (nombre, teléfono, empresa, etc.).
        'PUT api/admin/lead/{id}' => ['status', 'notes'],
        // Tablero de Juan: la próxima acción de la tarjeta; el responsable no se cambia.
        'PUT api/admin/pipeline-opportunities/{id}' => ['next_action_at', 'next_action_note'],
    ];

    /**
     * Rutas cuyo cuerpo trae un `status` de lead que tiene que existir en el catálogo de estados del
     * pipeline (fijos + dinámicos). Un valor inexistente da 422 y no guarda nada. Sólo rige para el perfil.
     *
     * @var array<int, string>
     */
    const RUTAS_CON_ESTADO_DE_LEAD = [
        'PUT api/admin/lead/{id}',
    ];

    /**
     * Patrones de canales de broadcasting que el perfil puede escuchar, tal como están registrados en
     * `routes/channels.php`. El resto de los canales le da acceso denegado.
     *
     * @var array<int, string>
     */
    const CANALES_PERMITIDOS = [
        // Su canal propio: por acá viajan los avisos in-app de tareas (el callback ya exige que sea el suyo).
        'admin.{admin_id}',
        // Alertas de verificación por agendamiento: el plan lo lista para el perfil.
        'verificacion-agendamiento-alerts',
    ];

    /**
     * Patrón de nombre de clave que NUNCA sale al perfil, a ninguna profundidad y en ninguna ruta: claves de
     * API, tokens, secretos, contraseñas y «clave». Es la red de seguridad de las columnas propias (un
     * `*_token` nuevo en `leads` no viaja solo); las relaciones anidadas ya van acotadas por
     * CAMPOS_MINIMOS_DE_RELACIONES, así que este patrón no es la defensa principal.
     */
    const PATRON_DE_CLAVE_SECRETA = '/api_key|token|secret|password|clave/i';

    /**
     * Claves que no matchean el patrón anterior pero tampoco salen al perfil. `demo_ingreso_url` es el link
     * de ingreso a la demo del lead: lleva el token en la URL (la clave no dice «token»).
     *
     * @var array<int, string>
     */
    const CLAVES_SENSIBLES_EXACTAS = [
        'demo_ingreso_url',
    ];

    /**
     * Relaciones anidadas que el perfil ve en forma MÍNIMA: clave de la respuesta => campos escalares que se
     * conservan (el resto de columnas y todo lo anidado se elimina). Se decidió mirando lo que lee la pantalla
     * de Leads del SPA (`Leads.vue`, `LeadConversationView.vue`, `components/lead/*`, la celda genérica de la
     * tabla que muestra `created_by_admin.name`):
     *
     * - target_client / promoted_client: `name` y `company_name` (la fila de la lista arma la etiqueta del
     *   cliente) más `id`. Sus claves de API, cobranzas, contrato, precios y AFIP NUNCA salen.
     * - demo: `nombre` y `erp_spa_url` (etiqueta de la fila) más `id`; los otros cinco campos son los de la
     *   lista de chats (`demo` de LeadChatsController: fecha y hora de la demo), que no son un secreto. La
     *   `api_key` y las URL/rutas de la API y del hosting de la demo NUNCA salen.
     * - admins (creador, emisor de un mensaje, reacción, asignados de una tarea): `id` y `name`; sin email,
     *   teléfono ni flags.
     * - lead (dentro de una tarea): lo que pinta el aviso.
     *
     * @var array<string, array<int, string>>
     */
    const CAMPOS_MINIMOS_DE_RELACIONES = [
        'target_client'     => ['id', 'name', 'company_name'],
        'promoted_client'   => ['id', 'name', 'company_name'],
        'demo'              => ['id', 'nombre', 'erp_spa_url', 'demo_id', 'demo_date', 'demo_start_time', 'demo_end_time', 'demo_setup_status'],
        'created_by_admin'  => ['id', 'name'],
        'sent_by_admin'     => ['id', 'name'],
        'admin_reaction_by' => ['id', 'name'],
        'assigned_admin'    => ['id', 'name'],
        'assigned_admins'   => ['id', 'name'],
        'done_by_admin'     => ['id', 'name'],
        'lead'              => ['id', 'contact_name', 'company_name'],
    ];

    /**
     * Claves que SÍ pueden contener algo anidado en una respuesta del perfil: los envoltorios de las rutas
     * permitidas (`model`, `models`, `data`, `meta`, `links`, ...), las relaciones propias del módulo de leads
     * (mensajes, adjuntos, mensajes programados, videos, socios, última llamada) y las columnas JSON propias
     * del lead, sus mensajes y sus programados. Cualquier otra clave con un objeto o una lista adentro se
     * elimina: una relación nueva que alguien cargue mañana (un `client` más, una `subscription`) no se
     * filtra sola. Para sumar una, se agrega acá a propósito y se escribe su test.
     *
     * @var array<int, string>
     */
    const CLAVES_ANIDADAS_PERMITIDAS = [
        // Envoltorios de las rutas permitidas.
        'admin', 'model', 'models', 'data', 'meta', 'links', 'errors', 'task', 'cards', 'slugs',
        'unread_by_status', 'cta_whatsapp_textos', 'properties', 'options', 'value',
        // Relaciones propias del módulo de leads.
        'messages', 'attachments', 'scheduled_messages', 'personalized_demo_videos', 'partners',
        'last_summarized_call', 'ultimo_mensaje', 'demo_form_panel', 'respuestas', 'pending_actions_summary',
        // Columnas JSON propias del lead, de sus mensajes, de los programados y de las llamadas.
        'contract_financiacion', 'contract_clausulas_particulares', 'contract_precios_sistemas',
        'contract_sistemas', 'contract_cotizacion_items', 'demo_summary_structured', 'call_summary',
        'demo_plan', 'admin_notifications', 'pending_actions', 'horarios_ofrecidos',
        'applied_actions_summary', 'actions_override_log', 'calendar_snapshot', 'template_variables',
    ];

    /**
     * Indica si el usuario tiene el perfil restringido. Un usuario nulo, o un admin sin la columna (base
     * todavía sin migrar) o con el flag en false, no lo tiene.
     *
     * @param mixed $usuario Usuario autenticado (Admin) o null.
     *
     * @return bool
     */
    public function tiene_perfil($usuario): bool
    {
        return $usuario !== null && (bool) ($usuario->solo_leads ?? false);
    }

    /**
     * Arma la clave "<MÉTODO> <uri>" con la que se busca en las listas. HEAD se trata como GET (Laravel lo
     * agrega solo a toda ruta GET) y la barra inicial de la uri se ignora.
     *
     * @param string $metodo Verbo HTTP.
     * @param string $uri    Uri de la ruta, como la devuelve `Route::uri()`.
     *
     * @return string
     */
    private function clave_de_ruta(string $metodo, string $uri): string
    {
        $metodo = strtoupper($metodo);
        if ($metodo === 'HEAD') {
            $metodo = 'GET';
        }

        return $metodo . ' ' . ltrim($uri, '/');
    }

    /**
     * Dice si el perfil puede usar una ruta: tiene que estar en la lista y, si tiene restricción por
     * parámetro, cumplirla.
     *
     * @param string               $metodo     Verbo HTTP.
     * @param string               $uri        Uri de la ruta con sus marcadores (`Route::uri()`).
     * @param array<string, mixed> $parametros Parámetros resueltos de la ruta (`Route::parameters()`).
     *
     * @return bool
     */
    public function permite(string $metodo, string $uri, array $parametros): bool
    {
        $clave = $this->clave_de_ruta($metodo, $uri);

        if (! in_array($clave, self::RUTAS_PERMITIDAS, true) && ! in_array($clave, self::RUTAS_DEL_TABLERO, true)) {
            return false;
        }

        $restricciones = self::RESTRICCIONES_DE_PARAMETROS[$clave] ?? [];
        foreach ($restricciones as $nombre => $valores_permitidos) {
            // Un parámetro restringido que no llega, o que no es un valor de la lista, cierra la ruta.
            if (! isset($parametros[$nombre]) || ! is_scalar($parametros[$nombre])
                || ! in_array((string) $parametros[$nombre], $valores_permitidos, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * La regla de recurso que hay que verificar contra la base para una ruta del tablero (null si no tiene).
     *
     * @param string $metodo Verbo HTTP.
     * @param string $uri    Uri de la ruta con sus marcadores.
     *
     * @return string|null Una constante de {@see SoloLeadsRecursos}.
     */
    public function regla_de_recurso(string $metodo, string $uri)
    {
        $clave = $this->clave_de_ruta($metodo, $uri);

        return isset(self::RECURSOS_DEL_TABLERO[$clave]) ? self::RECURSOS_DEL_TABLERO[$clave] : null;
    }

    /**
     * Deja del cuerpo sólo los campos que el perfil puede escribir en esa ruta. Si la ruta no tiene
     * campos editables definidos, devuelve el cuerpo igual. Se descarta, no se rechaza: el endpoint no pisa
     * lo que no viene.
     *
     * @param string               $metodo Verbo HTTP.
     * @param string               $uri    Uri de la ruta con sus marcadores.
     * @param array<string, mixed> $input  Cuerpo (o query) del pedido.
     *
     * @return array<string, mixed>
     */
    public function filtrar_cuerpo(string $metodo, string $uri, array $input): array
    {
        $clave = $this->clave_de_ruta($metodo, $uri);

        if (! isset(self::CAMPOS_EDITABLES[$clave])) {
            return $input;
        }

        return array_intersect_key($input, array_flip(self::CAMPOS_EDITABLES[$clave]));
    }

    /**
     * Indica si hay campos editables definidos para la ruta (el middleware sólo filtra en ese caso).
     *
     * @param string $metodo Verbo HTTP.
     * @param string $uri    Uri de la ruta con sus marcadores.
     *
     * @return bool
     */
    public function tiene_campos_editables(string $metodo, string $uri): bool
    {
        return isset(self::CAMPOS_EDITABLES[$this->clave_de_ruta($metodo, $uri)]);
    }

    /**
     * Dice si el usuario puede escuchar un canal de broadcasting. Lo consultan los callbacks de
     * `routes/channels.php`: un usuario sin el perfil no se ve afectado (devuelve true y el callback decide
     * como siempre); con el perfil sólo pasan los canales de CANALES_PERMITIDOS.
     *
     * @param mixed  $usuario Usuario del callback del canal.
     * @param string $patron  Patrón del canal tal como se registró (ej. `admin.{admin_id}`).
     *
     * @return bool
     */
    public function canal_permitido($usuario, string $patron): bool
    {
        if (! $this->tiene_perfil($usuario)) {
            return true;
        }

        return in_array($patron, self::CANALES_PERMITIDOS, true);
    }

    /**
     * Filtra el cuerpo JSON de una respuesta para el perfil (lista blanca de salida, cierre por defecto).
     * Recibe el valor decodificado (`json_decode` sin `assoc`: objetos como stdClass) y devuelve uno del
     * mismo tipo. Reglas, en orden:
     *
     * 1. Una clave que matchea PATRON_DE_CLAVE_SECRETA o está en CLAVES_SENSIBLES_EXACTAS se elimina, a
     *    cualquier profundidad.
     * 2. Un valor escalar (o null) se conserva: es una columna propia del recurso.
     * 3. Un objeto o lista bajo una clave de CAMPOS_MINIMOS_DE_RELACIONES queda reducido a esos campos.
     * 4. Un objeto o lista bajo una clave de CLAVES_ANIDADAS_PERMITIDAS se recorre con estas mismas reglas.
     * 5. Cualquier otro objeto o lista se elimina.
     *
     * @param mixed       $valor         Valor decodificado del cuerpo de la respuesta.
     * @param string|null $clave_de_ruta «<MÉTODO> <uri>» de la ruta (opcional): en las del tablero se suman las claves de
     *                                   `CLAVES_DEL_TABLERO`. Un llamador que no lo pasa filtra como antes.
     *
     * @return mixed El mismo valor sin lo que el perfil no puede ver.
     */
    public function filtrar_salida($valor, ?string $clave_de_ruta = null)
    {
        /* Sólo las rutas del tablero suman las claves anidadas del tablero; el resto filtra como siempre. */
        $extra = $clave_de_ruta !== null && in_array($clave_de_ruta, self::RUTAS_DEL_TABLERO, true) ? self::CLAVES_DEL_TABLERO : [];

        return $this->filtrar_nodo($valor, $extra);
    }

    /**
     * Recorre un nodo (objeto, lista o escalar) aplicando las reglas de `filtrar_salida()`.
     *
     * @param mixed              $nodo         Nodo a filtrar.
     * @param array<int, string> $claves_extra Claves anidadas que además se permiten (las del tablero, según la ruta).
     *
     * @return mixed
     */
    private function filtrar_nodo($nodo, array $claves_extra = [])
    {
        if (is_array($nodo) && $this->es_lista($nodo)) {
            return array_map(function ($hijo) use ($claves_extra) {
                return $this->filtrar_nodo($hijo, $claves_extra);
            }, $nodo);
        }

        if (! is_object($nodo) && ! is_array($nodo)) {
            return $nodo;
        }

        $es_objeto = is_object($nodo);
        $salida    = [];
        foreach (($es_objeto ? get_object_vars($nodo) : $nodo) as $clave => $hijo) {
            $clave = (string) $clave;

            if ($this->es_clave_sensible($clave)) {
                continue;
            }

            // Escalar o null: columna propia del recurso.
            if (! is_object($hijo) && ! is_array($hijo)) {
                $salida[$clave] = $hijo;
                continue;
            }

            /* Los errores de validación son sólo textos por campo (`errors.<campo>: [mensajes]`): el perfil los ve completos, porque sin
               ellos «Registrar llamada» no puede decirle a Juan qué le falta. */
            if ($clave === 'errors') {
                $salida[$clave] = $this->filtrar_errores($hijo);
                continue;
            }

            if (isset(self::CAMPOS_MINIMOS_DE_RELACIONES[$clave])) {
                $salida[$clave] = $this->reducir_a_campos($hijo, self::CAMPOS_MINIMOS_DE_RELACIONES[$clave]);
            } elseif (in_array($clave, self::CLAVES_ANIDADAS_PERMITIDAS, true) || in_array($clave, $claves_extra, true)) {
                $salida[$clave] = $this->filtrar_nodo($hijo, $claves_extra);
            }
            // Cualquier otra relación u objeto anidado no listado se elimina.
        }

        return $es_objeto ? (object) $salida : $salida;
    }

    /**
     * Deja pasar los errores de validación (`errors`) SÓLO como textos por campo: cada campo es una lista de textos (o un texto). Se
     * descarta todo lo demás (objetos, listas de objetos, anidados) y toda clave que parezca secreta.
     *
     * @param mixed $errores Objeto o arreglo `campo => [mensajes]`.
     *
     * @return mixed Del mismo tipo que recibió.
     */
    private function filtrar_errores($errores)
    {
        if (! is_object($errores) && ! is_array($errores)) {
            return $errores;
        }

        $es_objeto = is_object($errores);
        $salida    = [];
        foreach (($es_objeto ? get_object_vars($errores) : $errores) as $campo => $mensajes) {
            if ($this->es_clave_sensible((string) $campo)) {
                continue;
            }

            if (is_string($mensajes)) {
                $salida[$campo] = $mensajes;
                continue;
            }

            if (is_array($mensajes) && $this->es_lista($mensajes)) {
                $solo_textos = true;
                foreach ($mensajes as $mensaje) {
                    if (! is_string($mensaje)) {
                        $solo_textos = false;
                        break;
                    }
                }
                if ($solo_textos) {
                    $salida[$campo] = $mensajes;
                }
            }
        }

        return $es_objeto ? (object) $salida : $salida;
    }

    /**
     * Reduce un objeto (o cada objeto de una lista) a los campos escalares listados.
     *
     * @param mixed                $nodo   Objeto o lista de objetos de una relación.
     * @param array<int, string>   $campos Campos que se conservan.
     *
     * @return mixed
     */
    private function reducir_a_campos($nodo, array $campos)
    {
        if (is_array($nodo) && $this->es_lista($nodo)) {
            return array_map(function ($hijo) use ($campos) {
                return $this->reducir_a_campos($hijo, $campos);
            }, $nodo);
        }

        if (! is_object($nodo) && ! is_array($nodo)) {
            return $nodo;
        }

        $es_objeto = is_object($nodo);
        $salida    = [];
        foreach (($es_objeto ? get_object_vars($nodo) : $nodo) as $clave => $hijo) {
            if (in_array((string) $clave, $campos, true) && ! is_object($hijo) && ! is_array($hijo)) {
                $salida[(string) $clave] = $hijo;
            }
        }

        return $es_objeto ? (object) $salida : $salida;
    }

    /**
     * Indica si una clave no puede salir nunca al perfil (patrón secreto o lista exacta).
     *
     * @param string $clave Nombre de la clave.
     *
     * @return bool
     */
    private function es_clave_sensible(string $clave): bool
    {
        return in_array($clave, self::CLAVES_SENSIBLES_EXACTAS, true)
            || preg_match(self::PATRON_DE_CLAVE_SECRETA, $clave) === 1;
    }

    /**
     * Dice si un arreglo PHP es una lista (claves 0..n-1) y no un objeto asociativo.
     *
     * @param array $arreglo Arreglo a revisar.
     *
     * @return bool
     */
    private function es_lista(array $arreglo): bool
    {
        return $arreglo === [] || array_keys($arreglo) === range(0, count($arreglo) - 1);
    }

    /**
     * Dice si el perfil puede guardar ese valor como estado de un lead: un estado fijo del pipeline
     * (`Lead::PIPELINE_STATUSES`) o uno del catálogo dinámico (`LeadPipelineStatus`). Cualquier otro valor
     * (otro texto, vacío, nulo, un arreglo) no. La comparación es exacta.
     *
     * @param mixed $estado Valor que vino en el cuerpo.
     *
     * @return bool
     */
    public function estado_de_lead_valido($estado): bool
    {
        if (! is_string($estado) || $estado === '') {
            return false;
        }

        return in_array($estado, Lead::PIPELINE_STATUSES, true)
            || in_array($estado, LeadPipelineStatus::all_slugs(), true);
    }

    /**
     * Dice si el cuerpo del pedido trae un estado de lead inválido en una ruta que lo valida contra el
     * catálogo (hoy `PUT lead/{id}`). Sólo mira la clave si viene en el cuerpo: sin `status` no hay nada que
     * validar (se puede guardar sólo la nota).
     *
     * @param string               $metodo Verbo HTTP.
     * @param string               $uri    Uri de la ruta con sus marcadores.
     * @param array<string, mixed> $input  Cuerpo ya filtrado a los campos editables.
     *
     * @return bool True si hay que responder 422.
     */
    public function tiene_estado_invalido(string $metodo, string $uri, array $input): bool
    {
        $clave = $this->clave_de_ruta($metodo, $uri);

        if (! in_array($clave, self::RUTAS_CON_ESTADO_DE_LEAD, true) || ! array_key_exists('status', $input)) {
            return false;
        }

        return ! $this->estado_de_lead_valido($input['status']);
    }
}
