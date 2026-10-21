<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Identity of a packing problem.
 *
 * Separate from the packer so a caller can hash the inputs *before* deciding to pack.
 * That is the whole point: a memo keyed on a hash the packer only produces as output
 * can never be consulted, so the plan would be recomputed on every quote - and quoting
 * runs on the cart page, the shipping estimator and both checkout steps.
 */
final class PlanHasher
{
    /**
     * Hash everything that can change the resulting plan.
     *
     * Item signatures are sorted, so the order lines happen to sit in the cart cannot
     * change the hash. The box catalog contributes both its contents and the context's
     * catalog revision: the revision is what notices an admin editing or deleting a
     * box mid-session, which the box signatures alone would miss for a deletion.
     *
     * @param PackableItem[] $items
     * @param BoxType[] $boxes
     */
    public static function hash(array $items, array $boxes, PackingContext $context, string $packerCode): string
    {
        $itemSignatures = array_map(static fn (PackableItem $i): string => $i->signature(), $items);
        sort($itemSignatures);

        $boxSignatures = array_map(static fn (BoxType $b): string => $b->signature(), $boxes);
        sort($boxSignatures);

        return hash('sha256', implode("\n", [
            $packerCode,
            implode(';', $itemSignatures),
            implode(';', $boxSignatures),
            $context->signature(),
        ]));
    }
}
