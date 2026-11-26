<?php

namespace App\Services\WhatsappPersonal;

use App\Models\WhatsappConfig;
use App\Services\MetaTemplateService;
use App\Services\WhatsappInboundMediaService;
use App\Services\WhatsappSessionWindowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lectura del número PERSONAL: chats, mensajes, cursor de lectura, media bajo demanda, estado y plantillas
 * (misión wa-personal-coexistencia).
 *
 * 🔴 «Leer» es sólo un cursor interno (`leido_hasta`): no manda acuse de lectura a Meta, así Lucas sigue viendo los
 * tildes azules de su propia app. Los binarios no se guardan: se piden a Kapso cuando alguien los necesita, con la
 * clave y el `phone_number_id` de la fila personal, y se devuelven sin tocar el disco.
 */
class WhatsappPersonalLecturaService
{
    /** Máximo de chats por pedido. */
    const MAX_CHATS = 50;

    /** Máximo de mensajes por pedido. */
    const MAX_MENSAJES = 100;

    /** Largo máximo del último mensaje en el listado de chats. */
    const MAX_ULTIMO_MENSAJE = 200;

    /** Tope del adjunto que se devuelve (16 MB). */
    const MAX_MEDIA_BYTES = 16777216;

    /** Días sin ningún evento a partir de los cuales se avisa que hay que abrir la app. */
    const DIAS_SIN_EVENTOS_PARA_AVISAR = 12;

    /**
     * Lista de chats por último mensaje descendente, con sus no leídos.
     *
     * @param bool        $solo_no_leidos Sólo los que tienen no leídos.
     * @param string|null $desde          Sólo chats con actividad desde esta fecha (ISO 8601).
     * @param int         $limite         Máximo de chats (tope 50).
     *
     * @return array<int, array<string, mixed>>
     */
    public function chats(bool $solo_no_leidos, ?string $desde, int $limite): array
    {
        $limite    = max(1, min(self::MAX_CHATS, $limite));
        $no_leidos = $this->no_leidos_por_chat();

        $consulta = DB::table('whatsapp_personal_chats')->orderByDesc('last_message_at')->orderByDesc('id');
        if ($desde !== null) {
            $consulta->where('last_message_at', '>=', Carbon::parse($desde)->toDateTimeString());
        }
        if ($solo_no_leidos) {
            $consulta->whereIn('chat_telefono', array_keys($no_leidos));
        }

        $salida = [];
        foreach ($consulta->limit($limite)->get() as $chat) {
            $ultimo = DB::table('whatsapp_personal_messages')
                ->where('chat_telefono', $chat->chat_telefono)
                ->orderByDesc('sent_at')->orderByDesc('id')->first();

            $salida[] = [
                'telefono'                => $chat->chat_telefono,
                'contact_name'            => $chat->contact_name,
                'no_leidos'               => isset($no_leidos[$chat->chat_telefono]) ? $no_leidos[$chat->chat_telefono] : 0,
                'ultimo_mensaje'          => $ultimo !== null ? $this->resumen_del_mensaje($ultimo) : null,
                'ultimo_mensaje_direccion' => $ultimo !== null ? $ultimo->direccion : null,
                'last_message_at'         => $this->iso($chat->last_message_at),
                'last_inbound_at'         => $this->iso($chat->last_inbound_at),
            ];
        }

        return $salida;
    }

    /**
     * Mensajes de un chat en orden cronológico.
     *
     * Sin `desde` devuelve los más nuevos (o los anteriores a `antes_de_id`); con `desde`, los que siguen a esa fecha.
     *
     * @param string      $telefono    Chat (E.164).
     * @param string|null $desde       Fecha ISO 8601: sólo mensajes con `sent_at` igual o posterior.
     * @param int|null    $antes_de_id Sólo mensajes con id menor.
     * @param int         $limite      Máximo de mensajes (tope 100).
     *
     * @return array{mensajes: array<int, array<string, mixed>>, hay_mas: bool}
     */
    public function mensajes(string $telefono, ?string $desde, ?int $antes_de_id, int $limite): array
    {
        $limite   = max(1, min(self::MAX_MENSAJES, $limite));
        $consulta = DB::table('whatsapp_personal_messages')->where('chat_telefono', $telefono);

        if ($antes_de_id !== null) {
            $consulta->where('id', '<', $antes_de_id);
        }

        if ($desde !== null) {
            $filas = $consulta->where('sent_at', '>=', Carbon::parse($desde)->toDateTimeString())
                ->orderBy('sent_at')->orderBy('id')->limit($limite + 1)->get();
        } else {
            $filas = $consulta->orderByDesc('sent_at')->orderByDesc('id')->limit($limite + 1)->get();
        }

        $hay_mas = $filas->count() > $limite;
        $filas   = $filas->take($limite);
        if ($desde === null) {
            $filas = $filas->reverse()->values();
        }

        return ['mensajes' => $filas->map(function ($fila) {
            return $this->mensaje_publico($fila);
        })->all(), 'hay_mas' => $hay_mas];
    }

    /**
     * Marca un chat como leído hasta ahora. Es un cursor interno: NO se manda nada a Meta.
     *
     * @param string $telefono Chat (E.164).
     *
     * @return string|null Fecha ISO del cursor, o null si el chat no existe.
     */
    public function leer(string $telefono): ?string
    {
        $chat = DB::table('whatsapp_personal_chats')->where('chat_telefono', $telefono)->first();
        if ($chat === null) {
            return null;
        }

        $ahora = Carbon::now();
        DB::table('whatsapp_personal_chats')->where('id', $chat->id)->update([
            'leido_hasta' => $ahora->toDateTimeString(),
            'updated_at'  => $ahora->toDateTimeString(),
        ]);

        return $ahora->toIso8601String();
    }

    /**
     * Baja el adjunto de un mensaje con la clave y el número PERSONALES. No se guarda en disco.
     *
     * @param int $mensaje_id Id del mensaje.
     *
     * @return array{status: int, code?: string, binario?: string, mime?: string, filename?: string}
     */
    public function media(int $mensaje_id): array
    {
        $mensaje = DB::table('whatsapp_personal_messages')->where('id', $mensaje_id)->first();
        if ($mensaje === null) {
            return ['status' => 404, 'code' => 'mensaje_inexistente'];
        }
        if ($mensaje->media_id === null || $mensaje->media_id === '') {
            return ['status' => 404, 'code' => 'sin_adjunto'];
        }

        $personal = WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_PERSONAL);
        if ($personal === null) {
            return ['status' => 409, 'code' => 'numero_no_listo'];
        }

        $binario = (new WhatsappInboundMediaService())->descargar_binario([
            'url'               => null,
            'mime'              => $mensaje->media_mime,
            'filename'          => $mensaje->media_filename,
            'whatsapp_media_id' => $mensaje->media_id,
        ], $personal);

        if ($binario === null) {
            return ['status' => 502, 'code' => 'media_no_disponible'];
        }
        if (strlen($binario) > self::MAX_MEDIA_BYTES) {
            return ['status' => 413, 'code' => 'media_demasiado_grande'];
        }

        return [
            'status'   => 200,
            'binario'  => $binario,
            'mime'     => (string) $mensaje->media_mime,
            'filename' => (string) $mensaje->media_filename,
        ];
    }

    /**
     * Estado del número personal, sin ninguna clave ni secreto: sólo nombres y booleanos.
     *
     * @param string|null $telefono Si viene, suma la ventana de 24 h de ese chat (E.164).
     *
     * @return array<string, mixed>
     */
    public function estado(?string $telefono): array
    {
        $fila = WhatsappConfig::delProposito(WhatsappConfig::PROPOSITO_PERSONAL);

        $faltan = ['fila'];
        if ($fila !== null) {
            $faltan = $fila->loQueLeFaltaParaAtender();
            if (! $fila->is_active) {
                array_unshift($faltan, 'activo');
            }
        }

        $ultimo = DB::table('whatsapp_personal_messages')->max('updated_at');
        $aviso  = null;
        if ($fila !== null && $ultimo !== null
            && Carbon::parse($ultimo)->lt(Carbon::now()->subDays(self::DIAS_SIN_EVENTOS_PARA_AVISAR))) {
            $aviso = 'Hace más de ' . self::DIAS_SIN_EVENTOS_PARA_AVISAR . ' días que no llega ningún evento: la app de WhatsApp Business tiene '
                . 'que abrirse en el teléfono cada ~14 días o Meta desconecta la coexistencia (dato a confirmar con la doc de Meta).';
        }

        $estado = [
            'existe'             => $fila !== null,
            'activo'             => $fila !== null && (bool) $fila->is_active,
            'listo'              => $fila !== null && $fila->is_active && $faltan === [],
            'faltan'             => $faltan,
            'phone_number_id'    => $fila !== null ? $fila->phone_number_id : null,
            'display_phone'      => $fila !== null ? $fila->display_phone : null,
            'webhook_registrado' => $fila !== null && trim((string) $fila->webhook_secret) !== '',
            'test_mode'          => $fila !== null && (bool) $fila->test_mode,
            'ultimo_evento_at'   => $ultimo !== null ? $this->iso($ultimo) : null,
            'aviso_app'          => $aviso,
        ];

        if ($telefono !== null) {
            $estado['telefono'] = $telefono;
            $estado['ventana']  = (new WhatsappSessionWindowService())->window_state($telefono, WhatsappConfig::PROPOSITO_PERSONAL);
        }

        return $estado;
    }

    /**
     * Plantillas aprobadas de la WABA (la misma del institucional): nombre, idioma y cantidad de variables.
     *
     * @return array{ok: bool, plantillas: array<int, array<string, mixed>>, mensaje: string|null}
     */
    public function plantillas(): array
    {
        $respuesta = app(MetaTemplateService::class)->listar(['status' => 'APPROVED']);
        if ($respuesta['ok'] !== true) {
            return ['ok' => false, 'plantillas' => [], 'mensaje' => (string) $respuesta['mensaje']];
        }

        $salida = [];
        foreach ($respuesta['datos'] as $plantilla) {
            if (! isset($plantilla['name'])) {
                continue;
            }

            $variables = 0;
            foreach ((array) (isset($plantilla['components']) ? $plantilla['components'] : []) as $componente) {
                if (isset($componente['type'], $componente['text']) && strtoupper((string) $componente['type']) === 'BODY') {
                    $variables = preg_match_all('/\{\{\s*\d+\s*\}\}/', (string) $componente['text']);
                }
            }

            $salida[] = [
                'nombre'     => (string) $plantilla['name'],
                'idioma'     => isset($plantilla['language']) ? (string) $plantilla['language'] : null,
                'variables'  => (int) $variables,
            ];
        }

        return ['ok' => true, 'plantillas' => $salida, 'mensaje' => null];
    }

    /**
     * No leídos por chat: entrantes (que no son historial) posteriores al cursor `leido_hasta`.
     *
     * @return array<string, int> Teléfono => cantidad (sólo los que tienen).
     */
    private function no_leidos_por_chat(): array
    {
        $filas = DB::table('whatsapp_personal_messages as m')
            ->join('whatsapp_personal_chats as c', 'c.chat_telefono', '=', 'm.chat_telefono')
            ->where('m.direccion', 'in')
            ->where('m.origen', '!=', 'history_sync')
            ->where(function ($cursor) {
                $cursor->whereNull('c.leido_hasta')->orWhereColumn('m.sent_at', '>', 'c.leido_hasta');
            })
            ->groupBy('m.chat_telefono')
            ->select('m.chat_telefono', DB::raw('count(*) as total'))
            ->get();

        $salida = [];
        foreach ($filas as $fila) {
            $salida[$fila->chat_telefono] = (int) $fila->total;
        }

        return $salida;
    }

    /**
     * Texto del último mensaje recortado a 200 caracteres (o el tipo, si no tiene texto).
     *
     * @param object $mensaje Fila del mensaje.
     *
     * @return string
     */
    private function resumen_del_mensaje($mensaje): string
    {
        $texto = $mensaje->cuerpo !== null && $mensaje->cuerpo !== '' ? (string) $mensaje->cuerpo : '[' . $mensaje->tipo . ']';

        return mb_strlen($texto) > self::MAX_ULTIMO_MENSAJE ? mb_substr($texto, 0, self::MAX_ULTIMO_MENSAJE - 1) . '…' : $texto;
    }

    /**
     * Forma pública de un mensaje: sin payload crudo ni claves.
     *
     * @param object $fila Fila de `whatsapp_personal_messages`.
     *
     * @return array<string, mixed>
     */
    private function mensaje_publico($fila): array
    {
        return [
            'id'           => (int) $fila->id,
            'direccion'    => $fila->direccion,
            'origen'       => $fila->origen,
            'tipo'         => $fila->tipo,
            'cuerpo'       => $fila->cuerpo,
            'media'        => $fila->media_id !== null ? ['mime' => $fila->media_mime, 'filename' => $fila->media_filename] : null,
            'estado'       => $fila->estado,
            'estado_error' => $fila->estado_error,
            'wamid'        => $fila->whatsapp_message_id,
            'reply_to'     => $fila->reply_to_wamid,
            'contact_name' => $fila->contact_name,
            'sent_at'      => $this->iso($fila->sent_at),
        ];
    }

    /**
     * Fecha en ISO 8601, o null.
     *
     * @param mixed $valor Fecha de la base.
     *
     * @return string|null
     */
    private function iso($valor): ?string
    {
        return $valor === null ? null : Carbon::parse($valor)->toIso8601String();
    }
}
