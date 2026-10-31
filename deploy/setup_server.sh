#!/usr/bin/env bash
# First-time installation of Madhusudhan Earthmover ERP on a fresh Ubuntu 24.04
# server (e.g. a Hostinger VPS). Run as root after uploading the package:
#
#   bash setup_server.sh erp.example.com owner@example.com [package]
#
# The package is me-erp.tar.gz or the folder it was extracted into. When it is
# left out, the extracted folder this script sits in is used, else /root/me-erp.tar.gz.
# Safe to re-run: the existing database, .env, admin user and uploads are kept.
set -euo pipefail

DOMAIN="${1:-}"
EMAIL="${2:-}"
SCRIPT_PKG=$(realpath "$(dirname "$0")/..")
if [[ -n "${3:-}" ]]; then
  SRC="$3"
elif [[ -f "$SCRIPT_PKG/manage.py" ]]; then
  SRC="$SCRIPT_PKG"
else
  SRC=/root/me-erp.tar.gz
fi

BASE=/srv/me-erp
APP_DIR=$BASE/app
VENV=$BASE/venv
ENV_FILE=$BASE/.env
APP_USER=meerp
MEDIA=/var/lib/me-erp/media
DB_NAME=me_erp
DB_USER=me_erp

step() { echo; echo "==> $*"; }
fail() { echo "ERROR: $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Run as root."
[[ -n "$DOMAIN" && -n "$EMAIL" ]] || fail "Usage: bash setup_server.sh <domain> <email-for-https-certificate> [package]"
[[ -e "$SRC" ]] || fail "Package not found: $SRC"
SRC=$(realpath "$SRC")
cd /tmp

step "Installing system packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q python3 python3-venv python3-pip postgresql nginx certbot python3-certbot-nginx ufw

step "Creating application user and folders"
id -u $APP_USER >/dev/null 2>&1 || useradd --system --home-dir $BASE --shell /usr/sbin/nologin $APP_USER
mkdir -p $BASE $MEDIA
chown $APP_USER:$APP_USER $MEDIA
chmod 750 $MEDIA

step "Writing $ENV_FILE"
if [[ ! -f $ENV_FILE ]]; then
  DB_PASS=$(python3 -c 'import secrets; print(secrets.token_urlsafe(24))')
  SECRET=$(python3 -c 'import secrets; print(secrets.token_urlsafe(50))')
  cat > $ENV_FILE <<EOF
# Production settings for Madhusudhan Earthmover ERP. Keep this file private.
DJANGO_DEBUG=false
DJANGO_SECRET_KEY=$SECRET
DJANGO_ALLOWED_HOSTS=$DOMAIN
DJANGO_CSRF_TRUSTED_ORIGINS=https://$DOMAIN
DJANGO_SSL_REDIRECT=true
DJANGO_HSTS_SECONDS=0
DJANGO_MEDIA_ROOT=$MEDIA

DB_ENGINE=postgres
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASSWORD=$DB_PASS
DB_HOST=127.0.0.1
DB_PORT=5432
EOF
  echo "Created with a new secret key and database password."
else
  echo "Already exists; kept."
fi
chown root:$APP_USER $ENV_FILE
chmod 640 $ENV_FILE
DB_PASS=$(grep '^DB_PASSWORD=' $ENV_FILE | cut -d= -f2-)

step "Preparing PostgreSQL database"
if runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1; then
  runuser -u postgres -- psql -q -c "ALTER ROLE $DB_USER WITH LOGIN PASSWORD '$DB_PASS'"
else
  runuser -u postgres -- psql -q -c "CREATE ROLE $DB_USER WITH LOGIN PASSWORD '$DB_PASS'"
fi
if ! runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1; then
  runuser -u postgres -- createdb -O $DB_USER $DB_NAME
fi

step "Installing the application"
if [[ -d "$SRC" ]]; then
  bash "$SRC/deploy/update.sh" "$SRC"
else
  TMP=$(mktemp -d)
  tar -xzf "$SRC" -C "$TMP" ./deploy
  bash "$TMP/deploy/update.sh" "$SRC"
  rm -rf "$TMP"
fi

step "Administrator account"
manage() { (cd $APP_DIR && runuser -u $APP_USER -- $VENV/bin/python manage.py "$@"); }
HAS_ADMIN=$(manage shell -c "from apps.accounts.models import User; print(User.objects.filter(is_superuser=True).exists())")
if [[ "$HAS_ADMIN" == "True" ]]; then
  echo "An administrator already exists; kept."
else
  while true; do
    read -rp "Admin username [admin]: " ADMIN_USER
    ADMIN_USER=${ADMIN_USER:-admin}
    read -rsp "Admin password (min 8 characters): " PW1; echo
    read -rsp "Repeat password: " PW2; echo
    if [[ "$PW1" != "$PW2" ]]; then echo "Passwords do not match."; continue; fi
    if [[ ${#PW1} -lt 8 ]]; then echo "Too short."; continue; fi
    break
  done
  export ERP_ADMIN_PASSWORD="$PW1"
  manage setup_erp --username "$ADMIN_USER"
  unset ERP_ADMIN_PASSWORD PW1 PW2
fi

step "Starting the application service"
cp $APP_DIR/deploy/me-erp.service /etc/systemd/system/me-erp.service
systemctl daemon-reload
systemctl enable --now me-erp
systemctl restart me-erp

step "Configuring Nginx"
if [[ ! -f /etc/nginx/sites-available/me-erp ]]; then
  sed "s/__DOMAIN__/$DOMAIN/g" $APP_DIR/deploy/nginx.conf > /etc/nginx/sites-available/me-erp
fi
ln -sf /etc/nginx/sites-available/me-erp /etc/nginx/sites-enabled/me-erp
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

step "Firewall (SSH, HTTP, HTTPS only)"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

step "HTTPS certificate"
HTTPS_OK=1
if ! certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect --keep-until-expiring; then
  HTTPS_OK=0
fi

step "Daily backups (02:30, kept 14 days)"
cat > /etc/cron.d/me-erp-backup <<EOF
30 2 * * * root /bin/bash $APP_DIR/deploy/backup.sh >> /var/log/me-erp-backup.log 2>&1
EOF
chmod 644 /etc/cron.d/me-erp-backup

echo
if [[ $HTTPS_OK -eq 1 ]]; then
  echo "Done. Open https://$DOMAIN and sign in."
else
  echo "Installed, but the HTTPS certificate could not be issued."
  echo "Check that the domain's DNS A record points to this server, wait for it to update, then run:"
  echo "  certbot --nginx -d $DOMAIN -m $EMAIL --agree-tos --redirect"
  echo "The site will not open until HTTPS works."
fi
