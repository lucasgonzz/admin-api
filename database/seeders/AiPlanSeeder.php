<?php

namespace Database\Seeders;

use App\Models\AiPlan;
use Illuminate\Database\Seeder;

/**
 * Los tres paquetes de IA del piloto (misión foto-sucursal-y-asistente-configurable, 17/9/2026).
 *
 * 🔴 **Los números son TENTATIVOS y editables.** Lucas los va a ajustar cuando el piloto arroje
 * cuánto gasta de verdad un comercio. Por eso se usa `firstOrCreate` por nombre y NO
 * `updateOrCreate`: una vez que el paquete existe, este seeder no vuelve a pisar el precio ni los
 * topes: la edición del ABM manda, no una re-corrida del seeder en el próximo deploy.
 *
 * Los topes de tokens son estimaciones gruesas: se parte de las interacciones/día del paquete,
 * suponiendo ~30.000 tokens por interacción del asistente (input + output + caché de un loop de
 * tool use con contexto), por 30 días. Es un piso para arrancar, no una medición.
 *
 * El tope de búsquedas web diarias (misión asistente-fotos-barras-y-compras, 24/9/2026) es 30 en los
 * tres: es decisión de Lucas, no una estimación. En los paquetes que ya existían lo pone el default
 * de la columna, porque este seeder no vuelve a tocar un paquete existente.
 *
 *   Básico       ~20 interacciones/día  → ~18M tokens/mes → se redondea a 20.000.000.
 *   Intermedio   ~50 interacciones/día  → ~45M tokens/mes → se redondea a 60.000.000.
 *   Pro          ~150 interacciones/día → ~135M tokens/mes → se redondea a 200.000.000.
 */
class AiPlanSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        $paquetes = [
            [
                'nombre'                     => 'Básico',
                'precio_usd'                 => 100,
                // ~20 interacciones/día. Tope de tokens estimado (tentativo, ver el docblock).
                'tope_interacciones_diarias' => 20,
                'tope_tokens_mensual'        => 20000000,
                // Búsquedas por código de barras con búsqueda web por día (decisión de Lucas 24/9/2026).
                'tope_busquedas_web_diarias' => 30,
                'orden'                      => 1,
            ],
            [
                'nombre'                     => 'Intermedio',
                'precio_usd'                 => 200,
                'tope_interacciones_diarias' => 50,
                'tope_tokens_mensual'        => 60000000,
                'tope_busquedas_web_diarias' => 30,
                'orden'                      => 2,
            ],
            [
                'nombre'                     => 'Pro',
                'precio_usd'                 => 400,
                'tope_interacciones_diarias' => 150,
                'tope_tokens_mensual'        => 200000000,
                'tope_busquedas_web_diarias' => 30,
                'orden'                      => 3,
            ],
        ];

        foreach ($paquetes as $paquete) {
            /* firstOrCreate y no updateOrCreate: si el paquete ya existe, se respeta lo que Lucas
             * haya editado en el ABM. El seeder solo garantiza que los tres existan. */
            AiPlan::firstOrCreate(
                ['nombre' => $paquete['nombre']],
                [
                    'precio_usd'                 => $paquete['precio_usd'],
                    'tope_tokens_mensual'        => $paquete['tope_tokens_mensual'],
                    'tope_interacciones_diarias' => $paquete['tope_interacciones_diarias'],
                    'tope_busquedas_web_diarias' => $paquete['tope_busquedas_web_diarias'],
                    'activo'                     => true,
                    'orden'                      => $paquete['orden'],
                ]
            );
        }
    }
}
