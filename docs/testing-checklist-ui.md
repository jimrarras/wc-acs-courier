# ACS Courier WooCommerce Plugin -- UI Testing Checklist

This checklist covers all user-interface and front-end functionality that can be verified **without** valid ACS API credentials. Every item can be tested on a local or staging WordPress + WooCommerce installation.

---

## 1. Plugin Lifecycle

- [ ] Plugin activates from Plugins page without PHP errors or warnings
- [ ] No fatal errors appear in `debug.log` after activation
- [ ] Plugin deactivates cleanly (no errors, no leftover admin notices)
- [ ] Plugin reactivates successfully after a previous deactivation
- [ ] Database tables / options created on first activation still exist after deactivate-reactivate cycle
- [ ] Deactivating WooCommerce while ACS Courier is active shows an admin warning or disables ACS gracefully
- [ ] Reactivating WooCommerce after the above restores ACS Courier functionality without manual intervention

---

## 2. Settings Page (WooCommerce -> ACS Courier)

### 2.1 General

- [ ] Navigate to **WooCommerce -> ACS Courier** -- page loads without PHP errors
- [ ] Page title and branding render correctly
- [ ] No JavaScript console errors on page load

### 2.2 API Credentials Tab

- [ ] Tab is visible and clickable
- [ ] Company ID field renders and accepts text input
- [ ] Company Password field renders and accepts text input
- [ ] User ID field renders and accepts text input
- [ ] User Password field renders and accepts text input
- [ ] API Key field renders and accepts text input
- [ ] "Test Connection" button is present and clickable
- [ ] Clicking "Test Connection" shows a "Testing..." loading/spinner state
- [ ] Test Connection fails gracefully when credentials are empty (shows user-friendly error)
- [ ] Test Connection fails gracefully when credentials are invalid (shows user-friendly error)
- [ ] No JavaScript errors during the test connection flow

### 2.3 Shipping Tab

- [ ] Tab is visible and clickable
- [ ] All shipping-related fields render (charge type, default weight, product dimensions, etc.)
- [ ] Default weight field enforces a minimum of 0.5 kg
- [ ] Entering a value below 0.5 kg is corrected or rejected with a validation message
- [ ] Free shipping threshold field accepts numeric values
- [ ] Flat rate / calculated rate toggle or selector works
- [ ] Flat rate price field appears when flat rate mode is selected

### 2.4 Automation Tab

- [ ] Tab is visible and clickable
- [ ] Auto-create voucher on order status change option renders
- [ ] Auto-print option renders
- [ ] Auto-send email notification option renders
- [ ] All checkbox/toggle fields are interactive

### 2.5 Saving and Persistence

- [ ] Clicking "Save" persists all field values (reload and verify)
- [ ] Checkbox states (checked/unchecked) save and persist correctly after reload
- [ ] Switching between tabs does not lose unsaved changes (or prompts the user)
- [ ] Saving shows a success notice

---

## 3. Shipping Method Configuration

- [ ] Navigate to **WooCommerce -> Settings -> Shipping -> Shipping Zones**
- [ ] "ACS Courier" appears as an available shipping method when adding a method to a zone
- [ ] ACS Courier can be added to a shipping zone successfully
- [ ] Clicking "Edit" on the ACS Courier method opens an instance settings modal/page
- [ ] Instance settings modal displays all expected fields (title, cost, etc.)
- [ ] Instance settings save correctly and persist after page reload
- [ ] ACS Courier method can be removed from a shipping zone without errors

---

## 4. Checkout (Front-End)

### 4.1 Shipping Option Display

- [ ] Add a product to the cart and proceed to checkout
- [ ] ACS Courier appears as a shipping option when the shipping zone matches
- [ ] Shipping method label displays correctly (custom title if set)
- [ ] Flat rate mode: the configured flat rate price is shown next to ACS Courier
- [ ] Free shipping threshold: orders above the threshold show "Free" or zero cost for ACS Courier
- [ ] Free shipping threshold: orders below the threshold show the normal rate

### 4.2 Smartpoint Picker (removed in 1.1.0)

The "Ship to Smartpoint" checkbox under the ACS Courier rate no longer exists; it was
replaced by the separate "Pickup from ACS Point" shipping method and its map picker.
See the "ACS Points" section below for the current checklist.

---

## 5. Order Admin (Back-End)

### 5.1 ACS Metabox

- [ ] Open an order edit page (wp-admin -> WooCommerce -> Orders -> click an order)
- [ ] An "ACS Courier" metabox is visible on the order edit screen
- [ ] The metabox displays a "Create Voucher" form
- [ ] Create Voucher form fields render correctly (weight, dimensions, COD amount, etc.)
- [ ] Weight field auto-calculates based on order items (if product weights are set)
- [ ] COD amount field auto-populates for Cash on Delivery orders
- [ ] All form dropdowns (charge type, service type, etc.) have their options populated

### 5.2 Orders List Page

- [ ] Navigate to WooCommerce -> Orders
- [ ] An "ACS Voucher" column (or similar) is visible in the orders list table
- [ ] Orders without a voucher show an empty cell or a "Create" action
- [ ] Orders with a voucher show the voucher number
- [ ] Bulk actions dropdown includes ACS-related actions (e.g., "Create ACS Vouchers", "Print ACS Vouchers")
- [ ] Selecting orders and choosing an ACS bulk action does not cause PHP errors (even if it fails due to missing credentials)

---

## 6. Custom Order Statuses

- [ ] Navigate to WooCommerce -> Orders
- [ ] The status filter dropdown includes "Delivered (ACS)"
- [ ] The status filter dropdown includes "Delivery Denied (ACS)"
- [ ] Filtering by "Delivered (ACS)" returns only orders with that status (or empty list)
- [ ] Filtering by "Delivery Denied (ACS)" returns only orders with that status (or empty list)
- [ ] Custom statuses display with appropriate color badges in the orders list
- [ ] An order can be manually changed to "Delivered (ACS)" status
- [ ] An order can be manually changed to "Delivery Denied (ACS)" status

---

## 7. Debug Logging

- [ ] Navigate to ACS Courier settings and locate the debug/logging toggle
- [ ] Enable debug logging and save
- [ ] Perform an ACS-related action (e.g., click Test Connection)
- [ ] Navigate to **WooCommerce -> Status -> Logs**
- [ ] An ACS Courier log file appears in the log dropdown
- [ ] Log file contains entries related to the action performed
- [ ] Disable debug logging and save
- [ ] Confirm that new actions no longer produce log entries (or logging stops)

---

## Notes

- All tests assume WordPress 6.x and WooCommerce 8.x+ with HPOS (High-Performance Order Storage) enabled.
- If any test produces a PHP error, note the exact error message, file, and line number.
- Browser testing should cover at least Chrome and Firefox on desktop; checkout tests should also be verified on a mobile viewport.

## ACS Points

- [ ] Zone has "Pickup from ACS Point"; the rate shows with the ACS logo and price
- [ ] "Choose ACS Point" opens the full-screen map; tiles and clustered markers render
- [ ] Map opens centred near the typed postcode; Greece overview with an empty postcode
- [ ] Search filters the list by name, street, city and postcode, accent-insensitive
- [ ] Lockers / Stores chips filter both the list and the markers
- [ ] Clicking a list row's text opens the marker popup; the row's own "Select" and the popup's "Select" both close the overlay and the summary shows name, address, 24/7 and COD badges
- [ ] "Change" reopens the map; Escape, the close button and the browser back button close it
- [ ] After closing the map any of those ways, one Back press leaves the checkout (no leftover history entry)
- [ ] Phone (375px): one-line short title and a 44px close button; the list is a bottom sheet with a 44px handle whose label switches between show and hide list; about 4 rows are visible
- [ ] Phone: tapping a row's text collapses the sheet; focusing the search field expands it again
- [ ] Phone: the popup's "Select" and close buttons are 44px tall; the bottom of the sheet is not hidden behind the browser toolbar
- [ ] "My location" asks for permission and pans the map; denying shows the error text
- [ ] Placing the order without a point shows the "choose an ACS Point" error
- [ ] A landline in the phone field shows the Greek-mobile error
- [ ] A locker without a terminal hides cash on delivery; a store shows it
- [ ] Order meta has the seven _acs_point_* keys; the admin box shows the point and the change control
- [ ] Changing the point writes a note; after a voucher exists the control is gone
- [ ] Thank-you page, My Account and the order emails show "Pickup from: ..."
- [ ] Voucher for a point order: Item_Quantity 1, station codes in the payload (debug log), no REC
