<?php

namespace Tests\Feature;

use App\Services\SupportAiSuggestionService;
use Tests\TestCase;

/**
 * La voz de Lucas (atencion_cliente/voz_de_lucas.md) tiene que estar en el system prompt del
 * agente de tickets de soporte, sin tocar el resto del prompt.
 *
 * Criterios del plan: CA1 (el bloque `VOZ DE LUCAS:` con sus reglas), CA2 (los demás bloques
 * siguen y en el mismo orden) y CA3 (no hay contradicción con las reglas previas).
 */
class VozDeLucasEnPromptDeSoporteTest extends TestCase
{
    /**
     * Arma el system prompt real con las tres dependencias externas sustituidas por texto fijo,
     * para que el test no salga a la red ni a la base y sólo mire el texto del servicio.
     *
     * @return string System prompt completo.
     */
    private function armar_system_prompt(): string
    {
        // Subclase que reemplaza lo que pega a GitHub o a la base y expone el método protegido.
        $servicio = new class extends SupportAiSuggestionService {
            protected function fetch_manual_file_list(): string
            {
                return "- README.md\n- tickets.md";
            }

            protected function fetch_escalation_rules(): string
            {
                return "PROTOCOLO DE ESCALADO Y CIERRE:\nregla de prueba";
            }

            protected function build_identity_block(): string
            {
                return "Sos Martin, de ComercioCity.\n";
            }

            public function prompt_publico(): string
            {
                return $this->build_system_prompt();
            }
        };

        return $servicio->prompt_publico();
    }

    /**
     * Devuelve sólo el texto del bloque `VOZ DE LUCAS:` (hasta el próximo bloque en mayúsculas).
     *
     * @param string $prompt System prompt completo.
     *
     * @return string Texto del bloque, o '' si no existe.
     */
    private function bloque_voz(string $prompt): string
    {
        $inicio = strpos($prompt, 'VOZ DE LUCAS:');
        if ($inicio === false) {
            return '';
        }

        $fin = strpos($prompt, 'CUÁNDO HACER UNA PREGUNTA:', $inicio);

        return $fin === false ? substr($prompt, $inicio) : substr($prompt, $inicio, $fin - $inicio);
    }

    /**
     * CA1: el bloque existe, va justo después de ESTILO y trae todas las reglas de la voz.
     *
     * @return void
     */
    public function test_ca1_el_prompt_trae_el_bloque_de_voz_con_sus_reglas(): void
    {
        $prompt = $this->armar_system_prompt();
        $bloque = $this->bloque_voz($prompt);

        $this->assertNotSame('', $bloque, 'Falta el bloque "VOZ DE LUCAS:" en el system prompt.');

        // Va después de ESTILO y antes de CUÁNDO HACER UNA PREGUNTA.
        $this->assertGreaterThan(strpos($prompt, 'ESTILO:'), strpos($prompt, 'VOZ DE LUCAS:'));

        $esperados = [
            'mensajes cortos'        => 'cortos',
            'una idea por mensaje'   => 'una idea',
            'abrir con el nombre'    => 'nombre del cliente',
            'voseo'                  => 'voseo',
            'muletilla Okey'         => 'Okey',
            'muletilla Dale'         => 'Dale',
            'muletilla Listo'        => 'Listo',
            'muletilla De nada!'     => 'De nada!',
            'acción concreta 2'      => 'Probá ahora',
            'una sola pregunta'      => 'una sola pregunta',
            'sin signo de apertura'  => '¿',
            'sin emojis'             => 'emojis',
            'prohibido Estimado'     => 'Estimado',
            'prohibido Le informamos' => 'Le informamos',
            'prohibido No dudes'     => 'No dudes en contactarnos',
        ];

        foreach ($esperados as $regla => $texto) {
            $this->assertStringContainsString($texto, $bloque, "El bloque de voz no cubre: {$regla}.");
        }
    }

    /**
     * CA2: el resto del prompt sigue presente y en el mismo orden.
     *
     * @return void
     */
    public function test_ca2_el_resto_del_prompt_queda_igual_y_en_orden(): void
    {
        $prompt = $this->armar_system_prompt();

        $marcadores = [
            'ESTILO:',
            'VOZ DE LUCAS:',
            'CUÁNDO HACER UNA PREGUNTA:',
            'VARIOS MENSAJES:',
            'IMÁGENES:',
            'Archivos disponibles en el repositorio:',
            'PROTOCOLO DE ESCALADO Y CIERRE:',
        ];

        $posicion_anterior = -1;
        foreach ($marcadores as $marcador) {
            $posicion = strpos($prompt, $marcador);
            $this->assertNotFalse($posicion, "Falta el marcador {$marcador}.");
            $this->assertGreaterThan($posicion_anterior, $posicion, "{$marcador} está fuera de orden.");
            $posicion_anterior = $posicion;
        }
    }

    /**
     * CA3: sin contradicciones. Los cierres genéricos siguen prohibidos y la regla de partir en
     * mensajes con la línea de tres guiones se mantiene.
     *
     * @return void
     */
    public function test_ca3_no_contradice_las_reglas_previas(): void
    {
        $prompt = $this->armar_system_prompt();
        $bloque = $this->bloque_voz($prompt);

        $this->assertNotSame('', $bloque, 'Falta el bloque "VOZ DE LUCAS:" en el system prompt.');

        // Sigue prohibido el cierre genérico y sigue la regla de los tres guiones.
        $this->assertStringContainsString('cierres genéricos', $prompt);
        $this->assertStringContainsString('¿hay algo más en lo que te pueda ayudar?', $prompt);
        $this->assertStringContainsString('una línea que tenga solamente tres guiones', $prompt);

        // El bloque nuevo usa ese mismo separador para partir mensajes.
        $this->assertStringContainsString('---', $bloque);

        // El bloque no habilita el cierre genérico.
        $this->assertStringNotContainsString('hay algo más en lo que te pueda ayudar', $bloque);
    }

    /**
     * Ronda 2: Martín no vuelve después ni arregla en el medio, así que el bloque no puede
     * prometer eso ni afirmar estados que no conoce, y no puede derivar con frases propias.
     *
     * @return void
     */
    public function test_ronda2_no_promete_volver_ni_arreglar_ni_deriva_con_frase_propia(): void
    {
        $bloque = $this->bloque_voz($this->armar_system_prompt());

        $this->assertNotSame('', $bloque, 'Falta el bloque "VOZ DE LUCAS:" en el system prompt.');

        $prohibidas = [
            'Dame 5',
            'Te aviso ni bien termine',
            'Ya quedó',
            'Ya podés volver a ingresar',
            'Ahora reviso',
            'Ahora lo reviso',
            'Eso lo ve Lucas',
            'seguimos configurando',
            'Me lo podés solucionar?',
        ];

        foreach ($prohibidas as $texto) {
            $this->assertStringNotContainsString($texto, $bloque, "El bloque no debe contener: {$texto}");
        }

        // Las promesas que sí cumple en el mismo mensaje siguen.
        $this->assertStringContainsString('Probá ahora y contame', $bloque);
        $this->assertStringContainsString('Mandame foto de la pantalla completa', $bloque);
    }

    /**
     * Ronda 2: la voz está subordinada al protocolo de escalado y deriva con su texto fijo.
     *
     * @return void
     */
    public function test_ronda2_el_protocolo_de_escalado_manda_y_se_usa_su_texto_fijo(): void
    {
        $bloque = $this->bloque_voz($this->armar_system_prompt());

        $this->assertStringContainsString('manda el protocolo de escalado', $bloque);
        $this->assertStringContainsString('texto fijo exacto del protocolo de escalado', $bloque);
        $this->assertStringContainsString('conserva su emoji', $bloque);
        $this->assertStringContainsString('no puede entrar', $bloque);
    }

    /**
     * Ronda 2: el separador --- es solo de WhatsApp, Robotito (un solo mensaje) manda y el
     * nombre se usa sólo en el primer mensaje.
     *
     * @return void
     */
    public function test_ronda2_separador_solo_whatsapp_robotito_manda_y_nombre_solo_al_abrir(): void
    {
        $bloque = $this->bloque_voz($this->armar_system_prompt());

        $this->assertStringContainsString('aplica SOLO a WhatsApp', $bloque);
        $this->assertStringContainsString('Robotito', $bloque);
        $this->assertStringContainsString('un solo mensaje', $bloque);
        $this->assertStringContainsString('esa indicación manda', $bloque);
        $this->assertStringContainsString('primer mensaje del ticket', $bloque);
        $this->assertStringContainsString('no repitas el nombre', $bloque);

        // Todo --- de los ejemplos tiene que estar en el ejemplo marcado como WhatsApp.
        $ejemplos = substr($bloque, (int) strpos($bloque, 'Ejemplos (cliente, respuesta):'));
        $antes_de_whatsapp = substr($ejemplos, 0, (int) strpos($ejemplos, 'Ejemplo de WhatsApp'));
        $this->assertStringNotContainsString('---', $antes_de_whatsapp);
        $this->assertStringContainsString('---', $ejemplos);
    }
}
