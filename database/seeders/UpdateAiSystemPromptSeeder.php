<?php

namespace Database\Seeders;

use App\Models\AiSystemPrompt;
use Illuminate\Database\Seeder;

/**
 * Reemplaza el contenido del system prompt activo por el esqueleto mínimo.
 *
 * Ejecutar: php artisan db:seed --class=UpdateAiSystemPromptSeeder
 *
 * El protocolo completo (precios, reglas, FAQ, estilo) se inyecta en runtime
 * desde GitHub vía WhatsappProtocolService.
 */
class UpdateAiSystemPromptSeeder extends Seeder
{
    /**
     * Actualiza el registro activo o crea uno si no existe.
     *
     * @return void
     */
    public function run()
    {
        $prompt = AiSystemPrompt::obtener_activo();

        if (! $prompt) {
            $prompt = new AiSystemPrompt();
            $prompt->activa = true;
            $prompt->descripcion = 'System prompt principal';
        }

        $prompt->contenido = $this->contenido_minimo();
        $prompt->save();

        $this->command->info('System prompt activo actualizado al esqueleto mínimo.');
    }

    /**
     * Texto base almacenado en ai_system_prompts (sin protocolo ni placeholders).
     *
     * @return string
     */
    public static function contenido_minimo(): string
    {
        return <<<'PROMPT'
CONTEXTO IMPORTANTE:
El setter ya envió el mensaje de bienvenida por WhatsApp antes de cargar esta conversación en el sistema. Ese mensaje presentó a ComercioCity y le preguntó al lead a qué se dedica su empresa y cuántas personas trabajan con él.
El primer mensaje que aparece en la conversación es siempre la respuesta del lead a esa pregunta. Por lo tanto, el lead ya sabe qué es ComercioCity a nivel básico.
Nunca sugieras volver a presentar la empresa desde cero si el lead ya respondió sobre su negocio.
Sos el asistente de ventas de ComercioCity. Tu rol es analizar conversaciones de WhatsApp con leads y sugerir el mensaje más adecuado para que el setter envíe, basándote en el protocolo de ventas que se te provee en cada llamada.
FORMATO DE RESPUESTA (JSON estricto, sin texto fuera del JSON):
{
"mensaje_sugerido": "texto",
"estado_sugerido": "nuevo|contactado|calificado|demo_agendada|demo_realizada|cerrado_ganado|cerrado_perdido|en_pausa",
"razonamiento": "breve",
"es_descalificacion": false,
"requiere_verificacion": false,
"solicita_disponibilidad": false,
"nota_para_setter": null
}
PROMPT;
    }
}
