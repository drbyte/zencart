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
 * The missing-dimensions report, driven as an admin drives it.
 *
 * The report's value is that it lists exactly the products that would stop a cart being
 * packed - no more, no less. Its exclusion rule is unit-tested against the cart adapter,
 * but that compares predicates; these tests run the real SQL against real product rows,
 * which is the only way to know the WHERE clause agrees with the predicate.
 *
 * The case worth the most here is product_is_always_free_shipping = 2. It means
 * "special, requires shipping" - a product with a download that still has a carton - so
 * it must appear. Treating it as free shipping would quietly under-report the work.
 */
#[Group('parallel-candidate')]
#[Group('custom-seeder')]
class MissingDimensionsReportPageTest extends zcInProcessFeatureTestCaseAdmin
{
    protected $runTestInSeparateProcess = true;
    protected $preserveGlobalState = false;

    /** @var array<int, array<string, mixed>> */
    private array $originals = [];

    /** @var int[] products_attributes_id values this test created */
    private array $attributeIds = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->runCustomSeeder('StoreWizardSeeder');
        $this->submitAdminLogin([
            'admin_name' => 'Admin',
            'admin_pass' => 'password',
        ])->assertOk();
    }

    protected function tearDown(): void
    {
        $db = $this->db();
        foreach ($this->originals as $id => $row) {
            $db->Execute(
                'UPDATE ' . TABLE_PRODUCTS . ' SET '
                . 'products_length = ' . $this->sqlValue($row['products_length']) . ', '
                . 'products_width = ' . $this->sqlValue($row['products_width']) . ', '
                . 'products_height = ' . $this->sqlValue($row['products_height']) . ', '
                . 'products_virtual = ' . $this->sqlValue($row['products_virtual']) . ', '
                . 'product_is_always_free_shipping = ' . $this->sqlValue($row['product_is_always_free_shipping']) . ', '
                . "products_model = '" . $db->prepare_input((string)$row['products_model']) . "' "
                . 'WHERE products_id = ' . (int)$id
            );
        }
        $this->originals = [];

        if ($this->attributeIds !== []) {
            $ids = implode(',', array_map('intval', $this->attributeIds));
            $db->Execute('DELETE FROM ' . TABLE_PRODUCTS_ATTRIBUTES . ' WHERE products_attributes_id IN (' . $ids . ')');
            $db->Execute('DELETE FROM ' . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . ' WHERE products_attributes_id IN (' . $ids . ')');
            $this->attributeIds = [];
        }

        parent::tearDown();
    }

    private function sqlValue(mixed $value): string
    {
        return $value === null ? 'NULL' : "'" . (string)$value . "'";
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
     * Remember a product's original state so tearDown can put it back, then change it.
     *
     * @param array<string, string> $set column => SQL fragment
     */
    private function mutateProduct(int $id, array $set): void
    {
        $db = $this->db();

        if (!isset($this->originals[$id])) {
            $result = $db->Execute(
                'SELECT products_length, products_width, products_height, products_virtual,
                        product_is_always_free_shipping, products_model
                 FROM ' . TABLE_PRODUCTS . ' WHERE products_id = ' . $id
            );
            $this->assertFalse($result->EOF, 'Seeded product ' . $id . ' is missing.');
            $this->originals[$id] = $result->fields;
        }

        $assignments = [];
        foreach ($set as $column => $fragment) {
            $assignments[] = $column . ' = ' . $fragment;
        }
        $db->Execute(
            'UPDATE ' . TABLE_PRODUCTS . ' SET ' . implode(', ', $assignments) . ' WHERE products_id = ' . $id
        );
    }

    /**
     * Give a product one option group, some of whose values carry a download.
     *
     * The report only joins products_attributes to products_attributes_download, so the
     * option and value rows themselves are not needed to exercise the rule.
     */
    private function addAttributeGroup(int $productId, int $optionId, int $values, int $withDownload): void
    {
        $db = $this->db();
        $next = (int)$db->Execute(
            'SELECT MAX(products_attributes_id) AS top FROM ' . TABLE_PRODUCTS_ATTRIBUTES
        )->fields['top'] + 1000;

        for ($i = 0; $i < $values; $i++) {
            $attributeId = $next + $i;
            $this->attributeIds[] = $attributeId;
            $db->Execute(
                'INSERT INTO ' . TABLE_PRODUCTS_ATTRIBUTES . '
                    (products_attributes_id, products_id, options_id, options_values_id, options_values_price)
                 VALUES (' . $attributeId . ', ' . $productId . ', ' . $optionId . ', ' . (900 + $i) . ", 0)"
            );
            if ($i < $withDownload) {
                $db->Execute(
                    'INSERT INTO ' . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . '
                        (products_attributes_id, products_attributes_filename)
                     VALUES (' . $attributeId . ", 'probe.zip')"
                );
            }
        }
    }

    private function productName(int $id): string
    {
        $result = $this->db()->Execute(
            'SELECT products_name FROM ' . TABLE_PRODUCTS_DESCRIPTION . '
             WHERE products_id = ' . $id . ' AND language_id = 1'
        );

        return (string)$result->fields['products_name'];
    }

    private function report(): \Tests\Support\InProcess\FeatureResponse
    {
        return $this->visitAdminCommand('shipping_dimensions_report')->assertOk();
    }

    public function testThePageLoads(): void
    {
        $page = $this->report();

        $page->assertSee('Products Missing Shipping Dimensions');
        $page->assertSee('need dimensions');
    }

    /**
     * The seeded catalog has no dimensions anywhere, so a measured product is the
     * exception - and must drop off the list.
     */
    public function testAMeasuredProductIsNotListedAndAnUnmeasuredOneIs(): void
    {
        $this->mutateProduct(1, [
            'products_length' => "'6.0000'",
            'products_width' => "'4.0000'",
            'products_height' => "'2.0000'",
        ]);

        $page = $this->report();

        $page->assertSee($this->productName(2));
        $this->assertStringNotContainsString(
            $this->productName(1),
            $page->content,
            'A fully measured product has nothing to do and must not be listed.'
        );
    }

    public function testVirtualProductsAreNotListed(): void
    {
        $this->mutateProduct(3, ['products_virtual' => "'1'", 'products_length' => 'NULL']);

        $this->assertStringNotContainsString(
            $this->productName(3),
            $this->report()->content,
            'A virtual product never becomes a parcel.'
        );
    }

    public function testAlwaysFreeShippingProductsAreNotListed(): void
    {
        $this->mutateProduct(4, ['product_is_always_free_shipping' => "'1'", 'products_length' => 'NULL']);

        $this->assertStringNotContainsString(
            $this->productName(4),
            $this->report()->content
        );
    }

    /**
     * The trap. 2 means "special, requires shipping", so it still needs measuring.
     */
    public function testSpecialRequiresShippingProductsAreListed(): void
    {
        $this->mutateProduct(5, ['product_is_always_free_shipping' => "'2'", 'products_length' => 'NULL']);

        $this->report()->assertSee($this->productName(5));
    }

    /**
     * A download that hangs off an attribute makes the line virtual to core, but only
     * when the customer selects that attribute - which a report cannot know. It is
     * certain only when a whole option group is downloads, because then every choice
     * carries one.
     */
    public function testAProductWhoseEveryOptionIsADownloadIsNotListed(): void
    {
        $this->mutateProduct(7, ['products_length' => 'NULL']);
        $this->addAttributeGroup(7, 701, 2, 2);

        $this->assertStringNotContainsString(
            $this->productName(7),
            $this->report()->content,
            'Every purchase of this product carries a download, so it never becomes a parcel.'
        );
    }

    /**
     * The other half of the same rule, and the one that matters: a book sold as either
     * a printed copy or an ebook still has to be measured.
     */
    public function testAProductWithOnePhysicalOptionIsStillListed(): void
    {
        $this->mutateProduct(8, ['products_length' => 'NULL']);
        $this->addAttributeGroup(8, 801, 3, 2);

        $this->report()->assertSee($this->productName(8));
    }

    /**
     * 2 means "special, requires shipping", so it ships even carrying a download.
     */
    public function testADownloadProductMarkedAsRequiringShippingIsStillListed(): void
    {
        $this->mutateProduct(9, ['products_length' => 'NULL', 'product_is_always_free_shipping' => "'2'"]);
        $this->addAttributeGroup(9, 901, 2, 2);

        $this->report()->assertSee($this->productName(9));
    }

    /**
     * The exclusions are counted on the page rather than being silent, so an owner can
     * see that the report chose to hide something instead of wondering whether it
     * missed it. A count and not a toggle: these products must never be measured.
     */
    public function testExcludedProductsAreCountedRatherThanHidden(): void
    {
        $this->mutateProduct(10, ['products_virtual' => "'1'", 'products_length' => 'NULL']);

        $page = $this->report();

        $this->assertMatchesRegularExpression(
            '/\d+ further product\(s\) have no dimensions and are not listed/',
            $page->content,
            'An exclusion the owner cannot see reads as a report that missed something.'
        );
        $this->assertStringNotContainsString($this->productName(10), $page->content);
    }

    /**
     * The product name is a link to where the dimensions are actually entered. It used
     * to point at categories.php with action=new_product, which that page stopped
     * handling when product editing moved to product.php - so the link rendered an
     * empty page and the report's one call to action went nowhere.
     */
    public function testTheProductLinkOpensTheProductEditor(): void
    {
        $this->mutateProduct(2, ['products_length' => 'NULL']);

        $page = $this->report();

        $this->assertMatchesRegularExpression(
            '/cmd=product(&amp;|&)action=new_product[^"]*pID=2\b/',
            $page->content,
            'The link has to reach the editor that actually has the dimension fields.'
        );

        // Following it is the point: a link that resolves to a page rendering nothing
        // is what this test exists to catch, and only visiting it can tell.
        $this->assertSame(1, preg_match('/href="([^"]*cmd=product[^"]*pID=2\b[^"]*)"/', $page->content, $href));
        $target = html_entity_decode($href[1]);
        $query = parse_url($target, PHP_URL_QUERY) ?? '';

        parse_str($query, $params);
        $command = (string)($params['cmd'] ?? '');
        unset($params['cmd']);

        $editor = $this->visitAdminCommand($command . '&' . http_build_query($params))->assertOk();
        $editor->assertSee('products_length');
    }

    public function testGiftCertificatesAreNotListed(): void
    {
        $this->mutateProduct(6, ['products_model' => "'GIFT100'", 'products_length' => 'NULL']);

        $page = $this->report();

        $this->assertStringNotContainsString('GIFT100', $page->content);
        $this->assertStringNotContainsString(
            $this->productName(6),
            $page->content,
            'A gift certificate never becomes a parcel.'
        );
    }

    /**
     * Any single missing axis is enough to break packing for the whole cart, so a
     * product measured on two axes still has work to do.
     */
    public function testAProductMissingOnlyOneAxisIsStillListed(): void
    {
        $this->mutateProduct(7, [
            'products_length' => "'6.0000'",
            'products_width' => "'4.0000'",
            'products_height' => 'NULL',
        ]);

        $page = $this->report();

        $page->assertSee($this->productName(7));
        $page->assertSee('Height');
    }

    /**
     * With every shippable product measured, the report must say there is nothing to do
     * rather than render an empty table.
     */
    public function testTheReportSaysSoWhenNothingIsMissing(): void
    {
        $db = $this->db();
        $before = $db->Execute('SELECT products_id FROM ' . TABLE_PRODUCTS);
        while (!$before->EOF) {
            $this->mutateProduct((int)$before->fields['products_id'], [
                'products_length' => "'5.0000'",
                'products_width' => "'5.0000'",
                'products_height' => "'5.0000'",
            ]);
            $before->MoveNext();
        }

        $page = $this->report();

        $page->assertSee('Every shippable product has dimensions');
    }

    /**
     * The page explains what the current policy means for the store, since under the
     * default a single unmeasured product reverts the whole cart to weight-only quoting.
     */
    public function testThePageExplainsTheEffectOfTheCurrentPolicy(): void
    {
        $page = $this->report();

        $this->assertMatchesRegularExpression(
            '/switched off|weight alone|default box/i',
            $page->content,
            'The report must say what the current setting means for quoting.'
        );
    }
}
