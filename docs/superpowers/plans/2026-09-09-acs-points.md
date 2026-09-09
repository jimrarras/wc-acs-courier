# ACS Points Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a zone-based "Pickup from ACS Point" shipping method with a Leaflet map picker, a daily points feed from ACS, and correct voucher routing to lockers and stores, replacing the never-working Smartpoints checkbox.

**Architecture:** Four new server classes in `includes/` (feed, shipping method, picker, order views) follow the plugin's singleton pattern and the BOX NOW plugin's session-backed picker pattern. The browser gets one jQuery IIFE that lazy-loads vendored Leaflet on first click and fetches a positional JSON list from a REST route. The voucher builder reads two station codes from order meta.

**Tech Stack:** PHP 7.4+, WooCommerce 6 to 11 (classic checkout, HPOS-safe meta), jQuery (bundled with WP), Leaflet 1.9.4 + Leaflet.markercluster 1.5.3 (vendored), PHPUnit 9.6 + Brain Monkey + Mockery, Docker `php:8.3-cli` for the test runner.

**Spec:** `docs/superpowers/specs/2026-09-09-acs-points-design.md`

**Repos:** Tasks 1 to 12 are in `D:\Documents\Projects\POOQ\wc-acs-courier` on branch `feat/acs-points`. Task 13 edits site files in `D:\Documents\Projects\POOQ\woo-importer` (branch `feat/newsletter` is checked out there; commit on it, the site-backups folders are independent). Task 14 is manual.

**File structure note:** the spec lists three new classes. This plan splits the order-side views (admin box, change control, customer and email output) into a fourth file, `includes/class-acs-points-order.php`, so the picker file stays focused on the checkout.

## Global Constraints

- PHP 7.4 syntax only: no union types, no `match`, no `str_contains`, no named arguments, no typed class properties.
- Text domain `wc-acs-courier`. Source strings in English; Greek goes in `languages/wc-acs-courier-el.po` (Task 12). Never hardcode Greek in PHP or JS.
- No em dashes anywhere (code, comments, strings, docs). Use a comma, a colon or a full stop.
- Every WooCommerce call inside a hook callback is guarded (`function_exists( 'WC' )`, `WC()->session` null checks) the way `class-acs-shipping-method.php` and the BOX NOW plugin do it.
- No build step, no transpiling, no CDN: every browser asset lives under `assets/`.
- Third-party hosts contacted by the browser: `tile.openstreetmap.org` only, and only after the customer opens the map.
- Order meta keys, exactly: `_acs_point_id`, `_acs_point_type`, `_acs_point_name`, `_acs_point_address`, `_acs_point_station`, `_acs_point_branch`, `_acs_point_cod`.
- Shipping method id `acs_points`. Session key `acs_point_id`. REST route `wc-acs/v1/points`. Option `wc_acs_points_feed`. Cron hook `wc_acs_points_cron`.
- Unit tests run in Docker from the plugin root (Git Bash):

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && export MSYS_NO_PATHCONV=1 && docker run --rm -v "D:/Documents/Projects/POOQ/wc-acs-courier:/app" -w /app php:8.3-cli vendor/bin/phpunit -c phpunit.xml
```

  Add `--filter <TestClass>` to run one file. Baseline before Task 1: `OK (80 tests, 146 assertions)`. Integration tests (real ACS calls, credentials from `.env`):

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && export MSYS_NO_PATHCONV=1 && docker run --rm --env-file .env -v "D:/Documents/Projects/POOQ/wc-acs-courier:/app" -w /app php:8.3-cli vendor/bin/phpunit -c phpunit-integration.xml
```

- PHP lint for any new or edited PHP file: `docker run --rm -v "D:/Documents/Projects/POOQ/wc-acs-courier:/app" php:8.3-cli php -l /app/<path>`.
- Commit messages end with the trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

---

### Task 1: Remove the Smartpoints module and extend the test harness

**Files:**
- Delete: `includes/class-acs-smartpoints.php`, `assets/js/acs-smartpoints.js`, `assets/css/acs-smartpoints.css`, `tests/Unit/SmartpointsTest.php`
- Modify: `wc-acs-courier.php:50-60` (includes list), `:95-100` (init), `:140-150` (activation file list)
- Modify: `tests/bootstrap.php` (WP_Error stub, REST stubs, require list)

**Interfaces:**
- Produces: `WP_Error::add()`, `has_errors()`, `get_error_codes()` in the test stub; `WP_REST_Request` and `WP_REST_Response` stubs; a bootstrap that no longer requires the Smartpoints file. Later tasks append their own `require_once` lines to the bootstrap.

- [ ] **Step 1: Delete the old module files**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && git rm -q includes/class-acs-smartpoints.php assets/js/acs-smartpoints.js assets/css/acs-smartpoints.css tests/Unit/SmartpointsTest.php && git status --short
```

Expected: four `D` lines.

- [ ] **Step 2: Remove the module from the main plugin file**

In `wc-acs-courier.php`, delete the line `'includes/class-acs-smartpoints.php',` in BOTH arrays (`wc_acs_includes()` and `wc_acs_activate()`), and delete the line `WC_ACS_Smartpoints::instance();` in `wc_acs_init()`.

- [ ] **Step 3: Rewrite the WP_Error stub and add REST stubs in `tests/bootstrap.php`**

Replace the whole `class WP_Error { ... }` block with:

```php
class WP_Error {
    protected $code;
    protected $message;
    protected $data;
    protected $errors = array();

    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
        if ( '' !== $code ) {
            $this->errors[ $code ][] = $message;
        }
    }

    public function add( $code, $message, $data = '' ) {
        $this->errors[ $code ][] = $message;
        if ( '' === $this->code ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_codes() {
        return array_keys( $this->errors );
    }

    public function get_error_message( $code = '' ) {
        if ( '' === $code ) {
            return $this->message;
        }
        return $this->errors[ $code ][0] ?? '';
    }

    public function get_error_messages( $code = '' ) {
        if ( '' !== $code ) {
            return $this->errors[ $code ] ?? array();
        }
        $all = array();
        foreach ( $this->errors as $messages ) {
            $all = array_merge( $all, $messages );
        }
        return $all;
    }

    public function get_error_data() {
        return $this->data;
    }

    public function has_errors() {
        return ! empty( $this->errors );
    }
}

// ── REST stubs ────────────────────────────────────────────────────
class WP_REST_Request {
    private $headers = array();
    private $params  = array();

    public function __construct( $method = 'GET', $route = '' ) {}

    private function key( $name ) {
        return strtolower( str_replace( '-', '_', $name ) );
    }

    public function set_header( $name, $value ) {
        $this->headers[ $this->key( $name ) ] = $value;
    }

    public function get_header( $name ) {
        return $this->headers[ $this->key( $name ) ] ?? null;
    }

    public function set_params( array $params ) {
        $this->params = $params;
    }

    public function get_params() {
        return $this->params;
    }
}

class WP_REST_Response {
    public $data;
    public $status;
    public $headers = array();

    public function __construct( $data = null, $status = 200 ) {
        $this->data   = $data;
        $this->status = $status;
    }

    public function header( $name, $value ) {
        $this->headers[ $name ] = $value;
    }

    public function get_headers() {
        return $this->headers;
    }

    public function get_status() {
        return $this->status;
    }

    public function get_data() {
        return $this->data;
    }
}
```

Then delete the line `require_once $plugin_dir . 'class-acs-smartpoints.php';` at the bottom of the bootstrap.

- [ ] **Step 4: Run the suite**

Run the unit test command from Global Constraints.
Expected: `OK (75 tests, ...)` (the five Smartpoints tests are gone; the two voucher smartpoint tests still pass because the voucher is untouched until Task 10).

- [ ] **Step 5: Lint and commit**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && export MSYS_NO_PATHCONV=1 && docker run --rm -v "D:/Documents/Projects/POOQ/wc-acs-courier:/app" php:8.3-cli php -l /app/wc-acs-courier.php && git add -A && git commit -q -m "refactor: remove the Smartpoints checkbox module

The picker hooked on the acs_courier rate, which pooq.gr never used, and
its station feed asked for kinds 7 and 4 (no lockers since ACS moved
them to kind 8). Replaced by the ACS Points method in the next tasks.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>" && git log --oneline -1
```

---

### Task 2: API call for the points feed

**Files:**
- Modify: `includes/class-acs-api.php` (replace `get_stations()`/`get_smartpoints()` block, lines 339-395)
- Test: `tests/Integration/APIIntegrationTest.php`

**Interfaces:**
- Produces: `WC_ACS_API::get_points_feed() : array|WP_Error`. On success returns the `ACSOutputResponce` array; the points are at `$result['ACSTableOutput']['Table_Data1']`, the icon legend at `['Table_Data']`.
- Keeps: `WC_ACS_API::get_stations( $country, $shop_kind )` unchanged (used by nothing after Task 1, but part of the public API).
- Removes: `WC_ACS_API::get_smartpoints()`.

- [ ] **Step 1: Write the failing integration test**

Append to `tests/Integration/APIIntegrationTest.php`, inside the class:

```php
    // ── Points feed ─────────────────────────────────────────────

    public function test_get_points_feed_returns_lockers_and_stores(): void {
        $result = \WC_ACS_API::get_points_feed();

        $this->assertIsArray( $result, 'get_points_feed should return an array' );
        $points = $result['ACSTableOutput']['Table_Data1'] ?? array();
        $this->assertGreaterThan( 1000, count( $points ), 'ACS publishes about 2,000 points' );

        $first = $points[0];
        foreach ( array( 'id', 'type', 'name', 'lat', 'lon', 'street', 'city', 'sa_zipcode', 'Acs_Station_Destination', 'Acs_Station_Branch_Destination', 'Acs_Smartpoint_COD_Supported', 'Country_Code' ) as $key ) {
            $this->assertArrayHasKey( $key, $first );
        }

        $types = array_unique( array_column( $points, 'type' ) );
        $this->assertContains( 'smartlocker', $types );
        $this->assertContains( 'branch', $types );
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run the integration command with `--filter test_get_points_feed_returns_lockers_and_stores`.
Expected: FAIL with `Call to undefined method WC_ACS_API::get_points_feed()`.

- [ ] **Step 3: Replace `get_smartpoints()` with `get_points_feed()`**

In `includes/class-acs-api.php`, delete the whole `get_smartpoints()` method (the docblock starting `Get ACS Smartpoint locations` through its closing brace) and insert in its place:

```php
    /**
     * Get every ACS pickup point (Smartpoint lockers and ACS stores) in one call.
     *
     * Uses the alias that ACS ships in its own merchant plugin. Each row carries
     * coordinates, opening hours, the two voucher routing codes
     * (Acs_Station_Destination, Acs_Station_Branch_Destination) and a flag for
     * card payment on collection. Verified 2026-09-09: about 2,000 rows.
     *
     * @return array|WP_Error ACSOutputResponce; points under ACSTableOutput.Table_Data1.
     */
    public static function get_points_feed() {
        return self::request( 'ACS_Get_Stations_For_Plugin', array(
            'locale' => null,
        ) );
    }
```

- [ ] **Step 4: Run the integration test and the unit suite**

Integration filter as in Step 2. Expected: PASS.
Unit suite. Expected: `OK (75 tests, ...)`; `tests/Unit/APITest.php` lines 220-240 reference `get_smartpoints` in a test named around stations. Open that test: if it calls `get_smartpoints`, delete that one test method (it tested the removed method) and re-run.

- [ ] **Step 5: Commit**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && git add -A && git commit -q -m "feat(api): get_points_feed() via ACS_Get_Stations_For_Plugin

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Points feed class (normalise, store, refresh, find, centre)

**Files:**
- Create: `includes/class-acs-points-feed.php`
- Modify: `tests/bootstrap.php` (add require), `wc-acs-courier.php` (include list + activation list + init + cron schedule/clear)
- Test: `tests/Unit/PointsFeedTest.php`

**Interfaces:**
- Produces:
  - `WC_ACS_Points_Feed::instance()`
  - `WC_ACS_Points_Feed::OPTION = 'wc_acs_points_feed'`, `::ERROR_OPTION = 'wc_acs_points_feed_error'`, `::CRON_HOOK = 'wc_acs_points_cron'`, `::MIN_POINTS = 100`
  - `static store_country() : string` (e.g. `'GR'`)
  - `static normalise( array $raw_points, string $country ) : array` of point arrays with keys `id, type, name, street, city, zip, lat, lon, station, branch, cod, h24, hours, sat`
  - `static centre_for_postcode( string $zip, array $points ) : ?array` returning `array( lat, lon, zoom )`
  - `refresh() : true|WP_Error`
  - `get_points() : array`, `fetched_at() : int`, `find( string $id ) : ?array`, `count() : int`
- Consumes: `WC_ACS_API::get_points_feed()` (Task 2), `WC_ACS_API::log( $msg, $level )`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/PointsFeedTest.php`:

```php
<?php
namespace WC_ACS_Tests\Unit;

use Brain\Monkey\Functions;

class PointsFeedTest extends TestCase {

    private $options = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetStaticProperty( \WC_ACS_Points_Feed::class, 'instance' );
        $this->resetStaticProperty( \WC_ACS_API::class, 'credentials' );
        $this->options = [
            'wc_acs_api_key'          => 'key',
            'wc_acs_company_id'       => 'C',
            'wc_acs_company_password' => 'cp',
            'wc_acs_user_id'          => 'U',
            'wc_acs_user_password'    => 'up',
            'wc_acs_debug_logging'    => 'no',
            'woocommerce_default_country' => 'GR:D',
        ];
        $options = &$this->options;
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( &$options ) {
            return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
        } );
        Functions\when( 'update_option' )->alias( function ( $key, $value, $autoload = null ) use ( &$options ) {
            $options[ $key ] = $value;
            return true;
        } );
        Functions\when( 'delete_option' )->alias( function ( $key ) use ( &$options ) {
            unset( $options[ $key ] );
            return true;
        } );
    }

    protected function tearDown(): void {
        $this->resetStaticProperty( \WC_ACS_Points_Feed::class, 'instance' );
        parent::tearDown();
    }

    private function rawPoint( array $overrides = [] ): array {
        return array_merge( [
            'id'                             => 4400,
            'icon'                           => 'https://www.acscourier.net/x.png',
            'type'                           => 'smartlocker',
            'name'                           => 'ACS  SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ',
            'lat'                            => '39.66497772450228',
            'lon'                            => '20.84896917550984',
            'title'                          => 'ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ',
            'street'                         => ' Market In, Χαρ. Τρικούπη 38 ',
            'area'                           => 'ΙΩΑΝΝΙΝΩΝ',
            'city'                           => 'ΙΩΑΝΝΙΝΑ',
            'sa_zipcode'                     => 45333,
            'notes'                          => 'x',
            'is_24h'                         => 1,
            'saturday'                       => '24ΩΡΟ',
            'weekdays'                       => '24ΩΡΟ',
            'Acs_Station_Destination'        => 'ΙΒ',
            'Acs_Station_Branch_Destination' => 501,
            'Acs_Smartpoint_COD_Supported'   => 1,
            'Country_Code'                   => 'GR',
        ], $overrides );
    }

    private function stubFeedResponse( array $points ): void {
        Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 200 ], 'body' => '' ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [
            'ACSExecution_HasError' => false,
            'ACSOutputResponce'     => [
                'ACSTableOutput' => [
                    'Table_Data'  => [ [ 'title' => 'branch', 'icon' => 'x' ] ],
                    'Table_Data1' => $points,
                ],
            ],
        ] ) );
    }

    // ── normalise() ──────────────────────────────────────────────

    public function test_normalise_maps_every_field(): void {
        $out = \WC_ACS_Points_Feed::normalise( [ $this->rawPoint() ], 'GR' );

        $this->assertCount( 1, $out );
        $this->assertSame( [
            'id'      => '4400',
            'type'    => 'locker',
            'name'    => 'ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ',
            'street'  => 'Market In, Χαρ. Τρικούπη 38',
            'city'    => 'ΙΩΑΝΝΙΝΑ',
            'zip'     => '45333',
            'lat'     => 39.664978,
            'lon'     => 20.848969,
            'station' => 'ΙΒ',
            'branch'  => '501',
            'cod'     => 1,
            'h24'     => 1,
            'hours'   => '24ΩΡΟ',
            'sat'     => '24ΩΡΟ',
        ], $out[0] );
    }

    public function test_normalise_drops_other_countries_and_missing_coordinates(): void {
        $out = \WC_ACS_Points_Feed::normalise( [
            $this->rawPoint( [ 'id' => 1, 'Country_Code' => 'CY' ] ),
            $this->rawPoint( [ 'id' => 2, 'lat' => '', 'lon' => '' ] ),
            $this->rawPoint( [ 'id' => 3, 'lat' => 'abc' ] ),
            $this->rawPoint( [ 'id' => 4, 'id' => '' ] ),
            $this->rawPoint( [ 'id' => 5 ] ),
        ], 'GR' );

        $this->assertCount( 1, $out );
        $this->assertSame( '5', $out[0]['id'] );
    }

    public function test_normalise_forces_cod_on_stores(): void {
        $out = \WC_ACS_Points_Feed::normalise( [
            $this->rawPoint( [ 'type' => 'branch', 'Acs_Smartpoint_COD_Supported' => 0, 'is_24h' => 0, 'weekdays' => '08:00-20:00', 'saturday' => '08:00-15:00' ] ),
            $this->rawPoint( [ 'type' => 'smartlocker', 'Acs_Smartpoint_COD_Supported' => 0 ] ),
        ], 'GR' );

        $this->assertSame( 'store', $out[0]['type'] );
        $this->assertSame( 1, $out[0]['cod'] );
        $this->assertSame( 0, $out[0]['h24'] );
        $this->assertSame( 'locker', $out[1]['type'] );
        $this->assertSame( 0, $out[1]['cod'] );
    }

    // ── store_country() ──────────────────────────────────────────

    public function test_store_country_strips_state_suffix(): void {
        $this->assertSame( 'GR', \WC_ACS_Points_Feed::store_country() );
        $this->options['woocommerce_default_country'] = 'cy';
        $this->assertSame( 'CY', \WC_ACS_Points_Feed::store_country() );
    }

    // ── refresh() ────────────────────────────────────────────────

    private function manyPoints( int $n ): array {
        $points = [];
        for ( $i = 1; $i <= $n; $i++ ) {
            $points[] = $this->rawPoint( [ 'id' => $i ] );
        }
        return $points;
    }

    public function test_refresh_stores_normalised_points(): void {
        $this->stubFeedResponse( $this->manyPoints( 150 ) );

        $result = \WC_ACS_Points_Feed::instance()->refresh();

        $this->assertTrue( $result );
        $stored = $this->options['wc_acs_points_feed'];
        $this->assertSame( 'GR', $stored['country'] );
        $this->assertCount( 150, $stored['points'] );
        $this->assertGreaterThan( 0, $stored['fetched_at'] );
        $this->assertSame( 150, \WC_ACS_Points_Feed::instance()->count() );
    }

    public function test_refresh_keeps_stored_copy_on_api_error(): void {
        $this->options['wc_acs_points_feed'] = [ 'fetched_at' => 123, 'country' => 'GR', 'points' => [ [ 'id' => 'old' ] ] ];
        Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http', 'down' ) );

        $result = \WC_ACS_Points_Feed::instance()->refresh();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'old', $this->options['wc_acs_points_feed']['points'][0]['id'] );
        $this->assertSame( 'down', $this->options['wc_acs_points_feed_error'] );
    }

    public function test_refresh_keeps_stored_copy_on_short_list(): void {
        $this->options['wc_acs_points_feed'] = [ 'fetched_at' => 123, 'country' => 'GR', 'points' => [ [ 'id' => 'old' ] ] ];
        $this->stubFeedResponse( $this->manyPoints( 20 ) );

        $result = \WC_ACS_Points_Feed::instance()->refresh();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'acs_points_feed_short', $result->get_error_code() );
        $this->assertSame( 123, $this->options['wc_acs_points_feed']['fetched_at'] );
    }

    public function test_refresh_clears_previous_error(): void {
        $this->options['wc_acs_points_feed_error'] = 'old error';
        $this->stubFeedResponse( $this->manyPoints( 120 ) );

        \WC_ACS_Points_Feed::instance()->refresh();

        $this->assertArrayNotHasKey( 'wc_acs_points_feed_error', $this->options );
    }

    // ── find() ───────────────────────────────────────────────────

    public function test_find_returns_point_or_null(): void {
        $this->options['wc_acs_points_feed'] = [
            'fetched_at' => 1,
            'country'    => 'GR',
            'points'     => \WC_ACS_Points_Feed::normalise( [ $this->rawPoint( [ 'id' => 77 ] ) ], 'GR' ),
        ];
        $feed = \WC_ACS_Points_Feed::instance();

        $this->assertSame( '77', $feed->find( '77' )['id'] );
        $this->assertSame( '77', $feed->find( 77 )['id'] );
        $this->assertNull( $feed->find( '78' ) );
        $this->assertNull( $feed->find( '' ) );
    }

    // ── centre_for_postcode() ────────────────────────────────────

    public function test_centre_prefers_three_digit_prefix_then_two_then_null(): void {
        $points = [
            [ 'zip' => '45333', 'lat' => 39.60, 'lon' => 20.80 ],
            [ 'zip' => '45334', 'lat' => 39.70, 'lon' => 20.90 ],
            [ 'zip' => '45221', 'lat' => 39.00, 'lon' => 20.00 ],
            [ 'zip' => '10563', 'lat' => 37.97, 'lon' => 23.73 ],
        ];

        $this->assertSame( [ 39.65, 20.85, 13 ], \WC_ACS_Points_Feed::centre_for_postcode( '453 30', $points ) );
        $this->assertSame( [ 39.60, 20.80, 10 ], \WC_ACS_Points_Feed::centre_for_postcode( '45900', $points ) );
        $this->assertNull( \WC_ACS_Points_Feed::centre_for_postcode( '99999', $points ) );
        $this->assertNull( \WC_ACS_Points_Feed::centre_for_postcode( '1', $points ) );
    }
}
```

- [ ] **Step 2: Add the bootstrap require and run to verify failure**

In `tests/bootstrap.php`, after `require_once $plugin_dir . 'class-acs-tracking.php';` add:

```php
require_once $plugin_dir . 'class-acs-points-feed.php';
```

Run with `--filter PointsFeedTest`. Expected: bootstrap fatal `Failed opening required ... class-acs-points-feed.php`.

- [ ] **Step 3: Create `includes/class-acs-points-feed.php`**

```php
<?php
/**
 * ACS Points feed.
 *
 * Fetches every ACS pickup point (Smartpoint lockers and stores), keeps a
 * normalised copy in an option, refreshes it daily and answers lookups.
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Points_Feed {

    const OPTION       = 'wc_acs_points_feed';
    const ERROR_OPTION = 'wc_acs_points_feed_error';
    const CRON_HOOK    = 'wc_acs_points_cron';
    const MIN_POINTS   = 100;

    /** @var WC_ACS_Points_Feed|null */
    private static $instance = null;

    /** @var array|null Decoded option, loaded once per request. */
    private $stored = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::CRON_HOOK, array( $this, 'refresh' ) );
        add_action( 'init', array( $this, 'maybe_schedule' ) );
    }

    /**
     * Store base country without WooCommerce's optional ":STATE" suffix.
     *
     * @return string
     */
    public static function store_country() {
        $raw   = (string) get_option( 'woocommerce_default_country', 'GR' );
        $parts = explode( ':', $raw );
        $code  = strtoupper( trim( $parts[0] ) );
        return '' === $code ? 'GR' : $code;
    }

    /**
     * Turn raw feed rows into compact point records.
     *
     * Stores always get cod = 1: the feed's flag describes locker terminals
     * and reports 0 for branches, but every ACS store takes cash on delivery.
     *
     * @param array  $raw_points Rows from ACSTableOutput.Table_Data1.
     * @param string $country    Country code to keep (GR or CY).
     * @return array
     */
    public static function normalise( array $raw_points, $country ) {
        $country = strtoupper( (string) $country );
        $out     = array();

        foreach ( $raw_points as $raw ) {
            if ( ! is_array( $raw ) ) {
                continue;
            }
            if ( strtoupper( (string) ( $raw['Country_Code'] ?? '' ) ) !== $country ) {
                continue;
            }
            $id = trim( (string) ( $raw['id'] ?? '' ) );
            if ( '' === $id ) {
                continue;
            }
            $lat = $raw['lat'] ?? null;
            $lon = $raw['lon'] ?? null;
            if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) ) {
                continue;
            }

            $type = ( 'branch' === ( $raw['type'] ?? '' ) ) ? 'store' : 'locker';

            $out[] = array(
                'id'      => $id,
                'type'    => $type,
                'name'    => trim( preg_replace( '/\s+/u', ' ', (string) ( $raw['name'] ?? '' ) ) ),
                'street'  => trim( (string) ( $raw['street'] ?? '' ) ),
                'city'    => trim( (string) ( $raw['city'] ?? '' ) ),
                'zip'     => trim( (string) ( $raw['sa_zipcode'] ?? '' ) ),
                'lat'     => round( (float) $lat, 6 ),
                'lon'     => round( (float) $lon, 6 ),
                'station' => trim( (string) ( $raw['Acs_Station_Destination'] ?? '' ) ),
                'branch'  => trim( (string) ( $raw['Acs_Station_Branch_Destination'] ?? '' ) ),
                'cod'     => 'store' === $type ? 1 : (int) ! empty( $raw['Acs_Smartpoint_COD_Supported'] ),
                'h24'     => (int) ! empty( $raw['is_24h'] ),
                'hours'   => trim( (string) ( $raw['weekdays'] ?? '' ) ),
                'sat'     => trim( (string) ( $raw['saturday'] ?? '' ) ),
            );
        }

        return $out;
    }

    /**
     * Fetch from ACS and replace the stored list. Keeps the old list on failure.
     *
     * @return true|WP_Error
     */
    public function refresh() {
        $result = WC_ACS_API::get_points_feed();

        if ( is_wp_error( $result ) ) {
            $this->remember_error( $result->get_error_message() );
            return $result;
        }

        $raw     = $result['ACSTableOutput']['Table_Data1'] ?? array();
        $country = self::store_country();
        $points  = self::normalise( is_array( $raw ) ? $raw : array(), $country );

        if ( count( $points ) < self::MIN_POINTS ) {
            $error = new WP_Error(
                'acs_points_feed_short',
                sprintf(
                    /* translators: %d: number of points returned */
                    __( 'ACS returned only %d points; keeping the stored list.', 'wc-acs-courier' ),
                    count( $points )
                )
            );
            $this->remember_error( $error->get_error_message() );
            return $error;
        }

        $this->stored = array(
            'fetched_at' => time(),
            'country'    => $country,
            'points'     => $points,
        );
        update_option( self::OPTION, $this->stored, false );
        delete_option( self::ERROR_OPTION );

        return true;
    }

    private function remember_error( $message ) {
        WC_ACS_API::log( 'Points feed refresh failed: ' . $message, 'error' );
        update_option( self::ERROR_OPTION, $message, false );
    }

    /**
     * Last refresh error message, or empty string.
     *
     * @return string
     */
    public function last_error() {
        return (string) get_option( self::ERROR_OPTION, '' );
    }

    private function stored() {
        if ( null === $this->stored ) {
            $value        = get_option( self::OPTION, array() );
            $this->stored = is_array( $value ) ? $value : array();
        }
        return $this->stored;
    }

    /** @return array */
    public function get_points() {
        $stored = $this->stored();
        return isset( $stored['points'] ) && is_array( $stored['points'] ) ? $stored['points'] : array();
    }

    /** @return int Unix timestamp of the last successful refresh, 0 if none. */
    public function fetched_at() {
        $stored = $this->stored();
        return (int) ( $stored['fetched_at'] ?? 0 );
    }

    /** @return int */
    public function count() {
        return count( $this->get_points() );
    }

    /**
     * @param string|int $id Feed id.
     * @return array|null
     */
    public function find( $id ) {
        $id = trim( (string) $id );
        if ( '' === $id ) {
            return null;
        }
        foreach ( $this->get_points() as $point ) {
            if ( isset( $point['id'] ) && (string) $point['id'] === $id ) {
                return $point;
            }
        }
        return null;
    }

    /**
     * Map centre for a customer postcode: median of the points sharing the
     * first three digits (zoom 13), else the first two (zoom 10), else null.
     *
     * @param string $zip    Postcode as typed.
     * @param array  $points Point records with zip, lat, lon.
     * @return array|null array( lat, lon, zoom )
     */
    public static function centre_for_postcode( $zip, array $points ) {
        $digits = preg_replace( '/\D+/', '', (string) $zip );

        foreach ( array( array( 3, 13 ), array( 2, 10 ) ) as $rule ) {
            list( $len, $zoom ) = $rule;
            if ( strlen( $digits ) < $len ) {
                continue;
            }
            $prefix = substr( $digits, 0, $len );
            $lats   = array();
            $lons   = array();
            foreach ( $points as $p ) {
                if ( 0 === strpos( (string) ( $p['zip'] ?? '' ), $prefix ) ) {
                    $lats[] = (float) $p['lat'];
                    $lons[] = (float) $p['lon'];
                }
            }
            if ( ! empty( $lats ) ) {
                return array( self::median( $lats ), self::median( $lons ), $zoom );
            }
        }

        return null;
    }

    private static function median( array $values ) {
        sort( $values );
        $n   = count( $values );
        $mid = (int) floor( $n / 2 );
        $med = ( 0 === $n % 2 ) ? ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2 : $values[ $mid ];
        return round( $med, 6 );
    }

    /**
     * Schedule the daily refresh if it is missing (covers upgrades that skip
     * the activation hook).
     */
    public function maybe_schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }
}
```

- [ ] **Step 4: Run the tests**

`--filter PointsFeedTest`. Expected: 11 tests PASS. Then the full suite. Expected: `OK (86 tests, ...)`.

- [ ] **Step 5: Wire the class into the plugin**

In `wc-acs-courier.php`:
- Add `'includes/class-acs-points-feed.php',` after `'includes/class-acs-tracking.php',` in BOTH file arrays.
- In `wc_acs_init()`, after `WC_ACS_Tracking::instance();` add `WC_ACS_Points_Feed::instance();`.
- In `wc_acs_activate()`, after the tracking cron block add:

```php
    // Schedule the daily ACS points refresh and fetch once right away.
    if ( ! wp_next_scheduled( 'wc_acs_points_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'wc_acs_points_cron' );
    }
```

- In `wc_acs_deactivate()` add `wp_clear_scheduled_hook( 'wc_acs_points_cron' );`.
- In `uninstall.php`, add `'wc_acs_points_feed',` and `'wc_acs_points_feed_error',` to `$options`, and `wp_clear_scheduled_hook( 'wc_acs_points_cron' );` after the tracking one.

- [ ] **Step 6: Lint, test, commit**

Lint `wc-acs-courier.php`, `uninstall.php`, `includes/class-acs-points-feed.php`. Run the full suite (expected 86 pass).

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && git add -A && git commit -q -m "feat(points): feed class with daily refresh, normalisation, lookup and postcode centring

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Feed delivery: REST route, admin refresh button

**Files:**
- Modify: `includes/class-acs-points-feed.php` (REST + AJAX), `includes/class-acs-admin.php` (settings block, localize strings), `assets/js/acs-admin.js` (refresh button)
- Test: `tests/Unit/PointsFeedTest.php` (append)

**Interfaces:**
- Produces: `GET /wp-json/wc-acs/v1/points` returning `{ "v": <fetched_at>, "points": [[id, type, name, street, city, zip, lat, lon, station, branch, cod, h24, hours, sat], ...] }` with `Cache-Control: public, max-age=86400` and `ETag: "<fetched_at>"`, `304` on a matching `If-None-Match`.
- Produces: `WC_ACS_Points_Feed::payload() : array`, `rest_points( WP_REST_Request ) : WP_REST_Response`, `ajax_refresh()` (action `wc_acs_refresh_points`, nonce `wc_acs_nonce`, cap `manage_woocommerce`).
- Consumes: `wc_acs` localized object in `acs-admin.js` (has `ajax_url`, `nonce`, `i18n`).

- [ ] **Step 1: Append failing tests to `tests/Unit/PointsFeedTest.php`**

Inside the class, before the final `}`:

```php
    // ── payload() / rest_points() ────────────────────────────────

    private function seedStored(): void {
        $this->options['wc_acs_points_feed'] = [
            'fetched_at' => 1700000000,
            'country'    => 'GR',
            'points'     => \WC_ACS_Points_Feed::normalise( [ $this->rawPoint() ], 'GR' ),
        ];
    }

    public function test_payload_is_positional_in_documented_order(): void {
        $this->seedStored();

        $payload = \WC_ACS_Points_Feed::instance()->payload();

        $this->assertSame( 1700000000, $payload['v'] );
        $this->assertSame(
            [ '4400', 'locker', 'ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ', 'Market In, Χαρ. Τρικούπη 38', 'ΙΩΑΝΝΙΝΑ', '45333', 39.664978, 20.848969, 'ΙΒ', '501', 1, 1, '24ΩΡΟ', '24ΩΡΟ' ],
            $payload['points'][0]
        );
    }

    public function test_rest_points_sets_cache_headers(): void {
        $this->seedStored();
        $request = new \WP_REST_Request( 'GET', '/wc-acs/v1/points' );

        $response = \WC_ACS_Points_Feed::instance()->rest_points( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'public, max-age=86400', $response->get_headers()['Cache-Control'] );
        $this->assertSame( '"1700000000"', $response->get_headers()['ETag'] );
        $this->assertCount( 1, $response->get_data()['points'] );
    }

    public function test_rest_points_returns_304_on_matching_etag(): void {
        $this->seedStored();
        $request = new \WP_REST_Request( 'GET', '/wc-acs/v1/points' );
        $request->set_header( 'If-None-Match', '"1700000000"' );

        $response = \WC_ACS_Points_Feed::instance()->rest_points( $request );

        $this->assertSame( 304, $response->get_status() );
        $this->assertNull( $response->get_data() );
    }

    // ── ajax_refresh() ───────────────────────────────────────────

    public function test_ajax_refresh_requires_capability(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );
        $captured = null;
        Functions\when( 'wp_send_json_error' )->alias( function ( $data ) use ( &$captured ) {
            $captured = $data;
            throw new \RuntimeException( 'exit' );
        } );

        try {
            \WC_ACS_Points_Feed::instance()->ajax_refresh();
        } catch ( \RuntimeException $e ) {
        }

        $this->assertSame( 'Unauthorized.', $captured );
    }

    public function test_ajax_refresh_reports_count_and_date(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'date_i18n' )->justReturn( '2026-09-09 10:00' );
        $this->stubFeedResponse( $this->manyPoints( 130 ) );
        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$captured ) {
            $captured = $data;
            throw new \RuntimeException( 'exit' );
        } );

        try {
            \WC_ACS_Points_Feed::instance()->ajax_refresh();
        } catch ( \RuntimeException $e ) {
        }

        $this->assertSame( 130, $captured['count'] );
        $this->assertSame( '2026-09-09 10:00', $captured['fetched'] );
    }
```

- [ ] **Step 2: Run to verify failure**

`--filter PointsFeedTest`. Expected: 5 failures, `undefined method payload/rest_points/ajax_refresh`.

- [ ] **Step 3: Add REST and AJAX to the feed class**

In `includes/class-acs-points-feed.php`, add to the constructor after the `init` line:

```php
        add_action( 'rest_api_init', array( $this, 'register_rest' ) );
        add_action( 'wp_ajax_wc_acs_refresh_points', array( $this, 'ajax_refresh' ) );
```

Add these methods before the final `}` of the class:

```php
    const REST_NAMESPACE = 'wc-acs/v1';

    /**
     * Public, read-only route. The list is not personal data and the browser
     * caches it for a day, so no nonce and no cookies are involved.
     */
    public function register_rest() {
        register_rest_route( self::REST_NAMESPACE, '/points', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_points' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Positional rows keep the payload around 60 KB gzipped for 2,000 points.
     * Column order: id, type, name, street, city, zip, lat, lon, station,
     * branch, cod, h24, hours, sat. acs-points.js reads the same order.
     *
     * @return array
     */
    public function payload() {
        $rows = array();
        foreach ( $this->get_points() as $p ) {
            $rows[] = array(
                $p['id'], $p['type'], $p['name'], $p['street'], $p['city'], $p['zip'],
                $p['lat'], $p['lon'], $p['station'], $p['branch'],
                (int) $p['cod'], (int) $p['h24'], $p['hours'], $p['sat'],
            );
        }
        return array(
            'v'      => $this->fetched_at(),
            'points' => $rows,
        );
    }

    /**
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function rest_points( $request ) {
        $etag = '"' . $this->fetched_at() . '"';

        if ( $request->get_header( 'if_none_match' ) === $etag ) {
            $response = new WP_REST_Response( null, 304 );
            $response->header( 'ETag', $etag );
            return $response;
        }

        $response = new WP_REST_Response( $this->payload(), 200 );
        $response->header( 'Cache-Control', 'public, max-age=86400' );
        $response->header( 'ETag', $etag );
        return $response;
    }

    /**
     * Settings-page button: refresh now and report.
     */
    public function ajax_refresh() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        WC_ACS_API::reset_credentials();
        $result = $this->refresh();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'count'   => $this->count(),
            'fetched' => date_i18n( 'Y-m-d H:i', $this->fetched_at() ),
        ) );
    }
```

- [ ] **Step 4: Run the tests**

`--filter PointsFeedTest`. Expected: 16 PASS. Full suite: `OK (91 tests, ...)`.

- [ ] **Step 5: Add the settings block**

In `includes/class-acs-admin.php`, inside the `'shipping' === $active_tab` table, add a final row before `</table>` (find the `wc_acs_charge_type` row, which is the last one, and add after it):

```php
                        <tr>
                            <th scope="row"><?php esc_html_e( 'ACS Points', 'wc-acs-courier' ); ?></th>
                            <td>
                                <?php
                                $feed    = WC_ACS_Points_Feed::instance();
                                $fetched = $feed->fetched_at();
                                ?>
                                <p id="wc-acs-points-status">
                                    <?php
                                    if ( $fetched > 0 ) {
                                        printf(
                                            /* translators: 1: number of points, 2: date */
                                            esc_html__( 'ACS points: %1$s, updated %2$s', 'wc-acs-courier' ),
                                            esc_html( number_format_i18n( $feed->count() ) ),
                                            esc_html( date_i18n( 'Y-m-d H:i', $fetched ) )
                                        );
                                    } else {
                                        esc_html_e( 'Points have never been fetched.', 'wc-acs-courier' );
                                    }
                                    ?>
                                </p>
                                <?php if ( '' !== $feed->last_error() ) : ?>
                                    <p class="description" style="color:#b32d2e;"><?php echo esc_html( $feed->last_error() ); ?></p>
                                <?php endif; ?>
                                <button type="button" id="wc-acs-refresh-points" class="button button-secondary">
                                    <?php esc_html_e( 'Refresh points', 'wc-acs-courier' ); ?>
                                </button>
                                <span id="wc-acs-refresh-result" class="wc-acs-test-result"></span>
                                <p class="description"><?php esc_html_e( 'Lockers and stores offered by the "Pickup from ACS Point" shipping method. Refreshed automatically once a day.', 'wc-acs-courier' ); ?></p>
                            </td>
                        </tr>
```

In `enqueue_assets()`, inside the `'i18n' => array(` of `wp_localize_script( 'wc-acs-admin', ...)` add `'refreshing' => __( 'Refreshing...', 'wc-acs-courier' ),`.

- [ ] **Step 6: Add the button handler to `assets/js/acs-admin.js`**

In `bindEvents()` after the test-connection line add:

```js
            $(document).on('click', '#wc-acs-refresh-points', this.refreshPoints);
```

After the `testConnection` method add:

```js
        // ─── Refresh ACS points ────────────────────────────────
        refreshPoints(e) {
            e.preventDefault();
            const $btn = $(this);
            const $result = $('#wc-acs-refresh-result');

            $btn.prop('disabled', true);
            $result.text(wc_acs.i18n.refreshing).removeClass('success error');

            $.post(wc_acs.ajax_url, {
                action: 'wc_acs_refresh_points',
                nonce: wc_acs.nonce,
            })
                .done(function (res) {
                    if (res.success) {
                        $result.text('✓ ' + res.data.count + ' (' + res.data.fetched + ')').addClass('success');
                        $('#wc-acs-points-status').text(res.data.count + ' / ' + res.data.fetched);
                    } else {
                        $result.text('✗ ' + res.data).addClass('error');
                    }
                })
                .fail(function () {
                    $result.text('✗ Request failed').addClass('error');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },
```

- [ ] **Step 7: Lint, syntax-check JS, test, commit**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && export MSYS_NO_PATHCONV=1 && docker run --rm -v "D:/Documents/Projects/POOQ/wc-acs-courier:/app" php:8.3-cli sh -c 'php -l /app/includes/class-acs-points-feed.php && php -l /app/includes/class-acs-admin.php' && node --check assets/js/acs-admin.js && echo JS OK
```

Full suite expected `OK (91 tests, ...)`.

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && git add -A && git commit -q -m "feat(points): REST route with cache headers, settings refresh button

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Shipping method `acs_points`

**Files:**
- Create: `includes/class-acs-points-shipping-method.php`
- Modify: `wc-acs-courier.php` (file lists, registration), `tests/bootstrap.php` (require)
- Test: `tests/Unit/PointsShippingMethodTest.php`

**Interfaces:**
- Produces: class `WC_ACS_Points_Shipping_Method extends WC_Shipping_Method`, `id = 'acs_points'`, public properties `$cost, $free_min, $max_weight, $point_types` (strings from instance settings), rate id `acs_points:<instance>`; `static package_weight_kg( array $package ) : float`.
- Instance option key (read by Task 6): `woocommerce_acs_points_<instance_id>_settings`, array with `point_types` = `both|lockers`.
- Consumes: `WC_ACS_Voucher::convert_weight_to_kg()`, option `wc_acs_default_weight`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/PointsShippingMethodTest.php`:

```php
<?php
namespace WC_ACS_Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

class PointsShippingMethodTest extends TestCase {

    private \WC_ACS_Points_Shipping_Method $method;

    protected function setUp(): void {
        parent::setUp();
        $this->stubGetOption( [
            'wc_acs_default_weight'   => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );
        $this->method = new \WC_ACS_Points_Shipping_Method( 3 );
        $this->method->cost        = '2.50';
        $this->method->free_min    = '';
        $this->method->max_weight  = '6';
        $this->method->point_types = 'both';
    }

    private function item( $weight, int $qty ): array {
        $product = Mockery::mock( 'WC_Product' );
        $product->shouldReceive( 'get_weight' )->andReturn( $weight );
        return [ 'data' => $product, 'quantity' => $qty ];
    }

    private function package( array $items, float $cost = 30 ): array {
        return [ 'contents' => $items, 'contents_cost' => $cost, 'destination' => [ 'postcode' => '45333', 'country' => 'GR' ] ];
    }

    public function test_identity(): void {
        $this->assertSame( 'acs_points', $this->method->id );
        $this->assertSame( 3, $this->method->instance_id );
        $this->assertContains( 'shipping-zones', $this->method->supports );
        $this->assertContains( 'instance-settings', $this->method->supports );
    }

    public function test_adds_rate_at_cost(): void {
        $this->method->calculate_shipping( $this->package( [ $this->item( '0.2', 2 ) ] ) );

        $this->assertCount( 1, $this->method->rates_added );
        $this->assertSame( 'acs_points:3', $this->method->rates_added[0]['id'] );
        $this->assertEquals( 2.5, $this->method->rates_added[0]['cost'] );
    }

    public function test_free_above_threshold(): void {
        $this->method->free_min = '50';

        $this->method->calculate_shipping( $this->package( [ $this->item( '0.2', 1 ) ], 50 ) );

        $this->assertEquals( 0, $this->method->rates_added[0]['cost'] );
    }

    public function test_withheld_above_max_weight(): void {
        $this->method->calculate_shipping( $this->package( [ $this->item( '3.5', 2 ) ] ) );

        $this->assertCount( 0, $this->method->rates_added );
    }

    public function test_zero_max_weight_means_no_limit(): void {
        $this->method->max_weight = '0';

        $this->method->calculate_shipping( $this->package( [ $this->item( '30', 1 ) ] ) );

        $this->assertCount( 1, $this->method->rates_added );
    }

    public function test_package_weight_uses_default_for_weightless_items(): void {
        $weight = \WC_ACS_Points_Shipping_Method::package_weight_kg( $this->package( [
            $this->item( '', 2 ),
            $this->item( '1.5', 1 ),
        ] ) );

        $this->assertEqualsWithDelta( 2.5, $weight, 0.001 );
    }

    public function test_package_weight_converts_grams(): void {
        $this->stubGetOption( [ 'wc_acs_default_weight' => '0.5', 'woocommerce_weight_unit' => 'g' ] );

        $weight = \WC_ACS_Points_Shipping_Method::package_weight_kg( $this->package( [ $this->item( '250', 4 ) ] ) );

        $this->assertEqualsWithDelta( 1.0, $weight, 0.001 );
    }
}
```

- [ ] **Step 2: Add the bootstrap require and run to verify failure**

In `tests/bootstrap.php` after the feed require add `require_once $plugin_dir . 'class-acs-points-shipping-method.php';`. Run `--filter PointsShippingMethodTest`. Expected: fatal, file missing.

- [ ] **Step 3: Create `includes/class-acs-points-shipping-method.php`**

```php
<?php
/**
 * "Pickup from ACS Point" shipping method.
 *
 * Zone-based flat cost with a free-delivery threshold and a weight cap. The
 * point itself is chosen by WC_ACS_Points_Picker; this class only prices and
 * offers the rate.
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Points_Shipping_Method extends WC_Shipping_Method {

    const METHOD_ID = 'acs_points';

    /** @var string Flat cost. */
    public $cost;

    /** @var string Subtotal at or above which the rate is free; empty disables. */
    public $free_min;

    /** @var string Max package weight in kg; 0 disables the cap. */
    public $max_weight;

    /** @var string 'both' or 'lockers'. */
    public $point_types;

    /**
     * @param int $instance_id Shipping zone instance id.
     */
    public function __construct( $instance_id = 0 ) {
        $this->id                 = self::METHOD_ID;
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'ACS Points', 'wc-acs-courier' );
        $this->method_description = __( 'Pickup from an ACS Smartpoint locker or an ACS store, chosen on a map at checkout.', 'wc-acs-courier' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        parent::__construct( $instance_id );
        $this->init();
    }

    private function init() {
        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'Pickup from ACS Point', 'wc-acs-courier' ) );
        $this->enabled     = $this->get_option( 'enabled', 'yes' );
        $this->tax_status  = $this->get_option( 'tax_status', 'none' );
        $this->cost        = $this->get_option( 'cost', '0' );
        $this->free_min    = $this->get_option( 'free_min', '' );
        $this->max_weight  = $this->get_option( 'max_weight', '6' );
        $this->point_types = $this->get_option( 'point_types', 'both' );

        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public function init_form_fields() {
        $this->instance_form_fields = array(
            'enabled'     => array(
                'title'   => __( 'Enable/Disable', 'wc-acs-courier' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this shipping method', 'wc-acs-courier' ),
                'default' => 'yes',
            ),
            'title'       => array(
                'title'       => __( 'Method Title', 'wc-acs-courier' ),
                'type'        => 'text',
                'description' => __( 'Shown to the customer at checkout.', 'wc-acs-courier' ),
                'default'     => __( 'Pickup from ACS Point', 'wc-acs-courier' ),
                'desc_tip'    => true,
            ),
            'cost'        => array(
                'title'       => __( 'Cost', 'wc-acs-courier' ),
                'type'        => 'price',
                'description' => __( 'Flat cost for pickup at an ACS Point.', 'wc-acs-courier' ),
                'default'     => '0',
                'desc_tip'    => true,
            ),
            'free_min'    => array(
                'title'       => __( 'Free Delivery Threshold', 'wc-acs-courier' ),
                'type'        => 'price',
                'description' => __( 'Order subtotal at or above which pickup is free. Leave empty to disable.', 'wc-acs-courier' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'max_weight'  => array(
                'title'       => __( 'Max Weight (kg)', 'wc-acs-courier' ),
                'type'        => 'decimal',
                'description' => __( 'The rate is not offered above this package weight. ACS standard lockers take up to 6 kg. 0 disables the cap.', 'wc-acs-courier' ),
                'default'     => '6',
                'desc_tip'    => true,
            ),
            'point_types' => array(
                'title'       => __( 'Point Types', 'wc-acs-courier' ),
                'type'        => 'select',
                'description' => __( 'Which ACS Points the customer may choose.', 'wc-acs-courier' ),
                'default'     => 'both',
                'options'     => array(
                    'both'    => __( 'Lockers and stores', 'wc-acs-courier' ),
                    'lockers' => __( 'Lockers only', 'wc-acs-courier' ),
                ),
                'desc_tip'    => true,
            ),
            'tax_status'  => array(
                'title'   => __( 'Tax Status', 'wc-acs-courier' ),
                'type'    => 'select',
                'default' => 'none',
                'options' => array(
                    'taxable' => __( 'Taxable', 'wc-acs-courier' ),
                    'none'    => __( 'None', 'wc-acs-courier' ),
                ),
            ),
        );
    }

    /**
     * @param array $package Shipping package.
     */
    public function calculate_shipping( $package = array() ) {
        $max = (float) $this->max_weight;
        if ( $max > 0 && self::package_weight_kg( $package ) > $max ) {
            return;
        }

        $cost     = (float) $this->cost;
        $free_min = (float) $this->free_min;
        $subtotal = (float) ( $package['contents_cost'] ?? 0 );

        if ( $free_min > 0 && $subtotal >= $free_min ) {
            $cost = 0;
        }

        $this->add_rate( array(
            'id'      => $this->get_rate_id(),
            'label'   => $this->title,
            'cost'    => $cost,
            'package' => $package,
        ) );
    }

    /**
     * Package weight in kg. Items without a weight count as the plugin's
     * default weight, the same assumption the voucher builder makes.
     *
     * @param array $package Shipping package.
     * @return float
     */
    public static function package_weight_kg( $package ) {
        $default = (float) get_option( 'wc_acs_default_weight', '0.5' );
        $total   = 0.0;

        foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
            $product = $item['data'] ?? null;
            $qty     = max( 1, (int) ( $item['quantity'] ?? 1 ) );
            $raw     = ( is_object( $product ) && method_exists( $product, 'get_weight' ) ) ? $product->get_weight() : '';
            $weight  = ( is_numeric( $raw ) && (float) $raw > 0 ) ? WC_ACS_Voucher::convert_weight_to_kg( (float) $raw ) : $default;
            $total  += $weight * $qty;
        }

        return $total;
    }
}
```

- [ ] **Step 4: Run the tests**

`--filter PointsShippingMethodTest`. Expected: 7 PASS. Full suite: `OK (98 tests, ...)`.

- [ ] **Step 5: Register the method in the plugin**

In `wc-acs-courier.php`:
- Add `'includes/class-acs-points-shipping-method.php',` after the feed line in BOTH file arrays.
- In `wc_acs_init()`, change the `woocommerce_shipping_methods` filter body to:

```php
        $methods['acs_courier'] = 'WC_ACS_Shipping_Method';
        $methods['acs_points']  = 'WC_ACS_Points_Shipping_Method';
        return $methods;
```

- [ ] **Step 6: Lint, test, commit**

Lint the new file and `wc-acs-courier.php`; full suite `OK (98 tests, ...)`.

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && git add -A && git commit -q -m "feat(points): zone-based acs_points shipping method with weight cap

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Picker core: mobile check, validation, meta writer

**Files:**
- Create: `includes/class-acs-points-picker.php` (statics only in this task; hooks in Task 7)
- Modify: `tests/bootstrap.php` (require)
- Test: `tests/Unit/PointsPickerTest.php`

**Interfaces:**
- Produces (all static on `WC_ACS_Points_Picker`):
  - `METHOD_ID = 'acs_points'`, `SESSION_KEY = 'acs_point_id'`, `NONCE_ACTION = 'wc-acs-points'`, `STORE_API_NAMESPACE = 'wc-acs-courier'`
  - `normalise_mobile( $raw ) : ?string` (ten digits starting 69, or null)
  - `methods_include_points( array $chosen ) : bool`
  - `instance_point_types( array $chosen ) : string` (`both` or `lockers`, read from `woocommerce_acs_points_<id>_settings`)
  - `order_has_points( $order ) : bool` (any shipping line with method id `acs_points`)
  - `validate( array $data, $point, WP_Error $errors ) : void` where `$data` has `shipping_method` (array of rate ids), `payment_method`, `billing_phone`; `$point` is a feed record or null
  - `format_address( array $point ) : string` (`street, zip city`)
  - `apply_point_to_order( $order, array $point ) : void` (writes the seven meta keys, does not save)
- Consumes: feed record shape from Task 3.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/PointsPickerTest.php`:

```php
<?php
namespace WC_ACS_Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

class PointsPickerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->stubGetOption( [
            'woocommerce_acs_points_3_settings' => [ 'point_types' => 'lockers' ],
            'woocommerce_acs_points_4_settings' => [ 'point_types' => 'both' ],
        ] );
    }

    private function point( array $overrides = [] ): array {
        return array_merge( [
            'id' => '4400', 'type' => 'locker', 'name' => 'ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ',
            'street' => 'Market In, Χαρ. Τρικούπη 38', 'city' => 'ΙΩΑΝΝΙΝΑ', 'zip' => '45333',
            'lat' => 39.664978, 'lon' => 20.848969, 'station' => 'ΙΒ', 'branch' => '501',
            'cod' => 1, 'h24' => 1, 'hours' => '24ΩΡΟ', 'sat' => '24ΩΡΟ',
        ], $overrides );
    }

    private function data( array $overrides = [] ): array {
        return array_merge( [
            'shipping_method' => [ 'acs_points:4' ],
            'payment_method'  => 'bacs',
            'billing_phone'   => '6912345678',
        ], $overrides );
    }

    // ── normalise_mobile() ───────────────────────────────────────

    /** @dataProvider mobiles */
    public function test_normalise_mobile( $raw, $expected ): void {
        $this->assertSame( $expected, \WC_ACS_Points_Picker::normalise_mobile( $raw ) );
    }

    public function mobiles(): array {
        return [
            'plain'          => [ '6912345678', '6912345678' ],
            'spaces'         => [ '691 234 5678', '6912345678' ],
            'plus30'         => [ '+30 691 234 5678', '6912345678' ],
            'zero-zero-30'   => [ '0030-6912345678', '6912345678' ],
            'landline'       => [ '2101234567', null ],
            'short'          => [ '691234567', null ],
            'long'           => [ '69123456789', null ],
            'empty'          => [ '', null ],
            'null'           => [ null, null ],
            'foreign mobile' => [ '+44 7700 900123', null ],
        ];
    }

    // ── chosen-method helpers ────────────────────────────────────

    public function test_methods_include_points(): void {
        $this->assertTrue( \WC_ACS_Points_Picker::methods_include_points( [ 'flat_rate:2', 'acs_points:4' ] ) );
        $this->assertFalse( \WC_ACS_Points_Picker::methods_include_points( [ 'flat_rate:2' ] ) );
        $this->assertFalse( \WC_ACS_Points_Picker::methods_include_points( [ 'acs_courier:1' ] ) );
        $this->assertFalse( \WC_ACS_Points_Picker::methods_include_points( [] ) );
    }

    public function test_instance_point_types_reads_instance_option(): void {
        $this->assertSame( 'lockers', \WC_ACS_Points_Picker::instance_point_types( [ 'acs_points:3' ] ) );
        $this->assertSame( 'both', \WC_ACS_Points_Picker::instance_point_types( [ 'acs_points:4' ] ) );
        $this->assertSame( 'both', \WC_ACS_Points_Picker::instance_point_types( [ 'acs_points:9' ] ) );
        $this->assertSame( 'both', \WC_ACS_Points_Picker::instance_point_types( [ 'flat_rate:1' ] ) );
    }

    public function test_order_has_points(): void {
        $line = Mockery::mock( 'WC_Order_Item_Shipping' );
        $line->shouldReceive( 'get_method_id' )->andReturn( 'acs_points' );
        $other = Mockery::mock( 'WC_Order_Item_Shipping' );
        $other->shouldReceive( 'get_method_id' )->andReturn( 'flat_rate' );

        $this->assertTrue( \WC_ACS_Points_Picker::order_has_points( $this->createOrderMock( [ 'shipping_methods' => [ $line ] ] ) ) );
        $this->assertFalse( \WC_ACS_Points_Picker::order_has_points( $this->createOrderMock( [ 'shipping_methods' => [ $other ] ] ) ) );
    }

    // ── validate() ───────────────────────────────────────────────

    public function test_validate_ignores_other_methods(): void {
        $errors = new \WP_Error();
        \WC_ACS_Points_Picker::validate( $this->data( [ 'shipping_method' => [ 'flat_rate:2' ], 'billing_phone' => '2101234567' ] ), null, $errors );
        $this->assertFalse( $errors->has_errors() );
    }

    public function test_validate_requires_point(): void {
        $errors = new \WP_Error();
        \WC_ACS_Points_Picker::validate( $this->data(), null, $errors );
        $this->assertSame( [ 'acs_point_required' ], $errors->get_error_codes() );
    }

    public function test_validate_rejects_store_when_lockers_only(): void {
        $errors = new \WP_Error();
        \WC_ACS_Points_Picker::validate( $this->data( [ 'shipping_method' => [ 'acs_points:3' ] ] ), $this->point( [ 'type' => 'store' ] ), $errors );
        $this->assertSame( [ 'acs_point_type' ], $errors->get_error_codes() );
    }

    public function test_validate_rejects_cod_without_terminal(): void {
        $errors = new \WP_Error();
        \WC_ACS_Points_Picker::validate( $this->data( [ 'payment_method' => 'cod' ] ), $this->point( [ 'cod' => 0 ] ), $errors );
        $this->assertSame( [ 'acs_point_cod' ], $errors->get_error_codes() );
    }

    public function test_validate_allows_cod_with_terminal(): void {
        $errors = new \WP_Error();
        \WC_ACS_Points_Picker::validate( $this->data( [ 'payment_method' => 'cod' ] ), $this->point( [ 'cod' => 1 ] ), $errors );
        $this->assertFalse( $errors->has_errors() );
    }

    public function test_validate_requires_greek_mobile(): void {
        $errors = new \WP_Error();
        \WC_ACS_Points_Picker::validate( $this->data( [ 'billing_phone' => '2101234567' ] ), $this->point(), $errors );
        $this->assertSame( [ 'acs_point_mobile' ], $errors->get_error_codes() );
    }

    public function test_validate_reports_missing_point_and_bad_mobile_together(): void {
        $errors = new \WP_Error();
        \WC_ACS_Points_Picker::validate( $this->data( [ 'billing_phone' => '210' ] ), null, $errors );
        $this->assertSame( [ 'acs_point_required', 'acs_point_mobile' ], $errors->get_error_codes() );
    }

    public function test_validate_passes_good_order(): void {
        $errors = new \WP_Error();
        \WC_ACS_Points_Picker::validate( $this->data(), $this->point(), $errors );
        $this->assertFalse( $errors->has_errors() );
    }

    // ── apply_point_to_order() ───────────────────────────────────

    public function test_apply_point_writes_seven_keys(): void {
        $order = $this->createOrderMock();

        \WC_ACS_Points_Picker::apply_point_to_order( $order, $this->point( [ 'cod' => 0 ] ) );

        $this->assertSame( [
            '_acs_point_id'      => '4400',
            '_acs_point_type'    => 'locker',
            '_acs_point_name'    => 'ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ',
            '_acs_point_address' => 'Market In, Χαρ. Τρικούπη 38, 45333 ΙΩΑΝΝΙΝΑ',
            '_acs_point_station' => 'ΙΒ',
            '_acs_point_branch'  => '501',
            '_acs_point_cod'     => '0',
        ], $order->updated_meta );
    }
}
```

- [ ] **Step 2: Add the bootstrap require and run to verify failure**

In `tests/bootstrap.php` add `require_once $plugin_dir . 'class-acs-points-picker.php';` after the shipping-method require. Run `--filter PointsPickerTest`. Expected: fatal, file missing.

- [ ] **Step 3: Create `includes/class-acs-points-picker.php` with the statics**

```php
<?php
/**
 * ACS Points picker: checkout selection, validation and persistence.
 *
 * Follows the BOX NOW locker pattern: the chosen point id lives in the
 * WooCommerce session while the customer is on the checkout, validation is a
 * static side-effect-free function shared by the classic checkout and the
 * Store API, and the server never trusts point fields from the browser (only
 * the id, resolved through WC_ACS_Points_Feed::find()).
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Points_Picker {

    const METHOD_ID           = 'acs_points';
    const SESSION_KEY         = 'acs_point_id';
    const NONCE_ACTION        = 'wc-acs-points';
    const STORE_API_NAMESPACE = 'wc-acs-courier';

    /** @var WC_ACS_Points_Picker|null */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Checkout hooks are added in Task 7.
    }

    // ─── Static helpers ────────────────────────────────────────────

    /**
     * Greek mobile in its ten-digit form, or null. ACS sends the pickup PIN
     * by SMS, so a locker shipment needs one.
     *
     * @param mixed $raw Phone as typed.
     * @return string|null
     */
    public static function normalise_mobile( $raw ) {
        $digits = preg_replace( '/\D+/', '', (string) $raw );

        if ( 0 === strpos( $digits, '0030' ) ) {
            $digits = substr( $digits, 4 );
        } elseif ( 12 === strlen( $digits ) && 0 === strpos( $digits, '30' ) ) {
            $digits = substr( $digits, 2 );
        }

        return ( 1 === preg_match( '/^69\d{8}$/', $digits ) ) ? $digits : null;
    }

    /**
     * @param array $chosen Chosen rate ids, e.g. array( 'acs_points:4' ).
     * @return bool
     */
    public static function methods_include_points( array $chosen ) {
        foreach ( $chosen as $rate_id ) {
            $rate_id = (string) $rate_id;
            if ( self::METHOD_ID === $rate_id || 0 === strpos( $rate_id, self::METHOD_ID . ':' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 'both' or 'lockers' for the chosen acs_points instance.
     *
     * @param array $chosen Chosen rate ids.
     * @return string
     */
    public static function instance_point_types( array $chosen ) {
        foreach ( $chosen as $rate_id ) {
            $parts = explode( ':', (string) $rate_id, 2 );
            if ( self::METHOD_ID !== $parts[0] ) {
                continue;
            }
            $instance_id = isset( $parts[1] ) ? (int) $parts[1] : 0;
            $settings    = get_option( 'woocommerce_' . self::METHOD_ID . '_' . $instance_id . '_settings', array() );
            $types       = is_array( $settings ) ? ( $settings['point_types'] ?? 'both' ) : 'both';
            return 'lockers' === $types ? 'lockers' : 'both';
        }
        return 'both';
    }

    /**
     * @param WC_Order $order Order.
     * @return bool
     */
    public static function order_has_points( $order ) {
        foreach ( $order->get_shipping_methods() as $item ) {
            if ( self::METHOD_ID === $item->get_method_id() ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The checkout rules. Adds to $errors, never throws.
     *
     * @param array      $data   shipping_method (array), payment_method, billing_phone.
     * @param array|null $point  Resolved feed record or null.
     * @param WP_Error   $errors Collector.
     */
    public static function validate( array $data, $point, $errors ) {
        $chosen = (array) ( $data['shipping_method'] ?? array() );

        if ( ! self::methods_include_points( $chosen ) ) {
            return;
        }

        if ( ! is_array( $point ) ) {
            $errors->add( 'acs_point_required', __( 'Please choose an ACS Point before placing your order.', 'wc-acs-courier' ) );
        } else {
            if ( 'lockers' === self::instance_point_types( $chosen ) && 'store' === ( $point['type'] ?? '' ) ) {
                $errors->add( 'acs_point_type', __( 'Please choose an ACS locker.', 'wc-acs-courier' ) );
            }
            if ( 'cod' === ( $data['payment_method'] ?? '' ) && empty( $point['cod'] ) ) {
                $errors->add( 'acs_point_cod', __( 'Cash on delivery is not available at this ACS Point. Choose another point or pay by card.', 'wc-acs-courier' ) );
            }
        }

        if ( null === self::normalise_mobile( $data['billing_phone'] ?? '' ) ) {
            $errors->add( 'acs_point_mobile', __( 'ACS sends the pickup PIN by SMS, so a Greek mobile number (69xxxxxxxx) is required.', 'wc-acs-courier' ) );
        }
    }

    /**
     * @param array $point Feed record.
     * @return string "street, zip city"
     */
    public static function format_address( array $point ) {
        $line = trim( (string) ( $point['zip'] ?? '' ) . ' ' . (string) ( $point['city'] ?? '' ) );
        return trim( (string) ( $point['street'] ?? '' ) . ', ' . $line, ', ' );
    }

    /**
     * Write the point onto an order. The caller saves.
     *
     * @param WC_Order $order Order.
     * @param array    $point Feed record.
     */
    public static function apply_point_to_order( $order, array $point ) {
        $order->update_meta_data( '_acs_point_id', (string) $point['id'] );
        $order->update_meta_data( '_acs_point_type', (string) $point['type'] );
        $order->update_meta_data( '_acs_point_name', (string) $point['name'] );
        $order->update_meta_data( '_acs_point_address', self::format_address( $point ) );
        $order->update_meta_data( '_acs_point_station', (string) $point['station'] );
        $order->update_meta_data( '_acs_point_branch', (string) $point['branch'] );
        $order->update_meta_data( '_acs_point_cod', empty( $point['cod'] ) ? '0' : '1' );
    }
}
```

- [ ] **Step 4: Run the tests**

`--filter PointsPickerTest`. Expected: 21 PASS (10 data-provider rows plus 11 methods). Full suite `OK (119 tests, ...)`.

- [ ] **Step 5: Lint and commit**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && export MSYS_NO_PATHCONV=1 && docker run --rm -v "D:/Documents/Projects/POOQ/wc-acs-courier:/app" php:8.3-cli php -l /app/includes/class-acs-points-picker.php && git add -A && git commit -q -m "feat(points): picker rules (mobile check, validation, order meta writer)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Picker checkout wiring: session, render, save, gateways, Store API

**Files:**
- Modify: `includes/class-acs-points-picker.php` (constructor hooks + instance methods)
- Modify: `wc-acs-courier.php` (file lists, init)
- Test: `tests/Unit/PointsPickerTest.php` (append)

**Interfaces:**
- Produces on `WC_ACS_Points_Picker` (instance): `get_selected_point_id() : string`, `get_selected_point() : ?array`, `ajax_set_point()` (action `wc_acs_set_point`, nonce `wc-acs-points`, POST `point_id`), `render_picker( $rate, $index )`, `enqueue_assets()`, `validate_classic_checkout( $data, $errors )`, `save_classic_checkout( $order, $data )`, `static filter_payment_gateways( $gateways )`, `register_store_api()`, `save_from_store_api( $order, $request )`, `script_settings() : array`.
- Localized JS object `wcAcsPoints` (consumed by Task 9):

```
{ restUrl, ajaxUrl, nonce, pointTypes: 'both'|'lockers', postcodeCentre: [lat, lon, zoom]|null,
  assets: { leafletCss, leafletJs, clusterCss, clusterDefaultCss, clusterJs },
  icons: { locker, lockerCod, store, marker, markerShadow },
  i18n: { title, search, lockers, stores, all, myLocation, select, change, close, loading,
          loadError, moreHint, open24, cod, noCod, weekdays, saturday, locateError } }
```

- Markup produced by `render_picker()` (consumed by Task 9 and the site CSS in Task 13): `div.wc-acs-points-picker[data-package]` containing `button.wc-acs-points-open`, `div.wc-acs-points-selected[hidden?]` with `.wc-acs-points-selected-name`, `.wc-acs-points-selected-address`, `.wc-acs-points-badge`, `button.wc-acs-points-change`, and `input[type=hidden]#acs_point_id[name=acs_point_id]`.
- Consumes: `WC_ACS_Points_Feed::instance()->find()`, `->get_points()`, `WC_ACS_Points_Feed::centre_for_postcode()`.

- [ ] **Step 1: Append failing tests to `tests/Unit/PointsPickerTest.php`**

Add these helpers and tests inside the class:

```php
    // ── instance wiring ──────────────────────────────────────────

    private function picker(): \WC_ACS_Points_Picker {
        $this->resetStaticProperty( \WC_ACS_Points_Picker::class, 'instance' );
        return \WC_ACS_Points_Picker::instance();
    }

    private function seedFeed( array $points ): void {
        $this->resetStaticProperty( \WC_ACS_Points_Feed::class, 'instance' );
        $feed = [ 'fetched_at' => 1, 'country' => 'GR', 'points' => $points ];
        $this->stubGetOption( [
            'wc_acs_points_feed'                => $feed,
            'woocommerce_acs_points_3_settings' => [ 'point_types' => 'lockers' ],
            'woocommerce_acs_points_4_settings' => [ 'point_types' => 'both' ],
        ] );
    }

    private function mockSession( array $values ): void {
        $session = Mockery::mock();
        $session->stored = $values;
        $session->shouldReceive( 'get' )->andReturnUsing( function ( $key, $default = null ) use ( $session ) {
            return $session->stored[ $key ] ?? $default;
        } );
        $session->shouldReceive( 'set' )->andReturnUsing( function ( $key, $value ) use ( $session ) {
            $session->stored[ $key ] = $value;
        } );
        Functions\when( 'WC' )->justReturn( (object) [ 'session' => $session, 'customer' => null ] );
    }

    private function pointsOrder( array $overrides = [] ): \Mockery\MockInterface {
        $line = Mockery::mock( 'WC_Order_Item_Shipping' );
        $line->shouldReceive( 'get_method_id' )->andReturn( 'acs_points' );
        $line->shouldReceive( 'get_instance_id' )->andReturn( 4 );
        return $this->createOrderMock( array_merge( [ 'shipping_methods' => [ $line ], 'billing_phone' => '6912345678' ], $overrides ) );
    }

    public function test_selected_point_id_prefers_post_then_session(): void {
        $this->seedFeed( [] );
        $this->mockSession( [ 'acs_point_id' => '9' ] );
        $picker = $this->picker();

        unset( $_POST['acs_point_id'] );
        $this->assertSame( '9', $picker->get_selected_point_id() );

        $_POST['acs_point_id'] = '4400';
        $this->assertSame( '4400', $picker->get_selected_point_id() );
        unset( $_POST['acs_point_id'] );
    }

    public function test_save_classic_checkout_throws_without_point(): void {
        $this->seedFeed( [] );
        $this->mockSession( [] );
        unset( $_POST['acs_point_id'] );

        $this->expectException( \Exception::class );
        $this->picker()->save_classic_checkout( $this->pointsOrder(), [] );
    }

    public function test_save_classic_checkout_writes_meta_for_session_point(): void {
        $this->seedFeed( [ $this->point() ] );
        $this->mockSession( [ 'acs_point_id' => '4400' ] );
        unset( $_POST['acs_point_id'] );
        $order = $this->pointsOrder();

        $this->picker()->save_classic_checkout( $order, [] );

        $this->assertSame( '4400', $order->updated_meta['_acs_point_id'] );
        $this->assertSame( 'ΙΒ', $order->updated_meta['_acs_point_station'] );
    }

    public function test_save_classic_checkout_ignores_other_methods(): void {
        $this->seedFeed( [] );
        $this->mockSession( [] );
        $order = $this->createOrderMock();

        $this->picker()->save_classic_checkout( $order, [] );

        $this->assertSame( [], $order->updated_meta );
    }

    public function test_validate_classic_checkout_falls_back_to_session_method(): void {
        $this->seedFeed( [] );
        $this->mockSession( [ 'chosen_shipping_methods' => [ 'acs_points:4' ] ] );
        unset( $_POST['acs_point_id'] );
        $errors = new \WP_Error();

        $this->picker()->validate_classic_checkout( [ 'shipping_method' => '', 'billing_phone' => '6912345678' ], $errors );

        $this->assertSame( [ 'acs_point_required' ], $errors->get_error_codes() );
    }

    public function test_gateway_filter_hides_cod_only_for_point_without_terminal(): void {
        $this->seedFeed( [ $this->point( [ 'id' => '1', 'cod' => 0 ] ), $this->point( [ 'id' => '2', 'cod' => 1 ] ) ] );
        $gateways = [ 'cod' => 'COD', 'bacs' => 'Bank' ];

        $this->mockSession( [ 'chosen_shipping_methods' => [ 'acs_points:4' ], 'acs_point_id' => '1' ] );
        $this->assertSame( [ 'bacs' ], array_keys( \WC_ACS_Points_Picker::filter_payment_gateways( $gateways ) ) );

        $this->mockSession( [ 'chosen_shipping_methods' => [ 'acs_points:4' ], 'acs_point_id' => '2' ] );
        $this->assertSame( [ 'cod', 'bacs' ], array_keys( \WC_ACS_Points_Picker::filter_payment_gateways( $gateways ) ) );

        $this->mockSession( [ 'chosen_shipping_methods' => [ 'acs_points:4' ] ] );
        $this->assertSame( [ 'cod', 'bacs' ], array_keys( \WC_ACS_Points_Picker::filter_payment_gateways( $gateways ) ) );

        $this->mockSession( [ 'chosen_shipping_methods' => [ 'flat_rate:2' ], 'acs_point_id' => '1' ] );
        $this->assertSame( [ 'cod', 'bacs' ], array_keys( \WC_ACS_Points_Picker::filter_payment_gateways( $gateways ) ) );
    }

    public function test_ajax_set_point_rejects_unknown_id(): void {
        $this->seedFeed( [ $this->point() ] );
        $this->mockSession( [] );
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        $captured = null;
        Functions\when( 'wp_send_json_error' )->alias( function ( $data, $status = null ) use ( &$captured ) {
            $captured = $status;
            throw new \RuntimeException( 'exit' );
        } );
        $_POST['point_id'] = '999';

        try {
            $this->picker()->ajax_set_point();
        } catch ( \RuntimeException $e ) {
        }

        $this->assertSame( 404, $captured );
        unset( $_POST['point_id'] );
    }

    public function test_ajax_set_point_stores_id_in_session(): void {
        $this->seedFeed( [ $this->point() ] );
        $this->mockSession( [] );
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$captured ) {
            $captured = $data;
            throw new \RuntimeException( 'exit' );
        } );
        $_POST['point_id'] = '4400';

        try {
            $this->picker()->ajax_set_point();
        } catch ( \RuntimeException $e ) {
        }

        $this->assertSame( '4400', $captured['point']['id'] );
        $this->assertSame( '4400', \WC()->session->stored['acs_point_id'] );
        unset( $_POST['point_id'] );
    }

    public function test_save_from_store_api_reads_extension_data(): void {
        $this->seedFeed( [ $this->point() ] );
        $this->mockSession( [] );
        $order = $this->pointsOrder();
        $request = new \WP_REST_Request();
        $request->set_params( [ 'extensions' => [ 'wc-acs-courier' => [ 'point_id' => '4400' ] ] ] );

        $this->picker()->save_from_store_api( $order, $request );

        $this->assertSame( '501', $order->updated_meta['_acs_point_branch'] );
    }

    public function test_save_from_store_api_throws_on_missing_point(): void {
        $this->seedFeed( [] );
        $this->mockSession( [] );
        $request = new \WP_REST_Request();
        $request->set_params( [] );

        $this->expectException( \Exception::class );
        $this->picker()->save_from_store_api( $this->pointsOrder(), $request );
    }
```

- [ ] **Step 2: Run to verify failure**

`--filter PointsPickerTest`. Expected: 10 new failures, undefined methods.

- [ ] **Step 3: Add hooks and instance methods to `includes/class-acs-points-picker.php`**

Replace the constructor with:

```php
    private function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'woocommerce_after_shipping_rate', array( $this, 'render_picker' ), 10, 2 );
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic_checkout' ), 10, 2 );
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_payment_gateways' ) );

        add_action( 'wp_ajax_wc_acs_set_point', array( $this, 'ajax_set_point' ) );
        add_action( 'wp_ajax_nopriv_wc_acs_set_point', array( $this, 'ajax_set_point' ) );

        // woocommerce_blocks_loaded is deprecated since WC 8.4; woocommerce_init at 20 runs after WC is up.
        add_action( 'woocommerce_init', array( $this, 'register_store_api' ), 20 );
    }
```

Add these methods after `apply_point_to_order()`:

```php
    // ─── Session ───────────────────────────────────────────────────

    /**
     * Point id from the request, falling back to the session.
     *
     * @return string
     */
    public function get_selected_point_id() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the checkout flow verifies its own nonce.
        if ( isset( $_POST['acs_point_id'] ) ) {
            return sanitize_text_field( wp_unslash( $_POST['acs_point_id'] ) );
        }
        if ( function_exists( 'WC' ) && WC()->session ) {
            return (string) WC()->session->get( self::SESSION_KEY, '' );
        }
        return '';
    }

    /**
     * @return array|null Resolved feed record.
     */
    public function get_selected_point() {
        return WC_ACS_Points_Feed::instance()->find( $this->get_selected_point_id() );
    }

    /**
     * AJAX: remember the chosen point for this session and echo it back.
     */
    public function ajax_set_point() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $id    = isset( $_POST['point_id'] ) ? sanitize_text_field( wp_unslash( $_POST['point_id'] ) ) : '';
        $point = WC_ACS_Points_Feed::instance()->find( $id );

        if ( null === $point ) {
            wp_send_json_error( array( 'message' => __( 'Unknown ACS Point.', 'wc-acs-courier' ) ), 404 );
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( self::SESSION_KEY, $point['id'] );
        }

        wp_send_json_success( array( 'point' => $point ) );
    }

    // ─── Classic checkout ──────────────────────────────────────────

    /**
     * @param array    $data   Posted data.
     * @param WP_Error $errors Errors.
     */
    public function validate_classic_checkout( $data, $errors ) {
        $data = (array) $data;

        // get_posted_data() yields '' when the field is absent; the session
        // holds what create_order_shipping_lines() will use.
        if ( empty( $data['shipping_method'] ) && function_exists( 'WC' ) && WC()->session ) {
            $data['shipping_method'] = (array) WC()->session->get( 'chosen_shipping_methods', array() );
        }

        self::validate( $data, $this->get_selected_point(), $errors );
    }

    /**
     * @param WC_Order $order Order.
     * @param array    $data  Posted data (unused; the order's shipping line is the truth).
     * @throws Exception When ACS Points is on the order but no valid point was chosen.
     */
    public function save_classic_checkout( $order, $data = array() ) {
        if ( ! self::order_has_points( $order ) ) {
            return;
        }

        $point = $this->get_selected_point();

        if ( null === $point ) {
            throw new Exception( __( 'Please choose an ACS Point before placing your order.', 'wc-acs-courier' ) );
        }

        self::apply_point_to_order( $order, $point );
    }

    /**
     * Hide cash on delivery once a point without a terminal is chosen.
     *
     * @param array $gateways Gateway id => WC_Payment_Gateway.
     * @return array
     */
    public static function filter_payment_gateways( $gateways ) {
        if ( ! is_array( $gateways ) || ! isset( $gateways['cod'] ) ) {
            return $gateways;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return $gateways;
        }

        $chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
        if ( ! self::methods_include_points( $chosen ) ) {
            return $gateways;
        }

        $point = WC_ACS_Points_Feed::instance()->find( (string) WC()->session->get( self::SESSION_KEY, '' ) );
        if ( is_array( $point ) && empty( $point['cod'] ) ) {
            unset( $gateways['cod'] );
        }

        return $gateways;
    }

    // ─── Rendering ─────────────────────────────────────────────────

    /**
     * Picker beneath the acs_points rate.
     *
     * @param WC_Shipping_Rate $rate  Rate.
     * @param int              $index Package index.
     */
    public function render_picker( $rate, $index ) {
        if ( self::METHOD_ID !== $rate->get_method_id() ) {
            return;
        }

        $point = $this->get_selected_point();
        ?>
        <div class="wc-acs-points-picker" data-package="<?php echo esc_attr( $index ); ?>">
            <button type="button" class="wc-acs-points-open"<?php echo $point ? ' hidden' : ''; ?>>
                <?php esc_html_e( 'Choose ACS Point', 'wc-acs-courier' ); ?>
            </button>
            <div class="wc-acs-points-selected"<?php echo $point ? '' : ' hidden'; ?>>
                <?php if ( $point ) : ?>
                    <span class="wc-acs-points-selected-name"><?php echo esc_html( $point['name'] ); ?></span>
                    <span class="wc-acs-points-selected-address"><?php echo esc_html( self::format_address( $point ) ); ?></span>
                    <span class="wc-acs-points-badges">
                        <?php if ( ! empty( $point['h24'] ) ) : ?>
                            <span class="wc-acs-points-badge wc-acs-points-badge--24"><?php esc_html_e( '24/7', 'wc-acs-courier' ); ?></span>
                        <?php endif; ?>
                        <?php if ( ! empty( $point['cod'] ) ) : ?>
                            <span class="wc-acs-points-badge wc-acs-points-badge--cod"><?php esc_html_e( 'Cash on delivery available', 'wc-acs-courier' ); ?></span>
                        <?php else : ?>
                            <span class="wc-acs-points-badge wc-acs-points-badge--nocod"><?php esc_html_e( 'No cash on delivery', 'wc-acs-courier' ); ?></span>
                        <?php endif; ?>
                    </span>
                    <button type="button" class="wc-acs-points-change"><?php esc_html_e( 'Change', 'wc-acs-courier' ); ?></button>
                <?php endif; ?>
            </div>
            <input type="hidden" name="acs_point_id" id="acs_point_id" value="<?php echo esc_attr( $point ? $point['id'] : '' ); ?>" />
        </div>
        <?php
    }

    /**
     * Customer postcode from the session customer, shipping first.
     *
     * @return string
     */
    private function customer_postcode() {
        if ( ! function_exists( 'WC' ) || empty( WC()->customer ) ) {
            return '';
        }
        $customer = WC()->customer;
        $zip      = method_exists( $customer, 'get_shipping_postcode' ) ? (string) $customer->get_shipping_postcode() : '';
        if ( '' === $zip && method_exists( $customer, 'get_billing_postcode' ) ) {
            $zip = (string) $customer->get_billing_postcode();
        }
        return $zip;
    }

    /**
     * @return array Settings for acs-points.js.
     */
    public function script_settings() {
        $feed   = WC_ACS_Points_Feed::instance();
        $chosen = ( function_exists( 'WC' ) && WC()->session ) ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        $vendor = WC_ACS_PLUGIN_URL . 'assets/vendor/';
        $img    = WC_ACS_PLUGIN_URL . 'assets/img/';

        return array(
            'restUrl'        => rest_url( WC_ACS_Points_Feed::REST_NAMESPACE . '/points' ),
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
            'pointTypes'     => self::instance_point_types( $chosen ),
            'postcodeCentre' => WC_ACS_Points_Feed::centre_for_postcode( $this->customer_postcode(), $feed->get_points() ),
            'assets'         => array(
                'leafletCss'        => $vendor . 'leaflet/leaflet.css',
                'leafletJs'         => $vendor . 'leaflet/leaflet.js',
                'clusterCss'        => $vendor . 'leaflet.markercluster/MarkerCluster.css',
                'clusterDefaultCss' => $vendor . 'leaflet.markercluster/MarkerCluster.Default.css',
                'clusterJs'         => $vendor . 'leaflet.markercluster/leaflet.markercluster.js',
            ),
            'icons'          => array(
                'locker'       => $img . 'point-locker.svg',
                'lockerCod'    => $img . 'point-locker-cod.svg',
                'store'        => $img . 'point-store.svg',
                'marker'       => $vendor . 'leaflet/images/marker-icon.png',
                'markerShadow' => $vendor . 'leaflet/images/marker-shadow.png',
            ),
            'i18n'           => array(
                'title'       => __( 'Choose the ACS Point that suits you', 'wc-acs-courier' ),
                'search'      => __( 'Search by area, street or postcode', 'wc-acs-courier' ),
                'all'         => __( 'All', 'wc-acs-courier' ),
                'lockers'     => __( 'Lockers', 'wc-acs-courier' ),
                'stores'      => __( 'Stores', 'wc-acs-courier' ),
                'myLocation'  => __( 'My location', 'wc-acs-courier' ),
                'select'      => __( 'Select', 'wc-acs-courier' ),
                'change'      => __( 'Change', 'wc-acs-courier' ),
                'close'       => __( 'Close', 'wc-acs-courier' ),
                'loading'     => __( 'Loading points...', 'wc-acs-courier' ),
                'loadError'   => __( 'Could not load the ACS points. Please try again.', 'wc-acs-courier' ),
                'moreHint'    => __( 'Move the map to see more points', 'wc-acs-courier' ),
                'open24'      => __( '24/7', 'wc-acs-courier' ),
                'cod'         => __( 'Cash on delivery available', 'wc-acs-courier' ),
                'noCod'       => __( 'No cash on delivery', 'wc-acs-courier' ),
                'weekdays'    => __( 'Weekdays', 'wc-acs-courier' ),
                'saturday'    => __( 'Saturday', 'wc-acs-courier' ),
                'locateError' => __( 'Your location is not available.', 'wc-acs-courier' ),
                'locker'      => __( 'Locker', 'wc-acs-courier' ),
                'store'       => __( 'Store', 'wc-acs-courier' ),
            ),
        );
    }

    /**
     * Checkout assets only. Leaflet itself is loaded by acs-points.js on the
     * first click, so the checkout page weight does not change.
     */
    public function enqueue_assets() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
            return;
        }

        wp_enqueue_style( 'wc-acs-points', WC_ACS_PLUGIN_URL . 'assets/css/acs-points.css', array(), WC_ACS_VERSION );
        wp_enqueue_script( 'wc-acs-points', WC_ACS_PLUGIN_URL . 'assets/js/acs-points.js', array( 'jquery' ), WC_ACS_VERSION, true );
        wp_localize_script( 'wc-acs-points', 'wcAcsPoints', $this->script_settings() );
    }

    // ─── Store API (Blocks and express checkouts) ──────────────────

    public static function store_api_schema() {
        return array(
            'point_id' => array(
                'description' => __( 'Selected ACS Point id.', 'wc-acs-courier' ),
                'type'        => 'string',
                'readonly'    => true,
            ),
        );
    }

    public function register_store_api() {
        if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\StoreApi' ) || ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            return;
        }

        woocommerce_store_api_register_endpoint_data( array(
            'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
            'namespace'       => self::STORE_API_NAMESPACE,
            'schema_callback' => array( __CLASS__, 'store_api_schema' ),
            'schema_type'     => ARRAY_A,
        ) );

        add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'save_from_store_api' ), 10, 2 );
    }

    /**
     * Validate and persist for a Store API order. Runs for express flows
     * that never execute our JS, which is why the rules live here too.
     *
     * @param WC_Order              $order   Order.
     * @param WP_REST_Request|array $request Request.
     * @throws Exception On a failed rule.
     */
    public function save_from_store_api( $order, $request ) {
        if ( ! self::order_has_points( $order ) ) {
            return;
        }

        $params = is_array( $request ) ? $request : $request->get_params();
        $id     = (string) ( $params['extensions'][ self::STORE_API_NAMESPACE ]['point_id'] ?? '' );

        if ( '' === $id && function_exists( 'WC' ) && WC()->session ) {
            $id = (string) WC()->session->get( self::SESSION_KEY, '' );
        }

        $point  = WC_ACS_Points_Feed::instance()->find( $id );
        $chosen = array();
        foreach ( $order->get_shipping_methods() as $item ) {
            $chosen[] = $item->get_method_id() . ':' . (int) $item->get_instance_id();
        }

        $errors = new WP_Error();
        self::validate( array(
            'shipping_method' => $chosen,
            'payment_method'  => $order->get_payment_method(),
            'billing_phone'   => $order->get_billing_phone(),
        ), $point, $errors );

        if ( $errors->has_errors() ) {
            $message = $errors->get_error_message();
            if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'wc_acs_point_invalid', $message, 400 );
            }
            throw new Exception( $message );
        }

        self::apply_point_to_order( $order, $point );
    }
```

- [ ] **Step 4: Run the tests**

`--filter PointsPickerTest`. Expected: 31 PASS. Full suite `OK (129 tests, ...)`.

- [ ] **Step 5: Wire the picker into the plugin**

In `wc-acs-courier.php`: add `'includes/class-acs-points-picker.php',` after the shipping-method line in BOTH file arrays, and `WC_ACS_Points_Picker::instance();` after `WC_ACS_Points_Feed::instance();` in `wc_acs_init()`.

- [ ] **Step 6: Lint, test, commit**

Lint `includes/class-acs-points-picker.php` and `wc-acs-courier.php`. Full suite `OK (129 tests, ...)`.

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && git add -A && git commit -q -m "feat(points): checkout picker wiring, session, COD gateway filter, Store API guard

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: Vendor Leaflet and draw the marker icons

**Files:**
- Create: `assets/vendor/leaflet/{leaflet.js,leaflet.css,LICENSE,images/marker-icon.png,images/marker-icon-2x.png,images/marker-shadow.png,images/layers.png,images/layers-2x.png}`
- Create: `assets/vendor/leaflet.markercluster/{leaflet.markercluster.js,MarkerCluster.css,MarkerCluster.Default.css,MIT-LICENCE.txt}`
- Create: `assets/img/point-locker.svg`, `assets/img/point-locker-cod.svg`, `assets/img/point-store.svg`
- Modify: `build.sh` (nothing to change: it copies `assets/` recursively; verify in Step 5)

**Interfaces:**
- Produces the URLs Task 7's `script_settings()` already points at. Leaflet defines `window.L`; the cluster plugin adds `L.markerClusterGroup`.

- [ ] **Step 1: Fetch the two packages with npm and copy the dist files**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && TMP=$(mktemp -d) && mkdir -p "$TMP/a" "$TMP/b" assets/vendor/leaflet/images assets/vendor/leaflet.markercluster assets/img \
&& ( cd "$TMP" && npm pack leaflet@1.9.4 leaflet.markercluster@1.5.3 --silent >/dev/null && tar -xzf leaflet-1.9.4.tgz -C a && tar -xzf leaflet.markercluster-1.5.3.tgz -C b ) \
&& cp "$TMP/a/package/dist/leaflet.js" "$TMP/a/package/dist/leaflet.css" assets/vendor/leaflet/ \
&& cp "$TMP/a/package/LICENSE" assets/vendor/leaflet/LICENSE \
&& cp "$TMP/a/package/dist/images/"*.png assets/vendor/leaflet/images/ \
&& cp "$TMP/b/package/dist/leaflet.markercluster.js" "$TMP/b/package/dist/MarkerCluster.css" "$TMP/b/package/dist/MarkerCluster.Default.css" assets/vendor/leaflet.markercluster/ \
&& cp "$TMP/b/package/MIT-LICENCE.txt" assets/vendor/leaflet.markercluster/ \
&& rm -rf "$TMP" && ls -la assets/vendor/leaflet assets/vendor/leaflet/images assets/vendor/leaflet.markercluster
```

Expected: `leaflet.js` about 148 KB, `leaflet.markercluster.js` about 34 KB, five PNGs, two licence files.

- [ ] **Step 2: Verify versions**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && head -c 200 assets/vendor/leaflet/leaflet.js | grep -o "Leaflet 1.9.4" && grep -o "Leaflet.markercluster 1.5.3" assets/vendor/leaflet.markercluster/leaflet.markercluster.js | head -1
```

Expected: both version strings printed.

- [ ] **Step 3: Create the three marker icons**

`assets/img/point-locker.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="32" height="40" viewBox="0 0 32 40"><path d="M16 0C7.2 0 0 7.1 0 15.8 0 27 16 40 16 40s16-13 16-24.2C32 7.1 24.8 0 16 0z" fill="#4b5563"/><rect x="9" y="8" width="14" height="16" rx="2" fill="#fff"/><rect x="11" y="10" width="4" height="5" fill="#4b5563"/><rect x="17" y="10" width="4" height="5" fill="#4b5563"/><rect x="11" y="17" width="4" height="5" fill="#4b5563"/><rect x="17" y="17" width="4" height="5" fill="#4b5563"/></svg>
```

`assets/img/point-locker-cod.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="32" height="40" viewBox="0 0 32 40"><path d="M16 0C7.2 0 0 7.1 0 15.8 0 27 16 40 16 40s16-13 16-24.2C32 7.1 24.8 0 16 0z" fill="#4b5563"/><rect x="9" y="8" width="14" height="16" rx="2" fill="#fff"/><rect x="11" y="10" width="4" height="5" fill="#4b5563"/><rect x="17" y="10" width="4" height="5" fill="#4b5563"/><rect x="11" y="17" width="4" height="5" fill="#4b5563"/><rect x="17" y="17" width="4" height="5" fill="#4b5563"/><circle cx="25" cy="8" r="7" fill="#15803d"/><rect x="21" y="6" width="8" height="5" rx="1" fill="#fff"/><rect x="21" y="7.5" width="8" height="1.2" fill="#15803d"/></svg>
```

`assets/img/point-store.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="32" height="40" viewBox="0 0 32 40"><path d="M16 0C7.2 0 0 7.1 0 15.8 0 27 16 40 16 40s16-13 16-24.2C32 7.1 24.8 0 16 0z" fill="#e30613"/><path d="M8 12l2-4h12l2 4v2H8z" fill="#fff"/><rect x="9" y="14" width="14" height="10" fill="#fff"/><rect x="14" y="17" width="4" height="7" fill="#e30613"/></svg>
```

- [ ] **Step 4: Check the images render**

Open each SVG in the Browser pane (`file:///D:/Documents/Projects/POOQ/wc-acs-courier/assets/img/point-locker-cod.svg` and the other two) and confirm a pin shape with a locker grid, a green card badge on the COD variant, and a red store shape.

- [ ] **Step 5: Confirm the release zip includes the vendor tree**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && bash build.sh >/dev/null && unzip -l wc-acs-courier.zip | grep -c "assets/vendor/" && rm -f wc-acs-courier.zip
```

Expected: a count of at least 10.

- [ ] **Step 6: Commit**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && git add assets/vendor assets/img && git commit -q -m "chore(points): vendor Leaflet 1.9.4 and markercluster 1.5.3, marker icons

Both libraries ship under their own licences (BSD-2, MIT) next to the
files. Loaded lazily by acs-points.js, never from a CDN.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: Frontend: map picker script and styles

**Files:**
- Create: `assets/js/acs-points.js`, `assets/css/acs-points.css`

**Interfaces:**
- Consumes: `wcAcsPoints` (Task 7), the picker markup (Task 7), the REST payload column order (Task 4), AJAX `wc_acs_set_point` (Task 7), vendored assets (Task 8).
- Produces: after a selection, sets `#acs_point_id`, POSTs to `wc_acs_set_point`, then triggers `update_checkout` so PHP re-renders the summary.

- [ ] **Step 1: Create `assets/js/acs-points.js`**

```js
/**
 * ACS Points picker, classic checkout.
 *
 * Lazy-loads the vendored Leaflet on the first click, fetches the point list
 * from the store's REST route (cached a day by the browser), and hands the
 * chosen id to the server. The server re-renders the summary on
 * update_checkout, so this file never writes point details into the page.
 */
/* global wcAcsPoints, jQuery, L */
( function ( $ ) {
    'use strict';

    var cfg = window.wcAcsPoints || {};
    if ( ! cfg.restUrl ) {
        return;
    }

    var GREECE = { center: [ 38.5, 23.8 ], zoom: 7 };
    var LIST_CAP = 60;

    var state = {
        assets: null,
        points: null,
        overlay: null,
        map: null,
        cluster: null,
        markers: {},
        icons: null,
        filter: 'all',
        search: '',
        userMarker: null
    };

    // ─── Asset loading ────────────────────────────────────────────

    function loadStyle( href ) {
        return new Promise( function ( resolve ) {
            if ( document.querySelector( 'link[href="' + href + '"]' ) ) {
                resolve();
                return;
            }
            var link = document.createElement( 'link' );
            link.rel = 'stylesheet';
            link.href = href;
            link.onload = resolve;
            link.onerror = resolve;
            document.head.appendChild( link );
        } );
    }

    function loadScript( src ) {
        return new Promise( function ( resolve, reject ) {
            if ( document.querySelector( 'script[src="' + src + '"]' ) ) {
                resolve();
                return;
            }
            var script = document.createElement( 'script' );
            script.src = src;
            script.async = true;
            script.onload = resolve;
            script.onerror = function () {
                reject( new Error( 'Failed to load ' + src ) );
            };
            document.head.appendChild( script );
        } );
    }

    function loadAssets() {
        if ( ! state.assets ) {
            state.assets = Promise.all( [
                loadStyle( cfg.assets.leafletCss ),
                loadStyle( cfg.assets.clusterCss ),
                loadStyle( cfg.assets.clusterDefaultCss )
            ] )
                .then( function () { return loadScript( cfg.assets.leafletJs ); } )
                .then( function () { return loadScript( cfg.assets.clusterJs ); } );
        }
        return state.assets;
    }

    function loadPoints() {
        if ( state.points ) {
            return Promise.resolve( state.points );
        }
        return fetch( cfg.restUrl, { credentials: 'omit' } )
            .then( function ( response ) {
                if ( ! response.ok ) {
                    throw new Error( 'HTTP ' + response.status );
                }
                return response.json();
            } )
            .then( function ( json ) {
                // Column order matches WC_ACS_Points_Feed::payload().
                state.points = ( json.points || [] ).map( function ( row ) {
                    return {
                        id: String( row[ 0 ] ),
                        type: row[ 1 ],
                        name: row[ 2 ] || '',
                        street: row[ 3 ] || '',
                        city: row[ 4 ] || '',
                        zip: String( row[ 5 ] || '' ),
                        lat: parseFloat( row[ 6 ] ),
                        lon: parseFloat( row[ 7 ] ),
                        station: row[ 8 ],
                        branch: row[ 9 ],
                        cod: !! row[ 10 ],
                        h24: !! row[ 11 ],
                        hours: row[ 12 ] || '',
                        sat: row[ 13 ] || ''
                    };
                } ).filter( function ( p ) {
                    return ! isNaN( p.lat ) && ! isNaN( p.lon ) && ( cfg.pointTypes !== 'lockers' || p.type === 'locker' );
                } );
                return state.points;
            } );
    }

    // ─── Helpers ──────────────────────────────────────────────────

    function escapeHtml( text ) {
        var div = document.createElement( 'div' );
        div.appendChild( document.createTextNode( text == null ? '' : String( text ) ) );
        return div.innerHTML;
    }

    function fold( text ) {
        return String( text || '' ).normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ).toLowerCase();
    }

    function distanceKm( lat1, lon1, lat2, lon2 ) {
        var toRad = Math.PI / 180;
        var dLat = ( lat2 - lat1 ) * toRad;
        var dLon = ( lon2 - lon1 ) * toRad;
        var a = Math.sin( dLat / 2 ) * Math.sin( dLat / 2 ) +
            Math.cos( lat1 * toRad ) * Math.cos( lat2 * toRad ) * Math.sin( dLon / 2 ) * Math.sin( dLon / 2 );
        return 6371 * 2 * Math.atan2( Math.sqrt( a ), Math.sqrt( 1 - a ) );
    }

    function median( values ) {
        var sorted = values.slice().sort( function ( a, b ) { return a - b; } );
        var mid = Math.floor( sorted.length / 2 );
        return sorted.length % 2 === 0 ? ( sorted[ mid - 1 ] + sorted[ mid ] ) / 2 : sorted[ mid ];
    }

    function checkoutPostcode() {
        var field = $( '#ship-to-different-address-checkbox' ).is( ':checked' ) ? $( '#shipping_postcode' ) : $( '#billing_postcode' );
        return $.trim( String( field.val() || '' ) );
    }

    /** Same rule as WC_ACS_Points_Feed::centre_for_postcode(). */
    function centreForPostcode( zip, points ) {
        var digits = String( zip || '' ).replace( /\D+/g, '' );
        var rules = [ [ 3, 13 ], [ 2, 10 ] ];
        for ( var r = 0; r < rules.length; r++ ) {
            var len = rules[ r ][ 0 ];
            if ( digits.length < len ) {
                continue;
            }
            var prefix = digits.slice( 0, len );
            var lats = [], lons = [];
            points.forEach( function ( p ) {
                if ( p.zip.indexOf( prefix ) === 0 ) {
                    lats.push( p.lat );
                    lons.push( p.lon );
                }
            } );
            if ( lats.length ) {
                return [ median( lats ), median( lons ), rules[ r ][ 1 ] ];
            }
        }
        return null;
    }

    function hoursHtml( p ) {
        if ( p.h24 ) {
            return '<span class="wc-acs-points-badge wc-acs-points-badge--24">' + escapeHtml( cfg.i18n.open24 ) + '</span>';
        }
        return '<span class="wc-acs-points-hours">' + escapeHtml( cfg.i18n.weekdays ) + ': ' + escapeHtml( p.hours ) +
            ( p.sat ? ' · ' + escapeHtml( cfg.i18n.saturday ) + ': ' + escapeHtml( p.sat ) : '' ) + '</span>';
    }

    function codHtml( p ) {
        return p.cod
            ? '<span class="wc-acs-points-badge wc-acs-points-badge--cod">' + escapeHtml( cfg.i18n.cod ) + '</span>'
            : '<span class="wc-acs-points-badge wc-acs-points-badge--nocod">' + escapeHtml( cfg.i18n.noCod ) + '</span>';
    }

    // ─── Overlay ──────────────────────────────────────────────────

    function buildOverlay() {
        var chips = cfg.pointTypes === 'lockers' ? '' :
            '<div class="wc-acs-points-chips" role="group">' +
                '<button type="button" class="wc-acs-points-chip is-active" data-filter="all">' + escapeHtml( cfg.i18n.all ) + '</button>' +
                '<button type="button" class="wc-acs-points-chip" data-filter="locker">' + escapeHtml( cfg.i18n.lockers ) + '</button>' +
                '<button type="button" class="wc-acs-points-chip" data-filter="store">' + escapeHtml( cfg.i18n.stores ) + '</button>' +
            '</div>';

        var html =
            '<div class="wc-acs-points-overlay" role="dialog" aria-modal="true" aria-label="' + escapeHtml( cfg.i18n.title ) + '">' +
                '<div class="wc-acs-points-modal">' +
                    '<div class="wc-acs-points-header">' +
                        '<span class="wc-acs-points-title">' + escapeHtml( cfg.i18n.title ) + '</span>' +
                        '<button type="button" class="wc-acs-points-close" aria-label="' + escapeHtml( cfg.i18n.close ) + '">&times;</button>' +
                    '</div>' +
                    '<div class="wc-acs-points-body">' +
                        '<aside class="wc-acs-points-sidebar">' +
                            '<button type="button" class="wc-acs-points-sheet-toggle" aria-label="' + escapeHtml( cfg.i18n.close ) + '"><span></span></button>' +
                            '<div class="wc-acs-points-tools">' +
                                '<input type="search" class="wc-acs-points-search" placeholder="' + escapeHtml( cfg.i18n.search ) + '" autocomplete="off" />' +
                                '<button type="button" class="wc-acs-points-locate">' + escapeHtml( cfg.i18n.myLocation ) + '</button>' +
                            '</div>' +
                            chips +
                            '<div class="wc-acs-points-list" aria-live="polite"><p class="wc-acs-points-status">' + escapeHtml( cfg.i18n.loading ) + '</p></div>' +
                        '</aside>' +
                        '<div class="wc-acs-points-map"></div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        state.overlay = $( html ).appendTo( 'body' );
        $( 'body' ).addClass( 'wc-acs-points-noscroll' );

        state.overlay.on( 'click', '.wc-acs-points-close', closeOverlay );
        state.overlay.on( 'click', function ( e ) {
            if ( e.target === state.overlay[ 0 ] ) {
                closeOverlay();
            }
        } );
        state.overlay.on( 'click', '.wc-acs-points-chip', function () {
            state.filter = $( this ).data( 'filter' );
            state.overlay.find( '.wc-acs-points-chip' ).removeClass( 'is-active' );
            $( this ).addClass( 'is-active' );
            renderMarkers();
            renderList();
        } );
        var searchTimer = null;
        state.overlay.on( 'input', '.wc-acs-points-search', function () {
            var value = this.value;
            clearTimeout( searchTimer );
            searchTimer = setTimeout( function () {
                state.search = value;
                renderMarkers();
                renderList();
            }, 250 );
        } );
        state.overlay.on( 'click', '.wc-acs-points-locate', locate );
        state.overlay.on( 'click', '.wc-acs-points-sheet-toggle', function () {
            state.overlay.find( '.wc-acs-points-sidebar' ).toggleClass( 'is-collapsed' );
        } );
        state.overlay.on( 'click', '.wc-acs-points-item', function () {
            var id = $( this ).data( 'id' );
            var marker = state.markers[ id ];
            if ( marker ) {
                state.map.setView( marker.getLatLng(), Math.max( state.map.getZoom(), 15 ) );
                marker.openPopup();
            }
            if ( window.innerWidth < 768 ) {
                state.overlay.find( '.wc-acs-points-sidebar' ).addClass( 'is-collapsed' );
            }
        } );
        state.overlay.on( 'click', '.wc-acs-points-select', function () {
            selectPoint( String( $( this ).data( 'id' ) ) );
        } );
    }

    function closeOverlay() {
        if ( state.overlay ) {
            state.overlay.remove();
            state.overlay = null;
        }
        if ( state.map ) {
            state.map.remove();
            state.map = null;
            state.cluster = null;
            state.markers = {};
            state.userMarker = null;
        }
        $( 'body' ).removeClass( 'wc-acs-points-noscroll' );
    }

    // ─── Map ──────────────────────────────────────────────────────

    function icons() {
        if ( ! state.icons ) {
            var make = function ( url ) {
                return L.icon( { iconUrl: url, iconSize: [ 32, 40 ], iconAnchor: [ 16, 40 ], popupAnchor: [ 0, -36 ] } );
            };
            state.icons = {
                locker: make( cfg.icons.locker ),
                lockerCod: make( cfg.icons.lockerCod ),
                store: make( cfg.icons.store ),
                user: L.icon( { iconUrl: cfg.icons.marker, shadowUrl: cfg.icons.markerShadow, iconSize: [ 25, 41 ], iconAnchor: [ 12, 41 ], shadowSize: [ 41, 41 ] } )
            };
        }
        return state.icons;
    }

    function iconFor( p ) {
        if ( p.type === 'store' ) {
            return icons().store;
        }
        return p.cod ? icons().lockerCod : icons().locker;
    }

    function popupHtml( p ) {
        return '<div class="wc-acs-points-popup">' +
            '<strong>' + escapeHtml( p.name ) + '</strong>' +
            '<span>' + escapeHtml( p.street ) + ', ' + escapeHtml( p.zip ) + ' ' + escapeHtml( p.city ) + '</span>' +
            '<span class="wc-acs-points-popup-meta">' + hoursHtml( p ) + ' ' + codHtml( p ) + '</span>' +
            '<button type="button" class="wc-acs-points-select" data-id="' + escapeHtml( p.id ) + '">' + escapeHtml( cfg.i18n.select ) + '</button>' +
        '</div>';
    }

    function initMap() {
        var el = state.overlay.find( '.wc-acs-points-map' )[ 0 ];
        state.map = L.map( el, { zoomControl: true, minZoom: 6, maxZoom: 18 } );
        L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
        } ).addTo( state.map );

        state.cluster = L.markerClusterGroup( { maxClusterRadius: 60, disableClusteringAtZoom: 15, showCoverageOnHover: false } );
        state.map.addLayer( state.cluster );

        var centre = cfg.postcodeCentre || centreForPostcode( checkoutPostcode(), state.points );
        if ( centre ) {
            state.map.setView( [ centre[ 0 ], centre[ 1 ] ], centre[ 2 ] );
        } else {
            state.map.setView( GREECE.center, GREECE.zoom );
        }

        var moveTimer = null;
        state.map.on( 'moveend', function () {
            clearTimeout( moveTimer );
            moveTimer = setTimeout( renderList, 250 );
        } );

        renderMarkers();
        renderList();
        setTimeout( function () { state.map.invalidateSize(); }, 150 );
    }

    function visiblePoints() {
        var needle = fold( state.search );
        return state.points.filter( function ( p ) {
            if ( state.filter !== 'all' && p.type !== state.filter ) {
                return false;
            }
            if ( ! needle ) {
                return true;
            }
            return fold( p.name + ' ' + p.street + ' ' + p.city + ' ' + p.zip ).indexOf( needle ) !== -1;
        } );
    }

    function renderMarkers() {
        state.cluster.clearLayers();
        state.markers = {};
        visiblePoints().forEach( function ( p ) {
            var marker = L.marker( [ p.lat, p.lon ], { icon: iconFor( p ), title: p.name } );
            marker.bindPopup( popupHtml( p ), { maxWidth: 300 } );
            state.markers[ p.id ] = marker;
            state.cluster.addLayer( marker );
        } );
    }

    function renderList() {
        if ( ! state.map || ! state.overlay ) {
            return;
        }
        var centre = state.map.getCenter();
        var list = state.overlay.find( '.wc-acs-points-list' );
        var points = visiblePoints().map( function ( p ) {
            return { p: p, d: distanceKm( centre.lat, centre.lng, p.lat, p.lon ) };
        } ).sort( function ( a, b ) { return a.d - b.d; } );

        if ( ! points.length ) {
            list.html( '<p class="wc-acs-points-status">' + escapeHtml( cfg.i18n.moreHint ) + '</p>' );
            return;
        }

        var html = points.slice( 0, LIST_CAP ).map( function ( item ) {
            var p = item.p;
            var km = item.d < 10 ? item.d.toFixed( 1 ) : Math.round( item.d );
            return '<button type="button" class="wc-acs-points-item wc-acs-points-item--' + escapeHtml( p.type ) + '" data-id="' + escapeHtml( p.id ) + '">' +
                '<span class="wc-acs-points-item-name">' + escapeHtml( p.name ) + '</span>' +
                '<span class="wc-acs-points-item-address">' + escapeHtml( p.street ) + ', ' + escapeHtml( p.zip ) + ' ' + escapeHtml( p.city ) + '</span>' +
                '<span class="wc-acs-points-item-meta">' + hoursHtml( p ) + ' ' + codHtml( p ) + '<span class="wc-acs-points-km">' + km + ' km</span></span>' +
            '</button>';
        } ).join( '' );

        if ( points.length > LIST_CAP ) {
            html += '<p class="wc-acs-points-status">' + escapeHtml( cfg.i18n.moreHint ) + '</p>';
        }
        list.html( html );
    }

    function locate() {
        if ( ! navigator.geolocation ) {
            window.alert( cfg.i18n.locateError );
            return;
        }
        navigator.geolocation.getCurrentPosition( function ( pos ) {
            var latlng = [ pos.coords.latitude, pos.coords.longitude ];
            if ( state.userMarker ) {
                state.userMarker.setLatLng( latlng );
            } else {
                state.userMarker = L.marker( latlng, { icon: icons().user } ).addTo( state.map );
            }
            state.map.setView( latlng, 14 );
        }, function () {
            window.alert( cfg.i18n.locateError );
        }, { enableHighAccuracy: false, timeout: 8000, maximumAge: 300000 } );
    }

    // ─── Selection ────────────────────────────────────────────────

    function selectPoint( id ) {
        $.post( cfg.ajaxUrl, { action: 'wc_acs_set_point', nonce: cfg.nonce, point_id: id } )
            .done( function ( res ) {
                if ( ! res || ! res.success ) {
                    window.alert( ( res && res.data && res.data.message ) || cfg.i18n.loadError );
                    return;
                }
                $( '#acs_point_id' ).val( id );
                closeOverlay();
                $( document.body ).trigger( 'update_checkout' );
            } )
            .fail( function () {
                window.alert( cfg.i18n.loadError );
            } );
    }

    function openPicker() {
        if ( state.overlay ) {
            return;
        }
        buildOverlay();
        Promise.all( [ loadAssets(), loadPoints() ] )
            .then( function () {
                if ( state.overlay ) {
                    initMap();
                }
            } )
            .catch( function () {
                if ( state.overlay ) {
                    state.overlay.find( '.wc-acs-points-list' ).html( '<p class="wc-acs-points-status wc-acs-points-status--error">' + escapeHtml( cfg.i18n.loadError ) + '</p>' );
                }
            } );
    }

    // ─── Boot ─────────────────────────────────────────────────────

    $( function () {
        $( document.body ).on( 'click', '.wc-acs-points-open, .wc-acs-points-change', function ( e ) {
            e.preventDefault();
            openPicker();
        } );
        $( document ).on( 'keyup', function ( e ) {
            if ( e.key === 'Escape' && state.overlay ) {
                closeOverlay();
            }
        } );
    } );
}( jQuery ) );
```

- [ ] **Step 2: Syntax-check the script**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && node --check assets/js/acs-points.js && echo OK
```

- [ ] **Step 3: Create `assets/css/acs-points.css`**

```css
/* ACS Points picker. Every rule is scoped to the picker or the overlay. */

/* ---- Under the shipping rate ---- */
.wc-acs-points-picker {
    display: block;
    margin: 10px 0 4px;
}

.wc-acs-points-open,
.wc-acs-points-select {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 0;
    border-radius: 6px;
    background: #e30613;
    color: #fff;
    cursor: pointer;
    font: inherit;
    font-size: 0.95em;
    font-weight: 600;
    line-height: 1.2;
    padding: 10px 16px;
    text-decoration: none;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
    transition: filter 0.15s ease, transform 0.05s ease;
}

.wc-acs-points-open:hover,
.wc-acs-points-open:focus-visible,
.wc-acs-points-select:hover,
.wc-acs-points-select:focus-visible {
    filter: brightness(0.92);
    outline: 2px solid #7f0a0f;
    outline-offset: 2px;
}

.wc-acs-points-open[hidden],
.wc-acs-points-selected[hidden] {
    display: none;
}

.wc-acs-points-selected {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 10px;
    font-size: 0.92em;
}

.wc-acs-points-selected-name {
    flex: 1 1 100%;
    font-weight: 600;
}

.wc-acs-points-selected-address {
    flex: 1 1 100%;
    color: #555;
}

.wc-acs-points-change {
    background: none;
    border: 0;
    padding: 0;
    color: #e30613;
    cursor: pointer;
    font: inherit;
    font-size: 0.9em;
    text-decoration: underline;
}

.wc-acs-points-badge {
    display: inline-block;
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 0.8em;
    font-weight: 600;
    line-height: 1.6;
    background: #eef2f7;
    color: #334155;
}

.wc-acs-points-badge--24 { background: #e0f2fe; color: #075985; }
.wc-acs-points-badge--cod { background: #dcfce7; color: #166534; }
.wc-acs-points-badge--nocod { background: #fee2e2; color: #991b1b; }

/* ---- Overlay ---- */
body.wc-acs-points-noscroll {
    overflow: hidden;
}

.wc-acs-points-overlay {
    position: fixed;
    inset: 0;
    z-index: 99998;
    background: rgba(0, 0, 0, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.wc-acs-points-modal {
    display: flex;
    flex-direction: column;
    width: min(1200px, 100%);
    height: min(820px, 100%);
    background: #fff;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
}

.wc-acs-points-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
}

.wc-acs-points-title {
    font-weight: 700;
    font-size: 1.05em;
}

.wc-acs-points-close {
    border: 0;
    background: none;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    color: #374151;
    padding: 0 4px;
}

.wc-acs-points-body {
    display: grid;
    grid-template-columns: 360px 1fr;
    flex: 1 1 auto;
    min-height: 0;
    position: relative;
}

.wc-acs-points-sidebar {
    display: flex;
    flex-direction: column;
    min-height: 0;
    border-right: 1px solid #e5e7eb;
    background: #fff;
}

.wc-acs-points-sheet-toggle {
    display: none;
}

.wc-acs-points-tools {
    display: flex;
    gap: 8px;
    padding: 12px;
}

.wc-acs-points-search {
    flex: 1 1 auto;
    min-width: 0;
    padding: 9px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font: inherit;
}

.wc-acs-points-locate,
.wc-acs-points-chip {
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 999px;
    padding: 6px 12px;
    font: inherit;
    font-size: 0.85em;
    cursor: pointer;
    white-space: nowrap;
}

.wc-acs-points-chips {
    display: flex;
    gap: 6px;
    padding: 0 12px 10px;
}

.wc-acs-points-chip.is-active {
    background: #111827;
    color: #fff;
    border-color: #111827;
}

.wc-acs-points-list {
    flex: 1 1 auto;
    overflow-y: auto;
    min-height: 0;
    border-top: 1px solid #e5e7eb;
}

.wc-acs-points-status {
    margin: 0;
    padding: 16px 12px;
    color: #6b7280;
    font-size: 0.9em;
}

.wc-acs-points-status--error {
    color: #991b1b;
}

.wc-acs-points-item {
    display: flex;
    flex-direction: column;
    gap: 3px;
    width: 100%;
    text-align: left;
    padding: 10px 12px 10px 16px;
    border: 0;
    border-bottom: 1px solid #f3f4f6;
    border-left: 4px solid #9ca3af;
    background: #fff;
    cursor: pointer;
    font: inherit;
}

.wc-acs-points-item--store {
    border-left-color: #e30613;
}

.wc-acs-points-item:hover,
.wc-acs-points-item:focus-visible {
    background: #f9fafb;
    outline: none;
}

.wc-acs-points-item-name {
    font-weight: 600;
    font-size: 0.95em;
}

.wc-acs-points-item-address {
    color: #4b5563;
    font-size: 0.88em;
}

.wc-acs-points-item-meta,
.wc-acs-points-popup-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    font-size: 0.85em;
    color: #6b7280;
}

.wc-acs-points-km {
    margin-left: auto;
    font-variant-numeric: tabular-nums;
}

.wc-acs-points-map {
    min-height: 0;
    height: 100%;
    background: #e5e7eb;
}

/* Leaflet popup content */
.wc-acs-points-popup {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font: inherit;
    font-size: 13px;
    line-height: 1.35;
}

.wc-acs-points-popup .wc-acs-points-select {
    align-self: flex-start;
    padding: 8px 14px;
    margin-top: 4px;
}

/* ---- Phones: the list becomes a bottom sheet ---- */
@media (max-width: 767px) {
    .wc-acs-points-overlay {
        padding: 0;
    }

    .wc-acs-points-modal {
        width: 100%;
        height: 100%;
        border-radius: 0;
    }

    .wc-acs-points-body {
        display: block;
    }

    .wc-acs-points-map {
        position: absolute;
        inset: 0;
    }

    .wc-acs-points-sidebar {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 55%;
        border-right: 0;
        border-top: 1px solid #e5e7eb;
        border-radius: 14px 14px 0 0;
        box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.18);
        transition: height 0.2s ease;
        z-index: 1000;
    }

    .wc-acs-points-sidebar.is-collapsed {
        height: 132px;
    }

    .wc-acs-points-sidebar.is-collapsed .wc-acs-points-list {
        display: none;
    }

    .wc-acs-points-sheet-toggle {
        display: block;
        width: 100%;
        height: 22px;
        border: 0;
        background: none;
        cursor: pointer;
        padding: 8px 0 0;
    }

    .wc-acs-points-sheet-toggle span {
        display: block;
        width: 44px;
        height: 5px;
        margin: 0 auto;
        border-radius: 999px;
        background: #d1d5db;
    }

    .wc-acs-points-tools {
        padding: 6px 12px 8px;
    }
}
```

- [ ] **Step 4: Smoke test the overlay without WordPress**

Create a throwaway page in the scratchpad (not committed) that loads jQuery from `node_modules` or an inline copy is not available, so use this: `C:\Users\jimra\AppData\Local\Temp\claude\...\scratchpad\acs-points-harness.html` with the vendored assets referenced by relative `file://` paths, a fake `wcAcsPoints` object whose `restUrl` points at a local JSON file containing five hand-written positional rows (two Ioannina lockers, one with `cod` 0, one Ioannina store, two Athens lockers), and a `<div class="wc-acs-points-picker"><button class="wc-acs-points-open">Open</button><input id="acs_point_id" type="hidden"></div>`. jQuery: copy `wp-includes/js/jquery/jquery.min.js` from the staging container (`docker cp pooq-staging-wp-1:/var/www/html/wp-includes/js/jquery/jquery.min.js <scratchpad>/`). Because `fetch()` on `file://` is blocked by browsers, serve the scratchpad folder with `npx --yes http-server <scratchpad> -p 8099 -s` and open `http://localhost:8099/acs-points-harness.html` in the Browser pane. Confirm: overlay opens, tiles render, five markers cluster, typing "ιωαν" filters to three rows, "Stores" chip leaves one, clicking a row opens its popup, Escape closes. The AJAX post will fail (no WordPress) and alert the load error; that is expected in the harness. Stop the server afterwards.

- [ ] **Step 5: Commit**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && git add assets/js/acs-points.js assets/css/acs-points.css && git commit -q -m "feat(points): map picker script and styles (Leaflet, clustered, searchable, bottom sheet on phones)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 10: Voucher routing for point orders

**Files:**
- Modify: `includes/class-acs-voucher.php` (`build_voucher_params()` at 258-370, `ajax_create_voucher()` at ~380-410, `auto_create_voucher()` at ~765-800, `handle_bulk_actions()` at ~942-950, metabox at ~170-180)
- Test: `tests/Unit/VoucherTest.php` (replace two tests, add five)

**Interfaces:**
- Changes: `build_voucher_params( $order, $extra_params = array() ) : array|WP_Error`. Returns `WP_Error( 'acs_point_mobile' )` for a point order whose billing phone is not a Greek mobile. Every caller must check `is_wp_error()`.
- Point orders send `Acs_Station_Destination`, `Acs_Station_Branch_Destination` (int), `Item_Quantity = 1`, `Recipient_Cell_Phone` and `Recipient_Phone` normalised, `Delivery_Notes` starting with `ACS Point: <name>, <address>`.
- Consumes: `WC_ACS_Points_Picker::normalise_mobile()`.

- [ ] **Step 1: Replace the two smartpoint tests in `tests/Unit/VoucherTest.php`**

Delete `test_build_params_smartpoint_order()` and `test_build_params_cod_and_smartpoint()` entirely. Add, in their place:

```php
    private function pointMeta(): array {
        return [
            '_acs_point_id'      => '4400',
            '_acs_point_type'    => 'locker',
            '_acs_point_name'    => 'ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ',
            '_acs_point_address' => 'Market In, Χαρ. Τρικούπη 38, 45333 ΙΩΑΝΝΙΝΑ',
            '_acs_point_station' => 'ΙΒ',
            '_acs_point_branch'  => '501',
            '_acs_point_cod'     => '1',
        ];
    }

    private function pointOptions(): void {
        $this->stubGetOption( [
            'wc_acs_charge_type'      => '2',
            'wc_acs_default_weight'   => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );
    }

    public function test_build_params_point_order_routes_by_station_codes(): void {
        $this->pointOptions();
        $order = $this->createOrderMock( [ 'meta' => $this->pointMeta(), 'billing_phone' => '+30 691 234 5678' ] );

        $params = $this->voucher->build_voucher_params( $order );

        $this->assertSame( 'ΙΒ', $params['Acs_Station_Destination'] );
        $this->assertSame( 501, $params['Acs_Station_Branch_Destination'] );
        $this->assertSame( 1, $params['Item_Quantity'] );
        $this->assertSame( '6912345678', $params['Recipient_Cell_Phone'] );
        $this->assertSame( '6912345678', $params['Recipient_Phone'] );
        $this->assertSame( 'john@example.com', $params['Recipient_Email'] );
        $this->assertStringStartsWith( 'ACS Point: ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ, Market In', $params['Delivery_Notes'] );
    }

    public function test_build_params_point_order_keeps_customer_address_and_no_rec(): void {
        $this->pointOptions();
        $order = $this->createOrderMock( [ 'meta' => $this->pointMeta(), 'billing_phone' => '6912345678' ] );

        $params = $this->voucher->build_voucher_params( $order );

        $this->assertSame( 'John Doe', $params['Recipient_Name'] );
        $this->assertSame( 'Ermou', $params['Recipient_Address'] );
        $this->assertSame( '25', $params['Recipient_Address_Number'] );
        $this->assertSame( '10563', $params['Recipient_Zipcode'] );
        $this->assertNull( $params['Acs_Delivery_Products'] );
        $this->assertArrayNotHasKey( 'Reference_Key2', $params );
    }

    public function test_build_params_point_order_forces_single_parcel_over_metabox_value(): void {
        $this->pointOptions();
        $order = $this->createOrderMock( [ 'meta' => $this->pointMeta(), 'billing_phone' => '6912345678' ] );

        $params = $this->voucher->build_voucher_params( $order, [ 'Item_Quantity' => 3, 'Delivery_Notes' => 'Ring twice' ] );

        $this->assertSame( 1, $params['Item_Quantity'] );
        $this->assertSame( 'ACS Point: ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ, Market In, Χαρ. Τρικούπη 38, 45333 ΙΩΑΝΝΙΝΑ | Ring twice', $params['Delivery_Notes'] );
    }

    public function test_build_params_point_order_with_cod(): void {
        $this->pointOptions();
        $order = $this->createOrderMock( [ 'meta' => $this->pointMeta(), 'billing_phone' => '6912345678', 'payment_method' => 'cod', 'total' => '42.00' ] );

        $params = $this->voucher->build_voucher_params( $order );

        $this->assertSame( 'COD', $params['Acs_Delivery_Products'] );
        $this->assertEquals( 42.0, $params['Cod_Ammount'] );
        $this->assertSame( 'ΙΒ', $params['Acs_Station_Destination'] );
    }

    public function test_build_params_point_order_without_mobile_is_an_error(): void {
        $this->pointOptions();
        $order = $this->createOrderMock( [ 'meta' => $this->pointMeta(), 'billing_phone' => '2101234567' ] );

        $params = $this->voucher->build_voucher_params( $order );

        $this->assertInstanceOf( \WP_Error::class, $params );
        $this->assertSame( 'acs_point_mobile', $params->get_error_code() );
    }

    public function test_build_params_home_delivery_has_no_station_routing(): void {
        $this->pointOptions();
        $order = $this->createOrderMock( [ 'billing_phone' => '2101234567' ] );

        $params = $this->voucher->build_voucher_params( $order, [ 'Item_Quantity' => 2 ] );

        $this->assertArrayNotHasKey( 'Acs_Station_Destination', $params );
        $this->assertSame( 2, $params['Item_Quantity'] );
        $this->assertSame( '2101234567', $params['Recipient_Cell_Phone'] );
        $this->assertNull( $params['Delivery_Notes'] ?? null );
    }
```

- [ ] **Step 2: Run to verify failure**

`--filter VoucherTest`. Expected: the six new tests fail (`Acs_Station_Destination` missing, `REC` still present, no `WP_Error`).

- [ ] **Step 3: Rewrite the smartpoint block in `build_voucher_params()`**

In `includes/class-acs-voucher.php`, replace everything from the comment `// Check for Smartpoint delivery` down to (and including) the line `$delivery_products = ! empty( $products ) ? implode( ',', $products ) : null;` with:

```php
        // ACS Point delivery: routed by the two station codes, not by address.
        $point_station = (string) $order->get_meta( '_acs_point_station' );
        $point_branch  = (string) $order->get_meta( '_acs_point_branch' );
        $is_point      = ( '' !== $point_station && '' !== $point_branch );
        $mobile        = WC_ACS_Points_Picker::normalise_mobile( $order->get_billing_phone() );

        if ( $is_point && null === $mobile ) {
            return new WP_Error( 'acs_point_mobile', __( 'ACS Points need a Greek mobile number.', 'wc-acs-courier' ) );
        }

        $delivery_products = ! empty( $products ) ? implode( ',', $products ) : null;
```

Then replace the address-line block:

```php
        // Combine address lines; skip address_2 for smartpoint deliveries
        // (smartpoint block above overwrites address_1 with the full location).
        $address_line = $address['address_1'] ?? '';
        if ( ! $smartpoint_id && ! empty( $address['address_2'] ) ) {
            $address_line .= ', ' . $address['address_2'];
        }
```

with:

```php
        // Combine address lines.
        $address_line = $address['address_1'] ?? '';
        if ( ! empty( $address['address_2'] ) ) {
            $address_line .= ', ' . $address['address_2'];
        }
```

Delete the block starting `// Add smartpoint ID to Reference_Key2 and Delivery_Notes for ACS routing` through its closing `}` (the `if ( $smartpoint_id ) { ... }` that sets `Reference_Key2`, `$sp_note`, `Delivery_Notes`).

Replace the tail of the method, from `$merged = array_merge( $params, $extra_params );` to `return $merged;`, with:

```php
        $merged = array_merge( $params, $extra_params );

        if ( $is_point ) {
            $note = sprintf(
                'ACS Point: %s, %s',
                (string) $order->get_meta( '_acs_point_name' ),
                (string) $order->get_meta( '_acs_point_address' )
            );

            $merged['Acs_Station_Destination']        = $point_station;
            $merged['Acs_Station_Branch_Destination'] = (int) $point_branch;
            // ACS refuses multi-parcel shipments to a Smart Point.
            $merged['Item_Quantity']        = 1;
            $merged['Recipient_Cell_Phone'] = $mobile;
            $merged['Recipient_Phone']      = $mobile;
            $merged['Delivery_Notes']       = ! empty( $extra_params['Delivery_Notes'] )
                ? $note . ' | ' . $extra_params['Delivery_Notes']
                : $note;
        }

        return $merged;
```

Also update the method docblock `@return array` to `@return array|WP_Error`.

- [ ] **Step 4: Guard the three callers**

In `ajax_create_voucher()`, right after `$params = $this->build_voucher_params( $order, $extra );` add:

```php
        if ( is_wp_error( $params ) ) {
            wp_send_json_error( $params->get_error_message() );
        }
```

In `auto_create_voucher()`, right after `$params = $this->build_voucher_params( $order );` add:

```php
        if ( is_wp_error( $params ) ) {
            $order->add_order_note(
                /* translators: %s: error message */
                sprintf( __( 'ACS auto-voucher failed: %s', 'wc-acs-courier' ), $params->get_error_message() )
            );
            $order->save();
            return;
        }
```

In `handle_bulk_actions()`, right after `$params = $this->build_voucher_params( $order );` add:

```php
                if ( is_wp_error( $params ) ) {
                    $order->add_order_note( sprintf( __( 'ACS voucher skipped: %s', 'wc-acs-courier' ), $params->get_error_message() ) );
                    $order->save();
                    continue;
                }
```

- [ ] **Step 5: Lock the parcel count in the metabox**

In `render_metabox()`, replace the `Parcels` field paragraph with:

```php
                    <?php $is_point_order = '' !== (string) $order->get_meta( '_acs_point_station' ); ?>
                    <p class="wc-acs-field">
                        <label><?php esc_html_e( 'Parcels', 'wc-acs-courier' ); ?></label>
                        <input type="number" id="wc-acs-item-qty" value="1" min="1" max="99" class="small-text"<?php echo $is_point_order ? ' disabled="disabled"' : ''; ?> />
                        <?php if ( $is_point_order ) : ?>
                            <span class="description"><?php esc_html_e( 'ACS Points accept one parcel per shipment.', 'wc-acs-courier' ); ?></span>
                        <?php endif; ?>
                    </p>
```

- [ ] **Step 6: Run the tests**

`--filter VoucherTest`. Expected: all PASS (existing tests plus six new). Full suite `OK (133 tests, ...)` (129 + 6 new minus 2 removed).

- [ ] **Step 7: Lint and commit**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && export MSYS_NO_PATHCONV=1 && docker run --rm -v "D:/Documents/Projects/POOQ/wc-acs-courier:/app" php:8.3-cli php -l /app/includes/class-acs-voucher.php && git add -A && git commit -q -m "fix(voucher): route ACS Point orders by station codes, single parcel, mobile required

Replaces the REC product and the address rewrite, which ACS would have
treated as a home delivery to the shop's street address.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 11: Order views: admin box with change control, customer page, emails

**Files:**
- Create: `includes/class-acs-points-order.php`
- Modify: `assets/js/acs-admin.js` (search + save handlers), `assets/css/acs-admin.css` (result list), `wc-acs-courier.php` (file lists, init), `tests/bootstrap.php` (require)
- Test: `tests/Unit/PointsOrderTest.php`

**Interfaces:**
- Produces `WC_ACS_Points_Order` (singleton) with:
  - `static can_change_point( $order ) : bool` (true while `_acs_voucher_no` is empty)
  - `static summary_line( $order ) : string` (`"<name>, <address>"` or `''`)
  - `static maps_url( $order ) : string`
  - `render_admin_box( $order )`, `render_customer_details( $order )`, `render_email_meta( $order, $sent_to_admin, $plain_text )`
  - AJAX `wc_acs_search_points` (nonce `wc_acs_nonce`, cap `edit_shop_orders`, POST `q`) returning up to 20 `{ id, label, type }`
  - AJAX `wc_acs_set_order_point` (nonce `wc_acs_nonce`, cap `edit_shop_orders`, POST `order_id`, `point_id`) writing the seven keys through `apply_point_to_order()` and saving
- Consumes: `WC_ACS_Points_Feed::instance()->find()`, `->get_points()`, `WC_ACS_Points_Picker::apply_point_to_order()`, `::format_address()`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/PointsOrderTest.php`:

```php
<?php
namespace WC_ACS_Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

class PointsOrderTest extends TestCase {

    private function point( array $overrides = [] ): array {
        return array_merge( [
            'id' => '4400', 'type' => 'locker', 'name' => 'ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ',
            'street' => 'Market In, Χαρ. Τρικούπη 38', 'city' => 'ΙΩΑΝΝΙΝΑ', 'zip' => '45333',
            'lat' => 39.664978, 'lon' => 20.848969, 'station' => 'ΙΒ', 'branch' => '501',
            'cod' => 1, 'h24' => 1, 'hours' => '24ΩΡΟ', 'sat' => '24ΩΡΟ',
        ], $overrides );
    }

    private function seedFeed( array $points ): void {
        $this->resetStaticProperty( \WC_ACS_Points_Feed::class, 'instance' );
        $this->stubGetOption( [ 'wc_acs_points_feed' => [ 'fetched_at' => 1, 'country' => 'GR', 'points' => $points ] ] );
    }

    private function pointMeta( array $extra = [] ): array {
        return array_merge( [
            '_acs_point_id'      => '4400',
            '_acs_point_type'    => 'locker',
            '_acs_point_name'    => 'ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ',
            '_acs_point_address' => 'Market In, Χαρ. Τρικούπη 38, 45333 ΙΩΑΝΝΙΝΑ',
            '_acs_point_station' => 'ΙΒ',
            '_acs_point_branch'  => '501',
            '_acs_point_cod'     => '1',
        ], $extra );
    }

    private function orderObj(): \WC_ACS_Points_Order {
        $this->resetStaticProperty( \WC_ACS_Points_Order::class, 'instance' );
        return \WC_ACS_Points_Order::instance();
    }

    public function test_can_change_point_only_without_voucher(): void {
        $this->assertTrue( \WC_ACS_Points_Order::can_change_point( $this->createOrderMock( [ 'meta' => $this->pointMeta() ] ) ) );
        $this->assertFalse( \WC_ACS_Points_Order::can_change_point( $this->createOrderMock( [ 'meta' => $this->pointMeta( [ '_acs_voucher_no' => '9804656606' ] ) ] ) ) );
    }

    public function test_summary_line_and_maps_url(): void {
        $order = $this->createOrderMock( [ 'meta' => $this->pointMeta() ] );

        $this->assertSame( 'ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ, Market In, Χαρ. Τρικούπη 38, 45333 ΙΩΑΝΝΙΝΑ', \WC_ACS_Points_Order::summary_line( $order ) );
        $this->assertSame( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( 'ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ, Market In, Χαρ. Τρικούπη 38, 45333 ΙΩΑΝΝΙΝΑ' ), \WC_ACS_Points_Order::maps_url( $order ) );
        $this->assertSame( '', \WC_ACS_Points_Order::summary_line( $this->createOrderMock() ) );
    }

    public function test_email_meta_prints_pickup_line_in_plain_and_html(): void {
        $this->seedFeed( [] );
        $order = $this->createOrderMock( [ 'meta' => $this->pointMeta() ] );
        $obj   = $this->orderObj();

        ob_start();
        $obj->render_email_meta( $order, false, true );
        $plain = ob_get_clean();
        ob_start();
        $obj->render_email_meta( $order, false, false );
        $html = ob_get_clean();
        ob_start();
        $obj->render_email_meta( $this->createOrderMock(), false, false );
        $none = ob_get_clean();

        $this->assertStringContainsString( 'Pickup from: ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ', $plain );
        $this->assertStringContainsString( '<strong>Pickup from:</strong>', $html );
        $this->assertStringContainsString( 'google.com/maps', $html );
        $this->assertSame( '', $none );
    }

    public function test_ajax_search_points_matches_name_street_city_zip(): void {
        $this->seedFeed( [
            $this->point( [ 'id' => '1', 'name' => 'ACS LOCKER A', 'street' => 'Ermou 1', 'city' => 'ΑΘΗΝΑ', 'zip' => '10563' ] ),
            $this->point( [ 'id' => '2', 'name' => 'ACS ΙΩΑΝΝΙΝΑ', 'street' => 'Δωδώνης 161', 'city' => 'ΙΩΑΝΝΙΝΑ', 'zip' => '45221', 'type' => 'store' ] ),
        ] );
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$captured ) {
            $captured = $data;
            throw new \RuntimeException( 'exit' );
        } );

        foreach ( [ 'ιωάννινα' => '2', '10563' => '1', 'ermou' => '1' ] as $q => $expected ) {
            $_POST['q'] = $q;
            try {
                $this->orderObj()->ajax_search_points();
            } catch ( \RuntimeException $e ) {
            }
            $this->assertCount( 1, $captured['points'], "query {$q}" );
            $this->assertSame( $expected, $captured['points'][0]['id'], "query {$q}" );
        }
        unset( $_POST['q'] );
    }

    public function test_ajax_set_order_point_refuses_when_voucher_exists(): void {
        $this->seedFeed( [ $this->point() ] );
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        $order = $this->createOrderMock( [ 'meta' => $this->pointMeta( [ '_acs_voucher_no' => '9804656606' ] ) ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        $captured = null;
        Functions\when( 'wp_send_json_error' )->alias( function ( $data ) use ( &$captured ) {
            $captured = $data;
            throw new \RuntimeException( 'exit' );
        } );
        $_POST['order_id'] = '100';
        $_POST['point_id'] = '4400';

        try {
            $this->orderObj()->ajax_set_order_point();
        } catch ( \RuntimeException $e ) {
        }

        $this->assertSame( 'Delete the voucher to change the point.', $captured );
        $this->assertSame( [], $order->updated_meta );
        unset( $_POST['order_id'], $_POST['point_id'] );
    }

    public function test_ajax_set_order_point_writes_meta_and_note(): void {
        $this->seedFeed( [ $this->point( [ 'id' => '5', 'name' => 'ACS ΚΟΝΙΤΣΑ', 'type' => 'store', 'station' => 'ΙΓ', 'branch' => '1' ] ) ] );
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        $order = $this->createOrderMock( [ 'meta' => $this->pointMeta() ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$captured ) {
            $captured = $data;
            throw new \RuntimeException( 'exit' );
        } );
        $_POST['order_id'] = '100';
        $_POST['point_id'] = '5';

        try {
            $this->orderObj()->ajax_set_order_point();
        } catch ( \RuntimeException $e ) {
        }

        $this->assertSame( 'ΙΓ', $order->updated_meta['_acs_point_station'] );
        $this->assertSame( 'store', $order->updated_meta['_acs_point_type'] );
        $this->assertStringContainsString( 'ACS ΚΟΝΙΤΣΑ', $order->notes[0] );
        $this->assertStringContainsString( 'ACS ΚΟΝΙΤΣΑ', $captured['summary'] );
        unset( $_POST['order_id'], $_POST['point_id'] );
    }
}
```

- [ ] **Step 2: Add the bootstrap require and run to verify failure**

In `tests/bootstrap.php` add `require_once $plugin_dir . 'class-acs-points-order.php';` after the picker require. Run `--filter PointsOrderTest`. Expected: fatal, file missing.

- [ ] **Step 3: Create `includes/class-acs-points-order.php`**

```php
<?php
/**
 * ACS Point on the order: admin box with a change control, customer views,
 * email line.
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Points_Order {

    /** @var WC_ACS_Points_Order|null */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'render_admin_box' ) );
        add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'render_customer_details' ) );
        add_action( 'woocommerce_email_order_meta', array( $this, 'render_email_meta' ), 20, 3 );

        add_action( 'wp_ajax_wc_acs_search_points', array( $this, 'ajax_search_points' ) );
        add_action( 'wp_ajax_wc_acs_set_order_point', array( $this, 'ajax_set_order_point' ) );
    }

    /**
     * @param WC_Order $order Order.
     * @return bool
     */
    public static function can_change_point( $order ) {
        return '' === trim( (string) $order->get_meta( '_acs_voucher_no' ) );
    }

    /**
     * @param WC_Order $order Order.
     * @return string "name, address" or empty when the order has no point.
     */
    public static function summary_line( $order ) {
        $name = trim( (string) $order->get_meta( '_acs_point_name' ) );
        if ( '' === $name ) {
            return '';
        }
        $address = trim( (string) $order->get_meta( '_acs_point_address' ) );
        return '' === $address ? $name : $name . ', ' . $address;
    }

    /**
     * Plain link, no request until clicked.
     *
     * @param WC_Order $order Order.
     * @return string
     */
    public static function maps_url( $order ) {
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( self::summary_line( $order ) );
    }

    // ─── Admin ─────────────────────────────────────────────────────

    /**
     * @param WC_Order $order Order.
     */
    public function render_admin_box( $order ) {
        $summary = self::summary_line( $order );
        if ( '' === $summary ) {
            return;
        }
        $type = (string) $order->get_meta( '_acs_point_type' );
        $code = (string) $order->get_meta( '_acs_point_station' ) . (string) $order->get_meta( '_acs_point_branch' );
        $cod  = '1' === (string) $order->get_meta( '_acs_point_cod' );
        ?>
        <div class="wc-acs-point-admin" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" style="margin-top:15px; padding:10px; background:#f0f6fc; border-left:4px solid #2271b1;">
            <p style="margin:0 0 6px;">
                <strong><?php esc_html_e( 'ACS Point', 'wc-acs-courier' ); ?></strong>
                (<?php echo esc_html( 'store' === $type ? __( 'Store', 'wc-acs-courier' ) : __( 'Locker', 'wc-acs-courier' ) ); ?>)<br />
                <span class="wc-acs-point-summary"><?php echo esc_html( $summary ); ?></span><br />
                <small style="color:#666;"><?php echo esc_html( $code ); ?> ·
                    <?php echo esc_html( $cod ? __( 'Cash on delivery available', 'wc-acs-courier' ) : __( 'No cash on delivery', 'wc-acs-courier' ) ); ?> ·
                    <a href="<?php echo esc_url( self::maps_url( $order ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on map', 'wc-acs-courier' ); ?></a>
                </small>
            </p>
            <?php if ( self::can_change_point( $order ) ) : ?>
                <p style="margin:0;">
                    <input type="text" class="wc-acs-point-search regular-text" placeholder="<?php esc_attr_e( 'Change point: type an area, street or postcode', 'wc-acs-courier' ); ?>" autocomplete="off" />
                </p>
                <ul class="wc-acs-point-results" hidden></ul>
            <?php else : ?>
                <p class="description" style="margin:0;"><?php esc_html_e( 'Delete the voucher to change the point.', 'wc-acs-courier' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: up to 20 points matching a free-text query (accent-insensitive).
     */
    public function ajax_search_points() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        $q       = self::fold( isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '' );
        $matches = array();

        if ( '' !== $q ) {
            foreach ( WC_ACS_Points_Feed::instance()->get_points() as $point ) {
                $haystack = self::fold( $point['name'] . ' ' . $point['street'] . ' ' . $point['city'] . ' ' . $point['zip'] );
                if ( false !== mb_strpos( $haystack, $q ) ) {
                    $matches[] = array(
                        'id'    => $point['id'],
                        'type'  => $point['type'],
                        'label' => $point['name'] . ', ' . WC_ACS_Points_Picker::format_address( $point ),
                    );
                    if ( count( $matches ) >= 20 ) {
                        break;
                    }
                }
            }
        }

        wp_send_json_success( array( 'points' => $matches ) );
    }

    /**
     * Lowercase, accents stripped (Greek tonos included), for matching.
     *
     * @param string $text Text.
     * @return string
     */
    public static function fold( $text ) {
        $text = mb_strtolower( (string) $text, 'UTF-8' );
        $map  = array( 'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω', 'ς' => 'σ' );
        return trim( strtr( $text, $map ) );
    }

    /**
     * AJAX: change the point on an order that has no voucher yet.
     */
    public function ajax_set_order_point() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        $order = wc_get_order( intval( $_POST['order_id'] ?? 0 ) );
        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wc-acs-courier' ) );
        }
        if ( ! self::can_change_point( $order ) ) {
            wp_send_json_error( __( 'Delete the voucher to change the point.', 'wc-acs-courier' ) );
        }

        $point = WC_ACS_Points_Feed::instance()->find( isset( $_POST['point_id'] ) ? sanitize_text_field( wp_unslash( $_POST['point_id'] ) ) : '' );
        if ( null === $point ) {
            wp_send_json_error( __( 'Unknown ACS Point.', 'wc-acs-courier' ) );
        }

        WC_ACS_Points_Picker::apply_point_to_order( $order, $point );
        $order->add_order_note(
            /* translators: %s: point name and address */
            sprintf( __( 'ACS Point changed to: %s', 'wc-acs-courier' ), $point['name'] . ', ' . WC_ACS_Points_Picker::format_address( $point ) )
        );
        $order->save();

        wp_send_json_success( array(
            'summary' => $point['name'] . ', ' . WC_ACS_Points_Picker::format_address( $point ),
        ) );
    }

    // ─── Customer ──────────────────────────────────────────────────

    /**
     * Thank-you page and My Account order view.
     *
     * @param WC_Order $order Order.
     */
    public function render_customer_details( $order ) {
        $summary = self::summary_line( $order );
        if ( '' === $summary ) {
            return;
        }
        ?>
        <section class="wc-acs-point-customer">
            <h2 class="woocommerce-column__title"><?php esc_html_e( 'Pickup point', 'wc-acs-courier' ); ?></h2>
            <p>
                <?php echo esc_html( $summary ); ?><br />
                <a href="<?php echo esc_url( self::maps_url( $order ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on map', 'wc-acs-courier' ); ?></a>
            </p>
        </section>
        <?php
    }

    /**
     * Line in every WooCommerce order email.
     *
     * @param WC_Order $order         Order.
     * @param bool     $sent_to_admin Admin copy.
     * @param bool     $plain_text    Plain text email.
     */
    public function render_email_meta( $order, $sent_to_admin, $plain_text ) {
        $summary = self::summary_line( $order );
        if ( '' === $summary ) {
            return;
        }

        if ( $plain_text ) {
            echo "\n" . esc_html__( 'Pickup from:', 'wc-acs-courier' ) . ' ' . esc_html( $summary ) . "\n";
            echo esc_url( self::maps_url( $order ) ) . "\n";
            return;
        }

        echo '<p><strong>' . esc_html__( 'Pickup from:', 'wc-acs-courier' ) . '</strong> ' . esc_html( $summary );
        echo ' <a href="' . esc_url( self::maps_url( $order ) ) . '">' . esc_html__( 'View on map', 'wc-acs-courier' ) . '</a></p>';
    }
}
```

- [ ] **Step 4: Run the tests**

`--filter PointsOrderTest`. Expected: 6 PASS. Full suite `OK (139 tests, ...)`.

- [ ] **Step 5: Admin JS and CSS for the change control**

In `assets/js/acs-admin.js` `bindEvents()` add:

```js
            // Order screen: change the ACS Point while no voucher exists
            $(document).on('input', '.wc-acs-point-search', this.searchPoints);
            $(document).on('click', '.wc-acs-point-results li', this.setOrderPoint);
```

After `refreshPoints` add:

```js
        // ─── ACS Point change control ───────────────────────────
        pointSearchTimer: null,

        searchPoints() {
            const $input = $(this);
            const $box = $input.closest('.wc-acs-point-admin');
            const $results = $box.find('.wc-acs-point-results');
            const q = $.trim($input.val());

            clearTimeout(ACS.pointSearchTimer);
            if (q.length < 2) {
                $results.empty().prop('hidden', true);
                return;
            }

            ACS.pointSearchTimer = setTimeout(function () {
                $.post(wc_acs.ajax_url, { action: 'wc_acs_search_points', nonce: wc_acs.nonce, q: q })
                    .done(function (res) {
                        $results.empty();
                        if (!res.success || !res.data.points.length) {
                            $results.prop('hidden', true);
                            return;
                        }
                        res.data.points.forEach(function (p) {
                            $('<li>').attr('data-id', p.id).addClass('wc-acs-point-result--' + p.type).text(p.label).appendTo($results);
                        });
                        $results.prop('hidden', false);
                    });
            }, 300);
        },

        setOrderPoint() {
            const $li = $(this);
            const $box = $li.closest('.wc-acs-point-admin');

            $.post(wc_acs.ajax_url, {
                action: 'wc_acs_set_order_point',
                nonce: wc_acs.nonce,
                order_id: $box.data('order-id'),
                point_id: $li.data('id'),
            })
                .done(function (res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        window.alert(res.data);
                    }
                })
                .fail(function () {
                    window.alert('Request failed.');
                });
        },
```

Append to `assets/css/acs-admin.css`:

```css
/* ACS Point change control on the order screen */
.wc-acs-point-results {
    margin: 6px 0 0;
    padding: 0;
    list-style: none;
    max-height: 220px;
    overflow-y: auto;
    border: 1px solid #c3c4c7;
    background: #fff;
}

.wc-acs-point-results li {
    margin: 0;
    padding: 6px 8px;
    border-left: 3px solid #9ca3af;
    cursor: pointer;
}

.wc-acs-point-results li.wc-acs-point-result--store {
    border-left-color: #e30613;
}

.wc-acs-point-results li:hover {
    background: #f0f6fc;
}
```

- [ ] **Step 6: Wire the class into the plugin**

In `wc-acs-courier.php`: add `'includes/class-acs-points-order.php',` after the picker line in BOTH file arrays, and `WC_ACS_Points_Order::instance();` after `WC_ACS_Points_Picker::instance();`.

- [ ] **Step 7: Lint, syntax-check, test, commit**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && export MSYS_NO_PATHCONV=1 && docker run --rm -v "D:/Documents/Projects/POOQ/wc-acs-courier:/app" php:8.3-cli sh -c 'php -l /app/includes/class-acs-points-order.php && php -l /app/wc-acs-courier.php' && node --check assets/js/acs-admin.js && echo OK
```

Full suite `OK (139 tests, ...)`.

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && git add -A && git commit -q -m "feat(points): order screen box with change control, customer views, email line

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 12: Greek translations, docs, version 1.1.0

**Files:**
- Modify: `languages/wc-acs-courier.pot`, `languages/wc-acs-courier-el.po`, `languages/wc-acs-courier-el.mo`
- Modify: `wc-acs-courier.php` (version), `readme.txt` (stable tag, description, changelog, FAQ), `README.md` (features, meta table), `docs/testing-checklist-ui.md`, `docs/superpowers/specs/2026-03-16-smartpoints-blocks-design.md` (status line)

**Interfaces:** none; this task finishes the plugin release.

- [ ] **Step 1: Regenerate the POT and merge the Greek PO**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && export MSYS_NO_PATHCONV=1 \
&& docker run --rm -v "D:/Documents/Projects/POOQ/wc-acs-courier:/app" -w /app wordpress:cli-php8.3 wp i18n make-pot . languages/wc-acs-courier.pot --exclude=vendor,tests,docs,assets/vendor --allow-root \
&& docker run --rm -v "D:/Documents/Projects/POOQ/wc-acs-courier/languages:/l" alpine sh -c "apk add -q gettext && msgmerge -U --backup=none /l/wc-acs-courier-el.po /l/wc-acs-courier.pot && msgattrib --untranslated /l/wc-acs-courier-el.po | grep -c '^msgid'"
```

Expected: a count of untranslated entries (about 60). Smartpoint entries become obsolete (`#~`) and may be deleted.

- [ ] **Step 2: Fill in the Greek translations**

Edit `languages/wc-acs-courier-el.po`. For each untranslated `msgid` below set the `msgstr` shown. Any other new untranslated strings (settings labels) get a sensible Greek rendering in the same style.

| msgid | msgstr |
|---|---|
| Pickup from ACS Point | Παραλαβή από ACS Point |
| ACS Points | ACS Points |
| Pickup from an ACS Smartpoint locker or an ACS store, chosen on a map at checkout. | Παραλαβή από ACS Smartpoint locker ή κατάστημα ACS, με επιλογή σε χάρτη στο checkout. |
| Choose ACS Point | Επιλογή σημείου ACS |
| Choose the ACS Point that suits you | Επιλέξτε το ACS Point που σας εξυπηρετεί |
| Search by area, street or postcode | Αναζήτηση περιοχής, οδού ή Τ.Κ. |
| All | Όλα |
| Lockers | Lockers |
| Stores | Καταστήματα |
| Locker | Locker |
| Store | Κατάστημα |
| My location | Η θέση μου |
| Select | Επιλογή |
| Change | Αλλαγή |
| Close | Κλείσιμο |
| Loading points... | Φόρτωση σημείων... |
| Could not load the ACS points. Please try again. | Δεν ήταν δυνατή η φόρτωση των σημείων ACS. Δοκιμάστε ξανά. |
| Move the map to see more points | Μετακινήστε τον χάρτη για περισσότερα σημεία |
| 24/7 | 24ωρο |
| Cash on delivery available | Με αντικαταβολή |
| No cash on delivery | Χωρίς αντικαταβολή |
| Weekdays | Καθημερινές |
| Saturday | Σάββατο |
| Your location is not available. | Η θέση σας δεν είναι διαθέσιμη. |
| Please choose an ACS Point before placing your order. | Επιλέξτε ένα σημείο ACS πριν ολοκληρώσετε την παραγγελία. |
| Please choose an ACS locker. | Επιλέξτε ένα ACS locker. |
| Cash on delivery is not available at this ACS Point. Choose another point or pay by card. | Η αντικαταβολή δεν είναι διαθέσιμη σε αυτό το σημείο ACS. Επιλέξτε άλλο σημείο ή πληρώστε με κάρτα. |
| ACS sends the pickup PIN by SMS, so a Greek mobile number (69xxxxxxxx) is required. | Η ACS στέλνει το PIN παραλαβής με SMS, γι' αυτό χρειάζεται ελληνικό κινητό (69xxxxxxxx). |
| Unknown ACS Point. | Άγνωστο σημείο ACS. |
| Selected ACS Point id. | Αναγνωριστικό επιλεγμένου σημείου ACS. |
| ACS Point | Σημείο ACS |
| Pickup point | Σημείο παραλαβής |
| Pickup from: | Παραλαβή από: |
| View on map | Δείτε στον χάρτη |
| Change point: type an area, street or postcode | Αλλαγή σημείου: πληκτρολογήστε περιοχή, οδό ή Τ.Κ. |
| Delete the voucher to change the point. | Διαγράψτε το voucher για να αλλάξετε σημείο. |
| ACS Point changed to: %s | Το σημείο ACS άλλαξε σε: %s |
| ACS Points accept one parcel per shipment. | Τα ACS Points δέχονται ένα δέμα ανά αποστολή. |
| ACS Points need a Greek mobile number. | Τα ACS Points απαιτούν ελληνικό κινητό. |
| ACS voucher skipped: %s | Το voucher ACS παραλείφθηκε: %s |
| ACS returned only %d points; keeping the stored list. | Η ACS επέστρεψε μόνο %d σημεία. Διατηρείται η αποθηκευμένη λίστα. |
| ACS points: %1$s, updated %2$s | Σημεία ACS: %1$s, ενημερώθηκαν %2$s |
| Points have never been fetched. | Τα σημεία δεν έχουν ληφθεί ακόμη. |
| Refresh points | Ανανέωση σημείων |
| Refreshing... | Ανανέωση... |
| Lockers and stores offered by the "Pickup from ACS Point" shipping method. Refreshed automatically once a day. | Lockers και καταστήματα που προσφέρει η μέθοδος αποστολής "Παραλαβή από ACS Point". Ανανεώνονται αυτόματα μία φορά την ημέρα. |
| Enable this shipping method | Ενεργοποίηση αυτής της μεθόδου αποστολής |
| Flat cost for pickup at an ACS Point. | Σταθερό κόστος για παραλαβή από σημείο ACS. |
| Order subtotal at or above which pickup is free. Leave empty to disable. | Υποσύνολο παραγγελίας από το οποίο η παραλαβή είναι δωρεάν. Κενό για απενεργοποίηση. |
| Max Weight (kg) | Μέγιστο βάρος (kg) |
| The rate is not offered above this package weight. ACS standard lockers take up to 6 kg. 0 disables the cap. | Η μέθοδος δεν προσφέρεται πάνω από αυτό το βάρος. Τα τυπικά lockers ACS δέχονται έως 6 kg. Το 0 αφαιρεί το όριο. |
| Point Types | Τύποι σημείων |
| Which ACS Points the customer may choose. | Ποια σημεία ACS μπορεί να επιλέξει ο πελάτης. |
| Lockers and stores | Lockers και καταστήματα |
| Lockers only | Μόνο lockers |

- [ ] **Step 3: Compile the MO and check it**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && export MSYS_NO_PATHCONV=1 && docker run --rm -v "D:/Documents/Projects/POOQ/wc-acs-courier/languages:/l" alpine sh -c "apk add -q gettext && msgfmt --check -o /l/wc-acs-courier-el.mo /l/wc-acs-courier-el.po && msgattrib --untranslated /l/wc-acs-courier-el.po | grep -c '^msgid' || true"
```

Expected: `msgfmt` silent (no errors), untranslated count `0` (the `|| true` keeps grep's exit code from failing the command when the count is zero).

- [ ] **Step 4: Version and docs**

- `wc-acs-courier.php`: `Version: 1.1.0` in the header and `define( 'WC_ACS_VERSION', '1.1.0' )`.
- `readme.txt`: `Stable tag: 1.1.0`; in the short description replace `support ACS Smartpoints pickup locations` with `offer pickup from ACS Points (lockers and stores) on a map`; rename the `= ACS Smartpoints =` section to `= ACS Points =` with these bullets:

```
* "Pickup from ACS Point" shipping method for your zones, with cost, free-delivery threshold and a weight cap
* Map picker at checkout (OpenStreetMap, no API key): every ACS Smartpoint locker and ACS store, searchable, clustered, with opening hours and card-on-collection badges
* Vouchers routed to the chosen point through the ACS station codes
* Cash on delivery offered only at points with a card terminal
* Change the point from the order screen until a voucher exists
* Point shown on the thank-you page, in My Account and in order emails
```

  Replace the FAQ answer under `= Can customers pick up from ACS Smartpoints? =` (rename the question to `= Can customers pick up from an ACS locker or store? =`) with: `Yes. Add "Pickup from ACS Point" to a shipping zone. Customers choose a point on a map at checkout, and the voucher is routed to it automatically.` Replace the screenshot line `4. Smartpoint pickup selector at checkout` with `4. ACS Points map picker at checkout`. Add at the top of `== Changelog ==`:

```
= 1.1.0 =
* New: "Pickup from ACS Point" shipping method with a map picker (lockers and stores)
* New: daily ACS points feed, admin refresh button, REST route for the map
* New: change the point from the order screen until a voucher exists; point in emails and My Account
* Fix: vouchers to a point now use the ACS station codes (the old Smartpoint checkbox sent a home delivery to the shop's street)
* Removed: the Smartpoints checkbox under the ACS Courier rate
```

- `README.md`: replace the `### ACS Smartpoints` section with `### ACS Points` and the same six bullets; in the order-meta table replace the three `_acs_smartpoint_*` rows with the seven `_acs_point_*` keys (id, type, name, address, station, branch, cod) and one-line descriptions.
- `docs/testing-checklist-ui.md`: append:

```
## ACS Points

- [ ] Zone has "Pickup from ACS Point"; the rate shows with the ACS logo and price
- [ ] "Choose ACS Point" opens the full-screen map; tiles and clustered markers render
- [ ] Map opens centred near the typed postcode; Greece overview with an empty postcode
- [ ] Search filters the list by name, street, city and postcode, accent-insensitive
- [ ] Lockers / Stores chips filter both the list and the markers
- [ ] Clicking a list row opens the marker popup; "Select" closes the overlay and the summary shows name, address, 24/7 and COD badges
- [ ] "Change" reopens the map; Escape and the close button close it
- [ ] Phone: the list is a bottom sheet with a drag handle; selecting a row collapses it
- [ ] "My location" asks for permission and pans the map; denying shows the error text
- [ ] Placing the order without a point shows the "choose an ACS Point" error
- [ ] A landline in the phone field shows the Greek-mobile error
- [ ] A locker without a terminal hides cash on delivery; a store shows it
- [ ] Order meta has the seven _acs_point_* keys; the admin box shows the point and the change control
- [ ] Changing the point writes a note; after a voucher exists the control is gone
- [ ] Thank-you page, My Account and the order emails show "Pickup from: ..."
- [ ] Voucher for a point order: Item_Quantity 1, station codes in the payload (debug log), no REC
```

- `docs/superpowers/specs/2026-03-16-smartpoints-blocks-design.md`: change `**Status:** Approved` to `**Status:** Superseded by 2026-09-09-acs-points-design.md (never implemented)`.

- [ ] **Step 5: Build, full suite, commit**

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && bash build.sh && unzip -l wc-acs-courier.zip | grep -E "languages/wc-acs-courier-el.mo|assets/js/acs-points.js|includes/class-acs-points-order.php" && rm -f wc-acs-courier.zip
```

Expected: the three files listed. Full unit suite `OK (139 tests, ...)`.

```bash
cd /d/Documents/Projects/POOQ/wc-acs-courier && git add -A && git add -f docs/testing-checklist-ui.md docs/superpowers/specs/2026-03-16-smartpoints-blocks-design.md && git commit -q -m "chore(release): 1.1.0, Greek strings for ACS Points, docs

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 13: Site integration files for pooq.gr (woo-importer repo)

**Files (all under `D:\Documents\Projects\POOQ\woo-importer`):**
- Create: `data/site-backups/2026-09-acs-points/README.md`, `data/site-backups/2026-09-acs-points/stage.sh`
- Modify: `data/site-backups/2026-09-04-checkout/pooq-checkout/assets/css/pooq-checkout.css` (after the `.wc-boxnow-selected` rule, around line 388), `data/site-backups/2026-09-04-checkout/pooq-checkout/pooq-checkout.php:31` (version)
- Modify: `data/site-backups/mu-plugins/pooq-consent/config.php` (cookie table)
- Modify: `data/site-backups/mu-plugins/10-pooq-email-text.php` (completed email)

**Interfaces:**
- Consumes the picker markup class `.wc-acs-points-picker` (Task 7) and the meta keys `_acs_point_name`, `_acs_point_address` (Task 6).
- Spec deviation, on purpose: the spec said to bump `POOQ_CONSENT_POLICY_VERSION`. The config file's own rule bumps it only when a category or the first-layer text changes; a new cookie-table row is neither, and a bump would re-prompt every visitor. Leave it at 1.

- [ ] **Step 1: Checkout CSS**

In `pooq-checkout.css`, after the `body.pooq-co .pooq-shipping-methods li .wc-boxnow-selected { ... }` rule add:

```css
/* ACS Points picker (wc-acs-courier 1.1): same indented row as the BOX NOW picker. */
body.pooq-co .pooq-shipping-methods li .wc-acs-points-picker {
  flex: 1 1 100%;
  margin: 6px 0 2px 28px;
}

body.pooq-co .pooq-shipping-methods li .wc-acs-points-selected {
  color: var(--co-muted);
}
```

In `pooq-checkout.php` change `define( 'POOQ_CHECKOUT_VER', '1.2.3' );` to `'1.2.4'`.

- [ ] **Step 2: Consent inventory row**

In `pooq-consent/config.php`, after the `woocommerce_recently_viewed` row add:

```php
		array( 'name' => '(χωρίς cookie) tile.openstreetmap.org', 'provider' => 'OpenStreetMap Foundation', 'category' => 'necessary', 'purpose' => 'Χάρτης σημείων παραλαβής ACS. Φορτώνεται μόνο όταν ανοίξετε τον χάρτη στο checkout και δεν θέτει cookies.', 'lifetime' => 'Καμία αποθήκευση' ),
```

Do not change `POOQ_CONSENT_POLICY_VERSION` or `POOQ_CONSENT_VER` (no banner text or asset changed).

- [ ] **Step 3: Shipped email line**

In `10-pooq-email-text.php`, after the completed-email `add_action( 'woocommerce_email_order_details', ..., 5, 4 );` block add:

```php
/** Completed email: where an ACS Point order is waiting. Printed right after the voucher block. */
add_action(
	'woocommerce_email_order_details',
	static function ( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin || ! $email || 'customer_completed_order' !== $email->id ) { return; }
		$name = trim( (string) $order->get_meta( '_acs_point_name' ) );
		if ( '' === $name ) { return; }
		$address = trim( (string) $order->get_meta( '_acs_point_address' ) );
		$line    = 'Παραλαβή από: ' . $name . ( '' !== $address ? ', ' . $address : '' );
		$note    = 'Θα λάβετε SMS από την ACS με το PIN παραλαβής μόλις φτάσει το δέμα στο σημείο.';
		if ( $plain_text ) {
			echo "\n" . $line . "\n" . $note . "\n\n";
			return;
		}
		echo '<p style="margin:0 0 6px"><strong>' . esc_html( $line ) . '</strong></p>'
			. '<p style="margin:0 0 24px;color:#787c82">' . esc_html( $note ) . '</p>';
	},
	6,
	4
);
```

- [ ] **Step 4: Lint the three PHP files**

```bash
cd /d/Documents/Projects/POOQ/woo-importer && export MSYS_NO_PATHCONV=1 && docker run --rm -v "D:/Documents/Projects/POOQ/woo-importer/data/site-backups:/s" php:8.3-cli sh -c 'php -l /s/mu-plugins/10-pooq-email-text.php && php -l /s/mu-plugins/pooq-consent/config.php && php -l /s/2026-09-04-checkout/pooq-checkout/pooq-checkout.php'
```

Expected: three `No syntax errors detected`.

- [ ] **Step 5: Staging script**

Create `data/site-backups/2026-09-acs-points/stage.sh`:

```bash
#!/usr/bin/env bash
# Build wc-acs-courier and install it on the local Docker staging (D:\pooq-staging), plus the
# three site files this feature touches. Live deploy is manual: see README.md.
set -euo pipefail; export MSYS_NO_PATHCONV=1
PLUGIN=/d/Documents/Projects/POOQ/wc-acs-courier
HERE="$(cd "$(dirname "$0")" && pwd)"
SB="$HERE/.."

( cd "$PLUGIN" && bash build.sh >/dev/null )
docker cp "$PLUGIN/wc-acs-courier.zip" pooq-staging-wp-1:/var/www/html/wc-acs-courier.zip
( cd /d/pooq-staging && docker compose run --rm -T cli wp plugin install /var/www/html/wc-acs-courier.zip --force 2>&1 | tail -2 )
docker exec pooq-staging-wp-1 rm -f /var/www/html/wc-acs-courier.zip

for f in mu-plugins/10-pooq-email-text.php mu-plugins/pooq-consent/config.php; do
  docker cp "$SB/$f" "pooq-staging-wp-1:/tmp/$(basename "$f")"
  docker exec pooq-staging-wp-1 sh -c "php -l /tmp/$(basename "$f") && cp /tmp/$(basename "$f") /var/www/html/wp-content/$f && chown www-data:www-data /var/www/html/wp-content/$f"
done
docker cp "$SB/2026-09-04-checkout/pooq-checkout/." pooq-staging-wp-1:/var/www/html/wp-content/plugins/pooq-checkout/
docker exec pooq-staging-wp-1 chown -R www-data:www-data /var/www/html/wp-content/plugins/pooq-checkout

( cd /d/pooq-staging && docker compose run --rm -T cli wp cache flush 2>&1 | tail -1 )
( cd /d/pooq-staging && docker compose run --rm -T cli wp cron event run wc_acs_points_cron 2>&1 | tail -1 )
curl -s -o /dev/null -w 'staging home %{http_code}\n' "http://localhost:8080/" || true
```

- [ ] **Step 6: Folder README**

Create `data/site-backups/2026-09-acs-points/README.md`:

```markdown
# ACS Points: locker and store pickup with a map (wc-acs-courier 1.1.0)

Plugin source: `D:\Documents\Projects\POOQ\wc-acs-courier`, branch `feat/acs-points` (merge to master before
the live deploy and record the commit here). Spec: `docs/superpowers/specs/2026-09-09-acs-points-design.md`
in that repo. This folder holds the site-side pieces and the runbook.

## Site files changed (source of truth here, mirrored to live by hand)

| File | Change |
|---|---|
| `2026-09-04-checkout/pooq-checkout/assets/css/pooq-checkout.css` + `pooq-checkout.php` 1.2.4 | `.wc-acs-points-picker` gets the same indented row as the BOX NOW picker |
| `mu-plugins/pooq-consent/config.php` | cookie-table row for `tile.openstreetmap.org` (no cookies, loaded only when the map opens). Policy version NOT bumped: no category or banner text changed |
| `mu-plugins/10-pooq-email-text.php` | Completed email prints "Παραλαβή από: <point>" and the PIN-by-SMS note under the voucher block |

## Staging first

`bash stage.sh` builds the plugin zip, installs it on `D:\pooq-staging` (http://localhost:8080), copies
the three site files, flushes the object cache and runs the points cron once. Then, in staging wp-admin:
WooCommerce > Αποστολή > Ελλάδα > add "Pickup from ACS Point", cost as the owner decides. Walk
`docs/testing-checklist-ui.md` > "ACS Points" in the plugin repo on desktop and phone.

## Live deploy (owner go-ahead required; see SERVER-AND-DEPLOY.md for the connection)

1. Backups: `sudo -u pooq wp option get wc_acs_points_feed --format=json > /root/pooq-backups/acs-points-<date>/feed.json` (may be empty),
   `cp -a /var/www/pooq/wp-content/plugins/wc-acs-courier /root/pooq-backups/acs-points-<date>/wc-acs-courier.before`,
   and copies of the three site files from the server.
2. Plugin: upload the zip, `sudo -u pooq wp plugin install wc-acs-courier.zip --force`, then
   `sudo -u pooq wp cron event run wc_acs_points_cron` and confirm "ACS points: 2,0xx" on the settings page.
3. Site files: `10-pooq-email-text.php` and `pooq-consent/config.php` via the mu-plugins pattern (subfolder
   copy first, then root); `pooq-checkout` via FTP/scp; `php -l` each before it lands.
4. Zone: WooCommerce > Αποστολή > Ελλάδα > add `acs_points`, title "Παραλαβή από ACS Point", the owner's price.
   The 05-pooq-shipping rate rule zeroes it above 50 € like every courier rate.
5. Purge: LiteSpeed all pages + object cache.
6. Checks: logged-out checkout with a real cart; pick a locker; place a small COD order to a locker with a
   terminal; open the order; "Create Voucher"; inspect the PDF (does the label carry the customer's address
   or the point's?); delete the voucher; record the answer in the spec (Component 5, open check); cancel and
   delete the test order (stock first).

## Rollback

`sudo -u pooq wp plugin install /root/pooq-backups/acs-points-<date>/wc-acs-courier.before` is not a zip; instead
`mv` the directory back into `wp-content/plugins/`, remove `acs_points` from the zone, restore the three site
files from the backup copies, purge. Orders already carrying `_acs_point_*` meta keep it (harmless).
```

- [ ] **Step 7: Commit in woo-importer**

```bash
cd /d/Documents/Projects/POOQ/woo-importer && git add data/site-backups/2026-09-acs-points data/site-backups/2026-09-04-checkout/pooq-checkout/assets/css/pooq-checkout.css data/site-backups/2026-09-04-checkout/pooq-checkout/pooq-checkout.php data/site-backups/mu-plugins/pooq-consent/config.php data/site-backups/mu-plugins/10-pooq-email-text.php && git commit -q -m "feat(site): ACS Points site pieces (checkout row, consent inventory, shipped email, staging script, runbook)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

Note: the woo-importer working tree already has unrelated modified files from the newsletter work; the explicit paths above keep them out of this commit.

---

### Task 14: Staging walk, live deploy, voucher check (manual, owner-gated)

**Files:** none created. Updates `docs/superpowers/specs/2026-09-09-acs-points-design.md` (Component 5 open check) and `data/site-backups/2026-09-acs-points/README.md` (commit hash, date, outcome).

- [ ] **Step 1: Stage**

Run `bash data/site-backups/2026-09-acs-points/stage.sh` from the woo-importer root (Docker Desktop must be running). Add the zone method in staging wp-admin. Open `http://localhost:8080/checkout/` with an item in the cart in the Browser pane.

- [ ] **Step 2: Walk the checklist**

Go through every line of `docs/testing-checklist-ui.md` > "ACS Points" on desktop, then with the Browser pane at the mobile preset (reload after switching). Fix anything that fails in the plugin repo, re-run `stage.sh`, re-check. Staging sends no mail and makes no courier calls (`zz-staging.php`), so the email line is checked by previewing the Completed email through WooCommerce > Settings > Emails > Completed order > preview, and the voucher is NOT created on staging.

- [ ] **Step 3: Merge**

In the plugin repo: full unit suite green, then `git checkout master && git merge --no-ff feat/acs-points -m "Merge feat/acs-points: ACS Points pickup with map picker"` and note the merge hash in the site folder README. Push if the remote exists.

- [ ] **Step 4: Live deploy**

Only after the owner says go. Follow the README's "Live deploy" list exactly, in order, with the backups first.

- [ ] **Step 5: Close the open check**

After the first live voucher PDF is inspected and the voucher deleted, edit the spec's Component 5 "Open check" paragraph to record what the label showed and whether the builder was changed. If ACS expects the point's address on the label, change `build_voucher_params()` so a point order sets `Recipient_Address` from `_acs_point_address` (street part) and `Recipient_Zipcode` from the point's zip, add a test mirroring `test_build_params_point_order_keeps_customer_address_and_no_rec()` with the new expectation, and release 1.1.1.

---

## Self-review notes

- Spec coverage: feed (Tasks 2 to 4), shipping method (5), picker rules and wiring (6, 7), assets and map (8, 9), voucher (10), admin and customer views (11), translations and release (12), site integration (13), staging and live checks (14). The spec's "Cyprus filtered by store country" is in `normalise()`; the weight cap and `point_types` are in Task 5 and honoured by Task 6's validation and Task 9's client filter.
- Deliberate deviations from the spec, both stated in the task text: a fourth class file for order views (Task 11), and no consent policy-version bump (Task 13).
- Names used across tasks: `WC_ACS_Points_Feed::{normalise, store_country, centre_for_postcode, refresh, get_points, fetched_at, count, find, payload, rest_points, ajax_refresh, last_error, REST_NAMESPACE}`, `WC_ACS_Points_Picker::{normalise_mobile, methods_include_points, instance_point_types, order_has_points, validate, format_address, apply_point_to_order, get_selected_point_id, get_selected_point, ajax_set_point, render_picker, enqueue_assets, script_settings, validate_classic_checkout, save_classic_checkout, filter_payment_gateways, register_store_api, save_from_store_api}`, `WC_ACS_Points_Order::{can_change_point, summary_line, maps_url, fold, render_admin_box, render_customer_details, render_email_meta, ajax_search_points, ajax_set_order_point}`, `WC_ACS_Points_Shipping_Method::package_weight_kg`, `WC_ACS_API::get_points_feed`.
