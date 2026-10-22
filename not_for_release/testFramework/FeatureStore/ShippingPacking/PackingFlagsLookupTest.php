<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\FeatureStore\ShippingPacking;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\zcInProcessFeatureTestCaseStore;
use Zencart\Shipping\Packing\CartPackingAdapter;
use Zencart\Shipping\Packing\PackingSettings;

/**
 * Reading the per-product packing flags from the real schema.
 *
 * These columns shipped in the install and upgrade scripts, and the packer implements
 * both behaviors, but nothing read them: core's cart array carries ships_in_own_box and
 * not these, so every item was stackable and freely rotatable whatever the product
 * record said. A unit test cannot catch that - it mocks the rows - so the lookup is
 * exercised here, against the columns as the installer actually creates them.
 */
#[Group('parallel-candidate')]
class PackingFlagsLookupTest extends zcInProcessFeatureTestCaseStore
{
    protected $runTestInSeparateProcess = true;
    protected $preserveGlobalState = false;

    private const PRODUCT_ID = 3;

    private array $original = [];

    protected function tearDown(): void
    {
        if ($this->original !== []) {
            $this->db()->Execute(
                'UPDATE ' . TABLE_PRODUCTS . '
                    SET products_no_stack = ' . (int)$this->original['products_no_stack'] . ',
                        products_orientation_lock = ' . (int)$this->original['products_orientation_lock'] . '
                  WHERE products_id = ' . self::PRODUCT_ID
            );
            $this->original = [];
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

    private function setFlags(int $noStack, int $orientationLock): \queryFactory
    {
        $db = $this->db();

        $current = $db->Execute(
            'SELECT products_no_stack, products_orientation_lock
               FROM ' . TABLE_PRODUCTS . '
              WHERE products_id = ' . self::PRODUCT_ID
        );
        $this->assertFalse($current->EOF, 'Seeded product ' . self::PRODUCT_ID . ' is missing.');
        $this->original = $current->fields;

        $db->Execute(
            'UPDATE ' . TABLE_PRODUCTS . '
                SET products_no_stack = ' . $noStack . ',
                    products_orientation_lock = ' . $orientationLock . '
              WHERE products_id = ' . self::PRODUCT_ID
        );

        return $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cartLine(): array
    {
        return [[
            'id' => self::PRODUCT_ID,
            'quantity' => 1,
            'attributes' => [],
            'weight' => 2.0,
            'length' => 9.0,
            'width' => 6.0,
            'height' => 3.0,
        ]];
    }

    /**
     * The columns the installer creates and the columns the lookup selects must be the
     * same columns. A typo here is silent: every product simply reports stackable.
     */
    public function testTheFlagsAreReadFromTheProductRecord(): void
    {
        $db = $this->setFlags(1, 1);

        $facts = CartPackingAdapter::enrichFromDatabase($db, $this->cartLine());

        $this->assertSame(1, $facts['products'][0]['no_stack']);
        $this->assertSame(1, $facts['products'][0]['orientation_lock']);
    }

    public function testAProductWithTheFlagsOffPacksAsStackableAndFreelyRotatable(): void
    {
        $db = $this->setFlags(0, 0);

        $facts = CartPackingAdapter::enrichFromDatabase($db, $this->cartLine());
        $request = (new CartPackingAdapter())->fromCartProducts(
            $facts['products'],
            PackingSettings::contextFrom([]),
            $facts['downloadProductIds']
        );

        $this->assertFalse($request->items[0]->noStack);
        $this->assertFalse($request->items[0]->orientationLock);
        $this->assertCount(6, $request->items[0]->orientations());
    }

    /**
     * The flag has to survive the whole way into the item the packer places, not just
     * into the array the lookup returns.
     */
    public function testTheFlagsReachThePackableItem(): void
    {
        $db = $this->setFlags(1, 1);

        $facts = CartPackingAdapter::enrichFromDatabase($db, $this->cartLine());
        $request = (new CartPackingAdapter())->fromCartProducts(
            $facts['products'],
            PackingSettings::contextFrom([]),
            $facts['downloadProductIds']
        );

        $this->assertTrue($request->items[0]->noStack);
        $this->assertTrue($request->items[0]->orientationLock);
        $this->assertCount(2, $request->items[0]->orientations(), 'A locked item keeps its height axis.');
    }
}
