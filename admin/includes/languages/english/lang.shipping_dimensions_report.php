<?php
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: New in v3.0.0 $
 */

$define = [
    'HEADING_TITLE' => 'Products Missing Shipping Dimensions',
    'TEXT_INTRO' => 'Dimensional packing needs a length, width and height on every product that ships. Until these are filled in, carts containing them fall back to weight-only shipping quotes.',
    'TEXT_POLICY_LEGACY' => 'Your store is set to <strong>legacy</strong>: a cart containing any product below is quoted on weight alone, exactly as it was before packing was enabled.',
    'TEXT_POLICY_DEFAULT_BOX' => 'Your store is set to <strong>default_box</strong>: the products below are packed by weight into your default box.',
    'TEXT_PACKING_DISABLED' => 'Dimensional packing is currently switched off, so nothing here affects your shipping quotes yet.',
    'TABLE_HEADING_ID' => 'ID',
    'TABLE_HEADING_PRODUCT' => 'Product',
    'TABLE_HEADING_MODEL' => 'Model',
    'TABLE_HEADING_STATUS' => 'Status',
    'TABLE_HEADING_MISSING' => 'Missing',
    'TABLE_HEADING_WEIGHT' => 'Weight',
    'TEXT_STATUS_ENABLED' => 'Enabled',
    'TEXT_STATUS_DISABLED' => 'Disabled',
    'TEXT_AXIS_LENGTH' => 'Length',
    'TEXT_AXIS_WIDTH' => 'Width',
    'TEXT_AXIS_HEIGHT' => 'Height',
    'TEXT_SUMMARY' => '%1$s product(s) need dimensions: %2$s enabled, %3$s disabled.',
    'TEXT_SUMMARY_NO_WEIGHT' => '%s of these have no weight either, so they cannot be quoted by any method.',
    'TEXT_NONE_MISSING' => 'Every shippable product has dimensions. Nothing to do here.',
    'TEXT_EDIT_PRODUCT' => 'Edit',
    'TEXT_SHOW_DISABLED' => 'Include disabled products',
];
