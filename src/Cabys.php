<?php

declare(strict_types=1);

namespace Stea\FacturaElectronica;

/**
 * Classifies a CABYS code as goods (mercancía) or services, which decides
 * which ResumenFactura totals a line contributes to.
 *
 * CABYS takes its top level from the UN Central Product Classification, so the
 * leading digit is the section:
 *
 *   0  Productos de la agricultura, silvicultura y pesca        — mercancía
 *   1  Minerales; electricidad, gas y agua                      — mercancía
 *   2  Productos alimenticios, bebidas, tabaco, textiles, cuero — mercancía
 *   3  Otros bienes transportables                              — mercancía
 *   4  Productos metálicos, maquinaria y equipo                 — mercancía
 *   5  Construcciones y servicios de construcción               — see below
 *   6  Servicios de comercio, alojamiento, transporte           — servicio
 *   7  Servicios financieros, inmobiliarios, de arrendamiento   — servicio
 *   8  Servicios prestados a las empresas y de producción       — servicio
 *   9  Servicios para la comunidad, sociales y personales       — servicio
 *
 * Section 5 covers both finished constructions and the services that produce
 * them, so it cannot be decided from the code alone. It is treated as a
 * service: that matches the behaviour every comprobante had before this class
 * existed, so adopting it never silently re-categorises an existing caller.
 *
 * An empty or non-numeric code falls back to services for the same reason —
 * the fallback must never invent a goods classification.
 */
final class Cabys
{
    /** Highest CPC section that denotes goods rather than services. */
    private const ULTIMA_SECCION_MERCANCIA = 4;

    public static function esMercancia(string $codigo): bool
    {
        $seccion = substr(trim($codigo), 0, 1);

        if ($seccion === '' || ! ctype_digit($seccion)) {
            return false;
        }

        return (int) $seccion <= self::ULTIMA_SECCION_MERCANCIA;
    }
}
