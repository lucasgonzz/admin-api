<?php

namespace App\Services\WhatsappPersonal;

use App\Helpers\WhatsappNormalizer;
use App\Models\WhatsappConfig;
use App\Services\SystemErrorWhatsappService;
use App\Services\WhatsappSendService;
use App\Services\WhatsappSessionWindowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Envía un mensaje DESDE el número personal (misión wa-personal-coexistencia).
 *
 * 🔴 Reglas que no se negocian:
 *   - **Sale siempre por la fila personal**: se fija `WhatsappSendService::para($personal)` y nunca
 *     `paraProposito()`, que ante un canal no listo cae al principal. Sin el personal listo es 409 y no sale NADA.
 *   - **No es masivo**: un solo destinatario por pedido, hasta 20 envíos por hora y 1 cada 10 segundos por teléfono.
 *   - **Idempotente por `op`**: la fila se reserva ANTES de salir a la red (índice único en `op_id`). Si el envío
 *     falla de forma transitoria queda `incierto` y NO se reintenta solo: pudo haber salido.
 *   - **Sin avisos a los admins** por un fallo de este número (se silencia `SystemErrorWhatsappService` mientras dura
 *     el envío): el chat personal de Lucas no genera alertas de sistema.
 *   - Texto libre sólo con la ventana de 24 h abierta (medida sobre `whatsapp_personal_messages`); la plantilla
 *     siempre se puede.
 *
 * Orden de las comprobaciones: número listo → teléfono → ventana → dry run → confirmación → idempotencia → topes →
 * reserva → envío. Devuelve `['status' => int, 'body' => array]` para que el controlador sólo lo traduzca a HTTP.
 */
class WhatsappPersonalEnvioService
{
    /** Máximo de envíos por la API en la última hora. */
    const TOPE_POR_HORA = 20;

    /** Segundos mínimos entre dos envíos al mismo teléfono. */
    const SEGUNDOS_ENTRE_ENVIOS = 10;

    /** @var WhatsappPersonalWebhookService Mantiene el chat al día (último mensaje). */
    private $chats;

    /**
     * @param WhatsappPersonalWebhookService $chats Servicio que sabe actualizar la fila del chat.
     */
    public function __construct(WhatsappPersonalWebhookService $chats)
    {
        $this->chats = $chats;
    }

    /**
     * Normaliza un teléfono pedido a E.164, o null si no es un teléfono (letras, `@`, menos de 8 dígitos).
     *
     * @param string $crudo Teléfono tal como lo escribió quien llama.
     *
     * @return string|null
     */
    public static function normalizar_telefono(string $crudo): ?string
    {
        $crudo = trim($crudo);
        if ($crudo === '' || preg_match('/^[0-9+\-\s().]+$/', $crudo) !== 1) {
            return null;
        }

        $digitos = (string) preg_replace('/\D+/', '', $crudo);
        if (strlen(ltrim($digitos, '0')) < 8 || strlen($digitos) > 15) {
            return null;
        }

        $normalizado = WhatsappNormalizer::normalize($crudo);

        return $normalizado === '' ? null : $normalizado;
    }

    /**
     * Qué le falta a la fila personal para poder enviar, por nombre (sin ningún valor).
     *
     * @return array<int, string> Vacío = lista.
     */
    public function lo_que_falta(): array
    {
        $fila = WhatsappConfig::delProposito(WhatsappConfig::PROPOSITO_PERSONAL);
        if ($fila === null) {
            return ['fila'];
        }

        $faltan = $fila->loQueLeFaltaParaAtender();
        if (! $fila->is_active) {
            array_unshift($faltan, 'activo');
        }

        return $faltan;
    }

    /**
     * Resuelve un pedido de envío (o su dry run).
     *
     * @param array<string, mixed> $pedido Pedido ya validado en su forma: `telefono`, `texto` o `plantilla`
     *                                     (+ `variables`, `idioma`), `dry_run` (bool), `confirm_telefono`, `op`.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function enviar(array $pedido): array
    {
        $personal = WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_PERSONAL);
        if ($personal === null) {
            return $this->error(409, 'numero_no_listo', 'El número personal no está listo: no se envía nada, tampoco por el principal.', ['faltan' => $this->lo_que_falta()]);
        }

        $destino = self::normalizar_telefono((string) $pedido['telefono']);
        if ($destino === null) {
            return $this->error(422, 'telefono_invalido', 'El teléfono no es válido.');
        }

        $es_plantilla = isset($pedido['plantilla']) && $pedido['plantilla'] !== null;
        $ventana      = (new WhatsappSessionWindowService())->window_state($destino, WhatsappConfig::PROPOSITO_PERSONAL);

        if (! $es_plantilla && ! $ventana['open']) {
            return $this->error(422, 'fuera_de_ventana', 'La ventana de 24 h está cerrada: sólo se puede mandar una plantilla aprobada.', [
                'ventana'   => $ventana,
                'plantillas' => 'Mandá "plantilla" + "variables"; las aprobadas se listan con GET whatsapp-personal/plantillas.',
            ]);
        }

        if ($pedido['dry_run']) {
            return ['status' => 200, 'body' => [
                'dry_run'   => true,
                'destino'   => $destino,
                'tipo'      => $es_plantilla ? 'plantilla' : 'texto',
                'ventana'   => $ventana,
                'permitido' => true,
                'mensaje'   => 'No se envió nada. Para enviar repetí el pedido con dry_run=false, confirm_telefono (últimos 4 dígitos) y op.',
            ]];
        }

        if ((string) $pedido['confirm_telefono'] !== substr((string) preg_replace('/\D+/', '', $destino), -4)) {
            return $this->error(422, 'confirmacion_incorrecta', 'confirm_telefono tiene que ser los últimos 4 dígitos del teléfono de destino.');
        }

        $hash = $this->hash_del_contenido($pedido, $destino);

        $previo = DB::table('whatsapp_personal_messages')->where('op_id', (string) $pedido['op'])->first();
        if ($previo !== null) {
            $repetido = $this->resolver_op_repetida($previo, $hash);
            if ($repetido !== null) {
                return $repetido;
            }
        }

        $tope = $this->topes($destino);
        if ($tope !== null) {
            return $tope;
        }

        $fila_id = $this->reservar($pedido, $destino, $hash, $es_plantilla, $previo);
        if ($fila_id === null) {
            return $this->error(409, 'op_en_curso', 'Esa op ya se está procesando.');
        }

        return $this->salir_por_el_personal($personal, $pedido, $destino, $es_plantilla, $fila_id);
    }

    /**
     * Qué responder cuando la `op` ya existe: mismo contenido y enviado = el resultado anterior; otro
     * contenido = 409; incierto = 409. Null si la fila quedó `fallido` y se puede reintentar.
     *
     * @param object $previo Fila existente de esa `op`.
     * @param string $hash   Hash del contenido del pedido actual.
     *
     * @return array{status: int, body: array<string, mixed>}|null
     */
    private function resolver_op_repetida($previo, string $hash): ?array
    {
        if ((string) $previo->op_hash !== $hash) {
            return $this->error(409, 'op_con_otro_contenido', 'Esa op ya se usó con otro contenido: usá una op nueva.');
        }

        if ($previo->estado === 'incierto') {
            return $this->error(409, 'op_incierta', 'El envío anterior con esa op quedó incierto (pudo haber salido). Leé el chat y, si hace falta, usá una op nueva.');
        }

        if ($previo->estado === 'pendiente') {
            return $this->error(409, 'op_en_curso', 'Esa op ya se está procesando.');
        }

        if ($previo->estado === 'fallido') {
            return null;
        }

        return ['status' => 200, 'body' => [
            'ok'         => true,
            'ya_enviado' => true,
            'id'         => (int) $previo->id,
            'wamid'      => $previo->whatsapp_message_id,
            'estado'     => $previo->estado,
            'destino'    => $previo->chat_telefono,
        ]];
    }

    /**
     * Frenos anti-masivo: tope por hora y separación mínima por teléfono.
     *
     * @param string $destino Teléfono de destino (E.164).
     *
     * @return array{status: int, body: array<string, mixed>}|null Null si puede seguir.
     */
    private function topes(string $destino): ?array
    {
        $salidas = DB::table('whatsapp_personal_messages')
            ->where('direccion', 'out')
            ->where('origen', 'cloud_api')
            ->whereNotNull('op_id')
            ->where('estado', '!=', 'fallido');

        if ((clone $salidas)->where('created_at', '>=', Carbon::now()->subHour()->toDateTimeString())->count() >= self::TOPE_POR_HORA) {
            return $this->error(429, 'tope_de_envios', 'Se llegó al tope de ' . self::TOPE_POR_HORA . ' envíos por hora desde este número.');
        }

        if ((clone $salidas)->where('chat_telefono', $destino)
            ->where('created_at', '>', Carbon::now()->subSeconds(self::SEGUNDOS_ENTRE_ENVIOS)->toDateTimeString())->exists()) {
            return $this->error(429, 'demasiado_rapido', 'Ya se le escribió a ese teléfono hace menos de ' . self::SEGUNDOS_ENTRE_ENVIOS . ' segundos.');
        }

        return null;
    }

    /**
     * Reserva la fila del envío (estado `pendiente`) ANTES de salir a la red. El índice único de `op_id` hace
     * que dos pedidos simultáneos con la misma `op` no puedan salir los dos.
     *
     * @param array<string, mixed> $pedido       Pedido validado.
     * @param string               $destino      Teléfono E.164.
     * @param string               $hash         Hash del contenido.
     * @param bool                 $es_plantilla Si es plantilla.
     * @param object|null          $previo       Fila `fallido` de la misma op, si se reintenta.
     *
     * @return int|null Id de la fila, o null si otro pedido ya reservó esa op.
     */
    private function reservar(array $pedido, string $destino, string $hash, bool $es_plantilla, $previo): ?int
    {
        $ahora = Carbon::now()->toDateTimeString();
        $datos = [
            'estado'       => 'pendiente',
            'estado_error' => null,
            'op_hash'      => $hash,
            'updated_at'   => $ahora,
        ];

        if ($previo !== null) {
            $tomada = DB::table('whatsapp_personal_messages')->where('id', $previo->id)->where('estado', 'fallido')->update($datos);

            return $tomada === 1 ? (int) $previo->id : null;
        }

        $personal = WhatsappConfig::delProposito(WhatsappConfig::PROPOSITO_PERSONAL);

        $id = DB::table('whatsapp_personal_messages')->insertOrIgnore([
            'phone_number_id' => (string) $personal->phone_number_id,
            'chat_telefono'   => $destino,
            'direccion'       => 'out',
            'origen'          => 'cloud_api',
            'tipo'            => $es_plantilla ? 'template' : 'text',
            'cuerpo'          => $es_plantilla ? '[plantilla] ' . $pedido['plantilla'] : mb_substr((string) $pedido['texto'], 0, 4096),
            'op_id'           => (string) $pedido['op'],
            'sent_at'         => $ahora,
            'created_at'      => $ahora,
        ] + $datos);

        if ($id === 0) {
            return null;
        }

        return (int) DB::table('whatsapp_personal_messages')->where('op_id', (string) $pedido['op'])->value('id');
    }

    /**
     * Sale a Kapso por la fila personal y deja el resultado en la fila reservada.
     *
     * @param WhatsappConfig       $personal     Fila personal (lista).
     * @param array<string, mixed> $pedido       Pedido validado.
     * @param string               $destino      Teléfono E.164.
     * @param bool                 $es_plantilla Si es plantilla.
     * @param int                  $fila_id      Fila reservada.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function salir_por_el_personal(WhatsappConfig $personal, array $pedido, string $destino, bool $es_plantilla, int $fila_id): array
    {
        $sender = new WhatsappSendService();
        // 🔴 `para()` y NO `paraProposito()`: este último cae al principal si el canal no está listo.
        $sender->para($personal);

        $this->silenciar_avisos_a_admins();
        try {
            $wamid = $es_plantilla
                ? $sender->send_template($destino, (string) $pedido['plantilla'], array_values((array) $pedido['variables']), (string) $pedido['idioma'])
                : $sender->send_text($destino, (string) $pedido['texto'], null, true);
        } catch (\Throwable $exception) {
            $wamid = null;
            $sender->last_send_status_code = null;
        } finally {
            app()->forgetInstance(SystemErrorWhatsappService::class);
        }

        $ahora = Carbon::now();

        if ($wamid !== null) {
            $simulado = strpos($wamid, 'test-') === 0;
            $this->guardar_confirmacion($fila_id, $wamid, $simulado ? 'simulado' : 'enviado', $ahora);
            $this->chats->actualizar_chat($destino, null, $ahora, false);

            return ['status' => 200, 'body' => [
                'ok'         => true,
                'ya_enviado' => false,
                'id'         => $fila_id,
                'wamid'      => $wamid,
                'estado'     => $simulado ? 'simulado' : 'enviado',
                'destino'    => $destino,
            ]];
        }

        // Un 4xx definitivo (menos 409 y 429) es un rechazo claro; todo lo demás pudo haber salido.
        $codigo      = $sender->last_send_status_code;
        $rechazado   = $codigo !== null && $codigo >= 400 && $codigo < 500 && ! $sender->last_send_was_transient();
        $estado      = $rechazado ? 'fallido' : 'incierto';
        $motivo      = $sender->last_send_error !== null ? mb_substr((string) $sender->last_send_error, 0, 255) : 'Sin respuesta de Kapso';

        DB::table('whatsapp_personal_messages')->where('id', $fila_id)->update([
            'estado'       => $estado,
            'estado_error' => $motivo,
            'updated_at'   => $ahora->toDateTimeString(),
        ]);

        Log::channel('daily')->warning('WhatsappPersonal: el envío no se confirmó.', [
            'fila'   => $fila_id,
            'estado' => $estado,
            'http'   => $codigo,
        ]);

        if ($rechazado) {
            return $this->error(422, 'envio_rechazado', 'Meta o Kapso rechazaron el envío.', ['estado_error' => $motivo]);
        }

        return $this->error(502, 'envio_incierto', 'No se pudo confirmar el envío: pudo haber salido. Leé el chat antes de reintentar y usá una op nueva.', ['id' => $fila_id]);
    }

    /**
     * Deja confirmado el envío en la fila reservada. Si el eco `sent` de Kapso llegó ANTES y ya registró ese wamid
     * (carrera normal), se descarta la fila del eco y queda la nuestra, que tiene la `op`; si el wamid ya
     * pertenece a otro envío nuestro, esta fila queda confirmada sin wamid antes que fallar.
     *
     * @param int    $fila_id Fila reservada.
     * @param string $wamid   Id que devolvió Kapso.
     * @param string $estado  enviado|simulado.
     * @param Carbon $ahora   Hora de la confirmación.
     *
     * @return void
     */
    private function guardar_confirmacion(int $fila_id, string $wamid, string $estado, Carbon $ahora): void
    {
        $datos = ['estado' => $estado, 'sent_at' => $ahora->toDateTimeString(), 'updated_at' => $ahora->toDateTimeString()];

        // El eco que llegó primero no tiene `op`: es la misma pieza y se reemplaza por la nuestra.
        DB::table('whatsapp_personal_messages')->where('whatsapp_message_id', $wamid)->where('id', '!=', $fila_id)->whereNull('op_id')->delete();

        $propietario = DB::table('whatsapp_personal_messages')->where('whatsapp_message_id', $wamid)->where('id', '!=', $fila_id)->exists();
        if (! $propietario) {
            $datos['whatsapp_message_id'] = $wamid;
        }

        DB::table('whatsapp_personal_messages')->where('id', $fila_id)->update($datos);
    }

    /**
     * Reemplaza por un servicio mudo el que avisa a los admins por WhatsApp cuando falla un envío, así un fallo
     * del número personal nunca dispara una alerta de sistema (que además saldría por el principal).
     *
     * @return void
     */
    private function silenciar_avisos_a_admins(): void
    {
        app()->instance(SystemErrorWhatsappService::class, new class(new WhatsappSendService()) extends SystemErrorWhatsappService {
            /**
             * No avisa a nadie.
             *
             * @param string $context Contexto del fallo.
             * @param string $error   Detalle.
             *
             * @return void
             */
            public function notify_send_error(string $context, string $error): void
            {
            }
        });
    }

    /**
     * Huella del contenido del pedido: la misma `op` sólo vale para el mismo destino, texto/plantilla y variables.
     *
     * @param array<string, mixed> $pedido  Pedido validado.
     * @param string               $destino Teléfono E.164.
     *
     * @return string
     */
    private function hash_del_contenido(array $pedido, string $destino): string
    {
        return hash('sha256', json_encode([
            $destino,
            isset($pedido['texto']) ? $pedido['texto'] : null,
            isset($pedido['plantilla']) ? $pedido['plantilla'] : null,
            isset($pedido['variables']) ? array_values((array) $pedido['variables']) : [],
            isset($pedido['idioma']) ? $pedido['idioma'] : null,
        ]));
    }

    /**
     * Respuesta de error con la forma `{code, mensaje, ...}`.
     *
     * @param int                  $status  HTTP.
     * @param string               $code    Código estable.
     * @param string               $mensaje Texto para quien llama (sin datos privados).
     * @param array<string, mixed> $extra   Campos extra.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function error(int $status, string $code, string $mensaje, array $extra = []): array
    {
        return ['status' => $status, 'body' => ['code' => $code, 'mensaje' => $mensaje] + $extra];
    }
}
