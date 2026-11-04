<?php

namespace App\Jobs;

use App\Services\IaApp\IaImagenesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

class GenerarImagenOpenAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    const MODELO = 'gpt-image-2';

    const COLA = 'ia-imagenes';

    public $tries = 1;

    public $timeout = 150;

    private $pedido_id;

    public function __construct(int $pedido_id)
    {
        $this->pedido_id = $pedido_id;
    }

    public function handle(IaImagenesService $imagenes): void
    {
        $pedido = $imagenes->tomar_para_openai($this->pedido_id);
        if ($pedido === null) {
            return;
        }

        if (! $imagenes->openai_disponible()) {
            $imagenes->finalizar_openai($pedido->id, null, null, 'La generación por API no está habilitada.');
            return;
        }

        $tamano = ['cuadrada' => '1024x1024', 'vertical' => '1024x1536', 'horizontal' => '1536x1024'];
        $datos = [
            'model' => self::MODELO,
            'prompt' => $pedido->estilo === null ? $pedido->prompt : $pedido->prompt . "\nEstilo: " . $pedido->estilo,
            'quality' => $pedido->calidad,
            'size' => $tamano[$pedido->tamano],
            'n' => 1,
            'output_format' => 'webp',
            'safety_identifier' => hash('sha256', 'comerciocity:imagen:' . (int) $pedido->client_id),
        ];

        $http = Http::withToken(trim((string) config('ia_app.imagenes.openai_api_key')))
            ->withHeaders(['X-Client-Request-Id' => $pedido->uuid])
            ->acceptJson()->withOptions(['connect_timeout' => 10])->timeout(125);

        try {
            if ($pedido->referencia_base64 === null) {
                $respuesta = $http->post('https://api.openai.com/v1/images/generations', $datos);
            } else {
                $binario = base64_decode($pedido->referencia_base64, true);
                $mime = (string) (@getimagesizefromstring($binario)['mime'] ?? '');
                $ext = IaImagenesService::EXTENSIONES[$mime] ?? null;
                if ($ext === null) {
                    $imagenes->finalizar_openai($pedido->id, null, null, 'La referencia guardada es inválida.');
                    return;
                }

                $respuesta = $http->attach('image[]', $binario, 'referencia.' . $ext, ['Content-Type' => $mime])
                    ->post('https://api.openai.com/v1/images/edits', $datos);
            }
        } catch (ConnectionException $e) {
            $imagenes->finalizar_openai($pedido->id, null, null, 'No se pudo confirmar el resultado de OpenAI.', true);
            return;
        }

        if (! $respuesta->successful()) {
            $codigo = $respuesta->status();
            $incierto = $codigo >= 500 || in_array($codigo, [408, 409], true);
            $imagenes->finalizar_openai($pedido->id, null, null, 'OpenAI respondió HTTP ' . $codigo . '.', $incierto);
            return;
        }

        $uso = $respuesta->json('usage');
        $uso = is_array($uso) ? $uso : null;
        $salida = $respuesta->json('data.0.b64_json');
        $imagen = is_string($salida) ? base64_decode($salida, true) : false;
        if ($imagen === false || $imagen === '') {
            $imagenes->finalizar_openai($pedido->id, null, $uso, 'OpenAI no devolvió una imagen válida.', true);
            return;
        }

        $imagenes->finalizar_openai($pedido->id, $imagen, $uso, null);
    }
}
