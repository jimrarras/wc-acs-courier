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
