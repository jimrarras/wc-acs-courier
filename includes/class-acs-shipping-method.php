<?php
/**
 * ACS Courier Shipping Method
 *
 * Provides real-time shipping cost calculation at checkout via the ACS Price Calculation API.
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Shipping_Method extends WC_Shipping_Method {

    /** @var string Rate type: 'api' or 'flat'. */
    public $fee_type;

    /** @var string Flat rate cost. */
    public $flat_rate;

    /** @var string Free shipping minimum order amount. */
    public $free_min;

    /** @var string Handling fee added on top of rate. */
    public $extra_fee;

    /** @var string Extra fee for COD orders. */
    public $cod_fee;

    /** @var string Fallback cost when API fails. */
    public $fallback_cost;

    /**
     * Constructor.
     *
     * @param int $instance_id Shipping method instance ID.
     */
    public function __construct( $instance_id = 0 ) {
        $this->id                 = 'acs_courier';
        $this->method_title       = __( 'ACS Courier', 'wc-acs-courier' );
        $this->method_description = __( 'Real-time shipping rates from ACS Courier based on your contract pricing.', 'wc-acs-courier' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        parent::__construct( $instance_id );
        $this->init();
    }

    /**
     * Initialize settings.
     */
    private function init() {
        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option( 'title', __( 'ACS Courier', 'wc-acs-courier' ) );
        $this->enabled      = $this->get_option( 'enabled', 'yes' );
        $this->tax_status   = $this->get_option( 'tax_status', 'none' );
        $this->fee_type     = $this->get_option( 'fee_type', 'api' );
        $this->flat_rate    = $this->get_option( 'flat_rate', '' );
        $this->free_min     = $this->get_option( 'free_min', '' );
        $this->extra_fee    = $this->get_option( 'extra_fee', '0' );
        $this->cod_fee      = $this->get_option( 'cod_fee', '0' );
        $this->fallback_cost = $this->get_option( 'fallback_cost', '5' );

        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    /**
     * Define settings fields.
     */
    public function init_form_fields() {
        $this->instance_form_fields = array(
            'title' => array(
                'title'       => __( 'Title', 'wc-acs-courier' ),
                'type'        => 'text',
                'description' => __( 'Title displayed at checkout.', 'wc-acs-courier' ),
                'default'     => __( 'ACS Courier', 'wc-acs-courier' ),
                'desc_tip'    => true,
            ),
            'tax_status' => array(
                'title'   => __( 'Tax Status', 'wc-acs-courier' ),
                'type'    => 'select',
                'default' => 'none',
                'options' => array(
                    'taxable' => __( 'Taxable', 'wc-acs-courier' ),
                    'none'    => __( 'None (VAT included in ACS rate)', 'wc-acs-courier' ),
                ),
            ),
            'fee_type' => array(
                'title'   => __( 'Rate Type', 'wc-acs-courier' ),
                'type'    => 'select',
                'default' => 'api',
                'options' => array(
                    'api'  => __( 'API Price Calculation (real-time from ACS)', 'wc-acs-courier' ),
                    'flat' => __( 'Flat Rate', 'wc-acs-courier' ),
                ),
            ),
            'flat_rate' => array(
                'title'       => __( 'Flat Rate (€)', 'wc-acs-courier' ),
                'type'        => 'text',
                'default'     => '',
                'description' => __( 'Used when Rate Type is Flat Rate.', 'wc-acs-courier' ),
                'desc_tip'    => true,
            ),
            'free_min' => array(
                'title'       => __( 'Free Shipping Minimum (€)', 'wc-acs-courier' ),
                'type'        => 'text',
                'default'     => '',
                'description' => __( 'Offer free shipping for orders above this amount. Leave empty to disable.', 'wc-acs-courier' ),
                'desc_tip'    => true,
            ),
            'extra_fee' => array(
                'title'       => __( 'Handling Fee (€)', 'wc-acs-courier' ),
                'type'        => 'text',
                'default'     => '0',
                'description' => __( 'Extra fee added on top of the ACS rate.', 'wc-acs-courier' ),
                'desc_tip'    => true,
            ),
            'cod_fee' => array(
                'title'       => __( 'COD Extra Fee (€)', 'wc-acs-courier' ),
                'type'        => 'text',
                'default'     => '0',
                'description' => __( 'Extra fee for Cash on Delivery orders.', 'wc-acs-courier' ),
                'desc_tip'    => true,
            ),
            'fallback_cost' => array(
                'title'       => __( 'Fallback Cost (€)', 'wc-acs-courier' ),
                'type'        => 'text',
                'default'     => '5',
                'description' => __( 'Cost to use if API calculation fails.', 'wc-acs-courier' ),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Calculate shipping rates.
     *
     * @param array $package Shipping package.
     */
    public function calculate_shipping( $package = array() ) {
        $cost = 0;

        // Check free shipping threshold
        $free_min = floatval( $this->free_min );
        if ( $free_min > 0 && $package['contents_cost'] >= $free_min ) {
            $this->add_rate( array(
                'id'    => $this->get_rate_id(),
                'label' => $this->title . ' (' . __( 'Free', 'wc-acs-courier' ) . ')',
                'cost'  => 0,
            ) );
            return;
        }

        if ( 'flat' === $this->fee_type ) {
            $cost = floatval( $this->flat_rate );
        } else {
            // API price calculation
            $cost = $this->calculate_api_rate( $package );
        }

        // Add handling fee
        $cost += floatval( $this->extra_fee );

        // Add COD fee if applicable
        $chosen_payment = WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
        if ( 'cod' === $chosen_payment ) {
            $cost += floatval( $this->cod_fee );
        }

        $this->add_rate( array(
            'id'    => $this->get_rate_id(),
            'label' => $this->title,
            'cost'  => $cost,
        ) );
    }

    /**
     * Calculate rate via ACS API.
     *
     * @param array $package Shipping package.
     * @return float
     */
    private function calculate_api_rate( $package ) {
        $destination = $package['destination'] ?? array();
        $postcode    = $destination['postcode'] ?? '';
        $country     = $destination['country'] ?? 'GR';

        if ( empty( $postcode ) ) {
            return floatval( $this->fallback_cost );
        }

        // Only GR is supported for price calculation
        if ( 'GR' !== $country ) {
            return floatval( $this->fallback_cost );
        }

        // Calculate total weight
        $total_weight = 0;
        foreach ( $package['contents'] as $item ) {
            $product = $item['data'];
            $weight  = $product->get_weight();
            if ( $weight ) {
                $total_weight += floatval( $weight ) * $item['quantity'];
            }
        }

        // Convert from WooCommerce weight unit to kg (ACS requires kg)
        $total_weight = WC_ACS_Voucher::convert_weight_to_kg( $total_weight );

        $default_weight = floatval( get_option( 'wc_acs_default_weight', '0.5' ) );
        $total_weight   = max( 0.5, $total_weight ?: $default_weight );

        // Get destination station
        $station_destination = $this->get_destination_station( $postcode );

        if ( ! $station_destination ) {
            return floatval( $this->fallback_cost );
        }

        // Determine delivery products (needed for cache key and API call)
        $products = null;
        $chosen_payment = WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
        if ( 'cod' === $chosen_payment ) {
            $products = 'COD';
        }

        // Cache key includes COD flag to avoid stale rates when payment method changes
        $cache_key = 'acs_rate_' . md5( $station_destination . '_' . $total_weight . '_' . gmdate( 'Y-m-d' ) . '_' . ( $products ?: 'none' ) );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return floatval( $cached );
        }

        $params = array(
            'Acs_Station_Destination' => $station_destination,
            'Weight'                  => str_replace( '.', ',', strval( $total_weight ) ),
            'Acs_Delivery_Products'   => $products,
        );

        $result = WC_ACS_API::price_calculation( $params );

        if ( is_wp_error( $result ) ) {
            WC_ACS_API::log( 'Price calculation failed: ' . $result->get_error_message(), 'warning' );
            return floatval( $this->fallback_cost );
        }

        $total = $result['ACSValueOutput'][0]['Total_Ammount'] ?? null;
        $vat   = $result['ACSValueOutput'][0]['Total_Vat_Ammount'] ?? 0;

        if ( null === $total ) {
            return floatval( $this->fallback_cost );
        }

        // ACS returns net + VAT separately. If tax_status is 'none', include VAT.
        $rate = ( 'none' === $this->tax_status )
            ? floatval( $total ) + floatval( $vat )
            : floatval( $total );

        // Cache for 1 hour
        set_transient( $cache_key, $rate, HOUR_IN_SECONDS );

        return $rate;
    }

    /**
     * Get the ACS station ID for a destination postcode.
     *
     * @param string $postcode Destination postcode.
     * @return string|null Station ID (Greek characters).
     */
    private function get_destination_station( $postcode ) {
        $cache_key = 'acs_station_' . $postcode;
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            // '_none' is our sentinel for "no station found"
            return '_none' === $cached ? null : $cached;
        }

        $station = WC_ACS_API::get_station_for_zipcode( $postcode, 'GR' );

        // Cache both hits (24h) and misses (1h) to avoid repeated API calls
        if ( $station ) {
            set_transient( $cache_key, $station, DAY_IN_SECONDS );
        } else {
            set_transient( $cache_key, '_none', HOUR_IN_SECONDS );
        }

        return $station;
    }
}
