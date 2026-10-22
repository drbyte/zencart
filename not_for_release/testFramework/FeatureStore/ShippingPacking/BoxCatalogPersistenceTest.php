<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\FeatureStore\ShippingPacking;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\zcInProcessFeatureTestCaseStore;
use Zencart\Shipping\Packing\BoxForm;
use Zencart\Shipping\Packing\BoxRepository;

/**
 * Saving a box through the real schema.
 *
 * The unit tests assert the row BoxForm produces, which they did correctly while the
 * save was in fact storing nothing: validate() returns null for a box with no payload
 * limit, no carrier restriction and no per-box margin - the ordinary first box a store
 * creates - and zen_db_perform rejects PHP null on an int or decimal column. The admin
 * page then redirected as though it had worked.
 *
 * Only an insert catches that, so this is the test that guards it.
 */
#[Group('parallel-candidate')]
class BoxCatalogPersistenceTest extends zcInProcessFeatureTestCaseStore
{
    protected $runTestInSeparateProcess = true;
    protected $preserveGlobalState = false;

    private const CODE = 'ZZPROBE';

    protected function tearDown(): void
    {
        $this->db()->Execute(
            'DELETE FROM ' . TABLE_SHIPPING_BOXES . " WHERE box_code LIKE '" . self::CODE . "%'"
        );

        parent::tearDown();
    }

    private function db(): \queryFactory
    {
        if (!class_exists('queryFactory')) {
            require_once ROOTCWD . 'includes/classes/class.base.php';
            require_once ROOTCWD . 'includes/classes/db/' . DB_TYPE . '/query_factory.php';
        }
        if (!function_exists('zen_db_perform')) {
            require_once ROOTCWD . 'includes/functions/database.php';
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
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'box_code' => self::CODE,
            'box_title' => 'Probe Box',
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

    private function save(array $overrides = []): array
    {
        $db = $this->db();
        $values = BoxForm::validate($this->input($overrides))['values'];
        zen_db_perform(TABLE_SHIPPING_BOXES, BoxForm::toDatabaseRow($values));

        return ['db' => $db, 'id' => (int)$db->insert_ID(), 'values' => $values];
    }

    private function row(\queryFactory $db, string $code): array
    {
        $result = $db->Execute(
            'SELECT * FROM ' . TABLE_SHIPPING_BOXES . " WHERE box_code = '" . $code . "'"
        );
        $this->assertFalse($result->EOF, 'Box ' . $code . ' was not stored.');

        return $result->fields;
    }

    /**
     * The regression: the ordinary box, with every optional field left blank.
     */
    public function testABoxWithEveryOptionalFieldBlankIsActuallySaved(): void
    {
        $saved = $this->save();

        $this->assertGreaterThan(0, $saved['id'], 'The insert must produce an id, not fail silently.');

        $row = $this->row($saved['db'], self::CODE);
        $this->assertNull($row['max_payload_weight'], 'No payload limit stores as SQL NULL.');
        $this->assertNull($row['carrier_code']);
        $this->assertNull($row['interior_margin']);
        $this->assertEqualsWithDelta(12.0, (float)$row['interior_length'], 0.0001);
        $this->assertEqualsWithDelta(12.0, (float)$row['exterior_length'], 0.0001, 'Blank exterior takes the interior.');
        $this->assertSame(1, (int)$row['is_enabled']);
        $this->assertNotEmpty($row['last_modified'], "'now()' must survive the writer.");
    }

    public function testOptionalFieldsAreStoredWhenSupplied(): void
    {
        $saved = $this->save([
            'box_code' => self::CODE . '2',
            'max_payload_weight' => '50',
            'carrier_code' => 'usps',
            'interior_margin' => '0.25',
            'exterior_length' => '12.5',
            'exterior_width' => '10.5',
            'exterior_height' => '8.5',
            'destination_scope' => 'international',
        ]);

        $row = $this->row($saved['db'], self::CODE . '2');
        $this->assertEqualsWithDelta(50.0, (float)$row['max_payload_weight'], 0.0001);
        $this->assertSame('usps', $row['carrier_code']);
        $this->assertEqualsWithDelta(0.25, (float)$row['interior_margin'], 0.0001);
        $this->assertEqualsWithDelta(12.5, (float)$row['exterior_length'], 0.0001);
        $this->assertSame('international', $row['destination_scope']);
    }

    /**
     * An explicit zero margin means "this box needs no allowance" and must not be
     * flattened to NULL, which would silently hand the box back to the store default.
     */
    public function testAnExplicitZeroMarginIsStoredAsZeroNotNull(): void
    {
        $saved = $this->save(['box_code' => self::CODE . '3', 'interior_margin' => '0']);

        $row = $this->row($saved['db'], self::CODE . '3');
        $this->assertNotNull($row['interior_margin']);
        $this->assertEqualsWithDelta(0.0, (float)$row['interior_margin'], 0.0001);
    }

    /**
     * The round trip that matters: a box the form accepted and the database stored must
     * come back as one the packer will actually consider.
     */
    public function testASavedBoxHydratesIntoAUsablePackerBox(): void
    {
        $saved = $this->save(['box_code' => self::CODE . '4', 'max_payload_weight' => '50']);
        $db = $saved['db'];

        $catalog = BoxRepository::loadEnabled($db);
        $codes = array_map(static fn ($box) => $box->code, $catalog['boxes']);
        $this->assertContains(self::CODE . '4', $codes, 'A saved, enabled box must reach the packer.');

        foreach ($catalog['boxes'] as $box) {
            if ($box->code !== self::CODE . '4') {
                continue;
            }
            $this->assertTrue($box->interior->isUsable());
            $this->assertEqualsWithDelta(50.0, $box->maxPayload->value, 0.0001);
            $this->assertNull($box->carrierCode, 'A blank carrier means any carrier, not the string "null".');
        }
    }

    /**
     * A disabled box must not reach the packer at all.
     */
    public function testADisabledBoxIsNotLoadedForPacking(): void
    {
        $input = $this->input(['box_code' => self::CODE . '5']);
        unset($input['is_enabled']);

        $db = $this->db();
        $values = BoxForm::validate($input)['values'];
        zen_db_perform(TABLE_SHIPPING_BOXES, BoxForm::toDatabaseRow($values));

        $this->assertSame(0, (int)$this->row($db, self::CODE . '5')['is_enabled']);

        $codes = array_map(static fn ($box) => $box->code, BoxRepository::loadEnabled($db)['boxes']);
        $this->assertNotContains(self::CODE . '5', $codes);
    }
}
