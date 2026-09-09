<?php
namespace WC_ACS_Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

class VoucherTest extends TestCase {

    private $voucher;

    protected function setUp(): void {
        parent::setUp();
        $this->resetStaticProperty( \WC_ACS_Voucher::class, 'instance' );
        $this->voucher = \WC_ACS_Voucher::instance();
    }

    protected function tearDown(): void {
        $this->resetStaticProperty( \WC_ACS_Voucher::class, 'instance' );
        parent::tearDown();
    }

    private function createItemMocks( array $items_data ): array {
        $items = [];
        foreach ( $items_data as $data ) {
            $product = Mockery::mock( 'WC_Product' );
            $product->shouldReceive( 'get_weight' )->andReturn( $data['weight'] );

            $item = Mockery::mock( 'WC_Order_Item_Product' );
            $item->shouldReceive( 'get_product' )->andReturn( $product );
            $item->shouldReceive( 'get_quantity' )->andReturn( $data['qty'] );

            $items[] = $item;
        }
        return $items;
    }

    // ── convert_weight_to_kg() tests ──────────────────────────────

    public function test_convert_weight_kg_passthrough(): void {
        $this->stubGetOption( [ 'woocommerce_weight_unit' => 'kg' ] );
        $this->assertSame( 5.0, \WC_ACS_Voucher::convert_weight_to_kg( 5.0 ) );
    }

    public function test_convert_weight_grams(): void {
        $this->stubGetOption( [ 'woocommerce_weight_unit' => 'g' ] );
        $this->assertEqualsWithDelta( 1.5, \WC_ACS_Voucher::convert_weight_to_kg( 1500 ), 0.001 );
    }

    public function test_convert_weight_lbs(): void {
        $this->stubGetOption( [ 'woocommerce_weight_unit' => 'lbs' ] );
        $this->assertEqualsWithDelta( 0.453592, \WC_ACS_Voucher::convert_weight_to_kg( 1.0 ), 0.001 );
    }

    public function test_convert_weight_oz(): void {
        $this->stubGetOption( [ 'woocommerce_weight_unit' => 'oz' ] );
        $this->assertEqualsWithDelta( 0.0283495, \WC_ACS_Voucher::convert_weight_to_kg( 1.0 ), 0.001 );
    }

    public function test_convert_weight_zero_returns_zero(): void {
        $this->stubGetOption( [ 'woocommerce_weight_unit' => 'kg' ] );
        $this->assertSame( 0.0, \WC_ACS_Voucher::convert_weight_to_kg( 0 ) );
        $this->assertSame( 0.0, \WC_ACS_Voucher::convert_weight_to_kg( -1 ) );
    }

    // ── build_voucher_params() tests ──────────────────────────────

    public function test_build_params_uses_shipping_address(): void {
        $this->stubGetOption( [
            'wc_acs_charge_type'     => '2',
            'wc_acs_default_weight'  => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );

        $order = $this->createOrderMock( [
            'shipping_address' => [
                'first_name' => 'Ship',
                'last_name'  => 'Name',
                'address_1'  => 'Shipping St 10',
                'postcode'   => '11111',
                'city'       => 'Thessaloniki',
                'country'    => 'GR',
                'company'    => '',
            ],
        ] );

        $params = $this->voucher->build_voucher_params( $order );

        $this->assertSame( 'Shipping St', $params['Recipient_Address'] );
        $this->assertSame( '10', $params['Recipient_Address_Number'] );
        $this->assertSame( '11111', $params['Recipient_Zipcode'] );
    }

    public function test_build_params_falls_back_to_billing(): void {
        $this->stubGetOption( [
            'wc_acs_charge_type'     => '2',
            'wc_acs_default_weight'  => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );

        $order = $this->createOrderMock( [
            'shipping_address' => [
                'first_name' => '',
                'last_name'  => '',
                'address_1'  => '',
                'postcode'   => '',
                'city'       => '',
                'country'    => 'GR',
                'company'    => '',
            ],
            'billing_address' => [
                'first_name' => 'Bill',
                'last_name'  => 'Person',
                'address_1'  => 'Billing Ave 5',
                'postcode'   => '22222',
                'city'       => 'Patras',
                'country'    => 'GR',
                'company'    => '',
            ],
        ] );

        $params = $this->voucher->build_voucher_params( $order );

        $this->assertSame( 'Bill Person', $params['Recipient_Name'] );
        $this->assertSame( '22222', $params['Recipient_Zipcode'] );
    }

    public function test_build_params_cod_order(): void {
        $this->stubGetOption( [
            'wc_acs_charge_type'     => '2',
            'wc_acs_default_weight'  => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );

        $order = $this->createOrderMock( [
            'payment_method' => 'cod',
            'total'          => '99.90',
        ] );

        $params = $this->voucher->build_voucher_params( $order );

        $this->assertEqualsWithDelta( 99.90, $params['Cod_Ammount'], 0.01 );
        $this->assertSame( 0, $params['Cod_Payment_Way'] );
        $this->assertStringContainsString( 'COD', $params['Acs_Delivery_Products'] );
    }

    public function test_build_params_non_cod_order(): void {
        $this->stubGetOption( [
            'wc_acs_charge_type'     => '2',
            'wc_acs_default_weight'  => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );

        $order = $this->createOrderMock( [ 'payment_method' => 'bacs' ] );

        $params = $this->voucher->build_voucher_params( $order );

        $this->assertNull( $params['Cod_Ammount'] );
        $this->assertNull( $params['Cod_Payment_Way'] );
    }

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

    public function test_build_params_extracts_street_number(): void {
        $this->stubGetOption( [
            'wc_acs_charge_type'     => '2',
            'wc_acs_default_weight'  => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );

        $order = $this->createOrderMock( [
            'shipping_address' => [
                'first_name' => 'A',
                'last_name'  => 'B',
                'address_1'  => 'Ermou 25',
                'postcode'   => '10563',
                'city'       => 'Athens',
                'country'    => 'GR',
                'company'    => '',
            ],
        ] );

        $params = $this->voucher->build_voucher_params( $order );

        $this->assertSame( 'Ermou', $params['Recipient_Address'] );
        $this->assertSame( '25', $params['Recipient_Address_Number'] );
    }

    public function test_build_params_no_street_number(): void {
        $this->stubGetOption( [
            'wc_acs_charge_type'     => '2',
            'wc_acs_default_weight'  => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );

        $order = $this->createOrderMock( [
            'shipping_address' => [
                'first_name' => 'A',
                'last_name'  => 'B',
                'address_1'  => 'Ermou',
                'postcode'   => '10563',
                'city'       => 'Athens',
                'country'    => 'GR',
                'company'    => '',
            ],
        ] );

        $params = $this->voucher->build_voucher_params( $order );

        $this->assertSame( 'Ermou', $params['Recipient_Address'] );
        $this->assertSame( '', $params['Recipient_Address_Number'] );
    }

    public function test_build_params_includes_address_2(): void {
        $this->stubGetOption( [
            'wc_acs_charge_type'     => '2',
            'wc_acs_default_weight'  => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );

        $order = $this->createOrderMock( [
            'shipping_address' => [
                'first_name' => 'A',
                'last_name'  => 'B',
                'address_1'  => 'Ermou 25',
                'address_2'  => '3rd Floor',
                'postcode'   => '10563',
                'city'       => 'Athens',
                'country'    => 'GR',
                'company'    => '',
            ],
        ] );

        $params = $this->voucher->build_voucher_params( $order );

        $this->assertStringContainsString( '3rd Floor', $params['Recipient_Address'] );
    }

    public function test_build_params_extra_params_override(): void {
        $this->stubGetOption( [
            'wc_acs_charge_type'     => '2',
            'wc_acs_default_weight'  => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );

        $order = $this->createOrderMock();

        $params = $this->voucher->build_voucher_params( $order, [
            'Weight'        => 10.0,
            'Item_Quantity' => 3,
        ] );

        $this->assertEquals( 10.0, $params['Weight'] );
        $this->assertSame( 3, $params['Item_Quantity'] );
    }

    public function test_build_params_pickup_date_uses_site_local_date(): void {
        $this->stubGetOption( [
            'wc_acs_charge_type'      => '2',
            'wc_acs_default_weight'   => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );

        // Simulate a store shortly after midnight local time, when the
        // UTC date is still the previous day (e.g. Athens 01:30 = 22:30 UTC).
        Functions\when( 'current_time' )->alias( function ( $format ) {
            return ( new \DateTimeImmutable( '2030-01-15 01:30:00' ) )->format( $format );
        } );

        $order  = $this->createOrderMock();
        $params = $this->voucher->build_voucher_params( $order );

        $this->assertSame( '2030-01-15', $params['Pickup_Date'] );
    }

    // ── auto_create_voucher() tests ───────────────────────────────

    public function test_auto_create_disabled_returns_early(): void {
        $this->stubGetOption( [ 'wc_acs_auto_create_voucher' => 'no' ] );
        // wc_get_order not stubbed → fatal if called → proves early return
        $this->voucher->auto_create_voucher( 123, 'pending', 'processing' );
        $this->assertTrue( true );
    }

    public function test_auto_create_wrong_status_returns_early(): void {
        $this->stubGetOption( [
            'wc_acs_auto_create_voucher' => 'yes',
            'wc_acs_auto_create_status'  => 'wc-processing',
        ] );
        // wc_get_order not stubbed → proves early return
        $this->voucher->auto_create_voucher( 123, 'pending', 'completed' );
        $this->assertTrue( true );
    }

    public function test_auto_create_stores_site_local_voucher_date(): void {
        $this->stubGetOption( [
            'wc_acs_auto_create_voucher' => 'yes',
            'wc_acs_auto_create_status'  => 'wc-processing',
            'wc_acs_email_tracking'      => 'no',
            'wc_acs_charge_type'         => '2',
            'wc_acs_default_weight'      => '0.5',
            'woocommerce_weight_unit'    => 'kg',
            'wc_acs_api_key'             => 'test-api-key',
        ] );

        Functions\when( 'current_time' )->alias( function ( $format ) {
            return ( new \DateTimeImmutable( '2030-01-15 01:30:00' ) )->format( $format );
        } );

        $order = $this->createOrderMock();
        Functions\when( 'wc_get_order' )->justReturn( $order );

        Functions\when( 'wp_remote_post' )->justReturn(
            [ 'response' => [ 'code' => 200 ], 'body' => '' ]
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [
            'ACSExecution_HasError' => false,
            'ACSOutputResponse'     => [
                'ACSValueOutput' => [ [ 'Voucher_No' => '7000123', 'Error_Message' => '' ] ],
            ],
        ] ) );

        $this->voucher->auto_create_voucher( 100, 'pending', 'processing' );

        $this->assertSame( '2030-01-15', $order->updated_meta['_acs_voucher_date'] ?? null );
    }

    public function test_auto_create_existing_voucher_returns_early(): void {
        $this->stubGetOption( [
            'wc_acs_auto_create_voucher' => 'yes',
            'wc_acs_auto_create_status'  => 'wc-processing',
        ] );

        $order = $this->createOrderMock( [
            'meta' => [ '_acs_voucher_no' => '7000001' ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $this->voucher->auto_create_voucher( 100, 'pending', 'processing' );
        $this->assertTrue( true );
    }

    // ── add_voucher_column() ──────────────────────────────────────

    public function test_add_voucher_column_inserts_after_order_status(): void {
        $columns = [
            'cb'           => '<input type="checkbox" />',
            'order_number' => 'Order',
            'order_status' => 'Status',
            'order_date'   => 'Date',
        ];

        $result = $this->voucher->add_voucher_column( $columns );

        $keys = array_keys( $result );
        $status_pos  = array_search( 'order_status', $keys, true );
        $voucher_pos = array_search( 'acs_voucher', $keys, true );

        $this->assertNotFalse( $voucher_pos );
        $this->assertSame( $status_pos + 1, $voucher_pos );
    }

    // ── register_bulk_actions() ───────────────────────────────────

    public function test_register_bulk_actions_adds_acs_actions(): void {
        $actions = [ 'trash' => 'Move to Trash' ];
        $result  = $this->voucher->register_bulk_actions( $actions );

        $this->assertArrayHasKey( 'acs_create_vouchers', $result );
        $this->assertArrayHasKey( 'acs_print_vouchers', $result );
        $this->assertArrayHasKey( 'trash', $result );
    }

    // ── auto_create_voucher(): orders shipped by another carrier plugin ──

    private function shippingItem( string $method_id ) {
        $item = Mockery::mock( 'WC_Order_Item_Shipping' );
        $item->shouldReceive( 'get_method_id' )->andReturn( $method_id );
        return $item;
    }

    public function test_auto_create_skips_an_order_shipped_with_boxnow(): void {
        // pooq.gr runs wc-boxnow-delivery alongside this plugin. A BOX NOW locker
        // order reaching the trigger status must not also get an ACS voucher.
        // The ACS rate on that store is a plain flat_rate, so the rule is
        // "not another known carrier", never "is the ACS shipping method".
        $this->stubGetOption( [
            'wc_acs_auto_create_voucher' => 'yes',
            'wc_acs_auto_create_status'  => 'wc-processing',
        ] );

        $order = $this->createOrderMock( [
            'shipping_methods' => [ $this->shippingItem( 'box_now_delivery' ) ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        // wp_remote_post not stubbed: a call would fatal, proving the API was never reached.
        $this->voucher->auto_create_voucher( 100, 'pending', 'processing' );

        $this->assertArrayNotHasKey( '_acs_voucher_no', $order->updated_meta );
        $this->assertCount( 1, $order->notes, 'The operator should see why no ACS voucher was created.' );
        $this->assertStringContainsString( 'BOX NOW', $order->notes[0] );
    }

    public function test_auto_create_proceeds_for_a_flat_rate_order(): void {
        // The store's ACS rate is flat_rate:2, so a flat_rate order must still
        // be handled; only other carrier plugins are excluded.
        $this->stubGetOption( [
            'wc_acs_auto_create_voucher' => 'yes',
            'wc_acs_auto_create_status'  => 'wc-processing',
            'wc_acs_email_tracking'      => 'no',
            'wc_acs_charge_type'         => '2',
            'wc_acs_default_weight'      => '0.5',
            'woocommerce_weight_unit'    => 'kg',
            'wc_acs_api_key'             => 'test-api-key',
        ] );

        $order = $this->createOrderMock( [
            'shipping_methods' => [ $this->shippingItem( 'flat_rate' ) ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 200 ], 'body' => '' ] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [
            'ACSExecution_HasError' => false,
            'ACSOutputResponse'     => [
                'ACSValueOutput' => [ [ 'Voucher_No' => '7000124', 'Error_Message' => '' ] ],
            ],
        ] ) );

        $this->voucher->auto_create_voucher( 100, 'pending', 'processing' );

        $this->assertSame( '7000124', $order->updated_meta['_acs_voucher_no'] ?? null );
    }

    public function test_auto_create_can_be_vetoed_per_order_by_filter(): void {
        // Same credentials as the flat_rate test above, so that without the
        // veto the call WOULD reach wp_remote_post, which is not stubbed here
        // and would fatal. That is what makes the veto observable.
        $this->stubGetOption( [
            'wc_acs_auto_create_voucher' => 'yes',
            'wc_acs_auto_create_status'  => 'wc-processing',
            'wc_acs_email_tracking'      => 'no',
            'wc_acs_charge_type'         => '2',
            'wc_acs_default_weight'      => '0.5',
            'woocommerce_weight_unit'    => 'kg',
            'wc_acs_api_key'             => 'test-api-key',
        ] );

        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) {
            return 'wc_acs_auto_create_voucher_allowed' === $tag ? false : $value;
        } );

        $order = $this->createOrderMock( [
            'shipping_methods' => [ $this->shippingItem( 'flat_rate' ) ],
        ] );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        // wp_remote_post not stubbed: a call would fatal.
        $this->voucher->auto_create_voucher( 100, 'pending', 'processing' );

        $this->assertArrayNotHasKey( '_acs_voucher_no', $order->updated_meta );
    }
}
