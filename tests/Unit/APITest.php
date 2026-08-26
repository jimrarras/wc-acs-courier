<?php
namespace WC_ACS_Tests\Unit;

use Brain\Monkey\Functions;

class APITest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetStaticProperty( \WC_ACS_API::class, 'credentials' );
    }

    protected function tearDown(): void {
        $this->resetStaticProperty( \WC_ACS_API::class, 'credentials' );
        parent::tearDown();
    }

    private function withOptions( array $extra = [] ): void {
        $defaults = [
            'wc_acs_api_key'          => 'test-api-key',
            'wc_acs_company_id'       => 'COMP1',
            'wc_acs_company_password' => 'cpass',
            'wc_acs_user_id'          => 'USER1',
            'wc_acs_user_password'    => 'upass',
            'wc_acs_debug_logging'    => 'no',
        ];
        $this->stubGetOption( array_merge( $defaults, $extra ) );
    }

    private function mockHttp( int $code, string $body ): void {
        $response = [ 'response' => [ 'code' => $code ], 'body' => $body ];
        Functions\when( 'wp_remote_post' )->justReturn( $response );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
    }

    public function test_request_no_api_key_returns_error(): void {
        $this->stubGetOption( [ 'wc_acs_api_key' => '' ] );
        $result = \WC_ACS_API::request( 'ACS_Test' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'acs_no_api_key', $result->get_error_code() );
    }

    public function test_request_http_403_returns_forbidden_error(): void {
        $this->withOptions();
        $this->mockHttp( 403, '' );
        $result = \WC_ACS_API::request( 'ACS_Test' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'acs_forbidden', $result->get_error_code() );
    }

    public function test_request_http_406_returns_rate_limit_error(): void {
        $this->withOptions();
        $this->mockHttp( 406, '' );
        $result = \WC_ACS_API::request( 'ACS_Test' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'acs_rate_limit', $result->get_error_code() );
    }

    public function test_request_invalid_json_returns_error(): void {
        $this->withOptions();
        $this->mockHttp( 200, 'not-json' );
        $result = \WC_ACS_API::request( 'ACS_Test' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'acs_invalid_response', $result->get_error_code() );
    }

    public function test_request_execution_error_returns_error(): void {
        $this->withOptions();
        $body = json_encode( [
            'ACSExecution_HasError'     => true,
            'ACSExecutionErrorMessage'  => 'Bad params',
        ] );
        $this->mockHttp( 200, $body );
        $result = \WC_ACS_API::request( 'ACS_Test' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'acs_api_error', $result->get_error_code() );
        $this->assertSame( 'Bad params', $result->get_error_message() );
    }

    public function test_request_extracts_output_responCe_typo(): void {
        $this->withOptions();
        $body = json_encode( [
            'ACSExecution_HasError' => false,
            'ACSOutputResponce'     => [ 'data' => 'typo-variant' ],
        ] );
        $this->mockHttp( 200, $body );
        $result = \WC_ACS_API::request( 'ACS_Test' );
        $this->assertSame( [ 'data' => 'typo-variant' ], $result );
    }

    public function test_request_extracts_output_response_correct(): void {
        $this->withOptions();
        $body = json_encode( [
            'ACSExecution_HasError' => false,
            'ACSOutputResponse'     => [ 'data' => 'correct' ],
        ] );
        $this->mockHttp( 200, $body );
        $result = \WC_ACS_API::request( 'ACS_Test' );
        $this->assertSame( [ 'data' => 'correct' ], $result );
    }

    public function test_request_merges_credentials_when_enabled(): void {
        $this->withOptions();
        $captured_body = null;
        Functions\when( 'wp_remote_post' )->alias(
            function ( $url, $args ) use ( &$captured_body ) {
                $captured_body = json_decode( $args['body'], true );
                return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
            }
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn(
            json_encode( [ 'ACSExecution_HasError' => false, 'ACSOutputResponse' => [] ] )
        );

        \WC_ACS_API::request( 'ACS_Test', [ 'Custom' => 'val' ], true );

        $this->assertArrayHasKey( 'ACSInputParameters', $captured_body );
        $params = $captured_body['ACSInputParameters'];
        $this->assertSame( 'COMP1', $params['Company_ID'] );
        $this->assertSame( 'val', $params['Custom'] );
    }

    public function test_request_skips_credentials_when_disabled(): void {
        $this->withOptions();
        $captured_body = null;
        Functions\when( 'wp_remote_post' )->alias(
            function ( $url, $args ) use ( &$captured_body ) {
                $captured_body = json_decode( $args['body'], true );
                return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
            }
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn(
            json_encode( [ 'ACSExecution_HasError' => false, 'ACSOutputResponse' => [] ] )
        );

        \WC_ACS_API::request( 'ACS_Test', [ 'Param' => '1' ], false );

        $params = $captured_body['ACSInputParameters'];
        $this->assertArrayNotHasKey( 'Company_ID', $params );
        $this->assertSame( '1', $params['Param'] );
    }

    public function test_create_voucher_default_pickup_date_is_site_local(): void {
        $this->withOptions( [
            'wc_acs_billing_code' => 'BC1',
            'wc_acs_sender_name'  => 'Shop',
        ] );

        // Store shortly after midnight local time: UTC date is still yesterday.
        Functions\when( 'current_time' )->alias( function ( $format ) {
            return ( new \DateTimeImmutable( '2030-01-15 01:30:00' ) )->format( $format );
        } );

        $captured_body = null;
        Functions\when( 'wp_remote_post' )->alias(
            function ( $url, $args ) use ( &$captured_body ) {
                $captured_body = json_decode( $args['body'], true );
                return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
            }
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn(
            json_encode( [ 'ACSExecution_HasError' => false, 'ACSOutputResponse' => [] ] )
        );

        \WC_ACS_API::create_voucher( [ 'Recipient_Name' => 'Test' ] );

        $this->assertSame( '2030-01-15', $captured_body['ACSInputParameters']['Pickup_Date'] );
    }

    public function test_request_wp_error_from_http(): void {
        $this->withOptions();
        Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_error', 'timeout' ) );
        $result = \WC_ACS_API::request( 'ACS_Test' );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_create_voucher_merges_defaults_and_settings(): void {
        $this->withOptions( [
            'wc_acs_billing_code' => 'BILL123',
            'wc_acs_sender_name'  => 'MyShop',
        ] );
        Functions\when( 'get_bloginfo' )->justReturn( 'Fallback Shop' );

        $captured_body = null;
        Functions\when( 'wp_remote_post' )->alias(
            function ( $url, $args ) use ( &$captured_body ) {
                $captured_body = json_decode( $args['body'], true );
                return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
            }
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn(
            json_encode( [ 'ACSExecution_HasError' => false, 'ACSOutputResponse' => [] ] )
        );

        \WC_ACS_API::create_voucher( [
            'Recipient_Name' => 'Test',
            'Weight'         => 2.0,
        ] );

        $params = $captured_body['ACSInputParameters'];
        $this->assertSame( 'BILL123', $params['Billing_Code'] );
        $this->assertSame( 'MyShop', $params['Sender'] );
        $this->assertEquals( 2.0, $params['Weight'] );
        $this->assertSame( 'GR', $params['Recipient_Country'] );
    }

    public function test_get_smartpoints_combines_lockers_and_points(): void {
        $this->withOptions();

        $call_count = 0;
        Functions\when( 'wp_remote_post' )->alias( function () use ( &$call_count ) {
            $call_count++;
            return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

        $locker_response = json_encode( [
            'ACSExecution_HasError' => false,
            'ACSOutputResponce'     => [
                'ACSTableOutput' => [ 'Table_Data' => [
                    [ 'ACS_SHOP_ID_CODE' => 'L1', 'ACS_SHOP_KIND' => 7 ],
                ] ],
            ],
        ] );
        $point_response = json_encode( [
            'ACSExecution_HasError' => false,
            'ACSOutputResponce'     => [
                'ACSTableOutput' => [ 'Table_Data' => [
                    [ 'ACS_SHOP_ID_CODE' => 'P1', 'ACS_SHOP_KIND' => 4 ],
                ] ],
            ],
        ] );

        $responses = [ $locker_response, $point_response ];
        $idx = 0;
        Functions\when( 'wp_remote_retrieve_body' )->alias( function () use ( &$idx, $responses ) {
            return $responses[ $idx++ ] ?? '{}';
        } );

        $result = \WC_ACS_API::get_smartpoints( 'GR' );

        $this->assertCount( 2, $result );
        $this->assertSame( 'locker', $result[0]['_type'] );
        $this->assertSame( 'point', $result[1]['_type'] );
    }

    public function test_get_smartpoints_handles_wp_error_gracefully(): void {
        $this->withOptions();
        Functions\when( 'wp_remote_post' )->justReturn(
            new \WP_Error( 'http_error', 'timeout' )
        );
        $result = \WC_ACS_API::get_smartpoints( 'GR' );
        $this->assertSame( [], $result );
    }

    public function test_test_connection_returns_true_on_success(): void {
        $this->withOptions();
        $body = json_encode( [
            'ACSExecution_HasError' => false,
            'ACSOutputResponce'     => [
                'ACSTableOutput' => [ 'Table_Data' => [
                    [ 'Station_ID' => 'ΑΘ' ],
                ] ],
            ],
        ] );
        $this->mockHttp( 200, $body );
        $result = \WC_ACS_API::test_connection();
        $this->assertTrue( $result );
    }

    public function test_reset_credentials_clears_cache(): void {
        $this->withOptions( [ 'wc_acs_company_id' => 'OLD' ] );
        $creds = \WC_ACS_API::get_credentials();
        $this->assertSame( 'OLD', $creds['Company_ID'] );

        \WC_ACS_API::reset_credentials();
        $this->resetStaticProperty( \WC_ACS_API::class, 'credentials' );
        $this->withOptions( [ 'wc_acs_company_id' => 'NEW' ] );
        $creds = \WC_ACS_API::get_credentials();
        $this->assertSame( 'NEW', $creds['Company_ID'] );
    }

    public function test_api_url_constant_has_correct_default(): void {
        $this->assertSame(
            'https://webservices.acscourier.net/ACSRestServices/api/ACSAutoRest',
            \WC_ACS_API::API_URL
        );
    }

    public function test_get_station_for_zipcode_extracts_id(): void {
        $this->withOptions();
        $body = json_encode( [
            'ACSExecution_HasError' => false,
            'ACSOutputResponce'     => [
                'ACSTableOutput' => [ 'Table_Data' => [
                    [ 'Station_ID' => 'ΘΕΣ' ],
                ] ],
            ],
        ] );
        $this->mockHttp( 200, $body );
        $this->assertSame( 'ΘΕΣ', \WC_ACS_API::get_station_for_zipcode( '54624' ) );
    }
}
