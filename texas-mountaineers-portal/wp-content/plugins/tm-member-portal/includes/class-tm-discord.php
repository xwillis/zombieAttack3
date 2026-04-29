<?php
/**
 * Discord invite trigger: when a user has an active membership AND a current-year
 * waiver on file, post a single-use invite via the configured webhook and email it.
 *
 * Configure TM_DISCORD_WEBHOOK_URL in wp-config.php (already set by docker-compose
 * from the DISCORD_WEBHOOK_URL env var).
 *
 * The webhook URL on the TM side should be a *server-side* endpoint that
 * generates a single-use invite and returns JSON: { "invite_url": "..." }.
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_Discord {

    public static function init() {
        add_action( 'tm_waiver_signed', array( __CLASS__, 'maybe_send_invite' ) );
        add_action( 'pmpro_after_change_membership_level', array( __CLASS__, 'on_level_change' ), 10, 2 );
    }

    public static function on_level_change( $level_id, $user_id ) {
        if ( $level_id ) {
            self::maybe_send_invite( $user_id );
        }
    }

    public static function maybe_send_invite( $user_id ) {
        if ( ! defined( 'TM_DISCORD_WEBHOOK_URL' ) || empty( TM_DISCORD_WEBHOOK_URL ) ) {
            return;
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        if ( ! TM_Waiver::has_signed( $user_id ) ) {
            return;
        }
        if ( get_user_meta( $user_id, 'tm_discord_invite_sent_year', true ) === (string) TM_Waiver::current_year() ) {
            return; // Already invited this year.
        }

        $resp = wp_remote_post( TM_DISCORD_WEBHOOK_URL, array(
            'timeout' => 15,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array(
                'user_email' => $user->user_email,
                'user_name'  => $user->display_name,
            ) ),
        ) );
        if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
            return;
        }

        $payload = json_decode( wp_remote_retrieve_body( $resp ), true );
        $invite  = is_array( $payload ) ? ( $payload['invite_url'] ?? '' ) : '';
        if ( ! $invite ) {
            return;
        }

        wp_mail(
            $user->user_email,
            '[Texas Mountaineers] Your Discord invite',
            sprintf(
                "Hi %s,\n\nWelcome to the Texas Mountaineers. Your single-use Discord invite is below — it expires in 24 hours.\n\n%s\n\n— Texas Mountaineers",
                $user->display_name,
                esc_url_raw( $invite )
            )
        );
        update_user_meta( $user_id, 'tm_discord_invite_sent_year', TM_Waiver::current_year() );
    }
}
