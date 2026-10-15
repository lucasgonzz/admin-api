<?php

namespace Tests\Unit;

use App\Helpers\WhatsappNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * `WhatsappNormalizer::equals_estricto()`: el comparador que decide quién es «dueño» en el número del
 * asistente (misión asistente-segundo-numero, 15/10/2026).
 *
 * Tiene que cumplir dos cosas a la vez: reconocer al dueño argentino aunque la ficha guarde su teléfono
 * en cualquiera de los formatos habituales, y NO reconocer a nadie por parecerse. Un falso positivo
 * le abre a una persona el asistente de IA del negocio de otra.
 */
class ComparadorEstrictoDeTelefonosTest extends TestCase
{
    /**
     * Los formatos en que una ficha guarda el mismo teléfono argentino, contra lo que manda WhatsApp.
     *
     * @return void
     */
    public function test_reconoce_al_dueno_argentino_en_cualquier_formato_de_la_ficha(): void
    {
        $remitente = '5493411234567';

        foreach (['+5493411234567', '5493411234567', '+54 9 341 123-4567', '3411234567', '03411234567', '+54 341 123-4567', '93411234567'] as $ficha) {
            $this->assertTrue(
                WhatsappNormalizer::equals_estricto($ficha, $remitente),
                'La ficha "' . $ficha . '" es el mismo número que ' . $remitente
            );
        }
    }

    /**
     * 🔴 Rosario y Córdoba (mismos últimos siete dígitos) son dos personas.
     *
     * @return void
     */
    public function test_no_confunde_dos_caracteristicas_distintas(): void
    {
        $this->assertFalse(WhatsappNormalizer::equals_estricto('+5493411234567', '5493511234567'));
        $this->assertFalse(WhatsappNormalizer::equals_estricto('3411234567', '5493511234567'));
        $this->assertFalse(WhatsappNormalizer::equals_estricto('+5493411234567', '5493411234568'));
    }

    /**
     * 🔴 Un remitente de otro país no se convierte en argentino: `normalize()` le pegaría `+549` y lo
     * haría coincidir con una ficha de diez dígitos.
     *
     * @return void
     */
    public function test_un_remitente_extranjero_no_coincide_con_una_ficha_argentina(): void
    {
        $this->assertFalse(WhatsappNormalizer::equals_estricto('5551234567', '+15551234567'));
        $this->assertFalse(WhatsappNormalizer::equals_estricto('15551234567', '+5493411234567'));
    }

    /**
     * Un extranjero con la ficha cargada con el mismo número internacional sí coincide, dígito a dígito.
     *
     * @return void
     */
    public function test_un_extranjero_coincide_solo_con_su_numero_completo(): void
    {
        $this->assertTrue(WhatsappNormalizer::equals_estricto('+1 555 123 4567', '+15551234567'));
        $this->assertFalse(WhatsappNormalizer::equals_estricto('+1 555 123 4568', '+15551234567'));
    }

    /**
     * Dos ausencias no son una coincidencia.
     *
     * @return void
     */
    public function test_sin_digitos_nunca_coincide(): void
    {
        $this->assertFalse(WhatsappNormalizer::equals_estricto('', '5493411234567'));
        $this->assertFalse(WhatsappNormalizer::equals_estricto('5493411234567', ''));
        $this->assertFalse(WhatsappNormalizer::equals_estricto('', ''));
        $this->assertFalse(WhatsappNormalizer::equals_estricto('sin teléfono', 'tampoco'));
    }
}
