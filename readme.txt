=== ACS Courier for WooCommerce ===
Contributors: jimrarras
Tags: woocommerce, shipping, courier, acs, greece
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Open source ACS Courier integration for WooCommerce — create/print vouchers, track shipments, calculate shipping costs, and support ACS Smartpoints pickup locations.

== Description ==

Integrate your WooCommerce store with ACS Courier, Greece's largest courier service. This free, open source plugin handles the complete shipping workflow — from voucher creation to delivery tracking.

= Voucher Management =

* Create vouchers manually (per-order button) or automatically on order status change
* Print vouchers in PDF — A4 laser (3 labels/page) and thermal receipt formats
* Delete vouchers that haven't been added to a pickup list
* Bulk create vouchers for multiple orders from the orders list
* Issue and print pickup lists to finalize daily shipments
* Automatic COD amount inclusion for Cash on Delivery orders
* Multipart shipments — multiple parcels per order

= Shipment Tracking =

* Auto-tracking via WP Cron — hourly, twice daily, or daily
* Custom order statuses: "Delivered (ACS)" and "Delivery Denied (ACS)"
* Full tracking checkpoint history in the order metabox
* Automatic tracking number email to customers
* Tracking info appended to WooCommerce order emails
* `[acs_tracking]` shortcode for a customer-facing tracking page

= Shipping Cost Calculation =

* Real-time API rates based on your ACS contract pricing
* Flat rate option as an alternative
* Free shipping threshold — configurable minimum order amount
* Handling fee and COD surcharge support
* Fallback cost when the API is unavailable
* Rate and zip code lookup caching to minimize API calls

= ACS Smartpoints =

* Pickup point selector at checkout — customers choose an ACS Smartpoint Locker or Store
* Search by area or zip code with live filtering
* Visual distinction between Lockers and Stores
* Selected pickup point saved to order and displayed in admin

= Requirements =

* ACS Courier API credentials — request from your local ACS branch or call 210-8190000

== Installation ==

1. Upload the `wc-acs-courier` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **WooCommerce → ACS Courier** and enter your API credentials

== Configuration ==

= 1. API Credentials =

Navigate to **WooCommerce → ACS Courier → API Credentials** and enter your API Key, Company ID, Company Password, User ID, and User Password. Use the **Test Connection** button to verify.

= 2. Shipping Settings =

* **Billing Code** — your ACS credit/billing code (e.g., 2ΑΘ999999)
* **Sender Name** — appears on the voucher label
* **Origin Station Code** — your local ACS station in Greek uppercase (e.g., ΑΘ for Athens, ΘΣ for Thessaloniki)
* **Default Weight** — used when products don't have weight set (minimum 0.5 kg)
* **Charge Type** — sender pays or recipient pays

= 3. Automation =

* **Auto-create Voucher** — enable and select which order status triggers automatic creation
* **Auto Tracking** — enable automatic shipment status checking
* **Tracking Frequency** — how often to check (hourly, twice daily, daily)
* **Email Tracking Code** — send tracking email to customer when voucher is created

= 4. Shipping Zones =

Go to **WooCommerce → Settings → Shipping** and add "ACS Courier" as a shipping method to your desired zones. Configure rate type, free shipping minimum, handling fee, COD fee, and fallback cost.

== Frequently Asked Questions ==

= Where do I get ACS API credentials? =

Contact your local ACS branch or call ACS customer service at 210-8190000 to request API access.

= Does this plugin support Cash on Delivery? =

Yes. When an order uses the COD payment method, the plugin automatically includes the COD amount on the voucher.

= Can customers pick up from ACS Smartpoints? =

Yes. Enable Smartpoints in the plugin settings and customers will see a pickup point selector at checkout where they can choose a nearby ACS Locker or Store.

= Is this plugin compatible with WooCommerce HPOS? =

Yes. The plugin fully supports High-Performance Order Storage (HPOS) and works with both legacy post-based and HPOS order storage.

= How does auto-tracking work? =

When enabled, a WP Cron job checks the shipment status of orders with active vouchers at your chosen frequency. Orders are automatically moved to "Delivered (ACS)" or "Delivery Denied (ACS)" status based on ACS tracking data.

= What happens when I deactivate or uninstall? =

Deactivation clears the tracking cron job. Uninstalling removes all plugin settings from the database but leaves order meta data intact.

== Screenshots ==

1. Voucher management metabox on the order edit screen
2. Plugin settings — API Credentials tab
3. Plugin settings — Shipping tab
4. Smartpoint pickup selector at checkout
5. Tracking details in the order metabox

== Changelog ==

= 1.0.0 =
* Initial release
* Voucher creation, printing, and deletion
* Pickup list management
* Automatic shipment tracking with custom order statuses
* Real-time and flat rate shipping cost calculation
* ACS Smartpoints pickup point selector at checkout
* Bulk voucher creation from orders list
* Customer email notifications with tracking number
* Frontend tracking shortcode
* Full HPOS compatibility
