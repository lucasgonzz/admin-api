<?php

namespace Tests\Feature\ImplementacionMail;

use App\Services\ImplementacionMailService;

/**
 * La línea de progreso de los mails: el estado REAL de las siete etapas al momento de armar el mail.
 *
 * 🔴 Eran ocho hasta el 15/10/2026 (decisión de Lucas: la 8, «Seguimos con soporte», salió del flujo). Cada
 * número de este archivo —«de 7», los porcentajes, las cuentas de círculos— cambió por esa razón y no por
 * ajustar un test: 3 de 7 es 43 % y no 38 %, y con todo hecho la etapa es la 7. Los tests del final cubren la
 * compatibilidad con una implementación anterior que todavía trae su fila 8.
 *
 * Los marcadores del HTML que se cuentan:
 *   - `&#10003;</td>`      — el círculo de una etapa completada (el tilde de un subpaso hecho va
 *                            seguido de `&nbsp;`, así que no se confunde);
 *   - `>En curso</span>`   — la etiqueta de la etapa en curso;
 *   - `&ndash;</td>`       — el círculo de una etapa salteada.
 */
class ProgresoDelMailTest extends BaseDelMailDeImplementacion
{
    /**
     * Devuelve el HTML de la previa de un hito para una implementación en el estado pedido.
     *
     * @param string               $hito
     * @param array<int, string>   $etapas Estados de las etapas (ver `estados()`); null = sin filas.
     * @param array<string, mixed> $datos  Datos del hito; por defecto los de ejemplo.
     *
     * @return string
     */
    private function html(string $hito, ?array $etapas, ?array $datos = null): string
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $etapas);

        return ImplementacionMailService::previa($impl, $hito, $datos === null ? $this->datos_de_ejemplo($hito) : $datos, null)['html'];
    }

    /**
     * Tres etapas completadas y la cuarta en curso: "Etapa 4 de 7", la barra al 43 % (3 de 7
     * redondeado), tres círculos con tilde y una sola etiqueta "En curso".
     *
     * @return void
     */
    public function test_la_etapa_en_curso_y_la_barra_salen_del_estado_real()
    {
        $html = $this->html('acceso', $this->estados(3, 4));

        $this->assertStringContainsString('Etapa 4 de 7', $html);
        $this->assertStringContainsString('width="43%"', $html);
        $this->assertSame(3, substr_count($html, '&#10003;</td>'));
        $this->assertSame(1, substr_count($html, '>En curso</span>'));
        $this->assertSame(0, substr_count($html, '&ndash;</td>'));

        // Y el nombre de cada etapa es el que ve el comerciante, no el del panel.
        foreach (['Conocemos tu negocio', 'Instalamos tu sistema', 'Recibimos tu información', 'Cargamos tu catálogo',
                  'Te entregamos el sistema', 'Terminamos tu catálogo', 'Facturación electrónica'] as $nombre) {
            $this->assertStringContainsString($nombre, $html);
        }

        // La etapa 8 («Seguimos con soporte») salió del flujo el 15/10/2026: la línea de progreso no la nombra.
        $this->assertStringNotContainsString('Seguimos con soporte', $html);
    }

    /**
     * El mismo mail, con la implementación en otro punto, dice otra cosa: cada mail lee el estado
     * en el momento y no tiene la etapa escrita en el texto.
     *
     * @return void
     */
    public function test_el_mismo_hito_cambia_con_el_estado_de_la_implementacion()
    {
        $en_la_dos = $this->html('instalado', $this->estados(1, 2), []);
        $en_la_cinco = $this->html('instalado', $this->estados(4, 5), []);

        $this->assertStringContainsString('Etapa 2 de 7', $en_la_dos);
        $this->assertStringContainsString('width="14%"', $en_la_dos);

        $this->assertStringContainsString('Etapa 5 de 7', $en_la_cinco);
        $this->assertStringContainsString('width="57%"', $en_la_cinco);
        $this->assertSame(4, substr_count($en_la_cinco, '&#10003;</td>'));
    }

    /**
     * Cada hito define los subpasos de la etapa 4 como en el prototipo: acceso (artículos hecho, fotos en
     * curso, categorías pendiente), imagenes y categorias (artículos y fotos hechos, categorías en curso).
     *
     * @return void
     */
    public function test_cada_hito_define_sus_subpasos_de_la_etapa_4()
    {
        $acceso = $this->html('acceso', $this->estados(3, 4));
        $this->assertStringContainsString('&#10003;&nbsp;Tus artículos', $acceso);
        $this->assertStringContainsString('&#9679;&nbsp;Las fotos', $acceso);
        $this->assertStringContainsString('&#9675;&nbsp;Las categorías', $acceso);

        foreach (['imagenes', 'categorias'] as $hito) {
            $html = $this->html($hito, $this->estados(3, 4));
            $this->assertStringContainsString('&#10003;&nbsp;Tus artículos', $html, $hito);
            $this->assertStringContainsString('&#10003;&nbsp;Las fotos', $html, $hito);
            $this->assertStringContainsString('&#9679;&nbsp;Las categorías', $html, $hito);
            $this->assertStringNotContainsString('&#9675;&nbsp;', $html, $hito . ': ningún subpaso pendiente.');
        }
    }

    /**
     * Los subpasos solo salen si la etapa 4 está EN CURSO de verdad. Con la 4 ya completada (el mail
     * "listo", o un "acceso" mandado tarde) o sin llegar (bienvenida e instalado) no hay subpasos.
     *
     * @return void
     */
    public function test_los_subpasos_solo_salen_si_la_etapa_4_esta_en_curso()
    {
        // El listo: la 4 ya está completa y la que sigue en curso.
        $listo = $this->html('listo', $this->estados(5, 6));
        $this->assertStringNotContainsString('&#9679;&nbsp;', $listo);
        $this->assertStringNotContainsString('&#9675;&nbsp;', $listo);
        $this->assertStringNotContainsString('Tus artículos', $listo);

        // Un acceso mandado con la 4 ya cerrada: no inventa subpasos.
        $acceso_tarde = $this->html('acceso', $this->estados(4, 5));
        $this->assertStringContainsString('Etapa 5 de 7', $acceso_tarde);
        $this->assertStringNotContainsString('&#9679;&nbsp;Las fotos', $acceso_tarde);

        // Bienvenida e instalado no los definen, aunque la 4 estuviera en curso. (Se miran los
        // marcadores de los subpasos y no el texto: "Tus artículos" también es lo que pide el
        // mail de instalado.)
        foreach (['bienvenida', 'instalado'] as $hito) {
            $html = $this->html($hito, $this->estados(3, 4), []);

            $this->assertStringContainsString('Etapa 4 de 7', $html, $hito);
            $this->assertSame(1, substr_count($html, '>En curso</span>'), $hito);
            $this->assertStringNotContainsString('&#10003;&nbsp;', $html, $hito);
            $this->assertStringNotContainsString('&#9679;&nbsp;', $html, $hito);
            $this->assertStringNotContainsString('&#9675;&nbsp;', $html, $hito);
        }
    }

    /**
     * Una etapa salteada (p. ej. ARCA que no aplica) cuenta como hecha para la barra, se ve en gris
     * con guion y la leyenda "no aplica".
     *
     * @return void
     */
    public function test_una_etapa_salteada_cuenta_como_hecha_y_dice_que_no_aplica()
    {
        // 1 a 5 completadas, la 6 en curso y la 7 salteada: 6 de 7 hechas.
        $html = $this->html('listo', $this->estados(5, 6, [7]));

        $this->assertStringContainsString('Etapa 6 de 7', $html);
        $this->assertStringContainsString('width="86%"', $html);
        $this->assertStringContainsString('Facturación electrónica &middot; no aplica', $html);
        $this->assertSame(1, substr_count($html, '&ndash;</td>'));
        $this->assertSame(5, substr_count($html, '&#10003;</td>'));
        $this->assertSame(1, substr_count($html, '>En curso</span>'));
    }

    /**
     * Si ninguna etapa está en curso, la actual es la primera que todavía no se cerró.
     *
     * @return void
     */
    public function test_sin_etapa_en_curso_la_actual_es_la_primera_sin_cerrar()
    {
        $html = $this->html('instalado', $this->estados(2), []);

        $this->assertStringContainsString('Etapa 3 de 7', $html);
        $this->assertSame(0, substr_count($html, '>En curso</span>'));
        $this->assertSame(2, substr_count($html, '&#10003;</td>'));
    }

    /**
     * Con las siete cerradas, la etapa es la 7 y la barra se llena sola, sin parte gris.
     *
     * @return void
     */
    public function test_con_todo_hecho_la_barra_se_llena_y_la_etapa_es_la_ultima()
    {
        $html = $this->html('listo', $this->estados(7));

        $this->assertStringContainsString('Etapa 7 de 7', $html);
        $this->assertStringContainsString('width="100%"', $html);
        $this->assertSame(7, substr_count($html, '&#10003;</td>'));
        $this->assertStringNotContainsString('border-radius:0 6px 6px 0', $html, 'La parte gris de la barra no existe al 100 %.');
    }

    /**
     * Una implementación sin ninguna fila de etapas es "Etapa 1 de 7" con el hilo mínimo de barra.
     *
     * @return void
     */
    public function test_sin_filas_de_etapas_es_la_etapa_1_con_el_hilo_minimo()
    {
        $html = $this->html('bienvenida', null, []);

        $this->assertStringContainsString('Etapa 1 de 7', $html);
        $this->assertStringContainsString('width="4%"', $html);
        $this->assertSame(0, substr_count($html, '&#10003;</td>'));
        $this->assertSame(0, substr_count($html, '>En curso</span>'));
    }

    /**
     * El porcentaje se redondea al entero más cercano: 2 de 7 es 28,57 y sale 29 (sube); 5 de 7 es 71,43 y sale 71
     * (baja); 6 de 7 es 85,71 y sale 86.
     *
     * @return void
     */
    public function test_el_porcentaje_de_la_barra_se_redondea()
    {
        $this->assertStringContainsString('width="29%"', $this->html('instalado', $this->estados(2, 3), []));
        $this->assertStringContainsString('width="71%"', $this->html('listo', $this->estados(5, 6)));
        $this->assertStringContainsString('width="86%"', $this->html('listo', $this->estados(6, 7)));
    }

    /**
     * El progreso se lee de la base en el momento de armar el mail, no de la relación que la
     * instancia haya traído antes: si la etapa se cerró entre una previa y la otra, la segunda lo ve.
     *
     * @return void
     */
    public function test_el_progreso_se_lee_de_la_base_en_el_momento()
    {
        $client = $this->crear_cliente(['email' => 'dueno@ejemplo.test']);
        $impl   = $this->crear_implementacion($client, $this->estados(1, 2));

        // La instancia trae sus etapas cargadas, como las trae el panel.
        $this->assertCount(7, $impl->stages);

        $antes = ImplementacionMailService::previa($impl, 'instalado', [], null)['html'];
        $this->assertStringContainsString('Etapa 2 de 7', $antes);

        // Se cierra la 2 y arranca la 3, por fuera de la instancia que ya tenemos.
        $this->poner_las_etapas($impl, $this->estados(2, 3));

        $despues = ImplementacionMailService::previa($impl, 'instalado', [], null)['html'];
        $this->assertStringContainsString('Etapa 3 de 7', $despues);
        $this->assertSame(2, substr_count($despues, '&#10003;</td>'));
    }

    /**
     * COMPATIBILIDAD 1. Una implementación ANTERIOR al 15/10/2026 trae una fila 8 pendiente: el mail dice lo mismo que
     * si no la tuviera —«de 7», la misma barra, los mismos siete círculos—. La fila 8 no cuenta ni se nombra.
     *
     * @return void
     */
    public function test_una_implementacion_vieja_con_la_fila_8_pendiente_muestra_las_mismas_siete()
    {
        $nueva = $this->html('listo', $this->estados(5, 6));

        $vieja_estados    = $this->estados(5, 6);
        $vieja_estados[8] = 'pending';
        $vieja            = $this->html('listo', $vieja_estados);

        $this->assertStringContainsString('Etapa 6 de 7', $vieja);
        $this->assertStringContainsString('width="71%"', $vieja);
        $this->assertSame(substr_count($nueva, '&#10003;</td>'), substr_count($vieja, '&#10003;</td>'));
        $this->assertSame(substr_count($nueva, '>En curso</span>'), substr_count($vieja, '>En curso</span>'));
        $this->assertStringNotContainsString('Seguimos con soporte', $vieja);
    }

    /**
     * COMPATIBILIDAD 2. Una implementación vieja que ya llegó a la etapa 8 (las siete cerradas y la 8 en curso) se
     * ve como las siete hechas: «Etapa 7 de 7» y la barra llena. La 8 no suma una octava etapa ni deja la barra en
     * 100 % de 8.
     *
     * @return void
     */
    public function test_una_implementacion_vieja_en_la_etapa_8_se_ve_con_las_siete_hechas()
    {
        $estados    = $this->estados(7);
        $estados[8] = 'in_progress';

        $html = $this->html('listo', $estados);

        $this->assertStringContainsString('Etapa 7 de 7', $html);
        $this->assertStringContainsString('width="100%"', $html);
        $this->assertSame(7, substr_count($html, '&#10003;</td>'));
        $this->assertSame(0, substr_count($html, '>En curso</span>'), 'La etapa 8 en curso no se dibuja.');
        $this->assertStringNotContainsString('Seguimos con soporte', $html);
    }
}
