<?php
/**
 * Couple membership: $40/yr covers two linked WP users.
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_Couple {

    /** PMPro level ID for the Couple Annual level. Configurable via option. */
    public static function couple_level_ids() {
        $ids = (array) get_option( 'tm_couple_level_ids', array() );
        // Fallback: detect by name.
        if ( empty( $ids ) && function_exists( 'pmpro_getAllLevels' ) ) {
            foreach ( pmpro_getAllLevels( true, true ) as $lvl ) {
                if ( false !== stripos( $lvl->name, 'Couple' ) ) {
                    $ids[] = (int) $lvl->id;
                }
            }
        }
        return array_map( 'intval', $ids );
    }

    public static function init() {
        // Add a partner-email field to PMPro checkout for couple levels.
        add_action( 'pmpro_checkout_after_user_fields', array( __CLASS__, 'render_partner_field' ) );
        add_action( 'pmpro_after_checkout', array( __CLASS__, 'link_partner' ), 10, 2 );
    }

    public static function render_partner_field() {
        if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            return;
        }
        global $pmpro_level;
        if ( ! $pmpro_level || ! in_array( (int) $pmpro_level->id, self::couple_level_ids(), true ) ) {
            return;
        }
        $val = isset( $_REQUEST['tm_partner_email'] )
            ? esc_attr( wp_unslash( $_REQUEST['tm_partner_email'] ) )
            : '';
        ?>
        <div class="pmpro_checkout-field pmpro_checkout-field-tm-partner">
            <label for="tm_partner_email">Partner's Email Address (required for couple membership)</label>
            <input type="email" id="tm_partner_email" name="tm_partner_email" value="<?php echo $val; ?>" required />
            <small>We'll create a linked account so both partners can log in. Each partner signs the waiver separately.</small>
        </div>
        <?php
    }

    public static function link_partner( $primary_user_id, $order ) {
        if ( ! in_array( (int) ( $order->membership_id ?? 0 ), self::couple_level_ids(), true ) ) {
            return;
        }
        $partner_email = isset( $_REQUEST['tm_partner_email'] )
            ? sanitize_email( wp_unslash( $_REQUEST['tm_partner_email'] ) )
            : '';
        if ( ! is_email( $partner_email ) ) {
            return;
        }
        $partner = get_user_by( 'email', $partner_email );
        if ( ! $partner ) {
            $partner_id = wp_insert_user( array(
                'user_login' => sanitize_user( strstr( $partner_email, '@', true ) . '_' . wp_generate_password( 4, false ) ),
                'user_email' => $partner_email,
                'user_pass'  => wp_generate_password( 16 ),
                'role'       => 'tm_pending',
            ) );
            if ( is_wp_error( $partner_id ) ) {
                return;
            }
            wp_new_user_notification( $partner_id, null, 'user' );
        } else {
            $partner_id = $partner->ID;
        }

        update_user_meta( $primary_user_id, 'tm_partner_user_id', $partner_id );
        update_user_meta( $partner_id,      'tm_partner_user_id', $primary_user_id );

        // Mirror the PMPro membership level onto the partner.
        if ( function_exists( 'pmpro_changeMembershipLevel' ) ) {
            pmpro_changeMembershipLevel( (int) $order->membership_id, $partner_id );
        }
    }
}
