<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\ClientVersionUpgrade;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Models\Lead;
use Carbon\Carbon;

/**
 * El candado del user setup: UNA sola definición de "este sistema ya opera o ya se configuró, y no se le vuelve a
 * aplicar el setup" (misión `puertas-del-user-setup`, 6/10/2026).
 *
 * 🔴 POR QUÉ EXISTE. El user setup de un cliente (`POST {api del cliente}/api/admin-sync/user-setup`) hace
 * `migrate:fresh` del otro lado: repetirlo sobre un negocio que opera le BORRA TODO. El admin llega a ese endpoint por
 * cuatro puertas —el job de `claude/implementations/{id}/user-setup`, el modo automático de la conversación, el botón
 * `user_setup` del panel y el botón de LEADS (`RunUserSetupService`)— y hasta esta misión solo la primera tenía los
 * chequeos (eran métodos protegidos de `ClaudeImplementationOpsController`). Las otras tres le pegaban al mismo
 * endpoint sin mirar nada. Dos definiciones de "ya opera" se desincronizan sin que nada lo denuncie, así que la
 * definición vive acá y las cuatro puertas la usan.
 *
 * Dos cosas distintas, y no hay que mezclarlas:
 *  - Las PROTECCIONES son los chequeos que definen "vaciaría algo que ya opera o ya se configuró": instalación anterior
 *    a la implementación, sistema vivo, user setup ya aplicado por el camino de leads, candado de la implementación
 *    lleno. Son las mismas en todas las puertas (`protecciones_de_implementacion()`).
 *  - Las PRECONDICIONES (formulario enviado, instalación completada, API activa con URL, sin otro setup en curso) hablan
 *    de "está listo para aplicarse", no de "vaciaría algo vivo", y cada puerta decide cuáles exige.
 *
 * 🔴 `plan_estricto()` es el plan de nueve chequeos de `claude/implementations/{id}/user-setup`, MOVIDO tal cual del
 * controlador: mismos nombres, mismos textos, mismo orden y mismas claves. Viajan en las respuestas de `claude/*` y la
 * skill las lee: no se agregan claves a los arrays de chequeo (`chequeo`, `ok`, `detalle`).
 */
class UserSetupCandadoService
{
    /**
     * Minutos después de los cuales un user setup que dice `en_curso` se da por colgado.
     *
     * 🔴 Existe porque `en_curso` es un estado que escribe el endpoint ANTES de despachar el job y que el job
     * reemplaza por `ok` o `error` al terminar. Si el worker muere sin pasar ni por `handle()` ni por `failed()` (un
     * `kill -9`, un reinicio del servidor) el estado se queda en `en_curso` PARA SIEMPRE, y como `user-setup` frena con 409
     * mientras haya uno en curso, la implementación quedaría trabada sin que ninguna llamada pueda destrabarla. 45 minutos
     * son casi el doble del techo del job (1500 s) y quedan por encima del `retry_after` de la cola (2400 s): para entonces
     * el worker ya tuvo ocasión de recuperar el job y descartarlo. Pasado ese tiempo el estado se REPORTA igual (con
     * `colgado: true`) y el endpoint deja volver a intentar.
     *
     * ⚠️ "Colgado" es "no hubo señal en 45 minutos", NO "el job no pudo seguir vivo": no se sabe qué pasó con el proceso, y
     * afirmarlo sería mentir. Lo que hace seguro reintentar es otra cosa: cada intento lleva un token (su `iniciado_at`) y
     * un job viejo que arranque tarde se descarta solo, sin llamar al cliente, porque el registro ya es de otro intento. Y
     * del otro lado empresa-api toma un candado y contesta 409 si hay otro corriendo, y ese 409 vuelve como error, sin
     * reintento.
     */
    const MINUTOS_PARA_DAR_POR_COLGADO = 45;

    /* ==============================================================================================
     | El plan estricto (claude/implementations/{id}/user-setup, el job y el modo automático)
     |============================================================================================= */

    /**
     * Los nueve chequeos del user setup.
     *
     * Lee, no escribe. Los tres primeros son los del `user_setup_gate()` del panel, con dos precisiones: el segundo exige
     * la etapa 2 EXACTA (el panel acepta cualquiera desde la 2, y re-aplicar en la 3 o después le borra al cliente lo que
     * ya cargó) y el tercero mira la última instalación REAL (`completa`) de la API activa y no "la última del cliente",
     * porque con el par de filas (real + esqueleto) la última por id es el esqueleto, que termina después y no tiene nada
     * que ver con el sistema al que se le va a pegar.
     *
     * 🔴 Las PROTECCIONES (instalacion_de_esta_implementacion, sin_sistema_vivo, lead_sin_user_setup y sin_aplicar_antes)
     * salen de `protecciones_de_implementacion()`, que es lo que comparten todas las puertas; acá solo se intercalan en el
     * orden de siempre.
     *
     * @param Implementation $implementation La implementación.
     * @param Client         $client         Su cliente.
     *
     * @return array<string, mixed> `chequeos`, `aplicado` (bool: el candado está lleno), `en_curso` (bool: hay uno
     *                              corriendo y no está colgado), `endpoint` (string|null).
     */
    public function plan_estricto(Implementation $implementation, Client $client): array
    {
        // Los chequeos, en el orden en que se muestran.
        $chequeos = [];

        /* 1. El formulario. */
        $chequeos[] = $this->chequeo_del_formulario($implementation, $client);

        /* 2. La etapa: EXACTAMENTE la 2, como `install`. Antes no hay sistema instalado; después el negocio puede
           estar operando y `migrate:fresh` le borraría lo que cargó. */
        $chequeos[] = $this->chequeo_de_la_etapa_2($implementation);

        /* 3 y 4. La instalación real de la API activa y la URL a la que se le pega. */
        $activa = $client->active_client_api_id === null
            ? null
            : ClientApi::where('id', (int) $client->active_client_api_id)->where('client_id', $client->id)->first();

        $instalacion = $activa === null
            ? null
            : ClientInstallation::where('client_id', $client->id)
                ->where('kind', ClientInstallation::KIND_COMPLETA)
                ->where('client_api_id', $activa->id)
                ->orderByDesc('id')
                ->first();

        $instalada = $instalacion !== null && $instalacion->status === 'completada';

        $chequeos[] = $this->chequeo(
            'instalacion_completada',
            $instalada,
            $instalacion === null
                ? 'No hay ninguna instalación completa sobre la API activa: instalá primero (POST claude/implementations/{id}/install).'
                : ($instalada
                    ? 'La instalación ' . (int) $instalacion->id . ' de la API activa está completada.'
                    : 'La última instalación completa de la API activa (' . (int) $instalacion->id . ') está en "' . $instalacion->status . '", no en completada.')
        );

        /* 🔴 La misma URL que va a usar `trigger_user_setup()`: normalizada (con `/public` en hosting compartido, sin él en
           VPS). Con la URL cruda de un cliente nuevo de shared el POST daba 404; el dry-run tiene que mostrar el destino REAL. */
        $url = $activa === null ? '' : (new ClientEmpresaApiUrlResolver())->normalize_api_base_url($activa->url, $activa->hosting_type);

        $chequeos[] = $this->chequeo(
            'client_api_activa',
            $url !== '',
            $url !== '' ? 'La API activa del cliente es ' . $url . '.' : 'El cliente no tiene una API activa con URL (clients.active_client_api_id).'
        );

        /* 4b a 5. Las PROTECCIONES, las mismas de todas las puertas: la instalación es de ESTA implementación, el cliente no
           tiene un sistema vivo, el camino de leads no lo configuró ya y el candado está vacío. Sin instalación completada
           la primera "no aplica" (el chequeo de arriba ya está en false). */
        foreach ($this->protecciones_de_implementacion($implementation, $client, $instalada ? $instalacion : null) as $proteccion) {
            $chequeos[] = $proteccion;
        }

        /* 6. Otro en curso. Uno colgado (más de 45 minutos) no cuenta: ver MINUTOS_PARA_DAR_POR_COLGADO. */
        $aplicado = $implementation->user_setup_executed_at !== null;
        $en_curso = $this->chequeo_sin_setup_en_curso($implementation, $aplicado);

        $chequeos[] = $en_curso['chequeo'];

        return [
            'chequeos' => $chequeos,
            'aplicado' => $aplicado,
            'en_curso' => $en_curso['en_curso'],
            'endpoint' => $url === '' ? null : rtrim($url, '/') . '/api/admin-sync/user-setup',
        ];
    }

    /**
     * El chequeo `formulario_enviado` del plan estricto.
     *
     * @param Implementation $implementation La implementación.
     * @param Client         $client         Su cliente.
     *
     * @return array<string, mixed>
     */
    private function chequeo_del_formulario(Implementation $implementation, Client $client): array
    {
        // ¿El cliente envió el formulario? (con la precisión de `formulario_enviado()`).
        $formulario = $this->formulario_enviado($implementation, $client);

        return $this->chequeo(
            'formulario_enviado',
            $formulario,
            $formulario ? 'El cliente envió el formulario.' : 'Todavía no se completó el formulario (etapa 1): el payload no tendría datos reales.'
        );
    }

    /**
     * El chequeo `etapa_2`: la implementación está EXACTAMENTE en la etapa 2.
     *
     * Es de las dos listas a la vez: en el plan estricto es una precondición más; en el panel es una protección que se puede
     * forzar (con la etapa MAYOR a 2; con una menor falla el duro "todavía no llegó a la etapa 2").
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array<string, mixed>
     */
    private function chequeo_de_la_etapa_2(Implementation $implementation): array
    {
        // ¿Está en la etapa 2 exacta?
        $etapa_ok = (int) $implementation->current_stage === 2;

        return $this->chequeo(
            'etapa_2',
            $etapa_ok,
            $etapa_ok
                ? 'La implementación está en la etapa 2.'
                : 'La implementación está en la etapa ' . (int) $implementation->current_stage . ': el user setup solo se aplica en la etapa 2 (antes no hay sistema '
                    . 'instalado; después el negocio puede estar operando y migrate:fresh le borraría lo que cargó).'
        );
    }

    /**
     * El chequeo `sin_setup_en_curso`: no hay otro user setup corriendo (uno colgado, de más de 45 minutos, no cuenta).
     *
     * @param Implementation $implementation La implementación (con sus etapas, o se cargan).
     * @param bool           $aplicado       true = el candado (`user_setup_executed_at`) está lleno: un registro viejo en
     *                                       `en_curso` ya no importa.
     *
     * @return array{en_curso: bool, chequeo: array<string, mixed>} Si hay uno corriendo y el chequeo para mostrar.
     */
    private function chequeo_sin_setup_en_curso(Implementation $implementation, bool $aplicado): array
    {
        // El registro del job (`stage 2 data.user_setup`).
        $registro = $this->registro_del_user_setup($implementation);

        // ¿Hay uno corriendo ahora? (en_curso y no colgado).
        $en_curso = ! $aplicado && (isset($registro['estado']) ? $registro['estado'] : '') === 'en_curso' && ! $this->esta_colgado($registro);

        $chequeo = $this->chequeo(
            'sin_setup_en_curso',
            ! $en_curso,
            $en_curso
                ? 'Ya hay un user setup en curso (arrancó ' . (isset($registro['iniciado_at']) ? (string) $registro['iniciado_at'] : 'sin fecha') . '): esperá a que termine.'
                : (! $aplicado && (isset($registro['estado']) ? $registro['estado'] : '') === 'en_curso'
                    ? 'No hubo señal en ' . self::MINUTOS_PARA_DAR_POR_COLGADO . ' minutos (el registro sigue en en_curso sin resultado): se da por colgado. '
                        . ($this->llamo_antes_de_colgarse($registro)
                            ? 'El job SÍ llegó a llamar al cliente (llamada_iniciada_at): pudo haber corrido, así que hay que elegir `conciliar` o `reintentar`.'
                            : 'El job nunca llegó a llamar al cliente: se puede volver a intentar con la llamada normal; el job viejo, si arranca, se descarta solo.')
                    : 'No hay ninguno en curso.')
        );

        return ['en_curso' => $en_curso, 'chequeo' => $chequeo];
    }

    /* ==============================================================================================
     | Las protecciones: lo que define "este sistema ya opera o ya se configuró"
     |============================================================================================= */

    /**
     * Las cuatro PROTECCIONES de una implementación, en este orden: `instalacion_de_esta_implementacion`,
     * `sin_sistema_vivo`, `lead_sin_user_setup` y `sin_aplicar_antes`.
     *
     * 🔴 Es la definición de "vaciaría algo que ya opera o ya se configuró" y la usan TODAS las puertas del user setup
     * (el plan estricto de `claude/*`, el modo automático, el botón del panel y el punto de llamada
     * `ImplementationUserSetupService::trigger_user_setup()`). A propósito NO incluye la etapa: la etapa es de cada puerta
     * (hay tests y llamadores que le piden el servicio a una implementación en la etapa 1).
     *
     * Lee, no escribe. Los textos son los de siempre de `claude/*`: no se cambian (viajan en sus respuestas).
     *
     * @param Implementation          $implementation        La implementación.
     * @param Client                  $client                Su cliente.
     * @param ClientInstallation|null $instalacion_completada La instalación completada contra la que se compara el arranque
     *                                                        de la implementación. Null = "todavía no hay una": el chequeo no
     *                                                        aplica. Quien llama la resuelve (ver
     *                                                        `instalacion_completada_del_cliente()`).
     *
     * @return array<int, array<string, mixed>> Los cuatro chequeos, cada uno con `chequeo`, `ok` y `detalle`.
     */
    public function protecciones_de_implementacion(Implementation $implementation, Client $client, ?ClientInstallation $instalacion_completada = null): array
    {
        // Los chequeos, en orden.
        $chequeos = [];

        // La instalación contra la que se compara (null = no hay una completada).
        $instalacion = $instalacion_completada;
        $instalada   = $instalacion !== null;

        /* 1. 🔴 La instalación es de ESTA implementación. Una instalación completada ANTERIOR al arranque de la
           implementación es un sistema que ya existía (instalado por afuera de este camino, por /instalar-cliente, a mano o
           por otra implementación): puede estar operando, y migrate:fresh le borraría todo. Sin instalación completada
           todavía no aplica. */
        $posterior = ! $instalada
            || $implementation->started_at === null
            || $instalacion->created_at === null
            || $instalacion->created_at->gte($implementation->started_at);

        $chequeos[] = $this->chequeo(
            'instalacion_de_esta_implementacion',
            $posterior,
            ! $instalada
                ? 'No aplica todavía: no hay una instalación completada.'
                : ($posterior
                    ? 'La instalación ' . (int) $instalacion->id . ' es posterior al arranque de la implementación: la hizo este camino.'
                    : 'La instalación completada (' . (int) $instalacion->id . ', del ' . $instalacion->created_at->format('d/m/Y H:i') . ') es ANTERIOR al arranque de la '
                        . 'implementación (' . $implementation->started_at->format('d/m/Y H:i') . '): es un sistema que ya existía y puede estar operando. 🔴 migrate:fresh '
                        . 'le borraría todo. Si de verdad hace falta, se hace desde el panel, con una persona mirando.')
        );

        /* 2. 🔴 Que el cliente no tenga ya un sistema vivo (el mismo chequeo que el alta y `install`: un cliente al que el admin ya le
           desplegó versiones). El user setup es la acción que VACÍA la base, y es la que más lo necesita. */
        $vivo = $this->sistema_vivo($client);

        $chequeos[] = $this->chequeo(
            'sin_sistema_vivo',
            ! $vivo['vivo'],
            $vivo['vivo']
                ? 'El cliente ya tiene un sistema vivo: ' . implode(' ', $vivo['motivos']) . ' 🔴 migrate:fresh le borraría lo que tiene. Si de verdad hace '
                    . 'falta, se hace desde el panel, con una persona mirando.'
                : 'Sin señales de un sistema ya instalado (el cliente no tiene actualizaciones registradas).'
        );

        /* 3. 🔴 Que el user setup no se haya aplicado ya por el camino de LEADS (`RunUserSetupService`: el que usa
           /instalar-cliente para crear al dueño). Ese camino no escribe el candado de la implementación, así que sin
           esto el candado de abajo no se entera. `sin_confirmar` es "la llamada salió y no se sabe cómo terminó". */
        $estado_del_lead = $this->estado_del_user_setup_del_lead($client);
        $lead_aplicado   = in_array($estado_del_lead, ['ejecutandose', 'exitoso', RunDemoSetupService::ESTADO_SIN_CONFIRMAR], true);

        $chequeos[] = $this->chequeo(
            'lead_sin_user_setup',
            ! $lead_aplicado,
            $lead_aplicado
                ? 'El lead del que salió este cliente tiene el user setup en estado "' . $estado_del_lead . '": el sistema ya se configuró (o se está configurando) '
                    . 'por el camino de leads. 🔴 Aplicarlo de nuevo VACÍA la base del cliente (migrate:fresh). Verificá el sistema del cliente (`motor <cliente> '
                    . 'metricas`: ¿existe el dueño?) y seguí con la verificación; si de verdad hace falta re-aplicar, se hace desde el panel, con una persona mirando.'
                : 'El user setup no se aplicó por el camino de leads.'
        );

        /* 4. El candado: ya aplicado = nunca más por acá. */
        $aplicado = $implementation->user_setup_executed_at !== null;

        $chequeos[] = $this->chequeo(
            'sin_aplicar_antes',
            ! $aplicado,
            $aplicado
                ? 'El user setup ya se aplicó el ' . $implementation->user_setup_executed_at->format('d/m/Y H:i') . '. 🔴 Re-aplicarlo VACÍA la base del cliente '
                    . '(migrate:fresh): este camino no tiene forzar. Si de verdad hace falta, se hace desde el panel, con una persona mirando.'
                : 'Todavía no se aplicó.'
        );

        return $chequeos;
    }

    /**
     * Los chequeos que fallaron (los que tienen `ok === false`), en el mismo orden.
     *
     * @param array<int, array<string, mixed>> $chequeos Una lista de chequeos (`chequeo`, `ok`, `detalle`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function bloqueos(array $chequeos): array
    {
        // Los que fallaron.
        $bloqueos = [];

        foreach ($chequeos as $chequeo) {
            if (isset($chequeo['ok']) && $chequeo['ok'] === false) {
                $bloqueos[] = $chequeo;
            }
        }

        return $bloqueos;
    }

    /**
     * Los nombres de los chequeos que fallaron, en castellano corrido: "a", "a y b", "a, b y c". Para armar mensajes.
     *
     * @param array<int, array<string, mixed>> $bloqueos Los chequeos que fallaron (ver `bloqueos()`).
     *
     * @return string Vacío si no hay ninguno.
     */
    public function frase_de_bloqueos(array $bloqueos): string
    {
        // Los nombres, uno por chequeo.
        $nombres = [];

        foreach ($bloqueos as $bloqueo) {
            $nombres[] = (string) $bloqueo['chequeo'];
        }

        if (count($nombres) === 0) {
            return '';
        }

        if (count($nombres) === 1) {
            return $nombres[0];
        }

        // El último va con "y": "a, b y c".
        $ultimo = array_pop($nombres);

        return implode(', ', $nombres) . ' y ' . $ultimo;
    }

    /* ==============================================================================================
     | Lo que mira el candado (movido del controlador de claude/implementations/*)
     |============================================================================================= */

    /**
     * ¿Tiene el cliente un sistema vivo (ya instalado, ya en producción)?
     *
     * 🔴 La señal es su historial de actualizaciones (`client_version_upgrades`): un sistema al que se le actualizó la
     * versión es un sistema que ya estuvo en producción, y CUALQUIER fila cuenta, en el estado que sea —una pendiente o
     * una fallida también son un cliente al que el admin ya le despliega versiones—. Es lo que deja un cliente instalado
     * por afuera de este camino (a mano, por `/instalar-cliente`, por el panel), que `instalaciones_previas` no ve porque
     * solo mira las instalaciones que hizo éste.
     *
     * ⚠️ El dato "este sistema ya está configurado" vive en `client_version_upgrades.sistema_configurado_at`, NO en
     * `clients`: la tabla `clients` no tiene esa columna. Se cuenta por las filas de actualizaciones, que incluyen a las que
     * ya llegaron a configurar el sistema.
     *
     * @param Client $client El cliente.
     *
     * @return array{vivo: bool, motivos: array<int, string>}
     */
    public function sistema_vivo(Client $client): array
    {
        // Las actualizaciones del cliente, la última primero.
        $actualizaciones = ClientVersionUpgrade::where('client_id', $client->id)->orderByDesc('id')->get(['id', 'status', 'sistema_configurado_at']);
        $cantidad        = $actualizaciones->count();

        if ($cantidad === 0) {
            return ['vivo' => false, 'motivos' => []];
        }

        $ultima  = $actualizaciones->first();
        $motivos = [
            'Tiene ' . $cantidad . ' ' . ($cantidad === 1 ? 'actualización registrada' : 'actualizaciones registradas') . ' en client_version_upgrades (la última: id '
                . (int) $ultima->id . ', estado "' . (string) $ultima->status . '").',
        ];

        if ($actualizaciones->whereNotNull('sistema_configurado_at')->count() > 0) {
            $motivos[] = 'Alguna figura con el sistema configurado (sistema_configurado_at).';
        }

        return ['vivo' => true, 'motivos' => $motivos];
    }

    /**
     * El estado del user setup que dejó el camino de LEADS (`RunUserSetupService`) en el lead del que salió el cliente:
     * `pendiente`, `ejecutandose`, `exitoso`, `fallido` o `sin_confirmar`. Null si el cliente no salió de un lead (se creó
     * directo) o el lead no tiene estado.
     *
     * Lo mira el chequeo `lead_sin_user_setup` del user setup: ese camino llama al MISMO endpoint remoto
     * (`admin-sync/user-setup`, que hace `migrate:fresh`) y no escribe el candado de la implementación, así que sin este
     * dato el candado no se entera de que el sistema ya se configuró.
     *
     * @param Client $client El cliente.
     *
     * @return string|null
     */
    public function estado_del_user_setup_del_lead(Client $client): ?string
    {
        // El último lead promovido a este cliente.
        $lead = Lead::where('promoted_client_id', $client->id)->orderByDesc('id')->first();

        if ($lead === null || $lead->user_setup_status === null || trim((string) $lead->user_setup_status) === '') {
            return null;
        }

        return (string) $lead->user_setup_status;
    }

    /**
     * ¿El cliente envió el formulario?
     *
     * 🔴 ES EL GATE DEL PANEL (`user_setup_gate()` de `ImplementationActionService`), CON UNA PRECISIÓN. El panel da el
     * formulario por enviado si `form_submitted_at` está lleno O SI LA ETAPA 1 ESTÁ `completed`. Esa segunda mitad existe
     * porque la etapa 1 se completa sola al enviarse el formulario, pero también se completa cuando alguien aprieta "Avanzar
     * etapa" —y ahora también `advance` de Claude— SIN que el cliente haya cargado nada. En ese caso el user setup correría
     * con un `setup_data` vacío: `migrate:fresh` sobre el sistema del cliente y todos los valores por defecto, que es justo
     * lo que el formulario venía a evitar.
     *
     * Por eso acá la etapa 1 completada cuenta SOLO si además hay datos del formulario ya mapeados en `clients.setup_data`
     * (que es lo que consume el user setup): el caso legítimo es el de Lucas cargando las respuestas desde el panel
     * ("Editar" datos recolectados), que mapea `setup_data` pero no llena `form_submitted_at`.
     *
     * @param Implementation $implementation La implementación.
     * @param Client         $client         Su cliente.
     *
     * @return bool
     */
    public function formulario_enviado(Implementation $implementation, Client $client): bool
    {
        if ($implementation->form_submitted_at !== null) {
            return true;
        }

        // La etapa 1 de esta implementación.
        $primera = ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', 1)->first();

        return $primera !== null
            && $primera->status === 'completed'
            && is_array($client->setup_data)
            && count($client->setup_data) > 0;
    }

    /**
     * El registro del user setup guardado en la etapa 2 (`data.user_setup`), o un array vacío.
     *
     * @param Implementation $implementation La implementación (con sus etapas, o se cargan).
     *
     * @return array<string, mixed>
     */
    public function registro_del_user_setup(Implementation $implementation): array
    {
        $implementation->loadMissing('stages');

        // La etapa 2: donde el job deja el registro.
        $etapa_2 = $implementation->stages->firstWhere('stage_number', 2);
        if ($etapa_2 === null || ! is_array($etapa_2->data)) {
            return [];
        }

        return isset($etapa_2->data['user_setup']) && is_array($etapa_2->data['user_setup'])
            ? $etapa_2->data['user_setup']
            : [];
    }

    /**
     * ¿Hace más de `MINUTOS_PARA_DAR_POR_COLGADO` que corre el user setup que dice `en_curso`?
     *
     * 🔴 Los minutos se cuentan desde que el job LLAMÓ al cliente (`llamada_iniciada_at`) y no desde que se encoló
     * (`iniciado_at`): con la cola atrasada el job puede tardar en arrancar, y darlo por colgado con la llamada recién salida
     * mandaría a reintentar un setup que está corriendo. Sin la marca (el job todavía no llamó, o nunca llamó) se cuenta desde
     * que se encoló. Una marca ilegible no cuenta: se cae al encolado.
     *
     * Un `en_curso` sin ninguna de las dos fechas (no debería pasar: las escribe el endpoint y el job) se da por colgado: sin
     * fecha no hay forma de saber desde cuándo corre y dejarlo trabado para siempre es peor que dejar reintentar.
     *
     * @param array<string, mixed> $registro El registro de la etapa 2.
     *
     * @return bool
     */
    public function esta_colgado(array $registro): bool
    {
        // Desde cuándo corre: la llamada y, si no hay marca, el encolado.
        $desde = $this->parsear_o_null(isset($registro['llamada_iniciada_at']) ? $registro['llamada_iniciada_at'] : null);
        if ($desde === null) {
            $desde = $this->parsear_o_null(isset($registro['iniciado_at']) ? $registro['iniciado_at'] : null);
        }
        if ($desde === null) {
            return true;
        }

        return $desde->lt(now()->subMinutes(self::MINUTOS_PARA_DAR_POR_COLGADO));
    }

    /**
     * ¿Un user setup que quedó `en_curso` llegó a llamar al sistema del cliente antes de colgarse?
     *
     * 🔴 Lo dice `llamada_iniciada_at`, que el job escribe bajo lock ANTES de llamar (ver `tomar_el_turno()`). Sin la marca el
     * job nunca arrancó (cola parada o atrasada) y no salió nada; con ella, la llamada pudo estar en vuelo o haber terminado
     * cuando se cortó la ejecución, y el setup PUDO HABER CORRIDO del otro lado.
     *
     * @param array<string, mixed> $registro El registro de la etapa 2.
     *
     * @return bool
     */
    public function llamo_antes_de_colgarse(array $registro): bool
    {
        return isset($registro['llamada_iniciada_at']) && trim((string) $registro['llamada_iniciada_at']) !== '';
    }

    /**
     * Un chequeo de los que devuelven `install` y `user-setup`.
     *
     * @param string $nombre  Qué se chequeó.
     * @param bool   $ok      Si está bien.
     * @param string $detalle Lo que se vio, en castellano.
     *
     * @return array<string, mixed>
     */
    public function chequeo($nombre, $ok, $detalle): array
    {
        return ['chequeo' => $nombre, 'ok' => (bool) $ok, 'detalle' => (string) $detalle];
    }

    /**
     * Parsea una fecha o devuelve null si no se puede (la misma que usa el trait de los controladores de `claude/*`).
     *
     * @param mixed $valor La fecha, como texto o `DateTimeInterface`.
     *
     * @return Carbon|null
     */
    private function parsear_o_null($valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor, config('app.timezone'));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
