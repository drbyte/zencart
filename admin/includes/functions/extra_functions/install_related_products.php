<?php
/**
 * Simple install script for Related Products plugin
 * This checks to be sure that the database table and related Admin menu/config entries are available.
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

if (!defined('TABLE_RELATED_PRODUCTS')) define('TABLE_RELATED_PRODUCTS', DB_PREFIX . 'related_products');

if (!$sniffer->table_exists(TABLE_RELATED_PRODUCTS)) {
    $sql = "
      CREATE TABLE " . TABLE_RELATED_PRODUCTS . " (
      related_products_id int(15) NOT NULL auto_increment,
      products_id int(15) NOT NULL,
      related_id int(15) NOT NULL,
      status tinyint(1) NOT NULL,
      PRIMARY KEY  (related_products_id)
      )";
    $db->Execute($sql);
}

if (function_exists('zen_register_admin_page')) {
    if (!zen_page_key_exists('related_products')) {
        zen_register_admin_page('related_products', 'BOX_CATALOG_RELATED_PRODUCTS', 'FILENAME_RELATED_PRODUCTS_SELECT', '', 'catalog', 'Y', 200);
    }
}
