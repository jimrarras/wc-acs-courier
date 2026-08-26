<?php
/**
 * PHPUnit bootstrap — stubs WordPress/WooCommerce, loads plugin classes.
 */

// Composer autoloader (Brain Monkey, Mockery, PHPUnit)
require_once __DIR__ . '/../vendor/autoload.php';

// ── WordPress constants ───────────────────────────────────────────
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// ── Plugin constants ──────────────────────────────────────────────
define( 'WC_ACS_VERSION', '1.0.0-test' );
define( 'WC_ACS_PLUGIN_URL', 'https://example.com/wp-content/plugins/wc-acs-courier/' );

// ── WP_Error stub ─────────────────────────────────────────────────
class WP_Error {
    protected $code;
    protected $message;
    protected $data;

    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_data() {
        return $this->data;
    }
}

// ── WP_Post stub ──────────────────────────────────────────────────
class WP_Post {
    public $ID = 0;

    public function __construct( $id = 0 ) {
        $this->ID = $id;
    }
}

// ── WC_Shipping_Method stub ───────────────────────────────────────
class WC_Shipping_Method {
    public $id                  = '';
    public $instance_id         = 0;
    public $method_title        = '';
    public $method_description  = '';
    public $supports            = array();
    public $enabled             = 'yes';
    public $title               = '';
    public $tax_status          = '';
    protected $instance_form_fields = array();
    protected $settings         = array();

    /** Stores rates added during tests. */
    public $rates_added = array();

    public function __construct( $instance_id = 0 ) {
        $this->instance_id = $instance_id;
    }

    public function get_option( $key, $default = '' ) {
        return $this->settings[ $key ] ?? $default;
    }

    public function init_form_fields() {}

    public function init_settings() {}

    public function get_rate_id() {
        return $this->id . ':' . $this->instance_id;
    }

    public function add_rate( $args ) {
        $this->rates_added[] = $args;
    }

    public function process_admin_options() {}
}

// ── Load plugin source files ──────────────────────────────────────
$plugin_dir = dirname( __DIR__ ) . '/includes/';

require_once $plugin_dir . 'class-acs-api.php';
require_once $plugin_dir . 'class-acs-admin.php';
require_once $plugin_dir . 'class-acs-voucher.php';
require_once $plugin_dir . 'class-acs-shipping-method.php';
require_once $plugin_dir . 'class-acs-tracking.php';
require_once $plugin_dir . 'class-acs-smartpoints.php';
