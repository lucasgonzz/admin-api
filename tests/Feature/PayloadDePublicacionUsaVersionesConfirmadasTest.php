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
 *  - el `sort_order` que sí viaja se calcula con la fórmula HISTÓRICA
 *    (`globalNotificationSortOrder`: `version_id * 1000 + sort_order local`), porque el
 *    valor de ese campo es contrato con empresa-api y tiene que seguir siendo monótono
 *    ENTRE actualizaciones sucesivas del mismo cliente. La variante posicional quedó
 *    definida pero sin uso (ver `VersionPathService::positionalNotificationSortOrder`).
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
     * notificación activa que NO debe viajar, y `sort_order` calculado con la fórmula
     * histórica (`version_id * 1000 + sort_order local`).
     *
     * @return void
     */
    public function test_el_payload_usa_la_pivot_y_el_sort_order_usa_la_formula_historica(): void
    {
        $to               = $this->crear_version('3.7.3');
        $hotfix_intermedio = $this->crear_version('3.7.1.1', true);
        $from             = $this->crear_version('3.7.1');

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

        // Fórmula histórica: `version_id * 1000 + sort_order local`. Es única y monótona a
        // lo largo del tiempo para un cliente (los `id` de versión no se repiten), que es
        // lo que el contrato con empresa-api necesita entre actualizaciones sucesivas.
        $sort_order_esperado_from = $from->id * 1000 + 10;
        $sort_order_esperado_to   = $to->id * 1000 + 20;

        Http::assertSent(function ($request) use ($sort_order_esperado_from, $sort_order_esperado_to) {
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

            $sort_order_from = $por_titulo['Notificación de 3.7.1']['sort_order'] ?? null;
            $sort_order_to   = $por_titulo['Notificación de 3.7.3']['sort_order'] ?? null;

            return $sort_order_from === $sort_order_esperado_from
                && $sort_order_to === $sort_order_esperado_to;
        });
    }

    /**
     * `publish()` (publicación directa desde la vista de una versión, sin paso de
     * confirmación humana) tiene que llenar la pivot con TODO el rango calculado. Si no,
     * `buildPayload()` —que lee exclusivamente de la pivot— manda `notifications: []`
     * aunque haya notificaciones reales en el rango.
     *
     * @return void
     */
    public function test_publish_llena_la_pivot_con_todo_el_rango_y_las_notificaciones_viajan(): void
    {
        $from      = $this->crear_version('3.8.0');
        $intermedia = $this->crear_version('3.8.1');
        $hotfix    = $this->crear_version('3.8.1.1', true);
        $to        = $this->crear_version('3.8.2');

        $this->crear_notificacion($intermedia, 'Notificación de 3.8.1', 10);
        $this->crear_notificacion($hotfix, 'Notificación de 3.8.1.1', 10);
        $this->crear_notificacion($to, 'Notificación de 3.8.2', 10);

        $client                     = $this->crear_cliente();
        $client->current_version_id = $from->id;
        $client->save();

        Http::fake();

        $upgrade = (new PublishVersionService())->publish($client->fresh(), $to);

        $ids_en_pivot = $upgrade->confirmed_versions()->pluck('versions.id')->map('intval')->sort()->values()->all();
        $esperados    = collect([$intermedia->id, $hotfix->id, $to->id])->sort()->values()->all();

        $this->assertSame($esperados, $ids_en_pivot, 'publish() debía dejar todo el rango (from, to] en la pivot.');
        $this->assertNotContains((int) $from->id, $ids_en_pivot, 'La versión de origen no forma parte del rango (from, to].');

        Http::assertSent(function ($request) {
            $titulos = collect($request->data()['notifications'])->pluck('title')->all();

            return count($titulos) === 3
                && in_array('Notificación de 3.8.1', $titulos, true)
                && in_array('Notificación de 3.8.1.1', $titulos, true)
                && in_array('Notificación de 3.8.2', $titulos, true);
        });
    }
}
