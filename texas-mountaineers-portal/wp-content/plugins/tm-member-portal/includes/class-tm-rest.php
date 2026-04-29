<?php
/**
 * REST API: /wp-json/tm/v1/...
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_REST {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
    }

    public static function register() {
        register_rest_route( 'tm/v1', '/me', array(
            'methods'             => 'GET',
            'permission_callback' => function () { return is_user_logged_in(); },
            'callback'            => array( __CLASS__, 'me' ),
        ) );

        register_rest_route( 'tm/v1', '/classes/(?P<id>\d+)/roster.csv', array(
            'methods'             => 'GET',
            'permission_callback' => function () { return current_user_can( 'tm_view_roster' ); },
            'callback'            => array( __CLASS__, 'roster_csv' ),
        ) );

        register_rest_route( 'tm/v1', '/classes/(?P<id>\d+)/promote', array(
            'methods'             => 'POST',
            'permission_callback' => function () { return current_user_can( 'tm_manage_classes' ); },
            'callback'            => array( __CLASS__, 'promote' ),
        ) );
    }

    public static function me( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        $level   = function_exists( 'pmpro_getMembershipLevelForUser' ) ? pmpro_getMembershipLevelForUser( $user_id ) : null;

        global $wpdb;
        $classes = $wpdb->get_results( $wpdb->prepare(
            "SELECT class_id, status, position
             FROM {$wpdb->prefix}tm_class_registrations
             WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ) );

        return rest_ensure_response( array(
            'user'         => array(
                'id'           => $user_id,
                'display_name' => wp_get_current_user()->display_name,
                'email'        => wp_get_current_user()->user_email,
            ),
            'membership'   => $level ? array(
                'name'     => $level->name,
                'enddate'  => $level->enddate ? wp_date( 'Y-m-d', (int) $level->enddate ) : null,
            ) : null,
            'waiver_signed_year' => TM_Waiver::has_signed( $user_id ) ? TM_Waiver::current_year() : null,
            'certifications' => array_map( function ( $c ) {
                return array(
                    'slug'       => $c->cert_slug,
                    'granted_on' => $c->granted_on,
                );
            }, TM_Certifications::for_user( $user_id ) ),
            'classes'      => $classes,
            'gear_on_loan' => TM_Gear::open_loans_for_user( $user_id ),
        ) );
    }

    public static function roster_csv( WP_REST_Request $req ) {
        $class_id = absint( $req['id'] );
        $rows     = TM_Classes::roster( $class_id, 'both' );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="roster-' . $class_id . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'name', 'email', 'status', 'position' ) );
        foreach ( $rows as $r ) {
            fputcsv( $out, array( $r->display_name, $r->user_email, $r->status, $r->position ) );
        }
        fclose( $out );
        exit;
    }

    public static function promote( WP_REST_Request $req ) {
        TM_Classes::promote_next_from_waitlist( absint( $req['id'] ) );
        return rest_ensure_response( array( 'ok' => true ) );
    }
}
