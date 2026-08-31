<?php

namespace App\Services;

use App\Models\ClientSupportContext;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Arma el bloque de contexto por cliente que el agente de soporte recibe en cada consulta.
 *
 * Hasta ahora el agente atendía a los cuarenta clientes sin saber nada de ninguno: el prompt
 * llevaba el nombre, el mail y el historial del ticket, y nada más. Este servicio produce el
 * bloque que va entre esas dos cosas.
 *
 * El bloque tiene dos mitades y son de naturaleza distinta a propósito:
 *
 *  1. LA FICHA, que la escribe una persona (Claude cargándola por POST claude/client-context).
 *     Prosa libre: cómo se comunica el cliente, qué módulos usa, qué conviene evitar.
 *  2. LOS DATOS CALCULADOS, que no los escribe nadie: se leen de la base ACÁ, al armar el prompt.
 *
 * 🔴 POR QUÉ ESA DIVISIÓN ES LA REGLA CENTRAL DE ESTE ARCHIVO. Todo lo que se puede calcular se
 * calcula. Un "tickets abiertos: 3" guardado en la ficha es correcto el día que se escribe y
 * mentira una semana después, y el agente lo va a leer con la misma confianza que la prosa. La
 * ficha es para lo que NO se puede sacar de la base; para todo lo demás está datos_calculados().
 *
 * 🔴 Y LA OTRA REGLA: DE ACÁ NO PUEDE SALIR `notas_internas`. Ese campo existe para el operador
 * humano y no para el agente. La garantía no es que este archivo se acuerde de no leerlo: es que
 * el único acceso a la tabla es ClientSupportContext::ficha_operativa_de_cliente(), que hace un
 * SELECT de una sola columna y por lo tanto no tiene la nota ni en memoria.
 */
class SupportClientContextService
{
    /**
     * Encabezado del bloque. La aclaración del manual NO es adorno.
     *
     * 🔴 Sin ella el agente usa la ficha para afirmar cosas del sistema ("según tu historial, el
     * sistema hace X") y con eso esquiva el gate de citas: `tipo_respuesta: afirmacion_del_sistema`
     * exige `fuentes_kb` con rutas leídas con get_manual_file en esa misma consulta, y una ficha
     * escrita por una persona no es ninguna de esas rutas. La aclaración va acá, arriba de todo, y
     * se refuerza con una regla explícita en la sección de fuentes_kb de build_user_content().
     *
     * @var string
     */
    const ENCABEZADO = 'Contexto de este cliente (no es fuente sobre cómo funciona el sistema — para eso está el manual):';

    /**
     * Construye el bloque completo para el prompt de un ticket.
     *
     * 🔴 NUNCA LANZA. Este bloque enriquece el prompt: si una de las consultas falla, el agente
     * tiene que poder contestarle igual al cliente. `generate()` tiene un catch que ante cualquier
     * Throwable devuelve una sugerencia vacía, así que dejar propagar una excepción de acá dejaría
     * al cliente sin respuesta por no haber podido contar sus tickets.
     *
     * ⚠️ Y el fallo NO es silencioso: cuando algo no se pudo calcular, el bloque lo DICE. Un
     * prompt al que le falta el bloque sin avisar es peor que uno que avisa, porque el agente
     * asume que el cliente no tiene historia en vez de saber que no se pudo leer.
     *
     * @param SupportTicket $ticket Ticket para el que se arma el prompt.
     *
     * @return string Bloque listo para intercalar, sin salto de línea al final.
     */
    public function bloque_para_el_prompt(SupportTicket $ticket)
    {
        $client_id = (int) $ticket->client_id;

        $partes = [self::ENCABEZADO, ''];

        $partes[] = 'Ficha operativa:';
        $partes[] = $this->texto_de_la_ficha($client_id);
        $partes[] = '';

        $partes[] = 'Datos de este cliente, leídos de la base recién ahora (no están escritos a mano en la ficha, así que no pueden estar viejos):';

        foreach ($this->lineas_calculadas($client_id, (int) $ticket->id) as $linea) {
            $partes[] = '- ' . $linea;
        }

        return implode("\n", $partes);
    }

    /**
     * Texto de la ficha operativa, o el aviso de que no hay ninguna cargada.
     *
     * Se devuelve un aviso explícito en vez de una cadena vacía para que el agente distinga
     * "de este cliente no sabemos nada" de "el bloque no vino".
     *
     * @param int $client_id Id del cliente.
     *
     * @return string
     */
    protected function texto_de_la_ficha($client_id)
    {
        try {
            $ficha = ClientSupportContext::ficha_operativa_de_cliente($client_id);
        } catch (\Throwable $e) {
            Log::warning('SupportClientContextService: no se pudo leer la ficha operativa.', [
                'client_id' => $client_id,
                'error'     => $e->getMessage(),
            ]);

            return '(No se pudo leer la ficha de este cliente. Trabajá sin ella.)';
        }

        if ($ficha === null) {
            return '(Este cliente todavía no tiene ficha cargada.)';
        }

        return $ficha;
    }

    /**
     * Las líneas del bloque calculado, ya redactadas.
     *
     * @param int $client_id Id del cliente.
     * @param int $ticket_id Id del ticket en curso, para poder aclarar que va incluido en el conteo.
     *
     * @return array<int, string>
     */
    protected function lineas_calculadas($client_id, $ticket_id)
    {
        try {
            $datos = $this->datos_calculados($client_id);
        } catch (\Throwable $e) {
            Log::warning('SupportClientContextService: no se pudieron calcular los datos del cliente.', [
                'client_id' => $client_id,
                'ticket_id' => $ticket_id,
                'error'     => $e->getMessage(),
            ]);

            return ['No se pudieron calcular los datos de este cliente en esta consulta.'];
        }

        $lineas = [];

        if ($datos['antiguedad'] !== null) {
            $lineas[] = 'Es cliente desde ' . $datos['alta'] . ' (' . $datos['antiguedad'] . ').';
        } else {
            $lineas[] = 'No figura la fecha de alta de este cliente en el admin.';
        }

        if ($datos['version'] !== null) {
            $lineas[] = 'Versión del sistema que le corre hoy: ' . $datos['version'] . '.';
        } else {
            $lineas[] = 'No hay una versión asignada a este cliente en el admin.';
        }

        $lineas[] = 'Tickets de soporte: ' . $datos['tickets_abiertos'] . ' abiertos y '
            . $datos['tickets_totales'] . ' en total (este incluido).';

        $lineas[] = 'Mensajes intercambiados con este cliente en todos sus tickets: '
            . $datos['mensajes_totales'] . '.';

        $lineas[] = 'Veces que un ticket suyo se escaló a un humano: ' . $datos['veces_escalado'] . '.';

        return $lineas;
    }

    /**
     * Lee de la base todo lo que el bloque publica como calculado.
     *
     * 🔴 Consultas de agregación, no colecciones. Un cliente con seiscientos mensajes no tiene por
     * qué cargarse entero en memoria para que el prompt diga "600": esto corre en cada consulta
     * del agente, o sea varias veces por ticket.
     *
     * @param int $client_id Id del cliente.
     *
     * @return array<string, mixed>
     */
    public function datos_calculados($client_id)
    {
        $client_id = (int) $client_id;

        /* Un SELECT con join en vez de cargar el modelo Client: de la tabla sólo interesan dos
           columnas, y `clients` tiene api_key y credenciales que no hacen falta acá. */
        $cliente = DB::table('clients')
            ->leftJoin('versions', 'versions.id', '=', 'clients.current_version_id')
            ->where('clients.id', $client_id)
            ->select(['clients.created_at', 'versions.version'])
            ->first();

        $alta       = null;
        $antiguedad = null;
        $version    = null;

        if ($cliente !== null) {
            $version = $this->texto_o_null(isset($cliente->version) ? $cliente->version : null);

            $creado = isset($cliente->created_at) ? $cliente->created_at : null;
            if ($creado !== null && $creado !== '') {
                /* 🔴 `catch (\Throwable)` y no `catch (\Exception)`: Carbon::parse() tira TypeError
                   —que es \Error, no \Exception— cuando lo que llega no es un string. Es la misma
                   clase de error ya documentada en RespuestasParaClaude::parsear_o_null(). */
                try {
                    $fecha      = \Carbon\Carbon::parse($creado, config('app.timezone'));
                    $alta       = $fecha->format('d/m/Y');
                    $antiguedad = $this->antiguedad_en_palabras($fecha);
                } catch (\Throwable $e) {
                    $alta       = null;
                    $antiguedad = null;
                }
            }
        }

        $tickets = DB::table('support_tickets')
            ->where('client_id', $client_id)
            ->selectRaw('COUNT(*) as totales')
            ->selectRaw("SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as abiertos")
            ->selectRaw('SUM(CASE WHEN escalated_at IS NOT NULL THEN 1 ELSE 0 END) as escalados')
            ->first();

        $mensajes = DB::table('support_messages')
            ->join('support_tickets', 'support_tickets.id', '=', 'support_messages.support_ticket_id')
            ->where('support_tickets.client_id', $client_id)
            ->count();

        return [
            'alta'             => $alta,
            'antiguedad'       => $antiguedad,
            'version'          => $version,
            'tickets_totales'  => $tickets !== null ? (int) $tickets->totales : 0,
            'tickets_abiertos' => $tickets !== null ? (int) $tickets->abiertos : 0,
            'veces_escalado'   => $tickets !== null ? (int) $tickets->escalados : 0,
            'mensajes_totales' => (int) $mensajes,
        ];
    }

    /**
     * Antigüedad del cliente en castellano.
     *
     * 🔴 Se redacta a mano y no con `diffForHumans()` a propósito: ese método sigue el locale de
     * la app, y este repo sólo tiene traducciones en inglés (resources/lang/en). El prompt del
     * agente está entero en castellano; una línea que dijera "1 year and 5 months" en el medio es
     * exactamente el tipo de detalle que nadie mira hasta que el agente le contesta al cliente en
     * inglés.
     *
     * @param \Carbon\Carbon $desde Fecha de alta del cliente.
     *
     * @return string
     */
    protected function antiguedad_en_palabras($desde)
    {
        $meses = $desde->diffInMonths(now());

        if ($meses < 1) {
            $dias = $desde->diffInDays(now());

            return $dias === 1 ? 'hace 1 día' : 'hace ' . $dias . ' días';
        }

        $anios = intdiv($meses, 12);
        $resto = $meses % 12;

        $partes = [];
        if ($anios > 0) {
            $partes[] = $anios === 1 ? '1 año' : $anios . ' años';
        }
        if ($resto > 0) {
            $partes[] = $resto === 1 ? '1 mes' : $resto . ' meses';
        }

        return 'hace ' . implode(' y ', $partes);
    }

    /**
     * Texto recortado, o null si quedó vacío.
     *
     * @param mixed $valor Valor crudo.
     *
     * @return string|null
     */
    protected function texto_o_null($valor)
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
