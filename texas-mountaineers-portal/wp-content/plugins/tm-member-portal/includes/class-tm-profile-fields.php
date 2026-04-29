<?php
/**
 * Adds TM-specific user profile fields and exposes them on the WP profile screen
 * and via PMPro register/checkout fields.
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_Profile_Fields {

    /** Field key => label. Stored in user meta. */
    public static function fields() {
        return array(
            'phone_cell'           => 'Cell Phone',
            'phone_home'           => 'Home Phone',
            'address_1'            => 'Street Address',
            'address_2'            => 'Apt / Suite',
            'address_city'         => 'City',
            'address_state'        => 'State / Province',
            'address_zip'          => 'Postal Code',
            'address_country'      => 'Country',
            'emergency_name'       => 'Emergency Contact Name',
            'emergency_phone'      => 'Emergency Contact Phone',
            'emergency_relation'   => 'Relationship to Emergency Contact',
            'discord_handle'       => 'Discord Handle',
            'partner_user_id'      => 'Partner WP User ID (for couple memberships)',
        );
    }

    public static function init() {
        add_action( 'show_user_profile', array( __CLASS__, 'render_admin_fields' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'render_admin_fields' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save_admin_fields' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_admin_fields' ) );
    }

    public static function render_admin_fields( $user ) {
        if ( ! current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }
        echo '<h2>Texas Mountaineers Member Info</h2><table class="form-table">';
        foreach ( self::fields() as $key => $label ) {
            $value = esc_attr( get_user_meta( $user->ID, 'tm_' . $key, true ) );
            printf(
                '<tr><th><label for="tm_%1$s">%2$s</label></th>'
                . '<td><input type="text" name="tm_%1$s" id="tm_%1$s" value="%3$s" class="regular-text" /></td></tr>',
                esc_attr( $key ),
                esc_html( $label ),
                $value
            );
        }
        echo '</table>';
    }

    public static function save_admin_fields( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }
        foreach ( array_keys( self::fields() ) as $key ) {
            $field = 'tm_' . $key;
            if ( isset( $_POST[ $field ] ) ) {
                update_user_meta(
                    $user_id,
                    $field,
                    sanitize_text_field( wp_unslash( $_POST[ $field ] ) )
                );
            }
        }
    }
}
