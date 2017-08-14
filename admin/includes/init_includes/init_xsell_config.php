<?php
/**
 * Cross Sell Advanced
 *
 * @copyright 2013 C Jones
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: init_xsell_config.php v1.2 01/20/2014 C Jones $
 */

//-- CREATE XSELL SUPPORT TABLES
$sql = "CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "products_xsell (
  ID int(10) NOT NULL auto_increment,
  products_id int(10) unsigned NOT NULL default 1,
  xsell_id int(10) unsigned NOT NULL default 1,
  sort_order int(10) unsigned NOT NULL default 1,
  PRIMARY KEY  (ID), 
  KEY idx_products_id_xsell (products_id)
)";
$db->Execute($sql);
// -- --------------------------------------------------------


$xsell_old_menu_title = 'Cross Sell';
$xsell_menu_title     = 'Cross Sell Advanced';
$xsell_menu_text      = 'Cross Sell Advanced Configuration';

/* Find configuation group ID of Previous Version of Cross Sell */
$sql                        = "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP . " WHERE configuration_group_title='" . $xsell_old_menu_title . "' LIMIT 1";
$result                     = $db->Execute($sql);
$xsell_old_configuration_id = $result->fields['configuration_group_id'];

/* Remove Previous Version of Cross Sell from the configuration group table */
$sql = "DELETE FROM " . TABLE_CONFIGURATION_GROUP . " WHERE configuration_group_id ='" . $xsell_old_configuration_id . "'";
$db->Execute($sql);

/* Remove Previous Version of Cross Sell items from the configuration table */
$sql = "DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_group_id ='" . $xsell_old_configuration_id . "'";
$db->Execute($sql);

/* Find configuation group ID of Cross Sell Advanced */
$sql                    = "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP . " WHERE configuration_group_title='" . $xsell_menu_title . "' LIMIT 1";
$result                 = $db->Execute($sql);
$xsell_configuration_id = $result->fields['configuration_group_id'];

/* Remove Cross Sell Advanced items from the configuration group table */
$sql = "DELETE FROM " . TABLE_CONFIGURATION_GROUP . " WHERE configuration_group_id ='" . $xsell_configuration_id . "'";
$db->Execute($sql);

/* Remove Cross Sell Advanced items from the configuration table */
$sql = "DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_group_id ='" . $xsell_configuration_id . "'";
$db->Execute($sql);

/* Find max sort order in the configuation group table -- add 2 to this value to create the Cross Sell Advanced configuration group ID */
$sql    = "SELECT (MAX(sort_order)+2) as sort FROM " . TABLE_CONFIGURATION_GROUP;
$result = $db->Execute($sql);
$sort   = $result->fields['sort'];

/* Create Cross Sell Advanced configuration group */
$sql = "INSERT INTO " . TABLE_CONFIGURATION_GROUP . " (configuration_group_id, configuration_group_title, configuration_group_description, sort_order, visible) VALUES (NULL, '" . $xsell_menu_title . "', '" . $xsell_menu_text . "', " . $sort . ", '1')";
$db->Execute($sql);

/* Find configuation group ID of Cross Sell Advanced */
$sql                    = "SELECT configuration_group_id FROM " . TABLE_CONFIGURATION_GROUP . " WHERE configuration_group_title='" . $xsell_menu_title . "' LIMIT 1";
$result                 = $db->Execute($sql);
$xsell_configuration_id = $result->fields['configuration_group_id'];

//-- Add Values to Cross Sell Advanced Configuration Group (Admin > Configuration > Cross-Sell (X-Sell) Configuration)
$sql = "INSERT INTO " . TABLE_CONFIGURATION . " (configuration_id, configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, last_modified, date_added, use_function, set_function) VALUES (NULL, 'Display Cross-Sell Products Minimum', 'MIN_DISPLAY_XSELL', '1', 'This is the minimum number of configured Cross-Sell products required in order to cause the Cross Sell information to be displayed.<br />Default: 1', '" . $xsell_configuration_id . "', 20, NULL, now(), NULL, NULL)";
$db->Execute($sql);
$sql = "INSERT INTO " . TABLE_CONFIGURATION . " (configuration_id, configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, last_modified, date_added, use_function, set_function) VALUES (NULL, 'Display Cross-Sell Products Maximum', 'MAX_DISPLAY_XSELL', '6', 'This is the maximum number of configured Cross-Sell products to be displayed.<br />Default: 6', '" . $xsell_configuration_id . "', 25, NULL, now(), NULL, NULL)";
$db->Execute($sql);
$sql = "INSERT INTO " . TABLE_CONFIGURATION . " (configuration_id, configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, last_modified, date_added, use_function, set_function) VALUES (NULL, 'Cross-Sell Products Columns per Row', 'SHOW_PRODUCT_INFO_COLUMNS_XSELL_PRODUCTS', '3', 'Cross-Sell Products Columns to display per Row<br />0= off or set the sort order.<br />Default: 3', '" . $xsell_configuration_id . "', 30, NULL, now(), NULL, 'zen_cfg_select_option(array(''0'', ''1'', ''2'', ''3'', ''4'', ''5'', ''6''),')";
$db->Execute($sql);
$sql = "INSERT INTO " . TABLE_CONFIGURATION . " (configuration_id, configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, last_modified, date_added, use_function, set_function) VALUES (NULL, 'Cross-Sell - Display prices?', 'XSELL_DISPLAY_PRICE', 'false', 'Cross-Sell -- Do you want to display the product prices too?<br />Default: false', '" . $xsell_configuration_id . "', 35, NULL, now(), NULL, 'zen_cfg_select_option(array(''true'', ''false''),')";
$db->Execute($sql);
$sql = "INSERT INTO " . TABLE_CONFIGURATION . " (configuration_id, configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, last_modified, date_added, use_function, set_function) VALUES (NULL, 'Cross-Sell - Use common sort order?', 'XSELL_USE_COMMON_SORT_ORDER', 'false', 'Cross-Sell -- Use per product sort order (on Catalog-&gt;Cross-Sell Admin) or common sort order (on Catalog-&gt;Categories/Products Edit) ?<br />Default: false', '" . $xsell_configuration_id . "', 38, NULL, now(), NULL, 'zen_cfg_select_option(array(''true'', ''false''),')";
$db->Execute($sql);
$sql = "INSERT INTO " . TABLE_CONFIGURATION . " (configuration_id, configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, last_modified, date_added, use_function, set_function) VALUES (NULL, 'Cross Sell Advanced Version', 'XSELL_VERSION', '1.5', 'Cross Sell Advanced Version (DO NOT MODIFY THIS VALUE!)', '" . $xsell_configuration_id . "', 40, NULL, now(), NULL, NULL)";
$db->Execute($sql);

if (file_exists(DIR_FS_ADMIN . DIR_WS_INCLUDES . 'auto_loaders/config.xsell.php')) {
    if (!unlink(DIR_FS_ADMIN . DIR_WS_INCLUDES . 'auto_loaders/config.xsell.php')) {
        $messageStack->add('The auto-loader file ' . DIR_FS_ADMIN . 'includes/auto_loaders/config.xsell.php has not been deleted. For this module to work you must delete the ' . DIR_FS_ADMIN . 'includes/auto_loaders/config.xsell.php file manually.  Before you post on the Zen Cart forum to ask, YES you are REALLY supposed to follow these instructions and delete the ' . DIR_FS_ADMIN . 'includes/auto_loaders/config.xsell.php file.',
            'error');
    };
}

$messageStack->add('Cross Sell Advanced v1.3 install completed!', 'success');

// find next sort order in admin_pages table
$sql             = "SELECT (MAX(sort_order)+2) as sort FROM " . TABLE_ADMIN_PAGES;
$result          = $db->Execute($sql);
$admin_page_sort = $result->fields['sort'];

// now register the admin pages
// Admin Menu for Cross Sell Advanced Configuration Menu
zen_deregister_admin_pages('configXSELL');
zen_deregister_admin_pages('configXsellCombo');
zen_register_admin_page('configXsellCombo',
    'BOX_CONFIGURATION_XSELL', 'FILENAME_CONFIGURATION',
    'gID=' . $xsell_configuration_id, 'configuration', 'Y',
    $admin_page_sort);

//-- Catalog Menu for XSellCombo
zen_deregister_admin_pages('catalogXSellComboAdmin');
zen_register_admin_page('catalogXSellComboAdmin',
    'BOX_CATALOG_XSELL', 'FILENAME_XSELL',
    '', 'catalog', 'Y',
    $admin_page_sort);

//-- Catalog Menu for XSellComboAdvanced
zen_deregister_admin_pages('catalogXSellComboAdvancedAdmin');
zen_register_admin_page('catalogXSellComboAdvancedAdmin',
    'BOX_CATALOG_XSELL_ADVANCED', 'FILENAME_XSELL_ADVANCED',
    '', 'catalog', 'Y',
    $admin_page_sort);
