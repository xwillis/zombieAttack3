<?php
/**
 * Plugin Name: TM Member Portal
 * Description: Texas Mountaineers member portal: profile fields, waiver, certifications, gear, couple memberships, classes + waitlists, mass email, Discord invite.
 * Version:     0.1.0
 * Author:      Texas Mountaineers
 * License:     GPL-2.0-or-later
 * Requires PHP: 8.0
 *
 * @package TM_Member_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TM_PORTAL_VERSION', '0.1.0' );
define( 'TM_PORTAL_FILE', __FILE__ );
define( 'TM_PORTAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'TM_PORTAL_URL', plugin_dir_url( __FILE__ ) );

require_once TM_PORTAL_DIR . 'includes/class-tm-install.php';
require_once TM_PORTAL_DIR . 'includes/class-tm-roles.php';
require_once TM_PORTAL_DIR . 'includes/class-tm-profile-fields.php';
require_once TM_PORTAL_DIR . 'includes/class-tm-waiver.php';
require_once TM_PORTAL_DIR . 'includes/class-tm-certifications.php';
require_once TM_PORTAL_DIR . 'includes/class-tm-gear.php';
require_once TM_PORTAL_DIR . 'includes/class-tm-classes.php';
require_once TM_PORTAL_DIR . 'includes/class-tm-mass-email.php';
require_once TM_PORTAL_DIR . 'includes/class-tm-couple.php';
require_once TM_PORTAL_DIR . 'includes/class-tm-discord.php';
require_once TM_PORTAL_DIR . 'includes/class-tm-shortcodes.php';
require_once TM_PORTAL_DIR . 'includes/class-tm-rest.php';

register_activation_hook( __FILE__, array( 'TM_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TM_Install', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
    TM_Roles::init();
    TM_Profile_Fields::init();
    TM_Waiver::init();
    TM_Certifications::init();
    TM_Gear::init();
    TM_Classes::init();
    TM_Mass_Email::init();
    TM_Couple::init();
    TM_Discord::init();
    TM_Shortcodes::init();
    TM_REST::init();
} );

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'tm-portal',
        TM_PORTAL_URL . 'assets/css/portal.css',
        array(),
        TM_PORTAL_VERSION
    );
    wp_enqueue_script(
        'tm-portal',
        TM_PORTAL_URL . 'assets/js/portal.js',
        array(),
        TM_PORTAL_VERSION,
        true
    );
} );
