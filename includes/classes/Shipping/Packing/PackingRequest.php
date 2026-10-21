<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * The outcome of turning a cart into packable units, with the counters an admin needs
 * to understand what happened.
 *
 * The diagnostics are not decoration. A store whose quotes changed will ask why, and
 * the honest answers are all here: how many lines were excluded as non-shipping, how
 * many products are missing dimensions, and whether the cart was simply too large to
 * model. Without them the feature is experienced as "my quotes went weird".
 */
final readonly class PackingRequest
{
    /**
     * @param PackableItem[] $items discrete units, normalized to the context's units
     * @param string[] $missingDimensionUprids uprids lacking usable length/width/height
     */
    public function __construct(
        public array $items,
        public int $excludedLineCount = 0,
        public array $missingDimensionUprids = [],
        public bool $exceedsUnitCeiling = false,
        public int $declaredUnitTotal = 0
    ) {
    }

    public function isPackable(): bool
    {
        return !$this->exceedsUnitCeiling && $this->items !== [];
    }

    public function hasMissingDimensions(): bool
    {
        return $this->missingDimensionUprids !== [];
    }

    public function unitCount(): int
    {
        return count($this->items);
    }
}
