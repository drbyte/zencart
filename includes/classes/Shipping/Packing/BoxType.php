<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * A kind of box the store actually stocks.
 *
 * Interior and exterior are both first-class and neither is derived from the other.
 * Interior is the fit constraint; exterior is what the carrier measures and bills.
 * Per-axis wall thickness varies by box and by whether the store double-walls, so
 * storing one and computing the other produces quotes that are simultaneously too
 * expensive and geometrically wrong.
 */
final readonly class BoxType
{
    public function __construct(
        public ?int $boxId,
        public string $code,
        public string $title,
        public Dimensions $interior,
        public Dimensions $exterior,
        public Weight $tare,
        public ?Weight $maxPayload = null,
        public string $destinationScope = self::SCOPE_BOTH,
        public ?string $carrierCode = null,
        public ?float $interiorMargin = null
    ) {
    }

    public const SCOPE_BOTH = 'both';
    public const SCOPE_DOMESTIC = 'domestic';
    public const SCOPE_INTERNATIONAL = 'international';

    /**
     * The interior actually available to the packer, after packing-material margin.
     *
     * A per-box absolute margin wins over the store-wide percentage, for packaging
     * whose real usable interior is known.
     */
    public function usableInterior(float $marginPercent): Dimensions
    {
        return $this->interior->inset($marginPercent, $this->interiorMargin);
    }

    public function isAvailableFor(PackingContext $context): bool
    {
        if ($this->destinationScope !== self::SCOPE_BOTH) {
            $needed = $context->isDomestic ? self::SCOPE_DOMESTIC : self::SCOPE_INTERNATIONAL;
            if ($this->destinationScope !== $needed) {
                return false;
            }
        }

        if ($this->carrierCode !== null && $context->carrierCode !== null
            && strcasecmp($this->carrierCode, $context->carrierCode) !== 0) {
            return false;
        }

        // A box whose own exterior already breaches the carrier's girth limit can
        // never yield a shippable parcel, so it is excluded before any placement work.
        if ($context->maxGirth > 0.0 && $this->exterior->girth() > $context->maxGirth + Dimensions::EPSILON) {
            return false;
        }

        return true;
    }

    public function withUnits(string $dimUnits, string $weightUnits): self
    {
        return new self(
            $this->boxId,
            $this->code,
            $this->title,
            $this->interior->withUnits($dimUnits),
            $this->exterior->withUnits($dimUnits),
            $this->tare->withUnits($weightUnits),
            $this->maxPayload?->withUnits($weightUnits),
            $this->destinationScope,
            $this->carrierCode,
            $this->interiorMargin
        );
    }

    public function signature(): string
    {
        return implode('|', [
            $this->code,
            $this->interior->signature(),
            $this->exterior->signature(),
            sprintf('%.4F', $this->tare->value),
            $this->maxPayload === null ? 'null' : sprintf('%.4F', $this->maxPayload->value),
            $this->destinationScope,
            (string)$this->carrierCode,
            $this->interiorMargin === null ? 'null' : sprintf('%.4F', $this->interiorMargin),
        ]);
    }
}
