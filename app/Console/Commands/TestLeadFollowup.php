<?php

namespace App\Console\Commands;

use App\Models\FollowupRule;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadFollowupService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando de testing local para simular seguimientos automáticos de leads
 * sin esperar los tiempos reales definidos en followup_rules.
 *
 * Crea un lead de prueba con historial de conversación realista,
 * manipula los timestamps para que el servicio lo detecte como vencido
 * y dispara LeadFollowupService directamente.
 *
 * Uso:
 *   php artisan leads:test-followup --estado=contactado
 *   php artisan leads:test-followup --estado=demo_realizada --followup=2
 *   php artisan leads:test-followup --estado=nuevo --limpiar
 */
class TestLeadFollowup extends Command
{
    /**
     * Firma del comando con sus opciones.
     *
     * @var string
     */
    protected $signature = 'leads:test-followup
        {--estado= : Estado del lead a simular (nuevo|contactado|calificado|demo_agendada|demo_realizada|mail2_enviado). Requerido.}
        {--followup=1 : Número de followup a simular (1, 2 o 3). Default: 1.}
        {--limpiar : Si se pasa, elimina todos los leads de prueba [TEST] antes de crear uno nuevo.}';

    /**
     * Descripción del comando mostrada en php artisan list.
     *
     * @var string
     */
    protected $description = 'Testea un escenario de seguimiento automático de leads sin esperar los tiempos reales';

    /**
     * Estados válidos que acepta el comando.
     *
     * @var array<int, string>
     */
    private const VALID_STATES = [
        'nuevo',
        'contactado',
        'calificado',
        'demo_agendada',
        'demo_realizada',
        'mail2_enviado',
    ];

    /**
     * Prefijo en contact_name que identifica leads creados por este comando.
     * Permite limpiar todos los leads de prueba de forma segura.
     *
     * @var string
     */
    private const TEST_PREFIX = '[TEST]';

    /**
     * Orquesta la ejecución completa del comando.
     *
     * @param LeadFollowupService $followup_service Servicio inyectado por el contenedor.
     *
     * @return int 0 en éxito, 1 en error.
     */
    public function handle(LeadFollowupService $followup_service): int
    {
        /* Validar --estado antes de cualquier operación sobre la BD */
        $estado = (string) $this->option('estado');
        if ($estado === '' || ! in_array($estado, self::VALID_STATES, true)) {
            $this->error('--estado es requerido. Valores válidos: ' . implode('|', self::VALID_STATES));
            return 1;
        }

        /* Validar --followup */
        $followup_num = (int) $this->option('followup');
        if ($followup_num < 1 || $followup_num > 3) {
            $this->error('--followup debe ser 1, 2 o 3.');
            return 1;
        }

        /* Eliminar leads de prueba previos si se solicitó con --limpiar */
        if ($this->option('limpiar')) {
            $this->cleanup_test_leads();
        }

        /* Buscar la regla activa para el estado; advertir si no existe */
        $rule = FollowupRule::query()
            ->where('estado', $estado)
            ->where('activa', true)
            ->first();

        if (! $rule) {
            $this->warn("No hay followup_rule activa para '{$estado}'. Se usará 25h de espera como fallback.");
        }

        /* Crear el lead de prueba */
        $lead = $this->create_test_lead($estado, $followup_num);
        $this->info("Lead creado → ID: {$lead->id} | {$lead->contact_name} | Estado: {$lead->status}");

        /* Crear el historial de conversación correspondiente al estado */
        $this->create_conversation_messages($lead, $estado, $followup_num);

        /* Manipular timestamps para que el servicio detecte el lead como vencido */
        $this->backdate_to_expired($lead, $rule);

        /* Recargar con relación messages para que el servicio tenga el historial */
        $fresh_lead = Lead::query()->with('messages')->find($lead->id);

        /* Disparar el servicio directamente, sin esperar el cron */
        $this->line('Llamando a LeadFollowupService::process_single_lead()...');

        try {
            $result = $followup_service->process_single_lead($fresh_lead);
        } catch (\Throwable $e) {
            $this->error('Error al procesar el lead: ' . $e->getMessage());
            return 1;
        }

        /* Mostrar el resultado del procesamiento */
        $this->display_result($fresh_lead, $result);

        return 0;
    }

    /**
     * Elimina todos los leads cuyo contact_name empiece con el prefijo [TEST].
     * Usa el método deleting del modelo para también borrar sus mensajes.
     *
     * @return void
     */
    private function cleanup_test_leads(): void
    {
        /* Cargar para disparar el evento deleting del modelo (borra mensajes asociados) */
        $test_leads = Lead::query()
            ->where('contact_name', 'like', self::TEST_PREFIX . '%')
            ->get();

        foreach ($test_leads as $test_lead) {
            $test_lead->delete();
        }

        $this->info("Leads de prueba eliminados: {$test_leads->count()}");
    }

    /**
     * Crea el lead de prueba con los datos base del escenario simulado.
     *
     * @param string $estado       Estado del pipeline a simular.
     * @param int    $followup_num Número de followup que se quiere testear.
     *
     * @return Lead Lead persistido en BD.
     */
    private function create_test_lead(string $estado, int $followup_num): Lead
    {
        return Lead::create([
            'contact_name'               => self::TEST_PREFIX . " Lead prueba {$estado} followup {$followup_num}",
            'company_name'               => 'Empresa de prueba',
            'phone'                      => '5491100000000',
            'business_type'              => 'Distribuidora',
            'status'                     => $estado,
            'notes'                      => 'Lead creado automáticamente para testing de seguimientos',
            'tiene_sugerencia_pendiente' => false,
            'requiere_seguimiento'       => false,
            'tiene_seguimiento_sin_ver'  => false,
        ]);
    }

    /**
     * Crea mensajes de conversación que simulan el historial hasta el estado dado.
     *
     * Si followup_num > 1, agrega también los mensajes de followup ya enviados
     * anteriormente para que el servicio pueda contarlos correctamente.
     *
     * @param Lead   $lead         Lead al que se le asocian los mensajes.
     * @param string $estado       Estado del pipeline para determinar la conversación.
     * @param int    $followup_num Número de followup objetivo; si >1 se crean previos.
     *
     * @return void
     */
    private function create_conversation_messages(Lead $lead, string $estado, int $followup_num): void
    {
        /* Obtener los mensajes de conversación correspondientes al estado */
        $messages_data = $this->get_messages_for_state($estado);

        /* Ancla temporal: 7 días atrás como inicio de la conversación */
        $base_time = Carbon::now()->subDays(7);

        /* Crear los mensajes de conversación con timestamps escalonados */
        foreach ($messages_data as $idx => $msg_data) {
            $created_msg = LeadMessage::create(array_merge($msg_data, ['lead_id' => $lead->id]));

            /* Escalonar cada mensaje 2 horas más adelante que el anterior */
            $msg_time = $base_time->copy()->addHours($idx * 2);

            DB::table('lead_messages')->where('id', $created_msg->id)->update([
                'created_at' => $msg_time,
                'updated_at' => $msg_time,
            ]);
        }

        /* Crear followups previos simulados si se quiere testear followup 2 o 3 */
        $prev_followups_needed = $followup_num - 1;

        for ($i = 0; $i < $prev_followups_needed; $i++) {
            $followup_msg = LeadMessage::create([
                'lead_id'               => $lead->id,
                'sender'                => 'sistema',
                'content'               => 'Seguimiento automático previo #' . ($i + 1) . ' (simulado para testing).',
                'status'                => 'enviado',
                'is_followup'           => true,
                'requiere_verificacion' => false,
            ]);

            /*
             * Los followups previos se ubican entre la conversación y el momento actual,
             * separados 12 horas entre sí para simular una cadencia realista.
             */
            $followup_time = Carbon::now()->subHours(($prev_followups_needed - $i) * 12 + 2);

            DB::table('lead_messages')->where('id', $followup_msg->id)->update([
                'created_at' => $followup_time,
                'updated_at' => $followup_time,
            ]);
        }
    }

    /**
     * Retorna el array de datos de mensajes para el estado dado.
     *
     * Cada estado acumula los mensajes del anterior para reflejar
     * fielmente cómo avanza una conversación real en el pipeline.
     *
     * @param string $estado Estado del pipeline.
     *
     * @return array<int, array<string, mixed>> Datos de mensajes listos para LeadMessage::create().
     */
    private function get_messages_for_state(string $estado): array
    {
        /* Primer mensaje del lead respondiendo al setter de presentación */
        $msg_contacto_lead = [
            'sender'                => 'lead',
            'content'               => 'Hola, tengo una ferretería, somos 3 empleados',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ];

        /* Respuesta del setter con preguntas de calificación */
        $msg_calificacion_setter = [
            'sender'                => 'sistema',
            'content'               => '¡Hola! Excelente, una ferretería con 3 empleados es exactamente el perfil para el que está pensado ComercioCity. Para mostrarte cómo funciona: ¿tienen sucursales o todo desde un solo local? ¿Trabajan con listas de precios o precio fijo?',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ];

        /* Lead responde las preguntas de calificación con detalle */
        $msg_calificacion_lead = [
            'sender'                => 'lead',
            'content'               => 'Un solo local. Tenemos 2 listas de precios: minorista y mayorista. También hacemos pedidos a proveedores y nos gustaría llevar mejor el stock.',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ];

        /* Setter propone la demo tras calificar al lead */
        $msg_demo_propuesta = [
            'sender'                => 'sistema',
            'content'               => 'Perfecto, todo eso lo maneja ComercioCity muy bien. ¿Qué días y horarios tenés disponibilidad para una demo de 1 hora? Puedo mostrarte el sistema funcionando en vivo.',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ];

        /* Lead acepta y propone un horario para la demo */
        $msg_demo_aceptada_lead = [
            'sender'                => 'lead',
            'content'               => 'Podría ser el jueves a la mañana, entre las 9 y las 11',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ];

        /* Setter confirma la demo en el horario pactado */
        $msg_demo_confirmada = [
            'sender'                => 'sistema',
            'content'               => 'Perfecto, quedamos para el jueves a las 9:00 hs. Te voy a pasar el link de la demo. ¡Cualquier cosa avisame!',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ];

        /* Setter hace seguimiento post-demo para obtener feedback */
        $msg_post_demo_setter = [
            'sender'                => 'sistema',
            'content'               => 'Hola! ¿Cómo te pareció la demo? ¿Quedaste con alguna duda o querés que profundicemos en algún módulo?',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ];

        /* Lead responde positivamente y pide el detalle de precios */
        $msg_post_demo_lead = [
            'sender'                => 'lead',
            'content'               => 'Me gustó mucho, especialmente la parte de stock y listas de precios. ¿Me podés mandar el detalle de precios?',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ];

        /* Setter confirma envío del mail con la propuesta */
        $msg_propuesta_enviada = [
            'sender'                => 'sistema',
            'content'               => 'Te mandé el detalle de precios al mail. ¿Pudiste verlo? ¿Tenés alguna pregunta sobre los planes?',
            'status'                => 'enviado',
            'is_followup'           => false,
            'requiere_verificacion' => false,
        ];

        /* Construir lista acumulativa según el estado */
        switch ($estado) {
            case 'nuevo':
                /* Lead recién cargado, nunca respondió; sin mensajes de conversación */
                return [];

            case 'contactado':
                /* Solo el primer mensaje del lead */
                return [
                    $msg_contacto_lead,
                ];

            case 'calificado':
                /* Conversación hasta obtener respuestas de calificación */
                return [
                    $msg_contacto_lead,
                    $msg_calificacion_setter,
                    $msg_calificacion_lead,
                ];

            case 'demo_agendada':
                /* Conversación hasta confirmación de fecha y hora de la demo */
                return [
                    $msg_contacto_lead,
                    $msg_calificacion_setter,
                    $msg_calificacion_lead,
                    $msg_demo_propuesta,
                    $msg_demo_aceptada_lead,
                    $msg_demo_confirmada,
                ];

            case 'demo_realizada':
                /*
                 * Conversación hasta el follow-up post-demo.
                 * El lead no respondió aún → el setter espera feedback.
                 */
                return [
                    $msg_contacto_lead,
                    $msg_calificacion_setter,
                    $msg_calificacion_lead,
                    $msg_demo_propuesta,
                    $msg_demo_aceptada_lead,
                    $msg_demo_confirmada,
                    $msg_post_demo_setter,
                ];

            case 'mail2_enviado':
                /* Conversación completa hasta envío de propuesta por mail */
                return [
                    $msg_contacto_lead,
                    $msg_calificacion_setter,
                    $msg_calificacion_lead,
                    $msg_demo_propuesta,
                    $msg_demo_aceptada_lead,
                    $msg_demo_confirmada,
                    $msg_post_demo_setter,
                    $msg_post_demo_lead,
                    $msg_propuesta_enviada,
                ];

            default:
                return [];
        }
    }

    /**
     * Manipula los timestamps del lead y del último mensaje para que el
     * servicio de followup detecte el lead como vencido.
     *
     * Setea created_at del último mensaje (y updated_at del lead) a:
     *   now() - horas_espera_de_la_regla - 1 hora
     *
     * Si no hay regla disponible, usa 25 horas como fallback seguro.
     *
     * @param Lead              $lead Lead a manipular.
     * @param FollowupRule|null $rule Regla activa del estado del lead; null si no existe.
     *
     * @return void
     */
    private function backdate_to_expired(Lead $lead, ?FollowupRule $rule): void
    {
        /* 1 hora de margen adicional garantiza que diffInHours supere el umbral */
        $horas_espera = $rule ? ((int) $rule->horas_espera + 1) : 25;

        /* Timestamp que supera el umbral de horas_espera de la regla */
        $expired_at = Carbon::now()->subHours($horas_espera);

        /*
         * Forzar created_at y updated_at del lead vía DB::table para evitar que
         * Eloquent sobreescriba updated_at automáticamente al asignar la colección.
         * created_at es la referencia de last_message_at cuando no hay mensajes (estado nuevo).
         */
        DB::table('leads')->where('id', $lead->id)->update([
            'created_at' => $expired_at,
            'updated_at' => $expired_at,
        ]);

        /* Actualizar created_at del último mensaje no rechazado (referencia principal de last_message_at) */
        $last_msg = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('status', '!=', 'rechazado')
            ->orderByDesc('id')
            ->first();

        if ($last_msg) {
            DB::table('lead_messages')->where('id', $last_msg->id)->update([
                'created_at' => $expired_at,
                'updated_at' => $expired_at,
            ]);
            $this->line("  Lead + último mensaje backdateados → {$expired_at->format('Y-m-d H:i:s')} ({$horas_espera}h atrás)");
        } else {
            $this->line("  Sin mensajes; lead backdateado → {$expired_at->format('Y-m-d H:i:s')} ({$horas_espera}h atrás)");
        }
    }

    /**
     * Muestra en consola el resultado del procesamiento del followup.
     *
     * Según el resultado del servicio, muestra la sugerencia generada por Claude
     * con su razonamiento, estado sugerido y flag de verificación.
     *
     * @param Lead        $lead   Lead procesado (puede no tener mensajes recargados).
     * @param string|null $result Resultado del servicio: 'suggestion', 'paused' o null.
     *
     * @return void
     */
    private function display_result(Lead $lead, ?string $result): void
    {
        $this->newLine();
        $this->line('══════════════════════════════════════');
        $this->line('  RESULTADO DEL PROCESAMIENTO');
        $this->line('══════════════════════════════════════');

        /* El servicio omitió el lead (ya tiene sugerencia pendiente, sin regla o no venció) */
        if ($result === null) {
            $this->warn('El servicio omitió el lead.');
            $this->line('Causas posibles:');
            $this->line('  - tiene_sugerencia_pendiente = true');
            $this->line('  - No existe followup_rule activa para el estado');
            $this->line('  - El tiempo aún no venció (revisar backdate)');
            return;
        }

        /* El lead superó max_followups y fue pausado automáticamente */
        if ($result === 'paused') {
            $this->warn('El lead fue pasado a EN PAUSA por superar max_followups.');
            $this->line("Lead ID: {$lead->id} | {$lead->contact_name}");
            return;
        }

        /* Obtener la sugerencia recién generada por Claude (último mensaje del sistema) */
        $suggestion_msg = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sender', 'sistema')
            ->orderByDesc('id')
            ->first();

        if (! $suggestion_msg) {
            $this->warn('Resultado fue suggestion pero no se encontró el mensaje generado.');
            return;
        }

        /* Recargar el lead para reflejar el estado aplicado por el servicio */
        $updated_lead = Lead::find($lead->id);

        $this->info("Lead ID:          {$lead->id}");
        $this->info("Nombre:           {$lead->contact_name}");
        $this->info('Estado aplicado:  ' . ($updated_lead->status ?? $lead->status));
        $this->newLine();

        $this->line('┌─ MENSAJE SUGERIDO ─────────────────');
        $this->line($suggestion_msg->content);
        $this->newLine();

        $this->line('┌─ RAZONAMIENTO ─────────────────────');
        $reasoning = trim((string) ($suggestion_msg->ai_reasoning ?? ''));
        $this->line($reasoning !== '' ? $reasoning : '(sin razonamiento)');
        $this->newLine();

        /* Estado sugerido solo existe cuando Claude propone un cambio de estado */
        $suggested_status = trim((string) ($suggestion_msg->suggested_lead_status ?? ''));
        $this->line('┌─ ESTADO SUGERIDO ──────────────────');
        $this->line($suggested_status !== '' ? $suggested_status : '(mantiene estado actual)');
        $this->newLine();

        /* Indicador de si el setter debe verificar antes de enviar */
        $requiere = $suggestion_msg->requiere_verificacion ? '⚠ SÍ — verificar antes de enviar' : 'NO';
        $this->line("Requiere verificación: {$requiere}");
        $this->line('══════════════════════════════════════');
    }
}
