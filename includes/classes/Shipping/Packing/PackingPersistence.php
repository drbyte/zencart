<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Writes a packing plan against an order.
 *
 * Builds the rows as plain arrays and hands them to a writer callback, so the shape of
 * what gets stored is testable without a database and the same code can be driven by
 * zen_db_perform in production or by a recorder in a test.
 *
 * Parent and contents rows are written together in one pass. The tables are MyISAM, so
 * there is no transaction to roll back: splitting the write across the order-header
 * hook and a later one would leave a window where a dying request produces parcels with
 * no contents, which renders as empty packing slips - the most confusing failure this
 * feature could have.
 */
final class PackingPersistence
{
    public const ORIGIN_QUOTED = 'quoted';
    public const ORIGIN_RECONSTRUCTED = 'reconstructed';

    /**
     * Build every row for an order's plan.
     *
     * @param array<string, int> $lineIdsByUprid uprid => orders_products_id, collected
     *        during order creation. Resolved from the map rather than queried, because
     *        the plan is keyed by cart uprid while the record must key on the order
     *        line that was actually inserted.
     * @return array{packages: array<int, array<string, mixed>>, contents: array<int, array<string, mixed>>}
     */
    public static function rowsFor(
        PackingPlan $plan,
        int $ordersId,
        array $lineIdsByUprid,
        string $origin = self::ORIGIN_QUOTED,
        ?string $packedAt = null
    ): array {
        $packages = [];
        $contents = [];
        $total = $plan->packageCount();
        $sequence = 0;

        foreach ($plan->packages as $package) {
            $sequence++;
            $box = $package->boxType;

            $packages[] = [
                'orders_id' => $ordersId,
                'package_sequence' => $sequence,
                'package_count' => $total,
                // Null when no catalog box applied - an own-box item, or one larger
                // than anything the store stocks.
                'box_id' => PackingSchema::dbValue($box?->boxId),
                // Snapshotted, following the orders_products precedent: the catalog row
                // may be edited or deleted long before someone reprints the slip.
                'box_code' => $box->code ?? '',
                'box_title' => $box->title ?? '',
                'quoted_length' => $package->exterior->length,
                'quoted_width' => $package->exterior->width,
                'quoted_height' => $package->exterior->height,
                'quoted_weight' => $package->weight->value,
                'quoted_billable_weight' => PackingSchema::dbValue($package->billableWeight?->value),
                'tare_weight' => $package->tare->value,
                'dim_units' => $package->exterior->units,
                'weight_units' => $package->weight->units,
                'packer_code' => $plan->packerCode,
                'plan_origin' => $origin,
                'plan_is_stale' => 0,
                'packed_at' => PackingSchema::dbValue($packedAt),
            ];

            foreach ($package->quantitiesByLine() as $lineKey => $quantity) {
                // A line with no mapped id cannot be recorded against the order. That
                // means the plan and the order disagree about what was bought, so the
                // caller is told rather than a row being written against id 0.
                if (!isset($lineIdsByUprid[$lineKey])) {
                    continue;
                }

                $contents[] = [
                    'orders_packages_id' => $sequence, // placeholder, replaced on insert
                    'orders_id' => $ordersId,
                    'orders_products_id' => $lineIdsByUprid[$lineKey],
                    'products_quantity' => $quantity,
                ];
            }
        }

        return ['packages' => $packages, 'contents' => $contents];
    }

    /**
     * Line keys in the plan that the order has no row for.
     *
     * Non-empty means the plan describes something the order does not contain, which is
     * a reason to record the plan as reconstructed rather than quoted.
     *
     * @param array<string, int> $lineIdsByUprid
     * @return string[]
     */
    public static function unmappedLineKeys(PackingPlan $plan, array $lineIdsByUprid): array
    {
        $missing = [];
        foreach ($plan->packages as $package) {
            foreach (array_keys($package->quantitiesByLine()) as $lineKey) {
                if (!isset($lineIdsByUprid[$lineKey])) {
                    $missing[$lineKey] = $lineKey;
                }
            }
        }

        return array_values($missing);
    }

    /**
     * Persist a plan, resolving each parcel's generated id before writing its contents.
     *
     * $insert receives (table, row) and must return the new auto-increment id.
     *
     * @param array<string, int> $lineIdsByUprid
     * @param callable(string, array<string, mixed>): int $insert
     * @return int number of parcels written
     */
    public static function write(
        PackingPlan $plan,
        int $ordersId,
        array $lineIdsByUprid,
        callable $insert,
        string $origin = self::ORIGIN_QUOTED,
        ?string $packedAt = null
    ): int {
        if (!$plan->isPersistable() || $ordersId <= 0) {
            return 0;
        }

        $rows = self::rowsFor($plan, $ordersId, $lineIdsByUprid, $origin, $packedAt);
        $packagesTable = TABLE_ORDERS_PACKAGES;
        $contentsTable = TABLE_ORDERS_PACKAGES_PRODUCTS;

        $written = 0;
        foreach ($rows['packages'] as $package) {
            $sequence = $package['package_sequence'];
            $packageId = $insert($packagesTable, $package);
            $written++;

            foreach ($rows['contents'] as $content) {
                if ($content['orders_packages_id'] !== $sequence) {
                    continue;
                }
                $content['orders_packages_id'] = $packageId;
                $insert($contentsTable, $content);
            }
        }

        return $written;
    }
}
