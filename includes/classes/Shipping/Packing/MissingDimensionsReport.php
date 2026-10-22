<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Finds the products that would stop a cart from being packed.
 *
 * This is the migration path for the missing-dimensions policy. Under the default
 * policy a single unmeasured product reverts an entire cart to weight-only quoting, so
 * without a way to see which products those are, enabling packing is experienced as
 * "my quotes went weird" rather than "I have 400 products to measure".
 *
 * Its exclusion rule has to agree with CartPackingAdapter exactly. A product the
 * adapter ignores must not appear here as work to do, and a product the adapter would
 * trip over must never be missing from the list. ReportParityTest pins that agreement.
 */
final class MissingDimensionsReport
{
    /**
     * Products that need measuring: physical, shippable, and missing at least one axis.
     *
     * The WHERE clause mirrors CartPackingAdapter::isNonShipping() - virtual products,
     * always-free-shipping products and GIFT models never become parcels, so they are
     * not work. Note that product_is_always_free_shipping = 2 means "special, requires
     * shipping" and therefore *is* included; only 1 is excluded.
     *
     * Products whose downloads hang off attributes are handled one step further down,
     * where the certainty of the exclusion is explained.
     */
    public static function sql(int $languageId): string
    {
        $products = TABLE_PRODUCTS;
        $description = TABLE_PRODUCTS_DESCRIPTION;
        $types = TABLE_PRODUCT_TYPES;

        return "SELECT p.products_id, p.products_model, p.products_status,
                       p.products_length, p.products_width, p.products_height,
                       p.products_weight, pd.products_name
                FROM " . $products . " p
                LEFT JOIN " . $description . " pd
                  ON pd.products_id = p.products_id AND pd.language_id = " . $languageId . "
                LEFT JOIN " . $types . " pt ON pt.type_id = p.products_type
                WHERE NOT " . self::neverReachesPackerPredicate() . "
                  AND (p.products_length IS NULL OR p.products_length <= 0
                       OR p.products_width IS NULL OR p.products_width <= 0
                       OR p.products_height IS NULL OR p.products_height <= 0)
                ORDER BY p.products_status DESC, pd.products_name";
    }

    /**
     * Products that can never become a parcel, as one SQL expression.
     *
     * Named and shared because the report filters these out while the page reports how
     * many it hid; two copies of the rule would drift, and a drifting copy here means
     * either invented work or silently missing work.
     *
     * Every term is wrapped so the expression is never NULL: the report negates it, and
     * NOT NULL would quietly drop rows whose columns are unset.
     *
     * - products_virtual: never ships, by definition.
     * - allow_add_to_cart = 'N': the type cannot reach a cart, so it cannot reach the packer.
     * - product_is_always_free_shipping = 1: never quoted. 2 means "special, requires
     *   shipping" and is deliberately not excluded.
     * - GIFT models: core treats these as gift certificates.
     * - An option group whose every value carries a download. A download that hangs off
     *   an attribute only makes the line virtual when the customer selects it, which a
     *   report cannot know; it is certain only when every choice in a group is a
     *   download. A group with one physical choice keeps the product listed, because
     *   choosing it produces a parcel that has to be measured.
     */
    private static function neverReachesPackerPredicate(): string
    {
        $attributes = TABLE_PRODUCTS_ATTRIBUTES;
        $downloads = TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD;

        return "(COALESCE(p.products_virtual, 0) = 1
                 OR COALESCE(pt.allow_add_to_cart, 'Y') = 'N'
                 OR COALESCE(p.product_is_always_free_shipping, 0) = 1
                 OR COALESCE(p.products_model, '') LIKE 'GIFT%'
                 OR (COALESCE(p.product_is_always_free_shipping, 0) <> 2
                     AND EXISTS (
                         SELECT 1
                         FROM " . $attributes . " pa
                         LEFT JOIN " . $downloads . " pad USING (products_attributes_id)
                         WHERE pa.products_id = p.products_id
                         GROUP BY pa.options_id
                         HAVING COUNT(*) = COUNT(pad.products_attributes_id)
                     )))";
    }

    /**
     * How many unmeasured products the report is deliberately not showing.
     *
     * Shown as a footnote so the exclusions are legible rather than silent. It is a
     * count and not a listing: these products must never be measured, so offering them
     * as rows would be offering work that should not be done.
     */
    public static function excludedCountSql(): string
    {
        $products = TABLE_PRODUCTS;
        $types = TABLE_PRODUCT_TYPES;

        return "SELECT COUNT(*) AS total
                FROM " . $products . " p
                LEFT JOIN " . $types . " pt ON pt.type_id = p.products_type
                WHERE " . self::neverReachesPackerPredicate() . "
                  AND (p.products_length IS NULL OR p.products_length <= 0
                       OR p.products_width IS NULL OR p.products_width <= 0
                       OR p.products_height IS NULL OR p.products_height <= 0)";
    }

    /**
     * Which axes a row is missing, in display order.
     *
     * @param array<string, mixed> $row
     * @return string[] any of 'length', 'width', 'height'
     */
    public static function missingAxes(array $row): array
    {
        $missing = [];
        foreach (['length', 'width', 'height'] as $axis) {
            $value = $row['products_' . $axis] ?? null;
            if ($value === null || $value === '' || (float)$value <= 0.0) {
                $missing[] = $axis;
            }
        }

        return $missing;
    }

    /**
     * Headline figures for the report.
     *
     * Enabled products are counted separately because they are the ones that can
     * actually appear in a cart; a disabled product with no dimensions is not urgent.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{total: int, enabled: int, disabled: int, no_weight: int, by_axis: array<string, int>}
     */
    public static function summarize(array $rows): array
    {
        $summary = [
            'total' => 0,
            'enabled' => 0,
            'disabled' => 0,
            'no_weight' => 0,
            'by_axis' => ['length' => 0, 'width' => 0, 'height' => 0],
        ];

        foreach ($rows as $row) {
            $summary['total']++;

            if ((int)($row['products_status'] ?? 0) === 1) {
                $summary['enabled']++;
            } else {
                $summary['disabled']++;
            }

            // A product with neither dimensions nor weight cannot be quoted by any
            // method, so it is worth calling out separately.
            $weight = $row['products_weight'] ?? null;
            if ($weight === null || $weight === '' || (float)$weight <= 0.0) {
                $summary['no_weight']++;
            }

            foreach (self::missingAxes($row) as $axis) {
                $summary['by_axis'][$axis]++;
            }
        }

        return $summary;
    }

    /**
     * True when a product row would be ignored by packing altogether.
     *
     * Kept as a predicate so the report and the cart adapter can be shown to agree,
     * rather than two copies of the same rule drifting apart.
     *
     * @param array<string, mixed> $row
     */
    public static function isNonShipping(array $row): bool
    {
        if ((int)($row['products_virtual'] ?? 0) === 1) {
            return true;
        }
        if ((int)($row['product_is_always_free_shipping'] ?? 0) === 1) {
            return true;
        }

        return str_starts_with((string)($row['products_model'] ?? ''), 'GIFT');
    }
}
