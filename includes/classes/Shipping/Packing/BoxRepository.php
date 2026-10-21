<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Loads the store's box catalog.
 *
 * Mapping is separated from querying so the mapping - where every type coercion and
 * fallback lives - is testable as a pure function over rows, with no database at all.
 * The query itself is one statement with no parameters, run once per quote.
 */
final class BoxRepository
{
    /**
     * Fetch every enabled box, plus a revision marker for the plan hash.
     *
     * The revision is max(last_modified) across the enabled rows, which is what stops
     * a memoized plan outliving an admin edit to a box. Counting the rows as well
     * catches a deletion, which would not move a maximum.
     *
     * @param object $db queryFactory
     * @return array{boxes: BoxType[], revision: string}
     */
    public static function loadEnabled(object $db): array
    {
        $sql = "SELECT box_id, box_code, box_title,
                       interior_length, interior_width, interior_height,
                       exterior_length, exterior_width, exterior_height,
                       interior_margin, dim_units, tare_weight, max_payload_weight,
                       weight_units, destination_scope, carrier_code, last_modified
                FROM " . PackingSchema::tableName('shipping_boxes') . "
                WHERE is_enabled = 1
                ORDER BY sort_order, box_code";

        $result = $db->Execute($sql);

        $rows = [];
        while (!$result->EOF) {
            $rows[] = $result->fields;
            $result->MoveNext();
        }

        return [
            'boxes' => self::hydrate($rows),
            'revision' => self::revisionOf($rows),
        ];
    }

    /**
     * Map result rows to BoxType objects, skipping any that cannot describe a usable
     * box.
     *
     * A row with a zero or missing interior dimension is not a box, it is a
     * data-entry accident, and including it would let the packer "fit" items into
     * nothing. Skipping is safe: the worst case is more parcels, or an item reported
     * unpackable, both of which are visible. The admin box editor validates on entry;
     * this is the backstop for rows that arrived another way.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return BoxType[]
     */
    public static function hydrate(array $rows): array
    {
        $boxes = [];
        foreach ($rows as $row) {
            $dimUnits = (string)($row['dim_units'] ?? '');
            $dimUnits = Units::isKnown($dimUnits) ? $dimUnits : Units::INCHES;
            $weightUnits = (string)($row['weight_units'] ?? '');
            $weightUnits = Units::isKnown($weightUnits) ? $weightUnits : Units::POUNDS;

            $interior = new Dimensions(
                (float)($row['interior_length'] ?? 0),
                (float)($row['interior_width'] ?? 0),
                (float)($row['interior_height'] ?? 0),
                $dimUnits
            );
            if (!$interior->isUsable()) {
                continue;
            }

            // Exterior falls back to the interior when unset, rather than to zero: a
            // box with no recorded exterior still has to report a carrier-facing size,
            // and the interior understates it slightly rather than absurdly.
            $exterior = new Dimensions(
                (float)($row['exterior_length'] ?? 0) ?: $interior->length,
                (float)($row['exterior_width'] ?? 0) ?: $interior->width,
                (float)($row['exterior_height'] ?? 0) ?: $interior->height,
                $dimUnits
            );

            $maxPayload = $row['max_payload_weight'] ?? null;
            $margin = $row['interior_margin'] ?? null;

            $boxes[] = new BoxType(
                isset($row['box_id']) ? (int)$row['box_id'] : null,
                (string)($row['box_code'] ?? ''),
                (string)($row['box_title'] ?? ''),
                $interior,
                $exterior,
                new Weight((float)($row['tare_weight'] ?? 0), $weightUnits),
                ($maxPayload === null || $maxPayload === '' || (float)$maxPayload <= 0.0)
                    ? null
                    : new Weight((float)$maxPayload, $weightUnits),
                self::scope($row['destination_scope'] ?? null),
                ($row['carrier_code'] ?? '') === '' ? null : (string)$row['carrier_code'],
                ($margin === null || $margin === '' || (float)$margin < 0.0) ? null : (float)$margin
            );
        }

        return $boxes;
    }

    private static function scope(mixed $value): string
    {
        $scope = strtolower(trim((string)$value));

        return in_array($scope, [BoxType::SCOPE_DOMESTIC, BoxType::SCOPE_INTERNATIONAL], true)
            ? $scope
            : BoxType::SCOPE_BOTH;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function revisionOf(array $rows): string
    {
        $latest = '';
        foreach ($rows as $row) {
            $modified = (string)($row['last_modified'] ?? '');
            if ($modified > $latest) {
                $latest = $modified;
            }
        }

        return count($rows) . ':' . $latest;
    }
}
