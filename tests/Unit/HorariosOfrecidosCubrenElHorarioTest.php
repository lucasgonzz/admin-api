<?php

namespace Tests\Unit;

use App\Models\LeadMessage;
use PHPUnit\Framework\TestCase;

/**
 * El criterio de rango de `LeadMessage::horarios_ofrecidos_cubren()`, puro y sin base de datos.
 *
 * Este método es el que da PERMISO para ignorar el margen mínimo de anticipación cuando el lead
 * acepta un horario que ya le habíamos ofrecido. No decide disponibilidad —eso lo sigue decidiendo
 * la grilla fresca de LeadAiService— así que lo único que hay que fijar acá es el criterio de
 * matching: qué declaración de `horarios_ofrecidos` cubre qué (fecha, hora), y sobre todo qué NO.
 *
 * 🔴 Lo que más importa que quede clavado: `hasta` es INCLUSIVO. La oferta primaria se declara con
 * desde == hasta (es el caso de la lead Brisa, 25/8/2026: se le ofreció "17:05" y punto), así que
 * con un `hasta` exclusivo el caso principal no matchearía NUNCA y el fix entero sería decorativo.
 */
class HorariosOfrecidosCubrenElHorarioTest extends TestCase
{
    /** Fecha de trabajo de todos los casos, en Y-m-d. */
    const FECHA = '2026-08-25';

    /**
     * 1. El caso real: la oferta primaria se declara como un punto (desde == hasta) y tiene que
     *    cubrir exactamente esa hora.
     *
     * @return void
     */
    public function test_la_oferta_primaria_declarada_como_punto_cubre_su_horario(): void
    {
        $ofrecidos = [
            ['fecha' => self::FECHA, 'desde' => '17:05', 'hasta' => '17:05'],
        ];

        $this->assertTrue(
            LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '17:05'),
            'La oferta primaria (desde == hasta) no se reconoce a sí misma: el fix no rescataría el caso principal.'
        );
    }

    /**
     * 2. Un rango cubre su borde izquierdo, su interior y —lo que importa— su borde derecho.
     *
     * @return void
     */
    public function test_el_rango_cubre_los_dos_bordes_y_el_interior(): void
    {
        $ofrecidos = [
            ['fecha' => self::FECHA, 'desde' => '13:00', 'hasta' => '16:30'],
        ];

        $this->assertTrue(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '13:00'), 'El borde izquierdo del rango quedó afuera.');
        $this->assertTrue(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '14:30'), 'El interior del rango quedó afuera.');
        $this->assertTrue(
            LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '16:30'),
            'El `hasta` se trató como exclusivo: el texto le nombró las 16:30 al lead y se las estaríamos negando.'
        );
    }

    /**
     * 3. Y no cubre nada que caiga afuera, ni por un minuto.
     *
     * @return void
     */
    public function test_el_rango_no_se_pasa_ni_por_un_minuto(): void
    {
        $ofrecidos = [
            ['fecha' => self::FECHA, 'desde' => '13:00', 'hasta' => '16:30'],
        ];

        $this->assertFalse(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '16:31'), 'Se coló una hora posterior al `hasta`.');
        $this->assertFalse(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '12:59'), 'Se coló una hora anterior al `desde`.');
    }

    /**
     * 4. La fecha manda: la misma hora en otro día no está ofrecida.
     *
     * @return void
     */
    public function test_la_hora_correcta_en_otra_fecha_no_cubre(): void
    {
        $ofrecidos = [
            ['fecha' => self::FECHA, 'desde' => '17:05', 'hasta' => '17:05'],
        ];

        $this->assertFalse(
            LeadMessage::horarios_ofrecidos_cubren($ofrecidos, '2026-08-26', '17:05'),
            'El permiso se filtró a otro día: bastaría haber ofrecido 17:05 alguna vez para saltarse el margen siempre.'
        );
    }

    /**
     * 5. Una declaración mal formada no ensancha el permiso: `hasta` vacío, ilegible o anterior al
     *    `desde` degrada el ítem a un punto.
     *
     * @return void
     */
    public function test_un_hasta_mal_formado_degrada_el_item_a_un_punto(): void
    {
        $casos = [
            'hasta vacío'          => '',
            'hasta ilegible'       => 'a la tarde',
            'hasta menor al desde' => '12:00',
        ];

        foreach ($casos as $etiqueta => $hasta) {
            $ofrecidos = [
                ['fecha' => self::FECHA, 'desde' => '15:00', 'hasta' => $hasta],
            ];

            $this->assertTrue(
                LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '15:00'),
                'Con ' . $etiqueta . ' se perdió hasta el propio `desde`.'
            );
            $this->assertFalse(
                LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '15:05'),
                'Con ' . $etiqueta . ' el ítem se comportó como un rango abierto: una declaración rota estaría dando permiso de más.'
            );
        }
    }

    /**
     * 5bis. Y si el `hasta` falta directamente como clave, el ítem sigue siendo un punto válido.
     *
     * @return void
     */
    public function test_un_item_sin_clave_hasta_sigue_siendo_un_punto(): void
    {
        $ofrecidos = [
            ['fecha' => self::FECHA, 'desde' => '15:00'],
        ];

        $this->assertTrue(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '15:00'));
        $this->assertFalse(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '15:05'));
    }

    /**
     * 6. Los formatos sucios que escribe el agente se normalizan igual que en el resto del archivo
     *    (una hora sin cero a la izquierda, con espacios, o con segundos pegados).
     *
     * @return void
     */
    public function test_las_horas_sucias_se_normalizan_antes_de_comparar(): void
    {
        $ofrecidos_sucios = [
            ['fecha' => self::FECHA, 'desde' => '9:05', 'hasta' => '9:05'],
        ];

        $this->assertTrue(LeadMessage::horarios_ofrecidos_cubren($ofrecidos_sucios, self::FECHA, '09:05'), 'Un `desde` sin cero a la izquierda no matcheó.');
        $this->assertTrue(LeadMessage::horarios_ofrecidos_cubren($ofrecidos_sucios, self::FECHA, ' 09:05 '), 'Una hora con espacios alrededor no matcheó.');
        $this->assertTrue(LeadMessage::horarios_ofrecidos_cubren($ofrecidos_sucios, self::FECHA, '09:05:00'), 'Una hora con segundos pegados no matcheó.');

        /* Y al revés: el ítem prolijo contra la hora sucia. */
        $ofrecidos_prolijos = [
            ['fecha' => self::FECHA, 'desde' => '09:05', 'hasta' => '09:05'],
        ];
        $this->assertTrue(LeadMessage::horarios_ofrecidos_cubren($ofrecidos_prolijos, self::FECHA, '9:05'));
    }

    /**
     * 6bis. Una hora ilegible no cubre nada (y no explota).
     *
     * @return void
     */
    public function test_una_hora_ilegible_no_cubre_nada(): void
    {
        $ofrecidos = [
            ['fecha' => self::FECHA, 'desde' => '09:00', 'hasta' => '18:00'],
        ];

        $this->assertFalse(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, 'a la tarde'));
        $this->assertFalse(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, ''));
        $this->assertFalse(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, '', '10:00'));
    }

    /**
     * 7. Entradas degeneradas: array vacío, ítems que no son array, ítems sin fecha o sin desde.
     *    Ninguna cubre nada y ninguna tiene que emitir warnings (el test corre con
     *    convertWarningsToExceptions, así que un notice de índice inexistente rompería acá).
     *
     * @return void
     */
    public function test_las_entradas_degeneradas_devuelven_false_sin_romper(): void
    {
        $this->assertFalse(LeadMessage::horarios_ofrecidos_cubren([], self::FECHA, '17:05'), 'Un array vacío dio permiso.');

        $degenerados = [
            'no-array',
            123,
            null,
            [],
            ['desde' => '17:05'],
            ['fecha' => self::FECHA],
            ['fecha' => '', 'desde' => '17:05'],
            ['fecha' => self::FECHA, 'desde' => ''],
        ];

        $this->assertFalse(
            LeadMessage::horarios_ofrecidos_cubren($degenerados, self::FECHA, '17:05'),
            'Un ítem mal formado terminó dando permiso para saltarse el margen.'
        );
    }

    /**
     * 7bis. Con varios ítems, alcanza con que UNO cubra — y los ítems rotos del medio no tapan al
     *       bueno.
     *
     * @return void
     */
    public function test_alcanza_con_que_un_item_de_la_lista_cubra(): void
    {
        $ofrecidos = [
            'texto suelto',
            ['fecha' => '2026-08-24', 'desde' => '17:05', 'hasta' => '17:05'],
            ['fecha' => self::FECHA, 'desde' => '10:00', 'hasta' => '11:00'],
            ['fecha' => self::FECHA, 'desde' => '17:05', 'hasta' => '17:05'],
        ];

        $this->assertTrue(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '17:05'));
        $this->assertTrue(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '10:30'));
        $this->assertFalse(LeadMessage::horarios_ofrecidos_cubren($ofrecidos, self::FECHA, '12:00'));
    }
}
