---
paths:
  - "includes/classes/Shipping/**"
  - "includes/modules/shipping/**"
  - "zc_plugins/**/catalog/includes/modules/shipping/**"
---

# Dimensional shipping: consuming a packing plan

How a shipping module gets per-parcel dimensions and weights from Zen Cart's
dimensional packing, so it can quote on parcel size instead of on a notional box.

Core ships no dimensional carrier module, so **every consumer of a plan is a plugin**.
This is the supported integration surface; it is public API from its first release.

## The short version

```php
use Zencart\Shipping\Packing\QuoteAdapter;

public function quote($method = '')
{
    global $shipping_weight, $shipping_num_boxes;

    $plan = class_exists('\Zencart\Shipping\Packing\QuoteAdapter')
        ? QuoteAdapter::plan()
        : null;

    if ($plan === null) {
        // Behave exactly as you did before packing existed.
        return $this->quoteFromWeight($shipping_weight, $shipping_num_boxes);
    }

    $boxes = QuoteAdapter::packageCount($plan, $shipping_num_boxes);

    // Rate each parcel on its own size, and sum. The accurate path.
    foreach (QuoteAdapter::parcels($plan, 'inches', 'pounds', $_SESSION['cart']->get_products()) as $parcel) {
        $this->addPackage($parcel['length'], $parcel['width'], $parcel['height'], $parcel['weight'], $parcel['value']);
    }

    return $this->rateAllPackages();
}
```

## Rules

These are not style preferences. Each one prevents a specific failure seen in a real
module.

1. **Guard with `class_exists()` before touching the class.** A module installed on a
   Zen Cart without dimensional packing must still work. `QuoteAdapter::plan()` itself is
   safe once the class exists, but referencing the class at all is not.
2. **A null plan means behave exactly as before.** `plan()` returns null for: packing
   switched off, no boxes defined yet, a cart containing a product with no dimensions,
   and a plan that could not cover the cart. All four are normal. Do not quote a
   zero-sized package, and do not disable yourself.
3. **Never write `$shipping_num_boxes`.** `shipping::quote()` saves and restores
   `$shipping_weight` around each module call but **not** the box count, so assigning it
   leaks your value into every module quoted after yours. Read
   `QuoteAdapter::packageCount($plan, $shipping_num_boxes)` into a local instead.
4. **Use the count the adapter gives you, not `count($plan->packages)`.** For a very
   large cart the packer samples and extrapolates; `packageCount()` returns the
   extrapolated figure. Counting the array would quote a fraction of the real parcels.
5. **Ask for the units you need.** `parcels()` and `boundingParcel()` convert for you:
   `'inches'`, `'centimeters'`, `'mm'`, `'pounds'`, `'kgs'`, `'grams'`. Do not convert
   yourself; the plan's own units are the store's, not yours.
6. **Do not disturb the plan.** It is shared with the order record and with every other
   module quoting this request.

## Choosing a method

| Your module… | Use | Why |
|---|---|---|
| can rate several packages in one request, or make one request per parcel | `parcels()` | Exact. Each parcel rated on its own size and insured for its own contents |
| makes one rate request and multiplies by a box count | `boundingParcel()` | Approximate but safe |
| classifies against size limits or bands rather than rating | `parcels()` | Each parcel reports `volume` and `girth` |
| quotes on weight only | nothing | You gain nothing, and changing the box count would reprice. Leave it alone |

### `parcels()` — the accurate path

```php
$parcels = QuoteAdapter::parcels($plan, 'centimeters', 'kgs', $_SESSION['cart']->get_products());
```

Each entry:

| Key | Meaning |
|---|---|
| `sequence`, `count` | 1-based parcel number, and the total ("2 of 3") |
| `length`, `width`, `height` | **Box exterior** — what the carrier measures and bills |
| `volume`, `girth` | Measured after conversion. Girth is longest edge + 2×(other two) |
| `weight` | Contents plus the box's own tare weight |
| `billable_weight` | `max(actual, dimensional)`, or null if you supplied no divisor |
| `value` | This parcel's contents, for insurance. Zero if you passed no cart products |
| `box_code`, `dim_units`, `weight_units` | The box's catalog code; the units above |

Pass the cart products or `value` is zero. Dividing the order subtotal evenly across
parcels — what modules did before this existed — over-insures the light ones and
under-insures the heavy ones.

For a cart above `SHIPPING_PACKING_MAX_UNITS` the packer samples rather than modelling
every unit, and the list you get back is extrapolated: the sampled parcels repeated to
reach the billable count, so rating every entry still covers the whole shipment. The
sizes and weights are real; which item sits in which parcel is representative. Such a
plan is also not contents-complete, so it is never written to the order.

**Invariant worth relying on:** `count(parcels(...))` always equals
`packageCount($plan, ...)`. Rate every entry and you have rated the shipment.

### `boundingParcel()` — for one-request-and-multiply modules

```php
$bounding = QuoteAdapter::boundingParcel($plan, 'inches', 'pounds');
if ($bounding === null) {
    return $this->quoteFromWeight(...); // no parcel had a usable size
}
$this->setPackage($bounding['length'], $bounding['width'], $bounding['height'], $bounding['weight']);
$price *= $bounding['count'];
```

The largest extent on each axis and the heaviest parcel across the plan. Rating N copies
of it **over-quotes** a plan whose parcels differ and **never under-quotes** one, which
is the direction the packing contract requires. Prefer `parcels()` if you can.

Deliberately not called an *envelope*: in shipping, an envelope is a rate class
(USPS Flat Rate Envelope, Royal Mail Large Letter).

## Dimensional weight

If your carrier bills `max(actual, L×W×H/divisor)`, supply the divisor and read
`billable_weight` back rather than computing it:

```php
$context = PackingSettings::contextFrom($settings, dimDivisor: 139.0);
```

Only the module knows its divisor — it is carrier- and contract-specific, so core never
guesses one.

## Girth and size limits

Carrier girth ceilings differ (UPS 165", USPS 130"), so supply yours rather than relying
on the store-wide default:

```php
$context = PackingSettings::contextFrom($settings, carrierMaxGirth: 165.0);
```

A box whose own exterior breaches the limit is then never chosen at all, instead of
producing a parcel the carrier refuses.

## Testing your integration

Build a plan directly; no database or store needed.

```php
use Zencart\Shipping\Packing\{BoxType, Dimensions, FirstFitDecreasingPacker,
    PackableItem, PackingContext, Weight};

$items = [new PackableItem('1', '1', new Dimensions(6, 6, 10), new Weight(2.0))];
$boxes = [new BoxType(1, 'CUBE', 'Cube', new Dimensions(10, 10, 10),
    new Dimensions(10.25, 10.25, 10.25), new Weight(0.5), new Weight(50))];

$GLOBALS['shipping_packages'] = (new FirstFitDecreasingPacker())->pack(
    $items,
    $boxes,
    new PackingContext(interiorMarginPercent: 0.0, catalogRevision: 'test')
);
```

Plugin tests live in `zc_plugins/<Plugin>/<version>/tests/Unit/` and run via
`composer run-script tests-plugin`, which CI also runs. Cover at least:

- no plan published → your module behaves exactly as before;
- `PackingPlan::legacyFallback(...)` published → same, since it is not quotable;
- a real plan → your parcels carry the box **exterior**, not the contents' extent;
- `$GLOBALS['shipping_num_boxes']` is unchanged after you quote.

Instantiating a shipping module in a test usually needs its constructor skipped (use
reflection's "new instance without constructor"), the access guard satisfied
(`define('IS_ADMIN_FLAG', false)`), `includes/classes/class.base.php` required so the
parent class exists, and `includes/version.php` required if the module checks the cart
version at include time.

## What a plan will not tell you

- **Which physical item is in which parcel, in an approximated plan.** Above
  `SHIPPING_PACKING_MAX_UNITS` the packer samples; the parcel count is extrapolated and
  the plan is not contents-complete.
- **That it is optimal.** 3D bin packing is NP-hard. The packer is a documented
  conservative heuristic: it may use more parcels than a skilled packer would, and never
  fewer than geometry allows.
- **Anything about attributes changing an item's size.** Attribute dimensions are not
  implemented; only base product dimensions are used.

## Reference

- `includes/classes/Shipping/Packing/QuoteAdapter.php` — the API, with the contract in
  its docblocks.
- `includes/classes/Shipping/Packing/PackingPlan.php` — `isQuotable()`,
  `isPersistable()`, `quotablePackageCount()`.
- `includes/classes/observers/auto.shipping_packing.php` — where the plan is published,
  and why the legacy globals are left alone.
