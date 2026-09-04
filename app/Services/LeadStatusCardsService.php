<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadPipelineStatus;

/**
 * Arma las tarjetas de estado que van arriba de la grilla de leads en admin-spa.
 *
 * Cada tarjeta dice, para un estado del pipeline, cuántos leads hay en ese estado y cuántos de
 * esos leads necesitan revisión (mismo criterio que el botón de revisión: mensajes sin responder
 * o error sin resolver).
 *
 * Dos definiciones que no se negocian:
 *
 * 1. Los conteos son **globales**: acá no entran los filtros de columna ni de fecha del operador.
 *    Mismo criterio que los badges de no leídos de la barra de estados.
 * 2. `sin_responder` cuenta **leads**, no mensajes. Un lead con tres mensajes sin responder suma 1.
 *
 * El conteo de revisión se resuelve por SQL con `Lead::scopeRequiereRevision()`, no recorriendo
 * modelos en PHP: este servicio corre en cada carga de la vista y en cada refresco por webhook, y
 * la relación `messages` arrastra `with([...])`, o sea decenas de miles de modelos hidratados.
 */
class LeadStatusCardsService
{
    /**
     * Gris neutro cuando el estado no tiene color en el catálogo (mismo fallback que
     * LeadPipelineStatus::color_for()).
     */
    const COLOR_FALLBACK = '#ced4da';

    /**
     * Tarjetas para los estados pedidos, siempre las mismas claves y siempre en el orden recibido
     * (aunque den cero): el SPA no inventa ni ordena nada.
     *
     * @param array<int, string> $slugs Slugs de estado a contar, en el orden de salida.
     *
     * @return array<int, array<string, mixed>> Tarjetas {value, text, color, group, total, sin_responder}.
     */
    public static function cards_for_statuses(array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        // Total de leads por estado.
        $totales = Lead::query()
            ->whereIn('status', $slugs)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as cantidad')
            ->pluck('cantidad', 'status');

        // De esos, los que necesitan respuesta. 🔴 El `true` suma a la razón B los rechazos que
        // Meta avisa por webhook (`whatsapp_delivery_status = 'fallido'`), que NO dejan un
        // `is_error` y por lo tanto son invisibles para el botón de revisión. Va así por decisión
        // de Lucas del 1/9/2026: el pedido decía "error de sistema o de Meta", y sin el `true` la
        // tarjeta mostraba 0 arriba de una fila que la grilla pinta de rojo por ese mismo envío.
        // Ver el PHPDoc de Lead::scopeRequiereRevision() para el detalle de los dos criterios.
        $pendientes = Lead::query()
            ->whereIn('status', $slugs)
            ->requiereRevision(true)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as cantidad')
            ->pluck('cantidad', 'status');

        // Catálogo de estados indexado por slug: el color y la etiqueta de la tarjeta salen de la
        // MISMA fuente que el puntito de la barra de estados, así nunca divergen.
        $opciones = [];
        foreach (LeadPipelineStatus::options_for_meta() as $opcion) {
            if (isset($opcion['value'])) {
                $opciones[$opcion['value']] = $opcion;
            }
        }

        $cards = [];
        foreach ($slugs as $slug) {
            $slug = (string) $slug;

            if (isset($opciones[$slug])) {
                // Si el catálogo tiene el label vacío, se humaniza el slug igual: una tarjeta sin
                // título no es una opción.
                $text  = trim((string) $opciones[$slug]['text']) !== ''
                    ? (string) $opciones[$slug]['text']
                    : LeadPipelineStatus::humanize_slug($slug);
                $color = (string) $opciones[$slug]['color'];
                $group = $opciones[$slug]['group'];
            } else {
                // Estado que no está en el catálogo visible (por ejemplo, oculto del select).
                $text  = LeadPipelineStatus::humanize_slug($slug);
                $color = LeadPipelineStatus::color_for($slug);
                $group = LeadPipelineStatus::DEFAULT_STATUS_GROUPS[$slug] ?? null;
            }

            $cards[] = [
                'value'         => $slug,
                'text'          => $text,
                'color'         => $color !== '' ? $color : self::COLOR_FALLBACK,
                'group'         => $group,
                'total'         => (int) ($totales[$slug] ?? 0),
                'sin_responder' => (int) ($pendientes[$slug] ?? 0),
            ];
        }

        return $cards;
    }

    /**
     * Tarjeta agrupada: mismo criterio que cards_for_statuses(), pero sumando el total y el
     * "sin_responder" de VARIOS slugs bajo una sola tarjeta con label/color propios (no hay un slug
     * 1:1 en el catálogo al que pedírselos).
     *
     * @param string $value Clave sintética de la tarjeta (no es un status real de ningún lead).
     * @param string $text
     * @param string $color
     * @param string|null $group
     * @param array<int, string> $slugs Slugs de estado que suman al total de esta tarjeta.
     *
     * @return array<string, mixed> {value, text, color, group, slugs, total, sin_responder}.
     */
    public static function card_for_group(string $value, string $text, string $color, $group, array $slugs): array
    {
        $total = (int) Lead::query()->whereIn('status', $slugs)->count();
        $sin_responder = (int) Lead::query()->whereIn('status', $slugs)->requiereRevision(true)->count();

        return [
            'value'         => $value,
            'text'          => $text,
            'color'         => $color !== '' ? $color : self::COLOR_FALLBACK,
            'group'         => $group,
            'slugs'         => $slugs,
            'total'         => $total,
            'sin_responder' => $sin_responder,
        ];
    }
}
