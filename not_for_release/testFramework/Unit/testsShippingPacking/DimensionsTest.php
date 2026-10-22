<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zencart\Shipping\Packing\BoxType;
use Zencart\Shipping\Packing\Dimensions;
use Zencart\Shipping\Packing\Units;
use Zencart\Shipping\Packing\Weight;

/**
 * Direct tests for the geometry arithmetic.
 *
 * These exist because mutation testing showed the packing suite proved placement
 * thoroughly while barely constraining the arithmetic underneath it: removing the
 * sort() from girth(), or swapping which edge it doubles, changed no test result. The
 * placement tests cannot catch that, because a wrong girth still produces a valid
 * arrangement - it just produces the wrong carrier surcharge.
 */
class DimensionsTest extends TestCase
{
    /**
     * Carrier girth is longest edge plus twice the perimeter of the other two, and it
     * must not depend on which axis the store happened to type into which column.
     */
    public static function girthCases(): array
    {
        return [
            'already ascending' => [4.0, 6.0, 10.0, 30.0],
            'descending' => [10.0, 6.0, 4.0, 30.0],
            'longest in the middle' => [4.0, 10.0, 6.0, 30.0],
            'cube' => [5.0, 5.0, 5.0, 25.0],
            'long and thin' => [48.0, 2.0, 2.0, 56.0],
            'oversize parcel' => [40.0, 30.0, 20.0, 140.0],
        ];
    }

    /**
     * @dataProvider girthCases
     */
    #[DataProvider('girthCases')]
    public function testGirthIsLongestEdgePlusTwiceThePerimeterOfTheOtherTwo(
        float $l,
        float $w,
        float $h,
        float $expected
    ): void {
        $this->assertSame($expected, (new Dimensions($l, $w, $h))->girth());
    }

    public function testGirthUsesTheLongestEdgeNotTheLengthColumn(): void
    {
        // If girth doubled the wrong pair, this 2x2x48 bar would read 48 + 2*(2+48) = 148
        // rather than 56, and a store would be told its parcel is oversize when it is not.
        $this->assertSame(56.0, (new Dimensions(2.0, 2.0, 48.0))->girth());
        $this->assertSame(56.0, (new Dimensions(2.0, 48.0, 2.0))->girth());
    }

    public function testLongestAndShortestEdge(): void
    {
        $d = new Dimensions(3.0, 9.0, 5.0);

        $this->assertSame(9.0, $d->longestEdge());
        $this->assertSame(3.0, $d->shortestEdge());
    }

    public function testVolume(): void
    {
        $this->assertSame(120.0, (new Dimensions(4.0, 5.0, 6.0))->volume());
    }

    /**
     * Every axis must be independently required. A box or item with any axis at zero
     * or below is a data-entry accident, not something to pack into.
     */
    public static function unusableCases(): array
    {
        return [
            'zero length' => [0.0, 4.0, 4.0],
            'zero width' => [4.0, 0.0, 4.0],
            'zero height' => [4.0, 4.0, 0.0],
            'negative length' => [-1.0, 4.0, 4.0],
            'negative width' => [4.0, -1.0, 4.0],
            'negative height' => [4.0, 4.0, -1.0],
            'all zero' => [0.0, 0.0, 0.0],
        ];
    }

    /**
     * @dataProvider unusableCases
     */
    #[DataProvider('unusableCases')]
    public function testAnyNonPositiveAxisMakesDimensionsUnusable(float $l, float $w, float $h): void
    {
        $this->assertFalse((new Dimensions($l, $w, $h))->isUsable());
    }

    public function testAllPositiveAxesAreUsable(): void
    {
        $this->assertTrue((new Dimensions(0.0001, 0.0001, 0.0001))->isUsable());
        $this->assertTrue((new Dimensions(4.0, 5.0, 6.0))->isUsable());
    }

    public function testPercentageInsetShrinksEveryAxis(): void
    {
        $inset = (new Dimensions(10.0, 20.0, 40.0))->inset(5.0);

        $this->assertSame(9.5, $inset->length);
        $this->assertSame(19.0, $inset->width);
        $this->assertSame(38.0, $inset->height);
    }

    /**
     * A percentage is scale-invariant, which is why the margin is expressed that way: a
     * fixed absolute inset would consume a small box entirely while barely touching a
     * large one.
     */
    public function testPercentageInsetIsScaleInvariant(): void
    {
        $small = (new Dimensions(4.0, 4.0, 4.0))->inset(10.0);
        $large = (new Dimensions(40.0, 40.0, 40.0))->inset(10.0);

        $this->assertSame(0.9, round($small->length / 4.0, 10));
        $this->assertSame(0.9, round($large->length / 40.0, 10));
    }

    public function testZeroPercentageInsetReturnsTheSameInstance(): void
    {
        $d = new Dimensions(10.0, 10.0, 10.0);

        $this->assertSame($d, $d->inset(0.0), 'No margin means no new object and no float drift.');
        $this->assertSame($d, $d->inset(-5.0), 'A negative margin must not inflate the interior.');
    }

    /**
     * An absolute margin is per side, so it comes off both ends of each axis. This is
     * the path a per-box interior_margin takes, and it overrides the percentage
     * entirely rather than compounding with it.
     */
    public function testAbsoluteMarginIsAppliedToBothSidesOfEachAxis(): void
    {
        $inset = (new Dimensions(10.0, 10.0, 10.0))->inset(5.0, 0.5);

        $this->assertSame(9.0, $inset->length);
        $this->assertSame(9.0, $inset->width);
        $this->assertSame(9.0, $inset->height);
    }

    /**
     * An explicit absolute margin of zero means "this box needs no allowance", and must
     * suppress the store-wide percentage rather than being treated as absent.
     */
    public function testAbsoluteMarginOfZeroSuppressesThePercentage(): void
    {
        $inset = (new Dimensions(10.0, 10.0, 10.0))->inset(25.0, 0.0);

        $this->assertSame(10.0, $inset->length);
    }

    public function testAbsoluteMarginCannotDriveAnAxisNegative(): void
    {
        $inset = (new Dimensions(4.0, 4.0, 4.0))->inset(0.0, 10.0);

        $this->assertSame(0.0, $inset->length);
        $this->assertFalse($inset->isUsable(), 'An over-inset box is unusable, never negative.');
    }

    public function testFitsWithinAcceptsAnExactFit(): void
    {
        $item = new Dimensions(10.0, 10.0, 10.0);

        $this->assertTrue($item->fitsWithin(new Dimensions(10.0, 10.0, 10.0)));
        $this->assertFalse($item->fitsWithin(new Dimensions(10.0, 10.0, 9.999)));
    }

    public function testFitsWithinComparesEachAxisIndependently(): void
    {
        $container = new Dimensions(10.0, 8.0, 6.0);

        $this->assertTrue((new Dimensions(10.0, 8.0, 6.0))->fitsWithin($container));
        $this->assertFalse((new Dimensions(11.0, 8.0, 6.0))->fitsWithin($container));
        $this->assertFalse((new Dimensions(10.0, 9.0, 6.0))->fitsWithin($container));
        $this->assertFalse((new Dimensions(10.0, 8.0, 7.0))->fitsWithin($container));
    }

    public function testFitsWithinConvertsTheContainerUnits(): void
    {
        $item = new Dimensions(4.0, 4.0, 4.0, Units::INCHES);
        $container = new Dimensions(11.0, 11.0, 11.0, Units::CENTIMETERS);

        $this->assertTrue($item->fitsWithin($container), '11cm is about 4.33in, so a 4in cube fits.');
        $this->assertFalse((new Dimensions(5.0, 5.0, 5.0, Units::INCHES))->fitsWithin($container));
    }

    public function testWithUnitsReturnsTheSameInstanceWhenUnitsAlreadyMatch(): void
    {
        $d = new Dimensions(4.0, 4.0, 4.0, Units::INCHES);

        $this->assertSame($d, $d->withUnits(Units::INCHES), 'A no-op conversion must not re-derive the values.');
        $this->assertSame($d, $d->withUnits('INCHES'), 'Unit comparison is case-insensitive.');
    }

    public function testWithUnitsConvertsEveryAxis(): void
    {
        $metric = (new Dimensions(2.54, 5.08, 7.62, Units::CENTIMETERS))->withUnits(Units::INCHES);

        $this->assertEqualsWithDelta(1.0, $metric->length, 0.0001);
        $this->assertEqualsWithDelta(2.0, $metric->width, 0.0001);
        $this->assertEqualsWithDelta(3.0, $metric->height, 0.0001);
        $this->assertSame(Units::INCHES, $metric->units);
    }

    public function testSixRotationsForThreeDistinctAxes(): void
    {
        $rotations = (new Dimensions(1.0, 2.0, 3.0))->rotations();

        $this->assertCount(6, $rotations);
        $signatures = array_map(static fn (Dimensions $d): string => $d->signature(), $rotations);
        $this->assertCount(6, array_unique($signatures), 'Rotations must be distinct.');
        foreach ($rotations as $rotation) {
            $this->assertSame(6.0, $rotation->volume(), 'Rotating cannot change volume.');
        }
    }

    public function testACubeHasOneDistinctRotation(): void
    {
        $this->assertCount(1, (new Dimensions(5.0, 5.0, 5.0))->rotations());
    }

    /**
     * A locked item may turn in the horizontal plane but never tip, so its stored
     * height has to remain the vertical axis in every candidate.
     */
    public function testLockedRotationsKeepTheStoredHeightVertical(): void
    {
        $rotations = (new Dimensions(1.0, 2.0, 3.0))->rotations(true);

        $this->assertCount(2, $rotations);
        foreach ($rotations as $rotation) {
            $this->assertSame(3.0, $rotation->height, 'A "this side up" item must not be laid down.');
        }
    }

    public function testSignatureDistinguishesOrientationAndUnits(): void
    {
        $a = new Dimensions(1.0, 2.0, 3.0);
        $b = new Dimensions(3.0, 2.0, 1.0);
        $c = new Dimensions(1.0, 2.0, 3.0, Units::CENTIMETERS);

        $this->assertNotSame($a->signature(), $b->signature());
        $this->assertNotSame($a->signature(), $c->signature());
        $this->assertSame($a->signature(), (new Dimensions(1.0, 2.0, 3.0))->signature());
    }

    /**
     * The per-box absolute margin must beat the store-wide percentage, since it exists
     * for packaging whose real usable interior is known.
     */
    public function testBoxAbsoluteMarginOverridesTheStorePercentage(): void
    {
        $box = new BoxType(
            null,
            'M',
            'M',
            new Dimensions(10.0, 10.0, 10.0),
            new Dimensions(10.0, 10.0, 10.0),
            new Weight(0.0),
            null,
            BoxType::SCOPE_BOTH,
            null,
            0.25
        );

        $this->assertSame(9.5, $box->usableInterior(50.0)->length, 'The per-box override wins outright.');
    }

    public function testBoxWithoutItsOwnMarginUsesTheStorePercentage(): void
    {
        $box = new BoxType(
            null,
            'M',
            'M',
            new Dimensions(10.0, 10.0, 10.0),
            new Dimensions(10.0, 10.0, 10.0),
            new Weight(0.0)
        );

        $this->assertSame(9.5, $box->usableInterior(5.0)->length);
    }
}
