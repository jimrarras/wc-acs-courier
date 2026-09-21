# ACS Courier WooCommerce Plugin -- Integration Testing Checklist

This checklist covers all functionality that **requires valid ACS API credentials** (or a mock API server). Every item involves real API communication or verifies data returned from the ACS backend.

---

## Prerequisites

Before running any integration tests, ensure the following are in place:

- [ ] WordPress 6.x installed and running (local, staging, or test environment)
- [ ] WooCommerce 8.x+ installed, activated, and configured with at least one shipping zone covering Greece
- [ ] ACS Courier plugin installed and activated
- [ ] Valid ACS API credentials entered in **WooCommerce -> ACS Courier -> API Credentials**
- [ ] "Test Connection" returns a success response confirming credentials are valid
- [ ] At least one simple product created with a price and weight set
- [ ] At least one WooCommerce order in "Processing" status ready for voucher testing
- [ ] A Cash on Delivery (COD) payment method enabled in WooCommerce
- [ ] Debug logging enabled in ACS Courier settings for inspecting API requests/responses
- [ ] Access to **WooCommerce -> Status -> Logs** to review debug logs

---

## 1. Shipping Rates

- [ ] Add a product to the cart with a Greek shipping address
- [ ] ACS Courier rate is returned and displayed at checkout
- [ ] Rate amount is reasonable and matches expected pricing for the weight/destination
- [ ] Changing the destination postcode updates the rate accordingly
- [ ] Changing cart contents (adding/removing items) recalculates the rate
- [ ] Rate calculation handles a heavy item (e.g., 25 kg) without errors
- [ ] Rate calculation handles multiple items with combined weight
- [ ] Islands or remote areas return a rate (potentially higher) without errors
- [ ] Invalid or non-serviceable postcodes are handled gracefully (no rate or clear message)
- [ ] Debug log shows the outgoing rate request and the API response

---

## 2. Voucher Creation

- [ ] Open a "Processing" order in wp-admin
- [ ] Fill in the ACS metabox voucher creation form with valid data
- [ ] Click "Create Voucher"
- [ ] A voucher number is returned and displayed in the metabox
- [ ] The voucher number is saved to the order meta
- [ ] The voucher number appears in the orders list column
- [ ] An order note is added recording the voucher creation
- [ ] Debug log shows the voucher creation API request and response
- [ ] Creating a second voucher for the same order either replaces or appends (verify expected behavior)
- [ ] Voucher creation with minimum required fields succeeds
- [ ] Voucher creation with all optional fields populated succeeds

---

## 3. COD (Cash on Delivery) Orders

- [ ] Place an order using Cash on Delivery payment method
- [ ] Open the order and verify the COD amount is pre-populated in the ACS metabox
- [ ] Create a voucher for the COD order
- [ ] Verify the voucher was created with the correct COD amount in the API request
- [ ] Debug log confirms the COD amount was sent to ACS
- [ ] COD amount of zero on a non-COD order does not trigger COD handling
- [ ] Partial COD amount (manually edited) is sent correctly

---

## 4. Smartpoint Orders

- [ ] At checkout, select ACS Courier and enable the Smartpoint option
- [ ] Search for and select a valid Smartpoint location
- [ ] Complete the order
- [ ] Open the order in wp-admin and verify the Smartpoint data is stored (location ID, name, address)
- [ ] Create a voucher for the Smartpoint order
- [ ] Verify the API request includes the Smartpoint station ID
- [ ] Debug log shows the Smartpoint data in the voucher creation payload
- [ ] The voucher is created successfully with Smartpoint delivery type
- [ ] Smartpoint search returns results for a valid city/area name
- [ ] Smartpoint search returns an empty set or message for a nonsensical query

---

## 5. Voucher Printing

- [ ] Create a voucher for an order (if not already done)
- [ ] Click the "Print Voucher" button in the ACS metabox
- [ ] A PDF voucher label is returned and opens in a new tab or triggers a download
- [ ] The PDF contains correct order data (recipient, address, weight, voucher number)
- [ ] The PDF contains a scannable barcode
- [ ] Printing a voucher for an order without a voucher number shows an appropriate error
- [ ] Multiple consecutive print requests return the same PDF without errors

---

## 6. Voucher Deletion

- [ ] Create a voucher for a test order
- [ ] Click the "Delete Voucher" button in the ACS metabox
- [ ] Confirm the deletion prompt (if any)
- [ ] The voucher number is removed from the order meta
- [ ] The voucher column in the orders list is cleared for that order
- [ ] An order note is added recording the deletion
- [ ] Debug log shows the deletion API request and response
- [ ] Attempting to delete an already-deleted voucher shows an appropriate message
- [ ] After deletion, a new voucher can be created for the same order

---

## 7. Bulk Operations

- [ ] Navigate to WooCommerce -> Orders
- [ ] Select multiple orders in "Processing" status (without vouchers)
- [ ] Choose the "Create ACS Vouchers" bulk action and apply
- [ ] Vouchers are created for all selected orders
- [ ] A summary notice shows how many vouchers were created (and any failures)
- [ ] All created voucher numbers appear in the orders list column
- [ ] Select multiple orders that already have vouchers
- [ ] Choose the "Print ACS Vouchers" bulk action and apply
- [ ] A combined PDF with all voucher labels is returned
- [ ] Select a mix of orders (some with vouchers, some without) for bulk print -- verify graceful handling
- [ ] Bulk operations with a large number of orders (10+) complete without timeout

---

## 8. Pickup Lists

- [ ] Navigate to the ACS Courier pickup list section (if available in settings or orders page)
- [ ] Create a pickup list for today's date
- [ ] Verify the API request is sent and a pickup list ID is returned
- [ ] The pickup list includes all vouchers created for the day (or selected vouchers)
- [ ] Print the pickup list -- a PDF summary is returned
- [ ] Debug log shows pickup list creation request and response
- [ ] Creating a duplicate pickup list for the same date is handled (error or update)

---

## 9. Auto-Create Voucher (Automation)

- [ ] Enable "Auto-create voucher on status change" in ACS Courier settings
- [ ] Configure the trigger status (e.g., when order moves to "Processing")
- [ ] Place a new order or change an existing order to the trigger status
- [ ] Verify a voucher is automatically created without manual intervention
- [ ] Voucher number appears in the order metabox and orders list column
- [ ] An order note is added recording the auto-creation
- [ ] Debug log shows the automatic voucher creation
- [ ] Disable the auto-create setting and confirm orders no longer auto-create vouchers
- [ ] Auto-create with an order that has incomplete address data fails gracefully with a logged error

---

## 10. Tracking

- [ ] Create a voucher for an order
- [ ] Verify the tracking URL or tracking data is stored with the order
- [ ] Check that the tracking link is accessible and points to the correct ACS tracking page
- [ ] If tracking status polling is implemented: verify the order status updates based on ACS tracking events
- [ ] "Delivered" tracking event updates order status to "Delivered (ACS)"
- [ ] "Delivery Denied" tracking event updates order status to "Delivery Denied (ACS)"
- [ ] Tracking for a non-existent voucher number returns an appropriate error

---

## 11. Email Notifications

- [ ] Create a voucher for an order
- [ ] Verify the customer receives an email notification with the voucher/tracking information
- [ ] Email contains the correct voucher number
- [ ] Email contains a working tracking link
- [ ] Email formatting renders correctly in common email clients (check WooCommerce email preview if available)
- [ ] If auto-email is disabled in settings, no email is sent on voucher creation
- [ ] Re-sending the notification (if a resend button exists) delivers the email again

---

## 12. Edge Cases

### 12.1 Network and API Errors

- [ ] Simulate API timeout (e.g., using a very slow mock server) -- plugin shows a timeout error, does not hang
- [ ] Simulate API returning HTTP 500 -- plugin shows a server error message
- [ ] Simulate API returning invalid JSON -- plugin handles parse error gracefully
- [ ] Simulate API returning an authentication error (HTTP 401/403) -- plugin shows credentials error
- [ ] Rapid consecutive API calls (e.g., double-click "Create Voucher") do not create duplicate vouchers

### 12.2 Data Edge Cases

- [ ] Order with extremely long address lines (200+ characters) -- voucher creation handles truncation or succeeds
- [ ] Order with special characters in recipient name (accents, Greek characters) -- data is sent correctly
- [ ] Order with zero-weight products -- default weight (0.5 kg minimum) is applied
- [ ] Order with no phone number -- voucher creation either fails with a clear message or uses a default
- [ ] Order shipped to a non-Greek address -- appropriate error or unsupported message

### 12.3 Concurrent Operations

- [ ] Two admin users creating a voucher for the same order simultaneously -- no duplicate vouchers or data corruption
- [ ] Bulk operation running while a single voucher is being created -- no interference

### 12.4 Plugin Compatibility

- [ ] Plugin functions correctly with WooCommerce HPOS (High-Performance Order Storage) enabled
- [ ] Plugin functions correctly with WooCommerce legacy post-based order storage
- [ ] Plugin does not conflict with other popular shipping plugins (if installed alongside)
- [ ] Plugin functions correctly with WordPress multisite (if applicable)

---

## Notes

- After each section, review **WooCommerce -> Status -> Logs** for any unexpected errors or warnings.
- Record the ACS API response codes and messages for any failures.
- All tests should be run on a staging environment -- never on a production store with real customer orders.
- If using a mock API server, ensure it replicates the ACS API response format accurately.
