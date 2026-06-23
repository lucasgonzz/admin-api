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
        'mail2_enviado'                => 'Mail2 enviado',
        'cerrado_ganado'               => 'Cerrado ganado',
        'cerrado_perdido'              => 'Cerrado perdido',
        'en_pausa'                     => 'En pausa',
    ];

    /**
     * Color de fondo del badge por slug (hex). De menos a más llamativo en el pipeline;
     * gama azul para el progreso de la demo; gama ámbar para los estados de espera/fallo;
     * cierres y pausa vuelven a tonos discretos.
     */
    public const DEFAULT_COLORS = [
        'nuevo'                        => '#e9ecef',
        'contactado'                   => '#dee2e6',
        'calificado'                   => '#b8d4e8',
        'demo_agendada'                => '#6ea8fe',
        // Progreso del ciclo de demo: escala de azul hacia el azul intenso.
        'ingresando_demo'              => '#9ec5fe',
        'demo_en_curso'                => '#3d8bfd',
        // Ramas de fallo: ámbar discreto para "pendiente de acción".
        'demo_pendiente_de_ingreso'    => '#ffe5a0',
        'demo_pendiente_de_terminar'   => '#ffd8a8',
        'demo_realizada'               => '#0d6efd',
        'mail2_enviado'                => '#ffc107',
        'cerrado_ganado'               => '#d1e7dd',
        'cerrado_perdido'              => '#e8d4d4',
        'en_pausa'                     => '#f1f3f5',
    ];

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
     * Opciones `{ value, text }` para el select de estado en admin-spa.
     *
     * @return array<int, array{value: string, text: string}>
     */
    public static function options_for_meta(): array
    {
        $rows = static::query()->orderBy('sort_order')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            $options = [];
            foreach (static::DEFAULT_STATUSES as $slug => $label) {
                $options[] = [
                    'value' => $slug,
                    'text'  => $label,
                    'color' => static::DEFAULT_COLORS[$slug] ?? '#ced4da',
                ];
            }

            return $options;
        }

        $options = [];
        foreach ($rows as $row) {
            $options[] = [
                'value' => $row->slug,
                'text'  => $row->label,
                'color' => $row->color ?: (static::DEFAULT_COLORS[$row->slug] ?? '#ced4da'),
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
