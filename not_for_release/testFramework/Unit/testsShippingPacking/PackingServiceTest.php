<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use Zencart\Shipping\Packing\BoxRepository;
use Zencart\Shipping\Packing\BoxType;
use Zencart\Shipping\Packing\PackingContext;
use Zencart\Shipping\Packing\PackingService;
use Zencart\Shipping\Packing\PackingSettings;
use Zencart\Shipping\Packing\Units;

/**
 * The wiring between store data and the packer: catalog hydration, settings to
 * context, and the service that memoizes a plan.
 *
 * All three are exercised without a database or a bootstrap, which is the point of
 * keeping the row mapping separate from the query and the configuration reading
 * separate from the context.
 */
class PackingServiceTest extends PackingTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function boxRow(array $overrides = []): array
    {
        return array_merge([
            'box_id' => '3',
            'box_code' => 'M',
            'box_title' => 'Medium',
            'interior_length' => '12.0000',
            'interior_width' => '10.0000',
            'interior_height' => '8.0000',
            'exterior_length' => '12.5000',
            'exterior_width' => '10.5000',
            'exterior_height' => '8.5000',
            'interior_margin' => null,
            'dim_units' => 'inches',
            'tare_weight' => '0.5000',
            'max_payload_weight' => '50.0000',
            'weight_units' => 'pounds',
            'destination_scope' => 'both',
            'carrier_code' => null,
            'last_modified' => '2026-10-20 09:00:00',
        ], $overrides);
    }

    public function testCatalogRowsHydrateIntoBoxTypes(): void
    {
        $boxes = BoxRepository::hydrate([$this->boxRow()]);

        $this->assertCount(1, $boxes);
        $box = $boxes[0];
        $this->assertSame(3, $box->boxId);
        $this->assertSame('M', $box->code);
        $this->assertSame(12.0, $box->interior->length);
        $this->assertSame(12.5, $box->exterior->length);
        $this->assertSame(0.5, $box->tare->value);
        $this->assertNotNull($box->maxPayload);
        $this->assertSame(50.0, $box->maxPayload->value);
        $this->assertNull($box->carrierCode);
        $this->assertSame(BoxType::SCOPE_BOTH, $box->destinationScope);
    }

    /**
     * A row with a zero interior dimension is a data-entry accident, not a box.
     * Including it would let the packer "fit" items into nothing.
     */
    public function testRowsWithAnUnusableInteriorAreSkipped(): void
    {
        $boxes = BoxRepository::hydrate([
            $this->boxRow(['box_code' => 'BAD', 'interior_height' => '0.0000']),
            $this->boxRow(['box_code' => 'GOOD']),
        ]);

        $this->assertCount(1, $boxes);
        $this->assertSame('GOOD', $boxes[0]->code);
    }

    /**
     * A box with no recorded exterior still has to report a carrier-facing size, so it
     * falls back to the interior rather than to zero.
     */
    public function testMissingExteriorFallsBackToInterior(): void
    {
        $boxes = BoxRepository::hydrate([
            $this->boxRow(['exterior_length' => '0', 'exterior_width' => null, 'exterior_height' => '']),
        ]);

        $this->assertSame(12.0, $boxes[0]->exterior->length);
        $this->assertSame(10.0, $boxes[0]->exterior->width);
        $this->assertSame(8.0, $boxes[0]->exterior->height);
    }

    public function testZeroOrMissingPayloadBecomesUnlimited(): void
    {
        $this->assertNull(BoxRepository::hydrate([$this->boxRow(['max_payload_weight' => '0'])])[0]->maxPayload);
        $this->assertNull(BoxRepository::hydrate([$this->boxRow(['max_payload_weight' => null])])[0]->maxPayload);
        $this->assertNull(BoxRepository::hydrate([$this->boxRow(['max_payload_weight' => ''])])[0]->maxPayload);
    }

    public function testUnknownDestinationScopeFallsBackToBoth(): void
    {
        $boxes = BoxRepository::hydrate([$this->boxRow(['destination_scope' => 'martian'])]);

        $this->assertSame(BoxType::SCOPE_BOTH, $boxes[0]->destinationScope);
    }

    public function testSettingsBuildAContextWithDocumentedDefaults(): void
    {
        $context = PackingSettings::contextFrom([]);

        $this->assertSame(5.0, $context->interiorMarginPercent);
        $this->assertSame(PackingContext::POLICY_LEGACY, $context->missingDimensionsPolicy);
        $this->assertSame(500, $context->maxUnits);
        $this->assertSame(Units::INCHES, $context->dimUnits);
        $this->assertSame(Units::POUNDS, $context->weightUnits);
        $this->assertNull($context->defaultBoxCode);
    }

    public function testEnabledAndPersistFlagsReadAsStrings(): void
    {
        $this->assertFalse(PackingSettings::isEnabled([]));
        $this->assertFalse(PackingSettings::isEnabled(['SHIPPING_PACKING_ENABLED' => 'false']));
        $this->assertTrue(PackingSettings::isEnabled(['SHIPPING_PACKING_ENABLED' => 'true']));
        $this->assertTrue(PackingSettings::shouldPersist([]));
        $this->assertFalse(PackingSettings::shouldPersist(['SHIPPING_PACKING_PERSIST' => 'false']));
    }

    /**
     * Margin is a percentage of each axis, so 100 or more would leave no interior and
     * make every item unpackable. A store wanting that should switch packing off.
     */
    public function testMarginIsClampedBelowTotalInteriorLoss(): void
    {
        $this->assertSame(
            90.0,
            PackingSettings::contextFrom(['SHIPPING_PACKING_INTERIOR_MARGIN_PCT' => '150'])->interiorMarginPercent
        );
        $this->assertSame(
            0.0,
            PackingSettings::contextFrom(['SHIPPING_PACKING_INTERIOR_MARGIN_PCT' => '-5'])->interiorMarginPercent
        );
    }

    public function testMaxUnitsFloorsAtOneSoTheGuardCannotBeDisabled(): void
    {
        $this->assertSame(1, PackingSettings::contextFrom(['SHIPPING_PACKING_MAX_UNITS' => '0'])->maxUnits);
        $this->assertSame(1, PackingSettings::contextFrom(['SHIPPING_PACKING_MAX_UNITS' => '-10'])->maxUnits);
    }

    public function testInvalidPolicyFallsBackToLegacy(): void
    {
        $this->assertSame(
            PackingContext::POLICY_LEGACY,
            PackingSettings::contextFrom(['SHIPPING_PACKING_MISSING_DIMS_POLICY' => 'whatever'])
                ->missingDimensionsPolicy
        );
    }

    /**
     * A carrier module's girth limit must win over the store-wide default, because
     * ceilings differ per carrier and a store quoting several cannot express that in
     * one number.
     */
    public function testCarrierGirthOverridesTheStoreDefault(): void
    {
        $context = PackingSettings::contextFrom(
            ['SHIPPING_PACKING_MAX_GIRTH' => '130'],
            carrierMaxGirth: 165.0
        );

        $this->assertSame(165.0, $context->maxGirth);
    }

    private function cartLine(array $overrides = []): array
    {
        return array_merge([
            'id' => '1',
            'model' => 'W-1',
            'quantity' => 1.0,
            'weight' => '1.0',
            'weight_units' => Units::POUNDS,
            'dim_units' => Units::INCHES,
            'length' => '4.0000',
            'width' => '4.0000',
            'height' => '4.0000',
            'ships_in_own_box' => '0',
            'products_virtual' => 0,
            'product_is_always_free_shipping' => 0,
        ], $overrides);
    }

    public function testServiceProducesAPlanAndMemoizesItByHash(): void
    {
        $service = new PackingService();
        $context = PackingSettings::contextFrom([], catalogRevision: 'r1');
        $boxes = BoxRepository::hydrate([$this->boxRow()]);

        $plan = $service->planFor([$this->cartLine()], $boxes, $context);

        $this->assertTrue($plan->isQuotable());
        $this->assertSame(1, $plan->packageCount());
        $this->assertSame($plan, $service->recall($plan->planHash), 'The quoted plan must be recallable for persistence.');
    }

    /**
     * The memo has to be consulted, not merely populated.
     *
     * Quoting runs on the cart page, the estimator and both checkout steps, and a
     * module may re-quote, so the plan must be computed once per distinct input. This
     * asserts the packer invocation count rather than that "a plan came back", because
     * the latter passes whether or not the memo is ever read - which is exactly how
     * the memo sat unconsulted when it was first written.
     */
    public function testRepeatedQuotingOfTheSameCartPacksOnlyOnce(): void
    {
        $service = new PackingService();
        $context = PackingSettings::contextFrom([], catalogRevision: 'r1');
        $boxes = BoxRepository::hydrate([$this->boxRow()]);
        $cart = [$this->cartLine()];

        $first = $service->planFor($cart, $boxes, $context);
        $second = $service->planFor($cart, $boxes, $context);
        $third = $service->planFor($cart, $boxes, $context);

        $this->assertSame(1, $service->packCallCount(), 'The same cart must be packed once, not once per quote.');
        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    public function testAChangedCartIsPackedAgain(): void
    {
        $service = new PackingService();
        $context = PackingSettings::contextFrom([], catalogRevision: 'r1');
        $boxes = BoxRepository::hydrate([$this->boxRow()]);

        $service->planFor([$this->cartLine()], $boxes, $context);
        $service->planFor([$this->cartLine(['quantity' => 2.0])], $boxes, $context);

        $this->assertSame(2, $service->packCallCount(), 'A different cart is a different problem.');
    }

    /**
     * An admin editing a box must not leave a stale plan in a shopper's session, which
     * is what the catalog revision in the hash exists to prevent.
     */
    public function testEditingTheBoxCatalogInvalidatesTheMemo(): void
    {
        $service = new PackingService();
        $boxes = BoxRepository::hydrate([$this->boxRow()]);
        $cart = [$this->cartLine()];

        $service->planFor($cart, $boxes, PackingSettings::contextFrom([], catalogRevision: '1:2026-10-20 09:00:00'));
        $service->planFor($cart, $boxes, PackingSettings::contextFrom([], catalogRevision: '1:2026-10-21 11:00:00'));

        $this->assertSame(2, $service->packCallCount());
    }

    public function testCartLineOrderDoesNotDefeatTheMemo(): void
    {
        $service = new PackingService();
        $context = PackingSettings::contextFrom([], catalogRevision: 'r1');
        $boxes = BoxRepository::hydrate([$this->boxRow()]);

        $a = $this->cartLine(['id' => '1']);
        $b = $this->cartLine(['id' => '2', 'length' => '3.0000']);

        $service->planFor([$a, $b], $boxes, $context);
        $service->planFor([$b, $a], $boxes, $context);

        $this->assertSame(
            1,
            $service->packCallCount(),
            'The same items in a different order are the same packing problem.'
        );
    }

    /**
     * A store that switches packing on before defining any boxes must behave exactly
     * as it did before, not produce an empty or invented plan.
     */
    public function testNoBoxesDefinedFallsBackToLegacy(): void
    {
        $plan = (new PackingService())->planFor([$this->cartLine()], [], PackingSettings::contextFrom([]));

        $this->assertTrue($plan->legacyFallback);
        $this->assertFalse($plan->isPersistable());
        $this->assertNotSame('', $plan->planHash, 'Even a fallback gets an identity.');
    }

    public function testEmptyCartFallsBackToLegacy(): void
    {
        $boxes = BoxRepository::hydrate([$this->boxRow()]);

        $plan = (new PackingService())->planFor([], $boxes, PackingSettings::contextFrom([]));

        $this->assertTrue($plan->legacyFallback);
    }

    /**
     * Box units are normalized to the context before packing, so a catalog entered in
     * centimetres works in an inches store without the packer ever comparing
     * differently-scaled numbers.
     */
    public function testBoxesInForeignUnitsAreNormalized(): void
    {
        $metric = BoxRepository::hydrate([$this->boxRow([
            'dim_units' => Units::CENTIMETERS,
            'interior_length' => '30.48',
            'interior_width' => '25.4',
            'interior_height' => '20.32',
            'exterior_length' => '30.48',
            'exterior_width' => '25.4',
            'exterior_height' => '20.32',
            'weight_units' => Units::KILOGRAMS,
            'max_payload_weight' => '22.6796',
        ])]);

        $plan = (new PackingService())->planFor(
            [$this->cartLine()],
            $metric,
            PackingSettings::contextFrom([], catalogRevision: 'r1')
        );

        $this->assertSame(1, $plan->packageCount());
        $this->assertEqualsWithDelta(12.0, $plan->packages[0]->exterior->length, 0.01);
        $this->assertSame(Units::INCHES, $plan->packages[0]->exterior->units);
    }

    /**
     * The revision marker counts rows as well as taking the newest timestamp, so a
     * deletion - which does not move a maximum - still invalidates a memoized plan.
     */
    public function testRevisionMarkerChangesWhenABoxIsDeleted(): void
    {
        $two = [$this->boxRow(['box_code' => 'A']), $this->boxRow(['box_code' => 'B'])];
        $one = [$this->boxRow(['box_code' => 'A'])];

        $this->assertNotSame(
            $this->revisionFor($two),
            $this->revisionFor($one),
            'Deleting a box must change the catalog revision.'
        );
    }

    private function revisionFor(array $rows): string
    {
        $db = new class($rows) {
            public function __construct(private array $rows)
            {
            }

            public function Execute(string $sql): object
            {
                return new class($this->rows) {
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

        return BoxRepository::loadEnabled($db)['revision'];
    }
}
