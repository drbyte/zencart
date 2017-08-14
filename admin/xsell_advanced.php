<?php
/**
 * Cross Sell Advanced
 *
 * Derived from:
 * Original Idea From Isaac Mualem im@imwebdesigning.com
 * Portions Copyright (c) 2002 osCommerce
 * Complete Recoding From Stephen Walker admin@snjcomputers.com
 * Released under the GNU General Public License
 *
 * Adapted to Zen Cart by Merlin - Spring 2005
 * Reworked for Zen Cart v1.3.0  03-30-2006
 * Reworked for ZenCart V1.5.2 by RodG - December 2013
 */

require('includes/application_top.php');
require(DIR_WS_CLASSES . 'currencies.php');
global $db;
$currencies   = new currencies();
$languages_id = $_SESSION['languages_id'];

$common_sort_order = false;
if (defined('XSELL_USE_COMMON_SORT_ORDER') && (XSELL_USE_COMMON_SORT_ORDER == 'true')) {
    $common_sort_order = true;
}
function add_new_cross_product($products_id, $model)
{
    global $db, $messageStack;

    $cross_product = $db->Execute("select products_id from " . TABLE_PRODUCTS . " where products_model = '" . $model . "'");
    $check_xsell   = $db->Execute("select count(products_id) as records from " . TABLE_PRODUCTS_XSELL . " where products_id = '" . $products_id . "' and xsell_id = '" . $cross_product->fields['products_id'] . "'");
    if ($check_xsell->fields['records'] > 0) {
        $messageStack->add_session(sprintf(CROSS_SELL_MODEL_ALREADY_ADDED, $model, $_POST['cross_product_model']), 'error');
    } else {
        $insert_array = [
            'products_id' => $products_id,
            'xsell_id'    => $cross_product->fields['products_id'],
            'sort_order'  => '1',
        ];
        zen_db_perform(TABLE_PRODUCTS_XSELL, $insert_array);
        $messageStack->add_session(sprintf(CROSS_SELL_MODEL_ADDED, $model, $_POST['cross_product_model']), 'success');
    }
}

switch ($_GET['action']) {
    case 'confirm_delete_product':
        $db->Execute("delete from " . TABLE_PRODUCTS_XSELL . " where products_id = '" . (int)$_GET['cID'] . "'");
        $messageStack->add(CROSS_SELL_PRODUCT_DELETETION_SUCCESS, 'success');
        zen_redirect(zen_href_link(FILENAME_XSELL_ADVANCED, zen_get_all_get_params(['action'])));
        break;

    case 'newcross_sell':
        if (!isset($_POST['cross_product_id'])) {
            $product_lookup = $db->Execute("select p.products_id, pd.products_name from " . TABLE_PRODUCTS . " p, " . TABLE_PRODUCTS_DESCRIPTION . " pd " .
                "where p.products_id = pd.products_id and pd.language_id ='" . (int)$languages_id . "' and p.products_model = '" . zen_db_prepare_input($_POST['cross_product_model']) . "'");
            switch ($product_lookup->RecordCount()) {
                case '0':
                    $messageStack->add_session(sprintf(CROSS_SELL_NO_MODEL_FOUND, $_POST['cross_product_model']), 'error');
                    zen_redirect(FILENAME_XSELL_ADVANCED);
                    break;
                case '1':
                    $products_id = $product_lookup->fields['products_id'];
                    if (isset($_POST['product_model'])) {
                        foreach ($_POST['product_model'] as $id => $model) {
                            if (zen_not_null($model)) {
                                add_new_cross_product($products_id, $model);
                            }
                        }
                    }
                    break;
            }
        } else {
            $products_id = $_POST['cross_product_id'];
            if (isset($_POST['product_model'])) {
                foreach ($_POST['product_model'] as $id => $model) {
                    if (zen_not_null($model)) {
                        add_new_cross_product($products_id, $model);
                    }
                }
            }
        }
        zen_redirect(zen_href_link(FILENAME_XSELL_ADVANCED, 'cID=' . $products_id . '&action=edit'));

        break;

    case 'delete_cross':
        if (isset($_GET['xID'])) {
            $db->Execute("delete from " . TABLE_PRODUCTS_XSELL . " where ID = '" . zen_db_prepare_input($_GET['xID']) . "'");
            $messageStack->add_session(CROSS_SELL_PRODUCT_DELETED, 'success');
            zen_redirect(zen_href_link(FILENAME_XSELL_ADVANCED, 'cID=' . $_GET['cID'] . '&action=edit'));
        }
        break;

    case 'editcross_sell':
        $product_lookup = $db->Execute("select p.products_id from " . TABLE_PRODUCTS . " p " .
            "where p.products_model = '" . zen_db_prepare_input($_POST['cross_product_model']) . "'");
        if ($product_lookup->RecordCount() > 0) {
            zen_redirect(zen_href_link(FILENAME_XSELL_ADVANCED, 'cID=' . $product_lookup->fields['products_id'] . '&action=edit'));
        }
        $messageStack->add(sprintf(CROSS_SELL_PRODUCT_NOT_FOUND, $_POST['cross_product_model']), 'warning');
        break;

    case 'update':
        $products_id = $_POST['cross_product_id'];
        if (isset($_POST['product_model'])) {
            foreach ($_POST['product_model'] as $id => $model) {
                if (zen_not_null($model)) {
                    add_new_cross_product($products_id, $model);
                }
            }
        }
        if ($common_sort_order && isset($_POST['products_xsell_sort_order'])) {
            foreach ($_POST['products_xsell_sort_order'] as $id => $sort_order) {
                if (zen_not_null($sort_order)) {
                    $db->Execute("UPDATE " . TABLE_PRODUCTS . " SET products_xsell_sort_order = '" . (int)$sort_order . "' WHERE products_id = '" . (int)$id . "'");
                }
            }
        }
        zen_redirect(zen_href_link(FILENAME_XSELL_ADVANCED, 'cID=' . $products_id . '&action=edit'));
        break;


    case 'update_cross' :
        if ($_POST['product']) {
            foreach ($_POST['product'] as $temp_prod) {
                $db->execute('delete from ' . TABLE_PRODUCTS_XSELL . ' where xsell_id = "' . $temp_prod . '" and products_id = "' . $_GET['add_related_product_ID'] . '"');
            }
        }

        $sort_start_query = $db->execute('select sort_order from ' . TABLE_PRODUCTS_XSELL . ' where products_id = "' . $_GET['add_related_product_ID'] . '" order by sort_order desc limit 1');
        $sort_start       = $sort_start_query->fields;
        $sort             = (($sort_start['sort_order'] > 0) ? $sort_start['sort_order'] : '0');
        if ($_POST['cross']) {
            foreach ($_POST['cross'] as $temp) {
                $sort++;
                $insert_array = [
                    'products_id' => $_GET['add_related_product_ID'],
                    'xsell_id'    => $temp,
                    'sort_order'  => $sort,
                ];
                zen_db_perform(TABLE_PRODUCTS_XSELL, $insert_array);
            }
        }
        $messageStack->add(CROSS_SELL_SUCCESS, 'success');
        break;
    case 'update_sort' :
        foreach ($_POST as $key_a => $value_a) {
            $db->execute('update ' . TABLE_PRODUCTS_XSELL . ' set sort_order = "' . $value_a . '" where xsell_id = "' . $key_a . '"');
        }
        $messageStack->add(SORT_CROSS_SELL_SUCCESS, 'success');
        break;
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
<div class="header_area">
    <?php require(DIR_WS_INCLUDES . 'header.php'); ?>
</div>
<!-- header_eof //-->

<table border="0" width="100%" cellspacing="0" cellpadding="0">
    <tr>
        <td><?php echo zen_draw_separator('pixel_trans.gif', '100%', '10'); ?></td>
    </tr>
    <tr>
        <td class="pageHeading"><?php echo HEADING_TITLE; ?></td>
    </tr>
    <tr>
        <td><?php echo zen_draw_separator('pixel_trans.gif', '100%', '15'); ?></td>
    </tr>
</table>
<div style="padding:20px;padding-top:0px; float:left; width:40%;">
    <?php
    switch ($_GET['action']) {
    case 'newcross_sell':
        foreach ($_POST as $key => $value) {
            echo "Key $key => Value $value<br />";
        }
        if (!isset($_POST['cross_product_id'])) {
            $product_lookup = $db->Execute("select p.products_id, pd.products_name from " . TABLE_PRODUCTS . " p, " . TABLE_PRODUCTS_DESCRIPTION . " pd " .
                "where p.products_id = pd.products_id and pd.language_id ='" . (int)$languages_id . "' and p.products_model = '" . zen_db_prepare_input($_POST['cross_product_model']) . "'");
            switch ($product_lookup->RecordCount()) {
                case '0':
                    echo 'Error: No Product Found<br /><br />';
                    break;
                case '1':
                    echo zen_draw_hidden_field('cross_product_id', $product_lookup->fields['products_id']);
                    break;
                default:
                    $product_array = [];
                    while (!$product_lookup->EOF) {
                        echo $product_lookup->fields['products_name'] . '<br />';
                        $product_array[] = ['id' => $product_lookup->fields['products_id'], 'text' => $product_lookup->fields['products_name']];
                        $product_lookup->MoveNext();
                    }
                    echo zen_draw_pull_down_menu('cross_product_id', $product_array);
                    break;
            }
        }
        echo 'New cross sell applied';
        break;
    case 'delete':
        $product = $db->Execute("select p.products_model, pd.products_name from " . TABLE_PRODUCTS . " p, " . TABLE_PRODUCTS_DESCRIPTION . " pd where p.products_id = '" . zen_db_input($_GET['cID']) . "' and pd.products_id = p.products_id and pd.language_id = '" . (int)$languages_id . "'");
        ?>
        <h3 style="color:#0066FF;">Delete Confirmation</h3>
        To confirm that you wish to delete the following item, please click confirm below.
        <div style="color:#0033CC; padding:5px;"><?php echo $product->fields['products_model'] . ' - ' . $product->fields['products_name']; ?></div>
        <span style="float:right"><?php echo '<a href="' . zen_href_link(FILENAME_XSELL_ADVANCED,
                    zen_get_all_get_params(['action']) . 'action=confirm_delete_product') . '">' . zen_image_button('button_confirm.gif') . '</a>'; ?></span><br clear="all"/>
        <center>
            <hr style="color:#cccccc;" size="1" width="80%"/>
        </center>
        <?php
    default:
    ?>
    <h3 style="color:#0066FF;">New Cross-Sells</h3>
    <?php echo zen_draw_form('new_cross', FILENAME_XSELL_ADVANCED, 'action=newcross_sell', 'post'); ?>
    <fieldset style="width:100%;">
        <legend>New Cross Sell</legend>
        <br/>
        <label class="inputLabel">Product Cross-Sell applies to:&nbsp;</label><br/>
        <div style="padding-left:15px;">
            <label class="inputLabel">Product Model:&nbsp;</label>
            <?php echo zen_draw_input_field('cross_product_model'); ?>
        </div>
        <center>
            <hr style="color:#cccccc;" size="1" width="80%"/>
        </center>
        <label class="inputLabel">Products You wish to offer:&nbsp;</label><br/>
        <div style="padding-left:15px;">
            <label class="inputLabel">Product Model:&nbsp;</label>
            <?php echo zen_draw_input_field('product_model[0]'); ?><br/>
            <label class="inputLabel">Product Model:&nbsp;</label>
            <?php echo zen_draw_input_field('product_model[1]'); ?><br/>
            <label class="inputLabel">Product Model:&nbsp;</label>
            <?php echo zen_draw_input_field('product_model[2]'); ?><br/>
            <label class="inputLabel">Product Model:&nbsp;</label>
            <?php echo zen_draw_input_field('product_model[3]'); ?><br/>
            <label class="inputLabel">Product Model:&nbsp;</label>
            <?php echo zen_draw_input_field('product_model[4]'); ?><br/>
            <label class="inputLabel">Product Model:&nbsp;</label>
            <?php echo zen_draw_input_field('product_model[5]'); ?>
            <span style="float:right"><?php echo zen_image_submit('button_insert.gif', IMAGE_INSERT); ?></span>

        </div>
    </fieldset>
    <?php
    echo '</form>';
    ?>
    <br clear="all"/>
    <br/>
    <center>
        <hr style="color:#cccccc;" size="1" width="80%"/>
    </center>
    <br/>
    <h3 style="color:#0066FF;">Edit Cross-Sells</h3>
    <?php
    $xsell_products = $db->Execute("select p.products_id, p.products_model, pd.products_name, count(p.products_id) as xsells from " . TABLE_PRODUCTS . " p, " . TABLE_PRODUCTS_DESCRIPTION . " pd, " . TABLE_PRODUCTS_XSELL . " px " .
        "where p.products_id = pd.products_id and p.products_id = px.products_id and pd.language_id ='" . (int)$languages_id . "' group by p.products_id");
    if ($xsell_products->EOF) {
        echo 'No Cross Sells are currently active.';
    } else {
    echo '<div style="float:left; width:100%;">';
    echo zen_draw_form('edit_cross', FILENAME_XSELL_ADVANCED, 'action=editcross_sell', 'post');
    ?>
    <fieldset>
        <legend>Edit Current Cross Sell</legend>
        <label class="inputLabel">Product Model:&nbsp;</label>
        <?php echo zen_draw_input_field('cross_product_model'); ?>
        <span style="float:right"><?php echo zen_image_submit('button_search.gif', IMAGE_SEARCH); ?></span>
    </fieldset>
    <br/><br/>
    <?php
    echo '</form>';
    if ($_GET['action'] == 'edit') {
        $product_check = $db->Execute("select p.products_id, p.products_model, pd.products_name, count(p.products_id) as xsells from " . TABLE_PRODUCTS . " p, " . TABLE_PRODUCTS_DESCRIPTION . " pd " .
            "where p.products_id = pd.products_id and p.products_id = '" . (int)$_GET['cID'] . "' and pd.language_id ='" . (int)$languages_id . "' group by p.products_id");

        if ($common_sort_order) {
            $xsell_items = $db->Execute("select p.products_id, p.products_model, pd.products_name, p.products_xsell_sort_order, px.ID from " . TABLE_PRODUCTS . " p, " . TABLE_PRODUCTS_DESCRIPTION . " pd, " . TABLE_PRODUCTS_XSELL . " px " . "where p.products_id = pd.products_id and p.products_id = px.xsell_id and px.products_id = '" . (int)$_GET['cID'] . "' and pd.language_id ='" . (int)$languages_id . "' group by p.products_id order by p.products_xsell_sort_order");
        } else {
            $xsell_items = $db->Execute("select p.products_id, p.products_model, pd.products_name, px.ID from " . TABLE_PRODUCTS . " p, " . TABLE_PRODUCTS_DESCRIPTION . " pd, " . TABLE_PRODUCTS_XSELL . " px " . "where p.products_id = pd.products_id and p.products_id = px.xsell_id and px.products_id = '" . (int)$_GET['cID'] . "' and pd.language_id ='" . (int)$languages_id . "' group by p.products_id");
        }


        echo zen_draw_form('new_cross', FILENAME_XSELL_ADVANCED, 'action=update', 'post');
        echo zen_draw_hidden_field('cross_product_id', (int)$_GET['cID']);
        ?>
        <fieldset>
            <legend>Product Cross-Sell for <?php echo $product_check->fields['products_model']; ?></legend>
            <br/>
            <span style="padding:5px;"><span style="color:#0033CC">Product Name: </span><?php echo $product_check->fields['products_name']; ?></span><br/><br/>
            <label class="inputLabel">Current Cross-Sells:&nbsp;<br/><br/></label>
            <div style="padding-left:15px;">
                <?php
                echo '<table cellspacing="0" cellpadding="5" style="border:1px solid #cccccc; border-collapse: collapse;">';
                echo '<tr style="background-color:#dddddd;">';
                echo '<td>Product Model</td>';
                if ($common_sort_order) {
                    echo '<td>Sort Order</td>';
                }
                echo '<td>Product Name</td>';
                echo '<td>Action</td>';
                echo '</tr>';
                while (!$xsell_items->EOF) {
                    echo '<tr>';
                    echo '<td style="border-bottom:1px dashed #cccccc;">' . $xsell_items->fields['products_model'] . '</td>';
                    if ($common_sort_order) {
                        // echo '<td style="border-bottom:1px dashed #cccccc;">' . $xsell_items->fields['products_xsell_sort_order'] . '</td>';
                        echo '<td style="border-bottom:2px;">' . zen_draw_input_field('products_xsell_sort_order[' . $xsell_items->fields['products_id'] . ']',
                                $xsell_items->fields['products_xsell_sort_order']) . '</td>';
                    }
                    echo '<td style="border-bottom:1px dashed #cccccc;">' . $xsell_items->fields['products_name'] . '</td>';
                    echo '<td style="border-bottom:1px dashed #cccccc;">';
//			echo '<a href="' . zen_href_link(FILENAME_XSELL_ADVANCED, 'xID=' . $xsell_items->fields['products_id'] . '&action=edit', 'NONSSL') . '">' . zen_image(DIR_WS_IMAGES . 'icons/admin_edit.gif', 'Edit') . '</a>&nbsp;';
                    echo '<a href="' . zen_href_link(FILENAME_XSELL_ADVANCED, zen_get_all_get_params(['action']) . 'xID=' . $xsell_items->fields['ID'] . '&action=delete_cross',
                            'NONSSL') . '">' . zen_image(DIR_WS_IMAGES . 'icons/delete.gif', 'Delete Cross-Sell') . '</a>';
                    echo '</td>';
                    echo '</tr>';
                    $xsell_items->MoveNext();
                }
                echo '</table>';
                ?>
            </div>
            <center>
                <hr style="color:#cccccc;" size="1" width="80%"/>
            </center>
            <label class="inputLabel">Products You wish to offer:&nbsp;</label><br/><br/>
            <div style="padding-left:15px;">
                <label class="inputLabel">Product Model:&nbsp;</label>
                <?php echo zen_draw_input_field('product_model[0]'); ?><br/>
                <label class="inputLabel">Product Model:&nbsp;</label>
                <?php echo zen_draw_input_field('product_model[1]'); ?><br/>
                <label class="inputLabel">Product Model:&nbsp;</label>
                <?php echo zen_draw_input_field('product_model[2]'); ?><br/>
                <label class="inputLabel">Product Model:&nbsp;</label>
                <?php echo zen_draw_input_field('product_model[3]'); ?><br/>
                <label class="inputLabel">Product Model:&nbsp;</label>
                <?php echo zen_draw_input_field('product_model[4]'); ?><br/>
                <label class="inputLabel">Product Model:&nbsp;</label>
                <?php echo zen_draw_input_field('product_model[5]'); ?>
                <span style="float:right"><?php echo zen_image_submit('button_update.gif', IMAGE_UPDATE); ?></span>
            </div>
        </fieldset>
        <?php
        echo '</form>';
    }
    ?>
</div>
</div>
<div style="float:right; width:49%; padding-right:20px; padding-left:20px;">
    <h3 style="color:#0066FF;">Current Cross-Sells</h3>
    <?php
    // @TODO - language strings
    echo '<table cellspacing="0" cellpadding="5" style="border-collapse: collapse; border:1px solid #cccccc; width:100%;">';

    echo '<tr style="background-color:#dddddd;">';
    echo '<td>Product Model</td>';
    echo '<td>Product Name</td>';
    echo '<td>No. of Current Cross-Sells</td>';
    echo '<td>Action</td>';
    echo '</tr>';

    while (!$xsell_products->EOF) {
        echo '<tr>';
        echo '<td style="border-bottom:1px dashed #cccccc;">' . $xsell_products->fields['products_model'] . '</td>';
        echo '<td style="border-bottom:1px dashed #cccccc;">' . $xsell_products->fields['products_name'] . '</td>';
        echo '<td align="center" style="border-bottom:1px dashed #cccccc;">' . $xsell_products->fields['xsells'] . '</td>';
        echo '<td style="border-bottom:1px dashed #cccccc;">';
        echo '<a href="' . zen_href_link(FILENAME_XSELL_ADVANCED, 'cID=' . $xsell_products->fields['products_id'] . '&action=edit', 'NONSSL') . '">' . zen_image_button('button_edit.gif',
                'Edit Cross Sell') . '</a>&nbsp;';
        echo '<a href="' . zen_href_link(FILENAME_XSELL_ADVANCED, zen_get_all_get_params(['cID', 'action']) . 'cID=' . $xsell_products->fields['products_id'] . '&action=delete',
                'NONSSL') . '">' . zen_image_button('button_delete.gif', 'Delete Cross Sell') . '</a>';
        echo '</td>';
        echo '</tr>';
        $xsell_products->MoveNext();
    }
    echo '</table>';
    ?></div><?php
}
}
?>
<br clear="all"/>
<!-- body_eof //-->
<!-- footer //-->
<div class="footer-area">
    <?php require(DIR_WS_INCLUDES . 'footer.php'); ?>
</div>
<!-- footer_eof //-->
<br/>
</body>
</html>
<?php require(DIR_WS_INCLUDES . 'application_bottom.php'); ?>
