<?php
/**
 * Lightweight gear-loan log managed by Equipment Coordinator.
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_Gear {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_tm_gear_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_tm_gear_return', array( __CLASS__, 'handle_return' ) );
    }

    public static function open_loans_for_user( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, item, checked_out, due_date
             FROM {$wpdb->prefix}tm_gear_loans
             WHERE user_id = %d AND returned_on IS NULL
             ORDER BY checked_out DESC",
            $user_id
        ) );
    }

    public static function menu() {
        add_menu_page(
            'Gear Loans',
            'Gear Loans',
            'tm_manage_gear',
            'tm-gear',
            array( __CLASS__, 'render' ),
            'dashicons-archive',
            30
        );
    }

    public static function render() {
        if ( ! current_user_can( 'tm_manage_gear' ) ) {
            wp_die( 'Forbidden' );
        }
        global $wpdb;
        $loans = $wpdb->get_results(
            "SELECT l.*, u.display_name, u.user_email
             FROM {$wpdb->prefix}tm_gear_loans l
             JOIN {$wpdb->users} u ON u.ID = l.user_id
             ORDER BY (l.returned_on IS NULL) DESC, l.checked_out DESC
             LIMIT 200"
        );
        $users = get_users( array( 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
        ?>
        <div class="wrap">
            <h1>Gear Loans</h1>

            <h2>Check Out</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tm_gear_save' ); ?>
                <input type="hidden" name="action" value="tm_gear_save" />
                <table class="form-table">
                    <tr>
                        <th>Member</th>
                        <td>
                            <select name="user_id" required>
                                <?php foreach ( $users as $u ) : ?>
                                    <option value="<?php echo esc_attr( $u->ID ); ?>">
                                        <?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr><th>Item</th><td><input type="text" name="item" required class="regular-text" placeholder="60m rope #2" /></td></tr>
                    <tr><th>Checked Out</th><td><input type="date" name="checked_out" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required /></td></tr>
                    <tr><th>Due Back</th><td><input type="date" name="due_date" /></td></tr>
                    <tr><th>Notes</th><td><textarea name="notes" rows="2" class="large-text"></textarea></td></tr>
                </table>
                <?php submit_button( 'Record Loan' ); ?>
            </form>

            <h2>Recent Loans</h2>
            <table class="widefat striped">
                <thead><tr>
                    <th>Member</th><th>Item</th><th>Out</th><th>Due</th><th>Returned</th><th>Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $loans as $l ) : ?>
                    <tr>
                        <td><?php echo esc_html( $l->display_name ); ?></td>
                        <td><?php echo esc_html( $l->item ); ?></td>
                        <td><?php echo esc_html( $l->checked_out ); ?></td>
                        <td><?php echo esc_html( $l->due_date ?: '—' ); ?></td>
                        <td><?php echo esc_html( $l->returned_on ?: '—' ); ?></td>
                        <td>
                            <?php if ( ! $l->returned_on ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <?php wp_nonce_field( 'tm_gear_return' ); ?>
                                    <input type="hidden" name="action" value="tm_gear_return" />
                                    <input type="hidden" name="loan_id" value="<?php echo esc_attr( $l->id ); ?>" />
                                    <button class="button">Mark returned</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handle_save() {
        if ( ! current_user_can( 'tm_manage_gear' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'tm_gear_save' );
        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}tm_gear_loans",
            array(
                'user_id'     => absint( $_POST['user_id'] ),
                'item'        => sanitize_text_field( $_POST['item'] ),
                'checked_out' => sanitize_text_field( $_POST['checked_out'] ),
                'due_date'    => ! empty( $_POST['due_date'] ) ? sanitize_text_field( $_POST['due_date'] ) : null,
                'notes'       => sanitize_textarea_field( $_POST['notes'] ?? '' ),
                'staff_id'    => get_current_user_id(),
            )
        );
        wp_safe_redirect( wp_get_referer() );
        exit;
    }

    public static function handle_return() {
        if ( ! current_user_can( 'tm_manage_gear' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'tm_gear_return' );
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}tm_gear_loans",
            array( 'returned_on' => wp_date( 'Y-m-d' ) ),
            array( 'id' => absint( $_POST['loan_id'] ) )
        );
        wp_safe_redirect( wp_get_referer() );
        exit;
    }
}
