<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use PHPUnit\Framework\Attributes\DataProvider;
use Zencart\Shipping\Packing\CartPackingAdapter;
use Zencart\Shipping\Packing\MissingDimensionsReport;
use Zencart\Shipping\Packing\PackingContext;
use Zencart\Shipping\Packing\Units;

/**
 * The report that tells a store which products to go and measure.
 *
 * Its whole value is that it agrees with the packer about what counts. A product the
 * report omits but the adapter trips over leaves the store chasing a fallback it cannot
 * see; a product the report lists but packing ignores is busywork. The parity tests
 * below are therefore the point of this file, not an extra.
 */
class MissingDimensionsReportTest extends PackingTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function productRow(array $overrides = []): array
    {
        return array_merge([
            'products_id' => 1,
            'products_model' => 'W-1',
            'products_name' => 'Widget',
            'products_status' => 1,
            'products_length' => '4.0000',
            'products_width' => '4.0000',
            'products_height' => '4.0000',
            'products_weight' => '1.0000',
            'products_virtual' => 0,
            'product_is_always_free_shipping' => 0,
        ], $overrides);
    }

    public static function axisCases(): array
    {
        return [
            'null length' => ['products_length', null],
            'zero length' => ['products_length', '0.0000'],
            'empty length' => ['products_length', ''],
            'null width' => ['products_width', null],
            'zero width' => ['products_width', '0.0000'],
            'null height' => ['products_height', null],
            'zero height' => ['products_height', '0.0000'],
        ];
    }

    /**
     * @dataProvider axisCases
     */
    #[DataProvider('axisCases')]
    public function testEachMissingAxisIsIdentified(string $column, mixed $value): void
    {
        $missing = MissingDimensionsReport::missingAxes($this->productRow([$column => $value]));

        $this->assertSame([str_replace('products_', '', $column)], $missing);
    }

    public function testAFullyMeasuredProductHasNoMissingAxes(): void
    {
        $this->assertSame([], MissingDimensionsReport::missingAxes($this->productRow()));
    }

    public function testAllThreeAxesCanBeMissingAtOnce(): void
    {
        $row = $this->productRow([
            'products_length' => null,
            'products_width' => null,
            'products_height' => null,
        ]);

        $this->assertSame(['length', 'width', 'height'], MissingDimensionsReport::missingAxes($row));
    }

    /**
     * Enabled products are counted apart from disabled ones, because only enabled
     * products can actually appear in a cart and cause a fallback.
     */
    public function testSummaryCountsEnabledAndDisabledSeparately(): void
    {
        $summary = MissingDimensionsReport::summarize([
            $this->productRow(['products_length' => null, 'products_status' => 1]),
            $this->productRow(['products_width' => null, 'products_status' => 1]),
            $this->productRow(['products_height' => null, 'products_status' => 0]),
        ]);

        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['enabled']);
        $this->assertSame(1, $summary['disabled']);
        $this->assertSame(['length' => 1, 'width' => 1, 'height' => 1], $summary['by_axis']);
    }

    /**
     * A product with neither dimensions nor weight cannot be quoted by any method, so
     * it is worth separating from one that at least has a weight to fall back on.
     */
    public function testSummaryFlagsProductsWithNoWeightEither(): void
    {
        $summary = MissingDimensionsReport::summarize([
            $this->productRow(['products_length' => null, 'products_weight' => '2.0000']),
            $this->productRow(['products_length' => null, 'products_weight' => '0.0000']),
            $this->productRow(['products_length' => null, 'products_weight' => null]),
        ]);

        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['no_weight']);
    }

    public function testSummaryOfAnEmptyReport(): void
    {
        $summary = MissingDimensionsReport::summarize([]);

        $this->assertSame(0, $summary['total']);
        $this->assertSame(['length' => 0, 'width' => 0, 'height' => 0], $summary['by_axis']);
    }

    /**
     * The query must exclude exactly what packing ignores, and must not exclude
     * always-free-shipping value 2, which still ships.
     */
    public function testQueryExcludesOnlyWhatPackingIgnores(): void
    {
        $sql = MissingDimensionsReport::sql(1);

        $this->assertStringContainsString('products_virtual', $sql);
        $this->assertStringContainsString("NOT LIKE 'GIFT%'", $sql);
        $this->assertMatchesRegularExpression(
            '/product_is_always_free_shipping\s*<>\s*1/',
            $sql,
            'Only value 1 is excluded: 2 means "special, requires shipping" and still needs measuring.'
        );
        $this->assertMatchesRegularExpression('/language_id\s*=\s*1/', $sql);
        $this->assertStringContainsString('ORDER BY', $sql);
    }

    /**
     * A product type that cannot be added to a cart can never reach the packer, so it
     * is not work for the store to do.
     */
    public function testQueryExcludesTypesThatCannotBeAddedToACart(): void
    {
        $sql = MissingDimensionsReport::sql(1);

        $this->assertStringContainsString('product_types', $sql);
        $this->assertMatchesRegularExpression("/allow_add_to_cart\s*<>\s*'N'/", $sql);
    }

    public function testQueryLooksForAnyMissingAxisNotAllThree(): void
    {
        $sql = MissingDimensionsReport::sql(1);

        // Any one axis missing is enough to break packing for the whole cart, so the
        // axis conditions must be OR-ed, not AND-ed.
        $this->assertMatchesRegularExpression('/products_length\s+IS NULL\s+OR/i', $sql);
        $this->assertStringNotContainsString('products_length <= 0 AND', $sql);
    }

    /**
     * Parity with the cart adapter: the two must classify the same product the same way.
     *
     * This is the assertion that keeps the report honest. The rule lives in two places
     * because one reads DB rows and the other reads cart lines, and without this they
     * would drift the first time someone adjusts an exclusion.
     */
    public static function classificationCases(): array
    {
        return [
            'ordinary product' => [[], false],
            'virtual product' => [['products_virtual' => 1], true],
            'always free shipping' => [['product_is_always_free_shipping' => 1], true],
            'special requires shipping' => [['product_is_always_free_shipping' => 2], false],
            'gift certificate' => [['products_model' => 'GIFT100'], true],
            'model merely starting with G' => [['products_model' => 'GADGET-1'], false],
        ];
    }

    /**
     * @dataProvider classificationCases
     */
    #[DataProvider('classificationCases')]
    public function testReportAndCartAdapterAgreeOnWhatShips(array $overrides, bool $expectedNonShipping): void
    {
        $row = $this->productRow($overrides);

        $this->assertSame(
            $expectedNonShipping,
            MissingDimensionsReport::isNonShipping($row),
            'Report classification'
        );

        // The adapter is driven through its public entry point with an equivalent cart
        // line, so parity is asserted against real behaviour rather than a copied rule.
        $cartLine = [
            'id' => (string)$row['products_id'],
            'model' => $row['products_model'],
            'quantity' => 1.0,
            'weight' => $row['products_weight'],
            'length' => $row['products_length'],
            'width' => $row['products_width'],
            'height' => $row['products_height'],
            'products_virtual' => $row['products_virtual'],
            'product_is_always_free_shipping' => $row['product_is_always_free_shipping'],
        ];

        $request = (new CartPackingAdapter())->fromCartProducts(
            [$cartLine],
            new PackingContext(dimUnits: Units::INCHES, weightUnits: Units::POUNDS, catalogRevision: 't')
        );

        $this->assertSame(
            $expectedNonShipping,
            $request->unitCount() === 0,
            'The cart adapter must reach the same conclusion as the report.'
        );
    }

    /**
     * A product the adapter reports as dimensionless must be one the report would list.
     */
    public function testEveryProductTheAdapterCallsDimensionlessWouldBeListed(): void
    {
        $row = $this->productRow(['products_id' => 42, 'products_height' => null]);

        $request = (new CartPackingAdapter())->fromCartProducts(
            [[
                'id' => '42',
                'model' => $row['products_model'],
                'quantity' => 1.0,
                'weight' => $row['products_weight'],
                'length' => $row['products_length'],
                'width' => $row['products_width'],
                'height' => $row['products_height'],
                'products_virtual' => 0,
                'product_is_always_free_shipping' => 0,
            ]],
            new PackingContext(catalogRevision: 't')
        );

        $this->assertSame(['42'], $request->missingDimensionUprids);
        $this->assertNotSame([], MissingDimensionsReport::missingAxes($row));
    }
}
