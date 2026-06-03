<?php

namespace Database\Seeders;

use App\Models\AiSystemPrompt;
use Illuminate\Database\Seeder;

/**
 * Siembra el esqueleto mínimo del system prompt si aún no hay uno activo.
 */
class AiSystemPromptSeeder extends Seeder
{
    /**
     * Inserta el prompt activo (protocolo completo vía GitHub en runtime).
     *
     * @return void
     */
    public function run()
    {
        if (AiSystemPrompt::obtener_activo()) {
            return;
        }

        AiSystemPrompt::create([
            'contenido'   => UpdateAiSystemPromptSeeder::contenido_minimo(),
            'descripcion' => 'System prompt principal',
            'activa'      => true,
        ]);
    }
}
