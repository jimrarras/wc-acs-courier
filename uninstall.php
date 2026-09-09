<?php
/**
 * Uninstall handler for ACS Courier for WooCommerce.
 *
 * Cleans up all plugin data when the plugin is deleted via the WordPress admin.
 *
 * @package WC_ACS_Courier
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Plugin options to remove.
$options = array(
	'wc_acs_api_key',
	'wc_acs_company_id',
	'wc_acs_company_password',
	'wc_acs_user_id',
	'wc_acs_user_password',
	'wc_acs_billing_code',
	'wc_acs_sender_name',
	'wc_acs_station_origin',
	'wc_acs_default_weight',
	'wc_acs_charge_type',
	'wc_acs_auto_create_voucher',
	'wc_acs_auto_create_status',
	'wc_acs_auto_tracking',
	'wc_acs_tracking_frequency',
	'wc_acs_email_tracking',
	'wc_acs_debug_logging',
	'wc_acs_points_feed',
	'wc_acs_points_feed_error',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear scheduled cron.
wp_clear_scheduled_hook( 'wc_acs_tracking_cron' );
wp_clear_scheduled_hook( 'wc_acs_points_cron' );

// Remove transients.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acs_rate_%' OR option_name LIKE '_transient_timeout_acs_rate_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acs_station_%' OR option_name LIKE '_transient_timeout_acs_station_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acs_smartpoints_%' OR option_name LIKE '_transient_timeout_acs_smartpoints_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acs_bulk_print_%' OR option_name LIKE '_transient_timeout_acs_bulk_print_%'" );

// Note: Order meta (_acs_voucher_no, _acs_tracking_status, etc.) is intentionally
// NOT removed — it contains historical shipment data that belongs to the orders.
