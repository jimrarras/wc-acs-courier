<?php
namespace WC_ACS_Tests\Unit;

use Brain\Monkey\Functions;

class AdminTest extends TestCase {

    private $admin;

    protected function setUp(): void {
        parent::setUp();
        $this->resetStaticProperty( \WC_ACS_Admin::class, 'instance' );
        $this->admin = \WC_ACS_Admin::instance();
    }

    protected function tearDown(): void {
        $this->resetStaticProperty( \WC_ACS_Admin::class, 'instance' );
        parent::tearDown();
    }

    public function test_sanitize_checkbox_yes(): void {
        $this->assertSame( 'yes', $this->admin->sanitize_checkbox( 'yes' ) );
    }

    public function test_sanitize_checkbox_no(): void {
        $this->assertSame( 'no', $this->admin->sanitize_checkbox( 'no' ) );
    }

    public function test_sanitize_checkbox_other_value(): void {
        $this->assertSame( 'no', $this->admin->sanitize_checkbox( '1' ) );
        $this->assertSame( 'no', $this->admin->sanitize_checkbox( '' ) );
        $this->assertSame( 'no', $this->admin->sanitize_checkbox( 'true' ) );
    }

    public function test_sanitize_weight_enforces_minimum(): void {
        $this->assertSame( '0.5', $this->admin->sanitize_weight( '0.3' ) );
        $this->assertSame( '0.5', $this->admin->sanitize_weight( '0' ) );
        $this->assertSame( '0.5', $this->admin->sanitize_weight( '-1' ) );
    }

    public function test_sanitize_weight_allows_valid(): void {
        $this->assertSame( '1', $this->admin->sanitize_weight( '1' ) );
        $this->assertSame( '2.5', $this->admin->sanitize_weight( '2.5' ) );
    }

    public function test_sanitize_frequency_valid_values(): void {
        $this->assertSame( 'hourly', $this->admin->sanitize_frequency( 'hourly' ) );
        $this->assertSame( 'twicedaily', $this->admin->sanitize_frequency( 'twicedaily' ) );
        $this->assertSame( 'daily', $this->admin->sanitize_frequency( 'daily' ) );
    }

    public function test_sanitize_frequency_rejects_invalid(): void {
        $this->assertSame( 'hourly', $this->admin->sanitize_frequency( 'every5min' ) );
        $this->assertSame( 'hourly', $this->admin->sanitize_frequency( '' ) );
        $this->assertSame( 'hourly', $this->admin->sanitize_frequency( 'weekly' ) );
    }

    public function test_sanitize_checkbox_null_input(): void {
        $this->assertSame( 'no', $this->admin->sanitize_checkbox( null ) );
    }

    public function test_sanitize_weight_non_numeric(): void {
        $this->assertSame( '0.5', $this->admin->sanitize_weight( 'abc' ) );
    }
}
