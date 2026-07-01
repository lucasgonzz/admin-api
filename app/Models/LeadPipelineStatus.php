<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Estado del pipeline comercial de leads (catálogo para select, filtros y sugerencias IA).
 */
class LeadPipelineStatus extends Model
{
    /**
     * Slugs por defecto del pipeline (seed y fallback si la tabla está vacía).
     *
     * Orden del ciclo de vida de la demo:
     *   demo_agendada → ingresando_demo → demo_en_curso → demo_realizada
     * Ramas de fallo (sin confirmación de ingreso o de fin):
     *   demo_pendiente_de_ingreso, demo_pendiente_de_terminar
     */
    public const DEFAULT_STATUSES = [
        'nuevo'                        => 'Nuevo',
        'contactado'                   => 'Contactado',
        'calificado'                   => 'Calificado',
        'demo_agendada'                => 'Demo agendada',
        // Subestados del ciclo de vida de la demo (automatizados por el agente).
        'ingresando_demo'              => 'Ingresando a demo',
        'demo_en_curso'                => 'Demo en curso',
        // Ramas de fallo: no respondió al check de ingreso o no confirmó fin.
        'demo_pendiente_de_ingreso'    => 'Demo pendiente de ingreso',
        'demo_pendiente_de_terminar'   => 'Demo pendiente de terminar',
        'demo_realizada'               => 'Demo realizada',
        'closer_activo'                => 'Closer activo',
        'mail2_enviado'                => 'Mail2 enviado',
        'cerrado_ganado'               => 'Cerrado ganado',
        'cerrado_perdido'              => 'Cerrado perdido',
        'en_pausa'                     => 'En pausa',
    ];

    /**
     * Color de fondo del badge por slug (hex) para admin-spa.
     * Gris: etapas iniciales y cierres/pausa de baja prioridad visual.
     * Rojo: acciones urgentes del ciclo de demo (ingreso y pendientes).
     * Azul claro: solicita disponibilidad; azul fuerte: demo agendada;
     * amarillo: demo en curso; verde: calificado;
     * gris oscuro: cerrado ganado (texto blanco vía contraste en el SPA).
     */
    public const DEFAULT_COLORS = [
        'nuevo'                        => '#adb5bd',
        'contactado'                   => '#adb5bd',
        'calificado'                   => '#28a745',
        'solicita_disponibilidad'      => '#0d6efd',
        'demo_agendada'                => '#0a58ca',
        'ingresando_demo'              => '#dc3545',
        'demo_en_curso'                => '#ffc107',
        'demo_pendiente_de_ingreso'    => '#dc3545',
        'demo_pendiente_de_terminar'   => '#dc3545',
        'demo_realizada'               => '#0d6efd',
        'closer_activo'                => '#6f42c1',
        'mail2_enviado'                => '#adb5bd',
        'cerrado_ganado'               => '#495057',
        'cerrado_perdido'              => '#adb5bd',
        'en_pausa'                     => '#adb5bd',
    ];

    /**
     * Grupo de categoría visual por slug. Los slugs no listados se muestran sin grupo.
     * `demo_realizada` está ausente deliberadamente: se excluye del selector.
     */
    public const DEFAULT_STATUS_GROUPS = [
        'nuevo'                      => 'Calificación',
        'contactado'                 => 'Calificación',
        'calificado'                 => 'Calificación',
        'demo_agendada'              => 'Demo',
        'ingresando_demo'            => 'Demo',
        'demo_en_curso'              => 'Demo',
        'demo_pendiente_de_ingreso'  => 'Demo',
        'demo_pendiente_de_terminar' => 'Demo',
        'closer_activo'              => 'Cierre',
        'cerrado_ganado'             => 'Cierre',
        'cerrado_perdido'            => 'Fin',
        'en_pausa'                   => 'Fin',
        'mail2_enviado'              => 'Fin',
    ];

    /** Slug excluido de los selectores de la UI (existe en BD pero es estado técnico interno). */
    public const SLUG_HIDDEN_FROM_SELECT = 'demo_realizada';

    protected $guarded = [];

    /**
     * Normaliza un slug de estado (minúsculas, guiones bajos, caracteres seguros).
     *
     * @param string $raw Valor devuelto por Claude o input manual.
     *
     * @return string Slug vacío si no queda contenido válido.
     */
    public static function normalize_slug(string $raw): string
    {
        $slug = Str::slug(trim($raw), '_');
        $slug = preg_replace('/[^a-z0-9_]/', '', $slug) ?? '';

        return $slug;
    }

    /**
     * Etiqueta legible a partir del slug (sin consultar BD).
     *
     * @param string $slug
     *
     * @return string
     */
    public static function humanize_slug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        return ucfirst(str_replace('_', ' ', $slug));
    }

    /**
     * Devuelve la etiqueta de un slug; consulta BD o humaniza.
     *
     * @param string|null $slug
     *
     * @return string|null
     */
    public static function label_for(?string $slug): ?string
    {
        if ($slug === null || trim($slug) === '') {
            return null;
        }
        $slug = trim($slug);
        $row = static::query()->where('slug', $slug)->first();
        if ($row) {
            return $row->label;
        }

        return static::humanize_slug($slug);
    }

    /**
     * Color de badge para un slug; consulta BD o usa default / gris neutro.
     *
     * @param string|null $slug
     *
     * @return string Hex de fondo (ej. `#e9ecef`).
     */
    public static function color_for(?string $slug): string
    {
        if ($slug === null || trim($slug) === '') {
            return '#ced4da';
        }
        $slug = trim($slug);
        $row = static::query()->where('slug', $slug)->first();
        if ($row && is_string($row->color) && trim($row->color) !== '') {
            return trim($row->color);
        }

        return static::DEFAULT_COLORS[$slug] ?? '#ced4da';
    }

    /**
     * Lista de slugs válidos en BD (o defaults si aún no hay filas).
     *
     * @return array<int, string>
     */
    public static function all_slugs(): array
    {
        $from_db = static::query()->orderBy('sort_order')->orderBy('id')->pluck('slug')->all();
        if (! empty($from_db)) {
            return $from_db;
        }

        return array_keys(static::DEFAULT_STATUSES);
    }

    /**
     * Opciones `{ value, text, color, group }` para el select de estado en admin-spa.
     * Excluye `demo_realizada` (estado técnico interno).
     *
     * @return array<int, array{value: string, text: string, color: string, group: string|null}>
     */
    public static function options_for_meta(): array
    {
        $rows = static::query()->orderBy('sort_order')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $options = [];
            foreach (static::DEFAULT_STATUSES as $slug => $label) {
                if ($slug === static::SLUG_HIDDEN_FROM_SELECT) {
                    continue;
                }
                $options[] = [
                    'value' => $slug,
                    'text'  => $label,
                    'color' => static::DEFAULT_COLORS[$slug] ?? '#ced4da',
                    'group' => static::DEFAULT_STATUS_GROUPS[$slug] ?? null,
                ];
            }

            return $options;
        }

        $options = [];
        foreach ($rows as $row) {
            if ($row->slug === static::SLUG_HIDDEN_FROM_SELECT) {
                continue;
            }
            $options[] = [
                'value' => $row->slug,
                'text'  => $row->label,
                'color' => $row->color ?: (static::DEFAULT_COLORS[$row->slug] ?? '#ced4da'),
                'group' => static::DEFAULT_STATUS_GROUPS[$row->slug] ?? null,
            ];
        }

        return $options;
    }

    /**
     * Crea el estado en catálogo si no existe; devuelve el registro existente o nuevo.
     *
     * @param string      $raw_slug Valor crudo (se normaliza).
     * @param string|null $label    Etiqueta opcional; si falta, se humaniza el slug.
     *
     * @return self
     */
    public static function ensure_exists(string $raw_slug, ?string $label = null): self
    {
        $slug = static::normalize_slug($raw_slug);
        if ($slug === '') {
            throw new \InvalidArgumentException('El slug de estado del pipeline no es válido.');
        }

        $existing = static::query()->where('slug', $slug)->first();
        if ($existing) {
            return $existing;
        }

        $label_text = $label !== null && trim($label) !== ''
            ? trim($label)
            : (static::DEFAULT_STATUSES[$slug] ?? static::humanize_slug($slug));

        $max_order = (int) static::query()->max('sort_order');

        return static::create([
            'slug'       => $slug,
            'label'      => $label_text,
            'color'      => static::DEFAULT_COLORS[$slug] ?? '#ced4da',
            'sort_order' => $max_order + 1,
        ]);
    }

    /**
     * Inserta los estados por defecto si la tabla está vacía (idempotente).
     *
     * @return void
     */
    public static function seed_defaults_if_empty(): void
    {
        if (static::query()->exists()) {
            return;
        }

        $order = 0;
        foreach (static::DEFAULT_STATUSES as $slug => $label) {
            static::create([
                'slug'       => $slug,
                'label'      => $label,
                'color'      => static::DEFAULT_COLORS[$slug] ?? '#ced4da',
                'sort_order' => $order,
            ]);
            $order++;
        }
    }

    /**
     * Actualiza colores de los estados conocidos (idempotente; útil tras migrar columna `color`).
     *
     * @return void
     */
    public static function sync_default_colors(): void
    {
        foreach (static::DEFAULT_COLORS as $slug => $color) {
            static::query()->where('slug', $slug)->update(['color' => $color]);
        }
    }
}
