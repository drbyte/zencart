<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Builds a packing plan for the current cart, once.
 *
 * Quoting runs on the cart page, the shipping estimator and both checkout steps, and
 * a module may re-quote, so the plan is memoized on the hash of its own inputs. A hash
 * miss means something genuinely changed - the cart, the destination, a setting, or a
 * box - and recomputing is then correct.
 *
 * Settings arrive as an array rather than being read from zen_config() here, so the
 * whole service is testable without a bootstrap and so a caller with other settings
 * (an admin re-pack, a diagnostic page) can use the same path.
 */
final class PackingService
{
    /** @var array<string, PackingPlan> */
    private array $memo = [];

    private int $packCalls = 0;

    /**
     * Bound on the memo, so a long browsing session cannot accumulate one entry per
     * cart state. Quoting only ever needs the current plan; older entries are history.
     */
    private const MEMO_LIMIT = 8;

    public function __construct(
        private readonly PackerInterface $packer = new FirstFitDecreasingPacker(),
        private readonly CartPackingAdapter $adapter = new CartPackingAdapter()
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $cartProducts from shopping_cart::get_products()
     * @param BoxType[] $boxes
     */
    public function planFor(array $cartProducts, array $boxes, PackingContext $context): PackingPlan
    {
        $request = $this->adapter->fromCartProducts($cartProducts, $context);

        // Nothing to ship, no boxes defined, or a cart too large to model: fall back
        // rather than inventing a plan. A store with packing switched on but no boxes
        // yet must behave exactly as it did before.
        if (!$request->isPackable() || $boxes === []) {
            return PackingPlan::legacyFallback(
                $this->packer->code(),
                $this->fallbackHash($request, $boxes, $context)
            );
        }

        $normalizedBoxes = array_map(
            static fn (BoxType $box): BoxType => $box->withUnits($context->dimUnits, $context->weightUnits),
            $boxes
        );

        // Hash the inputs before packing, so the memo can actually be consulted.
        // Quoting runs on the cart page, the estimator and both checkout steps, and a
        // module may re-quote, so an unconsulted memo would mean repacking every time.
        $planHash = PlanHasher::hash($request->items, $normalizedBoxes, $context, $this->packer->code());
        $cached = $this->memo[$planHash] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $this->packCalls++;

        return $this->remember($this->packer->pack($request->items, $normalizedBoxes, $context));
    }

    /**
     * Return a previously computed plan by hash, or null.
     *
     * Persistence uses this at order-create time: the plan written against an order
     * must be the one that produced the quote the customer accepted, never a fresh
     * computation that might differ.
     */
    public function recall(string $planHash): ?PackingPlan
    {
        return $this->memo[$planHash] ?? null;
    }

    /**
     * How many times the packer has actually been invoked.
     *
     * Exposed so a test can prove the memo is consulted rather than merely populated -
     * the distinction a "a plan comes back" assertion cannot make.
     */
    public function packCallCount(): int
    {
        return $this->packCalls;
    }

    private function remember(PackingPlan $plan): PackingPlan
    {
        $this->memo[$plan->planHash] = $plan;
        if (count($this->memo) > self::MEMO_LIMIT) {
            $this->memo = array_slice($this->memo, -self::MEMO_LIMIT, null, true);
        }

        return $plan;
    }

    /**
     * A fallback still gets a hash, so a caller can tell one fallback from another and
     * so repeated quoting of the same unpackable cart is recognisable rather than
     * looking like a fresh decision each time.
     *
     * @param BoxType[] $boxes
     */
    private function fallbackHash(PackingRequest $request, array $boxes, PackingContext $context): string
    {
        return hash('sha256', implode('|', [
            'fallback',
            (string)$request->declaredUnitTotal,
            (string)$request->unitCount(),
            $request->exceedsUnitCeiling ? 'ceiling' : '',
            implode(',', $request->missingDimensionUprids),
            (string)count($boxes),
            $context->signature(),
        ]));
    }
}
