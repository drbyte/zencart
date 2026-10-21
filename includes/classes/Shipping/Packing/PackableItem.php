<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * One discrete physical unit to be packed.
 *
 * A cart line of quantity 5 becomes five PackableItem instances sharing a lineKey.
 * The packer works in units because a parcel contains units, not lines; the lineKey
 * is what lets the resulting plan be collapsed back into per-line quantities when it
 * is written against the order.
 */
final readonly class PackableItem
{
    public function __construct(
        public string $uprid,
        public string $lineKey,
        public Dimensions $dimensions,
        public Weight $weight,
        public bool $shipsInOwnBox = false,
        public bool $noStack = false,
        public bool $orientationLock = false
    ) {
    }

    /**
     * Orientations this item may be presented in, honouring its own axis lock.
     *
     * @return Dimensions[]
     */
    public function orientations(): array
    {
        return $this->dimensions->rotations($this->orientationLock);
    }

    public function withUnits(string $dimUnits, string $weightUnits): self
    {
        return new self(
            $this->uprid,
            $this->lineKey,
            $this->dimensions->withUnits($dimUnits),
            $this->weight->withUnits($weightUnits),
            $this->shipsInOwnBox,
            $this->noStack,
            $this->orientationLock
        );
    }

    /**
     * Stable identity for deterministic sorting and plan hashing. Two units of the
     * same line are interchangeable and share a signature by design.
     */
    public function signature(): string
    {
        return implode('|', [
            $this->uprid,
            $this->dimensions->signature(),
            sprintf('%.4F', $this->weight->value),
            Units::canonical($this->weight->units),
            $this->shipsInOwnBox ? '1' : '0',
            $this->noStack ? '1' : '0',
            $this->orientationLock ? '1' : '0',
        ]);
    }
}
