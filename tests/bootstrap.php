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
    protected $errors = array();

    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
        if ( '' !== $code ) {
            $this->errors[ $code ][] = $message;
        }
    }

    public function add( $code, $message, $data = '' ) {
        $this->errors[ $code ][] = $message;
        if ( '' === $this->code ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_codes() {
        return array_keys( $this->errors );
    }

    public function get_error_message( $code = '' ) {
        if ( '' === $code ) {
            return $this->message;
        }
        return $this->errors[ $code ][0] ?? '';
    }

    public function get_error_messages( $code = '' ) {
        if ( '' !== $code ) {
            return $this->errors[ $code ] ?? array();
        }
        $all = array();
        foreach ( $this->errors as $messages ) {
            $all = array_merge( $all, $messages );
        }
        return $all;
    }

    public function get_error_data() {
        return $this->data;
    }

    public function has_errors() {
        return ! empty( $this->errors );
    }
}

// ── REST stubs ────────────────────────────────────────────────────
class WP_REST_Request {
    private $headers = array();
    private $params  = array();

    public function __construct( $method = 'GET', $route = '' ) {}

    private function key( $name ) {
        return strtolower( str_replace( '-', '_', $name ) );
    }

    public function set_header( $name, $value ) {
        $this->headers[ $this->key( $name ) ] = $value;
    }

    public function get_header( $name ) {
        return $this->headers[ $this->key( $name ) ] ?? null;
    }

    public function set_params( array $params ) {
        $this->params = $params;
    }

    public function get_params() {
        return $this->params;
    }
}

class WP_REST_Response {
    public $data;
    public $status;
    public $headers = array();

    public function __construct( $data = null, $status = 200 ) {
        $this->data   = $data;
        $this->status = $status;
    }

    public function header( $name, $value ) {
        $this->headers[ $name ] = $value;
    }

    public function get_headers() {
        return $this->headers;
    }

    public function get_status() {
        return $this->status;
    }

    public function get_data() {
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
require_once $plugin_dir . 'class-acs-points-feed.php';
require_once $plugin_dir . 'class-acs-points-shipping-method.php';
require_once $plugin_dir . 'class-acs-points-picker.php';
