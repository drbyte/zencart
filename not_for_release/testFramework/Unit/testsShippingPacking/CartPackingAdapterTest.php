<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use Zencart\Shipping\Packing\CartPackingAdapter;
use Zencart\Shipping\Packing\PackingContext;
use Zencart\Shipping\Packing\Units;

/**
 * Turning a cart into packable units.
 *
 * Every case here is a shape that real cart data actually takes: nulls where a
 * dimension was never entered, strings where an int was expected, a renamed own-box
 * column, mixed units between lines, fractional quantities, and the several kinds of
 * line that must never become a parcel.
 */
class CartPackingAdapterTest extends PackingTestCase
{
    private function context(array $overrides = []): PackingContext
    {
        return new PackingContext(
            dimUnits: $overrides['dimUnits'] ?? Units::INCHES,
            weightUnits: $overrides['weightUnits'] ?? Units::POUNDS,
            catalogRevision: 'test'
        );
    }

    /**
     * Minimal realistic line, with the string-typed numerics the cart actually yields.
     *
     * @return array<string, mixed>
     */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'id' => '42',
            'model' => 'WIDGET-1',
            'quantity' => 2.0,
            'weight' => '1.5',
            'weight_units' => Units::POUNDS,
            'dim_units' => Units::INCHES,
            'length' => '6.0000',
            'width' => '4.0000',
            'height' => '2.0000',
            'ships_in_own_box' => '0',
            'products_virtual' => 0,
            'product_is_always_free_shipping' => 0,
        ], $overrides);
    }

    public function testQuantityIsExpandedIntoDiscreteUnits(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts([$this->line()], $this->context());

        $this->assertSame(2, $request->unitCount());
        $this->assertSame(2, $request->declaredUnitTotal);
        $this->assertSame(6.0, $request->items[0]->dimensions->length);
        $this->assertSame(1.5, $request->items[0]->weight->value);
    }

    /**
     * A fraction of a dimensioned product cannot occupy part of a parcel, so the
     * quantity rounds up. Over-reserving space is the conservative direction.
     */
    public function testFractionalQuantityRoundsUp(): void
    {
        // 2.2 rather than 2.5, so the assertion distinguishes rounding up from rounding
        // to nearest: both give 3 for 2.5, but only ceil() gives 3 for 2.2. Reserving
        // space for a whole unit is the conservative direction.
        // Pairs, not a keyed array: PHP casts float array keys to int, so 2.2 would
        // silently become 2.
        foreach ([[2.2, 3], [2.5, 3], [2.8, 3], [0.1, 1], [1.0, 1]] as [$quantity, $expected]) {
            $request = (new CartPackingAdapter())->fromCartProducts(
                [$this->line(['quantity' => (float)$quantity])],
                $this->context()
            );

            $this->assertSame($expected, $request->unitCount(), 'Quantity ' . $quantity);
        }
    }

    public function testZeroOrNegativeQuantityIsExcluded(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['quantity' => 0]), $this->line(['quantity' => -1])],
            $this->context()
        );

        $this->assertSame(0, $request->unitCount());
        $this->assertSame(2, $request->excludedLineCount);
    }

    public function testVirtualProductsAreExcluded(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['products_virtual' => 1])],
            $this->context()
        );

        $this->assertSame(0, $request->unitCount());
        $this->assertSame(1, $request->excludedLineCount);
    }

    public function testGiftCertificatesAreExcluded(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['model' => 'GIFT100'])],
            $this->context()
        );

        $this->assertSame(0, $request->unitCount());
    }

    public function testAlwaysFreeShippingProductsAreExcluded(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['product_is_always_free_shipping' => 1])],
            $this->context()
        );

        $this->assertSame(0, $request->unitCount());
    }

    /**
     * The trap in this column. A value of 2 does not mean "free shipping, more so" - it
     * means "special, requires shipping", the product-with-a-download case that still
     * has a physical carton. Excluding it would silently drop real parcels from every
     * plan, and the error would show up only as quotes that are mysteriously too low.
     */
    public function testAlwaysFreeShippingValueTwoStillShips(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['product_is_always_free_shipping' => 2, 'quantity' => 1.0])],
            $this->context()
        );

        $this->assertSame(1, $request->unitCount(), 'A "special, requires shipping" product must be packed.');
    }

    /**
     * Missing dimensions are reported, never invented. The uprid list is what the
     * admin diagnostics and the plan explainer need in order to say which products to
     * go and measure.
     */
    public function testMissingDimensionsAreReportedNotFabricated(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['id' => '7', 'length' => null, 'width' => '', 'height' => '0.0000'])],
            $this->context()
        );

        $this->assertTrue($request->hasMissingDimensions());
        $this->assertSame(['7'], $request->missingDimensionUprids);
        $this->assertFalse($request->items[0]->dimensions->isUsable());
        $this->assertSame(0.0, $request->items[0]->dimensions->length);
    }

    public function testPerLineUnitsAreNormalizedToTheContext(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line([
                'quantity' => 1.0,
                'dim_units' => Units::CENTIMETERS,
                'length' => '10.16',
                'width' => '10.16',
                'height' => '10.16',
                'weight_units' => Units::KILOGRAMS,
                'weight' => '0.4535924',
            ])],
            $this->context()
        );

        $item = $request->items[0];
        $this->assertSame(Units::INCHES, $item->dimensions->units);
        $this->assertEqualsWithDelta(4.0, $item->dimensions->length, 0.001);
        $this->assertEqualsWithDelta(1.0, $item->weight->value, 0.001);
    }

    /**
     * An unrecognised or empty unit string falls back to the store default rather than
     * throwing. Legacy rows carry all sorts of things in these columns, and a quote
     * page is the wrong place to discover that.
     */
    public function testUnknownUnitStringsFallBackToTheStoreDefault(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['quantity' => 1.0, 'dim_units' => 'cubits', 'weight_units' => ''])],
            $this->context()
        );

        $this->assertSame(Units::INCHES, $request->items[0]->dimensions->units);
        $this->assertSame(Units::POUNDS, $request->items[0]->weight->units);
    }

    public function testOwnBoxFlagIsReadPermissively(): void
    {
        $adapter = new CartPackingAdapter();
        $context = $this->context();

        $this->assertTrue(
            $adapter->fromCartProducts([$this->line(['quantity' => 1.0, 'ships_in_own_box' => '1'])], $context)
                ->items[0]->shipsInOwnBox
        );
        $this->assertFalse(
            $adapter->fromCartProducts([$this->line(['quantity' => 1.0, 'ships_in_own_box' => null])], $context)
                ->items[0]->shipsInOwnBox
        );
        $this->assertFalse(
            $adapter->fromCartProducts([$this->line(['quantity' => 1.0, 'ships_in_own_box' => '0'])], $context)
                ->items[0]->shipsInOwnBox
        );
    }

    /**
     * The no-stack and orientation-lock columns do not exist yet and get_products()
     * does not publish them, so absent keys must default to false rather than
     * tripping a notice.
     */
    public function testAbsentFutureFlagsDefaultToFalse(): void
    {
        $line = $this->line(['quantity' => 1.0]);
        unset($line['no_stack'], $line['orientation_lock']);

        $item = (new CartPackingAdapter())->fromCartProducts([$line], $this->context())->items[0];

        $this->assertFalse($item->noStack);
        $this->assertFalse($item->orientationLock);
    }

    /**
     * Above the hard ceiling the cart is declined outright rather than sampled, so
     * quoting falls back to the legacy weight calculation. Expansion itself must not
     * be allowed to exhaust memory, and declining beats compounding two separate
     * approximations.
     */
    public function testCartsAboveTheHardCeilingAreDeclined(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['quantity' => (float)(CartPackingAdapter::HARD_UNIT_CEILING + 1)])],
            $this->context()
        );

        $this->assertTrue($request->exceedsUnitCeiling);
        $this->assertFalse($request->isPackable());
        $this->assertSame([], $request->items, 'A declined cart must not leave a partial expansion behind.');
    }

    /**
     * A cart line may be missing keys outright, not merely empty - older plugins build
     * their own product arrays, and get_products() has gained fields over time. Absent
     * must behave like empty and must not raise a notice on the quote path.
     */
    public function testCartLinesWithAbsentKeysStillConvert(): void
    {
        $line = [
            'id' => '9',
            'quantity' => 1.0,
            'length' => '4.0000',
            'width' => '4.0000',
            'height' => '4.0000',
        ];

        $request = (new CartPackingAdapter())->fromCartProducts([$line], $this->context());

        $this->assertSame(1, $request->unitCount());
        $item = $request->items[0];
        $this->assertSame(0.0, $item->weight->value, 'An absent weight is zero, not a notice.');
        $this->assertSame(Units::INCHES, $item->dimensions->units);
        $this->assertSame(Units::POUNDS, $item->weight->units);
        $this->assertFalse($item->shipsInOwnBox);
        $this->assertSame('9', $item->lineKey);
    }

    /**
     * An absent id must not be mistaken for a product, and an absent model must not be
     * mistaken for a gift certificate.
     */
    public function testAbsentIdAndModelAreHandledWithoutMisclassifying(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [['quantity' => 1.0, 'length' => '2', 'width' => '2', 'height' => '2']],
            $this->context()
        );

        $this->assertSame(1, $request->unitCount(), 'A line with no model is not a gift certificate.');
        $this->assertSame('', $request->items[0]->uprid);
    }

    /**
     * An absent quantity means there is nothing to pack, and must be excluded rather
     * than silently packed as one unit.
     */
    public function testAbsentQuantityIsExcluded(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [['id' => '1', 'length' => '2', 'width' => '2', 'height' => '2']],
            $this->context()
        );

        $this->assertSame(0, $request->unitCount());
        $this->assertSame(1, $request->excludedLineCount);
    }

    /**
     * A non-shipping line must skip only itself.
     *
     * This guards a latent bug rather than a hypothetical one: turning the skip into an
     * early exit would make one virtual product or gift certificate silently drop every
     * line after it from the parcel plan, and the store would simply be under-quoted.
     * The single-line exclusion tests above cannot see the difference.
     */
    public function testAnExcludedLineDoesNotStopLaterLinesFromPacking(): void
    {
        $products = [
            $this->line(['id' => 'virtual', 'quantity' => 1.0, 'products_virtual' => 1]),
            $this->line(['id' => 'gift', 'quantity' => 1.0, 'model' => 'GIFT50']),
            $this->line(['id' => 'freeship', 'quantity' => 1.0, 'product_is_always_free_shipping' => 1]),
            $this->line(['id' => 'real-1', 'quantity' => 2.0]),
            $this->line(['id' => 'real-2', 'quantity' => 1.0]),
        ];

        $request = (new CartPackingAdapter())->fromCartProducts($products, $this->context());

        $this->assertSame(3, $request->excludedLineCount);
        $this->assertSame(3, $request->unitCount(), 'Both shippable lines must survive the exclusions.');

        $uprids = array_values(array_unique(array_map(static fn ($i) => $i->uprid, $request->items)));
        sort($uprids);
        $this->assertSame(['real-1', 'real-2'], $uprids);
    }

    /**
     * The declared total accumulates across lines. Replacing the accumulation with an
     * assignment would leave only the last line's quantity, which in turn would make
     * the packer's sampling guard scale its extrapolation from the wrong total.
     */
    public function testDeclaredUnitTotalAccumulatesAcrossLines(): void
    {
        $products = [
            $this->line(['id' => 'a', 'quantity' => 2.0]),
            $this->line(['id' => 'b', 'quantity' => 3.0]),
            $this->line(['id' => 'c', 'quantity' => 4.0]),
        ];

        $request = (new CartPackingAdapter())->fromCartProducts($products, $this->context());

        $this->assertSame(9, $request->declaredUnitTotal);
        $this->assertSame(9, $request->unitCount());
    }

    /**
     * A download line does not ship, so it must not be treated as an unmeasured
     * physical item.
     *
     * This is the difference between a download sitting quietly in a mixed cart and a
     * download dropping the entire cart to weight-only quoting: an unexcluded,
     * dimensionless line trips the missing-dimensions policy for everything.
     */
    public function testDownloadLinesAreExcludedAndDoNotForceAFallback(): void
    {
        $products = [
            $this->line(['id' => '179', 'quantity' => 1.0, 'length' => null, 'width' => null, 'height' => null]),
            $this->line(['id' => '42', 'quantity' => 1.0]),
        ];

        $request = (new CartPackingAdapter())->fromCartProducts($products, $this->context(), [179]);

        $this->assertSame(1, $request->excludedLineCount);
        $this->assertSame(1, $request->unitCount(), 'Only the physical line is packed.');
        $this->assertSame('42', $request->items[0]->uprid);
        $this->assertFalse(
            $request->hasMissingDimensions(),
            'A download must not be reported as a product needing measurement.'
        );
    }

    /**
     * product_is_always_free_shipping = 2 is the download-plus-physical-goods case, so
     * such a line ships even though it carries a download.
     */
    public function testADownloadThatStillShipsIsNotExcluded(): void
    {
        $products = [
            $this->line(['id' => '180', 'quantity' => 1.0, 'product_is_always_free_shipping' => 2]),
        ];

        $request = (new CartPackingAdapter())->fromCartProducts($products, $this->context(), [180]);

        $this->assertSame(1, $request->unitCount(), '"Special, requires shipping" still ships.');
    }

    /**
     * The query mirrors core: per selected attribute value, and skipping lines flagged
     * as still requiring shipping.
     */
    public function testDownloadQueryCoversEachSelectedAttributeValue(): void
    {
        $sql = CartPackingAdapter::downloadLinesSql([
            $this->line(['id' => '7', 'attributes' => [3 => 11, 4 => 12]]),
            $this->line(['id' => '8', 'attributes' => [3 => 13], 'product_is_always_free_shipping' => 2]),
        ]);

        $this->assertStringContainsString('pa.products_id = 7 AND pa.options_values_id = 11', $sql);
        $this->assertStringContainsString('pa.products_id = 7 AND pa.options_values_id = 12', $sql);
        $this->assertStringNotContainsString(
            'pa.products_id = 8',
            $sql,
            'A line that still requires shipping is not a download for this purpose.'
        );
        $this->assertStringContainsString('products_attributes_download', $sql);
    }

    public function testDownloadQueryIsEmptyWhenNothingHasAttributes(): void
    {
        $this->assertSame('', CartPackingAdapter::downloadLinesSql([$this->line(['quantity' => 1.0])]));
    }

    public function testUnitsOfTheSameLineShareALineKey(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts(
            [$this->line(['id' => '42:abc', 'quantity' => 3.0])],
            $this->context()
        );

        $keys = array_unique(array_map(static fn ($i) => $i->lineKey, $request->items));
        $this->assertSame(['42:abc'], array_values($keys));
    }

    public function testEmptyCartYieldsAnUnpackableRequest(): void
    {
        $request = (new CartPackingAdapter())->fromCartProducts([], $this->context());

        $this->assertFalse($request->isPackable());
        $this->assertFalse($request->exceedsUnitCeiling);
        $this->assertSame(0, $request->declaredUnitTotal);
    }

    /**
     * A fake queryFactory that answers a statement by what it selects, not by call
     * order: the download query is skipped entirely for a cart with no attribute
     * selections, so a positional queue would hand its rows to the next query instead.
     *
     * @param array<string, array<int, array<string, string>>> $resultSets keyed by a
     *        substring of the statement they answer
     */
    private function scriptedDatabase(array $resultSets): object
    {
        return new class($resultSets) {
            /** @var string[] */
            public array $statements = [];

            public function __construct(private array $resultSets)
            {
            }

            public function Execute(string $sql): object
            {
                $this->statements[] = $sql;
                $rows = [];
                foreach ($this->resultSets as $marker => $candidate) {
                    if (str_contains($sql, (string)$marker)) {
                        $rows = $candidate;
                        break;
                    }
                }

                return new class($rows) {
                    public array $fields = [];
                    public bool $EOF = false;
                    private int $i = 0;

                    public function __construct(private array $rows)
                    {
                        $this->sync();
                    }

                    public function MoveNext(): void
                    {
                        $this->i++;
                        $this->sync();
                    }

                    private function sync(): void
                    {
                        $this->EOF = !isset($this->rows[$this->i]);
                        $this->fields = $this->rows[$this->i] ?? [];
                    }
                };
            }
        };
    }

    public function testPackingFlagsQueryAsksForTheProductsInTheCartOnly(): void
    {
        $sql = CartPackingAdapter::packingFlagsSql([
            ['id' => '12', 'quantity' => 1],
            ['id' => 12, 'quantity' => 2],
            ['id' => 0, 'quantity' => 1],
        ]);

        $this->assertStringContainsString('products_no_stack', $sql);
        $this->assertStringContainsString('products_orientation_lock', $sql);
        $this->assertMatchesRegularExpression('/products_id IN \(12\)/', $sql, 'Ids are deduplicated and cast.');
    }

    public function testPackingFlagsQueryIsEmptyForAnEmptyCart(): void
    {
        $this->assertSame('', CartPackingAdapter::packingFlagsSql([]));
    }

    /**
     * The flags are properties of the product and are absent from the cart array, so
     * without this merge the packer's no-stack and orientation-lock handling can never
     * fire, whatever the product record says.
     */
    public function testEnrichmentMergesThePackingFlagsOntoTheCartLines(): void
    {
        $db = $this->scriptedDatabase([
            'products_no_stack' => [
                ['products_id' => '7', 'products_no_stack' => '1', 'products_orientation_lock' => '0'],
            ],
        ]);

        $facts = CartPackingAdapter::enrichFromDatabase($db, [
            ['id' => 7, 'quantity' => 1, 'attributes' => []],
            ['id' => 9, 'quantity' => 1, 'attributes' => []],
        ]);

        $this->assertSame(1, $facts['products'][0]['no_stack']);
        $this->assertSame(0, $facts['products'][0]['orientation_lock']);
        $this->assertSame(0, $facts['products'][1]['no_stack'], 'A product with no row defaults to stackable.');
        $this->assertSame([], $facts['downloadProductIds']);
    }

    /**
     * The flags have to survive into the packable item, or the merge above is decoration.
     */
    public function testAnEnrichedLineProducesANoStackItem(): void
    {
        $db = $this->scriptedDatabase([
            'products_no_stack' => [
                ['products_id' => '7', 'products_no_stack' => '1', 'products_orientation_lock' => '1'],
            ],
        ]);

        $facts = CartPackingAdapter::enrichFromDatabase($db, [
            [
                'id' => 7,
                'quantity' => 1,
                'attributes' => [],
                'weight' => 1.0,
                // Deliberately not a cube: a cube has one distinct orientation whether
                // or not it is locked, so it could not show the lock doing anything.
                'length' => 6.0,
                'width' => 4.0,
                'height' => 2.0,
            ],
        ]);

        $request = (new CartPackingAdapter())->fromCartProducts(
            $facts['products'],
            $this->context(),
            $facts['downloadProductIds']
        );

        $this->assertCount(1, $request->items);
        $this->assertTrue($request->items[0]->noStack, 'products_no_stack must reach the packer.');
        $this->assertTrue($request->items[0]->orientationLock);
        $this->assertCount(2, $request->items[0]->orientations(), 'A locked item has two orientations, not six.');
    }

    public function testEnrichmentStillReportsDownloads(): void
    {
        $db = $this->scriptedDatabase([
            'products_attributes_download' => [['products_id' => '7']],
        ]);

        $facts = CartPackingAdapter::enrichFromDatabase($db, [
            ['id' => 7, 'quantity' => 1, 'attributes' => [3]],
        ]);

        $this->assertSame([7], $facts['downloadProductIds']);
    }
}
