<?php

namespace Database\Seeders;

use App\Models\MessageVariant;
use Illuminate\Database\Seeder;

/**
 * Siembra las variantes iniciales de welcome para A/B testing.
 */
class MessageVariantSeeder extends Seeder
{
    /**
     * Definición compartida de variantes de arranque (reutilizada por el seeder standalone).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function variant_definitions(): array
    {
        return [
            [
                'slug'         => 'control',
                'name'         => 'Control — Presentación completa (actual)',
                'message_type' => 'welcome_with_name',
                'body'         => "Hola, soy Martín, del equipo de ComercioCity.\n\n"
                    . "Ayudamos a distribuidoras y comercios a profesionalizar su operación: stock, ventas, facturación ARCA, ecommerce integrado y WhatsApp conectado al sistema — todo en un solo lugar.\n\n"
                    . "La implementación la hacemos nosotros: te hacemos unas preguntas por WhatsApp, y te entregamos el sistema andando con tu información ya cargada. Sin tecnicismos, sin que tengas que hacer nada.\n\n"
                    . 'Para ver si encajamos con lo que necesitás, contame: ¿a qué se dedica tu empresa y cuántas personas trabajan con vos?',
                'active'        => true,
                'delay_seconds' => null,
                'notes'         => 'Variante de control — texto enviado históricamente. Tasa base de respuesta: ~46%.',
            ],
            [
                'slug'         => 'pregunta_directa',
                'name'         => 'B — Pregunta directa sin presentación',
                'message_type' => 'welcome_with_name',
                'body'         => 'Hola {nombre}! Dale, contame... ¿a qué se dedica el negocio?',
                'active'        => true,
                'delay_seconds' => null,
                'notes'         => 'Hipótesis: reducir fricción eliminando la presentación de empresa. El 54% de leads no responde al texto actual.',
            ],
            [
                'slug'         => 'empatia_pregunta',
                'name'         => 'C — Empatía + pregunta corta',
                'message_type' => 'welcome_with_name',
                'body'         => 'Hola {nombre}! Vi que te interesó lo del sistema... ¿qué tipo de negocio tenés vos?',
                'active'        => true,
                'delay_seconds' => null,
                'notes'         => 'Hipótesis: referenciar el ad crea continuidad. Más conversacional, sin vender.',
            ],
        ];
    }

    /**
     * Inserta las 3 variantes iniciales si no existen (idempotente por slug).
     *
     * @return void
     */
    public function run()
    {
        foreach (self::variant_definitions() as $definition) {
            /* Slug único como clave de upsert lógico. */
            $slug = $definition['slug'];
            unset($definition['slug']);

            /* delay_seconds null = usar welcome_delay_seconds global. */
            $delay_seconds = $definition['delay_seconds'] ?? null;

            $variant = MessageVariant::firstOrCreate(
                ['slug' => $slug],
                $definition
            );

            if (! $variant->wasRecentlyCreated) {
                $variant->fill(['delay_seconds' => $delay_seconds])->save();
            }
        }
    }
}
