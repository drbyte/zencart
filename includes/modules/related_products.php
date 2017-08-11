<?php
//
// +----------------------------------------------------------------------+
// |zen-cart Open Source E-commerce                                       |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003 The zen-cart developers                           |
// |                                                                      |
// | http://www.zen-cart.com/index.php                                    |
// |                                                                      |
// | Portions Copyright (c) 2003 osCommerce                               |
// +----------------------------------------------------------------------+
// | This source file is subject to version 2.0 of the GPL license,       |
// | that is bundled with this package in the file LICENSE, and is        |
// | available through the world-wide-web at the following url:           |
// | http://www.zen-cart.com/license/2_0.txt.                             |
// +----------------------------------------------------------------------+
//  Author: Ravi Gulhane
//
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}
$related_query_raw = "select rp.related_products_id, rp.related_id, rp.status, p.products_id, p.products_image, pd.products_name 
    from " . TABLE_PRODUCTS . " p, " . TABLE_RELATED_PRODUCTS . " rp, " . TABLE_PRODUCTS_DESCRIPTION . " pd 
    where p.products_id = pd.products_id 
    and pd.language_id = " . (int)$_SESSION['languages_id'] . " 
    and p.products_id = rp.related_id 
    and rp.products_id = " . (int)$_GET['products_id'] . "
    and rp.status = 1";

$featured_products = $db->ExecuteRandomMulti($related_query_raw, MAX_DISPLAY_SEARCH_RESULTS_RELATED);

$row = 0;
$col = 0;
$list_box_contents = [];
$title = '';

$num_products_count = $featured_products->RecordCount();

// show only when 1 or more
if ($num_products_count > 0) {
    if ($num_products_count < SHOW_PRODUCT_INFO_COLUMNS_FEATURED_PRODUCTS || SHOW_PRODUCT_INFO_COLUMNS_FEATURED_PRODUCTS == 0) {
        $col_width = floor(100 / $num_products_count);
    } else {
        $col_width = floor(100 / SHOW_PRODUCT_INFO_COLUMNS_FEATURED_PRODUCTS);
    }
    while (!$featured_products->EOF) {

        $products_price = zen_get_products_display_price($featured_products->fields['products_id']);

        $list_box_contents[$row][$col] = [
            'params' => 'class="centerBoxContentsFeatured centeredContent back"' . ' ' . 'style="width:' . $col_width . '%;"',
            'text'   => (($featured_products->fields['products_image'] == '' && PRODUCTS_IMAGE_NO_IMAGE_STATUS == 0) ? '' :
                    '<a href="' . zen_href_link(zen_get_info_page($featured_products->fields['products_id']), 'products_id=' . $featured_products->fields['products_id']) . '">' .
                      zen_image(DIR_WS_IMAGES . $featured_products->fields['products_image'], $featured_products->fields['products_name'], IMAGE_FEATURED_PRODUCTS_LISTING_WIDTH, IMAGE_FEATURED_PRODUCTS_LISTING_HEIGHT) . '</a><br />') .
                    '<a href="' . zen_href_link(zen_get_info_page($featured_products->fields['products_id']), 'products_id=' . $featured_products->fields['products_id']) . '">' . $featured_products->fields['products_name'] . '</a><br />' . $products_price,
        ];

        $col++;
        if ($col > (SHOW_PRODUCT_INFO_COLUMNS_FEATURED_PRODUCTS - 1)) {
            $col = 0;
            $row++;
        }
        $featured_products->MoveNextRandom();
    }

    if ($featured_products->RecordCount() > 0) {
        if (isset($new_products_category_id) && $new_products_category_id != 0) {
            $category_title = zen_get_categories_name((int)$new_products_category_id);
            $title          = '<h2 class="centerBoxHeading">' . TABLE_HEADING_RELATED_PRODUCTS . ($category_title != '' ? ' - ' . $category_title : '') . '</h2>';
        } else {
            $title = '<h2 class="centerBoxHeading">' . TABLE_HEADING_RELATED_PRODUCTS . '</h2>';
        }
        $zc_show_related = true;
    }
}
