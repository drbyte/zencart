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
 * The box catalog page, driven as an admin actually drives it.
 *
 * This page had a defect that every unit test passed: validate() returns null for the
 * optional fields, zen_db_perform rejects PHP null on a numeric column, and the page
 * redirected as though the box had been saved. Nothing short of submitting the form and
 * looking in the table could catch it, which is what these tests do.
 */
#[Group('parallel-candidate')]
#[Group('custom-seeder')]
class ShippingBoxesPageTest extends zcInProcessFeatureTestCaseAdmin
{
    protected $runTestInSeparateProcess = true;
    protected $preserveGlobalState = false;

    private const CODE = 'ZZADMIN';

    public function setUp(): void
    {
        parent::setUp();

        $this->runCustomSeeder('StoreWizardSeeder');
        $this->submitAdminLogin([
            'admin_name' => 'Admin',
            'admin_pass' => 'password',
        ])->assertOk();

        $this->cleanUpBoxes();
    }

    protected function tearDown(): void
    {
        $this->cleanUpBoxes();

        parent::tearDown();
    }

    private function cleanUpBoxes(): void
    {
        $this->db()->Execute(
            'DELETE FROM ' . TABLE_SHIPPING_BOXES . " WHERE box_code LIKE '" . self::CODE . "%'"
        );
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

    /**
     * @return array<string, mixed>|null
     */
    private function storedBox(string $code): ?array
    {
        $result = $this->db()->Execute(
            'SELECT * FROM ' . TABLE_SHIPPING_BOXES . " WHERE box_code = '" . $code . "'"
        );

        return $result->EOF ? null : $result->fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'box_code' => self::CODE,
            'box_title' => 'Admin Probe',
            'interior_length' => '12',
            'interior_width' => '10',
            'interior_height' => '8',
            'exterior_length' => '',
            'exterior_width' => '',
            'exterior_height' => '',
            'interior_margin' => '',
            'dim_units' => 'inches',
            'tare_weight' => '0.5',
            'max_payload_weight' => '',
            'weight_units' => 'pounds',
            'destination_scope' => 'both',
            'carrier_code' => '',
            'is_enabled' => 'on',
            'sort_order' => '10',
        ], $overrides);
    }

    /**
     * Submit the new-box form as the page renders it, so the action URL the page emits
     * is exercised too rather than being reconstructed here.
     */
    private function submitNewBoxForm(array $overrides = []): \Tests\Support\InProcess\FeatureResponse
    {
        $page = $this->visitAdminCommand('shipping_boxes&action=new')->assertOk();

        return $this->submitAdminForm($page, 'box', $this->form($overrides));
    }

    private function createBox(array $overrides = []): void
    {
        $this->submitNewBoxForm($overrides);
    }

    public function testThePageLoadsAndSaysSoWhenNoBoxesExist(): void
    {
        $this->db()->Execute('DELETE FROM ' . TABLE_SHIPPING_BOXES);

        $page = $this->visitAdminCommand('shipping_boxes')->assertOk();

        $page->assertSee('Shipping Boxes');
        $page->assertSee('No boxes defined yet');
    }

    public function testTheNewBoxFormRenders(): void
    {
        $page = $this->visitAdminCommand('shipping_boxes&action=new')->assertOk();

        $page->assertSee('Interior L');
        $page->assertSee('Exterior L');
        $page->assertSee('Max contents weight');
        $page->assertSee('Carrier only');
    }

    /**
     * The regression this file exists for: an ordinary box, every optional field blank,
     * submitted through the page.
     */
    public function testSubmittingTheFormActuallyStoresTheBox(): void
    {
        $this->createBox();

        $box = $this->storedBox(self::CODE);
        $this->assertNotNull($box, 'Submitting the new-box form must store a row.');
        $this->assertSame('Admin Probe', $box['box_title']);
        $this->assertEqualsWithDelta(12.0, (float)$box['interior_length'], 0.0001);
        $this->assertEqualsWithDelta(12.0, (float)$box['exterior_length'], 0.0001, 'Blank exterior takes the interior.');
        $this->assertNull($box['max_payload_weight'], 'Blank means no limit, stored as NULL.');
        $this->assertNull($box['carrier_code']);
        $this->assertSame(1, (int)$box['is_enabled']);
    }

    public function testASavedBoxAppearsInTheListing(): void
    {
        $this->createBox();

        $page = $this->visitAdminCommand('shipping_boxes')->assertOk();

        $page->assertSee(self::CODE);
        $page->assertSee('Admin Probe');
        $page->assertSee('No limit');
    }

    /**
     * An exterior smaller than the interior would under-report the parcel to the
     * carrier on every quote, so the page must refuse it and store nothing.
     */
    public function testAnExteriorSmallerThanTheInteriorIsRefused(): void
    {
        $page = $this->submitNewBoxForm(['exterior_length' => '5', 'exterior_width' => '5', 'exterior_height' => '5']);

        $page->assertSee('cannot be smaller than the interior');
        $this->assertNull($this->storedBox(self::CODE), 'A refused box must not be stored.');
    }

    public function testAMissingInteriorDimensionIsRefused(): void
    {
        $page = $this->submitNewBoxForm(['interior_height' => '0']);

        $page->assertSee('must be greater than zero');
        $this->assertNull($this->storedBox(self::CODE));
    }

    /**
     * The code is snapshotted onto every parcel and referenced by the default-box
     * setting, so a duplicate would make those references ambiguous.
     */
    public function testADuplicateCodeIsRefused(): void
    {
        $this->createBox();
        $this->assertNotNull($this->storedBox(self::CODE));

        $page = $this->submitNewBoxForm(['box_title' => 'Second attempt']);

        $page->assertSee('already in use');

        $result = $this->db()->Execute(
            'SELECT COUNT(*) AS c FROM ' . TABLE_SHIPPING_BOXES . " WHERE box_code = '" . self::CODE . "'"
        );
        $this->assertSame(1, (int)$result->fields['c'], 'The duplicate must not have been inserted.');
    }

    public function testTheEditFormShowsTheStoredValuesAndSaveUpdatesThem(): void
    {
        $this->createBox(['max_payload_weight' => '50']);
        $id = (int)$this->storedBox(self::CODE)['box_id'];

        $editPage = $this->visitAdminCommand('shipping_boxes&action=edit&bID=' . $id)
            ->assertOk();
        $editPage->assertSee('Admin Probe');
        $editPage->assertSee(self::CODE);

        $this->submitAdminForm($editPage, 'box', $this->form(['box_title' => 'Renamed', 'max_payload_weight' => '75']));

        $box = $this->storedBox(self::CODE);
        $this->assertSame('Renamed', $box['box_title']);
        $this->assertEqualsWithDelta(75.0, (float)$box['max_payload_weight'], 0.0001);
    }

    /**
     * Clearing a previously-set optional field has to store NULL again, not fail the
     * update and silently leave the old value.
     */
    public function testClearingAnOptionalFieldOnEditStoresNullAgain(): void
    {
        $this->createBox(['max_payload_weight' => '50', 'carrier_code' => 'usps']);
        $id = (int)$this->storedBox(self::CODE)['box_id'];

        $editPage = $this->visitAdminCommand('shipping_boxes&action=edit&bID=' . $id)->assertOk();
        $this->submitAdminForm($editPage, 'box', $this->form(['max_payload_weight' => '', 'carrier_code' => '']));

        $box = $this->storedBox(self::CODE);
        $this->assertNull($box['max_payload_weight'], 'Clearing a payload limit must store NULL.');
        $this->assertNull($box['carrier_code']);
    }

    public function testStatusCanBeToggledAndDisabledBoxesAreNotLoadedForPacking(): void
    {
        $this->createBox();
        $id = (int)$this->storedBox(self::CODE)['box_id'];

        $this->visitAdminCommand('shipping_boxes&action=setstatus&bID=' . $id);
        $this->assertSame(0, (int)$this->storedBox(self::CODE)['is_enabled']);

        $codes = array_map(
            static fn ($box) => $box->code,
            \Zencart\Shipping\Packing\BoxRepository::loadEnabled($this->db())['boxes']
        );
        $this->assertNotContains(self::CODE, $codes, 'A disabled box must not reach the packer.');

        $this->visitAdminCommand('shipping_boxes&action=setstatus&bID=' . $id);
        $this->assertSame(1, (int)$this->storedBox(self::CODE)['is_enabled']);
    }

    public function testABoxCanBeDeleted(): void
    {
        $this->createBox();
        $id = (int)$this->storedBox(self::CODE)['box_id'];

        $this->visitAdminCommand('shipping_boxes&action=deleteconfirm&bID=' . $id);

        $this->assertNull($this->storedBox(self::CODE));
    }
}
