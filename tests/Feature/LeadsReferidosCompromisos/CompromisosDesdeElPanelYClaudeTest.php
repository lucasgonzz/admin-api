<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Models\Lead;
use App\Models\LeadMessage;

/**
 * Compromisos desde el panel y desde Claude, y el compromiso en el contexto de Martín: criterios CA16 y CA17 de la etapa
 * E2 de la misión leads-referidos-compromisos.
 *
 * Panel (`admin/*`, con el admin logueado):
 *  - `GET admin/lead/{id}/compromisos` → 200 `{compromisos: [...]}`: el vigente primero y después el historial;
 *  - `POST admin/lead/{id}/compromisos` `{vence_at, accion, resumen, cita_del_lead?}` → 201 `{model}`; reemplaza al vigente;
 *    una fecha pasada (o a más de 90 días) es 422 con `errors.vence_at`; con el motor apagado no crea nada;
 *  - `PUT admin/lead-compromisos/{id}` cambia fecha, acción o resumen → 200 `{model}`;
 *  - `POST admin/lead-compromisos/{id}/cancelar` → 200 `{model}` en `cancelado`, con `cerrado_por_admin_id`.
 * Claude (`claude/*`, con `X-Claude-Task-Key`): lo usan `whatsapp-personal` y `chequeo-de-leads` para los compromisos que
 * Lucas pacta en su WhatsApp personal. `GET claude/leads/{id}/compromisos`, `POST claude/leads/{id}/compromisos` (origen
 * `agente`) y `POST claude/lead-compromisos/{id}/cancelar`; sin clave 401 y `dry_run` en true por defecto (cero escrituras).
 *
 * El perfil «Sólo Leads» (Juan) puede ver, crear, reprogramar y cancelar los compromisos de sus leads —es quien recibe el
 * «llamame el viernes»— pero NINGUNA de esas rutas dispara un envío a WhatsApp: lo que el motor le escriba al lead sale
 * por su camino de siempre (sugerencia con aprobación).
 *
 * Y con un compromiso vigente Martín lo sabe: su contexto trae «PRÓXIMO CONTACTO ACORDADO» para no proponer otro día.
 *
 * 🔴 Contrato que fijan estos tests y que el PLAN no escribe: los nombres de los campos del cuerpo (`vence_at`, `accion`,
 * `resumen`, `cita_del_lead`, `motivo`, `dry_run`) y las claves de las respuestas (`compromisos`, `model`, `dry_run`).
 *
 * Fechas: calendario real de 2026. «Hoy» es el martes 24/11 a las 12:00.
 *
 * Hoy están EN ROJO: no existen la tabla `lead_compromisos` ni ninguna de las rutas.
 */
class CompromisosDesdeElPanelYClaudeTest extends BaseDeReferidosYCompromisos
{
    /**
     * Martes 24/11 a las 12:00, con el motor prendido y el operador logueado.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ir_a('2026-11-24 12:00:00');
        $this->prender_el_motor();
    }

    /**
     * Cabeceras con la clave de ingesta de Claude.
     *
     * @return array<string, string>
     */
    private function cabeceras(): array
    {
        return ['X-Claude-Task-Key' => self::CLAVE_DE_CLAUDE, 'Accept' => 'application/json'];
    }

    // ------------------------------------------------------------------------------------------
    // Panel
    // ------------------------------------------------------------------------------------------

    /**
     * CA16. `GET admin/lead/{id}/compromisos` trae el vigente primero y después el historial (los reemplazados y los
     * cancelados), cada uno con su estado, acción, origen, fecha y resumen. Un lead que no existe es 404.
     *
     * @return void
     */
    public function test_listar(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead   = $this->lead_de_ro();
        $viejo  = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-27 10:00', 'resumen' => 'El del viernes.']);
        $this->ir_a('2026-11-25 10:00:00');
        $nuevo  = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-30 11:00', 'resumen' => 'El del lunes.']);

        $respuesta = $this->getJson('/api/admin/lead/' . $lead->id . '/compromisos');

        $respuesta->assertStatus(200);
        $this->assertCount(2, $respuesta->json('compromisos'));
        $respuesta->assertJsonPath('compromisos.0.id', (int) $nuevo->id);
        $respuesta->assertJsonPath('compromisos.0.status', 'vigente');
        $respuesta->assertJsonPath('compromisos.0.accion', 'llamar');
        $respuesta->assertJsonPath('compromisos.0.resumen', 'El del lunes.');
        $this->assertNotEmpty($respuesta->json('compromisos.0.vence_at'));
        $respuesta->assertJsonPath('compromisos.1.id', (int) $viejo->id);
        $respuesta->assertJsonPath('compromisos.1.status', 'reemplazado');

        $this->getJson('/api/admin/lead/99999999/compromisos')->assertStatus(404);
    }

    /**
     * CA16. `POST admin/lead/{id}/compromisos` con una fecha futura crea el compromiso (201, `origen = manual`, a nombre del
     * admin, con la huella del momento) y REEMPLAZA al vigente: el anterior queda `reemplazado`. Con sólo el día, la hora
     * es la de siempre: 10:00 para llamar y escribir, 18:00 para esperar.
     *
     * @return void
     */
    public function test_crear_manual_reemplaza(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->lead_de_ro();
        $this->entrante_a_las($lead, 'Hola, quiero ver cómo funciona', '2026-11-24 11:00:00');
        $ultimo = LeadMessage::query()->where('lead_id', (int) $lead->id)->max('id');

        $primero = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-27 10:00', 'accion' => 'llamar']);
        $this->assertSame('manual', $primero->origen);
        $this->assertSame((int) $this->admin->id, (int) $primero->created_by_admin_id);
        $this->assertSame((int) $ultimo, (int) $primero->baseline_lead_message_id, 'La huella toma el último mensaje del hilo.');
        $this->assertSame('contactado', $primero->estado_lead_al_crear);

        $segundo = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-30', 'accion' => 'escribir']);
        $this->assertSame('2026-11-30 10:00:00', $this->fecha_ar($segundo->vence_at), 'Sólo la fecha: escribir sale a las 10:00.');
        $this->assertSame('reemplazado', $this->compromiso((int) $primero->id)->status);
        $this->assertSame((int) $segundo->id, (int) $this->compromiso((int) $primero->id)->reemplazado_por_id);
        $this->assertCount(1, $this->compromisos_de($lead, 'vigente'));

        $tercero = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-12-01', 'accion' => 'esperar']);
        $this->assertSame('2026-12-01 18:00:00', $this->fecha_ar($tercero->vence_at), 'Esperar le da el día al lead: 18:00.');
    }

    /**
     * CA16. Una fecha pasada, a más de 90 días, sin fecha, o una acción que no existe: 422 con el error del campo y no se
     * guarda nada (ni se toca el vigente que hubiera).
     *
     * @return void
     */
    public function test_fecha_pasada_422(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead     = $this->lead_de_ro();
        $vigente  = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-27 10:00']);
        $this->loguear_a($this->admin);

        $casos = [
            'vence_at' => [
                ['vence_at' => '2026-11-23 10:00', 'accion' => 'llamar', 'resumen' => 'Ayer.'],
                ['vence_at' => '2027-03-15 10:00', 'accion' => 'llamar', 'resumen' => 'Muy lejos.'],
                ['accion' => 'llamar', 'resumen' => 'Sin fecha.'],
                ['vence_at' => 'cuando pueda', 'accion' => 'llamar', 'resumen' => 'Texto.'],
            ],
            'accion' => [
                ['vence_at' => '2026-11-30 10:00', 'accion' => 'hacer_magia', 'resumen' => 'Acción inventada.'],
            ],
        ];
        foreach ($casos as $campo => $cuerpos) {
            foreach ($cuerpos as $cuerpo) {
                $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/compromisos', $cuerpo);
                $respuesta->assertStatus(422)->assertJsonStructure(['errors' => [$campo]]);
            }
        }

        $this->assertCount(1, $this->compromisos_de($lead), 'Nada de eso se guardó.');
        $this->assertSame('vigente', $this->compromiso((int) $vigente->id)->status, 'Y el vigente sigue vigente.');
    }

    /**
     * CA16. Con el motor APAGADO el panel no crea compromisos (el PLAN: `crear` no hace nada con el interruptor apagado, así
     * ninguno queda guardado sin que nadie lo ejecute). Responde un error (no 201) y no se guarda nada.
     *
     * @return void
     */
    public function test_con_el_motor_apagado_no_se_crea(): void
    {
        $this->exigir_tabla_de_compromisos();
        $this->motor_de_compromisos(false);
        $lead = $this->lead_de_ro();
        $this->loguear_a($this->admin);

        $respuesta = $this->postJson('/api/admin/lead/' . $lead->id . '/compromisos', ['vence_at' => '2026-11-27 10:00', 'accion' => 'llamar', 'resumen' => 'x']);

        $this->assertNotSame(201, $respuesta->getStatusCode(), 'Con el motor apagado no se crea.');
        $this->assertCount(0, $this->compromisos_de($lead));
    }

    /**
     * CA16. `PUT admin/lead-compromisos/{id}` cambia la fecha (y la acción y el resumen si vienen): 200 con el modelo y el
     * cambio guardado. Una fecha pasada es 422 y no cambia nada.
     *
     * @return void
     */
    public function test_cambiar_fecha(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->lead_de_ro();
        $fila = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-27 10:00', 'accion' => 'llamar', 'resumen' => 'Llamarlo el viernes.']);
        $this->loguear_a($this->admin);

        $respuesta = $this->putJson('/api/admin/lead-compromisos/' . $fila->id, ['vence_at' => '2026-12-02 15:30', 'resumen' => 'Mejor el martes a la tarde.']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('model.id', (int) $fila->id);
        $cambiada = $this->compromiso((int) $fila->id);
        $this->assertSame('2026-12-02 15:30:00', $this->fecha_ar($cambiada->vence_at));
        $this->assertSame('Mejor el martes a la tarde.', $cambiada->resumen);
        $this->assertSame('llamar', $cambiada->accion, 'Lo que no vino no se toca.');
        $this->assertSame('vigente', $cambiada->status);

        $this->putJson('/api/admin/lead-compromisos/' . $fila->id, ['vence_at' => '2026-11-20 10:00'])->assertStatus(422)->assertJsonStructure(['errors' => ['vence_at']]);
        $this->assertSame('2026-12-02 15:30:00', $this->fecha_ar($this->compromiso((int) $fila->id)->vence_at), 'La fecha pasada no se guarda.');
        $this->putJson('/api/admin/lead-compromisos/99999999', ['vence_at' => '2026-12-02 15:30'])->assertStatus(404);
    }

    /**
     * CA16. `POST admin/lead-compromisos/{id}/cancelar` lo deja `cancelado`, con quién lo cerró; el lead queda sin vigente
     * (los seguimientos genéricos vuelven a correr).
     *
     * @return void
     */
    public function test_cancelar(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->lead_de_ro();
        $fila = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-27 10:00']);
        $this->loguear_a($this->admin);

        $respuesta = $this->postJson('/api/admin/lead-compromisos/' . $fila->id . '/cancelar', ['motivo' => 'El lead ya no lo necesita.']);

        $respuesta->assertStatus(200);
        $cancelado = $this->compromiso((int) $fila->id);
        $this->assertSame('cancelado', $cancelado->status);
        $this->assertSame((int) $this->admin->id, (int) $cancelado->cerrado_por_admin_id);
        $this->assertStringContainsString('ya no lo necesita', (string) $cancelado->resultado, 'El motivo queda guardado.');
        $this->assertNull($this->compromiso_vigente_de($lead));
    }

    /**
     * CA16. Si el lead tiene una tarjeta abierta en el tablero, crear el compromiso pone la fecha del compromiso como la
     * próxima acción de la tarjeta (`next_action_at`) y la nota empieza con «Compromiso:».
     *
     * @return void
     */
    public function test_tarjeta_next_action(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead    = $this->lead_de_ro();
        $tarjeta = $this->dar_de_alta_en($lead, 'interesados');

        $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-27 10:00', 'accion' => 'llamar', 'resumen' => 'Llamarlo por la prueba.']);

        $this->assertSame('2026-11-27 10:00:00', $this->proxima_accion_de($tarjeta), 'La próxima acción de la tarjeta es la hora del compromiso.');
        $this->assertStringStartsWith('Compromiso:', (string) $tarjeta->fresh()->next_action_note);
        $this->assertStringContainsString('Llamarlo por la prueba.', (string) $tarjeta->fresh()->next_action_note);
    }

    // ------------------------------------------------------------------------------------------
    // El perfil «Sólo Leads»
    // ------------------------------------------------------------------------------------------

    /**
     * Juan (perfil «Sólo Leads») ve los compromisos de sus leads, crea uno cuando el lead le dice «llamame el viernes», lo
     * reprograma y lo cancela. Y nada de eso manda un WhatsApp: no sale ninguna plantilla ni texto, y no queda ningún
     * mensaje nuestro en el hilo. La respuesta le llega completa (el filtro de salida del perfil no se la recorta).
     *
     * @return void
     */
    public function test_juan_ve_y_reprograma_pero_no_dispara_envios(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->lead_de_ro();
        $this->entrante_a_las($lead, 'Hola, ¿me pueden llamar el viernes?', '2026-11-24 11:00:00');
        $salientes = $this->cantidad_de_salientes($lead);
        $this->loguear_a($this->juan);

        $creado = $this->postJson('/api/admin/lead/' . $lead->id . '/compromisos', [
            'vence_at' => '2026-11-27 10:00', 'accion' => 'escribir', 'resumen' => 'Escribirle el viernes para ver cómo le fue.',
        ]);
        $creado->assertStatus(201);
        $id = (int) $creado->json('model.id');
        $this->assertGreaterThan(0, $id, 'Juan ve el modelo que creó.');

        $lista = $this->getJson('/api/admin/lead/' . $lead->id . '/compromisos');
        $lista->assertStatus(200);
        $lista->assertJsonPath('compromisos.0.id', $id);
        $lista->assertJsonPath('compromisos.0.accion', 'escribir');
        $this->assertNotEmpty($lista->json('compromisos.0.vence_at'), 'El filtro de salida del perfil no recorta la respuesta.');
        $this->assertNotEmpty($lista->json('compromisos.0.resumen'));

        $this->putJson('/api/admin/lead-compromisos/' . $id, ['vence_at' => '2026-11-30 11:00'])->assertStatus(200);
        $this->assertSame('2026-11-30 11:00:00', $this->fecha_ar($this->compromiso($id)->vence_at), 'Juan lo reprograma.');

        $this->postJson('/api/admin/lead-compromisos/' . $id . '/cancelar', ['motivo' => 'Se arregló por teléfono.'])->assertStatus(200);
        $cancelado = $this->compromiso($id);
        $this->assertSame('cancelado', $cancelado->status);
        $this->assertSame((int) $this->juan->id, (int) $cancelado->cerrado_por_admin_id);

        $this->assertSame([], $this->envios->plantillas, 'Ninguna de esas rutas manda una plantilla.');
        $this->assertSame([], $this->envios->textos, 'Ni un texto.');
        $this->assertSame($salientes, $this->cantidad_de_salientes($lead), 'Y no queda nada nuestro en el hilo del lead.');
        $this->assertSame(0, LeadMessage::query()->where('lead_id', (int) $lead->id)->where('system_message_kind', 'lead_compromiso')->count());
    }

    // ------------------------------------------------------------------------------------------
    // Claude
    // ------------------------------------------------------------------------------------------

    /**
     * CA16. Sin la clave (o con una equivocada) las tres rutas de Claude responden 401 y no se escribe ni se lee nada.
     *
     * @return void
     */
    public function test_claude_sin_clave_401(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->lead_de_ro();
        $fila = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-27 10:00']);
        $cuerpo = ['vence_at' => '2026-11-30 10:00', 'accion' => 'llamar', 'resumen' => 'x', 'dry_run' => false];

        $this->getJson('/api/claude/leads/' . $lead->id . '/compromisos')->assertStatus(401);
        $this->postJson('/api/claude/leads/' . $lead->id . '/compromisos', $cuerpo)->assertStatus(401);
        $this->postJson('/api/claude/leads/' . $lead->id . '/compromisos', $cuerpo, ['X-Claude-Task-Key' => 'clave-equivocada'])->assertStatus(401);
        $this->postJson('/api/claude/lead-compromisos/' . $fila->id . '/cancelar', ['dry_run' => false])->assertStatus(401);

        $this->assertCount(1, $this->compromisos_de($lead), 'No se creó nada.');
        $this->assertSame('vigente', $this->compromiso((int) $fila->id)->status, 'No se canceló nada.');
    }

    /**
     * CA16. Claude: `dry_run` en true por defecto (200 con lo que haría y CERO escrituras); con `dry_run=false` crea (201,
     * `origen = agente`) o cancela (200). Y puede leer los compromisos (`GET`, vigente primero).
     *
     * @return void
     */
    public function test_claude_dry_run(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead   = $this->lead_de_ro();
        $cuerpo = ['vence_at' => '2026-11-27 10:00', 'accion' => 'esperar', 'resumen' => 'Ro dijo en el WhatsApp de Lucas que avisa el viernes.', 'cita_del_lead' => 'el viernes te aviso'];

        $por_defecto = $this->withHeaders($this->cabeceras())->postJson('/api/claude/leads/' . $lead->id . '/compromisos', $cuerpo);
        $por_defecto->assertStatus(200);
        $por_defecto->assertJsonPath('dry_run', true);
        $explicito = $this->withHeaders($this->cabeceras())->postJson('/api/claude/leads/' . $lead->id . '/compromisos', array_merge($cuerpo, ['dry_run' => true]));
        $explicito->assertStatus(200);
        $explicito->assertJsonPath('dry_run', true);
        $this->assertCount(0, $this->compromisos_de($lead), 'El dry_run no escribe.');

        $real = $this->withHeaders($this->cabeceras())->postJson('/api/claude/leads/' . $lead->id . '/compromisos', array_merge($cuerpo, ['dry_run' => false]));
        $real->assertStatus(201);
        $fila = $this->compromiso_vigente_de($lead);
        $this->assertNotNull($fila);
        $this->assertSame('agente', $fila->origen);
        $this->assertSame('esperar', $fila->accion);
        $this->assertSame('2026-11-27 10:00:00', $this->fecha_ar($fila->vence_at), 'Con la hora dada se respeta, aunque sea un «esperar».');

        $lista = $this->withHeaders($this->cabeceras())->getJson('/api/claude/leads/' . $lead->id . '/compromisos');
        $lista->assertStatus(200);
        $lista->assertJsonPath('compromisos.0.id', (int) $fila->id);
        $lista->assertJsonPath('compromisos.0.status', 'vigente');

        $simulado = $this->withHeaders($this->cabeceras())->postJson('/api/claude/lead-compromisos/' . $fila->id . '/cancelar', ['motivo' => 'Ya hablaron.']);
        $simulado->assertStatus(200);
        $simulado->assertJsonPath('dry_run', true);
        $this->assertSame('vigente', $this->compromiso((int) $fila->id)->status, 'El dry_run no cancela.');

        $this->withHeaders($this->cabeceras())->postJson('/api/claude/lead-compromisos/' . $fila->id . '/cancelar', ['motivo' => 'Ya hablaron.', 'dry_run' => false])->assertStatus(200);
        $this->assertSame('cancelado', $this->compromiso((int) $fila->id)->status);
        $this->withHeaders($this->cabeceras())->postJson('/api/claude/leads/99999999/compromisos', array_merge($cuerpo, ['dry_run' => false]))->assertStatus(404);
    }

    // ------------------------------------------------------------------------------------------
    // CA17 — Martín
    // ------------------------------------------------------------------------------------------

    /**
     * CA17. Con un compromiso vigente, el contexto del turno de Martín trae «PRÓXIMO CONTACTO ACORDADO» con el día en
     * palabras, el resumen y la instrucción de no proponer otro día; sin vigente (o con el compromiso ya cancelado), no.
     *
     * @return void
     */
    public function test_martin_ve_el_compromiso(): void
    {
        $this->exigir_tabla_de_compromisos();
        $lead = $this->lead_de_ro();
        $this->assertStringNotContainsString('PRÓXIMO CONTACTO ACORDADO', $this->contexto_del_turno($lead->fresh()), 'Sin compromiso, el bloque no está.');

        $fila = $this->crear_compromiso_por_el_panel($lead, ['vence_at' => '2026-11-27 10:00', 'accion' => 'llamar', 'resumen' => 'Juan lo llama para ver cómo le fue con la prueba.']);

        $contexto = $this->contexto_del_turno($lead->fresh());
        $this->assertStringContainsString('PRÓXIMO CONTACTO ACORDADO', $contexto);
        $this->assertStringContainsStringIgnoringCase('viernes', $contexto, 'El día, en palabras.');
        $this->assertStringContainsString('Juan lo llama para ver cómo le fue con la prueba.', $contexto);
        $this->assertStringContainsString('No propongas otro día', $contexto);

        $this->loguear_a($this->admin);
        $this->postJson('/api/admin/lead-compromisos/' . $fila->id . '/cancelar', [])->assertStatus(200);
        $this->assertStringNotContainsString('PRÓXIMO CONTACTO ACORDADO', $this->contexto_del_turno($lead->fresh()), 'Cancelado, el bloque desaparece.');
    }
}
