<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Turns an order, or a hand-entered list of products, into the cart-shaped array the
 * packer takes - so a store owner can ask "why did this cart quote three parcels?" and
 * get the plan itself as the answer.
 *
 * The queries live here rather than on the admin page for the same reason the box form
 * rules do: a page cannot be unit tested, and "the explainer disagrees with the quote"
 * is the one failure that would make the page worse than having none. The array shape
 * is the one shopping_cart::get_products() produces, because that is what
 * CartPackingAdapter reads; building anything else here would explain a cart the
 * storefront never packs.
 */
final class PlanExplainer
{
    /**
     * Lines of an existing order, as product id and quantity.
     *
     * Dimensions deliberately come from the products table rather than the order, which
     * does not record them: this explains what the cart would do with today's product
     * data, which is the question an owner debugging their quotes is asking.
     */
    public static function orderLinesSql(int $ordersId): string
    {
        return "SELECT products_id, products_quantity
                FROM " . TABLE_ORDERS_PRODUCTS . "
                WHERE orders_id = " . $ordersId . "
                ORDER BY orders_products_id";
    }

    /**
     * The product data the packer needs, for the given ids.
     *
     * The language id is passed in rather than read from a global, as
     * MissingDimensionsReport::sql() does: the admin's language lives in the session,
     * and a class reaching for $GLOBALS would both guess wrong there and stop being
     * testable without a bootstrap.
     *
     * @param int[] $productIds
     */
    public static function productsSql(array $productIds, int $languageId = 1): string
    {
        $ids = [];
        foreach ($productIds as $productId) {
            $productId = (int)$productId;
            if ($productId > 0) {
                $ids[$productId] = $productId;
            }
        }

        if ($ids === []) {
            return '';
        }

        return "SELECT p.products_id, p.products_model, p.products_weight,
                       p.products_length, p.products_width, p.products_height,
                       p.products_virtual, p.product_is_always_free_shipping,
                       p.product_ships_in_own_box, p.products_no_stack,
                       p.products_orientation_lock, pd.products_name
                FROM " . TABLE_PRODUCTS . " p
                LEFT JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd
                  ON pd.products_id = p.products_id AND pd.language_id = " . $languageId . "
                WHERE p.products_id IN (" . implode(',', $ids) . ")";
    }

    /**
     * Build cart-shaped lines from product rows and the quantities asked for.
     *
     * A quantity for a product that no longer exists is reported rather than dropped:
     * silently explaining a smaller cart than the one asked about would be worse than
     * saying the product is gone.
     *
     * @param array<int, array<string, mixed>> $rows from productsSql()
     * @param array<int, float> $quantities product id => quantity
     * @return array{lines: array<int, array<string, mixed>>, missing: int[]}
     */
    public static function cartLines(array $rows, array $quantities): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['products_id']] = $row;
        }

        $lines = [];
        $missing = [];
        foreach ($quantities as $productId => $quantity) {
            $productId = (int)$productId;
            if (!isset($byId[$productId])) {
                $missing[] = $productId;
                continue;
            }

            $row = $byId[$productId];
            $lines[] = [
                'id' => $productId,
                'name' => (string)($row['products_name'] ?? ''),
                'model' => (string)($row['products_model'] ?? ''),
                'quantity' => (float)$quantity,
                'weight' => (float)($row['products_weight'] ?? 0),
                'length' => (float)($row['products_length'] ?? 0),
                'width' => (float)($row['products_width'] ?? 0),
                'height' => (float)($row['products_height'] ?? 0),
                'products_virtual' => (int)($row['products_virtual'] ?? 0),
                'product_is_always_free_shipping' => (int)($row['product_is_always_free_shipping'] ?? 0),
                'ships_in_own_box' => (int)($row['product_ships_in_own_box'] ?? 0),
                // Attribute selections are unknown outside a real cart, so a line here
                // can never be classified as an attribute-carried download. Stated on
                // the page rather than papered over.
                'attributes' => [],
            ];
        }

        return ['lines' => $lines, 'missing' => $missing];
    }

    /**
     * Parse the ad-hoc "product id times quantity" input.
     *
     * Accepts "12 x 3", "12x3", "12,3" and a bare "12" (meaning one), one per line,
     * because an owner copying ids out of a listing should not have to learn a syntax.
     *
     * @return array<int, float> product id => quantity, summed for repeated ids
     */
    public static function parseQuantities(string $input): array
    {
        $quantities = [];
        foreach (preg_split('/[\r\n]+/', $input) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s*(?:[x*,:]\s*(\d+(?:\.\d+)?))?$/i', $line, $matches) !== 1) {
                continue;
            }

            $productId = (int)$matches[1];
            if ($productId <= 0) {
                continue;
            }

            $quantity = isset($matches[2]) ? (float)$matches[2] : 1.0;
            $quantities[$productId] = ($quantities[$productId] ?? 0.0) + $quantity;
        }

        return $quantities;
    }

    /**
     * How full a parcel is, as a percentage of the interior the packer could use.
     *
     * Reported against the usable interior, not the raw one, because that is the space
     * the packer was actually allowed to fill; measuring against the raw interior would
     * make a correctly-packed box look half empty whenever a margin is set.
     */
    public static function fillPercent(Package $package, float $marginPercent): ?float
    {
        if ($package->boxType === null) {
            return null;
        }

        $usable = $package->boxType->usableInterior($marginPercent)->volume();
        if ($usable <= 0.0) {
            return null;
        }

        $contents = 0.0;
        foreach ($package->contents as $packed) {
            $contents += $packed->placedAs->volume();
        }

        return round(($contents / $usable) * 100.0, 1);
    }

    /**
     * Why a plan is not usable as-is, as language-constant names for the page to word.
     *
     * @return string[]
     */
    public static function caveats(PackingPlan $plan, PackingRequest $request): array
    {
        $caveats = [];

        if ($plan->legacyFallback) {
            $caveats[] = 'TEXT_CAVEAT_FALLBACK';
        }
        if ($plan->approximated) {
            $caveats[] = 'TEXT_CAVEAT_APPROXIMATED';
        }
        if ($request->exceedsUnitCeiling) {
            $caveats[] = 'TEXT_CAVEAT_CEILING';
        }
        if ($request->hasMissingDimensions()) {
            $caveats[] = 'TEXT_CAVEAT_MISSING_DIMENSIONS';
        }
        if ($request->excludedLineCount > 0) {
            $caveats[] = 'TEXT_CAVEAT_EXCLUDED';
        }
        if ($plan->unpackable !== []) {
            $caveats[] = 'TEXT_CAVEAT_UNPACKABLE';
        }

        return $caveats;
    }
}
