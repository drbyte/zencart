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

if (zen_not_null($action)) {
    switch ($action) {
        case 'setflag':
            $related_products_id = $_GET['cID'];
            $db->Execute("update " . TABLE_RELATED_PRODUCTS . " set status = " . (int)$_GET['flag'] . " where related_products_id = " . (int)$related_products_id);

            zen_redirect(zen_href_link(FILENAME_RELATED_PRODUCTS, 'products_id=' . (int)$_GET['products_id']));
            break;

        case 'insert':
            $related_id = $_POST['related_id'];
            $db->Execute("insert into " . TABLE_RELATED_PRODUCTS . " (products_id, related_id, status) 
                          values (" . (int)$_GET['products_id'] . ", " . (int)$related_id . ", 1)");

            zen_redirect(zen_href_link(FILENAME_RELATED_PRODUCTS, 'products_id=' . (int)$_GET['products_id']));
            break;

        case 'deleteconfirm':
            $related_products_id = $_POST['cID'];

            $db->Execute("delete from " . TABLE_RELATED_PRODUCTS . " where related_products_id = " . (int)$related_products_id);

            zen_redirect(zen_href_link(FILENAME_RELATED_PRODUCTS, 'products_id=' . (int)$_GET['products_id']));
            break;
    }
}
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
                                <td class="pageHeading"><?php echo HEADING_TITLE . zen_get_products_name((int)$_GET['products_id'], (int)$_SESSION['languages_id']); ?></td>
                                <td class="pageHeading" align="right"><?php echo zen_draw_separator('pixel_trans.gif', HEADING_IMAGE_WIDTH, HEADING_IMAGE_HEIGHT); ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td>
                        <table border="0" width="100%" cellspacing="0" cellpadding="0">
                            <tr>
                                <td valign="top">
                                    <table border="0" width="100%" cellspacing="0" cellpadding="2">
                                        <tr class="dataTableHeadingRow">
                                            <td class="dataTableHeadingContent" align="right"><?php echo TABLE_HEADING_ID; ?>&nbsp;</td>
                                            <td class="dataTableHeadingContent"><?php echo TABLE_HEADING_PRODUCTS; ?></td>
                                            <td class="dataTableHeadingContent"><?php echo TABLE_HEADING_PRODUCTS_MODEL; ?></td>
                                            <td class="dataTableHeadingContent" align="center"><?php echo TABLE_HEADING_STATUS; ?></td>
                                            <td class="dataTableHeadingContent" align="right"><?php echo TABLE_HEADING_ACTION; ?>&nbsp;</td>
                                        </tr>
                                        <?php
$related_query_raw = "select rp.related_products_id, rp.related_id, rp.status, pd.products_name, p.products_model 
  from " . TABLE_PRODUCTS . " p, " . TABLE_RELATED_PRODUCTS . " rp, " . TABLE_PRODUCTS_DESCRIPTION . " pd 
  where p.products_id = pd.products_id 
  and pd.language_id = " . (int)$_SESSION['languages_id'] . " 
  and p.products_id = rp.related_id 
  and rp.products_id = " . (int)$_GET['products_id'];
                                        //$related_split = new splitPageResults((int)$_GET['page'], MAX_DISPLAY_SEARCH_RESULTS, $related_query_raw);
                                        $related = $db->Execute($related_query_raw);
                                        while (!$related->EOF) {
                                            if ((!isset($_GET['cID']) || (isset($_GET['cID']) && ($_GET['cID'] == $related->fields['related_products_id']))) && !isset($cInfo) && (substr($action, 0, 3) != 'new')) {
                                                $cInfo = new objectInfo($related->fields);
                                            }
                                            ?>
                                            <td class="dataTableContent" align="right"><?php echo $related->fields['related_id']; ?>&nbsp;</td>
                                            <td class="dataTableContent"><?php echo $related->fields['products_name']; ?></td>
                                            <td class="dataTableContent" align="left"><?php echo $related->fields['products_model']; ?>&nbsp;</td>
                                            <td class="dataTableContent" align="center">
                                                <?php
                                                if ((int)$related->fields['status'] == 1) {
                                                    echo '<a href="' . zen_href_link(FILENAME_RELATED_PRODUCTS,
                                                            'action=setflag&flag=0&cID=' . $related->fields['related_products_id'] . '&products_id=' . (int)$_GET['products_id'],
                                                            'NONSSL') . '">' . zen_image(DIR_WS_IMAGES . 'icon_green_on.gif', IMAGE_ICON_STATUS_ON) . '</a>';
                                                } else {
                                                    echo '<a href="' . zen_href_link(FILENAME_RELATED_PRODUCTS,
                                                            'action=setflag&flag=1&cID=' . $related->fields['related_products_id'] . '&products_id=' . (int)$_GET['products_id'],
                                                            'NONSSL') . '">' . zen_image(DIR_WS_IMAGES . 'icon_red_on.gif', IMAGE_ICON_STATUS_OFF) . '</a>';
                                                }
                                                ?>
                                            </td>
                                            <td class="dataTableContent" align="right">
                                                <?php echo '<a href="' . zen_href_link(FILENAME_RELATED_PRODUCTS,
                                                        'cID=' . $related->fields['related_products_id'] . '&action=delete&products_id=' . (int)$_GET['products_id']) . '">' . zen_image(DIR_WS_IMAGES . 'icon_delete.gif',
                                                        ICON_DELETE) . '</a>'; ?>
                                            </td>
                                            </tr>


                                            <?php
                                            $related->MoveNext();
                                        }
                                        ?>
                                        <tr>
                                            <td colspan="4">
                                                <table border="0" width="100%" cellspacing="0" cellpadding="2">
                                                    <?php
                                                    if (empty($action)) {
                                                        ?>
                                                        <tr>
                                                            <td colspan="2" align="right"><?php echo '<a href="' . zen_href_link(FILENAME_RELATED_PRODUCTS,
                                                                        'page=' . (int)$_GET['page'] . '&action=new&products_id=' . (int)$_GET['products_id']) . '">' . zen_image_button('button_new_product.gif',
                                                                        IMAGE_NEW_PRODUCT) . '</a>'; ?>
                                                                <?php echo '<a href="' . zen_href_link(FILENAME_RELATED_PRODUCTS_SELECT) . '">' . zen_image_button('button_back.gif', IMAGE_BACK) . '</a>'; ?></td>
                                                        </tr>
                                                        <?php
                                                    }
                                                    ?>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                                <?php
                                $heading  = [];
                                $contents = [];

                                switch ($action) {
                                    case 'new':
                                        $heading[] = ['text' => '<b>' . TEXT_INFO_HEADING_NEW_RELATED_PRODUCT . '</b>'];

                                        $contents   = ['form' => zen_draw_form('related', FILENAME_RELATED_PRODUCTS, 'products_id=' . (int)$_GET['products_id'] . '&action=insert')];
                                        $contents[] = ['text' => '<br>' . zen_draw_products_pull_down('related_id', 'size="15" style="font-size:10px"', '', true, '', true)];
                                        $contents[] = ['align' => 'center',
                                                       'text'  => '<br>' . zen_image_submit('button_insert.gif', IMAGE_INSERT) . '&nbsp;<a href="' . zen_href_link(FILENAME_RELATED_PRODUCTS,
                                                               'products_id=' . (int)$_GET['products_id']) . '">' . zen_image_button('button_cancel.gif', IMAGE_CANCEL) . '</a>',
                                        ];
                                        break;
                                    case 'delete':
                                        $heading[] = ['text' => '<b>' . TEXT_INFO_HEADING_DELETE_RELATED_PRODUCT . '</b>'];

                                        $contents   = [
                                            'form' => zen_draw_form('related', FILENAME_RELATED_PRODUCTS, 'products_id=' . (int)$_GET['products_id'] . '&action=deleteconfirm') .
                                                zen_draw_hidden_field('cID', (int)$cInfo->related_products_id),
                                        ];
                                        $contents[] = ['text' => '<br><b>' . $cInfo->products_name . '</b>'];
                                        $contents[] = [
                                            'align' => 'center',
                                            'text'  => '<br>' . zen_image_submit('button_delete.gif', IMAGE_UPDATE) . '&nbsp;<a href="' . zen_href_link(FILENAME_RELATED_PRODUCTS, 'products_id=' . (int)$_GET['products_id']) . '">' . zen_image_button('button_cancel.gif', IMAGE_CANCEL) . '</a>',
                                        ];
                                        break;
                                }

                                if ((zen_not_null($heading)) && (zen_not_null($contents))) {
                                    echo '            <td width="25%" valign="top">' . "\n";

                                    $box = new box;
                                    echo $box->infoBox($heading, $contents);

                                    echo '            </td>' . "\n";
                                }
                                ?>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
        <!-- body_text_eof //-->
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
