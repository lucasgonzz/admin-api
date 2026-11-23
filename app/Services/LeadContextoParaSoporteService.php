<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadLlamada;
use App\Models\LeadMessage;

/**
 * Lo que Soporte necesita saber del lead del que salió un cliente (misión embudo-juan-pipeline, E1 y E1b).
 *
 * Sin esto, Soporte le vuelve a preguntar al dueño lo que ya habló con Martín y con Juan. El bloque viaja en el contexto del
 * ticket (`SupportCodexService::context`, clave `lead_del_cliente`): el lead (el promovido más nuevo), sus últimas 5 llamadas
 * (con la transcripción del audio de Juan, a 4.000 caracteres o menos), los últimos 20 mensajes de la charla con Martín
 * (sin eventos de estado, cada texto a 500 caracteres o menos) y el resumen de la demo.
 */
class LeadContextoParaSoporteService
{
    /** Cuántas llamadas trae. */
    const LLAMADAS = 5;

    /** Cuántos mensajes de la charla con Martín trae. */
    const MENSAJES = 20;

    /** Caracteres de cada mensaje de la charla. */
    const CARACTERES_POR_MENSAJE = 500;

    /** Caracteres de la transcripción de cada llamada. */
    const CARACTERES_DE_LA_TRANSCRIPCION = 4000;

    /**
     * El bloque del lead del cliente.
     *
     * @param Client|null $client
     *
     * @return array<string, mixed>|null Null si el cliente no salió de un lead.
     */
    public function bloque($client)
    {
        if ($client === null) {
            return null;
        }

        $lead = Lead::query()->where('promoted_client_id', (int) $client->id)->orderByDesc('id')->first();
        if ($lead === null) {
            return null;
        }

        return [
            'lead_id'            => (int) $lead->id,
            'nombre'             => $lead->contact_name,
            'empresa'            => $lead->company_name,
            'rubro'              => $lead->business_type,
            'cantidad_empleados' => $lead->cantidad_empleados === null ? null : (int) $lead->cantidad_empleados,
            'notas'              => $lead->notes,
            'llamadas'           => $this->llamadas($lead),
            'charla_con_martin'  => $this->charla($lead),
            'resumen_demo'       => $lead->demo_summary !== null && trim((string) $lead->demo_summary) !== '' ? (string) $lead->demo_summary : null,
        ];
    }

    /**
     * Las últimas llamadas del lead, de la más nueva a la más vieja.
     *
     * @param Lead $lead
     *
     * @return array<int, array<string, mixed>>
     */
    private function llamadas(Lead $lead): array
    {
        $llamadas = LeadLlamada::query()->with('admin')->where('lead_id', (int) $lead->id)->orderByDesc('id')->limit(self::LLAMADAS)->get();

        $salida = [];
        foreach ($llamadas as $llamada) {
            $salida[] = [
                'fecha'         => $llamada->created_at ? $llamada->created_at->format('Y-m-d H:i:s') : null,
                'admin'         => $llamada->admin ? (string) $llamada->admin->name : null,
                'atendio'       => (bool) $llamada->atendio,
                'resultado'     => $llamada->resultado,
                'cotizado'      => $llamada->cotizado,
                'notas'         => $llamada->notas,
                'origen'        => (string) $llamada->origen,
                'transcripcion' => $llamada->transcripcion === null ? null : mb_substr((string) $llamada->transcripcion, 0, self::CARACTERES_DE_LA_TRANSCRIPCION),
            ];
        }

        return $salida;
    }

    /**
     * Los últimos mensajes de la charla del lead con Martín, en orden cronológico, sin eventos de estado.
     *
     * @param Lead $lead
     *
     * @return array<int, array{fecha: string|null, de: string, texto: string}>
     */
    private function charla(Lead $lead): array
    {
        $mensajes = LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('is_status_event', false)
            ->whereIn('sender', ['lead', 'sistema'])
            ->where('status', 'enviado')
            ->whereNotNull('content')
            ->orderByDesc('id')
            ->limit(self::MENSAJES)
            ->get();

        $salida = [];
        foreach ($mensajes->reverse() as $mensaje) {
            $salida[] = [
                'fecha' => $mensaje->created_at ? $mensaje->created_at->format('Y-m-d H:i:s') : null,
                'de'    => (string) $mensaje->sender === 'lead' ? 'lead' : 'martin',
                'texto' => mb_substr((string) $mensaje->content, 0, self::CARACTERES_POR_MENSAJE),
            ];
        }

        return $salida;
    }
}
