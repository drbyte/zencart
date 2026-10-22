<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Reads back the parcel breakdown recorded against an order, and works out how a
 * packing slip should lay it out.
 *
 * The layout decision is a pure function of the stored parcels and the order's own
 * product list, so it is tested without a database and without rendering anything.
 */
final class OrderPackages
{
    public static function packagesSql(int $ordersId): string
    {
        return "SELECT orders_packages_id, package_sequence, package_count,
                       box_id, box_code, box_title,
                       quoted_length, quoted_width, quoted_height, quoted_weight,
                       quoted_billable_weight, tare_weight, dim_units, weight_units,
                       packer_code, plan_origin, plan_is_stale, packed_at
                FROM " . PackingSchema::tableName('orders_packages') . "
                WHERE orders_id = " . $ordersId . "
                ORDER BY package_sequence";
    }

    public static function contentsSql(int $ordersId): string
    {
        return "SELECT orders_packages_id, orders_id, orders_products_id, products_quantity
                FROM " . PackingSchema::tableName('orders_packages_products') . "
                WHERE orders_id = " . $ordersId;
    }

    /**
     * Combine parcel rows with their contents.
     *
     * @param array<int, array<string, mixed>> $packageRows
     * @param array<int, array<string, mixed>> $contentRows
     * @return array<int, array<string, mixed>>
     */
    public static function fromRows(array $packageRows, array $contentRows): array
    {
        $quantities = [];
        foreach ($contentRows as $row) {
            $packageId = (int)($row['orders_packages_id'] ?? 0);
            $lineId = (int)($row['orders_products_id'] ?? 0);
            if ($packageId === 0 || $lineId === 0) {
                continue;
            }
            $quantities[$packageId][$lineId] =
                ($quantities[$packageId][$lineId] ?? 0.0) + (float)($row['products_quantity'] ?? 0);
        }

        $packages = [];
        foreach ($packageRows as $row) {
            $packageId = (int)($row['orders_packages_id'] ?? 0);
            $packages[] = [
                'orders_packages_id' => $packageId,
                'sequence' => (int)($row['package_sequence'] ?? 0),
                'count' => (int)($row['package_count'] ?? 0),
                'box_code' => (string)($row['box_code'] ?? ''),
                'box_title' => (string)($row['box_title'] ?? ''),
                'length' => $row['quoted_length'] ?? null,
                'width' => $row['quoted_width'] ?? null,
                'height' => $row['quoted_height'] ?? null,
                'weight' => $row['quoted_weight'] ?? null,
                'dim_units' => (string)($row['dim_units'] ?? ''),
                'weight_units' => (string)($row['weight_units'] ?? ''),
                'plan_origin' => (string)($row['plan_origin'] ?? PackingPersistence::ORIGIN_QUOTED),
                'plan_is_stale' => (int)($row['plan_is_stale'] ?? 0) === 1,
                'quantities' => $quantities[$packageId] ?? [],
            ];
        }

        return $packages;
    }

    /**
     * Flatten parcels and order lines into the sequence a slip should print.
     *
     * Each entry is one printable row: the index into $orderProducts, the quantity to
     * show for it here, and the parcel it opens (null when it continues the current
     * one). A line split across parcels appears once per parcel with that parcel's
     * quantity, never with the order-line total.
     *
     * Order lines that no parcel claims are printed last, in an unassigned group. That
     * matters more than it looks: a plan recorded before someone added a line in admin
     * would otherwise leave goods off the slip entirely, and a warehouse cannot notice
     * what a document does not mention.
     *
     * @param array<int, array<string, mixed>> $packages from fromRows()
     * @param array<int, array<string, mixed>> $orderProducts $order->products
     * @param int[]|false $sortOrder optional display order of $orderProducts indices
     * @return array<int, array{index: int, qty: float|int|string, parcel: ?array<string, mixed>}>
     */
    public static function renderSequence(array $packages, array $orderProducts, array|false $sortOrder = false): array
    {
        $indices = is_array($sortOrder) && $sortOrder !== []
            ? array_values(array_filter($sortOrder, static fn ($i): bool => isset($orderProducts[$i])))
            : array_keys($orderProducts);

        $indexByLineId = [];
        foreach ($indices as $index) {
            $lineId = (int)($orderProducts[$index]['orders_products_id'] ?? 0);
            if ($lineId > 0) {
                $indexByLineId[$lineId] = $index;
            }
        }

        $sequence = [];
        $claimed = [];

        foreach ($packages as $package) {
            $opened = false;
            foreach ($indices as $index) {
                $lineId = (int)($orderProducts[$index]['orders_products_id'] ?? 0);
                if ($lineId === 0 || !isset($package['quantities'][$lineId])) {
                    continue;
                }
                $sequence[] = [
                    'index' => $index,
                    'qty' => (float)$package['quantities'][$lineId],
                    'parcel' => $opened ? null : $package,
                ];
                $opened = true;
                $claimed[$index] = true;
            }

            // A parcel whose contents are all gone - every line deleted in admin - still
            // existed and was paid for, so it is announced rather than skipped.
            if (!$opened) {
                $sequence[] = ['index' => -1, 'qty' => 0.0, 'parcel' => $package];
            }
        }

        foreach ($indices as $index) {
            if (isset($claimed[$index])) {
                continue;
            }
            $sequence[] = [
                // The order line's own value, passed through untouched. order::$products
                // has already normalised it to an int when whole, and the packing slip
                // has always printed it verbatim; casting here would risk changing what
                // every existing slip shows for no benefit.
                'index' => $index,
                'qty' => $orderProducts[$index]['qty'] ?? 0,
                'parcel' => null,
                'unassigned' => true,
            ];
        }

        return $sequence;
    }

    /**
     * True when any order line is not accounted for by a parcel.
     *
     * @param array<int, array<string, mixed>> $sequence from renderSequence()
     */
    public static function hasUnassignedLines(array $sequence): bool
    {
        foreach ($sequence as $entry) {
            if (!empty($entry['unassigned'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Order lines whose recorded parcel quantities no longer match the order.
     *
     * Derived rather than flagged, deliberately. The plan goes stale when an order's
     * lines are edited, and line editing happens only in admin tooling that core does
     * not ship - so an approach that waited for an edit hook would simply never fire
     * for most stores. Comparing what was recorded against what the order now says
     * needs no cooperation from anyone.
     *
     * Core's own NOTIFY_ADMIN_ORDERS_UPDATE_ORDER_START is deliberately not used for
     * this: core admin edits an order's status and comments, not its lines, so marking
     * the plan stale there would raise the caveat on nearly every order ever touched
     * and teach staff to ignore it.
     *
     * @param array<int, array<string, mixed>> $packages from fromRows()
     * @param array<int, array<string, mixed>> $orderProducts $order->products
     * @return array{changed: int[], missing: int[], extra: int[]} order line ids
     */
    public static function quantityMismatches(array $packages, array $orderProducts): array
    {
        $recorded = [];
        foreach ($packages as $package) {
            foreach ($package['quantities'] as $lineId => $quantity) {
                $recorded[(int)$lineId] = ($recorded[(int)$lineId] ?? 0.0) + (float)$quantity;
            }
        }

        $ordered = [];
        foreach ($orderProducts as $product) {
            $lineId = (int)($product['orders_products_id'] ?? 0);
            if ($lineId > 0) {
                $ordered[$lineId] = (float)($product['qty'] ?? 0);
            }
        }

        $changed = [];
        $extra = [];
        foreach ($recorded as $lineId => $quantity) {
            if (!isset($ordered[$lineId])) {
                // Recorded against a line the order no longer has: deleted in admin.
                $extra[] = $lineId;
                continue;
            }
            if (abs($ordered[$lineId] - $quantity) > 0.0001) {
                $changed[] = $lineId;
            }
        }

        $missing = [];
        foreach ($ordered as $lineId => $quantity) {
            if (!isset($recorded[$lineId])) {
                $missing[] = $lineId;
            }
        }

        sort($changed);
        sort($missing);
        sort($extra);

        return ['changed' => $changed, 'missing' => $missing, 'extra' => $extra];
    }

    /**
     * True when the recorded breakdown no longer describes this order.
     *
     * @param array<int, array<string, mixed>> $packages
     * @param array<int, array<string, mixed>> $orderProducts
     */
    public static function isOutOfDate(array $packages, array $orderProducts): bool
    {
        if ($packages === []) {
            return false;
        }

        $mismatches = self::quantityMismatches($packages, $orderProducts);

        return $mismatches['changed'] !== [] || $mismatches['missing'] !== [] || $mismatches['extra'] !== [];
    }

    /**
     * Whether the recorded breakdown should be shown with a caveat.
     *
     * @param array<int, array<string, mixed>> $packages
     */
    public static function needsCaveat(array $packages, array $orderProducts = []): bool
    {
        foreach ($packages as $package) {
            if ($package['plan_is_stale'] || $package['plan_origin'] !== PackingPersistence::ORIGIN_QUOTED) {
                return true;
            }
        }

        // The stored flag is authoritative when tooling sets it, but it cannot be
        // relied on, so the comparison runs too whenever the order is to hand.
        return $orderProducts !== [] && self::isOutOfDate($packages, $orderProducts);
    }

    /**
     * Mark an order's recorded plan as no longer trustworthy.
     *
     * Public so order-editing tooling can say so authoritatively at the moment it
     * changes a line, which is cheaper and more certain than any comparison. Nothing in
     * core calls it: core admin does not edit order lines. The flag is never cleared
     * here - a stale plan is replaced by re-packing, not by being declared fresh.
     *
     * @param object $db queryFactory
     */
    public static function markStale(object $db, int $ordersId): void
    {
        if ($ordersId <= 0) {
            return;
        }

        $db->Execute(
            'UPDATE ' . PackingSchema::tableName('orders_packages')
            . ' SET plan_is_stale = 1 WHERE orders_id = ' . $ordersId
        );
    }
}
