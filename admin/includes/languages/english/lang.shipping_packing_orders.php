<?php
/**
 * Wording for the parcel breakdown shown on an order.
 *
 * Loaded by zcObserverShippingPackingOrderDisplay rather than by a page, because the
 * breakdown is injected through a notifier and so has no page language file of its own.
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: New in v3.0.0 $
 */

$define = [
    'PACKING_ORDERS_HEADING' => 'Parcels',
    'PACKING_ORDERS_SUMMARY' => 'Quoted as %s parcel(s).',
    'PACKING_ORDERS_PARCEL' => 'Parcel %1$s of %2$s',
    'PACKING_ORDERS_COLUMN_PARCEL' => 'Parcel',
    'PACKING_ORDERS_BOX' => 'Box',
    'PACKING_ORDERS_NO_BOX' => 'No box &mdash; quoted at the item&rsquo;s own size',
    'PACKING_ORDERS_SIZE' => 'Size',
    'PACKING_ORDERS_WEIGHT' => 'Weight',
    'PACKING_ORDERS_ITEMS' => 'Items',
    'PACKING_ORDERS_BADGE_RECONSTRUCTED' => 'Reconstructed',
    'PACKING_ORDERS_BADGE_STALE' => 'Out of date',
    'PACKING_ORDERS_CAVEAT' => 'This breakdown may no longer match the order. It was either rebuilt after the order was placed rather than recorded from the quote the customer accepted, or the order&rsquo;s lines changed afterwards. Treat it as a guide and check the goods before shipping.',
];

return $define;
