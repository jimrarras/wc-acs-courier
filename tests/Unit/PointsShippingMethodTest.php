<?php
namespace WC_ACS_Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

class PointsShippingMethodTest extends TestCase {

    private \WC_ACS_Points_Shipping_Method $method;

    protected function setUp(): void {
        parent::setUp();
        $this->resetStaticProperty( \WC_ACS_Points_Feed::class, 'instance' );
        $this->stubGetOption( [
            'wc_acs_default_weight'   => '0.5',
            'woocommerce_weight_unit' => 'kg',
            'wc_acs_points_feed'      => [ 'fetched_at' => 1, 'country' => 'GR', 'points' => [ [ 'id' => '1' ] ] ],
        ] );
        $this->method = new \WC_ACS_Points_Shipping_Method( 3 );
        $this->method->cost        = '2.50';
        $this->method->free_min    = '';
        $this->method->max_weight  = '6';
        $this->method->point_types = 'both';
    }

    private function item( $weight, int $qty ): array {
        $product = Mockery::mock( 'WC_Product' );
        $product->shouldReceive( 'get_weight' )->andReturn( $weight );
        return [ 'data' => $product, 'quantity' => $qty ];
    }

    private function package( array $items, float $cost = 30 ): array {
        return [ 'contents' => $items, 'contents_cost' => $cost, 'destination' => [ 'postcode' => '45333', 'country' => 'GR' ] ];
    }

    public function test_identity(): void {
        $this->assertSame( 'acs_points', $this->method->id );
        $this->assertSame( 3, $this->method->instance_id );
        $this->assertContains( 'shipping-zones', $this->method->supports );
        $this->assertContains( 'instance-settings', $this->method->supports );
    }

    public function test_cod_mode_defaults_to_terminal(): void {
        $this->assertSame( 'terminal', $this->method->cod_mode );
    }

    public function test_init_form_fields_includes_cod_mode(): void {
        $reflection = new \ReflectionProperty( \WC_ACS_Points_Shipping_Method::class, 'instance_form_fields' );
        $reflection->setAccessible( true );
        $fields = $reflection->getValue( $this->method );

        $this->assertArrayHasKey( 'cod_mode', $fields );
        $this->assertSame( 'select', $fields['cod_mode']['type'] );
        $this->assertSame( 'terminal', $fields['cod_mode']['default'] );
        $this->assertSame( [ 'terminal', 'stores', 'off' ], array_keys( $fields['cod_mode']['options'] ) );

        $keys = array_keys( $fields );
        $this->assertSame( array_search( 'point_types', $keys, true ) + 1, array_search( 'cod_mode', $keys, true ) );
    }

    public function test_adds_rate_at_cost(): void {
        $this->method->calculate_shipping( $this->package( [ $this->item( '0.2', 2 ) ] ) );

        $this->assertCount( 1, $this->method->rates_added );
        $this->assertSame( 'acs_points:3', $this->method->rates_added[0]['id'] );
        $this->assertEquals( 2.5, $this->method->rates_added[0]['cost'] );
    }

    public function test_free_above_threshold(): void {
        $this->method->free_min = '50';

        $this->method->calculate_shipping( $this->package( [ $this->item( '0.2', 1 ) ], 50 ) );

        $this->assertEquals( 0, $this->method->rates_added[0]['cost'] );
    }

    public function test_withheld_above_max_weight(): void {
        $this->method->calculate_shipping( $this->package( [ $this->item( '3.5', 2 ) ] ) );

        $this->assertCount( 0, $this->method->rates_added );
    }

    public function test_zero_max_weight_means_no_limit(): void {
        $this->method->max_weight = '0';

        $this->method->calculate_shipping( $this->package( [ $this->item( '30', 1 ) ] ) );

        $this->assertCount( 1, $this->method->rates_added );
    }

    public function test_package_weight_uses_default_for_weightless_items(): void {
        $weight = \WC_ACS_Points_Shipping_Method::package_weight_kg( $this->package( [
            $this->item( '', 2 ),
            $this->item( '1.5', 1 ),
        ] ) );

        $this->assertEqualsWithDelta( 2.5, $weight, 0.001 );
    }

    public function test_package_weight_converts_grams(): void {
        $this->stubGetOption( [ 'wc_acs_default_weight' => '0.5', 'woocommerce_weight_unit' => 'g' ] );

        $weight = \WC_ACS_Points_Shipping_Method::package_weight_kg( $this->package( [ $this->item( '250', 4 ) ] ) );

        $this->assertEqualsWithDelta( 1.0, $weight, 0.001 );
    }

    public function test_withheld_when_feed_is_empty(): void {
        $this->resetStaticProperty( \WC_ACS_Points_Feed::class, 'instance' );
        $this->stubGetOption( [
            'wc_acs_default_weight'   => '0.5',
            'woocommerce_weight_unit' => 'kg',
        ] );

        $this->method->calculate_shipping( $this->package( [ $this->item( '0.2', 2 ) ] ) );

        $this->assertCount( 0, $this->method->rates_added );
    }
}
