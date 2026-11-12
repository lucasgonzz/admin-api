<?php

namespace App\Services\Prueba;

use App\Helpers\AdminApiPublicUrl;
use App\Mail\ComercioCityMailPayload;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Lead;
use App\Models\PruebaEncuesta;
use App\Models\PruebaWhatsappEnvio;
use App\Services\AdminPushNotificationService;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * La encuesta de fin de prueba gratuita (misión cruzada prueba-e2e-campana-imperio, 11/11/2026). Lucas la
 * pidió para todo negocio que termina la prueba, haya contratado o no.
 *
 *  - Se crea (una por cliente) y se manda cuando la prueba VENCE (`pruebas:vencer`, que además barre las que
 *    vencieron en las últimas {@see self::HORAS_DE_BARRIDO} horas y todavía no la recibieron: cubre el
 *    vencimiento que llegó por el evento `prueba.vencida` y la plantilla que Meta aprobó tarde) y cuando el
 *    negocio CONTRATA (`PruebaCicloService::contratar()`).
 *  - Sale por los dos canales: WhatsApp por el ticket de soporte, con la plantilla `cc_prueba_fin_encuesta`
 *    (la ventana suele estar cerrada), y correo. Cada canal una sola vez, registrado en `prueba_whatsapp_envios`.
 *  - El negocio la contesta en `GET/POST prueba/encuesta/{token}` (página pública), una sola vez.
 *  - Al contestar, le llega una notificación a Lucas con el resultado.
 *
 * Nunca tumba a quien la llama: vencer y contratar siguen aunque la encuesta falle.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados ni union types.
 */
class PruebaEncuestaService
{
    /** `clave` del correo en el registro de envíos (`correo_fin_encuesta`). */
    const CORREO = 'fin_encuesta';

    /** `hito` con el que queda registrado el WhatsApp de la encuesta. */
    const HITO = 'encuesta';

    /** Hasta cuántas horas después de vencida se sigue intentando mandarla. */
    const HORAS_DE_BARRIDO = 24;

    /** Largo máximo del comentario libre. */
    const MAX_COMENTARIO = 2000;

    /**
     * La encuesta del cliente; si no existe, la crea con su token.
     *
     * @param Client    $client
     * @param Lead|null $lead
     * @param string    $motivo `vencida` o `contrato` (solo se usa al crearla).
     *
     * @return PruebaEncuesta
     */
    public function para_cliente(Client $client, ?Lead $lead, string $motivo): PruebaEncuesta
    {
        $existente = PruebaEncuesta::where('client_id', (int) $client->id)->first();
        if ($existente !== null) {
            return $existente;
        }

        try {
            return PruebaEncuesta::create([
                'client_id' => (int) $client->id,
                'lead_id'   => $lead !== null ? (int) $lead->id : null,
                'token'     => Str::random(48),
                'motivo'    => $motivo,
            ]);
        } catch (QueryException $e) {
            /* 23000: otro proceso la creó en el medio (único por cliente). */
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return PruebaEncuesta::where('client_id', (int) $client->id)->firstOrFail();
        }
    }

    /**
     * El enlace público de la encuesta.
     *
     * @param PruebaEncuesta $encuesta
     *
     * @return string
     */
    public static function url(PruebaEncuesta $encuesta): string
    {
        $base = AdminApiPublicUrl::base();

        return ($base !== '' ? $base : rtrim((string) url('/'), '/')) . '/prueba/encuesta/' . $encuesta->token;
    }

    /**
     * Crea la encuesta del cliente (si no la tiene) y la manda por los canales que falten. Idempotente.
     *
     * @param Client $client
     * @param string $motivo `vencida` o `contrato`.
     *
     * @return array{estado: string, whatsapp: string|null, correo: string|null, encuesta_id: int|null}
     *   `estado`: `enviada` (salió por algún canal, ahora o antes), `pendiente` (todavía por ninguno),
     *   `ya_respondida` o `apagada`.
     */
    public function enviar(Client $client, string $motivo): array
    {
        if (! PruebaSettings::encuesta_activa()) {
            return ['estado' => 'apagada', 'whatsapp' => null, 'correo' => null, 'encuesta_id' => null];
        }

        /* Con `prueba_novedades_alcance = ensayo` (el default), solo a los clientes de ensayo. */
        if (! PruebaSettings::novedades_para($client)) {
            return ['estado' => 'fuera_de_alcance', 'whatsapp' => null, 'correo' => null, 'encuesta_id' => null];
        }

        $lead     = app(PruebaDelClienteService::class)->lead_del_cliente($client);
        $encuesta = $this->para_cliente($client, $lead, $motivo);

        if ($encuesta->respondida_at !== null) {
            return ['estado' => 'ya_respondida', 'whatsapp' => null, 'correo' => null, 'encuesta_id' => (int) $encuesta->id];
        }

        $url      = self::url($encuesta);
        $whatsapp = $this->enviar_whatsapp($client, $lead, $url, self::plantilla_de($encuesta));
        $correo   = $this->enviar_correo($client, $lead, $encuesta, $url);

        $salio = in_array($whatsapp, ['enviado', 'ya_enviado'], true) || in_array($correo, ['enviado', 'ya_enviado'], true);
        if ($salio && $encuesta->enviada_at === null) {
            $encuesta->enviada_at = now();
            $encuesta->save();
        }

        return [
            'estado'      => $encuesta->enviada_at !== null ? 'enviada' : 'pendiente',
            'whatsapp'    => $whatsapp,
            'correo'      => $correo,
            'encuesta_id' => (int) $encuesta->id,
        ];
    }

    /**
     * {@see self::enviar()} sin excepciones: para engancharlo a vencer y a contratar.
     *
     * @param Client $client
     * @param string $motivo
     *
     * @return array<string, mixed>
     */
    public function enviar_sin_romper(Client $client, string $motivo): array
    {
        try {
            return $this->enviar($client, $motivo);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('PruebaEncuestaService: no se pudo mandar la encuesta de fin de prueba.', [
                'client_id' => (int) $client->id,
                'motivo'    => $motivo,
                'error'     => $e->getMessage(),
            ]);

            return ['estado' => 'error', 'whatsapp' => null, 'correo' => null, 'encuesta_id' => null];
        }
    }

    /**
     * Las pruebas que vencieron hace poco y todavía no recibieron la encuesta por algún canal: la manda.
     * Lo corre `pruebas:vencer` cada diez minutos.
     *
     * @param Carbon|null $ahora
     *
     * @return array<int, array<string, mixed>> Un resultado por cliente recorrido.
     */
    public function barrer_vencidas(?Carbon $ahora = null): array
    {
        if (! PruebaSettings::encuesta_activa()) {
            return [];
        }

        $ahora = $ahora !== null ? $ahora : Carbon::now();

        /* Sin las cuentas de ensayo: a esas les manda la encuesta solo `pruebas:ensayo`. */
        $clientes = Client::query()
            ->where('estado', PruebaCicloService::CLIENTE_PRUEBA_VENCIDA)
            ->where('es_ensayo', false)
            ->where('is_active', true)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>=', $ahora->copy()->subHours(self::HORAS_DE_BARRIDO))
            ->where('trial_ends_at', '<=', $ahora)
            ->orderBy('id')
            ->get();

        $resultados = [];
        foreach ($clientes as $client) {
            if (! PruebaSettings::novedades_para($client) || $this->ya_salio_por_los_dos_canales($client)) {
                continue;
            }

            $resultados[] = ['client_id' => (int) $client->id] + $this->enviar_sin_romper($client, PruebaEncuesta::MOTIVO_VENCIDA);
        }

        return $resultados;
    }

    /**
     * Guarda la respuesta del negocio, una sola vez, y le avisa a Lucas.
     *
     * @param PruebaEncuesta       $encuesta
     * @param array<string, mixed> $datos `estrellas` (1 a 5), las seis preguntas (true, false o null) y `comentario`.
     *
     * @return bool true si esta llamada la guardó; false si ya estaba contestada.
     */
    public function responder(PruebaEncuesta $encuesta, array $datos): bool
    {
        $client = Client::find((int) $encuesta->client_id);

        $valores = [
            'estrellas'             => (int) $datos['estrellas'],
            'comentario'            => isset($datos['comentario']) && trim((string) $datos['comentario']) !== ''
                ? mb_substr(trim((string) $datos['comentario']), 0, self::MAX_COMENTARIO)
                : null,
            'contrato_al_responder' => $client !== null && (string) $client->estado === PruebaCicloService::CLIENTE_ACTIVO,
            'respondida_at'         => now(),
            'updated_at'            => now(),
        ];
        foreach (array_keys(PruebaEncuesta::PREGUNTAS) as $pregunta) {
            $valores[$pregunta] = isset($datos[$pregunta]) ? (bool) $datos[$pregunta] : null;
        }

        /* Una sola respuesta por token: el UPDATE condicionado es lo que decide quién la guardó. */
        $guardadas = PruebaEncuesta::query()
            ->whereKey($encuesta->id)
            ->whereNull('respondida_at')
            ->update($valores);

        if ($guardadas !== 1) {
            return false;
        }

        $this->avisar_a_lucas($encuesta->fresh(), $client);

        return true;
    }

    /**
     * La notificación a Lucas con el resultado. Nunca lanza.
     *
     * @param PruebaEncuesta $encuesta
     * @param Client|null    $client
     *
     * @return void
     */
    public function avisar_a_lucas(PruebaEncuesta $encuesta, ?Client $client): void
    {
        try {
            $admin = $this->destinatario();
            if ($admin === null) {
                return;
            }

            $entregado = $this->empujar_notificacion((int) $admin->id, $this->titulo_del_aviso($encuesta, $client), $this->texto_del_aviso($encuesta), $this->url_de_la_ficha($encuesta));

            /* `avisada_at` solo si algún dispositivo aceptó la notificación: si no, nadie se enteró. */
            if ($entregado) {
                $encuesta->avisada_at = now();
                $encuesta->save();
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaEncuestaService: no se pudo avisar a Lucas de una encuesta respondida.', [
                'encuesta_id' => (int) $encuesta->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Manda la notificación push. Aparte para poder medirla en los tests.
     *
     * @return bool true si al menos un dispositivo del admin la aceptó.
     */
    protected function empujar_notificacion(int $admin_id, string $titulo, string $texto, string $url): bool
    {
        $resultado = AdminPushNotificationService::send_to_admin_with_result($admin_id, $titulo, $texto, ['url' => $url]);

        return ! empty($resultado['accepted_endpoint_hashes']);
    }

    /**
     * A dónde lleva la notificación: la ficha del negocio, que tiene la pestaña «Prueba» con la encuesta. Con lead,
     * la del lead (`/leads?lead_id=`, el enlace que el admin-spa ya sabe abrir); sin lead, el listado de clientes.
     *
     * @param PruebaEncuesta $encuesta
     *
     * @return string
     */
    public function url_de_la_ficha(PruebaEncuesta $encuesta): string
    {
        $base = rtrim(trim((string) config('services.admin_spa.url')), '/');

        return $encuesta->lead_id !== null
            ? $base . '/leads?lead_id=' . (int) $encuesta->lead_id . '&pestana=prueba'
            : $base . '/clientes?cliente=' . (int) $encuesta->client_id . '&pestana=prueba';
    }

    /**
     * @param PruebaEncuesta $encuesta
     * @param Client|null    $client
     *
     * @return string
     */
    public function titulo_del_aviso(PruebaEncuesta $encuesta, ?Client $client): string
    {
        $negocio = $client !== null ? $client->resolve_display_name() : 'cliente #' . (int) $encuesta->client_id;

        return 'Encuesta de la prueba: ' . $negocio . ' puso ' . (int) $encuesta->estrellas . ' de 5';
    }

    /**
     * El cuerpo del aviso: qué contestó en cada pregunta y el comentario.
     *
     * @param PruebaEncuesta $encuesta
     *
     * @return string
     */
    public function texto_del_aviso(PruebaEncuesta $encuesta): string
    {
        $rotulos = [
            'implementacion_practica' => 'Puesta en marcha práctica',
            'soporte_sirvio'          => 'Le sirvió el soporte',
            'probo_gestion'           => 'Probó la gestión',
            'probo_tienda'            => 'Probó la tienda',
            'probo_agentes'           => 'Probó los agentes',
            'atencion_buena'          => 'Buena atención',
        ];

        $partes = [];
        foreach ($rotulos as $campo => $rotulo) {
            $valor    = $encuesta->getAttribute($campo);
            $partes[] = $rotulo . ': ' . ($valor === null ? 'sin contestar' : ($valor ? 'sí' : 'no'));
        }

        $texto = ($encuesta->contrato_al_responder ? 'Contrató. ' : 'No contrató. ') . implode(' · ', $partes) . '.';

        if (trim((string) $encuesta->comentario) !== '') {
            $texto .= ' Dijo: «' . mb_substr(trim((string) $encuesta->comentario), 0, 300) . '»';
        }

        return $texto;
    }

    /* ------------------------------------------------------------------------------------------
     | Internos
     |----------------------------------------------------------------------------------------- */

    /**
     * El WhatsApp con el enlace, por el ticket de soporte. Una sola vez (enviado o fallido cortan); si la
     * plantilla todavía no está aprobada no deja registro y lo reintenta el barrido.
     *
     * @return string `enviado`, `ya_enviado`, `plantilla_no_disponible`, o el estado del envío.
     */
    protected function enviar_whatsapp(Client $client, ?Lead $lead, string $url, string $plantilla): string
    {
        /* Un candado por cliente: el barrido, el reloj de ensayo y la contratación no la mandan dos veces a la vez. */
        $reserva = Cache::lock('prueba-encuesta-' . $client->id, 120);
        if (! $reserva->get()) {
            return 'en_curso';
        }

        try {
            $ya = PruebaWhatsappEnvio::query()
                ->where('client_id', (int) $client->id)
                ->whereIn('plantilla', PruebaPlantillasService::ENCUESTAS)
                ->whereIn('estado', [PruebaWhatsappEnvio::ESTADO_ENVIADO, PruebaWhatsappEnvio::ESTADO_FALLIDO])
                ->pluck('estado');

            if ($ya->contains(PruebaWhatsappEnvio::ESTADO_ENVIADO)) {
                return 'ya_enviado';
            }
            if ($ya->isNotEmpty()) {
                return 'fallido';
            }

            if (app(PruebaPlantillasService::class)->estado_para_enviar($plantilla)['motivo'] !== null) {
                return 'plantilla_no_disponible';
            }

            $envio = app(PruebaWhatsappService::class)->enviar_plantilla_por_ticket($client, $lead, $plantilla, self::HITO, false, $url);

            return (string) $envio['estado'];
        } finally {
            $reserva->release();
        }
    }

    /**
     * La plantilla de WhatsApp de una encuesta: al que contrató no se le dice «terminó tu prueba».
     *
     * @param PruebaEncuesta $encuesta
     *
     * @return string
     */
    public static function plantilla_de(PruebaEncuesta $encuesta): string
    {
        return (string) $encuesta->motivo === PruebaEncuesta::MOTIVO_CONTRATO
            ? PruebaPlantillasService::ENCUESTA_CONTRATO
            : PruebaPlantillasService::FIN_ENCUESTA;
    }

    /**
     * El correo con el botón a la encuesta.
     *
     * @return string El `estado` de `PruebaCorreoService::enviar_registrado()`.
     */
    protected function enviar_correo(Client $client, ?Lead $lead, PruebaEncuesta $encuesta, string $url): string
    {
        $saludo   = PruebaCorreoService::saludo($client, $lead);
        $contrato = (string) $encuesta->motivo === PruebaEncuesta::MOTIVO_CONTRATO;

        $payload = new ComercioCityMailPayload([
            'subject'    => '¿Cómo te fue con tu prueba de ComercioCity?',
            'title'      => $contrato ? 'Gracias por sumarte. ¿Cómo te fue en la prueba?' : 'Terminó tu prueba. ¿Cómo te fue?',
            'paragraphs' => [
                $saludo . ', soy Martín, del equipo de ComercioCity.',
                $contrato
                    ? 'Ya sos parte de ComercioCity y tu sistema sigue tal cual lo dejaste. Me ayudaría mucho saber cómo te resultó la prueba.'
                    : 'Tu prueba gratuita llegó a su fin. Me ayudaría mucho saber cómo te resultó.',
                'Son unas pocas preguntas y te lleva un minuto.',
            ],
            'cta'     => ['text' => 'Responder la encuesta', 'url' => $url],
            'closing' => $contrato
                ? 'Gracias. Equipo de ComercioCity'
                : 'Si querés seguir usando el sistema con todo lo que cargaste, respondé este correo o escribime por WhatsApp. Equipo de ComercioCity',
        ]);

        return (string) app(PruebaCorreoService::class)->enviar_registrado($client, $lead, self::CORREO, $payload, self::HITO)['estado'];
    }

    /**
     * Si la encuesta ya salió (o se dio por perdida) por WhatsApp y por correo: el barrido no tiene nada que hacer.
     */
    protected function ya_salio_por_los_dos_canales(Client $client): bool
    {
        $whatsapp = PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            ->whereIn('plantilla', PruebaPlantillasService::ENCUESTAS)
            ->whereIn('estado', [PruebaWhatsappEnvio::ESTADO_ENVIADO, PruebaWhatsappEnvio::ESTADO_FALLIDO])
            ->exists();

        if (! $whatsapp) {
            return false;
        }

        if (! filter_var(trim((string) $client->email), FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        $correos = PruebaWhatsappEnvio::query()
            ->where('client_id', (int) $client->id)
            ->where('canal', PruebaWhatsappEnvio::CANAL_CORREO)
            ->where('plantilla', PruebaCorreoService::PREFIJO . self::CORREO)
            ->pluck('estado');

        return $correos->contains(PruebaWhatsappEnvio::ESTADO_ENVIADO)
            || $correos->contains(PruebaWhatsappEnvio::ESTADO_ENVIANDO)
            || $correos->count() >= PruebaCorreoService::MAX_INTENTOS;
    }

    /**
     * A quién se le avisa: el admin de `CLAUDE_TASK_INGEST_CREATOR_ADMIN_ID`, el destinatario por defecto de
     * tareas, o el primero (la misma regla que el remitente de los WhatsApps de la prueba).
     */
    protected function destinatario(): ?Admin
    {
        $configurado = config('services.claude_task_ingest.default_creator_admin_id');
        if (! empty($configurado)) {
            $admin = Admin::find((int) $configurado);
            if ($admin !== null) {
                return $admin;
            }
        }

        $por_defecto = Admin::query()->where('is_default_task_assignee', true)->first();

        return $por_defecto !== null ? $por_defecto : Admin::query()->orderBy('id')->first();
    }
}
