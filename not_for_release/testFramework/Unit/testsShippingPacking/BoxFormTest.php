<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zencart\Shipping\Packing\BoxForm;
use Zencart\Shipping\Packing\BoxRepository;
use Zencart\Shipping\Packing\BoxType;
use Zencart\Shipping\Packing\Dimensions;
use Zencart\Shipping\Packing\Units;

/**
 * Validating what a store owner types into the box catalog.
 *
 * These rules are the only thing between the owner and a box the packer cannot use. A
 * row with an unusable interior is skipped silently when the catalog is loaded, so
 * catching it at entry is the difference between an error message and a box that
 * mysteriously never gets chosen.
 */
class BoxFormTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'box_code' => 'm12',
            'box_title' => 'Medium',
            'interior_length' => '12',
            'interior_width' => '10',
            'interior_height' => '8',
            'exterior_length' => '12.5',
            'exterior_width' => '10.5',
            'exterior_height' => '8.5',
            'interior_margin' => '',
            'dim_units' => Units::INCHES,
            'tare_weight' => '0.5',
            'max_payload_weight' => '50',
            'weight_units' => Units::POUNDS,
            'destination_scope' => BoxType::SCOPE_BOTH,
            'carrier_code' => '',
            'is_enabled' => 'on',
            'sort_order' => '10',
        ], $overrides);
    }

    private function errorKeys(array $result): array
    {
        return array_column($result['errors'], 'key');
    }

    public function testAValidSubmissionNormalizes(): void
    {
        $result = BoxForm::validate($this->input());

        $this->assertSame([], $result['errors']);
        $this->assertSame('M12', $result['values']['box_code'], 'Codes are upper-cased for a stable reference.');
        $this->assertSame(12.0, $result['values']['interior_length']);
        $this->assertSame(12.5, $result['values']['exterior_length']);
        $this->assertSame(50.0, $result['values']['max_payload_weight']);
        $this->assertNull($result['values']['carrier_code'], 'Blank carrier means any carrier.');
        $this->assertNull($result['values']['interior_margin']);
        $this->assertSame(1, $result['values']['is_enabled']);
        $this->assertSame(10, $result['values']['sort_order']);
    }

    public function testCodeIsRequired(): void
    {
        $this->assertContains('ERROR_BOX_CODE_REQUIRED', $this->errorKeys(BoxForm::validate($this->input(['box_code' => '  ']))));
    }

    public static function badCodes(): array
    {
        return [
            'spaces' => ['BIG BOX'],
            'slash' => ['A/B'],
            'quote' => ["O'BOX"],
            'too long' => [str_repeat('A', 33)],
        ];
    }

    /**
     * @dataProvider badCodes
     */
    #[DataProvider('badCodes')]
    public function testCodeCharactersAreRestricted(string $code): void
    {
        $this->assertContains('ERROR_BOX_CODE_INVALID', $this->errorKeys(BoxForm::validate($this->input(['box_code' => $code]))));
    }

    public function testTitleFallsBackToTheCode(): void
    {
        $result = BoxForm::validate($this->input(['box_title' => '']));

        $this->assertSame('M12', $result['values']['box_title']);
    }

    public static function missingInteriorAxes(): array
    {
        return [
            'no length' => ['interior_length'],
            'no width' => ['interior_width'],
            'no height' => ['interior_height'],
        ];
    }

    /**
     * @dataProvider missingInteriorAxes
     */
    #[DataProvider('missingInteriorAxes')]
    public function testEveryInteriorAxisIsRequired(string $field): void
    {
        $this->assertContains('ERROR_INTERIOR_REQUIRED', $this->errorKeys(BoxForm::validate($this->input([$field => '0']))));
        $this->assertContains('ERROR_INTERIOR_REQUIRED', $this->errorKeys(BoxForm::validate($this->input([$field => ''])))); 
    }

    /**
     * A box cannot be smaller outside than inside. Unchecked, this under-reports the
     * parcel to the carrier on every quote that uses the box.
     */
    public function testExteriorSmallerThanInteriorIsRejected(): void
    {
        $result = BoxForm::validate($this->input(['exterior_width' => '9']));

        $this->assertContains('ERROR_EXTERIOR_SMALLER', $this->errorKeys($result));
    }

    public function testExteriorEqualToInteriorIsAccepted(): void
    {
        $result = BoxForm::validate($this->input([
            'exterior_length' => '12',
            'exterior_width' => '10',
            'exterior_height' => '8',
        ]));

        $this->assertSame([], $result['errors']);
    }

    /**
     * A blank exterior takes the interior, matching how BoxRepository reads a row with
     * none - the parcel still has to report a size to the carrier.
     */
    public function testBlankExteriorFallsBackToTheInterior(): void
    {
        $result = BoxForm::validate($this->input([
            'exterior_length' => '',
            'exterior_width' => '0',
            'exterior_height' => null,
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame(12.0, $result['values']['exterior_length']);
        $this->assertSame(10.0, $result['values']['exterior_width']);
        $this->assertSame(8.0, $result['values']['exterior_height']);
    }

    public function testNegativeWeightsAreRejected(): void
    {
        $this->assertContains('ERROR_TARE_NEGATIVE', $this->errorKeys(BoxForm::validate($this->input(['tare_weight' => '-1']))));
        $this->assertContains('ERROR_PAYLOAD_NEGATIVE', $this->errorKeys(BoxForm::validate($this->input(['max_payload_weight' => '-5']))));
    }

    public function testZeroPayloadMeansUnlimited(): void
    {
        $result = BoxForm::validate($this->input(['max_payload_weight' => '0']));

        $this->assertSame([], $result['errors']);
        $this->assertNull($result['values']['max_payload_weight']);
    }

    /**
     * A margin that eats the interior leaves a box nothing can fit into, which the
     * owner would experience as "this box is never used".
     */
    public function testAMarginThatConsumesTheInteriorIsRejected(): void
    {
        $result = BoxForm::validate($this->input(['interior_margin' => '5', 'interior_height' => '8']));

        $this->assertContains('ERROR_MARGIN_TOO_LARGE', $this->errorKeys($result));
    }

    public function testAReasonableMarginIsAccepted(): void
    {
        $result = BoxForm::validate($this->input(['interior_margin' => '0.25']));

        $this->assertSame([], $result['errors']);
        $this->assertSame(0.25, $result['values']['interior_margin']);
    }

    public function testNegativeMarginIsRejected(): void
    {
        $this->assertContains('ERROR_MARGIN_NEGATIVE', $this->errorKeys(BoxForm::validate($this->input(['interior_margin' => '-1']))));
    }

    public function testBlankMarginMeansUseTheStorePercentage(): void
    {
        $this->assertNull(BoxForm::validate($this->input(['interior_margin' => '  ']))['values']['interior_margin']);
    }

    public function testUnknownUnitsAndScopeFallBackToDefaults(): void
    {
        $result = BoxForm::validate($this->input([
            'dim_units' => 'cubits',
            'weight_units' => 'stones',
            'destination_scope' => 'martian',
        ]));

        $this->assertSame(Units::INCHES, $result['values']['dim_units']);
        $this->assertSame(Units::POUNDS, $result['values']['weight_units']);
        $this->assertSame(BoxType::SCOPE_BOTH, $result['values']['destination_scope']);
    }

    public function testDisabledWhenTheCheckboxIsAbsent(): void
    {
        $input = $this->input();
        unset($input['is_enabled']);

        $this->assertSame(0, BoxForm::validate($input)['values']['is_enabled']);
    }

    /**
     * A comma decimal separator is what a European admin's keyboard produces, and
     * (float)'10,5' would silently become 10.
     */
    public function testCommaDecimalSeparatorIsAccepted(): void
    {
        $result = BoxForm::validate($this->input(['interior_width' => '10,5', 'exterior_width' => '11']));

        $this->assertSame([], $result['errors']);
        $this->assertSame(10.5, $result['values']['interior_width']);
    }

    public function testExteriorFromThicknessGrowsEachAxisByTwiceTheWall(): void
    {
        $exterior = BoxForm::exteriorFromThickness(new Dimensions(12.0, 10.0, 8.0), 0.25);

        $this->assertSame(['length' => 12.5, 'width' => 10.5, 'height' => 8.5], $exterior);
    }

    public function testExteriorFromThicknessIgnoresANegativeWall(): void
    {
        $exterior = BoxForm::exteriorFromThickness(new Dimensions(12.0, 10.0, 8.0), -1.0);

        $this->assertSame(['length' => 12.0, 'width' => 10.0, 'height' => 8.0], $exterior);
    }

    /**
     * The round trip that matters: anything the form accepts must survive storage and
     * come back as a box the packer will actually consider.
     */
    public function testAnAcceptedSubmissionHydratesIntoAUsableBox(): void
    {
        $values = BoxForm::validate($this->input())['values'];

        $boxes = BoxRepository::hydrate([$values + ['box_id' => 1, 'last_modified' => null]]);

        $this->assertCount(1, $boxes, 'A box the form accepted must not be skipped at load time.');
        $this->assertTrue($boxes[0]->interior->isUsable());
        $this->assertSame('M12', $boxes[0]->code);
        $this->assertSame(50.0, $boxes[0]->maxPayload->value);
    }
}
