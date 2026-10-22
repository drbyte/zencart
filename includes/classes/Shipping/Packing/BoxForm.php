<?php
declare(strict_types=1);
/**
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */

namespace Zencart\Shipping\Packing;

/**
 * Validates and normalizes a box-catalog form submission.
 *
 * Separate from the admin page so the rules are testable without a request, and because
 * these rules are the only thing standing between a store owner and a box the packer
 * cannot use. A row with a zero interior is skipped silently at load time (see
 * BoxRepository), so catching it here is the difference between an error message and a
 * box that mysteriously never gets chosen.
 */
final class BoxForm
{
    /**
     * Shipping module codes are filenames without the extension, so they are lowercase
     * and limited to what a PHP class name allows. The length matches the column.
     */
    public const CARRIER_CODE_PATTERN = '/^[a-z0-9_]{1,32}$/';

    /**
     * Normalize a submission and collect any problems.
     *
     * Errors are returned as language-constant names with substitution values, so the
     * page owns the wording and this class owns the rules.
     *
     * @param array<string, mixed> $input raw POST values
     * @return array{values: array<string, mixed>, errors: array<int, array{key: string, args: array<int, mixed>}>}
     */
    public static function validate(array $input): array
    {
        $errors = [];

        $code = strtoupper(trim((string)($input['box_code'] ?? '')));
        if ($code === '') {
            $errors[] = ['key' => 'ERROR_BOX_CODE_REQUIRED', 'args' => []];
        } elseif (!preg_match('/^[A-Z0-9_.\-]{1,32}$/', $code)) {
            // The code is a stable reference used by the default-box setting and
            // snapshotted onto every parcel, so it is kept to characters that survive
            // being typed into a config field and read off a packing slip.
            $errors[] = ['key' => 'ERROR_BOX_CODE_INVALID', 'args' => []];
        }

        $title = trim((string)($input['box_title'] ?? ''));

        $dimUnits = Units::isKnown($input['dim_units'] ?? null)
            ? Units::canonical((string)$input['dim_units'])
            : Units::INCHES;
        $weightUnits = Units::isKnown($input['weight_units'] ?? null)
            ? Units::canonical((string)$input['weight_units'])
            : Units::POUNDS;

        $interior = [];
        $exterior = [];
        foreach (['length', 'width', 'height'] as $axis) {
            $interior[$axis] = self::toFloat($input['interior_' . $axis] ?? null);
            $exterior[$axis] = self::toFloat($input['exterior_' . $axis] ?? null);

            if ($interior[$axis] <= 0.0) {
                $errors[] = ['key' => 'ERROR_INTERIOR_REQUIRED', 'args' => [$axis]];
            }
        }

        // An unspecified exterior falls back to the interior, matching how
        // BoxRepository reads a row that has none: the parcel still has to report a
        // carrier-facing size.
        foreach (['length', 'width', 'height'] as $axis) {
            if ($exterior[$axis] <= 0.0) {
                $exterior[$axis] = $interior[$axis];
            } elseif ($exterior[$axis] + Dimensions::EPSILON < $interior[$axis]) {
                // A box cannot be smaller outside than inside. Left unchecked this
                // under-reports the parcel to the carrier on every quote.
                $errors[] = ['key' => 'ERROR_EXTERIOR_SMALLER', 'args' => [$axis]];
            }
        }

        $tare = self::toFloat($input['tare_weight'] ?? null);
        if ($tare < 0.0) {
            $errors[] = ['key' => 'ERROR_TARE_NEGATIVE', 'args' => []];
        }

        $maxPayload = self::toFloat($input['max_payload_weight'] ?? null);
        if ($maxPayload < 0.0) {
            $errors[] = ['key' => 'ERROR_PAYLOAD_NEGATIVE', 'args' => []];
        }

        $margin = trim((string)($input['interior_margin'] ?? ''));
        $marginValue = $margin === '' ? null : self::toFloat($margin);
        if ($marginValue !== null && $marginValue < 0.0) {
            $errors[] = ['key' => 'ERROR_MARGIN_NEGATIVE', 'args' => []];
        }
        if ($marginValue !== null && $marginValue > 0.0) {
            foreach (['length', 'width', 'height'] as $axis) {
                if (2.0 * $marginValue >= $interior[$axis] && $interior[$axis] > 0.0) {
                    // A margin that consumes the interior leaves a box nothing can fit
                    // into, which presents as "this box is never used".
                    $errors[] = ['key' => 'ERROR_MARGIN_TOO_LARGE', 'args' => [$axis]];
                }
            }
        }

        $scope = strtolower(trim((string)($input['destination_scope'] ?? BoxType::SCOPE_BOTH)));
        if (!in_array($scope, [BoxType::SCOPE_BOTH, BoxType::SCOPE_DOMESTIC, BoxType::SCOPE_INTERNATIONAL], true)) {
            $scope = BoxType::SCOPE_BOTH;
        }

        $carrier = self::normalizeCarrierCode((string)($input['carrier_code'] ?? ''));
        if ($carrier !== '' && !preg_match(self::CARRIER_CODE_PATTERN, $carrier)) {
            // The value is matched against the code a shipping module reports, which is
            // its filename without the extension, so anything outside that character set
            // can never match and would silently disable the box.
            $errors[] = ['key' => 'ERROR_CARRIER_CODE_INVALID', 'args' => [$carrier]];
            $carrier = '';
        }

        return [
            'values' => [
                'box_code' => $code,
                'box_title' => $title === '' ? $code : $title,
                'interior_length' => $interior['length'],
                'interior_width' => $interior['width'],
                'interior_height' => $interior['height'],
                'exterior_length' => $exterior['length'],
                'exterior_width' => $exterior['width'],
                'exterior_height' => $exterior['height'],
                'interior_margin' => $marginValue,
                'dim_units' => $dimUnits,
                'tare_weight' => $tare,
                'max_payload_weight' => $maxPayload > 0.0 ? $maxPayload : null,
                'weight_units' => $weightUnits,
                'destination_scope' => $scope,
                'carrier_code' => $carrier === '' ? null : $carrier,
                'is_enabled' => !empty($input['is_enabled']) ? 1 : 0,
                'sort_order' => (int)($input['sort_order'] ?? 0),
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Render validated values as a row zen_db_perform can store.
     *
     * validate() returns nulls where null is the right domain meaning - no payload
     * limit, no carrier restriction, no per-box margin - and those are the common case,
     * not the exotic one. Handing them straight to zen_db_perform fails the insert
     * silently, so the translation happens here, in one named place the page cannot
     * skip, rather than being remembered at each call site.
     *
     * @param array<string, mixed> $values from validate()['values']
     * @return array<string, mixed>
     */
    public static function toDatabaseRow(array $values, bool $touchLastModified = true): array
    {
        $row = PackingSchema::dbRow($values);
        if ($touchLastModified) {
            $row['last_modified'] = 'now()';
        }

        return $row;
    }

    /**
     * Fill an exterior from an interior plus a uniform wall thickness, for the admin
     * convenience button. Thickness is per wall, so each axis grows by twice it.
     *
     * @return array{length: float, width: float, height: float}
     */
    public static function exteriorFromThickness(Dimensions $interior, float $thickness): array
    {
        $grow = static fn (float $axis): float => $axis + 2.0 * max(0.0, $thickness);

        return [
            'length' => $grow($interior->length),
            'width' => $grow($interior->width),
            'height' => $grow($interior->height),
        ];
    }

    /**
     * The carrier codes a store can restrict a box to, for the admin dropdown.
     *
     * The authoritative list is the installed-modules cache, which is a
     * semicolon-separated list of module filenames and includes plugin-provided
     * modules. A free-text field here is a typo waiting to happen, because a code that
     * does not match is not an error - the box is simply never offered.
     *
     * $current is kept in the list even when it is not installed, so editing a box
     * whose carrier module has been uninstalled or renamed does not silently drop the
     * restriction on the next save.
     *
     * @param string $installedList value of MODULE_SHIPPING_INSTALLED
     * @return string[] sorted codes, no blank entry
     */
    public static function carrierCodes(string $installedList, ?string $current = null): array
    {
        $codes = [];
        foreach (explode(';', $installedList) as $file) {
            $code = self::normalizeCarrierCode(pathinfo(trim($file), PATHINFO_FILENAME));
            if ($code !== '' && preg_match(self::CARRIER_CODE_PATTERN, $code)) {
                $codes[$code] = $code;
            }
        }

        $current = self::normalizeCarrierCode((string)$current);
        if ($current !== '') {
            $codes[$current] = $current;
        }

        ksort($codes);

        return array_values($codes);
    }

    private static function normalizeCarrierCode(string $value): string
    {
        return strtolower(trim($value));
    }

    private static function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float)str_replace(',', '.', (string)$value);
    }
}
