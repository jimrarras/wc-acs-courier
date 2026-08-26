<?php
namespace WC_ACS_Tests\Integration;

use Brain\Monkey\Functions;

class APIIntegrationTest extends IntegrationTestCase {

    // ── Connection Tests ─────────────────────────────────────────

    public function test_connection_succeeds(): void {
        $result = \WC_ACS_API::test_connection();
        $this->assertTrue( $result, 'test_connection() should return true with valid credentials' );
    }

    public function test_connection_bad_key_returns_error(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( 'wc_acs_api_key' === $key ) {
                return 'invalid-key-00000';
            }
            $map = [
                'wc_acs_company_id'       => getenv( 'ACS_COMPANY_ID' ),
                'wc_acs_company_password' => getenv( 'ACS_COMPANY_PASSWORD' ),
                'wc_acs_user_id'          => getenv( 'ACS_USER_ID' ),
                'wc_acs_user_password'    => getenv( 'ACS_USER_PASSWORD' ),
                'wc_acs_debug_logging'    => 'no',
                'wc_acs_billing_code'     => '',
                'wc_acs_sender_name'      => 'Test',
                'wc_acs_station_origin'   => '',
            ];
            return $map[ $key ] ?? $default;
        } );

        $ref = new \ReflectionProperty( \WC_ACS_API::class, 'credentials' );
        $ref->setAccessible( true );
        $ref->setValue( null, null );

        $result = \WC_ACS_API::test_connection();
        $this->assertInstanceOf( \WP_Error::class, $result, 'Bad API key should return WP_Error' );
    }

    // ── Address & Station Lookup Tests ───────────────────────────

    public function test_find_by_zipcode(): void {
        $result = \WC_ACS_API::find_by_zipcode( '10431', 0, 'GR' );

        $this->assertIsArray( $result, 'find_by_zipcode should return an array' );
        $this->assertArrayHasKey( 'ACSTableOutput', $result );
        $this->assertNotEmpty(
            $result['ACSTableOutput']['Table_Data'],
            'Athens zipcode 10431 should have station data'
        );
    }

    public function test_get_station_for_zipcode(): void {
        $station = \WC_ACS_API::get_station_for_zipcode( '10431', 'GR' );

        $this->assertNotNull( $station, 'Should return a station ID for Athens zipcode' );
        $this->assertIsString( $station );
    }

    public function test_get_stations(): void {
        $result = \WC_ACS_API::get_stations( 'GR', 1 );

        $this->assertIsArray( $result, 'get_stations should return an array' );
        $this->assertArrayHasKey( 'ACSTableOutput', $result );

        $stations = $result['ACSTableOutput']['Table_Data'] ?? [];
        $this->assertNotEmpty( $stations, 'Greece should have ACS stations' );
        $this->assertArrayHasKey( 'ACS_SHOP_ID_CODE', $stations[0] );
    }

    // ── Smartpoints & Pricing Tests ──────────────────────────────

    public function test_get_smartpoints(): void {
        $result = \WC_ACS_API::get_smartpoints( 'GR' );

        $this->assertIsArray( $result, 'get_smartpoints should return an array' );
        $this->assertNotEmpty( $result, 'Greece should have smartpoints' );

        $types = array_unique( array_column( $result, '_type' ) );
        $this->assertNotEmpty( $types, 'Each smartpoint should have a _type field' );
    }

    public function test_price_calculation(): void {
        $result = \WC_ACS_API::price_calculation( [
            'Acs_Station_Destination' => '1',
            'Weight'                  => '1',
        ] );

        $this->assertIsArray( $result, 'price_calculation should return an array' );
        $this->assertArrayHasKey( 'ACSTableOutput', $result );
    }

    // ── Voucher Lifecycle Tests (chained via @depends) ───────────

    public function test_create_voucher(): string {
        $result = \WC_ACS_API::create_voucher( [
            'Recipient_Name'    => 'Integration Test Recipient',
            'Recipient_Address' => 'Ermou 25',
            'Recipient_Zipcode' => '10563',
            'Recipient_Region'  => 'Athens',
            'Recipient_Phone'   => '2101234567',
            'Weight'            => 0.5,
        ] );

        $this->assertIsArray( $result, 'create_voucher should return an array' );

        $voucher_no = null;
        if ( isset( $result['ACSValueOutput'][0]['Voucher_No'] ) ) {
            $voucher_no = $result['ACSValueOutput'][0]['Voucher_No'];
        } elseif ( isset( $result['ACSTableOutput']['Table_Data'][0]['Voucher_No'] ) ) {
            $voucher_no = $result['ACSTableOutput']['Table_Data'][0]['Voucher_No'];
        } elseif ( isset( $result['Voucher_No'] ) ) {
            $voucher_no = $result['Voucher_No'];
        }

        $this->assertNotEmpty( $voucher_no, 'Should receive a Voucher_No from the API' );

        self::$created_vouchers[] = $voucher_no;

        return $voucher_no;
    }

    /**
     * @depends test_create_voucher
     */
    public function test_print_voucher( string $voucher_no ): string {
        $result = \WC_ACS_API::print_voucher( $voucher_no );

        $this->assertIsArray( $result, 'print_voucher should return an array' );

        return $voucher_no;
    }

    /**
     * @depends test_print_voucher
     */
    public function test_tracking_summary( string $voucher_no ): string {
        $result = \WC_ACS_API::tracking_summary( $voucher_no );

        $this->assertNotInstanceOf(
            \WP_Error::class,
            $result,
            'tracking_summary should not return WP_Error for a valid voucher'
        );

        return $voucher_no;
    }

    /**
     * @depends test_tracking_summary
     */
    public function test_delete_voucher( string $voucher_no ): void {
        $result = \WC_ACS_API::delete_voucher( $voucher_no );

        $this->assertNotInstanceOf(
            \WP_Error::class,
            $result,
            'delete_voucher should succeed for a voucher we just created'
        );

        self::$created_vouchers = array_diff( self::$created_vouchers, [ $voucher_no ] );
    }

    // ── Pickup List Test ─────────────────────────────────────────

    public function test_issue_pickup_list(): void {
        $result = \WC_ACS_API::issue_pickup_list();

        $this->assertNotInstanceOf(
            \WP_Error::class,
            $result,
            'issue_pickup_list should communicate with the API without HTTP errors'
        );
    }
}
