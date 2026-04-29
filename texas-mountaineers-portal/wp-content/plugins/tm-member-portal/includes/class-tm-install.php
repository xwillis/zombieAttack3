<?php
/**
 * Activation/deactivation: schema setup for TM-specific data.
 *
 * @package TM_Member_Portal
 */

defined( 'ABSPATH' ) || exit;

class TM_Install {

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix          = $wpdb->prefix;

        // Certifications: which member has which internal cert, granted by whom.
        $sql_certs = "CREATE TABLE {$prefix}tm_certifications (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            cert_slug     VARCHAR(64) NOT NULL,
            granted_on    DATE NOT NULL,
            granted_by    BIGINT UNSIGNED NOT NULL,
            notes         TEXT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_cert (user_id, cert_slug),
            KEY cert_slug (cert_slug)
        ) {$charset_collate};";
        dbDelta( $sql_certs );

        // Liability waivers: one row per signed waiver per membership year.
        $sql_waivers = "CREATE TABLE {$prefix}tm_waivers (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            full_name     VARCHAR(255) NOT NULL,
            membership_year SMALLINT UNSIGNED NOT NULL,
            ip_address    VARCHAR(64) NOT NULL,
            user_agent    VARCHAR(500) NOT NULL,
            text_hash     CHAR(64) NOT NULL,
            signed_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_year (user_id, membership_year),
            KEY signed_at (signed_at)
        ) {$charset_collate};";
        dbDelta( $sql_waivers );

        // Gear loans.
        $sql_gear = "CREATE TABLE {$prefix}tm_gear_loans (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            item          VARCHAR(255) NOT NULL,
            checked_out   DATE NOT NULL,
            due_date      DATE NULL,
            returned_on   DATE NULL,
            notes         TEXT NULL,
            staff_id      BIGINT UNSIGNED NOT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY returned_on (returned_on)
        ) {$charset_collate};";
        dbDelta( $sql_gear );

        // Class registrations (tied to tm_class custom post type).
        $sql_class_regs = "CREATE TABLE {$prefix}tm_class_registrations (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id      BIGINT UNSIGNED NOT NULL,
            user_id       BIGINT UNSIGNED NOT NULL,
            status        ENUM('confirmed','waitlist','cancelled') NOT NULL DEFAULT 'confirmed',
            position      INT UNSIGNED NOT NULL DEFAULT 0,
            notes         TEXT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY class_user (class_id, user_id),
            KEY class_status (class_id, status, position)
        ) {$charset_collate};";
        dbDelta( $sql_class_regs );

        // Audit log of mass emails sent to a class roster.
        $sql_class_emails = "CREATE TABLE {$prefix}tm_class_emails (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id        BIGINT UNSIGNED NOT NULL,
            sender_id       BIGINT UNSIGNED NOT NULL,
            audience        ENUM('confirmed','waitlist','both') NOT NULL,
            recipient_count INT UNSIGNED NOT NULL,
            subject         VARCHAR(500) NOT NULL,
            body_hash       CHAR(64) NOT NULL,
            sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY class_id (class_id),
            KEY sent_at (sent_at)
        ) {$charset_collate};";
        dbDelta( $sql_class_emails );

        // Persist installed schema version for future migrations.
        update_option( 'tm_portal_db_version', TM_PORTAL_VERSION );

        // Ensure roles exist.
        TM_Roles::register_roles();

        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
