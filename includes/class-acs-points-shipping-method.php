<?php
/**
 * "Pickup from ACS Point" shipping method.
 *
 * Zone-based flat cost with a free-delivery threshold and a weight cap. The
 * point itself is chosen by WC_ACS_Points_Picker; this class only prices and
 * offers the rate.
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Points_Shipping_Method extends WC_Shipping_Method {

    const METHOD_ID = 'acs_points';

    /** @var string Flat cost. */
    public $cost;

    /** @var string Subtotal at or above which the rate is free; empty disables. */
    public $free_min;

    /** @var string Max package weight in kg; 0 disables the cap. */
    public $max_weight;

    /** @var string 'both' or 'lockers'. */
    public $point_types;

    /** @var string 'terminal', 'stores' or 'off'. */
    public $cod_mode;

    /**
     * @param int $instance_id Shipping zone instance id.
     */
    public function __construct( $instance_id = 0 ) {
        $this->id                 = self::METHOD_ID;
        $this->instance_id        = absint( $instance_id );
        $this->method_title       = __( 'ACS Points', 'wc-acs-courier' );
        $this->method_description = __( 'Pickup from an ACS Smartpoint locker or an ACS store, chosen on a map at checkout.', 'wc-acs-courier' );
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        parent::__construct( $instance_id );
        $this->init();
    }

    private function init() {
        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'Pickup from ACS Point', 'wc-acs-courier' ) );
        $this->enabled     = $this->get_option( 'enabled', 'yes' );
        $this->tax_status  = $this->get_option( 'tax_status', 'none' );
        $this->cost        = $this->get_option( 'cost', '0' );
        $this->free_min    = $this->get_option( 'free_min', '' );
        $this->max_weight  = $this->get_option( 'max_weight', '6' );
        $this->point_types = $this->get_option( 'point_types', 'both' );
        $this->cod_mode    = $this->get_option( 'cod_mode', 'terminal' );

        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public function init_form_fields() {
        $this->instance_form_fields = array(
            'enabled'     => array(
                'title'   => __( 'Enable/Disable', 'wc-acs-courier' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this shipping method', 'wc-acs-courier' ),
                'default' => 'yes',
            ),
            'title'       => array(
                'title'       => __( 'Method Title', 'wc-acs-courier' ),
                'type'        => 'text',
                'description' => __( 'Shown to the customer at checkout.', 'wc-acs-courier' ),
                'default'     => __( 'Pickup from ACS Point', 'wc-acs-courier' ),
                'desc_tip'    => true,
            ),
            'cost'        => array(
                'title'       => __( 'Cost', 'wc-acs-courier' ),
                'type'        => 'price',
                'description' => __( 'Flat cost for pickup at an ACS Point.', 'wc-acs-courier' ),
                'default'     => '0',
                'desc_tip'    => true,
            ),
            'free_min'    => array(
                'title'       => __( 'Free Delivery Threshold', 'wc-acs-courier' ),
                'type'        => 'price',
                'description' => __( 'Order subtotal at or above which pickup is free. Leave empty to disable.', 'wc-acs-courier' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'max_weight'  => array(
                'title'       => __( 'Max Weight (kg)', 'wc-acs-courier' ),
                'type'        => 'decimal',
                'description' => __( 'The rate is not offered above this package weight. ACS standard lockers take up to 6 kg. 0 disables the cap.', 'wc-acs-courier' ),
                'default'     => '6',
                'desc_tip'    => true,
            ),
            'point_types' => array(
                'title'       => __( 'Point Types', 'wc-acs-courier' ),
                'type'        => 'select',
                'description' => __( 'Which ACS Points the customer may choose.', 'wc-acs-courier' ),
                'default'     => 'both',
                'options'     => array(
                    'both'    => __( 'Lockers and stores', 'wc-acs-courier' ),
                    'lockers' => __( 'Lockers only', 'wc-acs-courier' ),
                ),
                'desc_tip'    => true,
            ),
            'cod_mode'    => array(
                'title'       => __( 'Cash on Delivery', 'wc-acs-courier' ),
                'type'        => 'select',
                'description' => __( 'Where cash on delivery is offered for ACS Point pickup.', 'wc-acs-courier' ),
                'default'     => 'terminal',
                'options'     => array(
                    'terminal'  => __( 'Only at points with a card terminal (ACS data)', 'wc-acs-courier' ),
                    'stores'    => __( 'Never at lockers, allowed at ACS stores', 'wc-acs-courier' ),
                    'off'       => __( 'Never at any ACS Point', 'wc-acs-courier' ),
                    'exclusive' => __( 'Never at any ACS Point, and hide ACS Point while cash on delivery is selected', 'wc-acs-courier' ),
                ),
                'desc_tip'    => true,
            ),
            'tax_status'  => array(
                'title'   => __( 'Tax Status', 'wc-acs-courier' ),
                'type'    => 'select',
                'default' => 'none',
                'options' => array(
                    'taxable' => __( 'Taxable', 'wc-acs-courier' ),
                    'none'    => __( 'None', 'wc-acs-courier' ),
                ),
            ),
        );
    }

    /**
     * @param array $package Shipping package.
     */
    public function calculate_shipping( $package = array() ) {
        if ( WC_ACS_Points_Feed::instance()->count() < 1 ) {
            return;
        }

        $max = (float) $this->max_weight;
        if ( $max > 0 && self::package_weight_kg( $package ) > $max ) {
            return;
        }

        $cost     = (float) $this->cost;
        $free_min = (float) $this->free_min;
        $subtotal = (float) ( $package['contents_cost'] ?? 0 );

        if ( $free_min > 0 && $subtotal >= $free_min ) {
            $cost = 0;
        }

        $this->add_rate( array(
            'id'      => $this->get_rate_id(),
            'label'   => $this->title,
            'cost'    => $cost,
            'package' => $package,
        ) );
    }

    /**
     * Package weight in kg. Items without a weight count as the plugin's
     * default weight, the same assumption the voucher builder makes.
     *
     * @param array $package Shipping package.
     * @return float
     */
    public static function package_weight_kg( $package ) {
        $default = (float) get_option( 'wc_acs_default_weight', '0.5' );
        $total   = 0.0;

        foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
            $product = $item['data'] ?? null;
            $qty     = max( 1, (int) ( $item['quantity'] ?? 1 ) );
            $raw     = ( is_object( $product ) && is_callable( array( $product, 'get_weight' ) ) ) ? $product->get_weight() : '';
            $weight  = ( is_numeric( $raw ) && (float) $raw > 0 ) ? WC_ACS_Voucher::convert_weight_to_kg( (float) $raw ) : $default;
            $total  += $weight * $qty;
        }

        return $total;
    }
}
