<?php
/**
 * Front-end shortcodes for the member portal.
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_Shortcodes {

    public static function init() {
        add_shortcode( 'tm_member_dashboard', array( __CLASS__, 'dashboard' ) );
        add_shortcode( 'tm_member_directory', array( __CLASS__, 'directory' ) );
        add_shortcode( 'tm_class_signup',     array( __CLASS__, 'class_signup' ) );
        add_shortcode( 'tm_class_roster',     array( __CLASS__, 'class_roster' ) );
        add_shortcode( 'tm_waiver_form',      array( __CLASS__, 'waiver_form' ) );
    }

    /**
     * Member self-service dashboard. Shows status, expiry, certs, classes,
     * waitlist positions, gear loans, and a renew button.
     */
    public static function dashboard() {
        if ( ! is_user_logged_in() ) {
            return self::login_link( 'View your member dashboard' );
        }
        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        // Membership level + expiry.
        $level    = function_exists( 'pmpro_getMembershipLevelForUser' ) ? pmpro_getMembershipLevelForUser( $user_id ) : null;
        $expires  = ( $level && ! empty( $level->enddate ) ) ? wp_date( 'F j, Y', (int) $level->enddate ) : 'No active membership';
        $signed   = TM_Waiver::has_signed( $user_id );
        $certs    = TM_Certifications::for_user( $user_id );
        $catalog  = TM_Certifications::catalog();
        $loans    = TM_Gear::open_loans_for_user( $user_id );
        $classes  = self::user_class_history( $user_id );

        ob_start();
        ?>
        <section class="tm-dashboard">
            <h2>Welcome, <?php echo esc_html( $user->display_name ); ?>.</h2>

            <div class="tm-card">
                <h3>Membership status</h3>
                <p><strong>Level:</strong> <?php echo esc_html( $level && ! empty( $level->name ) ? $level->name : '— none —' ); ?></p>
                <p><strong>Renewal date:</strong> <?php echo esc_html( $expires ); ?></p>
                <p><strong>Waiver on file (<?php echo (int) TM_Waiver::current_year(); ?>):</strong>
                    <?php if ( $signed ) : ?>
                        <span class="tm-ok">Yes</span>
                    <?php else : ?>
                        <span class="tm-warn">No</span> — <a href="<?php echo esc_url( home_url( '/waiver/' ) ); ?>">Sign now</a>
                    <?php endif; ?>
                </p>
                <p>
                    <a class="button" href="<?php echo esc_url( home_url( '/membership/' ) ); ?>">Renew / change level</a>
                    <a class="button" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>">Edit profile</a>
                </p>
            </div>

            <div class="tm-card">
                <h3>Internal certifications</h3>
                <?php if ( empty( $certs ) ) : ?>
                    <p>No certifications recorded yet. Take a class to earn your first one.</p>
                <?php else : ?>
                    <ul class="tm-certs">
                        <?php foreach ( $certs as $c ) : ?>
                            <li>
                                <strong><?php echo esc_html( $catalog[ $c->cert_slug ] ?? $c->cert_slug ); ?></strong>
                                — granted <?php echo esc_html( $c->granted_on ); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="tm-card">
                <h3>Classes</h3>
                <?php if ( empty( $classes ) ) : ?>
                    <p>You aren't registered for any classes. <a href="<?php echo esc_url( home_url( '/classes/' ) ); ?>">Browse upcoming classes</a>.</p>
                <?php else : ?>
                    <table class="tm-table">
                        <thead><tr><th>Class</th><th>Date</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ( $classes as $c ) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url( get_permalink( $c->class_id ) ); ?>"><?php echo esc_html( get_the_title( $c->class_id ) ); ?></a></td>
                                <td><?php echo esc_html( get_post_meta( $c->class_id, 'tm_class_date', true ) ); ?></td>
                                <td>
                                    <?php if ( 'confirmed' === $c->status ) : ?>
                                        <span class="tm-ok">Confirmed</span>
                                    <?php elseif ( 'waitlist' === $c->status ) : ?>
                                        <span class="tm-warn">Waitlist #<?php echo (int) $c->position; ?></span>
                                    <?php else : ?>
                                        <span>Cancelled</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( 'cancelled' !== $c->status ) : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                            <?php wp_nonce_field( 'tm_class_cancel' ); ?>
                                            <input type="hidden" name="action" value="tm_class_cancel" />
                                            <input type="hidden" name="class_id" value="<?php echo esc_attr( $c->class_id ); ?>" />
                                            <button class="button">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if ( $loans ) : ?>
                <div class="tm-card">
                    <h3>Gear on loan to you</h3>
                    <ul>
                        <?php foreach ( $loans as $l ) : ?>
                            <li>
                                <strong><?php echo esc_html( $l->item ); ?></strong>
                                — out <?php echo esc_html( $l->checked_out ); ?>
                                <?php if ( $l->due_date ) : ?>
                                    , due <?php echo esc_html( $l->due_date ); ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function directory() {
        if ( ! is_user_logged_in() ) {
            return self::login_link( 'View the member directory' );
        }
        if ( ! current_user_can( 'tm_view_directory' ) ) {
            return '<p>Members only.</p>';
        }
        if ( ! TM_Waiver::has_signed( get_current_user_id() ) ) {
            return '<p>Please sign the current-year waiver first.</p>';
        }
        $members = get_users( array(
            'role__in' => array( 'tm_member', 'tm_training_coordinator', 'tm_equipment_coordinator', 'tm_treasurer', 'tm_eboard' ),
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ) );
        ob_start();
        echo '<table class="tm-table"><thead><tr><th>Name</th><th>Email</th><th>Discord</th></tr></thead><tbody>';
        foreach ( $members as $m ) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( $m->display_name ),
                esc_html( $m->user_email ),
                esc_html( get_user_meta( $m->ID, 'tm_discord_handle', true ) )
            );
        }
        echo '</tbody></table>';
        return ob_get_clean();
    }

    /**
     * Class signup card: shown on a class CPT page or via shortcode with class_id.
     */
    public static function class_signup( $atts ) {
        $atts = shortcode_atts( array( 'class_id' => 0 ), $atts );
        $class_id = (int) $atts['class_id'];
        if ( ! $class_id ) {
            global $post;
            if ( $post && get_post_type( $post ) === TM_Classes::CPT ) {
                $class_id = $post->ID;
            }
        }
        if ( ! $class_id ) {
            // Fallback: list upcoming classes.
            $upcoming = get_posts( array(
                'post_type'      => TM_Classes::CPT,
                'posts_per_page' => 20,
                'meta_key'       => 'tm_class_date',
                'orderby'        => 'meta_value',
                'order'          => 'ASC',
            ) );
            if ( empty( $upcoming ) ) {
                return '<p>No classes scheduled.</p>';
            }
            $out = '<ul class="tm-class-list">';
            foreach ( $upcoming as $c ) {
                $counts = TM_Classes::counts( $c->ID );
                $cap    = (int) get_post_meta( $c->ID, 'tm_class_capacity', true );
                $date   = get_post_meta( $c->ID, 'tm_class_date', true );
                $out   .= sprintf(
                    '<li><a href="%s">%s</a> — %s (%d / %s seats)</li>',
                    esc_url( get_permalink( $c->ID ) ),
                    esc_html( $c->post_title ),
                    esc_html( $date ),
                    $counts['confirmed'],
                    $cap > 0 ? (int) $cap : '∞'
                );
            }
            return $out . '</ul>';
        }

        $counts   = TM_Classes::counts( $class_id );
        $capacity = (int) get_post_meta( $class_id, 'tm_class_capacity', true );
        $full     = ( $capacity > 0 && $counts['confirmed'] >= $capacity );
        $user_id  = get_current_user_id();
        $status   = $user_id ? TM_Classes::user_status( $class_id, $user_id ) : null;

        ob_start();
        ?>
        <div class="tm-class-signup tm-card">
            <p>
                <strong>Confirmed:</strong> <?php echo (int) $counts['confirmed']; ?>
                <?php if ( $capacity ) : ?> / <?php echo (int) $capacity; ?><?php endif; ?>
                — <strong>Waitlist:</strong> <?php echo (int) $counts['waitlist']; ?>
            </p>
            <?php if ( ! is_user_logged_in() ) : ?>
                <?php echo self::login_link( 'Sign in to register' ); ?>
            <?php elseif ( $status && 'cancelled' !== $status->status ) : ?>
                <p>You are <strong><?php echo esc_html( $status->status ); ?></strong>
                    <?php if ( 'waitlist' === $status->status ) : ?>(position #<?php echo (int) $status->position; ?>)<?php endif; ?>.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'tm_class_cancel' ); ?>
                    <input type="hidden" name="action" value="tm_class_cancel" />
                    <input type="hidden" name="class_id" value="<?php echo esc_attr( $class_id ); ?>" />
                    <button class="button">Cancel my registration</button>
                </form>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( TM_Classes::NONCE ); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr( TM_Classes::ACT_REG ); ?>" />
                    <input type="hidden" name="class_id" value="<?php echo esc_attr( $class_id ); ?>" />
                    <button class="button button-primary">
                        <?php echo $full ? 'Join waitlist' : 'Register for this class'; ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function class_roster( $atts ) {
        if ( ! current_user_can( 'tm_view_roster' ) ) {
            return '<p>Forbidden.</p>';
        }
        $atts = shortcode_atts( array( 'class_id' => 0 ), $atts );
        $class_id = (int) $atts['class_id'];
        if ( ! $class_id ) {
            global $post;
            if ( $post && get_post_type( $post ) === TM_Classes::CPT ) {
                $class_id = $post->ID;
            }
        }
        if ( ! $class_id ) {
            return '<p>Specify class_id.</p>';
        }

        $confirmed = TM_Classes::roster( $class_id, 'confirmed' );
        $waitlist  = TM_Classes::roster( $class_id, 'waitlist' );

        ob_start();
        ?>
        <div class="tm-roster">
            <h3>Confirmed (<?php echo count( $confirmed ); ?>)</h3>
            <ol>
                <?php foreach ( $confirmed as $r ) : ?>
                    <li><?php echo esc_html( $r->display_name . ' — ' . $r->user_email ); ?></li>
                <?php endforeach; ?>
            </ol>
            <h3>Waitlist (<?php echo count( $waitlist ); ?>)</h3>
            <ol>
                <?php foreach ( $waitlist as $r ) : ?>
                    <li>#<?php echo (int) $r->position; ?> — <?php echo esc_html( $r->display_name . ' (' . $r->user_email . ')' ); ?></li>
                <?php endforeach; ?>
            </ol>
            <?php if ( current_user_can( 'tm_email_class' ) ) : ?>
                <p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . TM_Classes::CPT . '&page=' . TM_Mass_Email::PAGE ) ); ?>">Email this class →</a></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function waiver_form() {
        if ( ! is_user_logged_in() ) {
            return self::login_link( 'Sign in to sign the waiver' );
        }
        $user_id = get_current_user_id();
        if ( TM_Waiver::has_signed( $user_id ) ) {
            return '<p>Your <strong>' . (int) TM_Waiver::current_year() . '</strong> waiver is on file.</p>';
        }
        ob_start();
        ?>
        <form method="post" class="tm-waiver">
            <?php wp_nonce_field( 'tm_waiver_sign', 'tm_waiver_nonce' ); ?>
            <pre class="tm-waiver-text"><?php echo esc_html( TM_Waiver::TEXT ); ?></pre>
            <p>
                <label>Type your full legal name:
                    <input type="text" name="tm_waiver_full_name" required class="regular-text" />
                </label>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="tm_waiver_confirm" value="1" required>
                    I have read, understand, and agree to this Release.
                </label>
            </p>
            <p>
                <button type="submit" name="tm_waiver_submit" value="1" class="button button-primary">Sign waiver</button>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }

    /* ---- helpers ---- */

    private static function login_link( $label ) {
        return sprintf(
            '<p><a href="%s">%s →</a></p>',
            esc_url( wp_login_url( get_permalink() ) ),
            esc_html( $label )
        );
    }

    private static function user_class_history( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT class_id, status, position
             FROM {$wpdb->prefix}tm_class_registrations
             WHERE user_id = %d
             ORDER BY created_at DESC",
            $user_id
        ) );
    }
}
