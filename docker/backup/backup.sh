#!/bin/sh
set -e

BACKUP_DIR="/var/www/html/storage/app/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="${BACKUP_DIR}/backup_${TIMESTAMP}.sql.gz"

mkdir -p "${BACKUP_DIR}"

echo "📦 Starting PostgreSQL Database Backup..."

PGPASSWORD="${DB_PASSWORD}" pg_dump -h "${DB_HOST}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" | gzip > "${BACKUP_FILE}"

echo "✅ Backup successfully created at ${BACKUP_FILE}"
