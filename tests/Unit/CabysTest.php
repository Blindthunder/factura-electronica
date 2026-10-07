<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Cabys;

final class CabysTest extends TestCase
{
    /**
     * CABYS follows the UN CPC for its top level: sections 0-4 are goods,
     * 6-9 are services.
     */
    public function test_sections_zero_to_four_are_goods(): void
    {
        $this->assertTrue(Cabys::esMercancia('0111100000000'), 'agricultura');
        $this->assertTrue(Cabys::esMercancia('1700100000000'), 'minerales/electricidad');
        $this->assertTrue(Cabys::esMercancia('2321100000000'), 'alimentos/textiles');
        $this->assertTrue(Cabys::esMercancia('3461000000000'), 'otros bienes transportables');
        $this->assertTrue(Cabys::esMercancia('4521100000000'), 'maquinaria y equipo');
    }

    public function test_sections_six_to_nine_are_services(): void
    {
        $this->assertFalse(Cabys::esMercancia('6241000000000'), 'comercio/alojamiento');
        $this->assertFalse(Cabys::esMercancia('7111100000000'), 'financieros/inmobiliarios');
        $this->assertFalse(Cabys::esMercancia('8415000000000'), 'servicios a empresas');
        $this->assertFalse(Cabys::esMercancia('9231000000000'), 'comunitarios/personales');
    }

    /**
     * Section 5 is "Construcciones y servicios de construcción" — it genuinely
     * holds both. Classified as a service, which is the pre-existing behaviour,
     * so nothing silently changes category for an existing caller.
     */
    public function test_section_five_construction_is_treated_as_a_service(): void
    {
        $this->assertFalse(Cabys::esMercancia('5312000000000'));
    }

    /** Anything unclassifiable falls back to services — never guess goods. */
    public function test_unclassifiable_codes_fall_back_to_services(): void
    {
        $this->assertFalse(Cabys::esMercancia(''));
        $this->assertFalse(Cabys::esMercancia('   '));
        $this->assertFalse(Cabys::esMercancia('X123'));
    }
}
