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
 * The plan explainer, driven as an admin drives it.
 *
 * Every defect this page could have is a rendering defect: a language file that does
 * not return its array, a helper called with the catalog's argument order instead of
 * admin's, a plan that explains a cart the storefront would never pack. None of those
 * are visible to a unit test, so the page is exercised here end to end.
 */
#[Group('parallel-candidate')]
#[Group('custom-seeder')]
class PlanExplainerPageTest extends zcInProcessFeatureTestCaseAdmin
{
    protected $runTestInSeparateProcess = true;
    protected $preserveGlobalState = false;

    private const BOX_CODE = 'ZZEXPLAIN';
    private const PRODUCT_ID = 3;

    private array $originalProduct = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->runCustomSeeder('StoreWizardSeeder');
        $this->submitAdminLogin([
            'admin_name' => 'Admin',
            'admin_pass' => 'password',
        ])->assertOk();

        $this->db()->Execute(
            'DELETE FROM ' . TABLE_SHIPPING_BOXES . " WHERE box_code LIKE '" . self::BOX_CODE . "%'"
        );
    }

    protected function tearDown(): void
    {
        $db = $this->db();
        $db->Execute('DELETE FROM ' . TABLE_SHIPPING_BOXES . " WHERE box_code LIKE '" . self::BOX_CODE . "%'");

        if ($this->originalProduct !== []) {
            $db->Execute(
                'UPDATE ' . TABLE_PRODUCTS . '
                    SET products_length = ' . (float)$this->originalProduct['products_length'] . ',
                        products_width = ' . (float)$this->originalProduct['products_width'] . ',
                        products_height = ' . (float)$this->originalProduct['products_height'] . ',
                        products_weight = ' . (float)$this->originalProduct['products_weight'] . '
                  WHERE products_id = ' . self::PRODUCT_ID
            );
            $this->originalProduct = [];
        }

        parent::tearDown();
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

    private function givenABox(float $l = 12.0, float $w = 10.0, float $h = 8.0): void
    {
        $this->db()->Execute(
            'INSERT INTO ' . TABLE_SHIPPING_BOXES . "
                (box_code, box_title, interior_length, interior_width, interior_height,
                 exterior_length, exterior_width, exterior_height, dim_units,
                 tare_weight, weight_units, destination_scope, is_enabled, sort_order, last_modified)
             VALUES ('" . self::BOX_CODE . "', 'Explainer Box', $l, $w, $h,
                 $l, $w, $h, 'inches', 0.5, 'pounds', 'both', 1, 0, now())"
        );
    }

    private function givenAMeasuredProduct(float $l = 6.0, float $w = 4.0, float $h = 2.0): void
    {
        $db = $this->db();
        $current = $db->Execute(
            'SELECT products_length, products_width, products_height, products_weight
               FROM ' . TABLE_PRODUCTS . ' WHERE products_id = ' . self::PRODUCT_ID
        );
        $this->assertFalse($current->EOF, 'Seeded product is missing.');
        $this->originalProduct = $current->fields;

        $db->Execute(
            'UPDATE ' . TABLE_PRODUCTS . "
                SET products_length = $l, products_width = $w, products_height = $h, products_weight = 1.5
              WHERE products_id = " . self::PRODUCT_ID
        );
    }

    private function explain(array $post = []): \Tests\Support\InProcess\FeatureResponse
    {
        $page = $this->visitAdminCommand('shipping_plan_explainer')->assertOk();

        if ($post === []) {
            return $page;
        }

        return $this->submitAdminForm($page, 'explain', array_merge(['oID' => '', 'products' => ''], $post));
    }

    /**
     * The regression that broke both earlier pages on this branch: an admin language
     * file that does not return its array is a fatal on load, not a missing string.
     */
    public function testThePageLoads(): void
    {
        $page = $this->explain();

        $page->assertSee('Packing Plan Explainer');
        $page->assertSee('Settings in force');
        $page->assertSee('Order number');
    }

    public function testItSaysSoWhenNoBoxesAreDefined(): void
    {
        $this->db()->Execute('UPDATE ' . TABLE_SHIPPING_BOXES . ' SET is_enabled = 0');

        $this->explain()->assertSee('No enabled boxes are defined');

        $this->db()->Execute('UPDATE ' . TABLE_SHIPPING_BOXES . ' SET is_enabled = 1');
    }

    /**
     * The point of the page: a real plan, with the box named and the parcel counted.
     */
    public function testItExplainsAPlanForProductsTypedIn(): void
    {
        $this->givenABox();
        $this->givenAMeasuredProduct();

        $page = $this->explain(['products' => self::PRODUCT_ID . ' x 2']);

        $page->assertSee('Result');
        $page->assertSee(self::BOX_CODE);
        $page->assertSee('1 parcel(s)');
        $page->assertSee('Interior used');
        $page->assertSee('Contents');
        $page->assertSee('(#' . self::PRODUCT_ID . ')');
        // Bare uprids would make the reader look every line up by hand.
        $page->assertSee($this->productName());
    }

    private function productName(): string
    {
        $result = $this->db()->Execute(
            'SELECT products_name FROM ' . TABLE_PRODUCTS_DESCRIPTION . '
              WHERE products_id = ' . self::PRODUCT_ID . ' AND language_id = 1'
        );

        return (string)$result->fields['products_name'];
    }

    /**
     * Two items that cannot share a box must be explained as two parcels, or the page
     * is reporting arithmetic rather than packing.
     */
    public function testAnItemTooBigForTheBoxIsReportedAsUnpackable(): void
    {
        $this->givenABox(6.0, 6.0, 6.0);
        $this->givenAMeasuredProduct(20.0, 20.0, 20.0);

        $page = $this->explain(['products' => (string)self::PRODUCT_ID]);

        $page->assertSee('did not fit any box');
        $page->assertSee('Some items fit no enabled box');
    }

    public function testAProductThatDoesNotExistIsNamedRatherThanDroppedSilently(): void
    {
        $this->givenABox();

        $page = $this->explain(['products' => '999999']);

        $page->assertSee('Not found, and left out: 999999');
    }

    public function testAnOrderNumberWithNoProductsSaysSo(): void
    {
        $page = $this->explain(['oID' => '999999']);

        $page->assertSee('has no products on it');
    }

    /**
     * The page must never be mistaken for something that changes an order, so it is
     * asserted to be read-only: nothing it does writes a parcel record.
     */
    public function testExplainingWritesNothing(): void
    {
        $this->givenABox();
        $this->givenAMeasuredProduct();

        $before = $this->db()->Execute('SELECT COUNT(*) AS total FROM ' . TABLE_ORDERS_PACKAGES)->fields['total'];

        $this->explain(['products' => self::PRODUCT_ID . ' x 3']);

        $after = $this->db()->Execute('SELECT COUNT(*) AS total FROM ' . TABLE_ORDERS_PACKAGES)->fields['total'];
        $this->assertSame($before, $after, 'The explainer must not persist anything.');
    }

    /**
     * The page is useless if an upgraded store cannot reach it, and a page_key missing
     * from the scripts is invisible until someone upgrades.
     */
    public function testThePageIsRegisteredInBothSqlScripts(): void
    {
        foreach ([
            'zc_install/sql/install/mysql_zencart.sql',
            'zc_install/sql/updates/mysql_upgrade_zencart_300.sql',
        ] as $script) {
            $sql = (string)file_get_contents(ROOTCWD . $script);
            $this->assertStringContainsString('shippingPlanExplainer', $sql, $script . ' does not register the page.');
            $this->assertStringContainsString('FILENAME_SHIPPING_PLAN_EXPLAINER', $sql, $script . ' does not name the file.');
        }
    }
}
