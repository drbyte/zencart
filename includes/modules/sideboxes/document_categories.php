<?php
/**
 * document_categories sidebox - displays the categories sidebox containing ONLY "document" products (product type = 3)
 *
 * @package templateSystem
 * @copyright Copyright 2003-2016 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: document_categories.php $
 */

$main_category_tree = new category_tree;
$row = 0;

if ($main_category_tree->has_any_categories) {
    $box_categories_array = $main_category_tree->get_nested_category_tree_for_sidebox($cPath_array, 3);
    require($template->get_template_dir('tpl_document_categories.php',DIR_WS_TEMPLATE, $current_page_base,'sideboxes'). '/tpl_document_categories.php');

    $title = BOX_HEADING_DOCUMENT_CATEGORIES;
    $title_link = false;

    require($template->get_template_dir($column_box_default, DIR_WS_TEMPLATE, $current_page_base,'common') . '/' . $column_box_default);
}

