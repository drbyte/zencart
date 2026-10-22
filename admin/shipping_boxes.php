<?php
/**
 * Box catalog: the shipping boxes this store actually stocks, used by dimensional
 * packing to decide how a cart is parcelled.
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: New in v3.0.0 $
 */

use Zencart\Shipping\Packing\BoxForm;
use Zencart\Shipping\Packing\BoxType;
use Zencart\Shipping\Packing\Units;

require 'includes/application_top.php';

$action = $_GET['action'] ?? '';
$bID = (int)($_GET['bID'] ?? 0);
$form_errors = [];
$form_values = [];

switch ($action) {
    case 'insert':
    case 'save':
        $validated = BoxForm::validate($_POST);
        $form_values = $validated['values'];
        $form_errors = $validated['errors'];

        // The code is the stable handle used by the default-box setting and snapshotted
        // onto every parcel, so a duplicate would make those references ambiguous.
        $duplicate = $db->Execute(
            "SELECT box_id FROM " . TABLE_SHIPPING_BOXES . "
             WHERE box_code = '" . zen_db_input($form_values['box_code']) . "'"
             . ($action === 'save' ? " AND box_id <> " . $bID : '') . "
             LIMIT 1"
        );
        if (!$duplicate->EOF) {
            $form_errors[] = ['key' => 'ERROR_BOX_CODE_DUPLICATE', 'args' => [$form_values['box_code']]];
        }

        if ($form_errors !== []) {
            $action = ($action === 'save') ? 'edit' : 'new';
            break;
        }

        // Nulls have to be rendered for zen_db_perform or the insert fails silently;
        // see BoxForm::toDatabaseRow().
        $sql_data = BoxForm::toDatabaseRow($form_values);

        if ($action === 'save') {
            zen_db_perform(TABLE_SHIPPING_BOXES, $sql_data, 'UPDATE', 'box_id = ' . $bID);
            zen_record_admin_activity('Shipping box updated: ' . $form_values['box_code'], 'info');
        } else {
            zen_db_perform(TABLE_SHIPPING_BOXES, $sql_data);
            $bID = (int)$db->insert_ID();
            zen_record_admin_activity('Shipping box added: ' . $form_values['box_code'], 'info');
        }
        zen_redirect(zen_href_link(FILENAME_SHIPPING_BOXES, 'bID=' . $bID));
        break;

    case 'deleteconfirm':
        $box = $db->Execute("SELECT box_code FROM " . TABLE_SHIPPING_BOXES . " WHERE box_id = " . $bID . " LIMIT 1");
        if (!$box->EOF) {
            $db->Execute("DELETE FROM " . TABLE_SHIPPING_BOXES . " WHERE box_id = " . $bID);
            zen_record_admin_activity('Shipping box deleted: ' . $box->fields['box_code'], 'warning');
        }
        zen_redirect(zen_href_link(FILENAME_SHIPPING_BOXES));
        break;

    case 'setstatus':
        $db->Execute(
            "UPDATE " . TABLE_SHIPPING_BOXES . "
             SET is_enabled = IF(is_enabled = 1, 0, 1), last_modified = now()
             WHERE box_id = " . $bID
        );
        zen_redirect(zen_href_link(FILENAME_SHIPPING_BOXES, 'bID=' . $bID));
        break;
}

$boxes = [];
$boxes_result = $db->Execute(
    "SELECT * FROM " . TABLE_SHIPPING_BOXES . " ORDER BY sort_order, box_code"
);
while (!$boxes_result->EOF) {
    $boxes[] = $boxes_result->fields;
    $boxes_result->MoveNext();
}

// When re-showing a form after a validation failure, keep what the owner typed; when
// opening one fresh, load the stored row.
if ($action === 'edit' && $form_values === []) {
    foreach ($boxes as $box) {
        if ((int)$box['box_id'] === $bID) {
            $form_values = $box;
            break;
        }
    }
}

$unit_label = static fn (string $units): string => zen_output_string_protected($units);
?>
<!doctype html>
<html <?php echo HTML_PARAMS; ?>>
  <head>
      <?php require DIR_WS_INCLUDES . 'admin_html_head.php'; ?>
  </head>
  <body>
    <!-- header //-->
    <?php require DIR_WS_INCLUDES . 'header.php'; ?>
    <!-- header_eof //-->
    <div class="container-fluid">
      <h1 class="pageHeading"><?php echo HEADING_TITLE; ?></h1>
      <p><?php echo TEXT_INTRO; ?></p>

        <?php if ($boxes === [] && $action !== 'new') { ?>
          <div class="alert alert-warning"><?php echo TEXT_NO_BOXES_YET; ?></div>
        <?php } ?>

        <?php foreach ($form_errors as $error) { ?>
          <div class="alert alert-danger"><?php echo vsprintf(constant($error['key']), $error['args']); ?></div>
        <?php } ?>

        <?php if ($action === 'new' || $action === 'edit') { ?>
          <?php echo zen_draw_form('box', FILENAME_SHIPPING_BOXES, 'action=' . ($action === 'edit' ? 'save&bID=' . $bID : 'insert'), 'post', 'class="form-horizontal"'); ?>
          <table class="table">
            <tbody>
              <tr>
                <td><?php echo ENTRY_BOX_CODE; ?></td>
                <td><?php echo zen_draw_input_field('box_code', $form_values['box_code'] ?? '', 'maxlength="32"'); ?>
                    <br><small><?php echo ENTRY_BOX_CODE_INFO; ?></small></td>
              </tr>
              <tr>
                <td><?php echo ENTRY_BOX_TITLE; ?></td>
                <td><?php echo zen_draw_input_field('box_title', $form_values['box_title'] ?? '', 'maxlength="64"'); ?></td>
              </tr>
              <tr>
                <td><?php echo ENTRY_INTERIOR; ?></td>
                <td>
                    <?php
                      echo zen_draw_input_field('interior_length', $form_values['interior_length'] ?? '', 'size="8"') . ' &times; ';
                      echo zen_draw_input_field('interior_width', $form_values['interior_width'] ?? '', 'size="8"') . ' &times; ';
                      echo zen_draw_input_field('interior_height', $form_values['interior_height'] ?? '', 'size="8"');
                    ?>
                    <br><small><?php echo ENTRY_INTERIOR_INFO; ?></small>
                </td>
              </tr>
              <tr>
                <td><?php echo ENTRY_EXTERIOR; ?></td>
                <td>
                    <?php
                      echo zen_draw_input_field('exterior_length', $form_values['exterior_length'] ?? '', 'size="8"') . ' &times; ';
                      echo zen_draw_input_field('exterior_width', $form_values['exterior_width'] ?? '', 'size="8"') . ' &times; ';
                      echo zen_draw_input_field('exterior_height', $form_values['exterior_height'] ?? '', 'size="8"');
                    ?>
                    <br><small><?php echo ENTRY_EXTERIOR_INFO; ?></small>
                </td>
              </tr>
              <tr>
                <td><?php echo ENTRY_DIM_UNITS; ?></td>
                <td><?php echo zen_draw_pull_down_menu('dim_units', [
                        ['id' => Units::INCHES, 'text' => Units::INCHES],
                        ['id' => Units::CENTIMETERS, 'text' => Units::CENTIMETERS],
                    ], $form_values['dim_units'] ?? Units::INCHES); ?></td>
              </tr>
              <tr>
                <td><?php echo ENTRY_INTERIOR_MARGIN; ?></td>
                <td><?php echo zen_draw_input_field('interior_margin', $form_values['interior_margin'] ?? '', 'size="8"'); ?>
                    <br><small><?php echo ENTRY_INTERIOR_MARGIN_INFO; ?></small></td>
              </tr>
              <tr>
                <td><?php echo ENTRY_TARE_WEIGHT; ?></td>
                <td><?php echo zen_draw_input_field('tare_weight', $form_values['tare_weight'] ?? '0', 'size="8"'); ?>
                    <br><small><?php echo ENTRY_TARE_WEIGHT_INFO; ?></small></td>
              </tr>
              <tr>
                <td><?php echo ENTRY_MAX_PAYLOAD; ?></td>
                <td><?php echo zen_draw_input_field('max_payload_weight', $form_values['max_payload_weight'] ?? '', 'size="8"'); ?>
                    <br><small><?php echo ENTRY_MAX_PAYLOAD_INFO; ?></small></td>
              </tr>
              <tr>
                <td><?php echo ENTRY_WEIGHT_UNITS; ?></td>
                <td><?php echo zen_draw_pull_down_menu('weight_units', [
                        ['id' => Units::POUNDS, 'text' => Units::POUNDS],
                        ['id' => Units::KILOGRAMS, 'text' => Units::KILOGRAMS],
                    ], $form_values['weight_units'] ?? Units::POUNDS); ?></td>
              </tr>
              <tr>
                <td><?php echo ENTRY_DESTINATION_SCOPE; ?></td>
                <td><?php echo zen_draw_pull_down_menu('destination_scope', [
                        ['id' => BoxType::SCOPE_BOTH, 'text' => TEXT_SCOPE_BOTH],
                        ['id' => BoxType::SCOPE_DOMESTIC, 'text' => TEXT_SCOPE_DOMESTIC],
                        ['id' => BoxType::SCOPE_INTERNATIONAL, 'text' => TEXT_SCOPE_INTERNATIONAL],
                    ], $form_values['destination_scope'] ?? BoxType::SCOPE_BOTH); ?></td>
              </tr>
              <tr>
                <td><?php echo ENTRY_CARRIER_CODE; ?></td>
                <td><?php
                    /**
                     * A dropdown of the store's own installed shipping modules, because the
                     * value has to match the code a module reports and a typo here fails
                     * silently: the box is simply never offered.
                     */
                    $carrier_options = [['id' => '', 'text' => TEXT_CARRIER_ANY]];
                    foreach (BoxForm::carrierCodes(zen_config('MODULE_SHIPPING_INSTALLED', ''), $form_values['carrier_code'] ?? null) as $carrier_code) {
                        $carrier_options[] = ['id' => $carrier_code, 'text' => $carrier_code];
                    }
                    echo zen_draw_pull_down_menu('carrier_code', $carrier_options, $form_values['carrier_code'] ?? ''); ?>
                    <br><small><?php echo ENTRY_CARRIER_CODE_INFO; ?></small></td>
              </tr>
              <tr>
                <td><?php echo ENTRY_SORT_ORDER; ?></td>
                <td><?php echo zen_draw_input_field('sort_order', $form_values['sort_order'] ?? '0', 'size="4"'); ?>
                    <br><small><?php echo ENTRY_SORT_ORDER_INFO; ?></small></td>
              </tr>
              <tr>
                <td><?php echo ENTRY_ENABLED; ?></td>
                <td><?php echo zen_draw_checkbox_field('is_enabled', 'on', !empty($form_values['is_enabled']) || $action === 'new'); ?></td>
              </tr>
            </tbody>
          </table>
          <p>
            <button type="submit" class="btn btn-primary"><?php echo IMAGE_SAVE; ?></button>
            <a class="btn btn-default" href="<?php echo zen_href_link(FILENAME_SHIPPING_BOXES); ?>"><?php echo IMAGE_CANCEL; ?></a>
          </p>
          <?php echo '</form>' ?>
        <?php } else { ?>
          <p><a class="btn btn-primary" href="<?php echo zen_href_link(FILENAME_SHIPPING_BOXES, 'action=new'); ?>"><?php echo IMAGE_NEW_BOX; ?></a></p>

            <?php if ($boxes !== []) { ?>
            <table class="table table-hover">
              <thead>
                <tr class="dataTableHeadingRow">
                  <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_CODE; ?></th>
                  <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_TITLE; ?></th>
                  <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_INTERIOR; ?></th>
                  <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_EXTERIOR; ?></th>
                  <th class="dataTableHeadingContent text-right"><?php echo TABLE_HEADING_MAX_PAYLOAD; ?></th>
                  <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_SCOPE; ?></th>
                  <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_CARRIER; ?></th>
                  <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_ENABLED; ?></th>
                  <th class="dataTableHeadingContent text-right"><?php echo TABLE_HEADING_ACTION; ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($boxes as $box) {
                  $id = (int)$box['box_id'];
                  $dims = static fn (string $prefix): string => number_format((float)$box[$prefix . '_length'], 2)
                      . ' &times; ' . number_format((float)$box[$prefix . '_width'], 2)
                      . ' &times; ' . number_format((float)$box[$prefix . '_height'], 2);
                  ?>
                  <tr class="dataTableRow">
                    <td class="dataTableContent"><?php echo zen_output_string_protected($box['box_code']); ?></td>
                    <td class="dataTableContent"><?php echo zen_output_string_protected($box['box_title']); ?></td>
                    <td class="dataTableContent"><?php echo $dims('interior') . ' ' . $unit_label($box['dim_units']); ?></td>
                    <td class="dataTableContent"><?php echo $dims('exterior'); ?></td>
                    <td class="dataTableContent text-right">
                        <?php echo (float)$box['max_payload_weight'] > 0
                            ? number_format((float)$box['max_payload_weight'], 2) . ' ' . $unit_label($box['weight_units'])
                            : TEXT_UNLIMITED; ?>
                    </td>
                    <td class="dataTableContent"><?php echo zen_output_string_protected($box['destination_scope']); ?></td>
                    <td class="dataTableContent"><?php echo ($box['carrier_code'] ?? '') === ''
                        ? TEXT_CARRIER_ANY
                        : zen_output_string_protected((string)$box['carrier_code']); ?></td>
                    <td class="dataTableContent">
                      <a href="<?php echo zen_href_link(FILENAME_SHIPPING_BOXES, 'action=setstatus&bID=' . $id); ?>">
                          <?php echo (int)$box['is_enabled'] === 1 ? TEXT_STATUS_ENABLED : TEXT_STATUS_DISABLED; ?>
                      </a>
                    </td>
                    <td class="dataTableContent text-right">
                      <a href="<?php echo zen_href_link(FILENAME_SHIPPING_BOXES, 'action=edit&bID=' . $id); ?>"><?php echo IMAGE_EDIT; ?></a>
                      &nbsp;
                      <a href="<?php echo zen_href_link(FILENAME_SHIPPING_BOXES, 'action=deleteconfirm&bID=' . $id); ?>"
                         onclick="return confirm('<?php echo zen_output_string_protected(sprintf(TEXT_CONFIRM_DELETE, $box['box_code'])); ?>');"><?php echo IMAGE_DELETE; ?></a>
                    </td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
            <?php } ?>
        <?php } ?>
      <!-- body_eof //-->
    </div>
    <!-- footer //-->
    <?php require DIR_WS_INCLUDES . 'footer.php'; ?>
    <!-- footer_eof //-->
  </body>
</html>
<?php require DIR_WS_INCLUDES . 'application_bottom.php'; ?>
