<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadMessage;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando de testing para recrear rápidamente un lead calificado, con
 * conversación completa, justo antes de agendar la demo.
 *
 * El escenario que arma es el siguiente: Claude ya tiene el contexto del lead
 * (nombre, rubro, calificación) y acaba de ofrecer agendar la demo, dejando el
 * último mensaje en status `sugerido` (con `suggested_lead_status: demo_agendada`),
 * a la espera de que el lead elija un horario.
 *
 * Pensado para escribir desde WhatsApp con el número provisto en --phone y ver
 * cómo el sistema procesa la respuesta del lead como si fuera real.
 *
 * Uso:
 *   php artisan leads:seed-demo-testing --phone=5493415000000
 *   php artisan leads:seed-demo-testing --phone=5493415000000 --nombre=Lucas --empresa="Ferretería El Tornillo" --rubro=ferretería
 *   php artisan leads:seed-demo-testing --phone=5493415000000 --limpiar
 */
class SeedDemoTestingLead extends Command
{
    /**
     * Firma del comando con sus opciones.
     *
     * @var string
     */
    protected $signature = 'leads:seed-demo-testing
        {--phone= : Número de WhatsApp del lead (sin +, ej: 5493415000000). Requerido.}
        {--nombre= : Nombre del contacto. Default: "Lucas".}
        {--empresa= : Nombre de la empresa. Default: "Ferretería El Tornillo".}
        {--rubro= : Rubro del negocio. Default: "ferretería".}
        {--limpiar : Si se pasa, elimina primero todos los leads cuyo contact_name empiece con "[DEMO-TEST]".}';

    /**
     * Descripción del comando mostrada en php artisan list.
     *
     * @var string
     */
    protected $description = 'Crea un lead de testing calificado con conversación completa, justo antes de agendar la demo';

    /**
     * Prefijo en contact_name que identifica leads creados por este comando.
     * Permite limpiar todos los leads de testing de forma segura.
     *
     * @var string
     */
    private const DEMO_TEST_PREFIX = '[DEMO-TEST]';

    /**
     * Orquesta la ejecución completa del comando.
     *
     * @return int 0 en éxito, 1 en error.
     */
    public function handle(): int
    {
        /* Validar --phone antes de cualquier operación sobre la BD */
        $phone = trim((string) $this->option('phone'));
        if ($phone === '') {
            $this->error('--phone es requerido. Ej: --phone=5493415000000');
            return 1;
        }

        /* Resolver el resto de las opciones con sus defaults */
        $nombre  = trim((string) $this->option('nombre'))  ?: 'Lucas';
        $empresa = trim((string) $this->option('empresa')) ?: 'Ferretería El Tornillo';
        $rubro   = trim((string) $this->option('rubro'))   ?: 'ferretería';

        /* Eliminar leads de testing previos si se solicitó con --limpiar */
        if ($this->option('limpiar')) {
            $this->cleanup_demo_test_leads();
        }

        /* Crear el lead calificado con los datos base del escenario */
        $lead = $this->create_demo_test_lead($phone, $nombre, $empresa, $rubro);

        /* Crear la conversación completa con timestamps escalonados */
        $this->create_conversation_messages($lead, $nombre, $rubro);

        /*
         * El último mensaje quedó en status `sugerido`, por lo que el lead tiene
         * una sugerencia pendiente de acción. Reflejar el flag en el modelo.
         */
        $lead->tiene_sugerencia_pendiente = false;
        $lead->save();

        /* Mostrar el resumen del lead creado */
        $this->newLine();
        $this->info("Lead creado → ID: {$lead->id} | {$lead->contact_name} | Estado: calificado");
        $this->info("Phone: {$lead->phone}");
        $this->info('Mensajes creados: 7');
        $this->info('Último mensaje: aprobado (Martín ya ofreció la demo, esperando horario del lead)');
        $this->newLine();
        $this->line('El lead está listo para testear. Escribí desde WhatsApp con ese número');
        $this->line('y el sistema va a procesar tu respuesta como si fueras el lead.');

        return 0;
    }

    /**
     * Elimina todos los leads cuyo contact_name empiece con el prefijo [DEMO-TEST].
     *
     * Usa el método delete() de Eloquent (no un delete masivo por query) para
     * disparar el evento `deleting` del modelo Lead, que a su vez borra los
     * mensajes y los personalized_demo_videos asociados.
     *
     * @return void
     */
    private function cleanup_demo_test_leads(): void
    {
        /* Cargar para disparar el evento deleting del modelo (borra mensajes y videos asociados) */
        $demo_test_leads = Lead::query()
            ->where('contact_name', 'like', self::DEMO_TEST_PREFIX . '%')
            ->get();

        foreach ($demo_test_leads as $demo_test_lead) {
            $demo_test_lead->delete();
        }

        $this->info("Leads [DEMO-TEST] eliminados: {$demo_test_leads->count()}");
    }

    /**
     * Crea el lead de testing calificado con los datos base del escenario.
     *
     * @param string $phone   Número de WhatsApp del lead (sin +).
     * @param string $nombre  Nombre del contacto.
     * @param string $empresa Nombre de la empresa.
     * @param string $rubro   Rubro del negocio.
     *
     * @return Lead Lead persistido en BD.
     */
    private function create_demo_test_lead(string $phone, string $nombre, string $empresa, string $rubro): Lead
    {
        return Lead::create([
            'contact_name'               => self::DEMO_TEST_PREFIX . ' ' . $nombre,
            'company_name'               => $empresa,
            'phone'                      => $phone,
            'status'                     => 'calificado',
            'business_type'              => $rubro,
            'notes'                      => 'Lead de testing creado con leads:seed-demo-testing.',
            'tiene_sugerencia_pendiente' => false,
            'requiere_seguimiento'       => false,
            'tiene_seguimiento_sin_ver'  => false,
        ]);
    }

    /**
     * Crea los 7 mensajes de la conversación en orden, con timestamps escalonados.
     *
     * Cada mensaje se ubica 10 minutos después del anterior, empezando 60 minutos
     * atrás. Los timestamps se fuerzan vía DB::table para evitar que Eloquent los
     * sobreescriba con la hora actual al persistir el modelo.
     *
     * @param Lead   $lead   Lead al que se asocian los mensajes.
     * @param string $nombre Nombre del contacto (para personalizar los mensajes).
     * @param string $rubro  Rubro del negocio (para personalizar los mensajes).
     *
     * @return void
     */
    private function create_conversation_messages(Lead $lead, string $nombre, string $rubro): void
    {
        /* Datos de los 7 mensajes que componen la conversación calificada */
        $messages_data = $this->get_conversation_messages($nombre, $rubro);

        /* Ancla temporal: la conversación empieza 60 minutos atrás */
        $base_time = Carbon::now()->subMinutes(60);

        /* Crear cada mensaje y forzar su timestamp escalonado (10 min entre mensajes) */
        foreach ($messages_data as $idx => $msg_data) {
            $created_msg = LeadMessage::create(array_merge($msg_data, ['lead_id' => $lead->id]));

            /* Cada mensaje 10 minutos después del anterior */
            $msg_time = $base_time->copy()->addMinutes($idx * 10);

            DB::table('lead_messages')->where('id', $created_msg->id)->update([
                'created_at' => $msg_time,
                'updated_at' => $msg_time,
            ]);
        }
    }

    /**
     * Retorna los datos de los 7 mensajes de la conversación calificada.
     *
     * Reproduce el protocolo real de ComercioCity: presentación automática,
     * preguntas de calificación, respuestas del lead y oferta de demo (el último
     * mensaje queda `sugerido` con suggested_lead_status = demo_agendada).
     *
     * @param string $nombre Nombre del contacto.
     * @param string $rubro  Rubro del negocio.
     *
     * @return array<int, array<string, mixed>> Datos listos para LeadMessage::create().
     */
    private function get_conversation_messages(string $nombre, string $rubro): array
    {
        return [
            /* Mensaje 1 — presentación automática de ComercioCity */
            [
                'sender'  => 'sistema',
                'status'  => 'aprobado',
                'content' => "Hola! Soy del equipo de ComercioCity.\n\n"
                    . "Ayudamos a distribuidoras y comercios a profesionalizar su operación: stock, ventas, "
                    . "facturación ARCA, ecommerce integrado y WhatsApp conectado al sistema — todo en un solo lugar.\n\n"
                    . "La implementación la hacemos nosotros: te hacemos unas preguntas por WhatsApp, y te entregamos "
                    . "el sistema andando con tu información ya cargada. Sin tecnicismos, sin que tengas que hacer nada.\n\n"
                    . "Para ver si encajamos con lo que necesitás, contame: ¿a qué se dedica tu empresa y cuántas "
                    . "personas trabajan con vos?",
            ],

            /* Mensaje 2 — el lead responde con su rubro y cantidad de empleados */
            [
                'sender'  => 'lead',
                'status'  => 'enviado',
                'content' => "Hola, tenemos una {$rubro}. Somos 4 empleados.",
            ],

            /* Mensaje 3 — preguntas de calificación del sistema */
            [
                'sender'  => 'sistema',
                'status'  => 'aprobado',
                'content' => "Buenas, {$nombre}! Una {$rubro} con 4 personas es exactamente el perfil para el que "
                    . "está pensado ComercioCity.\n\n"
                    . "Tres preguntas rápidas para ver si se adapta bien a lo que necesitás:\n\n"
                    . "¿Cómo manejás el stock y las ventas hoy — en Excel, con algún sistema, o de memoria?\n\n"
                    . "¿Facturan electrónicamente (ARCA)?\n\n"
                    . "¿Tienen o les interesaría vender por internet (ecommerce, Mercado Libre)?",
            ],

            /* Mensaje 4 — el lead responde las preguntas de calificación */
            [
                'sender'  => 'lead',
                'status'  => 'enviado',
                'content' => "En Excel básicamente. Facturamos algo pero no todo. Y vender por internet no por ahora, "
                    . "pero en el futuro sí.",
            ],

            /* Mensaje 5 — el sistema indaga el problema concreto */
            [
                'sender'  => 'sistema',
                'status'  => 'aprobado',
                'content' => "Perfecto. Y una más: ¿qué problema concreto los llevó a buscar una solución ahora?",
            ],

            /* Mensaje 6 — el lead expone su dolor (descuadre de stock) */
            [
                'sender'  => 'lead',
                'status'  => 'enviado',
                'content' => "El stock no nos cierra. Siempre hay diferencias entre lo que tenemos anotado y lo que "
                    . "hay en el depósito. Y a veces vendemos cosas que no tenemos.",
            ],

            /* Mensaje 7 — el sistema ofrece la demo: ya enviado (simula que Martín ya lo mandó al lead) */
            [
                'sender'  => 'sistema',
                'status'  => 'aprobado',
                'content' => "Claro, eso es exactamente lo que resuelve ComercioCity — cada venta descuenta el stock "
                    . "en tiempo real, sin depender de que alguien lo anote.\n\n"
                    . "Lo que te propongo es que recorras nuestra demo. La hacés vos solo, con videos cortos, dura "
                    . "aproximadamente una hora. Y ni bien terminás, coordinamos una llamada de 10 minutos para "
                    . "contarte cómo sería la implementación en tu negocio puntualmente.\n\n"
                    . "¿Qué días y horarios te quedan bien esta semana?",
            ],
        ];
    }
}

