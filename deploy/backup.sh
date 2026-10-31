#!/usr/bin/env bash
# Backs up the ERP database and uploaded files to /var/backups/me-erp and
# deletes backups older than 14 days. Runs daily from /etc/cron.d/me-erp-backup;
# can also be run by hand as root:  bash /srv/me-erp/app/deploy/backup.sh
set -euo pipefail
umask 077

DEST=/var/backups/me-erp
KEEP_DAYS=14
STAMP=$(date +%Y-%m-%d_%H%M)

mkdir -p $DEST
cd /tmp
runuser -u postgres -- pg_dump -Fc me_erp > "$DEST/db_$STAMP.dump"
tar -czf "$DEST/media_$STAMP.tar.gz" -C /var/lib/me-erp media
find $DEST -type f -mtime +$KEEP_DAYS -delete
echo "$(date '+%F %T') backup ok: db_$STAMP.dump media_$STAMP.tar.gz"
