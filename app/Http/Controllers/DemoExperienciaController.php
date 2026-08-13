<?php

namespace App\Http\Controllers;

use App\Helpers\AppTime;
use App\Jobs\RunDemoSetupJob;
use App\Models\DemoMedia;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\DemoHitosService;
use App\Services\DemoPlanResolver;
use App\Services\LeadDemoFormMapper;
use App\Services\LeadDemoSettings;
use App\Services\RunDemoSetupService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backend de la página inmersiva de demo (grupo 300, prompt 03 —
 * `contexto/demo_experiencia.md` §9, bloque G).
 *
 * Dos endpoints PÚBLICOS (sin auth:sanctum), identificados por el `uuid` del lead (columna
 * única y no enumerable de `leads`, mismo patrón que `DemoLandingController` del grupo 213):
 * uno arma el payload completo que consume la página, el otro recibe las nueve respuestas del
 * formulario de configuración.
 *
 * Ninguno de los dos devuelve datos sensibles del lead (email, teléfono, notas, campos del
 * pipeline, `demo_ingreso_token`): son endpoints públicos, solo se expone lo que la página
 * realmente muestra.
 */
class DemoExperienciaController extends Controller
{
    /**
     * GET /api/demo-experiencia/{uuid}
     *
     * Arma el payload completo de la página: datos del lead, estado del turno, respuestas del
     * formulario y multimedia cargada.
     *
     * @param string $uuid Token público del lead (columna `uuid`, no enumerable).
     *
     * @return JsonResponse
     */
    public function show_json(string $uuid): JsonResponse
    {
        // Búsqueda manual por uuid: HasUuid::getRouteKeyName() devuelve 'id', así que el route
        // model binding implícito no sirve acá (mismo detalle que DemoLandingController).
        $lead = Lead::where('uuid', $uuid)->first();
        if (! $lead) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return response()->json($this->build_payload($lead), 200);
    }

    /**
     * POST /api/demo-experiencia/{uuid}/formulario
     *
     * Recibe (parcialmente, todas las claves `sometimes`) las nueve respuestas del formulario de
     * configuración, las aplica sobre el lead vía `LeadDemoFormMapper`, marca
     * `demo_form_completado_at` solo en el primer envío y deja constancia en el hilo del lead.
     *
     * Desde la misión 46 este endpoint SÍ dispara el demo setup, cuando ya se cumple la condición
     * de `RunDemoSetupService::evaluar_disparo()` (formulario completo + ventana abierta). El
     * comando `leads:run-demo-setup` sigue corriendo cada minuto como red de seguridad y como
     * camino de los turnos agendados para más adelante; lo que se gana acá son los hasta 60
     * segundos que el lead perdería esperando el próximo tick, sobre un margen total de 5 minutos.
     *
     * @param Request $request Body con las respuestas del formulario (claves opcionales).
     * @param string  $uuid    Token público del lead (columna `uuid`, no enumerable).
     *
     * @return JsonResponse Mismo payload que el GET, para refrescar la página con una sola llamada.
     */
    public function store_formulario_json(Request $request, string $uuid): JsonResponse
    {
        // Búsqueda manual por uuid, igual que en show_json().
        $lead = Lead::where('uuid', $uuid)->first();
        if (! $lead) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        // Validación acotada a las nueve claves del formulario. Todas "sometimes": el formulario
        // nunca debe fallar por un campo que no llegó (autoguardado parcial desde la página).
        $validated = $request->validate([
            'tipo_precios'                       => 'sometimes|string|in:unico,listas',
            'usa_depositos'                       => 'sometimes|boolean',
            'usa_cuentas_corrientes_clientes'     => 'sometimes|boolean',
            'costos_en_dolares'                   => 'sometimes|boolean',
            'descuentos_por_metodo_pago'          => 'sometimes|boolean',
            'usa_cuentas_corrientes_proveedores'  => 'sometimes|boolean',
            'usa_presupuestos'                    => 'sometimes|boolean',
            'registra_compras'                    => 'sometimes|boolean',
            'usa_ecommerce'                       => 'sometimes|boolean',
        ]);

        // Las claves ausentes del body conservan el valor que el lead ya tenía: se arrancan las
        // respuestas desde el estado actual (from_lead) y se pisan solo las que llegaron en este
        // envío. LeadDemoFormMapper::to_lead() por sí solo NO hace esto (cada clave ausente cae
        // en su default "apagado"), así que el merge tiene que pasar acá.
        $respuestas_actuales = LeadDemoFormMapper::from_lead($lead);
        $respuestas          = array_merge($respuestas_actuales, $validated);

        // Aplica las nueve respuestas sobre el lead (en memoria, sin guardar todavía).
        LeadDemoFormMapper::to_lead($lead, $respuestas);

        // demo_form_completado_at se marca UNA sola vez: si ya tenía valor, un reenvío del
        // formulario no lo mueve (esa marca es la que dispara el demo setup junto con T-15).
        $es_primer_envio = $lead->demo_form_completado_at === null;
        if ($es_primer_envio) {
            $lead->demo_form_completado_at = Carbon::now();
        }

        // El plan de demo se congela acá, en el primer envío, y en el MISMO save() que
        // demo_form_completado_at (misión 48, pieza 2). El orden importa: congelar_en_memoria()
        // lee las respuestas efectivas, que dependen de que demo_form_completado_at ya tenga
        // valor — de lo contrario resolvería con los defaults del catálogo en vez de con lo que
        // el lead acaba de contestar. En un reenvío no congela nada y sólo deja el log de la
        // diferencia. Los hitos se generan en la misma transacción que el save.
        DB::transaction(function () use ($lead) {
            // Se llama en todos los envíos, no sólo en el primero: en el primero congela, y en un
            // reenvío el método ve que el plan ya existe, NO lo re-resuelve y sólo deja constancia
            // de qué respuestas cambiaron. Volver a resolverlo dejaría los hitos ya marcados
            // apuntando a clips que pudieron salir del plan.
            $congelo = DemoPlanResolver::congelar_en_memoria($lead);

            $lead->save();

            if ($congelo) {
                DemoHitosService::generar($lead);
            }
        });

        // Trazabilidad en el hilo del lead: se escribe el LeadMessage directo acá (no se reusa
        // el helper administrativo del panel, que asume un admin autenticado vía Auth::user() —
        // acá no lo hay, lo completó el propio lead desde la página pública). Mismo patrón que
        // LeadFollowupService::pause_lead() para eventos de sistema sin admin.
        LeadMessage::create([
            'lead_id'         => $lead->id,
            'sender'          => 'sistema',
            'content'         => 'El lead completó el formulario de configuración de la demo',
            'status'          => 'enviado',
            'is_followup'     => false,
            // Evento de estado: no cuenta como actividad real del hilo (no toca last_message_at
            // ni genera badge de "sin leer").
            'is_status_event' => true,
            // Sin sent_by_admin_id: esta acción no la hizo un admin.
        ]);

        $this->disparar_setup_si_corresponde($lead);

        return response()->json($this->build_payload($lead), 200);
    }

    /**
     * POST /api/demo-experiencia/{uuid}/intro-progreso
     *
     * Recibe el avance del lead sobre el video de introducción (misión 46, pieza 4). Público como
     * los otros tres, mismo criterio: identifica por el `uuid` del lead y no devuelve nada
     * sensible.
     *
     * MONÓTONO: el valor guardado nunca baja. El reproductor reporta el mayor `currentTime`
     * alcanzado, pero llegan varios reportes por minuto y pueden cruzarse en la red — un pct viejo
     * que llegue tarde no puede borrar el progreso real. La primera vez que cruza el umbral sella
     * `intro_visto_at` y deja constancia en el hilo del lead.
     *
     * @param Request $request Body: `{ "pct": 0..100 }`.
     * @param string  $uuid    Token público del lead (columna `uuid`, no enumerable).
     *
     * @return JsonResponse Mismo payload que el GET, para que la página refresque con una sola
     *                      llamada y el botón se prenda sin esperar al poleo.
     */
    public function store_intro_progreso_json(Request $request, string $uuid): JsonResponse
    {
        // Búsqueda manual por uuid, igual que en el resto de los endpoints de este controller.
        $lead = Lead::where('uuid', $uuid)->first();
        if (! $lead) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        $validated = $request->validate([
            'pct' => 'required|integer|min:0|max:100',
        ]);

        $pct_recibido = (int) $validated['pct'];
        $pct_guardado = (int) $lead->intro_visto_pct;

        if ($pct_recibido > $pct_guardado) {
            $lead->intro_visto_pct = $pct_recibido;

            // El sello va una sola vez: interesa CUÁNDO terminó de mirarlo, no cuál fue el último
            // reporte. La condición mira intro_visto_at y no el pct viejo porque el umbral se puede
            // bajar desde el panel, y un lead que ya estaba por encima no tiene que volver a
            // "cruzarlo" ni generar un segundo mensaje en el hilo.
            if ($lead->intro_visto_at === null && $pct_recibido >= LeadDemoSettings::get_demo_intro_umbral_pct()) {
                $lead->intro_visto_at = Carbon::now();

                LeadMessage::create([
                    'lead_id'         => $lead->id,
                    'sender'          => 'sistema',
                    'content'         => 'El lead terminó el video de introducción',
                    'status'          => 'enviado',
                    'is_followup'     => false,
                    'is_status_event' => true,
                ]);
            }

            $lead->save();
        }

        return response()->json($this->build_payload($lead), 200);
    }

    /**
     * POST /api/demo-experiencia/{uuid}/ingresar
     *
     * Devuelve la URL de ingreso directo a la demo (grupo 233: `empresa-api` valida el token y
     * abre la sesión del guard `web`) solo si el turno está activo y el token de ingreso vigente.
     * Es POST y no forma parte del payload del GET: el GET se cachea y queda en logs de
     * intermediarios; el POST se dispara con el clic y valida la ventana temporal contra el reloj
     * del servidor en ese instante.
     *
     * Idempotente a propósito: el token no es de un solo uso (grupo 233), así que puede llamarse
     * muchas veces durante el turno sin invalidar nada.
     *
     * @param string $uuid Token público del lead (columna `uuid`, no enumerable).
     *
     * @return JsonResponse `{ "url": ... }` en éxito (200), `{ "motivo": ... }` en 409, 404 si no
     *                       existe el lead.
     */
    public function ingresar_json(string $uuid): JsonResponse
    {
        // Búsqueda manual por uuid, igual que en show_json() y store_formulario_json().
        $lead = Lead::where('uuid', $uuid)->first();
        if (! $lead) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        // Misma fuente de verdad que la página: evaluar_ingreso() es el ÚNICO lugar donde se decide
        // si se puede entrar, y es el mismo que alimenta `puede_ingresar` del payload. No se
        // recalcula la ventana acá con otra lógica, o aparecería el caso de un botón habilitado
        // que rebota.
        //
        // 🔴 Desde la misión 46 esto ya NO exige `turno.estado === 'activo'`: el reloj del turno
        // dejó de ser la puerta. La puerta es que la demo esté armada y el intro visto. Un turno de
        // mañana no se abre igual, porque su setup todavía no corrió.
        $evaluacion = $this->evaluar_ingreso($lead);
        if (! $evaluacion['puede']) {
            return response()->json(['motivo' => $evaluacion['motivo']], 409);
        }

        // El token lo emite el demo setup (RunDemoSetupService). Con el setup en `exitoso` esto no
        // debería pasar, pero si pasa el motivo correcto sigue siendo `preparando`: al lead le da
        // la misma instrucción — esperá un momento y probá de nuevo.
        if (empty($lead->demo_ingreso_token)) {
            return response()->json(['motivo' => 'preparando'], 409);
        }

        $token_revocado = $lead->demo_ingreso_token_revocado_at !== null;
        $token_vencido  = $lead->demo_ingreso_token_expira_at !== null
            && $lead->demo_ingreso_token_expira_at->isPast();
        if ($token_revocado || $token_vencido) {
            return response()->json(['motivo' => 'token_invalido'], 409);
        }

        // Accessor ya existente (grupo 233): null si no hay demo asignada o la demo no tiene
        // erp_spa_url cargada.
        $url = $lead->demo_ingreso_url;
        if (empty($url)) {
            return response()->json(['motivo' => 'sin_instancia'], 409);
        }

        Log::info('DemoExperienciaController: ingreso a la demo desde la página inmersiva', [
            'lead_id' => $lead->id,
            'uuid'    => $uuid,
            'ip'      => request()->ip(),
        ]);

        // Registro informativo en el hilo del lead. No se reusa registrar_evento_token_demo() de
        // LeadController porque asume un admin autenticado vía Auth::user() y acá no lo hay (ruta
        // pública, sin admin) — mismo criterio que store_formulario_json() más abajo. No confundir
        // con demo_ingreso_confirmado, que es la confirmación conversacional que dispara el
        // cambio de estado del pipeline; este registro es solo informativo.
        LeadMessage::create([
            'lead_id'         => $lead->id,
            'sender'          => 'sistema',
            'content'         => 'El lead entró a la demo desde la página',
            'status'          => 'enviado',
            'is_followup'     => false,
            'is_status_event' => true,
        ]);

        // El hito de ingreso queda en PARCIAL, no en completo: acá sólo consta que el lead pulsó
        // el botón y que le dimos la URL. El `completo` lo pone el evento `demo.ingreso` que manda
        // la instancia cuando valida el token (misión 50). Son dos señales distintas a propósito:
        // la diferencia entre las dos es el caso "pulsó y no entró", que hoy no se ve desde ningún
        // lado. Misión 48.
        DemoHitosService::marcar_ingreso_pulsado($lead);

        return response()->json(['url' => $url], 200);
    }

    /**
     * Arma el payload compartido por GET y POST: datos del lead, estado del turno, respuestas
     * del formulario y multimedia cargada. Sin ningún dato sensible (email, teléfono, notas,
     * campos del pipeline, `demo_ingreso_token`).
     *
     * @param Lead $lead Lead ya resuelto por uuid.
     *
     * @return array<string, mixed>
     */
    private function build_payload(Lead $lead): array
    {
        // Una sola consulta de media por request: la usan el mapa `media` de la página y el cálculo
        // de si el intro es obligatorio.
        $media      = DemoMedia::url_por_slot();
        $evaluacion = $this->evaluar_ingreso($lead, $media);

        return [
            'lead' => [
                'contact_name' => (string) ($lead->contact_name ?? ''),
                'company_name' => (string) ($lead->company_name ?? ''),
                // Nunca null: perfil_lead_efectivo() cae a 'dueno' si la columna está vacía o
                // tiene un valor desconocido. La página no tiene que decidir nada.
                'perfil'       => $lead->perfil_lead_efectivo(),
            ],
            'turno' => $this->build_turno($lead),
            'formulario' => array_merge(
                LeadDemoFormMapper::from_lead($lead),
                ['completado' => $lead->demo_form_completado_at !== null]
            ),
            // Solo los slots con URL cargada; los que faltan no aparecen y la página muestra su
            // placeholder (DemoMedia::url_por_slot() ya filtra por url no vacía).
            'media' => $media,

            // Estado del armado de la instancia. Hasta la misión 46 no viajaba, así que la página
            // no tenía forma de saber que la demo estaba lista: se enteraba haciendo clic y
            // comiéndose el 409 `preparando`.
            'setup' => [
                'estado' => (string) ($lead->demo_setup_status ?? 'pendiente'),
            ],

            // Progreso del lead sobre el video de introducción y umbral vigente.
            'intro' => $this->build_intro($lead, $media),

            // 🔴 Mismo interruptor que usa AppTime para el reloj virtual del admin, y por eso es el
            // correcto: producción no corre en `local`, así que el bypass no se puede filtrar por
            // accidente. No es un flag de build del front, ni una query string, ni una columna del
            // lead — cualquiera de esas tres se puede activar desde afuera.
            'modo_prueba' => $this->modo_prueba(),

            // 🔴 Lo calcula SOLO el backend. El front no lo deriva ni lo recalcula en ningún
            // componente: si lo hiciera, habría dos reglas para la misma puerta y la del navegador
            // sería la fácil de saltear.
            'puede_ingresar' => $evaluacion['puede'],
        ];
    }

    /**
     * El bloque `intro` del payload: cuánto vio el lead, cuánto se le exige, y si el gate aplica.
     *
     * `obligatorio` es false cuando NO hay URL cargada para el slot `intro`. Sin esa salvaguarda,
     * mientras el video no esté subido el gate dejaría a TODOS afuera para siempre y no habría
     * forma de entrar a ninguna demo — el video se graba post-merge, así que ese es el estado real
     * del sistema hoy. También es false en modo prueba, que es el camino con el que Lucas prueba.
     *
     * @param Lead                  $lead
     * @param array<string, string> $media Mapa slot_id => url ya resuelto por el llamador.
     *
     * @return array<string, mixed>
     */
    private function build_intro(Lead $lead, array $media): array
    {
        $hay_video_cargado = isset($media['intro']) && trim((string) $media['intro']) !== '';

        return [
            'visto_pct'   => (int) $lead->intro_visto_pct,
            'umbral_pct'  => LeadDemoSettings::get_demo_intro_umbral_pct(),
            'obligatorio' => $hay_video_cargado && ! $this->modo_prueba(),
        ];
    }

    /**
     * 🔴 EL ÚNICO lugar donde se decide si un lead puede entrar a su demo (misión 46, pieza 3).
     *
     * Lo consumen `build_payload()` (para el flag `puede_ingresar` que gobierna el botón) e
     * `ingresar_json()` (para autorizar el POST y devolver el motivo del 409). Que sean el mismo
     * cálculo es lo que evita el peor síntoma posible acá: un botón habilitado que rebota.
     *
     * La regla, tal cual la fija la misión:
     *
     *     puede_ingresar = setup exitoso
     *                  AND turno no vencido
     *                  AND ( modo_prueba OR intro no obligatorio OR visto_pct >= umbral )
     *
     * El orden de los chequeos define el `motivo`, y está elegido para que el lead lea lo más
     * accionable primero: un turno vencido no se arregla mirando el video.
     *
     * @param Lead                       $lead
     * @param array<string, string>|null $media Mapa slot_id => url; null = se consulta acá.
     *
     * @return array{puede: bool, motivo: string}
     */
    private function evaluar_ingreso(Lead $lead, ?array $media = null): array
    {
        if ($media === null) {
            $media = DemoMedia::url_por_slot();
        }

        $turno = $this->build_turno($lead);
        if ($turno['estado'] === 'vencido') {
            return ['puede' => false, 'motivo' => 'vencido'];
        }

        $setup = (string) ($lead->demo_setup_status ?? 'pendiente');
        if ($setup === 'fallido') {
            return ['puede' => false, 'motivo' => 'setup_fallido'];
        }
        if ($setup !== 'exitoso') {
            // pendiente | ejecutandose: la instancia se está armando. Un solo motivo para los dos
            // porque para el lead son lo mismo — esperar.
            return ['puede' => false, 'motivo' => 'preparando'];
        }

        $intro = $this->build_intro($lead, $media);
        if ($intro['obligatorio'] && $intro['visto_pct'] < $intro['umbral_pct']) {
            return ['puede' => false, 'motivo' => 'intro_pendiente'];
        }

        return ['puede' => true, 'motivo' => 'ok'];
    }

    /**
     * true en entorno de prueba, donde el gate del intro no aplica.
     *
     * @return bool
     */
    private function modo_prueba(): bool
    {
        return config('app.env') === 'local';
    }

    /**
     * Dispara el demo setup en el acto si el lead ya cumple la condición (misión 46, pieza 2).
     *
     * Sólo para la dinámica nueva y sólo con el setup todavía en `pendiente`. Si el turno es para
     * más adelante, no dispara nada: lo levanta el comando cuando abra la ventana.
     *
     * `afterResponse()` y no `dispatch()` a secas: con `QUEUE_CONNECTION=sync` un dispatch común
     * correría inline y le bloquearía al lead la respuesta del formulario hasta 300 segundos.
     *
     * @param Lead $lead
     *
     * @return void
     */
    private function disparar_setup_si_corresponde(Lead $lead): void
    {
        if (! $lead->usa_experiencia_demo_nueva()) {
            return;
        }

        if ((string) ($lead->demo_setup_status ?? 'pendiente') !== 'pendiente') {
            return;
        }

        $service    = new RunDemoSetupService();
        $evaluacion = $service->evaluar_disparo($lead);

        if (! $evaluacion['disparar']) {
            return;
        }

        Log::info('DemoExperienciaController: disparo inmediato del demo setup desde el formulario', [
            'lead_id' => $lead->id,
            'motivo'  => $evaluacion['motivo'],
        ]);

        RunDemoSetupJob::dispatch($lead->id)->afterResponse();
    }

    /**
     * Calcula el bloque `turno` del payload: fecha/horario crudos del lead más dos campos
     * calculados por el backend (nunca por la página, para no depender del reloj del lead):
     * `estado` (uno de sin_turno|antes|activo|vencido) e `ingreso` (bool).
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    private function build_turno(Lead $lead): array
    {
        // Sin fecha de demo asignada: no hay turno que mostrar, la página cae al estado
        // "sin_turno" (criterio de éxito #4). El resto de los campos van vacíos.
        if ($lead->demo_date === null) {
            return [
                'fecha'       => '',
                'hora_inicio' => '',
                'hora_fin'    => '',
                'estado'      => 'sin_turno',
                'ingreso'     => $this->resolve_ingreso($lead),
            ];
        }

        // Zona horaria de referencia para todo el cálculo del turno (misma que usan los
        // comandos existentes del ciclo de demo, ej. CheckDemoIngresoTimeout).
        $tz = 'America/Argentina/Buenos_Aires';

        // demo_date está casteada a 'date': se re-formatea a Y-m-d en la timezone de referencia
        // para combinarla con las horas (texto libre HH:MM) guardadas en demo_start_time/fin.
        $fecha_str       = $lead->demo_date->setTimezone($tz)->format('Y-m-d');
        $hora_inicio_str = (string) ($lead->demo_start_time ?? '');
        $hora_fin_str    = (string) ($lead->demo_end_time ?? '');

        // Momento actual, respetando el tiempo virtual de debug en entorno local (AppTime), para
        // que mover la fecha de la demo en la base cambie el estado sin tocar código ni depender
        // del reloj real (criterio de éxito #5).
        $now = AppTime::now($tz);

        // Datetime de inicio: si no hay hora de inicio cargada, no se puede ubicar el turno en el
        // tiempo con precisión; se trata como "antes" (todavía no hay nada que mostrar como
        // vencido) sin intentar parsear una hora vacía.
        $inicio = $this->parse_turno_datetime($fecha_str, $hora_inicio_str, $tz);
        if ($inicio === null) {
            return [
                'fecha'       => $fecha_str,
                'hora_inicio' => $hora_inicio_str,
                'hora_fin'    => $hora_fin_str,
                'estado'      => 'antes',
                'ingreso'     => $this->resolve_ingreso($lead),
            ];
        }

        // Datetime de fin: si no hay hora de fin cargada, se estima con la duración configurada
        // (LeadDemoSettings) a partir del inicio, mismo criterio que usa el resto del ciclo de
        // demo cuando falta el dato explícito.
        $fin = $this->parse_turno_datetime($fecha_str, $hora_fin_str, $tz);
        if ($fin === null) {
            $fin = $inicio->copy()->addMinutes(LeadDemoSettings::get_duracion_minutos());
        }

        // Límite de "activo": fin de la demo + minutos de gracia configurados.
        $fin_con_gracia = $fin->copy()->addMinutes(LeadDemoSettings::get_gracia_minutos_post());

        if ($now->lt($inicio)) {
            $estado = 'antes';
        } elseif ($now->lte($fin_con_gracia)) {
            $estado = 'activo';
        } else {
            $estado = 'vencido';
        }

        return [
            'fecha'       => $fecha_str,
            'hora_inicio' => $hora_inicio_str,
            'hora_fin'    => $hora_fin_str,
            'estado'      => $estado,
            'ingreso'     => $this->resolve_ingreso($lead),
        ];
    }

    /**
     * `turno.ingreso` — sale de la columna `demo_ingreso_confirmado`, NUNCA de la posición
     * numérica que un status ocupa en el orden declarado del pipeline. Ver el bloque de la
     * especificación del prompt ("`turno.ingreso` — sale de la columna, NUNCA de la posición en
     * el pipeline") para el detalle completo de por qué el `in_array` es necesario y por qué esos
     * tres slugs son la lista completa.
     *
     * `demo_realizada` se puede asignar a mano desde el panel (no pasa por el flujo de
     * confirmación por WhatsApp), así que sin ese tercer término un lead marcado a mano —o
     * cualquiera anterior a la migración que agregó la columna, que quedó en `false`— reportaría
     * `ingreso: false` habiendo hecho la demo.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    private function resolve_ingreso(Lead $lead): bool
    {
        return (bool) $lead->demo_ingreso_confirmado
            || in_array($lead->status, ['demo_en_curso', 'demo_pendiente_de_terminar', 'demo_realizada'], true);
    }

    /**
     * Combina una fecha (Y-m-d) con una hora (HH:MM) en un Carbon, en la timezone dada.
     *
     * @param string $fecha_str Fecha en formato Y-m-d.
     * @param string $hora_str  Hora en formato HH:MM (texto libre del lead); vacía = sin dato.
     * @param string $tz        Timezone IANA de referencia.
     *
     * @return Carbon|null Null si la hora está vacía o no se puede parsear.
     */
    private function parse_turno_datetime(string $fecha_str, string $hora_str, string $tz): ?Carbon
    {
        if (trim($hora_str) === '') {
            return null;
        }

        try {
            return Carbon::parse("{$fecha_str} {$hora_str}", $tz);
        } catch (\Exception $e) {
            return null;
        }
    }
}
