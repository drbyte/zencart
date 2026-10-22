<?php
declare(strict_types=1);
/**
 * Dimensional shipping: records the parcel breakdown of an order, so packing slips can
 * be printed per parcel.
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @since ZC v3.0.0
 */

use Zencart\Shipping\Packing\BoxRepository;
use Zencart\Shipping\Packing\PackingPersistence;
use Zencart\Shipping\Packing\PackingPlan;
use Zencart\Shipping\Packing\PackingSchema;
use Zencart\Shipping\Packing\PackingService;
use Zencart\Shipping\Packing\PackingSettings;

/**
 * Two hooks, one write pass.
 *
 * The plan is keyed by cart uprid, but the record has to key on the order line that was
 * actually inserted, and those ids do not exist until the lines are written. So the
 * uprid-to-line-id map is collected as each line lands, and everything is written at the
 * terminal hook, by which point both the order id and every line id exist.
 *
 * Writing both tables there rather than splitting across the order-header hook is
 * deliberate: the tables are MyISAM, so there is no transaction, and a request dying
 * between two write phases would leave parcels with no contents - which renders as empty
 * packing slips.
 */
class zcObserverShippingPackingOrder extends base
{
    /**
     * uprid => orders_products_id for the order currently being created.
     *
     * @var array<string, int>
     */
    private array $lineIdsByUprid = [];

    public function __construct()
    {
        $this->attach($this, [
            'NOTIFY_ORDER_DURING_CREATE_ADDED_PRODUCT_LINE_ITEM',
            'NOTIFY_ORDER_AFTER_ORDER_CREATE_ADD_PRODUCTS',
        ]);
    }

    /**
     * Record which order line each cart uprid became.
     *
     * The notifier hands over the whole orders_products payload, which carries
     * products_prid - the uprid the plan is keyed by - alongside the generated id.
     */
    public function updateNotifyOrderDuringCreateAddedProductLineItem(
        &$class,
        $eventID,
        $paramsArray = [],
        $ordersProductsId = null
    ): void {
        $uprid = (string)($paramsArray['products_prid'] ?? '');
        $lineId = (int)($paramsArray['orders_products_id'] ?? $ordersProductsId ?? 0);

        if ($uprid === '' || $lineId <= 0) {
            return;
        }

        // First writer wins. A cart cannot hold two entries with the same uprid
        // (shopping_cart::$contents is keyed by it), but a plugin can insert extra
        // orders_products rows, and the plan only knows about the original line.
        $this->lineIdsByUprid[$uprid] ??= $lineId;
    }

    /**
     * Write the parcel breakdown, then forget the map.
     */
    public function updateNotifyOrderAfterOrderCreateAddProducts(&$class, $eventID): void
    {
        $map = $this->lineIdsByUprid;
        $this->lineIdsByUprid = [];

        try {
            $settings = $this->settings();
            if (!PackingSettings::isEnabled($settings) || !PackingSettings::shouldPersist($settings)) {
                return;
            }

            $ordersId = (int)(is_object($class) && method_exists($class, 'getOrderId') ? $class->getOrderId() : 0);
            if ($ordersId <= 0) {
                return;
            }

            [$plan, $origin] = $this->planFor($settings);
            if ($plan === null || !$plan->isPersistable()) {
                return;
            }

            // A plan describing lines the order does not contain is not a record of
            // this order. Mark it rather than presenting it as what was quoted.
            if (PackingPersistence::unmappedLineKeys($plan, $map) !== []) {
                $origin = PackingPersistence::ORIGIN_RECONSTRUCTED;
            }

            global $db;
            PackingPersistence::write(
                $plan,
                $ordersId,
                $map,
                static function (string $table, array $row) use ($db): int {
                    zen_db_perform($table, $row);

                    return (int)$db->insert_ID();
                },
                $origin,
                date('Y-m-d H:i:s')
            );
        } catch (\Throwable $e) {
            // Order creation must never fail because the parcel record could not be
            // written. A missing breakdown costs a packing slip; a thrown exception
            // here costs the customer their order.
            trigger_error(
                'Dimensional shipping: parcel record not saved for this order (' . $e->getMessage() . ')',
                E_USER_NOTICE
            );
        }
    }

    /**
     * The plan that produced the accepted quote, or a fresh one marked as rebuilt.
     *
     * Re-packing is a last resort, for an order created where no quote ran in this
     * request: an order entered or amended in admin, or any flow that builds one
     * outside a shopper session. Such a plan is a reasonable guess about how the goods
     * will be packed, but it is not what the customer was charged for, and plan_origin
     * says so.
     *
     * @return array{0: ?PackingPlan, 1: string}
     */
    private function planFor(array $settings): array
    {
        $quoted = $GLOBALS[zcObserverShippingPacking::PLAN_GLOBAL] ?? null;
        if ($quoted instanceof PackingPlan && $quoted->isPersistable()) {
            return [$quoted, PackingPersistence::ORIGIN_QUOTED];
        }

        if (empty($_SESSION['cart']) || !is_object($_SESSION['cart'])) {
            return [null, PackingPersistence::ORIGIN_RECONSTRUCTED];
        }

        global $db, $order;

        $catalog = BoxRepository::loadEnabled($db);
        $countryId = (int)($order->delivery['country']['id'] ?? $order->delivery['country_id'] ?? 0);
        $storeCountry = (int)(defined('STORE_COUNTRY') ? STORE_COUNTRY : 0);

        $context = PackingSettings::contextFrom(
            $settings,
            destinationCountryId: $countryId,
            destinationZoneId: (int)($order->delivery['zone_id'] ?? 0),
            isDomestic: $countryId === 0 || $countryId === $storeCountry,
            catalogRevision: $catalog['revision']
        );

        $plan = (new PackingService())->planFor($_SESSION['cart']->get_products(), $catalog['boxes'], $context);

        return [$plan, PackingPersistence::ORIGIN_RECONSTRUCTED];
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $settings = [];
        foreach (array_merge(PackingSchema::configKeys(), ['SHIPPING_DIMENSION_UNITS', 'SHIPPING_WEIGHT_UNITS']) as $key) {
            $settings[$key] = zen_config($key, null);
        }

        return $settings;
    }
}
