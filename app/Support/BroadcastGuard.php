<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Emisión aislada de eventos de broadcast.
 *
 * 🔴 POR QUÉ EXISTE, y por qué «simplificarlo» sacando el try/catch reintroduce un defecto
 * que ya llegó a producción (lead Juan, 2/9/2026):
 *
 * `LeadAiService::generate_suggestion()` persiste la sugerencia y **después** emite
 * `LeadSuggestionCreated`. El evento implementa `ShouldBroadcastNow`, así que Pusher se llama
 * en línea, dentro del mismo `try` del controlador. Cuando el payload superó los 10240 bytes,
 * la excepción de Pusher subió hasta el `catch` de `LeadController@request_ai_suggestion_json`
 * y la pantalla dijo «No se pudo generar la sugerencia» **sobre una sugerencia que existía,
 * estaba guardada y se veía en la misma pantalla**.
 *
 * La clase de error es esa, no el tamaño del payload: **un aviso no puede voltear una
 * operación que ya terminó**. Un broadcast es una notificación de conveniencia —si no llega,
 * el operador ve el dato igual al recargar, porque ya está en la base—; no es parte de la
 * transacción, y por lo tanto su falla se loguea y no se propaga.
 *
 * El guard vive acá, y los eventos lo enganchan sobreescribiendo su propio `dispatch()`, en
 * vez de repetirse en cada sitio de llamada. Así queda cubierto también el emisor que hoy no
 * se puede tocar, y sobre todo el que se escriba mañana: un `dispatch()` nuevo nace protegido
 * sin que nadie se acuerde de envolverlo.
 */
class BroadcastGuard
{
    /**
     * Emite el evento y se traga cualquier falla, dejándola registrada.
     *
     * @param object $evento Instancia del evento a emitir.
     *
     * @return void
     */
    public static function emitir($evento): void
    {
        try {
            event($evento);
        } catch (\Throwable $e) {
            Log::error('BroadcastGuard: falló el aviso y se sigue adelante.', [
                'evento' => get_class($evento),
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
