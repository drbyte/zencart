<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * The supported way for a shipping module to consume a packing plan.
 *
 * Core ships no dimensional carrier module, so the first consumers of a plan are
 * carrier plugins. This class exists so each one does not reinvent the handover: unit
 * conversion, the quotable-plan check, per-parcel insured value, and the choice between
 * rating every parcel or rating one and multiplying.
 *
 * The contract a module must honour, and the reason it lives here rather than in prose:
 *
 * - Read the plan, never write $shipping_num_boxes. shipping::quote() saves and restores
 *   $shipping_weight around each module call but not the box count, so writing it leaks
 *   this module's value into every module quoted afterwards. Use packageCount() to pick
 *   between the plan's count and the legacy one locally.
 * - Fall back silently. When plan() returns null the module must behave exactly as it
 *   did before packing existed, because that covers packing switched off, no boxes
 *   defined, a cart with an unmeasured product, and an installation with no packing
 *   classes at all.
 */
final class QuoteAdapter
{
    /**
     * Global the quote-path observer publishes a plan into.
     */
    public const PLAN_GLOBAL = 'shipping_packages';

    /**
     * The plan for this quote, or null when there is nothing usable to quote from.
     *
     * A plan is withheld unless it is complete: a legacy fallback has no parcels, and a
     * plan with unpackable items describes less than the cart, so quoting from either
     * would understate the shipment.
     */
    public static function plan(): ?PackingPlan
    {
        $plan = $GLOBALS[self::PLAN_GLOBAL] ?? null;

        return ($plan instanceof PackingPlan && $plan->isQuotable()) ? $plan : null;
    }

    /**
     * Parcel count to bill for: the plan's when one is in use, else the caller's legacy
     * figure. Always use the return value locally rather than assigning the global.
     */
    public static function packageCount(?PackingPlan $plan, int $legacyNumBoxes): int
    {
        return $plan === null ? $legacyNumBoxes : $plan->quotablePackageCount();
    }

    /**
     * Every parcel, converted to the units a carrier API wants.
     *
     * For modules that can rate each parcel and sum the results, which is the accurate
     * path. $cartProducts is optional and, when given, is used to insure each parcel for
     * what is actually in it rather than for an even share of the order.
     *
     * Each parcel also reports its volume and carrier girth, because not every module
     * rates a parcel: Australia Post-style modules classify one against limits such as
     * a maximum length, a maximum cubic volume, or a letter size band, and recomputing
     * those from length/width/height in every module invites each to get it subtly
     * wrong.
     *
     * @param array<int, array<string, mixed>> $cartProducts shopping_cart::get_products()
     * @return array<int, array{sequence: int, count: int, length: float, width: float, height: float, volume: float, girth: float, weight: float, billable_weight: ?float, value: float, box_code: string, dim_units: string, weight_units: string}>
     */
    public static function parcels(
        PackingPlan $plan,
        string $dimUnits = Units::INCHES,
        string $weightUnits = Units::POUNDS,
        array $cartProducts = []
    ): array {
        $linePrices = [];
        foreach ($cartProducts as $product) {
            $linePrices[(string)($product['id'] ?? '')] = (float)($product['final_price'] ?? 0);
        }

        $total = $plan->packageCount();
        $parcels = [];
        $sequence = 0;

        foreach ($plan->packages as $package) {
            $sequence++;
            $exterior = $package->exterior;

            $value = 0.0;
            foreach ($package->quantitiesByLine() as $lineKey => $quantity) {
                $value += ($linePrices[(string)$lineKey] ?? 0.0) * (float)$quantity;
            }

            // Converted first, then measured, so volume and girth are expressed in the
            // units the caller asked for rather than the plan's.
            $converted = $exterior->withUnits($dimUnits);

            $parcels[] = [
                'sequence' => $sequence,
                'count' => $total,
                'length' => $converted->length,
                'width' => $converted->width,
                'height' => $converted->height,
                'volume' => $converted->volume(),
                'girth' => $converted->girth(),
                'dim_units' => $dimUnits,
                'weight_units' => $weightUnits,
                'weight' => Units::convert($package->weight->value, $package->weight->units, $weightUnits),
                'billable_weight' => $package->billableWeight === null
                    ? null
                    : Units::convert($package->billableWeight->value, $package->billableWeight->units, $weightUnits),
                'value' => $value,
                'box_code' => $package->boxType->code ?? '',
            ];
        }

        return $parcels;
    }

    /**
     * One notional parcel whose every measurement bounds every parcel in the plan.
     *
     * For the many modules that make a single rate request and multiply by the box
     * count. Rating N copies of this bounding parcel over-quotes a plan whose parcels differ
     * and can never under-quote one, which is the direction the packing contract
     * requires - but it is an approximation, and a module able to rate each parcel
     * should use parcels() instead.
     *
     * Returns null when no parcel has a usable size, so the caller falls back rather
     * than asking a carrier to rate a zero-sized package.
     *
     * @return array{length: float, width: float, height: float, weight: float, count: int, value: float}|null
     */
    public static function boundingParcel(
        PackingPlan $plan,
        string $dimUnits = Units::INCHES,
        string $weightUnits = Units::POUNDS,
        array $cartProducts = []
    ): ?array {
        $parcels = self::parcels($plan, $dimUnits, $weightUnits, $cartProducts);
        if ($parcels === []) {
            return null;
        }

        $bounding = [
            'length' => 0.0,
            'width' => 0.0,
            'height' => 0.0,
            'weight' => 0.0,
            'count' => $plan->quotablePackageCount(),
            'value' => 0.0,
        ];

        foreach ($parcels as $parcel) {
            $bounding['length'] = max($bounding['length'], $parcel['length']);
            $bounding['width'] = max($bounding['width'], $parcel['width']);
            $bounding['height'] = max($bounding['height'], $parcel['height']);
            $bounding['weight'] = max($bounding['weight'], $parcel['weight']);
            $bounding['value'] = max($bounding['value'], $parcel['value']);
        }

        if ($bounding['length'] <= 0.0 || $bounding['width'] <= 0.0
            || $bounding['height'] <= 0.0 || $bounding['weight'] <= 0.0) {
            return null;
        }

        return $bounding;
    }
}
