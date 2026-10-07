<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica\Tests\Unit\Xml;

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use Stea\FacturaElectronica\Clave\ClaveGenerator;
use Stea\FacturaElectronica\Credentials\SigningCertificate;
use Stea\FacturaElectronica\Dtos\EmisorDto;
use Stea\FacturaElectronica\Dtos\FacturaCompraDto;
use Stea\FacturaElectronica\Dtos\IdentificacionDto;
use Stea\FacturaElectronica\Dtos\ImpuestoDto;
use Stea\FacturaElectronica\Dtos\InformacionReferenciaDto;
use Stea\FacturaElectronica\Dtos\LineaDetalleDto;
use Stea\FacturaElectronica\Dtos\ReceptorDto;
use Stea\FacturaElectronica\Dtos\UbicacionDto;
use Stea\FacturaElectronica\Enums\Situacion;
use Stea\FacturaElectronica\Signing\XadesEpesSigner;
use Stea\FacturaElectronica\Xml\FacturaCompraXmlBuilder;
use Stea\FacturaElectronica\Xml\XsdValidator;

/**
 * Schema-valid only — uses fully synthetic data (no real cédulas, names, or addresses).
 */
final class FacturaCompraXmlBuilderTest extends TestCase
{
    private function dto(?float $baseImponible = 9.99): FacturaCompraDto
    {
        return new FacturaCompraDto(
            consecutivo: '00100001080000000001',
            fechaEmision: new DateTimeImmutable('2026-01-01T10:00:00-06:00'),
            proveedorSistemas: '2100042005',
            codigoActividadEmisor: '0000.2',
            codigoActividadReceptor: '6201.0',
            // Emisor = foreign supplier (idType 05 + OtrasSenasExtranjero)
            emisor: new EmisorDto(
                nombre: 'ACME SERVICES LLC',
                identificacion: new IdentificacionDto('05', '000000000'),
                correoElectronico: 'supplier@example.com',
                otrasSenasExtranjero: '100 Business Ave, Anytown, CA 90000',
            ),
            // Receptor = local buyer with Ubicacion
            receptor: new ReceptorDto(
                nombre: 'MI EMPRESA S.A.',
                identificacion: new IdentificacionDto('02', '3101000000'),
                ubicacion: new UbicacionDto(
                    provincia: '1',
                    canton: '01',
                    distrito: '01',
                    otrasSenas: '123 Calle Principal, San Jose',
                ),
                correoElectronico: 'emisor@example.com',
            ),
            lineas: [
                new LineaDetalleDto(
                    numeroLinea: 1,
                    codigoCabys: '8315900000200',
                    cantidad: 1.0,
                    unidadMedida: 'Unid',
                    detalle: 'Servicio de almacenamiento en la nube',
                    precioUnitario: 9.99,
                    montoTotal: 9.99,
                    subTotal: 9.99,
                    montoTotalLinea: 9.99,
                    impuestos: [
                        new ImpuestoDto(codigo: '01', codigoTarifa: '10', tarifa: 0.0, monto: 0.0),
                    ],
                    baseImponible: $baseImponible,
                ),
            ],
            informacionReferencia: new InformacionReferenciaDto(
                tipoDocIR: '16',
                numero: 'INV-TEST-001',
                fechaEmisionIR: new DateTimeImmutable('2026-01-01T00:00:00.000'),
                codigo: '11',
                razon: 'PRUEBA',
            ),
            condicionVenta: '01',
            medioPago: '02',
            moneda: 'USD',
            tipoCambio: 500.0,
        );
    }

    private function cert(): SigningCertificate
    {
        return SigningCertificate::fromPath(__DIR__.'/../../fixtures/cert/test.p12', '1234');
    }

    public function test_signed_fec_passes_xsd(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-01-01T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001080000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new FacturaCompraXmlBuilder;
        $doc = $builder->build($this->dto(), $clave);
        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $passes = $validator->validate($wire, $builder->xsdPath());

        $this->assertTrue($passes, 'Signed FEC must pass XSD: '.implode('; ', $validator->errors()));
    }

    public function test_fec_contains_expected_root_and_clave(): void
    {
        $fechaEmision = new DateTimeImmutable('2026-01-01T10:00:00-06:00');
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: $fechaEmision,
            consecutivo: '00100001080000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new FacturaCompraXmlBuilder;
        $xml = $builder->build($this->dto(), $clave)->saveXML();

        $this->assertStringContainsString('<FacturaElectronicaCompra', $xml);
        $this->assertStringContainsString($clave, $xml);
        $this->assertSame(50, strlen($clave));
    }

    /**
     * Regression: the FEC XSD requires BaseImponible before Impuesto (no minOccurs="0").
     * When the caller omits it (null), the builder must fall back to SubTotal rather than
     * drop the element — otherwise Hacienda rejects with cvc-complex-type.2.4.a.
     */
    public function test_fec_without_base_imponible_still_passes_xsd(): void
    {
        $clave = (new ClaveGenerator)->generate(
            cedula: '3101000000',
            fecha: new DateTimeImmutable('2026-01-01T10:00:00-06:00'),
            consecutivo: '00100001080000000001',
            situacion: Situacion::Normal,
            codigoSeguridad: '00000001',
        );

        $builder = new FacturaCompraXmlBuilder;
        $doc = $builder->build($this->dto(baseImponible: null), $clave);

        $this->assertStringContainsString('<BaseImponible>', (string) $doc->saveXML());

        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());
        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        $this->assertTrue(
            $validator->validate($wire, $builder->xsdPath()),
            'FEC without explicit BaseImponible must still pass XSD: '.implode('; ', $validator->errors()),
        );
    }

    // ── ResumenFactura: goods vs services categorisation ────────────────────

    /** @param LineaDetalleDto[] $lineas */
    private function dtoConLineas(array $lineas): FacturaCompraDto
    {
        $base = $this->dto();

        return new FacturaCompraDto(
            consecutivo: $base->consecutivo,
            fechaEmision: $base->fechaEmision,
            proveedorSistemas: $base->proveedorSistemas,
            codigoActividadEmisor: $base->codigoActividadEmisor,
            codigoActividadReceptor: $base->codigoActividadReceptor,
            emisor: $base->emisor,
            receptor: $base->receptor,
            lineas: $lineas,
            informacionReferencia: $base->informacionReferencia,
            condicionVenta: $base->condicionVenta,
            medioPago: $base->medioPago,
            moneda: $base->moneda,
            tipoCambio: $base->tipoCambio,
        );
    }

    private function linea(int $n, string $cabys, float $monto, float $tarifa): LineaDetalleDto
    {
        $impuesto = round($monto * ($tarifa / 100), 5);

        return new LineaDetalleDto(
            numeroLinea: $n,
            codigoCabys: $cabys,
            cantidad: 1.0,
            unidadMedida: 'Unid',
            detalle: 'Linea '.$n,
            precioUnitario: $monto,
            montoTotal: $monto,
            subTotal: $monto,
            montoTotalLinea: $monto + $impuesto,
            impuestos: [new ImpuestoDto(codigo: '01', codigoTarifa: $tarifa > 0.0 ? '08' : '10', tarifa: $tarifa, monto: $impuesto)],
            baseImponible: $monto,
        );
    }

    /** @return array<string, string> every ResumenFactura child keyed by tag */
    private function resumen(FacturaCompraDto $dto): array
    {
        $doc = (new FacturaCompraXmlBuilder)->build($dto, str_repeat('5', 50));
        $resumen = $doc->getElementsByTagName('ResumenFactura')->item(0);
        self::assertNotNull($resumen);

        $out = [];
        foreach ($resumen->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->childNodes->length === 1 && $child->firstChild instanceof \DOMText) {
                $out[$child->nodeName] = trim($child->textContent);
            }
        }

        return $out;
    }

    public function test_a_taxed_goods_line_lands_in_mercancias_gravadas(): void
    {
        $r = $this->resumen($this->dtoConLineas([$this->linea(1, '2321100000000', 100.0, 13.0)]));

        self::assertSame('100.00000', $r['TotalMercanciasGravadas']);
        self::assertSame('0.00000', $r['TotalServGravados']);
        self::assertSame('100.00000', $r['TotalGravado']);
        self::assertSame('0.00000', $r['TotalExento']);
    }

    public function test_an_exempt_goods_line_lands_in_mercancias_exentas(): void
    {
        $r = $this->resumen($this->dtoConLineas([$this->linea(1, '2321100000000', 100.0, 0.0)]));

        self::assertSame('100.00000', $r['TotalMercanciasExentas']);
        self::assertSame('0.00000', $r['TotalServExentos']);
        self::assertSame('100.00000', $r['TotalExento']);
    }

    /**
     * Regression: a taxed services line used to emit TotalGravado with no
     * category breakdown at all — neither servicios nor mercancías.
     */
    public function test_a_taxed_services_line_lands_in_serv_gravados(): void
    {
        $r = $this->resumen($this->dtoConLineas([$this->linea(1, '8415000000000', 10.06, 13.0)]));

        self::assertSame('10.06000', $r['TotalServGravados']);
        self::assertSame('0.00000', $r['TotalMercanciasGravadas']);
        self::assertSame('10.06000', $r['TotalGravado']);
    }

    public function test_a_mixed_invoice_splits_across_all_four_buckets(): void
    {
        $r = $this->resumen($this->dtoConLineas([
            $this->linea(1, '2321100000000', 100.0, 13.0), // mercancía gravada
            $this->linea(2, '3461000000000', 50.0, 0.0),   // mercancía exenta
            $this->linea(3, '8415000000000', 20.0, 13.0),  // servicio gravado
            $this->linea(4, '9231000000000', 5.0, 0.0),    // servicio exento
        ]));

        self::assertSame('100.00000', $r['TotalMercanciasGravadas']);
        self::assertSame('50.00000', $r['TotalMercanciasExentas']);
        self::assertSame('20.00000', $r['TotalServGravados']);
        self::assertSame('5.00000', $r['TotalServExentos']);

        // The roll-ups span both categories.
        self::assertSame('120.00000', $r['TotalGravado']);
        self::assertSame('55.00000', $r['TotalExento']);
        self::assertSame('175.00000', $r['TotalVenta']);
    }

    public function test_the_mixed_invoice_still_passes_the_xsd(): void
    {
        $dto = $this->dtoConLineas([
            $this->linea(1, '2321100000000', 100.0, 13.0),
            $this->linea(2, '8415000000000', 20.0, 13.0),
        ]);

        $builder = new FacturaCompraXmlBuilder;
        $doc = $builder->build($dto, str_repeat('5', 50));
        $signed = (new XadesEpesSigner)->sign($doc, $this->cert());

        $wire = new DOMDocument;
        $wire->loadXML((string) $signed->saveXML());

        $validator = new XsdValidator;
        self::assertTrue(
            $validator->validate($wire, $builder->xsdPath()),
            'mixed goods/services FEC must pass XSD: '.implode('; ', $validator->errors()),
        );
    }
}
