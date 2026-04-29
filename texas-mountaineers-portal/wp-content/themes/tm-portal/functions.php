<?php
/**
 * Astra child-theme bootstrap.
 *
 * @package TM_Portal_Theme
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'astra-parent',
        get_template_directory_uri() . '/style.css',
        array(),
        wp_get_theme( 'astra' )->get( 'Version' )
    );
    wp_enqueue_style(
        'tm-portal-child',
        get_stylesheet_uri(),
        array( 'astra-parent' ),
        wp_get_theme()->get( 'Version' )
    );
}, 20 );

// Add a quick "Member Dashboard" link to the user toolbar for logged-in members.
add_action( 'admin_bar_menu', function ( $bar ) {
    if ( ! is_user_logged_in() ) {
        return;
    }
    $bar->add_node( array(
        'id'    => 'tm-dashboard',
        'title' => 'My TM Dashboard',
        'href'  => home_url( '/member-dashboard/' ),
    ) );
}, 100 );
