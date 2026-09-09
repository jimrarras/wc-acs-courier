<?php
/**
 * ACS Voucher Management
 *
 * Handles voucher creation, printing, deletion, and the order metabox UI.
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Voucher {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Order metabox
        add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );

        // AJAX handlers
        add_action( 'wp_ajax_wc_acs_create_voucher', array( $this, 'ajax_create_voucher' ) );
        add_action( 'wp_ajax_wc_acs_print_voucher', array( $this, 'ajax_print_voucher' ) );
        add_action( 'wp_ajax_wc_acs_delete_voucher', array( $this, 'ajax_delete_voucher' ) );
        add_action( 'wp_ajax_wc_acs_track_voucher', array( $this, 'ajax_track_voucher' ) );
        add_action( 'wp_ajax_wc_acs_issue_pickup_list', array( $this, 'ajax_issue_pickup_list' ) );
        add_action( 'wp_ajax_wc_acs_print_pickup_list', array( $this, 'ajax_print_pickup_list' ) );

        // Auto-create voucher on status change
        add_action( 'woocommerce_order_status_changed', array( $this, 'auto_create_voucher' ), 10, 3 );

        // Bulk actions
        add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_bulk_actions' ) );
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'register_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_actions' ), 10, 3 );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_actions' ), 10, 3 );

        // Add voucher column to orders list (legacy)
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_voucher_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_voucher_column' ), 10, 2 );

        // Add voucher column to orders list (HPOS)
        add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_voucher_column' ) );
        add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_voucher_column_hpos' ), 10, 2 );

        // Admin notice for bulk action results
        add_action( 'admin_notices', array( $this, 'bulk_action_notices' ) );
    }

    /**
     * Add metabox to order edit page.
     */
    public function add_metabox() {
        $screen = function_exists( 'wc_get_page_screen_id' )
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'wc_acs_voucher',
            __( 'ACS Courier', 'wc-acs-courier' ),
            array( $this, 'render_metabox' ),
            $screen,
            'side',
            'high'
        );

        // Also add for the legacy post type screen (only if HPOS screen differs)
        if ( 'shop_order' !== $screen ) {
            add_meta_box(
                'wc_acs_voucher',
                __( 'ACS Courier', 'wc-acs-courier' ),
                array( $this, 'render_metabox' ),
                'shop_order',
                'side',
                'high'
            );
        }
    }

    /**
     * Render the order metabox.
     *
     * @param WP_Post|WC_Order $post_or_order Order object or post.
     */
    public function render_metabox( $post_or_order ) {
        $order = ( $post_or_order instanceof WP_Post )
            ? wc_get_order( $post_or_order->ID )
            : $post_or_order;

        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Order not found.', 'wc-acs-courier' ) . '</p>';
            return;
        }

        $order_id   = $order->get_id();
        $voucher_no = $order->get_meta( '_acs_voucher_no' );
        $pickup_list = $order->get_meta( '_acs_pickup_list_no' );

        ?>
        <div id="wc-acs-voucher-box" data-order-id="<?php echo esc_attr( $order_id ); ?>">

            <?php if ( $voucher_no ) : ?>
                <p class="wc-acs-voucher-info">
                    <strong><?php esc_html_e( 'Voucher:', 'wc-acs-courier' ); ?></strong>
                    <code class="wc-acs-voucher-code"><?php echo esc_html( $voucher_no ); ?></code>
                </p>

                <?php
                $tracking_info = $order->get_meta( '_acs_tracking_status' );
                if ( $tracking_info ) :
                ?>
                    <p class="wc-acs-tracking-status">
                        <strong><?php esc_html_e( 'Status:', 'wc-acs-courier' ); ?></strong>
                        <?php echo esc_html( $tracking_info ); ?>
                    </p>
                <?php endif; ?>

                <div class="wc-acs-actions">
                    <button type="button" class="button wc-acs-print-voucher" data-type="2"
                            title="<?php esc_attr_e( 'Print A4 (Laser)', 'wc-acs-courier' ); ?>">
                        <span class="dashicons dashicons-printer"></span> <?php esc_html_e( 'A4', 'wc-acs-courier' ); ?>
                    </button>
                    <button type="button" class="button wc-acs-print-voucher" data-type="1"
                            title="<?php esc_attr_e( 'Print Thermal', 'wc-acs-courier' ); ?>">
                        <span class="dashicons dashicons-printer"></span> <?php esc_html_e( 'Thermal', 'wc-acs-courier' ); ?>
                    </button>
                    <button type="button" class="button wc-acs-track-voucher"
                            title="<?php esc_attr_e( 'Track Shipment', 'wc-acs-courier' ); ?>">
                        <span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Track', 'wc-acs-courier' ); ?>
                    </button>
                    <button type="button" class="button wc-acs-delete-voucher"
                            title="<?php esc_attr_e( 'Delete Voucher', 'wc-acs-courier' ); ?>">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>

                <?php if ( ! $pickup_list ) :
                    $voucher_date = $order->get_meta( '_acs_voucher_date' ) ?: current_time( 'Y-m-d' );
                ?>
                <div class="wc-acs-pickup-list" style="margin-top:10px;">
                    <button type="button" class="button button-primary wc-acs-issue-pickup-list">
                        <?php
                        /* translators: %s: pickup date */
                        printf( esc_html__( 'Issue Pickup List (%s)', 'wc-acs-courier' ), esc_html( $voucher_date ) );
                        ?>
                    </button>
                    <p class="description" style="margin-top:4px;">
                        <?php esc_html_e( 'Issues a pickup list for all vouchers on this date.', 'wc-acs-courier' ); ?>
                    </p>
                </div>
                <?php else : ?>
                <p class="wc-acs-pickup-info" style="margin-top:10px;">
                    <strong><?php esc_html_e( 'Pickup List:', 'wc-acs-courier' ); ?></strong>
                    <code><?php echo esc_html( $pickup_list ); ?></code>
                    <button type="button" class="button button-small wc-acs-print-pickup-list">
                        <span class="dashicons dashicons-printer"></span>
                    </button>
                </p>
                <?php endif; ?>

                <div id="wc-acs-tracking-details" style="display:none; margin-top:10px;"></div>

            <?php else : ?>
                <div class="wc-acs-create-section">
                    <?php $is_point_order = '' !== (string) $order->get_meta( '_acs_point_station' ); ?>
                    <p class="wc-acs-field">
                        <label><?php esc_html_e( 'Parcels', 'wc-acs-courier' ); ?></label>
                        <input type="number" id="wc-acs-item-qty" value="1" min="1" max="99" class="small-text"<?php echo $is_point_order ? ' disabled="disabled"' : ''; ?> />
                        <?php if ( $is_point_order ) : ?>
                            <span class="description"><?php esc_html_e( 'ACS Points accept one parcel per shipment.', 'wc-acs-courier' ); ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="wc-acs-field">
                        <label><?php esc_html_e( 'Weight (kg)', 'wc-acs-courier' ); ?></label>
                        <input type="number" id="wc-acs-weight" step="0.1" min="0.5"
                               value="<?php echo esc_attr( $this->calculate_order_weight( $order ) ); ?>"
                               class="small-text" />
                    </p>
                    <p class="wc-acs-field">
                        <label><?php esc_html_e( 'Extra Services', 'wc-acs-courier' ); ?></label>
                        <label><input type="checkbox" class="wc-acs-service" value="SAT" /> <?php esc_html_e( 'Saturday', 'wc-acs-courier' ); ?></label>
                        <label><input type="checkbox" class="wc-acs-service" value="MDV" /> <?php esc_html_e( 'Morning', 'wc-acs-courier' ); ?></label>
                        <label><input type="checkbox" class="wc-acs-service" value="RDO" /> <?php esc_html_e( 'Return Docs', 'wc-acs-courier' ); ?></label>
                    </p>
                    <p class="wc-acs-field">
                        <label><?php esc_html_e( 'Notes', 'wc-acs-courier' ); ?></label>
                        <input type="text" id="wc-acs-notes" class="widefat"
                               placeholder="<?php esc_attr_e( 'Delivery notes...', 'wc-acs-courier' ); ?>" />
                    </p>
                    <button type="button" class="button button-primary wc-acs-create-voucher">
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e( 'Create Voucher', 'wc-acs-courier' ); ?>
                    </button>
                </div>
            <?php endif; ?>

            <div class="wc-acs-notice" style="display:none;"></div>
        </div>
        <?php
    }

    /**
     * Calculate total weight for an order.
     *
     * @param WC_Order $order Order object.
     * @return float
     */
    private function calculate_order_weight( $order ) {
        $total_weight = 0;

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && $product->get_weight() ) {
                $total_weight += floatval( $product->get_weight() ) * $item->get_quantity();
            }
        }

        // Convert from WooCommerce weight unit to kg (ACS requires kg)
        $total_weight = self::convert_weight_to_kg( $total_weight );

        if ( $total_weight < 0.5 ) {
            $total_weight = floatval( get_option( 'wc_acs_default_weight', '0.5' ) );
        }

        return max( 0.5, round( $total_weight, 1 ) );
    }

    /**
     * Convert weight from the WooCommerce weight unit to kilograms.
     *
     * @param float $weight Weight in WooCommerce unit.
     * @return float Weight in kg.
     */
    public static function convert_weight_to_kg( $weight ) {
        if ( $weight <= 0 ) {
            return 0.0;
        }

        $unit = get_option( 'woocommerce_weight_unit', 'kg' );

        switch ( $unit ) {
            case 'g':
                return $weight / 1000;
            case 'lbs':
                return $weight * 0.453592;
            case 'oz':
                return $weight * 0.0283495;
            case 'kg':
            default:
                return $weight;
        }
    }

    /**
     * Build voucher parameters from an order.
     *
     * @param WC_Order $order      Order object.
     * @param array    $extra_params Additional parameters.
     * @return array|WP_Error
     */
    public function build_voucher_params( $order, $extra_params = array() ) {
        $shipping_address = $order->get_address( 'shipping' );
        $billing_address  = $order->get_address( 'billing' );

        // Use shipping address, fallback to billing
        $address = ! empty( $shipping_address['address_1'] ) ? $shipping_address : $billing_address;

        $recipient_name = trim( ( $address['first_name'] ?? '' ) . ' ' . ( $address['last_name'] ?? '' ) );
        $country        = $address['country'] ?? 'GR';

        // Map country codes
        $country_map = array( 'GR' => 'GR', 'CY' => 'CY', 'BG' => 'BG', 'AL' => 'AL' );
        $acs_country = $country_map[ $country ] ?? 'GR';

        // Determine COD
        $payment_method = $order->get_payment_method();
        $is_cod         = ( 'cod' === $payment_method );
        $cod_amount     = $is_cod ? floatval( $order->get_total() ) : null;
        $cod_way        = $is_cod ? 0 : null; // 0 = cash

        // Delivery products
        $products = array();
        if ( $is_cod ) {
            $products[] = 'COD';
        }

        // ACS Point delivery: routed by the two station codes, not by address.
        $point_station = (string) $order->get_meta( '_acs_point_station' );
        $point_branch  = (string) $order->get_meta( '_acs_point_branch' );
        $is_point      = ( '' !== $point_station && '' !== $point_branch );
        $mobile        = WC_ACS_Points_Picker::normalise_mobile( $order->get_billing_phone() );

        if ( $is_point && null === $mobile ) {
            return new WP_Error( 'acs_point_mobile', __( 'ACS Points need a Greek mobile number.', 'wc-acs-courier' ) );
        }

        $delivery_products = ! empty( $products ) ? implode( ',', $products ) : null;

        // Combine address lines.
        $address_line = $address['address_1'] ?? '';
        if ( ! empty( $address['address_2'] ) ) {
            $address_line .= ', ' . $address['address_2'];
        }

        $params = array(
            'Pickup_Date'           => current_time( 'Y-m-d' ),
            'Recipient_Name'        => $recipient_name,
            'Recipient_Address'     => $address_line,
            'Recipient_Address_Number' => '', // Extracted if possible
            'Recipient_Zipcode'     => $address['postcode'] ?? '',
            'Recipient_Region'      => $address['city'] ?? '',
            'Recipient_Phone'       => $order->get_billing_phone() ?? '',
            'Recipient_Cell_Phone'  => $order->get_billing_phone() ?? '',
            'Recipient_Floor'       => null,
            'Recipient_Company_Name' => $address['company'] ?? null,
            'Recipient_Country'     => $acs_country,
            'Recipient_Email'       => $order->get_billing_email() ?? null,
            'Charge_Type'           => intval( get_option( 'wc_acs_charge_type', '2' ) ),
            'Item_Quantity'         => 1,
            'Weight'                => $this->calculate_order_weight( $order ),
            'Cod_Ammount'           => $cod_amount,
            'Cod_Payment_Way'       => $cod_way,
            'Acs_Delivery_Products' => $delivery_products,
            'Reference_Key1'        => strval( $order->get_order_number() ),
            'Language'              => 'EN',
        );

        // Try to extract street number from address
        if ( preg_match( '/^(.+?)\s+(\d+[a-zA-Z]?)\s*$/', $params['Recipient_Address'], $matches ) ) {
            $params['Recipient_Address']        = $matches[1];
            $params['Recipient_Address_Number'] = $matches[2];
        }

        $merged = array_merge( $params, $extra_params );

        if ( $is_point ) {
            $note = sprintf(
                'ACS Point: %s, %s',
                (string) $order->get_meta( '_acs_point_name' ),
                (string) $order->get_meta( '_acs_point_address' )
            );

            $merged['Acs_Station_Destination']        = $point_station;
            $merged['Acs_Station_Branch_Destination'] = (int) $point_branch;
            // ACS refuses multi-parcel shipments to a Smart Point.
            $merged['Item_Quantity']        = 1;
            $merged['Recipient_Cell_Phone'] = $mobile;
            $merged['Recipient_Phone']      = $mobile;
            $merged['Delivery_Notes']       = ! empty( $extra_params['Delivery_Notes'] )
                ? $note . ' | ' . $extra_params['Delivery_Notes']
                : $note;
        }

        return $merged;
    }

    // ─── AJAX HANDLERS ─────────────────────────────────────────────

    /**
     * AJAX: Create voucher.
     */
    public function ajax_create_voucher() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        $order_id = intval( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wc-acs-courier' ) );
        }

        // Already has voucher?
        if ( $order->get_meta( '_acs_voucher_no' ) ) {
            wp_send_json_error( __( 'This order already has an ACS voucher.', 'wc-acs-courier' ) );
        }

        // Extra params from the metabox form
        $extra = array();

        if ( ! empty( $_POST['item_qty'] ) ) {
            $extra['Item_Quantity'] = intval( $_POST['item_qty'] );
        }

        if ( ! empty( $_POST['weight'] ) ) {
            $extra['Weight'] = floatval( $_POST['weight'] );
        }

        if ( ! empty( $_POST['notes'] ) ) {
            $extra['Delivery_Notes'] = sanitize_text_field( wp_unslash( $_POST['notes'] ) );
        }

        $params = $this->build_voucher_params( $order, $extra );

        if ( is_wp_error( $params ) ) {
            wp_send_json_error( $params->get_error_message() );
        }

        // Merge extra services into delivery products (after build, to avoid double call)
        if ( ! empty( $_POST['services'] ) ) {
            $services = sanitize_text_field( wp_unslash( $_POST['services'] ) );
            $existing = $params['Acs_Delivery_Products'] ? explode( ',', $params['Acs_Delivery_Products'] ) : array();
            $new      = explode( ',', $services );
            $all      = array_unique( array_merge( $existing, $new ) );
            $params['Acs_Delivery_Products'] = implode( ',', $all );
        }
        $result = WC_ACS_API::create_voucher( $params );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $voucher_no = $result['ACSValueOutput'][0]['Voucher_No'] ?? null;
        $error_msg  = $result['ACSValueOutput'][0]['Error_Message'] ?? '';

        if ( ! $voucher_no || ! empty( $error_msg ) ) {
            wp_send_json_error( $error_msg ?: __( 'Failed to create voucher.', 'wc-acs-courier' ) );
        }

        // Save voucher to order
        $order->update_meta_data( '_acs_voucher_no', trim( $voucher_no ) );
        $order->update_meta_data( '_acs_voucher_date', current_time( 'Y-m-d' ) );
        $order->add_order_note(
            /* translators: %s: voucher number */
            sprintf( __( 'ACS voucher created: %s', 'wc-acs-courier' ), trim( $voucher_no ) )
        );
        $order->save();

        // Send tracking email to customer
        if ( 'yes' === get_option( 'wc_acs_email_tracking', 'yes' ) ) {
            $this->send_tracking_email( $order, trim( $voucher_no ) );
        }

        WC_ACS_API::log( "Voucher created for order #{$order_id}: {$voucher_no}" );

        wp_send_json_success( array(
            'voucher_no' => trim( $voucher_no ),
            'message'    => __( 'Voucher created successfully!', 'wc-acs-courier' ),
        ) );
    }

    /**
     * AJAX: Print voucher.
     */
    public function ajax_print_voucher() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        $order_id   = intval( $_POST['order_id'] ?? 0 );
        $print_type = intval( $_POST['print_type'] ?? 2 );
        $order      = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wc-acs-courier' ) );
        }

        $voucher_no = $order->get_meta( '_acs_voucher_no' );
        if ( ! $voucher_no ) {
            wp_send_json_error( __( 'No voucher found for this order.', 'wc-acs-courier' ) );
        }

        $result = WC_ACS_API::print_voucher( $voucher_no, $print_type );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // The API returns PDF data in ACSObjectOutput — either as a base64 string
        // or as a dictionary with voucher_no as key and base64 PDF as value.
        $pdf_data = null;

        if ( ! empty( $result['ACSValueOutput'][0]['ACSObjectOutput'] ) ) {
            $obj = $result['ACSValueOutput'][0]['ACSObjectOutput'];
            if ( is_array( $obj ) ) {
                $pdf_data = reset( $obj );
            } else {
                $pdf_data = $obj;
            }
        }

        if ( ! $pdf_data ) {
            $pdf_data = $result['ACSObjectOutput'] ?? null;
        }

        if ( $pdf_data ) {
            wp_send_json_success( array(
                'pdf_base64' => is_array( $pdf_data ) ? reset( $pdf_data ) : $pdf_data,
                'filename'   => "acs-voucher-{$voucher_no}.pdf",
            ) );
        }

        wp_send_json_error( __( 'Could not retrieve PDF data.', 'wc-acs-courier' ) );
    }

    /**
     * AJAX: Delete voucher.
     */
    public function ajax_delete_voucher() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        $order_id = intval( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wc-acs-courier' ) );
        }

        $voucher_no = $order->get_meta( '_acs_voucher_no' );
        if ( ! $voucher_no ) {
            wp_send_json_error( __( 'No voucher to delete.', 'wc-acs-courier' ) );
        }

        $result = WC_ACS_API::delete_voucher( $voucher_no );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $error_msg = $result['ACSValueOutput'][0]['Error_Message'] ?? null;
        if ( ! empty( $error_msg ) ) {
            wp_send_json_error( $error_msg );
        }

        $order->delete_meta_data( '_acs_voucher_no' );
        $order->delete_meta_data( '_acs_voucher_date' );
        $order->delete_meta_data( '_acs_tracking_status' );
        $order->delete_meta_data( '_acs_shipment_status' );
        $order->delete_meta_data( '_acs_tracking_final' );
        $order->add_order_note(
            /* translators: %s: voucher number */
            sprintf( __( 'ACS voucher deleted: %s', 'wc-acs-courier' ), $voucher_no )
        );
        $order->save();

        wp_send_json_success( __( 'Voucher deleted.', 'wc-acs-courier' ) );
    }

    /**
     * AJAX: Track voucher.
     */
    public function ajax_track_voucher() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        $order_id = intval( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wc-acs-courier' ) );
        }

        $voucher_no = $order->get_meta( '_acs_voucher_no' );
        if ( ! $voucher_no ) {
            wp_send_json_error( __( 'No voucher to track.', 'wc-acs-courier' ) );
        }

        $result = WC_ACS_API::tracking_details( $voucher_no );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $checkpoints = $result['ACSTableOutput']['Table_Data'] ?? array();

        wp_send_json_success( array(
            'voucher_no'  => $voucher_no,
            'checkpoints' => $checkpoints,
        ) );
    }

    /**
     * AJAX: Issue pickup list.
     */
    public function ajax_issue_pickup_list() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        $order_id = intval( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wc-acs-courier' ) );
        }

        $voucher_date = $order->get_meta( '_acs_voucher_date' ) ?: current_time( 'Y-m-d' );
        $result       = WC_ACS_API::issue_pickup_list( $voucher_date );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $pickup_list_no = $result['ACSValueOutput'][0]['PickupList_No'] ?? null;
        $error_msg      = $result['ACSValueOutput'][0]['Error_Message'] ?? '';

        if ( ! $pickup_list_no ) {
            wp_send_json_error( $error_msg ?: __( 'Failed to issue pickup list.', 'wc-acs-courier' ) );
        }

        // Store pickup list on ALL orders with vouchers for this date (not just the current one)
        $dated_orders = wc_get_orders( array(
            'limit'      => -1,
            'meta_query' => array(
                array(
                    'key'   => '_acs_voucher_date',
                    'value' => $voucher_date,
                ),
                array(
                    'key'     => '_acs_pickup_list_no',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        ) );

        foreach ( $dated_orders as $dated_order ) {
            $dated_order->update_meta_data( '_acs_pickup_list_no', $pickup_list_no );
            $dated_order->save();
        }

        // Ensure the current order is always updated
        if ( ! $order->get_meta( '_acs_pickup_list_no' ) ) {
            $order->update_meta_data( '_acs_pickup_list_no', $pickup_list_no );
            $order->save();
        }

        wp_send_json_success( array(
            'pickup_list_no' => $pickup_list_no,
            'message'        => __( 'Pickup list issued.', 'wc-acs-courier' ),
        ) );
    }

    /**
     * AJAX: Print pickup list.
     */
    public function ajax_print_pickup_list() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        $order_id = intval( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wc-acs-courier' ) );
        }

        $pickup_list_no = $order->get_meta( '_acs_pickup_list_no' );
        $voucher_date   = $order->get_meta( '_acs_voucher_date' ) ?: current_time( 'Y-m-d' );

        if ( ! $pickup_list_no ) {
            wp_send_json_error( __( 'No pickup list found.', 'wc-acs-courier' ) );
        }

        $result = WC_ACS_API::print_pickup_list( $pickup_list_no, $voucher_date );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Extract PDF data — pickup list response uses {"Mass_Voucher_No": "...", "PDFData": "..."}
        $pdf_data = null;

        if ( ! empty( $result['ACSValueOutput'][0]['ACSObjectOutput'] ) ) {
            $obj = $result['ACSValueOutput'][0]['ACSObjectOutput'];
            if ( is_array( $obj ) && isset( $obj['PDFData'] ) ) {
                $pdf_data = $obj['PDFData'];
            } elseif ( is_array( $obj ) ) {
                $pdf_data = reset( $obj );
            } else {
                $pdf_data = $obj;
            }
        }

        if ( ! $pdf_data ) {
            $pdf_data = $result['ACSObjectOutput'] ?? null;
        }

        if ( ! $pdf_data ) {
            wp_send_json_error( __( 'Could not retrieve pickup list PDF.', 'wc-acs-courier' ) );
        }

        wp_send_json_success( array(
            'pdf_base64' => is_array( $pdf_data ) ? reset( $pdf_data ) : $pdf_data,
            'filename'   => "acs-pickup-list-{$pickup_list_no}.pdf",
        ) );
    }

    /**
     * Should this order get an ACS voucher automatically?
     *
     * A store may run another carrier plugin alongside this one (pooq.gr runs
     * wc-boxnow-delivery). An order shipped through that carrier must not also
     * get an ACS voucher when it reaches the trigger status. The rule is "not
     * another known carrier", never "is the ACS shipping method": on pooq.gr
     * the ACS rate is a plain flat_rate, so requiring our own method id would
     * switch automation off for every real ACS order.
     *
     * @param WC_Order $order Order.
     * @return bool
     */
    public function should_auto_create( $order ) {
        /**
         * Shipping method ids that belong to other carrier plugins.
         *
         * @since 1.0.1
         *
         * @param string[] $method_ids Method ids to leave alone. Default: BOX NOW's.
         */
        $other_carriers = (array) apply_filters( 'wc_acs_other_carrier_method_ids', array( 'box_now_delivery' ) );

        foreach ( $order->get_shipping_methods() as $item ) {
            if ( in_array( $item->get_method_id(), $other_carriers, true ) ) {
                $order->add_order_note(
                    /* translators: %s: shipping method id of the other carrier */
                    sprintf( __( 'ACS auto-voucher skipped: this order ships with %s (BOX NOW or another carrier), not ACS.', 'wc-acs-courier' ), $item->get_method_id() )
                );
                $order->save();
                return false;
            }
        }

        /**
         * Final say on automatic ACS voucher creation for one order.
         *
         * @since 1.0.1
         *
         * @param bool     $allowed True to create the voucher automatically.
         * @param WC_Order $order   The order reaching the trigger status.
         */
        return (bool) apply_filters( 'wc_acs_auto_create_voucher_allowed', true, $order );
    }

    /**
     * Auto-create voucher on order status change.
     *
     * @param int    $order_id   Order ID.
     * @param string $old_status Old status.
     * @param string $new_status New status.
     */
    public function auto_create_voucher( $order_id, $old_status, $new_status ) {
        if ( 'yes' !== get_option( 'wc_acs_auto_create_voucher', 'no' ) ) {
            return;
        }

        $trigger_status = get_option( 'wc_acs_auto_create_status', 'wc-processing' );
        $trigger_status = str_replace( 'wc-', '', $trigger_status );

        if ( $new_status !== $trigger_status ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_acs_voucher_no' ) ) {
            return;
        }

        if ( ! $this->should_auto_create( $order ) ) {
            return;
        }

        $params = $this->build_voucher_params( $order );

        if ( is_wp_error( $params ) ) {
            $order->add_order_note(
                /* translators: %s: error message */
                sprintf( __( 'ACS auto-voucher failed: %s', 'wc-acs-courier' ), $params->get_error_message() )
            );
            $order->save();
            return;
        }

        $result = WC_ACS_API::create_voucher( $params );

        if ( is_wp_error( $result ) ) {
            $order->add_order_note(
                /* translators: %s: error message */
                sprintf( __( 'ACS auto-voucher failed: %s', 'wc-acs-courier' ), $result->get_error_message() )
            );
            $order->save();
            return;
        }

        $voucher_no = $result['ACSValueOutput'][0]['Voucher_No'] ?? null;
        $error_msg  = $result['ACSValueOutput'][0]['Error_Message'] ?? '';

        if ( $voucher_no && empty( $error_msg ) ) {
            $order->update_meta_data( '_acs_voucher_no', trim( $voucher_no ) );
            $order->update_meta_data( '_acs_voucher_date', current_time( 'Y-m-d' ) );
            $order->add_order_note(
                /* translators: %s: voucher number */
                sprintf( __( 'ACS voucher auto-created: %s', 'wc-acs-courier' ), trim( $voucher_no ) )
            );
            $order->save();

            if ( 'yes' === get_option( 'wc_acs_email_tracking', 'yes' ) ) {
                $this->send_tracking_email( $order, trim( $voucher_no ) );
            }
        } else {
            $order->add_order_note(
                /* translators: %s: error message */
                sprintf( __( 'ACS auto-voucher error: %s', 'wc-acs-courier' ), $error_msg )
            );
            $order->save();
        }
    }

    /**
     * Send tracking email to customer.
     *
     * @param WC_Order $order      Order object.
     * @param string   $voucher_no Voucher number.
     */
    private function send_tracking_email( $order, $voucher_no ) {
        $to = $order->get_billing_email();

        // -------------------------------------------------------------------
        // 1. WPML LANGUAGE DETECTION
        // -------------------------------------------------------------------
        $order_lang = get_post_meta( $order->get_id(), 'wpml_language', true );
        $is_en      = ( 'en' === $order_lang );

        // -------------------------------------------------------------------
        // 2. TRANSLATED STRINGS (English / Greek)
        // -------------------------------------------------------------------
        $str_heading      = $is_en ? 'Your order #%s has been shipped' : 'Η παραγγελία σας #%s έχει αποσταλεί';
        $str_subject      = $is_en ? 'Your order #%s has been shipped via ACS Courier' : 'Η παραγγελία σας #%s έχει αποσταλεί (ACS Courier)';
        $str_dear         = $is_en ? 'Dear %s,' : 'Αγαπητέ/ή %s,';
        $str_body_shipped = $is_en ? 'Your order #%s has been shipped via ACS Courier.' : 'Η παραγγελία σας #%s έχει παραδοθεί στην ACS Courier και είναι καθ\' οδόν.';
        $str_track_label  = $is_en ? 'Tracking Number' : 'Αριθμός Αποστολής (Voucher)';
        $str_track_btn    = $is_en ? 'Track your shipment' : 'Εντοπισμός Δέματος';
        $str_thanks       = $is_en ? 'Thank you for your purchase!' : 'Σας ευχαριστούμε για την προτίμηση!';

        $heading = sprintf( $str_heading, $order->get_order_number() );
        $subject = sprintf( $str_subject, $order->get_order_number() );

        $tracking_url = 'https://www.acscourier.net/el/track-and-trace/?generalCode=' . urlencode( $voucher_no );
        $base_color   = get_option( 'woocommerce_email_base_color', '#8526ff' );

        // Build HTML body content
        $body  = '<div class="email-introduction" style="padding-bottom: 24px;">';
        $body .= '<p style="margin: 0 0 16px;">' . sprintf( $str_dear, esc_html( $order->get_billing_first_name() ) ) . '</p>';
        $body .= '<p style="margin: 0 0 16px;">' . sprintf( $str_body_shipped, esc_html( $order->get_order_number() ) ) . '</p>';
        $body .= '</div>';

        // Tracking info box
        $body .= '<div style="margin: 0 0 24px; padding: 20px; background-color: #f8f8f8; border-radius: 8px; border-left: 4px solid ' . esc_attr( $base_color ) . ';">';
        $body .= '<p style="margin: 0 0 12px; font-size: 14px; color: #787c82;">' . esc_html( $str_track_label ) . '</p>';
        $body .= '<p style="margin: 0 0 16px; font-size: 20px; font-weight: bold; color: #1e1e1e; letter-spacing: 1px;">' . esc_html( $voucher_no ) . '</p>';
        $body .= '<a href="' . esc_url( $tracking_url ) . '" style="display: inline-block; padding: 12px 28px; background-color: ' . esc_attr( $base_color ) . '; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px;">';
        $body .= esc_html( $str_track_btn );
        $body .= '</a>';
        $body .= '</div>';

        $body .= '<p style="margin: 0;">' . esc_html( $str_thanks ) . '</p>';

        // Wrap in WooCommerce email template
        $mailer  = WC()->mailer();
        $wrapped = $mailer->wrap_message( $heading, $body );

        // Force WooCommerce to apply inline CSS
        if ( method_exists( $mailer, 'style_inline' ) ) {
            $wrapped = $mailer->style_inline( $wrapped );
        }

        // Constrain header logo size across all email clients.
        // Target every <img> whose src matches the WooCommerce header image URL,
        // strip any existing width/height/style so we can set our own.
        $header_img_url = get_option( 'woocommerce_email_header_image' );

        if ( $header_img_url ) {
            $escaped_url = preg_quote( esc_url( $header_img_url ), '/' );
            $wrapped = preg_replace_callback(
                '/<img\b([^>]*src=["\']' . $escaped_url . '["\'][^>]*)>/i',
                function ( $m ) {
                    $tag = $m[1];
                    // Remove any existing width, height, or style attributes
                    $tag = preg_replace( '/\s*(width|height)\s*=\s*["\'][^"\']*["\']/i', '', $tag );
                    $tag = preg_replace( '/\s*style\s*=\s*["\'][^"\']*["\']/i', '', $tag );
                    return '<img' . $tag
                        . ' width="150" height="auto"'
                        . ' style="max-width:150px;height:auto;display:block;border:none;"'
                        . '>';
                },
                $wrapped
            );
        }

        // Use WooCommerce sender settings
        $from_name    = get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) );
        $from_address = get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $from_name, $from_address ),
            sprintf( 'Reply-To: %s <%s>', $from_name, get_option( 'admin_email' ) ),
        );

        $sent = wp_mail( $to, $subject, $wrapped, $headers );

        if ( ! $sent ) {
            WC_ACS_API::log( "Failed to send tracking email for order #{$order->get_id()} to {$to}", 'warning' );
        }
    }

    // ─── BULK ACTIONS ──────────────────────────────────────────────

    /**
     * Register bulk actions.
     *
     * @param array $actions Existing bulk actions.
     * @return array
     */
    public function register_bulk_actions( $actions ) {
        $actions['acs_create_vouchers'] = __( 'ACS: Create Vouchers', 'wc-acs-courier' );
        $actions['acs_print_vouchers']  = __( 'ACS: Print Vouchers (A4)', 'wc-acs-courier' );
        return $actions;
    }

    /**
     * Handle bulk actions.
     *
     * @param string $redirect_to Redirect URL.
     * @param string $action      Action name.
     * @param array  $ids         Selected order IDs.
     * @return string
     */
    public function handle_bulk_actions( $redirect_to, $action, $ids ) {
        if ( 'acs_create_vouchers' === $action ) {
            $created = 0;
            foreach ( $ids as $order_id ) {
                $order = wc_get_order( $order_id );
                if ( ! $order || $order->get_meta( '_acs_voucher_no' ) ) {
                    continue;
                }

                $params = $this->build_voucher_params( $order );

                if ( is_wp_error( $params ) ) {
                    $order->add_order_note( sprintf( __( 'ACS voucher skipped: %s', 'wc-acs-courier' ), $params->get_error_message() ) );
                    $order->save();
                    continue;
                }

                $result = WC_ACS_API::create_voucher( $params );

                if ( ! is_wp_error( $result ) ) {
                    $voucher_no = $result['ACSValueOutput'][0]['Voucher_No'] ?? null;
                    $error_msg  = $result['ACSValueOutput'][0]['Error_Message'] ?? '';
                    if ( $voucher_no && empty( $error_msg ) ) {
                        $order->update_meta_data( '_acs_voucher_no', trim( $voucher_no ) );
                        $order->update_meta_data( '_acs_voucher_date', current_time( 'Y-m-d' ) );
                        $order->add_order_note( sprintf( __( 'ACS voucher created: %s', 'wc-acs-courier' ), trim( $voucher_no ) ) );
                        $order->save();
                        $created++;

                        if ( 'yes' === get_option( 'wc_acs_email_tracking', 'yes' ) ) {
                            $this->send_tracking_email( $order, trim( $voucher_no ) );
                        }
                    }
                }

                // Rate-limit API calls (150ms delay)
                usleep( 150000 );
            }

            $redirect_to = add_query_arg( 'acs_vouchers_created', $created, $redirect_to );
        }

        if ( 'acs_print_vouchers' === $action ) {
            $voucher_numbers = array();
            foreach ( $ids as $order_id ) {
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                    continue;
                }
                $voucher_no = $order->get_meta( '_acs_voucher_no' );
                if ( $voucher_no ) {
                    $voucher_numbers[] = $voucher_no;
                }
            }

            if ( empty( $voucher_numbers ) ) {
                $redirect_to = add_query_arg( 'acs_no_vouchers', 1, $redirect_to );
            } else {
                // ACS supports pipe-separated voucher numbers for bulk print
                $all_vouchers = implode( '|', $voucher_numbers );
                $result       = WC_ACS_API::print_voucher( $all_vouchers, 2 );

                if ( ! is_wp_error( $result ) ) {
                    // Store PDF data temporarily for download
                    $pdf_data = null;
                    if ( ! empty( $result['ACSValueOutput'][0]['ACSObjectOutput'] ) ) {
                        $obj = $result['ACSValueOutput'][0]['ACSObjectOutput'];
                        $pdf_data = is_array( $obj ) ? reset( $obj ) : $obj;
                    }
                    if ( ! $pdf_data ) {
                        $pdf_data = $result['ACSObjectOutput'] ?? null;
                    }

                    if ( $pdf_data ) {
                        set_transient(
                            'acs_bulk_print_' . get_current_user_id(),
                            array(
                                'pdf_base64' => is_array( $pdf_data ) ? reset( $pdf_data ) : $pdf_data,
                                'count'      => count( $voucher_numbers ),
                            ),
                            300
                        );
                        $redirect_to = add_query_arg( 'acs_vouchers_printed', count( $voucher_numbers ), $redirect_to );
                    } else {
                        $redirect_to = add_query_arg( 'acs_print_error', 1, $redirect_to );
                    }
                } else {
                    $redirect_to = add_query_arg( 'acs_print_error', 1, $redirect_to );
                }
            }
        }

        return $redirect_to;
    }

    /**
     * Display admin notices for bulk action results.
     */
    public function bulk_action_notices() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of query params.
        if ( ! empty( $_GET['acs_vouchers_created'] ) ) {
            $count = intval( $_GET['acs_vouchers_created'] );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                /* translators: %d: number of vouchers created */
                esc_html( sprintf( _n( '%d ACS voucher created.', '%d ACS vouchers created.', $count, 'wc-acs-courier' ), $count ) )
            );
        }

        if ( ! empty( $_GET['acs_vouchers_printed'] ) ) {
            $count    = intval( $_GET['acs_vouchers_printed'] );
            $pdf_data = get_transient( 'acs_bulk_print_' . get_current_user_id() );

            if ( $pdf_data ) {
                delete_transient( 'acs_bulk_print_' . get_current_user_id() );
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    /* translators: %d: number of vouchers */
                    esc_html( sprintf( _n( '%d voucher ready for printing.', '%d vouchers ready for printing.', $count, 'wc-acs-courier' ), $count ) )
                );
                // Inject inline script to auto-open the PDF
                echo '<script>document.addEventListener("DOMContentLoaded", function() {';
                echo 'try { var d=' . wp_json_encode( $pdf_data['pdf_base64'] ) . ';';
                echo 'var b=atob(d), n=new Uint8Array(b.length); for(var i=0;i<b.length;i++) n[i]=b.charCodeAt(i);';
                echo 'var blob=new Blob([n],{type:"application/pdf"}), u=URL.createObjectURL(blob); window.open(u,"_blank");';
                echo 'setTimeout(function(){URL.revokeObjectURL(u);},60000);';
                echo '} catch(e) { console.error("ACS PDF error:", e); }';
                echo '});</script>';
            }
        }

        if ( ! empty( $_GET['acs_print_error'] ) ) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html__( 'Failed to print ACS vouchers. Please try printing individually.', 'wc-acs-courier' )
            );
        }

        if ( ! empty( $_GET['acs_no_vouchers'] ) ) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html__( 'No ACS vouchers found on the selected orders. Create vouchers first.', 'wc-acs-courier' )
            );
        }
        // phpcs:enable
    }

    // ─── ORDER LIST COLUMN ─────────────────────────────────────────

    /**
     * Add voucher column to orders list.
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_voucher_column( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            if ( 'order_status' === $key ) {
                $new_columns['acs_voucher'] = __( 'ACS Voucher', 'wc-acs-courier' );
            }
        }
        return $new_columns;
    }

    /**
     * Render voucher column content (legacy post-based orders).
     *
     * @param string $column  Column name.
     * @param int    $post_id Post/Order ID.
     */
    public function render_voucher_column( $column, $post_id ) {
        if ( 'acs_voucher' !== $column ) {
            return;
        }

        $order = wc_get_order( $post_id );
        if ( ! $order ) {
            return;
        }

        $voucher_no = $order->get_meta( '_acs_voucher_no' );
        if ( $voucher_no ) {
            echo '<code>' . esc_html( $voucher_no ) . '</code>';
        } else {
            echo '<span style="color:#999;">&mdash;</span>';
        }
    }

    /**
     * Render voucher column content (HPOS).
     *
     * @param string   $column Column name.
     * @param WC_Order $order  Order object.
     */
    public function render_voucher_column_hpos( $column, $order ) {
        if ( 'acs_voucher' !== $column ) {
            return;
        }

        $voucher_no = $order->get_meta( '_acs_voucher_no' );
        if ( $voucher_no ) {
            echo '<code>' . esc_html( $voucher_no ) . '</code>';
        } else {
            echo '<span style="color:#999;">&mdash;</span>';
        }
    }
}
