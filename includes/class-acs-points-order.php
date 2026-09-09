<?php
/**
 * ACS Point on the order: admin box with a change control, customer views,
 * email line.
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Points_Order {

    /** @var WC_ACS_Points_Order|null */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'render_admin_box' ) );
        add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'render_customer_details' ) );
        add_action( 'woocommerce_email_order_meta', array( $this, 'render_email_meta' ), 20, 3 );

        add_action( 'wp_ajax_wc_acs_search_points', array( $this, 'ajax_search_points' ) );
        add_action( 'wp_ajax_wc_acs_set_order_point', array( $this, 'ajax_set_order_point' ) );
    }

    /**
     * @param WC_Order $order Order.
     * @return bool
     */
    public static function can_change_point( $order ) {
        return '' === trim( (string) $order->get_meta( '_acs_voucher_no' ) );
    }

    /**
     * @param WC_Order $order Order.
     * @return string "name, address" or empty when the order has no point.
     */
    public static function summary_line( $order ) {
        $name = trim( (string) $order->get_meta( '_acs_point_name' ) );
        if ( '' === $name ) {
            return '';
        }
        $address = trim( (string) $order->get_meta( '_acs_point_address' ) );
        return '' === $address ? $name : $name . ', ' . $address;
    }

    /**
     * Plain link, no request until clicked.
     *
     * @param WC_Order $order Order.
     * @return string
     */
    public static function maps_url( $order ) {
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( self::summary_line( $order ) );
    }

    // ─── Admin ─────────────────────────────────────────────────────

    /**
     * @param WC_Order $order Order.
     */
    public function render_admin_box( $order ) {
        $summary = self::summary_line( $order );
        if ( '' === $summary ) {
            return;
        }
        $type = (string) $order->get_meta( '_acs_point_type' );
        $code = (string) $order->get_meta( '_acs_point_station' ) . (string) $order->get_meta( '_acs_point_branch' );
        $cod  = '1' === (string) $order->get_meta( '_acs_point_cod' );
        ?>
        <div class="wc-acs-point-admin" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" style="margin-top:15px; padding:10px; background:#f0f6fc; border-left:4px solid #2271b1;">
            <p style="margin:0 0 6px;">
                <strong><?php esc_html_e( 'ACS Point', 'wc-acs-courier' ); ?></strong>
                (<?php echo esc_html( 'store' === $type ? __( 'Store', 'wc-acs-courier' ) : __( 'Locker', 'wc-acs-courier' ) ); ?>)<br />
                <span class="wc-acs-point-summary"><?php echo esc_html( $summary ); ?></span><br />
                <small style="color:#666;"><?php echo esc_html( $code ); ?> ·
                    <?php echo esc_html( $cod ? __( 'Cash on delivery available', 'wc-acs-courier' ) : __( 'No cash on delivery', 'wc-acs-courier' ) ); ?> ·
                    <a href="<?php echo esc_url( self::maps_url( $order ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on map', 'wc-acs-courier' ); ?></a>
                </small>
            </p>
            <?php if ( self::can_change_point( $order ) ) : ?>
                <p style="margin:0;">
                    <input type="text" class="wc-acs-point-search regular-text" placeholder="<?php esc_attr_e( 'Change point: type an area, street or postcode', 'wc-acs-courier' ); ?>" autocomplete="off" />
                </p>
                <ul class="wc-acs-point-results" hidden></ul>
            <?php else : ?>
                <p class="description" style="margin:0;"><?php esc_html_e( 'Delete the voucher to change the point.', 'wc-acs-courier' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: up to 20 points matching a free-text query (accent-insensitive).
     */
    public function ajax_search_points() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        $q       = self::fold( isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '' );
        $matches = array();

        if ( '' !== $q ) {
            foreach ( WC_ACS_Points_Feed::instance()->get_points() as $point ) {
                $haystack = self::fold( $point['name'] . ' ' . $point['street'] . ' ' . $point['city'] . ' ' . $point['zip'] );
                if ( false !== mb_strpos( $haystack, $q ) ) {
                    $matches[] = array(
                        'id'    => $point['id'],
                        'type'  => $point['type'],
                        'label' => $point['name'] . ', ' . WC_ACS_Points_Picker::format_address( $point ),
                    );
                    if ( count( $matches ) >= 20 ) {
                        break;
                    }
                }
            }
        }

        wp_send_json_success( array( 'points' => $matches ) );
    }

    /**
     * Lowercase, accents stripped (Greek tonos included), for matching.
     *
     * @param string $text Text.
     * @return string
     */
    public static function fold( $text ) {
        $text = mb_strtolower( (string) $text, 'UTF-8' );
        $map  = array( 'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω', 'ς' => 'σ' );
        return trim( strtr( $text, $map ) );
    }

    /**
     * AJAX: change the point on an order that has no voucher yet.
     */
    public function ajax_set_order_point() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        $order = wc_get_order( intval( $_POST['order_id'] ?? 0 ) );
        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wc-acs-courier' ) );
        }
        if ( ! self::can_change_point( $order ) ) {
            wp_send_json_error( __( 'Delete the voucher to change the point.', 'wc-acs-courier' ) );
        }

        $point = WC_ACS_Points_Feed::instance()->find( isset( $_POST['point_id'] ) ? sanitize_text_field( wp_unslash( $_POST['point_id'] ) ) : '' );
        if ( null === $point ) {
            wp_send_json_error( __( 'Unknown ACS Point.', 'wc-acs-courier' ) );
        }

        WC_ACS_Points_Picker::apply_point_to_order( $order, $point );
        $order->add_order_note(
            /* translators: %s: point name and address */
            sprintf( __( 'ACS Point changed to: %s', 'wc-acs-courier' ), $point['name'] . ', ' . WC_ACS_Points_Picker::format_address( $point ) )
        );
        $order->save();

        wp_send_json_success( array(
            'summary' => $point['name'] . ', ' . WC_ACS_Points_Picker::format_address( $point ),
        ) );
    }

    // ─── Customer ──────────────────────────────────────────────────

    /**
     * Thank-you page and My Account order view.
     *
     * @param WC_Order $order Order.
     */
    public function render_customer_details( $order ) {
        $summary = self::summary_line( $order );
        if ( '' === $summary ) {
            return;
        }
        ?>
        <section class="wc-acs-point-customer">
            <h2 class="woocommerce-column__title"><?php esc_html_e( 'Pickup point', 'wc-acs-courier' ); ?></h2>
            <p>
                <?php echo esc_html( $summary ); ?><br />
                <a href="<?php echo esc_url( self::maps_url( $order ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on map', 'wc-acs-courier' ); ?></a>
            </p>
        </section>
        <?php
    }

    /**
     * Line in every WooCommerce order email.
     *
     * @param WC_Order $order         Order.
     * @param bool     $sent_to_admin Admin copy.
     * @param bool     $plain_text    Plain text email.
     */
    public function render_email_meta( $order, $sent_to_admin, $plain_text ) {
        $summary = self::summary_line( $order );
        if ( '' === $summary ) {
            return;
        }

        if ( $plain_text ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body, not HTML.
            echo "\n" . __( 'Pickup from:', 'wc-acs-courier' ) . ' ' . $summary . "\n";
            echo esc_url_raw( self::maps_url( $order ) ) . "\n";
            return;
        }

        echo '<p><strong>' . esc_html__( 'Pickup from:', 'wc-acs-courier' ) . '</strong> ' . esc_html( $summary );
        echo ' <a href="' . esc_url( self::maps_url( $order ) ) . '">' . esc_html__( 'View on map', 'wc-acs-courier' ) . '</a></p>';
    }
}
