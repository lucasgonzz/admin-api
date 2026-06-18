<?php

namespace App\Services;

use App\Exceptions\GoogleCalendarTokenRevokedException;
use App\Models\Admin;
use App\Models\AdminCalendarConnection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gestiona el flujo OAuth2 de Google Calendar para admins closers.
 *
 * Toda la integración usa Guzzle (via Laravel HTTP) sobre el API REST de Google;
 * no se instala la librería google/apiclient para mantener las dependencias mínimas.
 *
 * Flujo esperado:
 *   1. build_authorization_url()  → redirige al usuario a Google
 *   2. handle_callback()          → intercambia el code por tokens y guarda la conexión
 *   3. get_fresh_access_token()   → obtiene access_token fresco antes de cada llamada a la API
 */
class GoogleCalendarOAuthService
{
    /**
     * Endpoint de autorización de Google OAuth2.
     */
    const GOOGLE_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';

    /**
     * Endpoint de intercambio de tokens de Google OAuth2.
     */
    const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Scope de lectura de calendarios (acceso de solo lectura).
     */
    const CALENDAR_READONLY_SCOPE = 'https://www.googleapis.com/auth/calendar.readonly';

    /**
     * Scope de email: necesario para que el endpoint userinfo devuelva el campo `email`.
     * Sin este scope, fetch_account_email() recibe una respuesta sin el dato y guarda null.
     */
    const EMAIL_SCOPE  = 'https://www.googleapis.com/auth/userinfo.email';

    /**
     * Scope openid: requerido junto con el de email para el flujo de identidad de Google
     * (userinfo necesita openid + email para resolver la cuenta del consentimiento).
     */
    const OPENID_SCOPE = 'openid';

    /**
     * Construye la URL de consentimiento OAuth2 de Google para que el closer la visite.
     *
     * Usa access_type=offline + prompt=consent para garantizar que Google devuelva
     * siempre el refresh_token (incluso en reconexiones posteriores).
     *
     * El admin_id se incluye en el parámetro state para poder identificar al admin
     * en el callback sin depender de sesión server-side.
     *
     * @param int $admin_id ID del admin que inicia el flujo OAuth.
     * @return string URL completa de autorización de Google.
     */
    public function build_authorization_url(int $admin_id): string
    {
        // Firmar el state con HMAC-SHA256 para evitar que el callback acepte admin_id arbitrario.
        $state = $this->sign_state($admin_id);

        $params = [
            'client_id'     => config('services.google_calendar.client_id'),
            'redirect_uri'  => config('services.google_calendar.redirect_uri'),
            'response_type' => 'code',
            // Los tres scopes separados por espacio (formato estándar OAuth2 para múltiples scopes).
            // email + openid son necesarios para que userinfo devuelva el email de la cuenta conectada.
            'scope'         => self::CALENDAR_READONLY_SCOPE . ' ' . self::EMAIL_SCOPE . ' ' . self::OPENID_SCOPE,
            'access_type'   => 'offline',
            // prompt=consent garantiza que Google siempre devuelva refresh_token.
            'prompt'        => 'consent',
            'state'         => $state,
        ];

        return self::GOOGLE_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Maneja el callback de Google: intercambia el code por tokens OAuth y guarda
     * (o actualiza) la conexión del admin en admin_calendar_connections.
     *
     * Si el admin ya tenía una conexión previa, la reemplaza por la nueva.
     *
     * @param string $code  Código de autorización recibido desde Google.
     * @param string $state Valor state devuelto por Google (debe verificarse).
     * @return AdminCalendarConnection Conexión creada o actualizada.
     *
     * @throws \InvalidArgumentException Si el state es inválido o el admin no existe.
     * @throws \RuntimeException         Si Google rechaza el intercambio de tokens.
     */
    public function handle_callback(string $code, string $state): AdminCalendarConnection
    {
        // Verificar la firma del state y extraer el admin_id.
        $admin_id = $this->verify_state($state);
        if (! $admin_id) {
            throw new \InvalidArgumentException('Parámetro state inválido o manipulado.');
        }

        // Confirmar que el admin existe antes de persistir la conexión.
        $admin = Admin::find($admin_id);
        if (! $admin) {
            throw new \InvalidArgumentException("Admin con id={$admin_id} no encontrado.");
        }

        // Intercambiar el código por tokens en Google.
        $token_response = Http::asForm()->post(self::GOOGLE_TOKEN_URL, [
            'code'          => $code,
            'client_id'     => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'redirect_uri'  => config('services.google_calendar.redirect_uri'),
            'grant_type'    => 'authorization_code',
        ]);

        if ($token_response->failed()) {
            Log::error('GoogleCalendarOAuth: fallo en intercambio de tokens', [
                'admin_id' => $admin_id,
                'status'   => $token_response->status(),
                'body'     => $token_response->body(),
            ]);
            throw new \RuntimeException(
                'Error al obtener tokens de Google: HTTP ' . $token_response->status()
            );
        }

        $token_data = $token_response->json();

        // refresh_token no siempre está presente; si falta, probablemente ya hay una conexión activa.
        if (empty($token_data['refresh_token'])) {
            Log::warning('GoogleCalendarOAuth: Google no devolvió refresh_token', [
                'admin_id' => $admin_id,
                'hint'     => 'Puede que prompt=consent no se haya enviado o la cuenta ya autorizó antes.',
            ]);
            throw new \RuntimeException(
                'Google no devolvió refresh_token. Asegurate de usar prompt=consent en la URL de autorización.'
            );
        }

        // Obtener el email de la cuenta para mostrarlo en la UI.
        $google_account_email = $this->fetch_account_email($token_data['access_token']);

        // Cifrar el refresh_token antes de persistirlo (nunca se guarda en texto plano).
        $encrypted_refresh_token = Crypt::encryptString($token_data['refresh_token']);

        // Upsert: un admin solo puede tener una conexión activa; si reconecta, reemplaza.
        $connection = AdminCalendarConnection::updateOrCreate(
            ['admin_id' => $admin_id],
            [
                'google_refresh_token_encrypted' => $encrypted_refresh_token,
                // google_calendar_id se asigna en un paso posterior (el closer elige el calendario).
                'google_calendar_id'             => '',
                'google_account_email'           => $google_account_email,
                'connected_at'                   => now(),
                'is_active'                      => true,
            ]
        );

        return $connection;
    }

    /**
     * Obtiene un access_token fresco usando el refresh_token almacenado.
     *
     * No se cachea el access_token en BD porque tienen vida corta (~1h) y es más
     * simple pedir uno nuevo por request que gestionar su expiración.
     *
     * @param AdminCalendarConnection $connection Conexión del admin closer.
     * @return string Access token listo para usar en headers de Google API.
     *
     * @throws GoogleCalendarTokenRevokedException Si Google devuelve invalid_grant.
     * @throws \RuntimeException                   Si Google devuelve cualquier otro error.
     */
    public function get_fresh_access_token(AdminCalendarConnection $connection): string
    {
        // Desencriptar el refresh_token para enviarlo a Google.
        $refresh_token = $connection->get_decrypted_refresh_token();

        $response = Http::asForm()->post(self::GOOGLE_TOKEN_URL, [
            'client_id'     => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        if ($response->failed()) {
            $body = $response->json();
            // invalid_grant significa que el usuario revocó el acceso.
            if (isset($body['error']) && $body['error'] === 'invalid_grant') {
                // Marcar la conexión como inactiva para que no bloquee futuros cálculos.
                $connection->update(['is_active' => false]);
                throw new GoogleCalendarTokenRevokedException(
                    (int) $connection->admin_id,
                    'Google Calendar: token revocado por el usuario (invalid_grant).'
                );
            }

            throw new \RuntimeException(
                'Error al refrescar access_token de Google: HTTP ' . $response->status()
            );
        }

        return (string) $response->json('access_token');
    }

    /**
     * Lista los calendarios disponibles en la cuenta Google del admin.
     *
     * Se usa para que el closer elija cuál calendario dedicado conectar,
     * sin necesidad de conocer el calendarId de memoria.
     *
     * @param AdminCalendarConnection $connection Conexión del admin.
     * @return array<int, array{id: string, summary: string}> Lista resumida de calendarios.
     *
     * @throws GoogleCalendarTokenRevokedException Si el token fue revocado.
     * @throws \RuntimeException                   Si la llamada a Google falla.
     */
    public function list_calendars(AdminCalendarConnection $connection): array
    {
        // Obtener access_token fresco antes de llamar a la API.
        $access_token = $this->get_fresh_access_token($connection);

        $response = Http::withToken($access_token)
            ->get('https://www.googleapis.com/calendar/v3/users/me/calendarList');

        if ($response->failed()) {
            throw new \RuntimeException(
                'Error al listar calendarios de Google: HTTP ' . $response->status()
            );
        }

        // Devolver solo id + summary para que el frontend muestre el selector.
        $items = $response->json('items', []);
        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'id'      => $item['id'] ?? '',
                'summary' => $item['summary'] ?? '',
            ];
        }
        return $result;
    }

    /**
     * Genera el parámetro state firmado con HMAC-SHA256 para el flujo OAuth.
     *
     * @param int $admin_id ID del admin que inicia el flujo.
     * @return string Valor state en formato "admin_id.hmac".
     */
    protected function sign_state(int $admin_id): string
    {
        // Usar APP_KEY como secreto de firma (ya está disponible en todos los entornos).
        $secret = config('app.key');
        $hmac   = hash_hmac('sha256', (string) $admin_id, $secret);
        return $admin_id . '.' . $hmac;
    }

    /**
     * Verifica el parámetro state firmado y extrae el admin_id.
     *
     * @param string $state Valor state devuelto por Google.
     * @return int|null admin_id si es válido, null si fue manipulado.
     */
    protected function verify_state(string $state): ?int
    {
        // El state tiene formato "admin_id.hmac".
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$admin_id_str, $received_hmac] = $parts;
        $admin_id = (int) $admin_id_str;

        // Recalcular el HMAC esperado y comparar en tiempo constante.
        $expected_hmac = hash_hmac('sha256', $admin_id_str, config('app.key'));
        if (! hash_equals($expected_hmac, $received_hmac)) {
            return null;
        }

        return $admin_id;
    }

    /**
     * Consulta el email de la cuenta Google autenticada usando el access_token.
     *
     * @param string $access_token Access token recién obtenido.
     * @return string|null Email de la cuenta, o null si no se pudo obtener.
     */
    protected function fetch_account_email(string $access_token): ?string
    {
        // Endpoint de userinfo de Google para obtener el email de la cuenta.
        // Requiere que el token tenga los scopes email + openid (ver build_authorization_url).
        $response = Http::withToken($access_token)
            ->get('https://www.googleapis.com/oauth2/v3/userinfo');

        if ($response->failed()) {
            // Se loguea el body completo para diagnosticar fallas de scope/permiso sin reproducir a ciegas.
            Log::warning('GoogleCalendarOAuth: no se pudo obtener email de la cuenta', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        // La respuesta puede ser 200 pero no traer el campo `email` (token sin scope email/openid).
        $email = $response->json('email');
        if (empty($email)) {
            // Se loguea el body completo para detectar la ausencia del campo email.
            Log::warning('GoogleCalendarOAuth: respuesta sin campo email', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        return $email;
    }
}
