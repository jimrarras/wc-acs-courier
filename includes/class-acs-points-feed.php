<?php
/**
 * ACS Points feed.
 *
 * Fetches every ACS pickup point (Smartpoint lockers and stores), keeps a
 * normalised copy in an option, refreshes it daily and answers lookups.
 *
 * @package WC_ACS_Courier
 */

defined( 'ABSPATH' ) || exit;

class WC_ACS_Points_Feed {

    const OPTION       = 'wc_acs_points_feed';
    const ERROR_OPTION = 'wc_acs_points_feed_error';
    const CRON_HOOK    = 'wc_acs_points_cron';
    const MIN_POINTS   = 100;

    /** @var WC_ACS_Points_Feed|null */
    private static $instance = null;

    /** @var array|null Decoded option, loaded once per request. */
    private $stored = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::CRON_HOOK, array( $this, 'refresh' ) );
        add_action( 'init', array( $this, 'maybe_schedule' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest' ) );
        add_action( 'wp_ajax_wc_acs_refresh_points', array( $this, 'ajax_refresh' ) );
    }

    /**
     * Store base country without WooCommerce's optional ":STATE" suffix.
     *
     * @return string
     */
    public static function store_country() {
        $raw   = (string) get_option( 'woocommerce_default_country', 'GR' );
        $parts = explode( ':', $raw );
        $code  = strtoupper( trim( $parts[0] ) );
        return '' === $code ? 'GR' : $code;
    }

    /**
     * Turn raw feed rows into compact point records.
     *
     * Stores always get cod = 1: the feed's flag describes locker terminals
     * and reports 0 for branches, but every ACS store takes cash on delivery.
     *
     * @param array  $raw_points Rows from ACSTableOutput.Table_Data1.
     * @param string $country    Country code to keep (GR or CY).
     * @return array
     */
    public static function normalise( array $raw_points, $country ) {
        $country = strtoupper( (string) $country );
        $out     = array();

        foreach ( $raw_points as $raw ) {
            if ( ! is_array( $raw ) ) {
                continue;
            }
            if ( strtoupper( (string) ( $raw['Country_Code'] ?? '' ) ) !== $country ) {
                continue;
            }
            $id = trim( (string) ( $raw['id'] ?? '' ) );
            if ( '' === $id ) {
                continue;
            }
            $lat = $raw['lat'] ?? null;
            $lon = $raw['lon'] ?? null;
            if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) ) {
                continue;
            }

            $type = ( 'branch' === ( $raw['type'] ?? '' ) ) ? 'store' : 'locker';

            $out[] = array(
                'id'      => $id,
                'type'    => $type,
                'name'    => trim( preg_replace( '/\s+/u', ' ', (string) ( $raw['name'] ?? '' ) ) ),
                'street'  => trim( (string) ( $raw['street'] ?? '' ) ),
                'city'    => trim( (string) ( $raw['city'] ?? '' ) ),
                'zip'     => trim( (string) ( $raw['sa_zipcode'] ?? '' ) ),
                'lat'     => round( (float) $lat, 6 ),
                'lon'     => round( (float) $lon, 6 ),
                'station' => trim( (string) ( $raw['Acs_Station_Destination'] ?? '' ) ),
                'branch'  => trim( (string) ( $raw['Acs_Station_Branch_Destination'] ?? '' ) ),
                'cod'     => 'store' === $type ? 1 : (int) ! empty( $raw['Acs_Smartpoint_COD_Supported'] ),
                'h24'     => (int) ! empty( $raw['is_24h'] ),
                'hours'   => trim( (string) ( $raw['weekdays'] ?? '' ) ),
                'sat'     => trim( (string) ( $raw['saturday'] ?? '' ) ),
            );
        }

        return $out;
    }

    /**
     * Fetch from ACS and replace the stored list. Keeps the old list on failure.
     *
     * @return true|WP_Error
     */
    public function refresh() {
        $result = WC_ACS_API::get_points_feed();

        if ( is_wp_error( $result ) ) {
            $this->remember_error( $result->get_error_message() );
            return $result;
        }

        $raw     = $result['ACSTableOutput']['Table_Data1'] ?? array();
        $country = self::store_country();
        $points  = self::normalise( is_array( $raw ) ? $raw : array(), $country );

        if ( count( $points ) < self::MIN_POINTS ) {
            $error = new WP_Error(
                'acs_points_feed_short',
                sprintf(
                    /* translators: %d: number of points returned */
                    __( 'ACS returned only %d points; keeping the stored list.', 'wc-acs-courier' ),
                    count( $points )
                )
            );
            $this->remember_error( $error->get_error_message() );
            return $error;
        }

        $this->stored = array(
            'fetched_at' => time(),
            'country'    => $country,
            'points'     => $points,
        );
        update_option( self::OPTION, $this->stored, false );
        delete_option( self::ERROR_OPTION );

        return true;
    }

    private function remember_error( $message ) {
        WC_ACS_API::log( 'Points feed refresh failed: ' . $message, 'error' );
        update_option( self::ERROR_OPTION, $message, false );
    }

    /**
     * Last refresh error message, or empty string.
     *
     * @return string
     */
    public function last_error() {
        return (string) get_option( self::ERROR_OPTION, '' );
    }

    private function stored() {
        if ( null === $this->stored ) {
            $value        = get_option( self::OPTION, array() );
            $this->stored = is_array( $value ) ? $value : array();
        }
        return $this->stored;
    }

    /** @return array */
    public function get_points() {
        $stored = $this->stored();
        return isset( $stored['points'] ) && is_array( $stored['points'] ) ? $stored['points'] : array();
    }

    /** @return int Unix timestamp of the last successful refresh, 0 if none. */
    public function fetched_at() {
        $stored = $this->stored();
        return (int) ( $stored['fetched_at'] ?? 0 );
    }

    /** @return int */
    public function count() {
        return count( $this->get_points() );
    }

    /**
     * @param string|int $id Feed id.
     * @return array|null
     */
    public function find( $id ) {
        $id = trim( (string) $id );
        if ( '' === $id ) {
            return null;
        }
        foreach ( $this->get_points() as $point ) {
            if ( isset( $point['id'] ) && (string) $point['id'] === $id ) {
                return $point;
            }
        }
        return null;
    }

    /**
     * Map centre for a customer postcode: median of the points sharing the
     * first three digits (zoom 13), else the first two (zoom 10), else null.
     *
     * @param string $zip    Postcode as typed.
     * @param array  $points Point records with zip, lat, lon.
     * @return array|null array( lat, lon, zoom )
     */
    public static function centre_for_postcode( $zip, array $points ) {
        $digits = preg_replace( '/\D+/', '', (string) $zip );

        foreach ( array( array( 3, 13 ), array( 2, 10 ) ) as $rule ) {
            list( $len, $zoom ) = $rule;
            if ( strlen( $digits ) < $len ) {
                continue;
            }
            $prefix = substr( $digits, 0, $len );
            $lats   = array();
            $lons   = array();
            foreach ( $points as $p ) {
                if ( 0 === strpos( (string) ( $p['zip'] ?? '' ), $prefix ) ) {
                    $lats[] = (float) $p['lat'];
                    $lons[] = (float) $p['lon'];
                }
            }
            if ( ! empty( $lats ) ) {
                return array( self::median( $lats ), self::median( $lons ), $zoom );
            }
        }

        return null;
    }

    private static function median( array $values ) {
        sort( $values );
        $n   = count( $values );
        $mid = (int) floor( $n / 2 );
        $med = ( 0 === $n % 2 ) ? ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2 : $values[ $mid ];
        return round( $med, 6 );
    }

    /**
     * Schedule the daily refresh if it is missing (covers upgrades that skip
     * the activation hook).
     */
    public function maybe_schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    const REST_NAMESPACE = 'wc-acs/v1';

    /**
     * Public, read-only route. The list is not personal data and the browser
     * caches it for a day, so no nonce and no cookies are involved.
     */
    public function register_rest() {
        register_rest_route( self::REST_NAMESPACE, '/points', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_points' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Positional rows keep the payload around 60 KB gzipped for 2,000 points.
     * Column order: id, type, name, street, city, zip, lat, lon, station,
     * branch, cod, h24, hours, sat. acs-points.js reads the same order.
     *
     * @return array
     */
    public function payload() {
        $rows = array();
        foreach ( $this->get_points() as $p ) {
            $rows[] = array(
                $p['id'], $p['type'], $p['name'], $p['street'], $p['city'], $p['zip'],
                $p['lat'], $p['lon'], $p['station'], $p['branch'],
                (int) $p['cod'], (int) $p['h24'], $p['hours'], $p['sat'],
            );
        }
        return array(
            'v'      => $this->fetched_at(),
            'points' => $rows,
        );
    }

    /**
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function rest_points( $request ) {
        $etag = '"' . $this->fetched_at() . '"';

        if ( $request->get_header( 'if_none_match' ) === $etag ) {
            $response = new WP_REST_Response( null, 304 );
            $response->header( 'ETag', $etag );
            return $response;
        }

        $response = new WP_REST_Response( $this->payload(), 200 );
        $response->header( 'Cache-Control', 'public, max-age=86400' );
        $response->header( 'ETag', $etag );
        return $response;
    }

    /**
     * Settings-page button: refresh now and report.
     */
    public function ajax_refresh() {
        check_ajax_referer( 'wc_acs_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'wc-acs-courier' ) );
        }

        WC_ACS_API::reset_credentials();
        $result = $this->refresh();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'count'   => $this->count(),
            'fetched' => date_i18n( 'Y-m-d H:i', $this->fetched_at() ),
        ) );
    }
}
