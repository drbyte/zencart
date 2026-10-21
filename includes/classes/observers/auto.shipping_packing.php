<?php
declare(strict_types=1);
/**
 * Dimensional shipping: builds a packing plan for the current cart and publishes it
 * for shipping modules that can quote per parcel.
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @since ZC v3.0.0
 */

use Zencart\Shipping\Packing\BoxRepository;
use Zencart\Shipping\Packing\PackingPlan;
use Zencart\Shipping\Packing\PackingService;
use Zencart\Shipping\Packing\PackingSettings;

/**
 * Deliberately changes nothing about existing quoting.
 *
 * It attaches to NOTIFY_SHIPPING_MODULE_CALCULATE_BOXES_AND_TARE, which fires *after*
 * the legacy weight-and-tare calculation has already run, and it never sets
 * $abort_legacy_calculations. So $shipping_weight and $shipping_num_boxes keep exactly
 * the values they would have had with this feature absent.
 *
 * That matters because every legacy shipping module multiplies its cost by
 * $shipping_num_boxes - table.php, zones.php and perweightunit.php all do - and that
 * value is almost always 1 today, since it only rises past SHIPPING_MAX_WEIGHT. Had
 * the plan replaced those globals, a correct answer of "3 parcels" would have tripled
 * flat-rate and table-rate quotes on stores that never asked for dimensional
 * shipping. Publishing the plan alongside the legacy values instead of in place of
 * them removes that hazard by construction rather than by a per-module switch, and it
 * needs no change to includes/classes/shipping.php.
 *
 * A module opts in simply by reading $GLOBALS['shipping_packages']. The contract for
 * doing so is: quote from the plan, and do not write to $shipping_num_boxes.
 * shipping::quote() saves and restores $shipping_weight around each module call but
 * not $shipping_num_boxes, so a module that set it would leak the value into every
 * module quoted after it.
 */
class zcObserverShippingPacking extends base
{
    /**
     * Global that carries the plan to shipping modules. Also read by the order
     * observer that records parcels, so the plan persisted is the one that was quoted.
     */
    public const PLAN_GLOBAL = 'shipping_packages';

    private PackingService $service;

    public function __construct()
    {
        $this->service = new PackingService();

        $this->attach($this, [
            'NOTIFY_SHIPPING_MODULE_CALCULATE_BOXES_AND_TARE',
        ]);
    }

    /**
     * Compute and publish the plan.
     *
     * Parameters are accepted untyped and never written to. The notifier hands over
     * $total_weight, $shipping_weight, $shipping_quoted and $shipping_num_boxes; this
     * observer reads none of them and modifies none of them.
     */
    public function updateNotifyShippingModuleCalculateBoxesAndTare(
        &$class,
        $eventID,
        $paramsArray = [],
        $totalWeight = null,
        $shippingWeight = null,
        $shippingQuoted = null,
        $shippingNumBoxes = null
    ): void {
        $GLOBALS[self::PLAN_GLOBAL] = $this->buildPlan();
    }

    private function buildPlan(): ?PackingPlan
    {
        $settings = $this->settings();
        if (!PackingSettings::isEnabled($settings)) {
            return null;
        }

        // Quoting must never be broken by a packing problem. This runs on the cart
        // page, the estimator and both checkout steps, so a failure here has to leave
        // the store quoting exactly as it did before rather than showing an error.
        try {
            global $db, $order;

            if (empty($_SESSION['cart']) || !is_object($_SESSION['cart'])) {
                return null;
            }

            $catalog = BoxRepository::loadEnabled($db);

            $countryId = (int)($order->delivery['country']['id'] ?? $order->delivery['country_id'] ?? 0);
            $storeCountry = (int)(defined('STORE_COUNTRY') ? STORE_COUNTRY : 0);

            $context = PackingSettings::contextFrom(
                $settings,
                destinationCountryId: $countryId,
                destinationZoneId: (int)($order->delivery['zone_id'] ?? 0),
                // An unknown destination is treated as domestic, matching how the rest
                // of shipping behaves before an address is known.
                isDomestic: $countryId === 0 || $countryId === $storeCountry,
                catalogRevision: $catalog['revision']
            );

            return $this->service->planFor($_SESSION['cart']->get_products(), $catalog['boxes'], $context);
        } catch (\Throwable $e) {
            trigger_error(
                'Dimensional shipping: packing skipped for this quote (' . $e->getMessage() . ')',
                E_USER_NOTICE
            );

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $keys = [
            'SHIPPING_PACKING_ENABLED',
            'SHIPPING_PACKING_INTERIOR_MARGIN_PCT',
            'SHIPPING_PACKING_MISSING_DIMS_POLICY',
            'SHIPPING_PACKING_DEFAULT_BOX_CODE',
            'SHIPPING_PACKING_MAX_UNITS',
            'SHIPPING_PACKING_MAX_GIRTH',
            'SHIPPING_PACKING_PERSIST',
            'SHIPPING_DIMENSION_UNITS',
            'SHIPPING_WEIGHT_UNITS',
        ];

        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = zen_config($key, null);
        }

        return $settings;
    }
}
