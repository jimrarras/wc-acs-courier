<?php
/**
 * ACS Admin Settings
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Admin {

    /**
     * Singleton instance.
     *
     * @var WC_ACS_Admin|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return WC_ACS_Admin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_wc_acs_test_connection', array( $this, 'ajax_test_connection' ) );
    }

    /**
     * Add admin menu page.
     */
    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'ACS Courier Settings', 'wc-acs-courier' ),
            __( 'ACS Courier', 'wc-acs-courier' ),
            'manage_woocommerce',
            'wc-acs-courier',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        $sanitize_text = array( 'sanitize_callback' => 'sanitize_text_field' );
        $sanitize_checkbox = array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) );

        // API Credentials (group: wc_acs_credentials)
        register_setting( 'wc_acs_credentials', 'wc_acs_api_key', $sanitize_text );
        register_setting( 'wc_acs_credentials', 'wc_acs_company_id', $sanitize_text );
        register_setting( 'wc_acs_credentials', 'wc_acs_company_password', $sanitize_text );
        register_setting( 'wc_acs_credentials', 'wc_acs_user_id', $sanitize_text );
        register_setting( 'wc_acs_credentials', 'wc_acs_user_password', $sanitize_text );
        register_setting( 'wc_acs_credentials', 'wc_acs_debug_logging', array_merge(
            array( 'default' => 'no' ),
            $sanitize_checkbox
        ) );

        // Shipping settings (group: wc_acs_shipping)
        register_setting( 'wc_acs_shipping', 'wc_acs_billing_code', $sanitize_text );
        register_setting( 'wc_acs_shipping', 'wc_acs_sender_name', $sanitize_text );
        register_setting( 'wc_acs_shipping', 'wc_acs_station_origin', $sanitize_text );
        register_setting( 'wc_acs_shipping', 'wc_acs_default_weight', array(
            'default'           => '0.5',
            'sanitize_callback' => array( $this, 'sanitize_weight' ),
        ) );
        register_setting( 'wc_acs_shipping', 'wc_acs_charge_type', array(
            'default'           => '2',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        // Automation settings (group: wc_acs_automation)
        register_setting( 'wc_acs_automation', 'wc_acs_auto_create_voucher', array_merge(
            array( 'default' => 'no' ),
            $sanitize_checkbox
        ) );
        register_setting( 'wc_acs_automation', 'wc_acs_auto_create_status', array(
            'default'           => 'wc-processing',
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'wc_acs_automation', 'wc_acs_auto_tracking', array_merge(
            array( 'default' => 'yes' ),
            $sanitize_checkbox
        ) );
        register_setting( 'wc_acs_automation', 'wc_acs_tracking_frequency', array(
            'default'           => 'hourly',
            'sanitize_callback' => array( $this, 'sanitize_frequency' ),
        ) );
        register_setting( 'wc_acs_automation', 'wc_acs_email_tracking', array_merge(
            array( 'default' => 'yes' ),
            $sanitize_checkbox
        ) );
    }

    /**
     * Sanitize checkbox value — returns 'yes' or 'no'.
     *
     * @param mixed $value Input value.
     * @return string
     */
    public function sanitize_checkbox( $value ) {
        return 'yes' === $value ? 'yes' : 'no';
    }

    /**
     * Sanitize weight value — ensures minimum 0.5.
     *
     * @param mixed $value Input value.
     * @return string
     */
    public function sanitize_weight( $value ) {
        $weight = floatval( $value );
        return strval( max( 0.5, $weight ) );
    }

    /**
     * Sanitize tracking frequency — only allow valid cron schedules.
     *
     * @param mixed $value Input value.
     * @return string
     */
    public function sanitize_frequency( $value ) {
        $allowed = array( 'hourly', 'twicedaily', 'daily' );
        return in_array( $value, $allowed, true ) ? $value : 'hourly';
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        // Load on our settings page and on order edit pages
        $is_our_page = ( 'woocommerce_page_wc-acs-courier' === $hook );
        $is_order_page = in_array( $hook, array( 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ), true );

        if ( ! $is_our_page && ! $is_order_page ) {
            return;
        }

        wp_enqueue_style(
            'wc-acs-admin',
            WC_ACS_PLUGIN_URL . 'assets/css/acs-admin.css',
            array(),
            WC_ACS_VERSION
        );

        wp_enqueue_script(
            'wc-acs-admin',
            WC_ACS_PLUGIN_URL . 'assets/js/acs-admin.js',
            array( 'jquery' ),
            WC_ACS_VERSION,
            true
        );

        wp_localize_script( 'wc-acs-admin', 'wc_acs', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wc_acs_nonce' ),
            'i18n'     => array(
                'testing'        => __( 'Testing connection...', 'wc-acs-courier' ),
                'success'        => __( 'Connection successful!', 'wc-acs-courier' ),
                'error'          => __( 'Connection failed', 'wc-acs-courier' ),
                'creating'       => __( 'Creating voucher...', 'wc-acs-courier' ),
                'confirm_delete' => __( 'Are you sure you want to delete this voucher?', 'wc-acs-courier' ),
                'refreshing'     => __( 'Refreshing...', 'wc-acs-courier' ),
                'request_failed' => __( 'Request failed.', 'wc-acs-courier' ),
            ),
        ) );
    }

    /**
     * AJAX: Test API connection.
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        WC_ACS_API::reset_credentials();

        $result = WC_ACS_API::test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( __( 'Connection to ACS API successful!', 'wc-acs-courier' ) );
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        $allowed_tabs = array( 'credentials', 'shipping', 'automation' );
        $active_tab   = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'credentials';
        if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
            $active_tab = 'credentials';
        }
        ?>
        <div class="wrap wc-acs-settings">
            <h1><?php esc_html_e( 'ACS Courier Settings', 'wc-acs-courier' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=wc-acs-courier&tab=credentials"
                   class="nav-tab <?php echo 'credentials' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'API Credentials', 'wc-acs-courier' ); ?>
                </a>
                <a href="?page=wc-acs-courier&tab=shipping"
                   class="nav-tab <?php echo 'shipping' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Shipping', 'wc-acs-courier' ); ?>
                </a>
                <a href="?page=wc-acs-courier&tab=automation"
                   class="nav-tab <?php echo 'automation' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Automation', 'wc-acs-courier' ); ?>
                </a>
            </nav>

            <form method="post" action="options.php">
                <?php
                $settings_groups = array(
                    'credentials' => 'wc_acs_credentials',
                    'shipping'    => 'wc_acs_shipping',
                    'automation'  => 'wc_acs_automation',
                );
                settings_fields( $settings_groups[ $active_tab ] );
                ?>

                <?php if ( 'credentials' === $active_tab ) : ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'API Key', 'wc-acs-courier' ); ?></th>
                            <td>
                                <input type="text" name="wc_acs_api_key"
                                       value="<?php echo esc_attr( get_option( 'wc_acs_api_key' ) ); ?>"
                                       class="regular-text" autocomplete="off" />
                                <p class="description"><?php esc_html_e( 'The API Key provided by ACS.', 'wc-acs-courier' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Company ID', 'wc-acs-courier' ); ?></th>
                            <td>
                                <input type="text" name="wc_acs_company_id"
                                       value="<?php echo esc_attr( get_option( 'wc_acs_company_id' ) ); ?>"
                                       class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Company Password', 'wc-acs-courier' ); ?></th>
                            <td>
                                <input type="password" name="wc_acs_company_password"
                                       value="<?php echo esc_attr( get_option( 'wc_acs_company_password' ) ); ?>"
                                       class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'User ID', 'wc-acs-courier' ); ?></th>
                            <td>
                                <input type="text" name="wc_acs_user_id"
                                       value="<?php echo esc_attr( get_option( 'wc_acs_user_id' ) ); ?>"
                                       class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'User Password', 'wc-acs-courier' ); ?></th>
                            <td>
                                <input type="password" name="wc_acs_user_password"
                                       value="<?php echo esc_attr( get_option( 'wc_acs_user_password' ) ); ?>"
                                       class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Test Connection', 'wc-acs-courier' ); ?></th>
                            <td>
                                <button type="button" id="wc-acs-test-connection" class="button button-secondary">
                                    <?php esc_html_e( 'Test Connection', 'wc-acs-courier' ); ?>
                                </button>
                                <span id="wc-acs-test-result" class="wc-acs-test-result"></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Debug Logging', 'wc-acs-courier' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_acs_debug_logging" value="yes"
                                        <?php checked( 'yes', get_option( 'wc_acs_debug_logging', 'no' ) ); ?> />
                                    <?php esc_html_e( 'Enable debug logging (WooCommerce > Status > Logs)', 'wc-acs-courier' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                <?php elseif ( 'shipping' === $active_tab ) : ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Billing Code', 'wc-acs-courier' ); ?></th>
                            <td>
                                <input type="text" name="wc_acs_billing_code"
                                       value="<?php echo esc_attr( get_option( 'wc_acs_billing_code' ) ); ?>"
                                       class="regular-text" />
                                <p class="description"><?php esc_html_e( 'Your ACS credit/billing code (e.g., 2ΑΘ999999).', 'wc-acs-courier' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Sender Name', 'wc-acs-courier' ); ?></th>
                            <td>
                                <input type="text" name="wc_acs_sender_name"
                                       value="<?php echo esc_attr( get_option( 'wc_acs_sender_name', get_bloginfo( 'name' ) ) ); ?>"
                                       class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Origin Station Code', 'wc-acs-courier' ); ?></th>
                            <td>
                                <input type="text" name="wc_acs_station_origin"
                                       value="<?php echo esc_attr( get_option( 'wc_acs_station_origin' ) ); ?>"
                                       class="regular-text" />
                                <p class="description"><?php esc_html_e( 'ACS station code in Greek uppercase (e.g., ΑΘ for Athens). Found via zip code lookup.', 'wc-acs-courier' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Default Weight (kg)', 'wc-acs-courier' ); ?></th>
                            <td>
                                <input type="number" name="wc_acs_default_weight" step="0.1" min="0.5"
                                       value="<?php echo esc_attr( get_option( 'wc_acs_default_weight', '0.5' ) ); ?>"
                                       class="small-text" />
                                <p class="description"><?php esc_html_e( 'Default weight when product weight is not set. Minimum 0.5 kg.', 'wc-acs-courier' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Charge Type', 'wc-acs-courier' ); ?></th>
                            <td>
                                <select name="wc_acs_charge_type">
                                    <option value="2" <?php selected( '2', get_option( 'wc_acs_charge_type', '2' ) ); ?>>
                                        <?php esc_html_e( 'Sender pays', 'wc-acs-courier' ); ?>
                                    </option>
                                    <option value="4" <?php selected( '4', get_option( 'wc_acs_charge_type', '2' ) ); ?>>
                                        <?php esc_html_e( 'Recipient pays', 'wc-acs-courier' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'ACS Points', 'wc-acs-courier' ); ?></th>
                            <td>
                                <?php
                                $feed    = WC_ACS_Points_Feed::instance();
                                $fetched = $feed->fetched_at();
                                ?>
                                <p id="wc-acs-points-status">
                                    <?php
                                    if ( $fetched > 0 ) {
                                        printf(
                                            /* translators: 1: number of points, 2: date */
                                            esc_html__( 'ACS points: %1$s, updated %2$s', 'wc-acs-courier' ),
                                            esc_html( number_format_i18n( $feed->count() ) ),
                                            esc_html( date_i18n( 'Y-m-d H:i', $fetched ) )
                                        );
                                    } else {
                                        esc_html_e( 'Points have never been fetched.', 'wc-acs-courier' );
                                    }
                                    ?>
                                </p>
                                <?php if ( '' !== $feed->last_error() ) : ?>
                                    <p class="description" style="color:#b32d2e;"><?php echo esc_html( $feed->last_error() ); ?></p>
                                <?php endif; ?>
                                <button type="button" id="wc-acs-refresh-points" class="button button-secondary">
                                    <?php esc_html_e( 'Refresh points', 'wc-acs-courier' ); ?>
                                </button>
                                <span id="wc-acs-refresh-result" class="wc-acs-test-result"></span>
                                <p class="description"><?php esc_html_e( 'Lockers and stores offered by the "Pickup from ACS Point" shipping method. Refreshed automatically once a day.', 'wc-acs-courier' ); ?></p>
                            </td>
                        </tr>
                    </table>

                <?php elseif ( 'automation' === $active_tab ) : ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Auto-create Voucher', 'wc-acs-courier' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_acs_auto_create_voucher" value="yes"
                                        <?php checked( 'yes', get_option( 'wc_acs_auto_create_voucher', 'no' ) ); ?> />
                                    <?php esc_html_e( 'Automatically create a voucher when order status changes.', 'wc-acs-courier' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Auto-create on Status', 'wc-acs-courier' ); ?></th>
                            <td>
                                <select name="wc_acs_auto_create_status">
                                    <?php
                                    $statuses = wc_get_order_statuses();
                                    $selected = get_option( 'wc_acs_auto_create_status', 'wc-processing' );
                                    foreach ( $statuses as $key => $label ) :
                                        ?>
                                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $selected ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Which order status triggers automatic voucher creation.', 'wc-acs-courier' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Auto Tracking', 'wc-acs-courier' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_acs_auto_tracking" value="yes"
                                        <?php checked( 'yes', get_option( 'wc_acs_auto_tracking', 'yes' ) ); ?> />
                                    <?php esc_html_e( 'Automatically check shipment status and update orders.', 'wc-acs-courier' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Tracking Frequency', 'wc-acs-courier' ); ?></th>
                            <td>
                                <select name="wc_acs_tracking_frequency">
                                    <option value="hourly" <?php selected( 'hourly', get_option( 'wc_acs_tracking_frequency', 'hourly' ) ); ?>>
                                        <?php esc_html_e( 'Every hour', 'wc-acs-courier' ); ?>
                                    </option>
                                    <option value="twicedaily" <?php selected( 'twicedaily', get_option( 'wc_acs_tracking_frequency', 'hourly' ) ); ?>>
                                        <?php esc_html_e( 'Twice a day', 'wc-acs-courier' ); ?>
                                    </option>
                                    <option value="daily" <?php selected( 'daily', get_option( 'wc_acs_tracking_frequency', 'hourly' ) ); ?>>
                                        <?php esc_html_e( 'Once a day', 'wc-acs-courier' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Email Tracking Code', 'wc-acs-courier' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_acs_email_tracking" value="yes"
                                        <?php checked( 'yes', get_option( 'wc_acs_email_tracking', 'yes' ) ); ?> />
                                    <?php esc_html_e( 'Send tracking number to customer via email when voucher is created.', 'wc-acs-courier' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
