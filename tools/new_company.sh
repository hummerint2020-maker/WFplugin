#!/usr/bin/env bash
# Workforce One: set up a new company on a CloudPanel VPS with one command.
#
# Creates  https://<slug>.<base domain>  as its own WordPress site (own Linux user, own database,
# own PHP-FPM pool), installs WordPress and the Workforce One plugin, makes the app page, sets
# Cairo time, switches WP-Cron to a real cron job, installs a Let's Encrypt certificate, and
# saves the logins to /root/wfo-companies/<slug>.txt (readable by root only).
#
# Run as root on the VPS, after CloudPanel is installed and the DNS record *.<base domain>
# points to the VPS:
#
#   sudo bash tools/new_company.sh --slug acme --name "Acme Trading" \
#        --base workforceone.example --email it@acme.com --plugin /root/workforce-one.zip
#
# --plugin is a zip of the workforce-one folder (cd repo && zip -r /root/workforce-one.zip workforce-one).
# Optional: --php 8.3 (default), --timezone Africa/Cairo (default), --admin-user wfoadmin (default),
# --wp-version 7.1.3 (default: latest).
set -euo pipefail

SLUG='' NAME='' BASE='' EMAIL='' PLUGIN='' PHP=8.3 TZ_NAME=Africa/Cairo ADMIN_USER=wfoadmin WP_VERSION=''
while [[ $# -gt 0 ]]; do
  case "$1" in
    --slug) SLUG=$2; shift 2;;
    --name) NAME=$2; shift 2;;
    --base) BASE=$2; shift 2;;
    --email) EMAIL=$2; shift 2;;
    --plugin) PLUGIN=$2; shift 2;;
    --php) PHP=$2; shift 2;;
    --timezone) TZ_NAME=$2; shift 2;;
    --admin-user) ADMIN_USER=$2; shift 2;;
    --wp-version) WP_VERSION=$2; shift 2;;
    -h|--help) sed -n '2,17p' "$0"; exit 0;;
    *) echo "Unknown option: $1" >&2; exit 1;;
  esac
done

die() { echo "ERROR: $*" >&2; exit 1; }
step() { echo; echo "==> $*"; }

[[ $EUID -eq 0 ]] || die "run as root (sudo)."
[[ -n $SLUG && -n $NAME && -n $BASE && -n $EMAIL && -n $PLUGIN ]] || die "missing options; see --help."
[[ $SLUG =~ ^[a-z][a-z0-9-]{1,30}$ ]] || die "slug: lowercase letters, digits and dashes, 2-31 chars, starting with a letter."
[[ -f $PLUGIN ]] || die "plugin zip not found: $PLUGIN"
command -v clpctl >/dev/null || die "clpctl not found; install CloudPanel first."
command -v openssl >/dev/null || die "openssl not found."

DOMAIN="$SLUG.$BASE"
SITE_USER="wfo-$SLUG"
DB_NAME="wfo_${SLUG//-/_}"
DB_USER="$DB_NAME"
ROOT_DIR="/home/$SITE_USER/htdocs/$DOMAIN"
CRED_DIR=/root/wfo-companies
CRED_FILE="$CRED_DIR/$SLUG.txt"
pw() { openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 20; }

[[ ! -e $CRED_FILE ]] || die "$CRED_FILE already exists: this company was set up before."
id "$SITE_USER" >/dev/null 2>&1 && die "Linux user $SITE_USER already exists."

# WP-CLI (CloudPanel usually ships it; install it if not).
if ! command -v wp >/dev/null; then
  step "Installing WP-CLI"
  curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  chmod 755 /usr/local/bin/wp
fi

# The vhost template must exist under this exact name.
clpctl vhost-templates:list 2>/dev/null | grep -qw 'WordPress' || die "CloudPanel has no 'WordPress' vhost template (run: clpctl vhost-templates:import)."

# DNS check: the name should already point to this server, or the certificate will fail later.
SERVER_IP=$(curl -fsS4 --max-time 5 https://api.ipify.org 2>/dev/null || true)
DNS_IP=$(getent ahostsv4 "$DOMAIN" | awk 'NR==1{print $1}' || true)
if [[ -n $SERVER_IP && $DNS_IP != "$SERVER_IP" ]]; then
  echo "Warning: $DOMAIN resolves to '${DNS_IP:-nothing}', this server is $SERVER_IP. The site will be created, the certificate may fail."
fi

SITE_PW=$(pw); DB_PW=$(pw); ADMIN_PW=$(pw)
mkdir -p "$CRED_DIR"; chmod 700 "$CRED_DIR"
# Save the passwords before anything else, so a failure halfway never loses them.
umask 077
cat > "$CRED_FILE" <<EOF
Company:        $NAME
App:            https://$DOMAIN/app/
wp-admin:       https://$DOMAIN/wp-admin/
Admin user:     $ADMIN_USER
Admin password: $ADMIN_PW
Admin email:    $EMAIL
Linux/SFTP:     $SITE_USER / $SITE_PW
Database:       $DB_NAME / $DB_USER / $DB_PW
Created:        $(date '+%Y-%m-%d %H:%M %Z')
EOF
umask 022

step "Creating the site $DOMAIN (PHP $PHP)"
clpctl site:add:php --domainName="$DOMAIN" --phpVersion="$PHP" --vhostTemplate='WordPress' \
  --siteUser="$SITE_USER" --siteUserPassword="$SITE_PW"
[[ -d $ROOT_DIR ]] || die "expected site folder $ROOT_DIR was not created."

step "Creating the database"
clpctl db:add --domainName="$DOMAIN" --databaseName="$DB_NAME" --databaseUserName="$DB_USER" --databaseUserPassword="$DB_PW"

wpc() { sudo -u "$SITE_USER" -H -- wp --path="$ROOT_DIR" --quiet "$@"; }

step "Installing WordPress"
wpc core download --force ${WP_VERSION:+--version="$WP_VERSION"}
wpc config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PW" --dbhost=127.0.0.1 --skip-check
wpc config set DISABLE_WP_CRON true --raw
wpc config set WP_DEBUG false --raw
wpc config set FORCE_SSL_ADMIN true --raw
wpc config set DISALLOW_FILE_EDIT true --raw
wpc core install --url="https://$DOMAIN" --title="$NAME" --admin_user="$ADMIN_USER" \
  --admin_password="$ADMIN_PW" --admin_email="$EMAIL" --skip-email
wpc option update timezone_string "$TZ_NAME"
wpc option update blog_public 0          # keep the company's app out of search engines
wpc rewrite structure '/%postname%/'
wpc plugin delete hello akismet 2>/dev/null || true
wpc post delete 1 2 --force 2>/dev/null || true   # sample post and page

step "Installing Workforce One"
TMP_ZIP=$(mktemp /tmp/workforce-one-XXXX.zip); cp "$PLUGIN" "$TMP_ZIP"; chmod 644 "$TMP_ZIP"
wpc plugin install "$TMP_ZIP" --activate
rm -f "$TMP_ZIP"
wpc post create --post_type=page --post_status=publish --post_title=App --post_name=app --post_content='[employee_app]'

step "Real cron instead of WP-Cron (every minute)"
CRON_LINE="* * * * * $(command -v wp) --path=$ROOT_DIR cron event run --due-now --quiet >/dev/null 2>&1"
( crontab -u "$SITE_USER" -l 2>/dev/null | grep -vF "$ROOT_DIR cron event run" || true; echo "$CRON_LINE" ) | crontab -u "$SITE_USER" -

step "HTTPS certificate (Let's Encrypt)"
if clpctl lets-encrypt:install:certificate --domainName="$DOMAIN"; then
  CERT=ok
else
  CERT=failed
  echo "Warning: certificate not installed. Fix the DNS, then run: clpctl lets-encrypt:install:certificate --domainName=$DOMAIN"
fi

echo
echo "Done: $NAME"
echo "  App:       https://$DOMAIN/app/"
echo "  wp-admin:  https://$DOMAIN/wp-admin/  (user $ADMIN_USER)"
echo "  HTTPS:     $CERT  (the camera and face sign-in need HTTPS)"
echo "  Logins saved in $CRED_FILE"
echo "Next: in wp-admin → Workforce One, add the work locations, departments and employees."
