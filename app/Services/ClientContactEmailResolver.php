<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A qué casilla se le escribe al dueño de un cliente.
 *
 * Tres pasos, en orden, y el primero que da un mail válido corta:
 *
 *   1. **`clients.email`** — la fuente de verdad. Lo que está escrito acá le gana a todo, así que
 *      corregirlo a mano en la ficha del cliente alcanza para redirigir el aviso.
 *   2. **`GET admin-sync/contacto-dueno` al `empresa-api` de ese cliente** — el dato real vive
 *      allá, en el `User` dueño de la instancia. Si vuelve un mail válido se ESCRIBE en
 *      `clients.email` y no se vuelve a preguntar nunca más.
 *   3. **Nada** — devuelve null.
 *
 * 🔴 **El paso 2 degrada sin romper, y eso no es defensividad genérica: es el estado normal
 * durante semanas.** Ese endpoint es nuevo del lado de `empresa-api` y los ~45 clientes corren
 * versiones distintas: ninguno lo tiene hasta que se actualiza a una versión que lo traiga, así
 * que la respuesta esperable hoy es **404**. Por eso `404`, `401`, `500`, timeout, body que no es
 * JSON, `contacto` ausente y `email` ausente, vacío o inválido se tratan TODOS igual: se devuelve
 * null, se deja una línea en el log y se sigue. Este servicio no tira excepción por ningún camino.
 *
 * Y la validación con `FILTER_VALIDATE_EMAIL` se aplica en los DOS pasos, no solo en el remoto:
 * `clients.email` se carga a mano desde el panel y una casilla mal tipeada ahí haría fallar el
 * envío sin que el fallback remoto llegue a correr.
 */
class ClientContactEmailResolver
{
    /**
     * Ruta relativa del contacto del dueño en el `empresa-api` del cliente.
     *
     * Sin `{user_id}`: el `empresa-api` resuelve el dueño de su propia instancia
     * (`config('app.USER_ID')`), igual que `mensualidad-info` y `branding`.
     */
    const CONTACTO_DUENO_PATH = 'api/admin-sync/contacto-dueno';

    /**
     * @var ClientEmpresaApiUrlResolver
     */
    protected $api_url_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver Inyectable para los tests.
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver !== null
            ? $api_url_resolver
            : new ClientEmpresaApiUrlResolver();
    }

    /**
     * La casilla del dueño de este cliente, o null si no hay ninguna en ningún lado.
     *
     * @param Client $client Cliente del admin.
     *
     * @return string|null Dirección válida, ya recortada.
     */
    public function resolve(Client $client): ?string
    {
        $propio = self::mail_valido($client->email);
        if ($propio !== null) {
            return $propio;
        }

        $remoto = $this->preguntarle_al_cliente($client);
        if ($remoto === null) {
            return null;
        }

        $this->recordar($client, $remoto);

        return $remoto;
    }

    /**
     * Le pregunta al `empresa-api` del cliente por el contacto de su dueño.
     *
     * 🔴 Todo lo que salga mal devuelve null. No hay ningún camino que propague una excepción
     * hacia arriba: arriba está el aviso de una actualización que YA SE CERRÓ BIEN, y quedarse sin
     * la casilla no puede ensuciar eso.
     *
     * @param Client $client Cliente del admin.
     *
     * @return string|null Mail válido del dueño, o null.
     */
    private function preguntarle_al_cliente(Client $client): ?string
    {
        $url = $this->api_url_resolver->admin_sync_url($client, self::CONTACTO_DUENO_PATH);

        if ($url === '') {
            $this->anotar($client, 'el cliente no tiene una URL de empresa-api válida');

            return null;
        }

        if (empty($client->api_key)) {
            $this->anotar($client, 'el cliente no tiene api_key cargada');

            return null;
        }

        try {
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $client->api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->get($url);
        } catch (RequestException $exception) {
            /*
             * ⚠️ Con `retry()` activo (tries > 1), Laravel NO devuelve la respuesta fallida: la
             * convierte en excepción después de agotar los intentos. O sea que el 404 —el caso de
             * lejos más común de este canal— llega por ACÁ y no por el `successful()` de abajo.
             * Ese chequeo igual se queda, porque con `CLIENT_API_RETRIES=1` no hay excepción.
             */
            $status = $exception->response !== null ? $exception->response->status() : 0;

            $this->anotar($client, $this->motivo_del_status($status));

            return null;
        } catch (\Throwable $exception) {
            // Timeout, DNS caído, certificado vencido: todo lo mismo, no hay mail.
            $this->anotar($client, 'no se pudo conectar (' . $exception->getMessage() . ')');

            return null;
        }

        if (! $response->successful()) {
            $this->anotar($client, $this->motivo_del_status($response->status()));

            return null;
        }

        /*
         * `json()` de Laravel devuelve null si el body no es JSON parseable, así que un HTML de
         * error o una página de mantenimiento con status 200 caen acá sin reventar.
         */
        try {
            $contacto = $response->json('contacto');
        } catch (\Throwable $exception) {
            $this->anotar($client, 'la respuesta no es JSON');

            return null;
        }

        if (! is_array($contacto)) {
            $this->anotar($client, 'la respuesta no trae "contacto"');

            return null;
        }

        $mail = self::mail_valido(isset($contacto['email']) ? $contacto['email'] : null);

        if ($mail === null) {
            $this->anotar($client, 'el contacto del dueño no trae un mail válido');

            return null;
        }

        return $mail;
    }

    /**
     * Guarda en `clients.email` la casilla que trajo el cliente, para no volver a preguntar.
     *
     * Se escribe por el query builder y se sincroniza el atributo a mano en vez de hacer
     * `$client->save()`: la instancia que llega acá puede venir con otros atributos tocados por el
     * llamador, y este servicio no tiene por qué persistirlos de rebote.
     *
     * @param Client $client Cliente del admin.
     * @param string $mail   Dirección ya validada.
     *
     * @return void
     */
    private function recordar(Client $client, string $mail): void
    {
        try {
            Client::where('id', $client->id)->update(['email' => $mail]);

            $client->setAttribute('email', $mail);
            $client->syncOriginalAttribute('email');

            Log::channel('daily')->info('ClientContactEmailResolver: se guardó la casilla del dueño.', [
                'client_id' => $client->id,
                'email'     => $mail,
            ]);
        } catch (\Throwable $exception) {
            // Si no se pudo guardar, el mail igual sirve para este envío. Se vuelve a preguntar
            // la próxima vez y ya.
            $this->anotar($client, 'no se pudo guardar la casilla (' . $exception->getMessage() . ')');
        }
    }

    /**
     * Cómo se cuenta en el log un status HTTP que no sirvió.
     *
     * El 404 se nombra aparte a propósito: es EL caso esperado hoy —el cliente corre una versión
     * anterior a la que trae el endpoint— y leerlo como "404" a secas hace pensar que hay algo
     * roto cuando no lo hay.
     *
     * @param int $status Código HTTP, o 0 si no se pudo determinar.
     *
     * @return string
     */
    private function motivo_del_status(int $status): string
    {
        if ($status === 404) {
            return 'el cliente todavía no tiene el endpoint (404): corre una versión anterior';
        }

        if ($status === 401 || $status === 403) {
            return 'el cliente rechazó la api_key (HTTP ' . $status . ')';
        }

        if ($status === 0) {
            return 'el cliente no respondió';
        }

        return 'el cliente respondió HTTP ' . $status;
    }

    /**
     * Deja una línea en el log y sigue. Es `info` y no `warning` porque el caso de lejos más
     * común —el cliente sin el endpoint— es lo esperado, no una alarma.
     *
     * @param Client $client Cliente del admin.
     * @param string $motivo Qué pasó, en castellano.
     *
     * @return void
     */
    private function anotar(Client $client, string $motivo): void
    {
        Log::channel('daily')->info('ClientContactEmailResolver: sin casilla del dueño. ' . $motivo, [
            'client_id' => $client->id,
            'slug'      => $client->slug,
        ]);
    }

    /**
     * Normaliza y valida una dirección de correo.
     *
     * @param mixed $valor Lo que haya: string, null, o cualquier cosa.
     *
     * @return string|null La dirección recortada si es válida, null si no.
     */
    public static function mail_valido($valor): ?string
    {
        if ($valor === null || is_array($valor) || is_object($valor)) {
            return null;
        }

        $limpio = trim((string) $valor);

        if ($limpio === '') {
            return null;
        }

        return filter_var($limpio, FILTER_VALIDATE_EMAIL) !== false ? $limpio : null;
    }
}
