<?php
/**
 * ACS REST API Client
 *
 * Handles all communication with the ACS Courier REST API.
 * API Docs: https://webservices.acscourier.net/ACSRestServices/swagger/
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_API {

    /**
     * API endpoint URL.
     */
    const API_URL = 'https://webservices.acscourier.net/ACSRestServices/api/ACSAutoRest';

    /**
     * Cached credentials.
     *
     * @var array|null
     */
    private static $credentials = null;

    /**
     * Get stored ACS credentials.
     *
     * @return array
     */
    public static function get_credentials() {
        if ( null === self::$credentials ) {
            self::$credentials = array(
                'Company_ID'       => get_option( 'wc_acs_company_id', '' ),
                'Company_Password' => get_option( 'wc_acs_company_password', '' ),
                'User_ID'          => get_option( 'wc_acs_user_id', '' ),
                'User_Password'    => get_option( 'wc_acs_user_password', '' ),
            );
        }
        return self::$credentials;
    }

    /**
     * Reset cached credentials (e.g., after settings change).
     */
    public static function reset_credentials() {
        self::$credentials = null;
    }

    /**
     * Get the API key.
     *
     * @return string
     */
    public static function get_api_key() {
        return get_option( 'wc_acs_api_key', '' );
    }

    /**
     * Make a POST request to the ACS API.
     *
     * @param string $alias           ACS method alias.
     * @param array  $params          Input parameters (without credentials).
     * @param bool   $with_credentials Whether to include credentials.
     * @return array|WP_Error
     */
    public static function request( $alias, $params = array(), $with_credentials = true ) {
        $api_key = self::get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'acs_no_api_key', __( 'ACS API Key is not configured.', 'wc-acs-courier' ) );
        }

        $body = array(
            'ACSAlias' => $alias,
        );

        if ( ! empty( $params ) || $with_credentials ) {
            $input_params = $with_credentials ? array_merge( self::get_credentials(), $params ) : $params;
            $body['ACSInputParameters'] = $input_params;
        }

        $api_url = defined( 'WC_ACS_API_URL' ) ? WC_ACS_API_URL : self::API_URL;

        $response = wp_remote_post( $api_url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'AcsApiKey'    => $api_key,
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            self::log( 'API request failed: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $raw_body  = wp_remote_retrieve_body( $response );

        if ( 403 === $http_code ) {
            return new WP_Error( 'acs_forbidden', __( 'Invalid ACS API Key.', 'wc-acs-courier' ) );
        }

        if ( 406 === $http_code ) {
            return new WP_Error( 'acs_rate_limit', __( 'ACS API rate limit exceeded. Try again shortly.', 'wc-acs-courier' ) );
        }

        $data = json_decode( $raw_body, true );

        if ( null === $data ) {
            self::log( 'Invalid JSON response from ACS API: ' . $raw_body, 'error' );
            return new WP_Error( 'acs_invalid_response', __( 'Invalid response from ACS API.', 'wc-acs-courier' ) );
        }

        // Check for ACS execution errors
        if ( ! empty( $data['ACSExecution_HasError'] ) ) {
            $error_msg = $data['ACSExecutionErrorMessage'] ?? __( 'Unknown ACS API error.', 'wc-acs-courier' );
            self::log( "ACS API error [{$alias}]: {$error_msg}", 'error' );
            return new WP_Error( 'acs_api_error', $error_msg );
        }

        return $data['ACSOutputResponce'] ?? $data['ACSOutputResponse'] ?? $data;
    }

    // ─── VOUCHER METHODS ───────────────────────────────────────────

    /**
     * Create a voucher.
     *
     * @param array $shipment_data Shipment parameters.
     * @return array|WP_Error Response with Voucher_No on success.
     */
    public static function create_voucher( $shipment_data ) {
        $defaults = array(
            'Pickup_Date'                    => current_time( 'Y-m-d' ),
            'Recipient_Country'              => 'GR',
            'Acs_Station_Destination'        => null,
            'Acs_Station_Branch_Destination' => 1,
            'Charge_Type'                    => 2,
            'Cost_Center_Code'               => null,
            'Item_Quantity'                   => 1,
            'Weight'                          => 0.5,
            'Dimension_X_In_Cm'              => null,
            'Dimension_Y_In_Cm'              => null,
            'Dimension_Z_In_Cm'              => null,
            'Cod_Ammount'                    => null,
            'Cod_Payment_Way'                => null,
            'Acs_Delivery_Products'          => null,
            'Insurance_Ammount'              => null,
            'Delivery_Notes'                 => null,
            'Appointment_Until_Time'         => null,
            'Recipient_Email'                => null,
            'Reference_Key1'                 => null,
            'Reference_Key2'                 => null,
            'With_Return_Voucher'            => null,
            'Content_Type_ID'                => null,
            'Language'                        => 'EN',
        );

        $params = array_merge( $defaults, $shipment_data );

        // Add billing code from settings
        if ( empty( $params['Billing_Code'] ) ) {
            $params['Billing_Code'] = get_option( 'wc_acs_billing_code', '' );
        }

        // Add sender name from settings
        if ( empty( $params['Sender'] ) ) {
            $params['Sender'] = get_option( 'wc_acs_sender_name', get_bloginfo( 'name' ) );
        }

        return self::request( 'ACS_Create_Voucher', $params );
    }

    /**
     * Print voucher as PDF.
     *
     * @param string $voucher_no  Voucher number(s), pipe-separated for multiple.
     * @param int    $print_type  1 = Thermal, 2 = Laser (A4).
     * @param int    $start_pos   Start position on A4 (1, 2, or 3).
     * @return array|WP_Error Response with PDF data.
     */
    public static function print_voucher( $voucher_no, $print_type = 2, $start_pos = 1 ) {
        return self::request( 'ACS_Print_Voucher', array(
            'Voucher_No'     => $voucher_no,
            'Print_Type'     => $print_type,
            'Start_Position' => $start_pos,
        ) );
    }

    /**
     * Delete a voucher.
     *
     * @param string $voucher_no Voucher number(s), comma-separated for bulk (max 20).
     * @return array|WP_Error
     */
    public static function delete_voucher( $voucher_no ) {
        return self::request( 'ACS_Delete_Voucher', array(
            'Voucher_No' => $voucher_no,
        ) );
    }

    // ─── PICKUP LIST METHODS ───────────────────────────────────────

    /**
     * Issue (finalize) the pickup list for a given date.
     *
     * @param string $date   Pickup date (Y-m-d).
     * @param int    $my_data 0 = all users, 1 = current user only.
     * @return array|WP_Error Response with PickupList_No.
     */
    public static function issue_pickup_list( $date = null, $my_data = null ) {
        return self::request( 'ACS_Issue_Pickup_List', array(
            'Language'    => 'EN',
            'Pickup_Date' => $date ?? current_time( 'Y-m-d' ),
            'MyData'      => $my_data,
        ) );
    }

    /**
     * Print a pickup list PDF.
     *
     * @param string $pickup_list_no PickupList_No from issue_pickup_list.
     * @param string $date           Pickup date.
     * @return array|WP_Error
     */
    public static function print_pickup_list( $pickup_list_no, $date = null ) {
        return self::request( 'ACS_Print_Pickup_List', array(
            'Language'    => 'EN',
            'Mass_Number' => $pickup_list_no,
            'Pickup_Date' => $date ?? current_time( 'Y-m-d' ),
        ) );
    }

    /**
     * Get all pickup lists for a date.
     *
     * @param string $date Pickup date.
     * @return array|WP_Error
     */
    public static function get_pickup_lists( $date = null ) {
        return self::request( 'ACS_Get_Pickup_Lists', array(
            'Language'    => 'EN',
            'Pickup_Date' => $date ?? current_time( 'Y-m-d' ),
        ) );
    }

    // ─── TRACKING METHODS ──────────────────────────────────────────

    /**
     * Get tracking summary for a voucher.
     *
     * @param string $voucher_no Voucher number.
     * @return array|WP_Error
     */
    public static function tracking_summary( $voucher_no ) {
        return self::request( 'ACS_Trackingsummary', array(
            'Language'   => 'EN',
            'Voucher_No' => $voucher_no,
        ) );
    }

    /**
     * Get detailed tracking for a voucher.
     *
     * @param string $voucher_no Voucher number.
     * @return array|WP_Error
     */
    public static function tracking_details( $voucher_no ) {
        return self::request( 'ACS_TrackingDetails', array(
            'Language'   => 'EN',
            'Voucher_No' => $voucher_no,
        ) );
    }

    // ─── PRICE & ADDRESS METHODS ───────────────────────────────────

    /**
     * Calculate shipping price.
     *
     * @param array $params Price calculation parameters.
     * @return array|WP_Error Response with pricing breakdown.
     */
    public static function price_calculation( $params ) {
        $defaults = array(
            'Billing_Code'          => get_option( 'wc_acs_billing_code', '' ),
            'Billing_Category'      => 2,
            'Acs_Station_Origin'    => get_option( 'wc_acs_station_origin', '' ),
            'Weight'                => '0,5',
            'Pickup_Date'           => current_time( 'Y-m-d' ),
            'Acs_Delivery_Products' => null,
            'Charge_Type'           => 2,
            'Delivery_Zone'         => null,
            'Insurance_Ammount'     => null,
            'Dimension_X_In_Cm'     => null,
            'Dimension_Y_In_Cm'     => null,
            'Dimension_Z_In_Cm'     => null,
            'Language'              => null,
        );

        return self::request( 'ACS_Price_Calculation', array_merge( $defaults, $params ) );
    }

    /**
     * Validate an address and get station info.
     *
     * @param string $address   Full address string (in Greek).
     * @param string $address_id Optional cached address ID.
     * @return array|WP_Error
     */
    public static function address_validation( $address, $address_id = null ) {
        return self::request( 'ACS_Address_Validation', array(
            'Language'  => null,
            'Address'   => $address,
            'AddressID' => $address_id,
        ) );
    }

    /**
     * Find areas by zip code.
     *
     * @param string $zip_code                   Zip code.
     * @param int    $show_only_inaccessible      0 = all, 1 = remote only.
     * @param string $country                     GR, CY, BG, AL.
     * @return array|WP_Error
     */
    public static function find_by_zipcode( $zip_code, $show_only_inaccessible = 0, $country = 'GR' ) {
        return self::request( 'ACS_Area_Find_By_Zip_Code', array(
            'Zip_Code'                    => $zip_code,
            'Show_Only_Inaccessible_Areas' => $show_only_inaccessible,
            'Language'                     => 'EN',
            'Country'                      => $country,
        ) );
    }

    // ─── STATION METHODS ───────────────────────────────────────────

    /**
     * Get ACS stations/shops.
     *
     * @param string $country   GR or CY.
     * @param int    $shop_kind 1=central, 2/3=sub, 4=Xpress, 5=Kiosks, 7=Smartpoints.
     * @return array|WP_Error
     */
    public static function get_stations( $country = 'GR', $shop_kind = 1 ) {
        return self::request( 'ACS_Stations', array(
            'language'            => 'EN',
            'ACS_SHOP_COUNTRY_ID' => $country,
            'ACS_SHOP_KIND'       => $shop_kind,
        ) );
    }

    /**
     * Get every ACS pickup point (Smartpoint lockers and ACS stores) in one call.
     *
     * Uses the alias that ACS ships in its own merchant plugin. Each row carries
     * coordinates, opening hours, the two voucher routing codes
     * (Acs_Station_Destination, Acs_Station_Branch_Destination) and a flag for
     * card payment on collection. Verified 2026-09-09: about 2,000 rows.
     *
     * @return array|WP_Error ACSOutputResponce; points under ACSTableOutput.Table_Data1.
     */
    public static function get_points_feed() {
        return self::request( 'ACS_Get_Stations_For_Plugin', array(
            'locale' => null,
        ) );
    }

    // ─── COD METHODS ───────────────────────────────────────────────

    /**
     * Get COD beneficiary info for a date.
     *
     * @param string $date Payment date (Y-m-d).
     * @return array|WP_Error
     */
    public static function cod_beneficiary_info( $date ) {
        return self::request( 'ACS_COD_Beneficiary_Info', array(
            'User_locals'    => 'GR',
            'COD_Payment_Date' => $date,
        ) );
    }

    // ─── UTILITY METHODS ───────────────────────────────────────────

    /**
     * Test API credentials.
     *
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function test_connection() {
        $result = self::find_by_zipcode( '10431', 0, 'GR' );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( ! empty( $result['ACSTableOutput']['Table_Data'] ) ) {
            return true;
        }

        return new WP_Error( 'acs_test_failed', __( 'Connection test failed — no data returned.', 'wc-acs-courier' ) );
    }

    /**
     * Get the station ID for a zip code.
     *
     * @param string $zip_code Zip code.
     * @param string $country  Country code.
     * @return string|null Station ID or null.
     */
    public static function get_station_for_zipcode( $zip_code, $country = 'GR' ) {
        $result = self::find_by_zipcode( $zip_code, 0, $country );

        if ( is_wp_error( $result ) ) {
            return null;
        }

        $table_data = $result['ACSTableOutput']['Table_Data'] ?? array();
        if ( ! empty( $table_data[0]['Station_ID'] ) ) {
            return $table_data[0]['Station_ID'];
        }

        return null;
    }

    /**
     * Log messages if WooCommerce logging is available.
     *
     * @param string $message Log message.
     * @param string $level   Log level (debug, info, notice, warning, error, critical).
     */
    public static function log( $message, $level = 'info' ) {
        if ( 'yes' !== get_option( 'wc_acs_debug_logging', 'no' ) ) {
            return;
        }

        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->log( $level, $message, array( 'source' => 'wc-acs-courier' ) );
        }
    }
}
