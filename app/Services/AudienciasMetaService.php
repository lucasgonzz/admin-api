<?php

namespace App\Services;

use App\Helpers\AppTime;
use Illuminate\Support\Facades\DB;

/**
 * Públicos personalizados para Meta, armados con el pipeline de leads (misión
 * origen-del-lead-y-conversiones-meta, 13/10/2026). Los consumen `GET claude/audiencias` (para M6b,
 * que los sube a Meta) y `leads:exportar-audiencias` (un CSV a mano).
 *
 * Cuatro etapas, una fila por persona y deduplicadas:
 *
 *   - `conversaron_sin_agendar`: llegaron a WhatsApp y no agendaron. Estado en {nuevo, contactado,
 *     calificado, solicita_disponibilidad}, sin demo, sin la marca de inalcanzable, sin promover, y
 *     con un mensaje DEL PROPIO LEAD en los últimos N días (14 por defecto; 0 = sin límite de tiempo,
 *     o sea «escribieron alguna vez y no agendaron»). Se mide por el último mensaje del lead y no por
 *     `last_message_at`, que también lo mueven nuestros seguimientos: un lead que no contesta hace
 *     dos meses no conversó, aunque le hayamos escrito ayer.
 *   - `agendaron_sin_entrar`: estado en {demo_agendada, demo_pendiente_de_ingreso} y sin el ingreso
 *     confirmado.
 *   - `agendaron`: los que agendaron ALGUNA VEZ, estén donde estén hoy: estado de demo agendada en
 *     adelante, o con `demo_date`, o con el ingreso confirmado. Sin ventana de días. M6b la usa como
 *     exclusión y como parte de la semilla.
 *   - `clientes`: leads promovidos o en `cerrado_ganado`, más la tabla `clients` (teléfono y mail
 *     del dueño), porque hay clientes anteriores al pipeline. Sirve de exclusión y de semilla del
 *     público similar.
 *
 * 🔴 **Nunca sale un dato personal en claro.** Teléfono y mail viajan como SHA-256, normalizados
 * como pide Meta: el teléfono con solo dígitos y código de país, sin `+` (`+549 341 123-4567` →
 * `5493411234567`, con una normalización propia y no la del webhook: ver normalizar_telefono());
 * el mail sin espacios y en minúsculas.
 *
 * Un celular argentino viaja en DOS variantes, con y sin el 9 (`549…` en `phone_sha256` y `54…` en
 * `phone_sha256_sin_9`): Meta guarda los números de los dos modos y subir los dos sube el match. Es
 * como M6b armó sus listas.
 */
class AudienciasMetaService
{
    const ETAPA_CONVERSARON_SIN_AGENDAR = 'conversaron_sin_agendar';
    const ETAPA_AGENDARON_SIN_ENTRAR = 'agendaron_sin_entrar';
    const ETAPA_AGENDARON = 'agendaron';
    const ETAPA_CLIENTES = 'clientes';

    /** Las cuatro etapas válidas. */
    const ETAPAS = [
        self::ETAPA_CONVERSARON_SIN_AGENDAR,
        self::ETAPA_AGENDARON_SIN_ENTRAR,
        self::ETAPA_AGENDARON,
        self::ETAPA_CLIENTES,
    ];

    /** Ventana por defecto de `conversaron_sin_agendar`, en días. */
    const DIAS_POR_DEFECTO = 14;

    /** `dias=0` en `conversaron_sin_agendar`: sin límite de tiempo. */
    const DIAS_SIN_LIMITE = 0;

    /** Tope de la ventana: más de un año ya no es una audiencia, es un archivo (para eso está el 0). */
    const DIAS_MAXIMO = 365;

    /** Estados de `conversaron_sin_agendar`. */
    const ESTADOS_CONVERSARON = ['nuevo', 'contactado', 'calificado', 'solicita_disponibilidad'];

    /** Estados de `agendaron_sin_entrar`. */
    const ESTADOS_AGENDARON = ['demo_agendada', 'demo_pendiente_de_ingreso'];

    /**
     * Estados de `agendaron` (agendó alguna vez): la demo agendada y todo lo que viene después,
     * `mail2_enviado` incluido (en desuso, pero es post-demo).
     */
    const ESTADOS_AGENDARON_ALGUNA_VEZ = [
        'demo_agendada',
        'demo_en_curso',
        'demo_pendiente_de_ingreso',
        'demo_pendiente_de_terminar',
        'demo_realizada',
        'closer_activo',
        'mail2_enviado',
        'cerrado_ganado',
    ];

    /**
     * Qué es cada etapa, para el que consume el endpoint.
     *
     * @return array<string, string>
     */
    public static function descripciones(): array
    {
        return [
            self::ETAPA_CONVERSARON_SIN_AGENDAR => 'Leads que escribieron por WhatsApp en los últimos N días (por su propio último mensaje; dias=0 = sin límite de tiempo) y no agendaron la demo: '
                . 'estado ' . implode(', ', self::ESTADOS_CONVERSARON) . ', sin demo, sin la marca de inalcanzable y sin promover.',
            self::ETAPA_AGENDARON_SIN_ENTRAR => 'Leads con la demo agendada que no confirmaron el ingreso: estado '
                . implode(', ', self::ESTADOS_AGENDARON) . ' y demo_ingreso_confirmado en 0.',
            self::ETAPA_AGENDARON => 'Leads que agendaron la demo alguna vez, estén donde estén hoy: estado '
                . implode(', ', self::ESTADOS_AGENDARON_ALGUNA_VEZ) . ', o con demo_date, o con demo_ingreso_confirmado en 1. '
                . 'Sin ventana de días. Sirve para excluir y como parte de la semilla.',
            self::ETAPA_CLIENTES => 'Clientes: leads promovidos o en cerrado_ganado, más el teléfono y el mail del dueño de cada fila de clients. '
                . 'Sirve para excluir y como semilla del público similar.',
        ];
    }

    /**
     * Las filas de una etapa, ya con hash y deduplicadas.
     *
     * @param string $etapa Una de ETAPAS.
     * @param int    $dias  Ventana de `conversaron_sin_agendar` (0 = sin límite; las otras la ignoran).
     *
     * @return array<int, array<string, string|null>> Cada fila: `phone_sha256`, `email_sha256` y
     *                                                `phone_sha256_sin_9` (null si falta o no aplica).
     */
    public function filas(string $etapa, int $dias = self::DIAS_POR_DEFECTO): array
    {
        return $this->audiencia($etapa, $dias)['filas'];
    }

    /**
     * La audiencia entera de una etapa: las filas (con hash y deduplicadas) y cuántos teléfonos se
     * dejaron afuera por no poder normalizarlos sin adivinar.
     *
     * @param string $etapa Una de ETAPAS.
     * @param int    $dias  Ventana de `conversaron_sin_agendar` (0 = sin límite; las otras la ignoran).
     *
     * @return array<string, mixed> `filas` y `descartados_por_telefono` (contactos con un teléfono
     *                              cargado que no encaja en ningún formato; si tienen mail, entran
     *                              igual por el mail).
     */
    public function audiencia(string $etapa, int $dias = self::DIAS_POR_DEFECTO): array
    {
        if ($etapa === self::ETAPA_CONVERSARON_SIN_AGENDAR) {
            $contactos = $this->contactos_conversaron($dias);
        } elseif ($etapa === self::ETAPA_AGENDARON_SIN_ENTRAR) {
            $contactos = $this->contactos_agendaron_sin_entrar();
        } elseif ($etapa === self::ETAPA_AGENDARON) {
            $contactos = $this->contactos_agendaron_alguna_vez();
        } elseif ($etapa === self::ETAPA_CLIENTES) {
            $contactos = $this->contactos_clientes();
        } else {
            throw new \InvalidArgumentException('La etapa "' . $etapa . '" no existe. Válidas: ' . implode(', ', self::ETAPAS) . '.');
        }

        return $this->deduplicar($contactos);
    }

    /**
     * CSV con encabezado `phone,email` (los dos ya hasheados).
     *
     * Una persona con celular argentino sale en DOS filas, una por variante del teléfono (con y sin
     * el 9) y el mail repetido: es como lo armó M6b y como lo acepta la lista de clientes de Meta.
     * El resto, en una sola fila.
     *
     * @param array<int, array<string, string|null>> $filas
     *
     * @return string
     */
    public function a_csv(array $filas): string
    {
        $lineas = ['phone,email'];
        foreach ($filas as $fila) {
            $email    = (string) $fila['email_sha256'];
            $lineas[] = (string) $fila['phone_sha256'] . ',' . $email;

            if (isset($fila['phone_sha256_sin_9']) && $fila['phone_sha256_sin_9'] !== null) {
                $lineas[] = (string) $fila['phone_sha256_sin_9'] . ',' . $email;
            }
        }

        return implode("\n", $lineas) . "\n";
    }

    /**
     * SHA-256 de la variante sin el 9 de un celular argentino (`54` + los 10 dígitos).
     *
     * @param string|null $telefono
     *
     * @return string|null Null si no es un celular argentino (o no encaja).
     */
    public static function hash_telefono_sin_9($telefono): ?string
    {
        $normalizado = self::normalizar_telefono($telefono);

        return $normalizado !== null && $normalizado['sin_9'] !== null ? hash('sha256', $normalizado['sin_9']) : null;
    }

    /**
     * SHA-256 del teléfono normalizado como pide Meta: solo dígitos, con código de país, sin `+`.
     *
     * @param string|null $telefono
     *
     * @return string|null Null si no queda un número usable (vacío o que no encaja: ver
     *                     {@see normalizar_telefono()}).
     */
    public static function hash_telefono($telefono): ?string
    {
        $normalizado = self::normalizar_telefono($telefono);

        return $normalizado !== null ? hash('sha256', $normalizado['con_9']) : null;
    }

    /**
     * El teléfono como lo pide Meta (solo dígitos, con código de país, sin `+`), con una regla propia
     * para las audiencias y NO la del webhook.
     *
     * 🔴 Por qué no `WhatsappNormalizer::normalize()`: está pensado para lo que manda WhatsApp (que
     * ya viene en E.164) y con un teléfono cargado a mano en `clients` se equivoca en silencio:
     * «0341 15 512-3456» le da `+341155123456` (código de país 34, España) y a cualquier extranjero
     * le fuerza el `549`. Un hash de un número equivocado no le hace match a nadie en Meta.
     *
     * La regla, en orden:
     *   (a) Si el crudo empieza con `+` o `00` y el código de país no es 54: internacional tal cual
     *       (solo dígitos, sin el `00`), entre 8 y 15 dígitos.
     *   (b) Argentino (sin prefijo internacional, o con `+54`/`0054`): se saca el 0 inicial y
     *       - 10 dígitos → `549` + los 10;
     *       - 12 dígitos con un «15» después de una característica de 2, 3 o 4 dígitos que, quitado,
     *         deja 10 → `549` + esos 10 (si más de una característica deja números distintos, es
     *         ambiguo y no se adivina);
     *       - `54` + 10 → `549` + los 10; `549` + 10 → tal cual; `54` + 12 con el 15 → igual que
     *         el caso del 15.
     *   (c) Lo que no encaja en nada de eso no se adivina: devuelve null y el que llama lo cuenta en
     *       `descartados_por_telefono`.
     *
     * `leads.phone` ya viene normalizado `+549…` desde el webhook y esta regla lo deja igual.
     *
     * @param string|null $telefono
     *
     * @return array<string, string|null>|null `con_9` (el número para Meta) y `sin_9` (`54` + los 10
     *                                         de un celular argentino; null para un internacional).
     *                                         Null si está vacío o no encaja.
     */
    public static function normalizar_telefono($telefono): ?array
    {
        $crudo = trim((string) $telefono);
        if ($crudo === '') {
            return null;
        }

        $digitos = (string) preg_replace('/\D+/', '', $crudo);

        if (strpos($crudo, '+') === 0 || strpos($crudo, '00') === 0) {
            if (strpos($crudo, '00') === 0) {
                $digitos = (string) substr($digitos, 2);
            }

            if (strpos($digitos, '54') !== 0) {
                $largo = strlen($digitos);

                return ($largo >= 8 && $largo <= 15) ? ['con_9' => $digitos, 'sin_9' => null] : null;
            }
        } elseif (strpos($digitos, '0') === 0) {
            $digitos = (string) substr($digitos, 1);
        }

        $diez = self::diez_digitos_argentinos($digitos);
        if ($diez === null) {
            return null;
        }

        return ['con_9' => '549' . $diez, 'sin_9' => '54' . $diez];
    }

    /**
     * Los 10 dígitos (característica + número) de un celular argentino, o null si no encaja.
     *
     * @param string $digitos Ya sin el 0 inicial.
     *
     * @return string|null
     */
    private static function diez_digitos_argentinos(string $digitos): ?string
    {
        $largo = strlen($digitos);

        if ($largo === 13 && strpos($digitos, '549') === 0) {
            return substr($digitos, 3);
        }
        if ($largo === 12 && strpos($digitos, '54') === 0) {
            return substr($digitos, 2);
        }
        if ($largo === 14 && strpos($digitos, '54') === 0) {
            return self::sacar_el_15(substr($digitos, 2));
        }
        if ($largo === 10) {
            return $digitos;
        }
        if ($largo === 12) {
            return self::sacar_el_15($digitos);
        }

        return null;
    }

    /**
     * Saca el «15» de un número local de 12 dígitos (característica de 2, 3 o 4 + 15 + número). Si
     * más de una característica posible deja números distintos, es ambiguo: null.
     *
     * @param string $doce
     *
     * @return string|null Los 10 dígitos.
     */
    private static function sacar_el_15(string $doce): ?string
    {
        if (strlen($doce) !== 12) {
            return null;
        }

        $candidatos = [];
        foreach ([2, 3, 4] as $largo_caracteristica) {
            if (substr($doce, $largo_caracteristica, 2) === '15') {
                $candidatos[] = substr($doce, 0, $largo_caracteristica) . substr($doce, $largo_caracteristica + 2);
            }
        }

        $candidatos = array_values(array_unique($candidatos));

        return count($candidatos) === 1 ? $candidatos[0] : null;
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
        /* dias=0: sin límite de tiempo («escribieron alguna vez y no agendaron»). Igual tiene que
           haber al menos un mensaje del propio lead. */
        $desde = $dias > self::DIAS_SIN_LIMITE
            ? AppTime::now()->subDays($dias)->format('Y-m-d H:i:s')
            : null;

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
                    ->where('lead_messages.is_status_event', 0);
                if ($desde !== null) {
                    $query->whereRaw('COALESCE(lead_messages.sent_at, lead_messages.created_at) >= ?', [$desde]);
                }
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
    private function contactos_agendaron_alguna_vez(): array
    {
        return DB::table('leads')
            ->where(function ($query) {
                $query->whereIn('status', self::ESTADOS_AGENDARON_ALGUNA_VEZ)
                    ->orWhereNotNull('demo_date')
                    ->orWhere('demo_ingreso_confirmado', 1);
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
    private function contactos_agendaron_sin_entrar(): array
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
     * Un teléfono cargado que no encaja en ningún formato se deja afuera (no se adivina) y se cuenta
     * en `descartados_por_telefono`; si el contacto tiene mail, entra igual por el mail.
     *
     * @param array<int, array<string, string|null>> $contactos
     *
     * @return array<string, mixed> `filas` y `descartados_por_telefono`.
     */
    private function deduplicar(array $contactos): array
    {
        $filas        = [];
        $por_telefono = [];
        $por_email    = [];
        $descartados  = 0;

        foreach ($contactos as $contacto) {
            $crudo     = isset($contacto['phone']) ? trim((string) $contacto['phone']) : '';
            $telefono  = self::hash_telefono($crudo);
            $sin_9     = self::hash_telefono_sin_9($crudo);
            $email     = self::hash_email(isset($contacto['email']) ? $contacto['email'] : null);

            if ($crudo !== '' && $telefono === null) {
                $descartados++;
            }

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
                $indice  = count($filas);
                /* `phone_sha256_sin_9` va al final: es un agregado compatible del contrato con M6b. */
                $filas[] = ['phone_sha256' => $telefono, 'email_sha256' => $email, 'phone_sha256_sin_9' => $sin_9];
            } else {
                if ($filas[$indice]['phone_sha256'] === null && $telefono !== null && ! isset($por_telefono[$telefono])) {
                    $filas[$indice]['phone_sha256']       = $telefono;
                    $filas[$indice]['phone_sha256_sin_9'] = $sin_9;
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

        return ['filas' => $filas, 'descartados_por_telefono' => $descartados];
    }
}
