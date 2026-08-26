<?php
/**
 * ACS Tracking & Auto-Status Updates
 *
 * Periodically checks shipment status via ACS API and updates WooCommerce order statuses.
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Tracking {

    private static $instance = null;

    /**
     * Shipment status code mapping.
     * Status 4 = delivered, Status 1 = refused.
     */
    const STATUS_DELIVERED = 4;
    const STATUS_REFUSED   = 1;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Register cron hook
        add_action( 'wc_acs_tracking_cron', array( $this, 'run_tracking' ) );

        // Reschedule if frequency changes
        add_action( 'update_option_wc_acs_tracking_frequency', array( $this, 'reschedule_cron' ), 10, 2 );

        // Display tracking info on order details page (frontend)
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_tracking_frontend' ) );

        // Add tracking info to order emails
        add_action( 'woocommerce_email_order_meta', array( $this, 'add_tracking_to_email' ), 10, 3 );

        // Frontend shortcode for tracking
        add_shortcode( 'acs_tracking', array( $this, 'tracking_shortcode' ) );
    }

    /**
     * Run auto-tracking for all orders with vouchers that haven't been delivered.
     */
    public function run_tracking() {
        if ( 'yes' !== get_option( 'wc_acs_auto_tracking', 'yes' ) ) {
            return;
        }

        WC_ACS_API::log( 'Starting auto-tracking cron job.' );

        // Get orders with vouchers that are not yet delivered/denied
        $args = array(
            'status'     => array( 'processing', 'on-hold', 'completed' ),
            'limit'      => 50,
            'orderby'    => 'date',
            'order'      => 'ASC',
            'meta_query' => array(
                array(
                    'key'     => '_acs_voucher_no',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key'     => '_acs_tracking_final',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        );

        $orders = wc_get_orders( $args );

        if ( empty( $orders ) ) {
            WC_ACS_API::log( 'No orders to track.' );
            return;
        }

        $tracked = 0;

        foreach ( $orders as $order ) {
            $voucher_no = $order->get_meta( '_acs_voucher_no' );
            if ( ! $voucher_no ) {
                continue;
            }

            $result = WC_ACS_API::tracking_summary( $voucher_no );

            if ( is_wp_error( $result ) ) {
                WC_ACS_API::log( "Tracking failed for voucher {$voucher_no}: " . $result->get_error_message(), 'warning' );
                continue;
            }

            $table_data = $result['ACSTableOutput']['Table_Data'] ?? array();
            if ( empty( $table_data ) ) {
                continue;
            }

            $tracking = $table_data[0];
            $status   = intval( $tracking['shipment_status'] ?? 0 );
            $info     = $tracking['delivery_info'] ?? '';

            // Check if status has actually changed before adding notes
            $previous_status = intval( $order->get_meta( '_acs_shipment_status' ) );
            $previous_info   = $order->get_meta( '_acs_tracking_status' );
            $status_changed  = ( $status !== $previous_status ) || ( $info !== $previous_info );

            // Update tracking status meta
            $order->update_meta_data( '_acs_tracking_status', $info );
            $order->update_meta_data( '_acs_shipment_status', $status );

            // Check if delivered
            if ( self::STATUS_DELIVERED === $status || 1 === intval( $tracking['delivery_flag'] ?? 0 ) ) {
                $order->update_meta_data( '_acs_tracking_final', 'delivered' );
                $order->set_status( 'acs-delivered', __( 'ACS: Shipment delivered.', 'wc-acs-courier' ) );
                $order->add_order_note(
                    /* translators: %s: delivery info */
                    sprintf( __( 'ACS Tracking: Delivered — %s', 'wc-acs-courier' ), $info )
                );
                WC_ACS_API::log( "Order #{$order->get_id()} marked as delivered." );
            }
            // Check if returned/refused
            elseif ( self::STATUS_REFUSED === $status || 1 === intval( $tracking['returned_flag'] ?? 0 ) ) {
                $reason = $tracking['non_delivery_reason_code'] ?? '';
                $order->update_meta_data( '_acs_tracking_final', 'denied' );
                $order->set_status( 'acs-denied', __( 'ACS: Delivery denied/returned.', 'wc-acs-courier' ) );
                $order->add_order_note(
                    /* translators: 1: delivery info, 2: reason code */
                    sprintf( __( 'ACS Tracking: Denied — %1$s (Reason: %2$s)', 'wc-acs-courier' ), $info, $reason )
                );
                WC_ACS_API::log( "Order #{$order->get_id()} marked as denied. Reason: {$reason}" );
            }
            // In transit — only add note when status changed
            elseif ( $status_changed ) {
                $order->add_order_note(
                    /* translators: %s: delivery info */
                    sprintf( __( 'ACS Tracking update: %s', 'wc-acs-courier' ), $info )
                );
            }

            $order->save();
            $tracked++;

            // Small delay to respect API rate limit (10 calls/sec)
            usleep( 150000 ); // 150ms
        }

        WC_ACS_API::log( "Auto-tracking complete. Tracked {$tracked} orders." );
    }

    /**
     * Reschedule cron when frequency setting changes.
     *
     * @param mixed $old_value Old option value.
     * @param mixed $new_value New option value.
     */
    public function reschedule_cron( $old_value, $new_value ) {
        wp_clear_scheduled_hook( 'wc_acs_tracking_cron' );

        if ( 'yes' === get_option( 'wc_acs_auto_tracking', 'yes' ) ) {
            wp_schedule_event( time(), $new_value, 'wc_acs_tracking_cron' );
        }
    }

    /**
     * Display tracking information on the frontend order details page.
     *
     * @param WC_Order $order Order object.
     */
    public function display_tracking_frontend( $order ) {
        $voucher_no = $order->get_meta( '_acs_voucher_no' );

        if ( ! $voucher_no ) {
            return;
        }

        $tracking_status = $order->get_meta( '_acs_tracking_status' );
        $tracking_url    = 'https://www.acscourier.net/el/track-and-trace/?generalCode=' . urlencode( $voucher_no );
        ?>
        <h2><?php esc_html_e( 'Shipment Tracking', 'wc-acs-courier' ); ?></h2>
        <table class="woocommerce-table shop_table acs-tracking-table">
            <tbody>
                <tr>
                    <th><?php esc_html_e( 'Courier', 'wc-acs-courier' ); ?></th>
                    <td>ACS Courier</td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Tracking Number', 'wc-acs-courier' ); ?></th>
                    <td>
                        <a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html( $voucher_no ); ?>
                        </a>
                    </td>
                </tr>
                <?php if ( $tracking_status ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Status', 'wc-acs-courier' ); ?></th>
                    <td><?php echo esc_html( $tracking_status ); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Add tracking info to WooCommerce order emails.
     *
     * @param WC_Order $order         Order object.
     * @param bool     $sent_to_admin Whether sent to admin.
     * @param bool     $plain_text    Whether plain text email.
     */
    public function add_tracking_to_email( $order, $sent_to_admin, $plain_text ) {
        $voucher_no = $order->get_meta( '_acs_voucher_no' );

        if ( ! $voucher_no ) {
            return;
        }

        $tracking_url = 'https://www.acscourier.net/el/track-and-trace/?generalCode=' . urlencode( $voucher_no );

        if ( $plain_text ) {
            echo "\n" . esc_html__( 'ACS Courier Tracking:', 'wc-acs-courier' ) . ' ' . esc_html( $voucher_no ) . "\n";
            echo esc_html__( 'Track your shipment:', 'wc-acs-courier' ) . ' ' . esc_url( $tracking_url ) . "\n";
        } else {
            echo '<h2>' . esc_html__( 'Shipment Tracking', 'wc-acs-courier' ) . '</h2>';
            echo '<p><strong>' . esc_html__( 'ACS Courier:', 'wc-acs-courier' ) . '</strong> ';
            echo '<a href="' . esc_url( $tracking_url ) . '">' . esc_html( $voucher_no ) . '</a></p>';
        }
    }

    /**
     * Tracking shortcode for frontend pages.
     * Usage: [acs_tracking]
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function tracking_shortcode( $atts ) {
        ob_start();

        $voucher_no = isset( $_GET['tracking'] ) ? sanitize_text_field( wp_unslash( $_GET['tracking'] ) ) : '';

        ?>
        <div class="wc-acs-tracking-form">
            <form method="get">
                <label for="acs-tracking-input"><?php esc_html_e( 'Track your ACS shipment:', 'wc-acs-courier' ); ?></label>
                <input type="text" id="acs-tracking-input" name="tracking"
                       value="<?php echo esc_attr( $voucher_no ); ?>"
                       placeholder="<?php esc_attr_e( 'Enter tracking number', 'wc-acs-courier' ); ?>" />
                <button type="submit" class="button"><?php esc_html_e( 'Track', 'wc-acs-courier' ); ?></button>
            </form>

            <?php
            if ( $voucher_no ) {
                $tracking_url = 'https://www.acscourier.net/el/track-and-trace/?generalCode=' . urlencode( $voucher_no );
                echo '<p><a href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener">';
                echo esc_html__( 'Track your shipment on the ACS website', 'wc-acs-courier' );
                echo '</a></p>';
            }
            ?>
        </div>
        <?php

        return ob_get_clean();
    }
}
