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

    // ── script_settings() / render_picker() ─────────────────────

    public function test_script_settings_exposes_the_js_contract(): void {
        $this->seedFeed( [ $this->point( [ 'zip' => '45333' ] ) ] );
        $this->mockSession( [ 'chosen_shipping_methods' => [ 'acs_points:3' ] ] );
        Functions\when( 'rest_url' )->alias( function ( $path ) {
            return 'https://example.com/wp-json/' . $path;
        } );
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin-ajax.php' );
        Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );

        $settings = $this->picker()->script_settings();

        $this->assertSame( [
            'restUrl', 'ajaxUrl', 'nonce', 'pointTypes', 'postcodeCentre', 'assets', 'icons', 'i18n',
        ], array_keys( $settings ) );

        $this->assertSame( 'https://example.com/wp-json/wc-acs/v1/points', $settings['restUrl'] );
        $this->assertSame( 'https://example.com/wp-admin/admin-ajax.php', $settings['ajaxUrl'] );
        $this->assertSame( 'nonce123', $settings['nonce'] );
        $this->assertSame( 'lockers', $settings['pointTypes'] );
        $this->assertNull( $settings['postcodeCentre'] );

        $this->assertSame( [
            'leafletCss', 'leafletJs', 'clusterCss', 'clusterDefaultCss', 'clusterJs',
        ], array_keys( $settings['assets'] ) );
        foreach ( $settings['assets'] as $value ) {
            $this->assertStringStartsWith( WC_ACS_PLUGIN_URL . 'assets/vendor/', $value );
        }

        $this->assertSame( [
            'locker', 'lockerCod', 'store', 'marker', 'markerShadow',
        ], array_keys( $settings['icons'] ) );

        $this->assertSame( [
            'title', 'search', 'all', 'lockers', 'stores', 'myLocation', 'select', 'change', 'close',
            'loading', 'loadError', 'moreHint', 'noMatches', 'open24', 'cod', 'noCod', 'weekdays', 'saturday',
            'locateError', 'locker', 'store',
        ], array_keys( $settings['i18n'] ) );
        foreach ( $settings['i18n'] as $value ) {
            $this->assertNotSame( '', $value );
        }
    }

    public function test_render_picker_outputs_hidden_input_and_selected_summary(): void {
        Functions\when( 'is_checkout' )->justReturn( true );
        Functions\when( 'esc_html_e' )->alias( function ( $text ) {
            echo $text;
        } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) {
            echo $text;
        } );
        $this->seedFeed( [ $this->point() ] );
        $this->mockSession( [ 'acs_point_id' => '4400' ] );
        $picker = $this->picker();

        $rate = Mockery::mock( 'WC_Shipping_Rate' );
        $rate->shouldReceive( 'get_method_id' )->andReturn( 'acs_points' );

        ob_start();
        $picker->render_picker( $rate, 0 );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'class="wc-acs-points-picker" data-package="0"', $output );
        $this->assertStringContainsString( 'name="acs_point_id"', $output );
        $this->assertStringContainsString( 'value="4400"', $output );
        $this->assertStringContainsString( 'ACS SMARTPOINT LOCKER ΙΩΑΝΝΙΝΑ', $output );
        $this->assertStringContainsString( 'wc-acs-points-badge--24', $output );
        $this->assertStringContainsString( 'wc-acs-points-badge--cod', $output );
        $this->assertStringContainsString( 'class="wc-acs-points-change"', $output );
        $this->assertStringContainsString( 'button type="button" class="wc-acs-points-open" hidden', $output );

        $other_rate = Mockery::mock( 'WC_Shipping_Rate' );
        $other_rate->shouldReceive( 'get_method_id' )->andReturn( 'flat_rate' );

        ob_start();
        $picker->render_picker( $other_rate, 0 );
        $other_output = ob_get_clean();

        $this->assertSame( '', $other_output );
    }

    public function test_render_picker_is_silent_off_the_checkout(): void {
        Functions\when( 'is_checkout' )->justReturn( false );
        $this->seedFeed( [ $this->point() ] );
        $this->mockSession( [ 'acs_point_id' => '4400' ] );
        $picker = $this->picker();

        $rate = Mockery::mock( 'WC_Shipping_Rate' );
        $rate->shouldReceive( 'get_method_id' )->andReturn( 'acs_points' );

        ob_start();
        $picker->render_picker( $rate, 0 );
        $output = ob_get_clean();

        $this->assertSame( '', $output );
    }
}
