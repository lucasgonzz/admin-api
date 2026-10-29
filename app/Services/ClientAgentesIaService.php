<?php

namespace App\Services;

use App\Models\Client;
use App\Services\Concerns\TapaLaClaveDelCliente;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lee y cambia la extensión «Agentes de IA» (slug `agentes_ia`) en el empresa-api de UN cliente (misión
 * `agentes-ia-extension-unica`, 22/10/2026).
 *
 * Contrato (empresa-api), puerta `admin-sync` con `admin.api.key:siempre`:
 *
 *  - `GET {frente activo}/api/admin-sync/agentes-ia`                       -> `{ok: true, activa: bool}`
 *  - `PUT {frente activo}/api/admin-sync/agentes-ia` body `{activa: bool}` -> `{ok: true, activa: bool}` (idempotente)
 *
 * Header `X-Admin-Api-Key` = `clients.api_key` en los dos. Códigos: 401 = clave rechazada (o instancia sin clave
 * cargada), 409 = la instancia no sabe quién es su dueño (falta `USER_ID` en su `.env` y la base es compartida),
 * 404/405 = empresa con versión vieja (la ruta no existe todavía).
 *
 * Molde: `ClientExtensionesAccesoService`. Mismos seis estados y mismos nombres; el controlador responde SIEMPRE
 * HTTP 200 y el estado dice qué pasó.
 *
 * 🔴 Lo que NO sale nunca: la clave del cliente (ni en la respuesta, ni en el log, ni en un mensaje de error).
 *
 * 🔴 Sin seguir redirecciones: Guzzle no saca un header propio como `X-Admin-Api-Key` al redirigir a otro host.
 *
 * 🔴 Nunca lanza. `activar_por_plan()` corre DESPUÉS de asignar un plan de IA y es best-effort: un fallo acá no puede
 * romper ni revertir la asignación del plan (decisión de Lucas, 22/10/2026).
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en constructor, etc.
 */
class ClientAgentesIaService
{
    use TapaLaClaveDelCliente;

    /** Ruta del empresa-api que lee y cambia la extensión. */
    const RUTA = 'api/admin-sync/agentes-ia';

    /** Segundos de espera de la llamada. */
    const TIMEOUT = 15;

    const ESTADO_OK              = 'ok';
    const ESTADO_VERSION_VIEJA   = 'version_vieja';
    const ESTADO_SIN_CLAVE       = 'sin_clave';
    const ESTADO_CLAVE_RECHAZADA = 'clave_rechazada';
    const ESTADO_SIN_FRENTE      = 'sin_frente';
    const ESTADO_SIN_CONEXION    = 'sin_conexion';

    /** @var ClientEmpresaApiUrlResolver */
    protected $api_url_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver Resolver del frente activo.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver ?: new ClientEmpresaApiUrlResolver();
    }

    /**
     * Pregunta al cliente si tiene «Agentes de IA» activa.
     *
     * @param Client $client Cliente a consultar.
     *
     * @return array{estado: string, mensaje: string, activa: bool|null}
     */
    public function consultar(Client $client)
    {
        return $this->llamar($client, 'GET', null);
    }

    /**
     * Activa o quita «Agentes de IA» en el cliente.
     *
     * @param Client $client Cliente a modificar.
     * @param bool   $activa true: activarla; false: quitarla.
     *
     * @return array{estado: string, mensaje: string, activa: bool|null}
     */
    public function cambiar(Client $client, $activa)
    {
        return $this->llamar($client, 'PUT', (bool) $activa);
    }

    /**
     * Activación automática al asignarle un plan de IA al cliente. Best-effort: NUNCA lanza.
     *
     * Quien asigna el plan ve el resultado en la respuesta (`ok` = se activó; otro estado = el motivo de que no).
     * Asignar el plan y activar la extensión son dos cosas separadas: si esto falla, el plan queda asignado igual.
     *
     * @param Client $client Cliente que acaba de recibir un plan.
     *
     * @return array{estado: string, mensaje: string, activa: bool|null}
     */
    public function activar_por_plan(Client $client)
    {
        try {
            return $this->cambiar($client, true);
        } catch (\Throwable $e) {
            Log::warning('ClientAgentesIaService: falló la activación automática al asignar el plan.', [
                'client_id' => (int) $client->id,
                'error'     => $this->texto_seguro(ClientCatalogoPuenteService::sin_la_query_de_las_urls($e->getMessage()), (string) $client->api_key),
            ]);

            return $this->sin_conexion();
        }
    }

    /**
     * Resultado para cuando se asignó el plan pero NO se intentó activar la extensión porque el push del plan ya
     * había fallado por transporte (el sistema del cliente no contestó): insistir sumaría otro timeout.
     *
     * @return array{estado: string, mensaje: string, activa: bool|null}
     */
    public function no_intentada_por_falla_del_plan()
    {
        return $this->resultado(
            self::ESTADO_SIN_CONEXION,
            'No se intentó activar Agentes de IA porque el sistema del cliente no respondió al enviarle el plan.'
            . ' Cuando vuelva a estar disponible se activa con el botón de la ficha.'
        );
    }

    /**
     * Hace la llamada al empresa-api del cliente y traduce la respuesta a un estado.
     *
     * @param Client    $client Cliente.
     * @param string    $metodo `GET` o `PUT`.
     * @param bool|null $activa Cuerpo del PUT (null en el GET).
     *
     * @return array{estado: string, mensaje: string, activa: bool|null}
     */
    protected function llamar(Client $client, $metodo, $activa)
    {
        $clave = trim((string) $client->api_key);

        if ($clave === '') {
            return $this->resultado(
                self::ESTADO_SIN_CLAVE,
                'Este cliente no tiene clave de API cargada en el admin, así que no se puede hablar con su sistema.'
            );
        }

        $base = $this->api_url_resolver->resolve_base_url($client);

        if ($base === '') {
            return $this->resultado(
                self::ESTADO_SIN_FRENTE,
                'Este cliente no tiene una API activa con una URL válida.'
            );
        }

        $url = rtrim($base, '/') . '/' . self::RUTA;

        try {
            $pedido = Http::withHeaders([
                    'X-Admin-Api-Key' => $clave,
                    'Accept'          => 'application/json',
                ])
                ->withOptions(['allow_redirects' => false])
                ->timeout(self::TIMEOUT);

            $response = $metodo === 'PUT'
                ? $pedido->put($url, ['activa' => (bool) $activa])
                : $pedido->get($url);
        } catch (\Throwable $e) {
            // Sin la query de las URLs ni la clave: el mensaje de Guzzle repite la URL entera.
            Log::warning('ClientAgentesIaService: no se pudo contactar al empresa-api del cliente.', [
                'client_id' => (int) $client->id,
                'metodo'    => $metodo,
                'error'     => $this->texto_seguro(ClientCatalogoPuenteService::sin_la_query_de_las_urls($e->getMessage()), $clave),
            ]);

            return $this->sin_conexion();
        }

        $status = (int) $response->status();

        Log::info('ClientAgentesIaService: llamada a la puerta agentes-ia del cliente.', [
            'client_id' => (int) $client->id,
            'metodo'    => $metodo,
            'status'    => $status,
        ]);

        if ($status === 404 || $status === 405) {
            return $this->resultado(
                self::ESTADO_VERSION_VIEJA,
                'Este cliente todavía no tiene la actualización que permite activar Agentes de IA desde el admin.'
            );
        }

        if ($status === 401) {
            return $this->resultado(
                self::ESTADO_CLAVE_RECHAZADA,
                'El sistema del cliente rechazó la clave de API que tiene el admin. Hay que volver a sincronizarla.'
            );
        }

        if ($status === 409) {
            // No es versión vieja ni un corte de red: el .env del frente no tiene USER_ID y la base es compartida.
            return $this->resultado(
                self::ESTADO_SIN_CONEXION,
                'El sistema del cliente no sabe quién es su dueño (falta USER_ID en el .env de su frente activo), '
                . 'así que no puede activar ni quitar Agentes de IA. Hay que cargar USER_ID y volver a intentar.'
            );
        }

        if ($status !== 200) {
            return $this->sin_conexion();
        }

        $json = json_decode((string) $response->body(), true);

        // Un 200 solo no alcanza (el hosting sirve su página genérica con 200 cuando está saturado): el cuerpo tiene
        // que confirmar con `ok: true` y traer `activa` como booleano.
        if (!is_array($json) || empty($json['ok']) || !array_key_exists('activa', $json) || !is_bool($json['activa'])) {
            return $this->sin_conexion();
        }

        $activa_ahora = $json['activa'];

        // Un PUT que el cliente confirma con OTRO valor del pedido no es un éxito: la extensión no quedó como se pidió.
        if ($metodo === 'PUT' && $activa_ahora !== (bool) $activa) {
            return $this->resultado(
                self::ESTADO_SIN_CONEXION,
                'El sistema del cliente no dejó Agentes de IA como se pidió (quedó '
                . ($activa_ahora ? 'activa' : 'no activa') . '). Volvé a consultar y probá de nuevo.'
            );
        }

        if ($metodo === 'PUT') {
            $mensaje = $activa_ahora
                ? 'Agentes de IA quedó activa en el sistema del cliente.'
                : 'Agentes de IA quedó quitada en el sistema del cliente.';

            if ($activa_ahora) {
                $this->inicializar_memoria_y_directorio($client);
            }
        } else {
            $mensaje = $activa_ahora
                ? 'El cliente tiene Agentes de IA activa.'
                : 'El cliente no tiene Agentes de IA activa.';
        }

        return $this->resultado(self::ESTADO_OK, $mensaje, $activa_ahora);
    }

    /**
     * Con la extensión recién confirmada activa, encola la creación de la memoria de contexto del dueño y su
     * alta en el directorio de la app `ia.comerciocity.com` (misión cruzada `app-ia-y-memoria`, 29/10/2026,
     * plan §4 A.3).
     *
     * 🔴 Best-effort: NUNCA rompe la activación. Si la cola no está (o el despacho falla por lo que sea), se
     * anota y la activación sigue contestando `ok`; el barrido nocturno `ia:directorio-sincronizar` y la
     * creación perezosa del lado del empresa-api cubren lo que no se hizo acá.
     *
     * @param Client $client
     *
     * @return void
     */
    protected function inicializar_memoria_y_directorio(Client $client)
    {
        try {
            /* El despachador explícito y no `Job::dispatch()`: ese encola recién en el destructor de su
               PendingDispatch, y un fallo ahí no es tan claro que caiga adentro de este try. */
            app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch(
                (new \App\Jobs\InicializarAgenteIaJob((int) $client->id))->onConnection('database')
            );
        } catch (\Throwable $e) {
            Log::warning('ClientAgentesIaService: no se pudo encolar la inicialización del agente.', [
                'client_id' => (int) $client->id,
                'error'     => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }

    /**
     * Resultado genérico de "no se pudo hablar con el cliente" (red, timeout, 5xx, respuesta inválida).
     *
     * @return array{estado: string, mensaje: string, activa: bool|null}
     */
    protected function sin_conexion()
    {
        return $this->resultado(
            self::ESTADO_SIN_CONEXION,
            'No se pudo contactar al sistema del cliente. Probá de nuevo en un momento.'
        );
    }

    /**
     * @param string    $estado  Uno de los ESTADO_*.
     * @param string    $mensaje Texto para mostrar.
     * @param bool|null $activa  Si el cliente tiene la extensión (solo en `ok`; null si no se pudo saber).
     *
     * @return array{estado: string, mensaje: string, activa: bool|null}
     */
    protected function resultado($estado, $mensaje, $activa = null)
    {
        return ['estado' => $estado, 'mensaje' => $mensaje, 'activa' => $activa];
    }
}
