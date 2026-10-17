<?php

namespace Database\Seeders;

use App\Models\MessageVariant;
use App\Services\LeadWhatsappOnboardingService;
use Illuminate\Database\Seeder;

/**
 * Siembra las tres variantes de la presentación de Martín para el lead que llega con la FRASE DE UN
 * BOTÓN (misión flujo-lead-landing-closer, 17/10/2026): la landing, la landing vieja o Instagram.
 *
 * Ese lead ya dijo "quiero hacer la demo" apretando un botón: la presentación genérica (que le
 * pregunta a qué se dedica como si recién llegara a mirar) no le cierra. Las tres variantes
 * reconocen el pedido y arrancan la calificación mínima de distinta manera, y se cargan ACTIVAS para
 * repartirse al azar (D2 del plan): se mide cuál arranca mejor, no se adivina. Lucas apaga la que
 * quiera desde la sección del agente.
 *
 * Sin `{nombre}`: el lead de la landing casi nunca llega con nombre de perfil, y el saludo con
 * nombre se pide después, como siempre.
 *
 * Idempotente por slug: si una variante ya existe NO se le pisa `body` ni `active` (puede estar
 * editada o apagada a mano), solo se asegura `message_type` y `delay_seconds` null (la demora la da
 * la setting landing, ver LeadWhatsappOnboardingSettings::KEY_WELCOME_DELAY_SECONDS_LANDING).
 */
class MessageVariantLandingSeeder extends Seeder
{
    /**
     * Definición de las tres variantes (plan §5).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function variant_definitions(): array
    {
        return [
            [
                'slug'          => 'landing_demo_primero',
                'name'          => 'Landing A — Confirmar la demo primero',
                'message_type'  => LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING,
                'body'          => 'Hola, soy Martín, del equipo de ComercioCity. Dale, te preparo la demo... Para dejártela armada como tu negocio, contame: ¿a qué se dedica y cuántas personas trabajan con vos?',
                'active'        => true,
                'delay_seconds' => null,
                'notes'         => 'Hipótesis: confirmar la demo primero baja la fricción; la pregunta compuesta ya probó ~46% en el control.',
            ],
            [
                'slug'          => 'landing_una_pregunta',
                'name'          => 'Landing B — Una sola pregunta',
                'message_type'  => LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING,
                'body'          => 'Hola, soy Martín, del equipo de ComercioCity. Dale, te armo la demo. Antes, una sola pregunta: ¿qué tipo de negocio tenés?',
                'active'        => true,
                'delay_seconds' => null,
                'notes'         => 'Hipótesis: la pregunta corta de negocio es la que más respuesta tuvo (79%, medido el 5/10/2026).',
            ],
            [
                'slug'          => 'landing_horario_primero',
                'name'          => 'Landing C — Fijar el momento primero',
                'message_type'  => LeadWhatsappOnboardingService::VARIANT_TYPE_WELCOME_LANDING,
                'body'          => 'Hola, soy Martín, del equipo de ComercioCity. Dale, te la preparo: es una versión completa del sistema para que la pruebes vos mismo, con calma. ¿La querés hacer ahora o te queda mejor en otro momento? Mientras, contame qué tipo de negocio tenés.',
                'active'        => true,
                'delay_seconds' => null,
                'notes'         => 'Hipótesis de Lucas: fijar el momento ya y calificar mientras.',
            ],
        ];
    }

    /**
     * Inserta las tres variantes si no existen (idempotente por slug). Las existentes conservan
     * `body` y `active`.
     *
     * @return void
     */
    public function run()
    {
        foreach (self::variant_definitions() as $definition) {
            $slug = $definition['slug'];
            unset($definition['slug']);

            $variant = MessageVariant::firstOrCreate(['slug' => $slug], $definition);

            if (! $variant->wasRecentlyCreated) {
                /* Solo las dos claves técnicas: el texto y el interruptor son de Lucas una vez sembrados. */
                $variant->fill([
                    'message_type'  => $definition['message_type'],
                    'delay_seconds' => null,
                ])->save();
            }
        }
    }
}
