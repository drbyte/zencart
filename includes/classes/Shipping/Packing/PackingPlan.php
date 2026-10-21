<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * The result of a packing attempt.
 *
 * A plan is not a statement about what shipped. It is what the packer believes at
 * quote time, and it carries the flags needed to say so honestly: whether the cart
 * fell back to weight-only quoting, whether parcel counts were extrapolated past the
 * unit guard, and which items could not be packed at all.
 *
 * Nothing here is permitted to be silent. An item larger than every box in the
 * catalog appears in $unpackable so that a quoting module can suppress its method or
 * flag the order, rather than being quietly issued a notional parcel at its own
 * dimensions and priced as though a box existed.
 */
final readonly class PackingPlan
{
    /**
     * @param Package[] $packages
     * @param PackableItem[] $unpackable
     */
    public function __construct(
        public array $packages,
        public array $unpackable = [],
        public bool $legacyFallback = false,
        public bool $approximated = false,
        public string $packerCode = '',
        public string $planHash = '',
        /**
         * Parcels the carrier should be quoted for.
         *
         * Equal to count($packages) for every ordinary plan. It differs only when
         * $approximated is set, where $packages holds the proportionally-sampled
         * parcels actually computed and this is the extrapolated total. An
         * approximated plan is therefore valid for rating but is NOT
         * contents-complete, and must not be persisted against an order as though it
         * described every unit.
         */
        public ?int $estimatedPackageCount = null
    ) {
    }

    public static function legacyFallback(string $packerCode, string $planHash): self
    {
        return new self([], [], true, false, $packerCode, $planHash);
    }

    public function packageCount(): int
    {
        return count($this->packages);
    }

    /**
     * Parcel count to quote on: the extrapolated figure when the unit guard sampled
     * the cart, otherwise the real one.
     */
    public function quotablePackageCount(): int
    {
        return $this->estimatedPackageCount ?? count($this->packages);
    }

    /**
     * Whether this plan may be written against an order. An approximated plan knows
     * how many parcels there will be but not which unit is in which, so persisting it
     * would produce packing slips whose quantities do not sum to the order.
     */
    public function isPersistable(): bool
    {
        return !$this->legacyFallback && !$this->approximated && $this->packages !== [];
    }

    public function isEmpty(): bool
    {
        return $this->packages === [];
    }

    public function hasUnpackableItems(): bool
    {
        return $this->unpackable !== [];
    }

    /**
     * True when the plan can be handed to a carrier as-is. A fallback plan has no
     * parcels, and a plan with unpackable items is incomplete by definition.
     */
    public function isQuotable(): bool
    {
        return !$this->legacyFallback && $this->packages !== [] && $this->unpackable === [];
    }

    public function totalWeight(string $units = Units::POUNDS): Weight
    {
        $total = Weight::zero($units);
        foreach ($this->packages as $package) {
            $total = $total->plus($package->weight);
        }

        return $total;
    }

    /**
     * Average parcel weight, which is what the legacy shipping globals expect.
     * Provided so a compatibility layer need not reimplement it, and deliberately
     * named to make its lossiness obvious at the call site.
     */
    public function averagePackageWeight(string $units = Units::POUNDS): Weight
    {
        $count = $this->packageCount();
        if ($count === 0) {
            return Weight::zero($units);
        }

        return $this->totalWeight($units)->times(1.0 / $count);
    }
}
