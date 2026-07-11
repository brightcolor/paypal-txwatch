#!/bin/bash
# Nightly backup for PayPal TxWatch: dumps the Postgres DB (custom format,
# compressed) plus the storage/app payload (exports, logos) and rotates old
# backups. Intended to run on the HOST via cron (see README "Backups"):
#
#   /etc/cron.d/paypal-txwatch-backup:
#   30 3 * * * root /opt/paypal-txwatch/backup.sh >> /var/log/paypal-txwatch-backup.log 2>&1
#
# Restore (DB):
#   gunzip -c backups/db-YYYY-mm-dd.dump.gz | docker exec -i paypal-txwatch-db-1 \
#     pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists
set -euo pipefail

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKUP_DIR="${BASE_DIR}/backups"
KEEP_DAYS=14
STAMP="$(date +%F)"

mkdir -p "${BACKUP_DIR}"

# Credentials come from the compose .env (single source of truth).
DB_USER="$(grep -E '^DB_USERNAME=' "${BASE_DIR}/.env" | cut -d= -f2- || true)"
DB_NAME="$(grep -E '^DB_DATABASE=' "${BASE_DIR}/.env" | cut -d= -f2- || true)"
DB_USER="${DB_USER:-paypal_txwatch}"
DB_NAME="${DB_NAME:-paypal_txwatch}"

echo "[$(date '+%F %T')] dumping database ${DB_NAME}…"
docker exec paypal-txwatch-db-1 pg_dump -U "${DB_USER}" -d "${DB_NAME}" --format=custom \
    | gzip > "${BACKUP_DIR}/db-${STAMP}.dump.gz.tmp"
mv "${BACKUP_DIR}/db-${STAMP}.dump.gz.tmp" "${BACKUP_DIR}/db-${STAMP}.dump.gz"

echo "[$(date '+%F %T')] archiving storage/app…"
tar -czf "${BACKUP_DIR}/storage-${STAMP}.tar.gz.tmp" -C "${BASE_DIR}" storage/app
mv "${BACKUP_DIR}/storage-${STAMP}.tar.gz.tmp" "${BACKUP_DIR}/storage-${STAMP}.tar.gz"

echo "[$(date '+%F %T')] rotating (keep ${KEEP_DAYS} days)…"
find "${BACKUP_DIR}" -name '*.gz' -mtime "+${KEEP_DAYS}" -delete

echo "[$(date '+%F %T')] done: $(du -sh "${BACKUP_DIR}" | cut -f1) total in ${BACKUP_DIR}"
