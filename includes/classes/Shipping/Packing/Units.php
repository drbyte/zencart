<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Unit names and conversion factors for the packing subsystem.
 *
 * Units exist only at the boundary. Everything inside the packer has already been
 * normalized to one dimension unit and one weight unit (see PackingContext), so no
 * algorithm code performs a conversion or compares two differently-scaled numbers.
 */
final class Units
{
    public const INCHES = 'inches';
    public const CENTIMETERS = 'centimeters';
    public const POUNDS = 'pounds';
    public const KILOGRAMS = 'kgs';

    /**
     * Factors to a neutral base (inches for length, pounds for mass). Keyed by the
     * spellings Zen Cart actually stores in SHIPPING_DIMENSION_UNITS and
     * SHIPPING_WEIGHT_UNITS, plus the common aliases found in older product rows.
     */
    private const FACTORS = [
        self::INCHES => 1.0,
        'inch' => 1.0,
        'in' => 1.0,
        self::CENTIMETERS => 0.393700787401575,
        'centimeter' => 0.393700787401575,
        'cm' => 0.393700787401575,
        'mm' => 0.0393700787401575,
        'millimeters' => 0.0393700787401575,
        self::POUNDS => 1.0,
        'pound' => 1.0,
        'lbs' => 1.0,
        'lb' => 1.0,
        self::KILOGRAMS => 2.20462262185,
        'kg' => 2.20462262185,
        'kilograms' => 2.20462262185,
        'kilogram' => 2.20462262185,
        'grams' => 0.00220462262185,
        'g' => 0.00220462262185,
    ];

    /**
     * Normalize a stored unit string for lookup. Unknown units are returned as-is so
     * that convert() can fail loudly rather than silently scaling by 1.0.
     */
    public static function canonical(?string $units): string
    {
        return strtolower(trim((string)$units));
    }

    public static function isKnown(?string $units): bool
    {
        return isset(self::FACTORS[self::canonical($units)]);
    }

    /**
     * Convert a magnitude between two units of the same kind.
     *
     * Identical units short-circuit without arithmetic, which is the common case and
     * the reason normalization targets the store's own configured units rather than a
     * fixed canonical: a store already working in centimeters never takes a
     * floating-point round trip.
     */
    public static function convert(float $value, ?string $from, ?string $to): float
    {
        $from = self::canonical($from);
        $to = self::canonical($to);

        if ($from === $to || $from === '' || $to === '') {
            return $value;
        }
        if (!isset(self::FACTORS[$from], self::FACTORS[$to])) {
            throw new \InvalidArgumentException('Unknown packing unit conversion: ' . $from . ' to ' . $to);
        }

        return $value * self::FACTORS[$from] / self::FACTORS[$to];
    }
}
