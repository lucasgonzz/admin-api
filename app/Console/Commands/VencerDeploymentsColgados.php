<?php

namespace App\Console\Commands;

use App\Events\DeploymentLogCreated;
use App\Jobs\RunDeploymentJob;
use App\Models\AdminSetting;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Saca del limbo a los upgrades cuyo deployment quedó en `running` y dejó de reportar actividad.
 *
 * 🔴 Por qué existe: `running` era un estado terminal de hecho. Las DOS puertas que arrancan un
 * deployment lo rechazan —el panel con `$active_deployment_statuses` y `claude/*` con su equivalente—,
 * así que un upgrade que entró en `running` y cuyo proceso murió antes de poder escribir el fallo se
 * quedaba ahí para siempre y solo se salía tocando la base a mano. `GET claude/upgrades/{id}` ya
 * REPORTABA el cuelgue en `salud.deployment_stale`, pero reportar no es destrabar.
 *
 * Y el proceso puede morir sin dejar rastro por construcción: hasta esta misma misión el panel
 * despachaba `RunDeploymentJob` sin `->onConnection('database')`, así que con `QUEUE_CONNECTION=sync`
 * el pipeline SSH entero corría adentro del request y bajo mod_php lo mataba `max_execution_time` a
 * los 120 segundos. Un fatal por tiempo no es capturable: el `catch (\Throwable)` del job nunca
 * corría. Sacar el trabajo del request es la mitad del arreglo; esta es la otra, y hace falta igual
 * porque **un worker también se puede morir**.
 *
 * Es el mismo comando que `leads:vencer-demo-setups-colgados`, para la otra máquina de estados del
 * sistema, y por el mismo motivo escrito en `APRENDER_NO_PARCHEAR.md` el 13/8/2026: *todo estado
 * intermedio necesita un proceso que lo destrabe que no sea el mismo que lo puso ahí*. La lección ya
 * se había aprendido dos veces —`demo_updates` el 13/7, `demo_setup_status` el 14/8— y las dos veces
 * quedó sin generalizar.
 *
 * 🔴 Lo que este comando NO hace, a diferencia del molde: **no reintenta nada**. Un demo setup
 * vencido se re-dispara solo porque el turno del lead vence en una hora y reintentarlo no le cuesta
 * nada a nadie. Un deployment vencido dejó a medias el servidor de un cliente REAL: reintentar es
 * una decisión de Lucas o de Claude, con el estado de ese servidor a la vista. Lo único que hace
 * este comando es devolver la puerta a `start` / `configure-system`.
 *
 * Este comando es la RED DE ABAJO, no el primer piso. El primer piso es `RunDeploymentJob::failed()`,
 * que destraba en segundos cuando el worker sí alcanza a avisar. Acá se cae lo que ni siquiera llegó
 * a eso: el proceso muerto de cuajo, el worker que nunca corrió, el scheduler que estuvo caído.
 *
 * ⚠️ Pérdida conocida, no un descuido: el motivo vive en `deployment_logs`, y `start_json` borra
 * los logs del intento anterior al arrancar de nuevo. O sea que el texto desaparece en el mismo
 * click que lo obedece a medias. Sobrevive el `Log::warning` en `storage/logs`. Se asume: darle una
 * columna propia al motivo sería duplicar el estado que ya vive en el log, que es justo la clase de
 * error que `APRENDER_NO_PARCHEAR.md` llama *"estado derivado guardado en su propio slot, aparte de
 * su fuente"*.
 *
 * ⚠️ La línea usa `step = 'vencimiento'`, que NO está en `DEPLOYMENT_STEP_ORDER` de la SPA ni en
 * los pipelines de `ClaudeClientOpsController`. No rompe nada —`get_step_status()` solo consulta
 * etapas conocidas—: la línea aparece en la consola cruda del panel y no en el timeline de etapas.
 *
 * **La detección de esta clase, para el próximo que la busque** (`APRENDER_NO_PARCHEAR.md` pide un
 * comando, no una intención):
 *
 * ```bash
 * grep -rn "deployment_status" app/ | grep -v "===\|!==\|in_array"
 * ```
 *
 * Y la pregunta de una línea por cada par (columna_de_estado, valor_en_curso) del sistema: *¿qué
 * proceso saca una fila de este estado si el que la puso ahí no vuelve?* Si la respuesta es "el
 * mismo que la puso", no hay respuesta. Queda pendiente correrla sobre `client_installations` y
 * `client_ecommerce_installations`, que tienen sus propios estados en curso y su propio
 * `assert_no_running_installation`, y que nadie midió todavía.
 */
class VencerDeploymentsColgados extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'deployments:vencer-colgados
                            {--minutos= : Umbral en minutos; pisa el configurado en admin_settings}';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Pasa a failed los deployments que quedaron colgados en running sin reportar actividad';

    /**
     * Clave de `admin_settings` con el umbral en minutos.
     *
     * @var string
     */
    const KEY_TIMEOUT_MINUTOS = 'deployment_timeout_minutos';

    /**
     * Umbral por defecto, en minutos.
     *
     * 🔴 45 y no los 15 de `ClaudeClientOpsController::STALE_MINUTOS`, a propósito: ese umbral
     * REPORTA (`salud.deployment_stale`) y este DESTRUYE ESTADO, así que va más flojo. Un
     * `compile_spa` con `npm ci` + `npm run build` sobre el VPS puede pasar varios minutos sin
     * escribir una línea de log, y vencer un deployment sano —dejándolo `failed` cuando en realidad
     * está a mitad de subir archivos— es peor que dejarlo colgado un rato más.
     *
     * Pero el argumento que de verdad sostiene el número es el de abajo, en el piso.
     *
     * @var int
     */
    const DEFAULT_TIMEOUT_MINUTOS = 45;

    /**
     * Margen, en minutos, entre el techo del job y el piso del umbral.
     *
     * @var int
     */
    const MARGEN_SOBRE_EL_JOB_MINUTOS = 5;

    /**
     * 🔴 EL INVARIANTE DE TODO ESTE COMANDO: el umbral tiene que ser MAYOR que el `$timeout` del
     * job, si no este comando mata procesos VIVOS.
     *
     * Lo único que hace seguro vencer un deployment es la aritmética: el worker guillotina
     * `RunDeploymentJob` a los `$timeout` segundos, así que ningún proceso vivo puede llegar al
     * umbral. Con un piso por debajo de ese techo, un `deployment_timeout_minutos` mal cargado en
     * `admin_settings` —o un `--minutos` tipeado apurado— marca `failed` un upgrade cuyo worker
     * está corriendo `npm run build` en ese mismo momento. Y `failed` es un estado del que las dos
     * puertas dejan arrancar de nuevo (`DeploymentController::start_json`,
     * `deploy_configure_system_json`, y `siguiente_accion` se lo recomienda a Claude): quedarían
     * **dos `DeploymentService` por SSH sobre el hosting del mismo cliente**, uno descomprimiendo
     * la API mientras el otro corre migraciones. Es la única forma de que este comando haga más
     * daño del que arregla.
     *
     * Por eso el piso NO es un número redondo elegido a ojo: se deriva de
     * `RunDeploymentJob::TIMEOUT_SEGUNDOS`. Si alguien sube el timeout del job, el piso sube solo.
     *
     * ⚠️ La guillotina del worker existe solo si el CLI del servidor tiene `pcntl`
     * (`Worker::supportsAsyncSignals()`). Sin `pcntl`, `$timeout` no rige nada — es la misma clase
     * de error del 13/8/2026, donde `$timeout = 600` tampoco regía bajo `afterResponse()`. Lo que
     * sigue protegiendo en ese escenario es que el ancla mira la ÚLTIMA LÍNEA DE LOG, no el
     * arranque: un pipeline vivo escribe entre paso y paso, y solo correría riesgo un único paso de
     * más de 45 minutos sin una sola línea.
     *
     * @return int
     */
    public static function min_timeout_minutos(): int
    {
        return (int) ceil(RunDeploymentJob::TIMEOUT_SEGUNDOS / 60) + self::MARGEN_SOBRE_EL_JOB_MINUTOS;
    }

    /**
     * Techo del umbral: doce horas. Más que eso ya no es "colgado", es "abandonado".
     *
     * @var int
     */
    const MAX_TIMEOUT_MINUTOS = 720;

    /**
     * Identificador de etapa con el que se escribe la línea del vencimiento.
     *
     * @var string
     */
    const STEP_VENCIMIENTO = 'vencimiento';

    /**
     * Busca los deployments sin actividad y los pasa a `failed` con el motivo escrito.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        $timeout_minutos = $this->timeout_minutos();

        /* `whereNotNull('deployment_running_since')` no es defensivo: es la condición que decide.
         * Un upgrade que ya estaba en `running` ANTES de la migración que agregó la columna la
         * tiene en NULL, y de ese no se sabe hace cuánto está ahí — vencerlo sería inventar la
         * medición. Sale a mano, una sola vez. Y dejarlo implícito haría que un cambio futuro en el
         * operador empezara a vencer upgrades sin ninguna medición detrás. */
        $candidatos = ClientVersionUpgrade::query()
            ->where('deployment_status', 'running')
            ->whereNotNull('deployment_running_since')
            ->get();

        /* Contador de upgrades vencidos para la línea final. */
        $vencidos = 0;

        foreach ($candidatos as $upgrade) {
            $resultado = $this->vencer_upgrade($upgrade, $timeout_minutos);

            if ($resultado['vencido']) {
                $vencidos++;
            }
        }

        $this->info("Deployments colgados vencidos: {$vencidos}");

        return 0;
    }

    /**
     * Vence UN upgrade: mide, reclama el tramo con un UPDATE condicionado y escribe el motivo.
     *
     * 🔴 Es el cuerpo del `foreach` de `handle()`, extraído a un método público **porque tiene un
     * segundo llamador**: `POST claude/upgrades/{id}/deploy/expire-stuck`, el destrabe a mano. Sin
     * esta extracción existirían dos definiciones de "vencer un deployment" —la del scheduler y la
     * del endpoint— y la que se quedaría vieja sería justo la que un humano invoca cuando algo ya
     * salió mal. La lógica y los tres números (`DEFAULT_TIMEOUT_MINUTOS`,
     * `MARGEN_SOBRE_EL_JOB_MINUTOS`, `MAX_TIMEOUT_MINUTOS`) son exactamente los de antes.
     *
     * ⚠️ CON UNA DIFERENCIA MEDIBLE, Y SE ESCRIBE PARA NO LLAMARLO "REFACTOR PURO" CUANDO NO LO ES:
     * el `$limite` (`Carbon::now()->subMinutes($timeout_minutos)`) antes se calculaba UNA vez, fuera
     * del `foreach` de `handle()`, y ahora se recalcula adentro de cada llamada, o sea una vez por
     * upgrade. El efecto es sub-segundo —el reloj corre unos milisegundos entre el primer candidato
     * y el último, así que el umbral del último es marginalmente más exigente—, no cambia ningún
     * desenlace real y no amerita volver atrás. Pero es un cambio de comportamiento, no cero, y
     * decir "esto es refactor puro" sobre algo que sí movió una cuenta es exactamente cómo se pierde
     * la confianza en los comentarios del resto del archivo.
     *
     * 🔴 El UPDATE es condicionado, y no un `save()` sobre el modelo que se leyó recién: entre la
     * lectura y este momento el worker puede haber terminado bien y haber escrito `success`,
     * `paused` o `failed`. Mismo criterio que el claim atómico de `RunDemoSetupService::run()`.
     *
     * 🔴 La condición incluye el ANCLA, no solo el estado —y acá se aparta del molde, que puede
     * condicionar solo por estado porque su ancla es la misma columna que filtra—. Sin ella queda
     * esta ventana: el worker termina el tramo y escribe `paused`, alguien aprieta post-cierre, el
     * upgrade vuelve a `running` con un sello NUEVO, y este UPDATE afecta 1 fila igual: mataríamos
     * un tramo recién nacido con el motivo de una medición que ya no aplica. Con el ancla adentro,
     * el claim dice "matá exactamente el tramo que medí" y esa carrera se cierra sola. Cuando el
     * claim no afecta ninguna fila se devuelve `motivo = 'claim_perdido'` y no se escribe nada: el
     * endpoint lo traduce a un 409.
     *
     * ⚠️ `$forzado` lo usa SOLO el endpoint, y saltea la comparación contra el umbral (no el claim,
     * que es lo que cierra la carrera y no se saltea nunca). El comando nunca lo pasa: su llamada
     * queda idéntica a lo que corría antes.
     *
     * @param ClientVersionUpgrade $upgrade         Upgrade a evaluar.
     * @param int                  $timeout_minutos Umbral aplicado, en minutos.
     * @param bool                 $forzado         True saltea la comparación con el umbral.
     *
     * @return array{vencido: bool, motivo: string|null, minutos_sin_actividad: int|null,
     *               timeout_minutos: int, motivo_escrito_en_el_log: string|null}
     */
    public function vencer_upgrade(ClientVersionUpgrade $upgrade, int $timeout_minutos, bool $forzado = false): array
    {
        $ultima_actividad = $this->ultima_actividad($upgrade);
        $minutos          = $ultima_actividad === null
            ? null
            : (int) $ultima_actividad->diffInMinutes(Carbon::now());

        if (! $forzado) {
            $limite = Carbon::now()->subMinutes($timeout_minutos);

            if ($ultima_actividad === null || ! $ultima_actividad->lessThan($limite)) {
                return $this->resultado_sin_vencer(
                    $ultima_actividad === null ? 'sin_medicion' : 'con_actividad',
                    $minutos,
                    $timeout_minutos
                );
            }
        }

        $afectadas = ClientVersionUpgrade::where('id', $upgrade->id)
            ->where('deployment_status', 'running')
            ->where('deployment_running_since', $upgrade->deployment_running_since)
            ->update(['deployment_status' => 'failed']);

        if ($afectadas !== 1) {
            return $this->resultado_sin_vencer('claim_perdido', $minutos, $timeout_minutos);
        }

        $motivo_escrito = $this->escribir_motivo($upgrade, $minutos, $timeout_minutos, $forzado);

        Log::warning('VencerDeploymentsColgados: deployment vencido por falta de actividad', [
            'upgrade_id'            => (int) $upgrade->id,
            'client_id'             => (int) $upgrade->client_id,
            'minutos_sin_actividad' => $minutos,
            'timeout_minutos'       => $timeout_minutos,
        ]);

        return [
            'vencido'                  => true,
            'motivo'                   => null,
            'minutos_sin_actividad'    => $minutos,
            'timeout_minutos'          => $timeout_minutos,
            'motivo_escrito_en_el_log' => $motivo_escrito,
        ];
    }

    /**
     * Resultado de un upgrade que NO se venció, con el motivo por el que se lo dejó como estaba.
     *
     * @param string   $motivo          sin_medicion | con_actividad | claim_perdido.
     * @param int|null $minutos         Minutos sin actividad medidos, si se pudo medir.
     * @param int      $timeout_minutos Umbral aplicado.
     *
     * @return array<string, mixed>
     */
    private function resultado_sin_vencer(string $motivo, $minutos, int $timeout_minutos): array
    {
        return [
            'vencido'                  => false,
            'motivo'                   => $motivo,
            'minutos_sin_actividad'    => $minutos,
            'timeout_minutos'          => $timeout_minutos,
            'motivo_escrito_en_el_log' => null,
        ];
    }

    /**
     * Umbral efectivo de la corrida automática, en minutos: lo que diga `admin_settings`, acotado.
     *
     * 🔴 Es público y estático porque `claude/*` publica este número en
     * `salud.vencimiento_minutos` y en `limites.vencimiento_minutos`, y **tiene que publicar el
     * valor que realmente se aplica, no la constante**. Publicar `DEFAULT_TIMEOUT_MINUTOS` a secas
     * hacía que el endpoint le mintiera a Claude apenas alguien tocara el setting — y la `nota` de
     * ese mismo bloque remite a este campo para explicar el comportamiento.
     *
     * @return int
     */
    public static function timeout_minutos_efectivo(): int
    {
        return self::acotar_minutos(
            (int) AdminSetting::get(self::KEY_TIMEOUT_MINUTOS, (string) self::DEFAULT_TIMEOUT_MINUTOS)
        );
    }

    /**
     * Umbral efectivo de ESTA corrida: el `--minutos` de la corrida a mano, o el configurado.
     *
     * @return int
     */
    private function timeout_minutos(): int
    {
        $opcion = $this->option('minutos');

        if ($opcion !== null && $opcion !== '') {
            return $this->acotar((int) $opcion);
        }

        return self::timeout_minutos_efectivo();
    }

    /**
     * Deja el umbral adentro de [piso, MAX]. Un 0 mal cargado en `admin_settings` vencería todo
     * deployment en curso en el primer tick; el piso lo sube por encima del techo del job. Aplica
     * también al `--minutos` de la corrida a mano, que es justo donde alguien apurado escribe un
     * número chico.
     *
     * @param int $minutos Valor crudo.
     *
     * @return int
     */
    private function acotar(int $minutos): int
    {
        $acotado = self::acotar_minutos($minutos);

        if ($acotado !== $minutos) {
            /* Se avisa, no se corrige en silencio: alguien que carga 10 esperando vencimientos
             * rápidos tiene que enterarse de que le quedaron en 35 y por qué. */
            $this->warn("Umbral pedido: {$minutos} min. Se aplica {$acotado} min. El piso es el techo "
                . 'del job más el margen: por debajo de eso este comando podría marcar fallido un '
                . 'deployment todavía vivo.');
        }

        return $acotado;
    }

    /**
     * El acotado propiamente dicho, sin consola de por medio para que lo pueda usar el getter
     * estático que consume `claude/*`.
     *
     * @param int $minutos Valor crudo.
     *
     * @return int
     */
    private static function acotar_minutos(int $minutos): int
    {
        $piso = self::min_timeout_minutos();

        if ($minutos < $piso) {
            return $piso;
        }

        if ($minutos > self::MAX_TIMEOUT_MINUTOS) {
            return self::MAX_TIMEOUT_MINUTOS;
        }

        return $minutos;
    }

    /**
     * Instante de la última señal de vida del tramo en curso.
     *
     * Es el más nuevo entre el sello de entrada a `running` y la última línea de `deployment_logs`
     * de ESTE upgrade. El sello tiene que entrar en la cuenta: entre que el endpoint responde y que
     * el worker escribe la primera línea pasa hasta un tick del scheduler, y sin él un deployment
     * recién arrancado sobre un upgrade viejo se vencería antes de empezar.
     *
     * 🔴 Se filtra por `client_version_upgrade_id`, nunca por `client_installation_id`:
     * `deployment_logs` es compartida con las instalaciones iniciales (`InstallationService`), que
     * tienen su propia máquina de estados y no son de este comando.
     *
     * @param ClientVersionUpgrade $upgrade Upgrade a medir.
     *
     * @return Carbon|null
     */
    private function ultima_actividad(ClientVersionUpgrade $upgrade): ?Carbon
    {
        $sello = $this->parsear_o_null($upgrade->deployment_running_since);

        $ultimo_log = $this->parsear_o_null(
            DB::table('deployment_logs')
                ->where('client_version_upgrade_id', $upgrade->id)
                ->max('created_at')
        );

        if ($ultimo_log === null) {
            return $sello;
        }

        if ($sello === null) {
            return $ultimo_log;
        }

        return $ultimo_log->greaterThan($sello) ? $ultimo_log : $sello;
    }

    /**
     * Escribe el motivo del vencimiento como línea de log del deployment.
     *
     * Va a `deployment_logs` y no a una columna porque `client_version_upgrades` **no tiene** un
     * campo de error de deployment: el log es donde el panel muestra el porqué (en rojo, por
     * `level = 'error'`, sin ningún cambio en la SPA) y de donde `GET claude/upgrades/{id}` saca
     * `logs.ultimo_error`.
     *
     * ⚠️ Los dos parámetros que se agregaron con la extracción de `vencer_upgrade()` sólo cambian el
     * texto en casos que la corrida automática NO puede alcanzar: `$minutos` en null pasa únicamente
     * cuando se vence a mano un upgrade sin ancla ni logs (el comando esos los saltea con su
     * `whereNotNull`), y `$forzado` sólo lo manda el endpoint. Para el scheduler el texto queda
     * palabra por palabra el que venía escribiendo.
     *
     * @param ClientVersionUpgrade $upgrade         Upgrade vencido.
     * @param int|null             $minutos         Minutos sin actividad, o null si no se pudo medir.
     * @param int                  $timeout_minutos Umbral aplicado.
     * @param bool                 $forzado         True si se venció a mano salteando el umbral.
     *
     * @return string El texto que quedó escrito en el log.
     */
    private function escribir_motivo(ClientVersionUpgrade $upgrade, $minutos, int $timeout_minutos, bool $forzado = false): string
    {
        /* 🔴 El texto dice que NO SE SABE cómo terminó, que es información distinta de "falló", y
         * avisa que el servidor del cliente quedó en un estado desconocido. Esa segunda mitad no
         * está en el molde de los demo setups y acá es lo más importante: reintentar un deployment
         * no es gratis como reintentar el armado de una demo. */
        $motivo = $minutos === null
            ? 'El deployment se venció a mano sin poder medir la actividad: no tiene deployment_running_since ni '
                . 'ninguna línea de log. El proceso que lo ejecutaba probablemente murió. Se marcó como fallido para '
                . 'poder reintentarlo: verificá en qué estado quedó el servidor del cliente antes de volver a arrancar.'
            : 'El deployment no reportó actividad en ' . $minutos . ' minutos (umbral: '
                . $timeout_minutos . '). El proceso que lo ejecutaba probablemente murió. Se marcó como '
                . 'fallido para poder reintentarlo: verificá en qué estado quedó el servidor del cliente '
                . 'antes de volver a arrancar.';

        if ($forzado) {
            $motivo .= ' 🔴 El vencimiento se pidió A MANO (force) desde claude/upgrades/'
                . (int) $upgrade->id . '/deploy/expire-stuck, con el motivo registrado en el log del sistema.';
        }

        $deployment_log = DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => self::STEP_VENCIMIENTO,
            'line'                      => $motivo,
            'level'                     => 'error',
            'created_at'                => Carbon::now(),
        ]);

        event(new DeploymentLogCreated($deployment_log));

        return $motivo;
    }

    /**
     * Convierte a Carbon lo que venga de la base, o null si no hay nada parseable.
     *
     * @param mixed $valor Fecha cruda.
     *
     * @return Carbon|null
     */
    private function parsear_o_null($valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof Carbon) {
            return $valor;
        }

        try {
            return Carbon::parse($valor);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
