#!/usr/bin/env bash
# Installs a new version of the ERP from a package built by make_package.ps1,
# either the .tar.gz itself or the folder it was extracted into. Run as root:
#
#   bash /srv/me-erp/app/deploy/update.sh /root/me-erp.tar.gz
#   bash /srv/me-erp/app/deploy/update.sh /root/me-erp        (extracted folder)
#
# Backs up the database first, keeps .env and uploaded files, applies database
# migrations and restarts the app. The previous version is kept in /srv/me-erp/app.old.
set -euo pipefail

SRC="${1:-/root/me-erp.tar.gz}"
BASE=/srv/me-erp
APP_DIR=$BASE/app
NEW=$BASE/app.new
VENV=$BASE/venv
ENV_FILE=$BASE/.env
APP_USER=meerp
PORT=8001

fail() { echo "ERROR: $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Run as root."
[[ -e "$SRC" ]] || fail "Package not found: $SRC"
SRC=$(realpath "$SRC")
if [[ -d "$SRC" ]]; then
  [[ -f "$SRC/manage.py" && -d "$SRC/apps" ]] || fail "$SRC is not an extracted ERP package (no manage.py / apps)."
  [[ "$SRC" != "$APP_DIR" ]] || fail "Give the newly uploaded package, not the installed app folder."
fi
[[ -f $ENV_FILE ]] || fail "$ENV_FILE is missing; run setup_server.sh first."
cd /tmp

if [[ -f $APP_DIR/deploy/backup.sh ]]; then
  echo "Backing up before the update..."
  bash $APP_DIR/deploy/backup.sh
fi

echo "Unpacking..."
rm -rf $NEW
mkdir -p $NEW
if [[ -d "$SRC" ]]; then
  # Copy only the application's own files, in case the package was extracted
  # straight into a home folder that also holds other things.
  for item in apps config deploy static templates tests manage.py requirements.txt requirements-dev.txt \
              tailwind.config.js README.md .env.example; do
    if [[ -e "$SRC/$item" ]]; then cp -a "$SRC/$item" $NEW/; fi
  done
else
  tar -xzf "$SRC" -C $NEW
fi
find $NEW -name __pycache__ -type d -prune -exec rm -rf {} +
ln -sf $ENV_FILE $NEW/.env
mkdir -p $NEW/staticfiles
chown -R $APP_USER:$APP_USER $NEW

echo "Installing Python packages..."
[[ -d $VENV ]] || python3 -m venv $VENV
$VENV/bin/pip install -q --upgrade pip
$VENV/bin/pip install -q -r $NEW/requirements.txt

manage() { (cd $NEW && runuser -u $APP_USER -- $VENV/bin/python manage.py "$@"); }
echo "Updating the database and static files..."
manage migrate --noinput
manage collectstatic --noinput -v 0
manage check --deploy

echo "Switching to the new version..."
rm -rf $BASE/app.old
[[ -d $APP_DIR ]] && mv $APP_DIR $BASE/app.old
mv $NEW $APP_DIR

if [[ -f /etc/systemd/system/me-erp.service ]]; then
  systemctl restart me-erp
  HOST=$(grep '^DJANGO_ALLOWED_HOSTS=' $ENV_FILE | cut -d= -f2 | cut -d, -f1)
  for _ in $(seq 1 15); do
    if curl -fsS -o /dev/null -H "Host: $HOST" -H "X-Forwarded-Proto: https" "http://127.0.0.1:$PORT/accounts/login/"; then
      echo "Update complete; the app is responding."
      exit 0
    fi
    sleep 1
  done
  journalctl -u me-erp -n 40 --no-pager
  fail "The app did not respond after restart (log above)."
fi
