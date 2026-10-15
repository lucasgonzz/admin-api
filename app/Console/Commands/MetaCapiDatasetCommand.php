<?php

namespace App\Console\Commands;

use App\Services\MetaConversionesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Trae (o crea) el conjunto de datos de Meta asociado a la cuenta de WhatsApp Business, que es el
 * `META_CAPI_DATASET_ID` al que se le mandan las conversiones (misión
 * origen-del-lead-y-conversiones-meta, 13/10/2026). Existe para que nadie tenga que encontrar ese id
 * a mano en Events Manager.
 *
 *     php artisan meta:capi-dataset            # GET  /{waba_id}/dataset: el que ya existe
 *     php artisan meta:capi-dataset --crear    # POST /{waba_id}/dataset: lo crea si no hay
 *
 * Usa el mismo `META_CAPI_TOKEN`, que necesita los permisos `whatsapp_business_management` y
 * `whatsapp_business_manage_events`. El token va en el header `Authorization: Bearer`, nunca en la
 * URL, y no se imprime.
 */
class MetaCapiDatasetCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'meta:capi-dataset
        {--crear : Crea el conjunto de datos de la WABA si todavía no existe (POST en vez de GET)}';

    /**
     * @var string
     */
    protected $description = 'Muestra (o crea con --crear) el id del conjunto de datos de Meta de la cuenta de WhatsApp Business, para META_CAPI_DATASET_ID';

    /**
     * @param MetaConversionesService $service Solo para limpiar el token de los mensajes.
     *
     * @return int
     */
    public function handle(MetaConversionesService $service): int
    {
        $token = trim((string) config('services.meta_capi.token'));
        if ($token === '') {
            $this->error('Falta META_CAPI_TOKEN en el .env. Es un token de sistema de Meta con los permisos '
                . 'whatsapp_business_management y whatsapp_business_manage_events.');

            return 1;
        }

        $waba_id = trim((string) config('services.meta_capi.waba_id'));
        $version = trim((string) config('services.meta_capi.graph_version', 'v24.0'));
        $url     = MetaConversionesService::URL_GRAPH . '/' . $version . '/' . $waba_id . '/dataset';
        $crear   = (bool) $this->option('crear');

        try {
            $pedido = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('services.meta_capi.timeout', 15));
            $respuesta = $crear ? $pedido->post($url) : $pedido->get($url);
        } catch (\Throwable $exception) {
            $this->error('No se pudo conectar con Meta: ' . $service->sin_token($exception->getMessage()));

            return 1;
        }

        $json = $respuesta->json();
        $json = is_array($json) ? $json : [];

        if (! $respuesta->successful()) {
            $mensaje = isset($json['error']['message']) && is_string($json['error']['message'])
                ? $json['error']['message']
                : 'sin mensaje';
            $this->error('Meta contestó HTTP ' . $respuesta->status() . ': ' . $service->sin_token($mensaje));
            $this->line('Revisá que el token tenga whatsapp_business_management y whatsapp_business_manage_events, '
                . 'y que META_CAPI_WABA_ID (' . $waba_id . ') sea la cuenta de WhatsApp Business.');

            return 1;
        }

        /* POST devuelve {"id": ...}; GET, la lista en `data`. Se aceptan las dos formas. */
        $id = '';
        if (isset($json['id'])) {
            $id = (string) $json['id'];
        } elseif (isset($json['data'][0]['id'])) {
            $id = (string) $json['data'][0]['id'];
        }

        if ($id === '') {
            $this->warn('Meta no devolvió ningún conjunto de datos para la WABA ' . $waba_id . '.'
                . ($crear ? '' : ' Corré el comando con --crear para crearlo.'));
            $this->line($service->sin_token((string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

            return 1;
        }

        $this->info('Conjunto de datos de la WABA ' . $waba_id . ': ' . $id);
        $this->line('Cargalo en el .env como META_CAPI_DATASET_ID=' . $id);

        return 0;
    }
}
