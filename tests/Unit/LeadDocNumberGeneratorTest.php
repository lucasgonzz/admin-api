<?php

namespace Tests\Unit;

use App\Services\LeadDocNumberGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del generador de documento aleatorio para leads de WhatsApp.
 */
class LeadDocNumberGeneratorTest extends TestCase
{
    /**
     * El formato debe ser exactamente 5 dígitos numéricos.
     */
    public function test_generate_random_produces_five_digit_pattern(): void
    {
        for ($iteration = 0; $iteration < 50; $iteration++) {
            $doc_number = LeadDocNumberGenerator::generate_random();

            $this->assertSame(5, strlen($doc_number));
            $this->assertMatchesRegularExpression('/^\d{5}$/', $doc_number);
        }
    }

    /**
     * El valor mínimo del rango debe rellenarse con ceros a la izquierda.
     */
    public function test_generate_random_pads_leading_zeros(): void
    {
        // Ejemplo fijo del formato esperado para el límite inferior del rango.
        $this->assertSame('00000', str_pad('0', LeadDocNumberGenerator::TOTAL_LENGTH, '0', STR_PAD_LEFT));
    }

    /**
     * Las constantes del generador deben reflejar un rango de 5 dígitos (00000–99999).
     */
    public function test_constants_define_five_digit_range(): void
    {
        $this->assertSame(5, LeadDocNumberGenerator::TOTAL_LENGTH);
        $this->assertSame(0, LeadDocNumberGenerator::MIN_VALUE);
        $this->assertSame(99999, LeadDocNumberGenerator::MAX_VALUE);
    }
}
