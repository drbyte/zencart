<?php
declare(strict_types=1);
/**
 * Dimensional shipping: shows an order's recorded parcel breakdown on the order page.
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @since ZC v3.0.0
 */

use Zencart\Shipping\Packing\OrderPackages;

/**
 * Attaches to NOTIFY_ADMIN_ORDERS_CONTENT_UNDER_PRODUCTS, which exists precisely for
 * this and echoes whatever an observer appends. No edit to admin/orders.php is needed,
 * which matters more than convenience: a core page nobody had to touch is a core page
 * that cannot be broken by this feature, and the same observer works on any version
 * carrying that notifier.
 *
 * Renders nothing at all for an order with no recorded parcels, so a store that has
 * never enabled packing sees its order page exactly as before.
 *
 * Deliberately a summary and not a second copy of the products table: which goods are
 * in which parcel belongs on the packing slip, where someone is standing at a bench
 * filling boxes. Here the questions are how many parcels were quoted, how big they
 * were, and whether the record can still be trusted.
 */
class zcObserverShippingPackingOrderDisplay extends base
{
    /** @var array<string, string>|null */
    private ?array $strings = null;

    public function __construct()
    {
        $this->attach($this, ['NOTIFY_ADMIN_ORDERS_CONTENT_UNDER_PRODUCTS']);
    }

    /**
     * @param array<string, mixed> $paramsArray
     */
    public function updateNotifyAdminOrdersContentUnderProducts(
        &$class,
        $eventID,
        $paramsArray = [],
        &$extraContent = null
    ): void {
        if (!is_string($extraContent) && $extraContent !== null) {
            return;
        }

        $ordersId = (int)($paramsArray['oID'] ?? 0);
        if ($ordersId <= 0 || !defined('TABLE_ORDERS_PACKAGES')) {
            return;
        }

        try {
            $packages = $this->packagesFor($ordersId);
            if ($packages === []) {
                return;
            }

            $extraContent .= $this->render($packages);
        } catch (\Throwable $e) {
            // An order page that will not render is a worse outcome than an order page
            // without its parcel summary.
            trigger_error(
                'Dimensional shipping: parcel summary not shown for order ' . $ordersId
                . ' (' . $e->getMessage() . ')',
                E_USER_NOTICE
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function packagesFor(int $ordersId): array
    {
        global $db;

        $packageRows = [];
        $result = $db->Execute(OrderPackages::packagesSql($ordersId));
        while (!$result->EOF) {
            $packageRows[] = $result->fields;
            $result->MoveNext();
        }

        if ($packageRows === []) {
            return [];
        }

        $contentRows = [];
        $result = $db->Execute(OrderPackages::contentsSql($ordersId));
        while (!$result->EOF) {
            $contentRows[] = $result->fields;
            $result->MoveNext();
        }

        return OrderPackages::fromRows($packageRows, $contentRows);
    }

    /**
     * Wording comes from this observer's own language file: content injected through a
     * notifier has no page file to live in.
     */
    private function text(string $key): string
    {
        if ($this->strings === null) {
            $this->strings = [];
            $directory = DIR_FS_ADMIN . DIR_WS_LANGUAGES;
            // English backs up a translation that has not been made yet, which is what
            // the rest of the admin does; a missing file must not print constant names.
            foreach ([(string)($_SESSION['language'] ?? 'english'), 'english'] as $language) {
                $file = $directory . $language . '/lang.shipping_packing_orders.php';
                if (is_file($file)) {
                    $this->strings = (array)(require $file);
                    break;
                }
            }
        }

        return (string)($this->strings[$key] ?? $key);
    }

    /**
     * @param array<int, array<string, mixed>> $packages
     */
    private function render(array $packages): string
    {
        global $order;

        $orderProducts = is_object($order) && is_array($order->products ?? null) ? $order->products : [];
        $caveat = OrderPackages::needsCaveat($packages, $orderProducts);
        $total = max(count($packages), (int)($packages[0]['count'] ?? 0));

        $html = '<div class="row"><div class="col-sm-12">'
            . '<h4>' . $this->text('PACKING_ORDERS_HEADING') . '</h4>'
            . '<p>' . sprintf($this->text('PACKING_ORDERS_SUMMARY'), $total) . '</p>';

        if ($caveat) {
            $html .= '<div class="alert alert-warning">' . $this->text('PACKING_ORDERS_CAVEAT') . '</div>';
        }

        $html .= '<table class="table table-condensed"><thead><tr>'
            . '<th>' . $this->text('PACKING_ORDERS_COLUMN_PARCEL') . '</th>'
            . '<th>' . $this->text('PACKING_ORDERS_BOX') . '</th>'
            . '<th>' . $this->text('PACKING_ORDERS_SIZE') . '</th>'
            . '<th>' . $this->text('PACKING_ORDERS_WEIGHT') . '</th>'
            . '<th>' . $this->text('PACKING_ORDERS_ITEMS') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($packages as $package) {
            $html .= '<tr><td>'
                . sprintf($this->text('PACKING_ORDERS_PARCEL'), (int)$package['sequence'], $total)
                . $this->badges($package)
                . '</td><td>' . $this->boxLabel($package)
                . '</td><td>' . $this->size($package)
                . '</td><td>' . $this->weight($package)
                . '</td><td>' . $this->itemCount($package)
                . '</td></tr>';
        }

        return $html . '</tbody></table></div></div>';
    }

    /**
     * @param array<string, mixed> $package
     */
    private function badges(array $package): string
    {
        $badges = '';
        if (($package['plan_origin'] ?? '') !== \Zencart\Shipping\Packing\PackingPersistence::ORIGIN_QUOTED) {
            $badges .= ' <span class="label label-default">'
                . $this->text('PACKING_ORDERS_BADGE_RECONSTRUCTED') . '</span>';
        }
        if (!empty($package['plan_is_stale'])) {
            $badges .= ' <span class="label label-warning">'
                . $this->text('PACKING_ORDERS_BADGE_STALE') . '</span>';
        }

        return $badges;
    }

    /**
     * @param array<string, mixed> $package
     */
    private function boxLabel(array $package): string
    {
        $code = trim((string)($package['box_code'] ?? ''));
        if ($code === '') {
            return $this->text('PACKING_ORDERS_NO_BOX');
        }

        $title = trim((string)($package['box_title'] ?? ''));

        return zen_output_string_protected($title === '' ? $code : $code . ' - ' . $title);
    }

    /**
     * @param array<string, mixed> $package
     */
    private function size(array $package): string
    {
        foreach (['length', 'width', 'height'] as $axis) {
            if (($package[$axis] ?? null) === null) {
                return '&mdash;';
            }
        }

        return number_format((float)$package['length'], 2) . ' &times; '
            . number_format((float)$package['width'], 2) . ' &times; '
            . number_format((float)$package['height'], 2) . ' '
            . zen_output_string_protected((string)$package['dim_units']);
    }

    /**
     * @param array<string, mixed> $package
     */
    private function weight(array $package): string
    {
        if (($package['weight'] ?? null) === null) {
            return '&mdash;';
        }

        return number_format((float)$package['weight'], 2) . ' '
            . zen_output_string_protected((string)$package['weight_units']);
    }

    /**
     * @param array<string, mixed> $package
     */
    private function itemCount(array $package): string
    {
        $total = 0.0;
        foreach ((array)($package['quantities'] ?? []) as $quantity) {
            $total += (float)$quantity;
        }

        return (string)(int)round($total);
    }
}
