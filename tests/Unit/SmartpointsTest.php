<?php
namespace WC_ACS_Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

class SmartpointsTest extends TestCase {

    private $smartpoints;

    protected function setUp(): void {
        parent::setUp();
        $this->resetStaticProperty( \WC_ACS_Smartpoints::class, 'instance' );
        $this->smartpoints = \WC_ACS_Smartpoints::instance();
    }

    protected function tearDown(): void {
        $this->resetStaticProperty( \WC_ACS_Smartpoints::class, 'instance' );
        parent::tearDown();
    }

    private function makeRawPoint( array $overrides = [] ): array {
        return array_merge( [
            'ACS_SHOP_ID_CODE'                  => 'SP001',
            'ACS_SHOP_STATION_DESCR'            => 'Athens Center',
            'ACS_SHOP_ADDRESS'                  => 'Ermou 10',
            'ACS_SHOP_ZIPCODE'                  => '10563',
            'ACS_SHOP_PHONES'                   => '2101111111',
            'ACS_SHOP_WORKING_HOURS'            => '09:00-20:00',
            'ACS_SHOP_WORKING_HOURS_SATURDAY'   => '09:00-14:00',
            'ACS_SHOP_LAT'                      => '37.975',
            'ACS_SHOP_LONG'                     => '23.735',
            '_type'                             => 'locker',
            'ACS_SHOP_KIND'                     => 7,
        ], $overrides );
    }

    public function test_format_smartpoint_maps_fields(): void {
        $this->stubGetOption();

        $point_data = $this->makeRawPoint();

        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'get_transient' )->justReturn( [ $point_data ] );
        Functions\when( 'set_transient' )->justReturn( true );

        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$captured ) {
            $captured = $data;
        } );

        $_POST['search']  = '';
        $_POST['country'] = 'GR';

        $this->smartpoints->ajax_get_smartpoints();

        $this->assertNotNull( $captured );
        $this->assertCount( 1, $captured['points'] );

        $p = $captured['points'][0];
        $this->assertSame( 'SP001', $p['id'] );
        $this->assertSame( 'Athens Center', $p['name'] );
        $this->assertSame( 'Ermou 10', $p['address'] );
        $this->assertSame( '10563', $p['zipcode'] );
        $this->assertSame( 'locker', $p['type'] );
    }

    public function test_filter_by_search_case_insensitive(): void {
        $this->stubGetOption();

        $points = [
            $this->makeRawPoint( [ 'ACS_SHOP_STATION_DESCR' => 'Athens Center', 'ACS_SHOP_ZIPCODE' => '10563' ] ),
            $this->makeRawPoint( [ 'ACS_SHOP_STATION_DESCR' => 'Thessaloniki', 'ACS_SHOP_ZIPCODE' => '54624' ] ),
            $this->makeRawPoint( [ 'ACS_SHOP_STATION_DESCR' => 'Patras', 'ACS_SHOP_ZIPCODE' => '26221' ] ),
        ];

        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'get_transient' )->justReturn( $points );
        Functions\when( 'set_transient' )->justReturn( true );

        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$captured ) {
            $captured = $data;
        } );

        $_POST['search']  = 'ATHENS';
        $_POST['country'] = 'GR';

        $this->smartpoints->ajax_get_smartpoints();

        $this->assertCount( 1, $captured['points'] );
        $this->assertSame( 'Athens Center', $captured['points'][0]['name'] );
    }

    public function test_results_limited_to_30(): void {
        $this->stubGetOption();

        $points = [];
        for ( $i = 0; $i < 50; $i++ ) {
            $points[] = $this->makeRawPoint( [ 'ACS_SHOP_ID_CODE' => "SP{$i}" ] );
        }

        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'get_transient' )->justReturn( $points );
        Functions\when( 'set_transient' )->justReturn( true );

        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$captured ) {
            $captured = $data;
        } );

        $_POST['search']  = '';
        $_POST['country'] = 'GR';

        $this->smartpoints->ajax_get_smartpoints();

        $this->assertCount( 30, $captured['points'] );
    }

    public function test_empty_smartpoints_are_cached(): void {
        $this->stubGetOption( [
            'wc_acs_api_key'          => 'key',
            'wc_acs_company_id'       => 'C',
            'wc_acs_company_password' => 'cp',
            'wc_acs_user_id'          => 'U',
            'wc_acs_user_password'    => 'up',
            'wc_acs_debug_logging'    => 'no',
        ] );

        Functions\when( 'check_ajax_referer' )->justReturn( true );

        $transient_store = [];
        Functions\when( 'get_transient' )->alias( function ( $key ) use ( &$transient_store ) {
            return $transient_store[ $key ] ?? false;
        } );
        Functions\when( 'set_transient' )->alias( function ( $key, $value, $ttl ) use ( &$transient_store ) {
            $transient_store[ $key ] = $value;
            return true;
        } );

        Functions\when( 'wp_remote_post' )->justReturn( [
            'response' => [ 'code' => 200 ],
            'body'     => '',
        ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn(
            json_encode( [ 'ACSExecution_HasError' => false, 'ACSOutputResponce' => [] ] )
        );

        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$captured ) {
            $captured = $data;
        } );

        $_POST['search']  = '';
        $_POST['country'] = 'GR';

        $this->smartpoints->ajax_get_smartpoints();

        $this->assertArrayHasKey( 'acs_smartpoints_GR', $transient_store );
    }

    public function test_validate_missing_smartpoint_id(): void {
        $_POST['acs_use_smartpoint'] = '1';
        $_POST['acs_smartpoint_id']  = '';

        $notice_added = false;
        Functions\when( 'wc_add_notice' )->alias( function () use ( &$notice_added ) {
            $notice_added = true;
        } );

        $this->smartpoints->validate_smartpoint_selection();

        $this->assertTrue( $notice_added );
    }
}
