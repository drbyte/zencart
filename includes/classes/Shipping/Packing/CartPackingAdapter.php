<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Turns the array that shopping_cart::get_products() already returns into discrete
 * packable units.
 *
 * Takes the plain array rather than the cart object, so it is unit-testable with no
 * session and no database, and so a caller with products from somewhere else - an
 * admin-created order, a re-pack of a stored order - can use the same code path.
 *
 * It issues no queries. Everything needed is already published per cart line
 * (length, width, height, ships_in_own_box, dim_units, weight_units); adding a lookup
 * here would put an N+1 on the busiest page in the application, since quoting runs on
 * the cart page, the estimator and both checkout steps.
 */
final class CartPackingAdapter
{
    /**
     * Absolute ceiling on expanded units.
     *
     * Above this the cart is not modelled at all and quoting falls back to the legacy
     * weight calculation. The packer has its own, much lower, sampling guard for large
     * carts; this one exists a layer earlier to stop expansion itself exhausting
     * memory. Declining outright beats sampling twice and compounding two
     * approximations, and weight-only quoting on a 20,000-unit cart is a reasonable
     * answer.
     */
    public const HARD_UNIT_CEILING = 20000;

    /**
     * Query finding which cart lines are downloads, and so do not ship.
     *
     * Core treats a line as virtual when a *selected attribute value* carries a
     * download, unless product_is_always_free_shipping is 2 - "special, requires
     * shipping", the download-plus-physical-goods case. See
     * shopping_cart::get_content_type(), which this mirrors.
     *
     * Returned as one statement over every (product, selected value) pair in the cart
     * rather than a query per line, because this runs on the quote path. Returns an
     * empty string when the cart has no attribute selections at all.
     *
     * @param array<int, array<string, mixed>> $products as returned by get_products()
     */
    public static function downloadLinesSql(array $products): string
    {
        $pairs = [];
        foreach ($products as $product) {
            if ((int)($product['product_is_always_free_shipping'] ?? 0) === 2) {
                continue;
            }
            foreach ((array)($product['attributes'] ?? []) as $valueId) {
                $productId = (int)$product['id'];
                $valueId = (int)$valueId;
                if ($productId > 0 && $valueId > 0) {
                    $pairs[] = '(pa.products_id = ' . $productId . ' AND pa.options_values_id = ' . $valueId . ')';
                }
            }
        }

        if ($pairs === []) {
            return '';
        }

        return 'SELECT DISTINCT pa.products_id
                FROM ' . PackingSchema::tableName('products_attributes') . ' pa
                INNER JOIN ' . PackingSchema::tableName('products_attributes_download') . ' pad
                  USING (products_attributes_id)
                WHERE ' . implode(' OR ', $pairs);
    }

    /**
     * @param array<int, array<string, mixed>> $products as returned by shopping_cart::get_products()
     * @param int[] $downloadProductIds product ids whose selected attributes carry a
     *        download, from downloadLinesSql(); such lines do not ship
     */
    public function fromCartProducts(array $products, PackingContext $context, array $downloadProductIds = []): PackingRequest
    {
        $downloads = array_flip(array_map('intval', $downloadProductIds));

        $items = [];
        $excluded = 0;
        $missing = [];
        $declaredUnits = 0;

        foreach ($products as $product) {
            // A download line is virtual in core's eyes, so it must not be treated as
            // an unmeasured physical item - that would drop the whole cart to
            // weight-only quoting over goods that never enter a parcel.
            $isDownload = isset($downloads[(int)($product['id'] ?? 0)])
                && (int)($product['product_is_always_free_shipping'] ?? 0) !== 2;

            if ($isDownload || $this->isNonShipping($product)) {
                $excluded++;
                continue;
            }

            $quantity = $this->discreteQuantity($product['quantity'] ?? 0);
            if ($quantity < 1) {
                $excluded++;
                continue;
            }
            $declaredUnits += $quantity;

            $uprid = (string)($product['id'] ?? '');
            $dimensions = new Dimensions(
                $this->toFloat($product['length'] ?? null),
                $this->toFloat($product['width'] ?? null),
                $this->toFloat($product['height'] ?? null),
                $this->dimUnits($product, $context)
            );

            if (!$dimensions->isUsable()) {
                $missing[$uprid] = $uprid;
            }

            $prototype = new PackableItem(
                $uprid,
                $uprid,
                $dimensions->withUnits($context->dimUnits),
                (new Weight($this->toFloat($product['weight'] ?? null), $this->weightUnits($product, $context)))
                    ->withUnits($context->weightUnits),
                $this->flag($product['ships_in_own_box'] ?? null),
                $this->flag($product['no_stack'] ?? null),
                $this->flag($product['orientation_lock'] ?? null)
            );

            if ($declaredUnits > self::HARD_UNIT_CEILING) {
                return new PackingRequest([], $excluded, array_values($missing), true, $declaredUnits);
            }

            for ($i = 0; $i < $quantity; $i++) {
                $items[] = $prototype;
            }
        }

        return new PackingRequest($items, $excluded, array_values($missing), false, $declaredUnits);
    }

    /**
     * Lines that never become a parcel.
     *
     * product_is_always_free_shipping deserves care: 1 means always-free and does not
     * ship, but 2 means "special, requires shipping" - the download-plus-physical-goods
     * case - and must be packed. Treating 2 as non-shipping would silently drop real
     * cartons from the plan. Product::isAlwaysFreeShipping() makes the same distinction.
     *
     * @param array<string, mixed> $product
     */
    private function isNonShipping(array $product): bool
    {
        if ((int)($product['products_virtual'] ?? 0) === 1) {
            return true;
        }
        if ((int)($product['product_is_always_free_shipping'] ?? 0) === 1) {
            return true;
        }

        // Gift certificates are identified by model prefix throughout the cart code.
        return str_starts_with((string)($product['model'] ?? ''), 'GIFT');
    }

    /**
     * Whole units to pack for a line.
     *
     * Zen Cart permits fractional quantities, but a fraction of a dimensioned product
     * has no meaning as a parcel occupant, so the quantity is rounded up: 2.5 units are
     * packed as 3. That over-reserves space, which is the conservative direction and
     * consistent with the packer's contract.
     */
    private function discreteQuantity(mixed $quantity): int
    {
        $value = (float)$quantity;
        if ($value <= 0.0) {
            return 0;
        }

        return (int)ceil($value);
    }

    /**
     * @param array<string, mixed> $product
     */
    private function dimUnits(array $product, PackingContext $context): string
    {
        $units = (string)($product['dim_units'] ?? '');

        return Units::isKnown($units) ? $units : $context->dimUnits;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function weightUnits(array $product, PackingContext $context): string
    {
        $units = (string)($product['weight_units'] ?? '');

        return Units::isKnown($units) ? $units : $context->weightUnits;
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float)$value;
    }

    /**
     * Cart values arrive as strings, nulls or ints depending on how the column was
     * written, and the own-box column was renamed in v2.0.0, so the flag is read
     * permissively rather than compared against one representation.
     */
    private function flag(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return (int)$value === 1;
    }
}
