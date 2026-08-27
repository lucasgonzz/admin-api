<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\DemoMedia;
use App\Models\Lead;
use App\Services\DemoHitosService;
use App\Services\DemoPathResolver;
use App\Services\DemoPlanResolver;
use App\Services\ImplementationSettings;
use App\Services\LeadDemoFormMapper;
use App\Services\LeadDemoSettings;
use App\Services\ClientEmpresaApiUrlResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Service responsable de disparar DemoSetupHelper::run en la ERP API
 * de la demo asignada al Lead, vía el endpoint admin-sync/demo-setup.
 *
 * Mismo patrón de llamada HTTP que PublishVersionService para mantener
 * consistencia en admin-api (timeout/retries configurables por services.php,
 * sin autenticación adicional para este flujo, logging en caso de excepción).
 */
class RunDemoSetupService
{
    /**
     * Minutos después del inicio del turno a partir de los cuales se arma la instancia con los
     * defaults del catálogo para un lead que nunca completó el formulario (misión 46, pieza 2).
     *
     * No es cero a propósito: el que está tipeando el formulario tiene que GANARLE al fallback.
     * Con 0, dos minutos de demora bastarían para que le armen la demo con respuestas que no son
     * las suyas. Es el borde de `contexto/demo_experiencia.md` §3.16 B.
     */
    const MARGEN_SIN_FORMULARIO_MINUTOS = 5;

    /** Zona horaria de referencia de todo el ciclo de demo (misma que usan el resto de los comandos). */
    const TZ = 'America/Argentina/Buenos_Aires';

    /**
     * Estado de `demo_setup_status` para una corrida cuyo desenlace NO se conoce.
     *
     * 🔴 No es un sinónimo elegante de `fallido`: es información distinta. `fallido` afirma que el
     * armado salió mal; `sin_confirmar` dice que el admin dejó de escuchar (timeout de la llamada)
     * o que la instancia avisó que ya tenía una corrida viva (HTTP 409). En los dos casos la
     * corrida del otro lado puede estar sembrando la base en este mismo instante, así que mostrar
     * el botón "Correr demo setup ahora" encima sería exactamente el segundo `migrate:fresh` que
     * esta misión vino a evitar.
     *
     * Cabe sin migración: `demo_setup_status` ya es un string(20) con default `pendiente`.
     *
     * 🔴 Y NO es un estado terminal: lo destraba `leads:vencer-demo-setups-colgados`, que es otro
     * proceso distinto del que lo puso acá. Es la regla que dejó escrita el 13/8/2026 en
     * APRENDER_NO_PARCHEAR después de que tres leads quedaran colgados en `ejecutandose` con el
     * error en NULL para siempre. Un estado intermedio sin vencimiento es una fuga, no un estado.
     */
    const ESTADO_SIN_CONFIRMAR = 'sin_confirmar';

    /**
     * Servicio que centraliza el cálculo de vencimiento del token de ingreso a la demo
     * (extraído de acá a DemoIngresoTokenService en el grupo 233, prompt 05, para que el
     * setup inicial y la reemisión manual desde el panel usen la misma lógica sin duplicarla).
     *
     * @var DemoIngresoTokenService
     */
    protected $demo_ingreso_token_service;

    /**
     * @param DemoIngresoTokenService|null $demo_ingreso_token_service Inyectable para tests.
     */
    public function __construct(?DemoIngresoTokenService $demo_ingreso_token_service = null)
    {
        $this->demo_ingreso_token_service = $demo_ingreso_token_service ?? new DemoIngresoTokenService();
    }

    /**
     * Ejecuta la demo remotamente y actualiza los campos de trazabilidad
     * del Lead (demo_setup_status / demo_setup_last_error / demo_setup_last_run_at).
     *
     * @param Lead $lead              Prospecto con demo seteada
     * @param bool $solo_si_pendiente true = claim atómico (misión 46): sólo corre si el lead
     *                                todavía está en `pendiente`, y la transición a `ejecutandose`
     *                                se hace condicional para que dos disparadores simultáneos no
     *                                lo corran dos veces. false (default) = comportamiento de
     *                                siempre, que es el del botón "Disparar setup demo" del panel:
     *                                re-correr a propósito aunque el estado sea `exitoso`.
     *
     * @return Lead El mismo Lead refrescado
     */
    public function run(Lead $lead, bool $solo_si_pendiente = false)
    {
        $lead->loadMissing('demo');
        $demo = $lead->demo;

        // Precondición: debe existir demo asignada para tomar su ERP API.
        if (is_null($demo)) {
            return $this->mark_failed($lead, 'El lead no tiene demo asignada.');
        }

        /**
         * URL de ERP API de la demo asignada al lead.
         * Se normaliza con la regla idempotente de /public, que solo aplica si la demo vive en
         * hosting compartido: en el VPS el docroot ya es public/ y el sufijo daría 404.
         */
        $resolver = new ClientEmpresaApiUrlResolver();
        $path_resolver = new DemoPathResolver();
        $erp_api_url = $resolver->normalize_demo_api_base_url(
            $demo->erp_api_url,
            $path_resolver->hosting_type($demo)
        );
        if ($erp_api_url === '') {
            return $this->mark_failed($lead, 'La demo asignada no tiene ERP API URL configurada.');
        }

        /* Marcamos el arranque para que el panel muestre el intento en curso.
         *
         * Con $solo_si_pendiente la transición es un CLAIM: un UPDATE condicionado a que el estado
         * siga siendo `pendiente`, y se sigue de largo si no afectó ninguna fila. Desde la misión 46
         * hay dos disparadores (el comando cada minuto y el POST del formulario), así que el update
         * incondicional de antes permitía que los dos entraran a la vez y le hicieran DOS
         * `migrate:fresh` a la misma instancia — con el lead adentro en el peor caso. La condición
         * viaja adentro del propio UPDATE, no en un `if` previo: un chequeo y después un update es
         * exactamente la ventana que hay que cerrar. */
        if ($solo_si_pendiente) {
            $claimed = Lead::where('id', $lead->id)
                ->where('demo_setup_status', 'pendiente')
                ->update([
                    'demo_setup_status' => 'ejecutandose',
                    'demo_setup_last_run_at' => now(),
                    'demo_setup_last_error' => null,
                ]);

            if ($claimed !== 1) {
                Log::info('RunDemoSetupService: el setup ya lo tomó otro disparador, no se corre de nuevo.', [
                    'lead_id' => $lead->id,
                ]);

                return $lead->refresh();
            }

            /* El UPDATE de arriba fue por query builder: la instancia en memoria todavía tiene el
             * estado viejo y el resto del método (y el llamador) lee de ella. */
            $lead->refresh();
        } else {
            $lead->update([
                'demo_setup_status' => 'ejecutandose',
                'demo_setup_last_run_at' => now(),
                'demo_setup_last_error' => null,
            ]);
        }

        /* El token de ingreso se asegura ANTES de armar el payload porque viaja adentro de él.
         * REUTILIZA el que el lead ya tenga: rotarlo acá y que después el POST no llegue a correr
         * (409 de la instancia, timeout) es lo que dejaba al panel mostrando un link que la demo
         * no conoce. El porqué completo está en el docblock del método. */
        $this->asegurar_token_de_ingreso($lead);

        // Último momento en que se puede congelar el plan: el lead que nunca completó el
        // formulario lo congela acá, con los defaults del catálogo, para que ninguno entre a la
        // demo sin roadmap. Si ya lo tenía congelado (caso normal, lo hizo el formulario) esto no
        // hace nada. Misión 48.
        $this->congelar_plan_si_falta($lead);

        $payload = $this->build_payload($lead);

        try {
            $response = Http::withHeaders([
                    'Accept'          => 'application/json',
                ])
                /* Techo propio y alto (900 s por default), no el genérico ×20 de los otros
                 * servicios. Una corrida sola del setup tardó 565,7 s medidos el 25/8/2026: con los
                 * 300 s de antes, el camino normal terminaba SIEMPRE en timeout mientras la
                 * instancia seguía sembrando la base tan campante. Ver el comentario largo de
                 * `services.client_api.demo_setup_timeout` en config/services.php. */
                ->timeout((int) config('services.client_api.demo_setup_timeout', 900))
                /* 🔴 SIN `->retry()`, y esto no se repone. Cualquiera que venga a "emparejarlo con
                 * el resto de los llamadores de este cliente HTTP" tiene que leer esto primero:
                 *
                 *  - En Laravel 8, con `tries > 1` una respuesta NO exitosa se relanza
                 *    (`PendingRequest::send`, línea 702 del vendor). O sea que el reintento no es
                 *    sólo para errores de red: un 500 del armado vuelve a hacer el POST y le
                 *    re-dispara a la instancia el `migrate:fresh` ENTERO 500 ms después. Es la
                 *    única llamada de este repo que dispara una operación destructiva del otro
                 *    lado; las demás (PublishVersionService y compañía) son idempotentes o
                 *    baratas, y por eso `config('services.client_api.retries')` sigue intacto: lo
                 *    comparten ellas, no esta.
                 *  - Reintentar un timeout del servidor da el mismo timeout. Duplica la espera con
                 *    cero probabilidad de éxito, y era justamente lo que empujaba a admin-api más
                 *    allá de su propio techo de ejecución.
                 *  - Y con `tries = 1` el cliente de Laravel deja de llamar solo a
                 *    `$response->throw()`: la respuesta no exitosa vuelve por el camino normal y la
                 *    maneja el `if` de acá abajo, que es lo que permite distinguir el 409 del 500.
                 *    Con reintentos, un 409 llegaba convertido en `RequestException` al `catch` y
                 *    con el mensaje truncado — indistinguible de un fallo real del armado.
                 *
                 * 🔴 Sí: esto le cambia el comportamiento también a la dinámica ACTUAL, que hasta
                 * la misión 60 conservaba los reintentos de la config. Es a propósito y está
                 * decidido en el plan del 25/8/2026: el argumento de arriba no depende de la
                 * dinámica del lead, porque la base que se vacía es la misma. Lo que pierde un lead
                 * 'actual' es el reintento que salvaba un 502 transitorio; lo que gana es que un
                 * 500 del armado no le dispare un segundo `migrate:fresh`. */
                ->post($erp_api_url . '/api/admin-sync/demo-setup', $payload);

            if ($response->successful()) {
                /* 🔴 Query builder y NO `$lead->update()`, por la misma razón por la que ya escriben
                 * así `mark_sin_confirmar()` y `mark_failed()`.
                 *
                 * Con Eloquent, `demo_setup_last_error => null` NO llegaba a la base: el modelo en
                 * memoria ya tenía esa columna en `null` desde el claim de arriba, así que asignarle
                 * `null` no lo ensucia y `save()` la deja afuera del UPDATE. El error que otro
                 * proceso hubiera escrito MIENTRAS esta corrida estaba en vuelo —típicamente el 409
                 * de un segundo disparo— sobrevivía intacto, y el panel terminaba mostrando
                 * "Estado: exitoso" con un mensaje de error colgado abajo. Medido en producción el
                 * 27/8/2026.
                 *
                 * `updated_at` a mano porque el query builder no estampa timestamps y el panel
                 * ordena por esa columna. Mismo criterio que los otros dos métodos. */
                Lead::where('id', $lead->id)
                    ->update([
                        'demo_setup_status'     => 'exitoso',
                        'demo_setup_last_error' => null,
                        'updated_at'            => now(),
                    ]);

                return $lead->refresh();
            }

            /* 🔴 409 = la instancia ya tiene un demo setup corriendo, y NO tocó la base para
             * decírnoslo (empresa-api toma un candado con flock antes de llamar al helper). Eso no
             * es un fallo del armado: es la confirmación de que hay otra corrida viva. Marcarlo
             * `fallido` haría reaparecer el botón del panel justo encima de esa corrida, que es la
             * secuencia exacta que vació la base de la demo.
             *
             * Compatibilidad hacia atrás: una instancia con un deploy anterior al 22/8/2026 nunca
             * devuelve 409, así que para ella esta rama simplemente no existe y todo sigue como
             * antes. No hace falta ninguna negociación de versión. */
            if ($response->status() === 409) {
                Log::info('RunDemoSetupService: la instancia ya tenía un demo setup corriendo (HTTP 409).', [
                    'lead_id' => $lead->id,
                    'demo_id' => $demo->id,
                ]);

                return $this->mark_sin_confirmar(
                    $lead,
                    'Ya hay un demo setup corriendo en la instancia. No se disparó otro; esperá a que termine el que está en curso.'
                );
            }

            /* 2000 y no 500: la columna es TEXT y el cuerpo de un 500 de Laravel trae el mensaje
             * de la excepción y el principio del stack, que es lo único que se tiene para saber
             * por qué falló el armado. Con 500 caracteres el mensaje útil quedaba cortado justo
             * cuando más falta hace.
             *
             * El resto de los códigos no exitosos sigue yendo a `fallido`, como siempre. */
            return $this->mark_failed(
                $lead,
                'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 2000)
            );
        } catch (ConnectionException $e) {
            /* 🔴 ANTES del catch genérico, y no es orden estético: un timeout de la llamada no es
             * un fallo del setup, es "no sé cómo terminó".
             *
             * `ConnectionException` es lo que tira el cliente HTTP de Laravel cuando se vence el
             * `timeout()` de arriba o cuando no se pudo conectar. Del otro lado el endpoint corre
             * con `ignore_user_abort(true)` y `set_time_limit(0)` a propósito: que nosotros dejemos
             * de escuchar NO detiene nada. La corrida sigue viva, sembrando la base, y en unos
             * minutos puede terminar bien. Escribir `fallido` ahí afirma algo que nadie midió, y
             * peor: devuelve el botón "Correr demo setup ahora" al panel, cuyo segundo click le
             * hace un `migrate:fresh` a la base que la primera corrida está llenando. Es la cadena
             * causal completa del bug que esta misión vino a cortar. */
            Log::warning('RunDemoSetupService@run: sin respuesta del setup (timeout o conexión caída). La corrida puede seguir viva.', [
                'lead_id' => $lead->id,
                'demo_id' => $demo->id,
                'error'   => $e->getMessage(),
            ]);

            return $this->mark_sin_confirmar(
                $lead,
                'Sin respuesta del setup (timeout). La corrida puede seguir viva en la instancia.'
            );
        } catch (\Throwable $e) {
            Log::error('RunDemoSetupService@run error: ' . $e->getMessage(), [
                'lead_id'   => $lead->id,
                'demo_id'   => $demo->id,
            ]);

            return $this->mark_failed($lead, 'Excepción: ' . $e->getMessage());
        }
    }

    /**
     * ¿Corresponde disparar el demo setup de este lead en este instante?
     *
     * 🔴 Regla "formulario + T-setup, lo que pase ULTIMO" (`contexto/demo_experiencia.md` §3.16 B).
     * No es una optimización: sin la condición del formulario, un turno a ahora+5 cae dentro de la
     * ventana de 15 minutos en el mismo instante en que se agenda y el setup corre ANTES de que el
     * lead conteste nada, así que la instancia se arma con los defaults del catálogo y las nueve
     * respuestas del formulario no cambian nada. Y sin sacar el tope superior, el lead que tarda en
     * completarlo deja de ser candidato apenas pasa su hora y no entra nunca. Misión 46.
     *
     * 🔴 Y vive acá, en un solo lugar, porque la necesitan DOS llamadores por motivos distintos: el
     * comando `leads:run-demo-setup` (cada minuto, red de seguridad y camino de los turnos agendados
     * para más adelante) y el POST del formulario de la página inmersiva, que dispara en el acto
     * para no perder hasta 60 segundos de los 5 minutos del lead. Dos copias de esta condición
     * coincidirían por casualidad, no por construcción — mismo criterio que `estados-turno.js`.
     *
     * La dinámica ACTUAL conserva exactamente el comportamiento de antes de la misión 46 —ventana
     * `[ahora, ahora + setup_minutos_antes]`, sin mirar el formulario— porque esos leads no tienen
     * página inmersiva y nunca van a tener `demo_form_completado_at`: aplicarles la condición del
     * formulario los dejaría sin setup para siempre.
     *
     * @param Lead        $lead Lead candidato, con demo_date y demo_start_time cargados.
     * @param Carbon|null $now  Instante de referencia; null = AppTime::now() (respeta el reloj
     *                          virtual de local).
     *
     * @return array{disparar: bool, motivo: string, inicio: Carbon|null}
     */
    public function evaluar_disparo(Lead $lead, ?Carbon $now = null): array
    {
        if ($now === null) {
            $now = AppTime::now(self::TZ);
        }

        $inicio = $this->parse_turno_datetime($lead->demo_date, (string) $lead->demo_start_time);
        if ($inicio === null) {
            return ['disparar' => false, 'motivo' => 'sin_hora_de_inicio', 'inicio' => null];
        }

        $setup_minutos = LeadDemoSettings::get_setup_minutos_antes();

        // Dinámica actual: idéntico a como estaba. Ninguna rama nueva la alcanza.
        if (! $lead->usa_experiencia_demo_nueva()) {
            $window_end = $now->copy()->addMinutes($setup_minutos);
            if ($inicio->lt($now) || $inicio->gt($window_end)) {
                return ['disparar' => false, 'motivo' => 'fuera_de_ventana', 'inicio' => $inicio];
            }

            return ['disparar' => true, 'motivo' => 'ventana_dinamica_actual', 'inicio' => $inicio];
        }

        /* Corte superior de la dinámica nueva: el turno vencido, no la hora de inicio. El fin sale
         * de demo_end_time y, si está vacío, de la duración configurada — mismo criterio que
         * DemoExperienciaController::build_turno(). */
        $fin = $this->parse_turno_datetime($lead->demo_date, (string) $lead->demo_end_time);
        if ($fin === null) {
            $fin = $inicio->copy()->addMinutes(LeadDemoSettings::get_duracion_minutos());
        }
        $fin_con_gracia = $fin->copy()->addMinutes(LeadDemoSettings::get_gracia_minutos_post());

        if ($now->gt($fin_con_gracia)) {
            return ['disparar' => false, 'motivo' => 'turno_vencido', 'inicio' => $inicio];
        }

        $formulario_completado = $lead->demo_form_completado_at !== null;

        // (a) Formulario completo y ya abrió la ventana de preparación.
        if ($formulario_completado) {
            if ($now->gte($inicio->copy()->subMinutes($setup_minutos))) {
                return ['disparar' => true, 'motivo' => 'formulario_y_ventana', 'inicio' => $inicio];
            }

            return ['disparar' => false, 'motivo' => 'formulario_pero_falta_para_la_ventana', 'inicio' => $inicio];
        }

        // (b) Sin formulario: el fallback con los defaults del catálogo, ya arrancado el turno.
        if ($now->gte($inicio->copy()->addMinutes(self::MARGEN_SIN_FORMULARIO_MINUTOS))) {
            return ['disparar' => true, 'motivo' => 'fallback_sin_formulario', 'inicio' => $inicio];
        }

        return ['disparar' => false, 'motivo' => 'sin_formulario_todavia', 'inicio' => $inicio];
    }

    /**
     * Combina la fecha de la demo con una hora en texto libre (HH:MM) en la timezone de referencia.
     *
     * Devuelve null si la hora está vacía o no se puede parsear, para que un dato mal cargado
     * saltee ese lead en vez de romper el batch entero.
     *
     * @param mixed  $demo_date Valor de `demo_date` del lead (Carbon por el cast, o null).
     * @param string $hora_str  Hora en texto libre; vacía = sin dato.
     *
     * @return Carbon|null
     */
    protected function parse_turno_datetime($demo_date, string $hora_str)
    {
        if ($demo_date === null || trim($hora_str) === '') {
            return null;
        }

        try {
            $fecha_str = $demo_date->copy()->setTimezone(self::TZ)->format('Y-m-d');

            return Carbon::parse("{$fecha_str} {$hora_str}", self::TZ);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Arma el array que se envía al endpoint admin-sync/demo-setup del
     * empresa-api destino. Replica los nombres de campos que consume
     * DemoSetupHelper::run.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    protected function build_payload(Lead $lead)
    {
        $payload = [
            // Datos visibles del User
            'name'          => $lead->contact_name,
            'company_name'  => $lead->company_name,
            'doc_number'    => $lead->doc_number,
            'email'         => $lead->email,
            'online'        => $lead->demo->ecommerce_api_url,

            // Tipo de negocio requerido por el helper
            'business_type' => $lead->business_type,

            // Flags booleanos del setup
            'iva_included'                 => (bool) $lead->iva_included,
            'redondear_centenas_en_vender' => (bool) $lead->redondear_centenas_en_vender,
            'ask_amount_in_vender'         => (bool) $lead->ask_amount_in_vender,

            // Nota: el helper original usa "usan_cuentas_corrientes" invertido;
            // en el Lead lo guardamos como "omitir_cuentas_corrientes" para que
            // sea coherente con user/setup.blade.php. Aquí traducimos.
            'usan_cuentas_corrientes'      => !((bool) $lead->omitir_cuentas_corrientes),

            'use_deposits'                 => (bool) $lead->use_deposits,
            'address_1'                    => $lead->address_1,
            'address_2'                    => $lead->address_2,
            'address_3'                    => $lead->address_3,

            'use_price_lists'              => (bool) $lead->use_price_lists,
            'price_type_1'                 => $lead->price_type_1,
            'price_type_2'                 => $lead->price_type_2,
            'price_type_3'                 => $lead->price_type_3,

            'ventas_con_fecha_de_entrega'  => (bool) $lead->ventas_con_fecha_de_entrega,
            'cajas'                        => (bool) $lead->cajas,
            'usar_codigos_de_barra'        => (bool) $lead->usar_codigos_de_barra,
            'codigos_de_barra_por_defecto' => (bool) $lead->codigos_de_barra_por_defecto,
            'consultora_de_precios'        => (bool) $lead->consultora_de_precios,
            'imagenes'                     => (bool) $lead->imagenes,
            'produccion'                   => (bool) $lead->produccion,

            // Cuota diaria de Google Custom Search para el User de la demo. A diferencia de la
            // API key, esto no es un secreto y tiene un default sano (100), así que se manda
            // siempre (sin condición), a diferencia de google_custom_search_api_key más abajo.
            'google_cuota'                 => ImplementationSettings::get_google_cuota_demo(),

            // Token de ingreso directo a la demo (sesión ya iniciada) y su vencimiento.
            // Lo deja listo asegurar_token_de_ingreso(), antes de armar este payload.
            'demo_ingreso_token'            => $lead->demo_ingreso_token,
            'demo_ingreso_token_expira_at'  => $lead->demo_ingreso_token_expira_at
                ? $lead->demo_ingreso_token_expira_at->format('Y-m-d H:i:s')
                : null,
        ];

        // La API key de Google solo viaja si está cargada en admin. Si está vacía no se manda
        // el campo, y empresa-api usa la constante de fallback que tiene en DemoSetupHelper.
        // Se lee la key de DEMO (distinta de la de clientes reales) para no compartir cuota.
        $google_api_key = ImplementationSettings::get_google_api_key_demo();
        if ($google_api_key !== '') {
            $payload['google_custom_search_api_key'] = $google_api_key;
        }

        // El merge va AL FINAL, después de todas las claves de siempre, y no al principio: las
        // claves recalculadas de la dinámica nueva (use_price_lists, use_deposits,
        // usan_cuentas_corrientes) tienen que pisar a las viejas, no al revés. Para la dinámica
        // actual claves_de_la_dinamica_nueva() devuelve [] y el payload queda idéntico al de siempre.
        return array_merge($payload, $this->claves_de_la_dinamica_nueva($lead));
    }

    /**
     * Las claves que hay que agregar o pisar en el payload según la dinámica de demo del lead.
     *
     * Para la dinámica ACTUAL devuelve un array vacío, sin excepciones: el payload de esos leads
     * tiene que quedar idéntico byte por byte al de antes de este método. La ramificación usa
     * `Lead::usa_experiencia_demo_nueva()` (grupo 293) y nunca la columna a mano, porque ese
     * método ya cae a `Lead::EXPERIENCIA_ACTUAL` ante cualquier valor desconocido o nulo: mientras
     * la dinámica nueva no esté activada, ningún lead de producción puede recibir el bloque nuevo
     * por accidente.
     *
     * En la dinámica nueva los tres campos legados (`use_price_lists`, `use_deposits`,
     * `usan_cuentas_corrientes`) se recalculan desde las MISMAS respuestas efectivas y no desde
     * las columnas del lead, para que el payload tenga una sola fuente. Hoy coinciden porque el
     * mapper escribe esas columnas al enviarse el formulario; pero si el formulario todavía no se
     * completó, las respuestas efectivas salen de los defaults del catálogo mientras las columnas
     * siguen en su valor de base. Dos fuentes que pueden discrepar es la clase de bug que no
     * avisa: el bloque nuevo diría una cosa, el viejo otra, y `empresa-api` elegiría cualquiera.
     *
     * El método es puro a propósito: sólo el lead y `LeadDemoFormMapper`. Nada de settings, nada
     * de base, nada de HTTP — así se puede probar sin base.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed> Vacío para la dinámica actual.
     */
    protected function respuestas_para_payload(Lead $lead)
    {
        if (! $lead->usa_experiencia_demo_nueva()) {
            return [];
        }

        $formulario_completado = $lead->demo_form_completado_at !== null;

        if (! $formulario_completado) {
            // No es un error: el botón "Disparar setup demo" del panel de Operaciones se puede
            // pulsar antes de que el lead abra la página, a propósito. Queda registrado porque
            // cuando después la demo aparezca sin ecommerce o sin compras, esta línea explica
            // por qué se armó así.
            Log::info('Demo setup con los defaults del catálogo: el lead todavía no completó el formulario de la página inmersiva.', [
                'lead_id' => $lead->id,
            ]);
        }

        $respuestas = LeadDemoFormMapper::respuestas_efectivas($lead);

        return [
            'demo_experiencia'      => Lead::EXPERIENCIA_NUEVA,
            'demo_form_completado'  => $formulario_completado,
            'respuestas_formulario' => $respuestas,

            // Los tres legados, recalculados desde las respuestas de arriba (ver docblock).
            'use_price_lists'         => ($respuestas['tipo_precios'] === 'listas'),
            'use_deposits'            => (bool) $respuestas['usa_depositos'],
            'usan_cuentas_corrientes' => (bool) $respuestas['usa_cuentas_corrientes_clientes'],
        ];
    }

    /**
     * Todo el bloque de claves que sólo recibe un lead de la dinámica nueva: las respuestas del
     * formulario más el canal de eventos, el plan congelado y el mapa de multimedia (misión 48).
     *
     * La guardia de dinámica es UNA sola y vive en `respuestas_para_payload()`: si ese método
     * devolvió vacío, el lead es de la dinámica actual y acá no se agrega absolutamente nada. Se
     * hace así, y no repitiendo el `usa_experiencia_demo_nueva()`, porque dos condiciones que
     * deciden lo mismo en dos lugares distintos son dos condiciones que pueden divergir — y la que
     * quedara desactualizada le mandaría claves nuevas a un lead de producción.
     *
     * Este método SÍ toca la base (`demo_media`), a diferencia de `respuestas_para_payload()`, que
     * se mantiene puro a propósito para poder probarse sin base.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed> Vacío para la dinámica actual.
     */
    protected function claves_de_la_dinamica_nueva(Lead $lead)
    {
        $respuestas = $this->respuestas_para_payload($lead);

        if (empty($respuestas)) {
            return [];
        }

        return array_merge($respuestas, [
            /* El canal de eventos se autoconfigura por el payload y NO por el `.env` de cada
             * instancia (misión 48). `empresa-api` ya tiene un canal saliente hacia el admin
             * (`services.admin_api`, usado por AdminReportHelper y SupportSyncHelper), pero exige
             * ADMIN_API_URL y ADMIN_API_OUTBOUND_KEY cargadas a mano en las tres instancias demo —
             * y ese es exactamente el modo de falla que ya se pagó con el panel, cuyas
             * PANEL_ADMIN_API_URL / PANEL_ADMIN_INGEST_KEY quedaron vacías y el botón de novedades
             * no publicó nunca sin que nadie se enterara. El payload ya transporta un secreto por
             * precedente (google_custom_search_api_key) y se reescribe entero en cada
             * migrate:fresh, así que no queda ningún paso manual por instancia que pueda faltar. */
            'demo_eventos_token' => $lead->demo_eventos_token,
            'demo_eventos_url'   => rtrim((string) config('app.url'), '/') . '/api/demo-eventos',

            // El plan congelado, para el panel lateral y el roadmap de la instancia (misión 51).
            'demo_plan' => $lead->demo_plan,

            /* Las URLs van APARTE del plan y no adentro, a propósito: el plan es estructura y se
             * congela; las URLs son contenido y se resuelven en cada setup. De los 43 slots
             * multimedia hoy hay uno solo cargado, y se van subiendo desde /multimedia-demo sin
             * deploy — si quedaran congeladas dentro del plan, un video subido después nunca
             * llegaría a una demo ya armada. Es la misma separación que ya rige entre
             * demo_catalogo.json (repo) y la tabla demo_media (base). */
            'demo_media_urls' => DemoMedia::url_por_slot(),
        ]);
    }

    /**
     * Congela el plan de demo del lead si todavía no lo tiene, y le genera los hitos.
     *
     * Es la red de contención del lead que nunca abrió la página inmersiva: el formulario es el
     * lugar natural del congelamiento, pero el botón "Disparar setup demo" del panel se puede
     * pulsar antes. `respuestas_efectivas()` devuelve en ese caso los defaults del catálogo, así
     * que el plan queda armado con lo que la página le habría mostrado preseleccionado.
     *
     * Sólo aplica a la dinámica nueva: un lead de la dinámica actual no tiene página inmersiva,
     * ni roadmap, ni nada que congelar.
     *
     * Si el resolver devuelve null (catálogo sin sincronizar) no se congela nada y el setup sigue
     * su curso: que no haya roadmap no puede impedir que la demo exista.
     *
     * @param Lead $lead
     */
    protected function congelar_plan_si_falta(Lead $lead)
    {
        if (! $lead->usa_experiencia_demo_nueva()) {
            return;
        }

        if ($lead->demo_plan_congelado_at !== null) {
            return;
        }

        DB::transaction(function () use ($lead) {
            if (! DemoPlanResolver::congelar_en_memoria($lead)) {
                return;
            }

            $lead->save();
            DemoHitosService::generar($lead);
        });
    }

    /**
     * Deja al Lead con un token de ingreso utilizable, REUTILIZANDO el que ya tenga.
     *
     * Se llama una sola vez por corrida de run(), antes de armar el payload: el token viaja
     * adentro del POST, así que no se puede emitir después de saber cómo salió la llamada.
     *
     * 🔴 POR QUE REUTILIZA Y NO EMITE UNO NUEVO EN CADA CORRIDA (27/8/2026)
     * --------------------------------------------------------------------
     * Hasta hoy este método hacía `Str::random(64)` siempre. Eso tiene un problema que no se ve
     * hasta que muerde, y mordió en producción el 27/8/2026:
     *
     * **El token se rota ACA, antes del POST, pero la instancia recién lo guarda si el POST llega
     * a correr.** Si `empresa-api` rebota con 409 —ya hay un demo setup corriendo, candado
     * `flock` de `DemoSetupLockHelper`— la instancia NO tocó nada y se queda con el token de la
     * corrida que está viva, mientras acá quedó escrito uno nuevo. A partir de ese instante el
     * link que muestra el panel apunta a un token que la demo no conoce: `resolver()` devuelve
     * null, el ingreso contesta 401 y el lead ve "Este acceso a la demo ya no está disponible".
     * Y como el botón se puede volver a apretar, **cada click empeora la desincronización**.
     *
     * Reutilizar el token vigente corta esa cadena de raíz: si no hay nada que rotar, no hay nada
     * que pueda quedar desincronizado. Es preferible a "restaurar el token viejo cuando rebota"
     * —que era la otra opción— porque no depende de acertar en qué ramas de error hay que
     * revertir: el timeout, por ejemplo, NO se puede revertir (la corrida puede seguir viva del
     * otro lado y quedarse con el valor nuevo).
     *
     * Y arregla de paso el hallazgo #1 del informe `20260826-link-demo-fresco-tras-setup.md`, que
     * es el caso más caro de los dos: **un lead que ya recibió su link por WhatsApp quedaba afuera
     * si alguien volvía a correr el setup**, porque `DemoIngresoTokenHelper::guardar()` de
     * `empresa-api` borra los tokens anteriores del usuario. Nadie se enteraba: no falla nada.
     *
     * Lo que SI sigue emitiendo un token nuevo, y son los dos únicos casos:
     *
     *  - **No hay token.** El lead nunca corrió un setup.
     *  - **El token está revocado.** Revocar es un acto explícito de "ese link no vale más"
     *    (`revocar_demo_token_json` del panel), y reutilizarlo lo resucitaría.
     *
     * 🔴 El VENCIMIENTO se recalcula siempre, se reutilice el token o no. No es lo mismo que el
     * valor: el vencimiento es el control de tiempo del link y tiene que seguir al turno, así que
     * si la demo se reagendó, el lead que ya tiene el link entra igual. Es exactamente lo que se
     * quiere — y es también el único control de seguridad real de este link, que viaja por
     * WhatsApp y es inherentemente compartible.
     *
     * Asimetría intencional de almacenamiento: acá en admin-api se guarda el token EN CLARO
     * (demo_ingreso_token), porque el admin necesita poder reconstruir el link para reenviarlo
     * por WhatsApp. Del lado de empresa-api solo se guarda el hash.
     *
     * @param Lead $lead Lead al que se le asegura el token
     *
     * @return string El token en claro que quedó vigente (el reutilizado, o el recién generado)
     */
    protected function asegurar_token_de_ingreso(Lead $lead)
    {
        // Cálculo de vencimiento delegado a DemoIngresoTokenService::calcular_expiracion()
        // (extraído de acá, grupo 233 prompt 05) para que el setup inicial y la reemisión
        // manual desde el panel usen exactamente la misma lógica y no se desincronicen.
        //
        // Va afuera del `if`: se recalcula siempre, por el motivo del docblock.
        $campos = [
            'demo_ingreso_token_expira_at' => $this->demo_ingreso_token_service->calcular_expiracion($lead),
        ];

        /* Los dos únicos casos que emiten uno nuevo. Ojo con el orden de la condición: un lead sin
         * token nunca tuvo nada que revocar, así que `empty()` va primero y la segunda rama sólo
         * mira leads que sí tienen uno. */
        if (empty($lead->demo_ingreso_token) || !is_null($lead->demo_ingreso_token_revocado_at)) {
            // Token de 64 caracteres, no es de un solo uso: vale durante toda la ventana de vigencia.
            $campos['demo_ingreso_token'] = Str::random(64);

            /* Sólo se limpia acá. Antes se escribía `null` en TODAS las corridas, lo que convertía
             * cualquier re-corrida del setup en una des-revocación silenciosa. */
            $campos['demo_ingreso_token_revocado_at'] = null;
        }

        /* Secreto del canal de eventos (misión 48), con el MISMO criterio y por el mismo motivo:
         * si se rota y el POST no llega a correr, la instancia sigue emitiendo con el token viejo
         * y `DemoEventosKey` —que resuelve por `Lead::where('demo_eventos_token', ...)`— los
         * rechaza. Es un 401 permanente, y está descrito como tal en `RunDemoSetup.php:246`, donde
         * justamente se agregó una señal local para no depender de este canal.
         *
         * No tiene vencimiento ni revocación, así que la única condición es "no hay".
         *
         * Sólo para la dinámica nueva. Un lead de la dinámica actual no emite nada por este canal
         * —no tiene página inmersiva ni instancia que reporte—, así que ni siquiera se le escribe
         * la columna: el alcance de la misión 48 termina en Lead::usa_experiencia_demo_nueva(). */
        if ($lead->usa_experiencia_demo_nueva() && empty($lead->demo_eventos_token)) {
            $campos['demo_eventos_token'] = Str::random(64);
        }

        $lead->update($campos);

        return $lead->demo_ingreso_token;
    }

    /**
     * Helper interno que deja el Lead en `sin_confirmar` con el motivo dado, y devuelve el lead
     * refrescado.
     *
     * Se usa en los dos casos en que el admin NO sabe cómo terminó el armado: el timeout de la
     * llamada y el HTTP 409 de una instancia que ya tenía una corrida viva. Ver el docblock de
     * `ESTADO_SIN_CONFIRMAR` para el por qué de que no sea `fallido`.
     *
     * 🔴 La guarda de `exitoso` va para las DOS dinámicas, a diferencia de `mark_failed()`, y el
     * motivo es distinto del de allá. Acá el orden "la instancia termina bien → recién después se
     * vence nuestra espera" no es una hipótesis: es EL caso normal desde que el techo son 900 s y
     * la instancia avisa por el canal de eventos apenas termina. Pisar un `exitoso` real con
     * `sin_confirmar` dejaría al lead sin botón de ingreso teniendo la demo perfectamente armada.
     * Y para la dinámica actual la guarda no cambia nada que se pueda notar: su único `exitoso`
     * posible es el de un POST anterior, y volver a correr el setup para que se venza la espera no
     * es información suficiente para borrar ese dato.
     *
     * Como en `mark_failed()`, la condición viaja ADENTRO del UPDATE y no en un `if` sobre `$lead`:
     * la instancia en memoria trae el estado de hace hasta 900 segundos.
     *
     * @param Lead   $lead
     * @param string $reason Motivo, en castellano, que se muestra en el panel.
     *
     * @return Lead
     */
    protected function mark_sin_confirmar(Lead $lead, string $reason)
    {
        $marcados = Lead::where('id', $lead->id)
            ->where('demo_setup_status', '!=', 'exitoso')
            ->update([
                'demo_setup_status'     => self::ESTADO_SIN_CONFIRMAR,
                'demo_setup_last_error' => $reason,
                // A mano porque el query builder no estampa los timestamps, y el panel ordena por
                // esta columna. Mismo criterio que mark_failed().
                'updated_at'            => now(),
            ]);

        if ($marcados !== 1) {
            Log::info('RunDemoSetupService: no se escribió el sin_confirmar (el setup ya estaba en exitoso, o el mismo motivo ya estaba escrito).', [
                'lead_id'           => $lead->id,
                'motivo_descartado' => $reason,
            ]);
        }

        return $lead->refresh();
    }

    /**
     * Helper interno que marca el Lead como fallido con el motivo dado
     * y devuelve el lead refrescado.
     *
     * @param Lead   $lead
     * @param string $reason
     *
     * @return Lead
     */
    protected function mark_failed(Lead $lead, string $reason)
    {
        /* 🔴 Guarda de la dinámica NUEVA: un `exitoso` ya escrito no se pisa con un `fallido`.
         *
         * Es la mitad de admin del arreglo de la misión cruzada demo-v2. Desde la misión cruzada demo-v2 la instancia avisa
         * por el canal de eventos (`demo.setup.completado`) que terminó de armarse, y ese aviso
         * llega por su propia conexión, independiente del POST que estamos esperando acá. El orden
         * "llega el evento → después vence/se corta el POST" es real y no una hipótesis: el POST
         * espera hasta 900 segundos (`services.client_api.demo_setup_timeout`; eran 300 hasta la
         * misión cruzada del 25/8/2026) y el evento sale apenas el setup termina, que medido son
         * 565,7 s. O sea que ahora el orden no es sólo posible: es el NORMAL. Sin esta guarda, ese
         * orden vuelve a dejar al lead en `fallido` con la demo perfectamente armada, y
         * `evaluar_ingreso()` no le habilita nunca el botón — que es el caso exacto que la misión
         * vino a arreglar.
         *
         * La condición viaja ADENTRO del propio UPDATE y no en un `if` sobre `$lead`, por dos
         * motivos: la instancia en memoria trae el estado de hace hasta 900 segundos (es lo que
         * puede haber durado el POST que estamos por dar por perdido), y un chequeo seguido de un
         * update es exactamente la ventana de carrera que hay que cerrar. Mismo criterio que el
         * claim atómico de `run()`.
         *
         * 🔴 Y por qué la dinámica ACTUAL queda AFUERA de la guarda, aunque el argumento parezca
         * general: `run()` y este método son COMPARTIDOS —los llaman el comando
         * `leads:run-demo-setup`, el `RunDemoSetupJob` y el botón "Correr demo setup ahora" del
         * panel, ninguno mirando la dinámica del lead—. Un lead de la dinámica actual no tiene
         * canal de eventos (`asegurar_token_de_ingreso()` ni le escribe `demo_eventos_token`), así
         * que nunca puede llegarle un `exitoso` desde afuera: el único `exitoso` que puede tener es
         * el de un POST anterior, y ahí el botón del panel, que se pulsa para re-correr el setup a
         * propósito, TIENE que poder dejarlo en `fallido` si esta vez falló de verdad. Extenderle
         * la guarda le cambiaría el comportamiento a los leads de producción, que es justo lo que
         * el criterio de aceptación de la misión 60 prohíbe. */
        if ($lead->usa_experiencia_demo_nueva()) {
            $marcados = Lead::where('id', $lead->id)
                ->where('demo_setup_status', '!=', 'exitoso')
                ->update([
                    'demo_setup_status' => 'fallido',
                    'demo_setup_last_error' => $reason,
                    // A mano porque el query builder no estampa los timestamps: sin esto, el mismo
                    // fallo movía `updated_at` por el camino de Eloquent y deja de moverlo por
                    // éste, y el panel ordena por esa columna.
                    'updated_at' => now(),
                ]);

            /* El mensaje dice "no se escribió" y NO "ya estaba en exitoso", aunque ese sea el caso
             * que importa. `config/database.php` no setea `PDO::MYSQL_ATTR_FOUND_ROWS`, así que
             * `update()` devuelve las filas CAMBIADAS y no las matcheadas: dos `mark_failed`
             * seguidos con el mismo motivo dentro del mismo segundo también dan 0, y ahí el estado
             * era `fallido`, no `exitoso`. Un log que afirma de más es peor que uno que describe
             * de menos — el que lo lea a las tres de la mañana le va a creer. */
            if ($marcados !== 1) {
                Log::info('RunDemoSetupService: no se escribió el fallido (el setup ya estaba en exitoso, o el fallido ya estaba escrito igual).', [
                    'lead_id'           => $lead->id,
                    'motivo_descartado' => $reason,
                ]);
            }

            return $lead->refresh();
        }

        $lead->update([
            'demo_setup_status' => 'fallido',
            'demo_setup_last_error' => $reason,
        ]);

        return $lead->refresh();
    }
}
