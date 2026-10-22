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
     * Everything the packer needs about these cart lines that the cart array does not
     * carry, read in two queries and merged in.
     *
     * The cart array core builds (shopping_cart::get_products()) supplies dimensions,
     * weight and ships_in_own_box, but neither the no-stack and orientation-lock flags
     * nor whether a selected attribute makes the line a download. Those rules used to
     * live in the quote observer, which meant the order observer's reconstruction path
     * packed without them and could persist a parcel breakdown that disagreed with the
     * quote the customer accepted. One method, called by both, is what stops that.
     *
     * @param object $db queryFactory
     * @param array<int, array<string, mixed>> $products as returned by get_products()
     * @return array{products: array<int, array<string, mixed>>, downloadProductIds: int[]}
     */
    public static function enrichFromDatabase(object $db, array $products): array
    {
        $downloadProductIds = [];
        $downloadSql = self::downloadLinesSql($products);
        if ($downloadSql !== '') {
            $result = $db->Execute($downloadSql);
            while (!$result->EOF) {
                $downloadProductIds[] = (int)$result->fields['products_id'];
                $result->MoveNext();
            }
        }

        $flags = [];
        $flagsSql = self::packingFlagsSql($products);
        if ($flagsSql !== '') {
            $result = $db->Execute($flagsSql);
            while (!$result->EOF) {
                $flags[(int)$result->fields['products_id']] = [
                    'no_stack' => (int)$result->fields['products_no_stack'],
                    'orientation_lock' => (int)$result->fields['products_orientation_lock'],
                ];
                $result->MoveNext();
            }
        }

        foreach ($products as $index => $product) {
            $productId = (int)($product['id'] ?? 0);
            $products[$index]['no_stack'] = $flags[$productId]['no_stack'] ?? 0;
            $products[$index]['orientation_lock'] = $flags[$productId]['orientation_lock'] ?? 0;
        }

        return ['products' => $products, 'downloadProductIds' => $downloadProductIds];
    }

    /**
     * Query reading the per-product packing flags.
     *
     * These are properties of the product, not of the cart line, so they are not in
     * the cart array at all. Without this lookup the packer's no-stack and
     * orientation-lock handling is unreachable: every item would be stackable and
     * freely rotatable regardless of what the product record says.
     *
     * @param array<int, array<string, mixed>> $products as returned by get_products()
     */
    public static function packingFlagsSql(array $products): string
    {
        $ids = [];
        foreach ($products as $product) {
            $productId = (int)($product['id'] ?? 0);
            if ($productId > 0) {
                $ids[$productId] = $productId;
            }
        }

        if ($ids === []) {
            return '';
        }

        return 'SELECT products_id, products_no_stack, products_orientation_lock
                FROM ' . TABLE_PRODUCTS . '
                WHERE products_id IN (' . implode(',', $ids) . ')';
    }

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
                FROM ' . TABLE_PRODUCTS_ATTRIBUTES . ' pa
                INNER JOIN ' . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . ' pad
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
