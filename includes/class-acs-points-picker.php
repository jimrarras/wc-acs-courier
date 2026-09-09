<?php
/**
 * ACS Points picker: checkout selection, validation and persistence.
 *
 * Follows the BOX NOW locker pattern: the chosen point id lives in the
 * WooCommerce session while the customer is on the checkout, validation is a
 * static side-effect-free function shared by the classic checkout and the
 * Store API, and the server never trusts point fields from the browser (only
 * the id, resolved through WC_ACS_Points_Feed::find()).
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Points_Picker {

    const METHOD_ID           = 'acs_points';
    const SESSION_KEY         = 'acs_point_id';
    const NONCE_ACTION        = 'wc-acs-points';
    const STORE_API_NAMESPACE = 'wc-acs-courier';

    /** @var WC_ACS_Points_Picker|null */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'woocommerce_after_shipping_rate', array( $this, 'render_picker' ), 10, 2 );
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic_checkout' ), 10, 2 );
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_payment_gateways' ) );

        add_action( 'wp_ajax_wc_acs_set_point', array( $this, 'ajax_set_point' ) );
        add_action( 'wp_ajax_nopriv_wc_acs_set_point', array( $this, 'ajax_set_point' ) );

        // woocommerce_blocks_loaded is deprecated since WC 8.4; woocommerce_init at 20 runs after WC is up.
        add_action( 'woocommerce_init', array( $this, 'register_store_api' ), 20 );
    }

    // ─── Static helpers ────────────────────────────────────────────

    /**
     * Greek mobile in its ten-digit form, or null. ACS sends the pickup PIN
     * by SMS, so a locker shipment needs one.
     *
     * @param mixed $raw Phone as typed.
     * @return string|null
     */
    public static function normalise_mobile( $raw ) {
        $digits = preg_replace( '/\D+/', '', (string) $raw );

        if ( 0 === strpos( $digits, '0030' ) ) {
            $digits = substr( $digits, 4 );
        } elseif ( 12 === strlen( $digits ) && 0 === strpos( $digits, '30' ) ) {
            $digits = substr( $digits, 2 );
        }

        return ( 1 === preg_match( '/^69\d{8}$/', $digits ) ) ? $digits : null;
    }

    /**
     * @param array $chosen Chosen rate ids, e.g. array( 'acs_points:4' ).
     * @return bool
     */
    public static function methods_include_points( array $chosen ) {
        foreach ( $chosen as $rate_id ) {
            $rate_id = (string) $rate_id;
            if ( self::METHOD_ID === $rate_id || 0 === strpos( $rate_id, self::METHOD_ID . ':' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 'both' or 'lockers' for the chosen acs_points instance.
     *
     * @param array $chosen Chosen rate ids.
     * @return string
     */
    public static function instance_point_types( array $chosen ) {
        foreach ( $chosen as $rate_id ) {
            $parts = explode( ':', (string) $rate_id, 2 );
            if ( self::METHOD_ID !== $parts[0] ) {
                continue;
            }
            $instance_id = isset( $parts[1] ) ? (int) $parts[1] : 0;
            $settings    = get_option( 'woocommerce_' . self::METHOD_ID . '_' . $instance_id . '_settings', array() );
            $types       = is_array( $settings ) ? ( $settings['point_types'] ?? 'both' ) : 'both';
            return 'lockers' === $types ? 'lockers' : 'both';
        }
        return 'both';
    }

    /**
     * 'terminal', 'stores' or 'off' for the chosen acs_points instance.
     *
     * @param array $chosen Chosen rate ids.
     * @return string
     */
    public static function instance_cod_mode( array $chosen ) {
        foreach ( $chosen as $rate_id ) {
            $parts = explode( ':', (string) $rate_id, 2 );
            if ( self::METHOD_ID !== $parts[0] ) {
                continue;
            }
            $instance_id = isset( $parts[1] ) ? (int) $parts[1] : 0;
            $settings    = get_option( 'woocommerce_' . self::METHOD_ID . '_' . $instance_id . '_settings', array() );
            $mode        = is_array( $settings ) ? ( $settings['cod_mode'] ?? 'terminal' ) : 'terminal';
            return in_array( $mode, array( 'terminal', 'stores', 'off' ), true ) ? $mode : 'terminal';
        }
        return 'terminal';
    }

    /**
     * @param array  $point Feed record.
     * @param string $mode  'terminal', 'stores' or 'off'.
     * @return bool
     */
    public static function point_allows_cod( array $point, $mode ) {
        if ( 'off' === $mode ) {
            return false;
        }
        if ( 'stores' === $mode ) {
            return 'store' === ( $point['type'] ?? '' );
        }
        return ! empty( $point['cod'] );
    }

    /**
     * @param WC_Order $order Order.
     * @return bool
     */
    public static function order_has_points( $order ) {
        foreach ( $order->get_shipping_methods() as $item ) {
            if ( self::METHOD_ID === $item->get_method_id() ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The checkout rules. Adds to $errors, never throws.
     *
     * @param array      $data   shipping_method (array), payment_method, billing_phone.
     * @param array|null $point  Resolved feed record or null.
     * @param WP_Error   $errors Collector.
     */
    public static function validate( array $data, $point, $errors ) {
        $chosen = (array) ( $data['shipping_method'] ?? array() );

        if ( ! self::methods_include_points( $chosen ) ) {
            return;
        }

        if ( ! is_array( $point ) ) {
            $errors->add( 'acs_point_required', __( 'Please choose an ACS Point before placing your order.', 'wc-acs-courier' ) );
        } else {
            if ( 'lockers' === self::instance_point_types( $chosen ) && 'store' === ( $point['type'] ?? '' ) ) {
                $errors->add( 'acs_point_type', __( 'Please choose an ACS locker.', 'wc-acs-courier' ) );
            }
            if ( 'cod' === ( $data['payment_method'] ?? '' ) && ! self::point_allows_cod( $point, self::instance_cod_mode( $chosen ) ) ) {
                $errors->add( 'acs_point_cod', __( 'Cash on delivery is not available at this ACS Point. Choose another point or pay by card.', 'wc-acs-courier' ) );
            }
        }

        if ( null === self::normalise_mobile( $data['billing_phone'] ?? '' ) ) {
            $errors->add( 'acs_point_mobile', __( 'ACS sends the pickup PIN by SMS, so a Greek mobile number (69xxxxxxxx) is required.', 'wc-acs-courier' ) );
        }
    }

    /**
     * @param array $point Feed record.
     * @return string "street, zip city"
     */
    public static function format_address( array $point ) {
        $line = trim( (string) ( $point['zip'] ?? '' ) . ' ' . (string) ( $point['city'] ?? '' ) );
        return trim( (string) ( $point['street'] ?? '' ) . ', ' . $line, ', ' );
    }

    /**
     * Write the point onto an order. The caller saves.
     *
     * @param WC_Order $order Order.
     * @param array    $point Feed record.
     */
    public static function apply_point_to_order( $order, array $point ) {
        $order->update_meta_data( '_acs_point_id', (string) $point['id'] );
        $order->update_meta_data( '_acs_point_type', (string) $point['type'] );
        $order->update_meta_data( '_acs_point_name', (string) $point['name'] );
        $order->update_meta_data( '_acs_point_address', self::format_address( $point ) );
        $order->update_meta_data( '_acs_point_station', (string) $point['station'] );
        $order->update_meta_data( '_acs_point_branch', (string) $point['branch'] );
        $order->update_meta_data( '_acs_point_cod', empty( $point['cod'] ) ? '0' : '1' );
    }

    // ─── Session ───────────────────────────────────────────────────

    /**
     * Point id from the request, falling back to the session.
     *
     * @return string
     */
    public function get_selected_point_id() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the checkout flow verifies its own nonce.
        if ( isset( $_POST['acs_point_id'] ) ) {
            return sanitize_text_field( wp_unslash( $_POST['acs_point_id'] ) );
        }
        if ( function_exists( 'WC' ) && WC()->session ) {
            return (string) WC()->session->get( self::SESSION_KEY, '' );
        }
        return '';
    }

    /**
     * @return array|null Resolved feed record.
     */
    public function get_selected_point() {
        return WC_ACS_Points_Feed::instance()->find( $this->get_selected_point_id() );
    }

    /**
     * AJAX: remember the chosen point for this session and echo it back.
     */
    public function ajax_set_point() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $id    = isset( $_POST['point_id'] ) ? sanitize_text_field( wp_unslash( $_POST['point_id'] ) ) : '';
        $point = WC_ACS_Points_Feed::instance()->find( $id );

        if ( null === $point ) {
            wp_send_json_error( array( 'message' => __( 'Unknown ACS Point.', 'wc-acs-courier' ) ), 404 );
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( self::SESSION_KEY, $point['id'] );
        }

        wp_send_json_success( array( 'point' => $point ) );
    }

    // ─── Classic checkout ──────────────────────────────────────────

    /**
     * @param array    $data   Posted data.
     * @param WP_Error $errors Errors.
     */
    public function validate_classic_checkout( $data, $errors ) {
        $data = (array) $data;

        // get_posted_data() yields '' when the field is absent; the session
        // holds what create_order_shipping_lines() will use.
        if ( empty( $data['shipping_method'] ) && function_exists( 'WC' ) && WC()->session ) {
            $data['shipping_method'] = (array) WC()->session->get( 'chosen_shipping_methods', array() );
        }

        self::validate( $data, $this->get_selected_point(), $errors );
    }

    /**
     * @param WC_Order $order Order.
     * @param array    $data  Posted data (unused; the order's shipping line is the truth).
     * @throws Exception When ACS Points is on the order but no valid point was chosen.
     */
    public function save_classic_checkout( $order, $data = array() ) {
        if ( ! self::order_has_points( $order ) ) {
            return;
        }

        $point = $this->get_selected_point();

        if ( null === $point ) {
            throw new Exception( __( 'Please choose an ACS Point before placing your order.', 'wc-acs-courier' ) );
        }

        self::apply_point_to_order( $order, $point );
    }

    /**
     * Hide cash on delivery once a point without a terminal is chosen.
     *
     * @param array $gateways Gateway id => WC_Payment_Gateway.
     * @return array
     */
    public static function filter_payment_gateways( $gateways ) {
        if ( ! is_array( $gateways ) || ! isset( $gateways['cod'] ) ) {
            return $gateways;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return $gateways;
        }

        $chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
        if ( ! self::methods_include_points( $chosen ) ) {
            return $gateways;
        }

        $point = WC_ACS_Points_Feed::instance()->find( (string) WC()->session->get( self::SESSION_KEY, '' ) );
        if ( is_array( $point ) && ! self::point_allows_cod( $point, self::instance_cod_mode( $chosen ) ) ) {
            unset( $gateways['cod'] );
        }

        return $gateways;
    }

    // ─── Rendering ─────────────────────────────────────────────────

    /**
     * Picker beneath the acs_points rate.
     *
     * @param WC_Shipping_Rate $rate  Rate.
     * @param int              $index Package index.
     */
    public function render_picker( $rate, $index ) {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }

        if ( self::METHOD_ID !== $rate->get_method_id() ) {
            return;
        }

        $point       = $this->get_selected_point();
        $chosen      = ( function_exists( 'WC' ) && WC()->session ) ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        $allows_cod  = $point ? self::point_allows_cod( $point, self::instance_cod_mode( $chosen ) ) : false;
        ?>
        <div class="wc-acs-points-picker" data-package="<?php echo esc_attr( $index ); ?>">
            <button type="button" class="wc-acs-points-open"<?php echo $point ? ' hidden' : ''; ?>>
                <?php esc_html_e( 'Choose ACS Point', 'wc-acs-courier' ); ?>
            </button>
            <div class="wc-acs-points-selected"<?php echo $point ? '' : ' hidden'; ?>>
                <?php if ( $point ) : ?>
                    <span class="wc-acs-points-selected-name"><?php echo esc_html( $point['name'] ); ?></span>
                    <span class="wc-acs-points-selected-address"><?php echo esc_html( self::format_address( $point ) ); ?></span>
                    <span class="wc-acs-points-badges">
                        <?php if ( ! empty( $point['h24'] ) ) : ?>
                            <span class="wc-acs-points-badge wc-acs-points-badge--24"><?php esc_html_e( '24/7', 'wc-acs-courier' ); ?></span>
                        <?php endif; ?>
                        <?php if ( $allows_cod ) : ?>
                            <span class="wc-acs-points-badge wc-acs-points-badge--cod"><?php esc_html_e( 'Cash on delivery available', 'wc-acs-courier' ); ?></span>
                        <?php else : ?>
                            <span class="wc-acs-points-badge wc-acs-points-badge--nocod"><?php esc_html_e( 'No cash on delivery', 'wc-acs-courier' ); ?></span>
                        <?php endif; ?>
                    </span>
                    <button type="button" class="wc-acs-points-change"><?php esc_html_e( 'Change', 'wc-acs-courier' ); ?></button>
                <?php endif; ?>
            </div>
            <input type="hidden" name="acs_point_id" id="acs_point_id" value="<?php echo esc_attr( $point ? $point['id'] : '' ); ?>" />
        </div>
        <?php
    }

    /**
     * Customer postcode from the session customer, shipping first.
     *
     * @return string
     */
    private function customer_postcode() {
        if ( ! function_exists( 'WC' ) || empty( WC()->customer ) ) {
            return '';
        }
        $customer = WC()->customer;
        $zip      = method_exists( $customer, 'get_shipping_postcode' ) ? (string) $customer->get_shipping_postcode() : '';
        if ( '' === $zip && method_exists( $customer, 'get_billing_postcode' ) ) {
            $zip = (string) $customer->get_billing_postcode();
        }
        return $zip;
    }

    /**
     * @return array Settings for acs-points.js.
     */
    public function script_settings() {
        $feed   = WC_ACS_Points_Feed::instance();
        $chosen = ( function_exists( 'WC' ) && WC()->session ) ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        $vendor = WC_ACS_PLUGIN_URL . 'assets/vendor/';
        $img    = WC_ACS_PLUGIN_URL . 'assets/img/';

        return array(
            'restUrl'        => rest_url( WC_ACS_Points_Feed::REST_NAMESPACE . '/points' ),
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
            'pointTypes'     => self::instance_point_types( $chosen ),
            'codMode'        => self::instance_cod_mode( $chosen ),
            'postcodeCentre' => WC_ACS_Points_Feed::centre_for_postcode( $this->customer_postcode(), $feed->get_points() ),
            'assets'         => array(
                'leafletCss'        => $vendor . 'leaflet/leaflet.css',
                'leafletJs'         => $vendor . 'leaflet/leaflet.js',
                'clusterCss'        => $vendor . 'leaflet.markercluster/MarkerCluster.css',
                'clusterDefaultCss' => $vendor . 'leaflet.markercluster/MarkerCluster.Default.css',
                'clusterJs'         => $vendor . 'leaflet.markercluster/leaflet.markercluster.js',
            ),
            'icons'          => array(
                'locker'       => $img . 'point-locker.svg',
                'lockerCod'    => $img . 'point-locker-cod.svg',
                'store'        => $img . 'point-store.svg',
                'marker'       => $vendor . 'leaflet/images/marker-icon.png',
                'markerShadow' => $vendor . 'leaflet/images/marker-shadow.png',
            ),
            'i18n'           => array(
                'title'       => __( 'Choose the ACS Point that suits you', 'wc-acs-courier' ),
                'search'      => __( 'Search by area, street or postcode', 'wc-acs-courier' ),
                'all'         => __( 'All', 'wc-acs-courier' ),
                'lockers'     => __( 'Lockers', 'wc-acs-courier' ),
                'stores'      => __( 'Stores', 'wc-acs-courier' ),
                'myLocation'  => __( 'My location', 'wc-acs-courier' ),
                'select'      => __( 'Select', 'wc-acs-courier' ),
                'change'      => __( 'Change', 'wc-acs-courier' ),
                'close'       => __( 'Close', 'wc-acs-courier' ),
                'loading'     => __( 'Loading points...', 'wc-acs-courier' ),
                'loadError'   => __( 'Could not load the ACS points. Please try again.', 'wc-acs-courier' ),
                'moreHint'    => __( 'Move the map to see more points', 'wc-acs-courier' ),
                'noMatches'   => __( 'No ACS Points match your search.', 'wc-acs-courier' ),
                'open24'      => __( '24/7', 'wc-acs-courier' ),
                'cod'         => __( 'Cash on delivery available', 'wc-acs-courier' ),
                'noCod'       => __( 'No cash on delivery', 'wc-acs-courier' ),
                'weekdays'    => __( 'Weekdays', 'wc-acs-courier' ),
                'saturday'    => __( 'Saturday', 'wc-acs-courier' ),
                'locateError' => __( 'Your location is not available.', 'wc-acs-courier' ),
                'locker'      => __( 'Locker', 'wc-acs-courier' ),
                'store'       => __( 'Store', 'wc-acs-courier' ),
            ),
        );
    }

    /**
     * Checkout assets only. Leaflet itself is loaded by acs-points.js on the
     * first click, so the checkout page weight does not change.
     */
    public function enqueue_assets() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
            return;
        }

        wp_enqueue_style( 'wc-acs-points', WC_ACS_PLUGIN_URL . 'assets/css/acs-points.css', array(), WC_ACS_VERSION );
        wp_enqueue_script( 'wc-acs-points', WC_ACS_PLUGIN_URL . 'assets/js/acs-points.js', array( 'jquery' ), WC_ACS_VERSION, true );
        wp_localize_script( 'wc-acs-points', 'wcAcsPoints', $this->script_settings() );
    }

    // ─── Store API (Blocks and express checkouts) ──────────────────

    public static function store_api_schema() {
        return array(
            'point_id' => array(
                'description' => __( 'Selected ACS Point id.', 'wc-acs-courier' ),
                'type'        => 'string',
                'readonly'    => true,
            ),
        );
    }

    public function register_store_api() {
        if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\StoreApi' ) || ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
            return;
        }

        woocommerce_store_api_register_endpoint_data( array(
            'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
            'namespace'       => self::STORE_API_NAMESPACE,
            'schema_callback' => array( __CLASS__, 'store_api_schema' ),
            'schema_type'     => ARRAY_A,
        ) );

        add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'save_from_store_api' ), 10, 2 );
    }

    /**
     * Validate and persist for a Store API order. Runs for express flows
     * that never execute our JS, which is why the rules live here too.
     *
     * @param WC_Order              $order   Order.
     * @param WP_REST_Request|array $request Request.
     * @throws Exception On a failed rule.
     */
    public function save_from_store_api( $order, $request ) {
        if ( ! self::order_has_points( $order ) ) {
            return;
        }

        $params = is_array( $request ) ? $request : $request->get_params();
        $id     = (string) ( $params['extensions'][ self::STORE_API_NAMESPACE ]['point_id'] ?? '' );

        if ( '' === $id && function_exists( 'WC' ) && WC()->session ) {
            $id = (string) WC()->session->get( self::SESSION_KEY, '' );
        }

        $point  = WC_ACS_Points_Feed::instance()->find( $id );
        $chosen = array();
        foreach ( $order->get_shipping_methods() as $item ) {
            $chosen[] = $item->get_method_id() . ':' . (int) $item->get_instance_id();
        }

        $errors = new WP_Error();
        self::validate( array(
            'shipping_method' => $chosen,
            'payment_method'  => $order->get_payment_method(),
            'billing_phone'   => $order->get_billing_phone(),
        ), $point, $errors );

        if ( $errors->has_errors() ) {
            $message = $errors->get_error_message();
            if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'wc_acs_point_invalid', $message, 400 );
            }
            throw new Exception( $message );
        }

        self::apply_point_to_order( $order, $point );
    }
}
