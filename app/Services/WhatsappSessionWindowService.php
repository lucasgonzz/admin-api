<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\ClientAssistantMessage;
use App\Models\WhatsappConfig;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve si la ventana de 24hs de Meta está abierta para un número.
 *
 * Meta solo deja mandar texto libre dentro de las 24hs posteriores al último mensaje que
 * ESE número le mandó al negocio; fuera de esa ventana hay que usar una plantilla aprobada.
 * La ventana es por par de números, no por canal: leads, soporte e implementación comparten
 * el mismo WhatsappConfig::getActive(), así que un entrante por cualquiera de los tres la
 * abre para los tres. Por eso se miran las tres tablas.
 *
 * 🔴 **Y por eso mismo, desde el 13/10/2026 el propósito importa** (misión
 * whatsapp-dos-numeros). El asistente de los dueños vive en OTRO número de WhatsApp, así que es
 * otro par de números y otra ventana: lo que el dueño le escribió al número institucional no
 * habilita texto libre por el número del asistente, ni al revés. Con `proposito = 'asistente'` la
 * ventana se calcula sobre `client_assistant_messages`, que es el hilo de ese número. Con null o
 * con `'principal'` se miran las tres tablas de siempre, idéntico a antes de la misión.
 *
 * Ante la duda (sin ningún registro entrante) se devuelve CERRADA: mandar una plantilla
 * pudiendo mandar texto libre es una molestia; mandar texto libre con la ventana cerrada es
 * un mensaje que Meta rechaza y que el cliente nunca ve.
 */
class WhatsappSessionWindowService
{
    /**
     * Duración de la ventana de atención al cliente de Meta, en horas.
     */
    const WINDOW_HOURS = 24;

    /**
     * Entrantes de las últimas 24hs por canal, cacheados durante el request.
     *
     * El endpoint de contactos pregunta por la ventana una vez por teléfono del cliente, y sin
     * esto un cliente con seis empleados traía seis veces el mismo conjunto de filas.
     *
     * @var array<string, \Illuminate\Support\Collection>
     */
    private $rows_cache = [];

    /**
     * Estado de la ventana para un teléfono.
     *
     * @param string      $phone     Teléfono en cualquier formato.
     * @param string|null $proposito Número por el que se va a mandar: uno de los
     *        WhatsappConfig::PROPOSITO_*. 🔴 **Opcional y con default null a propósito**: hay
     *        varios consumidores (la bandeja de soporte, los mensajes programados, el endpoint de
     *        contactos, los seguimientos de leads) y ninguno se tocó. Null se comporta exactamente
     *        como antes del 13/10/2026.
     *
     * @return array{open: bool, last_inbound_at: string|null, expires_at: string|null, origin: string|null}
     */
    public function window_state(string $phone, ?string $proposito = null): array
    {
        $closed = [
            'open'            => false,
            'last_inbound_at' => null,
            'expires_at'      => null,
            'origin'          => null,
        ];

        if (trim($phone) === '') {
            return $closed;
        }

        $cutoff = now()->subHours(self::WINDOW_HOURS);

        /*
         * 🔴 Se consultan los TRES canales y se elige el entrante MÁS RECIENTE. No se corta en el
         * primero que da positivo, aunque para el booleano `open` alcanzaría.
         *
         * Hasta el 10/9/2026 esto cortaba en el primer canal con algún entrante, y era correcto
         * para lo único que se consumía entonces: `open`, que es el OR de los tres. Pero
         * `expires_at` salía del canal que respondió primero, no del último mensaje real — y con
         * un número que escribió a soporte hace 23 hs y al hilo del lead hace 10 minutos, devolvía
         * una ventana que vencía en una hora en vez de en veinticuatro.
         *
         * Lo destapó la misión de mensajes programados: ahí `expires_at` pasó a ser el TECHO que
         * decide hasta qué hora se puede programar texto libre, así que un vencimiento corto de
         * más rechaza envíos que Meta habría aceptado. Errar para el lado de "cerrada" cuesta una
         * plantilla de más cuando es solo un booleano; cuesta un mensaje que no sale cuando es una
         * fecha.
         *
         * El costo son tres consultas en vez de una en el peor caso — acotado, porque `rows_for()`
         * las cachea por request y el consumidor que más pega (el endpoint de contactos) pregunta
         * muchas veces por teléfonos distintos sobre las mismas filas.
         */
        /* El canal del asistente tiene UN solo buscador y no tres, porque es otro número: los
         * entrantes de soporte, leads e implementación llegaron al institucional y no abren nada
         * acá. Y al revés —un dueño que le escribe a su asistente— no habilita texto libre por el
         * número de leads, así que el camino de siempre tampoco mira esta tabla. */
        $buscadores = $proposito === WhatsappConfig::PROPOSITO_ASISTENTE
            ? ['find_asistente_inbound']
            : ['find_support_inbound', 'find_lead_inbound', 'find_implementation_inbound'];

        $candidatos = [];
        foreach ($buscadores as $buscador) {
            $encontrado = $this->{$buscador}($phone, $cutoff);
            if ($encontrado !== null) {
                $candidatos[] = $encontrado;
            }
        }

        if (empty($candidatos)) {
            return $closed;
        }

        $found = $candidatos[0];
        foreach ($candidatos as $candidato) {
            if ($candidato['at']->gt($found['at'])) {
                $found = $candidato;
            }
        }

        $last_inbound_at = $found['at'];

        return [
            'open'            => true,
            'last_inbound_at' => $last_inbound_at->toIso8601String(),
            'expires_at'      => $last_inbound_at->copy()->addHours(self::WINDOW_HOURS)->toIso8601String(),
            'origin'          => $found['origin'],
        ];
    }

    /**
     * Atajo booleano de window_state().
     *
     * @param string      $phone     Teléfono en cualquier formato.
     * @param string|null $proposito Ver {@see window_state()}. Null = comportamiento de siempre.
     *
     * @return bool
     */
    public function is_open(string $phone, ?string $proposito = null): bool
    {
        $state = $this->window_state($phone, $proposito);

        return (bool) $state['open'];
    }

    /**
     * Último entrante de soporte de ese número dentro de la ventana.
     *
     * @param string                    $phone  Teléfono buscado.
     * @param \Illuminate\Support\Carbon $cutoff Momento a partir del cual cuenta.
     *
     * @return array{at: \Illuminate\Support\Carbon, origin: string}|null
     */
    private function find_support_inbound(string $phone, $cutoff)
    {
        $rows = $this->rows_for('soporte', function () use ($cutoff) {
            return DB::table('support_messages')
                ->join('support_tickets', 'support_tickets.id', '=', 'support_messages.support_ticket_id')
                ->where('support_messages.sender_type', 'user')
                ->whereNotNull('support_messages.delivered_at')
                ->where('support_messages.delivered_at', '>=', $cutoff)
                ->whereNotNull('support_tickets.whatsapp_phone')
                ->select('support_tickets.whatsapp_phone as phone', 'support_messages.delivered_at as at')
                ->orderByDesc('support_messages.delivered_at')
                ->get();
        });

        return $this->first_matching_row($rows, $phone, 'soporte');
    }

    /**
     * Último entrante del pipeline de leads de ese número dentro de la ventana.
     *
     * @param string                    $phone  Teléfono buscado.
     * @param \Illuminate\Support\Carbon $cutoff Momento a partir del cual cuenta.
     *
     * @return array{at: \Illuminate\Support\Carbon, origin: string}|null
     */
    private function find_lead_inbound(string $phone, $cutoff)
    {
        $rows = $this->rows_for('leads', function () use ($cutoff) {
            return DB::table('lead_messages')
                ->join('leads', 'leads.id', '=', 'lead_messages.lead_id')
                ->where('lead_messages.sender', 'lead')
                ->where('lead_messages.created_at', '>=', $cutoff)
                ->whereNotNull('leads.phone')
                ->select('leads.phone as phone', 'lead_messages.created_at as at')
                ->orderByDesc('lead_messages.created_at')
                ->get();
        });

        return $this->first_matching_row($rows, $phone, 'leads');
    }

    /**
     * Último entrante de implementación de ese número dentro de la ventana.
     *
     * No se mira ecommerce_implementation_messages porque esa tabla no guarda el teléfono:
     * habría que resolverlo por cliente y el error costaría solo mandar una plantilla de más.
     *
     * @param string                    $phone  Teléfono buscado.
     * @param \Illuminate\Support\Carbon $cutoff Momento a partir del cual cuenta.
     *
     * @return array{at: \Illuminate\Support\Carbon, origin: string}|null
     */
    private function find_implementation_inbound(string $phone, $cutoff)
    {
        $rows = $this->rows_for('implementacion', function () use ($cutoff) {
            return DB::table('implementation_messages')
                ->where('direction', 'inbound')
                ->where('created_at', '>=', $cutoff)
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->select('phone', 'created_at as at')
                ->orderByDesc('created_at')
                ->get();
        });

        return $this->first_matching_row($rows, $phone, 'implementacion');
    }

    /**
     * Último entrante del canal del asistente de ese número dentro de la ventana.
     *
     * Cuenta cualquier fila entrante del hilo, sin mirar el `estado`: una foto que todavía está
     * `esperando` su instrucción es un mensaje que el dueño ya mandó, y para Meta la ventana la
     * abre el mensaje, no lo que nosotros hayamos hecho después con él.
     *
     * 🔴 **Se filtra por el `phone_number_id` del número del asistente, y las filas con la columna en
     * null o con el número del principal NO cuentan.** Un dueño que le escribió al asistente por el
     * número de leads y clientes —que es lo único que pudo hacer hasta que el número nuevo existió—
     * no abrió ninguna ventana en el número nuevo: Meta la abre por par de números. Contarlas daría la
     * ventana por abierta el día del corte, el informe o el aviso saldrían como texto libre y Meta los
     * rechazaría. Una versión anterior de este comentario justificaba NO filtrar con las filas viejas
     * en null: era al revés, esas filas son justamente las que no pueden contar.
     *
     * Sin fila del asistente no hay ventana que medir: devuelve null.
     *
     * @param string                    $phone  Teléfono buscado.
     * @param \Illuminate\Support\Carbon $cutoff Momento a partir del cual cuenta.
     *
     * @return array{at: \Illuminate\Support\Carbon, origin: string}|null
     */
    private function find_asistente_inbound(string $phone, $cutoff)
    {
        $numero_del_asistente = WhatsappConfig::porProposito(WhatsappConfig::PROPOSITO_ASISTENTE);
        $phone_number_id      = $numero_del_asistente !== null ? trim((string) $numero_del_asistente->phone_number_id) : '';

        if ($phone_number_id === '') {
            return null;
        }

        $rows = $this->rows_for('asistente', function () use ($cutoff, $phone_number_id) {
            return DB::table('client_assistant_messages')
                ->where('direccion', ClientAssistantMessage::DIRECCION_ENTRANTE)
                ->where('phone_number_id', $phone_number_id)
                ->where('created_at', '>=', $cutoff)
                ->whereNotNull('telefono')
                ->where('telefono', '!=', '')
                ->select('telefono as phone', 'created_at as at')
                ->orderByDesc('created_at')
                ->get();
        });

        return $this->first_matching_row($rows, $phone, 'asistente');
    }

    /**
     * Devuelve las filas de un canal, consultándolas una sola vez por request.
     *
     * @param string   $key      Canal.
     * @param callable $resolver Consulta que devuelve las filas.
     *
     * @return \Illuminate\Support\Collection
     */
    private function rows_for(string $key, callable $resolver)
    {
        if (! array_key_exists($key, $this->rows_cache)) {
            $this->rows_cache[$key] = $resolver();
        }

        return $this->rows_cache[$key];
    }

    /**
     * Primera fila cuyo teléfono coincide con el buscado.
     *
     * La comparación va en PHP y no en SQL porque los números están guardados en formatos
     * distintos según por dónde entraron. Acá se exige igualdad EXACTA del E.164 y no el
     * phones_match() del webhook, que cae a comparar los últimos ocho dígitos: contra los
     * mensajes de todos los leads del sistema, ese criterio da falsos "ventana abierta", y un
     * texto libre fuera de ventana lo rechaza Meta. Errar para el lado de "cerrada" solo
     * cuesta mandar una plantilla de más.
     *
     * @param \Illuminate\Support\Collection $rows   Filas con phone y at.
     * @param string                         $phone  Teléfono buscado.
     * @param string                         $origin Etiqueta del canal, para diagnóstico.
     *
     * @return array{at: \Illuminate\Support\Carbon, origin: string}|null
     */
    private function first_matching_row($rows, string $phone, string $origin)
    {
        $normalized = WhatsappNormalizer::normalize($phone);
        if ($normalized === '') {
            return null;
        }

        foreach ($rows as $row) {
            $row_phone = (string) ($row->phone ?? '');
            if ($row_phone === '' || WhatsappNormalizer::normalize($row_phone) !== $normalized) {
                continue;
            }

            return [
                'at'     => \Illuminate\Support\Carbon::parse($row->at),
                'origin' => $origin,
            ];
        }

        return null;
    }
}
