<?php

namespace App\Http\Controllers\Api;

use App\Helpers\WhatsappNormalizer;
use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientContactChange;
use App\Models\ClientEmployee;
use App\Models\WhatsappConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Contacto de un cliente para Claude (misión telefono-personal-del-dueno, 2/11/2026): ver y cambiar los
 * teléfonos del cliente y el interruptor «Habla con su asistente por WhatsApp» sin pasar por el panel.
 *
 *   GET   claude/clients/{id}/contacto → los dos teléfonos, el efectivo del dueño, el interruptor y los últimos
 *                                         cambios.
 *   PATCH claude/clients/{id}/contacto → cambia `phone`, `asistente_phone` y/o `asistente_whatsapp_activo`, con
 *                                         `dry_run`, validación y rastro.
 *
 * 🔴 DOS TELÉFONOS, DOS ROLES. `phone` es el WhatsApp del NEGOCIO (el que se conecta a Kapso para atender a los
 * clientes del cliente); `asistente_phone` es el teléfono PERSONAL del dueño (desde el que le escribe a su agente
 * personal y al que el admin le escribe como persona). Sin `asistente_phone` el dueño usa `phone`, como siempre
 * (`Client::telefono_personal()`).
 *
 * 🔴 Cambiar un teléfono REDIRIGE envíos y el reconocimiento del dueño ante el agente personal. Por eso:
 *   - `dry_run=true` devuelve el antes y el después sin escribir nada;
 *   - el formato tiene que ser internacional (`+` y entre 10 y 15 dígitos);
 *   - un número que ya es de otro cliente activo o de un empleado se rechaza (422): el agente personal no sabría a
 *     quién darle el acceso, y por seguridad no se lo daría a nadie;
 *   - cada campo cambiado deja una fila en `client_contact_changes`.
 *
 * Protegido por el middleware `claude.task.key` como el resto del bloque `claude/*`.
 */
class ClaudeClientContactoController extends Controller
{
    use RespuestasParaClaude;

    /** Cuántos cambios recientes viajan en el GET. */
    const CAMBIOS_RECIENTES = 10;

    /** Campos que este endpoint puede cambiar. */
    const CAMPOS = ['phone', 'asistente_phone', 'asistente_whatsapp_activo'];

    /**
     * Contacto actual del cliente.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function contacto_json(Request $request, $id)
    {
        $client = $this->resolver_cliente($id);
        if ($client === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        return response()->json(array_merge(
            ['client' => $this->cliente_resumido($client)],
            $this->estado_del_contacto($client),
            ['cambios_recientes' => $this->cambios_recientes($client)]
        ), 200);
    }

    /**
     * Cambia el contacto del cliente. Todos los campos son opcionales, pero tiene que venir al menos uno.
     *
     * Cuerpo: `phone` (string), `asistente_phone` (string, o null / "" para borrarlo), `asistente_whatsapp_activo`
     * (booleano), `motivo` (string, queda en el rastro) y `dry_run` (booleano, default false).
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid del cliente.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function actualizar_contacto_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'phone'                     => 'nullable|string|max:60',
            'asistente_phone'           => 'nullable|string|max:60',
            'asistente_whatsapp_activo' => 'nullable|boolean',
            'motivo'                    => 'nullable|string|max:191',
            'dry_run'                   => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $client = $this->resolver_cliente($id);
        if ($client === null) {
            return $this->error_404('no existe el cliente ' . $id);
        }

        /* Qué campos vinieron. `asistente_phone` se mira por la CLAVE y no por el valor: un null o un "" es
           «borralo», y es muy distinto de no haberlo mandado. */
        $entrada = $request->all();
        $manda_phone    = array_key_exists('phone', $entrada);
        $manda_personal = array_key_exists('asistente_phone', $entrada);
        $manda_flag     = array_key_exists('asistente_whatsapp_activo', $entrada) && $entrada['asistente_whatsapp_activo'] !== null;

        if (! $manda_phone && ! $manda_personal && ! $manda_flag) {
            return $this->error_422(
                'No mandaste ningún campo para cambiar. No se escribió nada.',
                ['ayuda' => 'Mandá al menos uno de: ' . implode(', ', self::CAMPOS) . '.']
            );
        }

        $nuevo = [];

        if ($manda_phone) {
            $crudo = trim((string) $entrada['phone']);
            if ($crudo === '') {
                return $this->error_422(
                    'El teléfono del negocio (`phone`) no se puede dejar vacío. No se escribió nada.',
                    ['campo' => 'phone', 'ayuda' => 'Mandá un número internacional, por ejemplo +5493454622139.']
                );
            }

            $normalizado = $this->normalizar_internacional($crudo);
            if ($normalizado === null) {
                return $this->error_422_de_formato('phone', $crudo);
            }
            $nuevo['phone'] = $normalizado;
        }

        if ($manda_personal) {
            $crudo = trim((string) $entrada['asistente_phone']);
            if ($crudo === '') {
                $nuevo['asistente_phone'] = null;
            } else {
                $normalizado = $this->normalizar_internacional($crudo);
                if ($normalizado === null) {
                    return $this->error_422_de_formato('asistente_phone', $crudo);
                }
                $nuevo['asistente_phone'] = $normalizado;
            }
        }

        if ($manda_flag) {
            $nuevo['asistente_whatsapp_activo'] = $request->boolean('asistente_whatsapp_activo');
        }

        /* Lo que hay hoy, con los mismos tipos con los que se va a comparar. */
        $antes = [
            'phone'                     => $this->texto_o_null_de_la_ficha($client->phone),
            'asistente_phone'           => $this->texto_o_null_de_la_ficha($client->asistente_phone),
            'asistente_whatsapp_activo' => (bool) $client->asistente_whatsapp_activo,
        ];

        $cambios = [];
        foreach ($nuevo as $campo => $valor) {
            if ($this->son_distintos($campo, $antes[$campo], $valor)) {
                $cambios[$campo] = ['antes' => $antes[$campo], 'despues' => $valor];
            }
        }

        /* Los números que cambian no pueden ser de otro cliente activo ni de un empleado. Solo se miran los que
           CAMBIAN: una ficha que ya tenía un duplicado heredado no se bloquea por reenviar su mismo número. */
        foreach (['phone', 'asistente_phone'] as $campo) {
            if (! isset($cambios[$campo]) || $cambios[$campo]['despues'] === null) {
                continue;
            }

            $choque = $this->choque_del_numero($client, (string) $cambios[$campo]['despues']);
            if ($choque !== null) {
                return $this->error_422(
                    'El número ' . $cambios[$campo]['despues'] . ' ya figura ' . $choque['donde']
                    . ' y el agente personal no podría reconocer a su dueño. No se escribió nada.',
                    ['campo' => $campo, 'motivo' => $choque['motivo']]
                );
            }
        }

        /* Cómo queda la ficha si se aplica todo, para validar el interruptor contra el resultado y no contra lo
           que hay hoy. */
        $despues = array_merge($antes, array_map(function ($cambio) {
            return $cambio['despues'];
        }, $cambios));

        if ($despues['asistente_whatsapp_activo'] === true
            && $despues['phone'] === null
            && $despues['asistente_phone'] === null) {
            return $this->error_422(
                'No se puede prender «Habla con su asistente por WhatsApp» en un cliente sin ningún teléfono: no habría a quién reconocer. No se escribió nada.',
                ['campo' => 'asistente_whatsapp_activo', 'ayuda' => 'Cargá `phone` o `asistente_phone` en el mismo pedido.']
            );
        }

        $advertencias = $this->advertencias($client, $antes, $despues, $cambios);

        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : false;

        /* `(object)` para que un cambio vacío viaje como `{}` y no como `[]`. */
        $cambios_json = count($cambios) === 0 ? (object) [] : $cambios;

        if ($dry_run) {
            return response()->json([
                'aplicado'     => false,
                'dry_run'      => true,
                'client'       => $this->cliente_resumido($client),
                'cambios'      => $cambios_json,
                'advertencias' => $advertencias,
                'nota'         => 'No se escribió NADA. Repetí la misma llamada sin dry_run (o con dry_run=false) para aplicarla.',
            ], 200);
        }

        if (count($cambios) === 0) {
            return response()->json([
                'aplicado'     => false,
                'dry_run'      => false,
                'client'       => $this->cliente_resumido($client),
                'cambios'      => $cambios_json,
                'advertencias' => $advertencias,
                'nota'         => 'Los valores que mandaste ya son los que tiene el cliente: no había nada que cambiar.',
            ], 200);
        }

        $motivo = $this->texto_o_null($request->input('motivo'));

        /* Todo o nada: la ficha y su rastro se escriben en la misma transacción. */
        DB::transaction(function () use ($client, $cambios, $motivo) {
            foreach ($cambios as $campo => $cambio) {
                $client->{$campo} = $cambio['despues'];
            }
            $client->save();

            foreach ($cambios as $campo => $cambio) {
                ClientContactChange::create([
                    'client_id'      => (int) $client->id,
                    'campo'          => $campo,
                    'valor_anterior' => $this->como_texto_del_rastro($cambio['antes']),
                    'valor_nuevo'    => $this->como_texto_del_rastro($cambio['despues']),
                    'origen'         => ClientContactChange::ORIGEN_CLAUDE,
                    'motivo'         => $motivo,
                ]);
            }
        });

        $client = $this->resolver_cliente((int) $client->id);

        return response()->json([
            'aplicado'     => true,
            'dry_run'      => false,
            'client'       => $this->cliente_resumido($client),
            'cambios'      => $cambios_json,
            'advertencias' => $advertencias,
            'contacto'     => $this->estado_del_contacto($client),
        ], 200);
    }

    /**
     * Lo que dice la ficha hoy sobre el contacto, en la forma que comparten el GET y la respuesta del PATCH.
     *
     * @param Client $client Cliente.
     *
     * @return array<string, mixed>
     */
    private function estado_del_contacto(Client $client)
    {
        return [
            'phone'                        => $this->texto_o_null_de_la_ficha($client->phone),
            'asistente_phone'              => $this->texto_o_null_de_la_ficha($client->asistente_phone),
            'telefono_personal_efectivo'   => $this->texto_o_null_de_la_ficha($client->telefono_personal()),
            'asistente_whatsapp_activo'    => (bool) $client->asistente_whatsapp_activo,
            /* Solo la cantidad: los números de los empleados son PII y no hacen falta para operar. */
            'empleados_con_telefono'       => ClientEmployee::query()
                ->where('client_id', (int) $client->id)
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->count(),
        ];
    }

    /**
     * Los últimos cambios de contacto del cliente, del más nuevo al más viejo.
     *
     * @param Client $client Cliente.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cambios_recientes(Client $client)
    {
        return ClientContactChange::query()
            ->where('client_id', (int) $client->id)
            ->orderBy('id', 'desc')
            ->limit(self::CAMBIOS_RECIENTES)
            ->get()
            ->map(function (ClientContactChange $cambio) {
                return [
                    'campo'          => (string) $cambio->campo,
                    'valor_anterior' => $cambio->valor_anterior,
                    'valor_nuevo'    => $cambio->valor_nuevo,
                    'origen'         => (string) $cambio->origen,
                    'motivo'         => $cambio->motivo,
                    'created_at'     => $cambio->created_at === null ? null : $cambio->created_at->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * Las advertencias (no frenan nada) de un cambio de contacto.
     *
     * @param Client                           $client  Cliente.
     * @param array<string, mixed>             $antes   Valores de hoy.
     * @param array<string, mixed>             $despues Valores como quedarían.
     * @param array<string, array<string, mixed>> $cambios Los campos que de verdad cambian.
     *
     * @return array<int, string>
     */
    private function advertencias(Client $client, array $antes, array $despues, array $cambios)
    {
        $advertencias = [];

        if (isset($cambios['phone'])) {
            $advertencias[] = '`phone` es el WhatsApp del NEGOCIO: cambiarlo acá no reconecta el número en Kapso (eso va por la implementación de agentes).';

            if ($antes['asistente_phone'] === null && $despues['asistente_phone'] === null) {
                $advertencias[] = 'El cliente no tiene `asistente_phone`: el dueño usa `phone`, así que este cambio también cambia el teléfono con el que se reconoce al dueño ante su agente personal y al que se le escribe.';
            }
        }

        if (isset($cambios['asistente_phone']) && $despues['asistente_phone'] !== null && $despues['asistente_phone'] === $despues['phone']) {
            $advertencias[] = '`asistente_phone` es igual a `phone`: no hace falta cargarlo.';
        }

        if (isset($cambios['asistente_phone']) && $despues['asistente_phone'] !== null && $despues['asistente_whatsapp_activo'] !== true) {
            $advertencias[] = 'Quedó cargado el teléfono personal, pero «Habla con su asistente por WhatsApp» está apagado: el dueño no va a hablar con el agente personal hasta que se prenda.';
        }

        if ($despues['asistente_whatsapp_activo'] === true) {
            if (WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_ASISTENTE) === null) {
                $advertencias[] = 'El número del asistente no está listo (falta la fila, la clave o el secreto del webhook): mientras tanto el agente le contesta al dueño por el número principal.';
            }

            if (trim((string) $client->api_key) === '') {
                $advertencias[] = 'El cliente no tiene api_key cargada: sin ella el agente personal no puede consultar su sistema.';
            }

            if (empty($client->ai_plan_id)) {
                $advertencias[] = 'El cliente no tiene un paquete de IA asignado: puede que no tenga la extensión de Agentes de IA y el agente personal le conteste que su sistema todavía no tiene la función.';
            }
        }

        return $advertencias;
    }

    /**
     * Si el número ya figura en otro lado donde haría ambiguo al dueño.
     *
     * Se compara con `WhatsappNormalizer::equals_estricto()` (el comparador «de dueño» del agente personal) contra:
     * el `phone` y el `asistente_phone` de los OTROS clientes activos, y el teléfono de cualquier empleado.
     *
     * @param Client $client Cliente que se está cambiando.
     * @param string $numero Número nuevo ya normalizado.
     *
     * @return array{donde: string, motivo: string}|null Null si no choca con nadie.
     */
    private function choque_del_numero(Client $client, $numero)
    {
        $otros = Client::query()
            ->where('is_active', true)
            ->where('id', '!=', (int) $client->id)
            ->get(['id', 'phone', 'asistente_phone']);

        foreach ($otros as $otro) {
            foreach (['phone', 'asistente_phone'] as $campo) {
                if (trim((string) $otro->{$campo}) !== '' && WhatsappNormalizer::equals_estricto((string) $otro->{$campo}, $numero)) {
                    return [
                        'donde'  => 'como `' . $campo . '` del cliente activo ' . (int) $otro->id,
                        'motivo' => 'numero_de_otro_cliente',
                    ];
                }
            }
        }

        $empleados = ClientEmployee::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get(['id', 'client_id', 'phone']);

        foreach ($empleados as $empleado) {
            if (WhatsappNormalizer::equals_estricto((string) $empleado->phone, $numero)) {
                return [
                    'donde'  => 'como teléfono de un empleado (cliente ' . (int) $empleado->client_id . ')',
                    'motivo' => 'numero_de_un_empleado',
                ];
            }
        }

        return null;
    }

    /**
     * Normaliza un teléfono a E.164 internacional, o devuelve null si no tiene el formato.
     *
     * Exige que lo ESCRITO empiece con `+` y que tenga entre 10 y 15 dígitos. Un número argentino pasa por
     * `WhatsappNormalizer::normalize()` (agrega el 9 del móvil si falta); uno de otro país se guarda como
     * `+` y los dígitos, porque `normalize()` está escrito para teléfonos argentinos y un `+1 555 123 4567`
     * lo convertiría en `+54915551234567`.
     *
     * @param string $crudo Teléfono tal como lo mandaron.
     *
     * @return string|null
     */
    private function normalizar_internacional($crudo)
    {
        $crudo = trim((string) $crudo);

        if ($crudo === '' || $crudo[0] !== '+') {
            return null;
        }

        /* Solo dígitos y los separadores habituales: letras u otros símbolos no son un teléfono. */
        if (preg_match('/^\+[\d\s().-]+$/', $crudo) !== 1) {
            return null;
        }

        $digitos = (string) preg_replace('/\D+/', '', $crudo);
        if (strlen($digitos) < 10 || strlen($digitos) > 15) {
            return null;
        }

        $normalizado = strpos($digitos, '54') === 0
            ? WhatsappNormalizer::normalize($crudo)
            : '+' . $digitos;

        if (preg_match('/^\+\d{10,15}$/', $normalizado) !== 1) {
            return null;
        }

        return $normalizado;
    }

    /**
     * El 422 de un teléfono con formato inválido.
     *
     * @param string $campo Campo que falló.
     * @param string $crudo Valor mandado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function error_422_de_formato($campo, $crudo)
    {
        return $this->error_422(
            'El teléfono de `' . $campo . '` ("' . $crudo . '") no tiene formato internacional: tiene que empezar con + y tener entre 10 y 15 dígitos. No se escribió nada.',
            [
                'campo' => $campo,
                'ayuda' => 'Ejemplo: +5493454622139 (Argentina, celular) o +5491144622139.',
            ]
        );
    }

    /**
     * Si dos valores de un campo son realmente distintos.
     *
     * Los teléfonos se comparan normalizados: `+54 9 345 462-2139` guardado a mano y `+5493454622139` son el mismo
     * número y no son un cambio.
     *
     * @param string $campo Nombre del campo.
     * @param mixed  $antes Valor de hoy.
     * @param mixed  $nuevo Valor pedido.
     *
     * @return bool
     */
    private function son_distintos($campo, $antes, $nuevo)
    {
        if ($campo === 'asistente_whatsapp_activo') {
            return (bool) $antes !== (bool) $nuevo;
        }

        if ($antes === null || $nuevo === null) {
            return $antes !== $nuevo;
        }

        return WhatsappNormalizer::normalize((string) $antes) !== WhatsappNormalizer::normalize((string) $nuevo);
    }

    /**
     * Un valor del rastro como texto: los booleanos como 1/0 y los nulos como null.
     *
     * @param mixed $valor Valor.
     *
     * @return string|null
     */
    private function como_texto_del_rastro($valor)
    {
        if ($valor === null) {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        return (string) $valor;
    }

    /**
     * Un campo de la ficha como texto recortado, o null si está vacío.
     *
     * @param mixed $valor Valor de la ficha.
     *
     * @return string|null
     */
    private function texto_o_null_de_la_ficha($valor)
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * El cliente en su forma corta, para identificarlo en la respuesta.
     *
     * @param Client $client Cliente.
     *
     * @return array<string, mixed>
     */
    private function cliente_resumido(Client $client)
    {
        return [
            'id'   => (int) $client->id,
            'uuid' => (string) $client->uuid,
            'name' => (string) $client->name,
        ];
    }

    /**
     * Resuelve el cliente de la ruta (id numérico o uuid).
     *
     * @param int|string $route_id Segmento de la ruta.
     *
     * @return Client|null
     */
    private function resolver_cliente($route_id)
    {
        $query = Client::query();

        return is_numeric($route_id)
            ? $query->where('id', (int) $route_id)->first()
            : $query->where('uuid', (string) $route_id)->first();
    }
}
