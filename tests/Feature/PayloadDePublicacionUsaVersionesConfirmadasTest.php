<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Models\VersionNotification;
use App\Services\PublishVersionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `PublishVersionService::buildPayload()` lee la pivot `client_version_upgrade_versions`
 * (el conjunto YA CONFIRMADO por el admin), no un recálculo del rango por `id`.
 *
 * Dos cosas se prueban a la vez, porque sólo tienen sentido juntas:
 *
 *  - una notificación de una versión que NO está en la pivot (acá, un hotfix intermedio)
 *    nunca viaja en el payload, aunque exista y esté activa;
 *  - el `sort_order` que sí viaja respeta el orden SEMÁNTICO de las versiones
 *    confirmadas, no el orden de `id` — las versiones se cargan con `id` a propósito
 *    desordenado respecto al orden semántico para que un cálculo por `id` quede expuesto.
 *
 * Se mockea la llamada HTTP saliente (`Http::fake`, mismo patrón que
 * `DemoSetupCompletadoAvisadoPorLaInstanciaTest`) y se inspecciona el payload real que
 * viajaría a empresa-api vía `syncExisting()` — el flujo real de publicación.
 */
class PayloadDePublicacionUsaVersionesConfirmadasTest extends TestCase
{
    use DatabaseTransactions;

    private function crear_version(string $codigo, bool $is_hotfix = false): Version
    {
        $version                = new Version();
        $version->uuid          = (string) Str::uuid();
        $version->version       = $codigo;
        $version->title         = 'Versión ' . $codigo;
        $version->status        = 'published';
        $version->published_at  = now();
        $version->is_hotfix     = $is_hotfix;
        $version->save();

        return $version;
    }

    private function crear_notificacion(Version $version, string $titulo, int $sort_order): VersionNotification
    {
        $notif             = new VersionNotification();
        $notif->uuid       = (string) Str::uuid();
        $notif->version_id = $version->id;
        $notif->title      = $titulo;
        $notif->body       = 'Cuerpo de ' . $titulo;
        $notif->sort_order = $sort_order;
        $notif->is_active  = true;
        $notif->save();

        return $notif;
    }

    private function crear_cliente(): Client
    {
        $client                  = new Client();
        $client->name            = 'Cliente publicación';
        $client->slug            = 'cliente-publicacion-' . Str::random(8);
        $client->api_url         = 'https://cliente-publicacion-test.local';
        $client->api_key         = 'clave-api-publicacion';
        $client->inbound_api_key = 'clave-inbound-publicacion';
        $client->is_active       = true;
        $client->save();

        return $client;
    }

    /**
     * El caso completo: pivot = {3.7.1, 3.7.3}, hotfix intermedio 3.7.1.1 con
     * notificación activa que NO debe viajar, y `sort_order` que debe reflejar el orden
     * semántico (3.7.1 antes que 3.7.3) aunque el `id` de tabla diga lo contrario.
     *
     * @return void
     */
    public function test_el_payload_usa_la_pivot_y_el_sort_order_respeta_el_orden_semantico(): void
    {
        // Orden de inserción (y por lo tanto de `id`) deliberadamente al revés del orden
        // semántico del conjunto confirmado: la destino (3.7.3) se crea PRIMERO, y la
        // troncal anterior (3.7.1) se crea DESPUÉS. Si el cálculo de posición usara el
        // `id` en vez del orden semántico, los sort_order saldrían invertidos.
        $to               = $this->crear_version('3.7.3');
        $hotfix_intermedio = $this->crear_version('3.7.1.1', true);
        $from             = $this->crear_version('3.7.1');

        $this->assertLessThan($from->id, $to->id, 'La base del test no quedó desordenada como se esperaba.');

        // Notificación en el hotfix NO confirmado: no debe viajar nunca.
        $this->crear_notificacion($hotfix_intermedio, 'Notificación del hotfix (no confirmado)', 5);

        // Notificaciones en las dos versiones SÍ confirmadas.
        $notif_from = $this->crear_notificacion($from, 'Notificación de 3.7.1', 10);
        $notif_to   = $this->crear_notificacion($to, 'Notificación de 3.7.3', 20);

        $client = $this->crear_cliente();

        $upgrade = ClientVersionUpgrade::create([
            'client_id'       => $client->id,
            'from_version_id' => $from->id,
            'to_version_id'   => $to->id,
            'status'          => 'pendiente',
            'notes'           => null,
        ]);

        // Pivot: sólo 3.7.1 y 3.7.3 quedaron confirmadas por el admin. El hotfix 3.7.1.1
        // existe en la base pero nunca se tildó.
        $upgrade->confirmed_versions()->sync([$from->id, $to->id]);

        Http::fake();

        $resultado = (new PublishVersionService())->syncExisting($upgrade->fresh());

        $this->assertSame('terminada', $resultado->status, 'La sincronización debía terminar en éxito con el fake de HTTP.');

        Http::assertSent(function ($request) use ($notif_from, $notif_to) {
            $payload = $request->data();

            $notifications = collect($payload['notifications']);

            // El hotfix no confirmado no viaja, aunque su notificación esté activa.
            if ($notifications->contains('title', 'Notificación del hotfix (no confirmado)')) {
                return false;
            }

            if ($notifications->count() !== 2) {
                return false;
            }

            $por_titulo = $notifications->keyBy('title');

            // Posición 1-based en el orden SEMÁNTICO del conjunto confirmado: 3.7.1 es la
            // posición 1 (multiplicador 1000 + sort_order local 10 = 1010) y 3.7.3 es la
            // posición 2 (2000 + 20 = 2020) — pase lo que pase con el `id` de tabla.
            $sort_order_from = $por_titulo['Notificación de 3.7.1']['sort_order'] ?? null;
            $sort_order_to   = $por_titulo['Notificación de 3.7.3']['sort_order'] ?? null;

            return $sort_order_from === 1010 && $sort_order_to === 2020;
        });
    }
}
