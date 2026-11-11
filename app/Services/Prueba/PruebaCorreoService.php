<?php

namespace App\Services\Prueba;

use App\Mail\ComercioCityMail;
use App\Mail\ComercioCityMailPayload;
use App\Mail\Helpers\RechazosDeCorreoHelper;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use App\Models\TrialInstance;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PruebaCorreoService
{
    /** Prefijo de `prueba_whatsapp_envios.plantilla` para los correos (`canal = correo`). */
    const PREFIJO = 'correo_';

    /** Cuántas veces se intenta un mismo correo si el servidor lo rechaza. */
    const MAX_INTENTOS = 3;

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

    /**
     * Manda un correo de la prueba al dueño y lo deja registrado en `prueba_whatsapp_envios` con
     * `canal = correo` (misión prueba-e2e-campana-imperio, 11/11/2026). Uno solo por `$clave` y por
     * cliente: el registro va ANTES del envío, en `enviando`, igual que el video 04; un `enviado` o un
     * `enviando` cortan todo reintento, y un rechazo se reintenta hasta {@see self::MAX_INTENTOS} veces.
     *
     * @param Client                  $client
     * @param Lead|null               $lead
     * @param string                  $clave   Qué correo es (`fin_encuesta`, `dia_5`, `paso_venta`…).
     * @param ComercioCityMailPayload $payload
     * @param string|null             $hito    El hito que acompaña, si lo hay.
     *
     * @return array{estado: string, detalle: string|null}
     *   `estado`: `enviado`, `ya_enviado`, `sin_correo`, `en_curso` o `fallido`.
     */
    public function enviar_registrado(Client $client, ?Lead $lead, string $clave, ComercioCityMailPayload $payload, ?string $hito = null): array
    {
        $email = trim((string) $client->email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['estado' => 'sin_correo', 'detalle' => 'El cliente no tiene un correo válido en la ficha.'];
        }

        $plantilla = self::PREFIJO . $clave;
        $reserva   = Cache::lock('prueba-correo-' . $client->id . '-' . $clave, 60);
        if (! $reserva->get()) {
            return ['estado' => 'en_curso', 'detalle' => null];
        }

        try {
            $previos = PruebaWhatsappEnvio::query()
                ->where('client_id', (int) $client->id)
                ->where('canal', PruebaWhatsappEnvio::CANAL_CORREO)
                ->where('plantilla', $plantilla)
                ->pluck('estado');

            if ($previos->contains(PruebaWhatsappEnvio::ESTADO_ENVIADO) || $previos->contains(PruebaWhatsappEnvio::ESTADO_ENVIANDO)) {
                return ['estado' => 'ya_enviado', 'detalle' => null];
            }

            if ($previos->filter(function ($estado) {
                return $estado === PruebaWhatsappEnvio::ESTADO_FALLIDO;
            })->count() >= self::MAX_INTENTOS) {
                return ['estado' => 'fallido', 'detalle' => 'Se agotaron los intentos de este correo.'];
            }

            $registro = PruebaWhatsappEnvio::create([
                'client_id' => (int) $client->id,
                'lead_id'   => $lead !== null ? (int) $lead->id : null,
                'plantilla' => $plantilla,
                'canal'     => PruebaWhatsappEnvio::CANAL_CORREO,
                'hito'      => $hito,
                'estado'    => PruebaWhatsappEnvio::ESTADO_ENVIANDO,
                'dia'       => PruebaWhatsappService::hoy(),
            ]);

            try {
                Mail::to($email)->send(new ComercioCityMail($payload));
                RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
            } catch (\Throwable $e) {
                $registro->estado  = PruebaWhatsappEnvio::ESTADO_FALLIDO;
                $registro->detalle = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 1000);
                $registro->save();

                Log::warning('PruebaCorreoService: no salió un correo de la prueba.', [
                    'client_id' => (int) $client->id,
                    'correo'    => $clave,
                    'tipo'      => get_class($e),
                ]);

                return ['estado' => 'fallido', 'detalle' => get_class($e)];
            }

            $registro->estado     = PruebaWhatsappEnvio::ESTADO_ENVIADO;
            $registro->enviado_at = now();
            $registro->save();

            return ['estado' => 'enviado', 'detalle' => null];
        } finally {
            $reserva->release();
        }
    }

    /**
     * «Hola Marcela» (o «Hola» a secas): el saludo de los correos, con el primer nombre del lead o de la ficha.
     *
     * @param Client    $client
     * @param Lead|null $lead
     *
     * @return string
     */
    public static function saludo(Client $client, ?Lead $lead): string
    {
        foreach ([$lead !== null ? (string) $lead->contact_name : '', (string) $client->name] as $candidato) {
            $primero = Lead::primer_nombre_de($candidato);
            if ($primero !== null && trim($primero) !== '') {
                return 'Hola ' . trim($primero);
            }
        }

        return 'Hola';
    }

    /**
     * El botón «Entrar a mi sistema» de los correos de la prueba, o null si el cliente no tiene instancia.
     *
     * @param Client $client
     *
     * @return array{text: string, url: string}|null
     */
    public static function boton_del_sistema(Client $client): ?array
    {
        $instancia = PruebaCicloService::instancia_de($client);
        if ($instancia === null || trim((string) $instancia->alias_slug) === '') {
            return null;
        }

        $hosts = PruebaNombres::hosts_del_slug((string) $instancia->alias_slug);

        return ['text' => 'Entrar a mi sistema', 'url' => 'https://' . $hosts['erp_spa']];
    }
}
