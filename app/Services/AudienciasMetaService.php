<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Helpers\WhatsappNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Públicos personalizados para Meta, armados con el pipeline de leads (misión
 * origen-del-lead-y-conversiones-meta, 13/10/2026). Los consumen `GET claude/audiencias` (para M6b,
 * que los sube a Meta) y `leads:exportar-audiencias` (un CSV a mano).
 *
 * Tres etapas, una fila por persona y deduplicadas:
 *
 *   - `conversaron_sin_agendar`: llegaron a WhatsApp y no agendaron. Estado en {nuevo, contactado,
 *     calificado, solicita_disponibilidad}, sin demo, sin la marca de inalcanzable, sin promover, y
 *     con un mensaje DEL PROPIO LEAD en los últimos N días (14 por defecto). Se mide por el último
 *     mensaje del lead y no por `last_message_at`, que también lo mueven nuestros seguimientos: un
 *     lead que no contesta hace dos meses no conversó, aunque le hayamos escrito ayer.
 *   - `agendaron_sin_entrar`: estado en {demo_agendada, demo_pendiente_de_ingreso} y sin el ingreso
 *     confirmado.
 *   - `clientes`: leads promovidos o en `cerrado_ganado`, más la tabla `clients` (teléfono y mail
 *     del dueño), porque hay clientes anteriores al pipeline. Sirve de exclusión y de semilla del
 *     público similar.
 *
 * 🔴 **Nunca sale un dato personal en claro.** Teléfono y mail viajan como SHA-256, normalizados
 * como pide Meta: el teléfono con solo dígitos y código de país, sin `+` (`+549 341 123-4567` →
 * `5493411234567`); el mail sin espacios y en minúsculas.
 */
class AudienciasMetaService
{
    const ETAPA_CONVERSARON_SIN_AGENDAR = 'conversaron_sin_agendar';
    const ETAPA_AGENDARON_SIN_ENTRAR = 'agendaron_sin_entrar';
    const ETAPA_CLIENTES = 'clientes';

    /** Las tres etapas válidas. */
    const ETAPAS = [
        self::ETAPA_CONVERSARON_SIN_AGENDAR,
        self::ETAPA_AGENDARON_SIN_ENTRAR,
        self::ETAPA_CLIENTES,
    ];

    /** Ventana por defecto de `conversaron_sin_agendar`, en días. */
    const DIAS_POR_DEFECTO = 14;

    /** Tope de la ventana: más de un año ya no es una audiencia, es un archivo. */
    const DIAS_MAXIMO = 365;

    /** Estados de `conversaron_sin_agendar`. */
    const ESTADOS_CONVERSARON = ['nuevo', 'contactado', 'calificado', 'solicita_disponibilidad'];

    /** Estados de `agendaron_sin_entrar`. */
    const ESTADOS_AGENDARON = ['demo_agendada', 'demo_pendiente_de_ingreso'];

    /**
     * Qué es cada etapa, para el que consume el endpoint.
     *
     * @return array<string, string>
     */
    public static function descripciones(): array
    {
        return [
            self::ETAPA_CONVERSARON_SIN_AGENDAR => 'Leads que escribieron por WhatsApp en los últimos N días (por su propio último mensaje) y no agendaron la demo: '
                . 'estado ' . implode(', ', self::ESTADOS_CONVERSARON) . ', sin demo, sin la marca de inalcanzable y sin promover.',
            self::ETAPA_AGENDARON_SIN_ENTRAR => 'Leads con la demo agendada que no confirmaron el ingreso: estado '
                . implode(', ', self::ESTADOS_AGENDARON) . ' y demo_ingreso_confirmado en 0.',
            self::ETAPA_CLIENTES => 'Clientes: leads promovidos o en cerrado_ganado, más el teléfono y el mail del dueño de cada fila de clients. '
                . 'Sirve para excluir y como semilla del público similar.',
        ];
    }

    /**
     * Las filas de una etapa, ya con hash y deduplicadas.
     *
     * @param string $etapa Una de ETAPAS.
     * @param int    $dias  Ventana de `conversaron_sin_agendar` (las otras dos la ignoran).
     *
     * @return array<int, array<string, string|null>> Cada fila: `phone_sha256` y `email_sha256` (null si falta).
     */
    public function filas(string $etapa, int $dias = self::DIAS_POR_DEFECTO): array
    {
        if ($etapa === self::ETAPA_CONVERSARON_SIN_AGENDAR) {
            $contactos = $this->contactos_conversaron($dias);
        } elseif ($etapa === self::ETAPA_AGENDARON_SIN_ENTRAR) {
            $contactos = $this->contactos_agendaron();
        } elseif ($etapa === self::ETAPA_CLIENTES) {
            $contactos = $this->contactos_clientes();
        } else {
            throw new \InvalidArgumentException('La etapa "' . $etapa . '" no existe. Válidas: ' . implode(', ', self::ETAPAS) . '.');
        }

        return $this->deduplicar($contactos);
    }

    /**
     * CSV con encabezado `phone,email` (los dos ya hasheados), una fila por persona.
     *
     * @param array<int, array<string, string|null>> $filas
     *
     * @return string
     */
    public function a_csv(array $filas): string
    {
        $lineas = ['phone,email'];
        foreach ($filas as $fila) {
            $lineas[] = (string) $fila['phone_sha256'] . ',' . (string) $fila['email_sha256'];
        }

        return implode("\n", $lineas) . "\n";
    }

    /**
     * SHA-256 del teléfono normalizado como pide Meta: solo dígitos, con código de país, sin `+`.
     *
     * @param string|null $telefono
     *
     * @return string|null Null si no queda un número usable.
     */
    public static function hash_telefono($telefono): ?string
    {
        $normalizado = WhatsappNormalizer::normalize((string) $telefono);
        $digitos     = (string) preg_replace('/\D+/', '', $normalizado);
        if (strlen($digitos) < 8) {
            return null;
        }

        return hash('sha256', $digitos);
    }

    /**
     * SHA-256 del mail sin espacios y en minúsculas.
     *
     * @param string|null $email
     *
     * @return string|null Null si no parece un mail.
     */
    public static function hash_email($email): ?string
    {
        $limpio = mb_strtolower((string) preg_replace('/\s+/u', '', (string) $email), 'UTF-8');
        if ($limpio === '' || strpos($limpio, '@') === false) {
            return null;
        }

        return hash('sha256', $limpio);
    }

    /**
     * @param int $dias
     *
     * @return array<int, array<string, string|null>> Filas crudas `phone`, `email`.
     */
    private function contactos_conversaron(int $dias): array
    {
        $desde = AppTime::now()->subDays(max(1, $dias))->format('Y-m-d H:i:s');

        return DB::table('leads')
            ->whereIn('leads.status', self::ESTADOS_CONVERSARON)
            ->whereNull('leads.demo_date')
            ->whereNull('leads.no_recibe_mensajes_at')
            ->whereNull('leads.promoted_client_id')
            ->whereExists(function ($query) use ($desde) {
                /* El último mensaje DEL LEAD, no `last_message_at`: ver el docblock de la clase. */
                $query->select(DB::raw(1))
                    ->from('lead_messages')
                    ->whereColumn('lead_messages.lead_id', 'leads.id')
                    ->where('lead_messages.sender', 'lead')
                    ->where('lead_messages.is_status_event', 0)
                    ->whereRaw('COALESCE(lead_messages.sent_at, lead_messages.created_at) >= ?', [$desde]);
            })
            ->orderBy('leads.id')
            ->get(['leads.phone', 'leads.email'])
            ->map(function ($fila) {
                return ['phone' => $fila->phone, 'email' => $fila->email];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function contactos_agendaron(): array
    {
        return DB::table('leads')
            ->whereIn('status', self::ESTADOS_AGENDARON)
            ->where(function ($query) {
                $query->where('demo_ingreso_confirmado', 0)->orWhereNull('demo_ingreso_confirmado');
            })
            ->orderBy('id')
            ->get(['phone', 'email'])
            ->map(function ($fila) {
                return ['phone' => $fila->phone, 'email' => $fila->email];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function contactos_clientes(): array
    {
        $leads = DB::table('leads')
            ->where(function ($query) {
                $query->whereNotNull('promoted_client_id')->orWhere('status', 'cerrado_ganado');
            })
            ->orderBy('id')
            ->get(['phone', 'email'])
            ->map(function ($fila) {
                return ['phone' => $fila->phone, 'email' => $fila->email];
            })
            ->all();

        $clientes = DB::table('clients')
            ->orderBy('id')
            ->get(['phone', 'email'])
            ->map(function ($fila) {
                return ['phone' => $fila->phone, 'email' => $fila->email];
            })
            ->all();

        return array_merge($leads, $clientes);
    }

    /**
     * Una fila por persona: dos contactos con el mismo teléfono o el mismo mail son la misma
     * persona, y la fila se completa con lo que falte (un lead sin mail y su cliente con mail quedan
     * en una sola fila con los dos). Un contacto sin teléfono ni mail usable no entra.
     *
     * @param array<int, array<string, string|null>> $contactos
     *
     * @return array<int, array<string, string|null>>
     */
    private function deduplicar(array $contactos): array
    {
        $filas        = [];
        $por_telefono = [];
        $por_email    = [];

        foreach ($contactos as $contacto) {
            $telefono = self::hash_telefono(isset($contacto['phone']) ? $contacto['phone'] : null);
            $email    = self::hash_email(isset($contacto['email']) ? $contacto['email'] : null);

            if ($telefono === null && $email === null) {
                continue;
            }

            $indice = null;
            if ($telefono !== null && isset($por_telefono[$telefono])) {
                $indice = $por_telefono[$telefono];
            } elseif ($email !== null && isset($por_email[$email])) {
                $indice = $por_email[$email];
            }

            if ($indice === null) {
                $indice   = count($filas);
                $filas[]  = ['phone_sha256' => $telefono, 'email_sha256' => $email];
            } else {
                if ($filas[$indice]['phone_sha256'] === null && $telefono !== null && ! isset($por_telefono[$telefono])) {
                    $filas[$indice]['phone_sha256'] = $telefono;
                }
                if ($filas[$indice]['email_sha256'] === null && $email !== null && ! isset($por_email[$email])) {
                    $filas[$indice]['email_sha256'] = $email;
                }
            }

            if ($filas[$indice]['phone_sha256'] !== null) {
                $por_telefono[$filas[$indice]['phone_sha256']] = $indice;
            }
            if ($filas[$indice]['email_sha256'] !== null) {
                $por_email[$filas[$indice]['email_sha256']] = $indice;
            }
        }

        return $filas;
    }
}
