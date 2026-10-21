<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * An axis-aligned cuboid extent: the size of an item, the interior or exterior of a
 * box, or a region of free space.
 *
 * Nothing here knows about orientation in the sense of "which way up the product
 * really is". An item's stored length/width/height are taken as given, and
 * rotations() enumerates the ways the packer is permitted to present it.
 */
final readonly class Dimensions
{
    /**
     * Tolerance for fit comparisons. Dimensions are stored as DECIMAL(8,4) and are
     * converted between units as floats, so a strict comparison would reject an item
     * that fits a box exactly - which is the single most visible way a packer can look
     * broken ("a 10 inch item will not go in my 10 inch box").
     */
    public const EPSILON = 1.0e-6;

    public function __construct(
        public float $length,
        public float $width,
        public float $height,
        public string $units = Units::INCHES
    ) {
    }

    /**
     * True when every axis is a usable positive measurement.
     *
     * Items failing this are "dimensionless" and are routed by the missing-dimensions
     * policy. Dimensions are never invented for them.
     */
    public function isUsable(): bool
    {
        return $this->length > 0.0 && $this->width > 0.0 && $this->height > 0.0;
    }

    public function volume(): float
    {
        return $this->length * $this->width * $this->height;
    }

    public function longestEdge(): float
    {
        return max($this->length, $this->width, $this->height);
    }

    public function shortestEdge(): float
    {
        return min($this->length, $this->width, $this->height);
    }

    /**
     * Carrier girth: longest edge plus twice the perimeter of the other two.
     *
     * Carriers surcharge above a threshold and refuse above a limit, and the limit is
     * carrier-specific, so the comparison value lives here and the threshold lives on
     * PackingContext.
     */
    public function girth(): float
    {
        $edges = [$this->length, $this->width, $this->height];
        sort($edges);

        return $edges[2] + 2.0 * ($edges[0] + $edges[1]);
    }

    public function withUnits(string $units): self
    {
        if (Units::canonical($units) === Units::canonical($this->units)) {
            return $this;
        }

        return new self(
            Units::convert($this->length, $this->units, $units),
            Units::convert($this->width, $this->units, $units),
            Units::convert($this->height, $this->units, $units),
            $units
        );
    }

    /**
     * Reduce every axis to model packing material and handling slack.
     *
     * The margin is a percentage of each linear axis so that it is scale-invariant: a
     * fixed absolute inset would consume a small box entirely while barely affecting a
     * large one. An absolute override is accepted for packaging whose real usable
     * interior is known.
     *
     * This is deliberately a dimensional inset rather than a cap on usable volume. A
     * volume cap does not compose with geometric placement - four 5x5x10 items tile a
     * 10x10x10 box exactly, and a 75% volume cap would reject the fourth and open a
     * second parcel for an item that demonstrably fits, penalising precisely the carts
     * that pack most efficiently.
     */
    public function inset(float $marginPercent, ?float $absoluteMargin = null): self
    {
        if ($absoluteMargin !== null) {
            $reduce = static fn (float $axis): float => max(0.0, $axis - 2.0 * $absoluteMargin);

            return new self($reduce($this->length), $reduce($this->width), $reduce($this->height), $this->units);
        }

        if ($marginPercent <= 0.0) {
            return $this;
        }

        $scale = max(0.0, 1.0 - ($marginPercent / 100.0));

        return new self($this->length * $scale, $this->width * $scale, $this->height * $scale, $this->units);
    }

    /**
     * True when this extent fits inside $container on every axis, as oriented.
     *
     * Callers that may rotate must test each candidate from rotations(); this method
     * deliberately does not rotate on the caller's behalf, so that a placement can
     * always be described by the exact Dimensions that were tested.
     */
    public function fitsWithin(self $container): bool
    {
        $other = $container->withUnits($this->units);

        return $this->length <= $other->length + self::EPSILON
            && $this->width <= $other->width + self::EPSILON
            && $this->height <= $other->height + self::EPSILON;
    }

    /**
     * The orientations the packer may present this extent in.
     *
     * Six axis-aligned permutations normally. When the height axis is locked ("this
     * side up"), only the two that keep the stored height vertical. Duplicates are
     * removed so that a cube yields one candidate rather than six, which keeps the
     * placement search deterministic and cheap for the common case of regular boxes.
     *
     * @return self[]
     */
    public function rotations(bool $lockHeight = false): array
    {
        $candidates = $lockHeight
            ? [
                [$this->length, $this->width, $this->height],
                [$this->width, $this->length, $this->height],
            ]
            : [
                [$this->length, $this->width, $this->height],
                [$this->width, $this->length, $this->height],
                [$this->length, $this->height, $this->width],
                [$this->height, $this->length, $this->width],
                [$this->width, $this->height, $this->length],
                [$this->height, $this->width, $this->length],
            ];

        $unique = [];
        foreach ($candidates as $axes) {
            $key = sprintf('%.6F|%.6F|%.6F', $axes[0], $axes[1], $axes[2]);
            if (!isset($unique[$key])) {
                $unique[$key] = new self($axes[0], $axes[1], $axes[2], $this->units);
            }
        }

        return array_values($unique);
    }

    /**
     * Stable identity used in sorting tie-breaks and plan hashing.
     */
    public function signature(): string
    {
        return sprintf('%.4F:%.4F:%.4F:%s', $this->length, $this->width, $this->height, Units::canonical($this->units));
    }

    public function toArray(): array
    {
        return [
            'length' => $this->length,
            'width' => $this->width,
            'height' => $this->height,
            'units' => $this->units,
        ];
    }
}
