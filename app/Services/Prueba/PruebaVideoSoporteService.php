<?php

namespace App\Services\Prueba;

use App\Models\Client;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use App\Models\TrialInstance;
use Illuminate\Support\Facades\Log;

/**
 * Los videos de la prueba gratuita en el ticket de Soporte (misión cruzada prueba-ayuda-unificada-en-soporte,
 * 10/11/2026). Lucas decidió que el recorrido de videos va en el ticket de Soporte: el admin pone el primero (el
 * 04, bienvenida y recorrido) sin correr Codex, y Martín manda los siguientes según los hitos.
 *
 * Dos cosas:
 *
 *  1. **`prueba.videos` en el contexto de Martín (C1).** Se registra como extensión de
 *     `PruebaContextoSoporteService` (sin tocar ese archivo) y suma la lista del catálogo aprobado
 *     (`PruebaVideoCatalogoService::videos()`) con `{codigo, titulo, url, hito, entregado_at}`. El hito es fijo
 *     ({@see self::HITOS}) y `entregado_at` sale del registro de envíos (`prueba_whatsapp_envios`, plantilla
 *     `video_<codigo>`). Clave opcional: un Martín viejo la ignora y sigue con `recursos`.
 *
 *  2. **El video 04 sin Codex (C2).** {@see self::entregar_bienvenida()} lo manda por el ticket «Prueba gratuita»
 *     con `PruebaWhatsappService::mandar_texto_por_ticket()`, una sola vez por cliente.
 *
 * 🔴 Por qué no hay un «mensaje solo para el sistema» para la ventana cerrada: se investigó y el ticket no lo
 * tiene. `support_messages` no tiene ninguna marca por mensaje que lo deje fuera de WhatsApp; lo único parecido es
 * `support_tickets.sistema_exclusivo_at`, que es del TICKET entero (lo pone el traspaso del lead desde su sistema)
 * y no de un mensaje. Un mensaje de admin sin wamid en un ticket de WhatsApp tampoco lo ve el sistema del cliente
 * (`InboundAgentesController::filtrar_mensajes_visibles()` pide wamid o `synced_to_client_at`). Así que vale la
 * regla del plan: con la ventana cerrada no sale nada, y el video sale apenas el lead escriba, ANTES de que
 * Martín tome el ticket (el webhook lo intenta al recibir el entrante y `SupportCodexService::claim()` lo vuelve
 * a intentar antes de armar el contexto). No se inventa una columna nueva.
 *
 * 🔴 El mensaje sale con `is_trial_import_notice` (lo pone `mandar_texto_por_ticket()` cuando recibe el ticket):
 * es la marca que ya existe para «aviso del equipo que queda en el hilo pero no tapa el entrante que espera
 * respuesta» (`SupportCodexService::last_real_message()`). Sin ella, el video mandado después de la pregunta del
 * lead dejaría el ticket como respondido y Martín no la contestaría nunca.
 *
 * Idempotente: el candado por cliente serializa el webhook, la toma de Martín y la bienvenida, y un envío
 * `enviado` (o `fallido`) de `video_04` corta todo reintento. Nunca se manda texto libre con la ventana cerrada:
 * esa guarda la hace `mandar_texto_por_ticket()`.
 */
class PruebaVideoSoporteService
{
    /** El hito de la prueba que acompaña cada video (fijo, del plan de la misión). */
    const HITOS = [
        '04' => 'ingreso',
        '05' => 'importo',
        '06' => 'articulo_creado',
        '07' => 'compra',
        '08' => 'venta',
        '09' => 'agente',
    ];

    /** Prefijo de `prueba_whatsapp_envios.plantilla` para los videos entregados por el ticket. */
    const PLANTILLA_PREFIJO = 'video_';

    /** @var bool Si la extensión ya quedó registrada en este proceso. */
    protected static $registrada = false;

    /**
     * Registra la extensión `videos` del bloque `prueba` (una vez por proceso; `$forzar` para los tests).
     *
     * @param bool $forzar
     *
     * @return void
     */
    public static function registrar(bool $forzar = false): void
    {
        if (self::$registrada && ! $forzar) {
            return;
        }

        PruebaContextoSoporteService::extender(function (array $base, Client $client, ?Lead $lead, ?TrialInstance $instancia) {
            return app(PruebaVideoSoporteService::class)->extension($client);
        });

        self::$registrada = true;
    }

    /**
     * `plantilla` del registro de envíos para un video.
     *
     * @param string $codigo
     *
     * @return string
     */
    public static function plantilla(string $codigo): string
    {
        return self::PLANTILLA_PREFIJO . $codigo;
    }

    /**
     * Lo que se le suma al bloque `prueba`. Nunca lanza: ante un error no suma la clave (Martín sigue con `recursos`).
     *
     * @param Client $client
     *
     * @return array<string, mixed>
     */
    public function extension(Client $client): array
    {
        try {
            return ['videos' => $this->videos_del_cliente($client)];
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaVideoSoporteService: no se pudieron armar los videos del contexto.', [
                'client_id' => (int) $client->id,
                'error'     => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Los videos aprobados con su hito y cuándo se le entregaron a este cliente por el ticket (o null).
     *
     * @param Client $client
     *
     * @return array<int, array{codigo: string, titulo: string, url: string, hito: string|null, entregado_at: string|null}>
     */
    public function videos_del_cliente(Client $client): array
    {
        $entregas = PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            ->where('plantilla', 'like', self::PLANTILLA_PREFIJO . '%')
            ->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)
            ->orderBy('id')
            ->get();

        $videos = [];
        foreach (app(PruebaVideoCatalogoService::class)->videos() as $video) {
            $codigo  = (string) $video['codigo'];
            $entrega = $entregas->first(function (PruebaWhatsappEnvio $envio) use ($codigo) {
                return $envio->plantilla === self::plantilla($codigo);
            });

            $videos[] = [
                'codigo'       => $codigo,
                'titulo'       => (string) $video['titulo'],
                'url'          => (string) $video['url'],
                'hito'         => isset(self::HITOS[$codigo]) ? self::HITOS[$codigo] : null,
                'entregado_at' => $entrega !== null && $entrega->enviado_at !== null ? $entrega->enviado_at->toIso8601String() : null,
            ];
        }

        return $videos;
    }
}
