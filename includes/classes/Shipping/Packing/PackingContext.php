<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Everything outside the items and the box catalog that affects a plan.
 *
 * Carrier-specific values (girth limit, dimensional divisor, carrier code) are
 * supplied by the quoting module rather than read from configuration, because limits
 * differ per carrier and a store quoting several cannot express that in one
 * store-wide number. Store configuration provides the fallbacks.
 */
final readonly class PackingContext
{
    public const POLICY_LEGACY = 'legacy';
    public const POLICY_DEFAULT_BOX = 'default_box';

    public function __construct(
        public int $destinationCountryId = 0,
        public int $destinationZoneId = 0,
        public bool $isDomestic = true,
        public float $interiorMarginPercent = 5.0,
        public float $maxGirth = 0.0,
        public ?float $dimDivisor = null,
        public ?string $carrierCode = null,
        public string $missingDimensionsPolicy = self::POLICY_LEGACY,
        public ?string $defaultBoxCode = null,
        public int $maxUnits = 500,
        public string $dimUnits = Units::INCHES,
        public string $weightUnits = Units::POUNDS,
        /**
         * Opaque marker for the state of the box catalog, mixed into the plan hash so
         * that editing a box in admin cannot leave a stale memoized plan in a
         * shopper's session. In production this is max(last_modified) over the
         * enabled rows.
         */
        public string $catalogRevision = ''
    ) {
    }

    /**
     * Identity of everything in the context that can change a plan.
     *
     * destinationZoneId and destinationCountryId are included because box
     * availability depends on them; dimDivisor is included because it changes the
     * billable weight recorded on each parcel.
     */
    public function signature(): string
    {
        return implode('|', [
            (string)$this->destinationCountryId,
            (string)$this->destinationZoneId,
            $this->isDomestic ? 'dom' : 'intl',
            sprintf('%.4F', $this->interiorMarginPercent),
            sprintf('%.4F', $this->maxGirth),
            $this->dimDivisor === null ? 'null' : sprintf('%.4F', $this->dimDivisor),
            (string)$this->carrierCode,
            $this->missingDimensionsPolicy,
            (string)$this->defaultBoxCode,
            (string)$this->maxUnits,
            Units::canonical($this->dimUnits),
            Units::canonical($this->weightUnits),
            $this->catalogRevision,
        ]);
    }
}
