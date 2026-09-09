<?php
/**
 * Plugin Name: ACS Courier for WooCommerce
 * Plugin URI: https://github.com/jimrarras/wc-acs-courier
 * Description: Open source ACS Courier integration for WooCommerce, create/print vouchers, track shipments, calculate shipping costs, and offer pickup from ACS Points (lockers and stores) on a map.
 * Version: 1.2.2
 * Author: Dimitrios Rarras
 * Author URI: https://github.com/jimrarras/wc-acs-courier
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-acs-courier
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.6
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants — guarded to prevent fatal errors if loaded twice.
defined( 'WC_ACS_VERSION' )    || define( 'WC_ACS_VERSION', '1.2.2' );
defined( 'WC_ACS_PLUGIN_FILE' ) || define( 'WC_ACS_PLUGIN_FILE', __FILE__ );
defined( 'WC_ACS_PLUGIN_DIR' )  || define( 'WC_ACS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'WC_ACS_PLUGIN_URL' )  || define( 'WC_ACS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check if WooCommerce is active before initializing.
 */
function wc_acs_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            printf(
                '<div class="error"><p><strong>%s</strong> %s</p></div>',
                esc_html__( 'ACS Courier for WooCommerce', 'wc-acs-courier' ),
                esc_html__( 'requires WooCommerce to be installed and active.', 'wc-acs-courier' )
            );
        } );
        return false;
    }
    return true;
}

/**
 * Include required files.
 *
 * Verifies each file exists before including to prevent fatal errors
 * from corrupted or partial uploads.
 */
function wc_acs_includes() {
    $files = array(
        'includes/class-acs-api.php',
        'includes/class-acs-admin.php',
        'includes/class-acs-voucher.php',
        'includes/class-acs-shipping-method.php',
        'includes/class-acs-tracking.php',
        'includes/class-acs-points-feed.php',
        'includes/class-acs-points-shipping-method.php',
        'includes/class-acs-points-picker.php',
        'includes/class-acs-points-order.php',
    );

    foreach ( $files as $file ) {
        $path = WC_ACS_PLUGIN_DIR . $file;
        if ( ! file_exists( $path ) ) {
            add_action( 'admin_notices', function () use ( $file ) {
                printf(
                    '<div class="error"><p><strong>%s:</strong> %s <code>%s</code></p></div>',
                    esc_html__( 'ACS Courier for WooCommerce', 'wc-acs-courier' ),
                    esc_html__( 'Missing required file:', 'wc-acs-courier' ),
                    esc_html( $file )
                );
            } );
            return false;
        }
    }

    foreach ( $files as $file ) {
        require_once WC_ACS_PLUGIN_DIR . $file;
    }

    return true;
}

/**
 * Initialize the plugin.
 */
function wc_acs_init() {
    if ( ! wc_acs_check_woocommerce() ) {
        return;
    }

    if ( ! wc_acs_includes() ) {
        return;
    }

    // Initialize components
    WC_ACS_Admin::instance();
    WC_ACS_Voucher::instance();
    WC_ACS_Tracking::instance();
    WC_ACS_Points_Feed::instance();
    WC_ACS_Points_Picker::instance();
    WC_ACS_Points_Order::instance();

    // Register shipping methods
    add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
        $methods['acs_courier'] = 'WC_ACS_Shipping_Method';
        $methods['acs_points']  = 'WC_ACS_Points_Shipping_Method';
        return $methods;
    } );

    // Load text domain
    load_plugin_textdomain( 'wc-acs-courier', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wc_acs_init' );

/**
 * Activation hook — verify environment and schedule cron.
 *
 * Checks PHP version, WooCommerce availability, and required files
 * before allowing activation. Prevents the "white screen of death"
 * that occurs when a plugin fatals on every page load.
 */
function wc_acs_activate() {
    // Check PHP version
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'ACS Courier for WooCommerce requires PHP 7.4 or higher.', 'wc-acs-courier' ),
            esc_html__( 'Plugin Activation Error', 'wc-acs-courier' ),
            array( 'back_link' => true )
        );
    }

    // Check WooCommerce is active
    if ( ! class_exists( 'WooCommerce' ) && ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ), true ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'ACS Courier for WooCommerce requires WooCommerce to be installed and active.', 'wc-acs-courier' ),
            esc_html__( 'Plugin Activation Error', 'wc-acs-courier' ),
            array( 'back_link' => true )
        );
    }

    // Verify all required files exist (catches corrupted/partial uploads)
    $required_files = array(
        'includes/class-acs-api.php',
        'includes/class-acs-admin.php',
        'includes/class-acs-voucher.php',
        'includes/class-acs-shipping-method.php',
        'includes/class-acs-tracking.php',
        'includes/class-acs-points-feed.php',
        'includes/class-acs-points-shipping-method.php',
        'includes/class-acs-points-picker.php',
        'includes/class-acs-points-order.php',
    );

    foreach ( $required_files as $file ) {
        if ( ! file_exists( plugin_dir_path( __FILE__ ) . $file ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die(
                sprintf(
                    /* translators: %s: missing file path */
                    esc_html__( 'ACS Courier for WooCommerce is missing a required file: %s. Please re-upload the plugin.', 'wc-acs-courier' ),
                    '<code>' . esc_html( $file ) . '</code>'
                ),
                esc_html__( 'Plugin Activation Error', 'wc-acs-courier' ),
                array( 'back_link' => true )
            );
        }
    }

    // Schedule tracking cron
    if ( ! wp_next_scheduled( 'wc_acs_tracking_cron' ) ) {
        $frequency = get_option( 'wc_acs_tracking_frequency', 'hourly' );
        wp_schedule_event( time(), $frequency, 'wc_acs_tracking_cron' );
    }

    // Schedule the daily ACS points refresh. The first tick is due immediately;
    // the settings page has a manual "Refresh points" button for hosts whose
    // cron runs late.
    if ( ! wp_next_scheduled( 'wc_acs_points_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'wc_acs_points_cron' );
    }
}
register_activation_hook( __FILE__, 'wc_acs_activate' );

/**
 * Deactivation hook — clear cron.
 */
function wc_acs_deactivate() {
    wp_clear_scheduled_hook( 'wc_acs_tracking_cron' );
    wp_clear_scheduled_hook( 'wc_acs_points_cron' );
}
register_deactivation_hook( __FILE__, 'wc_acs_deactivate' );

/**
 * Register custom order statuses.
 */
function wc_acs_register_order_statuses() {
    register_post_status( 'wc-acs-delivered', array(
        'label'                     => _x( 'Delivered (ACS)', 'Order status', 'wc-acs-courier' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        /* translators: %s: number of orders */
        'label_count'               => _n_noop( 'Delivered (ACS) <span class="count">(%s)</span>', 'Delivered (ACS) <span class="count">(%s)</span>', 'wc-acs-courier' ),
    ) );

    register_post_status( 'wc-acs-denied', array(
        'label'                     => _x( 'Delivery Denied (ACS)', 'Order status', 'wc-acs-courier' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        /* translators: %s: number of orders */
        'label_count'               => _n_noop( 'Delivery Denied (ACS) <span class="count">(%s)</span>', 'Delivery Denied (ACS) <span class="count">(%s)</span>', 'wc-acs-courier' ),
    ) );
}
add_action( 'init', 'wc_acs_register_order_statuses' );

/**
 * Add custom statuses to WooCommerce order statuses.
 */
function wc_acs_add_order_statuses( $order_statuses ) {
    $acs_statuses = array(
        'wc-acs-delivered' => _x( 'Delivered (ACS)', 'Order status', 'wc-acs-courier' ),
        'wc-acs-denied'    => _x( 'Delivery Denied (ACS)', 'Order status', 'wc-acs-courier' ),
    );

    // Try to insert after 'wc-completed'
    if ( isset( $order_statuses['wc-completed'] ) ) {
        $new_statuses = array();
        foreach ( $order_statuses as $key => $status ) {
            $new_statuses[ $key ] = $status;
            if ( 'wc-completed' === $key ) {
                $new_statuses = array_merge( $new_statuses, $acs_statuses );
            }
        }
        return $new_statuses;
    }

    // Fallback: append at the end
    return array_merge( $order_statuses, $acs_statuses );
}
add_filter( 'wc_order_statuses', 'wc_acs_add_order_statuses' );

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * Add settings link on plugin page.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-acs-courier' ) ) . '">' . esc_html__( 'Settings', 'wc-acs-courier' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
} );
