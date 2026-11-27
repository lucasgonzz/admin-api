<?php

namespace Tests\Feature\Missions;

use App\Models\MissionQuestion;
use App\Models\MissionTracking;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CA6 de la misión dotin-misiones-admin: las preguntas que publica la Gamer (`POST claude/missions/{slug}/questions`).
 *
 * Reglas medidas: uid nuevo reemplaza a la abierta, misma revisión es duplicada, revisión mayor actualiza y
 * rechaza la respuesta pendiente de la revisión vieja, revisión menor y uid ajeno son 409, las opciones se validan.
 */
class PreguntasDeMisionTest extends TestCase
{
    use DatabaseTransactions;
    use MissionFixture;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->misiones_preparar();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->misiones_soltar_reloj();

        parent::tearDown();
    }

    /**
     * La pregunta por su uid.
     *
     * @param string $uid UUID.
     *
     * @return MissionQuestion|null
     */
    private function pregunta($uid)
    {
        return MissionQuestion::where('question_uid', $uid)->first();
    }

    /**
     * Un uid nuevo reemplaza a la abierta (y rechaza su respuesta pendiente); la reemplazada ya no se puede reabrir.
     *
     * @return void
     */
    public function test_uid_nuevo_reemplaza_abierta(): void
    {
        $primera = $this->misiones_con_pregunta('pregs');
        $this->assertSame('abierta', $this->pregunta($primera)->estado);
        $this->assertSame($primera, MissionTracking::where('mission_key', 'cc:pregs')->first()->pregunta_abierta_uid);
        $pendiente = $this->misiones_sembrar_respuesta('pregs', $primera);

        $segunda = $this->misiones_uuid();
        $alta    = $this->misiones_post_pregunta('pregs', $segunda, ['pregunta' => 'Otra pregunta distinta']);
        $alta->assertStatus(201);
        $this->assertSame($segunda, $alta->json('question.question_uid'));

        $this->assertSame('reemplazada', $this->pregunta($primera)->estado);
        $this->assertSame('abierta', $this->pregunta($segunda)->estado);
        $this->assertSame($segunda, MissionTracking::where('mission_key', 'cc:pregs')->first()->pregunta_abierta_uid);
        $this->assertSame('rechazada', $pendiente->fresh()->estado);
        $this->assertSame('reemplazada', $pendiente->fresh()->rechazo_motivo);

        // La reemplazada no vuelve.
        $reabrir = $this->misiones_post_pregunta('pregs', $primera);
        $reabrir->assertStatus(409);
        $this->assertSame('pregunta_cerrada', $reabrir->json('codigo'));
    }

    /**
     * Una revisión mayor actualiza texto y opciones, la pregunta vuelve a `abierta` y la respuesta vieja se rechaza.
     *
     * @return void
     */
    public function test_revision_mayor_rechaza_respuesta_vieja(): void
    {
        $uid       = $this->misiones_con_pregunta('revis');
        $respuesta = $this->misiones_sembrar_respuesta('revis', $uid);
        $this->assertSame('respondida', $this->pregunta($uid)->estado);

        $nueva = $this->misiones_post_pregunta('revis', $uid, [
            'revision' => 2,
            'pregunta' => 'Pregunta corregida',
            'opciones' => [
                ['clave' => 'A', 'texto' => 'Opción A nueva', 'impacto' => 'Impacto nuevo A'],
                ['clave' => 'B', 'texto' => 'Opción B nueva', 'impacto' => 'Impacto nuevo B'],
                ['clave' => 'C', 'texto' => 'Opción C', 'impacto' => 'Impacto C'],
            ],
            'recomendada' => 'C',
        ]);
        $nueva->assertStatus(200);

        $pregunta = $this->pregunta($uid);
        $this->assertSame(2, (int) $pregunta->revision);
        $this->assertSame('abierta', $pregunta->estado);
        $this->assertSame('Pregunta corregida', $pregunta->pregunta);
        $this->assertCount(3, $pregunta->opciones);
        $this->assertSame('C', $pregunta->recomendada);

        $this->assertSame('rechazada', $respuesta->fresh()->estado);
        $this->assertSame('revision_reemplazada', $respuesta->fresh()->rechazo_motivo);
    }

    /**
     * Una revisión menor que la vigente es 409 `revision_vieja` y no cambia nada.
     *
     * @return void
     */
    public function test_revision_menor_409(): void
    {
        $uid = $this->misiones_con_pregunta('menor');
        $this->misiones_post_pregunta('menor', $uid, ['revision' => 2, 'pregunta' => 'Segunda'])->assertStatus(200);

        $vieja = $this->misiones_post_pregunta('menor', $uid, ['revision' => 1, 'pregunta' => 'Primera de nuevo']);
        $vieja->assertStatus(409);
        $this->assertSame('revision_vieja', $vieja->json('codigo'));
        $this->assertSame('Segunda', $this->pregunta($uid)->pregunta);
        $this->assertSame(2, (int) $this->pregunta($uid)->revision);
    }

    /**
     * Un uid que ya es de otra misión es 409 `pregunta_ajena`; una misión inexistente es 404.
     *
     * @return void
     */
    public function test_uid_ajeno_409(): void
    {
        $uid = $this->misiones_con_pregunta('duena');
        $this->misiones_put('intrusa', ['estado' => 'esperando_decision'])->assertStatus(200);

        $ajena = $this->misiones_post_pregunta('intrusa', $uid);
        $ajena->assertStatus(409);
        $this->assertSame('pregunta_ajena', $ajena->json('codigo'));
        $this->assertSame('cc:duena', $this->pregunta($uid)->mission_key);

        $inexistente = $this->misiones_post_pregunta('no-existe', $this->misiones_uuid());
        $inexistente->assertStatus(404);
        $this->assertSame('mision_inexistente', $inexistente->json('codigo'));
    }

    /**
     * Las opciones: de 2 a 6, claves distintas y con el formato del contrato; el tipo y el uid también se validan.
     *
     * @return void
     */
    public function test_opciones_invalidas_422(): void
    {
        $this->misiones_put('opc', ['estado' => 'esperando_decision'])->assertStatus(200);
        $una = [['clave' => 'A', 'texto' => 'Sola', 'impacto' => 'Nada']];
        $siete = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $clave) {
            $siete[] = ['clave' => $clave, 'texto' => 'Opción ' . $clave, 'impacto' => 'Impacto ' . $clave];
        }

        $malas = [
            ['opciones' => $una],
            ['opciones' => $siete],
            ['opciones' => [['clave' => 'A', 'texto' => 'Uno', 'impacto' => 'x'], ['clave' => 'A', 'texto' => 'Dos', 'impacto' => 'y']]],
            ['opciones' => [['clave' => 'A B', 'texto' => 'Uno', 'impacto' => 'x'], ['clave' => 'B', 'texto' => 'Dos', 'impacto' => 'y']]],
            ['opciones' => [['clave' => 'A', 'texto' => '', 'impacto' => 'x'], ['clave' => 'B', 'texto' => 'Dos', 'impacto' => 'y']]],
            ['tipo' => 'urgente'],
            ['revision' => 0],
            ['pregunta' => ''],
            ['question_uid' => 'no-es-uuid'],
        ];
        foreach ($malas as $cambio) {
            $this->misiones_post_pregunta('opc', $this->misiones_uuid(), $cambio)->assertStatus(422);
        }
        $this->assertSame(0, MissionQuestion::where('mission_key', 'cc:opc')->count());

        // Seis opciones es el máximo y entra.
        $seis = array_slice($siete, 0, 6);
        $this->misiones_post_pregunta('opc', $this->misiones_uuid(), ['opciones' => $seis, 'recomendada' => null])->assertStatus(201);
    }

    /**
     * El mismo uid con la misma revisión es una duplicada: 200 y no se crea otra fila.
     *
     * @return void
     */
    public function test_duplicada_200(): void
    {
        $uid = $this->misiones_con_pregunta('dup');

        $repetida = $this->misiones_post_pregunta('dup', $uid);
        $repetida->assertStatus(200);
        $this->assertTrue($repetida->json('duplicada'));
        $this->assertSame($uid, $repetida->json('question.question_uid'));
        $this->assertSame(1, MissionQuestion::where('mission_key', 'cc:dup')->count());
        $this->assertSame('abierta', $this->pregunta($uid)->estado);
    }

    /**
     * Las preguntas sensibles se guardan con su tipo (la Gamer lo decide con su detector).
     *
     * @return void
     */
    public function test_pregunta_sensible_se_guarda_como_sensible(): void
    {
        $uid = $this->misiones_con_pregunta('sensi', 'sensible');

        $this->assertSame('sensible', $this->pregunta($uid)->tipo);
        $this->assertSame('abierta', $this->pregunta($uid)->estado);
    }
}
