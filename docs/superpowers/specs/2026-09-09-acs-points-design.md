# ACS Points: locker and store pickup with a map picker

**Date:** 2026-09-09
**Status:** Approved (design agreed in conversation; spec pending owner review)
**Supersedes:** `2026-03-16-smartpoints-blocks-design.md` (never implemented; the Smartpoints module it extended is removed by this work)
**Repo:** `wc-acs-courier` (plugin). Site-side steps for pooq.gr live in the woo-importer repo under `data/site-backups/`.

---

## Problem

1. **The Smartpoints picker has never rendered on pooq.gr.** It hooks on the `acs_courier` rate, but the live zone "Ελλάδα" ships with a `flat_rate` titled "ACS Courier" (3.60 €) plus `free_shipping` over 50 €. No customer has ever been able to choose a locker.
2. **The station feed is wrong.** `get_smartpoints()` asks `ACS_Stations` for kinds 7 and 4. Verified on 2026-09-09 with the store's credentials: kind 7 returns 0 rows (lockers moved to kind 8, 1,528 rows), and kind 4 is "Xpress points, cash store pickups only", which cannot receive a parcel.
3. **The voucher routing is wrong.** The ACS web-services manual (ACS_Create_Voucher, note 10) routes a Smart Point delivery through `Acs_Station_Destination` (area code, e.g. `ΙΒ`) and `Acs_Station_Branch_Destination` (point code, e.g. `501`). The plugin instead adds a `REC` product, rewrites the recipient address to the point's street and puts the shop id in `Reference_Key2`. ACS would treat that as a home delivery to the shop's street address.
4. **No map.** BOX NOW on the same store opens a map; ACS pickup should match it.

The official "ACS Points" plugin (AfterSalesPro, 2.2.0) was evaluated and rejected: it writes `data.json` into its own plugin directory, loads Leaflet from unpkg, geocodes the customer's address through nominatim.openstreetmap.org (against that service's usage policy for commercial autocomplete), registers a global non-zone shipping method with its own pricing that fights the store's rate rule, stores the ACS credentials a second time, mixes Greek and English strings, and does not create vouchers. What it does get right is the data source and the routing codes, which this design adopts.

## Goals

1. A second, zone-based shipping method "Παραλαβή από ACS Point" with a full-screen map picker on the classic checkout, at parity with the BOX NOW picker.
2. Correct voucher creation for point deliveries using the two station codes and the ACS rules (mobile required, email for COD, one parcel).
3. Point data from the alias ACS ships to merchants, refreshed daily, served from the store, cached in the browser.
4. No third-party request until the customer opens the map, and then only OpenStreetMap tiles. No geocoder. No CDN.
5. Cash on delivery allowed where the point has a terminal, hidden and refused elsewhere.
6. Operators can see and, until a voucher exists, change the point on the order screen. Customers see the point on the thank-you page, in emails and in My Account.

## Out of scope

- A Blocks checkout UI. The Store API save-and-validate callback IS included so a Blocks or express order cannot be created with `acs_points` and no point (it is refused with a message).
- Cyprus points. The feed carries `Country_Code`; only the store base country is kept.
- A geocoder or address search on the map. Centring uses the customer's postcode against the point list.
- Changing the existing `acs_courier` home-delivery method or its API rate calculation.

## Decisions taken with the owner (2026-09-09)

| Decision | Choice |
|---|---|
| Map stack | Leaflet + OpenStreetMap tiles, vendored libraries, no geocoder |
| COD at points | Allowed where the point reports a terminal, hidden and refused otherwise |
| Presentation | Second radio row like BOX NOW, not a checkbox under the home-delivery row |
| Third-party plugin | Not installed; knowledge ported into wc-acs-courier |

---

## Architecture

```
includes/
  class-acs-api.php               + get_points_feed()            (new alias call)
  class-acs-points-feed.php       NEW  fetch, normalise, store, cron, REST endpoint
  class-acs-points-shipping-method.php  NEW  WC_Shipping_Method 'acs_points'
  class-acs-points-picker.php     NEW  checkout UI hooks, session, validation, save, COD filter
  class-acs-voucher.php           ~ build_shipment_params(): station routing, qty 1
  class-acs-admin.php             ~ "Refresh points" button + feed status on the settings page
  class-acs-smartpoints.php       REMOVED
assets/
  js/acs-points.js                NEW  opener, popup, list, map, selection
  css/acs-points.css              NEW
  js/acs-smartpoints.js           REMOVED
  css/acs-smartpoints.css         REMOVED
  vendor/leaflet/                 NEW  leaflet.js, leaflet.css, images/ (1.9.4, BSD-2)
  vendor/leaflet.markercluster/   NEW  leaflet.markercluster.js, MarkerCluster.css, MarkerCluster.Default.css (1.5.3, MIT)
  img/point-locker.svg, point-store.svg, point-locker-cod.svg  NEW  marker icons (own artwork)
```

`wc_acs_includes()` and the activation file check gain the three new files and lose the Smartpoints one. `wc_acs_init()` instantiates `WC_ACS_Points_Feed` and `WC_ACS_Points_Picker` and registers `acs_points` next to `acs_courier`.

### Component 1: points feed (`WC_ACS_Points_Feed`)

**Source.** `WC_ACS_API::get_points_feed()` posts alias `ACS_Get_Stations_For_Plugin` with the standard credential block plus `locale => null`. Verified response on 2026-09-09: `ACSTableOutput.Table_Data` holds three icon rows (branch, smartlocker, smartpoint), `Table_Data1` holds 2,029 points (1,715 `smartlocker`, 314 `branch`) with keys `id, icon, type, name, lat, lon, title, street, area, city, sa_zipcode, notes, is_24h, saturday, weekdays, Acs_Station_Destination, Acs_Station_Branch_Destination, Acs_Smartpoint_COD_Supported, Country_Code`.

**Normalisation.** `normalise( array $raw, string $country ) : array` keeps points whose `Country_Code` equals the store base country and whose `lat`/`lon` parse as floats, and maps each to:

```php
array(
  'id'      => (string) $raw['id'],
  'type'    => 'branch' === $raw['type'] ? 'store' : 'locker',
  'name'    => trim( preg_replace( '/\s+/', ' ', $raw['name'] ) ),
  'street'  => trim( $raw['street'] ),
  'city'    => trim( $raw['city'] ),
  'zip'     => (string) $raw['sa_zipcode'],
  'lat'     => round( (float) $raw['lat'], 6 ),
  'lon'     => round( (float) $raw['lon'], 6 ),
  'station' => (string) $raw['Acs_Station_Destination'],
  'branch'  => (string) $raw['Acs_Station_Branch_Destination'],
  'cod'     => (int) ! empty( $raw['Acs_Smartpoint_COD_Supported'] ),
  'h24'     => (int) ! empty( $raw['is_24h'] ),
  'hours'   => trim( $raw['weekdays'] ),
  'sat'     => trim( $raw['saturday'] ),
)
```

`notes`, `icon`, `title`, `area` are dropped. A store (`type = store`) always has `cod = 1`: the alias reports `Acs_Smartpoint_COD_Supported = 0` for branches because the flag describes locker terminals, but every ACS store takes cash on delivery. This override is explicit in `normalise()` and covered by a test.

**Storage.** Option `wc_acs_points_feed` (autoload `no`) holding `array( 'fetched_at' => <unix>, 'country' => 'GR', 'points' => [...] )`. On this store the option lands in Redis-backed object cache; at ~2,000 points the serialised array is ~450 KB, which is fine for a single option read once per request that needs it.

**Refresh.** `refresh() : true|WP_Error`. On success it replaces the option. On any API failure (`WP_Error`, empty `Table_Data1`, fewer than 100 points) it returns the error, leaves the stored copy untouched and logs through `WC_ACS_API::log()`. Cron hook `wc_acs_points_cron`, scheduled `daily` on activation (and on first request if not scheduled), cleared on deactivation. The settings page shows "Σημεία ACS: 2,029, ενημερώθηκαν <date>" and a "Refresh points" button (AJAX `wc_acs_refresh_points`, `manage_woocommerce`, nonce).

**Delivery to the browser.** REST route `GET /wp-json/wc-acs/v1/points` (public, no auth, `permission_callback => __return_true`). Response: `{ "v": <fetched_at>, "points": [ [id, type, name, street, city, zip, lat, lon, station, branch, cod, h24, hours, sat], ... ] }` as positional arrays to shrink the payload (~60 KB gzipped). Headers: `Cache-Control: public, max-age=86400`, `ETag: "<fetched_at>"`, and `304` on a matching `If-None-Match`. On pooq.gr `/wp-json/` is not page-cached, so the route must set those headers itself. The endpoint is not hit until the popup opens.

**Lookup.** `find( string $id ) : ?array` returns the normalised point or null. Used by validation, saving and the admin change control, so the server never trusts point fields from the browser. The browser sends the id only.

**Postcode centre.** `centre_for_postcode( string $zip ) : ?array` returns `[lat, lon, zoom]`: the median lat/lon of points sharing the first three digits (zoom 13), else the first two digits (zoom 10), else null (the JS falls back to a Greece overview at zoom 7). Pure function on the stored list, unit-tested. Exposed to the JS as part of the localized settings (computed server-side on render from the session's shipping or billing postcode) AND recomputed client-side from the fetched list when the postcode field changes, using the same rule.

### Component 2: shipping method (`WC_ACS_Points_Shipping_Method`)

`id = 'acs_points'`, `supports = shipping-zones, instance-settings, instance-settings-modal`. Instance fields:

| Key | Type | Default | Meaning |
|---|---|---|---|
| `enabled` | checkbox | yes | |
| `title` | text | "Pickup from ACS Point" | Greek via .po |
| `cost` | price | 0 | flat cost |
| `free_min` | price | "" | subtotal at or above which the rate is 0; empty disables |
| `max_weight` | decimal | 6 | kg; rate withheld above it (ACS standard locker limit) |
| `point_types` | select | `both` | `both` or `lockers` |
| `tax_status` | select | none | as the home-delivery method |

`calculate_shipping()` withholds the rate when the package weight (`wc_get_weight` of contents, falling back to `wc_acs_default_weight` per item without weight) exceeds `max_weight`, otherwise adds one rate at `cost`, or 0 when `free_min` applies. Rate id is `acs_points:<instance>`. On pooq.gr the 05-pooq-shipping rule zeroes every courier rate above the store threshold anyway; `free_min` exists for stores without that rule.

`point_types` is read by the picker (which filters the list client-side by type) and by validation (a store id is refused when `lockers` is set).

### Component 3: picker (`WC_ACS_Points_Picker`)

Follows `WC_BoxNow_Locker` line by line where the problems are the same. Constants: `METHOD_ID = 'acs_points'`, session key `acs_point_id`.

**Hooks.**
- `wp_enqueue_scripts`: on `is_checkout()` (not order-received) enqueue `acs-points.css` and `acs-points.js` (dep `jquery`, footer) and localize `wcAcsPoints` with: REST URL, ajax URL, nonce, vendor asset URLs, icon URLs, store country, `pointTypes`, `postcodeCentre` (see feed), i18n strings, and `codDisabledMessage`.
- `woocommerce_after_shipping_rate` (10, 2): when `$rate->get_method_id() === 'acs_points'`, print `<div class="wc-acs-points-picker" data-package="<i>">` with the opener button `.wc-acs-points-open`, the `.wc-acs-points-selected` summary (hidden when nothing chosen; otherwise name, street and city, `24ωρο` badge when `h24`, `Με αντικαταβολή` / `Χωρίς αντικαταβολή` badge, and an `Αλλαγή` button), and `<input type="hidden" name="acs_point_id">` prefilled from the session. The summary is rendered from `find( $id )`, never from posted fields.
- `woocommerce_after_checkout_validation` (10, 2): as BOX NOW, fill `shipping_method` from the session when the posted field is empty, then `validate( $data, $point_id, $errors )`.
- `woocommerce_checkout_create_order` (10, 2): when the order's shipping line is `acs_points`, look the id up, throw `Exception` with the "choose a point" message when missing or unknown, else write the seven meta keys (below).
- `woocommerce_available_payment_gateways`: when the session's chosen method is `acs_points` and the session's point exists and has `cod = 0`, unset `cod`. While no point is chosen yet the gateway list is left alone, so it does not flicker during selection; the server-side rule catches a COD submission for a point without terminal.
- `wp_ajax_wc_acs_set_point` / `nopriv`: nonce `wc-acs-points`, body `point_id`; stores the id in the session after `find()` succeeds, returns the normalised point; the JS then triggers `update_checkout` so the summary, the gateway list and the totals re-render server-side.
- `woocommerce_init` (20): register Store API endpoint data under namespace `wc-acs-courier` with one string field `point_id`, and hook `woocommerce_store_api_checkout_update_order_from_request` to run the same validation and save, throwing `RouteException` (400) on failure. Session fallback as BOX NOW.

**Validation rules** (`validate()` is static and side-effect free, reused by both paths):

1. Skip unless a chosen method starts with `acs_points`.
2. Point id empty or `find()` null: error `acs_point_required`, "Please choose an ACS Point before placing your order."
3. Point type `store` while the instance is `lockers`: error `acs_point_type`, "Please choose an ACS locker."
4. Payment method `cod` and point `cod = 0`: error `acs_point_cod`, "Cash on delivery is not available at this ACS Point. Choose another point or pay by card."
5. Billing phone not a Greek mobile: error `acs_point_mobile`, "ACS sends the pickup PIN by SMS, so a Greek mobile number (69xxxxxxxx) is required." The check normalises by stripping spaces, dashes, a leading `+30` or `0030`, and accepts exactly ten digits starting with `69`. Applied only to `acs_points` orders; the home-delivery flow is unchanged.

The mobile normalisation lives in a small static helper `WC_ACS_Points_Picker::normalise_mobile( $raw ) : ?string` returning the ten-digit form or null, reused by the voucher builder.

**Order meta written on save** (all via `update_meta_data`, HPOS-safe):

| Key | Value |
|---|---|
| `_acs_point_id` | feed id |
| `_acs_point_type` | `locker` or `store` |
| `_acs_point_name` | name |
| `_acs_point_address` | `street, zip city` |
| `_acs_point_station` | e.g. `ΙΒ` |
| `_acs_point_branch` | e.g. `501` |
| `_acs_point_cod` | `0` or `1` |

The old `_acs_smartpoint_*` keys are no longer written or read. No order on pooq.gr carries them (the picker never rendered), so no migration.

### Component 4: frontend (`assets/js/acs-points.js`, `assets/css/acs-points.css`)

One jQuery IIFE, no build step, same conventions as `boxnow-locker.js`.

**Lifecycle.**
- On the opener click: if Leaflet is not loaded, inject the vendored CSS and JS (`leaflet`, then `markercluster`) by appending `<link>`/`<script>` elements and wait for load; fetch `/wp-json/wc-acs/v1/points` once per page (browser cache makes repeat visits free); build the overlay; render.
- The overlay is a full-screen `.wc-acs-points-overlay` (z-index above the checkout, `position: fixed; inset: 0`) containing a header (ACS logo, title, close button), a sidebar (search input, type filter chips "Lockers / Stores" when `pointTypes = both`, the list) and the map. Escape and the close button close it. Body scroll is locked while open.
- Map: `L.map` with OSM tile layer `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`, attribution as required by OSM, `maxZoom 18`, `minZoom 6`. Markers in a `markerClusterGroup` (`maxClusterRadius 60`, `disableClusteringAtZoom 15`). Icon per point: locker, locker with terminal, store. Marker popup: name, street, city, hours (24ωρο badge or weekday and Saturday lines), COD badge, "Επιλογή" button.
- Initial view: `postcodeCentre` from settings when present, else the same rule computed client-side from the current postcode field value (shipping postcode when "ship to a different address" is ticked, else billing), else Greece overview `[38.5, 23.8]` zoom 7.
- "Η θέση μου" button: `navigator.geolocation.getCurrentPosition` on click only; on success pan to the position at zoom 14 and drop a plain marker. Nothing is transmitted.
- List: filtered by the search string (case-insensitive, accent-insensitive via `String.normalize('NFD')` and stripping combining marks, matched against name, street, city, zip) and the type chips; sorted by distance from the map centre, recomputed on `moveend` (debounced 250 ms); capped at 60 rows with "Μετακινήστε τον χάρτη για περισσότερα". Clicking a row pans to the marker and opens its popup.
- Selecting: `POST admin-ajax.php action=wc_acs_set_point` with the id and nonce; on success write the id to the hidden input, close the overlay, and trigger `update_checkout`. The server re-renders the summary.
- `Αλλαγή` reopens the overlay. Changing the shipping radio to another method leaves the session value alone (harmless; validation only runs for `acs_points`).
- Phones (`max-width: 767px`): the sidebar becomes a bottom sheet with a drag handle toggling between a 40 % and a 90 % height; the map fills the rest.

**CSS.** All rules scoped under `.wc-acs-points-picker` and `.wc-acs-points-overlay`. Opener styled on its own class (ACS red `#e30613`, white text, same shape as `.wc-boxnow-open`), never the theme `.button` class, for the reason recorded in the BOX NOW plugin. Leaflet's own CSS is loaded only when the map loads.

**Icons.** Three SVGs drawn for this plugin (no ACS artwork), 32 × 40 px pins: locker (grey), locker with terminal (grey with a card glyph), store (ACS red).

### Component 5: voucher (`WC_ACS_Voucher::build_shipment_params()`)

When the order has `_acs_point_station` and `_acs_point_branch`:

- `Acs_Station_Destination = station`, `Acs_Station_Branch_Destination = branch`.
- `Item_Quantity = 1` regardless of the metabox field; the multipart input is disabled in the metabox for such orders with the note "ACS Points accept one parcel per shipment."
- `Recipient_Cell_Phone = normalise_mobile( billing phone )`, `Recipient_Phone` the same. If normalisation fails (the order predates the rule or was created by hand), voucher creation returns a `WP_Error` "ACS Points need a Greek mobile number" instead of calling ACS.
- `Recipient_Email = billing email` (already always sent).
- Recipient name and address: the customer's own. The station fields do the routing; the label still names the customer.
- `Delivery_Notes = "ACS Point: <name>, <street>"`, joined with ` | ` to any operator note as today.
- `Acs_Delivery_Products`: `COD` only when the payment method is COD; the `REC` product and the address rewrite are removed.
- Orders without the point meta are built exactly as before.

**Open check, to be closed on the first live voucher.** The manual does not say what `Recipient_Address` should carry for a locker shipment. The first live point order will get a voucher created from the order screen, its PDF inspected, and the voucher deleted; if the label or ACS's response shows the point's address is expected instead, the builder switches to the point's street and zip for `Recipient_Address` and `Recipient_Zipcode` (a one-line change) and this section is updated. The same inspection confirms the branch code format: the builder sends Acs_Station_Branch_Destination as an integer, which would drop a leading zero if ACS ever issued one.

Auto-voucher: `acs_points` is not in `wc_acs_other_carrier_method_ids`, so the existing automation creates the voucher on `processing` as for home delivery.

### Component 6: admin and customer views

- **Order screen.** Under the shipping address (`woocommerce_admin_order_data_after_shipping_address`): a box "ACS Point" with type, name, address, `station+branch` code, COD flag, and a Google Maps link. While `_acs_voucher_no` is empty, a "Change point" control: a text input with a datalist-style search (AJAX `wc_acs_admin_search_points`, `edit_shop_orders`, returns up to 20 matches on name, street, city or zip) and a "Save point" button that writes the seven meta keys through `find()`. Mirrors BOX NOW 1.0.3's editable locker. After a voucher exists the control is replaced by the note "Delete the voucher to change the point."
- **Thank-you page and My Account** (`woocommerce_order_details_after_customer_details`): "Παραλαβή από: <name>, <address>" with the Maps link.
- **Emails** (`woocommerce_email_order_meta`, both HTML and plain): the same line. The existing tracking email code is untouched.
- **Settings page.** A "Points" block in the ACS settings: count, fetched-at, "Refresh points" button, last error if any.

---

## Site integration on pooq.gr

Recorded in a new folder `data/site-backups/<deploy date>-acs-points/README.md` in the woo-importer repo, named after the day it goes live. Steps, in order:

1. Deploy wc-acs-courier 1.1.0 to staging (`D:\pooq-staging`), walk the checkout on desktop and phone, place a staging order, check the meta and the admin box.
2. Live: back up the plugin directory and `wp option get` of the ACS options into `/root/pooq-backups/acs-points-<date>/`, swap the plugin directory, purge caches.
3. WooCommerce > Αποστολή > Ελλάδα: add "Pickup from ACS Point" (`acs_points`) with the owner's price. The 05-pooq-shipping rule zeroes it above 50 € like every courier rate, and the label filter already prefixes the ACS logo because the token `acs` matches the method id.
4. `pooq-checkout.css`: one rule giving `.wc-acs-points-picker` the same `flex: 1 1 100%; margin: 6px 0 2px 28px` as `.wc-boxnow-picker`. Bump `POOQ_CHECKOUT_VER`.
5. `pooq-consent/config.php`: add a row for `tile.openstreetmap.org` (provider OpenStreetMap Foundation, category necessary, purpose "Χάρτης σημείων παραλαβής ACS, φορτώνεται μόνο όταν ανοίξετε τον χάρτη στο checkout. Δεν θέτει cookies.") and bump `POOQ_CONSENT_POLICY_VERSION` as the file's own rule requires.
6. `10-pooq-email-text.php`: in the shipped (Completed) email, when `_acs_point_name` exists, add "Παραλαβή από: <name>, <address>" under the voucher block.
7. First live point order: create the voucher from the order screen, inspect the PDF, delete the voucher (closes the open check in Component 5), then let automation handle real orders.

---

## Testing

**PHPUnit (`tests/Unit`)**, new files `PointsFeedTest.php`, `PointsShippingMethodTest.php`, `PointsPickerTest.php`, and additions to `VoucherTest.php`; `SmartpointsTest.php` removed.

- Feed: `normalise()` maps every field, drops foreign-country and coordinate-less points, forces `cod = 1` on stores; `refresh()` keeps the stored copy on `WP_Error`, on empty data and on fewer than 100 points; the REST payload is positional with the documented order and sets `Cache-Control` and `ETag`; `find()` returns null for unknown ids; `centre_for_postcode()` picks three-digit, then two-digit, then null.
- Shipping method: rate present at `cost`, zero at or above `free_min`, absent above `max_weight`, default weight applied to weightless items.
- Picker: `validate()` for each of the five rules and for the non-`acs_points` early return; session fallback when `shipping_method` is not posted; save writes the seven keys and throws with no point; gateway filter removes `cod` only for a chosen point without terminal; `normalise_mobile()` accepts `6912345678`, `+30 691 234 5678`, `0030-6912345678`, rejects landlines and short numbers.
- Voucher: point orders send the station and branch codes, `Item_Quantity = 1` even when the metabox posts 3, no `REC`, customer address kept, `Delivery_Notes` contains the point, `WP_Error` without a mobile; a home-delivery order's params are byte-for-byte unchanged against the current fixtures.
- Admin: changing the point is refused once `_acs_voucher_no` exists.

**Integration** (`tests/Integration`, real credentials from `.env`): `get_points_feed()` returns more than 1,000 points with both station codes populated.

**Manual checklist** (`docs/testing-checklist-ui.md` gains a section): staging desktop and phone walk (open, search, filter, pick, change, close with Escape, geolocation prompt), COD hidden for a locker without terminal and shown for a store, mobile-number error, order meta, admin box and change control, email line, live voucher check.

## Versioning

Plugin version 1.1.0. `readme.txt` changelog entry lists the new method, the map, the voucher fix, and the removal of the Smartpoints checkbox.

### Open check: closed 2026-09-09 (live voucher 9805904835, test order 9136, both deleted)

The label printed by ACS for a locker shipment keeps the customer's own name and address as the receiver
(ΠΑΡΑΛΗΠΤΗΣ: Δοκιμή ACS Points, Δωδώνης 10, 45333 Ιωάννινα), shows the destination as `ΙΒ` / `S501`, and ACS
itself adds "ΠΑΡΑΛΑΒΗ ΑΠΟ ACS-SMARTPOINT(ΙΒ 501)" in the notes ahead of our `Delivery_Notes`. So the builder sends the
right thing: the customer's address plus the two station codes. The integer branch code (501) printed correctly.
Nothing to change.
