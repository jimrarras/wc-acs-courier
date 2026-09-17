<?php
namespace WC_ACS_Tests\Integration;

use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;

abstract class IntegrationTestCase extends TestCase {

    private const REQUIRED_ENV = [
        'ACS_API_KEY',
        'ACS_COMPANY_ID',
        'ACS_COMPANY_PASSWORD',
        'ACS_USER_ID',
        'ACS_USER_PASSWORD',
    ];

    protected static $created_vouchers = [];

    protected function setUp(): void {
        parent::setUp();

        foreach ( self::REQUIRED_ENV as $var ) {
            if ( empty( getenv( $var ) ) ) {
                $this->markTestSkipped( "Missing env var {$var}. Set all ACS credentials to run integration tests." );
            }
        }

        $ref = new \ReflectionProperty( \WC_ACS_API::class, 'credentials' );
        $ref->setAccessible( true );
        $ref->setValue( null, null );

        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            $map = [
                'wc_acs_api_key'          => getenv( 'ACS_API_KEY' ),
                'wc_acs_company_id'       => getenv( 'ACS_COMPANY_ID' ),
                'wc_acs_company_password' => getenv( 'ACS_COMPANY_PASSWORD' ),
                'wc_acs_user_id'          => getenv( 'ACS_USER_ID' ),
                'wc_acs_user_password'    => getenv( 'ACS_USER_PASSWORD' ),
                'wc_acs_debug_logging'    => 'no',
                'wc_acs_billing_code'     => getenv( 'ACS_BILLING_CODE' ) ?: getenv( 'ACS_COMPANY_ID' ),
                'wc_acs_sender_name'      => 'Integration Test',
                'wc_acs_station_origin'   => '',
            ];
            return array_key_exists( $key, $map ) ? $map[ $key ] : $default;
        } );
    }

    public static function tearDownAfterClass(): void {
        if ( ! empty( self::$created_vouchers ) ) {
            foreach ( self::$created_vouchers as $voucher_no ) {
                try {
                    \WC_ACS_API::delete_voucher( $voucher_no );
                } catch ( \Throwable $e ) {
                    // Best-effort cleanup.
                }
            }
            self::$created_vouchers = [];
        }
        parent::tearDownAfterClass();
    }
}
