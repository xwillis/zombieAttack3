<?php
/**
 * Internal certifications: Beginner, Anchor, Sport, Intermediate, Assisted-*, Qualified-Trad-Leader.
 * Granted by Training Coordinator / eboard.
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_Certifications {

    /** Slug => display label. Edit here to add a cert. */
    public static function catalog() {
        return array(
            'beginner'              => 'Beginner Class',
            'anchor'                => 'Anchor Class',
            'sport'                 => 'Sport Lead Class',
            'intermediate'          => 'Intermediate / Trad Class',
            'assisted_indoor'       => 'Assisted Indoor Class',
            'assisted_outdoor'      => 'Assisted Outdoor Class',
            'assisted_anchor'       => 'Assisted Anchor Class',
            'qualified_trad_leader' => 'Qualified Trad Leader',
            'first_aid'             => 'First Aid (current)',
            'instructor'            => 'Instructor (vetted by Safety Officer)',
        );
    }

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
        add_action( 'admin_post_tm_grant_cert', array( __CLASS__, 'handle_grant' ) );
        add_action( 'admin_post_tm_revoke_cert', array( __CLASS__, 'handle_revoke' ) );
    }

    public static function for_user( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT cert_slug, granted_on, granted_by, notes
             FROM {$wpdb->prefix}tm_certifications
             WHERE user_id = %d
             ORDER BY granted_on DESC",
            $user_id
        ) );
    }

    public static function user_has( $user_id, $slug ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tm_certifications WHERE user_id = %d AND cert_slug = %s",
            $user_id,
            $slug
        ) );
    }

    public static function register_admin_page() {
        add_users_page(
            'TM Certifications',
            'TM Certifications',
            'tm_grant_certs',
            'tm-certifications',
            array( __CLASS__, 'render_admin' )
        );
    }

    public static function render_admin() {
        if ( ! current_user_can( 'tm_grant_certs' ) ) {
            wp_die( 'Forbidden' );
        }
        $catalog = self::catalog();
        $users   = get_users( array( 'role__in' => array(
            'tm_member', 'tm_pending', 'tm_training_coordinator',
            'tm_equipment_coordinator', 'tm_treasurer', 'tm_eboard',
        ) ) );
        ?>
        <div class="wrap">
            <h1>Grant Certification</h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tm_grant_cert' ); ?>
                <input type="hidden" name="action" value="tm_grant_cert" />
                <table class="form-table">
                    <tr>
                        <th><label for="tm_user">Member</label></th>
                        <td>
                            <select name="user_id" id="tm_user" required>
                                <?php foreach ( $users as $u ) : ?>
                                    <option value="<?php echo esc_attr( $u->ID ); ?>">
                                        <?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tm_cert">Certification</label></th>
                        <td>
                            <select name="cert_slug" id="tm_cert" required>
                                <?php foreach ( $catalog as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tm_granted_on">Date Granted</label></th>
                        <td><input type="date" name="granted_on" id="tm_granted_on" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required /></td>
                    </tr>
                    <tr>
                        <th><label for="tm_notes">Notes</label></th>
                        <td><textarea name="notes" id="tm_notes" rows="3" class="large-text"></textarea></td>
                    </tr>
                </table>
                <?php submit_button( 'Grant Certification' ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_grant() {
        if ( ! current_user_can( 'tm_grant_certs' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'tm_grant_cert' );

        $user_id    = absint( $_POST['user_id'] ?? 0 );
        $cert_slug  = sanitize_key( $_POST['cert_slug'] ?? '' );
        $granted_on = sanitize_text_field( $_POST['granted_on'] ?? wp_date( 'Y-m-d' ) );
        $notes      = sanitize_textarea_field( $_POST['notes'] ?? '' );

        if ( ! $user_id || ! array_key_exists( $cert_slug, self::catalog() ) ) {
            wp_die( 'Bad input' );
        }

        global $wpdb;
        $wpdb->replace(
            "{$wpdb->prefix}tm_certifications",
            array(
                'user_id'    => $user_id,
                'cert_slug'  => $cert_slug,
                'granted_on' => $granted_on,
                'granted_by' => get_current_user_id(),
                'notes'      => $notes,
            ),
            array( '%d', '%s', '%s', '%d', '%s' )
        );

        do_action( 'tm_cert_granted', $user_id, $cert_slug, get_current_user_id() );

        wp_safe_redirect( add_query_arg( 'granted', '1', wp_get_referer() ) );
        exit;
    }

    public static function handle_revoke() {
        if ( ! current_user_can( 'tm_grant_certs' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'tm_revoke_cert' );

        $user_id   = absint( $_POST['user_id'] ?? 0 );
        $cert_slug = sanitize_key( $_POST['cert_slug'] ?? '' );

        global $wpdb;
        $wpdb->delete(
            "{$wpdb->prefix}tm_certifications",
            array( 'user_id' => $user_id, 'cert_slug' => $cert_slug ),
            array( '%d', '%s' )
        );

        wp_safe_redirect( add_query_arg( 'revoked', '1', wp_get_referer() ) );
        exit;
    }
}
