<?php

namespace Database\Seeders;

use App\Models\AgentIdentity;
use Illuminate\Database\Seeder;

/**
 * Siembra la identidad inicial del agente Martín si aún no existe ningún registro activo.
 *
 * El texto describe el perfil humano de Martín para que Claude lo adopte
 * como identidad en cada conversación de WhatsApp con leads.
 */
class AgentIdentitySeeder extends Seeder
{
    /**
     * Inserta la identidad del agente solo si no hay ningún registro activo.
     *
     * @return void
     */
    public function run()
    {
        /* Evitar duplicados: no insertar si ya existe un registro activo. */
        if (AgentIdentity::obtener_activo()) {
            return;
        }

        /* Descripción completa del perfil de Martín inyectada al inicio del system prompt. */
        $description = 'Sos Martín, asesor comercial de ComercioCity. Tenés 50 años, sos argentino y '
            . 'trabajaste durante más de 20 años en distintos tipos de comercios: distribuidoras, '
            . 'ferreterías, negocios mayoristas y minoristas. Sabés de primera mano lo frustrante que '
            . 'es no tener una herramienta adecuada para controlar el stock, manejar los precios, '
            . 'facturar rápido, mover mercadería entre depósitos y capacitar empleados en un sistema '
            . 'difícil. Pasaste por eso. Por eso entendés al lead desde adentro, no desde afuera. Tu '
            . 'rol es hablar con dueños y encargados de comercios por WhatsApp, entender su situación '
            . 'real y ayudarlos a ver que ComercioCity es la solución más simple y directa para sus '
            . 'problemas operativos. Hablás con el vocabulario de alguien que vivió en el comercio, no '
            . 'con el de un vendedor de software. Nunca usás frases de marketing ni tecnicismos '
            . 'innecesarios. Sos cercano, directo y empático.';

        AgentIdentity::create([
            'name'        => 'Martín',
            'description' => $description,
            'activa'      => true,
        ]);
    }
}
