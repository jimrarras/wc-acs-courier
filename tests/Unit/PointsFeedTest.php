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
}
