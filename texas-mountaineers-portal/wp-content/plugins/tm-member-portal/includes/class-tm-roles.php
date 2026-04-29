<?php
/**
 * Custom roles + capabilities for TM eboard, instructors, members.
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_Roles {

    public static function init() {
        // Idempotent: ensure roles exist on every page load (cheap; WP handles caching).
        add_action( 'init', array( __CLASS__, 'register_roles' ), 1 );
    }

    /**
     * Roles:
     *   tm_pending              — registered but not paid / waiver not signed
     *   tm_member               — paid, waiver on file
     *   tm_training_coordinator — can manage classes + grant certs + email rosters
     *   tm_equipment_coordinator — can manage gear loans
     *   tm_treasurer            — can view/export roster + payments
     *   tm_eboard               — superset of the above (except admin)
     */
    public static function register_roles() {
        $member_caps = array(
            'read'                 => true,
            'tm_view_directory'    => true,
            'tm_signup_class'      => true,
            'tm_sign_waiver'       => true,
        );

        add_role( 'tm_pending', 'TM Pending Member', array( 'read' => true, 'tm_sign_waiver' => true ) );
        add_role( 'tm_member', 'TM Member', $member_caps );

        add_role( 'tm_training_coordinator', 'TM Training Coordinator', array_merge(
            $member_caps,
            array(
                'tm_manage_classes' => true,
                'tm_grant_certs'    => true,
                'tm_email_class'    => true,
                'tm_view_roster'    => true,
                'edit_posts'        => true,
            )
        ) );

        add_role( 'tm_equipment_coordinator', 'TM Equipment Coordinator', array_merge(
            $member_caps,
            array(
                'tm_manage_gear' => true,
                'tm_view_roster' => true,
            )
        ) );

        add_role( 'tm_treasurer', 'TM Treasurer', array_merge(
            $member_caps,
            array(
                'tm_view_roster'  => true,
                'tm_export_roster' => true,
            )
        ) );

        add_role( 'tm_eboard', 'TM Eboard', array_merge(
            $member_caps,
            array(
                'tm_manage_classes' => true,
                'tm_grant_certs'    => true,
                'tm_email_class'    => true,
                'tm_manage_gear'    => true,
                'tm_view_roster'    => true,
                'tm_export_roster'  => true,
                'edit_posts'        => true,
            )
        ) );

        // Site admin can do everything TM-specific too.
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( array(
                'tm_view_directory', 'tm_signup_class', 'tm_sign_waiver',
                'tm_manage_classes', 'tm_grant_certs', 'tm_email_class',
                'tm_manage_gear', 'tm_view_roster', 'tm_export_roster',
            ) as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }

    /**
     * Promote a pending user to full member after payment + waiver.
     */
    public static function promote_to_member( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $user->set_role( 'tm_member' );
    }
}
