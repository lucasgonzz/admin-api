<?php

namespace Tests\Feature\Missions;

use App\Models\Admin;
use App\Models\MissionAnswer;
use App\Models\MissionQuestion;
use App\Services\Local\EscenaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Herramientas compartidas por los tests del seguimiento de misiones en el Admin (misión dotin-misiones-admin,
 * 26/11/2026): el estado que publica la Gamer (`PUT claude/missions/{slug}`), las preguntas, las respuestas
 * sembradas directo en la base (para probar el consumo sin depender de la ruta humana) y las respuestas hechas
 * desde el Admin.
 *
 * Los nombres llevan el prefijo `misiones_` para poder usar este trait junto con `McpFixture` y `ReporteV2Fixture`
 * en una misma clase sin choques. Los textos de las claves y de los códigos de error se escriben literales, tal
 * como los fija el contrato del plan (§2.2.2 a §2.2.4): si cambian, los tests tienen que fallar.
 */
trait MissionFixture
{
    /** Clave de la Gamer (`X-Claude-Task-Key`) que se configura para los tests. */
    private static $clave_de_misiones = 'clave-de-claude-para-las-misiones';

    /** Hora fija de los tests (reloj de Argentina). */
    private static $hora_de_misiones = '2026-11-26 12:00:00';

    /** Pedido padre que se usa por defecto en las misiones de prueba. */
    private static $pedido_de_misiones = '20261126-1137-seguimiento-misiones-admin';

    /** Hilo de Slack que se usa por defecto en las misiones de prueba. */
    private static $hilo_de_misiones = 'C0CLS7JQF9D:1795703848.327699';

    /** @var array<string, int> Último `seq` mandado por slug (cada PUT usa el siguiente). */
    private $seq_de_misiones = [];

    /**
     * Configura la clave de la Gamer, vacía la caché de la escena y congela el reloj en el 26/11/2026 12:00 ART.
     * No toca `config('missions.*')`: los tests miden los valores por defecto del archivo de configuración.
     *
     * @return void
     */
    private function misiones_preparar()
    {
        config(['services.claude_task_ingest.key' => self::$clave_de_misiones]);
        Cache::forget(EscenaService::CACHE_KEY);
        $this->seq_de_misiones = [];
        $this->misiones_congelar(self::$hora_de_misiones);
    }

    /**
     * Congela el reloj en una hora de Argentina.
     *
     * @param string $fecha_hora `Y-m-d H:i:s` en hora argentina.
     *
     * @return void
     */
    private function misiones_congelar($fecha_hora)
    {
        Carbon::setTestNow(Carbon::parse($fecha_hora, 'America/Argentina/Buenos_Aires'));
    }

    /**
     * Suelta el reloj congelado. Se llama desde `tearDown()`.
     *
     * @return void
     */
    private function misiones_soltar_reloj()
    {
        Carbon::setTestNow();
    }

    /**
     * Una marca ISO-8601 de Argentina.
     *
     * @param string $fecha_hora `Y-m-d H:i:s` en hora argentina.
     *
     * @return string
     */
    private function misiones_iso($fecha_hora)
    {
        return Carbon::parse($fecha_hora, 'America/Argentina/Buenos_Aires')->toIso8601String();
    }

    /**
     * Encabezados de la Gamer: la clave que hoy está en la configuración (así sirve junto con otros fixtures que
     * la pisan) y JSON.
     *
     * @return array<string, string>
     */
    private function misiones_headers()
    {
        return ['X-Claude-Task-Key' => (string) config('services.claude_task_ingest.key'), 'Accept' => 'application/json'];
    }

    /**
     * Un UUID nuevo.
     *
     * @return string
     */
    private function misiones_uuid()
    {
        return (string) Str::uuid();
    }

    /**
     * El cuerpo de un `PUT claude/missions/{slug}` con todos los campos del contrato y `seq` creciente.
     *
     * @param string               $slug    Slug de la misión.
     * @param array<string, mixed> $cambios Cambios sobre el cuerpo (si trae `seq`, se usa ése).
     *
     * @return array<string, mixed>
     */
    private function misiones_cuerpo($slug, array $cambios = [])
    {
        $siguiente = (isset($this->seq_de_misiones[$slug]) ? $this->seq_de_misiones[$slug] : 0) + 1;
        $cuerpo    = array_merge([
            'seq'                      => $siguiente,
            'host'                     => 'lucas-pc',
            'titulo'                   => 'Misión de prueba ' . $slug,
            'origen'                   => 'dots',
            'pedido_id'                => self::$pedido_de_misiones,
            'hilo'                     => self::$hilo_de_misiones,
            'repos'                    => ['admin-api'],
            'estado'                   => 'en_cola',
            'fase'                     => null,
            'fase_desde'               => null,
            'estado_desde'             => null,
            'ultimo_avance'            => null,
            'pendiente'                => null,
            'motivo'                   => null,
            'proximo_paso'             => null,
            'ejecucion_n'              => 0,
            'intentos_tecnicos'        => 0,
            'arranque_confirmado_at'   => null,
            'ultima_actividad_real_at' => null,
            'pregunta_abierta'         => null,
            'evidencia'                => null,
        ], $cambios);
        $this->seq_de_misiones[$slug] = max($siguiente, (int) $cuerpo['seq']);

        return $cuerpo;
    }

    /**
     * Publica el estado de una misión como lo hace la Gamer.
     *
     * @param string               $slug    Slug de la misión.
     * @param array<string, mixed> $cambios Cambios sobre el cuerpo.
     * @param string|null          $hora    Si viene, congela el reloj en esa hora antes de publicar.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function misiones_put($slug, array $cambios = [], $hora = null)
    {
        if ($hora !== null) {
            $this->misiones_congelar($hora);
        }

        return $this->putJson('/api/claude/missions/' . $slug, $this->misiones_cuerpo($slug, $cambios), $this->misiones_headers());
    }

    /**
     * Una evidencia válida (un merge verificado) para publicar una misión `terminada`.
     *
     * @return array<string, mixed>
     */
    private function misiones_evidencia()
    {
        return ['merges' => ['admin-api@master=0123456789abcdef0123456789abcdef01234567']];
    }

    /**
     * Las dos opciones de una pregunta de prueba, con su impacto.
     *
     * @return array<int, array<string, string>>
     */
    private function misiones_opciones()
    {
        return [
            ['clave' => 'A', 'texto' => 'Sí, seguí con lo propuesto', 'impacto' => 'La misión sigue sola con su recomendación'],
            ['clave' => 'B', 'texto' => 'No, frená', 'impacto' => 'La misión queda detenida esperando otra instrucción'],
        ];
    }

    /**
     * Publica una pregunta como lo hace la Gamer (`POST claude/missions/{slug}/questions`).
     *
     * @param string               $slug    Slug de la misión (tiene que existir).
     * @param string               $uid     UUID de la pregunta.
     * @param array<string, mixed> $cambios Cambios sobre el cuerpo.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function misiones_post_pregunta($slug, $uid, array $cambios = [])
    {
        $cuerpo = array_merge([
            'question_uid' => $uid,
            'revision'     => 1,
            'tipo'         => 'ordinaria',
            'pregunta'     => '¿Seguimos con la opción recomendada?',
            'opciones'     => $this->misiones_opciones(),
            'recomendada'  => 'A',
        ], $cambios);

        return $this->postJson('/api/claude/missions/' . $slug . '/questions', $cuerpo, $this->misiones_headers());
    }

    /**
     * Deja una misión `esperando_decision` con una pregunta abierta y devuelve el uid de la pregunta.
     *
     * @param string               $slug    Slug de la misión (se crea).
     * @param string               $tipo    `ordinaria` o `sensible`.
     * @param array<string, mixed> $estado  Cambios sobre el estado publicado de la misión.
     *
     * @return string UUID de la pregunta abierta.
     */
    private function misiones_con_pregunta($slug, $tipo = 'ordinaria', array $estado = [])
    {
        $this->misiones_put($slug, array_merge(['estado' => 'esperando_decision', 'ejecucion_n' => 1], $estado))->assertStatus(200);

        $uid = $this->misiones_uuid();
        $this->misiones_post_pregunta($slug, $uid, ['tipo' => $tipo])->assertStatus(201);

        return $uid;
    }

    /**
     * Un admin humano (sin `solo_leads` ni `es_agente`) con email único; `$cambios` pisa columnas.
     *
     * @param array<string, mixed> $cambios Columnas a pisar.
     *
     * @return Admin
     */
    private function misiones_admin(array $cambios = [])
    {
        return Admin::create(array_merge([
            'name'     => 'Lucas de las misiones',
            'email'    => 'misiones-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('clave-segura-misiones-1'),
        ], $cambios));
    }

    /**
     * Siembra una respuesta directo en la base (sin pasar por la ruta del Admin) para probar el consumo de la
     * Gamer. Deja la pregunta `respondida`.
     *
     * @param string               $slug    Slug de la misión.
     * @param string               $uid     UUID de la pregunta (tiene que existir).
     * @param array<string, mixed> $cambios Columnas a pisar de la respuesta.
     *
     * @return MissionAnswer
     */
    private function misiones_sembrar_respuesta($slug, $uid, array $cambios = [])
    {
        $pregunta = MissionQuestion::where('question_uid', $uid)->firstOrFail();
        $autor    = isset($cambios['admin_id']) ? null : $this->misiones_admin();

        $respuesta = MissionAnswer::create(array_merge([
            'question_id'  => $pregunta->id,
            'mission_key'  => 'cc:' . $slug,
            'question_uid' => $uid,
            'revision'     => $pregunta->revision,
            'answer_uid'   => $this->misiones_uuid(),
            'opcion'       => 'A',
            'texto'        => null,
            'admin_id'     => $autor === null ? 0 : $autor->id,
            'canal'        => 'admin',
            'estado'       => 'registrada',
        ], $cambios));

        $pregunta->estado = 'respondida';
        $pregunta->save();

        return $respuesta;
    }

    /**
     * Registra una respuesta como lo hace el Admin: `POST api/admin/local/missions/{slug}/questions/{uid}/answer`.
     *
     * @param Admin|null           $admin  Quien responde (null = sin login).
     * @param string               $slug   Slug de la misión.
     * @param string               $uid    UUID de la pregunta.
     * @param array<string, mixed> $cuerpo Cuerpo (`revision`, `opcion`, `texto`, `answer_uid`).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function misiones_responder($admin, $slug, $uid, array $cuerpo)
    {
        $llamador = $admin === null ? $this : $this->actingAs($admin, 'sanctum');

        return $llamador->postJson('/api/admin/local/missions/' . $slug . '/questions/' . $uid . '/answer', $cuerpo);
    }
}
