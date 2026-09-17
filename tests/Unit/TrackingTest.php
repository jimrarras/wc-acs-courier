<?php
namespace WC_ACS_Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

class TrackingTest extends TestCase {

    private $tracking;

    protected function setUp(): void {
        parent::setUp();
        $this->resetStaticProperty( \WC_ACS_Tracking::class, 'instance' );
        $this->resetStaticProperty( \WC_ACS_API::class, 'credentials' );
        $this->tracking = \WC_ACS_Tracking::instance();
    }

    protected function tearDown(): void {
        $this->resetStaticProperty( \WC_ACS_Tracking::class, 'instance' );
        $this->resetStaticProperty( \WC_ACS_API::class, 'credentials' );
        parent::tearDown();
    }

    /**
     * Create an order mock that captures meta updates, status changes, and order notes
     * into a shared stdClass object (objects are pass-by-handle in PHP, unlike arrays).
     *
     * @return array [ MockInterface $order, stdClass $captures ]
     */
    private function makeTrackingOrder( array $meta = [], int $id = 100 ): array {
        $captures          = new \stdClass();
        $captures->meta    = [];
        $captures->statuses = [];
        $captures->notes   = [];

        $order = Mockery::mock( 'WC_Order' );
        $order->shouldReceive( 'get_id' )->andReturn( $id );
        $order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key ) use ( $meta ) {
            return $meta[ $key ] ?? '';
        } );
        $order->shouldReceive( 'update_meta_data' )->andReturnUsing(
            function ( $key, $value ) use ( $captures ) {
                $captures->meta[ $key ] = $value;
            }
        );
        $order->shouldReceive( 'set_status' )->andReturnUsing(
            function ( $status ) use ( $captures ) {
                $captures->statuses[] = $status;
            }
        );
        $order->shouldReceive( 'add_order_note' )->andReturnUsing(
            function ( $note ) use ( $captures ) {
                $captures->notes[] = $note;
            }
        );
        $order->shouldReceive( 'save' )->andReturnNull();

        return [ $order, $captures ];
    }

    private function stubTrackingApi( array $tracking_data ): void {
        $this->stubGetOption( [
            'wc_acs_auto_tracking'    => 'yes',
            'wc_acs_api_key'          => 'key',
            'wc_acs_company_id'       => 'C',
            'wc_acs_company_password' => 'cp',
            'wc_acs_user_id'          => 'U',
            'wc_acs_user_password'    => 'up',
            'wc_acs_debug_logging'    => 'no',
        ] );

        $body = json_encode( [
            'ACSExecution_HasError' => false,
            'ACSOutputResponce'     => [
                'ACSTableOutput' => [ 'Table_Data' => [ $tracking_data ] ],
            ],
        ] );

        Functions\when( 'wp_remote_post' )->justReturn( [
            'response' => [ 'code' => 200 ],
            'body'     => $body,
        ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
    }

    public function test_tracking_disabled_returns_early(): void {
        $this->stubGetOption( [ 'wc_acs_auto_tracking' => 'no' ] );
        $this->tracking->run_tracking();
        $this->assertTrue( true );
    }

    public function test_no_orders_returns_early(): void {
        $this->stubGetOption( [
            'wc_acs_auto_tracking' => 'yes',
            'wc_acs_debug_logging' => 'no',
        ] );
        Functions\when( 'wc_get_orders' )->justReturn( [] );
        $this->tracking->run_tracking();
        $this->assertTrue( true );
    }

    public function test_status_delivered_marks_order(): void {
        [ $order, $captures ] = $this->makeTrackingOrder( [
            '_acs_voucher_no'      => '7000001',
            '_acs_shipment_status' => 0,
            '_acs_tracking_status' => '',
        ] );

        $this->stubTrackingApi( [
            'shipment_status' => 4,
            'delivery_info'   => 'Delivered to recipient',
            'delivery_flag'   => 0,
            'returned_flag'   => 0,
        ] );

        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        $this->tracking->run_tracking();

        $this->assertEquals( 'delivered', $captures->meta['_acs_tracking_final'] );
        $this->assertContains( 'acs-delivered', $captures->statuses );
        $this->assertNotEmpty( $captures->notes );
    }

    public function test_delivery_flag_marks_delivered(): void {
        [ $order, $captures ] = $this->makeTrackingOrder( [
            '_acs_voucher_no'      => '7000002',
            '_acs_shipment_status' => 0,
            '_acs_tracking_status' => '',
        ] );

        $this->stubTrackingApi( [
            'shipment_status' => 3,
            'delivery_info'   => 'At destination',
            'delivery_flag'   => 1,
            'returned_flag'   => 0,
        ] );

        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        $this->tracking->run_tracking();

        $this->assertEquals( 'delivered', $captures->meta['_acs_tracking_final'] );
        $this->assertContains( 'acs-delivered', $captures->statuses );
    }

    public function test_status_refused_marks_denied(): void {
        [ $order, $captures ] = $this->makeTrackingOrder( [
            '_acs_voucher_no'      => '7000003',
            '_acs_shipment_status' => 0,
            '_acs_tracking_status' => '',
        ] );

        $this->stubTrackingApi( [
            'shipment_status'          => 1,
            'delivery_info'            => 'Refused by recipient',
            'delivery_flag'            => 0,
            'returned_flag'            => 0,
            'non_delivery_reason_code' => 'REF',
        ] );

        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        $this->tracking->run_tracking();

        $this->assertEquals( 'denied', $captures->meta['_acs_tracking_final'] );
        $this->assertContains( 'acs-denied', $captures->statuses );
        $this->assertNotEmpty( $captures->notes );
    }

    public function test_returned_flag_marks_denied(): void {
        [ $order, $captures ] = $this->makeTrackingOrder( [
            '_acs_voucher_no'      => '7000004',
            '_acs_shipment_status' => 0,
            '_acs_tracking_status' => '',
        ] );

        $this->stubTrackingApi( [
            'shipment_status'          => 2,
            'delivery_info'            => 'Returned',
            'delivery_flag'            => 0,
            'returned_flag'            => 1,
            'non_delivery_reason_code' => 'RET',
        ] );

        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        $this->tracking->run_tracking();

        $this->assertEquals( 'denied', $captures->meta['_acs_tracking_final'] );
        $this->assertContains( 'acs-denied', $captures->statuses );
    }

    public function test_in_transit_changed_adds_note(): void {
        [ $order, $captures ] = $this->makeTrackingOrder( [
            '_acs_voucher_no'      => '7000005',
            '_acs_shipment_status' => '2',
            '_acs_tracking_status' => 'In transit',
        ] );

        $this->stubTrackingApi( [
            'shipment_status' => 3,
            'delivery_info'   => 'Out for delivery',
            'delivery_flag'   => 0,
            'returned_flag'   => 0,
        ] );

        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        $this->tracking->run_tracking();

        $this->assertArrayNotHasKey( '_acs_tracking_final', $captures->meta );
        $this->assertNotEmpty( $captures->notes );
        $this->assertStringContainsString( 'Out for delivery', $captures->notes[0] );
    }

    public function test_in_transit_unchanged_no_duplicate_note(): void {
        [ $order, $captures ] = $this->makeTrackingOrder( [
            '_acs_voucher_no'      => '7000006',
            '_acs_shipment_status' => '3',
            '_acs_tracking_status' => 'Out for delivery',
        ] );

        $this->stubTrackingApi( [
            'shipment_status' => 3,
            'delivery_info'   => 'Out for delivery',
            'delivery_flag'   => 0,
            'returned_flag'   => 0,
        ] );

        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        $this->tracking->run_tracking();

        $this->assertArrayNotHasKey( '_acs_tracking_final', $captures->meta );
        $this->assertEmpty( $captures->notes, 'No order note should be added when status is unchanged' );
    }

    public function test_tracking_api_error_skips_order(): void {
        [ $order, $captures ] = $this->makeTrackingOrder( [
            '_acs_voucher_no'      => '7000010',
            '_acs_shipment_status' => 0,
            '_acs_tracking_status' => '',
        ] );

        $this->stubGetOption( [
            'wc_acs_auto_tracking'    => 'yes',
            'wc_acs_api_key'          => 'key',
            'wc_acs_company_id'       => 'C',
            'wc_acs_company_password' => 'cp',
            'wc_acs_user_id'          => 'U',
            'wc_acs_user_password'    => 'up',
            'wc_acs_debug_logging'    => 'no',
        ] );

        Functions\when( 'wp_remote_post' )->justReturn(
            new \WP_Error( 'http_error', 'timeout' )
        );
        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        $this->tracking->run_tracking();

        $this->assertEmpty( $captures->statuses );
        $this->assertEmpty( $captures->notes );
    }

    public function test_tracking_empty_table_data_skips_order(): void {
        [ $order, $captures ] = $this->makeTrackingOrder( [
            '_acs_voucher_no'      => '7000011',
            '_acs_shipment_status' => 0,
            '_acs_tracking_status' => '',
        ] );

        $this->stubGetOption( [
            'wc_acs_auto_tracking'    => 'yes',
            'wc_acs_api_key'          => 'key',
            'wc_acs_company_id'       => 'C',
            'wc_acs_company_password' => 'cp',
            'wc_acs_user_id'          => 'U',
            'wc_acs_user_password'    => 'up',
            'wc_acs_debug_logging'    => 'no',
        ] );

        $body = json_encode( [
            'ACSExecution_HasError' => false,
            'ACSOutputResponce'     => [
                'ACSTableOutput' => [ 'Table_Data' => [] ],
            ],
        ] );
        Functions\when( 'wp_remote_post' )->justReturn( [
            'response' => [ 'code' => 200 ],
            'body'     => $body,
        ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
        Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

        $this->tracking->run_tracking();

        $this->assertEmpty( $captures->statuses );
        $this->assertEmpty( $captures->notes );
    }
}
