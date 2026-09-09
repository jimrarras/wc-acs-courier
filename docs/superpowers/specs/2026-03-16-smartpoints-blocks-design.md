# Smartpoints — WooCommerce Blocks Checkout Support

**Date:** 2026-03-16
**Status:** Superseded by 2026-09-09-acs-points-design.md (never implemented)
**Scope:** `includes/class-acs-api.php`, `includes/class-acs-smartpoints.php`, `assets/js/acs-smartpoints-blocks.js`

---

## Problem

The ACS Smartpoints pickup-point selector only works with the WooCommerce classic checkout (shortcode-based). WooCommerce 8+ ships the Blocks checkout as default; the PHP hook `woocommerce_after_shipping_rate` never fires in that context, so the picker is invisible to most new stores.

Additionally, `get_stations()` hardcodes `'language' => 'EN'`, meaning Greek-locale sites receive station names in English. The transient cache key does not vary by language.

---

## Goals

1. Render the Smartpoints picker in the WooCommerce Blocks checkout.
2. Make station/smartpoint names language-aware (Greek or English based on WP locale).
3. No build tools, no transpilation — consistent with the existing codebase.

---

## Out of Scope

- Admin UI to manually assign/change a Smartpoint on an existing order.
- Smartpoints in customer-facing order emails or My Account.
- Languages other than Greek and English.

---

## Design

### 1. Language-aware API (`class-acs-api.php`)

`get_stations( $country, $shop_kind, $language )` — add a third param defaulting to `'EN'`. Pass it through to the `language` field in the `ACS_Stations` request body.

`get_smartpoints( $country, $language )` — add a second param defaulting to `'EN'`. Thread it through to both `get_stations()` calls.

No other API methods are affected.

### 2. Language detection (`class-acs-smartpoints.php`)

Add a private static helper `get_acs_language()`:

```php
private static function get_acs_language() {
    $locale = get_locale();
    return ( substr( $locale, 0, 2 ) === 'el' ) ? 'GR' : 'EN';
}
```

`substr( $locale, 0, 2 )` is used rather than `strpos` to avoid false positives from locales that contain `'el'` elsewhere.

Used in two places:
- `ajax_get_smartpoints()` — passed to `WC_ACS_API::get_smartpoints()`.
- Cache key — becomes `acs_smartpoints_{country}_{language}` (e.g. `acs_smartpoints_GR_GR` or `acs_smartpoints_GR_EN`).

**Stale cache cleanup:** In `WC_ACS_Smartpoints::__construct()`, call `delete_transient('acs_smartpoints_GR')` and `delete_transient('acs_smartpoints_CY')` unconditionally. These keys are harmless to delete on every load (the next cache miss simply repopulates under the new key), and a run-once option flag would add complexity not warranted by a two-line cleanup.

The frontend JS passes `country` only; language resolution stays server-side.

### 3. Store API extension (`class-acs-smartpoints.php`)

Registered on `woocommerce_init` (priority 20, after WC loads). `woocommerce_blocks_loaded` is deprecated since WC 8.4 and must not be used.

Guarded by `class_exists('Automattic\WooCommerce\StoreApi\StoreApi')` — more reliable than a `function_exists` check across the WC 6–9 range this plugin targets.

Registers five string fields on the checkout schema under namespace `wc-acs-courier`:

| Field | Description |
|---|---|
| `use_smartpoint` | `'1'` if customer opted in, `''` otherwise |
| `smartpoint_id` | ACS Smartpoint ID |
| `smartpoint_name` | Display name |
| `smartpoint_address` | Street address |
| `smartpoint_zipcode` | Postal code |

**Saving and validation** are handled in a single `woocommerce_store_api_checkout_update_order_from_request` callback (before order save, so RouteException cleanly aborts without partial writes):

```php
add_action( 'woocommerce_store_api_checkout_update_order_from_request', function( $order, $request ) {
    $ext = $request['extensions']['wc-acs-courier'] ?? array();
    // Validate first
    if ( ! empty( $ext['use_smartpoint'] ) && empty( $ext['smartpoint_id'] ) ) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
            'acs_smartpoint_required',
            __( 'Please select an ACS pickup point or uncheck the Smartpoint option.', 'wc-acs-courier' ),
            400
        );
    }
    // Then save
    if ( ! empty( $ext['smartpoint_id'] ) ) {
        $order->update_meta_data( '_acs_smartpoint_id',      sanitize_text_field( $ext['smartpoint_id'] ) );
        $order->update_meta_data( '_acs_smartpoint_name',    sanitize_text_field( $ext['smartpoint_name'] ?? '' ) );
        $order->update_meta_data( '_acs_smartpoint_address', sanitize_text_field( $ext['smartpoint_address'] ?? '' ) );
        $order->update_meta_data( '_acs_smartpoint_zipcode', sanitize_text_field( $ext['smartpoint_zipcode'] ?? '' ) );
    }
}, 10, 2 );
```

Note: `$request['extensions']['wc-acs-courier']` uses array access on `WP_REST_Request`, which is the correct pattern for reading nested extension data in the Store API.

**Asset enqueue:** `enqueue_blocks_assets()` enqueues `acs-smartpoints-blocks.js` on checkout pages. It also calls `wp_localize_script` with the `wc_acs_sp` object (same data as the classic `enqueue_frontend_assets()` method), so the Blocks JS has access to the AJAX URL and nonce regardless of which enqueue path ran. Called only when the Store API guard passes.

### 4. Blocks picker JS (`assets/js/acs-smartpoints-blocks.js`)

A single IIFE — no imports, no build step. Globals accessed defensively with early-exit guards:

```js
if ( ! window.wp?.plugins || ! window.wc?.blocksCheckout || ! window.wp?.element || ! window.wp?.data ) {
    return;
}
```

**Globals used:**
- `wp.plugins.registerPlugin`
- `wp.element.{ useState, useEffect, createElement }`
- `wp.data.{ useSelect, useDispatch }`
- `wc.blocksCheckout.ExperimentalOrderShippingPackages`
- `wc_acs_sp` (localized data — same object as classic checkout)

**ACS-selected check:**

```js
const isACSSelected = useSelect( select =>
    select( 'wc/store/cart' )
        .getCart()
        ?.shipping_rates
        ?.some( pkg =>
            pkg.shipping_rates?.some( r => r.selected && r.method_id === 'acs_courier' )
        )
);
```

`getCart()` is used (not `getCartData()`) to ensure subscription to live cart state updates during checkout. Returns `null` (renders nothing) when ACS is not the chosen method.

**UI state:** `useSmartpoint` (checkbox), `search`, `points[]`, `selectedPoint`, `loading` — all `useState`.

**Point loading:** 400ms debounced; calls existing `wc_acs_get_smartpoints` AJAX endpoint with the same nonce and response shape. No changes to the endpoint.

**Submitting selection:** On point select or state change, `useEffect` dispatches extension data:

```js
const checkoutDispatch = useDispatch( 'wc/store/checkout' );
// Use public API where available, fall back to internal
const setData = checkoutDispatch.setExtensionData ?? checkoutDispatch.__internalSetExtensionData;
if ( setData ) {
    setData( 'wc-acs-courier', {
        use_smartpoint:     useSmartpoint ? '1' : '',
        smartpoint_id:      selectedPoint?.id      ?? '',
        smartpoint_name:    selectedPoint?.name     ?? '',
        smartpoint_address: selectedPoint?.address  ?? '',
        smartpoint_zipcode: selectedPoint?.zipcode  ?? '',
    });
}
```

**Slot:** Output is wrapped in `createElement( ExperimentalOrderShippingPackages, null, ... )`. This slot renders within the `woocommerce/checkout-shipping-methods-block` and is the standard WC Blocks extension point used by WooCommerce's own local pickup feature. Visually this places the picker immediately after the shipping rate list. Position should be verified against the target WC Blocks version during testing.

Note: The `Experimental` prefix means WooCommerce may rename or promote this slot in a future minor version without a deprecation period. The implementer should monitor the WC Blocks changelog for this slot name and plan to update the reference if it is renamed.

**Script dependencies:** `wp-element`, `wp-data`, `wp-plugins`, `wc-blocks-checkout`, `jquery`.

### 5. Classic checkout

No changes. Both flows coexist, writing to the same order meta keys and using the same AJAX endpoint.

---

## File Change Summary

| File | Change |
|---|---|
| `includes/class-acs-api.php` | Add `$language` param to `get_stations()` and `get_smartpoints()` |
| `includes/class-acs-smartpoints.php` | Add `get_acs_language()`, update cache key + stale key cleanup, update `ajax_get_smartpoints()`, add `register_blocks_integration()`, `enqueue_blocks_assets()` |
| `assets/js/acs-smartpoints-blocks.js` | New file — Blocks picker component |
| `tests/Unit/SmartpointsTest.php` | Update cache key assertions from `acs_smartpoints_GR` → `acs_smartpoints_GR_EN` (or `_GR` for Greek locale) |

---

## Testing Checklist

- [ ] Classic checkout: picker still appears, selection saves to order meta, voucher builds correctly
- [ ] Blocks checkout: picker appears only when ACS is selected
- [ ] Blocks checkout: selecting a point, placing order — `_acs_smartpoint_*` meta saved correctly
- [ ] Blocks checkout: checking "use smartpoint" but not selecting a point shows validation error at checkout
- [ ] Blocks checkout: unchecking "use smartpoint" clears extension data and no meta is saved
- [ ] Greek locale (`el_GR`): station names returned in Greek; cache key is `acs_smartpoints_GR_GR`
- [ ] English locale (`en_US`): station names in English; cache key is `acs_smartpoints_GR_EN`
- [ ] `get_acs_language()` unit test: `el`, `el_GR` → `'GR'`; `en_US`, `de_DE`, `''` → `'EN'`
- [ ] Existing unit test `SmartpointsTest` updated for new cache key
- [ ] WC version without Store API (`class_exists` guard): plugin activates without fatal errors, classic checkout unaffected
- [ ] Smartpoint picker position verified visually in target WC Blocks version
