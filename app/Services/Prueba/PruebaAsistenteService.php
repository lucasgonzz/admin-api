<?php

namespace App\Services\Prueba;

use App\Helpers\WhatsappNormalizer;
use App\Models\Client;
use App\Models\Lead;
use App\Models\TrialInstance;
use App\Models\WhatsappConfig;
use App\Services\AsistenteDemoLeadService;
use App\Services\AsistenteWhatsappService;
use App\Services\ClientEmpresaApiUrlResolver;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class PruebaAsistenteService
{
    public const RUTA_DISPONIBILIDAD = 'api/admin-sync/asistente/disponibilidad';

    public const USO = 'Podés hacerle preguntas de tu negocio, como cuánto vendiste hoy, o mandarle una foto de una factura de proveedor para que prepare la compra y la revises en el sistema antes de cargarla.';

    public static function instancia_vigente(Client $client): ?TrialInstance
    {
        if ((string) $client->estado !== PruebaCicloService::CLIENTE_PRUEBA || ! $client->is_active
            || $client->trial_ends_at === null || ! Carbon::parse($client->trial_ends_at)->isFuture()) {
            return null;
        }

        $instancia = PruebaCicloService::instancia_de($client);

        return $instancia !== null && $instancia->estado === TrialInstance::ESTADO_ASIGNADA
            && ! $instancia->esta_dormida() ? $instancia : null;
    }

    /** El canal de la prueba habilita el chat del dueño sin inscribirlo en avisos programados. */
    public static function puede_atender(Client $client, ?string $telefono = null): bool
    {
        if (in_array((string) $client->estado, [PruebaCicloService::CLIENTE_PRUEBA_VENCIDA, PruebaCicloService::CLIENTE_PRUEBA_BORRADA], true)) {
            return false;
        }

        if ((string) $client->estado !== PruebaCicloService::CLIENTE_PRUEBA) {
            return (bool) $client->asistente_whatsapp_activo;
        }

        $instancia = self::instancia_vigente($client);

        return $instancia !== null
            && ($instancia->asistente_listo_at !== null || (bool) $client->asistente_whatsapp_activo)
            && ($telefono === null || WhatsappNormalizer::equals_estricto((string) $client->telefono_personal(), $telefono));
    }

    /** @return array{numero: string, url: string}|null */
    public function habilitar(Client $client, Lead $lead, TrialInstance $instancia): ?array
    {
        $canal = WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_ASISTENTE);
        $telefono = (string) $client->telefono_personal();
        if ($canal === null || $canal->test_mode || ! AsistenteDemoLeadService::telefono_argentino($telefono)
            || $instancia->lead_id !== (int) $lead->id || $instancia->client_id !== (int) $client->id
            || (int) $lead->promoted_client_id !== (int) $client->id || $lead->demo_setup_status !== 'exitoso') {
            return null;
        }

        $dueno = app(AsistenteWhatsappService::class)->dueno_por_telefono_exacto($telefono, true);
        if ($dueno === null || (int) $dueno->id !== (int) $client->id) {
            return null;
        }

        $url = app(ClientEmpresaApiUrlResolver::class)->admin_sync_url($client, self::RUTA_DISPONIBILIDAD);
        $clave = trim((string) $client->api_key);
        if ($url === '' || $clave === '' || (int) $client->user_id <= 0 || $instancia->user_id !== (int) $client->user_id) {
            return null;
        }

        try {
            $respuesta = Http::acceptJson()->withHeaders(['X-Admin-Api-Key' => $clave])
                ->timeout(4)->withOptions(['allow_redirects' => false])->get($url);
        } catch (ConnectionException $e) {
            return null;
        }

        if ($respuesta->status() !== 200 || $respuesta->json('disponible') !== true
            || $respuesta->json('user_id') !== (int) $client->user_id) {
            return null;
        }

        $numero = trim((string) $canal->display_phone);
        $destino = (string) preg_replace('/\D+/', '', $numero);
        if (! preg_match('/^[1-9]\d{7,14}$/D', $destino)) {
            return null;
        }

        if ($instancia->asistente_listo_at === null) {
            $instancia->asistente_listo_at = now();
            $instancia->save();
        }

        return ['numero' => $numero, 'url' => 'https://wa.me/' . $destino];
    }
}
