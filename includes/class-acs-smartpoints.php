<?php
/**
 * ACS Smartpoints Integration
 *
 * Enables customers to select ACS Smartpoint lockers and stores as pickup locations at checkout.
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Smartpoints {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Add pickup point selector after shipping methods at checkout
        add_action( 'woocommerce_after_shipping_rate', array( $this, 'render_smartpoint_selector' ), 10, 2 );

        // Validate smartpoint selection at checkout
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_smartpoint_selection' ) );

        // Save selected smartpoint to order
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_smartpoint_to_order' ), 10, 2 );

        // AJAX: Get smartpoints
        add_action( 'wp_ajax_wc_acs_get_smartpoints', array( $this, 'ajax_get_smartpoints' ) );
        add_action( 'wp_ajax_nopriv_wc_acs_get_smartpoints', array( $this, 'ajax_get_smartpoints' ) );

        // Enqueue frontend assets
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // Show selected smartpoint on order admin
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'display_smartpoint_admin' ) );
    }

    /**
     * Enqueue frontend assets on checkout page.
     */
    public function enqueue_frontend_assets() {
        if ( ! is_checkout() ) {
            return;
        }

        wp_enqueue_style(
            'wc-acs-smartpoints',
            WC_ACS_PLUGIN_URL . 'assets/css/acs-smartpoints.css',
            array(),
            WC_ACS_VERSION
        );

        wp_enqueue_script(
            'wc-acs-smartpoints',
            WC_ACS_PLUGIN_URL . 'assets/js/acs-smartpoints.js',
            array( 'jquery' ),
            WC_ACS_VERSION,
            true
        );

        wp_localize_script( 'wc-acs-smartpoints', 'wc_acs_sp', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wc_acs_smartpoints_nonce' ),
            'i18n'     => array(
                'loading'      => __( 'Loading ACS pickup points...', 'wc-acs-courier' ),
                'select_point' => __( 'Select a pickup point', 'wc-acs-courier' ),
                'selected'     => __( 'Selected:', 'wc-acs-courier' ),
                'search'       => __( 'Search by area or zip code...', 'wc-acs-courier' ),
                'no_results'   => __( 'No pickup points found for this area.', 'wc-acs-courier' ),
                'locker'       => __( 'Smartpoint Locker', 'wc-acs-courier' ),
                'store'        => __( 'ACS Store', 'wc-acs-courier' ),
            ),
        ) );
    }

    /**
     * Render the smartpoint selector after the ACS shipping rate.
     *
     * @param WC_Shipping_Rate $method Shipping rate.
     * @param int              $index  Rate index.
     */
    public function render_smartpoint_selector( $method, $index ) {
        // Only show for ACS shipping method
        if ( 'acs_courier' !== $method->method_id ) {
            return;
        }

        // Only show if this rate is selected
        $chosen_methods = WC()->session ? WC()->session->get( 'chosen_shipping_methods', array() ) : array();
        $chosen         = $chosen_methods[0] ?? '';

        if ( $method->id !== $chosen ) {
            return;
        }

        ?>
        <div id="wc-acs-smartpoint-container" class="wc-acs-smartpoint-wrapper" style="margin-top:12px;">
            <p class="wc-acs-smartpoint-toggle">
                <label>
                    <input type="checkbox" id="wc-acs-use-smartpoint" name="acs_use_smartpoint" value="1" />
                    <?php esc_html_e( 'Pick up from ACS Smartpoint / Store', 'wc-acs-courier' ); ?>
                </label>
            </p>

            <div id="wc-acs-smartpoint-picker" style="display:none;">
                <input type="text" id="wc-acs-smartpoint-search"
                       placeholder="<?php esc_attr_e( 'Search by area or zip code...', 'wc-acs-courier' ); ?>"
                       class="input-text" />

                <div id="wc-acs-smartpoint-list" class="wc-acs-smartpoint-list"></div>

                <input type="hidden" id="wc-acs-smartpoint-id" name="acs_smartpoint_id" value="" />
                <input type="hidden" id="wc-acs-smartpoint-name" name="acs_smartpoint_name" value="" />
                <input type="hidden" id="wc-acs-smartpoint-address" name="acs_smartpoint_address" value="" />
                <input type="hidden" id="wc-acs-smartpoint-zipcode" name="acs_smartpoint_zipcode" value="" />

                <div id="wc-acs-smartpoint-selected" class="wc-acs-smartpoint-selected" style="display:none;">
                    <strong><?php esc_html_e( 'Pickup point:', 'wc-acs-courier' ); ?></strong>
                    <span id="wc-acs-smartpoint-selected-name"></span>
                    <br />
                    <small id="wc-acs-smartpoint-selected-address"></small>
                    <button type="button" class="button button-small" id="wc-acs-smartpoint-change">
                        <?php esc_html_e( 'Change', 'wc-acs-courier' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Get smartpoints for a zip code or area.
     */
    public function ajax_get_smartpoints() {
        check_ajax_referer( 'wc_acs_smartpoints_nonce', 'nonce' );

        $search  = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
        $country = sanitize_text_field( wp_unslash( $_POST['country'] ?? 'GR' ) );

        // Get cached smartpoints
        $cache_key   = 'acs_smartpoints_' . $country;
        $smartpoints = get_transient( $cache_key );

        if ( false === $smartpoints ) {
            $smartpoints = WC_ACS_API::get_smartpoints( $country );

            // Cache both populated (12h) and empty results (1h) to avoid repeated API calls
            $ttl = ! empty( $smartpoints ) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS;
            set_transient( $cache_key, $smartpoints ?: array(), $ttl );
        }

        if ( empty( $smartpoints ) ) {
            wp_send_json_success( array( 'points' => array() ) );
        }

        // Filter by search term
        $filtered = array();
        $search_lower = mb_strtolower( $search );

        foreach ( $smartpoints as $point ) {
            if ( empty( $search ) ) {
                $filtered[] = $this->format_smartpoint( $point );
                continue;
            }

            $area    = mb_strtolower( $point['ACS_SHOP_STATION_DESCR'] ?? '' );
            $address = mb_strtolower( $point['ACS_SHOP_ADDRESS'] ?? '' );
            $zip     = $point['ACS_SHOP_ZIPCODE'] ?? '';

            if (
                false !== mb_strpos( $area, $search_lower ) ||
                false !== mb_strpos( $address, $search_lower ) ||
                false !== mb_strpos( $zip, $search_lower )
            ) {
                $filtered[] = $this->format_smartpoint( $point );
            }
        }

        // Limit results
        $filtered = array_slice( $filtered, 0, 30 );

        wp_send_json_success( array( 'points' => $filtered ) );
    }

    /**
     * Format a smartpoint for the frontend.
     *
     * @param array $point Raw smartpoint data.
     * @return array Formatted data.
     */
    private function format_smartpoint( $point ) {
        return array(
            'id'       => $point['ACS_SHOP_ID_CODE'] ?? '',
            'name'     => $point['ACS_SHOP_STATION_DESCR'] ?? '',
            'address'  => $point['ACS_SHOP_ADDRESS'] ?? '',
            'zipcode'  => $point['ACS_SHOP_ZIPCODE'] ?? '',
            'phone'    => $point['ACS_SHOP_PHONES'] ?? '',
            'hours'    => $point['ACS_SHOP_WORKING_HOURS'] ?? '',
            'hours_sat' => $point['ACS_SHOP_WORKING_HOURS_SATURDAY'] ?? '',
            'lat'      => $point['ACS_SHOP_LAT'] ?? '',
            'lng'      => $point['ACS_SHOP_LONG'] ?? '',
            'type'     => $point['_type'] ?? 'store',
            'kind'     => $point['ACS_SHOP_KIND'] ?? '',
        );
    }

    /**
     * Validate smartpoint selection at checkout.
     */
    public function validate_smartpoint_selection() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce checkout.
        if ( ! empty( $_POST['acs_use_smartpoint'] ) && empty( $_POST['acs_smartpoint_id'] ) ) {
            wc_add_notice(
                __( 'Please select an ACS pickup point or uncheck the Smartpoint option.', 'wc-acs-courier' ),
                'error'
            );
        }
        // phpcs:enable
    }

    /**
     * Save selected smartpoint to order.
     *
     * @param WC_Order $order Order being created.
     * @param array    $data  Checkout data.
     */
    public function save_smartpoint_to_order( $order, $data ) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce checkout.
        if ( ! empty( $_POST['acs_use_smartpoint'] ) && ! empty( $_POST['acs_smartpoint_id'] ) ) {
            $order->update_meta_data( '_acs_smartpoint_id', sanitize_text_field( wp_unslash( $_POST['acs_smartpoint_id'] ) ) );
            $order->update_meta_data( '_acs_smartpoint_name', sanitize_text_field( wp_unslash( $_POST['acs_smartpoint_name'] ?? '' ) ) );
            $order->update_meta_data( '_acs_smartpoint_address', sanitize_text_field( wp_unslash( $_POST['acs_smartpoint_address'] ?? '' ) ) );
            $order->update_meta_data( '_acs_smartpoint_zipcode', sanitize_text_field( wp_unslash( $_POST['acs_smartpoint_zipcode'] ?? '' ) ) );
        }
        // phpcs:enable
    }

    /**
     * Display selected smartpoint on the admin order page.
     *
     * @param WC_Order $order Order object.
     */
    public function display_smartpoint_admin( $order ) {
        $smartpoint_id      = $order->get_meta( '_acs_smartpoint_id' );
        $smartpoint_name    = $order->get_meta( '_acs_smartpoint_name' );
        $smartpoint_address = $order->get_meta( '_acs_smartpoint_address' );

        if ( ! $smartpoint_id ) {
            return;
        }

        ?>
        <div class="wc-acs-smartpoint-admin" style="margin-top:15px; padding:10px; background:#f0f6fc; border-left:4px solid #2271b1;">
            <p style="margin:0;">
                <strong><?php esc_html_e( 'ACS Smartpoint Pickup:', 'wc-acs-courier' ); ?></strong><br />
                <?php echo esc_html( $smartpoint_name ); ?><br />
                <small><?php echo esc_html( $smartpoint_address ); ?></small><br />
                <small style="color:#666;">ID: <?php echo esc_html( $smartpoint_id ); ?></small>
            </p>
        </div>
        <?php
    }
}
