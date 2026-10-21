<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * The authoritative description of the packing schema.
 *
 * Exists because the same schema has to be installable two ways: by the core SQL
 * scripts under zc_install/, and by a plugin's ScriptedInstaller for stores on an
 * earlier version. Declaring the structure once, here, lets a test assert that the
 * core scripts still match it, so the two cannot drift apart silently.
 *
 * The structure accessors are pure and need no bootstrap. Only the DDL builders read
 * the TABLE_* constants, and they fall back to the unprefixed name when those are
 * absent so the class remains usable in a unit test.
 */
final class PackingSchema
{
    /**
     * Columns of each new table, in declaration order.
     *
     * @return array<string, string[]>
     */
    public static function tables(): array
    {
        return [
            'shipping_boxes' => [
                'box_id',
                'box_code',
                'box_title',
                'interior_length',
                'interior_width',
                'interior_height',
                'exterior_length',
                'exterior_width',
                'exterior_height',
                'interior_margin',
                'dim_units',
                'tare_weight',
                'max_payload_weight',
                'weight_units',
                'destination_scope',
                'carrier_code',
                'is_enabled',
                'sort_order',
                'last_modified',
            ],
            'orders_packages' => [
                'orders_packages_id',
                'orders_id',
                'package_sequence',
                'package_count',
                'box_id',
                'box_code',
                'box_title',
                'quoted_length',
                'quoted_width',
                'quoted_height',
                'quoted_weight',
                'quoted_billable_weight',
                'tare_weight',
                'dim_units',
                'weight_units',
                'packer_code',
                'plan_origin',
                'plan_is_stale',
                'packed_at',
            ],
            'orders_packages_products' => [
                'orders_packages_products_id',
                'orders_packages_id',
                'orders_id',
                'orders_products_id',
                'products_quantity',
            ],
        ];
    }

    /**
     * Columns added to the existing products table.
     *
     * @return string[]
     */
    public static function productColumns(): array
    {
        return ['products_no_stack', 'products_orientation_lock'];
    }

    /**
     * Configuration keys the subsystem reads.
     *
     * @return string[]
     */
    public static function configKeys(): array
    {
        return [
            'SHIPPING_PACKING_ENABLED',
            'SHIPPING_PACKING_INTERIOR_MARGIN_PCT',
            'SHIPPING_PACKING_MISSING_DIMS_POLICY',
            'SHIPPING_PACKING_DEFAULT_BOX_CODE',
            'SHIPPING_PACKING_MAX_UNITS',
            'SHIPPING_PACKING_MAX_GIRTH',
            'SHIPPING_PACKING_PERSIST',
        ];
    }

    /**
     * Resolve a logical table name to its runtime name, honouring the configured
     * prefix when the constants are loaded.
     */
    public static function tableName(string $logicalName): string
    {
        $constant = 'TABLE_' . strtoupper($logicalName);

        return defined($constant) ? (string)constant($constant) : $logicalName;
    }

    /**
     * CREATE TABLE statements for an installer that runs through the database layer.
     *
     * MyISAM and no foreign keys, matching the 104 of 105 core tables that are already
     * MyISAM; referential integrity stays in code, as it does everywhere else here.
     * DECIMAL(8,4) matches the existing products_length family exactly, so a dimension
     * copied from a product into a parcel record never changes value.
     *
     * @return string[]
     */
    public static function createTableStatements(): array
    {
        $boxes = self::tableName('shipping_boxes');
        $packages = self::tableName('orders_packages');
        $contents = self::tableName('orders_packages_products');

        return [
            "CREATE TABLE IF NOT EXISTS $boxes (
  box_id int(11) NOT NULL auto_increment,
  box_code varchar(32) NOT NULL default '',
  box_title varchar(64) NOT NULL default '',
  interior_length decimal(8,4) NOT NULL default 0,
  interior_width decimal(8,4) NOT NULL default 0,
  interior_height decimal(8,4) NOT NULL default 0,
  exterior_length decimal(8,4) NOT NULL default 0,
  exterior_width decimal(8,4) NOT NULL default 0,
  exterior_height decimal(8,4) NOT NULL default 0,
  interior_margin decimal(8,4) default NULL,
  dim_units varchar(16) NOT NULL default 'inches',
  tare_weight decimal(8,4) NOT NULL default 0,
  max_payload_weight decimal(8,4) default NULL,
  weight_units varchar(16) NOT NULL default 'pounds',
  destination_scope varchar(16) NOT NULL default 'both',
  carrier_code varchar(32) default NULL,
  is_enabled tinyint(1) NOT NULL default 1,
  sort_order int(11) NOT NULL default 0,
  last_modified datetime default NULL,
  PRIMARY KEY (box_id),
  UNIQUE KEY idx_box_code_zen (box_code),
  KEY idx_enabled_scope_zen (is_enabled, destination_scope)
) ENGINE=MyISAM",

            "CREATE TABLE IF NOT EXISTS $packages (
  orders_packages_id int(11) NOT NULL auto_increment,
  orders_id int(11) NOT NULL default 0,
  package_sequence int(5) NOT NULL default 1,
  package_count int(5) NOT NULL default 1,
  box_id int(11) default NULL,
  box_code varchar(32) NOT NULL default '',
  box_title varchar(64) NOT NULL default '',
  quoted_length decimal(8,4) default NULL,
  quoted_width decimal(8,4) default NULL,
  quoted_height decimal(8,4) default NULL,
  quoted_weight decimal(8,4) default NULL,
  quoted_billable_weight decimal(8,4) default NULL,
  tare_weight decimal(8,4) default NULL,
  dim_units varchar(16) NOT NULL default '',
  weight_units varchar(16) NOT NULL default '',
  packer_code varchar(32) NOT NULL default '',
  plan_origin varchar(16) NOT NULL default 'quoted',
  plan_is_stale tinyint(1) NOT NULL default 0,
  packed_at datetime default NULL,
  PRIMARY KEY (orders_packages_id),
  KEY idx_orders_id_seq_zen (orders_id, package_sequence)
) ENGINE=MyISAM",

            "CREATE TABLE IF NOT EXISTS $contents (
  orders_packages_products_id int(11) NOT NULL auto_increment,
  orders_packages_id int(11) NOT NULL default 0,
  orders_id int(11) NOT NULL default 0,
  orders_products_id int(11) NOT NULL default 0,
  products_quantity float NOT NULL default 0,
  PRIMARY KEY (orders_packages_products_id),
  KEY idx_pkg_id_zen (orders_packages_id),
  KEY idx_orders_id_zen (orders_id),
  KEY idx_op_id_zen (orders_products_id)
) ENGINE=MyISAM",
        ];
    }

    /**
     * Column additions for the products table, as an installer would apply them.
     *
     * Both default to 0 so existing rows and existing behaviour are unchanged.
     *
     * @return string[]
     */
    public static function alterProductsStatements(): array
    {
        $products = self::tableName('products');

        return [
            "ALTER TABLE $products ADD products_no_stack tinyint(1) NOT NULL default 0",
            "ALTER TABLE $products ADD products_orientation_lock tinyint(1) NOT NULL default 0",
        ];
    }
}
