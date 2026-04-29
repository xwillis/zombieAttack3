<?php
/**
 * Liability waiver: typed-name e-signature, captured per membership year.
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_Waiver {

    const TEXT = <<<EOT
RELEASE AND INDEMNITY AGREEMENT — TEXAS MOUNTAINEERS

I acknowledge that rock climbing, mountaineering, and related activities
(collectively, the "Activities") are inherently dangerous and carry a
risk of serious bodily injury or death. I voluntarily assume all risks
associated with my participation, whether as a member, instructor,
student, guest, or volunteer of the Texas Mountaineers ("TM").

In consideration of being permitted to participate, I, for myself, my
heirs, personal representatives, or assigns, do hereby release, waive,
discharge, and covenant not to sue TM, its officers, directors,
employees, members, instructors, and agents (collectively, "Released
Parties") from liability from any and all claims arising from my
participation in the Activities, including any such claims that allege
negligent acts or omissions of the Released Parties.

I acknowledge that I am responsible for my own safety and for evaluating
my own ability to participate in any Activity. I agree to follow all
TM rules and the directions of TM officers and instructors.

By typing my full legal name below, I certify that I have read and
understood this Release, that I am at least 18 years of age, and that
I am signing it freely and voluntarily.
EOT;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'maybe_handle_submit' ) );
        add_filter( 'the_content', array( __CLASS__, 'gate_member_content' ), 5 );
    }

    public static function text_hash() {
        return hash( 'sha256', self::TEXT );
    }

    public static function current_year() {
        return (int) wp_date( 'Y' );
    }

    public static function has_signed( $user_id, $year = null ) {
        global $wpdb;
        $year = $year ?: self::current_year();
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tm_waivers WHERE user_id = %d AND membership_year = %d",
            $user_id,
            $year
        ) );
        return ! empty( $row );
    }

    public static function maybe_handle_submit() {
        if ( empty( $_POST['tm_waiver_submit'] ) ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            return;
        }
        check_admin_referer( 'tm_waiver_sign', 'tm_waiver_nonce' );

        $user_id   = get_current_user_id();
        $full_name = isset( $_POST['tm_waiver_full_name'] )
            ? sanitize_text_field( wp_unslash( $_POST['tm_waiver_full_name'] ) )
            : '';
        $confirm   = ! empty( $_POST['tm_waiver_confirm'] );

        if ( ! $full_name || ! $confirm ) {
            wp_safe_redirect( add_query_arg( 'tm_waiver', 'invalid', wp_get_referer() ?: home_url( '/waiver/' ) ) );
            exit;
        }

        global $wpdb;
        $wpdb->replace(
            "{$wpdb->prefix}tm_waivers",
            array(
                'user_id'         => $user_id,
                'full_name'       => $full_name,
                'membership_year' => self::current_year(),
                'ip_address'      => self::client_ip(),
                'user_agent'      => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 500 ),
                'text_hash'       => self::text_hash(),
                'signed_at'       => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
        );

        do_action( 'tm_waiver_signed', $user_id );

        wp_safe_redirect( add_query_arg( 'tm_waiver', 'signed', home_url( '/member-dashboard/' ) ) );
        exit;
    }

    /**
     * If a member-only page is accessed without a signed current-year waiver, swap content.
     */
    public static function gate_member_content( $content ) {
        if ( ! is_singular() || ! is_user_logged_in() ) {
            return $content;
        }
        global $post;
        $member_only_slugs = array( 'member-dashboard', 'member-directory' );
        if ( ! in_array( $post->post_name, $member_only_slugs, true ) ) {
            return $content;
        }
        if ( self::has_signed( get_current_user_id() ) ) {
            return $content;
        }
        return '<div class="tm-notice tm-notice-warning">'
            . esc_html__( 'You must sign the current-year liability waiver before accessing member resources.', 'tm-portal' )
            . ' <a href="' . esc_url( home_url( '/waiver/' ) ) . '">Sign waiver</a>.'
            . '</div>';
    }

    private static function client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $forwarded = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip = trim( $forwarded[0] );
        }
        return substr( filter_var( $ip, FILTER_VALIDATE_IP ) ?: '', 0, 64 );
    }
}
