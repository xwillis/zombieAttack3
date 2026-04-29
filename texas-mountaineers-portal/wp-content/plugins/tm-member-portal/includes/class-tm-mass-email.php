<?php
/**
 * Mass-email a class roster (confirmed / waitlist / both).
 * - Capability `tm_email_class` required.
 * - Uses BCC to keep recipient privacy.
 * - Throttles per-recipient sends to avoid SMTP rate-limits.
 * - Audits every send into tm_class_emails.
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_Mass_Email {

    const ACT  = 'tm_email_class';
    const PAGE = 'tm-email-class';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_' . self::ACT, array( __CLASS__, 'handle_send' ) );
    }

    public static function menu() {
        add_submenu_page(
            'edit.php?post_type=' . TM_Classes::CPT,
            'Email Class',
            'Email Class',
            'tm_email_class',
            self::PAGE,
            array( __CLASS__, 'render' )
        );
    }

    public static function render() {
        if ( ! current_user_can( 'tm_email_class' ) ) {
            wp_die( 'Forbidden' );
        }
        $classes = get_posts( array(
            'post_type'      => TM_Classes::CPT,
            'posts_per_page' => 100,
            'post_status'    => 'publish',
            'orderby'        => 'meta_value',
            'meta_key'       => 'tm_class_date',
            'order'          => 'DESC',
        ) );

        $sent_count = isset( $_GET['sent'] ) ? absint( $_GET['sent'] ) : 0;
        ?>
        <div class="wrap">
            <h1>Email a Class Roster</h1>
            <?php if ( $sent_count ) : ?>
                <div class="notice notice-success"><p><?php printf( 'Email queued to %d recipient(s).', $sent_count ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::ACT ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACT ); ?>" />
                <table class="form-table">
                    <tr>
                        <th><label for="class_id">Class</label></th>
                        <td>
                            <select name="class_id" id="class_id" required>
                                <option value="">— Choose a class —</option>
                                <?php foreach ( $classes as $c ) :
                                    $counts = TM_Classes::counts( $c->ID );
                                    $date   = get_post_meta( $c->ID, 'tm_class_date', true );
                                    ?>
                                    <option value="<?php echo esc_attr( $c->ID ); ?>">
                                        <?php echo esc_html( sprintf(
                                            '%s — %s (confirmed %d / waitlist %d)',
                                            $c->post_title,
                                            $date,
                                            $counts['confirmed'],
                                            $counts['waitlist']
                                        ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Audience</th>
                        <td>
                            <label><input type="radio" name="audience" value="confirmed" checked> Confirmed only</label><br>
                            <label><input type="radio" name="audience" value="waitlist"> Waitlist only</label><br>
                            <label><input type="radio" name="audience" value="both"> Both</label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="subject">Subject</label></th>
                        <td><input type="text" id="subject" name="subject" class="large-text" required /></td>
                    </tr>
                    <tr>
                        <th><label for="body">Message</label></th>
                        <td>
                            <textarea id="body" name="body" rows="12" class="large-text" required></textarea>
                            <p class="description">
                                Merge tags: <code>{first_name}</code>, <code>{display_name}</code>,
                                <code>{class_title}</code>, <code>{class_date}</code>, <code>{status}</code>,
                                <code>{position}</code>.
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Send Email' ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_send() {
        if ( ! current_user_can( 'tm_email_class' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( self::ACT );

        $class_id = absint( $_POST['class_id'] ?? 0 );
        $audience = in_array( $_POST['audience'] ?? '', array( 'confirmed', 'waitlist', 'both' ), true )
            ? $_POST['audience']
            : 'confirmed';
        $subject  = sanitize_text_field( $_POST['subject'] ?? '' );
        $body     = wp_kses_post( $_POST['body'] ?? '' );

        if ( ! $class_id || ! $subject || ! $body ) {
            wp_die( 'Missing fields' );
        }

        $class       = get_post( $class_id );
        $class_title = $class ? $class->post_title : '';
        $class_date  = get_post_meta( $class_id, 'tm_class_date', true );
        $roster      = TM_Classes::roster( $class_id, $audience );

        $sent = 0;
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf(
                'From: %s <%s>',
                defined( 'TM_FROM_NAME' ) ? TM_FROM_NAME : 'Texas Mountaineers',
                defined( 'TM_FROM_EMAIL' ) ? TM_FROM_EMAIL : get_option( 'admin_email' )
            ),
        );

        foreach ( $roster as $r ) {
            $user = get_userdata( $r->user_id );
            if ( ! $user ) {
                continue;
            }
            $first = trim( explode( ' ', $user->display_name )[0] ?? '' );
            $merged = strtr( $body, array(
                '{first_name}'   => esc_html( $first ),
                '{display_name}' => esc_html( $user->display_name ),
                '{class_title}'  => esc_html( $class_title ),
                '{class_date}'   => esc_html( $class_date ),
                '{status}'       => esc_html( $r->status ),
                '{position}'     => esc_html( $r->position ),
            ) );
            $merged_subject = strtr( $subject, array(
                '{class_title}' => $class_title,
                '{first_name}'  => $first,
            ) );

            // wpautop preserves our line breaks for HTML content.
            $html = wpautop( $merged );

            $ok = wp_mail( $user->user_email, $merged_subject, $html, $headers );
            if ( $ok ) {
                $sent++;
            }
            // 250 ms inter-message throttle (cheap on a 30-person class, prevents SMTP bursts).
            usleep( 250000 );
        }

        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}tm_class_emails",
            array(
                'class_id'        => $class_id,
                'sender_id'       => get_current_user_id(),
                'audience'        => $audience,
                'recipient_count' => $sent,
                'subject'         => $subject,
                'body_hash'       => hash( 'sha256', $body ),
            )
        );

        wp_safe_redirect( add_query_arg( 'sent', $sent, admin_url( 'edit.php?post_type=' . TM_Classes::CPT . '&page=' . self::PAGE ) ) );
        exit;
    }
}
