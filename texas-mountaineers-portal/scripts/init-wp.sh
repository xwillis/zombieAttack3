#!/usr/bin/env bash
# init-wp.sh — Idempotent WordPress bootstrap for the TM portal.
# Runs inside the wpcli container.
#   docker compose --profile tools run --rm wpcli /scripts/init-wp.sh
set -euo pipefail

WP="wp --allow-root --path=/var/www/html"

: "${SITE_URL:?SITE_URL must be set}"
: "${SITE_TITLE:=Texas Mountaineers}"
: "${ADMIN_USER:?ADMIN_USER must be set}"
: "${ADMIN_EMAIL:?ADMIN_EMAIL must be set}"
: "${ADMIN_PASSWORD:?ADMIN_PASSWORD must be set}"

echo "==> Waiting for DB..."
until $WP db check >/dev/null 2>&1 || $WP db query "SELECT 1" >/dev/null 2>&1; do
    sleep 2
done

if ! $WP core is-installed >/dev/null 2>&1; then
    echo "==> Installing WordPress core..."
    $WP core install \
        --url="$SITE_URL" \
        --title="$SITE_TITLE" \
        --admin_user="$ADMIN_USER" \
        --admin_email="$ADMIN_EMAIL" \
        --admin_password="$ADMIN_PASSWORD" \
        --skip-email
else
    echo "==> WordPress already installed."
fi

echo "==> Setting site options..."
$WP option update permalink_structure '/%postname%/'
$WP option update timezone_string 'America/Chicago'
$WP option update date_format 'F j, Y'
$WP option update time_format 'g:i a'
$WP option update default_role 'tm_pending'
$WP option update users_can_register 1
$WP rewrite flush

echo "==> Installing third-party plugins..."
$WP plugin install paid-memberships-pro --activate
$WP plugin install redis-cache --activate
$WP plugin install wp-mail-smtp --activate
$WP plugin install wp-2fa --activate
$WP plugin install wordfence --activate

echo "==> Enabling Redis object cache..."
$WP redis enable || true

echo "==> Activating TM custom plugin + theme..."
$WP plugin activate tm-member-portal

# Astra parent + child
$WP theme install astra
$WP theme activate tm-portal || $WP theme activate astra

echo "==> Seeding PMPro membership levels..."
seed_level() {
    local name="$1" cost="$2" period="$3" cycles="$4" desc="$5"
    if ! $WP db query "SELECT id FROM \`$($WP db prefix)pmpro_membership_levels\` WHERE name='$name' LIMIT 1;" --skip-column-names | grep -q .; then
        $WP db query "INSERT INTO \`$($WP db prefix)pmpro_membership_levels\`
            (name, description, confirmation, initial_payment, billing_amount, cycle_number, cycle_period, billing_limit, trial_amount, trial_limit, allow_signups)
            VALUES ('$name', '$desc', '', $cost, $cost, $cycles, '$period', 0, 0, 0, 1);"
    fi
}
seed_level "Individual Annual"            30  "Year" 1 "Annual individual membership."
seed_level "Couple/Family Annual"         40  "Year" 1 "Annual couple/family membership."
seed_level "Beginner Class + Membership" 125  ""     0 "Beginner class fee plus 1-yr individual membership."
seed_level "Couples Beginner + Membership" 215 ""    0 "Couples beginner class plus 1-yr couple membership."
seed_level "Beginner Class Only"          95  ""     0 "Beginner class only (no membership)."
seed_level "Sport Class"                  60  ""     0 "Sport-lead class (members only)."
seed_level "Intro to Trad"                80  ""     0 "Intro-to-trad class (members only)."
seed_level "Anchor Class"                  0  ""     0 "Anchor class (free, members only)."

echo "==> Seeding pages..."
seed_page() {
    local title="$1" slug="$2" content="$3"
    if ! $WP post list --post_type=page --name="$slug" --field=ID | grep -q .; then
        $WP post create --post_type=page --post_status=publish --post_title="$title" --post_name="$slug" --post_content="$content"
    fi
}
seed_page "Home"                  "home"             "<h1>Welcome to the Texas Mountaineers</h1><p>The North Texas climbing community since 1988.</p>"
seed_page "About"                 "about"            "<p>About the Texas Mountaineers.</p>"
seed_page "Classes"               "classes"          "[tm_class_signup]"
seed_page "Membership"            "membership"       "[pmpro_levels]"
seed_page "Member Dashboard"      "member-dashboard" "[tm_member_dashboard]"
seed_page "Member Directory"      "member-directory" "[tm_member_directory]"
seed_page "Liability Waiver"      "waiver"           "[tm_waiver_form]"
seed_page "Calendar"              "calendar"         "<p>Club calendar.</p>"
seed_page "Class Roster"          "class-roster"     "[tm_class_roster]"

# Front page
home_id=$($WP post list --post_type=page --name=home --field=ID | head -1)
if [ -n "$home_id" ]; then
    $WP option update show_on_front page
    $WP option update page_on_front "$home_id"
fi

echo "==> Configuring SMTP if provided..."
if [ -n "${SMTP_HOST:-}" ]; then
    $WP option patch update wp_mail_smtp mail from_email "${MAIL_FROM:-noreply@texasmountaineers.org}" || true
    $WP option patch update wp_mail_smtp mail from_name  "${MAIL_FROM_NAME:-Texas Mountaineers}" || true
    $WP option patch update wp_mail_smtp mail mailer     "smtp" || true
    $WP option patch update wp_mail_smtp smtp host       "$SMTP_HOST" || true
    $WP option patch update wp_mail_smtp smtp port       "${SMTP_PORT:-587}" || true
    $WP option patch update wp_mail_smtp smtp encryption "${SMTP_SECURE:-tls}" || true
    $WP option patch update wp_mail_smtp smtp auth       1 || true
    $WP option patch update wp_mail_smtp smtp user       "${SMTP_USER:-}" || true
    $WP option patch update wp_mail_smtp smtp pass       "${SMTP_PASSWORD:-}" || true
fi

echo "==> Done. Admin: ${SITE_URL}/wp-admin (user: ${ADMIN_USER})"
