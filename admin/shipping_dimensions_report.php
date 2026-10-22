<?php
/**
 * Lists products that still need shipping dimensions before dimensional packing can
 * quote carts containing them.
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: New in v3.0.0 $
 */

use Zencart\Shipping\Packing\MissingDimensionsReport;
use Zencart\Shipping\Packing\PackingContext;
use Zencart\Shipping\Packing\PackingSettings;

require 'includes/application_top.php';

$showDisabled = isset($_GET['show_disabled']) && $_GET['show_disabled'] === '1';

$rows = [];
$reportResult = $db->Execute(MissingDimensionsReport::sql((int)$_SESSION['languages_id']));
while (!$reportResult->EOF) {
    $rows[] = $reportResult->fields;
    $reportResult->MoveNext();
}

$summary = MissingDimensionsReport::summarize($rows);

// Products that are unmeasured but can never ship. Counted, not listed: measuring them
// is not deferred work, it is work that must not be done, so rows would invite it.
$excludedCount = (int)$db->Execute(MissingDimensionsReport::excludedCountSql())->fields['total'];

if (!$showDisabled) {
    $rows = array_values(array_filter($rows, static fn (array $row): bool => (int)$row['products_status'] === 1));
}

$packingEnabled = PackingSettings::isEnabled(['SHIPPING_PACKING_ENABLED' => zen_config('SHIPPING_PACKING_ENABLED')]);
$policy = PackingSettings::contextFrom([
    'SHIPPING_PACKING_MISSING_DIMS_POLICY' => zen_config('SHIPPING_PACKING_MISSING_DIMS_POLICY'),
])->missingDimensionsPolicy;

$axisLabels = [
    'length' => TEXT_AXIS_LENGTH,
    'width' => TEXT_AXIS_WIDTH,
    'height' => TEXT_AXIS_HEIGHT,
];
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
      <p>
          <?php
            if (!$packingEnabled) {
                echo TEXT_PACKING_DISABLED;
            } elseif ($policy === PackingContext::POLICY_DEFAULT_BOX) {
                echo TEXT_POLICY_DEFAULT_BOX;
            } else {
                echo TEXT_POLICY_LEGACY;
            }
          ?>
      </p>

        <?php if ($summary['total'] === 0) { ?>
          <div class="alert alert-success"><?php echo TEXT_NONE_MISSING; ?></div>
        <?php } else { ?>
          <div class="alert alert-warning">
              <?php
                echo sprintf(TEXT_SUMMARY, $summary['total'], $summary['enabled'], $summary['disabled']);
                if ($summary['no_weight'] > 0) {
                    echo ' ' . sprintf(TEXT_SUMMARY_NO_WEIGHT, $summary['no_weight']);
                }
              ?>
          </div>

          <p class="text-muted"><?php echo TEXT_ATTRIBUTE_NOTE; ?></p>

            <?php if ($excludedCount > 0) { ?>
            <p class="text-muted"><?php echo sprintf(TEXT_EXCLUDED_NOTE, $excludedCount); ?></p>
            <?php } ?>

          <p>
            <a href="<?php echo zen_href_link(FILENAME_SHIPPING_DIMENSIONS_REPORT, 'show_disabled=' . ($showDisabled ? '0' : '1')); ?>">
                <?php echo TEXT_SHOW_DISABLED; ?><?php echo $showDisabled ? ' &check;' : ''; ?>
            </a>
          </p>

          <table class="table table-hover">
            <thead>
              <tr class="dataTableHeadingRow">
                <th class="dataTableHeadingContent right"><?php echo TABLE_HEADING_ID; ?></th>
                <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_PRODUCT; ?></th>
                <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_MODEL; ?></th>
                <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_MISSING; ?></th>
                <th class="dataTableHeadingContent right"><?php echo TABLE_HEADING_WEIGHT; ?></th>
                <th class="dataTableHeadingContent"><?php echo TABLE_HEADING_STATUS; ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row) {
                $missing = array_map(static fn (string $axis): string => $axisLabels[$axis], MissingDimensionsReport::missingAxes($row));
                $productId = (int)$row['products_id'];
                ?>
                <tr class="dataTableRow">
                  <td class="dataTableContent right"><?php echo $productId; ?></td>
                  <td class="dataTableContent">
                    <a href="<?php echo zen_href_link(FILENAME_PRODUCT, 'action=new_product&cPath=' . zen_get_product_path($productId) . '&pID=' . $productId . '&product_type=' . zen_get_products_type($productId)); ?>">
                        <?php echo zen_output_string_protected($row['products_name'] ?? ''); ?>
                    </a>
                  </td>
                  <td class="dataTableContent"><?php echo zen_output_string_protected($row['products_model'] ?? ''); ?></td>
                  <td class="dataTableContent"><?php echo implode(', ', $missing); ?></td>
                  <td class="dataTableContent right"><?php echo (float)($row['products_weight'] ?? 0) > 0 ? number_format((float)$row['products_weight'], 2) : '&mdash;'; ?></td>
                  <td class="dataTableContent"><?php echo (int)$row['products_status'] === 1 ? TEXT_STATUS_ENABLED : TEXT_STATUS_DISABLED; ?></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        <?php } ?>
      <!-- body_eof //-->
    </div>
    <!-- footer //-->
    <?php require DIR_WS_INCLUDES . 'footer.php'; ?>
    <!-- footer_eof //-->
  </body>
</html>
<?php require DIR_WS_INCLUDES . 'application_bottom.php'; ?>
