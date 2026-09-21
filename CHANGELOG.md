# Changelog

All notable changes to ACS Courier for WooCommerce are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.3.1] - 2026-09-21

### Added

- Automatic vouchers skip orders shipped with Geniki Taxydromiki (wc-geniki-taxydromiki), the same way they skip BOX NOW orders

### Changed

- The points map shares one Leaflet copy with other carrier pickers on the same checkout

## [1.3.0] - 2026-09-11

### Added

- Every point in the ACS Points list has its own Select button, so a point is chosen in one tap
- The phone back button closes the map instead of leaving the checkout

### Changed

- Phone layout with a one-line title, 44px close, list handle and popup buttons, a taller list, and the list opens when the search field is focused

## [1.2.3] - 2026-09-09

### Fixed

- The chosen point is also forgotten when the shipping method arrives only as the posted field of the checkout refresh

## [1.2.2] - 2026-09-09

### Added

- "Remove" button on the chosen point; the chosen point is forgotten when the shipping method changes or the order completes

## [1.2.1] - 2026-09-09

### Fixed

- The exclusive cash-on-delivery mode now re-evaluates the ACS Point rate when the payment method changes (the shipping rate cache key follows the payment choice)

## [1.2.0] - 2026-09-09

### Added

- "exclusive" cash-on-delivery mode: choosing cash on delivery removes the ACS Point option and choosing ACS Point hides cash on delivery

## [1.1.4] - 2026-09-09

### Fixed

- The map settings are localized after the checkout renders, so the point-type filter and COD badges match the zone instance on first load

## [1.1.3] - 2026-09-09

### Fixed

- Map badges and point-type filter follow the ACS Points instance even when another rate is preselected at page load

## [1.1.2] - 2026-09-09

### Added

- "Cash on Delivery" setting on the ACS Points method: only at points with a terminal (default), never at lockers, or never at any point

## [1.1.1] - 2026-09-09

### Fixed

- Locker cash-on-delivery badge now follows the ACS notes text; the feed's COD flag is set on every locker and was showing "cash on delivery available" everywhere

## [1.1.0] - 2026-09-09

### Added

- "Pickup from ACS Point" shipping method with a map picker (lockers and stores)
- Daily ACS points feed, admin refresh button, REST route for the map
- Change the point from the order screen until a voucher exists; point in emails and My Account

### Fixed

- Vouchers to a point now use the ACS station codes (the old Smartpoint checkbox sent a home delivery to the shop's street)

### Removed

- The Smartpoints checkbox under the ACS Courier rate

## [1.0.1] - 2026-09-05

### Changed

- Automatic voucher creation skips orders shipped through another carrier plugin (BOX NOW), with a per-order veto filter wc_acs_auto_create_voucher_allowed and a wc_acs_other_carrier_method_ids filter for the excluded method ids

## [1.0.0] - 2026-08-26

### Changed

- Initial release
- Voucher creation, printing, and deletion
- Pickup list management
- Automatic shipment tracking with custom order statuses
- Real-time and flat rate shipping cost calculation
- ACS Smartpoints pickup point selector at checkout
- Bulk voucher creation from orders list
- Customer email notifications with tracking number
- Frontend tracking shortcode
- Full HPOS compatibility
