<?php
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: New in v3.0.0 $
 */

$define = [
    'HEADING_TITLE' => 'Packing Plan Explainer',
    'TEXT_INTRO' => 'Work out how a cart would be parcelled, and why. Enter an order number to use that order\'s items, or list products yourself. Nothing here changes an order or a quote.',

    'ENTRY_ORDER' => 'Order number',
    'ENTRY_ORDER_INFO' => 'Uses the items on that order, measured with today\'s product dimensions.',
    'ENTRY_PRODUCTS' => 'Or list products',
    'ENTRY_PRODUCTS_INFO' => 'One per line, as <em>product id x quantity</em> &mdash; for example <em>12 x 3</em>. A bare id means one.',
    'BUTTON_EXPLAIN' => 'Explain',

    'HEADING_SETTINGS' => 'Settings in force',
    'TEXT_SETTING_ENABLED' => 'Dimensional packing',
    'TEXT_SETTING_POLICY' => 'Missing dimensions policy',
    'TEXT_SETTING_MARGIN' => 'Packing allowance',
    'TEXT_SETTING_MAX_UNITS' => 'Max units modelled',
    'TEXT_SETTING_GIRTH' => 'Max girth',
    'TEXT_SETTING_BOXES' => 'Enabled boxes',
    'TEXT_SETTING_UNITS' => 'Units',
    'TEXT_ON' => 'On',
    'TEXT_OFF' => 'Off',
    'TEXT_NO_LIMIT' => 'No limit',

    'HEADING_RESULT' => 'Result',
    'TEXT_PARCELS' => '%s parcel(s)',
    'TEXT_PARCELS_QUOTED' => 'Quoted as %s parcel(s).',
    'TEXT_PACKER' => 'Packer: %s',
    'TEXT_PLAN_HASH' => 'Plan: %s',
    'TEXT_UNITS_PACKED' => '%1$s unit(s) from %2$s line(s).',

    'HEADING_PARCEL' => 'Parcel %1$s of %2$s',
    'TEXT_BOX' => 'Box',
    'TEXT_NO_BOX' => 'No box fits this item',
    'TEXT_EXTERIOR' => 'Exterior',
    'TEXT_FILL' => 'Interior used',
    'TEXT_WEIGHT' => 'Weight',
    'TEXT_TARE' => 'of which box',
    'TEXT_GIRTH' => 'Girth',
    'TEXT_CONTENTS' => 'Contents',
    'TEXT_PLACED_AT' => 'at',
    'TEXT_PLACED_AS' => 'as',

    'HEADING_UNPACKABLE' => 'Items that did not fit any box',
    'TEXT_UNPACKABLE_INFO' => 'These are quoted as parcels of their own size, and the order is flagged. Add a box that fits them, or correct their dimensions.',

    'HEADING_CAVEATS' => 'Why this plan is not the whole story',
    'TEXT_CAVEAT_FALLBACK' => 'No plan was produced, so quoting falls back to weight only, exactly as it behaved before packing was enabled. That happens when packing is off, no boxes are enabled, or the missing-dimensions policy is <em>legacy</em> and something here has no dimensions.',
    'TEXT_CAVEAT_APPROXIMATED' => 'This cart is larger than the max units modelled, so the parcel count is extrapolated from a sample rather than packed in full. Such an order gets no per-parcel packing slip.',
    'TEXT_CAVEAT_CEILING' => 'This cart exceeds the hard unit ceiling and was not modelled at all.',
    'TEXT_CAVEAT_MISSING_DIMENSIONS' => 'Some products have no usable length, width or height. Under the <em>legacy</em> policy one such product reverts the whole cart to weight-only quoting.',
    'TEXT_CAVEAT_EXCLUDED' => 'Some lines were left out because they never ship: virtual products, gift certificates, and always-free-shipping products.',
    'TEXT_CAVEAT_UNPACKABLE' => 'Some items fit no enabled box.',

    'TEXT_MISSING_DIMENSIONS_LIST' => 'No dimensions: %s',
    'TEXT_PRODUCTS_NOT_FOUND' => 'Not found, and left out: %s',
    'TEXT_NO_INPUT' => 'Enter an order number or list some products.',
    'TEXT_ORDER_HAS_NO_LINES' => 'Order %s has no products on it.',
    'TEXT_NOTHING_TO_PACK' => 'Nothing here needs packing: every line is virtual, a gift certificate, or always-free-shipping.',
    'TEXT_PACKING_DISABLED' => 'Dimensional packing is switched off, so this is what <em>would</em> happen if you turned it on. Your live quotes are unaffected.',
    'TEXT_NO_BOXES' => 'No enabled boxes are defined, so nothing can be packed. Define a box first.',
];

return $define;
