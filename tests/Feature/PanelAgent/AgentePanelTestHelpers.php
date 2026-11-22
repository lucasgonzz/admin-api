<?php

namespace Tests\Feature\PanelAgent;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Lead;
use App\Models\MessageVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Utilidades compartidas por los tests de la misión admin-control-total (token de agente en el panel).
 *
 * Todo lo que arman estos helpers son datos de prueba creados por el propio test (la base de testing del
 * worktree + `DatabaseTransactions`): nunca se tocan datos reales ni se manda nada a internet.
 *
 * Los pedidos se hacen con un token real de Sanctum en el header `Authorization` (no con `Sanctum::actingAs`),
 * para probar el camino completo: `auth:sanctum` → guard del agente → controlador.
 */
trait AgentePanelTestHelpers
{
    /**
     * Crea un admin marcado como agente (`es_agente = true`).
     *
     * Sin la columna `es_agente` (estado previo a la implementación) este helper falla con
     * «Unknown column»: es el rojo esperado.
     *
     * @param  string $nombre Nombre visible del agente.
     * @return Admin
     */
    protected function crear_agente(string $nombre = 'Agente de prueba'): Admin
    {
        return Admin::create([
            'name'      => $nombre,
            'email'     => 'agente.' . Str::lower(Str::random(12)) . '@agentes.test',
            'password'  => bcrypt(Str::random(32)),
            'es_agente' => true,
        ]);
    }

    /**
     * Crea un admin humano común (el del panel de siempre, sin `es_agente`).
     *
     * @param  string $nombre Nombre visible.
     * @return Admin
     */
    protected function crear_humano(string $nombre = 'Operador humano'): Admin
    {
        return Admin::create([
            'name'     => $nombre,
            'email'    => 'humano.' . Str::lower(Str::random(12)) . '@test.local',
            'password' => bcrypt(Str::random(32)),
        ]);
    }

    /**
     * Emite un token Sanctum para el admin y devuelve el texto plano (`id|secreto`).
     *
     * @param  Admin $admin Dueño del token.
     * @return string
     */
    protected function token_de(Admin $admin): string
    {
        return $admin->createToken('prueba-agente', ['agente'])->plainTextToken;
    }

    /**
     * Hace UN pedido JSON al panel con el token indicado.
     *
     * Antes de cada pedido olvida los guards resueltos: así un mismo test puede alternar entre el token de un
     * agente y el de un humano sin que Laravel arrastre al usuario del pedido anterior. Los headers se pasan
     * por pedido (no con `withHeaders`) para que `X-Agente-Confirmar` y compañía no queden pegados al siguiente.
     *
     * @param  string|null          $token   Token en texto plano; null = pedido sin autenticar.
     * @param  string               $metodo  GET, POST, PUT, PATCH o DELETE.
     * @param  string               $uri     Ruta completa, p. ej. `/api/admin/client/83`.
     * @param  array<string, mixed> $datos   Cuerpo JSON (o query string en un GET).
     * @param  array<string, string> $headers Headers extra (p. ej. `X-Agente-Motivo`).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_como(?string $token, string $metodo, string $uri, array $datos = [], array $headers = [])
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');

        $headers_finales = $headers;
        if ($token !== null) {
            $headers_finales['Authorization'] = 'Bearer ' . $token;
        }

        return $this->json($metodo, $uri, $datos, $headers_finales);
    }

    /**
     * Crea un cliente de prueba activo. Si se pide un id puntual (el caso del cliente 83) y está libre, se fuerza.
     *
     * @param  int|null             $id_preferido Id que se quiere para el cliente (null = autoincremental).
     * @param  array<string, mixed> $extra        Columnas adicionales o que pisan las de siempre.
     * @return Client
     */
    protected function crear_cliente(?int $id_preferido = null, array $extra = []): Client
    {
        $columnas = $extra + [
            'name'      => 'Comercio de prueba del agente',
            'phone'     => '+5493410000083',
            'is_active' => true,
            'api_url'   => 'https://empresa-agente.test',
            'api_key'   => 'clave-de-prueba-del-cliente',
        ];

        if ($id_preferido !== null && Client::query()->where('id', $id_preferido)->doesntExist()) {
            $columnas['id'] = $id_preferido;
        }

        return Client::create($columnas);
    }

    /**
     * Crea una variante de mensaje sin envíos (`sent_count = 0`): el DELETE la borra de verdad.
     *
     * @return MessageVariant
     */
    protected function crear_variante(): MessageVariant
    {
        return MessageVariant::create([
            'slug'         => 'agente-' . Str::lower(Str::random(10)),
            'name'         => 'Variante de prueba del agente',
            'message_type' => 'welcome_with_name',
            'body'         => 'Hola, soy una variante de prueba.',
        ]);
    }

    /**
     * Crea un lead de prueba con teléfono (apto para el envío directo de WhatsApp).
     *
     * @return Lead
     */
    protected function crear_lead(): Lead
    {
        return Lead::create([
            'uuid'         => (string) Str::uuid(),
            'contact_name' => 'Lead del agente',
            'phone'        => '+5493415550101',
        ]);
    }

    /**
     * Crea una API de cliente (necesaria para el binding de `client-apis/{client_api}/…`).
     *
     * @param  Client $cliente Dueño de la API.
     * @return ClientApi
     */
    protected function crear_api_de_cliente(Client $cliente): ClientApi
    {
        return ClientApi::create([
            'uuid'      => (string) Str::uuid(),
            'client_id' => $cliente->id,
            'url'       => 'https://api-agente.test',
            'path'      => 'api',
        ]);
    }

    /**
     * Filas de auditoría del agente, de la más vieja a la más nueva.
     *
     * Se lee con `DB::table` a propósito: los tests fijan el contrato de la TABLA (columnas de §2.2 del plan),
     * no la forma del modelo Eloquent.
     *
     * @param  Admin $agente Agente cuyas filas se quieren.
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function auditoria_de(Admin $agente)
    {
        return DB::table('panel_agent_audit_logs')->where('admin_id', $agente->id)->orderBy('id')->get();
    }

    /**
     * Última fila de auditoría del agente (null si no hay).
     *
     * @param  Admin $agente Agente.
     * @return object|null
     */
    protected function ultima_auditoria_de(Admin $agente)
    {
        return $this->auditoria_de($agente)->last();
    }

    /**
     * Indica si un texto contiene una aguja (compatible con PHP 7.4: sin `str_contains`).
     *
     * @param  string|null $texto  Texto donde buscar.
     * @param  string      $aguja  Fragmento buscado.
     * @return bool
     */
    protected function contiene($texto, string $aguja): bool
    {
        return $texto !== null && strpos((string) $texto, $aguja) !== false;
    }
}
