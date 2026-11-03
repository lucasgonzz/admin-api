<?php

namespace App\Services\Local;

use App\Helpers\AppTime;
use App\Models\LocalActividadCliente;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * La actividad que el sistema de cada cliente le avisa al admin (misión el-local, 3/11/2026, contrato C:
 * `POST api/inbound/local/actividad`), y su poda.
 *
 * En la Fase 1 nadie la manda todavía: el emisor es el `empresa-api` en la Fase 2 (al iniciar sesión y cada
 * 5 minutos de uso, al terminar un escaneo de factura y al responder el agente del negocio). Queda
 * construida y probada ahora para que esa misión toque solo empresa.
 *
 * 🔴 El cliente sale SIEMPRE de la clave `X-Admin-Api-Key` (middleware `admin.inbound.strict`), nunca del
 * body: un sistema no puede anotar actividad a nombre de otro.
 *
 * 🔴 No duplica: el mismo evento (cliente, tipo, ocurrido_at y usuario) que vuelve a llegar —un reintento del
 * emisor que perdió la respuesta— no crea otra fila. Lo garantiza el índice único `(client_id, huella)` con
 * `insertOrIgnore`, no una lectura previa: dos POST simultáneos con el mismo evento no pueden colarse los dos.
 *
 * Las claves desconocidas de cada evento se ignoran (no dan 422): un emisor más nuevo que el admin no se
 * queda afuera.
 */
class ActividadClientesService
{
    /** Tope de eventos por POST (contrato C). */
    const TOPE_EVENTOS = 100;

    /** Tope del `detalle` de un evento, en bytes de JSON (contrato C: ≤ 2 KB). */
    const TOPE_DETALLE_BYTES = 2048;

    /** Días que se conserva la actividad (los poda `local:podar`). */
    const RETENCION_DIAS = 7;

    /** Filas que borra cada tanda de la poda. */
    const TANDA_DE_PODA = 1000;

    /**
     * Mensajes de validación en español (el proyecto solo trae traducciones en inglés).
     *
     * @var array<string, string>
     */
    private $mensajes = [
        'required' => 'El campo :attribute es obligatorio.',
        'array'    => 'El campo :attribute tiene que ser una lista u objeto.',
        'string'   => 'El campo :attribute tiene que ser texto.',
        'date'     => 'El campo :attribute tiene que ser una fecha válida (ISO-8601 con zona).',
        'in'       => 'El campo :attribute tiene un valor que no está permitido.',
        'min'      => 'El campo :attribute está por debajo del mínimo permitido.',
        'max'      => 'El campo :attribute supera el máximo permitido.',
    ];

    /**
     * Reglas del contrato C.
     *
     * @return array<string, string>
     */
    public function reglas()
    {
        return [
            'eventos'               => 'required|array|min:1|max:' . self::TOPE_EVENTOS,
            'eventos.*'             => 'array',
            'eventos.*.tipo'        => 'required|string|in:' . implode(',', LocalActividadCliente::TIPOS),
            'eventos.*.ocurrido_at' => 'required|date',
            'eventos.*.usuario'     => 'nullable|string|max:120',
            'eventos.*.detalle'     => 'nullable|array',
        ];
    }

    /**
     * Valida el payload, incluido el tope de 2 KB del `detalle` de cada evento.
     *
     * @param array<string, mixed> $datos Body del POST.
     *
     * @return array<string, array<int, string>>|null Errores por campo, o null si valida.
     */
    public function validar(array $datos)
    {
        $validator = Validator::make($datos, $this->reglas(), $this->mensajes);

        $validator->after(function ($validator) use ($datos) {
            $eventos = isset($datos['eventos']) && is_array($datos['eventos']) ? $datos['eventos'] : [];
            foreach ($eventos as $i => $evento) {
                if (! is_array($evento) || ! isset($evento['detalle']) || ! is_array($evento['detalle'])) {
                    continue;
                }
                if (strlen((string) json_encode($evento['detalle'])) > self::TOPE_DETALLE_BYTES) {
                    $validator->errors()->add(
                        'eventos.' . $i . '.detalle',
                        'El detalle del evento supera los ' . self::TOPE_DETALLE_BYTES . ' bytes.'
                    );
                }
            }
        });

        if ($validator->fails()) {
            return $validator->errors()->toArray();
        }

        return null;
    }

    /**
     * Guarda los eventos validados de un cliente.
     *
     * @param int                              $client_id Cliente resuelto por la clave.
     * @param array<int, array<string, mixed>> $eventos   Eventos validados.
     *
     * @return int Cantidad de eventos del lote que quedaron guardados. 🔴 Cuenta también los que ya estaban
     *             (un reintento): "guardado" significa "el admin lo tiene", que es lo que el emisor necesita
     *             saber para vaciar su cola sin reintentar para siempre. Los repetidos no crean filas nuevas.
     */
    public function guardar($client_id, array $eventos)
    {
        $tz     = config('app.timezone');
        $creado = AppTime::now()->setTimezone($tz)->format('Y-m-d H:i:s');
        $filas  = [];

        foreach ($eventos as $evento) {
            $tipo     = (string) $evento['tipo'];
            $ocurrido = Carbon::parse((string) $evento['ocurrido_at'], $tz)->setTimezone($tz);
            $usuario  = $this->usuario_o_null(isset($evento['usuario']) ? $evento['usuario'] : null);
            $detalle  = isset($evento['detalle']) && is_array($evento['detalle']) && count($evento['detalle']) > 0
                ? json_encode($evento['detalle'])
                : null;

            /* La huella se arma con la hora en UTC: el mismo instante mandado como -03:00 o como Z tiene
               que dar la misma huella. */
            $huella = sha1($tipo . '|' . $ocurrido->copy()->utc()->format('Y-m-d H:i:s') . '|' . ($usuario === null ? '' : $usuario));

            // Repetidos dentro del mismo lote: queda uno.
            $filas[$huella] = [
                'client_id'   => (int) $client_id,
                'tipo'        => $tipo,
                'usuario'     => $usuario,
                'detalle'     => $detalle,
                'ocurrido_at' => $ocurrido->format('Y-m-d H:i:s'),
                'huella'      => $huella,
                'created_at'  => $creado,
            ];
        }

        if (count($filas) > 0) {
            DB::table('local_actividad_clientes')->insertOrIgnore(array_values($filas));
        }

        return count($eventos);
    }

    /**
     * Borra la actividad más vieja que la retención, en tandas para no bloquear la tabla.
     *
     * Mira `ocurrido_at` (indexado) y también `created_at`: un evento que llegó con la hora adelantada por un
     * reloj mal puesto se borra igual cuando su fila cumple la retención.
     *
     * @param int $dias Días que se conservan.
     *
     * @return int Filas borradas.
     */
    public function podar($dias = self::RETENCION_DIAS)
    {
        $dias  = max(1, (int) $dias);
        $corte = AppTime::now()->setTimezone(config('app.timezone'))->subDays($dias)->format('Y-m-d H:i:s');
        $total = 0;

        do {
            $borradas = DB::table('local_actividad_clientes')
                ->where(function ($query) use ($corte) {
                    $query->where('ocurrido_at', '<', $corte)
                        ->orWhere('created_at', '<', $corte);
                })
                ->limit(self::TANDA_DE_PODA)
                ->delete();

            $total += $borradas;
        } while ($borradas >= self::TANDA_DE_PODA);

        return $total;
    }

    /**
     * Usuario recortado, o null si vino vacío.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    private function usuario_o_null($valor)
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_substr($valor, 0, 120);
    }
}
