<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * A position inside a box, measured from the interior origin corner.
 *
 * Recorded for every placed item so that a plan can be verified geometrically and
 * rendered or exported, and so that a third-party packer which reports coordinates
 * has somewhere to put them.
 */
final readonly class Vector3
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $z = 0.0
    ) {
    }

    public function translate(float $dx, float $dy, float $dz): self
    {
        return new self($this->x + $dx, $this->y + $dy, $this->z + $dz);
    }

    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'z' => $this->z];
    }
}
