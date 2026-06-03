<?php

namespace App\Services;

/**
 * Normaliza texto pegado desde exportaciones de WhatsApp (Web / copiar chat).
 *
 * Formato típico por línea: `[7:23 p. m., 13/5/2026] +54 9 11 3066-5894: cuerpo del mensaje`
 * Las líneas siguientes sin ese prefijo se tratan como continuación del mensaje anterior.
 *
 * Con {@see parse_export_paste()} se pueden pegar varios mensajes del hilo (lead y setter)
 * en un solo movimiento; el remitente se infiere comparando con el teléfono/nombre del lead.
 */
class LeadWhatsAppPasteCleaner
{
    /**
     * Patrón de línea con metadatos: corchetes (fecha/hora), remitente, dos puntos, texto inicial.
     *
     * @var string
     */
    private const LINE_WITH_META_PATTERN = '#^\[[^\]]+\]\s*(.+?):\s*(.*)$#u';

    /**
     * Remitentes que WhatsApp Web usa para los mensajes propios del operador (setter).
     *
     * @var array<int, string>
     */
    private const SELF_SENDER_LABELS = ['tú', 'tu', 'you', 'vos'];

    /**
     * Quita prefijos de exportación WhatsApp línea a línea; preserva saltos y líneas de continuación.
     *
     * Si ninguna línea coincide con el formato WhatsApp, se devuelve el texto original recortado
     * (evita alterar mensajes que no vinieron de un pegado de chat).
     *
     * @param string $raw Texto tal cual lo pega el operador (puede ser multilínea).
     *
     * @return string Texto listo para guardar o enviar al modelo.
     */
    public static function clean_export_paste(string $raw): string
    {
        $parsed = self::parse_export_paste($raw);

        if (count($parsed) === 1) {
            return $parsed[0]['content'];
        }

        if (count($parsed) > 1) {
            $parts = [];
            foreach ($parsed as $item) {
                $parts[] = $item['content'];
            }

            return trim(implode("\n\n", $parts));
        }

        return trim(self::normalize_raw($raw));
    }

    /**
     * Divide un pegado de WhatsApp en mensajes ordenados con emisor lead o setter.
     *
     * Sin líneas con formato WhatsApp devuelve un único mensaje `lead` (compatibilidad con pegado libre).
     * Con varias líneas meta, cada bloque conserva el orden del export y clasifica el remitente.
     *
     * @param string      $raw               Texto pegado desde WhatsApp.
     * @param string|null $lead_phone        Teléfono del lead en admin (para comparar con el remitente).
     * @param string|null $lead_contact_name Nombre de contacto del lead (fallback si no hay teléfono).
     *
     * @return array<int, array{sender: string, content: string}>
     */
    public static function parse_export_paste(
        string $raw,
        ?string $lead_phone = null,
        ?string $lead_contact_name = null
    ): array {
        $normalized = self::normalize_raw($raw);
        if ($normalized === '') {
            return [];
        }

        $lines = explode("\n", $normalized);
        $blocks = self::split_into_blocks($lines);

        if (empty($blocks)) {
            return [];
        }

        if (! $blocks['any_meta_line']) {
            return [
                [
                    'sender'  => 'lead',
                    'content' => trim($normalized),
                ],
            ];
        }

        $result = [];
        foreach ($blocks['messages'] as $block) {
            $content = self::finalize_block_content($block['content_lines']);
            if ($content === '') {
                continue;
            }

            $result[] = [
                'sender'  => self::classify_sender(
                    $block['raw_sender'],
                    $lead_phone,
                    $lead_contact_name
                ),
                'content' => $content,
            ];
        }

        if (empty($result)) {
            return [
                [
                    'sender'  => 'lead',
                    'content' => trim($normalized),
                ],
            ];
        }

        return $result;
    }

    /**
     * Normaliza saltos de línea y quita BOM al inicio del pegado.
     *
     * @param string $raw Texto crudo.
     *
     * @return string
     */
    private static function normalize_raw(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

        return str_replace(["\r\n", "\r"], "\n", $raw);
    }

    /**
     * Agrupa líneas del export en bloques (un mensaje por línea con corchetes + continuaciones).
     *
     * @param array<int, string> $lines Líneas ya normalizadas.
     *
     * @return array{any_meta_line: bool, messages: array<int, array{raw_sender: string, content_lines: array<int, string>}>}
     */
    private static function split_into_blocks(array $lines): array
    {
        $any_meta_line = false;
        $messages = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match(self::LINE_WITH_META_PATTERN, $line, $matches)) {
                $any_meta_line = true;

                if ($current !== null) {
                    $messages[] = $current;
                }

                $current = [
                    'raw_sender'     => trim((string) $matches[1]),
                    'content_lines'  => [],
                ];

                $first_body = trim((string) $matches[2]);
                if ($first_body !== '') {
                    $current['content_lines'][] = $first_body;
                }
            } elseif ($current !== null) {
                $current['content_lines'][] = trim($line);
            } else {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    if ($current === null) {
                        $current = [
                            'raw_sender'    => '',
                            'content_lines' => [$trimmed],
                        ];
                    } else {
                        $current['content_lines'][] = $trimmed;
                    }
                }
            }
        }

        if ($current !== null) {
            $messages[] = $current;
        }

        return [
            'any_meta_line' => $any_meta_line,
            'messages'      => $messages,
        ];
    }

    /**
     * Une líneas de cuerpo del mensaje y colapsa saltos excesivos.
     *
     * @param array<int, string> $content_lines Fragmentos de texto del mensaje.
     *
     * @return string
     */
    private static function finalize_block_content(array $content_lines): string
    {
        $filtered = [];
        foreach ($content_lines as $line) {
            if ($line !== '') {
                $filtered[] = $line;
            }
        }

        if (empty($filtered)) {
            return '';
        }

        $joined = implode("\n", $filtered);
        $joined = trim(preg_replace("/\n{3,}/", "\n\n", $joined) ?? $joined);

        return $joined;
    }

    /**
     * Determina si el remitente del export corresponde al lead o al setter.
     *
     * Prioridad: etiqueta "Tú" → setter; teléfono del lead → lead; nombre de contacto → lead;
     * número distinto al del lead → setter; sin señales → lead (formulario histórico).
     *
     * @param string      $raw_sender        Texto entre corchetes y los dos puntos (teléfono o nombre).
     * @param string|null $lead_phone        Teléfono guardado en el lead.
     * @param string|null $lead_contact_name Nombre del contacto del lead.
     *
     * @return string `lead` o `setter`
     */
    private static function classify_sender(
        string $raw_sender,
        ?string $lead_phone,
        ?string $lead_contact_name
    ): string {
        $raw = trim($raw_sender);

        if ($raw === '') {
            return 'lead';
        }

        if (self::is_self_sender_label($raw)) {
            return 'setter';
        }

        $lead_digits = self::normalize_phone_digits($lead_phone);
        $sender_digits = self::normalize_phone_digits($raw);

        if ($lead_digits !== '' && $sender_digits !== '') {
            return self::phones_match($sender_digits, $lead_digits) ? 'lead' : 'setter';
        }

        $contact = trim((string) $lead_contact_name);
        if ($contact !== '') {
            if (mb_strtolower($raw) === mb_strtolower($contact)) {
                return 'lead';
            }

            if (mb_stripos($raw, $contact) !== false || mb_stripos($contact, $raw) !== false) {
                return 'lead';
            }

            if ($lead_digits === '' && $sender_digits === '') {
                return 'setter';
            }
        }

        if ($lead_digits !== '' && $sender_digits === '' && ! self::looks_like_phone_fragment($raw)) {
            return 'setter';
        }

        return 'lead';
    }

    /**
     * true si el remitente es la etiqueta de mensajes propios en WhatsApp Web.
     *
     * @param string $raw_sender
     *
     * @return bool
     */
    private static function is_self_sender_label(string $raw_sender): bool
    {
        $normalized = mb_strtolower(trim($raw_sender));

        return in_array($normalized, self::SELF_SENDER_LABELS, true);
    }

    /**
     * Extrae solo dígitos para comparar teléfonos con distintos formatos (+54, guiones, espacios).
     *
     * @param string|null $value
     *
     * @return string
     */
    private static function normalize_phone_digits(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * Compara dos teléfonos por sufijo (últimos 8–10 dígitos) para tolerar prefijos de país.
     *
     * @param string $sender_digits
     * @param string $lead_digits
     *
     * @return bool
     */
    private static function phones_match(string $sender_digits, string $lead_digits): bool
    {
        if ($sender_digits === $lead_digits) {
            return true;
        }

        $suffix_lengths = [10, 9, 8];
        foreach ($suffix_lengths as $len) {
            if (strlen($sender_digits) >= $len && strlen($lead_digits) >= $len) {
                $sender_suffix = substr($sender_digits, -$len);
                $lead_suffix = substr($lead_digits, -$len);
                if ($sender_suffix === $lead_suffix) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Heurística: el remitente parece un número de teléfono aunque venga con texto extra.
     *
     * @param string $raw_sender
     *
     * @return bool
     */
    private static function looks_like_phone_fragment(string $raw_sender): bool
    {
        $digits = self::normalize_phone_digits($raw_sender);

        return strlen($digits) >= 6;
    }
}
