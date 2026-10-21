<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * One parcel in a finished plan: a box, what is in it, and what the carrier should be
 * told about it.
 *
 * $exterior is the carrier-facing size, so it comes from the box's exterior rather
 * than from the items. A parcel with no box type - an own-box item, or an item larger
 * than anything in the catalog - reports the item's own extent instead.
 */
final readonly class Package
{
    /**
     * @param PackedItem[] $contents
     */
    public function __construct(
        public ?BoxType $boxType,
        public Dimensions $exterior,
        public array $contents,
        public Weight $weight,
        public Weight $tare,
        public ?Weight $billableWeight = null,
        public float $fillRatio = 0.0
    ) {
    }

    public function itemCount(): int
    {
        return count($this->contents);
    }

    /**
     * Quantities per cart line, which is the shape the order record needs: one row per
     * line per parcel, not one row per unit.
     *
     * @return array<string, float> lineKey => quantity in this parcel
     */
    public function quantitiesByLine(): array
    {
        $totals = [];
        foreach ($this->contents as $packed) {
            $key = $packed->item->lineKey;
            $totals[$key] = ($totals[$key] ?? 0.0) + 1.0;
        }

        return $totals;
    }

    /**
     * What the carrier will actually bill on, when a dimensional divisor is known.
     *
     * The divisor must be expressed in the same dimension units as the plan; the
     * quoting module supplies it, because the value is carrier- and contract-specific.
     */
    public static function computeBillableWeight(Dimensions $exterior, Weight $actual, ?float $dimDivisor): ?Weight
    {
        if ($dimDivisor === null || $dimDivisor <= 0.0) {
            return null;
        }

        $dimensional = $exterior->volume() / $dimDivisor;

        return new Weight(max($actual->value, $dimensional), $actual->units);
    }
}
