<?php

namespace App\Services\Prueba;

use App\Mail\ComercioCityMail;
use App\Mail\ComercioCityMailPayload;
use App\Mail\Helpers\RechazosDeCorreoHelper;
use App\Models\Client;
use App\Models\TrialInstance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PruebaCorreoService
{
    /** Se llama bajo el mismo lock de la bienvenida. */
    public function enviar(Client $client, TrialInstance $instancia, array $asistente): void
    {
        $email = trim((string) $client->email);
        if ($instancia->correo_bienvenida_at !== null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $hosts = PruebaNombres::hosts_del_slug((string) $instancia->alias_slug);
        $mail = new ComercioCityMail(new ComercioCityMailPayload([
            'subject' => 'Tu prueba de ComercioCity ya está lista',
            'title' => 'Tu prueba de siete días ya está lista',
            'paragraphs' => [
                'Tu sistema está listo para que lo pruebes con datos de ejemplo.',
                'Tu asistente personal te atiende por WhatsApp en el ' . $asistente['numero'] . '. ' . PruebaAsistenteService::USO,
            ],
            'cta' => ['text' => 'Entrar a mi sistema', 'url' => 'https://' . $hosts['erp_spa']],
            'links' => [['text' => 'Escribirle a mi asistente personal por WhatsApp', 'url' => $asistente['url']]],
            'closing' => 'Equipo de ComercioCity',
        ]));

        try {
            Mail::to($email)->send($mail);
            RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
            $instancia->correo_bienvenida_at = now();
            $instancia->save();
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar el envío del correo de bienvenida de la prueba.', [
                'client_id' => (int) $client->id,
                'tipo' => get_class($e),
            ]);
        }
    }
}
