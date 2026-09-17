<?php
namespace WC_ACS_Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class TestCase extends PHPUnitTestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Translation functions — pass through first argument
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();

        // WP utility stubs
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( intval( $val ) );
        } );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );

        // Hooks that Brain Monkey doesn't auto-stub
        Functions\when( 'add_shortcode' )->justReturn( true );
        Functions\when( 'get_bloginfo' )->justReturn( 'Test Shop' );
        Functions\when( 'current_time' )->alias( function ( $type, $gmt = 0 ) {
            return date( $type );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Reset a static property on a class (singletons, caches).
     */
    protected function resetStaticProperty( string $class, string $property, $value = null ): void {
        $ref = new \ReflectionProperty( $class, $property );
        $ref->setAccessible( true );
        $ref->setValue( null, $value );
    }

    /**
     * Stub get_option to return values from a map, falling back to $default.
     */
    protected function stubGetOption( array $map = [] ): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( $map ) {
            return array_key_exists( $key, $map ) ? $map[ $key ] : $default;
        } );
    }

    /**
     * Create a Mockery mock of WC_Order with common methods.
     */
    protected function createOrderMock( array $overrides = [] ): \Mockery\MockInterface {
        $defaults = [
            'id'               => 100,
            'order_number'     => '100',
            'shipping_address' => [
                'first_name' => 'John',
                'last_name'  => 'Doe',
                'address_1'  => 'Ermou 25',
                'postcode'   => '10563',
                'city'        => 'Athens',
                'country'    => 'GR',
                'company'    => '',
            ],
            'billing_address' => [
                'first_name' => 'John',
                'last_name'  => 'Doe',
                'address_1'  => 'Stadiou 10',
                'postcode'   => '10564',
                'city'        => 'Athens',
                'country'    => 'GR',
                'company'    => '',
            ],
            'payment_method'  => 'bacs',
            'total'           => '50.00',
            'billing_phone'   => '2101234567',
            'billing_email'   => 'john@example.com',
            'billing_first_name' => 'John',
            'meta'            => [],
            'items'           => [],
            'shipping_methods' => [],
        ];

        $cfg = array_merge( $defaults, $overrides );

        $order = \Mockery::mock( 'WC_Order' );
        $order->shouldReceive( 'get_id' )->andReturn( $cfg['id'] );
        $order->shouldReceive( 'get_order_number' )->andReturn( $cfg['order_number'] );
        $order->shouldReceive( 'get_address' )->with( 'shipping' )->andReturn( $cfg['shipping_address'] );
        $order->shouldReceive( 'get_address' )->with( 'billing' )->andReturn( $cfg['billing_address'] );
        $order->shouldReceive( 'get_payment_method' )->andReturn( $cfg['payment_method'] );
        $order->shouldReceive( 'get_total' )->andReturn( $cfg['total'] );
        $order->shouldReceive( 'get_billing_phone' )->andReturn( $cfg['billing_phone'] );
        $order->shouldReceive( 'get_billing_email' )->andReturn( $cfg['billing_email'] );
        $order->shouldReceive( 'get_billing_first_name' )->andReturn( $cfg['billing_first_name'] );

        $order->shouldReceive( 'get_meta' )->andReturnUsing( function ( $key ) use ( $cfg ) {
            return $cfg['meta'][ $key ] ?? '';
        } );

        $order->shouldReceive( 'get_items' )->andReturn( $cfg['items'] );
        $order->shouldReceive( 'get_shipping_methods' )->andReturn( $cfg['shipping_methods'] );
        $order->updated_meta = [];
        $order->shouldReceive( 'update_meta_data' )->andReturnUsing( function ( $key, $value ) use ( $order ) {
            $order->updated_meta[ $key ] = $value;
        } );
        $order->shouldReceive( 'delete_meta_data' )->andReturnNull();
        $order->notes = [];
        $order->shouldReceive( 'add_order_note' )->andReturnUsing( function ( $note ) use ( $order ) {
            $order->notes[] = $note;
        } );
        $order->shouldReceive( 'set_status' )->andReturnNull();
        $order->shouldReceive( 'save' )->andReturnNull();

        return $order;
    }
}
