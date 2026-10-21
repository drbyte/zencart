<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

final readonly class Weight
{
    public function __construct(
        public float $value,
        public string $units = Units::POUNDS
    ) {
    }

    public static function zero(string $units = Units::POUNDS): self
    {
        return new self(0.0, $units);
    }

    public function withUnits(string $units): self
    {
        if (Units::canonical($units) === Units::canonical($this->units)) {
            return $this;
        }

        return new self(Units::convert($this->value, $this->units, $units), $units);
    }

    /**
     * Add another weight, converting it into this weight's units first. Returning a
     * new instance keeps the value object immutable; the packer accumulates parcel
     * weight by reassignment rather than mutation.
     */
    public function plus(self $other): self
    {
        return new self($this->value + $other->withUnits($this->units)->value, $this->units);
    }

    public function times(float $factor): self
    {
        return new self($this->value * $factor, $this->units);
    }

    public function exceeds(?self $limit): bool
    {
        if ($limit === null) {
            return false;
        }

        return $this->value > $limit->withUnits($this->units)->value + self::EPSILON;
    }

    /**
     * Tolerance for weight comparisons. Product weights are stored as FLOAT, so an
     * exact comparison against a payload cap would reject a parcel that is at the
     * limit by a rounding artifact.
     */
    public const EPSILON = 1.0e-6;
}
