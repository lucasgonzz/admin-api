<?php

namespace App\Models;

use App\ModelProperties\LeadProperties;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\UsesVirtualTime;
use App\Models\LeadAdminNotification;
use App\Models\LeadPipelineStatus;
use App\Helpers\WhatsappNormalizer;
use App\Services\DemoUrlNormalizer;
use App\Services\LeadDemoFormMapper;
use App\Services\Pipelines\PipelineLeadFunnelService;
use App\Services\LeadDemoSettings;
use App\Services\LeadWhatsappOnboardingSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Prospecto comercial cargado desde el panel de admin-api.
 *
 * Un Lead concentra:
 * - datos de contacto,
 * - configuración técnica del sistema (espejo del formulario de demo),
 * - referencia al empresa-api destino donde se corre la demo remotamente,
 * - api_url del sistema productivo del cliente (para user-setup una vez promovido),
 * - trazabilidad del mail de presentación y del setup remoto,
 * - fecha de la demo y horas de inicio/fin (texto) acordadas con el prospecto,
 * - tutoriales de video personalizados para el mail de demo (`personalized_demo_videos`).
 *
 * Ver migraciones de la tabla `leads` (create y alter) para el esquema de columnas.
 */
class Lead extends Model
{
    use HasUuid;
    use UsesVirtualTime;

    /**
     * Estados por defecto del pipeline (legacy; catálogo dinámico en {@see LeadPipelineStatus}).
     */
    public const PIPELINE_STATUSES = [
        'nuevo',
        'contactado',
        'calificado',
        'demo_agendada',
        'demo_realizada',
        'mail2_enviado',
        'cerrado_ganado',
        'cerrado_perdido',
        'en_pausa',
    ];

    /**
     * Estados en los que la verificación de mensajes se auto-enciende (latch): desde que el lead
     * entra a solicita_disponibilidad hasta closer_activo, inclusive. Incluye demo_realizada y
     * closer_activo, que NO están en LeadAiService::ESTADOS_REQUIEREN_SUPERVISION_AGENDAMIENTO (esa
     * const, más corta, es la que usa el gate de agendamiento — su contenido no cambia, pero desde
     * el 1/9/2026 el EFECTO de retención de ese gate también depende del mismo interruptor global
     * que gobierna este latch, ver LeadWhatsappOnboardingSettings). Ver latch en booted().
     */
    public const ESTADOS_VENTANA_VERIFICACION_MENSAJES = [
        'solicita_disponibilidad',
        'demo_agendada',
        'demo_en_curso',
        'demo_pendiente_de_ingreso',
        'demo_pendiente_de_terminar',
        'demo_realizada',
        'closer_activo',
    ];

    /**
     * Estados en los que el lead ya es del closer: mientras esté acá, el mensaje entrante común del
     * lead le llega a los admins con is_closer = true y no a los setters (decisión de Lucas,
     * 2/9/2026 — antes le llegaba todo al mismo, porque el único que atendía leads era el setter).
     *
     * 🔴 NO gobierna los mensajes que requieren verificación ni el escalado a humano: esos van
     * SIEMPRE a los setters, esté el lead donde esté, porque el que aprueba lo que sale es el
     * setter y no el closer. Ver LeadNotificationAudienceResolver, que es el único consumidor.
     *
     * Es una lista y no una comparación contra un solo string a propósito: si mañana el tramo del
     * closer se abre a más estados, se agregan acá y el resolver no se toca.
     *
     * No confundir con ESTADOS_VENTANA_VERIFICACION_MENSAJES (arriba): esa describe DÓNDE se
     * auto-enciende la verificación de mensajes, y closer_activo está en las dos por motivos
     * distintos. Son conjuntos independientes; cambiar una no implica cambiar la otra.
     */
    public const ESTADOS_DUENO_CLOSER = [
        'closer_activo',
    ];

    /**
     * Dinámica de demo histórica: Mail 1 con credenciales y videos por link.
     *
     * 🔴 RETIRADA el 14/10/2026 (misión demo-solo-experiencia-nueva, decisión de Lucas): ningún
     * lead puede estar en esta dinámica. La constante se conserva solo porque otros archivos y
     * tests todavía la nombran; nada la puede volver a escribir (ver el hook `saving` de
     * `booted()`) ni leer como vigente (ver `demo_experiencia_efectiva()`).
     */
    public const EXPERIENCIA_ACTUAL = 'actual';

    /** Dinámica vigente de TODOS los leads: página inmersiva, formulario, panel lateral y tour. */
    public const EXPERIENCIA_NUEVA = 'nueva';

    /** Valores históricos de la columna `demo_experiencia` (hoy el único vigente es 'nueva'). */
    public const EXPERIENCIAS = [self::EXPERIENCIA_ACTUAL, self::EXPERIENCIA_NUEVA];

    /** Perfil "dueño" del lead (§3.17): versión por defecto de la página inmersiva. */
    public const PERFIL_DUENO = 'dueno';

    /** Perfil "campeón" del lead (§3.17): versión alternativa de la página inmersiva. */
    public const PERFIL_CAMPEON = 'campeon';

    /** Valores válidos de la columna `perfil_lead` (grupo 300, prompt 01). */
    public const PERFILES = [self::PERFIL_DUENO, self::PERFIL_CAMPEON];

    /**
     * Slugs válidos del pipeline (catálogo en BD o defaults).
     *
     * @return array<int, string>
     */
    public static function pipeline_status_slugs(): array
    {
        return LeadPipelineStatus::all_slugs();
    }

    /**
     * Elimina videos personalizados y mensajes de conversación al borrar el lead.
     *
     * @return void
     */
    protected static function booted()
    {
        // Defaults de sucursales y depósitos: todo lead nuevo arranca configurado para demo
        static::creating(function (Lead $lead) {
            if (! $lead->use_deposits) {
                $lead->use_deposits = true;
            }
            if (empty($lead->address_1)) {
                $lead->address_1 = 'Sucursal 1';
            }
            if (empty($lead->address_2)) {
                $lead->address_2 = 'Sucursal 2';
            }
            if (empty($lead->address_3)) {
                $lead->address_3 = 'Sucursal 3';
            }
        });

        // 🔴 CANDADO TOTAL de la dinámica de demo (misión demo-solo-experiencia-nueva, 14/10/2026).
        //
        // Lucas retiró la dinámica 'actual': TODOS los leads, los que ya existen y los que nazcan,
        // usan siempre la página de experiencia con su formulario, y pidió que no haya forma de
        // que un lead no la use. Por eso el valor no se "estampa al nacer con la setting global"
        // (como antes): se FUERZA a 'nueva' en cada guardado, creación incluida. Así ninguna
        // escritura por Eloquent puede dejar la columna en 'actual', en null ni en un valor
        // inventado: ni un `Lead::create(['demo_experiencia' => 'actual'])`, ni un controller viejo,
        // ni el SPA anterior que todavía ofrecía la opción, ni un script de mantenimiento.
        //
        // Es la primera de tres capas, a propósito redundantes:
        //  1. Este hook (escritura por Eloquent).
        //  2. `demo_experiencia_efectiva()` (lectura): devuelve siempre 'nueva' sin mirar la
        //     columna, así que un UPDATE directo que se salte este hook igual no cambia el
        //     comportamiento del lead.
        //  3. `LeadController::set_demo_experiencia_json` y `LeadDemoSettings` (puertas de la API
        //     y de la configuración) rechazan o ignoran 'actual'.
        // Solo se toca `demo_experiencia`. Los `DB::table()->update` directos no pasan por acá y no
        // se tocan (los cubre la capa 2).
        static::saving(function (Lead $lead) {
            $lead->demo_experiencia = self::EXPERIENCIA_NUEVA;
        });

        // Auto-activación de la verificación de mensajes al ENTRAR a la ventana de verificación
        // (solicita_disponibilidad → closer_activo). Latch de una sola vez: si el lead cruza desde un
        // estado FUERA de la ventana hacia uno DENTRO, se enciende requiere_verificacion_mensajes. No se
        // vuelve a forzar después (si el admin lo apaga estando en la ventana, queda apagado — decisión de
        // Lucas, 15/7/2026, el toggle es libremente apagable). Tampoco se apaga al salir de la ventana: una
        // vez encendida, persiste hasta que el admin la apague.
        //
        // ACTUALIZADO 1/9/2026 (decisión explícita de Lucas): este latch, igual que el gate de
        // agendamiento de LeadAiService y la retención de seguimientos de LeadFollowupService, ahora
        // depende del interruptor global de Cuenta (LeadWhatsappOnboardingSettings). Con el
        // interruptor apagado, NINGUNA red automática queda en pie: ni este latch ni el gate de
        // agendamiento retienen nada por el solo hecho del tramo — las acciones de agenda (agendar,
        // cancelar, confirmar ingreso, marcar no ingreso) se aplican en el acto. El toggle manual
        // por-lead (botón del escudo en la conversación) sigue funcionando siempre, apagado o
        // prendido el interruptor global.
        static::saving(function (Lead $lead) {
            if (! $lead->isDirty('status')) {
                return;
            }

            $ventana  = self::ESTADOS_VENTANA_VERIFICACION_MENSAJES;
            $nuevo    = (string) $lead->status;
            $anterior = (string) $lead->getOriginal('status');

            $entra_a_la_ventana = in_array($nuevo, $ventana, true) && ! in_array($anterior, $ventana, true);

            if ($entra_a_la_ventana
                && ! (bool) $lead->requiere_verificacion_mensajes
                && LeadWhatsappOnboardingSettings::get_auto_activar_verificacion_al_solicitar_disponibilidad()
            ) {
                $lead->requiere_verificacion_mensajes = true;
            }
        });

        /* Embudo "Leads" de Pipelines (misión embudo-lead-pipelines, 13/10/2026): cuando el lead
           confirma interés después de la demo (`closer_activo`) nace su oportunidad en el tablero
           de Tomás, y queda donde diga el estado de sus videollamadas.

           Va en `saved` y no en `saving` porque el alta necesita el `id` del lead ya guardado: una
           oportunidad que apuntara a un lead que al final no se guardó sería una tarjeta sin sujeto.

           🔴 El servicio se traga sus propios errores a propósito (ver su docblock). `saved` de Lead
           corre en el webhook de WhatsApp, en LeadAiService y en el scheduler de seguimientos: una
           excepción del CRM acá empezaría a tirar 500 en lugares que no tienen nada que ver con el
           CRM. */
        static::saved(function (Lead $lead) {
            if (! $lead->wasChanged('status')) {
                return;
            }

            app(PipelineLeadFunnelService::class)->al_cambiar_estado_del_lead($lead);
        });

        static::deleting(function (Lead $lead) {
            $lead->personalized_demo_videos()->delete();
            $lead->messages()->delete();
            $lead->partners()->delete();
            // Borrado en cascada (a nivel Eloquent, sin FK) de las llamadas del closer con este
            // lead (refactor "múltiples llamadas por lead", prompt 484). Los socios ya se borran
            // arriba vía partners() y siguen colgando también de lead_id, así que no hace falta
            // un borrado extra por llamada acá.
            $lead->calls()->delete();

            /* 🔴 NO se usa la relación scheduled_messages(): está filtrada a los estados visibles, así
               que borraría solo una parte y dejaría huérfanas las filas enviadas y canceladas. Acá
               se borran TODAS las del lead.
               Sin esto, un pendiente de un lead borrado lo sigue levantando el comando cada minuto:
               el despacho lo cancela solo al no encontrar el lead, pero con un motivo que no es el
               real y después de haberlo mirado una vez de más. */
            LeadScheduledMessage::query()->where('lead_id', $lead->id)->delete();
        });
    }

    /**
     * Devuelve definición declarativa de propiedades para admin-spa.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return LeadProperties::all();
    }

    protected $guarded = [];

    /**
     * Atributos que nunca salen al serializar el modelo (misión 48).
     *
     * `demo_eventos_token` es el secreto con el que una instancia de demo se identifica ante
     * `POST /api/demo-eventos`: no lo necesita ninguna vista ni ningún consumidor del panel, sale
     * del admin una sola vez y por un camino explícito (el payload del setup, donde se pone a mano
     * en `RunDemoSetupService`). Se oculta acá porque el modelo se serializa entero en varios
     * lugares —`fullModel` de las rutas del panel, y el `broadcastWith()` de `LeadSuggestionCreated`,
     * que emite sobre un `Channel` público— y `$guarded = []` sin `$hidden` significa que cualquier
     * columna nueva viaja a todos ellos por default.
     *
     * A propósito NO se agrega `demo_ingreso_token` acá, aunque también sea un secreto: el admin
     * lo necesita en claro para reconstruir el link que reenvía por WhatsApp (ver el docblock de
     * `RunDemoSetupService::asegurar_token_de_ingreso()`), así que ocultarlo puede romper el panel.
     * Queda como hallazgo aparte, no como cambio al pasar de esta misión.
     *
     * @var array<int, string>
     */
    protected $hidden = ['demo_eventos_token'];

    /**
     * Casts para mantener tipados los booleanos del formulario y las fechas
     * de agendamiento / trazabilidad (demo y producción).
     */
    protected $casts = [
        'demo_date'                    => 'date',
        'meeting_scheduled_at'         => 'datetime',
        'presentation_mail_sent_at'    => 'datetime',
        'demo_setup_last_run_at'       => 'datetime',
        'user_setup_last_run_at'       => 'datetime',

        // Token de ingreso directo a la demo: vencimiento y revocación (grupo 233, prompt 01).
        'demo_ingreso_token_expira_at'   => 'datetime',
        'demo_ingreso_token_revocado_at' => 'datetime',
        'followup_mail_sent_at'        => 'datetime',
        'demo_mail_sent_at'            => 'datetime',

        'use_deposits'                 => 'boolean',
        'use_price_lists'              => 'boolean',
        'iva_included'                 => 'boolean',
        'ventas_con_fecha_de_entrega'  => 'boolean',
        'cajas'                        => 'boolean',
        'usar_codigos_de_barra'        => 'boolean',
        'codigos_de_barra_por_defecto' => 'boolean',
        'consultora_de_precios'        => 'boolean',
        'imagenes'                     => 'boolean',
        'produccion'                   => 'boolean',
        'ask_amount_in_vender'         => 'boolean',
        'redondear_centenas_en_vender' => 'boolean',
        'omitir_cuentas_corrientes'    => 'boolean',

        'tiene_sugerencia_pendiente'   => 'boolean',
        // Si false, Claude no genera ni envía sugerencias automáticas para este lead.
        'claude_auto_reply'            => 'boolean',

        // Master del ciclo de demo: false = "lo manejo yo, no automatices nada" (prompt 318).
        'automatizaciones_demo_activas' => 'boolean',
        // Toggles puntuales de automatización por lead (prompt 318): permiten apagar una
        // automatización específica del ciclo de demo sin afectar al resto.
        'auto_recordatorio_demo'       => 'boolean',
        'auto_check_ingreso_demo'      => 'boolean',
        'auto_check_fin_demo'          => 'boolean',
        'auto_resumen_closer'          => 'boolean',

        // Flag persistido: Claude (o el admin) marcó que este lead requiere intervención humana.
        'requiere_intervencion_humana' => 'boolean',
        // Toggle por lead: si true, todo mensaje de Claude se retiene para verificación humana antes
        // de salir (en cualquier estado). Si false, envío inmediato. Se auto-enciende (latch) al entrar
        // a la ventana solicita_disponibilidad → closer_activo. Default false. Consumido por LeadAiService (407).
        'requiere_verificacion_mensajes' => 'boolean',
        'requiere_seguimiento'         => 'boolean',
        'tiene_seguimiento_sin_ver'    => 'boolean',

        // Toggle manual por lead: notifica al admin por push cada mensaje entrante.
        'notificar_mensajes'           => 'boolean',

        // Flag de recordatorio pre-demo: evita generar el mensaje más de una vez por demo agendada.
        'recordatorio_demo_enviado'    => 'boolean',

        // Flag de recordatorio de mañana: evita enviar el mensaje más de una vez por demo agendada.
        'recordatorio_manana_enviado'  => 'boolean',

        // Flag de check de ingreso post-demo: evita duplicar el mensaje al lead.
        'demo_check_ingreso_enviado'   => 'boolean',

        // Flag: el lead confirmó por WhatsApp que pudo entrar a la demo.
        'demo_ingreso_confirmado'      => 'boolean',

        // Marca «no necesita respuesta» de la bandeja (misión flujo-lead-landing-closer, 17/10/2026):
        // id del último mensaje del lead dado por atendido sin contestarle. Null = nada marcado.
        // Ver Lead::apply_condicion_mensaje_sin_responder() y LeadChatsController::marcar_respondido_json().
        'atendido_hasta_message_id'    => 'integer',

        // Flag: ya se envió el mensaje preguntando si terminó la demo.
        'demo_fin_check_enviado'       => 'boolean',

        // Reprogramación del check de fin (grupo 307, prompt 01): null = sin reprogramación
        // vigente (usar demo_datetime + duración); con fecha, ese es el nuevo objetivo temporal
        // porque hubo conversación viva o el agente pospuso a pedido del lead.
        'demo_fin_check_reprogramado_para' => 'datetime',

        // Flag: la demo se dejó abierta en un rango amplio (sin horario puntual); no reservar
        // automáticamente ventana de llamada para el closer (se coordina aparte, manualmente).
        'demo_flexible'                => 'boolean',

        // Timestamp exacto en que Claude confirmó el ingreso del lead a la demo.
        'demo_ingreso_confirmado_at'        => 'datetime',

        // Flag: Claude infirió que la demo terminó (el lead confirmó el fin).
        'demo_terminada_confirmada'         => 'boolean',

        // Timestamp exacto en que se confirmó el fin de la demo.
        'demo_terminada_confirmada_at'      => 'datetime',

        // Flag de un solo disparo: ya se envió el seguimiento de fin (anti-duplicado).
        'demo_fin_seguimiento_enviado'      => 'boolean',

        // Flag de un solo disparo: ya se notificó a admins el timeout de fin (anti-duplicado).
        'demo_pendiente_terminar_notificado' => 'boolean',

        // Flag de un solo disparo: ya se notificó a admins el no-ingreso (anti-duplicado).
        'demo_no_ingreso_notificado'        => 'boolean',

        // Timestamp de llamada del closer tras la demo: parte del pipeline de cierre.
        'closer_called_at'             => 'datetime',

        // Último mensaje del hilo WhatsApp (desnormalizado para orden en listado).
        'last_message_at'              => 'datetime',

        // Primer mensaje del hilo WhatsApp (desnormalizado para filtrar inicio de conversación).
        'first_message_at'             => 'datetime',

        // Timestamp de fijado (pin global): null = no fijado; los leads fijados aparecen primero en la tabla.
        'pinned_at'                    => 'datetime',

        // Timestamp de "pendiente de revisión" (global): null = no pendiente; con fecha = fila roja en la
        // grilla (botón de revisión, prompt 302). Se limpia al abrir la conversación.
        'pendiente_revision_at'        => 'datetime',

        // Marca manual "este lead ya no recibe mensajes" (número bloqueado o dado de baja).
        // null = recibe con normalidad. Con fecha, sus entregas fallidas dejan de pintar la fila de
        // rojo: el rojo está para lo que se puede reintentar, y esto no se reintenta.
        // Ver la migración 2026_09_02_150000 para por qué la pone una persona y no el sistema.
        'no_recibe_mensajes_at'        => 'datetime',

        // Marca manual "no leído" (estilo WhatsApp) del admin autenticado sobre este lead. Calculado
        // per-request en scopeWithUnreadLeadMessagesCount, no es una columna real de `leads`.
        'manually_marked_unread'       => 'boolean',

        // Fila amarilla en la grilla de leads: true si el lead tiene ≥1 mensaje pendiente de verificación
        // del setter (requiere_verificacion + sugerido). Calculado en scopeWithUnreadLeadMessagesCount.
        'row_warning'                  => 'boolean',

        // Cuotas del contrato PDF: [{monto, fecha}]
        'contract_financiacion'              => 'array',

        // Cláusulas particulares del contrato: [{titulo, texto}]
        'contract_clausulas_particulares'    => 'array',

        // Fechas del contrato ComercioCity (inputs type="date" en admin-spa).
        'contract_fecha_emision'             => 'date',
        'contract_fecha_primer_pago_unico'   => 'date',
        'contract_fecha_primer_pago_mensual' => 'date',

        // Cada cuántos meses se actualiza la mensualidad por IPC (misión modulo-cobranzas,
        // 18/9/2026). Int porque va al PDF en letras y se copia al cliente para hacer cuentas.
        'contract_meses_actualizacion'       => 'integer',

        // Modalidad del contrato (misión precios-dos-modalidades, 13/10/2026). `contract_modalidad`
        // no lleva cast: es texto ('licencia' | 'suscripcion' | null). Los sistemas contratados son
        // un JSON de keys de CotizadorSettings::SISTEMAS, y el plazo mínimo de la Suscripción va a
        // int porque el PDF lo escribe en letras con LeadContractPdfService::meses_en_letras().
        'contract_sistemas'                  => 'array',
        'contract_permanencia_meses'         => 'integer',

        // Foto de la cotización del sistema hecha desde la solapa Contrato (misión
        // cotizador-lead-mercado-pago, 22/9/2026): [{key, label, precio_usd, precio_ars}].
        'contract_cotizacion_items'          => 'array',

        // Cuándo se generó el link de pago. Con el cast, el front recibe la fecha en el mismo
        // formato que el resto de los timestamps del lead.
        'contract_cotizacion_generada_at'    => 'datetime',

        // Resumen estructurado generado por Claude: {empresa, situacion_actual, funcionalidades, puntos_dolor}
        'demo_summary_structured'      => 'array',

        // Resumen estructurado de la llamada del closer, extraído por Claude de la transcripción de Recall.ai.
        'call_summary'                 => 'array',

        // Snapshot de la variante de welcome asignada en A/B testing.
        'welcome_variant_id'           => 'integer',

        // Timestamps del flujo de alerta "Tomar llamada" (prompt 127).
        'closer_alert_sent_at'          => 'datetime',
        'closer_alert_accepted_at'      => 'datetime',
        'closer_delay_message_sent_at'  => 'datetime',
        'closer_no_show_rescheduled_at' => 'datetime',

        // Seis respuestas del formulario de demo (grupo 300, prompt 01) que no tenían columna
        // previa. Las otras tres respuestas del mismo formulario reusan columnas ya casteadas
        // arriba (use_price_lists, use_deposits, omitir_cuentas_corrientes) — ver
        // App\Services\LeadDemoFormMapper para la traducción completa.
        'costos_en_dolares'                  => 'boolean',
        'descuentos_por_metodo_pago'         => 'boolean',
        'usa_cuentas_corrientes_proveedores' => 'boolean',
        'usa_presupuestos'                   => 'boolean',
        'registra_compras'                   => 'boolean',
        'usa_ecommerce'                      => 'boolean',

        // Timestamp de envío del formulario de demo: null = todavía no lo completó (grupo 300, prompt 01).
        'demo_form_completado_at'            => 'datetime',

        // Timestamp de la última edición manual de esas mismas respuestas desde el modal del lead
        // (misión del 27/8/2026). Es una marca APARTE de la de arriba y no un reemplazo: aquella
        // dice "contestó el lead" y esta "las puso el admin". Cualquiera de las dos alcanza para
        // que LeadDemoFormMapper::respuestas_efectivas() deje de devolver los defaults del catálogo
        // y pase a leer las columnas.
        'demo_form_editado_admin_at'         => 'datetime',

        // Progreso del lead sobre el video de introducción (misión 46). El pct se castea a entero
        // para que el payload público no lo devuelva como string y el front tenga que compararlo
        // contra el umbral con conversión implícita.
        'intro_visto_pct'                    => 'integer',
        'intro_visto_at'                     => 'datetime',

        // Marca de un solo disparo del seguimiento de página (misión experiencia-landing,
        // 11/9/2026): cuándo `leads:check-pagina-sin-demo` le mandó "vi que le pegaste una mirada
        // a tu página". Null = todavía no salió. Es fecha y no booleano porque también se estampa
        // cuando el envío FALLA (para no reintentar en loop), y la fecha es lo único que después
        // permite distinguir "salió" de "se intentó" cruzando con lead_messages.
        'pagina_seguimiento_enviado_at'      => 'datetime',

        // Plan de demo congelado (misión 48): qué secciones y qué clips le tocan a este lead. Es
        // una foto, no una vista: se resuelve una sola vez con DemoPlanResolver y no se recalcula
        // al leer, porque el catálogo se sincroniza a producción sin deploy y le cambiaría el
        // roadmap a un lead que está en el medio de la demo.
        'demo_plan'                          => 'array',
        'demo_plan_congelado_at'             => 'datetime',
    ];

    /**
     * Scope `withAll` requerido por la convención de admin-api (regla 20).
     * Precarga las relaciones habituales para evitar N+1 al listar leads.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        $query->with(
            'target_client',
            // Los dos clientes del lead se serializan con los accesores ecommerce_* de su $appends,
            // que resuelven contra client_ecommerce: sin precargarla salian dos consultas por lead.
            'target_client.client_ecommerce',
            'promoted_client.implementation',
            'promoted_client.client_ecommerce',
            'created_by_admin',
            'demo',
            'personalized_demo_videos',
            'messages.attachments',
            'partners',
            /* Los mensajes programados viajan con el lead y no por un endpoint propio: la
               conversación ya se rehidrata entera con fullModel('lead') después de cada acción y
               tras cada evento de LeadBroadcastService, así que un endpoint de lectura aparte sería
               
               a los estados visibles (ver scheduled_messages()), que es lo único que se muestra. */
            'scheduled_messages'
        );

        /* El último resumen de llamada del closer, para la pestaña "Resumen" (ver
           `last_summarized_call()`). Va en una llamada aparte y no sumada al `with()` de arriba
           porque esa forma variádica solo acepta NOMBRES de relación: una entrada con closure
           adentro de esa lista la rompe.

           🔴 CON COLUMNAS ACOTADAS Y SIN LA TRANSCRIPCIÓN. Este scope lo usa también el listado de
           leads (hasta 500 por página) y el payload del lead se rehidrata entero después de cada
           acción del panel y de cada evento de LeadBroadcastService. `transcript` no se pide, y
           `call_summary` viaja SIN `transcripcion_completa`, que CallSummaryService duplica
           adentro del resumen: una transcripción por lead con llamada, multiplicada por la
           lista, es exactamente cómo un listado se vuelve lento sin que nadie lo vea venir. La
           transcripción entera se lee en la ficha de la oportunidad del embudo.

           `JSON_VALID` guarda el caso de un resumen que no sea JSON válido (la columna es TEXT):
           sin la guarda, `JSON_REMOVE` tiraría error y rompería TODO listado de leads; con ella
           ese resumen llega null y la pestaña cae a lo legado. `lead_id` va en la lista porque es
           la clave con la que Eloquent empareja la relación. */
        $query->with(['last_summarized_call' => function ($relacion) {
            $relacion->select([
                'id', 'lead_id', 'meet_url', 'estado', 'scheduled_at', 'started_at',
                DB::raw("CASE WHEN JSON_VALID(call_summary) THEN JSON_REMOVE(call_summary, '$.transcripcion_completa') ELSE NULL END AS call_summary"),
                'created_at', 'updated_at',
            ]);
        }]);

        $query->withUnreadLeadMessagesCount();
    }

    /**
     * Variante liviana para listados admin-spa: relaciones del lead + solo mensajes de notificación.
     *
     * Los mensajes se serializan bajo la clave `messages` (mismo contrato que el SPA) pero filtrados.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAllForList($query)
    {
        $query->with(
            'target_client',
            // Mismo motivo que en scopeWithAll(): los accesores ecommerce_* del $appends de Client
            // resuelven contra client_ecommerce, y esta variante la usa el LISTADO de leads.
            'target_client.client_ecommerce',
            'promoted_client.implementation',
            'promoted_client.client_ecommerce',
            'created_by_admin',
            'demo',
            'personalized_demo_videos',
            'notification_messages'
        );
        $query->withUnreadLeadMessagesCount();
    }

    /**
     * Marca el alcance de mensajes incluidos en la respuesta JSON (`notification` | `full`).
     *
     * @param string $scope
     *
     * @return $this
     */
    public function mark_messages_scope(string $scope)
    {
        $this->setAttribute('messages_scope', $scope);

        return $this;
    }

    /**
     * Expone mensajes de notificación bajo `messages` para compatibilidad con admin-spa.
     *
     * @return void
     */
    public function expose_notification_messages_as_messages(): void
    {
        if ($this->relationLoaded('notification_messages')) {
            $this->setRelation('messages', $this->notification_messages);
            $this->unsetRelation('notification_messages');
        }
    }

    /**
     * Normaliza una colección o paginador de leads de listado para JSON del SPA.
     *
     * @param mixed $models
     *
     * @return mixed
     */
    public static function prepare_collection_for_list_json($models)
    {
        $collection = $models instanceof \Illuminate\Contracts\Pagination\Paginator
            ? $models->getCollection()
            : $models;

        if ($collection) {
            $collection->each(function (Lead $lead) {
                $lead->expose_notification_messages_as_messages();
                $lead->mark_messages_scope('notification');
            });
        }

        return $models;
    }

    /**
     * Agrega los conteos que alimentan los tres badges de la columna "Sin leer" de la tabla de leads
     * (rediseño prompt 420, 15/7/2026), más la marca manual y la fila de alerta.
     *
     * - `unread_messages_count` / `unread_count`: mensajes del lead (sender = lead) sin leer para el
     *   admin autenticado (per-usuario). Alimenta el badge GRIS. `unread_messages_count` lo sigue
     *   usando la pestaña Conversación para auto-marcar leído; `unread_count` es el que consume el
     *   badge de la tabla.
     * - `pending_verification_count`: mensajes por verificar (badge ROJO). Global (no per-admin),
     *   mismo criterio que `row_warning` (requiere_verificacion = true + status = 'sugerido').
     * - `failed_send_count`: mensajes con error de envío sin resolver (badge AMARILLO). Global,
     *   replica `LeadPendingReviewService::tiene_error_sin_resolver()` en SQL.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithUnreadLeadMessagesCount($query)
    {
        // Admin autenticado: si no hay sesión (ej. jobs), 0 hace que nada se cuente como no leído.
        $admin_id = (int) (Auth::id() ?? 0);

        // Filtro reutilizable: mensajes entrantes del lead sin registro de lectura del admin actual.
        $unread_filter = function ($sub) use ($admin_id) {
            $sub->where('sender', 'lead')
                ->whereNotExists(function ($exists) use ($admin_id) {
                    $exists->selectRaw('1')
                        ->from('lead_message_reads')
                        ->whereColumn('lead_message_reads.lead_message_id', 'lead_messages.id')
                        ->where('lead_message_reads.admin_id', $admin_id);
                });
        };

        $query->withCount([
            'messages as unread_messages_count' => $unread_filter,
            'messages as unread_count'          => $unread_filter,
            // Badge ROJO de la columna "Sin leer": mensajes por verificar. Global (no per-admin):
            // mismo criterio que row_warning (requiere_verificacion = true + status = 'sugerido').
            // Cualquier admin ve la misma alerta; baja cuando el mensaje sale de 'sugerido'
            // (aprobado / rechazado / auto-enviado).
            'messages as pending_verification_count' => function ($sub) {
                $sub->where('requiere_verificacion', true)
                    ->where('status', 'sugerido');
            },
        ]);

        // Marca manual de "no leído" (estilo WhatsApp) hecha por el admin actual sobre este lead.
        // Independiente de unread_count: la UI (admin-spa) solo la muestra como punto sin número
        // cuando el contador real está en 0 (ver LeadProperties::all(), clave 'manually_unread_key').
        return $query->addSelect([
            'manually_marked_unread' => LeadManualUnreadMark::selectRaw('COUNT(*) > 0')
                ->whereColumn('lead_manual_unread_marks.lead_id', 'leads.id')
                ->where('lead_manual_unread_marks.admin_id', $admin_id),

            // Resaltado AMARILLO de la fila en la grilla de leads (fila que necesita revisión del setter,
            // ver prompt 295). Global (no per-admin): true si el lead tiene AL MENOS un mensaje pendiente
            // de verificación — requiere_verificacion = true + status = 'sugerido' (aún sin aprobar,
            // rechazar ni enviar). Cubre los dos casos sin distinción: (a) escalación conversacional del
            // agente (requiere_verificacion), y (b) todo el tramo de agenda desde solicita_disponibilidad
            // en adelante (los prompts 272/228 fuerzan requiere_verificacion en esos mensajes). Baja solo
            // cuando el mensaje sale de 'sugerido' (aprobado / rechazado / auto-enviado por el respaldo).
            // Fila AMARILLA de la grilla (definición de Lucas, 2/9/2026): "hay alguien esperando
            // una respuesta". Dos formas de estarlo, y las dos pintan igual porque piden lo mismo
            // del operador — que alguien conteste:
            //   (a) hay un mensaje por verificar listo para salir (requiere_verificacion + sugerido);
            //   (b) el lead habló último y no le llegó ninguna respuesta (razón A).
            // 🔴 (b) es la MISMA condición que cuenta la tarjeta "sin responder"
            // (Lead::apply_condicion_mensaje_sin_responder). Antes el amarillo solo miraba (a), así
            // que la tarjeta podía decir 2 y la grilla no marcar ninguna fila.
            'row_warning' => \App\Models\LeadMessage::selectRaw('COUNT(*) > 0')
                ->whereColumn('lead_messages.lead_id', 'leads.id')
                ->where(function ($amarillo) {
                    $amarillo->where(function ($por_verificar) {
                        $por_verificar->where('lead_messages.requiere_verificacion', true)
                            ->where('lead_messages.status', 'sugerido');
                    })->orWhere(function ($sin_responder) {
                        self::apply_condicion_mensaje_sin_responder($sin_responder);
                    });
                }),

            // Badge AMARILLO de la columna "Sin leer": mensajes salientes cuyo envío al lead falló y el
            // error sigue SIN RESOLVER. Global (no per-admin). Dos fuentes de error:
            //   (a) registro de error de sistema (is_error = true) — fallo de envío o de generación de IA;
            //   (b) mensaje saliente con entrega fallida confirmada por Kapso (whatsapp_delivery_status = 'fallido').
            // "Sin resolver" replica LeadPendingReviewService::tiene_error_sin_resolver: el mensaje de error
            // no tiene NINGUNA actividad real posterior (otro mensaje del mismo lead, con id mayor, que NO sea
            // evento de estado). Si después hubo un reintento exitoso o el lead respondió, el error se considera
            // resuelto y deja de contar. Los registros is_error son is_status_event = true, así que un error
            // más nuevo no "resuelve" a otro error (no cuenta como actividad real).
            //
            // El predicado vive en apply_condicion_error_sin_resolver() desde el 17/10/2026, porque la
            // lista de chats (LeadChatsController, `solo_atencion=1`) necesita el MISMO criterio en un
            // WHERE y copiarlo a mano es la receta con la que ya divergieron dos veces otros gemelos.
            'failed_send_count' => \App\Models\LeadMessage::selectRaw('COUNT(*)')
                ->whereColumn('lead_messages.lead_id', 'leads.id')
                ->where(function ($error) {
                    self::apply_condicion_error_sin_resolver($error);
                }),
        ]);
    }

    /**
     * 🔴 LA definición en SQL de "este saliente falló y el error sigue sin resolver", y la única que hay.
     *
     * Es el criterio del badge `failed_send_count` (y del rojo `error_envio` de la lista de chats),
     * extraído tal cual el 17/10/2026 (misión flujo-lead-landing-closer) para que
     * `LeadChatsController` pueda usarlo también como filtro (`solo_atencion=1`) sin reescribirlo.
     * No cambia nada de lo que contaba antes: dos fuentes de error —el registro de sistema
     * (`is_error`) y la entrega fallida que avisa Kapso (`whatsapp_delivery_status = 'fallido'`)— y
     * "sin resolver" = sin NINGUNA actividad real posterior del mismo lead (un mensaje con id mayor
     * que no sea evento de estado). Los registros `is_error` son `is_status_event = true`, así que
     * un error más nuevo no resuelve a otro error.
     *
     * ⚠️ Es más ancho que la razón B de {@see self::scopeRequiereRevision()} (que solo suma la
     * entrega fallida con `$incluir_entrega_fallida = true`): acá la entrega fallida entra siempre,
     * como entraba en el badge. No unificarlos sin leer `TarjetasDeEstadoDeLeadsTest`.
     *
     * @param \Illuminate\Database\Query\Builder $query Subconsulta ya apuntada a `lead_messages`
     *                                                  (sin alias), correlacionada con el lead.
     *
     * @return void
     */
    public static function apply_condicion_error_sin_resolver($query): void
    {
        $query->where(function ($error_source) {
                $error_source->where('lead_messages.is_error', true)
                    ->orWhere('lead_messages.whatsapp_delivery_status', 'fallido');
            })
            ->whereNotExists(function ($posterior) {
                // Actividad real posterior: mensaje del MISMO lead, id mayor, que NO sea evento de estado.
                $posterior->selectRaw('1')
                    ->from('lead_messages as lm_post')
                    ->whereColumn('lm_post.lead_id', 'lead_messages.lead_id')
                    ->whereColumn('lm_post.id', '>', 'lead_messages.id')
                    ->where('lm_post.is_status_event', false);
            });
    }

    /**
     * Leads que ameritan revisión, en SQL puro (gemelo de LeadPendingReviewService::lead_requiere_revision()).
     *
     * Razón A: mensajes del lead sin responder tras el último saliente **que le llegó**. Qué cuenta
     * como respuesta lo define {@see LeadMessage::is_reply_to_lead()} y su gemelo en SQL
     * {@see LeadMessage::apply_reply_to_lead_conditions()} — una sugerencia de la IA esperando
     * verificación no contesta nada, y un envío que nunca salió tampoco. La relación `messages` está
     * ordenada por id, así que "posterior en el bucle" es "id mayor".
     * Razón B: el hilo termina en un error sin actividad real posterior.
     *
     * 🔴 **Por qué el segundo parámetro existe, y por qué su default es `false`.**
     *
     * Hay DOS criterios de "error de envío" en el sistema, y no son el mismo conjunto:
     *
     * - El de `LeadPendingReviewService::lead_requiere_revision()` (el gemelo en PHP de este scope,
     *   y el oráculo del test de paridad): solo `is_error = true`, o sea los errores que el propio
     *   sistema registró al fallar el envío o la generación. Hasta el 2/9/2026 era además el
     *   criterio del botón de revisión, que se sacó cuando dejó de pintar colores.
     * - El del **badge amarillo** de la columna "Sin leer" (`failed_send_count`, acá arriba):
     *   `is_error` **o** `whatsapp_delivery_status = 'fallido'`, o sea sumando los rechazos que
     *   Meta avisa por webhook.
     *
     * La diferencia no es cosmética: cuando Meta rechaza un envío responde **200** en el momento
     * y avisa el fallo después, por webhook. Ese aviso lo procesa
     * `WhatsappWebhookController::handle_outbound_status_event()`, que escribe
     * `whatsapp_delivery_status = 'fallido'` y **nunca** un `is_error`. O sea que un rechazo de
     * Meta es invisible para el criterio del botón de revisión, siempre.
     *
     * - Con `$incluir_entrega_fallida = false` (default) este scope es el **gemelo exacto** de
     *   `LeadPendingReviewService::lead_requiere_revision()`. Es lo que compara
     *   `RevisionDeLeadsEnSqlYEnPhpCoincidenTest`, y por eso el default no se cambia: si alguien
     *   le suma la entrega fallida al camino por defecto, ese test se pone rojo con razón.
     * - Con `$incluir_entrega_fallida = true` suma los rechazos de Meta. Es lo que usan las
     *   tarjetas de estado de la grilla (`LeadStatusCardsService`), por decisión de Lucas del
     *   1/9/2026: el "sin responder" de la tarjeta tiene que incluir los leads a los que no se
     *   les pudo enviar el mensaje **por error de sistema o de Meta**, para que la tarjeta no
     *   diga 0 arriba de una fila que la grilla está pintando de rojo.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param bool                                  $incluir_entrega_fallida Sumar a la razón B los
     *                                                                       rechazos de Meta
     *                                                                       (`whatsapp_delivery_status = 'fallido'`).
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    /**
     * 🔴 LA definición en SQL de "este mensaje del lead quedó sin responder", y la única que hay.
     *
     * Se aplica sobre una subconsulta YA apuntada a `lead_messages` y correlacionada con el lead.
     * Un mensaje del lead cuenta como sin responder cuando es suyo, real (no una reacción, ni por
     * `kind` ni en el formato legado de Kapso en texto plano), **nosotros no le reaccionamos desde
     * el panel** — eso lo decide {@see LeadMessage::apply_sin_reaccion_admin_conditions()} (decisión
     * de Lucas del 8/9/2026: una reacción nuestra sobre el mensaje cuenta como respuesta, igual que
     * un texto) — y **no hay ningún saliente posterior que le haya llegado** — eso último lo decide
     * {@see LeadMessage::apply_reply_to_lead_conditions()}.
     *
     * 🔴 Por qué la reacción se mira sobre EL PROPIO mensaje candidato y no como un saliente más:
     * la reacción del panel no crea una fila nueva en `lead_messages` — se guarda como columnas
     * `admin_reaction_*` sobre la MISMA fila del mensaje del lead al que se reacciona (ver migración
     * `2026_09_01_100000_add_admin_reaction_to_lead_messages_table`). No tiene `id` propio, así que
     * no puede jugar el rol de "saliente posterior" del `whereNotExists` de abajo: si el mensaje
     * reaccionado no es el último del hilo, un mensaje posterior del lead sigue sin responder aunque
     * este quede resuelto por la reacción.
     *
     * 🔴 Y la marca «no necesita respuesta» (misión flujo-lead-landing-closer, decisión D6 de Lucas,
     * 17/10/2026): un mensaje del lead con `id <= leads.atendido_hasta_message_id` NO está sin
     * responder, aunque nadie le haya contestado ni reaccionado. El operador lo dio por atendido
     * desde la conversación («no necesita respuesta»: un «gracias», un sticker, un «dale» que no
     * merecen ni un texto ni una reacción) y hasta hoy esa fila quedaba amarilla para siempre. Es un
     * id y no un booleano a propósito: el mensaje siguiente del lead tiene un id mayor y vuelve a
     * contar solo, sin que haya que limpiar nada. Entra ACÁ, en la definición única, y no como un
     * filtro aparte en cada consumidor, porque así la marca vale igual para el amarillo de la fila,
     * para el `sin_responder` de las tarjetas, para `?sin_responder=1` y para la lista de chats; y
     * su gemelo PHP ({@see \App\Services\LeadConversationAiState::has_unanswered_lead_messages()})
     * hace que el scheduler de la IA tampoco genere una sugerencia para ese mensaje.
     *
     * ⚠️ Esa cláusula referencia `leads.atendido_hasta_message_id`, o sea que la subconsulta tiene
     * que estar correlacionada con la tabla `leads` SIN alias (es lo que ya hacen los tres
     * call-sites: `requiereRevision`, `row_warning` y la lista de chats, todos con
     * `whereColumn('lead_messages.lead_id', 'leads.id')`). Se eligió así en vez de un subselect
     * por PK sobre `leads` porque este predicado corre sobre cada mensaje de cada lead al contar
     * las tarjetas, y el subselect sumaba una lectura por mensaje para no ganar nada.
     *
     * La usan las cosas que tienen que decir lo mismo: el scope `requiereRevision` (que cuenta la
     * tarjeta "sin responder"), el flag `row_warning` (que pinta la fila de amarillo) y la
     * `atencion` amarilla de `GET admin/lead/chats`. Si estuvieran escritas por separado, la
     * tarjeta y la grilla volverían a contradecirse — que es exactamente lo que Lucas reportó el
     * 2/9/2026.
     *
     * @param \Illuminate\Database\Query\Builder $query Subconsulta sobre lead_messages, correlacionada
     *                                                  con `leads` (la tabla exterior, sin alias).
     *
     * @return void
     */
    public static function apply_condicion_mensaje_sin_responder($query): void
    {
        $query->where('lead_messages.sender', 'lead')
            ->where('lead_messages.status', 'enviado')
            /* «No necesita respuesta» (D6, 17/10/2026): dado por atendido hasta ese id, inclusive.
               Con la marca en null no filtra nada, que es el estado de todo lead anterior a la
               columna. Ver el porqué completo en el docblock. */
            ->where(function ($atendido) {
                $atendido->whereNull('leads.atendido_hasta_message_id')
                    ->orWhereColumn('lead_messages.id', '>', 'leads.atendido_hasta_message_id');
            })
            ->where(function ($no_reaccion) {
                $no_reaccion->whereNull('lead_messages.kind')
                    ->orWhere('lead_messages.kind', '<>', 'reaction');
            })
            // Reacciones en formato legado de Kapso (texto plano), ver
            // LeadWhatsappReactionService::is_legacy_reaction_content(). LIKE y no REGEXP:
            // el hosting puede estar en MySQL 5.7.
            ->whereRaw("TRIM(lead_messages.content) NOT LIKE 'Reacted % to message wamid.%'")
            ->whereRaw("TRIM(lead_messages.content) NOT LIKE 'Removed reaction from message wamid.%'");

        /* Nosotros ya le reaccionamos desde el panel: para Lucas eso es una respuesta.
           Gemelo en SQL de LeadMessage::tiene_reaccion_admin(). No lo vuelvas a escribir a
           mano acá: es la misma lección de abajo, para un predicado distinto. */
        LeadMessage::apply_sin_reaccion_admin_conditions($query);

        $query->whereNotExists(function ($outbound) {
            $outbound->selectRaw('1')
                ->from('lead_messages as outbound')
                ->whereColumn('outbound.lead_id', 'lead_messages.lead_id')
                ->whereColumn('outbound.id', '>', 'lead_messages.id');
            /* Gemelo en SQL de LeadMessage::is_reply_to_lead(). No lo vuelvas a
               escribir a mano acá: eran tres copias y se separaron. */
            LeadMessage::apply_reply_to_lead_conditions($outbound, 'outbound');
        });
    }

    public function scopeRequiereRevision($query, $incluir_entrega_fallida = false)
    {
        /* 🔴 Los marcados como "ya no recibe mensajes" quedan afuera de todo.
           No es un refinamiento: es lo que sostiene la promesa de que el número de la tarjeta y las
           filas con color son el mismo conjunto. La grilla no los pinta (ver danger_row_ids en
           Leads.vue), así que si la tarjeta los siguiera contando volvería el defecto que Lucas
           reportó el 2/9/2026 — "dice 2 y veo una sola fila". Y de fondo: no hay nada que hacer con
           un lead al que no le llega nada, así que no tiene por qué pedir atención. */
        $query->whereNull('leads.no_recibe_mensajes_at');

        return $query->where(function ($wrap) use ($incluir_entrega_fallida) {
            // Razón A: mensaje del lead (no reacción) sin ningún saliente posterior.
            $wrap->whereExists(function ($sin_responder) {
                $sin_responder->selectRaw('1')
                    ->from('lead_messages')
                    ->whereColumn('lead_messages.lead_id', 'leads.id');
                self::apply_condicion_mensaje_sin_responder($sin_responder);
            })
            // Razón B: último error sin actividad real posterior.
            ->orWhereExists(function ($error) use ($incluir_entrega_fallida) {
                $error->selectRaw('1')
                    ->from('lead_messages')
                    ->whereColumn('lead_messages.lead_id', 'leads.id')
                    ->where(function ($fuente) use ($incluir_entrega_fallida) {
                        $fuente->where('lead_messages.is_error', true);
                        if ($incluir_entrega_fallida) {
                            $fuente->orWhere('lead_messages.whatsapp_delivery_status', 'fallido');
                        }
                    })
                    ->whereNotExists(function ($posterior) {
                        $posterior->selectRaw('1')
                            ->from('lead_messages as lm_post')
                            ->whereColumn('lm_post.lead_id', 'lead_messages.lead_id')
                            ->whereColumn('lm_post.id', '>', 'lead_messages.id')
                            ->where('lm_post.is_status_event', false);
                    });
            });
        });
    }

    /**
     * Aplica el orden de la bandeja de leads (selector del header en admin-spa: `last_message`,
     * `created_at`, `atencion` — prompt 286/02). Extraído a un scope único porque el criterio de
     * orden estaba **duplicado** en `LeadController::index_json()` y en `SearchController` (el
     * listado con filtros de columna), y ya había divergido por accidente al tocar uno solo.
     *
     * ⚠️ DEPENDENCIA OBLIGATORIA: en modo `atencion` este scope referencia dentro de un `CASE` del
     * `ORDER BY` los alias `row_warning` y `failed_send_count`, que NO son columnas reales de
     * `leads` sino que los agrega el `SELECT` de `scopeWithUnreadLeadMessagesCount()` (MySQL permite
     * referenciar alias del select list en el ORDER BY). Por eso este scope solo funciona sobre un
     * query que YA pasó por `withAllForList()` o `withUnreadLeadMessagesCount()` antes de llamarlo.
     * Aplicado sobre un `Lead::query()` pelado, en modo `atencion` MySQL tira "Unknown column".
     * No reordenar estas líneas asumiendo que son columnas normales.
     *
     * Prioridad del orden, de mayor a menor:
     * 1. Solo en modo `atencion`: primero los leads que necesitan atención humana — rojo
     *    (`pendiente_revision_at` no nulo o `failed_send_count > 0`) antes que amarillo
     *    (`row_warning`), y recién después el resto. Rojo va primero porque el amarillo tiene
     *    respaldo automático (`AutoSendLeadAiSuggestionJob` termina enviando la sugerencia si nadie
     *    la confirma) y el rojo no: un envío fallido se queda ahí hasta que un humano lo vea.
     *
     *    ⚠️ Desde el 2/9/2026 este orden NO es exactamente el criterio del color. El rojo de la
     *    grilla dejó de mirar `pendiente_revision_at` (era la marca del botón de revisión, que se
     *    sacó) y ahora descuenta los leads marcados como "ya no recibe mensajes". Acá se conserva
     *    `pendiente_revision_at` a propósito: es la ÚNICA superficie que le queda al Caso B de
     *    `LeadSuggestionSendService` —el respaldo automático que envió sin que nadie lo revisara—,
     *    que no tiene color propio. Si algún día ese caso gana su propio indicador, esta línea
     *    tiene que salir de acá y el orden vuelve a espejar el color.
     * 2. Siempre (en los tres modos): fijados primero. Dentro de cada bloque de atención (rojo,
     *    amarillo, resto) se sigue respetando "fijados primero" — el pin ya no le gana a la
     *    atención, pero sigue existiendo un escalón más abajo.
     * 3. Desempate final: `created_at DESC` en modo `created_at`; `COALESCE(last_message_at,
     *    created_at) DESC` en los otros dos modos (incluido `atencion`, que no es un criterio
     *    completo de orden sino un prefijo que necesita el desempate de siempre).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $sort_by Modo pedido por el header del SPA: 'last_message' | 'created_at' | 'atencion'.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApplyBandejaOrder($query, $sort_by)
    {
        // Normalización: cualquier valor fuera de la lista blanca (incluido vacío/null) cae al
        // default histórico de la bandeja, 'last_message'.
        $valid_sort_modes = ['last_message', 'created_at', 'atencion'];
        if (!in_array($sort_by, $valid_sort_modes, true)) {
            $sort_by = 'last_message';
        }

        // Prefijo de atención: solo en modo 'atencion', y va ANTES que el criterio de fijados
        // (por eso un lead con atención pendiente sube incluso por encima de los pineados).
        if ($sort_by === 'atencion') {
            $query->orderByRaw('
                CASE
                    WHEN pendiente_revision_at IS NOT NULL OR failed_send_count > 0 THEN 0
                    WHEN row_warning = 1 THEN 1
                    ELSE 2
                END ASC
            ');
        }

        // Fijados primero (pinned_at DESC NULLS LAST). CASE WHEN por compatibilidad con MySQL,
        // que no soporta NULLS LAST directamente. Se mantiene igual en los tres modos.
        $query->orderByRaw('CASE WHEN pinned_at IS NOT NULL THEN 0 ELSE 1 END ASC');
        $query->orderByRaw('pinned_at DESC');

        // Desempate final: creación del lead en modo 'created_at'; último mensaje (con COALESCE
        // para no dejar al final a los leads sin mensajes) en 'last_message' y en 'atencion'.
        if ($sort_by === 'created_at') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByRaw('COALESCE(last_message_at, created_at) DESC');
        }

        return $query;
    }

    /**
     * Empresa-api (Client registrado en admin-api) donde se corre la demo.
     */
    public function target_client()
    {
        return $this->belongsTo(Client::class, 'target_client_id');
    }

    /**
     * Client de producción creado automáticamente al promover el Lead.
     * Es el sistema real donde se corre el user-setup.
     */
    public function promoted_client()
    {
        return $this->belongsTo(Client::class, 'promoted_client_id');
    }

    /**
     * Admin que dio de alta el Lead.
     */
    public function created_by_admin()
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * Admin que activó el toggle de notificaciones para este lead (recibe el push en mensajes entrantes).
     *
     * @deprecated Reemplazado por notification_admins() (tabla pivot lead_admin_notifications).
     *             Se mantiene hasta confirmar que el nuevo sistema funciona correctamente en producción.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function notify_admin()
    {
        return $this->belongsTo(Admin::class, 'notify_admin_id');
    }

    /**
     * Admins que recibirán un WhatsApp al llegar un mensaje de este lead.
     * Reemplaza la columna notify_admin_id: ahora múltiples admins pueden suscribirse.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function notification_admins()
    {
        return $this->belongsToMany(Admin::class, 'lead_admin_notifications', 'lead_id', 'admin_id')
                    ->using(LeadAdminNotification::class);
    }

    /**
     * Demo elegida para este lead desde el catálogo administrado.
     */
    public function demo()
    {
        return $this->belongsTo(Demo::class, 'demo_id');
    }

    /**
     * Tutoriales personalizados incluidos en el mail de demo (ordenados).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function personalized_demo_videos()
    {
        return $this->hasMany(LeadPersonalizedDemoVideo::class, 'lead_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Socios adicionales vinculados al lead (confirmados o pendientes).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function partners()
    {
        return $this->hasMany(LeadPartner::class, 'lead_id');
    }

    /**
     * Socios ya confirmados por el closer (excluye sugerencias pendientes).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function confirmed_partners()
    {
        return $this->hasMany(LeadPartner::class, 'lead_id')->where('pending_confirmation', false);
    }

    /**
     * Llamadas del closer con este lead (múltiples, refactor prompt 484), de la más vieja a la
     * más nueva.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function calls()
    {
        return $this->hasMany(LeadCall::class, 'lead_id')->orderBy('id');
    }

    /**
     * La última llamada del closer con este lead que YA TIENE RESUMEN (la de `id` más alto con
     * `call_summary`), o null.
     *
     * 🔴 Existe para que la pestaña "Resumen" del modal deje de leer `leads.call_summary`, que es
     * una columna LEGADA que nadie escribe desde el refactor de múltiples llamadas por lead: el
     * resumen real vive en `lead_calls.call_summary`, lo escribe `CallSummaryService` y hasta hoy
     * esa pestaña mostraba vacío para toda llamada posterior al refactor.
     *
     * Es "la última CON resumen" y no "la última" a propósito: el caso frecuente es un lead en
     * seguimiento con la primera llamada resumida y una segunda agendada; con "la última" la
     * pestaña se quedaba sin el resumen justo antes de la segunda llamada.
     *
     * `orderByDesc('id')` sin `latestOfMany()` a propósito: con eager loading, `hasOne` se queda con
     * la primera fila de cada grupo, que con ese orden es la de `id` más alto. Es el mismo
     * resultado, sin la subconsulta agregada que arma `ofMany()`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function last_summarized_call()
    {
        return $this->hasOne(LeadCall::class, 'lead_id')
            ->whereNotNull('call_summary')
            ->orderByDesc('id');
    }

    /**
     * Oportunidades del CRM de pipelines donde este lead es el sujeto (misión pipelines-crm,
     * 27/9/2026), abiertas y cerradas, de todos los pipelines.
     *
     * 🔴 No tiene nada que ver con el pipeline de leads (`status` / LeadPipelineStatus): una
     * oportunidad de un pipeline del CRM no cambia el estado comercial del lead ni dispara nada de
     * su automatización. A propósito NO se suma a scopeWithAll(): la pestaña "Pipelines" de la ficha
     * tiene su propio endpoint (`GET pipeline-opportunities?lead_id=`).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function pipeline_opportunities()
    {
        return $this->hasMany(PipelineOpportunity::class, 'lead_id');
    }

    /**
     * Variante de mensaje de welcome asignada a este lead (snapshot A/B).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function welcome_variant()
    {
        return $this->belongsTo(MessageVariant::class, 'welcome_variant_id');
    }

    /**
     * Anuncios Click-to-WhatsApp que trajeron a este teléfono, si los hubo.
     *
     * 🔴 Se ata por TELÉFONO y no por id, a propósito: el bloque `referral` de Meta llega en el
     * primer mensaje de la persona, cuando el lead todavía no existe. La fila de atribución se
     * crea antes que el lead, por un camino aparte
     * ({@see \App\Http\Controllers\MetaRawWebhookController}) que tiene prohibido crear leads.
     *
     * Es solo lectura y no participa de ningún flujo: nada del pipeline de leads depende de esta
     * relación. Si no hay webhook `kind: meta` configurado en Kapso, devuelve vacío y listo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function whatsapp_ad_referrals()
    {
        return $this->hasMany(WhatsappAdReferral::class, 'phone', 'phone');
    }

    /**
     * Conversiones que se le mandaron (o se le van a mandar) a Meta por este lead, si vino de un
     * anuncio Click-to-WhatsApp: una fila por evento. Ver MetaConversionesService.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function eventos_meta()
    {
        return $this->hasMany(LeadEventoMeta::class, 'lead_id')->orderBy('id');
    }

    /**
     * Mensajes de la conversación WhatsApp (lead, setter, sugerencias de Claude).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        // sent_by_admin:id,name (prompt 403) se eager-loadea solo acá (el hilo completo del lead),
        // no en notification_messages()/scopeWithAllForList(): esos listados no muestran el nombre
        // del emisor y el accessor sent_by_admin_name ya devuelve null por la guarda relationLoaded.
        // admin_reaction_by:id,name va por el mismo criterio: solo el hilo completo pinta las
        // reacciones del panel, y su accessor también devuelve null solo si no vino cargada.
        return $this->hasMany(LeadMessage::class, 'lead_id')
            ->with(['attachments', 'sent_by_admin:id,name', 'admin_reaction_by:id,name'])
            ->orderBy('id');
    }

    /**
     * Mensajes de WhatsApp PROGRAMADOS que todavía no salieron.
     *
     * 🔴 Filtrada a `pendiente` + `error` a propósito, y no es una optimización cosmética. Los
     * `enviado` ya existen como `LeadMessage` normal en `messages()` —traerlos también acá los
     * mostraría dos veces en el hilo— y los `cancelado` no le sirven a nadie. Como esta relación
     * viaja en `scopeWithAll()`, o sea en cada respuesta que devuelve un lead, dejarla abierta
     * haría crecer para siempre el peso de todas: un lead con seis meses de programados enviados
     * arrastraría seis meses de filas muertas en cada request.
     *
     * `error` sí entra porque es lo único que el operador tiene que ver para actuar (se le cerró
     * la ventana antes de salir, Meta lo rechazó): un error invisible es un mensaje que nunca se
     * mandó y que nadie se enteró de que no se mandó.
     *
     * Ordenada por `scheduled_send_at` porque así los muestra la conversación —varios programados
     * a la vez, en el orden en que van a salir—, que es la decisión de Lucas del 10/9/2026.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function scheduled_messages()
    {
        return $this->hasMany(LeadScheduledMessage::class, 'lead_id')
            ->whereIn('status', LeadScheduledMessage::STATUSES_VISIBLES)
            ->orderBy('scheduled_send_at');
    }

    /**
     * Mensajes relevantes para notificaciones en listados (sin cargar el hilo completo).
     *
     * Incluye:
     * - mensajes del lead no leídos (`read_at` nulo),
     * - sugerencias de Claude pendientes (`status = sugerido`),
     * - mensajes del lead sin respuesta del setter/sistema posterior.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function notification_messages()
    {
        return $this->hasMany(LeadMessage::class, 'lead_id')
            ->forListNotifications()
            ->with('attachments')
            ->orderBy('id');
    }

    /**
     * Recalcula flags de sugerencias pendientes y seguimiento según mensajes `sugerido`.
     *
     * @return void
     */
    public function sync_suggestion_flags(): void
    {
        $pending = $this->messages()->where('status', 'sugerido')->exists();
        $this->tiene_sugerencia_pendiente = $pending;
        if (! $pending) {
            $this->requiere_seguimiento = false;
        }
        $this->save();
    }

    /**
     * Normaliza fecha de demo a Y-m-d; vacío limpia la columna.
     *
     * @param mixed $value input type="date", ISO o DateTimeInterface.
     */
    public function setDemoDateAttribute($value)
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->attributes['demo_date'] = null;

            return;
        }
        if ($value instanceof \DateTimeInterface) {
            $this->attributes['demo_date'] = $value->format('Y-m-d');

            return;
        }
        $this->attributes['demo_date'] = substr((string) $value, 0, 10);
    }

    /**
     * Hora de inicio de demo: blancos → null.
     *
     * @param mixed $value texto libre (p. ej. 09:00).
     */
    public function setDemoStartTimeAttribute($value)
    {
        $this->attributes['demo_start_time'] = self::nullable_trimmed_string($value);
    }

    /**
     * Hora de fin de demo: blancos → null.
     *
     * @param mixed $value texto libre (p. ej. 18:00).
     */
    public function setDemoEndTimeAttribute($value)
    {
        $this->attributes['demo_end_time'] = self::nullable_trimmed_string($value);
    }

    /**
     * Arma el link completo de ingreso directo a la demo (grupo 233, prompt 01).
     *
     * Sale del `erp_spa_url` de la demo asignada al lead, no de ninguna constante:
     * cada instancia demo tiene su propio subdominio. Devuelve null si todavía no
     * se emitió token o si el lead no tiene demo asignada.
     *
     * 🔴 La URL pasa por DemoUrlNormalizer y NO se concatena cruda (bug reportado por Lucas el
     * 17/8/2026): `erp_spa_url` es texto libre y puede estar guardada sin esquema —el seeder local
     * la guarda así, y el módulo de Demos lo permite—. Este link no se muestra: se navega, con
     * `window.location.href` en BotonAcceso.vue. Sin esquema el navegador lee `empresa.local:`
     * como protocolo, bloquea la navegación, y como eso no es un error de HTTP el `.catch` del SPA
     * nunca corre: el botón se queda en "Entrando…" para siempre. El mail de demo y el contexto
     * del agente de IA ya normalizaban este mismo valor; este camino era el único que no.
     *
     * @return string|null
     */
    public function getDemoIngresoUrlAttribute()
    {
        if (empty($this->demo_ingreso_token) || is_null($this->demo)) {
            return null;
        }
        $base = DemoUrlNormalizer::base($this->demo->erp_spa_url);
        if ($base === '') {
            return null;
        }

        return $base . '/demo/ingreso?t=' . $this->demo_ingreso_token;
    }

    /**
     * Arma el link público de la página inmersiva de demo de este lead (grupo 300, ruta
     * `/experiencia/{uuid}` de `admin-spa`; correctivo del grupo 308, prompt 03).
     *
     * La base sale de `config('services.admin_spa.url')` -- la misma fuente que ya usan
     * DemoScheduledWhatsappService, LeadEscalationWhatsappService y el resto de los links
     * a admin-spa que arma este archivo. No confundir con `route('demo.landing', ...)`
     * (grupo 213): esa landing la sirve admin-api en su propio dominio; la página
     * inmersiva es una ruta de admin-spa, un dominio distinto.
     *
     * @return string|null Null si el lead no tiene uuid.
     */
    public function getDemoExperienciaUrlAttribute()
    {
        $clave = $this->demo_experiencia_clave;
        if ($clave === '') {
            return null;
        }
        $base = rtrim((string) config('services.admin_spa.url'), '/');
        if ($base === '') {
            return null;
        }

        return $base . '/experiencia/' . $clave;
    }

    /**
     * Identificador que va en el link de la página inmersiva (misión demo-agendado-directo,
     * 10/9/2026): los dígitos del teléfono del lead, y sólo si no tiene teléfono, el uuid.
     *
     * Lucas pidió acortar el link y que fuera "más personalizado": el uuid son 36 caracteres
     * opacos, el teléfono es un número que el lead reconoce como propio. Se descartó el id de la
     * base porque es enumerable (con `/experiencia/1`, `/2`, `/3` cualquiera recorre el pipeline
     * comercial entero: nombres, negocios y la puerta de cada demo). El teléfono no se puede
     * recorrer a ciegas y sólo lo conoce quien ya conoce al lead — el mismo nivel de exposición
     * que reenviar el link por WhatsApp.
     *
     * 🔴 Los links con uuid ya enviados siguen resolviendo: `resolver_por_clave_de_experiencia()`
     * acepta las dos formas. No se cambia ningún link viejo.
     *
     * @return string Dígitos del teléfono (sin `+`), el uuid, o '' si no hay ninguno de los dos.
     */
    public function getDemoExperienciaClaveAttribute(): string
    {
        $telefono = trim((string) $this->phone);
        if ($telefono !== '') {
            $digitos = preg_replace('/\D+/', '', WhatsappNormalizer::normalize($telefono));
            if (is_string($digitos) && strlen($digitos) >= 8) {
                return $digitos;
            }
        }

        return (string) $this->uuid;
    }

    /**
     * Resuelve el lead dueño de una página inmersiva a partir de lo que viene en la URL: un uuid
     * (links anteriores al 10/9/2026) o los dígitos de su teléfono (links nuevos, ver
     * getDemoExperienciaClaveAttribute()).
     *
     * 🔴 Con teléfono la comparación es por IGUALDAD del número normalizado (E.164), no la
     * tolerancia por sufijo de WhatsappNormalizer::phones_match(). Esa tolerancia acepta que
     * coincidan los últimos 8 dígitos, y en Argentina el sufijo de 8 pisa parte del código de área
     * del interior: 351-555-1234 (Córdoba) y 261-555-1234 (Mendoza) matchean, y el link del
     * primero abriría la página —y la demo— del segundo (hallazgo del chequeo adversarial de la
     * misión demo-agendado-directo, reproducido). El webhook puede permitirse esa tolerancia porque
     * enruta un mensaje del propio número; acá la clave viene de una URL que cualquiera puede tipear.
     *
     * Si hay varios leads con el mismo número (re-ingresos por otra campaña) gana el MÁS RECIENTE:
     * es el mismo criterio con el que el webhook enruta los mensajes entrantes, así que la página
     * cae en la misma conversación en la que el agente le pasó el link.
     *
     * La consulta se acota por LIKE sobre los últimos ocho dígitos del teléfono NORMALIZADO EN SQL
     * (sin espacios, guiones, paréntesis ni `+`): esta ruta es pública y la página la poleá cada
     * diez segundos, no puede barrer la tabla entera por pedido. Los candidatos se comparan después
     * en PHP con el normalizador completo, que además inserta el 9 de móvil cuando falta.
     *
     * @param string $clave Segmento de la URL: uuid o dígitos de teléfono.
     *
     * @return static|null
     */
    public static function resolver_por_clave_de_experiencia(string $clave)
    {
        $clave = trim($clave);
        if ($clave === '') {
            return null;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clave)) {
            return static::where('uuid', $clave)->first();
        }

        $digitos = preg_replace('/\D+/', '', $clave);
        if (! is_string($digitos) || strlen($digitos) < 8) {
            return null;
        }

        $buscado = WhatsappNormalizer::normalize($digitos);
        if ($buscado === '') {
            return null;
        }

        /* Sólo dígitos del lado de la base, para que "+54 9 351 123-4567" también sea candidato. */
        $telefono_sin_formato = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";

        $candidatos = static::query()
            ->whereNotNull('phone')
            ->whereRaw($telefono_sin_formato . ' LIKE ?', ['%' . substr($digitos, -8) . '%'])
            ->orderBy('id', 'desc')
            ->get(['id', 'phone']);

        foreach ($candidatos as $candidato) {
            if (WhatsappNormalizer::normalize((string) $candidato->phone) === $buscado) {
                /* La consulta trae sólo (id, phone): se devuelve el modelo completo. */
                return static::find($candidato->id);
            }
        }

        return null;
    }

    /**
     * Primer nombre del contacto, derivado de contact_name (primera palabra por espacios).
     * Se usa en los saludos de WhatsApp/mail al lead: "Hola {nombre}" en vez del nombre
     * completo con apellido (decisión de Lucas, 8/9/2026). Refleja la misma nulabilidad que
     * contact_name (null → null, vacío → vacío) para no cambiar el comportamiento de los
     * `?? 'fallback'` que ya consumen contact_name en los call sites existentes.
     *
     * Desde la misión experiencia-landing (11/9/2026) delega en `primer_nombre_de()`: la misma
     * regla la necesita el onboarding ANTES de que exista el lead con su contact_name (recibe el
     * nombre del perfil de WhatsApp suelto), y dos implementaciones de "primera palabra" terminan
     * difiriendo en el caso de borde justo cuando nadie las está mirando.
     *
     * @return string|null
     */
    public function getContactFirstNameAttribute(): ?string
    {
        return self::primer_nombre_de($this->contact_name);
    }

    /**
     * Primera palabra (por espacios) de un nombre: "Juan Pérez" → "Juan". Es la regla única de
     * "tratar al lead por su primer nombre, nunca por nombre y apellido" (decisiones de Lucas del
     * 8/9 y del 11/9/2026), compartida por el accessor `contact_first_name`, el onboarding de
     * WhatsApp y el contexto del agente.
     *
     * Misma nulabilidad que la entrada: null → null, vacío o sólo espacios → '' (no null), para que
     * los `?? 'fallback'` y los `=== ''` de los consumidores sigan comportándose igual que con el
     * accessor de antes.
     *
     * @param string|null $nombre Nombre completo tal como vino (perfil de WhatsApp, contact_name).
     *
     * @return string|null
     */
    public static function primer_nombre_de(?string $nombre): ?string
    {
        if ($nombre === null) {
            return null;
        }
        $trimmed = trim($nombre);
        if ($trimmed === '') {
            return $trimmed;
        }
        $words = preg_split('/\s+/', $trimmed);

        return ($words[0] ?? '') !== '' ? $words[0] : $trimmed;
    }

    /**
     * Estado completo de la tarjeta "Respuestas del formulario de la demo" del modal del lead
     * (misión del 27/8/2026): las nueve respuestas efectivas más de dónde salieron.
     *
     * Toda la lógica vive en `LeadDemoFormMapper::estado_para_panel()`, no acá. El mapper ya es
     * el único lugar del sistema que sabe traducir respuestas ↔ columnas y el único que sabe
     * cuándo valen los defaults del catálogo; partir esa decisión en dos archivos es la forma de
     * que el panel y el demo setup terminen mostrando cosas distintas.
     *
     * 🔴 Igual que `demo_ingreso_url` y `demo_experiencia_url`, este accesor NO va en un
     * `$appends` del modelo: lo appendea a mano `LeadController::prepare_lead_for_detail_json()`
     * para el detalle y nada más. En `$appends` viajaría también en el listado de leads y en los
     * broadcasts, sumando trabajo en payloads donde nadie lo consume.
     *
     * @return array<string, mixed>
     */
    public function getDemoFormPanelAttribute()
    {
        return LeadDemoFormMapper::estado_para_panel($this);
    }

    /**
     * @param mixed $raw
     *
     * @return string|null
     */
    protected static function nullable_trimmed_string($raw)
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim((string) $raw);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Dinámica de demo efectiva de este lead (grupo 293, prompt 01).
     *
     * 🔴 Desde el 14/10/2026 (misión demo-solo-experiencia-nueva) devuelve SIEMPRE
     * self::EXPERIENCIA_NUEVA, sin mirar la columna `demo_experiencia`. Antes devolvía el valor de
     * la columna si era uno de los válidos y caía a 'actual' ante null o basura ("ante la duda, la
     * dinámica que funciona en producción"); esa regla de seguridad se dio vuelta: la dinámica
     * 'actual' está retirada, así que ante cualquier duda —columna null, 'actual', valor
     * inventado, una fila que un UPDATE directo dejó mal— el lead usa la página de experiencia.
     *
     * Es la capa de LECTURA del candado (la de escritura es el hook `saving` de `booted()`): si
     * alguien saca el forzado de arriba, o escribe la columna por fuera de Eloquent, el
     * comportamiento del lead no cambia. Todo lo que decide por dinámica —y hay mucho: los
     * comandos de ciclo de demo, el agente, el payload del setup— pasa por acá o por
     * usa_experiencia_demo_nueva().
     *
     * @return string Siempre self::EXPERIENCIA_NUEVA.
     */
    public function demo_experiencia_efectiva(): string
    {
        return self::EXPERIENCIA_NUEVA;
    }

    /**
     * Azúcar sobre demo_experiencia_efectiva(): true si a este lead le toca la dinámica nueva.
     *
     * @return bool
     */
    public function usa_experiencia_demo_nueva(): bool
    {
        return $this->demo_experiencia_efectiva() === self::EXPERIENCIA_NUEVA;
    }

    /**
     * Perfil efectivo de este lead para decidir qué versión del scroll de la página inmersiva ve
     * (grupo 300, prompt 01 — `contexto/demo_experiencia.md` §3.17).
     *
     * Devuelve el valor de la columna `perfil_lead` si es uno de los válidos (self::PERFILES); en
     * cualquier otro caso (null, string vacío, valor desconocido) devuelve self::PERFIL_DUENO.
     * Mismo criterio de fallback que `demo_experiencia_efectiva()`: ante la duda, la versión
     * dueño, porque el costo de errarle es asimétrico (§3.17).
     *
     * @return string
     */
    public function perfil_lead_efectivo(): string
    {
        if (in_array($this->perfil_lead, self::PERFILES, true)) {
            return $this->perfil_lead;
        }

        return self::PERFIL_DUENO;
    }
}
