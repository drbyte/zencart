<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Tests\Unit\testsShippingPacking;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zencart\Shipping\Packing\PackingSchema;

/**
 * Drift guard across the three places the packing schema is written.
 *
 * The same structure has to exist in the fresh-install script, in the upgrade script,
 * and in PackingSchema's DDL builders - the last of which is what a plugin installer
 * would run for a store on an earlier version. Nothing links them, so without this
 * test a column added to one would simply be missing from the others, and the failure
 * would surface as a broken install months later.
 *
 * Deliberately asserts presence of tables, columns, config keys and index names rather
 * than comparing SQL text. Byte equality would fail on harmless formatting differences
 * between the scripts and would make the test an obstacle instead of a guard.
 */
class PackingSchemaTest extends TestCase
{
    private const INSTALL_SQL = 'zc_install/sql/install/mysql_zencart.sql';
    private const UPGRADE_SQL = 'zc_install/sql/updates/mysql_upgrade_zencart_300.sql';

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function read(string $relative): string
    {
        $path = self::projectRoot() . '/' . $relative;
        if (!is_file($path)) {
            self::fail('Expected SQL script is missing: ' . $relative);
        }

        return (string)file_get_contents($path);
    }

    public static function sqlScripts(): array
    {
        return [
            'fresh install' => [self::INSTALL_SQL],
            'v3.0.0 upgrade' => [self::UPGRADE_SQL],
        ];
    }

    /**
     * @dataProvider sqlScripts
     */
    #[DataProvider('sqlScripts')]
    public function testEveryTableAndColumnAppearsInTheScript(string $script): void
    {
        $sql = self::read($script);

        foreach (PackingSchema::tables() as $table => $columns) {
            $this->assertMatchesRegularExpression(
                '/CREATE TABLE (IF NOT EXISTS )?' . preg_quote($table, '/') . '\s*\(/i',
                $sql,
                sprintf('%s does not create table %s.', $script, $table)
            );

            foreach ($columns as $column) {
                $this->assertMatchesRegularExpression(
                    '/^\s*' . preg_quote($column, '/') . '\s+\S/mi',
                    $sql,
                    sprintf('%s is missing column %s.%s.', $script, $table, $column)
                );
            }
        }
    }

    /**
     * @dataProvider sqlScripts
     */
    #[DataProvider('sqlScripts')]
    public function testProductColumnsAppearInTheScript(string $script): void
    {
        $sql = self::read($script);

        foreach (PackingSchema::productColumns() as $column) {
            $this->assertStringContainsString(
                $column,
                $sql,
                sprintf('%s is missing the products.%s column.', $script, $column)
            );
        }
    }

    /**
     * @dataProvider sqlScripts
     */
    #[DataProvider('sqlScripts')]
    public function testEveryConfigurationKeyIsInsertedByTheScript(string $script): void
    {
        $sql = self::read($script);

        foreach (PackingSchema::configKeys() as $key) {
            $this->assertStringContainsString(
                "'" . $key . "'",
                $sql,
                sprintf('%s does not insert configuration key %s.', $script, $key)
            );
        }
    }

    /**
     * The runtime DDL - what a plugin installer executes - must describe the same
     * tables, columns and indexes as the scripts.
     */
    public function testRuntimeDdlCoversEveryDeclaredTableAndColumn(): void
    {
        $ddl = implode("\n", PackingSchema::createTableStatements());

        foreach (PackingSchema::tables() as $table => $columns) {
            $this->assertStringContainsString($table, $ddl, 'Runtime DDL omits table ' . $table);
            foreach ($columns as $column) {
                $this->assertMatchesRegularExpression(
                    '/^\s*' . preg_quote($column, '/') . '\s+\S/mi',
                    $ddl,
                    sprintf('Runtime DDL omits column %s.%s.', $table, $column)
                );
            }
        }

        foreach (PackingSchema::productColumns() as $column) {
            $this->assertStringContainsString(
                $column,
                implode("\n", PackingSchema::alterProductsStatements()),
                'Runtime ALTER omits products.' . $column
            );
        }
    }

    /**
     * Index names must match between the scripts and the runtime DDL, since a plugin
     * install and a core install have to be interchangeable for a later upgrade.
     */
    public function testIndexNamesAgreeAcrossScriptsAndRuntimeDdl(): void
    {
        $indexes = [
            'idx_box_code_zen',
            'idx_enabled_scope_zen',
            'idx_orders_id_seq_zen',
            'idx_pkg_id_zen',
            'idx_orders_id_zen',
            'idx_op_id_zen',
        ];

        $sources = [
            'runtime DDL' => implode("\n", PackingSchema::createTableStatements()),
            self::INSTALL_SQL => self::read(self::INSTALL_SQL),
            self::UPGRADE_SQL => self::read(self::UPGRADE_SQL),
        ];

        foreach ($sources as $label => $sql) {
            foreach ($indexes as $index) {
                $this->assertStringContainsString($index, $sql, sprintf('%s is missing index %s.', $label, $index));
            }
        }
    }

    /**
     * Packing must be off after a fresh install and after an upgrade. An existing
     * store's quotes cannot change until its owner has defined boxes and opted in.
     */
    #[DataProvider('sqlScripts')]
    public function testPackingIsDisabledByDefault(string $script): void
    {
        $sql = self::read($script);

        $this->assertMatchesRegularExpression(
            "/'SHIPPING_PACKING_ENABLED',\s*'false'/",
            $sql,
            sprintf('%s must install SHIPPING_PACKING_ENABLED as false.', $script)
        );
    }

    /**
     * The table-name helper has to work both with the constants loaded, as at runtime,
     * and without them, as in this test - which is what lets the schema be declared in
     * one place and verified without a bootstrap.
     */
    public function testTableNameFallsBackToTheUnprefixedNameWithoutConstants(): void
    {
        $this->assertSame(
            defined('TABLE_SHIPPING_BOXES') ? (string)constant('TABLE_SHIPPING_BOXES') : 'shipping_boxes',
            PackingSchema::tableName('shipping_boxes')
        );
    }
}
