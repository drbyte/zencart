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
require('includes/application_top.php');

$action = isset($_GET['action']) ? zen_output_string_protected($_GET['action']) : '';

require(DIR_WS_CLASSES . 'currencies.php');
$currencies = new currencies();

$languages = zen_get_languages();
?>
    <!doctype html public "-//W3C//DTD HTML 4.01 Transitional//EN">
    <html <?php echo HTML_PARAMS; ?>>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=<?php echo CHARSET; ?>">
        <title><?php echo TITLE; ?></title>
        <link rel="stylesheet" type="text/css" href="includes/stylesheet.css">
        <link rel="stylesheet" type="text/css" href="includes/cssjsmenuhover.css" media="all" id="hoverJS">
        <script language="javascript" src="includes/menu.js"></script>
        <script language="javascript" src="includes/general.js"></script>
        <script type="text/javascript">
            <!--
            function init() {
                cssjsmenu('navbar');
                if (document.getElementById) {
                    var kill = document.getElementById('hoverJS');
                    kill.disabled = true;
                }
            }

            // -->
        </script>
    </head>
    <body onLoad="init()">
    <!-- header //-->
    <?php require(DIR_WS_INCLUDES . 'header.php'); ?>
    <!-- header_eof //-->

    <!-- body //-->
    <table border="0" width="100%" cellspacing="2" cellpadding="2">
        <tr>
            <!-- body_text //-->
            <td width="100%" valign="top">
                <table border="0" width="100%" cellspacing="0" cellpadding="2">
                    <tr>
                        <td>
                            <table border="0" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td class="pageHeading"><?php echo HEADING_TITLE; ?></td>
                                    <td class="pageHeading" align="right"><?php echo zen_draw_separator('pixel_trans.gif', HEADING_IMAGE_WIDTH, HEADING_IMAGE_HEIGHT); ?></td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <table border="0" width="100%" cellspacing="0" cellpadding="0">
                                <?php
                                require(DIR_WS_MODULES . FILENAME_PREV_NEXT);
                                $curr_page = FILENAME_RELATED_PRODUCTS_SELECT;
                                require('includes/modules/products_previous_next_display.php');
                                ?></table>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <?php echo zen_draw_form('related_products', FILENAME_RELATED_PRODUCTS, '', 'get');
                            echo zen_draw_products_pull_down('products_id', 'size="10"', '', true, zen_output_string_protected($_GET['products_filter']), true, true);
                            echo zen_image_submit('button_related_products.gif', BUTTON_RELATED_PRODUCTS);
                            ?>
                            </form>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
    <!-- body_eof //-->

    <!-- footer //-->
    <?php require(DIR_WS_INCLUDES . 'footer.php'); ?>
    <!-- footer_eof //-->
    <br>
    </body>
    </html>
<?php require(DIR_WS_INCLUDES . 'application_bottom.php'); ?>
