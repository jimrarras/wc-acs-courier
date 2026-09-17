<?php
/**
 * Integration test bootstrap — extends unit bootstrap with real HTTP via cURL.
 */

// Load the unit bootstrap (constants, WP/WC stubs, plugin source files).
require_once dirname( __DIR__ ) . '/bootstrap.php';

// Load Brain Monkey so we can define function stubs for integration scope.
use Brain\Monkey\Functions;

Brain\Monkey\setUp();

// ── Real HTTP bridge ─────────────────────────────────────────────

Functions\when( 'wp_remote_post' )->alias( function ( $url, $args = [] ) {
    $ch = curl_init( $url );

    curl_setopt( $ch, CURLOPT_POST, true );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_TIMEOUT, $args['timeout'] ?? 30 );

    // Use system CA bundle, or fall back to bundled cacert.pem if available.
    $ca_path = getenv( 'CURL_CA_BUNDLE' ) ?: ( ini_get( 'curl.cainfo' ) ?: false );
    if ( $ca_path && file_exists( $ca_path ) ) {
        curl_setopt( $ch, CURLOPT_CAINFO, $ca_path );
    } else {
        // Test environment without CA bundle — disable verification.
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
    }

    if ( isset( $args['body'] ) ) {
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] );
    }

    if ( ! empty( $args['headers'] ) ) {
        $curl_headers = [];
        foreach ( $args['headers'] as $name => $value ) {
            $curl_headers[] = "{$name}: {$value}";
        }
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
    }

    $body      = curl_exec( $ch );
    $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $error     = curl_error( $ch );
    curl_close( $ch );

    if ( false === $body ) {
        return new WP_Error( 'http_request_failed', $error );
    }

    return [
        'response' => [ 'code' => $http_code ],
        'body'     => $body,
    ];
} );

Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) {
    return $response['response']['code'] ?? 0;
} );

Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) {
    return $response['body'] ?? '';
} );

// ── WordPress stubs needed at integration scope ──────────────────

Functions\when( '__' )->returnArg();
Functions\when( 'esc_html__' )->returnArg();

Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
    return $thing instanceof WP_Error;
} );

Functions\when( 'current_time' )->alias( function ( $type, $gmt = 0 ) {
    return date( $type );
} );

Functions\when( 'wc_get_logger' )->justReturn( new class {
    public function log( $level, $message, $context = [] ) {}
    public function __call( $name, $args ) {}
} );

Functions\when( 'get_bloginfo' )->justReturn( 'Test Store' );
