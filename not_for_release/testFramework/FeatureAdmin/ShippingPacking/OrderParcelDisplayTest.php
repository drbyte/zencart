<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\FeatureAdmin\ShippingPacking;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\zcInProcessFeatureTestCaseAdmin;

/**
 * The parcel breakdown shown on an order, driven through admin/orders.php itself.
 *
 * It is injected through NOTIFY_ADMIN_ORDERS_CONTENT_UNDER_PRODUCTS rather than by
 * editing the page, so the thing worth proving is that the notifier really does carry
 * it onto the rendered page - an observer that silently never fires looks identical to
 * one that was never written.
 */
#[Group('parallel-candidate')]
#[Group('custom-seeder')]
class OrderParcelDisplayTest extends zcInProcessFeatureTestCaseAdmin
{
    protected $runTestInSeparateProcess = true;
    protected $preserveGlobalState = false;

    private const ORDERS_ID = 909090;
    private const PRODUCT_ID = 3;

    public function setUp(): void
    {
        parent::setUp();

        $this->runCustomSeeder('StoreWizardSeeder');
        $this->submitAdminLogin([
            'admin_name' => 'Admin',
            'admin_pass' => 'password',
        ])->assertOk();

        $this->removeFixture();
    }

    protected function tearDown(): void
    {
        $this->removeFixture();

        parent::tearDown();
    }

    private function removeFixture(): void
    {
        $db = $this->db();
        foreach ([TABLE_ORDERS_PACKAGES_PRODUCTS, TABLE_ORDERS_PACKAGES, TABLE_ORDERS_PRODUCTS, TABLE_ORDERS] as $table) {
            $db->Execute('DELETE FROM ' . $table . ' WHERE orders_id = ' . self::ORDERS_ID);
        }
    }

    private function db(): \queryFactory
    {
        if (!class_exists('queryFactory')) {
            require_once ROOTCWD . 'includes/classes/class.base.php';
            require_once ROOTCWD . 'includes/classes/db/' . DB_TYPE . '/query_factory.php';
        }
        if (!defined('USE_PCONNECT')) {
            define('USE_PCONNECT', 'false');
        }

        $db = new \queryFactory();
        $db->connect(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, USE_PCONNECT, false);
        $GLOBALS['db'] = $db;

        return $db;
    }

    private function givenAnOrder(): int
    {
        $db = $this->db();

        $db->Execute(
            'INSERT INTO ' . TABLE_ORDERS . " (orders_id, customers_id, customers_name,
                customers_street_address, customers_city, customers_state, customers_postcode,
                customers_country, customers_telephone, customers_email_address,
                delivery_name, delivery_street_address, delivery_city, delivery_state,
                delivery_postcode, delivery_country,
                billing_name, billing_street_address, billing_city, billing_state,
                billing_postcode, billing_country,
                payment_method, date_purchased, orders_status, currency, currency_value)
             VALUES (" . self::ORDERS_ID . ", 1, 'Probe Buyer',
                '1 Test St', 'Testville', 'Florida', 'T3 5T',
                'United States', '555', 'probe@example.com',
                'Probe Buyer', '1 Test St', 'Testville', 'Florida', 'T3 5T', 'United States',
                'Probe Buyer', '1 Test St', 'Testville', 'Florida', 'T3 5T', 'United States',
                'Check', now(), 1, 'USD', 1.0)"
        );

        $db->Execute(
            'INSERT INTO ' . TABLE_ORDERS_PRODUCTS . ' (orders_id, products_id, products_prid, products_model,
                products_name, products_price, final_price, products_tax, products_quantity)
             VALUES (' . self::ORDERS_ID . ', ' . self::PRODUCT_ID . ', ' . self::PRODUCT_ID . ", 'PROBE', 'Probe Product', 10.00, 10.00, 0, 2)"
        );

        $line = $db->Execute(
            'SELECT orders_products_id FROM ' . TABLE_ORDERS_PRODUCTS . '
              WHERE orders_id = ' . self::ORDERS_ID . ' LIMIT 1'
        );
        // Asserted, not assumed: an insert that fails for a missing column leaves the
        // order with no lines, and every assertion about the parcel table still passes.
        $this->assertFalse($line->EOF, 'The order fixture has no product lines.');

        return (int)$line->fields['orders_products_id'];
    }

    private function givenAParcel(int $orderLineId, string $origin = 'quoted', int $stale = 0): void
    {
        $db = $this->db();

        $db->Execute(
            'INSERT INTO ' . TABLE_ORDERS_PACKAGES . " (orders_id, package_sequence, package_count,
                box_id, box_code, box_title, quoted_length, quoted_width, quoted_height,
                quoted_weight, tare_weight, dim_units, weight_units, packer_code,
                plan_origin, plan_is_stale, packed_at)
             VALUES (" . self::ORDERS_ID . ", 1, 1, NULL, 'ZZORD', 'Order Probe Box',
                12.0000, 10.0000, 8.0000, 3.5000, 0.5000, 'inches', 'pounds', 'zc-ffd-v1',
                '" . $origin . "', " . $stale . ', now())'
        );

        $packageId = (int)$db->insert_ID();

        $db->Execute(
            'INSERT INTO ' . TABLE_ORDERS_PACKAGES_PRODUCTS . ' (orders_packages_id, orders_id,
                orders_products_id, products_quantity)
             VALUES (' . $packageId . ', ' . self::ORDERS_ID . ', ' . $orderLineId . ', 2)'
        );
    }

    private function orderPage(): \Tests\Support\InProcess\FeatureResponse
    {
        return $this->visitAdminCommand('orders&oID=' . self::ORDERS_ID . '&action=edit')->assertOk();
    }

    /**
     * An order with no recorded parcels must look exactly as it always did, or every
     * store that never enables packing pays for the feature in clutter.
     */
    public function testAnOrderWithoutParcelsShowsNothingExtra(): void
    {
        $this->givenAnOrder();

        $page = $this->orderPage();

        $this->assertStringNotContainsString('Parcels', $page->content);
        $this->assertStringNotContainsString('Order Probe Box', $page->content);
    }

    public function testParcelsAreShownOnTheOrder(): void
    {
        $lineId = $this->givenAnOrder();
        $this->givenAParcel($lineId);

        $page = $this->orderPage();

        $page->assertSee('Parcels');
        $page->assertSee('Quoted as 1 parcel(s)');
        $page->assertSee('ZZORD - Order Probe Box');
        $page->assertSee('12.00 &times; 10.00 &times; 8.00 inches');
        $page->assertSee('3.50 pounds');
        // The contents link parcel to order line; an empty quantities map renders a
        // plausible-looking table that silently knows about no goods at all.
        $page->assertSee('<td>2</td>');
        // A sprintf format reused as a column heading renders as "%1$s of %2$s" and
        // every assertion on the rows still passes.
        $this->assertStringNotContainsString('%1$s', $page->content, 'An unsubstituted format string reached the page.');
        $this->assertStringNotContainsString('%2$s', $page->content);
        $this->assertStringNotContainsString('PACKING_ORDERS_', $page->content, 'A missing string rendered as its key.');
    }

    /**
     * A quoted, current plan must carry no warning: a caveat shown on every order is a
     * caveat staff learn to ignore.
     */
    public function testAGoodPlanIsShownWithoutACaveat(): void
    {
        $lineId = $this->givenAnOrder();
        $this->givenAParcel($lineId);

        $this->assertStringNotContainsString('may no longer match the order', $this->orderPage()->content);
    }

    public function testAStalePlanIsBadgedAndCaveated(): void
    {
        $lineId = $this->givenAnOrder();
        $this->givenAParcel($lineId, 'quoted', 1);

        $page = $this->orderPage();

        $page->assertSee('Out of date');
        $page->assertSee('may no longer match the order');
    }

    public function testAReconstructedPlanSaysSo(): void
    {
        $lineId = $this->givenAnOrder();
        $this->givenAParcel($lineId, 'reconstructed');

        $page = $this->orderPage();

        $page->assertSee('Reconstructed');
        $page->assertSee('may no longer match the order');
    }

    /**
     * The quantity recorded against the parcel is what ships, so a parcel claiming
     * fewer units than the order holds has to be visible as a caveat rather than
     * reading as a complete breakdown.
     */
    public function testAParcelThatNoLongerMatchesTheOrderIsCaveated(): void
    {
        $lineId = $this->givenAnOrder();
        $this->givenAParcel($lineId);

        $this->db()->Execute(
            'UPDATE ' . TABLE_ORDERS_PRODUCTS . ' SET products_quantity = 5 WHERE orders_products_id = ' . $lineId
        );

        $this->orderPage()->assertSee('may no longer match the order');
    }
}
