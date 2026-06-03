<?php

namespace Tests\Unit;

use App\Services\LeadWhatsAppPasteCleaner;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del parser de pegados de WhatsApp para conversaciones de leads.
 */
class LeadWhatsAppPasteCleanerTest extends TestCase
{
    /**
     * Varias líneas meta en orden conservan lead/setter según teléfono y "Tú".
     */
    public function test_parse_multiple_messages_lead_and_setter(): void
    {
        $raw = <<<'TXT'
[10:00, 13/5/2026] +54 9 11 3066-5894: Hola, quiero info
[10:01, 13/5/2026] Tú: Buenas, te cuento
[10:02, 13/5/2026] +54 9 11 3066-5894: Dale, gracias
TXT;

        $parsed = LeadWhatsAppPasteCleaner::parse_export_paste($raw, '+54 9 11 3066-5894', 'Juan');

        $this->assertCount(3, $parsed);
        $this->assertSame('lead', $parsed[0]['sender']);
        $this->assertSame('Hola, quiero info', $parsed[0]['content']);
        $this->assertSame('setter', $parsed[1]['sender']);
        $this->assertSame('Buenas, te cuento', $parsed[1]['content']);
        $this->assertSame('lead', $parsed[2]['sender']);
    }

    /**
     * Sin formato WhatsApp se mantiene un único mensaje lead (texto libre).
     */
    public function test_parse_plain_text_defaults_to_lead(): void
    {
        $parsed = LeadWhatsAppPasteCleaner::parse_export_paste('Solo un mensaje libre', null, null);

        $this->assertCount(1, $parsed);
        $this->assertSame('lead', $parsed[0]['sender']);
        $this->assertSame('Solo un mensaje libre', $parsed[0]['content']);
    }
}
