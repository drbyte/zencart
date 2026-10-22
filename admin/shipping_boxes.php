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
          <div class="form-group">
              <?= zen_draw_label(ENTRY_BOX_CODE, 'box_code', 'class="col-sm-3 control-label"') ?>
            <div class="col-sm-6">
                <?= zen_draw_input_field('box_code', $form_values['box_code'] ?? '', 'maxlength="32" class="form-control" id="box_code"') ?>
              <p class="help-block"><?= ENTRY_BOX_CODE_INFO ?></p>
            </div>
          </div>
          <div class="form-group">
              <?= zen_draw_label(ENTRY_BOX_TITLE, 'box_title', 'class="col-sm-3 control-label"') ?>
            <div class="col-sm-6">
                <?= zen_draw_input_field('box_title', $form_values['box_title'] ?? '', 'maxlength="64" class="form-control" id="box_title"') ?>
            </div>
          </div>
            <?php
            /**
             * Length, width and height belong to one measurement, so the three inputs stay
             * on one line inside a single form-group rather than becoming three rows.
             */
            foreach ([
                'interior' => ['label' => ENTRY_INTERIOR, 'info' => ENTRY_INTERIOR_INFO],
                'exterior' => ['label' => ENTRY_EXTERIOR, 'info' => ENTRY_EXTERIOR_INFO],
            ] as $prefix => $axis_group) { ?>
            <div class="form-group">
                <?= zen_draw_label($axis_group['label'], $prefix . '_length', 'class="col-sm-3 control-label"') ?>
              <div class="col-sm-6">
                <div class="row">
                    <?php
                    /**
                     * Three columns rather than inline fields: below the Bootstrap small
                     * breakpoint an inline row stacks, which left the separators dangling
                     * on lines of their own. Each axis is labeled by its placeholder, so
                     * the fields stay self-describing once they are narrow.
                     */
                    foreach (['length' => PLACEHOLDER_LENGTH, 'width' => PLACEHOLDER_WIDTH, 'height' => PLACEHOLDER_HEIGHT] as $axis => $placeholder) {
                        $field = $prefix . '_' . $axis;
                        ?>
                      <div class="col-xs-4">
                          <?= zen_draw_input_field($field, $form_values[$field] ?? '', 'class="form-control" id="' . $field . '" placeholder="' . $placeholder . '"') ?>
                      </div>
                    <?php } ?>
                </div>
                <p class="help-block"><?= $axis_group['info'] ?></p>
              </div>
            </div>
            <?php } ?>
          <div class="form-group">
              <?= zen_draw_label(ENTRY_DIM_UNITS, 'dim_units', 'class="col-sm-3 control-label"') ?>
            <div class="col-sm-3">
                <?= zen_draw_pull_down_menu('dim_units', [
                    ['id' => Units::INCHES, 'text' => Units::INCHES],
                    ['id' => Units::CENTIMETERS, 'text' => Units::CENTIMETERS],
                ], $form_values['dim_units'] ?? Units::INCHES, 'class="form-control" id="dim_units"') ?>
            </div>
          </div>
          <div class="form-group">
              <?= zen_draw_label(ENTRY_INTERIOR_MARGIN, 'interior_margin', 'class="col-sm-3 control-label"') ?>
            <div class="col-sm-6">
                <?= zen_draw_input_field('interior_margin', $form_values['interior_margin'] ?? '', 'size="8" class="form-control" id="interior_margin"') ?>
              <p class="help-block"><?= ENTRY_INTERIOR_MARGIN_INFO ?></p>
            </div>
          </div>
          <div class="form-group">
              <?= zen_draw_label(ENTRY_TARE_WEIGHT, 'tare_weight', 'class="col-sm-3 control-label"') ?>
            <div class="col-sm-6">
                <?= zen_draw_input_field('tare_weight', $form_values['tare_weight'] ?? '0', 'size="8" class="form-control" id="tare_weight"') ?>
              <p class="help-block"><?= ENTRY_TARE_WEIGHT_INFO ?></p>
            </div>
          </div>
          <div class="form-group">
              <?= zen_draw_label(ENTRY_MAX_PAYLOAD, 'max_payload_weight', 'class="col-sm-3 control-label"') ?>
            <div class="col-sm-6">
                <?= zen_draw_input_field('max_payload_weight', $form_values['max_payload_weight'] ?? '', 'size="8" class="form-control" id="max_payload_weight"') ?>
              <p class="help-block"><?= ENTRY_MAX_PAYLOAD_INFO ?></p>
            </div>
          </div>
          <div class="form-group">
              <?= zen_draw_label(ENTRY_WEIGHT_UNITS, 'weight_units', 'class="col-sm-3 control-label"') ?>
            <div class="col-sm-3">
                <?= zen_draw_pull_down_menu('weight_units', [
                    ['id' => Units::POUNDS, 'text' => Units::POUNDS],
                    ['id' => Units::KILOGRAMS, 'text' => Units::KILOGRAMS],
                ], $form_values['weight_units'] ?? Units::POUNDS, 'class="form-control" id="weight_units"') ?>
            </div>
          </div>
          <div class="form-group">
              <?= zen_draw_label(ENTRY_DESTINATION_SCOPE, 'destination_scope', 'class="col-sm-3 control-label"') ?>
            <div class="col-sm-6">
                <?= zen_draw_pull_down_menu('destination_scope', [
                    ['id' => BoxType::SCOPE_BOTH, 'text' => TEXT_SCOPE_BOTH],
                    ['id' => BoxType::SCOPE_DOMESTIC, 'text' => TEXT_SCOPE_DOMESTIC],
                    ['id' => BoxType::SCOPE_INTERNATIONAL, 'text' => TEXT_SCOPE_INTERNATIONAL],
                ], $form_values['destination_scope'] ?? BoxType::SCOPE_BOTH, 'class="form-control" id="destination_scope"') ?>
            </div>
          </div>
          <div class="form-group">
              <?= zen_draw_label(ENTRY_CARRIER_CODE, 'carrier_code', 'class="col-sm-3 control-label"') ?>
            <div class="col-sm-6">
                <?php
                /**
                 * A dropdown of the store's own installed shipping modules, because the
                 * value has to match the code a module reports and a typo here fails
                 * silently: the box is simply never offered.
                 */
                $carrier_options = [['id' => '', 'text' => TEXT_CARRIER_ANY]];
                foreach (BoxForm::carrierCodes(zen_config('MODULE_SHIPPING_INSTALLED', ''), $form_values['carrier_code'] ?? null) as $installed_carrier) {
                    $carrier_options[] = ['id' => $installed_carrier, 'text' => $installed_carrier];
                }
                echo zen_draw_pull_down_menu('carrier_code', $carrier_options, $form_values['carrier_code'] ?? '', 'class="form-control" id="carrier_code"');
                ?>
              <p class="help-block"><?= ENTRY_CARRIER_CODE_INFO ?></p>
            </div>
          </div>
          <div class="form-group">
              <?= zen_draw_label(ENTRY_SORT_ORDER, 'sort_order', 'class="col-sm-3 control-label"') ?>
            <div class="col-sm-6">
              <div class="form-inline">
                  <?= zen_draw_input_field('sort_order', $form_values['sort_order'] ?? '0', 'size="4" class="form-control" id="sort_order"', false, 'number') ?>
              </div>
              <p class="help-block"><?= ENTRY_SORT_ORDER_INFO ?></p>
            </div>
          </div>
          <div class="form-group">
            <div class="col-sm-9 col-sm-offset-3">
              <div class="checkbox">
                <label>
                    <?= zen_draw_checkbox_field('is_enabled', 'on', !empty($form_values['is_enabled']) || $action === 'new', 'id="is_enabled"') ?>
                    <?= ENTRY_ENABLED ?>
                </label>
              </div>
            </div>
          </div>
          <div class="form-group">
            <div class="col-sm-9 col-sm-offset-3">
              <button type="submit" class="btn btn-primary"><?php echo IMAGE_SAVE; ?></button>
              <a class="btn btn-default" href="<?php echo zen_href_link(FILENAME_SHIPPING_BOXES); ?>"><?php echo IMAGE_CANCEL; ?></a>
            </div>
          </div>
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
