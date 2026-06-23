<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminCalendarConnection;
use App\Services\CloserGoogleCalendarBusyService;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gestiona la conexión OAuth2 de Google Calendar para un admin objetivo.
 *
 * El admin objetivo se identifica por {admin_id} en la URL de la ruta,
 * no por el admin autenticado en la sesión (que puede ser diferente cuando
 * un superadmin gestiona el calendario de un closer desde el modal de edición).
 *
 * Flujo completo:
 *   1. GET {admin_id}/connect        → devuelve authorization_url para redirigir al closer a Google
 *   2. GET callback                  → Google redirige aquí con code; intercambia por tokens y guarda
 *   3. GET {admin_id}/status         → informa si hay conexión activa y qué cuenta está conectada
 *   4. GET {admin_id}/list-calendars → lista los calendarios disponibles para elegir el dedicado
 *   5. PUT {admin_id}/select-calendar → persiste el calendar_id elegido
 *   6. DELETE {admin_id}             → desactiva la conexión (soft disconnect)
 */
class AdminCalendarConnectionController extends Controller
{
    /** @var GoogleCalendarOAuthService */
    protected $oauth_service;

    /** @var CloserGoogleCalendarBusyService */
    protected $busy_service;

    /**
     * @param GoogleCalendarOAuthService       $oauth_service Servicio de autenticación OAuth Google.
     * @param CloserGoogleCalendarBusyService  $busy_service  Consulta eventos y caché de disponibilidad.
     */
    public function __construct(
        GoogleCalendarOAuthService $oauth_service,
        CloserGoogleCalendarBusyService $busy_service
    ) {
        $this->oauth_service = $oauth_service;
        $this->busy_service  = $busy_service;
    }

    /**
     * Devuelve la URL de autorización OAuth2 de Google para iniciar el flujo.
     * El frontend debe redirigir al usuario a esta URL.
     *
     * @param Request $request
     * @param int     $admin_id  ID del admin objetivo cuyo calendario se está gestionando.
     * @return \Illuminate\Http\JsonResponse
     */
    public function connect(Request $request, int $admin_id)
    {
        // Verificar que el admin objetivo exista antes de generar la URL de OAuth.
        if (! Admin::find($admin_id)) {
            return response()->json(['message' => 'Admin no encontrado.'], 404);
        }

        $authorization_url = $this->oauth_service->build_authorization_url($admin_id);

        return response()->json(['authorization_url' => $authorization_url], 200);
    }

    /**
     * Callback público que Google redirige tras el consentimiento del usuario.
     *
     * No puede usar auth:sanctum porque Google no envía el token de Sanctum.
     * La seguridad se garantiza por la firma HMAC del parámetro state.
     *
     * Tras procesar el callback, redirige al frontend de admin-spa con un
     * query param indicando éxito o error.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function callback(Request $request)
    {
        // Verificar que Google devolvió code y state.
        $code  = $request->input('code');
        $state = $request->input('state');

        // URL base del SPA para la redirección final.
        $spa_base = config('app.frontend_url', env('FRONTEND_URL', 'https://admin.comerciocity.com'));

        if (! $code || ! $state) {
            return redirect($spa_base . '/usuarios-admin?calendar_connected=false&error=missing_params');
        }

        try {
            // Intercambiar código por tokens y guardar la conexión.
            $connection = $this->oauth_service->handle_callback($code, $state);

            // Redirigir al SPA informando éxito con el admin_id para que el frontend
            // pueda re-abrir el modal del closer correcto.
            return redirect(
                $spa_base . '/usuarios-admin?calendar_connected=true&admin_id=' . $connection->admin_id
            );
        } catch (\Exception $e) {
            Log::error('AdminCalendarConnectionController: fallo en callback OAuth', [
                'error' => $e->getMessage(),
            ]);
            return redirect($spa_base . '/usuarios-admin?calendar_connected=false&error=oauth_failed');
        }
    }

    /**
     * Devuelve el estado de la conexión de Google Calendar del admin objetivo.
     *
     * @param Request $request
     * @param int     $admin_id  ID del admin objetivo cuyo estado se consulta.
     * @return \Illuminate\Http\JsonResponse
     */
    public function status(Request $request, int $admin_id)
    {
        // Verificar que el admin objetivo exista.
        if (! Admin::find($admin_id)) {
            return response()->json(['message' => 'Admin no encontrado.'], 404);
        }

        // Buscar conexión activa del admin objetivo (no del admin logueado en la sesión).
        $connection = AdminCalendarConnection::where('admin_id', $admin_id)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            // Sin conexión activa: devolver estado desconectado.
            return response()->json([
                'connected'            => false,
                'google_account_email' => null,
                'google_calendar_id'   => null,
                'last_synced_at'       => null,
            ], 200);
        }

        return response()->json([
            'connected'            => true,
            'google_account_email' => $connection->google_account_email,
            'google_calendar_id'   => $connection->google_calendar_id,
            'last_synced_at'       => $connection->last_synced_at,
        ], 200);
    }

    /**
     * Lista los calendarios disponibles en la cuenta Google del admin objetivo.
     * Se usa para que el closer elija cuál calendario dedicado conectar.
     *
     * @param Request $request
     * @param int     $admin_id  ID del admin objetivo cuya lista de calendarios se obtiene.
     * @return \Illuminate\Http\JsonResponse
     */
    public function list_calendars(Request $request, int $admin_id)
    {
        // Verificar que el admin objetivo exista.
        if (! Admin::find($admin_id)) {
            return response()->json(['message' => 'Admin no encontrado.'], 404);
        }

        // Verificar que el admin objetivo tenga una conexión activa.
        $connection = AdminCalendarConnection::where('admin_id', $admin_id)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            return response()->json(['message' => 'No hay conexión activa con Google Calendar.'], 422);
        }

        try {
            $calendars = $this->oauth_service->list_calendars($connection);
            return response()->json(['calendars' => $calendars], 200);
        } catch (\Exception $e) {
            Log::error('AdminCalendarConnectionController: fallo al listar calendarios', [
                'admin_id' => $admin_id,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Error al obtener la lista de calendarios de Google: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Guarda el ID del calendario dedicado elegido para el admin objetivo.
     * Este paso es necesario después de la conexión OAuth inicial.
     *
     * @param Request $request
     * @param int     $admin_id  ID del admin objetivo cuyo calendario se está configurando.
     * @return \Illuminate\Http\JsonResponse
     */
    public function select_calendar(Request $request, int $admin_id)
    {
        $request->validate([
            'calendar_id' => 'required|string|max:500',
        ]);

        // Verificar que el admin objetivo exista.
        if (! Admin::find($admin_id)) {
            return response()->json(['message' => 'Admin no encontrado.'], 404);
        }

        // Buscar conexión activa del admin objetivo (no del admin logueado en la sesión).
        $connection = AdminCalendarConnection::where('admin_id', $admin_id)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            return response()->json(['message' => 'No hay conexión activa con Google Calendar.'], 422);
        }

        // Persistir el calendar_id del calendario dedicado elegido.
        $connection->update([
            'google_calendar_id' => $request->input('calendar_id'),
        ]);

        return response()->json([
            'message'            => 'Calendario seleccionado correctamente.',
            'google_calendar_id' => $connection->google_calendar_id,
        ], 200);
    }

    /**
     * Lista los eventos próximos del calendario Google del admin objetivo (con nombre y horario).
     *
     * @param Request $request
     * @param int     $admin_id  ID del admin objetivo cuyos eventos se consultan.
     * @return \Illuminate\Http\JsonResponse
     */
    public function get_events(Request $request, int $admin_id)
    {
        // Verificar que el admin objetivo exista.
        if (! Admin::find($admin_id)) {
            return response()->json(['message' => 'Admin no encontrado.'], 404);
        }

        // Conexión activa con calendario dedicado elegido.
        $connection = AdminCalendarConnection::where('admin_id', $admin_id)
            ->where('is_active', true)
            ->first();

        if (! $connection || empty($connection->google_calendar_id)) {
            return response()->json(['message' => 'No hay conexión activa con calendario configurado.'], 422);
        }

        // Consulta fresca a events.list de Google (sin caché intermedia).
        $events = $this->busy_service->get_events_for_admin($connection);

        // Recargar last_synced_at actualizado por el servicio tras consulta exitosa.
        $connection->refresh();

        return response()->json([
            'events'         => $events,
            'last_synced_at' => $connection->last_synced_at,
        ], 200);
    }

    /**
     * Fuerza sincronización del calendario: refresca eventos e invalida caché freeBusy de 14 días.
     *
     * @param Request $request
     * @param int     $admin_id  ID del admin objetivo cuyo calendario se sincroniza.
     * @return \Illuminate\Http\JsonResponse
     */
    public function sync_calendar(Request $request, int $admin_id)
    {
        // Verificar que el admin objetivo exista.
        if (! Admin::find($admin_id)) {
            return response()->json(['message' => 'Admin no encontrado.'], 404);
        }

        // Conexión activa del closer.
        $connection = AdminCalendarConnection::where('admin_id', $admin_id)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            return response()->json(['message' => 'No hay conexión activa con Google Calendar.'], 422);
        }

        // Refrescar listado de eventos desde Google.
        $events = $this->busy_service->get_events_for_admin($connection);

        // Invalidar caché freeBusy de hoy y los 13 días siguientes (14 fechas en total).
        $tz = 'America/Argentina/Buenos_Aires';
        for ($day_offset = 0; $day_offset < 14; $day_offset++) {
            $date_string = \Carbon\Carbon::now($tz)->addDays($day_offset)->format('Y-m-d');
            $this->busy_service->flush_cache_for_date($date_string);
        }

        $connection->refresh();

        return response()->json([
            'events'         => $events,
            'last_synced_at' => $connection->last_synced_at,
        ], 200);
    }

    /**
     * Desconecta el Google Calendar del admin objetivo.
     * Desactivación soft: no borra el registro para mantener historial.
     *
     * @param Request $request
     * @param int     $admin_id  ID del admin objetivo cuya conexión se desactiva.
     * @return \Illuminate\Http\JsonResponse
     */
    public function disconnect(Request $request, int $admin_id)
    {
        // Verificar que el admin objetivo exista.
        if (! Admin::find($admin_id)) {
            return response()->json(['message' => 'Admin no encontrado.'], 404);
        }

        // Buscar cualquier conexión del admin objetivo (activa o no) para desactivarla.
        $connection = AdminCalendarConnection::where('admin_id', $admin_id)->first();

        if (! $connection) {
            return response()->json(['message' => 'No hay conexión para desconectar.'], 404);
        }

        // Desactivación soft: preserva el registro histórico.
        $connection->update(['is_active' => false]);

        return response()->json(['message' => 'Desconectado correctamente.'], 200);
    }
}
