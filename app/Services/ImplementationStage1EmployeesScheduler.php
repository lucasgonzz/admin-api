<?php

namespace App\Services;

use App\Jobs\ProcessImplementationStage1Employees;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Programa la confirmación diferida de fin de carga de empleados en la Etapa 1.
 *
 * Cada vez que el cliente envía un mensaje de empleados se reinicia el timer:
 * se incrementa el token de programación y se despacha un nuevo job diferido.
 * Solo el job cuyo token coincida con el almacenado en caché enviará la pregunta
 * de confirmación; los anteriores se descartan automáticamente.
 */
class ImplementationStage1EmployeesScheduler
{
    /** Prefijo de clave de caché por implementación. */
    private const CACHE_KEY_PREFIX = 'impl_stage1_employees_token:';

    /** TTL del token en caché: cubre demoras largas de cola y procesamiento. */
    private const CACHE_TTL_SECONDS = 7200;

    /**
     * Reinicia la espera y programa un job diferido para consultar la confirmación.
     *
     * Llamar este método cada vez que llega un mensaje de empleados en la Etapa 1.
     * Si llega otro mensaje antes de que expire el timer, el token anterior queda
     * obsoleto y su job será ignorado al ejecutarse.
     *
     * @param int $implementation_id ID de la implementación que recibió un mensaje de empleados.
     *
     * @return void
     */
    public function schedule_after_employee_message(int $implementation_id): void
    {
        // Demora configurable antes de preguntar al cliente si terminó (debounce).
        $delay = ImplementationSettings::get_employees_wait_seconds();

        // Incrementar el token para invalidar jobs anteriores en cola.
        $token = $this->bump_token($implementation_id);

        // Despachar el job con el nuevo token y la demora indicada.
        ProcessImplementationStage1Employees::dispatch($implementation_id, $token)
            ->delay(now()->addSeconds($delay));

        Log::channel('daily')->debug('ImplementationStage1EmployeesScheduler: confirmación diferida programada.', [
            'implementation_id' => $implementation_id,
            'delay_seconds'     => $delay,
            'schedule_token'    => $token,
        ]);
    }

    /**
     * Indica si el token del job sigue siendo el último programado para esa implementación.
     *
     * @param int $implementation_id
     * @param int $schedule_token    Token capturado al encolar el job.
     *
     * @return bool true si el token coincide con el almacenado en caché.
     */
    public function is_schedule_token_current(int $implementation_id, int $schedule_token): bool
    {
        $current = Cache::get($this->cache_key($implementation_id));

        return (int) $current === $schedule_token;
    }

    /**
     * Incrementa el token de programación de la implementación y lo persiste en caché.
     *
     * @param int $implementation_id
     *
     * @return int Token nuevo asignado al job.
     */
    private function bump_token(int $implementation_id): int
    {
        $cache_key = $this->cache_key($implementation_id);

        // Leer el token actual y calcular el siguiente.
        $current = (int) Cache::get($cache_key, 0);
        $next    = $current + 1;

        Cache::put($cache_key, $next, self::CACHE_TTL_SECONDS);

        return $next;
    }

    /**
     * Arma la clave de caché para el token de debounce de la implementación.
     *
     * @param int $implementation_id
     *
     * @return string
     */
    private function cache_key(int $implementation_id): string
    {
        return self::CACHE_KEY_PREFIX . $implementation_id;
    }
}
