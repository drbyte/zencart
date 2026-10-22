<?php
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: New in v3.0.0 $
 */

$define = [
    'HEADING_TITLE' => 'Shipping Boxes',
    'TEXT_INTRO' => 'The boxes your store actually stocks. Dimensional packing fits cart items into these, so quotes reflect parcels you can really send. Interior is what items must fit inside; exterior is what the carrier measures and bills.',
    'TEXT_NO_BOXES_YET' => 'No boxes defined yet. Dimensional packing cannot quote until at least one box exists, so carts fall back to weight-only shipping.',

    'TABLE_HEADING_CODE' => 'Code',
    'TABLE_HEADING_TITLE' => 'Name',
    'TABLE_HEADING_INTERIOR' => 'Interior (L x W x H)',
    'TABLE_HEADING_EXTERIOR' => 'Exterior (L x W x H)',
    'TABLE_HEADING_MAX_PAYLOAD' => 'Max Contents',
    'TABLE_HEADING_SCOPE' => 'Destinations',
    'TABLE_HEADING_ENABLED' => 'Enabled',
    'TABLE_HEADING_ACTION' => 'Action',

    'ENTRY_BOX_CODE' => 'Code',
    'ENTRY_BOX_CODE_INFO' => 'A short stable reference, e.g. M12. Letters, digits, dot, dash and underscore only. It is recorded on every parcel and used by the default-box setting, so avoid changing it once orders exist.',
    'ENTRY_BOX_TITLE' => 'Name',
    'ENTRY_INTERIOR' => 'Interior L x W x H',
    'ENTRY_INTERIOR_INFO' => 'The usable space inside. Items are fitted against this.',
    'ENTRY_EXTERIOR' => 'Exterior L x W x H',
    'ENTRY_EXTERIOR_INFO' => 'What the carrier measures. Leave blank to use the interior; it can never be smaller than the interior.',
    'ENTRY_DIM_UNITS' => 'Dimension units',
    'ENTRY_INTERIOR_MARGIN' => 'Packing allowance',
    'ENTRY_INTERIOR_MARGIN_INFO' => 'Optional, per side, in the units above. Overrides the store-wide percentage for this box. Enter 0 for a box that needs no allowance; leave blank to use the store setting.',
    'ENTRY_TARE_WEIGHT' => 'Empty box weight',
    'ENTRY_TARE_WEIGHT_INFO' => 'Added to the contents when the parcel weight is quoted.',
    'ENTRY_MAX_PAYLOAD' => 'Max contents weight',
    'ENTRY_MAX_PAYLOAD_INFO' => 'Most this box may hold, excluding the box itself. Leave blank or 0 for no limit.',
    'ENTRY_WEIGHT_UNITS' => 'Weight units',
    'ENTRY_DESTINATION_SCOPE' => 'Destinations',
    'ENTRY_CARRIER_CODE' => 'Carrier only',
    'ENTRY_CARRIER_CODE_INFO' => 'Leave blank for ordinary stock. Set it only for carrier-supplied packaging, e.g. a flat-rate envelope, so it is offered only when quoting that carrier.',
    'ENTRY_SORT_ORDER' => 'Sort order',
    'ENTRY_ENABLED' => 'Enabled',

    'TEXT_SCOPE_BOTH' => 'Domestic and international',
    'TEXT_SCOPE_DOMESTIC' => 'Domestic only',
    'TEXT_SCOPE_INTERNATIONAL' => 'International only',
    'TEXT_STATUS_ENABLED' => 'Yes',
    'TEXT_STATUS_DISABLED' => 'No',
    'TEXT_UNLIMITED' => 'No limit',
    'TEXT_CONFIRM_DELETE' => 'Delete box %s? Orders already packed keep their own record of it.',
    'IMAGE_NEW_BOX' => 'New Box',

    'ERROR_BOX_CODE_REQUIRED' => 'A box code is required.',
    'ERROR_BOX_CODE_INVALID' => 'A box code may be up to 32 characters of letters, digits, dot, dash or underscore.',
    'ERROR_BOX_CODE_DUPLICATE' => 'The box code %s is already in use.',
    'ERROR_INTERIOR_REQUIRED' => 'The interior %s must be greater than zero, or nothing can be packed into this box.',
    'ERROR_EXTERIOR_SMALLER' => 'The exterior %s cannot be smaller than the interior, or the carrier would be told the wrong parcel size.',
    'ERROR_TARE_NEGATIVE' => 'The empty box weight cannot be negative.',
    'ERROR_PAYLOAD_NEGATIVE' => 'The maximum contents weight cannot be negative.',
    'ERROR_MARGIN_NEGATIVE' => 'The packing allowance cannot be negative.',
    'ERROR_MARGIN_TOO_LARGE' => 'The packing allowance leaves no usable %s, so nothing would ever fit in this box.',
];
