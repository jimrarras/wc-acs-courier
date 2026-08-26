<?php
/**
 * Generate test voucher and pickup list PDFs for ACS verification.
 *
 * Usage: php generate-test-pdfs.php
 *
 * Reads credentials from .env and outputs PDFs to the current directory.
 */

// ── Load the plugin environment ──────────────────────────────────
require_once __DIR__ . '/tests/bootstrap.php';
require_once __DIR__ . '/tests/Integration/bootstrap.php';

use Brain\Monkey\Functions;

// Load .env
$env_file = __DIR__ . '/.env';
if ( ! file_exists( $env_file ) ) {
    fwrite( STDERR, "Error: .env file not found. Copy .env.example and fill in credentials.\n" );
    exit( 1 );
}
foreach ( file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
    if ( str_starts_with( trim( $line ), '#' ) ) continue;
    putenv( trim( $line ) );
}

// Wire up get_option to read from env
Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
    $map = [
        'wc_acs_api_key'          => getenv( 'ACS_API_KEY' ),
        'wc_acs_company_id'       => getenv( 'ACS_COMPANY_ID' ),
        'wc_acs_company_password' => getenv( 'ACS_COMPANY_PASSWORD' ),
        'wc_acs_user_id'          => getenv( 'ACS_USER_ID' ),
        'wc_acs_user_password'    => getenv( 'ACS_USER_PASSWORD' ),
        'wc_acs_debug_logging'    => 'no',
        'wc_acs_billing_code'     => getenv( 'ACS_BILLING_CODE' ) ?: getenv( 'ACS_COMPANY_ID' ),
        'wc_acs_sender_name'      => 'POOQ Test',
        'wc_acs_station_origin'   => '',
    ];
    return array_key_exists( $key, $map ) ? $map[ $key ] : $default;
} );

// Reset cached credentials
$ref = new ReflectionProperty( WC_ACS_API::class, 'credentials' );
$ref->setAccessible( true );
$ref->setValue( null, null );

// ── Helper ───────────────────────────────────────────────────────
function extract_pdf( array $result ): ?string {
    // Try ACSValueOutput path
    if ( ! empty( $result['ACSValueOutput'][0]['ACSObjectOutput'] ) ) {
        $obj = $result['ACSValueOutput'][0]['ACSObjectOutput'];
        if ( is_array( $obj ) ) {
            // Pickup list uses {"Mass_Voucher_No": "...", "PDFData": "..."}
            if ( isset( $obj['PDFData'] ) ) {
                return $obj['PDFData'];
            }
            // Voucher uses {voucher_no: base64_pdf}
            return reset( $obj );
        }
        return $obj;
    }
    // Try direct ACSObjectOutput
    if ( ! empty( $result['ACSObjectOutput'] ) ) {
        $obj = $result['ACSObjectOutput'];
        if ( is_array( $obj ) && isset( $obj['PDFData'] ) ) {
            return $obj['PDFData'];
        }
        return is_array( $obj ) ? reset( $obj ) : $obj;
    }
    return null;
}

// ── Step 1: Create a test voucher ────────────────────────────────
echo "Creating test voucher...\n";
$create_result = WC_ACS_API::create_voucher( [
    'Recipient_Name'    => 'Test Recipient',
    'Recipient_Address' => 'Ermou 25',
    'Recipient_Zipcode' => '10563',
    'Recipient_Region'  => 'Athens',
    'Recipient_Phone'   => '2101234567',
    'Weight'            => 0.5,
] );

if ( $create_result instanceof WP_Error ) {
    fwrite( STDERR, "Failed to create voucher: " . $create_result->get_error_message() . "\n" );
    exit( 1 );
}

$voucher_no = $create_result['ACSValueOutput'][0]['Voucher_No']
    ?? $create_result['ACSTableOutput']['Table_Data'][0]['Voucher_No']
    ?? $create_result['Voucher_No']
    ?? null;

if ( ! $voucher_no ) {
    fwrite( STDERR, "No Voucher_No in response:\n" . json_encode( $create_result, JSON_PRETTY_PRINT ) . "\n" );
    exit( 1 );
}
echo "  Voucher created: {$voucher_no}\n";

// ── Step 2: Print voucher PDF (A4 Laser) ─────────────────────────
echo "Printing voucher PDF (A4 Laser)...\n";
$print_result = WC_ACS_API::print_voucher( $voucher_no, 2 );

if ( $print_result instanceof WP_Error ) {
    fwrite( STDERR, "Failed to print voucher: " . $print_result->get_error_message() . "\n" );
    exit( 1 );
}

$pdf_base64 = extract_pdf( $print_result );
if ( ! $pdf_base64 ) {
    fwrite( STDERR, "No PDF data in print_voucher response:\n" . json_encode( $print_result, JSON_PRETTY_PRINT ) . "\n" );
    exit( 1 );
}

$voucher_pdf_path = __DIR__ . "/acs-voucher-{$voucher_no}.pdf";
file_put_contents( $voucher_pdf_path, base64_decode( $pdf_base64 ) );
echo "  Saved: {$voucher_pdf_path}\n";

// ── Step 3: Issue pickup list ────────────────────────────────────
echo "Issuing pickup list...\n";
$pickup_result = WC_ACS_API::issue_pickup_list( gmdate( 'Y-m-d' ) );

if ( $pickup_result instanceof WP_Error ) {
    fwrite( STDERR, "Failed to issue pickup list: " . $pickup_result->get_error_message() . "\n" );
    exit( 1 );
}

$pickup_list_no = $pickup_result['ACSValueOutput'][0]['PickupList_No'] ?? null;
$pickup_error   = $pickup_result['ACSValueOutput'][0]['Error_Message'] ?? '';

if ( ! $pickup_list_no ) {
    fwrite( STDERR, "No PickupList_No in response: " . ( $pickup_error ?: json_encode( $pickup_result, JSON_PRETTY_PRINT ) ) . "\n" );
    exit( 1 );
}
echo "  Pickup list issued: {$pickup_list_no}\n";

// ── Step 4: Print pickup list PDF ────────────────────────────────
echo "Printing pickup list PDF...\n";
$print_pickup_result = WC_ACS_API::print_pickup_list( $pickup_list_no, gmdate( 'Y-m-d' ) );

if ( $print_pickup_result instanceof WP_Error ) {
    fwrite( STDERR, "Failed to print pickup list: " . $print_pickup_result->get_error_message() . "\n" );
    exit( 1 );
}

$pickup_pdf_base64 = extract_pdf( $print_pickup_result );
if ( ! $pickup_pdf_base64 ) {
    fwrite( STDERR, "No PDF data in print_pickup_list response:\n" . json_encode( $print_pickup_result, JSON_PRETTY_PRINT ) . "\n" );
    exit( 1 );
}

$pickup_pdf_path = __DIR__ . "/acs-pickup-list-{$pickup_list_no}.pdf";
file_put_contents( $pickup_pdf_path, base64_decode( $pickup_pdf_base64 ) );
echo "  Saved: {$pickup_pdf_path}\n";

// ── Done ─────────────────────────────────────────────────────────
echo "\nDone! Send these two files to ACS:\n";
echo "  1. {$voucher_pdf_path}\n";
echo "  2. {$pickup_pdf_path}\n";
