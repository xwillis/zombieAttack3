<?php
/**
 * Plugin Name: TM Defaults (must-use)
 * Description: Locks in TM-specific WordPress defaults that should never drift.
 *              MU plugins cannot be deactivated by an admin.
 *
 * @package TM_Member_Portal
 */

// Block direct access.
defined( 'ABSPATH' ) || exit;

// Force HTTPS for admin once SSL is provisioned upstream.
if ( ! defined( 'FORCE_SSL_ADMIN' ) ) {
    define( 'FORCE_SSL_ADMIN', false );
}

// Disable the in-admin theme/plugin file editor.
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
    define( 'DISALLOW_FILE_EDIT', true );
}

// Friendlier error display in production.
add_filter( 'wp_die_handler', function ( $h ) { return $h; } );

// Trim WP version from <head> meta and feed to reduce fingerprinting.
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

// Drop legacy XML-RPC pingback method to reduce DDoS amplification surface.
add_filter( 'xmlrpc_methods', function ( $methods ) {
    unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
    return $methods;
} );

// Force user-agent header for outbound HTTP (helps Wordfence, etc).
add_filter( 'http_request_args', function ( $args ) {
    if ( empty( $args['user-agent'] ) ) {
        $args['user-agent'] = 'TexasMountaineersPortal/' . get_bloginfo( 'version' );
    }
    return $args;
} );

// Whenever a brand-new user registers (via wp_register), default them to
// `tm_pending` instead of WP's `subscriber`. Belt + suspenders alongside
// the default_role option set in init-wp.sh.
add_action( 'user_register', function ( $user_id ) {
    $u = get_user_by( 'id', $user_id );
    if ( $u && in_array( 'subscriber', (array) $u->roles, true ) ) {
        $u->set_role( 'tm_pending' );
    }
}, 5 );
