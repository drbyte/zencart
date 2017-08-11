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
$zc_show_related = false;
include(DIR_WS_MODULES . zen_get_module_directory(FILENAME_RELATED_PRODUCTS_MODULE));
?>

<!-- bof: related products  -->
<?php if ($zc_show_related == true) { ?>
    <div class="centerBoxWrapper" id="featuredProducts">
        <?php
        /**
         * require the list_box_content template to display the product
         */
        require($template->get_template_dir('tpl_columnar_display.php', DIR_WS_TEMPLATE, $current_page_base, 'common') . '/tpl_columnar_display.php');
        ?>
    </div>
<?php } ?>
<!-- eof: related products  -->
