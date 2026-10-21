<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * The packing extension point.
 *
 * Takes and returns only this subsystem's own value objects, so that an alternative
 * implementation - a store's own heuristic, or an adapter over a third-party packing
 * library pulled in through a plugin's composer.json - is a pure translation layer
 * requiring no core change. No caller may depend on a concrete packer.
 */
interface PackerInterface
{
    /**
     * @param PackableItem[] $items discrete units, already normalized to the context's units
     * @param BoxType[] $boxes the store's catalog, already normalized to the context's units
     */
    public function pack(array $items, array $boxes, PackingContext $context): PackingPlan;

    /**
     * Short stable identifier recorded against any order this packer plans, so that an
     * old order's parcels can still be explained after a store changes packers.
     */
    public function code(): string;
}
