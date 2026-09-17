<?php
namespace WC_ACS_Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

class ShippingMethodTest extends TestCase {

    private \WC_ACS_Shipping_Method $method;

    protected function setUp(): void {
        parent::setUp();
        $this->resetStaticProperty( \WC_ACS_API::class, 'credentials' );
        $this->method = new \WC_ACS_Shipping_Method();
    }

    protected function tearDown(): void {
        $this->resetStaticProperty( \WC_ACS_API::class, 'credentials' );
        parent::tearDown();
    }

    private function makePackage( array $overrides = [] ): array {
        return array_merge( [
            'contents'      => [],
            'contents_cost' => 30,
            'destination'   => [
                'postcode' => '10563',
                'country'  => 'GR',
            ],
        ], $overrides );
    }

    private function mockWCSession( string $payment_method = '' ): void {
        $session = Mockery::mock();
        $session->shouldReceive( 'get' )->withAnyArgs()->andReturnUsing(
            function ( $key, $default = null ) use ( $payment_method ) {
                if ( 'chosen_payment_method' === $key ) {
                    return $payment_method;
                }
                return $default;
            }
        );
        $wc = (object) [ 'session' => $session ];
        Functions\when( 'WC' )->justReturn( $wc );
    }

    public function test_free_shipping_threshold(): void {
        $this->method->free_min = '50';
        $this->method->fee_type = 'flat';
        $this->mockWCSession();

        $package = $this->makePackage( [ 'contents_cost' => 60 ] );
        $this->method->calculate_shipping( $package );

        $this->assertCount( 1, $this->method->rates_added );
        $this->assertEquals( 0, $this->method->rates_added[0]['cost'] );
        $this->assertStringContainsString( 'Free', $this->method->rates_added[0]['label'] );
    }

    public function test_flat_rate_mode(): void {
        $this->method->fee_type  = 'flat';
        $this->method->flat_rate = '4.50';
        $this->method->free_min  = '';
        $this->method->extra_fee = '0';
        $this->method->cod_fee   = '0';
        $this->mockWCSession();

        $this->method->calculate_shipping( $this->makePackage() );

        $this->assertEquals( 4.50, $this->method->rates_added[0]['cost'] );
    }

    public function test_handling_fee_added(): void {
        $this->method->fee_type  = 'flat';
        $this->method->flat_rate = '3.00';
        $this->method->free_min  = '';
        $this->method->extra_fee = '1.50';
        $this->method->cod_fee   = '0';
        $this->mockWCSession();

        $this->method->calculate_shipping( $this->makePackage() );

        $this->assertEquals( 4.50, $this->method->rates_added[0]['cost'] );
    }

    public function test_cod_fee_added(): void {
        $this->method->fee_type  = 'flat';
        $this->method->flat_rate = '3.00';
        $this->method->free_min  = '';
        $this->method->extra_fee = '0';
        $this->method->cod_fee   = '2.00';
        $this->mockWCSession( 'cod' );

        $this->method->calculate_shipping( $this->makePackage() );

        $this->assertEquals( 5.00, $this->method->rates_added[0]['cost'] );
    }

    public function test_api_rate_empty_postcode_returns_fallback(): void {
        $this->method->fee_type      = 'api';
        $this->method->free_min      = '';
        $this->method->extra_fee     = '0';
        $this->method->cod_fee       = '0';
        $this->method->fallback_cost = '5.00';
        $this->mockWCSession();

        $package = $this->makePackage( [
            'destination' => [ 'postcode' => '', 'country' => 'GR' ],
        ] );
        $this->method->calculate_shipping( $package );

        $this->assertEquals( 5.00, $this->method->rates_added[0]['cost'] );
    }

    public function test_api_rate_non_gr_returns_fallback(): void {
        $this->method->fee_type      = 'api';
        $this->method->free_min      = '';
        $this->method->extra_fee     = '0';
        $this->method->cod_fee       = '0';
        $this->method->fallback_cost = '5.00';
        $this->mockWCSession();

        $package = $this->makePackage( [
            'destination' => [ 'postcode' => '1000', 'country' => 'CY' ],
        ] );
        $this->method->calculate_shipping( $package );

        $this->assertEquals( 5.00, $this->method->rates_added[0]['cost'] );
    }

    public function test_api_rate_no_station_returns_fallback(): void {
        $this->method->fee_type      = 'api';
        $this->method->free_min      = '';
        $this->method->extra_fee     = '0';
        $this->method->cod_fee       = '0';
        $this->method->fallback_cost = '5.00';
        $this->mockWCSession();
        $this->stubGetOption( [
            'wc_acs_default_weight'    => '0.5',
            'woocommerce_weight_unit'  => 'kg',
        ] );

        Functions\when( 'get_transient' )->alias( function ( $key ) {
            if ( strpos( $key, 'acs_station_' ) === 0 ) {
                return '_none';
            }
            return false;
        } );
        Functions\when( 'set_transient' )->justReturn( true );

        $this->method->calculate_shipping( $this->makePackage() );

        $this->assertEquals( 5.00, $this->method->rates_added[0]['cost'] );
    }

    public function test_api_rate_success_vat_included(): void {
        $this->method->fee_type      = 'api';
        $this->method->tax_status    = 'none';
        $this->method->free_min      = '';
        $this->method->extra_fee     = '0';
        $this->method->cod_fee       = '0';
        $this->method->fallback_cost = '5.00';
        $this->mockWCSession();
        $this->stubGetOption( [
            'wc_acs_api_key'           => 'key',
            'wc_acs_company_id'        => 'C',
            'wc_acs_company_password'  => 'cp',
            'wc_acs_user_id'           => 'U',
            'wc_acs_user_password'     => 'up',
            'wc_acs_billing_code'      => 'BILL',
            'wc_acs_station_origin'    => 'ΑΘ',
            'wc_acs_default_weight'    => '0.5',
            'wc_acs_debug_logging'     => 'no',
            'woocommerce_weight_unit'  => 'kg',
        ] );

        Functions\when( 'get_transient' )->alias( function ( $key ) {
            if ( strpos( $key, 'acs_station_' ) === 0 ) {
                return 'ΑΘ';
            }
            return false;
        } );
        Functions\when( 'set_transient' )->justReturn( true );

        $api_response = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'ACSExecution_HasError' => false,
                'ACSOutputResponce'     => [
                    'ACSValueOutput' => [ [
                        'Total_Ammount'     => '3.50',
                        'Total_Vat_Ammount' => '0.84',
                    ] ],
                ],
            ] ),
        ];
        Functions\when( 'wp_remote_post' )->justReturn( $api_response );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) {
            return $r['body'];
        } );

        $this->method->calculate_shipping( $this->makePackage() );

        $this->assertEqualsWithDelta( 4.34, $this->method->rates_added[0]['cost'], 0.01 );
    }

    public function test_api_rate_success_vat_excluded(): void {
        $this->method->fee_type      = 'api';
        $this->method->tax_status    = 'taxable';
        $this->method->free_min      = '';
        $this->method->extra_fee     = '0';
        $this->method->cod_fee       = '0';
        $this->method->fallback_cost = '5.00';
        $this->mockWCSession();
        $this->stubGetOption( [
            'wc_acs_api_key'           => 'key',
            'wc_acs_company_id'        => 'C',
            'wc_acs_company_password'  => 'cp',
            'wc_acs_user_id'           => 'U',
            'wc_acs_user_password'     => 'up',
            'wc_acs_billing_code'      => 'BILL',
            'wc_acs_station_origin'    => 'ΑΘ',
            'wc_acs_default_weight'    => '0.5',
            'wc_acs_debug_logging'     => 'no',
            'woocommerce_weight_unit'  => 'kg',
        ] );

        Functions\when( 'get_transient' )->alias( function ( $key ) {
            if ( strpos( $key, 'acs_station_' ) === 0 ) {
                return 'ΑΘ';
            }
            return false;
        } );
        Functions\when( 'set_transient' )->justReturn( true );

        $api_response = [
            'response' => [ 'code' => 200 ],
            'body'     => json_encode( [
                'ACSExecution_HasError' => false,
                'ACSOutputResponce'     => [
                    'ACSValueOutput' => [ [
                        'Total_Ammount'     => '3.50',
                        'Total_Vat_Ammount' => '0.84',
                    ] ],
                ],
            ] ),
        ];
        Functions\when( 'wp_remote_post' )->justReturn( $api_response );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) {
            return $r['body'];
        } );

        $this->method->calculate_shipping( $this->makePackage() );

        $this->assertEqualsWithDelta( 3.50, $this->method->rates_added[0]['cost'], 0.01 );
    }

    public function test_api_rate_error_returns_fallback(): void {
        $this->method->fee_type      = 'api';
        $this->method->free_min      = '';
        $this->method->extra_fee     = '0';
        $this->method->cod_fee       = '0';
        $this->method->fallback_cost = '7.00';
        $this->mockWCSession();
        $this->stubGetOption( [
            'wc_acs_api_key'           => 'key',
            'wc_acs_company_id'        => 'C',
            'wc_acs_company_password'  => 'cp',
            'wc_acs_user_id'           => 'U',
            'wc_acs_user_password'     => 'up',
            'wc_acs_billing_code'      => 'BILL',
            'wc_acs_station_origin'    => 'ΑΘ',
            'wc_acs_default_weight'    => '0.5',
            'wc_acs_debug_logging'     => 'no',
            'woocommerce_weight_unit'  => 'kg',
        ] );

        Functions\when( 'get_transient' )->alias( function ( $key ) {
            if ( strpos( $key, 'acs_station_' ) === 0 ) {
                return 'ΑΘ';
            }
            return false;
        } );
        Functions\when( 'set_transient' )->justReturn( true );

        Functions\when( 'wp_remote_post' )->justReturn(
            new \WP_Error( 'http_error', 'timeout' )
        );

        $this->method->calculate_shipping( $this->makePackage() );

        $this->assertEquals( 7.00, $this->method->rates_added[0]['cost'] );
    }

    public function test_api_rate_calculates_weight_from_items(): void {
        $this->method->fee_type      = 'api';
        $this->method->free_min      = '';
        $this->method->extra_fee     = '0';
        $this->method->cod_fee       = '0';
        $this->method->tax_status    = 'none';
        $this->method->fallback_cost = '5.00';
        $this->mockWCSession();
        $this->stubGetOption( [
            'wc_acs_api_key'           => 'key',
            'wc_acs_company_id'        => 'C',
            'wc_acs_company_password'  => 'cp',
            'wc_acs_user_id'           => 'U',
            'wc_acs_user_password'     => 'up',
            'wc_acs_billing_code'      => 'BILL',
            'wc_acs_station_origin'    => 'ΑΘ',
            'wc_acs_default_weight'    => '0.5',
            'wc_acs_debug_logging'     => 'no',
            'woocommerce_weight_unit'  => 'kg',
        ] );

        Functions\when( 'get_transient' )->alias( function ( $key ) {
            if ( strpos( $key, 'acs_station_' ) === 0 ) return 'ΑΘ';
            return false;
        } );
        Functions\when( 'set_transient' )->justReturn( true );

        $captured_weight = null;
        Functions\when( 'wp_remote_post' )->alias( function ( $url, $args ) use ( &$captured_weight ) {
            $body = json_decode( $args['body'], true );
            $captured_weight = $body['ACSInputParameters']['Weight'] ?? null;
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [
                    'ACSExecution_HasError' => false,
                    'ACSOutputResponce'     => [
                        'ACSValueOutput' => [ [
                            'Total_Ammount'     => '5.00',
                            'Total_Vat_Ammount' => '1.20',
                        ] ],
                    ],
                ] ),
            ];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) {
            return $r['body'];
        } );

        $product1 = Mockery::mock( 'WC_Product' );
        $product1->shouldReceive( 'get_weight' )->andReturn( '2.0' );
        $product2 = Mockery::mock( 'WC_Product' );
        $product2->shouldReceive( 'get_weight' )->andReturn( '1.5' );

        $package = $this->makePackage( [
            'contents' => [
                [ 'data' => $product1, 'quantity' => 2 ],
                [ 'data' => $product2, 'quantity' => 1 ],
            ],
        ] );

        $this->method->calculate_shipping( $package );

        $this->assertSame( '5,5', $captured_weight );
    }
}
