<?php
/**
 * Packing plan explainer: shows how a cart would be parcelled, and why.
 *
 * Read-only by construction. It runs the same packer the quote path runs, through the
 * same adapter, so what it shows is what the storefront would do - an explainer that
 * built its own idea of a cart would be worse than no explainer at all.
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: New in v3.0.0 $
 */

use Zencart\Shipping\Packing\BoxRepository;
use Zencart\Shipping\Packing\CartPackingAdapter;
use Zencart\Shipping\Packing\PackingSchema;
use Zencart\Shipping\Packing\PackingService;
use Zencart\Shipping\Packing\PackingSettings;
use Zencart\Shipping\Packing\PlanExplainer;

require 'includes/application_top.php';

$ordersId = (int)($_GET['oID'] ?? $_POST['oID'] ?? 0);
$productsInput = (string)($_POST['products'] ?? '');

$messages = [];
$plan = null;
$request = null;
$lines = [];

$settings = [];
foreach (array_merge(PackingSchema::configKeys(), ['SHIPPING_DIMENSION_UNITS', 'SHIPPING_WEIGHT_UNITS']) as $key) {
    $settings[$key] = zen_config($key, null);
}

$catalog = BoxRepository::loadEnabled($db);
$context = PackingSettings::contextFrom($settings, catalogRevision: $catalog['revision']);
$packingEnabled = PackingSettings::isEnabled($settings);

if (!$packingEnabled) {
    $messages[] = ['class' => 'info', 'text' => TEXT_PACKING_DISABLED];
}
if ($catalog['boxes'] === []) {
    $messages[] = ['class' => 'warning', 'text' => TEXT_NO_BOXES];
}

$quantities = PlanExplainer::parseQuantities($productsInput);

if ($quantities === [] && $ordersId > 0) {
    $orderLines = $db->Execute(PlanExplainer::orderLinesSql($ordersId));
    while (!$orderLines->EOF) {
        $productId = (int)$orderLines->fields['products_id'];
        $quantities[$productId] = ($quantities[$productId] ?? 0.0) + (float)$orderLines->fields['products_quantity'];
        $orderLines->MoveNext();
    }
    if ($quantities === []) {
        $messages[] = ['class' => 'warning', 'text' => sprintf(TEXT_ORDER_HAS_NO_LINES, $ordersId)];
    }
}

$submitted = $ordersId > 0 || $productsInput !== '';

if ($submitted && $quantities === [] && $messages === []) {
    $messages[] = ['class' => 'warning', 'text' => TEXT_NO_INPUT];
}

if ($quantities !== []) {
    $rows = [];
    $productsSql = PlanExplainer::productsSql(array_keys($quantities), (int)$_SESSION['languages_id']);
    if ($productsSql !== '') {
        $result = $db->Execute($productsSql);
        while (!$result->EOF) {
            $rows[] = $result->fields;
            $result->MoveNext();
        }
    }

    $built = PlanExplainer::cartLines($rows, $quantities);
    $lines = $built['lines'];
    if ($built['missing'] !== []) {
        $messages[] = [
            'class' => 'warning',
            'text' => sprintf(TEXT_PRODUCTS_NOT_FOUND, implode(', ', $built['missing'])),
        ];
    }

    if ($lines !== []) {
        // The same enrichment and the same service the quote path uses, so this page
        // cannot explain a plan the storefront would not produce.
        $facts = CartPackingAdapter::enrichFromDatabase($db, $lines);
        $request = (new CartPackingAdapter())->fromCartProducts($facts['products'], $context, $facts['downloadProductIds']);
        $plan = (new PackingService())->planFor($facts['products'], $catalog['boxes'], $context, $facts['downloadProductIds']);

        if ($request->items === [] && !$request->exceedsUnitCeiling) {
            $messages[] = ['class' => 'info', 'text' => TEXT_NOTHING_TO_PACK];
        }
    }
}

// The packer knows items only by uprid, which on this page is the product id. An
// explainer that answered "why is this three parcels?" with a column of bare numbers
// would make the reader go and look each one up.
$productNames = [];
foreach ($lines as $line) {
    $productNames[(string)$line['id']] = trim(($line['name'] ?? '') . ' (#' . $line['id'] . ')');
}
$itemLabel = static function (string $uprid) use ($productNames): string {
    return zen_output_string_protected($productNames[$uprid] ?? $uprid);
};

$dimUnits = zen_output_string_protected($context->dimUnits);
$weightUnits = zen_output_string_protected($context->weightUnits);
$axes = static fn (object $d): string => number_format($d->length, 2) . ' &times; '
    . number_format($d->width, 2) . ' &times; ' . number_format($d->height, 2);
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

        <?php foreach ($messages as $message) { ?>
          <div class="alert alert-<?php echo $message['class']; ?>"><?php echo $message['text']; ?></div>
        <?php } ?>

        <?php echo zen_draw_form('explain', FILENAME_SHIPPING_PLAN_EXPLAINER, '', 'post', 'class="form-horizontal"'); ?>
        <div class="form-group">
            <?= zen_draw_label(ENTRY_ORDER, 'oID', 'class="col-sm-3 control-label"') ?>
          <div class="col-sm-6">
            <div class="form-inline">
                <?= zen_draw_input_field('oID', $ordersId > 0 ? (string)$ordersId : '', 'size="8" class="form-control" id="oID"', false, 'number') ?>
            </div>
            <p class="help-block"><?= ENTRY_ORDER_INFO ?></p>
          </div>
        </div>
        <div class="form-group">
            <?= zen_draw_label(ENTRY_PRODUCTS, 'products', 'class="col-sm-3 control-label"') ?>
          <div class="col-sm-6">
              <?= zen_draw_textarea_field('products', 'soft', 40, 4, zen_output_string_protected($productsInput), 'class="form-control" id="products"') ?>
            <p class="help-block"><?= ENTRY_PRODUCTS_INFO ?></p>
          </div>
        </div>
        <div class="form-group">
          <div class="col-sm-9 col-sm-offset-3">
            <button type="submit" class="btn btn-primary"><?php echo BUTTON_EXPLAIN; ?></button>
          </div>
        </div>
        <?php echo '</form>' ?>

      <h2><?php echo HEADING_SETTINGS; ?></h2>
      <table class="table table-condensed">
        <tbody>
          <tr><td><?php echo TEXT_SETTING_ENABLED; ?></td><td><?php echo $packingEnabled ? TEXT_ON : TEXT_OFF; ?></td></tr>
          <tr><td><?php echo TEXT_SETTING_POLICY; ?></td><td><?php echo zen_output_string_protected($context->missingDimensionsPolicy); ?></td></tr>
          <tr><td><?php echo TEXT_SETTING_MARGIN; ?></td><td><?php echo number_format($context->interiorMarginPercent, 2); ?>%</td></tr>
          <tr><td><?php echo TEXT_SETTING_MAX_UNITS; ?></td><td><?php echo (int)$context->maxUnits; ?></td></tr>
          <tr><td><?php echo TEXT_SETTING_GIRTH; ?></td>
              <td><?php echo $context->maxGirth > 0 ? number_format($context->maxGirth, 2) . ' ' . $dimUnits : TEXT_NO_LIMIT; ?></td></tr>
          <tr><td><?php echo TEXT_SETTING_BOXES; ?></td><td><?php echo count($catalog['boxes']); ?></td></tr>
          <tr><td><?php echo TEXT_SETTING_UNITS; ?></td><td><?php echo $dimUnits . ' / ' . $weightUnits; ?></td></tr>
        </tbody>
      </table>

        <?php if ($plan !== null && $request !== null) { ?>
          <h2><?php echo HEADING_RESULT; ?></h2>
          <p>
              <?php
                echo sprintf(TEXT_PARCELS, $plan->packageCount());
                if ($plan->quotablePackageCount() !== $plan->packageCount()) {
                    echo ' ' . sprintf(TEXT_PARCELS_QUOTED, $plan->quotablePackageCount());
                }
                echo '<br>' . sprintf(TEXT_UNITS_PACKED, $request->unitCount(), count($lines));
                echo '<br>' . sprintf(TEXT_PACKER, zen_output_string_protected($plan->packerCode));
                echo '<br><small class="text-muted">' . sprintf(TEXT_PLAN_HASH, zen_output_string_protected(substr($plan->planHash, 0, 12))) . '</small>';
              ?>
          </p>

            <?php $caveats = PlanExplainer::caveats($plan, $request); ?>
            <?php if ($caveats !== []) { ?>
              <h3><?php echo HEADING_CAVEATS; ?></h3>
                <?php foreach ($caveats as $caveat) { ?>
                  <div class="alert alert-warning"><?php echo constant($caveat); ?></div>
                <?php } ?>
                <?php if ($request->hasMissingDimensions()) { ?>
                  <p class="text-muted"><?php echo sprintf(
                      TEXT_MISSING_DIMENSIONS_LIST,
                      implode(', ', array_map($itemLabel, $request->missingDimensionUprids))
                  ); ?></p>
                <?php } ?>
            <?php } ?>

            <?php foreach ($plan->packages as $index => $package) { ?>
              <h3><?php echo sprintf(HEADING_PARCEL, $index + 1, $plan->packageCount()); ?></h3>
              <table class="table table-condensed">
                <tbody>
                  <tr>
                    <td><?php echo TEXT_BOX; ?></td>
                    <td><?php echo $package->boxType === null
                            ? TEXT_NO_BOX
                            : zen_output_string_protected($package->boxType->code . ' - ' . $package->boxType->title); ?></td>
                  </tr>
                  <tr><td><?php echo TEXT_EXTERIOR; ?></td><td><?php echo $axes($package->exterior) . ' ' . $dimUnits; ?></td></tr>
                  <tr><td><?php echo TEXT_GIRTH; ?></td><td><?php echo number_format($package->exterior->girth(), 2) . ' ' . $dimUnits; ?></td></tr>
                    <?php $fill = PlanExplainer::fillPercent($package, $context->interiorMarginPercent); ?>
                    <?php if ($fill !== null) { ?>
                      <tr><td><?php echo TEXT_FILL; ?></td><td><?php echo number_format($fill, 1); ?>%</td></tr>
                    <?php } ?>
                  <tr>
                    <td><?php echo TEXT_WEIGHT; ?></td>
                    <td><?php echo number_format($package->weight->value, 2) . ' ' . $weightUnits
                        . ' (' . TEXT_TARE . ' ' . number_format($package->tare->value, 2) . ')'; ?></td>
                  </tr>
                  <tr>
                    <td><?php echo TEXT_CONTENTS; ?></td>
                    <td>
                      <ul class="list-unstyled">
                          <?php foreach ($package->contents as $packed) { ?>
                            <li>
                                <?php echo $itemLabel($packed->item->uprid); ?>
                                &mdash; <?php echo TEXT_PLACED_AS . ' ' . $axes($packed->placedAs); ?>
                                <?php echo TEXT_PLACED_AT; ?>
                                (<?php echo number_format($packed->placedAt->x, 2) . ', '
                                    . number_format($packed->placedAt->y, 2) . ', '
                                    . number_format($packed->placedAt->z, 2); ?>)
                            </li>
                          <?php } ?>
                      </ul>
                    </td>
                  </tr>
                </tbody>
              </table>
            <?php } ?>

            <?php if ($plan->unpackable !== []) { ?>
              <h3><?php echo HEADING_UNPACKABLE; ?></h3>
              <p><?php echo TEXT_UNPACKABLE_INFO; ?></p>
              <ul>
                  <?php foreach ($plan->unpackable as $item) { ?>
                    <li><?php echo $itemLabel($item->uprid) . ' &mdash; ' . $axes($item->dimensions) . ' ' . $dimUnits; ?></li>
                  <?php } ?>
              </ul>
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
