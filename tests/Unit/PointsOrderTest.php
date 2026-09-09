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
