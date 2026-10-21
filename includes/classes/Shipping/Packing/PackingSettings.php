<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Turns store configuration into a PackingContext.
 *
 * The reading of configuration is isolated here, in one small class, so that
 * everything downstream takes a plain value object. That is what keeps the packer,
 * the adapter and the service testable without a bootstrap, and it means an admin
 * diagnostic page can build a context with deliberately altered settings to show a
 * store owner what a different margin would do.
 */
final class PackingSettings
{
    /**
     * Build a context from an already-gathered settings array.
     *
     * Unknown or missing keys take the documented defaults rather than throwing: a
     * store part-way through an upgrade, or a plugin install that has not yet written
     * its configuration rows, must still quote.
     *
     * @param array<string, mixed> $settings configuration_key => value
     */
    public static function contextFrom(
        array $settings,
        int $destinationCountryId = 0,
        int $destinationZoneId = 0,
        bool $isDomestic = true,
        string $catalogRevision = '',
        ?string $carrierCode = null,
        ?float $dimDivisor = null,
        ?float $carrierMaxGirth = null
    ): PackingContext {
        $policy = strtolower(trim((string)($settings['SHIPPING_PACKING_MISSING_DIMS_POLICY'] ?? '')));
        if (!in_array($policy, [PackingContext::POLICY_LEGACY, PackingContext::POLICY_DEFAULT_BOX], true)) {
            $policy = PackingContext::POLICY_LEGACY;
        }

        $defaultBox = trim((string)($settings['SHIPPING_PACKING_DEFAULT_BOX_CODE'] ?? ''));

        return new PackingContext(
            destinationCountryId: $destinationCountryId,
            destinationZoneId: $destinationZoneId,
            isDomestic: $isDomestic,
            interiorMarginPercent: self::clampedMargin($settings['SHIPPING_PACKING_INTERIOR_MARGIN_PCT'] ?? 5),
            // A carrier module's own limit wins over the store-wide default, because
            // girth ceilings differ per carrier (UPS 165", USPS 130") and a store
            // quoting several cannot express that in one number.
            maxGirth: $carrierMaxGirth ?? max(0.0, (float)($settings['SHIPPING_PACKING_MAX_GIRTH'] ?? 0)),
            dimDivisor: $dimDivisor,
            carrierCode: $carrierCode,
            missingDimensionsPolicy: $policy,
            defaultBoxCode: $defaultBox === '' ? null : $defaultBox,
            maxUnits: self::clampedMaxUnits($settings['SHIPPING_PACKING_MAX_UNITS'] ?? 500),
            dimUnits: self::knownUnits($settings['SHIPPING_DIMENSION_UNITS'] ?? null, Units::INCHES),
            weightUnits: self::knownUnits($settings['SHIPPING_WEIGHT_UNITS'] ?? null, Units::POUNDS),
            catalogRevision: $catalogRevision
        );
    }

    public static function isEnabled(array $settings): bool
    {
        return strtolower(trim((string)($settings['SHIPPING_PACKING_ENABLED'] ?? 'false'))) === 'true';
    }

    public static function shouldPersist(array $settings): bool
    {
        return strtolower(trim((string)($settings['SHIPPING_PACKING_PERSIST'] ?? 'true'))) === 'true';
    }

    /**
     * Margin is a percentage of each interior axis, so values at or above 100 would
     * leave no usable interior and make every item unpackable. Clamped well short of
     * that; a store wanting to effectively disable packing should switch it off.
     */
    private static function clampedMargin(mixed $value): float
    {
        return min(90.0, max(0.0, (float)$value));
    }

    /**
     * Zero or a negative would disable the sampling guard entirely and let a large
     * cart run unbounded on the quote path, so it floors at 1.
     */
    private static function clampedMaxUnits(mixed $value): int
    {
        return max(1, (int)$value);
    }

    private static function knownUnits(mixed $value, string $fallback): string
    {
        $units = (string)$value;

        return Units::isKnown($units) ? $units : $fallback;
    }
}
