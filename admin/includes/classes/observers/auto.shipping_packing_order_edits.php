<?php
declare(strict_types=1);
/**
 * Dimensional shipping: marks an order's recorded parcel breakdown stale when its lines
 * are edited in admin.
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @since ZC v3.0.0
 */

use Zencart\Shipping\Packing\OrderPackages;

/**
 * Core admin cannot edit an order's lines, so the events that invalidate a packing plan
 * are fired by the Edit Orders plugin. Attaching to them here costs nothing when that
 * plugin is absent - the notifiers simply never fire - and gives an authoritative flag
 * the moment a line actually changes, which is cheaper and more certain than comparing
 * the record against the order afterwards.
 *
 * The comparison in OrderPackages::isOutOfDate() remains the backstop, for stores
 * editing orders by some other means. This observer is the fast path, not the only one.
 *
 * Deliberately not attached to core's NOTIFY_ADMIN_ORDERS_UPDATE_ORDER_START: that
 * fires for a status or comment change, which does not alter what is in the parcels.
 * Flagging there would raise the packing slip's caveat on nearly every order ever
 * touched and teach staff to ignore it.
 */
class zcObserverShippingPackingOrderEdits extends base
{
    public function __construct()
    {
        $this->attach($this, [
            'NOTIFY_EO_PRODUCT_ADDED',
            'NOTIFY_EO_PRODUCT_CHANGED',
            'NOTIFY_EO_PRODUCT_REMOVED',
            'NOTIFY_EO_PRODUCT_ATTRIBUTE_ADDED',
        ]);
    }

    public function updateNotifyEoProductAdded(&$class, $eventID, $paramsArray = []): void
    {
        $this->markStale($paramsArray);
    }

    public function updateNotifyEoProductChanged(&$class, $eventID, $paramsArray = []): void
    {
        $this->markStale($paramsArray);
    }

    public function updateNotifyEoProductRemoved(&$class, $eventID, $paramsArray = []): void
    {
        $this->markStale($paramsArray);
    }

    /**
     * An attribute change can alter an item's dimensions, so it invalidates the plan
     * just as a quantity change does.
     */
    public function updateNotifyEoProductAttributeAdded(&$class, $eventID, $paramsArray = []): void
    {
        $this->markStale($paramsArray);
    }

    /**
     * @param array<string, mixed> $paramsArray
     */
    private function markStale(array $paramsArray): void
    {
        // Flagging a stale plan must never interfere with the edit itself. The
        // comparison backstop still protects the packing slip if this fails.
        try {
            $ordersId = $this->resolveOrderId($paramsArray);
            if ($ordersId <= 0) {
                return;
            }

            global $db;
            OrderPackages::markStale($db, $ordersId);
        } catch (\Throwable $e) {
            trigger_error(
                'Dimensional shipping: could not flag the parcel record as stale (' . $e->getMessage() . ')',
                E_USER_NOTICE
            );
        }
    }

    /**
     * The notifiers carry an orders_products_id but not the order id, and Edit Orders
     * keeps its own order id protected. The request parameter is the dependable source:
     * admin/edit_orders.php requires oID and exits without it.
     *
     * @param array<string, mixed> $paramsArray
     */
    private function resolveOrderId(array $paramsArray): int
    {
        $fromPayload = (int)($paramsArray['sql']['orders_id'] ?? 0);
        if ($fromPayload > 0) {
            return $fromPayload;
        }

        return (int)($_GET['oID'] ?? 0);
    }
}
