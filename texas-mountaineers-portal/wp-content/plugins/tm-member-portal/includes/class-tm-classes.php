<?php
/**
 * Class offerings (CPT `tm_class`) + registrations with waitlist and auto-promotion.
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_Classes {

    const CPT     = 'tm_class';
    const NONCE   = 'tm_class_register';
    const ACT_REG = 'tm_class_register';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_cpt' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ) );
        add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save_metabox' ), 10, 2 );

        add_action( 'admin_post_' . self::ACT_REG, array( __CLASS__, 'handle_registration' ) );
        add_action( 'admin_post_nopriv_' . self::ACT_REG, array( __CLASS__, 'handle_registration' ) );

        add_action( 'admin_post_tm_class_cancel', array( __CLASS__, 'handle_cancel' ) );
    }

    public static function register_cpt() {
        register_post_type( self::CPT, array(
            'label'         => 'Classes',
            'public'        => true,
            'show_ui'       => true,
            'show_in_menu'  => true,
            'menu_icon'     => 'dashicons-welcome-learn-more',
            'menu_position' => 25,
            'supports'      => array( 'title', 'editor', 'thumbnail' ),
            'has_archive'   => true,
            'rewrite'       => array( 'slug' => 'class' ),
            'show_in_rest'  => true,
            'capability_type' => 'post',
            'map_meta_cap'  => true,
        ) );
    }

    public static function register_metabox() {
        add_meta_box(
            'tm_class_meta',
            'Class Details',
            array( __CLASS__, 'render_metabox' ),
            self::CPT
        );
    }

    public static function render_metabox( $post ) {
        wp_nonce_field( 'tm_class_meta', 'tm_class_meta_nonce' );
        $get = function ( $key ) use ( $post ) { return get_post_meta( $post->ID, $key, true ); };
        ?>
        <p><label>Date:
            <input type="datetime-local" name="tm_class_date" value="<?php echo esc_attr( $get( 'tm_class_date' ) ); ?>" />
        </label></p>
        <p><label>Location:
            <input type="text" name="tm_class_location" value="<?php echo esc_attr( $get( 'tm_class_location' ) ); ?>" class="regular-text" />
        </label></p>
        <p><label>Capacity (confirmed seats):
            <input type="number" min="0" name="tm_class_capacity" value="<?php echo esc_attr( $get( 'tm_class_capacity' ) ?: 0 ); ?>" />
        </label></p>
        <p><label>PMPro Level (for paid registration):
            <input type="number" min="0" name="tm_class_level_id" value="<?php echo esc_attr( $get( 'tm_class_level_id' ) ?: 0 ); ?>" />
        </label></p>
        <p><label>Required certs (comma-separated slugs, e.g. "beginner,anchor"):
            <input type="text" name="tm_class_prereq_certs" value="<?php echo esc_attr( $get( 'tm_class_prereq_certs' ) ); ?>" class="regular-text" />
        </label></p>
        <?php
    }

    public static function save_metabox( $post_id, $post ) {
        if ( ! isset( $_POST['tm_class_meta_nonce'] ) || ! wp_verify_nonce( $_POST['tm_class_meta_nonce'], 'tm_class_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $fields = array(
            'tm_class_date'         => 'sanitize_text_field',
            'tm_class_location'     => 'sanitize_text_field',
            'tm_class_capacity'     => 'absint',
            'tm_class_level_id'     => 'absint',
            'tm_class_prereq_certs' => 'sanitize_text_field',
        );
        foreach ( $fields as $key => $sanitizer ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_post_meta( $post_id, $key, call_user_func( $sanitizer, wp_unslash( $_POST[ $key ] ) ) );
            }
        }
    }

    /* ---- Registration logic ---- */

    public static function counts( $class_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(status='confirmed') AS confirmed,
                SUM(status='waitlist')  AS waitlist
             FROM {$wpdb->prefix}tm_class_registrations
             WHERE class_id = %d",
            $class_id
        ) );
        return array(
            'confirmed' => (int) ( $row->confirmed ?? 0 ),
            'waitlist'  => (int) ( $row->waitlist ?? 0 ),
        );
    }

    public static function user_status( $class_id, $user_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT status, position
             FROM {$wpdb->prefix}tm_class_registrations
             WHERE class_id = %d AND user_id = %d",
            $class_id,
            $user_id
        ) );
    }

    public static function roster( $class_id, $audience = 'both' ) {
        global $wpdb;
        $where = "class_id = %d AND status != 'cancelled'";
        $args  = array( $class_id );
        if ( 'confirmed' === $audience ) {
            $where = "class_id = %d AND status = 'confirmed'";
        } elseif ( 'waitlist' === $audience ) {
            $where = "class_id = %d AND status = 'waitlist'";
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.user_id, r.status, r.position, u.display_name, u.user_email
             FROM {$wpdb->prefix}tm_class_registrations r
             JOIN {$wpdb->users} u ON u.ID = r.user_id
             WHERE $where
             ORDER BY (r.status='confirmed') DESC, r.position ASC, r.created_at ASC",
            ...$args
        ) );
    }

    public static function handle_registration() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( wp_get_referer() ?: home_url() ) );
            exit;
        }
        check_admin_referer( self::NONCE );

        $class_id = absint( $_POST['class_id'] ?? 0 );
        $user_id  = get_current_user_id();
        if ( ! $class_id || get_post_type( $class_id ) !== self::CPT ) {
            wp_die( 'Bad class' );
        }

        // Prereq cert check.
        $prereq_raw = (string) get_post_meta( $class_id, 'tm_class_prereq_certs', true );
        if ( $prereq_raw ) {
            foreach ( array_filter( array_map( 'trim', explode( ',', $prereq_raw ) ) ) as $needed ) {
                if ( ! TM_Certifications::user_has( $user_id, $needed ) ) {
                    wp_safe_redirect( add_query_arg( 'tm_class', 'prereq', get_permalink( $class_id ) ) );
                    exit;
                }
            }
        }

        global $wpdb;
        $existing = self::user_status( $class_id, $user_id );
        if ( $existing && 'cancelled' !== $existing->status ) {
            wp_safe_redirect( add_query_arg( 'tm_class', 'already', get_permalink( $class_id ) ) );
            exit;
        }

        $capacity = (int) get_post_meta( $class_id, 'tm_class_capacity', true );
        $counts   = self::counts( $class_id );

        if ( $capacity > 0 && $counts['confirmed'] >= $capacity ) {
            // Append to waitlist.
            $position = $counts['waitlist'] + 1;
            $wpdb->replace(
                "{$wpdb->prefix}tm_class_registrations",
                array(
                    'class_id' => $class_id,
                    'user_id'  => $user_id,
                    'status'   => 'waitlist',
                    'position' => $position,
                ),
                array( '%d', '%d', '%s', '%d' )
            );
            do_action( 'tm_class_waitlisted', $class_id, $user_id, $position );
            wp_safe_redirect( add_query_arg( 'tm_class', 'waitlisted', get_permalink( $class_id ) ) );
            exit;
        }

        $wpdb->replace(
            "{$wpdb->prefix}tm_class_registrations",
            array(
                'class_id' => $class_id,
                'user_id'  => $user_id,
                'status'   => 'confirmed',
                'position' => 0,
            ),
            array( '%d', '%d', '%s', '%d' )
        );
        do_action( 'tm_class_confirmed', $class_id, $user_id );
        wp_safe_redirect( add_query_arg( 'tm_class', 'confirmed', get_permalink( $class_id ) ) );
        exit;
    }

    public static function handle_cancel() {
        if ( ! is_user_logged_in() ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'tm_class_cancel' );

        $class_id   = absint( $_POST['class_id'] ?? 0 );
        $cancel_uid = absint( $_POST['user_id'] ?? get_current_user_id() );

        if ( $cancel_uid !== get_current_user_id() && ! current_user_can( 'tm_manage_classes' ) ) {
            wp_die( 'Forbidden' );
        }

        global $wpdb;
        $row = self::user_status( $class_id, $cancel_uid );
        if ( ! $row ) {
            wp_safe_redirect( wp_get_referer() );
            exit;
        }

        $was_confirmed = ( 'confirmed' === $row->status );
        $wpdb->update(
            "{$wpdb->prefix}tm_class_registrations",
            array( 'status' => 'cancelled' ),
            array( 'class_id' => $class_id, 'user_id' => $cancel_uid )
        );
        do_action( 'tm_class_cancelled', $class_id, $cancel_uid );

        if ( $was_confirmed ) {
            self::promote_next_from_waitlist( $class_id );
        }

        wp_safe_redirect( wp_get_referer() );
        exit;
    }

    /**
     * Move the head-of-waitlist registrant into a confirmed seat and email them.
     * Re-numbers remaining waitlist positions.
     */
    public static function promote_next_from_waitlist( $class_id ) {
        global $wpdb;
        $head = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, user_id FROM {$wpdb->prefix}tm_class_registrations
             WHERE class_id = %d AND status = 'waitlist'
             ORDER BY position ASC, created_at ASC LIMIT 1",
            $class_id
        ) );
        if ( ! $head ) {
            return;
        }
        $wpdb->update(
            "{$wpdb->prefix}tm_class_registrations",
            array( 'status' => 'confirmed', 'position' => 0 ),
            array( 'id' => $head->id )
        );
        // Renumber the remaining waitlist.
        $remaining = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tm_class_registrations
             WHERE class_id = %d AND status = 'waitlist'
             ORDER BY position ASC, created_at ASC",
            $class_id
        ) );
        $i = 1;
        foreach ( $remaining as $r ) {
            $wpdb->update(
                "{$wpdb->prefix}tm_class_registrations",
                array( 'position' => $i++ ),
                array( 'id' => $r->id )
            );
        }

        // Notify the promoted student.
        $user = get_userdata( $head->user_id );
        if ( $user ) {
            $class_title = get_the_title( $class_id );
            $url         = get_permalink( $class_id );
            $subject     = sprintf( '[Texas Mountaineers] You are now confirmed for: %s', $class_title );
            $body        = sprintf(
                "Hi %s,\n\nA spot opened up in \"%s\" and you have been moved from the waitlist into a confirmed seat.\n\nClass page: %s\n\nIf you can no longer attend, please cancel from your member dashboard so the next person on the waitlist can be promoted.\n\n— Texas Mountaineers",
                $user->display_name,
                $class_title,
                $url
            );
            wp_mail( $user->user_email, $subject, $body );
        }

        do_action( 'tm_class_waitlist_promoted', $class_id, $head->user_id );
    }
}
