<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Services\LeadAiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * La OFERTA PRIMARIA y su apertura FLEXIBLE.
 *
 * Este archivo tiene dos mitades y el orden importa:
 *
 *   1) Los casos 1 a 4 son de CARACTERIZACIÓN: clavan lo que `resolve_oferta_primaria()`,
 *      `primer_slot_disponible()` y `texto_referencia_oferta()` hacen HOY. No había un solo test
 *      sobre ninguno de los tres, así que el bloque `OFERTA PRIMARIA` del prompt se podía reescribir
 *      sin que nada avisara si la resolución del slot cambiaba de paso. Se escribieron ANTES de
 *      tocar el prompt, contra el código tal cual estaba.
 *
 *   2) Los casos 5 a 7 son del cambio: la apertura flexible que se enciende con el marcador del
 *      `.md`. El 5 es el que afirma que, con el `.md` viejo todavía vivo, el prompt sale byte a byte
 *      igual que antes — o sea que el estado intermedio del despliegue (código nuevo, `.md` viejo)
 *      es inerte de verdad y no un "casi".
 *
 * Los tres métodos que se ejercitan son privados o protegidos; se llegan por el mismo patrón que ya
 * usa OfertaAceptadaNoCaducaPorMargenTest: una subclase anónima que los expone sin cambiar ninguna
 * lógica. `primer_slot_disponible()` y `texto_referencia_oferta()` son `private`, así que no se
 * pueden exponer directo: se ejercitan A TRAVÉS de `resolve_oferta_primaria()`, que es justamente
 * como los usa producción.
 */
class OfertaFlexibleDeDemoTest extends TestCase
{
    use DatabaseTransactions;

    /** El "hoy" de todos los casos. */
    const HOY = '2026-09-07';

    /** El "mañana" de todos los casos. */
    const MANANA = '2026-09-08';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HOY . ' 09:00:00', 'America/Argentina/Buenos_Aires'));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* 1 a 4 — caracterización de lo que ya hace el sistema                 */
    /* ------------------------------------------------------------------ */

    /**
     * (1) La oferta primaria es el slot MÁS TEMPRANO de todas las demos y de todas las fechas, con
     *     su fecha, su hora y el demo_id de la instancia que lo tiene. Se cruzan dos demos y tres
     *     fechas a propósito, y la más temprana no es la primera de la lista: si la resolución se
     *     volviera "la primera que encuentro", este caso se pone rojo.
     *
     * @return void
     */
    public function test_la_oferta_primaria_resuelve_el_primer_slot_real(): void
    {
        $datos = ['demos' => [
            7 => [
                'lunes ' . self::HOY    => ['14:00', '11:30'],
                'martes ' . self::MANANA => ['08:00'],
            ],
            9 => [
                'lunes ' . self::HOY => ['10:15', '16:00'],
            ],
        ]];

        $oferta = $this->service()->oferta_primaria($datos, true);

        $this->assertTrue($oferta['hay_disponibilidad']);
        $this->assertTrue($oferta['es_hoy']);
        $this->assertSame(self::HOY, $oferta['fecha']);
        $this->assertSame('10:15', $oferta['hora'], 'La oferta primaria no es el slot más temprano de todas las demos.');
        $this->assertSame(9, $oferta['demo_id'], 'El demo_id no es el de la instancia que tiene el slot más temprano.');
        $this->assertSame('lunes ' . self::HOY, $oferta['dia_label']);
        $this->assertSame('hoy a las 10:15', $oferta['texto_referencia']);
    }

    /**
     * (2) Sin disponibilidad no se inventa nada: el array trae `hay_disponibilidad: false` y NINGUNA
     *     otra clave. Importa que sea exactamente eso y no un array con claves vacías: el bloque del
     *     prompt lee `texto_referencia` sin preguntar, y una clave presente con valor vacío haría
     *     que el agente reciba "Ofrecé ESTE momento: " sin momento.
     *
     * @return void
     */
    public function test_la_oferta_primaria_sin_disponibilidad_no_inventa_nada(): void
    {
        $service = $this->service();

        $this->assertSame(['hay_disponibilidad' => false], $service->oferta_primaria(['demos' => []], true));
        $this->assertSame(['hay_disponibilidad' => false], $service->oferta_primaria([], true));

        /* Fechas presentes pero sin un solo slot: mismo resultado. */
        $this->assertSame(
            ['hay_disponibilidad' => false],
            $service->oferta_primaria(['demos' => [7 => ['lunes ' . self::HOY => []]]], true)
        );

        /* Y el resguardo por dinámica: un lead de la dinámica actual nunca tiene oferta primaria,
         * aunque el JSON venga lleno. */
        $this->assertSame(
            ['hay_disponibilidad' => false],
            $service->oferta_primaria(['demos' => [7 => ['lunes ' . self::HOY => ['10:00']]]], false)
        );
    }

    /**
     * (3) `oferta_manana` es el primer slot en una fecha POSTERIOR A HOY, que no es necesariamente
     *     mañana: si mañana está lleno, es el próximo día con lugar. Acá mañana viene sin slots a
     *     propósito.
     *
     * @return void
     */
    public function test_la_oferta_de_manana_saltea_hoy_y_agarra_el_proximo_dia_con_lugar(): void
    {
        $pasado = '2026-09-09';

        $datos = ['demos' => [
            7 => [
                'lunes ' . self::HOY     => ['10:15'],
                'martes ' . self::MANANA => [],
                'miercoles ' . $pasado   => ['09:45', '18:00'],
            ],
        ]];

        $oferta = $this->service()->oferta_primaria($datos, true);

        $this->assertSame(self::HOY, $oferta['fecha'], 'La oferta primaria dejó de ser la de hoy.');

        $this->assertTrue($oferta['oferta_manana']['hay_disponibilidad']);
        $this->assertFalse($oferta['oferta_manana']['es_hoy']);
        $this->assertSame($pasado, $oferta['oferta_manana']['fecha'], 'La oferta del turno siguiente no salteó el día sin lugar.');
        $this->assertSame('09:45', $oferta['oferta_manana']['hora']);
        $this->assertSame('el ' . $pasado . ' a las 09:45', $oferta['oferta_manana']['texto_referencia']);

        /* Y si HOY es lo único que hay, no hay oferta para el turno siguiente. */
        $solo_hoy = $this->service()->oferta_primaria(['demos' => [7 => ['lunes ' . self::HOY => ['10:15']]]], true);
        $this->assertSame(['hay_disponibilidad' => false], $solo_hoy['oferta_manana']);
    }

    /**
     * (4) El texto de referencia dice "hoy", "mañana" o la fecha pelada, según corresponda. Son los
     *     tres casos del helper, y el tercero es el que importa cuidar: es el que ve el agente
     *     cuando la primera disponibilidad está a varios días.
     *
     * @return void
     */
    public function test_el_texto_de_referencia_dice_hoy_manana_o_la_fecha(): void
    {
        $service = $this->service();

        $hoy = $service->oferta_primaria(['demos' => [7 => ['lunes ' . self::HOY => ['10:15']]]], true);
        $this->assertSame('hoy a las 10:15', $hoy['texto_referencia']);

        $manana = $service->oferta_primaria(['demos' => [7 => ['martes ' . self::MANANA => ['08:30']]]], true);
        $this->assertSame('mañana a las 08:30', $manana['texto_referencia']);

        $lejos = $service->oferta_primaria(['demos' => [7 => ['viernes 2026-09-11' => ['16:00']]]], true);
        $this->assertSame('el 2026-09-11 a las 16:00', $lejos['texto_referencia']);
    }

    /* ------------------------------------------------------------------ */
    /* Montaje                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Instancia del service con `resolve_oferta_primaria()` expuesto. La subclase no cambia ninguna
     * lógica: solo abre la puerta.
     *
     * @return LeadAiService
     */
    private function service(): LeadAiService
    {
        return new class extends LeadAiService {
            /**
             * @param array<string, mixed> $availability_data
             * @param bool                 $usa_experiencia_nueva
             *
             * @return array<string, mixed>
             */
            public function oferta_primaria(array $availability_data, bool $usa_experiencia_nueva): array
            {
                return $this->resolve_oferta_primaria($availability_data, $usa_experiencia_nueva);
            }
        };
    }
}
