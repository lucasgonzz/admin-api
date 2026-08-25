<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
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
     * @param string $phone Teléfono en cualquier formato.
     *
     * @return array{open: bool, last_inbound_at: string|null, expires_at: string|null, origin: string|null}
     */
    public function window_state(string $phone): array
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

        // Se consultan en orden de probabilidad y se corta apenas una da positivo: para
        // decidir el canal de envío alcanza con saber que la ventana está abierta.
        $found = $this->find_support_inbound($phone, $cutoff);
        if ($found === null) {
            $found = $this->find_lead_inbound($phone, $cutoff);
        }
        if ($found === null) {
            $found = $this->find_implementation_inbound($phone, $cutoff);
        }

        if ($found === null) {
            return $closed;
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
     * @param string $phone Teléfono en cualquier formato.
     *
     * @return bool
     */
    public function is_open(string $phone): bool
    {
        $state = $this->window_state($phone);

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
     * distintos según por dónde entraron; phones_match() es el mismo criterio con el que el
     * webhook enruta. El conjunto ya viene acotado a 24hs, así que recorrerlo es barato.
     *
     * @param \Illuminate\Support\Collection $rows   Filas con phone y at.
     * @param string                         $phone  Teléfono buscado.
     * @param string                         $origin Etiqueta del canal, para diagnóstico.
     *
     * @return array{at: \Illuminate\Support\Carbon, origin: string}|null
     */
    private function first_matching_row($rows, string $phone, string $origin)
    {
        foreach ($rows as $row) {
            $row_phone = (string) ($row->phone ?? '');
            if ($row_phone === '' || ! WhatsappNormalizer::phones_match($row_phone, $phone)) {
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
