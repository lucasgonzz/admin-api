<?php

namespace Tests\Unit;

use App\Services\LeadDocNumberGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del generador de documento memorable para leads de WhatsApp.
 */
class LeadDocNumberGeneratorTest extends TestCase
{
    /**
     * El formato debe ser 12 dígitos: prefijo 20 + id padded + checksum.
     */
    public function test_from_lead_id_generates_twelve_digit_pattern(): void
    {
        $doc_number = LeadDocNumberGenerator::from_lead_id(42);

        $this->assertSame('200000004207', $doc_number);
        $this->assertSame(12, strlen($doc_number));
        $this->assertMatchesRegularExpression('/^\d{12}$/', $doc_number);
    }

    /**
     * Ids grandes se acotan a 8 dígitos sin romper el largo total.
     */
    public function test_from_lead_id_bounds_large_ids(): void
    {
        $doc_number = LeadDocNumberGenerator::from_lead_id(123456789);

        $this->assertSame(12, strlen($doc_number));
        $this->assertStringStartsWith('20', $doc_number);
        $this->assertStringEndsWith('36', $doc_number);
    }

    /**
     * El id 1 produce el mínimo patrón con ceros intermedios.
     */
    public function test_from_lead_id_for_first_lead(): void
    {
        $this->assertSame('200000000120', LeadDocNumberGenerator::from_lead_id(1));
    }
}
