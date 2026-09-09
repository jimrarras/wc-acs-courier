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
        // Checkout hooks are added in Task 7.
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
            if ( 'cod' === ( $data['payment_method'] ?? '' ) && empty( $point['cod'] ) ) {
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
}
