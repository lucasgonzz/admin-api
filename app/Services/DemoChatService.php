<?php

namespace App\Services;

use App\Jobs\GenerateDemoChatReplyJob;
use App\Models\DemoChatConversacion;
use App\Models\DemoChatMensaje;
use App\Models\DemoChatProgreso;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Historial central de la demo. El hilo comercial de WhatsApp no se mezcla con este chat. */
class DemoChatService
{
    const ESPERA_SEGUNDOS = 10;

    public function conversacion(Lead $lead): DemoChatConversacion
    {
        DB::table('demo_chat_conversaciones')->insertOrIgnore([
            'lead_id' => $lead->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return DemoChatConversacion::where('lead_id', $lead->id)->firstOrFail();
    }

    public function iniciar(Lead $lead): void
    {
        $id = $this->conversacion($lead)->id;
        DB::transaction(function () use ($id, $lead) {
            $chat = DemoChatConversacion::lockForUpdate()->findOrFail($id);
            if ($chat->iniciado_at !== null) {
                return;
            }
            $video = app(DemoChatCatalogoService::class)->siguiente($lead, []);
            $this->crear($chat, 'asistente',
                '¡Hola! Soy tu asistente de la demo. Te voy pasando videos cortos para recorrerla. '
                . 'Si querés ver algo puntual, preguntame acá.' . ($video ? ' Empecemos por «' . $video['titulo'] . '».' : ''),
                $video ? [$video['clave']] : [], 'guia');
            $chat->iniciado_at = now();
            $chat->revision++;
            $chat->save();
        });
    }

    public function recibir(Lead $lead, string $contenido, string $uuid): void
    {
        $this->iniciar($lead);
        $job = DB::transaction(function () use ($lead, $contenido, $uuid) {
            $chat = DemoChatConversacion::where('lead_id', $lead->id)->lockForUpdate()->firstOrFail();
            if ($chat->mensajes()->where('cliente_uuid', $uuid)->exists()) {
                return null;
            }
            // Cota por hilo, además del throttle del puente: no permite crecimiento ilimitado.
            if ($chat->mensajes()->where('rol', 'lead')->count() >= 300) {
                throw ValidationException::withMessages(['contenido' => 'Llegaste al límite de mensajes de esta demo.']);
            }
            $this->crear($chat, 'lead', $contenido, [], 'lead', 'publicado', $uuid);
            return $this->programar($chat);
        });
        if ($job !== null) {
            $this->despachar($job[0], $job[1], $job[2]);
        }
    }

    public function sugerir(Lead $lead): void
    {
        $this->iniciar($lead);
        $job = DB::transaction(function () use ($lead) {
            $chat = DemoChatConversacion::where('lead_id', $lead->id)->lockForUpdate()->firstOrFail();
            return $this->programar($chat);
        });
        $this->despachar($job[0], $job[1], $job[2]);
    }

    /** Una elección explícita entrega una tarjeta real, también en modo manual. */
    public function seleccionar(Lead $lead, string $clave, string $uuid): void
    {
        $catalogo = app(DemoChatCatalogoService::class)->videos($lead);
        if (! isset($catalogo[$clave]['posicion'])) {
            throw ValidationException::withMessages(['clave' => 'Ese video ya no está disponible. Elegí otro del catálogo.']);
        }
        $this->iniciar($lead);
        DB::transaction(function () use ($lead, $clave, $uuid, $catalogo) {
            $chat = DemoChatConversacion::where('lead_id', $lead->id)->lockForUpdate()->firstOrFail();
            // El UUID pertenece al hilo, nunca al navegador ni a un lead elegido en el body.
            if ($chat->mensajes()->where('cliente_uuid', $uuid)->exists()) {
                return;
            }
            if ($chat->mensajes()->where('origen', 'seleccion')->count() >= 300) {
                throw ValidationException::withMessages(['clave' => 'Llegaste al límite de selecciones de esta demo.']);
            }
            $this->crear($chat, 'asistente', 'Acá tenés «' . $catalogo[$clave]['titulo'] . '». Miralo a tu ritmo y preguntame lo que necesites.',
                [$clave], 'seleccion', 'publicado', $uuid);
            $chat->revision++;
            $chat->save();
        });
    }

    private function programar(DemoChatConversacion $chat): array
    {
        $chat->mensajes()->where('estado', 'borrador')->update(['estado' => 'descartado']);
        $chat->generacion++;
        $chat->revision++;
        $chat->estado = 'esperando';
        $chat->espera_iniciada_at = now();
        $chat->responder_desde = now()->addSeconds($chat->espera_segundos);
        $chat->ultimo_error = null;
        $chat->save();
        return [(int) $chat->id, (int) $chat->generacion, $chat->responder_desde->copy()];
    }

    private function despachar(int $id, int $generacion, $desde): void
    {
        // El worker permanente de esta cola ya existe. default se consume una vez por minuto.
        GenerateDemoChatReplyJob::dispatch($id, $generacion)
            ->onConnection('database')->onQueue(AsistenteWhatsappService::COLA_DEL_ASISTENTE)
            ->delay($desde->isFuture() ? $desde : now());
    }

    public function generar(int $id, int $generacion): void
    {
        $tomada = DB::transaction(function () use ($id, $generacion) {
            $chat = DemoChatConversacion::lockForUpdate()->find($id);
            if ($chat === null || $chat->generacion !== $generacion || $chat->estado !== 'esperando'
                || ($chat->responder_desde !== null && $chat->responder_desde->isFuture())) {
                return false;
            }
            $chat->estado = 'pensando';
            $chat->revision++;
            $chat->save();
            return true;
        });
        if (! $tomada) {
            return;
        }
        try {
            $chat = DemoChatConversacion::findOrFail($id);
            $lead = Lead::findOrFail($chat->lead_id);
            $salida = app(DemoChatAiService::class)->responder($lead, $chat);
            DB::transaction(function () use ($id, $generacion, $salida) {
                $chat = DemoChatConversacion::lockForUpdate()->findOrFail($id);
                // Un mensaje nuevo o una intervención mientras respondía invalida la respuesta completa.
                if ($chat->generacion !== $generacion || $chat->estado !== 'pensando') {
                    return;
                }
                $this->crear($chat, 'asistente', $salida['contenido'], $salida['videos'], 'ia', $chat->auto_responder ? 'publicado' : 'borrador');
                $chat->estado = $chat->auto_responder ? 'inactivo' : 'listo';
                $chat->responder_desde = null;
                $chat->revision++;
                $chat->save();
            });
        } catch (\Throwable $e) {
            DB::transaction(function () use ($id, $generacion, $e) {
                $chat = DemoChatConversacion::lockForUpdate()->find($id);
                if ($chat !== null && $chat->generacion === $generacion) {
                    $chat->estado = 'error';
                    $chat->ultimo_error = 'No se pudo generar la respuesta con DeepSeek. Revisá la configuración y volvé a sugerir.';
                    $chat->revision++;
                    $chat->save();
                }
            });
            report($e);
        }
    }

    public function configurar(Lead $lead, ?bool $automatico = null, ?int $espera = null): void
    {
        $id = $this->conversacion($lead)->id;
        $job = DB::transaction(function () use ($id, $automatico, $espera) {
            $chat = DemoChatConversacion::lockForUpdate()->findOrFail($id);
            $job = null;
            if ($automatico !== null) { $chat->auto_responder = $automatico; }
            if ($espera !== null && $espera !== $chat->espera_segundos) {
                if ($chat->estado === 'esperando') {
                    // El contrato anterior no guardaba el inicio: se recupera desde su vencimiento.
                    $inicio = $chat->espera_iniciada_at ?: $chat->responder_desde->copy()->subSeconds($chat->espera_segundos);
                    $chat->espera_iniciada_at = $inicio;
                    $chat->responder_desde = $inicio->copy()->addSeconds($espera);
                    // El job anterior queda invalidado aunque ya esté reservado por un worker.
                    $chat->generacion++;
                    $job = [(int) $chat->id, (int) $chat->generacion, $chat->responder_desde->copy()];
                }
                $chat->espera_segundos = $espera;
            }
            // Prender automático no publica un borrador viejo sin revisión.
            $chat->revision++;
            $chat->save();
            return $job;
        });
        if ($job !== null) { $this->despachar($job[0], $job[1], $job[2]); }
    }

    public function mensaje_admin(Lead $lead, string $accion, ?int $mensaje_id, array $datos, ?int $admin_id): void
    {
        $id = $this->conversacion($lead)->id;
        DB::transaction(function () use ($id, $lead, $accion, $mensaje_id, $datos, $admin_id) {
            $chat = DemoChatConversacion::lockForUpdate()->findOrFail($id);
            $mensaje = $mensaje_id === null ? null : $chat->mensajes()->where('id', $mensaje_id)->firstOrFail();
            if ($mensaje !== null && ! in_array($mensaje->estado, ['publicado', 'borrador'], true)) {
                abort(404);
            }
            if ($accion === 'crear') {
                $claves = $this->validar_videos($lead, (array) ($datos['videos'] ?? []));
                $this->crear($chat, 'asistente', trim($datos['contenido']), $claves, 'admin', 'publicado', null, $admin_id);
            } else {
                $revisiones = (array) $mensaje->revisiones;
                $revisiones[] = ['contenido' => $mensaje->contenido, 'videos' => $mensaje->videos, 'estado' => $mensaje->estado,
                    'accion' => $accion, 'admin_id' => $admin_id, 'fecha' => now()->toIso8601String()];
                $mensaje->revisiones = $revisiones;
                $mensaje->admin_id = $admin_id;
                if ($accion === 'editar') {
                    $mensaje->contenido = trim($datos['contenido']);
                    if (isset($datos['videos'])) {
                        $mensaje->videos = $this->validar_videos($lead, $datos['videos']);
                    }
                } elseif ($accion === 'eliminar') {
                    $mensaje->estado = 'eliminado';
                } elseif ($accion === 'enviar') {
                    if ($mensaje->rol !== 'asistente' || $mensaje->estado !== 'borrador') {
                        throw ValidationException::withMessages(['mensaje' => 'Este mensaje no es un borrador pendiente.']);
                    }
                    $mensaje->estado = 'publicado';
                }
                $mensaje->save();
            }
            // Editar un borrador lo conserva; cualquier intervención invalida la generación en vuelo.
            $chat->generacion++;
            $chat->revision++;
            $chat->responder_desde = null;
            if (in_array($accion, ['crear', 'enviar'], true)) {
                $chat->mensajes()->where('estado', 'borrador')->update(['estado' => 'descartado']);
            }
            $chat->estado = $chat->mensajes()->where('estado', 'borrador')->exists() ? 'listo' : 'inactivo';
            $chat->save();
        });
    }

    public function progreso(Lead $lead, string $clave, array $segundos, int $duracion, bool $omitir = false, bool $terminado = false): void
    {
        $id = $this->conversacion($lead)->id;
        DB::transaction(function () use ($id, $lead, $clave, $segundos, $duracion, $omitir, $terminado) {
            $chat = DemoChatConversacion::lockForUpdate()->findOrFail($id);
            $ofrecidos = $this->ofrecidos($chat);
            $catalogo = app(DemoChatCatalogoService::class)->videos($lead);
            if (! isset($ofrecidos[$clave]) || ! isset($catalogo[$clave])) {
                throw ValidationException::withMessages(['clave' => 'Ese video no forma parte de esta conversación.']);
            }
            $progreso = DemoChatProgreso::firstOrNew(['conversacion_id' => $id, 'clave' => $clave]);
            $antes = $progreso->completado_at !== null || $progreso->omitido;
            $largo = (int) $catalogo[$clave]['duracion'];
            if ($largo <= 0) {
                $largo = (int) $progreso->duracion > 0 ? (int) $progreso->duracion : $duracion;
            }
            $largo = min(7200, max(0, $largo));
            $vistos = array_values(array_unique(array_merge((array) $progreso->segundos_vistos, $segundos)));
            $vistos = array_values(array_filter($vistos, function ($s) use ($largo) { return is_int($s) && $s >= 0 && ($largo === 0 || $s < $largo); }));
            sort($vistos);
            $progreso->segundos_vistos = $vistos;
            $progreso->duracion = $largo;
            $progreso->omitido = $progreso->omitido || $omitir;
            // El porcentaje evita que un salto al final cuente como visto. El evento ended evita
            // anunciar «terminaste» mientras todavía se reproduce el último tramo del video.
            if ($terminado && $largo > 0 && count($vistos) / $largo >= 0.90 && $progreso->completado_at === null) {
                $progreso->completado_at = now();
            }
            $progreso->save();
            if (! $antes && ($progreso->completado_at !== null || $progreso->omitido)) {
                $terminados = [];
                foreach ($chat->progresos()->get() as $p) {
                    if ($p->completado_at !== null || $p->omitido) { $terminados[$p->clave] = true; }
                }
                $siguiente = app(DemoChatCatalogoService::class)->siguiente($lead, $terminados, $ofrecidos);
                // Si hay una pregunta en vuelo, la IA ve el avance y decide el próximo tema.
                if ($siguiente !== null && ! in_array($chat->estado, ['esperando', 'pensando', 'listo'], true)) {
                    $texto = $omitir ? 'Dale, lo dejamos para después.' : '¡Buenísimo, terminaste este video!';
                    $texto .= ' Si no te quedó ninguna duda, seguimos con «' . $siguiente['titulo'] . '». También podés saltearlo.';
                    $this->crear($chat, 'asistente', $texto, [$siguiente['clave']], 'guia', $chat->auto_responder ? 'publicado' : 'borrador');
                    $chat->estado = $chat->auto_responder ? 'inactivo' : 'listo';
                }
            }
            $chat->revision++;
            $chat->save();
        });
    }

    public function snapshot(Lead $lead, bool $admin = false): array
    {
        $chat = DemoChatConversacion::where('lead_id', $lead->id)->first();
        $salida = ['disponible' => true, 'version' => 1, 'conversacion' => [
            'auto_responder' => $chat ? $chat->auto_responder : true, 'estado' => $chat ? $chat->estado : 'inactivo',
            'responder_desde' => $chat && $chat->responder_desde ? $chat->responder_desde->toIso8601String() : null,
            'revision' => $chat ? $chat->revision : 0,
            'espera_segundos' => $chat ? $chat->espera_segundos : self::ESPERA_SEGUNDOS,
        ], 'mensajes' => [], 'catalogo' => []];
        $videos = app(DemoChatCatalogoService::class)->videos($lead);
        $progresos = $chat ? $chat->progresos()->get()->keyBy('clave') : collect();
        foreach ($videos as $clave => $video) {
            $videos[$clave] = $this->video_con_progreso($video, $progresos->get($clave));
        }
        // Un clip retirado sigue en el historial, pero no inventa un ordinal ni vuelve al selector.
        $salida['catalogo'] = array_values(array_filter($videos, function ($video) { return isset($video['posicion']); }));
        if ($chat === null) { return $salida; }
        foreach ($chat->mensajes()->whereIn('estado', $admin ? ['publicado', 'borrador'] : ['publicado'])->orderBy('id')->get() as $mensaje) {
            $adjuntos = [];
            foreach ((array) $mensaje->videos as $clave) {
                if (! isset($videos[$clave])) { continue; }
                $adjuntos[] = $videos[$clave];
            }
            $fila = ['id' => $mensaje->id, 'rol' => $mensaje->rol, 'contenido' => $mensaje->contenido,
                'creado_at' => $mensaje->created_at->toIso8601String(), 'videos' => $adjuntos];
            if ($admin) { $fila['estado'] = $mensaje->estado; $fila['origen'] = $mensaje->origen; }
            $salida['mensajes'][] = $fila;
        }
        if ($admin) { $salida['conversacion']['ultimo_error'] = $chat->ultimo_error; }
        return $salida;
    }

    private function video_con_progreso(array $video, ?DemoChatProgreso $progreso): array
    {
        unset($video['descripcion']);
        $duracion = $progreso && $progreso->duracion > 0 ? $progreso->duracion : $video['duracion'];
        $video['duracion'] = (int) $duracion;
        $video['porcentaje'] = $progreso && $duracion > 0 ? min(100, (int) floor(count((array) $progreso->segundos_vistos) * 100 / $duracion)) : 0;
        $video['completado'] = $progreso && $progreso->completado_at !== null;
        $video['omitido'] = $progreso ? (bool) $progreso->omitido : false;
        return $video;
    }

    private function crear(DemoChatConversacion $chat, string $rol, string $contenido, array $videos, string $origen,
        string $estado = 'publicado', ?string $uuid = null, ?int $admin_id = null): DemoChatMensaje
    {
        return $chat->mensajes()->create(['rol' => $rol, 'contenido' => $contenido, 'videos' => array_values($videos),
            'origen' => $origen, 'estado' => $estado, 'cliente_uuid' => $uuid, 'admin_id' => $admin_id]);
    }

    private function ofrecidos(DemoChatConversacion $chat): array
    {
        $resultado = [];
        foreach ($chat->mensajes()->where('estado', 'publicado')->get(['videos']) as $mensaje) {
            foreach ((array) $mensaje->videos as $clave) { $resultado[$clave] = true; }
        }
        return $resultado;
    }

    private function validar_videos(Lead $lead, array $claves): array
    {
        $catalogo = app(DemoChatCatalogoService::class)->videos($lead);
        foreach ($claves as $clave) {
            if (! is_string($clave) || ! isset($catalogo[$clave])) {
                throw ValidationException::withMessages(['videos' => 'Elegí videos disponibles del catálogo.']);
            }
        }
        return array_values(array_unique($claves));
    }
}
